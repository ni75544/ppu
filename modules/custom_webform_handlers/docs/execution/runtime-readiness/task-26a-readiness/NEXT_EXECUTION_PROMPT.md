# Task 26B — Proposed Next Execution Prompt

> **⚠️ NOT AUTHORIZED FOR EXECUTION.**
> **STATUS: BLOCKED.** This prompt is prepared for review only. It has **not** been run, and
> running it requires the explicit human approvals listed in §6. Do **not** execute any command
> in this file until those approvals are granted in writing for the exact targets named here.

> **CORRECTED 2026-10-08 by Task 26A-R1** — [TASK-26A-R1-PROMPT_SPEC.md](TASK-26A-R1-PROMPT_SPEC.md),
> record in [READINESS.md](READINESS.md) §10 and in §10 below. The command set and checkpoint
> ordering in the original version of this file were **unsafe and partly unrunnable**: it used an
> option that does not exist, relied on a post-`updatedb` state that does not occur, resolved two
> distinct blockers with one rehearsal, inferred isolation, and proposed reverse-DDL recovery.
> Blockers now number **six**, and approval is split into **two** separate gates. Neither is
> granted.

Prepared: 2026-10-08 (America/New_York). Derived from
[READINESS.md](READINESS.md) (Task 26A). Repository `C:\Users\mo737\ppu`.

---

## 1. Why this is BLOCKED rather than ready

The commands are **not** merely awaiting a signature. The sequence itself was wrong (§10), and six
entry prerequisites do not yet exist:

| Blocker | Detail | Why it must be resolved first |
| --- | --- | --- |
| **B1 — No isolated rehearsal target** | QA cannot serve: it is already at schema **10049**, ahead of the default site's **10046**, so it cannot rehearse a `10046 → 10047` application. No `db` clone exists. | The plan mutates the default database's schema. A rehearsal target for that exact transition must be created and approved first. |
| **B2 — Activation scope is broader than three hooks, and it happens at `updatedb`** | Rebuilding the default container activates the **service wiring** of the uncommitted Task 24B/24C/24H source (`DispatchCheckoutManager`, `CheckoutAttemptStore`, `QuoteSnapshot`, planner/store/lock changes, `booking_admission_store`, `domain_event_emitter`, `@database` ownership), not merely the argument count. **Corrected:** this activation is triggered by `updatedb`'s own default cache flush, **not** by a later `cr` (§10.2 / READINESS.md §10.2). | The owner must approve the whole activated wiring set, not just "16 args" — and must do so **before `updatedb`**, because there is no separate `cr` gate to approve it at. |
| **B3 — Runtime atomicity and safe disposal after a failed rollback are unresolved** | Real-database atomicity is recorded **PENDING** and safe disposal after a *failed* rollback **BLOCKED** (`../../commerce-webform-bridge/task-25a-bridge-compatibility/DECISION.md:227-228`, referencing 24C-8C-R2). The missing 16th argument **is** `@database`, which **is** the transaction owner in `DispatchCheckoutManager` (`src/Commerce/DispatchCheckoutManager.php:98`, `:369`, `:499`, `:535`). Activating the 16-argument container is therefore exactly what exposes the unresolved path. | **Correctness evidence is required.** A backup, a restore, or an owner acknowledgment is **not** a substitute. Default-site activation stays blocked wherever it would expose this path. |
| **B4 — Backup completeness and restoration capability unproven** | The private backup (§5.1) has not been taken, and no isolated restoration rehearsal has been performed. | A restore path that has never been exercised is not a recovery path. **A successful restore closes B4 only — it does not close B3.** |
| **B5 — The web container is unhealthy, and the failed checks are prerequisites** | Last healthcheck: `/var/www/html:OK mailpit:FAILED phpstatus:FAILED`, exit 1, failing streak 10708 (READINESS.md §10.4). `phpstatus` is an HTTP request into PHP-FPM; `mailpit` is the local mail sink. The 26A bootstrap successes were PHP **CLI** and do not clear either. | The post-change activation happens on the next **HTTP** request, and any browser verification needs FPM. Mail isolation needs a working sink. Root cause is **REQUIRES_VERIFICATION**; this plan does **not** authorize infrastructure repair. |
| **B6 — No approved isolation target manifest** | A database clone alone is insufficient for a rehearsal that bootstraps Drupal or rebuilds caches. The manifest in READINESS.md §5.1 (code/vendor ownership, database + credentials, URI/settings/files, cache and compiled-container namespace, sessions/queues/cron/workers, email, webhooks/routing/payments) is **unwritten**, and the Redis site boundary is a **key-prefix convention on a shared instance**, not a verified boundary (READINESS.md §10.3). | Isolation must be **verified per component**, not inferred from `--uri` or one observed Redis key. |

