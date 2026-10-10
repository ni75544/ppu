# Task 26A — Runtime Readiness: Evidence, Compatibility Matrix, and Findings

Read-only assessment. Prepared 2026-10-08 (America/New_York). Repository `C:\Users\mo737\ppu`.

**No repair was performed.** No source or configuration edit, Composer operation, module
install/enable, migration, backfill, cleanup, gateway setup, paid API call, form submission,
fixture, payment operation, or test suite was run. `drush cr`, `updb`, config import, cron,
queue workers, container restart, and database restore were **not** run.

Governing instructions: [PROMPT_SPEC.md](PROMPT_SPEC.md). Prior task:
[commerce-webform-bridge](../../commerce-webform-bridge/README.md) (25A-R1 verdict B retained;
25B–25D BLOCKED).

> **CORRECTED 2026-10-08 by Task 26A-R1** — [TASK-26A-R1-PROMPT_SPEC.md](TASK-26A-R1-PROMPT_SPEC.md).
> The Step-A/B/C evidence below stands. The **execution and recovery proposal** had six safety
> gaps; they are corrected inline and recorded in **§10**. Read §10 before acting on §5 or §6.2,
> and read [NEXT_EXECUTION_PROMPT.md](NEXT_EXECUTION_PROMPT.md) §10 before acting on any command.
> Task 26B remains **BLOCKED / NOT AUTHORIZED FOR EXECUTION**.

---

## 1. Step A — Current baseline

### 1.1 Revision and dirty work (unchanged; nothing staged, committed, or discarded)

| Fact | Value |
| --- | --- |
| Revision | `b49c259e3e3d5452286ffb45d1ec6bf1451c0fcc` |
| Branch | `codex-experiments` |
| Tracked modifications | **29** — 28 under `modules/custom_webform_handlers/` plus `.gitignore` |
| Untracked entries | **16** — four `docs/execution/**` packs (now five, with this one), `.claude/`, and Task 24B/24C/24H source (`src/Checkout/AcceptedJob.php`, `src/Dispatch/Admission/`, `src/Dispatch/PreparedHold.php`, eight `src/Distance/Route*`/`Routing*` files) |

The dirty-work set is the same one recorded in 25A §1.2; no file was added or removed.

> **Clarified 2026-10-08 (26A-R1).** Re-measured: HEAD still `b49c259e…`, **29** tracked
> modifications (unchanged), nothing staged, and **17** collapsed untracked entries. The 17 is the
> 16 above **plus** the `runtime-readiness/` pack this document's own row anticipated ("now five,
> with this one") — `git status --porcelain` collapses directories, so the number only moved when
> the fifth pack directory first appeared. The three 26A-R1 documentation files live inside
> already-untracked directories and do not move it again. **No unexplained drift.**

### 1.2 Container health (no lifecycle change)

`docker ps`:

| Container | Status |
| --- | --- |
| `ddev-ppu-db` | Up 4 hours (healthy) |
| **`ddev-ppu-web`** | Up 4 hours (**unhealthy**) |
| `ddev-router` | Up 4 hours (healthy) |
| `ddev-ppu-selenium-chrome`, `ddev-ppu-nominatim`, `ddev-ppu-redis`, `ddev-ppu-osrm` | Up 4 hours |
| `ddev-ssh-agent` | Up 4 hours (healthy) |

**Observation (not a blocker for this assessment):** the web container reports **unhealthy**
while Drupal bootstrap and database access through it succeed for both sites
(§1.3). The healthcheck verdict is recorded as-is; it was **not** diagnosed or remediated,
because doing so would require lifecycle changes this task forbids. Whether it affects anything
beyond the healthcheck is **REQUIRES_VERIFICATION**.

> **Corrected 2026-10-08 (26A-R1) — see §10.4.** Identifying the failed check needed no restart
> and no repair. `docker inspect ddev-ppu-web` reports the last healthcheck output as
> `/var/www/html:OK mailpit:FAILED phpstatus:FAILED` with exit code 1 and a failing streak of
> **10708** at a 1-second interval (≈3 hours continuous). Per the container's own
> `/healthcheck.sh`, `phpstatus` is `curl --fail -s 127.0.0.1/phpstatus` (`:62`) — an **HTTP
> request through the webserver into PHP-FPM** — and overall health requires
> `phpstatus && htmlaccess && mailpit` (`:70`). Consequences, which this section previously left
> unstated: the §1.3 bootstrap successes were obtained through **PHP CLI** via `docker exec`, so
> they do **not** clear the FPM failure; the code mount is **OK**, so this is not a sync or mount
> fault; and the local mail sink is down. This is an **unresolved execution prerequisite**, not a
> cosmetic verdict — see blockers **B5** and **B6** in NEXT_EXECUTION_PROMPT.md §1. Root cause and
> blast radius remain **REQUIRES_VERIFICATION**; no repair was attempted.

### 1.3 Inspected sites (each identified explicitly)

| Field | Default site | QA site |
| --- | --- | --- |
| URI | `https://ppu.ddev.site` | `https://ppu-qa.ddev.site` |
| Site directory | `sites/default` | `sites/qa` |
| Database identity (`SELECT DATABASE()`) | **`db`** | **`qa`** |
| Drupal core | 10.6.16 | 10.6.16 |
| DB status / bootstrap | Connected / Successful | Connected / Successful |
| `custom_webform_handlers` schema version | **10046** | **10049** |
| `commerce_order` schema version | 10303 | 10303 |
| Other schema | `commerce_checkout` 8000, `commerce_payment` 10303, `webform` 8649 | — |
| Effective routing mode | **`development_fixed`** | **`development_fixed`** |
| Config `routing_mode` value | `live_cached` | `live_cached` |

Both reported schema numbers **reproduce** (default 10046, QA 10049). The effective routing mode
is `development_fixed` on **both** sites even though the stored config says `live_cached` — the
`IS_DDEV_PROJECT=true` signal wins in code (`RoutingModeResolver`), which is the Task 21A
zero-cost safeguard and is **intact**. This is the expected state, not a finding.

---

## 2. Q1 — Does the 15-versus-16 constructor-argument mismatch still exist?

**YES — it still exists, and it is now confirmed at the compiled-definition level.**
**Label: PASS (the finding is confirmed) / the underlying defect is FAIL.**

### 2.1 The three sides of the comparison

| Side | Argument count for `custom_webform_handlers.dispatch_checkout_manager` | Evidence |
| --- | --- | --- |
| `DispatchCheckoutManager` constructor (source) | **16** promoted parameters | `src/Commerce/DispatchCheckoutManager.php` — 16 × `private readonly` |
| Its service YAML (source) | **16** arguments | `custom_webform_handlers.services.yml`, block ending in `- '@database'` |
| **Compiled container — default site** | **15** (`value";a:15:`) | Redis hash, see §2.2 |
| **Compiled container — QA site** | **16** (`value";a:16:`) | Redis hash, see §2.2 |

The **missing argument is the final one, `@database`.** The compiled default list is indices
0–14, ending at `custom_webform_handlers.booking_admission_store`; the QA list adds index 15,
`database`:

```
[14] custom_webform_handlers.booking_admission_store
[15] database                        ← present on QA, ABSENT on default
```

### 2.2 Where the compiled container lives, and how its key is formed

