# Task 24B — Strategy B: guarded reservation binding acceptance

Status: **BLOCKED at runtime probe. Strategy B NOT complete. Task 24B remains PARTIAL.**

Date: 2026-09-23. QA-only, no commit, no migration, no infrastructure change.

## 1. Summary

Source tracing for the three challenged assumptions is complete and is recorded
below with file:line evidence. The bounded fixture probe could **not** execute:
QA MariaDB is at `max_connections` (100/100), fully consumed by idle
`dbhub_ro` connections, so the Drupal CLI cannot open a database connection.
Freeing the pool is infrastructure action outside this task's authorized scope.

No fixture was written. The read-only preflight temporary file was removed from
both host and container and its absence verified.

**Update 2026-09-24 (Phase B, §3.6).** Runtime verification of the connection-pool
cap ran against the running connectors — **7 connector instances (14 processes:
7 launchers + 7 servers)**. The on-disk deployment is patched (§3.4), but every
running `@bytebase/dbhub` **server child** loads the **global unpatched** build
(direct loaded-entry-point evidence), so the cap is **not yet in effect at runtime**
and the reported client reload replaced no process. QA MariaDB is currently
**11/100** (incidental headroom, its low value consistent with an *inferred*
inter-session container restart — not lifecycle-confirmed) and QA Drupal bootstrap
now **PASSES** — but that reflects freed slots, not the cap. Returned-vs-borrowed /
idle release is **UNVERIFIED** (§3.6 Step 4 retraction). Strategy B remains
**BLOCKED**; not resumed.

## 2. Assumption corrections (source-established)

### 2.1 "Fixed routing does not prove unchanged feasibility"

Correct. Constant `quote_route_meters`/`quote_route_seconds` do not by themselves
prove handling time, eligibility, buffers or working-hour checks are unchanged.
In the guarded path these are separate stages:

- `DispatchPlanner::revalidate()` runs the **binding/identity checks first**
  (price-snapshot binding at `DispatchPlanner.php:858-869`; job-facts digest at
  `DispatchPlanner.php:886-891`), and only **after** those pass does it re-derive
  timing/feasibility at `DispatchPlanner.php:893+`.
- Therefore a mismatch case that is refused at 858-891 is refused **specifically
  at the guarded identity/binding comparison**, before feasibility re-derivation —
  which is exactly the property Strategy B must demonstrate. Proving this at
  runtime still requires the probe (see blocker); the ordering is established
  from source, the runtime refusal point is not yet observed.

### 2.2 "One handler does not establish the entire save path's safety"

Inventory of the actual save path (read from source, YAML confirmed directly per
graph-coverage limits):

- `.module` defines only `custom_webform_handlers_mail` and
  `custom_webform_handlers_cron` — **no** entity/submission save hooks.
- Exactly one enabled hg Webform handler: `address_distance_handler`
  (id `address_and_distance_calculator`).
- Exactly one event subscriber: `ReviewTrackerCacheSubscriber` — a response
  header rewriter, not save-triggered.
- Two ECA models present in config (`..._popularity`, `..._route_warm`); their
  triggering events were not confirmed and remain a residual side-effect risk to
  be denied at probe time via probe-local no-network/no-notify isolation.
- `AddressDistanceHandler::postSave()` (`AddressDistanceHandler.php:1997-2051`)
  returns early for non-completed submissions (`:2000-2003`) and for updates
  (`:2012-2013`). A **completed, new** submission with dispatch enabled calls
  `announceDispatchManualReview()` (`:2030`) →
  `dispatchNotifier->notify(...)` (`:2638`) — a real notification side effect
  that a DB rollback cannot undo.

**Consequence for fixture design:** seed the submission as a **draft** to obtain
a genuine persisted `sid` with zero completed-submission side effects, then drive
the guarded confirmation path directly. Do not save a completed submission.

### 2.3 "Verify what 'under-lock reread' actually means"

This is the decisive finding. The guard does **two different things**:

- **Price-snapshot binding — genuine persisted read under lock.**
  `DatabaseReservationStore::confirmWithRevalidation()` re-reads the authoritative
  reservation row **inside** `ResourceCalendarGuard::withLockedDays()`, including
  `price_snapshot_id` (`DatabaseReservationStore.php:449`), builds a `LockedHold`,
  and `revalidate()` compares that persisted-under-lock value against the accepted
  snapshot id (`DispatchPlanner.php:858-869`). This is a real reread of persisted
  binding state under the row locks.

- **Job-facts digest — recomputed from an in-memory submission captured before
  the lock.** In `confirmFor()`, the closure computes
  `AcceptedJob::fromSubmissionData($submission->getData(), ...)->digest` from the
  `$submission` captured via `use ($submission)` (`DispatchPlanner.php:547-551`),
  not from a submission re-read inside the guard. The code documents this
  explicitly as an **honest boundary** (`DispatchPlanner.php:525-536`): the
  calendar guard locks resource/day rows, **not** the `webform_submission` row,
  and the closure reads the in-memory submission it captured.

**Reportable gap (not a proof):** the required *persisted-submission-facts reread
inside the guard is absent by design.* Consequences for the case table:

- Binding-identity mismatch cases (different reservation, different `snapshotId`,
  different composite binding, missing/malformed binding) **are** provable through
  the genuine persisted-under-lock `price_snapshot_id` path.
- "Saved job changed after acceptance" cases can only be exercised at the
  **in-memory digest** level (mutate the captured `$submission` object), which is
  a weaker demonstration than a persisted reread inside the lock. This must be
  reported as an in-memory comparison, **not** counted as a persisted-reread
  proof.

## 3. Precise remaining blocker

The bounded probe cannot open a database connection.

```text
max_connections            = 100
THREADS_CONNECTED          = 101   (server-wide)
dbhub_ro Sleep connections = 100   (age 511s .. 18655s)
Drupal CLI bootstrap       = SQLSTATE[HY000] [1040] Too many connections
```

The `dbhub_ro` read-only MCP account holds 100 idle (`Sleep`) connections whose
age (up to ~5.2 h) matches the `ddev-ppu-db` container uptime (`Up 5 hours,
unhealthy`). The pool is fully saturated, so no new connection — including the
QA Drush bootstrap the probe needs — can be established.

Resolving this requires either terminating the idle `dbhub_ro` sessions or
restarting the DBHub MCP server / db container. Both are shared-service /
infrastructure actions:

- PROMPT_SPEC §3: "No ddev start/restart/exec [...] If existing-container
  execution is unavailable, stop with a precise blocker."
- User constraint: "No external APIs, payments, migrations, infrastructure
  changes or commits."

### 3.1 Authorized bounded connection-recovery attempt — FAILED (pool auto-refills)

The owner subsequently authorized a bounded recovery: recheck via one admin
connection, kill at most five `dbhub_ro` `Sleep` sessions, verifying each is
still `Sleep` with no open transaction/lock immediately before termination; no
restart, no privilege/`max_connections`/pool change. Executed 2026-09-23 via the
root admin connection on `ddev-ppu-db` only:

```text
BEFORE: THREADS_CONNECTED=101, max_connections=100, dbhub_ro Sleep=100,
        dbhub_ro sessions in open InnoDB transaction = 0
Candidates (oldest, all DB=db, Sleep, ~19046s, empty STATE): 22 21 20 19 18
Terminated (each re-verified Sleep + zero INNODB_TRX immediately before KILL): 22, 21, 19, 18
  ID 20 SKIPPED — admin recheck hit a transient "1040 Too many connections";
  uncertain, so not killed (per rule 4).
AFTER: THREADS_CONNECTED=101, dbhub_ro sessions=100
Post-attempt census: youngest dbhub_ro sessions aged only 22-30s (IDs ~1512-1533)
  — brand-new connections opened seconds after the kills.
```

The DBHub connector **immediately re-opened** connections to hold its pool at
100, so THREADS_CONNECTED never dropped below the cap and no slot freed for the
QA Drush bootstrap. Four terminations (within the five-session budget) were
insufficient and the slots refilled at once — both of the owner's stop
conditions. Per that authorization the recovery **stopped**; cleanup was not
repeated. Terminated sessions 22/21/19/18 are gone and cannot be restored — this
was not fully reversible. Permanent DBHub connection-pool repair (stopping/
reconfiguring the connector) is a **separate task**, explicitly out of scope
here.

**Net effect:** the probe remains blocked. The `+1` over `max_connections` is the
privileged root admin connection (MariaDB's reserved extra slot); a normal
Drupal account is still capped out by the 100 `dbhub_ro` connections.

### 3.2 Authorized connector-pause attempt — STOPPED (connector not isolatable)

The owner then authorized pausing *only the responsible DBHub connector* via
supported lifecycle controls, forbidding kills of shared connectors, other apps,
Claude itself, or further SQL session kills. Identification (admin/read-only host
inspection, no credentials printed):

- Connector is a **stdio** MCP server: `node .claude/scripts/dbhub-mcp.cjs`.
  Its lifecycle is owned by the spawning MCP client — there is no external
  "pause" control; the supported control is each client's own MCP enable/disable.
- All 100 `dbhub_ro` sessions originate from host gateway `172.18.0.1` into the
  db container's `3306` (published `127.0.0.1:49368`) — i.e. host processes.
- **10 live connector instances**, under **two different applications**:
  - 6 spawned by `codex.exe` (oldest started `2026-09-23 13:51:55Z`, matching the
    ~5.3 h age of the oldest `dbhub_ro` connections)
  - 4 spawned by `claude.exe`
  - ~10 connections each → 100 total.

**Result: STOPPED without acting.** No single "responsible" connector exists —
the pool is spread across 10 stdio instances under two apps, the majority under
`codex.exe`, a separate application whose consumers cannot be managed here
(the excluded "shared connector" case). A stdio server has no external pause
control, and OS-killing `claude.exe`'s own MCP children is not a supported
control and skirts the "do not stop Claude" boundary. Nothing was killed; no
config changed.

**Exact owner action required to unblock:** in each running Codex CLI session
(primary — holds the majority and the oldest connections) and each Claude
session, disable/stop the `dbhub` MCP server via that client's MCP control (the
supported lifecycle action), which drains that child's `dbhub_ro` connections;
closing surplus sessions does the same. A durable fix — capping the pool inside
`.claude/scripts/dbhub-mcp.cjs` — is a persistent config change belonging to the
separately-authorized pool-repair task.

Per those bounds this run **stops with the blocker named** rather than continuing
to act on the connection pool.

### 3.3 Authorized bounded connection-pool repair — Step 2 established, Step 3 STOPPED (cap not reachable through the authorized file)

The owner authorized a narrowly scoped tooling repair: modify only
`.claude/scripts/dbhub-mcp.cjs` (and these evidence docs) to cap each DBHub
instance at ≤2 connections; no dependency/lockfile/global-config edits, no
process/SQL/container action, DBHub MCP calls kept suspended.

**Step 2 — actual connection owner (source-established, read-only):**

- `.claude/scripts/dbhub-mcp.cjs` is a **launcher only**. It resolves the
  published port, sets `dsn.port`, and `spawn`s the real implementation
  (`@bytebase/dbhub` `dist/index.js`, `--transport stdio`, DSN passed via env).
  It **owns no database pool** and its only levers are the DSN string and the
  spawn args/env.
- The pool owner is `@bytebase/dbhub` **1.2.3**, mariadb connector
  `dist/mariadb-DQ6P57IX.js`, at line **127**:
  `this.pool = mariadb.createPool(connectionConfig)`. The concrete driver is
  `mariadb` **3.5.4** (`@bytebase/dbhub/node_modules/mariadb`).
- `connectionConfig` (`mariadb-DQ6P57IX.js:43-68`) carries **no pool-size key**,
  and `forEachSearchParam` (`:69-79`) forwards **only** `sslmode` from the DSN
  query string. So driver defaults govern the pool:
  - `connectionLimit` defaults to **10** (`mariadb/lib/config/pool-options.js:29`).
  - `minimumIdle` defaults to `connectionLimit` (`pool-options.js:37-38`), and the
    pool fills to `minimumIdle` at startup (`pool.js:506`
    `needsMinimumIdle = totalConnections() < minimumIdle`; init loop `:48-91`).
  - ⇒ each connector instance **eagerly opens up to ~10 connections**. The
    per-instance 10 is source-established; the aggregate 100 depends on the live
    instance count (previously observed 10 instances across `codex.exe`/
    `claude.exe`) and remains an **estimate**, not a per-process measurement.
  - A `SELECT VERSION()` test query (`mariadb-DQ6P57IX.js:128`) opens at least one
    connection eagerly on connect.

**Step 3 — precise limitation: the ≤2 cap is NOT reachable through the authorized file.**

1. DSN query params other than `sslmode` are ignored (`:69-79`), so a
   `?connectionLimit=2` on the DSN would be an option the implementation ignores
   — which the owner explicitly forbade adding.
2. No env var, CLI flag, or `dbhub.toml` source key feeds the mariadb pool size.
   Searched `@bytebase/dbhub/dist`: `--max-rows` is removed and unrelated
   (result rows, `chunk-F5TPYAVO.js:564-566`); `maxConnection` appears only in
   health reporting (`index.js:923`) and the **postgres/sqlserver** connectors,
   never mariadb.
