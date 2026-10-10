# Task 24C-2 — QA schema + admission-store verification

Status: **QA foundation verified; admission enforcement inactive.**
Scope: one additive QA migration + store-only rollback probes. No caller
activation, no public-gate change, no commit. This record accepts Task 24C-1 as
an inactive-source checkpoint, not as behavioural acceptance.

All commands ran against the already-running DDEV container via `ddev drush`
(the `docker` CLI is not on this host's PATH; `ddev exec` is avoided because it
recreates web+db). QA is addressed explicitly with `--uri=https://ppu-qa.ddev.site`
on every invocation; the default site is never substituted.

## 1. Preflight (QA target confirmed)

| Fact | Value | How established |
| --- | --- | --- |
| URI | `https://ppu-qa.ddev.site` | Passed explicitly on every `drush` call. |
| Site dir | `sites/qa` | QA multisite (isolated from active `ppu`). |
| Database | `qa` | QA connection. |
| Installed schema (`custom_webform_handlers`) | **10048** | `keyValue('system.schema')->get(...)`. |
| Pending updates | **none** | `drush updatedb:status` → "No database updates required." |
| Admission table present | **yes** | `Connection::schema()->tableExists(...)`. |

The "stop if unrelated updates are pending" guard held: after applying only
`update_10048`, the pending list is empty — no unrelated update was carried in.

The prior cache rebuild's site is recorded as **QA** for this stage (every `cr`
here was run with `--uri=https://ppu-qa.ddev.site`, see §7). Any earlier
rebuild whose site is not evidenced is left unverified rather than assumed.

## 2. Schema + migration

- **Fail-closed before migration (source-established, not re-demonstrated here).**
  Read from `assertSchema()` in `BookingAdmissionStore`: with the table absent every
  store entry point throws `AdmissionSchemaMissingException`, and a transient
  inspection error also throws and is not memoised, so an absent table never
  degrades to an "admitted" answer. This is established by **reading the source**,
  not by runtime evidence in this stage: the QA table is present (schema 10048) and
  was **never dropped** to recreate the absent-table condition. (Correction logged
  in VERIFICATION-24C-2A §C.)
- **Table added to `hook_schema()`** in `custom_webform_handlers.install`
  (`custom_webform_handlers_schema()`), one row per submission, ordinary
  PRIMARY KEY on `submission_id`. No unique keys, no secondary indexes, no
  generated columns, no default on `generation`.
- **New update hook `custom_webform_handlers_update_10048()`** — verified free
  (not assumed): QA was at `update_10047` before this stage, so 10048 is
  (current max + 1). The hook is idempotent (`tableExists` guard) and creates the
  table from `custom_webform_handlers_schema()[$table]` via `$schema->createTable()`.
- **Verified QA backup** taken before applying: `/tmp/qa-pre-24c2.sql` in the
  container (34,553,480 bytes). Retained as a rollback net while Task 24C is
  incomplete (container `/tmp` only — outside the repo, never committed).
- **Applied only the authorized update** (10047 → 10048). No backfill, no change
  to existing booking data.

Live DDL read back from QA (`SHOW CREATE TABLE`, read-only):

```sql
CREATE TABLE `custom_webform_handlers_dispatch_booking_admission` (
  `submission_id` int(10) unsigned NOT NULL,
  `generation` int(10) unsigned NOT NULL,
  `state` varchar(32) CHARACTER SET ascii ... NOT NULL,
  `owner_token_hash` varchar(64) CHARACTER SET ascii ... NOT NULL,
  `intent_fingerprint` varchar(255) CHARACTER SET ascii ... NOT NULL,
  `owner_kind` varchar(16) CHARACTER SET ascii ... NOT NULL,
  `note` varchar(255) DEFAULT NULL,
  `created_at` int(10) unsigned NOT NULL,
  `updated_at` int(10) unsigned NOT NULL,
  `confirmed_at` int(10) unsigned DEFAULT NULL,
  `released_at` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`submission_id`)
)
```

**Column-size reconciliation against store validation** (`BookingAdmissionStore`):