The compiled service container is **not** a file and **not** the `cache_container` DB row. It is
stored in **Redis as a hash** (Redis type 5), under a key built by
`DrupalKernel::getContainerCacheKey()` (`core/lib/Drupal/Core/DrupalKernel.php:906`):

```
service_container : {environment} : {Drupal::VERSION} : {deployment_identifier} : {PHP_OS} : {serialize(container_yamls)}
```

Observed keys (both sites; leftovers from a 10.6.15 core are also present):

```
drupal.redis.10.6.16..539018e…:container:service_container:prod:10.6.16::Linux:a:3:{…sites/default/services.yml…}
qa_…:container:service_container:prod:10.6.16::Linux:a:2:{…redis/example.services.yml…}
```

Two consequences, both load-bearing:

1. **The cache key does not include any service-YAML content or mtime.** Editing
   `custom_webform_handlers.services.yml` does **not** invalidate the compiled container. It is
   invalidated by a version change, a `deployment_identifier` change, a `container_yamls`
   change, or an explicit cache rebuild.
2. **The key is version-scoped** (`prod:10.6.16`), which is why separate 10.6.15 and 10.6.16
   entries coexist — and why the *current* key on each site is the one examined above.

### 2.3 Why default holds 15 and QA holds 16 — established by timestamp

| Item | Timestamp (UTC) |
| --- | --- |
| Default compiled container created | **2026-10-01 17:54:59** |
| QA compiled container created | **2026-10-06 02:24:11** |
| `custom_webform_handlers.services.yml` mtime | **2026-10-05 02:56:42** |
| `DispatchCheckoutManager.php` mtime | 2026-10-06 02:16:44 |

The `@database` argument was added to `services.yml` on **2026-10-05**, *after* the default site
compiled its container (2026-10-01) and *before* QA did (2026-10-06). That single ordering
explains both counts exactly. This is the mechanism behind the `ArgumentCountError` reported in
25A §1.5.1: instantiating the `hg` handler on the default site asks the container to build a
16-parameter service from a 15-argument definition.

### 2.4 Source-vs-container file identity (fingerprints, not counts)

The mismatch is **purely in the compiled container** — the source files are byte-identical in the
host working tree and inside the running web container:

| File | SHA-256 (host == container) |
| --- | --- |
| `custom_webform_handlers.services.yml` | `920581e37196b365b143dd11bd0108906a05b4c8c7e482f49fe497e7c5263e5f` |
| `src/Commerce/DispatchCheckoutManager.php` | `b7251503286a641565d43ed0e618532c5caa94327cae23eb446b86f37a0a399e` |
| `custom_webform_handlers.install` | `172d25b023f9cf8bd992130b83015a97fa7599675a20f36097fec22669e4a236` |

So there is **no host/container code divergence** and no Mutagen sync gap for these files; the
only out-of-step artefact is the **Drupal compiled service container**, which is distinct from
Docker infrastructure (Docker containers are running; the *compiled DI container* is stale).

---

## 3. Q2 — Which schema differences would matter if the container were rebuilt?

A rebuild activates the working-tree source. The source's schema expectations were read from
code, then compared against the **actual** schema (not the version number alone).

### 3.1 Source requirements read from code

- `CheckoutAttemptStore::snapshotSchemaState()` / `determineSnapshotSchemaState()`
  (`src/Checkout/CheckoutAttemptStore.php:160,184`) count the **eight** Task 24B snapshot columns
  and gate on them. If the state is not `complete`, `open()` **refuses** every new attempt
  (`:308`), logging `update_10047 is unapplied or partial, or the schema could not be inspected`.
- `CheckoutAttemptStore::hasAdmissionGenerationColumn()` (`:220`) probes for
  `admission_generation`; a supplied generation without the column throws (`:319-326`), but **no
  business caller supplies one**, so its absence is inert for the checkout path.
- `DispatchOrderFactory` / the manager otherwise use ordinary Commerce entity storage.

### 3.2 Source-to-schema compatibility matrix

Legend: **present** = column/table exists in the actual DB; each row checked per site.

| Requirement (source) | Needs | DEFAULT actual | QA actual | If rebuilt while un-applied |
| --- | --- | --- | --- | --- |
| Task 24B snapshot payload on `custom_webform_handlers_checkout_attempt` | 8 columns: `quote_route_meters`, `quote_route_seconds`, `quote_line_items`, `quote_pair_fingerprint`, `quote_payload_schema_version`, `quote_payload_digest`, `quote_job_facts_digest`, `quote_job_facts` | **ALL 8 ABSENT** (table has 20 cols) | all 8 present | `snapshotSchemaState()` = `absent` → **new attempts refused** (fail-closed, no crash) |
| Booking admission table | `custom_webform_handlers_dispatch_booking_admission` | **ABSENT** | present | inert (registered, no caller); `BookingAdmissionStore` would fail closed if ever called |
| Admission binding column | `admission_generation` on `checkout_attempt`, `dispatch_reservation`, **legacy `reservation`** | **ABSENT on all 3** | present on all 3 | inert (no business caller passes a generation) |
| Pricing-policy version on `dispatch_reservation` | `price_snapshot_version` | **present** (10046 applied) | present | no change needed |
| `DispatchCheckoutManager` constructor | 16 args incl. `@database` | compiled container has **15** | 16 | handler instantiation throws `ArgumentCountError` until rebuild |

**Actual-schema check method:** `SHOW COLUMNS` plus `schema()->fieldExists()`/`tableExists()` per
site, not the stored version number. The stored version (`10046`) happens to be **consistent**
with the observed schema on default here — but the check was performed against the columns
themselves, because a version number is not proof.

### 3.3 What a rebuild would and would not do (the key interaction)

- **Rebuild alone does not fix the default site.** Today the `hg` handler cannot instantiate
  (`ArgumentCountError` → hard failure). After a rebuild it *would* instantiate, but
  `CheckoutAttemptStore::open()` would then **refuse** every new attempt because the eight
  columns are absent. One failure would be exchanged for another — this time a graceful,
  fail-closed refusal rather than a crash, but still no working checkout.
- **`update_10047` is the column-side prerequisite** for a rebuilt container to create attempts.
- The **activation is fail-closed, not destructive**: nothing in the rebuilt path writes or
  rewrites existing rows it cannot understand — `CheckoutAttemptStore` refuses rather than
  migrating, and legacy rows are read as legacy figures.

**Conclusion (Q2):** the materially-required schema change is **`update_10047`** (the eight
Checkout Attempt snapshot columns). `update_10048` and `update_10049` are needed for
completeness and to keep the store inert-but-satisfiable, but the checkout path's hard
dependency is 10047. Rebuilding **without** them is **safe but non-functional** (fail-closed);
rebuilding **with** them is the target state. Label: **PASS (matrix complete)**; the specific
rebuild-then-behave claims are **REQUIRES_VERIFICATION** until exercised under authorization.

---

## 4. Q3 — Which update hooks can resolve the differences safely?

### 4.1 Pending hook inventory (non-executing)

| Site | Pending hooks |
| --- | --- |
| **Default (`db`)** | `custom_webform_handlers_update_10047`, `_10048`, `_10049` |
| **QA (`qa`)** | **none** (already at 10049) |

No other module has pending updates. There is no `post_update` pending.