Until **B1–B6** are satisfied, this remains BLOCKED. Missing payment-gateway configuration is
**not** listed: it is a separate workstream and is out of scope here.

---

## 2. Exact target

| Field | Value |
| --- | --- |
| Repository | `C:\Users\mo737\ppu` |
| Branch / revision at assessment | `codex-experiments` / `b49c259e3e3d5452286ffb45d1ec6bf1451c0fcc` |
| **Mutated site** | **default only** — URI `https://ppu.ddev.site`, site dir `sites/default`, database `db` |
| **Not touched** | QA site `https://ppu-qa.ddev.site` (database `qa`, schema 10049) |
| Rehearsal target | **to be created/approved** — a clone of `db` is **necessary but not sufficient**; the full isolation manifest in READINESS.md §5.1 must be written and each boundary verified. See **B1** and **B6**. |
| Source files at the centre (sha256, must not change during execution) | `services.yml` `920581e3…`, `DispatchCheckoutManager.php` `b7251503…`, `custom_webform_handlers.install` `172d25b0…` |

## 3. Permitted changes (the whole scope)

1. Apply **`custom_webform_handlers_update_10047`**, **`_10048`**, **`_10049`** on the default
   site — and only those, and only on the default site.
2. The **cache and compiled-container invalidation that `updatedb` performs as part of step 1.**
   This is not a separable second step: `updatedb` clears caches by default, which invalidates the
   default site's compiled container (§10.2). Approving step 1 approves this.

Nothing else. No other module's updates, no Composer change, no source or config edit, no
gateway, no data cleanup, no QA change, no container restart/recreate.

> **Corrected 2026-10-08 (26A-R1).** Item 2 previously read "Rebuild the default site's compiled
> Drupal service container (`drush cr --uri=…`)", presented as a step that could be approved and
> sequenced separately from item 1. It cannot. A separate `drush cr` is **redundant** after a
> default `updatedb`, and treating it as the activation gate hid the fact that activation already
> happened. Also note the side effects `updatedb` carries that are **not** in this scope list and
> must be accounted for in approval: a **maintenance-mode toggle** (set on, restored only as the
> last batch operation — so a failed run can leave it **on**), and **two schema-version writes**
> per hook (§10.1).

---

## 4. Prepared commands — DIAGNOSTIC reads (safe; no mutation)

Run these first and again after, to bracket the change.

```bash
# Revision + dirty-work fingerprint (must be unchanged across execution)
rtk proxy git -C C:/Users/mo737/ppu rev-parse HEAD
rtk proxy git -C C:/Users/mo737/ppu status --porcelain

# Source fingerprints (must equal §2 values)
rtk proxy docker exec ddev-ppu-web sha256sum \
  /var/www/html/modules/custom_webform_handlers/custom_webform_handlers.services.yml \
  /var/www/html/modules/custom_webform_handlers/src/Commerce/DispatchCheckoutManager.php \
  /var/www/html/modules/custom_webform_handlers/custom_webform_handlers.install

# Pending hooks (expect exactly 10047, 10048, 10049 on default; none on QA)
rtk proxy docker exec ddev-ppu-web bash -c 'cd /var/www/html && vendor/bin/drush --uri=https://ppu.ddev.site updatedb:status --format=json'
rtk proxy docker exec ddev-ppu-web bash -c 'cd /var/www/html && vendor/bin/drush --uri=https://ppu-qa.ddev.site updatedb:status --format=json'
```

> Note: `updatedb:status` itself boots the **update kernel** (`UpdateDBCommands.php:107`), which
> compiles a fresh container in-process from current source. It is read-only — it does not write
> the container to cache (`UpdateKernel.php:49-53`; `DrupalBoot8.php:196`) and does not flush
> caches or touch maintenance mode — but it means a successful status check says nothing about the
> site's **cached** container.