3. A wrapper-injected monkeypatch cannot work either: `mariadb` is ESM
   (`"type":"module"`) with a **separate CJS build**
   (`exports.import`→`promise.js`, `exports.require`→`dist/promise.cjs`). dbhub
   imports the ESM build, whose namespace bindings are read-only (cannot reassign
   `createPool`), while a CJS `--require` preload would patch the unused CJS
   instance. So the wrapper cannot inject `createPool` options by preload.

⇒ The installed implementation cannot support a bounded ≤2 pool through
`.claude/scripts/dbhub-mcp.cjs` alone. Per the owner's Step 3 stop-clause, no
functional edit was made to the wrapper (adding an ignored DSN param is
forbidden), and the repair stops here with the smallest additional change named.

**Smallest additional change required (NOT performed — needs owner authorization
to widen the edit surface beyond the wrapper):** a one-line edit at
`@bytebase/dbhub/dist/mariadb-DQ6P57IX.js:127`:

```js
this.pool = mariadb.createPool({ ...connectionConfig, connectionLimit: 2, minimumIdle: 0, idleTimeout: 60 });
```

Every key is verified honored by the installed driver (`pool-options.js:29,30,37-38`):
`connectionLimit: 2` caps at two; `minimumIdle: 0` disables the eager prewarm
(lazy open — `Math.min(0, 2) = 0`); `idleTimeout: 60` releases idle connections
after 60 s; `acquireTimeout` is left at its 10000 ms default, which bounds
acquisition waits. This edits a **globally-installed** vendored package shared by
every instance (desirable blast radius: it caps all of them), but it is a
global-package file, not the authorized wrapper, and is **lost on any dbhub
reinstall/upgrade** — so a durable form (patch-package, or a pinned vendored copy
that the wrapper re-points to) is a separate, larger change.

**Deployment status:** source fix **NOT implemented**; runtime verification
**PENDING**. Even once applied, already-running instances keep their existing
pools — the owner must disable/re-enable the `dbhub` MCP server in each Codex and
Claude client (or close surplus sessions) to replace them. No process kills, SQL
kills, or container restarts were performed; DBHub MCP calls remain suspended.

**QA connectivity:** unchanged — still blocked at `max_connections` 100/100.
Strategy B remains BLOCKED and is **not** resumed by this task.

**Superseded by §3.4:** the owner subsequently authorized the durable
**project-local** form (not the global-file edit named above), which is now
implemented and reproducible; the "source fix NOT implemented" status in this
subsection reflects only the earlier stopped attempt.

### 3.4 Authorized project-local reproducible pool cap — IMPLEMENTED (source repair reproducible; client replacement + runtime verification PENDING)

The owner then authorized the durable form §3.3 named: a **project-local**,
version-pinned install of `@bytebase/dbhub` plus a reproducible, version-checked
patch — explicitly **not** an edit to the global/shared install, and no root
Composer/npm/global/database/app-code changes, no commits, DBHub MCP calls kept
suspended throughout.

**Chosen form.** Rather than editing the globally-installed vendored file (§3.3 —
lost on reinstall, mutates a shared package), the cap now lives in a project-local
runtime under `.claude/tools/dbhub/`, and the launcher re-points to it. The global
installation was not modified.

**Files created (`.claude/tools/dbhub/`):**

- `package.json` — pins `@bytebase/dbhub` to exactly `1.2.3`; its `postinstall`
  and `provision`/`verify` scripts run the applier.
- `package-lock.json` — locks the full dependency tree, including mariadb `3.5.4`.
- `patch-target.cjs` — dependency-free (Node built-ins only) shared locator +
  patch-state helper, required by both the applier and the launcher.
- `provision.cjs` — version-checked, idempotent applier (also the postinstall hook).
- `README.md` — purpose, exact patch, option semantics, tracking-status file
  table, provisioning commands, license preservation, owner reload procedure.

**Patch applied (one call site).** The connector chunk, resolved by content (never
by a hardcoded hash), is `dist/mariadb-DQ6P57IX.js`; it holds **exactly one**
`createPool` call site. It was changed from
`mariadb.createPool(connectionConfig)` to:

```js
this.pool = mariadb.createPool({ ...connectionConfig, connectionLimit: 2, minimumIdle: 0, idleTimeout: 60 });
```

The cap is a **per-pool** cap of two connections at this one call site: capping
the single `createPool` site bounds each pool constructed there to ≤2. The
aggregate bound "N processes ⇒ ≤ 2N connections" assumes exactly one such pool per
process; that the connector is constructed once per process is a separate runtime
fact and is **not** established by the single call site alone (deferred to runtime
verification). `minimumIdle: 0` disables the pool's eager idle prewarm
(`Math.min(0, 2) = 0`, no connections opened merely to sit idle) — but this does
**not** prove that DBHub initialization or health checks open no connections: the
connector still issues a `SELECT VERSION()` probe on connect
(`mariadb-DQ6P57IX.js:128`), and init/health paths may open a connection on demand.
`idleTimeout: 60` (driver unit = **seconds**) sets the idle-release target to 60 s.
MariaDB's *documented default* `wait_timeout` is 28800 s — a documented default,
**not** a current runtime measurement of this server (server settings unchanged;
the live `wait_timeout` read is deferred to runtime verification — no DB connection
was opened) — which 60 s is below. A configured 60 s target is **not** evidence
that idle connections were actually released after 60 s; that is runtime behaviour
and is not verified here. Auth, TLS (`ssl`), the read-only transaction path,
`multipleStatements`, and the finite `acquireTimeout` (10000 ms default) are
preserved — the patch only spreads the existing `connectionConfig` and adds three
keys, removing nothing. Upstream `LICENSE` files are preserved under `node_modules`.

**Launcher (`.claude/scripts/dbhub-mcp.cjs`).** Re-pointed to the project-local
runtime, resolved from the project root **independently of cwd**. Before spawning
it runs a **read-only** patched-gate: if the local runtime is missing, or its
connector is not in the `patched` state, it fails with a secret-free diagnostic
naming the provisioning command. It **never** falls back to the global install,
**never** downloads another version, **never** patches at startup, and opens **no**
database connection for the check. DSN read/validation
(`mariadb://…dbhub_ro@127.0.0.1/db`), published-port resolution, transport
(`--transport stdio`), credential-via-env handling, and exit/signal forwarding are
unchanged.

**Provisioner guarantees (verified).** Idempotent (re-run ⇒ "already patched",
exit 0); `verify`/`--check` exits 0 only when patched; fails explicitly on version
drift (`@bytebase/dbhub` ≠ 1.2.3, or the *actually-resolved* mariadb driver ≠ the
reviewed 3.5.4) and on content drift (neither the reviewed nor the patched form, or
≠ 1 call site); reapplies after a clean install via `postinstall`. The installed
driver resolved to **exactly 3.5.4**, so the "revalidate if the driver differs"
hard-fail branch did not trigger.

**Bounded verification performed (no DB connection, no new MCP instance started):**

- `node --check` — clean for `patch-target.cjs`, `provision.cjs`, the launcher.
- Installed `@bytebase/dbhub` = 1.2.3; resolved `mariadb` = 3.5.4; connector =
  `dist/mariadb-DQ6P57IX.js`; state = `patched`; exactly one `createPool` site.
- Launcher: global path removed (grep count 0), project-local path resolved, and
  the patched-gate reports "would launch" **without** connecting to the database.
- **Clean-provisioning reproduction** in a task-owned temp dir
  (`.claude/tools/dbhub/tmp/repro`): `npm ci` from the lockfile ran `postinstall`,
  which **auto-applied** the patch — `dbhub provision: patched … connectionLimit=2,
  minimumIdle=0, idleTimeout=60 (@bytebase/dbhub 1.2.3, mariadb 3.5.4)`; an
  independent recheck returned `patched / 1.2.3 / 3.5.4`; the temp dir was then
  removed and its absence verified. Only that task-owned artifact was removed.

**Git hygiene (owner-authorized narrow `.gitignore` exception applied).** The
former blanket rule `.gitignore:178:/.claude/` (Task 14B) ignored every path under
`.claude/`, including these repair sources — so the sources were on disk but
untracked and could not be committed (an earlier revision of this section wrongly
concluded "no `.gitignore` edit needed"; that is corrected here). Under explicit
owner authorization, that blanket rule was expanded into a level-by-level cascade
(`/.claude/*`, then re-include each parent directory, re-ignore its contents, and
allowlist the exact files) that makes **exactly six** repair sources eligible for
ordinary version control: `.claude/scripts/dbhub-mcp.cjs` and the five files under
`.claude/tools/dbhub/` (`package.json`, `package-lock.json`, `patch-target.cjs`,
`provision.cjs`, `README.md`). Everything else under `.claude/` stays ignored —
`node_modules/`, `tmp/`, `.claude/dbhub.toml` (the DSN password lives only there),
local configuration, memory, and logs. Verified with `git check-ignore -v`
(authoritative across all ignore sources): the six resolve to their `!`-allowlist
rules (not ignored), while `node_modules/`, `tmp`, and `dbhub.toml` resolve to the
re-ignore rules; and `git status --porcelain -uall -- .claude/` lists **exactly**
those six as untracked, nothing else. **Reproducible on this workstation and
preserved in repository history are distinct:** the six are now *eligible* for
version control but are **not yet committed** (untracked). Per owner instruction
nothing was staged, force-added, or committed. Until they are committed,
reproducibility is on-disk + `npm ci` from the local lockfile; committing the six
is what would preserve the repair in repository history.

**Deployment status:**

- **Source repair — IMPLEMENTED and reproducible** (project-local, version-checked,
  idempotent; clean-install reapply proven above).
- **Client replacement — PENDING (owner action).** Already-running DBHub instances
  keep their loaded code and existing pools; capacity is not freed until each is
  replaced.
- **Runtime verification — PENDING.** Bounded, read-only, and only after the owner
  frees capacity: confirm the loaded runtime path, observe live `dbhub_ro` usage
  capping at ≤2/instance with lazy open and 60 s idle release, read `wait_timeout`,
  and re-run the QA Drupal CLI bootstrap. Runtime success is **not** claimed from
  this configuration/provisioning evidence alone.

**Exact owner action to free capacity.** In each running **Codex CLI** session
(holds the majority and the oldest connections) and each **Claude** session,
disable then re-enable the `dbhub` MCP server via that client's own MCP control (or
close the surplus sessions). Each reload drains that child's `dbhub_ro` connections
and its replacement opens the capped (≤2, lazy) pool. No process kills,
SQL-session kills, or container restarts are required or intended.

**Guarantees preserved.** No application code, root Composer/npm dependency, global
package, database setting, or server variable was changed, and no commit was made;
the zero-cost routing and security guarantees are untouched. Strategy B remains
**BLOCKED** and is **not** resumed by this task — its persisted-job stale-object
case, unchanged-job positive control, and rollback requirements are unchanged and
deferred until capacity recovers and Strategy B is separately resumed.

### 3.5 Bounded runtime acceptance attempt — BLOCKED (source ready; runtime identity UNVERIFIED; awaiting owner-confirmed client replacement)

**Correction (2026-09-24, later revision).** An earlier version of this section
concluded that the exercised connector was "proven old" and that "0 patched
instances" were running, inferring both from connection counts and a long
server-side idle time. That conclusion is **retracted as unsupported**; runtime
identity is recorded as **unverified**:

- A server-side `Sleep` state, and its duration, is a server command state — not a
  client-side pool state. Per the MariaDB Node.js connector, `idleTimeout` releases
  a connection **that has been returned to the pool and is sitting idle**; it does
  not act on a connection still **borrowed/leased** by the application (the
  connector exposes this lifecycle via `getConnection()` / `conn.release()` and a
  separate `leakDetectionTimeout` for borrowed connections). A connector that
  borrows one connection for the session and holds it open would show a long server
  `Sleep` and never be reaped under **either** the old (`idleTimeout 1800`) **or**
  the patched (`idleTimeout 60`) configuration. So a 197 s `Sleep` does not
  discriminate old from patched.
- Connection-count totals do not determine **process counts** or **loaded
  versions**. 70 `dbhub_ro` connections cannot be divided into "N old instances ×
  ~10" or "0 patched" — that is an unfounded multiple.

Runtime identity therefore requires **direct process evidence** (PID, entry-point
path, resolved install) plus a returned-vs-borrowed measurement — see the pending
verification below. The measurements that *were* taken (connection counts, idle
durations, QA bootstrap) are preserved as-is; only the identity conclusion is
withdrawn.

Performed 2026-09-24 (~01:00Z), read-only, through the already-running web/db
containers via the approved `docker exec` path (never `ddev exec`), `rtk`-prefixed.
**No client reload was performed by this session:** the Claude Code `/mcp`
Reconnect action is interactive-terminal-only and is not available in this
desktop-app Code tab, and the Codex CLI is a separate external application. Client
replacement stays owner-operated. No process kills, SQL-session kills, container
restarts, writes, or commits. No credentials or connection strings printed.