> **Clarified 2026-10-08 (26A-R1).** This module defines **no** post-update hooks at all (zero
> `_post_update_` occurrences in `custom_webform_handlers.install`; no `*.post_update.php` file),
> so the module side is structural. The *site-wide* "none pending" fact is a point-in-time
> observation: Drush recomputes it at run time for every extension via
> `update.post_update_registry->getPendingUpdateFunctions()` (`UpdateDBCommands.php:394`). It
> must be re-confirmed immediately before execution, because if **any** post-update is pending
> and regular updates also run, Drush inserts a full `cacheRebuild()` — flush, `rebuildContainer()`,
> `loadAll()`, `invokeAll('rebuild')` — as a batch operation **between** the hooks and the
> post-updates (`:396-399`, `:471-480`). That would make the new wiring live **mid-batch**. See
> §10.2.

### 4.2 Per-hook analysis (read from `custom_webform_handlers.install`)

| Hook | Source line | Tables / columns / config affected | Data transformation or historical writes | Scheduling / admission / transaction effect | Prerequisites, retry, recovery | Verdict |
| --- | --- | --- | --- | --- | --- | --- |
| **10047** | `:4497` | Adds **8 nullable columns** to `custom_webform_handlers_checkout_attempt`. Each added **only when absent** (`fieldExists` guard), definition read from `custom_webform_handlers_schema()`. | **None.** No row is read or written. Docblock: "No existing attempt is rewritten: a row written before this payload existed is honoured as it stands." | None — pure DDL on the attempt table; touches no scheduling, guard, or reservation table. | Prereq: attempt table must exist (else benign no-op message). Idempotent per column; safe re-run. **Partial completion is possible** — see §4.2.1. Reversal is **not** automatic. | **Necessary** — the hard prerequisite for a rebuilt container (§3.3). |
| **10048** | `:4546` | Creates `custom_webform_handlers_dispatch_booking_admission` (11 columns, PK `submission_id`) from `custom_webform_handlers_schema()`. | **None.** `tableExists` guard; no backfill, no generated column, no secondary index. | None — the store is registered but inert with no caller. | Prereq: none beyond schema function. Idempotent. Single DDL statement, so no partial completion within the hook. Reversal is **not** automatic — see §4.2.1. | **Necessary for completeness**; not on the checkout path. |
| **10049** | `:4579` | Adds nullable `admission_generation` to **three** tables: `checkout_attempt`, `dispatch_reservation`, and the **legacy `custom_webform_handlers_reservation`**. Frozen inline definition (not read from schema()). | **None** — no backfill, no new index, no binding-ledger table, wires no caller. | None — booking admission stays inactive after it runs. | Prereq: **all three tables must exist**; a missing table throws `UpdateException` from a true preflight loop, before any mutation. Defensive: an already-present column is accepted only if it is a nullable `INT UNSIGNED` defaulting to NULL with no auto_increment/generated behaviour, else throws — but **that check runs per table inside the mutating loop, so it can throw after an earlier table was already altered.** Idempotent (with a compatible column it is kept). **Partial completion is possible** — see §4.2.1. Reversal is **not** automatic. | **Necessary for completeness**; note it **ALTERs a legacy table** (additive, nullable, no data change). |

### 4.2.1 Per-hook failure anatomy (added 2026-10-08, 26A-R1)

Read from `custom_webform_handlers.install`. The original §4.2 row for `10049` claimed the hook
"fails **before any mutation**". That is true of **one** of its two validation stages only. Each
hook is therefore decomposed into the four phases an approval has to reason about:

| Hook | Preflight validation (before any DDL) | Individual DDL operations | Partial completion possible? | Retry + schema-version bookkeeping |
| --- | --- | --- | --- | --- |
| **10047** (`:4497`) | `tableExists` on the attempt table only (`:4500`) — a benign early return, not a throw. **No preflight over the eight columns.** | A loop of up to **8** separate `addField` calls (`:4517-4523`), each guarded by `fieldExists`. | **Yes.** A failure on the *n*-th column leaves columns 1…*n*-1 added. MariaDB `ALTER TABLE` commits implicitly, so these are **not** rolled back. | Re-run is safe (`fieldExists` skips what exists). On abort the hook's version is **not** recorded, so the site stays at the prior version with a **partially migrated table**. `CheckoutAttemptStore::snapshotSchemaState()` reads that as `partial`, which still refuses attempts (§3.1) — so the fail-closed property survives a partial run, but the *schema number no longer describes the columns*. |
| **10048** (`:4546`) | `tableExists` (`:4550`) — early return if present. | **One** `createTable` (`:4555`). | **No** within the hook — a single statement either creates the table or does not. | Re-run is safe. Version recorded only on success. |
| **10049** (`:4579`) | A genuine preflight loop over **all three** tables (`:4601-4613`) throwing `UpdateException` **before** any DDL — this is the stage §4.2 described. | Then a **second, mutating** loop (`:4617-4651`): per table, either validate an existing column (which can throw at `:4636`) or `addField` (`:4649`). | **Yes.** Table 1 can be altered successfully and table 2 then throw on an incompatible existing column — a throw **after** mutation. The compatibility check is *inside* the mutating loop, not in the preflight. | Re-run is safe (a compatible column is `kept`). Version recorded only on success, so a partial run leaves 1–2 of 3 tables carrying the column at the prior version number. |

Two cross-cutting facts that apply to all three:

1. **Schema-version bookkeeping is a write, and it happens twice.** Before the batch runs, Drush
   calls `drupal_set_installed_schema_version($module, $number - 1)`
   (`UpdateDBCommands.php:378-384`); after each hook returns successfully it calls
   `setInstalledVersion($module, $number)` (`:255-258`). On abort, `updateDoOne` catches the
   `\Throwable`, records `#abort`, and **skips** the version write (`:213-217`, `:255`).
2. **Reversal is not automatic and is not proposed.** Dropping an added column or table is safe
   only while nothing has written to it. `CheckoutAttemptStore` begins populating the eight
   `quote_*` columns as soon as the schema state reads `complete`, so once the container is live
   and a single attempt is created, a later `DROP COLUMN` **destroys data**. Additive DDL is not
   losslessly reversible in general. See §5.4 and NEXT_EXECUTION_PROMPT.md §9.



- **No hook modifies scheduling behavior, guard logic, capacity, Hold/Reservation logic,
  admission enforcement, or transaction/locking implementation.** All three are additive DDL.
- **No hook performs a data transformation, backfill, or historical-record write.** All are
  `addField`/`createTable` only. Values are not recomputed and references are not rewritten.
- **`10049` does alter the legacy `custom_webform_handlers_reservation` table's schema** (adds a
  nullable column). It writes no row. This is the one place a legacy table is touched, and it is
  additive and reversible; it is called out so the approval explicitly covers it.
- **The normal update sequence does not cross a protected logic boundary.** Therefore there is
  **no approval blocker of the "protected-boundary crossing" kind** for 10047–10049 themselves.

### 4.4 Explicitly not proposed

- **No blanket `updb`** across all modules is proposed (only the three named hooks are in scope,
  and only on the default site).