### 4.1 Actual-schema probe — self-contained, no scratch file

> **Corrected 2026-10-08 (26A-R1).** The original §4 ended with
> `drush … php:script /tmp/cols.php`, a scratch file with no reviewed contents and no provenance,
> so the step was not reproducible. That file **still exists** in the container
> (sha256 `62e62f08…`, 972 B) along with an unrecorded `/tmp/preserve.sql`
> (sha256 `ef9966ef…`, 1273 B); neither was cleaned up — see READINESS.md §10.5. The reference is
> replaced below with a self-contained command that creates **no** file.

The probe's purpose: report, per site, whether each of the nine columns and one table that
10047–10049 govern actually exists — because a schema version number is not proof. Expected output
is one `COL`/`TBL` line per item with `1` or `0`, then a `RESULT:` line.

```bash
# READ-ONLY. Creates no file. Prints only schema existence flags — no row data.
rtk proxy docker exec ddev-ppu-web bash -c 'cd /var/www/html && vendor/bin/drush --uri=https://ppu.ddev.site php:eval '"'"'
$s = \Drupal::database()->schema();
$t = "custom_webform_handlers_checkout_attempt";
$cols = ["quote_route_meters","quote_route_seconds","quote_line_items","quote_pair_fingerprint","quote_payload_schema_version","quote_payload_digest","quote_job_facts_digest","quote_job_facts"];
$ok = 0;
foreach ($cols as $c) { $e = (int) $s->fieldExists($t, $c); $ok += $e; print "COL $t.$c=$e\n"; }
foreach (["custom_webform_handlers_checkout_attempt","custom_webform_handlers_dispatch_reservation","custom_webform_handlers_reservation"] as $bt) {
  print "COL $bt.admission_generation=" . (int) $s->fieldExists($bt, "admission_generation") . "\n";
}
print "TBL custom_webform_handlers_dispatch_booking_admission=" . (int) $s->tableExists("custom_webform_handlers_dispatch_booking_admission") . "\n";
print "RESULT: snapshot_columns_present=$ok/8\n";
'"'"''
```

Three cautions on reading its result:

- **`drush php:script`/`php:eval` swallow exit codes.** Judge by the `RESULT:` line, never by `$?`.
- `snapshot_columns_present` between 1 and 7 means a **partially applied** `10047` (§4.2.1 of
  READINESS.md), which is a stop condition, not a retry prompt.
- If a future task does need a file-based probe, it must carry the file's exact contents, purpose,
  expected output, fingerprint, and an explicit creation **and cleanup** procedure in the approved
  plan. Creating a scratch file is a filesystem change and must be recorded as one.

The preservation queries in READINESS.md §6.2 are **reads** and belong in the before/after
bracket. They must be run with the keyed, deterministically-serialized method of §6.2 item 8, and
must publish digests only.

---

## 5. Prepared commands — MUTATING (do NOT run without approval)

> **Corrected 2026-10-08 (26A-R1).** The original ordering rationale read: "`updb` runs without
> instantiating the mismatched service (the container builds services lazily), so the schema can be
> brought current while the old container is still in place; the container is then rebuilt only
> once the schema can satisfy it." **Both halves are unsupported.** `updatedb` boots the **update
> kernel**, which force-builds a fresh container in-process from current source
> (`core/lib/Drupal/Core/Update/UpdateKernel.php:38-44`), so laziness is irrelevant; and
> `updatedb` ends with `drupal_flush_all_caches()` by default (`UpdateDBCommands.php:51`, `:95-97`),
> which invalidates the site's cached container — so the old container is **not** still in place
> afterwards. The rationale and the two-step ordering it justified are withdrawn.

**Source-backed proposal: there is one mutating step, not two.** A default `updatedb` run on the
default site applies the hooks **and** invalidates the compiled container in the same command. The
new 16-argument wiring therefore goes live on the **next HTTP request**, with no intervening gate.
Follow the normal update lifecycle; do not try to decompose it.

Consequences that must be accepted before approval, each **REQUIRES_VERIFICATION** (none exercised):