**Runtime exercised, identity UNVERIFIED (Steps 1–2).** The real DBHub path was
exercised through *this session's own* `dbhub` MCP connector (not a standalone
driver example): a read-only `SELECT` reported `CURRENT_USER() = dbhub_ro@%`, host
`ppu-db`, server connection id **3048** — one live DBHub connection. That connection
was observed idle **45 → 106 → 136 → 167 → 197 s** (monotonic, no background health
traffic resetting it), with no release seen, while total `dbhub_ro` stayed at **70**.
These raw measurements are preserved. They do **not** establish which runtime this
connector loaded: the idle figure is server-side `Sleep`, which for a borrowed
connection is unaffected by `idleTimeout` under either configuration (see the
Correction above), and no PID / entry-point / resolved-install evidence was
collected. Identity is therefore **unverified** — neither patched nor old is proven.
An on-disk patch alone does not establish loaded code, and neither does a long
server-side idle interval.

**Capacity measured (Step 3).** `max_connections = 100`; three observations over
~1 min were stable at `Threads_connected = 71` (70 `dbhub_ro` `Sleep` + 1 root
census), `Max_used_connections = 101` (prior saturation peak). The 70 `dbhub_ro`
connections are aged 45 s … ~7.4 h; their per-process runtime identity is
**unverified** (see Correction — age and count do not identify the loaded runtime).
Present headroom is ≈29 slots (100 − 71) because ~3 clients closed since the
100-connection census; this is incidental slack, not a demonstrated ≤2/instance
cap, and the connection population can re-saturate.

**QA bootstrap (Step 6) — PASS.** `drush --uri=https://ppu-qa.ddev.site
core:status`: Drupal **10.6.16**, DB name **qa**, Database **Connected**, bootstrap
**Successful**, site path **sites/qa**; exit **0**. Routing mode read via the real
service (`RoutingModeResolver::effectiveMode()`): **development_fixed**,
`isDevelopmentFixed() = true`, `IS_DDEV_PROJECT = true` — the unconditional DDEV
branch, so no `custom_webform_handlers.settings` read and no browser credential was
touched, and no outbound routing call was made; exit **0**.

**Per-check verdict.**

| Step | Check | Verdict |
| --- | --- | --- |
| 1 | Recheck processes; identify global-runtime vs patched-runtime instances | **PARTIAL** — connection census DONE (70 `dbhub_ro` Sleep); per-process runtime identity **UNVERIFIED** (no PID / entry-point / install evidence; not inferable from counts) |
| 2 | Verify actual *running* patched identity | **BLOCKED / UNVERIFIED** — real connector (id 3048) exercised, but identity not established either way; a long server `Sleep` does not discriminate old from patched |
| 3 | Measure DB capacity (3 obs / ~1 min) | **DONE** — 71/100, ≈29 incidental headroom, 70 `dbhub_ro` idle (identity unverified) |
| 4 | Exercise one *verified patched* connector | **BLOCKED** — no connector verified as patched exists to exercise; no fresh (replaced) connector present |
| 5 | Observe idle release | **BLOCKED** — cannot validly assess: no verified patched connector, and returned-vs-borrowed state was not distinguished, so idle-release semantics were untested |
| 6 | QA Drupal bootstrap (db `qa`, `sites/qa`, `development_fixed`) | **PASS** — exit 0, all confirmed |

**Overall: NOT "pool repair complete," and runtime identity UNVERIFIED.** The
source repair is ready for deployment (§3.4). The precise remaining stage is
**owner-controlled client replacement**, then verification of a *freshly started*
connector: (1) record its PID, parent, start time and runtime entry-point path
(no credentials / full args); (2) establish it loads the project-local patched
install — reading the on-disk launcher is insufficient; (3) exercise it with
bounded read-only requests and measure attributable connection usage; (4)
distinguish connections **returned to the pool** from those **still borrowed**
before assessing idle release; (5) recheck DB headroom and QA bootstrap via the
approved container path. This verification is **held until the owner confirms
client replacement has occurred**, and cannot be driven from this desktop-app Code
tab in any case (`/mcp` Reconnect is interactive-terminal-only; Codex is external).
If a *verified* patched runtime is later found to retain connections unexpectedly,
that is a **reproducible behaviour requiring diagnosis — not proof that the runtime
is old**. Strategy B remains **BLOCKED** and its acceptance criteria are **not**
claimed.

### 3.6 Phase B — fresh-connector runtime verification (2026-09-24) — source deployment PATCHED, running runtime UNPATCHED (global); reported reload ineffective

Performed 2026-09-24 (~05:3xZ), read-only, through the already-running web/db
containers via the approved `docker exec` path (never `ddev exec`), `rtk`-prefixed,
plus a host process census (read-only PowerShell) that emits only
PID/PPID/parent/start/entry-point and classification booleans — no credentials, no
full argument strings. No process kills, SQL-session kills, container/DDEV/MariaDB
restarts, writes, source changes, or commits. The owner reported "client reload has
been completed"; this section records what the running runtime **actually is**.

Owner-carried framing honoured: a retained-connection result is recorded as
**reproducible behaviour requiring diagnosis, not** as proof the runtime "must be
old." The identity conclusion here rests on **direct entry-point-path + start-time
evidence**, not on connection counts or idle duration (the §3.5 confound).

**Step 1 — process facts (PID / parent / start / entry-point) — PASS.**
The DBHub footprint is **7 connector instances**, i.e. **14 `node` processes** =
7 launchers + 7 server children (each launcher spawns exactly one
`@bytebase/dbhub` server). It is **not** 14 DBHub servers: only the 7 children are
servers; the 7 launchers are thin `dbhub-mcp.cjs` wrappers that own no pool.
- Every **launcher** entry-point = the project-local `…\.claude\scripts\dbhub-mcp.cjs`
  (`UsesProjectLauncher = True`).
- Every **server child** (the actual `@bytebase/dbhub` server) entry-point = the
  **global** `…\AppData\Roaming\npm\node_modules\@bytebase\dbhub\dist\index.js`
  (`MentionsGlobalPkg = True`, `UsesToolsDbhub = False`).
- Parents: `claude.exe` (4 launchers) and `codex.exe` (3 launchers).
- Start times span 2026-09-23 **09:52:50Z … 19:19:29Z**; current time ~2026-09-24
  05:3xZ. **No process started after the reported reload** — the newest child is
  ~10 h old.

**Step 2 — does the running connector load the project-local *patched* install? FAIL.**
Direct evidence (loaded module path, not launcher-reading): every running server
child's entry-point is the **global unpatched** `@bytebase/dbhub/dist/index.js`;
**none** loads the project-local `…\.claude\tools\dbhub\node_modules\@bytebase\dbhub\dist\index.js`.
On-disk patch state of each install (verified this session at `mariadb-DQ6P57IX.js:127`):
- project-local = `createPool({ ...connectionConfig, connectionLimit: 2, minimumIdle: 0, idleTimeout: 60 })` — **patched, present**;
- global = `createPool(connectionConfig)` — **unpatched**.
So the connection-pool cap is **not in effect in any running connector**.

**Reconciliation (diagnosis).** The on-disk launcher read this session
(`dbhub-mcp.cjs`) spawns the child from the **project-local** `dbhubPath` only and
refuses to start unless that install exists and `patch-target.cjs` reports
`patched` ("Never fall back to a global installation"). The install is present and
patched. Yet every running child loads the global package. The only consistent
explanation: the 7 running server children were spawned by **earlier launchers**
(global-resolving), before the §3.4 launcher rewrite + project-local patched
install were placed on disk — consistent with every start time being 2026-09-23 ≤
19:19Z. **The reported client reload replaced none of these processes.** A fresh
start of the current on-disk launcher would load the patched runtime; no client has
performed one.

**Step 3 — exercise + attributable connection usage — measured; result UNPATCHED.**
This session's own `dbhub` MCP connector was exercised read-only:
`CURRENT_USER() = dbhub_ro@%`; `CONNECTION_ID()` returned **10** then **11** on
successive calls — both members of a single contiguous prewarm batch of **10**
connections (server IDs **10–19**), opened together (identical `TIME`; sequential
ephemeral source ports in the earlier census). Ten simultaneous connections is the
unpatched `connectionLimit=10 / minimumIdle=10` eager-prewarm signature; a patched
pool (`connectionLimit=2, minimumIdle=0`) can never exceed 2 and opens lazily. The
10-count exceeds the patched cap **independently** of any borrowed-vs-returned
consideration.

**Step 4 — returned-vs-borrowed before idle release — UNVERIFIED.**
The 10 connections (IDs 10–19) were observed `COMMAND = Sleep`, empty `STATE`,
`TIME` rising monotonically **3 s → 552 s** (~9.2 min) with no reset and no drop at
the patched 60 s target, count pinned at 10. **These measured values are preserved.**
But the earlier reading that `Sleep` + empty `STATE` **proves** the connection was
**returned to the client-side pool** (and therefore that idle release was validly
testable) is **RETRACTED**: a server-side `Sleep`/empty `STATE` only means the
*session is not mid-statement*; it does **not** distinguish a connection the
connector has **returned to its pool** from one still **borrowed/leased** by the
application — a held-but-idle borrowed connection presents identically server-side.
That client-side lifecycle (`getConnection()` / `conn.release()`, and
`leakDetectionTimeout` for borrowed connections) was **not** observed, so no
pool-state evidence exists. **Returned-vs-borrowed is therefore UNVERIFIED, and
idle-release semantics cannot be assessed from these observations.** This retraction
does **not** disturb the identity conclusion, which rests on the loaded entry-point
paths (Step 2) and, independently, on the eager-prewarm **count** of 10 simultaneous
connections (Step 3) exceeding the patched cap of 2 — neither of which needs a
returned-vs-borrowed assumption.

**Step 5 — headroom + QA bootstrap (existing container) — PASS.**
`SHOW`-based census this session: `dbhub_ro` = **10**, `Threads_connected` = **11**,
`Max_used_connections` = **11**, `max_connections` = **100**, `wait_timeout` =
**28800** ⇒ ≈89 free slots. `Max_used_connections` fell 101 → 11 and the docker
gateway moved 172.18.0.1 → 172.19.0.1 between sessions; these counter/address changes
are **consistent with** an inter-session container/DB restart but **do not by
themselves confirm one** — no start-time / uptime or other lifecycle evidence was
captured, and this session performed no restart. Treat the restart as an
**inference**, not an established fact. QA bootstrap:
`drush --uri=https://ppu-qa.ddev.site core:status` → Drupal **10.6.16**, Database
**Connected**, bootstrap **Successful**, site path **sites/qa**, exit **0**. Guard:
`IS_DDEV_PROJECT = true` confirmed in-container ⇒ `RoutingModeResolver::effectiveMode()`
= `development_fixed` unconditionally (source read this session, lines 66/73 — no
config/credential read, no network). QA bootstraps now only because ~10 slots are in
use — **incidental headroom** (its low value is consistent with the inferred
inter-session restart but is not proof of one), **not a demonstrated ≤2/instance
cap**; the population re-saturates whenever more unpatched connectors are queried.

**Per-check verdict.**

| Step | Check | Verdict |
| --- | --- | --- |
| 1 | Record PID / parent / start / entry-point (no credentials / full args) | **PASS** — 7 connector instances (14 processes: 7 launchers + 7 servers); none started after the reported reload |
| 2 | Establish it loads the project-local *patched* install (not by reading the launcher) | **FAIL** — every running child loads the **global unpatched** `@bytebase/dbhub`; none loads `tools/dbhub`; on-disk deployment is patched but unused |
| 3 | Exercise + measure attributable connections | **PASS (measured)** — this connector eagerly opened 10 (IDs 10–19); unpatched signature, > patched cap 2 |
| 4 | Distinguish returned vs borrowed before idle release | **UNVERIFIED** — `Sleep` / empty `STATE` does not prove a connection was returned vs still borrowed; no client-side pool-state evidence collected. Measured `TIME` 3→552 s preserved; idle release not assessable |
| 5 | Recheck headroom + QA bootstrap | **PASS** — 11/100, QA Drupal 10.6.16 bootstrap Successful (exit 0), `development_fixed`; headroom incidental |

**Overall: the source / on-disk deployment is correct and patched (§3.4), but the
*running* DBHub runtime is the global *unpatched* build on every connector, and the
reported client reload replaced no running process.** The connection-pool cap is
therefore **not yet in effect at runtime**. Per the owner's directive this is
recorded as **a reproducible behaviour requiring diagnosis** — the running
connectors predate the patched deployment and/or the clients did not actually
terminate and re-spawn their stdio `dbhub` children — **not** as proof the runtime
"must be old" (the conclusion rests on loaded entry-point paths and start times, not
counts). These observations describe the **global unpatched** runtime only and are
kept **separate from acceptance of the patched runtime**, whose runtime behaviour
remains **unproven** (only its on-disk deployment is verified, §3.4):
returned-vs-borrowed / idle release is **UNVERIFIED** (Step 4 retraction), and the
inter-session restart (Step 5) is an **inference**, not a lifecycle-confirmed fact.
Remaining action stays **owner-operated**: fully stop and re-spawn each
client's `dbhub` MCP child (Codex CLI + this desktop app's connector; `/mcp`
Reconnect is interactive-terminal-only and unavailable in the Code tab, Codex is
external), then re-run Steps 1–5 against a child whose entry-point is
`…\.claude\tools\dbhub\…`. The read-only host census used here is preserved at
`.claude/tmp/dbhub-proc.ps1` (git-ignored) for that re-verification. Strategy B
remains **BLOCKED**; its acceptance criteria are **not** claimed, and it was **not**
resumed.