- **No hook is proposed to be skipped or individually forced** out of the normal sequence.
- **`10049` failing on a legacy-table precondition** is a real possibility if any of the three
  tables were altered out-of-band. A **missing** table is caught by the preflight loop and fails
  before any DDL; an **incompatible existing column** is caught inside the mutating loop and can
  fail *after* an earlier table was altered (§4.2.1). Either way it is recorded as a stop
  condition, not worked around.

Label: **PASS (hook analysis complete)**; the runtime result of running them is
**REQUIRES_VERIFICATION** until executed under authorization.

---

## 5. Q4 — Prerequisites for any execution

### 5.1 Isolation

- The two sites are a **shared codebase** (§4 of the 25A decision, corrected): code, Composer
  `vendor/`, and containers are shared; the database (`db` vs `qa`), files, cache key space
  (`cache_prefix = 'qa_'`), and DB-backed ledgers/queues are per-site.
- Consequence for this plan: **QA is not an isolated rehearsal target for a default-site schema
  change.** QA is *ahead* (10049), so it cannot rehearse "apply 10047 on a 10046 database." An
  isolated target must be proposed explicitly (a **clone of `db`**, or a dedicated environment)
  before any execution.
- **Required isolation:** a database clone alone is **not sufficient** for a rehearsal that
  bootstraps Drupal or rebuilds caches. Before any rehearsal is approved, an **exact proposed
  target manifest** must be written out and each shared component's boundary **verified, not
  inferred**. `--uri` and one observed Redis key prefix do not establish isolation.

| Manifest item | What must be stated and verified | Current status on this host |
| --- | --- | --- |
| Code + `vendor/` snapshot and ownership | Which tree the rehearsal executes from, who owns it, and whether it is writable by anything else | **Shared.** One tree serves both sites; `vendor/` is shared. A rehearsal that edits code edits the live site's code. |
| Database + credentials | Clone name, how it was produced, which account the rehearsal uses, and that it is least-privilege | Clone **does not exist**. DBHub's read-only account is **suspended**. |
| Site URI, `settings.php`, public/private files | The URI the rehearsal answers on, which settings file it loads, and the file paths it writes | `sites/default` and `sites/qa` only; no third site exists. |
| Cache + compiled-container namespace | The cache prefix, and proof that a flush in the rehearsal cannot invalidate the live site's `container` bin | **Shared Redis instance.** Separation is a **key-prefix convention**, not an instance boundary — see §10.3. **REQUIRES_VERIFICATION.** |
| Sessions, queues, cron, workers | Whether any scheduled or queued work can touch the rehearsal database or the live database during the window | Containers are shared. Drush disables automated cron **for its own process only** (`DrupalBoot8.php:203`); it says nothing about FPM-side cron, queue runners, or workers. **REQUIRES_VERIFICATION.** |
| Outbound email | Where mail goes if Drupal sends any during the rehearsal | **`mailpit:FAILED`** (§1.2). The local sink is down; whether mail is dropped or attempted elsewhere is **REQUIRES_VERIFICATION**. |
| Webhooks, routing, payments | That no external call can be made, and that `development_fixed` and the zero-gateway state still hold | `development_fixed` intact on both sites (§1.3); 0 gateways (§6.1). Must be re-asserted inside the rehearsal target. |

- The **codebase is not isolated** and any `drush cr` affects the shared container cache — which
  is why the plan touches the **default site's** container only, and does not touch QA. See
  §10.3 for why "touches only the default site" is a prefix claim that still needs verifying.

### 5.2 Private backup scope

Before any mutation, a **private** (not committed, not printed) backup of:

| Item | Why |
| --- | --- |
| The `db` database (schema + data), consistent snapshot | The hooks mutate schema; restore path must exist |
| `sites/default/files` (public) and any private path | DDL should not touch files, but the restore unit must be whole |
| The three source-file fingerprints (§2.4) | To prove the executed code is the assessed code |
| The current **compiled container key** for the default site (§2.2) | To record the pre-change container for comparison/restore |

Records must include **identity and relationship integrity, not counts** (Step C §6). No
customer data, addresses, credentials, receipt tokens, or raw snapshots may appear in
public/pack evidence.

### 5.3 Verification prerequisites

- The Step-C preservation checks (§6) captured **before** any change, and re-run **after**.
- The exact pending-hook manifest (§4.1) re-confirmed immediately before execution.
- Payment-gateway configuration is **out of scope**; its absence must not be "fixed" here.

### 5.4 Recovery checkpoints

> **Corrected 2026-10-08 (26A-R1) — see §10.1/§10.2.** The original second bullet proposed a
> checkpoint "between `updb` and `cr` (schema applied, container still old)". **That state does
> not exist.** `updatedb` clears caches **by default** (`cache-clear` defaults to `true`,
> `UpdateDBCommands.php:51`; flush at `:95-97`), and that flush invalidates the compiled-container
> cache. The checkpoint, and the ordering rationale built on it, are withdrawn.

- Checkpoint **before** any hook (the backup in §5.2). This is the only checkpoint that exists
  with the proposed command set.
- There is **no** post-`updatedb`, pre-`cr` checkpoint. The default `updatedb` run ends with
  `drupal_flush_all_caches()`, which deletes every cache bin (`core/includes/common.inc:444-446`)
  and invalidates the container (`:467-471` → `DrupalKernel::invalidateContainer()`,
  `core/lib/Drupal/Core/DrupalKernel.php:1306-1319`). The next ordinary request therefore compiles
  the **current 16-argument source**. The separately-proposed `drush cr` is **redundant**, and the
  activation it was supposed to gate has already happened.
- **The flush is not conditional on success.** `updatedb` runs it "regardless of whether updates
  ran" (`:91-97`), outside the `$success` branch, and then returns `EXIT_FAILURE` (`:99`). So an
  **aborted** update run also invalidates the container, and the next request runs the new wiring
  against a **partially migrated** schema (§4.2.1). This is the most consequential gap in the
  original plan.
- A stop must preserve legitimate writes after the baseline; a restore must never roll back newer
  orders/reservations.
- **No claim of one atomic rollback.** Filesystem, configuration, DDL, and database changes do
  **not** share one transaction, and MariaDB `ALTER TABLE` commits implicitly, so a failed hook
  leaves committed DDL behind.
- **Reversal is not automatic.** Dropping an added column or table is not a safe default recovery
  step; see §4.2.1 and NEXT_EXECUTION_PROMPT.md §9. Restoring an old compiled container is also
  not a recovery step: the cache key does not encode the source it was compiled from (§2.2), so a
  reinstated old container would be a 15-argument definition over a migrated schema.
- **B3 and B4 are different blockers and cannot be closed together.** Real-database atomicity is
  recorded **PENDING** and safe disposal after a *failed* rollback **BLOCKED**
  (`../../commerce-webform-bridge/task-25a-bridge-compatibility/DECISION.md:227-228`, referencing
  the 24C-8C-R2 record). A successful backup and restore rehearsal (B4) demonstrates *recoverability*;
  it demonstrates nothing about *transaction correctness* (B3). The two are coupled here in one
  specific way: the missing 16th container argument **is** `@database` (§2.1), and `@database` **is**
  the transaction owner in `DispatchCheckoutManager`
  (`src/Commerce/DispatchCheckoutManager.php:98`, `:369`, `:499`, `:535`) — the 24C-8C
  transaction-ownership wiring. Activating the 16-argument container on the default site is
  therefore precisely the act that exposes the unresolved transaction path, which is why
  default-site activation stays **blocked** on B3 independently of B4.