| Consequence | Source | Why it matters |
| --- | --- | --- |
| Activation is not separately gated | `UpdateDBCommands.php:95-97`; `core/includes/common.inc:444-446`, `:467-471`; `DrupalKernel.php:1306-1319` | B2 and B3 must be cleared **before** this command, not before a later `cr`. |
| The flush runs even when the batch **aborts** | `:91-97` sits outside the `$success` branch; `:99` returns `EXIT_FAILURE` after it | A failed run activates new wiring over a **partially migrated** schema (READINESS.md §4.2.1). This is the plan's single largest hazard. |
| Maintenance mode may be left **on** after a failure | `:405-409` sets it; `restoreMaintMode(false)` is the **last** batch operation (`:408`) | Expect it, check it, clear it deliberately. |
| A pending post-update anywhere on the site injects a mid-batch container rebuild | `:396-399`, `:471-480` | Re-confirm "no post-updates pending" immediately before running (§4). |

**On suppressing the flush.** `--no-cache-clear` **is** a real option (generated implicitly for
default-`true` booleans: `annotated-command/src/Parser/CommandInfo.php:562-575`). It is **not**
proposed here, and must not be substituted casually: it suppresses only the final flush at `:96`.
It does **not** suppress the mid-batch `cacheRebuild()` at `:398`, the maintenance-mode toggle, the
schema-version writes, or the DDL; and Drush's own comment at `:91-94` states the default exists
"to be compatible with updates that rely on this behavior", so suppressing it is a deliberate
departure from core's update semantics. For pure-DDL hooks the risk appears low, but that is
**REQUIRES_VERIFICATION** and is a separate decision requiring its own approval.

### Step 1 — Private backup (before anything else)

```bash
# Example shape only — the exact backup command and destination require approval.
# Must capture: database `db` (schema+data), sites/default/files, the current compiled
# container key, and the three source fingerprints. Output stays PRIVATE; never committed/printed.
```

### Step 2 — Rehearsal on the approved isolated target

Apply 10047–10049 and rebuild on the **clone/dedicated target** first, and run the §6 checks
there. Only a green rehearsal unlocks Step 3.

> **Corrected 2026-10-08 (26A-R1).** A green rehearsal here closes **B1, B4 and B6** (the target
> exists, the restore works, isolation is verified). It does **not** close **B3**: the rehearsal
> proves recoverability, not transaction correctness. It does **not** close **B5** either. See §6.

### Step 3 — Apply the three hooks on the default site

```bash
# NOT AUTHORIZED FOR EXECUTION
# Applies 10047/10048/10049 AND invalidates the default site's compiled container (see above).
rtk proxy docker exec ddev-ppu-web bash -c 'cd /var/www/html && vendor/bin/drush --uri=https://ppu.ddev.site updatedb'
```

> **Corrected 2026-10-08 (26A-R1).** This command previously carried `--no-post-updates`. That
> option **does not exist** in installed Drush 13.6.2: `updatedb` declares exactly one option,
> `cache-clear` (`UpdateDBCommands.php:47`, `:51`), and the implicit-negation mechanism that
> produces `--no-cache-clear` produces no `--no-post-updates`
> (`annotated-command/src/Parser/CommandInfo.php:562-575`). The original command would have been
> rejected at option parsing. Suppressing post-updates is achieved by there being none pending —
> verified in §4 — not by a flag.

> Scope note: this is **not** a blanket `updb` across all modules by intent; the manifest must be
> re-confirmed to contain only `custom_webform_handlers_update_10047/10048/10049` immediately
> before running. If any other module appears pending, **stop**. `updatedb` will also prompt twice
> (requirements, then "run the specified pending updates?") — answer them interactively. Do **not**
> add `--yes`: it removes the only in-command human review of the manifest.

### Step 4 — Confirm activation (no separate rebuild command)

> **Corrected 2026-10-08 (26A-R1).** This step previously ran
> `drush --uri=https://ppu.ddev.site cr` as the activation gate. After a default `updatedb` that
> command is **redundant** — the container cache is already invalidated — and presenting it as the
> gate concealed that activation had already occurred at Step 3. No separate rebuild command is
> proposed. The note that "`drush cr` is site-scoped by URI and the container cache key embeds the
> site's `container_yamls`" remains true but is **not** proof of cross-site safety: the Redis site
> boundary is a key-prefix convention on a shared instance (READINESS.md §10.3), which is **B6**.