### 3.7 Phase C — reopened-client verification (2026-09-24, read-only; no mutation / kills / restarts / commits)

Owner reopened the Claude desktop application and cleared the owner-confirmation
gate, directing: run a fresh census and record whether the prior launcher/server
pairs exited and the replacement's identity; **before querying DBHub, confirm this
session's actual server child loads the project-local patched
`…\.claude\tools\dbhub\node_modules\@bytebase\dbhub\dist\index.js`** (reading the
launcher alone is insufficient); if it still resolves global, report its parent /
launch config and **do not repeat idle measurements against the wrong runtime or
ask for another identical restart**; only once a patched connector is verified, run
the bounded read-only checks (attributable usage, headroom, idle release, QA
bootstrap), distinguishing server-side `Sleep` from client-side pool return.

**Step 1 — census — PASS.** All four previously-recorded **claude.exe**-owned DBHub
connectors (servers 47992 / 30876 / 64272 / 29376 and their launchers) have
**EXITED**; the app close/reopen did terminate them. **No claude-owned replacement
connector exists.** The surviving footprint is **6 processes = 3 launcher/server
pairs, all `codex.exe`-owned** (app PID 54348, app start 2026-09-23 09:46:48Z),
unchanged from Phase B (Codex was not reopened):

| Launcher PID (start) | Server PID (start) | Server entry-point | Install |
| --- | --- | --- | --- |
| 17400 (16:59:07Z) | 4192 (16:59:08Z) | `…\AppData\Roaming\npm\…\@bytebase\dbhub\dist\index.js` | GLOBAL-unpatched |
| 30488 (17:01:48Z) | 11840 (17:01:48Z) | (same global path) | GLOBAL-unpatched |
| 33040 (17:56:56Z) | 21020 (17:56:56Z) | (same global path) | GLOBAL-unpatched |

All launchers load `…\.claude\scripts\dbhub-mcp.cjs` under PPID 54348 (`codex.exe`).

**Step 2 — on-disk PASS; runtime BLOCKED.** The project-local install is re-verified
patched: `@bytebase/dbhub` **1.2.3**, `mariadb` **3.5.4**, cap
`createPool({ ...connectionConfig, connectionLimit: 2, minimumIdle: 0, idleTimeout: 60 })`
at `mariadb-DQ6P57IX.js:127` (with `connect()` then issuing a single
`SELECT VERSION()` ⇒ a patched connector opens **1** connection lazily at connect,
not 10). **But this session has no DBHub runtime to confirm.** This reopened Claude
session exposes **no `mcp__dbhub__*` tool**, and `ListMcpResourcesTool(server="dbhub")`
returned *"No resources found"* without spawning any process; a census re-run
immediately afterward still showed the **same 6 codex processes and zero
claude-owned dbhub process**. There is therefore **no "this session's actual server
child"** to verify against the project-local `index.js`. **A Claude app reopen alone
did not attach a (patched) DBHub connector to this session** — the connector is not
connected here at all.

**Step 3 — reported; no identical-restart request.** The only live DBHub connectors
are the **3 codex.exe global-unpatched** instances above; launch configuration =
launcher `…\.claude\scripts\dbhub-mcp.cjs` (PPID 54348 `codex.exe`) → server child
loading the **global** `index.js`. Per the owner's directive, no idle measurement
was repeated against this wrong runtime and **no further identical restart is
requested** — the actionable gap is that the Claude session lacks a connected
`dbhub` server, not that another app-reopen is needed.

**Step 4 — attributable usage / headroom / idle release — BLOCKED / UNVERIFIED.**
Read-only DB snapshot (existing container, `docker exec ddev-ppu-db`):
`max_connections` = **100**, `Threads_connected` = **1**, **`dbhub_ro` = 0**. There
are currently **zero DBHub connections of any kind** — neither the patched
signature (1 at connect) nor the unpatched eager-10 signature (§3.6) is present, so
no connection can be attributed and idle release cannot be exercised.
Returned-vs-borrowed stays **structurally UNVERIFIED** (no client-side pool-state
evidence, and now no connection to observe). Headroom is effectively full
(≈99 slots), i.e. the earlier saturation is absent this instant; this is a footprint
snapshot of the **unpatched** population at rest, **not** a demonstration of the
≤2 cap.

**Step 5 — QA bootstrap — PASS (independent of DBHub).**
`drush --uri=https://ppu-qa.ddev.site core:status` → Drupal **10.6.16**, Site URI
**https://ppu-qa.ddev.site**, DB name **qa**, Database **Connected**, Drupal
bootstrap **Successful**, Site path **sites/qa**; `RoutingModeResolver::effectiveMode()`
= **`development_fixed`** at runtime. Headroom this instant is ~full (not the
incidental scarce headroom of §3.6), but because **no DBHub connection was
exercised**, this still does **not** demonstrate the patched cap.

**Per-check verdict (Phase C).**

| Step | Check | Verdict |
| --- | --- | --- |
| 1 | Census: prior pairs exited? record replacement | **PASS** — 4 claude connectors exited; **no replacement**; 3 codex global-unpatched persist |
| 2 | Confirm this session's server child loads project-local patched `index.js` | **BLOCKED** — on-disk patched (1.2.3 / mariadb 3.5.4 / cap @127) re-verified, but **this session has no dbhub tool and spawned no dbhub process** ⇒ no runtime child exists |
| 3 | If global, report parent / launch config; no idle re-measure, no identical restart | **PASS (reported)** — only live = 3 codex global-unpatched under PPID 54348; no re-measure, no restart asked |
| 4 | Attributable usage / headroom / idle release; returned vs borrowed | **BLOCKED / UNVERIFIED** — `dbhub_ro`=0, `Threads_connected`=1/100; nothing connected to attribute or time |
| 5 | QA bootstrap (existing container, ppu-qa) | **PASS** — Drupal 10.6.16, DB `qa`, `sites/qa`, bootstrap Successful, `development_fixed` |

**Overall (Phase C): runtime patch acceptance remains NOT DEMONSTRATED.** The
patched on-disk deployment (§3.4) is confirmed but was **never loaded by any running
process attributable to this session**, and this reopened Claude session **cannot
exercise DBHub** (no connector, no tool). The owner reopen correctly terminated the
prior claude connectors but did **not** — and by itself cannot — yield a verifiable
patched claude connector; the connector must be **connected in the session** for the
project-local launcher to run and resolve the patched install. Source stays
**"ready for deployment"**, **not** "pool repair complete"; Strategy B remains
**BLOCKED** and was **not** resumed.

**Cleanup (Phase C):** the read-only census script `.claude/tmp/dbhub-proc.ps1` was
**removed** this phase per the owner's instruction (this supersedes the §3.6 note
that it was preserved); re-verification must recreate it. **Remaining unpatched
instances:** the 3 `codex.exe` connectors (servers 4192 / 11840 / 21020) — Codex was
not reopened, so its DBHub children still load the global unpatched build.

### 3.8 Phase D — attach diagnosis (2026-09-24, read-only; no config edit / spawn / kills / restarts / commits)

**Trigger.** Owner authorized making the existing project-local **patched** DBHub
available in this Claude session (attach, **not** reinstall), after first verifying
that Desktop configuration does not override the project entry.

**Configuration sources + precedence** (inspected fields only; no credentials / DSNs
printed):

| Store | dbhub state |
| --- | --- |
| `.mcp.json` `mcpServers` | **registered** — stdio launcher `…\.claude\scripts\dbhub-mcp.cjs` |
| `.claude/settings.local.json` | **enabled** — `enabledMcpjsonServers: [gitnexus, context7, dbhub]`; no `disabled*`, no `enableAll*`, no local `mcpServers` |
| `~/.claude.json` top-level `mcpServers` | **empty** — no user/Desktop-level dbhub |
| `~/.claude.json` `projects[ppu]` | `mcpServers: {}`, `enabledMcpjsonServers: []`, `disabledMcpjsonServers: []` — nothing disables dbhub; no PPU override |

⇒ dbhub is **correctly registered and enabled**, with **no overriding shared/Desktop
definition** and **no disabled flag**. No config change is warranted; an edit would be
a no-op or a forbidden duplicate registration.

**Project-MCP loading works (parity proof).** `gitnexus` is defined **only** in the
same `.mcp.json`, in the same enable list, but has **no runtime precondition** — and
its `mcp__gitnexus__*` tools **are live** in this session. So the client honors the
project `.mcp.json`; the difference is isolated to the dbhub launcher's connect step,
not to config loading.

**Launcher preconditions all PASS now** (read-only): `dbhub.toml` valid + exactly one
`mariadb://dbhub_ro@127.0.0.1/db` DSN; `docker port ddev-ppu-db 3306/tcp` →
`127.0.0.1:<port>`; project-local `dist/index.js` present; `patch-target.cjs`
`connectorState()==='patched'`. **Correction:** an initial "DDEV database is
unavailable" reading at the docker-port precondition was a **false negative** — the
Windows backslash docker path was mangled inside a `node -e '…'` string (JS consumed
`\r` / `\U`); re-running with the path passed via an env var succeeded, and a direct
`docker port` confirmed the mapping. The docker precondition genuinely passes.

**Runtime identity (host census + DB census, read-only).** 8 dbhub processes = **4
launcher/server pairs, ALL parented by `codex.exe` (PPID 54348)**; **zero
`claude.exe`-owned dbhub processes**, and this session exposes **no `mcp__dbhub__*`
tool**. Server children: 4192 / 11840 / 21020 = **GLOBAL(unpatched)** (started
16:59–17:56 local); **47000 = PROJECT-LOCAL(patched)** (launcher 34408, started
22:44 local) — the **first observed running patched server child anywhere**, proving
the launcher resolves and loads the on-disk patched install (§3.4) when a fresh
connector initializes. DB: `max_connections`=100, `Threads_connected`=1,
`dbhub_ro`=**0** (total and Sleep) — none of the 4 codex children currently holds any
DB connection (consistent with the inter-session DB restart severing pools; not
re-prewarmed since).