| Column | Live width | Store writes | Reconciled |
| --- | --- | --- | --- |
| `owner_token_hash` | varchar(64) ascii | sha256 hex = exactly 64 chars | fits exactly |
| `intent_fingerprint` | varchar(255) ascii | caller-minted hash, matched in **full** (never truncated) | fits; not truncated on compare |
| `state` | varchar(32) ascii | longest enum `reconciliation_required` = 23 chars | fits |
| `owner_kind` | varchar(16) ascii | `public` / `staff` / `manual` | fits |
| `note` | varchar(255) | `mb_substr(…, 0, 255)` at write | matched to bound |
| timestamps | int unsigned | request time (always positive) | matches; `confirmed_at`/`released_at` nullable |

FOUNDATION §3's proposed `CHAR(64)` / plain `INT` are realised as
`varchar(64)` ascii and `int unsigned`; behaviourally equivalent for the
fixed-width hash and positive request-time timestamps the store emits.

- **DDL persists after fixture rollback.** MariaDB auto-commits DDL, so the table
  survives the probe's outer `rollBack()`; only the synthetic rows vanish. Proven
  in both probes (§3–§4): table still present, row count returns to baseline 0.

## 3. Contract checks — probe P1 (post-migration, in-transaction)

Ran in a fresh `php:script` process against QA. All writes nested under one outer
`startTransaction()`, undone in `finally`. **RESULT: PASS 55/55** (fall-through
exit 0).

- **Reuse / refusal** — same owner + same intent on a live row → `Reused`
  (admission); wrong owner → `RefusedOwner`; wrong intent under the right owner →
  `RefusedIntent`. A matching fingerprint under a mismatched owner is still refused
  (fingerprint is not an authenticator).
- **Confirmed / reconciliation cannot authorize another booking** — on a
  `confirmed` row another owner's `acquire()` and `verify()` both → `RefusedOwner`;
  the rightful owner re-acquiring → `Reused` (idempotent, not a fresh grant).
  `Confirmed` and `ReconciliationRequired` are **neither** `isAdmitted()` nor
  `isRefused()`, so a caller must test `isAdmitted()` positively.
- **Release / reacquire** — `release()` (non-empty evidence) → `Released`, **row
  retained** at the same PK, generation **not** incremented, evidence stored as
  `note`. Reacquire from `released` → `Admitted`, generation **+1**, re-armed to
  `pending`, `note` cleared. Stale-generation `confirm()` and `release()` (gen 1
  against a row now at gen 2) → `RefusedGeneration`.
- **Fail closed on corrupt rows** — a directly-inserted `state='bogus_state'` row:
  `find()` → `NULL`; `acquire()`/`verify()` → `RefusedState` / reason
  `corrupt_state` (never a live or released answer). The three
  `[error] Unreadable booking-admission row …` log lines are this case
  fail-closing as designed, not failures.
- **`verify()` inside a transaction (positive path only in this stage)** — inside
  the txn it reads its own just-written row → `Verified`; wrong generation →
  `RefusedGeneration`; wrong owner → `RefusedOwner`; on a `released` (non-live)
  row → `RefusedState` / reason `not_live`. `Verified` is neither admission nor
  refusal (it is the binding primitive, non-mutating by design). **Scope
  correction:** this stage exercised only the *inside-transaction* path; running
  these assertions inside a transaction does **not** prove `verify()` *requires*
  one. That precondition was neither enforced nor demonstrated in 24C-2 — it is
  added in code and proven by a standalone-refusal probe in Task 24C-2A (see
  VERIFICATION-24C-2A §A).
- **Nested rollback preserves the outer transaction** — an inner
  `startTransaction()` (savepoint) `confirm()` is visible inside the savepoint,
  and its `rollBack()` returns the row to `pending` while the outer txn stays
  usable.

## 4. Bounded-probe isolation — probe P2 (separate fresh process)

A second, independent `php:script` process. **RESULT: PASS 9/9** (exit 0):