---

## 6. Step C — Legacy-preservation evidence

### 6.1 Baseline captured (bounded read-only aggregates, no sensitive fields)

**Default site (`db`)** — observed 2026-10-08:

| Check | Value |
| --- | --- |
| `commerce_order` (type `dispatch`) | 4 |
| `commerce_order_item` (type `dispatch_service`) | 4 |
| `custom_webform_handlers_checkout_attempt` | 4 |
| — attempts whose order is missing (orphans) | **0** |
| `custom_webform_handlers_dispatch_reservation` | 4 |
| `custom_webform_handlers_reservation` (**legacy**) | 11 |
| — legacy `state = confirmed` | **4** |
| — legacy rows whose submission is missing (orphans) | **0** |
| `custom_webform_handlers_dispatch_guard` | 14 |
| — guard rows with `legacy_unplanned = 1` | 1 |
| `custom_webform_handlers_review_request` | 3 |
| `commerce_payment` (local) | **0** |
| `commerce_payment_gateway` (config entity) | **0** |
| `file_managed` | 0 |
| `webform_submission` | 45 |

**QA site (`qa`)**: orders 0, attempts 0, legacy reservations 0, legacy confirmed 0, guards 4,
submissions 5, local payments 0, reviews 8, dispatch reservations 6 (state mix recorded
separately).

**Correction carried from 25A:** `commerce_payment_gateway` is a **config** entity, not a table
(`commerce_payment_gateway` does not exist as a DB table — verified). Gateway counts come from
the entity API, not SQL. Also, local `commerce_payment` = 0 is a **local-ledger** fact only and
is **not** proof of no external financial activity.

### 6.2 Required repeatable before/after checks (design)

Counts alone are insufficient. The following are the checks a later task must run **before** and
**after**, comparing **fingerprints**, not just totals:

1. **Identity + status sets.** For each of `commerce_order`, `commerce_order_item`,
   `custom_webform_handlers_checkout_attempt`, `_dispatch_reservation`, `_reservation` (legacy),
   `_dispatch_guard`, `_review_request`: an ordered, salted hash over `(primary id, state)` — so
   a changed status or a changed identity set is detected even when the count is unchanged. Salt/
   key kept private; only the digest is published.
2. **Relationship integrity — actual tuples, not orphan counts.** Orphan counts and cardinalities
   are **insufficient**: a swap that re-points attempt A at B's order and B's at A's leaves every
   orphan count at 0 and every cardinality at 4/4/4. Compare the **relationship tuples themselves**
   as an ordered, keyed digest over the full edge set: `(attempt_id → order_id)`,
   `(order_id → order_item_id)`, `(dispatch_reservation_id → order_id)`,
   `(dispatch_reservation_id → submission_id)`, `(legacy reservation_id → submission_id)`,
   `(review_request_id → submission_id)`. Orphan counts are retained only as a cheap secondary
   signal.
3. **Legacy confirmed reservations.** The **4** legacy `confirmed` rows must keep their
   identity, `state`, `submission_id`, and non-NULL `active_slot_key` (UNIQUE). Hash over those
   columns before/after.
4. **Guard records.** The 14 guard rows, including the 1 `legacy_unplanned` row, must keep their
   `(guard_id, resource_id, local_date, legacy_unplanned)` set.
5. **Amounts / currencies / immutable snapshots — over ALL baseline records, not only
   paid/confirmed ones.** The original wording scoped this to "paid/confirmed/staff-approved"
   records, which **excludes exactly the rows a schema change is most likely to disturb**: draft
   and unplaced `commerce_order` rows, `pending`/`failed` checkout attempts, and unconfirmed
   (`pending`, `held`, `released`, `cancelled`) dispatch reservations. The comparison must cover
   **every row present at baseline, in every state**, with a keyed digest over, where the column
   exists: record id, state, amount in integer cents, currency code, customer/`uid` association,
   `submission_id` association, `order_id`/`order_item_id` binding, `price_snapshot_version`,
   `quote_payload_digest`, `quote_job_facts_digest`, the reservation binding (`active_slot_key`,
   `resource_id`, `local_date`), and the guard identity. Values are never printed. A DDL-only
   change must leave every digest unchanged.
6. **Files.** `file_managed` set (0) **and** on-disk file inventory per site (default
   `sites/default/files`; QA `sites/qa/files` + private) by path+size+content hash. `file_managed`
   = 0 is **not** proof no files exist.
7. **Schema delta.** After the change, confirm **exactly** the intended columns/tables were added
   and **nothing else** (compare full `information_schema` column sets per touched table).
8. **Comparison method (added 26A-R1).** Every digest above must be a **private keyed** digest
   (HMAC with a key held outside the repository) over a **deterministically serialized** record:
   fixed column order, explicit `ORDER BY` on the primary key, canonical NULL and numeric
   rendering, and a declared text encoding — so that a digest mismatch means a data change and
   never a serialization or row-order artefact. Publish **only** the per-check result and digest,
   never the serialized input, the key, or any row value.
9. **Unexplained drift is a stop condition (added 26A-R1).** If a digest changes and no authorized
   concurrent operation accounts for it, **stop**. Do not conclude that the executing task caused
   it, and do not conclude that it is benign; both are unproven. Record the failing check, preserve
   the state, and escalate.

### 6.3 Legitimate concurrent change vs task-caused change

- Between the baseline (this task) and any execution, **new orders/reservations/reviews may
  legitimately appear**. The before/after comparison must therefore be **set-based and additive-
  aware**: it asserts that **pre-existing** identities/statuses/digests are unchanged and that no
  new orphans or schema changes beyond the manifest appear — it does **not** assert that totals
  are equal (a new booking would legitimately raise a count).
- Any change to a **pre-existing** record's status, amount, digest, or relationship is a
  task-caused failure unless separately explained by an authorized concurrent operation.

Label: **PASS (check design specified)**; the checks themselves are **NOT RUN** until execution.

---

## 7. Findings summary