**Conclusion.** dbhub's absence here is **not** a project-config defect and **not** a
runtime-patch defect: it is a **client-side MCP connection state** — Claude's Code-tab
client has not established a `dbhub` connection for this session (most consistent with
a connect-time failure at this session's MCP-init, with no in-session auto-retry),
while gitnexus connected. Attaching requires the client's **supported reconnect /
re-init of the `dbhub` server** for this session; the interactive `/mcp` reconnect is
terminal-only and **unavailable in the desktop Code tab**, and no in-session tool can
trigger a reconnect. Unlike the earlier restart, this action is now **explained**:
every launcher precondition is verified satisfied and Codex's patched child (47000)
proves the launcher + patch work — the only missing element is a Claude-owned
connector.

**Per-check verdict (Phase D)** — configuration, tool discovery and execution kept
separate:

| Check | Verdict |
| --- | --- |
| Configuration (registered + enabled, no override) | **PASS** |
| Tool discovery (callable `mcp__dbhub__*`) | **FAIL** — absent; no dbhub process for this session |
| Execution (bounded read-only runtime checks) | **NOT EXERCISED** — no connector to exercise |
| Config change required | **NONE** — a no-op or a forbidden duplicate |

**Remaining unpatched instances:** the 3 `codex.exe` global-unpatched server children
**4192 / 11840 / 21020** (Codex was not reopened); its 4th connector (47000) is
patched. **Cleanup (Phase D):** temp census `.claude/tmp/dbhub-census.ps1` removed
after this read; recreate for the next attempt. Source stays **"ready for
deployment"**, **not** "pool repair complete"; Strategy B **not** resumed.

### 3.9 Phase E — first evidenced failing stage: MCP connect timeout under co-launch contention (2026-09-24, read-only; no mutation / spawn / kills / restarts / commits)

**Correction of scope.** Phases C–D concluded the reopened Claude session "never
connected" dbhub and framed the remaining work as a client-side reconnect. The
Code-tab MCP connection trace corrects that *interpretation*. History in §3.7–3.8
is preserved; this subsection supersedes their reading, not their measurements.

**Authoritative trace.** Claude Code (v2.1.229) writes one JSONL per connection
attempt to `…\AppData\Local\claude-cli-nodejs\Cache\C--Users-mo737-ppu\mcp-logs-dbhub\`
— 203 dbhub attempts, 2026-08-25 → 2026-09-24. Sanitized fields only (no
DSN / published port / credentials printed).

**First evidenced failing stage = `CONNECT_TIMEOUT`.** Newest attempt, session
`1bade85e…`, cwd = project:
- `Starting connection with timeout of 30000ms` (02:21:39.238Z)
- `Connection failed after 30052ms (CONNECT_TIMEOUT): MCP server "dbhub" connection timed out after 30000ms` (02:22:09Z)

The client spawned the server but the MCP `initialize`/connect handshake did not
complete within the 30000 ms deadline → server unregistered → no `mcp__dbhub__*`
tools this session. **Completion criterion met: the first evidenced failing stage
is identified.**

**Runtime is proven-good; the failure is intermittent.** The *same* session
connected dbhub in **3233 ms** at 2026-09-23T23:19:32Z (server banner
"Configuration source: environment variable / Connecting to 1 database
source(s)…") and then called `execute_sql` successfully 3× (78–306 ms) through
01:47Z. Launcher + project-local patched child + DSN + read-only account all
work; the 02:21 timeout is a *later reconnect* that stalled — not a config,
precedence, patch, or DSN defect.

**Trigger = host-wide cold-start contention, not dbhub-specific.** In the *same*
02:21:39 launch cycle, co-launched **gitnexus connected but took 26062 ms** (vs
its usual seconds) — also near the 30 s ceiling. dbhub, whose cold path is the
heaviest (launcher `docker port` lookup → child spawn → MariaDB pool → blocking
`SELECT VERSION()` before ready), crossed 30 s while gitnexus squeaked under.
Corroboration: **0 of the 17 timeout logs contain any server stderr** (no startup
banner, no `cjs/loader` crash, no launcher "DDEV unavailable" line) → a silent
stall in the cold-start window, not a crash or precondition failure. Measured
now: `docker port ddev-ppu-db 3306/tcp` ≈ 300 ms ×5, db `Up (healthy)` — the
trigger is absent on demand.

**Four Phase-D over-conclusions, resolved by this evidence:**
- "Client never connected" → **WRONG**: it connected (3.2 s) and used tools; the
  current no-tools state is a *subsequent* reconnect CONNECT_TIMEOUT.
- "Passing launcher preconditions ≠ successful init" → **CONFIRMED**: preconditions
  are fast/pass, yet initialize still timed out under contention.
- "Codex patched child = entry-point only" → unchanged.
- "GitNexus working doesn't exonerate dbhub" → refined: gitnexus's *26 s* in the
  same cycle *implicates a shared host stall* and isolates dbhub as the
  heaviest-cold-start victim, not a uniquely broken server.

**§3 bounded probe — DECLINED (smallest justified action).** The trace is
available and conclusive as to stage, and well-supported as to trigger. With
docker ≈ 300 ms, db healthy, and no co-launch contention now, the authorized
launcher probe would connect fast and prove only "the launcher works in the
probe's context" (the owner's stated non-probative outcome) without reproducing
the intermittent contention event. It yields ~zero marginal evidence, so it was
not run. No probe processes created; nothing to clean up.

**Proven vs uncertain.** *Proven:* failing stage = 30 s MCP connect timeout;
runtime otherwise healthy (same-session 3.2 s connect + working `execute_sql`); a
shared host slow-launch (gitnexus 26 s) coincided in the same cycle. *Not proven:*
the exact resource that stalled at 02:21Z (Docker Desktop / mutagen resume, disk,
or CPU at session launch) — it did not reproduce during this read-only window.

**Smallest justified next action / owner action.** No config, patch, or source
change is warranted for the connection itself (config correct, runtime proven).
dbhub tools return on the next healthy connection cycle; an in-session `/mcp`
reconnect (terminal-only, unavailable in the Code tab) during a warm window would
attach it, as the 23:19 cycle shows. *Optional durable hardening (source change;
NOT done here, needs authorization):* add an explicit `timeout` + fail-fast to the
launcher's `execFileSync(docker port …)` so a docker stall surfaces an immediate
captured error instead of a silent 30 s CONNECT_TIMEOUT. **Strategy B remains
BLOCKED and is not resumed.**

## 4. Cases — status

All seven binding cases are **BLOCKED** (not attempted at runtime). No fixture
was written; no confirmation, refusal, or rollback was executed. The source-level
provability of each is characterized in §2.3.

## 5. Cleanup and safety state

- Read-only preflight temp file `preflight-readonly-temporary.php` removed from
  host and container; absence verified on both (`ABSENT`).
- No transaction was opened; no fixture rows created; nothing to roll back.
- No source edits. No commit, reset, stash. No migration. No cache rebuild
  (no PHP source changed). No default-site command.
- Source parity (host == container SHA-256) had been reconfirmed for the target
  files earlier this session before the connection pool saturated.

## 6. What remains for Strategy B

1. Free the QA connection pool. The durable pool-cap fix is now **implemented** as
   a project-local, reproducible runtime (§3.4); the remaining stage is
   owner-operated — disable/re-enable the `dbhub` MCP server in each Codex/Claude
   client (or close surplus sessions) so running instances are replaced by the
   capped (≤2, lazy) pool — after which the bounded read-only runtime verification
   can run. **Phase B (§3.6) ran this verification and found the reported reload
   ineffective:** every running connector still loads the **global unpatched**
   build (all spawned 2026-09-23 ≤ 19:19Z, before the §3.4 deployment). The
   replacement must **actually terminate and re-spawn** each client's stdio `dbhub`
   child so its entry-point becomes `…\.claude\tools\dbhub\…`; re-run §3.6 Steps 1–5
   against that fresh child to confirm the ≤2 lazy cap. **Phase C (§3.7) confirms the
   prior claude connectors exited but the reopened Claude session has no connected
   `dbhub` server at all (no `mcp__dbhub__*` tool, no spawned process), so no patched
   runtime could be verified from it; the census script was removed and must be
   recreated for the next attempt.** **Phase D (§3.8) then diagnosed the attach: the
   project entry is correctly registered and enabled with no Desktop/user override and
   every launcher precondition satisfied, so no config edit applies; the blocker is a
   client-side MCP reconnect of `dbhub` for this Claude session, which cannot be
   triggered in-session (interactive `/mcp` is terminal-only). Phase D also recorded
   the **first observed running patched server child anywhere** — Codex's PID 47000
   (project-local `…\.claude\tools\dbhub\…`), evidence the launcher + on-disk patch
   load correctly when a fresh connector initializes.**
2. Run the bounded probe: draft-seeded submission → real `DispatchOwner` session
   → `createCheckoutHold()` persisting the composite binding → reload accepted
   snapshot via `CheckoutAttemptStore` → unchanged-job confirmation first, then
   the mismatch cases through the real planner + `ResourceCalendarGuard`.
3. Record, per §7 of the spec, that the decisive comparison for binding-identity
   cases occurred under the guard's row locks, and explicitly label the
   job-facts-change cases as in-memory-digest comparisons (per §2.3 gap).
4. Explicit rollback in `finally`, independent post-exit fixture-absence check,
   captured outer exit code.

Task 24B stays **PARTIAL**: real Commerce order / checkout-display integration,
concurrency/payment acceptance, and browser acceptance remain out of this run.

---

## 7. Strategy-B guarded-confirmation preflight — resumed under the owner's bounded DBHub exception (2026-09-24)

**Authorization.** The owner authorized resuming this Strategy-B
acceptance-and-targeted-fix task and, *for this task only*, replacing DBHub's
read-only schema/metadata checks with equivalent checks through the validated,
already-running DDEV container using existing least-privilege credentials. This
overrides the DBHub-only blocking condition in PROMPT_SPEC §4. It does **not**
waive any other prerequisite, expand fixture-write authority, or change DBHub's
launcher, timeout, dependencies, pool configuration, or client registration.
DBHub MCP calls stay **suspended**; all schema/metadata below was read read-only
through `docker exec ddev-ppu-web … drush --uri=https://ppu-qa.ddev.site …` at
`/var/www/html`, every command `rtk`-prefixed, no credential output.

### 7.1 Evidence separation (per owner directive §2) — DBHub status stays open

- **Confirmed:** a DBHub MCP connection attempt exceeded Claude Code's 30 000 ms
  connect deadline (`Connection failed after 30052ms (CONNECT_TIMEOUT)`, §3.9).
- **Confirmed:** earlier connections and queries in the same session succeeded
  (a 3 233 ms connect; three `execute_sql` calls).
- **Unproven:** the exact stalled component; host-wide causation; and the
  patched-runtime identity of those earlier successful queries, unless
  independently correlated (§3.6 found every *running* child global-unpatched).
- **Open:** DBHub startup reliability and pool-runtime acceptance.
- **Non-claim:** the successful QA reservation preflight below does **not** fix,
  and is not reported as fixing, DBHub. Read-only metadata access is kept
  separate from the fixture writes authorized by the Strategy-B spec.

### 7.2 Read-only preflight gates — all satisfied (no fixture, no mutation)

Measured via bounded `docker exec` Drush / `php:eval` calls; outputs abbreviated:

- **§6.1 Source parity — PROVEN.** SHA-256 of the 10 binding-path files is
  identical host ↔ container (`DispatchPlanner`, `DatabaseReservationStore`,
  `ResourceCalendarGuard`, `LockedHold`, `DispatchRequestFactory`,
  `DispatchClaim`, `CheckoutAttemptStore`, `CheckoutAttempt`, `QuoteSnapshot`,
  `AddressDistanceHandler`). Mutagen sync does not by itself prove QA-only source.
- **§6.2 Runtime identity — CONFIRMED.** `core:status`: database `qa`, site path
  `sites/qa`, bootstrap Successful, Drupal 10.6.16, profile `standard`, schema
  `update_10047`. `RoutingModeResolver::effectiveMode()` = `development_fixed`
  (unconditional DDEV branch; no settings/credential read, no network). Active
  module path `modules/custom_webform_handlers`. All 8 checkout-attempt snapshot
  columns and the 4 reservation `price_snapshot_*` columns present.
- **§6.3 Transaction / trigger state — CONFIRMED.**
  - No ambient transaction at probe entry (`inTransaction()` = 0).
  - Nested-transaction behavior of *installed* Drupal 10.6 shown empirically
    without destructor reliance: outer BEGIN → 1, inner (savepoint) → 1, explicit
    inner `rollBack()` → still 1 (root open), explicit outer `rollBack()` → 0.
  - **Zero database triggers** in the whole `qa` schema (prefix-proof: DB prefix
    is empty, driver `mysql`/MariaDB) ⇒ no trigger side effect on any write.
- **§6.7 Cleanup mechanism — DEMONSTRATED.** An explicit
  `$transaction->rollBack()` returns `inTransaction()` to 0 and writes nothing;
  this is the mechanism the probe uses in `finally`.

### 7.3 Side-effect / ECA inventory — CLOSED for a rolled-back probe (owner §4)

Resolves the outstanding ECA/save-side-effect gate the owner required closed
*before* fixture writes:

1. **DB triggers:** 0 (empirical, §7.2).
2. **`custom_webform_handlers.module`:** only `hook_mail` + `hook_cron`; **no**
   entity / webform-submission save hooks.
3. **`AddressDistanceHandler`:** `postSave()` returns immediately for a
   non-completed (DRAFT) submission and for updates; `preSave()` makes **no**
   network call in `development_fixed` — the route figure is a server-owned DB
   read (`RouteFactStore`), and a NULL fact fails **closed** to Manual Review
   plus a warning log, never an outbound call.
4. **ECA:** the two `config/optional/` models (`route_warm`, `popularity`) are
   keyed on the domain events `reservation_confirmed` / `review_transitioned`,
   **not** on submission save.
5. **`DomainEventEmitter::emit()` (load-bearing):** when a transaction is open it
   defers via `TransactionManager::addPostTransactionCallback(fn (bool $success)
   => $success ? dispatch : nothing)` and dispatches **only on commit**; on
   rollback `$success` is false and the event is **dropped**. The probe runs its
   confirmation inside the explicit outer transaction and always rolls back, so
   `reservation_confirmed` is **never dispatched** ⇒ neither ECA model fires ⇒ no
   route-warm / popularity reaction, no network, inside or after the probe txn.
   (Emit dispatches synchronously only when no transaction is open — not the
   probe's path. The probe-local no-network guard in §7.4 defensively covers the
   `addPostTransactionCallback`-throws fallback branch regardless.)

### 7.4 Remaining probe-design gates — realized inside the probe, before any fixture

- **§6.4 no-network denial:** the probe installs a probe-local guard so a
  synchronous listener could not reach an external distance/route provider; the
  route figure is pre-seeded as a `RouteFactStore` fact so the estimator boundary
  never calls out. Demonstrated in a dry-run pass before any fixture is written.
- **§6.5 synthetic identifiers + cleanup ledger:** reserve unused synthetic
  submission / reservation / token identifiers under a unique task tag; record an
  exact ledger for the independent post-exit absence check.
- **§6.6 real storage / public constructors:** seed a **DRAFT** `hg` submission
  with synthetic non-empty pickup / drop-off addresses (so
  `DispatchRequestFactory::build()` does not return NULL) via supported public
  APIs; no suppressed side effects. `hg` is a dispatch workflow
  (`dispatch_enabled: true`, `reservation_enabled: false`).

STOP gate honored: **no fixture is written until §6.4 no-network isolation is
installed and demonstrated.** Schema, source parity, tools, and the cleanup
mechanism are proven (§7.2); no-network isolation is the last unproven item and
is built into the probe.

### 7.5 Case-execution note (supersedes §4's BLOCKED framing for this run)

With the bounded exception the seven §7 cases are no longer blocked on DBHub.
The mandatory **stale-object** case targets the source-documented residual — the
under-lock digest recompute reads the in-memory submission
(`DispatchPlanner.php:514-536`), not a fresh persisted read;
`confirmWithRevalidation` re-reads only the reservation row under lock, and the
calendar guard locks resource/day rows, not the submission row. That case is
expected to **reproduce / characterize** the gap, not be fixed here: closing it
needs a schema change (submission-revision CAS or planning from persisted
immutable facts), which PROMPT_SPEC §8 places out of scope ⇒ honest outcome is a
bounded follow-up proposal, not a fix.

### 7.6 No-network mechanism for the confirm path — corrected (supersedes §7.4's RouteFactStore pre-seed)

Direct read of active source establishes that the planner's confirm-under-lock
route estimate is offline **by construction** in `development_fixed`, so the
§6.4 no-network denial needs no RouteFactStore pre-seed:

- `DistanceProviderRouteEstimator::estimate()` short-circuits to the offline
  branch the moment the mode is fixed — `if ($this->routingModeResolver?->isDevelopmentFixed())
  return $this->fixedDevelopmentEstimate(...)`
  (src/Dispatch/Route/DistanceProviderRouteEstimator.php:99-101). The scheduled/
  ordinary chains, whose adapters fail closed at the socket, are never reached;
  the dev branch asks only the development provider (:199-259).
- `FixedDevelopmentRouteProvider::calculate()` "never opens a socket": it is
  deterministic (empty `FIXTURES` ⇒ every usable pair resolves to the fallback
  19312 m / 1680 s), stamps provider id `development_fixed`, `trafficAware=FALSE`,
  and its constructor takes a nullable `TimeInterface` "so direct construction in
  a probe needs no container" (src/Distance/FixedDevelopmentRouteProvider.php:40-116;
  PROVIDER_ID :45).