Verify activation per §7 instead. The first ordinary HTTP request to the default site is what
compiles the new container — which is why **B5** (`phpstatus:FAILED`) must be resolved first: with
FPM not serving, that request cannot be made or observed.

### Explicitly forbidden during execution

- `drush cr` on QA (`--uri=https://ppu-qa.ddev.site cr`)
- `ddev exec`, container restart/recreate, `ddev restart`
- Any config import, any Composer operation, any source edit
- Any `updb` on a site other than the approved target

---

## 6. Required approvals before execution — TWO SEPARATE GATES

> **Corrected 2026-10-08 (26A-R1).** The original list had one gate and resolved **B3 and B4
> together** ("B3/B4 resolved: the private backup is taken and an isolated restoration rehearsal
> has passed"). A restore rehearsal cannot resolve a transaction-correctness blocker. The approvals
> are now split in two, and B3 is assigned to the second gate with its own evidence requirement.
> **Neither gate is granted here.**

### Gate 1 — Isolated setup and rehearsal (NOT GRANTED)

Authorizes creating the isolated target and rehearsing there. Authorizes **nothing** on the
default site.

1. **B6:** the target isolation manifest (READINESS.md §5.1) is written, approved, and each
   component's boundary **verified** — explicitly including the compiled-container / Redis
   namespace and the sessions/queues/cron/workers boundary.
2. **B1:** the rehearsal target exists for the exact `10046 → 10047` transition and is approved by
   name.
3. **B4:** the private backup (§5 Step 1) is taken, and restoration into the isolated target is
   **exercised and recorded**.
4. The §4 diagnostics and the §6.2 preservation checks run green in the rehearsal, with the keyed
   deterministic method of §6.2 item 8.

Passing Gate 1 closes **B1, B4, B6**. It does **not** close B2, B3, or B5, and it does **not**
authorize Step 3.

### Gate 2 — Default-site activation (NOT GRANTED; blocked independently of Gate 1)

Authorizes Step 3 on the default site. Requires Gate 1 **plus**:

5. **B2:** the owner explicitly approves the **whole activated wiring set** (the uncommitted
   Task 24B/24C/24H service definitions), acknowledging that it is wider than the three hooks and
   that it goes live at `updatedb`, not at a later rebuild.
6. **B3:** **correctness evidence** for runtime atomicity and for safe disposal after a *failed*
   rollback, covering the `@database` transaction owner the 16th argument activates. A backup, a
   successful restore, or an owner acknowledgment is **not** acceptable in its place. Until this
   exists, default-site activation stays **blocked** wherever it would expose that path.
7. **B5:** the web container's `phpstatus` failure is resolved under a **separate** authorization,
   or the plan is amended to not depend on serving HTTP. Mail isolation (`mailpit:FAILED`) is
   resolved or explicitly accepted with its consequences stated.
8. **Preservation baseline** (READINESS.md §6.1) captured immediately before Step 3, with the
   §6.2 before/after checks ready to run.
9. Re-confirmed immediately before Step 3: the pending-hook manifest contains only
   `custom_webform_handlers_update_10047/10048/10049`; **no post-update is pending site-wide**; the
   three source fingerprints (§2) are unchanged.

## 7. Success criteria

> **Corrected 2026-10-08 (26A-R1).** Two criteria were unsound. The scratch-file probe is replaced
> by §4.1, and the Redis-hash re-read is demoted from proof to corroboration: Redis `deleteAll()`
> writes an invalidation **timestamp marker** rather than deleting keys
> (`modules/contrib/redis/src/Cache/CacheBase.php:511-519`), so the stale 15-argument hash can
> still be physically present while logically dead — a hash re-read can report **15** and produce a
> false FAIL, or report 16 for an entry that is not the served one.

- `updatedb:status` on default is **empty**; QA still empty.
- The §4.1 self-contained probe on default reports `snapshot_columns_present=8/8`, the **booking
  admission** table present, and **`admission_generation` present on all 3 tables**. Judge by the
  `RESULT:` line, not by the exit code.
- The **effective service definition** for `custom_webform_handlers.dispatch_checkout_manager`,
  read through a bootstrapped kernel, holds **16** arguments ending in `database`. The Redis hash
  may be re-read as in 26A §2.2 for corroboration **only**; a 15 there is not by itself a failure,
  and a 16 there is not by itself proof.
- The `hg` handler **instantiates without `ArgumentCountError`** on the default site.
- `system.maintenance_mode` is back to its pre-execution value.
- The §6.2 preservation checks show **no change** to any pre-existing identity, status,
  relationship **tuple**, amount, currency, customer/submission association, snapshot digest,
  reservation binding, guard identity, or file — across **all** baseline records in every state,
  not only paid/confirmed ones. Additive-only growth allowed.
- The schema delta equals **exactly** the manifest: 8 + 1 columns on `checkout_attempt`, 1 new
  table, 1 column on `dispatch_reservation`, 1 column on legacy `reservation`. Nothing else.

## 8. Stop conditions (stop and do not continue)

- Any pending hook other than 10047/10048/10049 appears.
- **Any** post-update is pending site-wide (it would inject a mid-batch container rebuild, §5).
- `update_10049` throws `UpdateException`. **Corrected:** a *missing prerequisite table* is caught
  by the preflight loop and fails before any DDL, but an *incompatible existing column* is caught
  inside the mutating loop and can fail **after** an earlier table was already altered
  (READINESS.md §4.2.1). Record which case occurred, and stop — do not work around either.
- The §4.1 probe reports a partial count (`snapshot_columns_present` between 1 and 7), or any hook
  aborts: the schema version no longer describes the columns. Stop; do not re-run to "finish".
- Any pre-existing record's status, amount, currency, digest, relationship **tuple**, association,
  reservation binding, or guard identity changes.
- Any digest changes with no authorized concurrent operation to explain it. **Unexplained drift is
  a stop condition** — do not conclude it was caused by this task, and do not conclude it is
  benign. Preserve state and escalate.
- Any source fingerprint (§2) changes mid-execution.
- The isolated rehearsal is not green, or the backup/restore rehearsal has not passed.
- Any second-order effect on the shared codebase (e.g. QA changes behavior).
- `system.maintenance_mode` is left on and its cause is not understood.

## 9. Recovery / checkpoints

> **Corrected 2026-10-08 (26A-R1).** "Checkpoint 1 — after `updb`, before `cr` (schema current,
> container old)" **does not exist**: `updatedb` clears caches by default and invalidates the
> compiled container in the same command (§5, READINESS.md §10.2). There was never a safe,
> cheap intermediate stop between the two, and the old-container half of Checkpoint 2's recovery
> story was unachievable. Both are withdrawn.