- Table exists (DDL persisted through P1's rollback).
- Row count is **0** (baseline equality — no fixture leaked past `finally`).
- All 7 ledger IDs (`924000001`–`924000007`) absent (`find()` → `NULL`).

Both probes used only synthetic admission-table records keyed on an unused-ID
ledger with a verified-empty baseline. No Webform, Commerce, or capacity
fixtures were created. Failed assertions would force a nonzero outer exit; none
did. **No concurrency, payment, or booking-flow acceptance is claimed.**

## 5. Confirmed defect — FOUNDATION §5 lifecycle (doc corrected, code kept)

The probe empirically confirmed that `confirm()` accepts **only** a `pending`
row (idempotent on an already-`confirmed` one) and **refuses**
`reconciliation_required` with `RefusedState` / reason `not_pending`. The
original FOUNDATION §5 diagram and its "confirmed ↔ reconciliation_required"
bullet described a `reconciliation_required → confirmed` back-edge that the code
does not implement.

Per the directive ("fix confirmed defects only within the authorized
foundation") and because the store's behaviour is the fail-closed authority, the
**documentation was corrected**, not the code: FOUNDATION-24C-1.md §5 now shows
`confirmed → reconciliation_required` as one-way and states that a
`reconciliation_required` row's only exit is `release()` → `released`. No store
code was changed.

## 6. Release-note semantics (activation prerequisite, unchanged)

A non-empty `release()` evidence string is stored verbatim as the `note` column.
It is **audit metadata only** — it is not proof that a payment was refunded, a
booking abandoned, or any downstream obligation discharged. The store does not
interpret it. Enforcing a release policy (who may release, and against what
verified downstream state) remains an explicit **activation prerequisite**, not
something this foundation provides.

## 7. Final verification (exit codes recorded)

| Check | Command (abridged) | Result | Exit |
| --- | --- | --- | --- |
| PHP lint — 7 admission files + `.install` | in-container `php -l` loop | `No syntax errors detected` ×8 | 0 |
| YAML parse + service present | `Yaml::parseFile(services.yml)` + isset | `YAML_OK: booking_admission_store present` | 0 |
| QA cache rebuild | `drush --uri=…ppu-qa… cr` | `[success] Cache rebuild complete.` | 0 |
| Whitespace / conflict markers | `git diff --check` | clean | 0 |
| QA schema version | `keyValue('system.schema')` | `10048` | 0 |
| Pending updates | `drush updatedb:status` | `No database updates required.` | 0 |

No automated suites (PHPUnit/Kernel/Functional) were run, per policy.

## 8. Cleanup

- Container `/tmp/adm_probe1.php` and `/tmp/adm_probe2.php` removed (verified:
  0 remaining).
- Host temp `adm_probe1.php` and `adm_probe2.php` removed (verified: 0 remaining).
- QA backup `/tmp/qa-pre-24c2.sql` (container `/tmp`, ~34.5 MB) **retained** as a
  rollback net while Task 24C is incomplete. It lives outside the repo and is
  never committed; it can be deleted once 24C is accepted or abandoned.
- No repository artifacts were created for the probes; the only tracked additions
  from this stage are documentation and the already-authorized source.

## 9. Remaining gates before any activation

This stage verifies the store's contract and the QA schema. It does **not**
satisfy the activation prerequisites (FOUNDATION §11), which remain open:

1. Validation against **both** capacity families under real routing.
2. Safe anonymous-job identity (`submission_id` for a saved submission).
3. Behaviour against pre-existing in-flight records at activation.
4. Conflict reporting surfaced to operators/UX (not swallowed).
5. Failure compensation when a binding write fails after `verify()`.
6. Release-policy enforcement (§6) and renewal-without-cron end to end.

The service remains inert: no tag, no caller, no subscriber, no hook wiring, no
constructor query.

## 10. Evidence systems used + coverage limits

- **Direct source inspection** (authoritative for YAML and Markdown, which are
  outside Graphify/GitNexus coverage): `.install`, `services.yml`, the seven
  `src/Dispatch/Admission/*.php` files, FOUNDATION-24C-1.md.
- **`ddev drush`** against the running container (php lint, `php:eval`,
  `php:script`, `cr`, `updatedb:status`) — the `docker` CLI is unavailable on
  this host and `ddev exec` is avoided (recreation risk).
- **DBHub** remained **suspended** (policy); no MCP SQL was issued. `SHOW CREATE
  TABLE` was a read-only query through the Drupal DB connection, not DBHub.
- Coverage note: Graphify/GitNexus do not index YAML or Markdown, so service
  wiring and this document are confirmed by direct reads, not graph edges.

---

**QA foundation verified; admission enforcement inactive. Task 24C incomplete;
Task 24B PARTIAL.**