- Locked revalidation admits that fact only while the resolver still reports the
  mode and only for the fixture's own id (`accepts()` :295-309), so a development
  fact can never masquerade as live traffic.

Therefore §6.4 is demonstrable as a REAL positive assertion, not a mock: assert
`RoutingModeResolver::isDevelopmentFixed() === TRUE`, run one real estimate for
the synthetic pair, and assert the returned `RouteEstimate->provider` is
`development_fixed`. That id can only originate from the offline branch, so it is
direct evidence that no network provider answered.

`RouteFactStore` (src/Distance/RouteFactStore.php:12-54, 55-116) is a
`PrivateTempStore` carrier **scoped to one browser session** (owner-partitioned
via `RouteRequestOwner`) for the AJAX preview→reprice path — not the planner's
confirm-lock estimate source. §7.4's "pre-seeded RouteFactStore fact" is
superseded: the confirm fixture needs only `development_fixed` (already active)
plus synthetic non-empty, non-identical pickup/drop-off addresses.

---

## 8. Strategy-B guarded-confirmation — EXECUTED (2026-09-24, real public chain, explicit rollback)

**Terminal result.** `RESULT 47 passed / 0 failed; Strategy B guarded-confirmation
(real public chain, explicit rollback)`, captured outer exit **0**
(`OUTER_EXIT=0`). This **supersedes §4's BLOCKED framing** and completes the §7
preflight. Judged by the printed `RESULT:` line, not `$?` — `drush php:script`
swallows explicit exit codes (a bare success falls through to 0).

Executed through the validated, already-running QA container, DBHub MCP kept
**suspended**:

```text
docker exec -w /var/www/html ddev-ppu-web \
  vendor/bin/drush --uri=https://ppu-qa.ddev.site php:script \
  …/strategy-b-guarded-confirmation-fixture-temporary.php
```

`rtk`-prefixed, `MSYS_NO_PATHCONV=1`, native `docker.exe`. No DBHub call; no
launcher / pool / timeout / registration change; no `ddev`/Docker
start/restart/recreate; no commit. Host↔container SHA-256 parity reconfirmed for
the probe immediately before the run (`6a97eefc…653d2a8b`, identical both sides);
`php -l` clean.

### 8.1 Cases — PASS/FAIL/BLOCKED (all through the real planner + ResourceCalendarGuard)

Five executable cases cover the three PROMPT_SPEC-mandatory acceptances (unchanged
positive control; identity mismatch at identical price/route/window; persisted-
change stale-object) plus binding-axis isolation and the legacy skip. Every case
seeded a real **DRAFT** `hg` submission, ran the real
`offerSlots → createCheckoutHold → CheckoutAttemptStore::open → fresh
CheckoutAttemptStore::loadByOrder → confirmCheckoutReservation` chain, and
reloaded the accepted snapshot through a **fresh** store instance. `bindingIdentity()`
was supplied through the method contract, never hand-built. No mock, no reflection.

| Case | Intent | Real reservation | Outcome | Result |
| --- | --- | --- | --- | --- |
| **C1** | Unchanged accepted job (mandatory positive control) | Hold held, reservation **28**, `AMOUNT_CENTS=2950` | **CONFIRMS** (`isConfirmed` + reservationId); no refusal NOTICE | **PASS** |
| **C4** | Different job (subB), *identical* price + route seconds + route meters + reserved window; only job identity differs | Hold held on subA | **REFUSED**; **job-facts** axis NOTICE captured; NOT the snapshot-binding axis; accepted price still 2950 | **PASS** |
| **C5** | Correct job (subA) but a mismatched current-format `acceptedSnapshotId` (subB's `v3:` binding) | Hold held on subA | **REFUSED**; **snapshot-binding** axis NOTICE captured; NOT the job-facts axis; presented job digest still matches accepted (isolates the binding axis) | **PASS** |
| **CS** | Retain originally loaded submission, change *saved* material job facts after acceptance, confirm with the original object | Hold held, reservation **31** | **CONFIRMS** with the stale original while the persisted job now differs — the documented in-memory-recompute boundary; no refusal NOTICE (genuine confirm, not a masked refuse) | **FAIL — see §9 (mislabeled PASS)** |
| **C7** | Legacy caller with no identity (`''` + `''`) | Hold held, reservation confirmed | **CONFIRMS** (both axes skipped); no refusal NOTICE | **PASS** |

C4 is the decisive PROMPT_SPEC §2 assertion: a job edited between acceptance and
confirmation is refused **even though fixed price, route duration and timing are
identical** (asserted equal in-run: `C4 price identical`, `C4 route seconds
identical`, `C4 route meters identical`, `C4 job identity DIFFERS`).

### 8.2 Discriminated refusal — exact NOTICE strings, single logger-channel seam

Both `dispatch_planner` and `dispatch_reservation_store` log on the one channel
`logger.channel.custom_webform_handlers`. A single narrowly-scoped probe observer
(anonymous `Psr\Log\AbstractLogger` subclass added via `->addLogger()` at that
existing seam — no mock, no reflection, §7-permitted) captured both refusals and
discriminated the axes by the planner's verbatim static NOTICE text:

- **Snapshot-binding axis** — `the accepted price snapshot is not the one this
  reservation was held for`
- **Job-facts axis** — `the accepted job no longer matches the submission`

Drush emitted the same NOTICEs on the wire, confirming the real path fired:
`Dispatch confirmation refused on hg: the accepted job no longer matches the
submission, though its price and reserved duration are unchanged.` →
`Dispatch Hold 29 failed revalidation at confirmation: invalid_request.` (C4), and
`… the accepted price snapshot is not the one this reservation was held for.` →
`Dispatch Hold 30 failed revalidation at confirmation: invalid_request.` (C5).
Binding is checked **before** job-facts and returns on refuse, so C5 (correct job,
wrong binding) refuses on the binding axis while the job-facts axis it would have
passed is never reached — the two axes are proved independent.

### 8.3 Shared-resource-day capacity finding + per-case savepoint isolation (probe-design, not a module defect)

A confirmed reservation consumes real bookable capacity: an earlier single-
transaction run of this probe dropped the bookable count **18 → 15** after C1's
confirm, and the real planner then refused the neighbouring holds
(`C4_HOLD_REFUSED reason=SlotUnavailable`, `C5 SlotUnavailable`,
`CS RepositioningInfeasible`) before their two-axis confirm assertions could run —
`RESULT 29 passed / 3 failed`. That was a **fixture-isolation flaw, not a module
defect**: capacity enforcement is exactly the behaviour under test and was left
intact.

Fix (probe-only): all three drafts are seeded up front (drafts consume no
dispatch capacity, so seeding is calendar-neutral), and each case is wrapped in
its **own nested savepoint** — `$sp = $db->startTransaction(); … $sp->rollBack();
unset($sp);` — rolled back after its assertions. Rolling back the savepoint
discards that case's reservation-row INSERT, restoring slot **visibility** for the
next case even though the outer transaction's `FOR UPDATE` row locks persist (one
connection re-locks its own rows without deadlock). The empirical proof is in the
run: `BOOKABLE_SLOTS=18` and **every** `C{n}_BOOKABLE_AT_START=18` — full capacity
restored before each case, so all five holds held. A LIFO-safe guard in `catch`
rolls back a still-open savepoint before the `finally` outer rollback.

The **route-fact warning is NON-FATAL** for both hold and confirm in
`development_fixed` (`Authoritative quote could not be persisted: no valid
server-owned route fact …` appears at seed time yet C1/C7/CS all confirm through
it) — consistent with §7.6: the confirm-under-lock estimate is offline by
construction, so no `RouteFactStore` pre-seed is required.

### 8.4 In-memory-recompute boundary (CS) + versioned composite-binding verdict

CS characterizes the source-documented residual (`DispatchPlanner.php:514-536`):
the under-lock digest recompute reads the **in-memory** `$submission` captured by
the confirm callback, not a fresh persisted read;
`store->confirmWithRevalidation()` re-reads only the reservation row under lock and
the calendar guard locks resource/day rows, **not** the `webform_submission` row.
So confirming with the *original* accepted object after the *saved* facts change
**CONFIRMS** (`CS_STALE_CONFIRMS reservation=31 persisted_now_differs=yes`), while
the run independently proves a fresh reload **would** refuse (`CS persisted job
digest now DIFFERS from accepted`, re-derived via `loadUnchanged`). This is the
honest boundary, **characterized not fixed**: closing it needs a schema change
(submission-revision CAS, or planning from the persisted immutable facts) that
PROMPT_SPEC §8 places out of scope ⇒ bounded follow-up, not a repair here.

The **versioned composite binding is sound as-implemented**: C1 (matched) confirms;
C4 refuses a changed job at identical price/route/window (job-facts axis); C5
refuses a mismatched current-format `v3:` binding while the job matches (snapshot-
binding axis); C7 preserves the genuine legacy `''`/`''` skip. No new reservation
identity column or update hook was needed or added.### 8.5 Reversibility — in-process + independent separate-process check

- **In-process** (same run, after the explicit `finally` rollback):
  `ROLLBACK explicit outer transaction rollback executed` →
  `post-rollback no active transaction`, and fresh `loadByOrder(899000301..305)`
  all `=== NULL`.
- **Independent separate-process** (a *second* QA Drush `php:eval`, fresh
  bootstrap, real `CheckoutAttemptStore`): `DB=qa`,
  `ATTEMPTS_PRESENT_FOR_SYNTH_ORDERS=0`, `SEEDED_DRAFT_ROWS_32_33_34=0`,
  `INTX=no`, outer exit **0**. The seeded drafts (sids 32/33/34) and every
  synthetic order (899000301–305) are absent; nothing committed.

Auto-increment counters advanced (drafts and reservations 28/31 were allocated
then rolled back); those gaps are **expected rollback residue**, not retained
rows. The synthetic order ids are references inside the store fixture, not proof
of real Commerce entities — none were created.

### 8.6 Harness / transport findings (carried, re-confirmed this run)

- **CLI synthetic-session boundary:** `DispatchOwner::ownerId()` needs a request
  with a session; the probe pushes a real `Symfony … Request` + `new Session(new
  MockArraySessionStorage())` (never persisted) and pops it at the end. Disclosed
  harness boundary, not a stored session.
- **`HoldResult` accessor:** the held Hold is the **public property**
  `$holdResult->hold`; `held()` is a static constructor, not an instance accessor.
  `isHeld()` gates it.
- **Transport:** `MSYS_NO_PATHCONV=1` is required so Git-Bash leaves the container
  path (`ddev-ppu-web:/var/www/html/…`) and Windows-form `docker cp` source
  (`C:/Users/…`) unmangled; host `sha256sum` uses the Git-Bash form (`/c/Users/…`).
  Parity was proved host==container==`6a97eefc…653d2a8b` before running.
- **Exit codes:** judged by the printed `RESULT:` line; `drush php:script`
  swallows explicit exit codes.

### 8.7 Cleanup

Both temporary probes removed and absence verified on **host and container**:
`strategy-b-guarded-confirmation-fixture-temporary.php` and
`strategy-b-preflight-readonly-temporary.php`. The directory retains only the
permanent `EVIDENCE.md` and `PROMPT_SPEC.md` on both sides. No source edit, no
cache rebuild (no shipped PHP changed), no commit/reset/stash.

### 8.8 Verdict

> **⚠ RETRACTED 2026-09-24 — superseded by §9.** The two bullets below are preserved
> verbatim as the historical (incorrect) conclusion of the 47/0 run; they are NOT the
> current status. CS is a **FAIL** (the spec requires the stale-object confirmation to
> be refused; it was accepted), a module defect **was** reproduced, and Strategy B is
> **INCOMPLETE**. "47/0" reflects an incorrect CS acceptance assertion, not compliance.

- ~~**Strategy B (guarded-confirmation): COMPLETE.**~~ **[RETRACTED — see §9]** All five executable cases pass
  (three mandatory acceptances + both binding-axis isolations + legacy skip),
  explicit rollback, independent fixture-absence check, and temporary-file cleanup
  all satisfied, with a verified `RESULT 47 passed / 0 failed` and outer exit 0.