- **Checkpoint 0** — before Step 3 (private backup). This is the **only** checkpoint the proposed
  command set actually produces.
- **After Step 3 there is no pre-activation stop.** The hooks have run (possibly partially) and
  the container is invalidated. The next HTTP request compiles the 16-argument wiring. Plan for
  this state, do not plan to stop before it.
- **This holds on failure too.** The flush is not conditional on success, so an aborted run also
  reaches the activated-wiring state — over a partially migrated schema. Treat a failed Step 3 as
  *more* urgent than a successful one, not less.
- **No automatic reverse-DDL.** Dropping the added columns or the new table is **not** offered as a
  recovery step. Safety depends on what has been written since: `CheckoutAttemptStore` starts
  populating the eight `quote_*` columns as soon as the schema state reads `complete`, so after a
  single new attempt a `DROP COLUMN` **destroys data**. Additive DDL is not automatically
  losslessly reversible. Any reversal must be designed against the actual post-change writes and
  approved separately.
- **No restoring an old compiled container.** The container cache key does not encode the source it
  was compiled from (26A §2.2), so reinstating the previous entry would place a **15-argument**
  definition over a **migrated** schema — a different broken state, not a recovery. The Redis
  entry is in any case only timestamp-invalidated, not a usable restore artefact
  (READINESS.md §10.3).
- A restore must **never** roll back legitimate orders/reservations created after the baseline.
  If any exist, restore into isolation and reconcile — do not overwrite live writes.
- **Recovery does not resolve B3.** Restoring successfully demonstrates B4 only. The unresolved
  transaction path remains unresolved after any number of successful restores.

---

## 10. Correction record — Task 26A-R1, 2026-10-08 (America/New_York)