| # | Finding | Label |
| --- | --- | --- |
| 1 | 15-vs-16 argument mismatch **still exists**; default compiled container holds 15 (`@database` absent), QA holds 16 | **PASS** (confirmed) |
| 2 | Mechanism: container cache key excludes YAML content/mtime; default compiled 2026-10-01, `services.yml` edited 2026-10-05, QA compiled 2026-10-06 | **PASS** |
| 3 | Source files byte-identical host vs container (sha256 match) — mismatch is container-only | **PASS** |
| 4 | Compiled container is a **Redis hash**, version-scoped key; leftover 10.6.15 keys present | **PASS** |
| 5 | Default site at schema 10046 with **8 Task 24B columns, booking-admission table, and `admission_generation` all absent**; QA at 10049 fully migrated | **PASS** |
| 6 | A rebuild without `update_10047` leaves checkout **fail-closed** (attempts refused), not crashed | **REQUIRES_VERIFICATION** (source-backed; not exercised) |
| 7 | `update_10047/10048/10049` are additive DDL, no backfill, no scheduling/admission/transaction effect; only `10049` alters a legacy table's schema (additive, nullable) | **PASS** |
| 8 | No protected-logic boundary is crossed by the normal update sequence | **PASS** |
| 9 | `ddev-ppu-web` reports **unhealthy**: last healthcheck is `/var/www/html:OK mailpit:FAILED phpstatus:FAILED`, exit 1, failing streak 10708. `phpstatus` is an HTTP request into PHP-FPM; the §1.3 bootstrap successes were PHP **CLI**, so they do not clear it | **FAIL** (failed check identified, 26A-R1); root cause and blast radius **REQUIRES_VERIFICATION** |
| 10 | Gateways: 0 configured on both sites; local payments 0 | **PASS** (local only; not proof of no external activity) |
| 11 | QA cannot rehearse a 10046→10047 change (it is already 10049); isolated target required | **PASS** |
| 12 | Real-DB atomicity and failed-rollback disposal remain unresolved | **BLOCKED** (pre-existing; separate authorization) |
| 13 | `updatedb --no-post-updates` is **not a valid option** in installed Drush 13.6.2 — `updatedb` declares exactly one option, `cache-clear` (`UpdateDBCommands.php:47,51`) | **FAIL** (26A-R1; the original plan's command would not run) |
| 14 | `updatedb` **clears caches by default** and the flush is **not conditional on success**, so an aborted run also invalidates the compiled container and the next request runs new wiring on a partially-migrated schema | **FAIL** (26A-R1; source-backed. Runtime behaviour **REQUIRES_VERIFICATION**) |
| 15 | The update kernel builds a fresh container **in-process** from current source and declines to cache **that** container — which is **not** the same as leaving the site's cached container in place; the site's container bin is invalidated by the same run | **PASS** (26A-R1; distinction established) |
| 16 | `10047` and `10049` can **partially complete**: both mutate inside a loop, and `10049`'s compatibility check sits in the mutating loop, not its preflight. MariaDB DDL commits implicitly | **FAIL** (26A-R1; corrects "fails before any mutation") |
| 17 | The missing 16th argument `@database` **is** the `DispatchCheckoutManager` transaction owner, so activating the 16-arg container is exactly what exposes the unresolved transaction path — B4 cannot close B3 | **BLOCKED** (26A-R1; B3 gates default-site activation independently) |
| 18 | Two 26A scratch artefacts persist in the container (`/tmp/cols.php`, `/tmp/preserve.sql`); cleanup was **not** verified and `/tmp/preserve.sql` was never recorded in 26A | **FAIL** (26A-R1; inventoried in §10.5, contents not printed) |

---

## 8. Tool coverage and limits

| System | Use | Limit |
| --- | --- | --- |
| Graphify | Query-first orientation for PHP structure | `graphify-out/graph.json` is **stale** relative to working-tree edits; PHP line references here are **read from source**, not graph-established. Graph does **not** cover YAML or SQL. |
| GitNexus MCP | Not used this task | Local index does not model container wiring; 25A showed 0 callers resolved for `DispatchOrderFactory`. No value added over direct inspection here. |
| Context7 MCP | Not required this task | The question is about local runtime state, not library behavior. |
| DBHub MCP | **Suspended** | Per instructions, bounded read-only SQL/Drush via the running `ddev-ppu-web` container was the authorized fallback. Not reactivated. |
| Direct inspection | `docker exec ddev-ppu-web … drush …`; Redis via PhpRedis inside the container | `.ddev/config.yaml`, `settings.php`, `sites.php`, and the module's YAML/install read directly. **Never `ddev exec`** (avoids start/recreate). |
| Installed vendor source (26A-R1) | Drush 13.6.2, core 10.6.16, `annotated-command`, `contrib/redis` read at file:line | The authoritative source for the update lifecycle (§10.1–§10.3). Outside Graphify's coverage, so **read from source**, never graph-established. |
| `docker inspect` (26A-R1) | Healthcheck status, streak, definition, last output | Read-only; no restart. Identifies *which* checks fail, not *why* (§10.4). |

---

## 9. Commands actually run (representative; all read-only)

| Purpose | Command shape | Result |
| --- | --- | --- |
| Revision / dirty work | `git rev-parse HEAD`, `git status --porcelain` | `b49c259e…`, 29 tracked / 16 untracked |
| Container health | `docker ps --format …` | web **unhealthy**; others up |
| Site identity | `drush --uri=… core:status`, `sql:query "SELECT DATABASE()"` | `db` / `qa`; both bootstrap |
| Schema versions | `drush php:eval` reading `system.schema` | default 10046, QA 10049 |
| Routing mode | `drush php:eval` on `routing_mode_resolver` | `development_fixed` both |
| Arg counts | `grep -c 'private readonly'`; `services.yml` block; Redis hash parse | 16 / 16 / **15 default**, 16 QA |
| Compiled container | PhpRedis `hGetAll` on the version-scoped key | 15 vs 16 args; timestamps |
| File identity | `sha256sum` host and container | identical (§2.4) |
| Pending hooks | `drush updatedb:status --format=json` | default 10047/10048/10049; QA none |
| Actual schema | `SHOW COLUMNS`; `schema()->fieldExists()`/`tableExists()` per URI | §3.2 |
| Preservation aggregates | bounded `sql:query` counts + orphan joins | §6.1 |

**Failures encountered and worked around (no state change):** nested-shell SQL quoting broke
several aggregate queries; resolved by running each query as a single-quoted `sql:query` and, for
the schema probe, a container-side `php:script` per URI. `commerce_payment_gateway` is not a table
(ERROR 1146) — it is a config entity, read via the entity API instead. No command was retried in a
way that changed runtime state.

> **Corrected 2026-10-08 (26A-R1) — see §10.5.** "No state change" overstates it. No *Drupal*
> runtime or database state was changed, but the run **wrote two scratch files** into the web
> container (`/tmp/cols.php`, `/tmp/preserve.sql`) and **did not remove them**. Creating a scratch
> file is a filesystem change and is recorded as one.

---

## 10. Correction record — Task 26A-R1, 2026-10-08 (America/New_York)

Governing instruction: [TASK-26A-R1-PROMPT_SPEC.md](TASK-26A-R1-PROMPT_SPEC.md). Scope:
**documentation correction plus bounded read-only inspection.** No migration, `drush cr`,
update-hook execution, installation, container lifecycle change, backup restoration, fixture,
payment, external routing call, automated suite, commit, or memory edit was performed. Task 26A's
Step-A/B/C **evidence is retained**; what changed is the lifecycle account and the actionable
instructions built on it.

Unchanged: **25A verdict B**, **25B–25D BLOCKED**, **26B BLOCKED / NOT AUTHORIZED**.

### 10.1 The Drush execution lifecycle, pinned to the installed source

Installed versions, from `composer.lock`: **Drush 13.6.2**, **Drupal core 10.6.16**. The live
docroot is the repository root (`.ddev/config.yaml` → `docroot: .`), so root `core/` (10.6.16) is
authoritative; the parallel `web/core/` tree is 10.6.15 and is **not** the live core. The two
`UpdateKernel.php` copies are byte-identical
(`3b6a8e163ff8c05efab4b5704d35906db0c0bd19f8d6b742373afa7e4b1e0842`), so nothing below depends on
which was read.

**`updatedb` is not three isolated DDL operations.** In source order:

| # | Phase | Source | Note |
| --- | --- | --- | --- |
| 1 | Bootstrap `FULL` under the **update kernel**, environment `prod` | `UpdateDBCommands.php:48,50`; `Boot/Kernels.php:61`; `Boot/DrupalBoot8.php:195-197` | `$allow_dumping = FALSE` for the update kernel (`:196`). Environment is `prod` — the **same** cache-key namespace as a normal web request. |
| 2 | Force an in-process container rebuild; refuse to cache it | `core/lib/Drupal/Core/Update/UpdateKernel.php:38-44`, `:49-53` | `containerNeedsRebuild = TRUE`; `cacheDrupalContainer()` returns `FALSE` "so it does not leak into the main site at all". |
| 3 | Load `install.inc` / `update.inc`, `drupal_load_updates()` | `:53-55` | — |
| 4 | Requirements check; on error **prompt** to continue | `:62-66` | `UserAbortException` if declined. `--yes` auto-confirms. |
| 5 | Re-dispatch `updatedb:status` as a **sub-process** | `:71-72` | `mustRun()`. Status itself also runs under the update kernel (`:107`). |
| 6 | **Prompt** "Do you wish to run the specified pending updates?" | `:76-78` | `--yes` auto-confirms, which removes the human review gate. |
| 7 | Pre-run schema-version write: `setInstalledVersion($module, $number - 1)` | `:378-384` | A **write** before any hook runs. |
| 8 | Hook batch; each hook runs with `MAINTENANCE_MODE = 'update'` defined | `:171-259`, `:176-178` | Disables config-entity overrides. |
| 9 | Per-hook success write: `setInstalledVersion($module, $number)`; on `\Throwable`, record `#abort` and **skip** the write | `:255-258`, `:213-217` | This is the retry/bookkeeping seam (§4.2.1). |
| 10 | If post-updates pend **and** regular updates ran: insert `cacheRebuild()` **between** them | `:396-399`, `:471-480` | Full flush + `rebuildContainer()` + `loadAll()` + `invokeAll('rebuild')` **mid-batch**. |
| 11 | Post-update batch operations | `:400-402`, `:268-340` | None pending for this module (§4.1). |
| 12 | **Maintenance mode set to TRUE** if it was off; restore appended as the **last** batch operation | `:405-409`, `:439-442` | See the hazard below. |
| 13 | Final `drupal_flush_all_caches()` **if `cache-clear`** — default **`true`** | `:51`, `:91-97` | See §10.2. |
| 14 | Return `EXIT_SUCCESS` / `EXIT_FAILURE` | `:99` | The flush at step 13 runs **before** this, regardless of `$success`. |

**`--no-post-updates` does not exist.** `updatedb` declares exactly one option —
`#[CLI\Option(name: 'cache-clear', …)]` with `$options = ['cache-clear' => true]`
(`:47`, `:51`). Negated forms are generated implicitly, and only for options whose default is
`=== true`: `annotated-command/src/Parser/CommandInfo.php:562-575`. That same mechanism **does**
produce `--no-cache-clear` and **does not** produce `--no-post-updates` — a positive control for
the negative finding. The original command would be rejected at option parsing, before any work.
This matches the [Drush `updatedb` documentation](https://www.drush.org/13.x/commands/updatedb/),
which documents `--cache-clear` and no post-update switch.

**Maintenance-mode hazard (new, source-backed).** `restoreMaintMode(false)` is appended as the
**final** batch operation (`:408`). `drush_backend_batch_process` stops on an aborted operation, so
an update that fails partway can leave `system.maintenance_mode` **ON**. Any execution plan must
treat "maintenance mode still on after a failed run" as an expected outcome to check and clear
deliberately, not as a surprise. **REQUIRES_VERIFICATION** (not exercised).

### 10.2 Why the "old container retained" checkpoint does not exist

`updatedb`'s default flush is `drupal_flush_all_caches()` with no `$kernel` argument
(`UpdateDBCommands.php:96`). In `core/includes/common.inc:437`:

- `:444-446` — `foreach (Cache::getBins() as $cache_backend) { $cache_backend->deleteAll(); }` —
  **every** bin, including `container`.
- `:467-471` — because `$kernel` is `NULL`, it takes `\Drupal::service('kernel')`, then calls
  `invalidateContainer()` **and** `rebuildContainer()`.
- `DrupalKernel::invalidateContainer()` (`core/lib/Drupal/Core/DrupalKernel.php:1306-1319`) sets
  `containerNeedsRebuild = TRUE` and calls `cache.container->deleteAll()` (`:1318`) — a **bin-wide**
  delete, not a key-scoped one.

**The distinction the original plan missed.** The update kernel not caching **its own** container
(`UpdateKernel.php:49-53`; `$allow_dumping = FALSE`) is a statement about *that* container only. It
does **not** mean the normal site's cached container survives. The normal site's `container` bin is
invalidated by the same `updatedb` run, and because the update kernel runs in the **same `prod`
cache-key namespace** (`DrupalBoot8.php:197`; key shape at `DrupalKernel.php:906-909`), there is no
separate namespace protecting it. After `updatedb` the next ordinary request compiles the current
**16-argument** source. There is therefore **no window** in which "schema is current and the old
15-argument container is still serving", and the separate `drush cr` step is **redundant**.

**Consequence for approvals.** Blocker **B2** (approval of the activated wiring set) and blocker
**B3** (the unresolved transaction path) gate the **`updatedb`** step, not a later `cr`.

**Consequence for verification.** Re-reading the Redis hash is **no longer sound proof** on its own
— see §10.3.

### 10.3 Redis invalidation is a marker, not a deletion — and the site boundary is a prefix

`modules/contrib/redis/src/Cache/CacheBase.php:511-519`: `deleteAll()` does **not** delete keys. It
writes a `_redis_last_delete_all` timestamp (`:50`, `:518`); reads compare an entry against it
(`:500-506`) and treat older entries as misses.

Two consequences:

1. **The stale 15-argument hash can still be physically present after the change** while being
   logically dead. A verification step that re-reads the Redis hash and counts arguments (the
   original success criterion) may read the invalidated entry and report **15** — a false FAIL — or
   read a fresh entry. Either way the hash read alone does not establish the live wiring. The sound
   check is the **effective service definition through a bootstrapped kernel**, with the hash read
   kept only as corroboration.
2. **The default/QA boundary is a key-prefix convention on a shared Redis instance**, not an
   instance boundary: `getKey()` is `getPrefix() . ':' . $bin . ':' . $cid` (`:258-265`), and 26A §2.2
   observed two distinct prefixes. That is consistent with per-site scoping but **does not prove**
   that a default-site flush cannot affect QA's `container` bin. Marked
   **REQUIRES_VERIFICATION**; it must be verified, not inferred from one observed key.

### 10.4 The unhealthy web container, identified read-only

`docker inspect ddev-ppu-web` (read-only; no restart, no repair):

| Field | Value |
| --- | --- |
| Health status | `unhealthy` |
| Failing streak | **10708** (interval 1 s → ≈3 h continuous) |
| Healthcheck | `CMD-SHELL /healthcheck.sh`, timeout 70 s, retries 70, start period 240 s |
| Last output | `/var/www/html:OK mailpit:FAILED phpstatus:FAILED` (exit 1) |

From the container's own `/healthcheck.sh`: `phpstatus` is `curl --fail -s 127.0.0.1/phpstatus`
(`:62`) and overall health requires `phpstatus && htmlaccess && mailpit` (`:70`). Nothing sensitive
appears in the output; it was scanned and is reproduced verbatim as the only two failing check
names.

Interpretation: the **code mount is healthy**, so this is not a Mutagen or mount fault. The failing
checks are the **nginx→PHP-FPM HTTP path** and the **local mail sink**. 26A's §1.3 bootstrap
successes were obtained through PHP **CLI** (`docker exec … drush`), which does not traverse FPM,
so they do **not** clear `phpstatus`. This is an **unresolved execution prerequisite** for any step
that depends on serving HTTP (browser verification, the post-change request that compiles the new
container) or on mail isolation. Root cause, duration significance, and blast radius remain
**REQUIRES_VERIFICATION**. No repair was attempted and none is authorized here.

### 10.5 Scratch-artefact inventory from the 26A run

Inventory scope: container `/tmp` at depth 1, entries newer than 2026-10-07; plus untracked host
files in the repository. **Contents were not printed**; files were characterised by fingerprint and
by keyword shape only. Anything outside that scope is unenumerated.

| Artefact | Fingerprint (SHA-256) | Size | Created | Purpose and shape | Cleanup |
| --- | --- | --- | --- | --- | --- |
| `/tmp/cols.php` (container) | `62e62f0800eec2870728d7c911cb6579b52d2433657117a68182c5b7d7941e57` | 972 B | 2026-10-08 06:07 | The §3.2 actual-schema probe. API surface: `getConnection`, `schema`, `tableExists`, `fieldExists`, `print`. Read-only. Credential-shape matches: **0**. | **NOT verified — still present.** |
| `/tmp/preserve.sql` (container) | `ef9966efe7d25b2e4de32ff70f965cf8806a49c67a0a0f7c83d25719efc51772` | 1273 B | 2026-10-08 06:01 | The §6.1 preservation aggregates. 14 `SELECT`s over 11 tables (incl. the `commerce_payment_gateway` read that produced ERROR 1146); **0** `INSERT`/`UPDATE`/`DELETE`/`DROP`/`ALTER`. Credential/email-shape matches: **0** (scanner positive-controlled at 14 `SELECT` hits). | **NOT verified — still present.** **This file was never recorded in 26A at all.** |
| Compiled-container dump file | — | — | — | **None found** in the inventory scope. 26A read Redis in-process via PhpRedis, which leaves no dump artefact. | n/a |

Neither file is deleted by this task: §6 of the governing instruction forbids executing or deleting
unidentified temporary files, and both are now identified but their removal is a mutation this task
is not authorized to make. A future approved task must carry an explicit **creation and cleanup
procedure** — or, preferably, use the self-contained diagnostic in NEXT_EXECUTION_PROMPT.md §4 that
needs no scratch file at all.

### 10.6 Index of corrections

| # | Corrected statement | Where it was | Now |
| --- | --- | --- | --- |
| 1 | `updatedb --no-post-updates` | NEXT_EXECUTION_PROMPT.md §5 Step 3 | Option does not exist; removed (§10.1, finding 13) |
| 2 | "updates complete, old container retained until the subsequent `cr`" | READINESS.md §5.4; NEXT_EXECUTION_PROMPT.md §5, §9 | Withdrawn; `updatedb` clears caches by default (§10.2, finding 14) |
| 3 | Updater as three isolated DDL operations | READINESS.md §4.2; NEXT_EXECUTION_PROMPT.md §5 | Full 14-phase lifecycle (§10.1) |
| 4 | `10049` "fails before any mutation" | READINESS.md §4.2, §4.4 | Preflight yes; compatibility check is inside the mutating loop (§4.2.1, finding 16) |
| 5 | Recovery by dropping added columns/tables | READINESS.md §4.2; NEXT_EXECUTION_PROMPT.md §9 | Removed; not losslessly reversible (§4.2.1, §5.4) |
| 6 | B3 and B4 closed together by one rehearsal | NEXT_EXECUTION_PROMPT.md §1, §6 | Separated; B4 cannot close B3 (§5.4, finding 17) |
| 7 | Isolation = a `db` clone | READINESS.md §5.1 | Explicit target manifest, every boundary verified (§5.1) |
| 8 | Unhealthy container "not a blocker" | READINESS.md §1.2 | Failed checks identified; unresolved prerequisite (§10.4, finding 9) |
| 9 | Preservation via orphan counts; paid/confirmed records only | READINESS.md §6.2 items 2, 5 | Relationship tuples; all baseline records in every state (§6.2) |
| 10 | `/tmp/cols.php` referenced with no provenance | NEXT_EXECUTION_PROMPT.md §4, §7 | Self-contained diagnostic; artefacts inventoried (§10.5) |
| 11 | Redis hash re-read as success proof | NEXT_EXECUTION_PROMPT.md §7 | Not sound alone; invalidation is a marker (§10.3) |
| 12 | 26A run described as "no state change" | READINESS.md §9 | Two scratch files written and not cleaned up (§10.5, finding 18) |

### 10.7 Commands actually run in 26A-R1 (all read-only)

| Purpose | Command shape | Result |
| --- | --- | --- |
| Query-first orientation | `graphify query …` (×2) | Established nothing here; graph excludes `vendor/` and YAML, and is stale |
| Installed versions | `composer.lock` read | Drush 13.6.2, core 10.6.16 |
| Live docroot / core identity | `.ddev/config.yaml`, `composer.json`, `Drupal::VERSION`, `sha256sum` | root `core/` 10.6.16 live; `web/core/` 10.6.15; `UpdateKernel.php` identical |
| Drush lifecycle | Read `UpdateDBCommands.php`, `Drupal/UpdateKernel.php`, `Boot/Kernels.php`, `Boot/DrupalBoot8.php` | §10.1 |
| Core lifecycle | Read `core/lib/Drupal/Core/Update/UpdateKernel.php`, `DrupalKernel.php`, `core/includes/common.inc` | §10.2 |
| Option negation | Read `annotated-command/src/Parser/CommandInfo.php`; grep with positive control | `--no-cache-clear` exists, `--no-post-updates` does not |
| Redis semantics | Read `modules/contrib/redis/src/Cache/CacheBase.php` | §10.3 |
| Hook anatomy | Read `custom_webform_handlers.install:4497-4665`; grep for `_post_update_` | §4.2.1; module defines no post-updates |
| Transaction owner | grep `src/Commerce/DispatchCheckoutManager.php` | 16th arg `@database` owns the transaction (`:98`, `:369`, `:499`, `:535`) |
| Container health | `docker inspect ddev-ppu-web`; `docker exec … grep /healthcheck.sh` | §10.4 |
| Scratch inventory | `docker exec … find /tmp`, `sha256sum`, keyword-shape scans with positive control; `git status --porcelain -uall` | §10.5 |

**Not run, deliberately:** no Drush command of any kind (including `updatedb:status`, which boots
the update kernel), no Redis read, no SQL, no `drush cr`, no `ddev exec`, no container lifecycle
operation, no graph rebuild. The §4.1 pending-hook manifest and the §2 container-argument counts are
carried forward from 26A **as historical** and must be re-confirmed immediately before any
execution.