- ~~**No module defect was reproduced**~~ **[RETRACTED — a defect WAS reproduced; see §9]**, so no source change was made: the two-axis
  guard behaved correctly; the CS in-memory-recompute residual is characterized as
  a bounded PROMPT_SPEC §8 follow-up (out of scope to fix here); the earlier
  capacity failures were a probe-isolation flaw, now fixed in the probe only.
- **Task 24B stays PARTIAL:** real Commerce-order / checkout-display integration,
  concurrency & payment-callback idempotency, and browser acceptance remain
  outstanding. Do **not** auto-advance to 24C–24K.
- **DBHub status stays OPEN / separate** (§7.1): this successful reservation QA is
  **not** reported as fixing DBHub startup reliability or pool-runtime acceptance.

## 9. CORRECTION (2026-09-24) — §8 RETRACTED: Strategy B INCOMPLETE, CS is a FAIL

**Authority.** The owner reviewed the §8 run and ruled, verbatim: *"Strategy B is
NOT COMPLETE. CS confirmed after persisted job facts changed; the required outcome
was rejection. '47/0' reflects incorrect acceptance assertions, not specification
compliance."* This section is the authoritative current status. §8 (its 8.8 verdict
and the CS row) is preserved only as the historical record of the incorrect run.

**What was wrong.** The CS case — retain the originally loaded submission, change the
*saved* material job facts after acceptance, then confirm through the original
object — was asserted to EXPECT the observed CONFIRM. Per PROMPT_SPEC §2/§4/§7 the
required outcome is **refusal**. The probe encoded the defect as the expected
result, producing a false `47 passed / 0 failed`.

**Reclassification / retractions:**
- **CS: FAIL.** Confirming after the accepted job's persisted facts changed is the
  E09-class failure Task 24B exists to prevent.