Governing instruction: [TASK-26A-R1-PROMPT_SPEC.md](TASK-26A-R1-PROMPT_SPEC.md). Full lifecycle
evidence and source citations: [READINESS.md](READINESS.md) §10. Scope of 26A-R1: **documentation
correction plus bounded read-only inspection.** No command in this file was executed, and no
migration, `drush cr`, update-hook execution, installation, container lifecycle change, backup
restoration, fixture, payment, external routing call, automated suite, commit, or memory edit was
performed. **Task 25A verdict B, Tasks 25B–25D BLOCKED, and Task 26B BLOCKED / NOT AUTHORIZED
remain unchanged.**

| # | Original instruction | Status | Correction |
| --- | --- | --- | --- |
| 1 | `updatedb --no-post-updates` (§5 Step 3) | **Unrunnable** | Option not defined in Drush 13.6.2; `updatedb` declares only `cache-clear`. Command is now plain `updatedb`; post-update suppression comes from none being pending, verified in §4. |
| 2 | "Checkpoint 1 — after `updb`, before `cr` (schema current, container old)" (§9) | **Non-existent state** | `updatedb` clears caches by default, invalidating the compiled container. Checkpoint withdrawn; §9 now records that there is no pre-activation stop after Step 3. |
| 3 | Ordering rationale: "`updb` runs without instantiating the mismatched service … old container still in place" (§5) | **Unsupported** | The update kernel force-builds a fresh container in-process, so laziness is irrelevant; and the flush removes the old container. Rationale withdrawn; §5 now states the real lifecycle. |
| 4 | Step 4 `drush cr` as the activation gate (§3 item 2, §5) | **Redundant and misleading** | Removed as a command. Activation occurs at Step 3, so **B2 and B3 now gate Step 3**. |
| 5 | `drush php:script /tmp/cols.php` (§4, §7) | **Not reproducible** | Replaced by the self-contained §4.1 probe that creates no file. The two surviving 26A scratch artefacts are inventoried in READINESS.md §10.5; cleanup was **not** verified. |
| 6 | "B3/B4 resolved: the private backup is taken and an isolated restoration rehearsal has passed" (§6) | **Conflated two blockers** | Split. B4 = backup completeness and restoration capability; B3 = runtime atomicity and safe disposal after failed rollback, requiring **correctness evidence**. Approval split into Gate 1 and Gate 2. |
| 7 | Rehearsal target = a clone of `db` (§2, §6) | **Insufficient** | New **B6**: an exact isolation target manifest with every shared component's boundary verified, not inferred from `--uri` or one Redis key. |
| 8 | Unhealthy web container not listed as a blocker | **Omitted prerequisite** | New **B5**: `phpstatus:FAILED` and `mailpit:FAILED` identified read-only; the activation request and any browser verification depend on FPM. Root cause **REQUIRES_VERIFICATION**; no repair authorized. |
| 9 | "`update_10049` … fails before mutating" (§8) | **Half true** | Preflight yes; the compatibility check is inside the mutating loop and can throw after an earlier table was altered. §8 now distinguishes the two cases. |
| 10 | Redis hash re-read as the container success proof (§7) | **Unsound** | Redis `deleteAll()` is a timestamp marker, not a key deletion, so the stale hash can persist while dead. Demoted to corroboration; the effective service definition through a bootstrapped kernel is the proof. |
| 11 | Preservation via orphan counts; amounts only for paid/confirmed records (§7, via READINESS.md §6.2) | **Too weak** | Relationship **tuples** replace orphan counts; the comparison now covers **all** baseline records in every state, with keyed deterministic serialization publishing digests only. |
| 12 | Recovery by dropping added columns/tables; restoring an old compiled container (§9, READINESS.md §4.2) | **Unsafe** | Removed. Additive DDL is not automatically losslessly reversible once written to, and an old container would be a 15-argument definition over a migrated schema. |

Newly surfaced and not present in the original plan at all: the **maintenance-mode toggle** can be
left on by a failed run (`restoreMaintMode` is the last batch operation); **two schema-version
writes** occur per hook; a pending post-update anywhere on the site injects a **mid-batch container
rebuild**; and `10047` can **partially complete** across its eight `addField` calls. All are
recorded in READINESS.md §10.1 and §4.2.1 and are **REQUIRES_VERIFICATION** — none was exercised.

**Reminder: this file is a proposal. No command above has been executed, and none may be until the
§6 Gate 1 and Gate 2 approvals are granted. Neither gate is granted by this correction.**