- **A module defect WAS reproduced** (retracts §8.8 "no module defect was
  reproduced"). Source-established mechanism: guarded confirmation recomputes the
  job-facts digest from the **in-memory `$submission` captured before the lock**
  (`DispatchPlanner.php` `confirmFor` → locked callback), and
  `confirmWithRevalidation` re-reads only the reservation row — never the persisted
  submission — so a stale object confirms while a fresh persisted read would refuse.
- **Strategy B: INCOMPLETE. Task 24B: PARTIAL.**

**No schema change is assumed.** The persisted submission facts already exist in
storage; the candidate correction is the smallest persisted-facts freshness read
INSIDE the guarded transaction (recompute the accepted-job digest from freshly
loaded persisted data, bypassing the entity static cache), within the owning method
per PROMPT_SPEC §8. Whether a *safe* fix fits the narrow scope — given entity
caching, transaction visibility, and that resource-day locks do NOT lock the
submission row (so they do not alone prove concurrent submission-writer safety) — is
under evaluation; if it exceeds scope, execution stops with the precise required
expansion, not a completion claim.

**Corrective work (PENDING execution at time of writing):** (1) repair probe
assertions — CS asserts refusal after verifying the saved change alters the digest,
retain the unchanged-job control + per-case savepoints, and prove a deliberate
assertion failure yields a nonzero OUTER exit after rollback; (2) reproduce against
the real planner/store/guard, then evaluate the minimal in-scope fix (or request a
bounded expansion); (3) map full §7 coverage case-by-case; (4) independent cleanup
across attempts, reservations, submissions, and any new guard rows.

## 10. RESOLUTION (2026-09-25) — §9 corrective work COMPLETE: fix applied, CS REFUSES, full §7 coverage green, cleanup independently verified

**Status now.** Strategy B guarded-confirmation **acceptance and cleanup PASS**. The
§9 retraction's stated reason — *"CS confirmed after persisted job facts changed; the
required outcome was rejection"* — is **resolved**: CS now **REFUSES on the job-facts
axis** against the real planner / store / ResourceCalendarGuard, with the originally
loaded submission object retained in memory and the persisted accepted-job digest
proven to differ at confirm time. All changes remain **uncommitted**; **no schema
change**; QA at `update_10047`, `ppu` at 10046. This section is the authoritative
current status; §8 stays as the incorrect-run record, §9 as its retraction.

### 10.1 The fix (Instruction 3) — smallest persisted-facts freshness read, in the owning method

`DispatchPlanner::confirmFor`'s locked revalidation callback previously recomputed the
accepted-job digest from the **in-memory `$submission` captured before the lock**. It
now, gated on a non-empty accepted job-facts digest, reloads the submission **fresh
inside the guarded transaction** via injected `EntityTypeManagerInterface` →
`getStorage('webform_submission')->loadUnchanged($submissionId)` (bypasses the entity
static cache; same-connection read-your-writes under the txn), rebuilds the request
endpoints from that fresh entity, and recomputes the digest from persisted data before
`hash_equals`. Fail-closed (`InvalidRequest` + notice) if the reload is not a
`WebformSubmissionInterface` or `fromSubmission()` yields NULL. Wiring: a 14th ctor
arg + `- '@entity_type.manager'` in `custom_webform_handlers.services.yml`. Two files,
module-scoped, no schema. `php -l` clean; QA `drush cr` recompiled the DI wiring.

**Freshness scope actually exercised (closeout item 2 — honest boundary).** The CS
case saved the endpoint change and then reloaded the submission on the **same
database connection inside the same outer transaction** as the confirmation. What is
therefore proven is same-connection / same-transaction **read-your-writes** of an
edit persisted *before* confirm. `loadUnchanged()` only bypasses Drupal's entity
**static cache**; it does **not**, by itself, establish visibility of a *different*
connection's commit under this transaction's isolation level. The claim is limited to
the scenario exercised — a change persisted before confirmation, re-read on the same
connection. Cross-connection / concurrent-writer visibility is a separate matter,
addressed (and left open) in §10.6; it is not claimed here.

### 10.2 Second finding (binding-gate legacy downgrade) — closed, reachability disclosed

While mapping §7, an **empty accepted binding id** combined with a **current-format
(`v3:`) stored** binding fell through the legacy branch and **CONFIRMED** — the R6
"current-format record must not downgrade into acceptance" gap. Fixed by adding
`$storedIsCurrentFormat` as a refusal disjunct. **Honesty:** the sanctioned caller
`DispatchCheckoutManager` always passes `$accepted->bindingIdentity()` (non-empty
`v3:`), so this is **not reachable in production** — the fix is spec-conformance /
defence-in-depth, with R7 (genuine NULL stored binding) as the positive control that
it does not over-refuse.

### 10.3 Full §7 coverage (Instruction 4) — 10 cases, RESULT 91 passed / 0 failed, OUTER_EC=0

Extended probe over the real public chain (real `DispatchPlanner` + `CheckoutAttemptStore`
+ `DatabaseReservationStore` + `ResourceCalendarGuard`), one outer transaction, per-case
nested savepoints, refusals discriminated by the single `custom_webform_handlers`
logger channel:

| Case | Scenario | Expected | Observed |
| --- | --- | --- | --- |
| C1 | Unchanged valid job | CONFIRM (control) | CONFIRMS, no NOTICE |
| C4 | Different job, identical price + route seconds/meters + window | JOB refuse | REFUSED, JOB axis, price unchanged |
| C5 | Correct job, wrong current-format binding | SNAP refuse | REFUSED, SNAP axis, digest matches accepted |
| CS | Saved endpoint change, confirm via stale in-memory object | JOB refuse | REFUSED, JOB axis; stale held DEST_A; persisted digest differs |
| R3 | Saved non-endpoint (weight) change | JOB refuse | REFUSED, JOB axis; endpoints/window unchanged |
| R4 | Two snapshots, one QuoteResult (equal price-only snapshotId, different composite binding) | SNAP refuse | REFUSED, SNAP axis; snapshotId identical, binding differs |
| R6a | Malformed current-format (`v3:`+40 zeros) binding | SNAP refuse | REFUSED, SNAP axis |
| R6b | Bare non-current binding (64 hex, no `v3:`) | SNAP refuse | REFUSED, SNAP axis |
| R6c | Empty accepted vs current-format stored (2nd finding) | SNAP refuse | REFUSED, SNAP axis (PRE-FIX this CONFIRMED) |
| R7 | Genuine NULL stored binding + empty accepted (legacy) | CONFIRM | CONFIRMS, no NOTICE (no over-refusal) |

CS is the §9-decisive assertion, now inverted to the specification-required refusal.
C4 remains the decisive PROMPT_SPEC §2 case (identical price/route/window, different
job → refused). R7 proves the legacy tolerance is preserved, not broken by the R6c fix.
Reservations observed: C1=43, R7=52; holds 44–52; post-rollback all ten synthetic
orders (899000301–310) load NULL.

### 10.4 Nonzero-exit discipline (Instruction 2)

The probe throws `\RuntimeException` when `$fail > 0`, **after** the `finally` rollback.
Green run ⇒ fall-through ⇒ **OUTER_EC=0** (read via `echo OUTER_EC=$?` inside the same
container shell, not through a pipe). A prior window proved a deliberate assertion
failure yields a nonzero outer exit after rollback, so 91/0 + OUTER_EC=0 is a real
pass, not a swallowed failure (`drush php:script` exit-code caveat honoured).

### 10.5 Independent cleanup (Instruction 5) — separate process, all sweeps 0

After the probe process exited (own outer rollback done; its post-rollback loadByOrder
loop over orders 899000301–310 already NULL), a **separate** read-only Drush process
(fresh connection) verified:

- **Deterministic synthetic-id sweep — all 0:** `commerce_order`, `commerce_order_item`
  (order_id 899000301–310); `custom_webform_handlers_checkout_attempt`,
  `custom_webform_handlers_dispatch_reservation` (by order range AND submission_id
  41–44); `custom_webform_handlers_reservation`, `webform_submission`,
  `webform_submission_data` (sid 41–44).
- **Recent-write sweep (NOW-1800s) — all 0:** `dispatch_guard`, `dispatch_status`,
  `dispatch_notification`, `dispatch_notify_claim`, `routing_budget`, `routing_usage`.
- **Epoch sanity:** guard/reservation latest committed write ≈ 5.4 days before now, and
  `routing_usage` is empty (MAX=0) — the sweep window is valid (columns are epoch ints)
  and the zero-cost boundary held (no paid-route usage written).

**Guard-row cleanup — structural guarantee + honest baseline gap (closeout item 4).**
The primary cleanup guarantee is **structural, not sweep-based**: the entire §7 probe
ran inside **one outer transaction** that is rolled back in the `finally` (§10.3/§10.4).
Every `dispatch_guard` write `ResourceCalendarGuard` performed during a confirm attempt
— including the successful C1 (reservation 43) and R7 (reservation 52) confirms — was
part of that outer transaction and was therefore **never committed**; it is discarded by
the rollback. The post-rollback synthetic-id sweep (all 0) and the ≈5.4-day-old latest
committed guard write are consistent with this.
**Owner-flagged insufficiency, acknowledged.** The `NOW-1800s` recent-write sweep for
`dispatch_guard` is corroborating only and is **not** a substitute for a per-identifier
differential. The rigorous form the closeout asks for — capturing the exact
`dispatch_guard(resource_id, local_date)` rows the holds 44–52 would touch **before** the
run and re-counting them **after** against that recorded baseline — was **not** performed:
no such pre-run per-identifier baseline was captured, and because the probe fixture has
since been removed from host and container, the exact resource_id/local_date it exercised
can no longer be reconstructed from it. This is reported as a **missing-evidence gap**, not
closed by invention: no baseline is fabricated and no row is deleted. The structural
single-transaction rollback above is the actual basis for the no-committed-guard-row
claim; a future run that needs the differential must record the per-identifier baseline
first.

> **[2026-09-25 forward-pointer — gap since supplied, original preserved as historical.]**
> The per-identifier `dispatch_guard(resource_id, local_date)` differential this
> paragraph reports as **missing** was subsequently captured and independently verified
> by an owner-authorized **bounded QA-only replay** — see **§10.9**. That replay
> reconstructed the ten-case probe, persisted a sanitized per-identifier guard + fixture
> baseline **before** any write, and re-checked it from a **separate fresh process** after
> the outer rollback (CHECKER_RESULT CLEAN). The text above is retained verbatim and is
> **still true of the earlier run it describes**: a successful contiguous replay does
> **not** retroactively prove that earlier pre-baseline run — it supplies fresh,
> independently-paired evidence from §10.9 forward.

Pre-existing rows untouched (SELECT-only). Auto-increment high-water advancement past
sid 44 / the rolled-back reservation ids is expected residue, not a leak. The temporary
probe fixture was removed from **host and container**; absence verified both sides.

### 10.6 Documented out-of-scope residual — concurrent-writer race (unchanged)

The fix closes the **stale-object** case (facts committed BEFORE confirmation): the
under-lock `loadUnchanged` sees them on the same connection. It does **not** close a
write **committed during** the confirm transaction — the guard locks resource/day rows,
**not** the `webform_submission` row, and under this connection's isolation a concurrent
external commit is not guaranteed visible mid-transaction.

There are two candidate closures, and (closeout item 3) their cost is **not** the same —
the earlier "submission-row lock = schema change" framing was wrong and is corrected here:

- **Existing-row lock** (`SELECT … FOR UPDATE` on the already-present submission /
  revision row): needs **no migration** — it takes a lock on a row that already exists,
  so it is **not** a schema change. Whether it is actually *safe and sufficient* is a
  separate question requiring analysis of who writes the submission row, the
  lock-acquisition ordering against the resource/day locks the guard already holds
  (deadlock avoidance), and the surrounding transaction/isolation semantics
  ([MariaDB `FOR UPDATE` locking reference](https://mariadb.com/docs/server/reference/sql-statements/data-manipulation/selecting-data/for-update)).
  That analysis is **not** performed here and no lock is added.
- **Revision / CAS identity column** on the reservation binding: *this* one is a schema
  change, which PROMPT_SPEC §8 places out of scope.

Neither is attempted here; this residual is **characterized, not closed**, and closing it
is explicitly not part of this task.

### 10.7 Verdict

- **Strategy B (guarded confirmation): functional acceptance — PASS, and closeout
  requirements now evidenced.** The two reported defects are resolved and preserved as
  **sequential functional acceptance**: CS now **REFUSES on the job-facts axis** (the §9
  retraction's stated reason, inverted) and the empty-binding current-format downgrade is
  closed — RESULT **91 passed / 0 failed, OUTER_EC=0** over the real
  planner/store/guard. The confirmation-objective closeout is satisfied: freshness scoped
  to the same-connection/same-transaction scenario actually exercised (§10.1), the
  concurrency explanation corrected — existing-row locking is **not** a schema change
  (§10.6), guard-row cleanup rested on the structural single-transaction rollback with the
  per-identifier baseline gap reported honestly (§10.5), verification linked (§10.8), and
  the current-status pointer synced in `../round2-evidence.md`. Scope is **guarded
  confirmation only**; it does not extend to the broader Task 24B objectives below.
- **Fix: applied, uncommitted, module-scoped, no schema** (`DispatchPlanner.php` +
  `custom_webform_handlers.services.yml`).
- **Task 24B: PARTIAL.** Its guarded-confirmation objective is met, but **Commerce
  order/display integration, concurrency, and payment acceptance remain UNVERIFIED**, so
  the task as a whole is not complete. **DBHub startup + pool-runtime acceptance stay
  OPEN / separate** and are **not** resolved by this reservation QA. **No task advance**
  to 24C–24K. **No commit** (not authorized).

### 10.8 Verification linkage (closeout item 5) — reused where valid, refreshed where authorized

- **Source-parity (reused):** host `DispatchPlanner.php` sha256
  `8fa9cd83bbfd4b6aaaf2c16cfc4009bf1c6251da3be2c095e2959ee873b9c512`,
  `custom_webform_handlers.services.yml` sha256
  `26eeeaf4434043f5c535843454053ec0c4541f2763a14547942f41bfff77891a`; host==container
  parity was verified at the time the §10.3 probe ran and no source changed since, so it is
  reused, not re-executed (closeout: "Reuse valid results").
- **PHP lint (reused):** `php -l` clean on both fix files (§10.1).
- **QA cache-rebuild (reused):** `drush cr` on QA recompiled the DI container so the 14th
  planner argument resolved (§10.1).
- **Graph refresh (refreshed now):** `graphify update .` re-extracted the working tree
  (AST-only, no API cost) → 5586 nodes / 11176 edges / 476 communities, so the graph
  reflects the uncommitted `DispatchPlanner.php` + `services.yml` edits. Coverage caveat
  per project rule: Graphify does **not** index YAML or Markdown, so this evidence file,
  `round2-evidence.md`, and the `services.yml` wiring are verified by **direct read**, not
  by a graph edge.
- **Diff-check (refreshed now):** `git diff --stat` vs `HEAD` (`b49c259e`) shows both
  fix-bearing files modified in the working tree — `DispatchPlanner.php` and
  `custom_webform_handlers.services.yml`. The stat totals include the broader uncommitted
  Task 24B immutable-snapshot work already present in those files; the guarded-confirmation
  fix (the `confirmFor` under-lock `loadUnchanged` digest recompute + `$storedIsCurrentFormat`
  disjunct + the `@entity_type.manager` arg) is the module-scoped subset within them. No
  file outside `modules/custom_webform_handlers/` is touched by the fix.

> **[2026-09-25 label correction — see §10.9 for the executed checks.]** Two closeout
> verification items above were mis-labelled or missing and are corrected in **§10.9**:
> (a) the **Diff-check** bullet cited `git diff --stat` (a line-count summary), but the
> closeout asks for `git diff --check` (a whitespace / conflict-marker scan) **scoped to
> the two fix files** — the actual `--check` was run in §10.9 → **EC=0 unstaged and EC=0
> staged**; the `--stat` line is retained as context, not as the closeout check. (b) No
> label validated the YAML: `php -l` (above) lints PHP and does **not** parse YAML, so
> `services.yml` was additionally parsed with the **installed Symfony YAML parser** (the
> parser Drupal's container compiler uses) in §10.9 → parses OK, `dispatch_planner` ARGC=14,
> last argument `@entity_type.manager`. The reused-still-valid items (source-parity shas,
> `php -l`, `drush cr`, `graphify update`) are unchanged.

### 10.9 Bounded QA-only cleanup-evidence replay (2026-09-25) — supplies the §10.5 baseline, corrects §10.8 labels

<!-- SB_10_9_ANCHOR -->

**Authorization and scope.** The owner authorized **one bounded QA-only replay** of the
ten-case temporary probe for the sole purpose of (a) supplying the per-identifier
`dispatch_guard(resource_id, local_date)` baseline reported missing in §10.5 and (b)
correcting the §10.8 verification labels. **No application-source change**, no schema, no
migration, no commit, no automated suite; existing-container QA only
(`--uri=https://ppu-qa.ddev.site`); DBHub MCP suspended (owner exception used only for
read-only schema/metadata via the already-running container); no DDEV/Docker/Mutagen
lifecycle command. Functional acceptance (§10.7) is **preserved, not re-litigated**.

**Why a fresh replay was required.** Cleanup evidence is only valid as a **contiguous
three-phase chain** — baseline snapshot (T0) → probe writes + explicit outer rollback (T1)
→ separate fresh-process verification against that persisted baseline (T2). The earlier
91/0 run (§10.3) executed **before** any per-identifier baseline existed, so it can never
be paired with one after the fact; §10.5's gap is genuine and stays historical. This
replay re-ran all three phases contiguously in one window.

#### T0 — sanitized per-identifier baseline (before any write), persisted

Read-only Drush process. Resource identity resolved from config, not assumed:
`dispatch_profile_factory->create()` → **`resourceId=driver-01`**, **`timezone=America/New_York`**.
Target date `2026-09-28` bracketed for cross-midnight guard locks →
**expected_dates = 2026-09-27 / 2026-09-28 / 2026-09-29**.

- **Guard table** `custom_webform_handlers_dispatch_guard` — key columns discovered
  (`resource_id` | `local_date`); **4 pre-existing rows**, each stored as
  `res|date → canonical sha256`:
  `driver-01|2026-09-07 = 9e79ca3a…`, `driver-01|2026-09-08 = cf80d64b…`,
  `driver-01|2026-09-09 = 80c9b90c…`, `driver-01|2026-09-21 = 14f4f58a…`.
  All **three target keys present=0** (none of 09-27/28/29 pre-existed — recorded as
  genuinely absent, not invented).
- **Fixture tables** (count + order-independent aggregate sha256):
  `custom_webform_handlers_dispatch_reservation` = **6 / `15bdac18…`**;
  `webform_submission` = **5 / `d5b12ad0…`**; `webform_submission_data` = **260 /
  `d475a0d0…`**; the other 8 tracked tables (`checkout_attempt`, `reservation`,
  `dispatch_status`, `dispatch_notification`, `dispatch_notify_claim`,
  `dispatch_blackout`, `commerce_order__field_dispatch_contact_{name,phone}`) = **0 /
  `e3b0c442…`** (empty-string agg).
- Persisted as sanitized ledger **`guard-baseline.json`** (sha256 row hashes, counts,
  resource id, dates, and the ten probe order ids `899000301–899000310` only — no raw row
  values, PII, or credentials). `OUTER_EC=0`.

#### T1 — ten-case writing replay through the real chain, explicit outer rollback

The **reconstructed authoritative probe** was delivered by `docker cp` and run in-container
unmodified: **host sha256 == container sha256 =
`61ad41919cc8dc7262f9f72fd45e9f44689252d23c3f45f5f3860f90d9db0647`** (36 249 bytes). It ran
through the real planner / reservation-store / `ResourceCalendarGuard` — no mocks — under a
single outer `startTransaction()`, per-case savepoints, and `finally { rollBack(); }`.

- **`RESULT 91 passed / 0 failed`, `OUTER_EC=0`.** Every axis behaved as the specification
  requires, including the two the §9 retraction turned on:
  - **CS REFUSED on the JOB axis** — the stale-object confirm, attempted **after** persisted
    job-facts changed, is rejected (the retraction's required outcome, now correct).
  - **R6c REFUSED** — an empty accepted binding must not downgrade a current-format stored
    record into acceptance.
  - C1 / R7 CONFIRM (genuine), C4 / CS / R3 refuse on JOB, C5 / R4 / R6a / R6b / R6c refuse
    on SNAP — discriminated via the single `custom_webform_handlers`-channel logger seam.
- **Explicit outer rollback executed**; post-rollback the probe re-asserted
  `inTransaction() === FALSE` and `loadByOrder(89900030X) === NULL` for **all ten** probe
  orders. Benign run-time log noise (`Authoritative quote could not be persisted…`,
  `route estimate is not traffic-aware; inflating by 25%`) is expected — the probe drives
  the chain directly — and did not affect the result.

#### T2 — separate fresh-process cleanup verification vs the persisted baseline

A **distinct** read-only Drush process reloaded `guard-baseline.json` and recomputed every
figure with the **identical** canonicalization (ksort'd `k=v` joined by `\x1f`, NULL
sentinel `\x00NULL`) and aggregate (SORT_STRING-sorted row hashes joined by `|`) used at
T0, so the hashes are directly comparable:

- **Guard key set + row hashes byte-identical to baseline** (4 keys, unchanged); **all three
  previously-absent target keys (09-27/28/29) STILL absent**; **no new guard key introduced.**
- **All 11 tracked tables: count + aggregate hash unchanged** vs baseline
  (`dispatch_reservation` restored 6 / `15bdac18…`; submissions 5 / 260; the rest still empty).
- **No probe order id** (`899000301–899000310`) present in any column named `entity_id` or
  matching `/order/` across the tracked tables.
- **`CHECKER_RESULT CLEAN`, `OUTER_EC=0`.** The checker throws (nonzero) on any discrepancy
  and **never deletes or repairs** a row — zero residue is proven, not enforced.

**This T0→T1→T2 chain is the per-identifier differential §10.5 lacked:** the exact
`dispatch_guard(resource_id, local_date)` rows the confirm path could touch were recorded
before the run and re-verified unchanged after it, from a separate process.

#### Corrected verification labels (closeout item 5) — supersede §10.8 where noted

- **Diff-check — CORRECTED (`--check`, not `--stat`):** `git diff --check` scoped to the two
  fix files (`DispatchPlanner.php`, `custom_webform_handlers.services.yml`) → **unstaged
  EC=0** and **staged (`--cached`) EC=0** — no whitespace errors, no conflict markers. This
  is the check the closeout asked for; §10.8's `--stat` line stays as context only.
- **YAML validation — ADDED (installed parser, not `php -l`):** `\Symfony\Component\Yaml\Yaml::parseFile()`
  on `services.yml` parses OK (top key `services`); `services.custom_webform_handlers.dispatch_planner`
  has **ARGC=14** with last argument **`@entity_type.manager`** — the CS-fix wiring is present.
  `php -l` does not parse YAML, so this closes that label gap.
- **Reused where still valid (unchanged since §10.3/§10.8):** source-parity shas
  (`DispatchPlanner.php 8fa9cd83…`, `services.yml 26eeeaf4…`), `php -l` on both fix files,
  `drush cr`, and `graphify update .`.

#### Historical preservation and verdict

- The **§9 retraction** and the **original §10.5 gap** remain **historical and true of the
  runs they describe**. A successful contiguous replay does **not** retroactively validate
  the earlier pre-baseline 91/0 run; it supplies fresh, independently-paired cleanup
  evidence from this point forward.
- **No application source changed**, no schema, no migration, no commit, no automated
  suite; QA-only in the existing container; DBHub MCP suspended; no DDEV/Docker/Mutagen
  lifecycle command. All five temporary probe/checker files and `guard-baseline.json` were
  removed from **host and container** after results were persisted; absence verified both
  sides (see §8.7 / this section).
- **Verdict unchanged:** Strategy B guarded-confirmation **functional acceptance = PASS**
  with the closeout cleanup + verification now **fully evidenced**; **Task 24B stays
  PARTIAL** (Commerce order/display integration, concurrency, payment, and browser
  acceptance remain UNVERIFIED); DBHub startup + pool-runtime acceptance stay OPEN and
  separate. **No task advance to 24C–24K.**


