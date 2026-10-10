# Task 24B / Rounds 2–3 — E08/E09 evidence

Status: **PARTIAL** for Task 24B; **Strategy A COMPLETE**; **Strategy B
guarded-confirmation — functional acceptance now PASSES (see
[strategy-b-guarded-confirmation/EVIDENCE.md](strategy-b-guarded-confirmation/EVIDENCE.md)
§10)**. History preserved: the §8 "COMPLETE (47/0)" claim was **RETRACTED** by
EVIDENCE.md §9 (the CS stale-object case was a **FAIL** / reproduced defect; "47/0"
reflected an incorrect acceptance assertion), and that retraction stands. EVIDENCE.md
§10 then **resolved** it — the CS stale-object confirmation now **REFUSES on the
job-facts axis**, the empty-binding current-format downgrade is closed, and the
corrected run is **91 passed / 0 failed, OUTER_EC=0** over the real
planner/store/guard. This is guarded-confirmation functional acceptance **only**: Task
24B stays **PARTIAL** — Commerce order/display integration, concurrency and payment
acceptance remain unverified. This document retains
the historical Rounds 2–3 evidence and recovery chronology. Later results
supersede earlier pending/blocked statements, not the other way around:

- §9 records the corrected value-object harness exit contract.
- §10 records QA-only `update_10047` and the A–I harness (55 passed / 0 failed,
  command exit 0). Migration-not-run claims in earlier sections are historical;
  they are no longer true for QA. No default-site migration is claimed.
- [Strategy A execution evidence, 2026-09-23](strategy-a-evidence-20260923.md)
  records the owner-authorized real attempt INSERT, exact eight-column payload,
  fresh-store hydration, mandatory rollback, and independent cleanup check:
  **68 passed / 0 failed, command exit 0**. This supersedes attempt-persistence
  approval/execution blockers only; it is not a committed booking test.
- [Strategy B guarded-confirmation evidence](strategy-b-guarded-confirmation/EVIDENCE.md)
  records the three source-established assumption corrections (most notably: the
  guard rereads the persisted reservation `price_snapshot_id` under lock, but
  recomputes the job-facts digest from an in-memory submission captured before the
  lock — the persisted-submission-facts reread inside the guard is absent by
  design, `DispatchPlanner.php:525-536`). **The §8 "COMPLETE" claim is RETRACTED by
  EVIDENCE.md §9 (2026-09-24).** The 47/0 run happened (real public
  planner/reservation-store/calendar-guard chain, owner-authorized bounded
  existing-container path, DBHub MCP suspended, **RESULT 47 passed / 0 failed, outer
  exit 0**), but its CS assertion was wrong: CS (stale object after a *saved*
  job-facts change) CONFIRMED where the spec requires **refusal**, so CS is a
  **FAIL** and a reproduced module defect, and Strategy B was **INCOMPLETE** at §9. The
  C1/C4/C5/C7 behaviours and the per-case savepoint isolation stand as historical
  observations. **This was subsequently RESOLVED — see EVIDENCE.md §10:** the CS
  stale-object case now **refuses** on the job-facts axis, the empty-binding downgrade
  is closed, and guarded confirmation is verified as functional acceptance (91/0,
  OUTER_EC=0). The earlier BLOCKED
  (max_connections / dbhub_ro) status also remains as history — neither it nor the
  retracted 47/0 is the current verdict; **EVIDENCE.md §10 is**. Task 24B remains PARTIAL; DBHub
  startup/pool-runtime acceptance stays OPEN and separate (this reservation QA is not
  reported as fixing DBHub).

Guarded-confirmation **functional acceptance is now verified** by EVIDENCE.md §10 (the
CS stale-object case **refuses** on the job-facts axis; empty-binding downgrade closed;
91/0, OUTER_EC=0). The §8 "47/0" remains **retracted** (EVIDENCE.md §9) and is kept only
as history. The closeout cleanup + verification is now **fully evidenced** by EVIDENCE.md
**§10.9** (2026-09-25): an owner-authorized bounded QA-only replay captured the missing
per-identifier `dispatch_guard(resource_id, local_date)` baseline **before** writes
(resource `driver-01`; targets 2026-09-27/28/29 absent), re-ran the ten cases (probe sha256
`61ad4191…0647`, RESULT 91/0, OUTER_EC=0, explicit outer rollback), and independently
re-verified zero residue from a **separate fresh process** (CHECKER_RESULT CLEAN), and
corrected the §10.8 labels (`git diff --check` EC=0 on both fix files, not `--stat`;
`services.yml` parsed by the installed Symfony YAML parser, ARGC=14, `@entity_type.manager`).
The original §10.5 gap and the §9 retraction are preserved as historical — the replay does
**not** retroactively prove the earlier pre-baseline run. Real reservation/order persistence
(Commerce order/display integration), concurrency and payment acceptance, and browser
acceptance remain **unverified**.
**Task 24B is not complete — PARTIAL.**

## 1. What Round 2 corrected

Round 1 closed E08/E09 in outline but left seven gaps a second review found. The
corrections, and where each now lives:

1. **No new snapshot may enter the legacy bypass.** `QuoteSnapshot::build()`
   previously blanked a missing/invalid job-facts identity and still returned a
   schema-current object. It now **refuses** (`build()` returns NULL) unless the
   identity is a valid 64-hex digest *and* the accepted facts re-derive to it.
   The factories (`fromQuoteResult`, `fromApprovedReview`) take a whole
   `AcceptedJob` — not a bare string — so digest and facts provably correspond by
   construction. The only assembler that tolerates absent identity is the private
   `buildLegacy()`, reachable *only* from `fromStored()`'s versionless-and-clean
   branch. A newly created review with underivable facts is refused by the caller
   before it reaches the factory, never recorded as legacy.

2. **NULL-schema downgrade closed.** `fromStored()` treated
   `schemaVersion === NULL` as legacy regardless of populated new-format fields.
   It now computes `$hasNewFormatField` and **refuses** a versionless row that
   carries any of them as a mixed record. The list is every field only a Task 24B
   write produces: payload digest, job-facts digest, accepted-facts JSON,
   line-items JSON, the raw route metrics (`routeMeters` / `routeSeconds`, tested by
   `!== NULL` not falsiness, so a zero-length route still counts as present), and
   the endpoint-pair fingerprint. A genuine pre-24B row — versionless *and* free of
   every one — still hydrates through `buildLegacy()`. Only the three fields a
   legacy row legitimately carried (`routeSource` / `routeFingerprint` /
   `routeCalculatedAt`) are excluded. The route metrics and fingerprint were omitted
   from an earlier version of this check and added in Round 3 (§6 #4;
   `QuoteSnapshot.php` L361–378).

3. **Accepted-job identity folded into the intent key.** `snapshotId` derives from
   six pricing-**output** fields, so two materially different jobs that price
   identically shared it — and, on the same submission and slot, shared the
   attempt idempotency key. `CheckoutAttempt::intentKey()` now **appends**
   `jobFactsDigest` when the snapshot carries one. Append-only: a legacy snapshot
   with no identity keeps its byte-identical pre-fold key, and a current snapshot's
   key is a superset derivation, so nothing that was distinct becomes equal.

4. **Accepted-facts contract completed.** `AcceptedJob` is now `VERSION = 2`, a
   `final readonly class` that retains the canonical facts beside the digest and
   exposes `factsJson()` / `digestForFactsJson()` so a reader can reconstruct the
   accepted job and re-derive its identity. The canonicalizer is **fail-closed**:
   a non-scalar/non-array value throws `\UnexpectedValueException`; encoding uses
   `JSON_THROW_ON_ERROR` and rethrows as `\UnexpectedValueException`. Lists keep
   their stored order (sorting could collapse two different sequences into one
   identity — the fail-open direction); only map key order is normalized. The
   canonical facts are persisted in a new `quote_job_facts` column (nullable,
   added by the deferred `update_10047`). `order_is_paid_and_ready` is documented
   as belonging to the **revalidated set**, not the identity set — a lifecycle
   eligibility flag the BookingWorkflowGate re-proves at confirmation, so freezing
   it into identity would wrongly refuse a job whose paid-and-ready state advanced.

5. **Guarded confirmation bound to persisted authority.** The confirm path now
   binds on **two axes**, both proved inside the Resource Calendar Guard's
   transaction:
   - *Job identity* — the accepted job-facts digest is recomputed from the saved
     submission under the lock and compared with `hash_equals()`.
   - *Snapshot binding* — the accepted snapshot id is compared against the
     `price_snapshot_id` the **reservation itself** persisted when the Hold was
     created, now surfaced on `LockedHold` and read under the same lock. This is
     what makes the identity digest authoritative rather than free-floating: it
     proves the accepted quote being settled is the one *this* reservation was held
     for. Route preparation stays outside the lock; under the lock only bounded
     local comparisons and DB operations run. `confirmCheckoutReservation()` gained
     an `$acceptedSnapshotId` parameter carried from `DispatchCheckoutManager` →
     `DispatchPlanner::confirmFor` → `revalidate`.

6. **Verification record corrected.** See §3. Round 1's harness performed no DB
   writes; its round-trips are value-object serialization/hydration checks, and
   this is now stated plainly. Attempt insertion, guarded confirmation, and
   reservation/order persistence are explicitly PENDING.

7. **Tooling and migration gates respected.** See §4. Graphify refreshed and
   query-first satisfied; no index deleted, no process killed. Migration plan is
   prepared for approval and not run.

## 2. Files changed (all under `modules/custom_webform_handlers/`)

| File | Correction |
| --- | --- |
| `src/Checkout/AcceptedJob.php` | #4 (rewritten: `VERSION=2`, facts retained, fail-closed) |
| `src/Checkout/QuoteSnapshot.php` | #1 #2 #3 #4 (`SCHEMA_VERSION=3`, `buildLegacy()`, mixed-row + identity gates); R3 §6 (`bindingIdentity()` composite; per-field NULL-schema gate incl. route metrics + fingerprint; explicit malformed-line-items refusal) |
| `src/Checkout/CheckoutAttempt.php` | #3 (append-only intent-key fold) |
| `src/Checkout/DispatchCheckoutStarter.php` | #1 #4 (`acceptedJobFor()`, both doors refuse on underivable identity) |
| `src/Checkout/CheckoutAttemptStore.php` | #4 (`quote_job_facts` field write + hydrate) |
| `custom_webform_handlers.install` | #4 (schema + `update_10047` add `quote_job_facts`) |
| `src/Dispatch/Storage/LockedHold.php` | #5 (`priceSnapshotId` surfaced) |
| `src/Dispatch/Storage/DatabaseReservationStore.php` | #5 (`price_snapshot_id` read under lock, both `LockedHold` sites) |
| `src/Dispatch/DispatchCheckoutPlannerInterface.php` | #5 (`$acceptedSnapshotId` param + two-axis contract) |
| `src/Dispatch/DispatchPlanner.php` | #5 (under-lock snapshot-binding check); R3 §6 (version-aware binding refusal bound to the reservation's persisted `price_snapshot_id`; under-lock recompute confirmed, not prefetched) |
| `src/Commerce/DispatchCheckoutManager.php` | #5; R3 §6 (passes `$accepted->bindingIdentity()`, the versioned composite — not the bare `snapshotId`) |
| `docs/…/round2-value-object-probes.php` | Value-object harness (not shipped code). R2 groups A–E (29 executed); R3 adds F/G/H/I + a nonzero-exit terminal (26 checks, lint-clean, execution PENDING — §6). |

## 3. Verification — and the boundary between value-object and database

### 3a. Container-level (executed)

- `php -l` on all 11 changed source files — **no syntax errors** (each linted
  individually; the container shell runs `nounset`).
- `drush --uri=https://ppu-qa.ddev.site cr` (Round 2 only). **Necessary, not
  sufficient — and not re-run in Round 3.** A rebuild resolves and compiles the
  service *container*; it does not instantiate every service, exercise any lazy or
  runtime path, or prove call-site method-signature compatibility (PHP resolves
  those dynamically at call time). So it cannot prove "no changed signature is
  incompatible" — an earlier draft's over-claim, corrected in §6 #2. Round 3 could
  not re-run it at all: `ddev exec` is BLOCKED on this host (Docker CLI in WSL
  only, absent from the Windows PATH), so no post-Round-3 rebuild has run.
- `git diff --check -- modules/custom_webform_handlers` — clean (no whitespace
  errors).
- Caller/implementer sweep (graphify query + full `.php` grep incl. tests):
  every production caller of the changed signatures is updated; no test double or
  other implementer of `DispatchCheckoutPlannerInterface` exists. The only
  old-signature matches are the frozen `docs/.../task-24a-recovery/**` baseline
  copies, which are out of scope and left untouched.

### 3b. Value-object probes (executed) — NO DATABASE WRITES

Harness: `round2-value-object-probes.php` (this directory). It bootstraps Drupal
only so the module's classes autoload, then constructs value objects and compares
the results of their real methods. **It performs no database write, opens no
transaction, takes no lock, inserts no checkout attempt, and confirms no
reservation.** Every "round-trip" is a value-object serialization → hydration
check (`build()` → `fromStored()`) or a pure comparison of two derived identities.
`QuoteSnapshot::build()` is invoked via reflection to mint canonically-derived
baseline rows through the class's *own* real derivation (real payload digest, real
facts↔digest correspondence) — reflection only bypasses the visibility that keeps
controllers out; nothing is faked.

Run on `ppu-qa.ddev.site` via `drush php:script`. **Result: 29 passed, 0 failed.**
These 29 are the Round-2 groups A–E only; the 26 Round-3 additions (groups F–I,
§6) are lint-clean but **not** in this figure — their Drupal-bootstrapped run was
BLOCKED at the time (see §3c and §6c). **SUPERSEDED by §9:** the full A–I harness
(55 checks) has since run green on QA — 55 passed, 0 failed, command exit 0.

| ID | Claim | Correction |
| --- | --- | --- |
| A1 | endpoint whitespace/case noise → identical accepted-job digest | #4 |
| A2 | a different destination → a different accepted-job digest | #4 |
| A3 | `digestForFactsJson(factsJson())` re-derives the stored digest | #4 |
| A4 | int `5` and string `'5'` canonicalize to the same identity | #4 |
| A5 | an empty answer reads the same as an absent one | #4 |
| A6 | a non-scalar/non-array field value is refused by exception | #4 |
| A7 | an un-encodable value is refused by exception (`JSON_THROW_ON_ERROR`) | #4 |
| B1 | `build()` with valid identity + corresponding facts produces a snapshot | #1 |
| B2 | that snapshot carries identity and complete provenance | #1 |
| B3 | `build()` refuses an empty identity (no blank-and-keep) | #1 |
| B4 | `build()` refuses a malformed identity | #1 |
| B5 | `build()` refuses facts that do not re-derive to the identity | #1 #4 |
| C1 | a valid current-schema row hydrates, preserving total/identity/digest | #2 |
| C2 | a tampered payload digest is refused | #2 |
| C3 | an altered total with the digest left intact is refused | #2 |
| C4 | a versionless row carrying a new-format field is refused | #2 |
| C5 | a versionless row carrying only an accepted-job identity is refused | #2 |
| C6 | a current-schema row missing the accepted facts is refused | #1 #2 |
| C7 | a row with an unrecognized schema version is refused | #2 |
| C8 | a fully versionless clean row hydrates as legacy | #2 |
| C9 | a legacy row carries no identity (confirm gate skips, not refuses) | #2 |
| C10 | a row with no currency is refused before schema is consulted | #2 |
| D1 | two different jobs that price identically share a `snapshotId` (the bug) | #3 |
| D2 | same submission+slot+price → DIFFERENT intent keys for different jobs | #3 |
| D3 | two legacy snapshots sharing a `snapshotId` still collide (append-only) | #3 |
| E1 | snapshot-binding predicate: mismatched id fails `hash_equals` | #5 |
| E2 | snapshot-binding predicate: matching id passes `hash_equals` | #5 |
| E3 | job-revision predicate: an edited job fails identity `hash_equals` | #5 |
| E4 | job-revision predicate: the unedited job re-derives to the same identity | #5 |

### 3c. Explicitly PENDING (NOT verified here)

The E-group probes prove the **local comparison predicates** the guarded
confirmation performs. They do **not** exercise:

- **Checkout-attempt insertion** of the new `quote_job_facts` column — requires
  `update_10047` applied (see §4) and a real attempt write.
- **Guarded confirmation under the calendar lock** — the actual DB read of
  `price_snapshot_id` inside `DatabaseReservationStore`, the under-lock recompute
  of the accepted-job identity from the saved submission, and the two-axis refusal
  in `DispatchPlanner::revalidate`. This runs only in a real, authorized guarded
  confirmation on QA.
- **Reservation/order persistence** round-trip through the store and Commerce.

These remain PENDING a separately authorized QA run. Nothing in Round 2 asserts
they were confirmed.

## 4. Deferred / unresolved invariants and gates

- **Reservation-level job-facts digest is deferred (migration-gated).** The
  under-lock snapshot binding (#5) uses the reservation's existing
  `price_snapshot_id`, which requires no migration. Binding the reservation to the
  **job-facts digest** directly (rather than transitively through the snapshot id
  and the sealed attempt row) would need a new reservation column and is deferred
  behind a migration. Until then, the confirm-time job-identity axis is proved by
  recomputing from the saved submission and comparing with the digest carried from
  the sealed attempt row — authoritative because #1/#2 make `fromStored()`
  fail-closed, i.e. the attempt's identity is trustworthy — while the reservation
  binding proves that attempt belongs to this reservation. That binding is the
  versioned composite `QuoteSnapshot::bindingIdentity()` (prefix + `snapshotId` +
  accepted `jobFactsDigest`), compared version-aware against the persisted
  `price_snapshot_id`, **not** the bare pricing-output `snapshotId` — which never
  established accepted-job identity (§6 #3). This is the documented
  residual: a single reservation-owned identity column would make the binding
  direct, and is left for an approved migration.
- **`update_10047` not run.** It adds the whole E08/E09 priced-job payload —
  **eight** columns, all nullable, added idempotently per-column via `fieldExists`
  so a site that already has some is left untouched:
  `quote_route_meters`, `quote_route_seconds`, `quote_line_items`,
  `quote_pair_fingerprint`, `quote_payload_schema_version`, `quote_payload_digest`,
  `quote_job_facts_digest`, and `quote_job_facts`. Verified in the source
  (`.install` lines 365–394: every one `'not null' => FALSE`). It is unapplied on
  **both** sites — `checkout_attempt` carries only its 7 pre-E08 columns
  everywhere. Do **not** infer from QA schema 10046 that 10047 is unapplied *only*
  on QA; verify per site.
- **Migration plan (prepared, NOT run — for approval).** See §5.

## 5. Migration plan for approval (QA only — do not run without sign-off)

1. **Backup first.** `drush --uri=https://ppu-qa.ddev.site sql:dump` to a
   timestamped file outside the repo; label it `pre-24b-round2`.
2. **Confirm the update is pending.** `drush updatedb:status` on QA should list
   `update_10047` as pending and no others. Round 3 introduced **no** new update
   hook, so the pending list must still be exactly `update_10047`; re-verify the
   complete `updatedb:status` output immediately before running — from the live
   command, not from this document.
3. **Inspect `update_10047`'s final contents** in `custom_webform_handlers.install`
   before running (done — §4). It adds the eight nullable E08/E09 columns to
   `checkout_attempt` and nothing else, each only when absent. On QA at 10046 none
   of the eight exist yet, so all eight will be added in one hook.
4. **Apply QA only, single hook.** `drush --uri=https://ppu-qa.ddev.site updatedb`
   scoped to the pending hook. Never a blanket `updatedb` across unrelated hooks,
   never a manual `drush ... entity:updates` or hand-edited schema-version bump.
5. **Post-apply verification (PENDING items in §3c).** Re-run the value-object
   harness — now 55 checks across groups A–I, whose terminal **throws** on any
   failure so a non-green run exits the command nonzero (§9 corrects the earlier
   `exit()` description; a clean run falls through to command exit 0) — then a real
   authorized checkout on QA to exercise attempt insertion, guarded confirmation, and
   reservation persistence.
6. **Active `ppu` untouched.** No default-site write. Active migration is a
   separate, later, separately-approved step.

## 6. Round 3 — corrected over-claims and additional closures

Round 3 revalidated Round 2's source against live code (each item cites the file
and lines it was read from) and corrected three over-claims earlier drafts of
§§3–5 made. §§1–5 above now read in their corrected form; this section is the
changelog. Task 24B stays **PARTIAL**.

### 6a. Three over-claims corrected

1. **"Job identity is recomputed under the lock" was necessary but not, alone,
   sufficient.** Moving `hash_equals()` under the lock still compared two
   caller-supplied digests that nothing tied to the stored hold. Round 3 binds the
   under-lock comparison to persisted authority: the accepted binding is checked
   against the reservation's own `price_snapshot_id` before the digest comparison
   is trusted (`DispatchPlanner.php` L833–869), and the current digest is confirmed
   recomputed inside the locked callback, not prefetched (`DispatchPlanner.php`
   L871–891; `DispatchCheckoutManager.php` L396–423). The claim is now true *and*
   anchored to stored state.

2. **A `drush cr` is not a "real integrity signal".** §3a called the Round-2
   rebuild proof that no changed signature was incompatible. A rebuild resolves and
   compiles the service *container* only — it neither instantiates every service,
   exercises lazy/runtime paths, nor proves call-site signature compatibility (PHP
   resolves those dynamically). Necessary, not sufficient; and Round 3 could not
   re-run it (`ddev exec` BLOCKED, Docker CLI in WSL only). Reported as a blocker;
   no infrastructure restarted.

3. **The bare `snapshotId` never established accepted-job identity.** It is a
   truncated hash of six pricing *output* fields, so two materially different jobs
   that price identically share it. Identity is now established by the versioned
   composite `QuoteSnapshot::bindingIdentity()` — `BINDING_IDENTITY_PREFIX .
   snapshotId . ':' . jobFactsDigest`, ≤ the `price_snapshot_id`
   `varchar_ascii(128)` budget (`QuoteSnapshot.php` L541–546) — persisted at hold
   creation (`DispatchPlanner::createCheckoutHold`, L235–257) and compared at
   confirmation (`DispatchCheckoutManager`, L423). A legacy snapshot with no
   identity still yields the bare `snapshotId`, byte-identical to pre-24B rows, so
   the fold is append-only.

### 6b. Additional Round-3 closures (beyond the three corrections)

- **§2 — binding refusal is version-aware and fail-closed on missing data.** A
  current-format accepted binding (prefixed) refuses a NULL/empty/mismatched stored
  id; only a genuine legacy binding (no prefix) tolerates an absent stored id, and a
  stored legacy mismatch still refuses (`DispatchPlanner.php` L858–869). Missing
  data alone is not proof of legacy status.
- **§4 — the NULL-schema gate now covers every new-format field.** The raw route
  metrics and endpoint-pair fingerprint were added to `$hasNewFormatField`
  (`QuoteSnapshot.php` L361–378); presence is tested by `!== NULL`, not falsiness,
  so a zero-length route still counts. Malformed line-items JSON is refused
  explicitly (decode under `JSON_THROW_ON_ERROR`, non-array refused) while an absent
  blob stays a legitimate empty breakdown (`QuoteSnapshot.php` L425–437).
- **§5 — harness extended (extended, not automated into a suite).** Group **F**
  (binding stability + current-vs-legacy distinctness), **G** (each new-format field
  independently blocks a NULL-schema downgrade), **H** (`fromQuoteResult` /
  `fromApprovedReview` PUBLIC-factory construction + `fromStored` round-trip, empty
  and malformed breakdowns), **I** (the version-aware binding-refusal predicate,
  mirroring `DispatchPlanner.php` L858–869). Group H uses the public factories, not
  reflection, because reflection-only construction does not verify factory
  integration.

### 6c. Round-3 commands run (exit codes) and blockers

- `graphify update .` — **exit 0** (AST-only rebuild; YAML/Markdown remain outside
  AST coverage, so their edges are not asserted from the graph).
- Per-file `php -l` on the changed sources and the extended harness, via a
  throwaway read-only container (the `ddev/ddev-webserver` image run under WSL with
  `--entrypoint php -l`, repo mounted `:ro`) — **"No syntax errors detected"** on
  every file.
- **CORRECTED (see §9) — the exit-propagation claim here was wrong.** This bullet
  originally read that an out-of-band `php -r 'exit(1)'` → 1 and `php -r 'exit(0)'`
  → 0 proved the old harness terminal `exit($fail > 0 ? 1 : 0)` "yields a real
  nonzero command exit on failure." A bare `php -r` does **not** model the
  `drush php:script` runner. Measured under that runner (§9), **any** explicit
  `exit(N)` — including `exit(0)` — is caught as "terminated abnormally" and the
  command exits **1**; only fall-through returns 0. So the old terminal could not
  distinguish pass from fail: a clean 55/0 run and a failing run would *both* have
  exited 1. Round 3's Section-2 fix replaces that terminal — success now falls
  through (command exit 0) and a failure **throws** a `\RuntimeException` (a genuine
  nonzero command exit). Recorded as observed behavior of this installed invocation
  only, not a universal claim about every Drush version.
- `git diff --check -- modules/custom_webform_handlers` — clean.
- **BLOCKED at Round-3 time; RESOLVED — see §9.** The full Drupal-bootstrapped
  `drush php:script` harness run was blocked when this section was written because
  Windows `ddev exec` could not reach Docker. It has since run via the authorized
  native `docker.exe exec` path (never DDEV): §9 records 55 passed / 0 failed /
  command exit 0. No infrastructure was started, restarted, or recreated.

### 6d. Still PENDING — Task 24B remains PARTIAL

The 26 Round-3 harness checks (groups F–I) are lint-clean but **not executed** —
the bootstrapped run is blocked above, and the "29 passed" figure in §3b covers
only Round-2 groups A–E. **SUPERSEDED by §9:** groups F–I have since executed on
QA (full A–I = 55 passed, 0 failed, command exit 0). Everything in §3c stays PENDING: attempt insertion of the
new columns (needs `update_10047`, §5), guarded confirmation under the calendar
lock, and reservation/order persistence. **Task 24B is not complete while
persistence and guarded-confirmation acceptance are unverified. No migration was
run, and migration approval is not granted by this work.**

## 7. Session 2026-09-23 — QA recovery attempt: container STALE, harness NOT run

> **SUPERSEDED by §9 (same day, appended later).** The container has since synced
> to the working tree (host↔container harness SHA-256 parity confirmed) and the
> full A–I harness has run on QA. §7 is retained as the historical record of the
> earlier state; its "STALE" and "harness NOT run" findings no longer hold.

Observed results only, appended; §§1–6 above are unchanged historical evidence.

### 7a. Environment blocker — diagnosed, not worked around

- **Windows-side `ddev` cannot operate this project.** `rtk proxy ddev list`
  works (read-only). `rtk proxy ddev exec … drush --uri=https://ppu-qa.ddev.site
  status` **failed, exit 1**, aborting at mutagen: `docker CLI not found in PATH`.
  The `docker` client is absent from the Windows Git Bash PATH that `ddev` uses.
- **Side effect (flagged — a prohibited category triggered unintentionally):**
  that same `ddev exec` **recreated `ddev-ppu-web` and `ddev-ppu-db`** before
  it failed. No further Windows `ddev exec`/start was attempted.
- **Incident record — corrected per preflight §4 (container health ≠ data
  integrity):** the recreation of the web and database containers is retained as
  disclosed above. The earlier "no data loss" claim is **withdrawn as unproven.**
  What read-only evidence actually establishes (re-observed 2026-09-23, ~50 min
  after the recreate, via `docker.exe ps`/`volume ls`): the persistent volumes
  `ppu-mariadb` and `ppu_project_mutagen` are present; `ddev-ppu-db` reports
  **healthy**; `ddev-ppu-web` reports **unhealthy** (its mutagen sync never
  completed → stale code). What is **not** established: application-data
  integrity was **not** independently checked — no row counts, no schema or data
  probe were run, nor are they authorized here. No permanently-resolved root
  cause is claimed: the recreate-then-mutagen path has not yet succeeded against
  the current synchronized code.
- **WSL Ubuntu (already Running) is usable:** `docker` + `ddev` both work there;
  every check below used **read-only** `docker inspect` / `docker exec` into the
  existing containers — no start, restart, or recreate.

### 7b. Approot confirmed — but the served code is a mutagen volume

- `docker inspect ddev-ppu-web` label `com.ddev.approot = C:\Users\mo737\ppu`
  — the project's declared approot IS this working tree.
- Mounts: only `.ddev` is host-bind-mounted (→ `/mnt/ddev_config`, ro). `/var/www`
  is served from the Docker volume **`ppu_project_mutagen`** — the code the web
  container runs is the *last mutagen-synced snapshot*, not a live bind of the tree.

### 7c. The container copy is STALE — this blocks §3

Read-only sha256, host working tree vs `ddev-ppu-web:/var/www/html`:

| Artifact | Host | Container | Match |
| --- | --- | --- | --- |
| `src/**/*.php` aggregate (209 files each) | `2f55400…a81fab` | `907fff9…19bd74a` | **NO** |
| same, CR-stripped (rules out EOL) | `2f55400…` (LF) | `907fff9…` (LF) | **NO** |
| harness `round2-value-object-probes.php` | `e47bec1…79b0de` | `197649e…7db90f` | **NO** |

- Harness line count: host **564** vs container **334** — the container harness has
  **groups A–E only** (ends at Group E, then the echo header); **groups F, G, H, I —
  the entire Round-3 extension — are physically absent**, as is the Round-3
  exit-code-propagation note.
- `QuoteSnapshot.php`: both carry `const SCHEMA_VERSION = 3` (L99), yet the files
  differ in aggregate → the container's value objects are an older revision.
- Cause: the mutagen daemon is not running (Windows `ddev` cannot start it; the
  recreate left it unsynced), so the Round-3 working-tree edits never reached
  `ppu_project_mutagen`.

**Consequence:** running the harness inside `ddev-ppu-web` now would execute the
old 334-line **A–E** harness — not "the complete current harness including Round 3
groups F–I" that §3 requires — and would report on a *different copy* of the module.
Per the task's §1 stop-rule the harness, the QA `cr`, service-construction checks,
and any schema / `updatedb:status` read were **NOT executed** against the stale
container (`updatedb:status` would also enumerate the stale `.install`).

### 7d. Exact minimal action requiring authorization

To make `/var/www/html` == this working tree a **DDEV mutagen sync** must run —
reliably `ddev start` (reattaches mutagen and completes the initial sync; a narrow
`ddev mutagen sync ppu` needs the daemon already up) — **inside the already-running
WSL Ubuntu**, from the project approot. That is a "start DDEV services" action §1
prohibits without approval. Nothing in §§2–5 can be truthfully verified until the
container reflects the working tree.

### 7e. Sequence unblocked once the sync is authorized (unchanged)

1. §2 — QA identity: `drush --uri=https://ppu-qa.ddev.site status` (`db-name`/`site`/
   `uri`/`bootstrap`) vs the default site's `db-name` to prove DB isolation; confirm
   `development_fixed` (DDEV-forced) and the zero-cost budget (0 = no paid calls)
   still hold. Stop if either fails.
2. §3 — full harness A–I via `drush --uri=… php:script <harness>`; per-group + total
   + process exit code; a bounded, then removed, negative control proving a nonzero
   exit propagates through the wrapper.
3. §4 — QA `drush cr`; safe service-construction checks; `php -l` + `git diff
   --check`. A rebuild is not proof of runtime paths.
4. §5 — read QA schema version + full `updatedb:status`; present the QA-only
   `update_10047` migration scope, backup/recovery, and post-migration
   persistence/guarded-confirmation checks for **separate** approval. Ordinary
   `updatedb` runs *all* pending updates, not `update_10047` alone.

**Task 24B remains PARTIAL and is NOT complete.** No source/config changed, no
default-site write, no migration, and no commit were made by this recovery attempt.

---

## 8. Recovery preflight (2026-09-23) — environment RESOLVED, one bounded plan for approval

Read-only follow-up to §7. Nothing was started, restarted, recreated, reset, or
synced. Every command was `docker.exe … version/context/ps/volume ls`, file
reads, or `git status` — all read-only. §§1–7 stand; §7a's incident wording is
corrected above.

### 8a. §4 incident correction

Applied in §7a above (container health ≠ data integrity; "no data loss"
withdrawn; volumes present, db healthy, web unhealthy, app-data integrity
unchecked; no permanently-resolved root cause).

### 8b. §2 RESOLVED — one execution environment (repair, do NOT switch)

- **Registration owner = Windows DDEV.** `C:\Users\mo737\.ddev\project_list.yaml`
  lists `ppu` (approot `C:\Users\mo737\ppu`) + `ppu-courier` only. The WSL DDEV
  project list is **empty** — WSL DDEV owns no project. Both binaries are v1.25.3.
- **Daemon = Docker Desktop (WSL2 backend), UP now.** `docker version` →
  Client/Server 29.8.0, ServerOS linux, active context `desktop-linux`.
- **Windows `docker.exe` IS installed but off the process PATH** — the §2
  "installed but missing from PATH" case, proven:
  - Real binary: `C:\Users\mo737\AppData\Local\Programs\DockerDesktop\resources\bin\docker.exe`
    (per-user install; there is no `C:\Program Files\Docker`).
  - Git Bash PATH's only Docker entry is `/c/Program Files/Docker/Docker/resources/bin`,
    **which does not exist**; the Windows system PATH has **no** Docker entry.
    So DDEV's mutagen step (shells `docker` by bare name) fails "docker CLI not
    found in PATH" — exactly the §7a symptom. That binary reaches the daemon
    (verified read-only), so the fix is a PATH fix, not an install.
- **Repair, not switch (§2 preference):** run the existing Windows DDEV with the
  real Docker bin prepended **for one command only** — transient, no persistent
  PATH edit, no context change, no second registration:
  `PATH="/c/Users/mo737/AppData/Local/Programs/DockerDesktop/resources/bin:$PATH" ddev start`
- **Why not WSL DDEV:** invoking `ddev` from WSL against `/mnt/c/Users/mo737/ppu`
  would create a **second** registration and re-derive mutagen ownership — the
  outcome §2 forbids. Rejected.
- **No start-time hooks.** Neither `.ddev/config.yaml` nor the selenium config
  nor `~/.ddev/global_config.yaml` defines a `hooks:` block, so `ddev start` runs
  **no** drush/updatedb/import/provider/default-site command. It (re)starts the
  service containers (web, db, redis, osrm, nominatim, selenium);
  `default_container_timeout: 240`.

### 8c. §3 RESOLVED — the sync is NOT QA-only; new code hard-requires update_10047

- `ppu-qa` is a **multisite of project `ppu`, not a separate DDEV project**
  (`additional_hostnames: ["ppu-qa"]`, same `/var/www/html`; `--uri` selects only
  the DB). Mutagen syncs the **whole approot**, deploying the working tree to the
  code **both** sites run. There is no file-level QA isolation in a shared-codebase
  multisite.
- The Round-3 `CheckoutAttemptStore` (host copy) references all eight
  `update_10047` columns **unconditionally**: `const FIELDS` lists them; `open()`
  INSERTs them; `loadByOrder`/`openForSubmission`/`loadByKey` `SELECT … self::FIELDS`.
  No `fieldExists` guard, no try/catch, no schema gate. `hydrate()`'s `isset()`
  tolerates a NULL value, **not** a missing column.
- Both sites are at schema **10046** (update_10047 unapplied). Against a 10046
  table those SELECT/INSERTs throw a fatal `Column not found`
  (`DatabaseExceptionWrapper` — not the caught integrity exception). The **stale**
  container runs the OLD 10046-compatible store, so default checkout works *today*
  **because** the container is stale.
- **Therefore** the sync replaces the default (customer, never-written) site's
  checkout code with a version its schema cannot serve. Default checkout becomes
  **latently broken** on the first ordinary request; repair needs `update_10047`
  on the **default** DB — **not authorized** (writing the active site is forbidden).
- **What the sync does NOT break:** the authorized **value-object harness**
  (QuoteSnapshot/AcceptedJob, groups A–I) exercises value objects only — it never
  touches `CheckoutAttemptStore` or the DB — so it runs correctly post-sync
  **without** update_10047. The schema hazard bites only real checkout requests.
- **Source-only compatibility change (Condition 1) — three explicit schema states,
  refuse before any partial write.** "Don't send the default site checkout traffic" is
  not a safeguard the code enforces, so centralize one in `CheckoutAttemptStore` that
  resolves the table to exactly one of three states and never guesses from a single
  column:
  1. **Schema-capability probe (all eight, memoized per connection + request):**
     `snapshotSchemaState()` counts, via
     `$this->database->schema()->fieldExists(self::TABLE, $c)`, how many of the **eight**
     `update_10047` columns exist (`quote_route_meters`, `quote_route_seconds`,
     `quote_line_items`, `quote_pair_fingerprint`, `quote_payload_schema_version`,
     `quote_payload_digest`, `quote_job_facts_digest`, `quote_job_facts`): **0 → ABSENT,
     8 → COMPLETE, 1–7 → PARTIAL.** A single-column probe cannot establish readiness after
     an interrupted migration, so all eight are checked. The verdict is memoized in a
     **private instance property** (scoped to the injected `Connection` and this request)
     — never a `static` or a shared Drupal cache bin, so one site's verdict cannot leak to
     the other. A missing table or any schema-inspection error is **fail-closed (NOT read
     as ABSENT/legacy)**.
  2. **`open()` (writes a new current-format attempt):** COMPLETE → INSERT the full field
     set unchanged (existing strict path). ABSENT → log and `return NULL` before the
     INSERT. PARTIAL → log a controlled **error** diagnostic and `return NULL` (fail
     closed). Every snapshot this module mints today is current-format
     (`fromQuoteResult()`/`fromApprovedReview()` always stamp `SCHEMA_VERSION=3`, a digest,
     and an accepted-job identity), so it cannot be persisted faithfully without the eight
     columns. `NULL` is already `open()`'s documented "refused checkout" signal (its own
     docblock), so `DispatchCheckoutManager::recordAttempt()` needs no change — no partial
     write, no silent legacy downgrade, snapshot integrity never bypassed.
  3. **Readers (`loadByOrder`/`openForSubmission`/`loadByKey`):** COMPLETE → select the
     full `FIELDS` (existing strict `hydrate()`/`fromStored()` validation). ABSENT → select
     the **legacy field set** (`FIELDS` minus the eight); `hydrate()` is **unchanged** — its
     `isset($row->quote_*)` already yields NULL for an absent column, so
     `QuoteSnapshot::fromStored()` reads the row through its versionless → `buildLegacy()`
     branch. That is **not** a downgrade: on an ABSENT table the sealing columns do not
     exist, so any row physically present **is** legacy, and the `open()` guard guarantees
     no current-format row is ever written there. PARTIAL → **fail closed with a controlled
     diagnostic**; do **not** omit the surviving integrity columns and hydrate the row as
     legacy. Existing supported (legacy) rows and unaffected workflows keep working.
- **Workflow effect of the refusal (Condition 2 — established by reading the caller
  sequence, not assumed).** `open()`'s only caller is
  `DispatchCheckoutManager::recordAttempt()`, whose only caller is `start()`. By the time
  `open()` runs, `start()` has already made two writes: the Hold
  (`planner->createCheckoutHold()`, `DispatchCheckoutManager.php:131`) and the Commerce
  draft order (`orderFactory->create()`, `:139`). **No payment, authorization, or capture
  occurs on this path** — those live in `confirmPaid()`/`confirmHeld()`, a separate later
  in-session request. `recordAttempt()`'s existing `$attempt === NULL` branch
  (`:306`–`:315`) is the safe failure/cleanup contract: `discard($order)`,
  `releaseHold($hold)`, log, return `refused(InvalidRequest)`. A fail-closed NULL rides
  that exact contract, so the Hold and order made moments earlier are both undone — no
  orphan payable order, no stranded capacity. The accurate claim is therefore **not**
  "nothing is written," but "the two prior writes are reversed by the store's existing
  NULL-handling, and the patch introduces no new partial-write path"; its only residual is
  the pre-existing one shared by every NULL return (a failure inside
  `discard()`/`releaseHold()` itself).
- **This patch prevents missing-column *failures*; it does NOT restore new checkout
  *availability*.** With the columns ABSENT, every new snapshot-dependent checkout is
  refused on **both** sites (the default fails closed instead of fatally). Real checkout
  returns only after the separately authorized `update_10047` migration — the owner must
  approve that **temporary unavailability on both sites** as part of approving the sync.

  This change is **source-only** and ships on the **same sync** (it is in the synced
  tree), so it needs no separate deploy; follow it with `drush cr` on **QA only**
  (never the default site). It does **not** migrate either DB.
- **Alternative requiring authorization (NOT permitted now):** apply `update_10047`
  to each site's DB (default `db` + QA) to add the eight nullable columns; the store
  then runs at full capability with no guard. That is a **database migration** —
  outside Condition 1 ("Do not migrate either database") and forbidden on the active
  default site. The compatibility change above is what makes the sync safe **without**
  it.

### 8d. §1 backup — host tree AND both databases (destination OUTSIDE the sync scope)

Mutagen ignores `/.git`, `/.tarballs`, `/.ddev/db_snapshots`, `/.ddev/.importdb*`,
`/sites/default/files`, so the backup goes **outside the approot entirely**, e.g.
`C:\Users\mo737\ppu-backups\24b-preflight-2026-09-23\` — a destination **outside the
authorized workspace**, so it must be approved explicitly.

*Host working tree (protects Round-3 source):*
- Full module copy `modules/custom_webform_handlers/` (holds the entire uncommitted
  Round-3 delta — `git status` shows all changed/untracked paths are in-module).
- History: `git bundle create <dest>/ppu-all.bundle --all`; record `HEAD=b49c259e`,
  branch `codex-experiments`.
- Tree-wide net: `git diff HEAD > <dest>/tracked.patch` + archive of
  `git ls-files --others --exclude-standard`.

*Databases (Condition 2 — the host-tree backup does NOT cover these):* read-only
`mysqldump` of **both** schemas — default `db` and the QA DB — run directly against
the already-healthy `ddev-ppu-db` container so it needs no `ddev start`:
`docker.exe exec ddev-ppu-db mysqldump …` (credentials read from the container's own
env, never echoed or committed). This is the **only** backup that makes the pre-sync
DB state recoverable; the module-dir copy does not touch it.

*Container-side synchronized files (Condition 3 — the host tree backup does NOT cover
these):* before any sync, capture the container's current `/var/www/html` so both sides
are preserved, not just the host. Because reconciliation could overwrite a container-only
edit or pull a container-only new file onto the host, take a read-only archive of the
container tree (`docker.exe exec ddev-ppu-web tar`/`cp` into the backup dir) **or**, at
minimum, a complete archive of the files that differ from or are unique to the host
working tree **plus a manifest** listing them. "STOP on conflict" is **not** a substitute
for preserving both sides beforehand.

*Container-only excluded content (Condition 2):* `/sites/default/files` is
**sync-excluded**, so it lives only in the `ppu_project_mutagen` volume and is
**invisible to the host tree backup**. It survives `ddev start` and container recreate
(volume-backed) and is at risk only from a volume **reset/delete**, which is **not
proposed**.

*Integrity record (Condition 3):* record each artifact's **size and checksum** and that
the backup completed; keep the destination outside the sync scope with **restricted
access**. Do **not** claim restore verification unless a restore is actually performed.
Do **not** archive the MariaDB **data directory** as a substitute for the logical
`mysqldump`. Do **not** reset or delete any volume.

*Volumes:* `ppu-mariadb` (DB) and `ppu_project_mutagen` (web tree + excluded files)
both **persist** across `ddev start` and container recreate; neither is deleted or
reset by the proposed action.

### 8e. §5 — ONE bounded recovery plan (approval required; nothing below executed)

- **Environment / path:** Windows DDEV (registration owner), approot
  `C:\Users\mo737\ppu`, Docker Desktop daemon (already up).
- **Sufficiency:** a sync alone is not separately runnable while mutagen's daemon
  is down; the minimal reliable action is `ddev start` (reattaches mutagen and
  completes the initial host→volume sync). **No volume deletion** —
  `ddev mutagen reset` is NOT proposed (§1 forbids deleting `ppu_project_mutagen`).
- **Exact commands, in order:**
  1. Backup per §8d (host-side copy only; no container/daemon interaction).
  2. Read-only precheck: `PATH="…/DockerDesktop/resources/bin:$PATH" docker.exe info`
     (already passing).
  3. `PATH="/c/Users/mo737/AppData/Local/Programs/DockerDesktop/resources/bin:$PATH" ddev start`
     from `C:\Users\mo737\ppu`.
- **Expected side effects:** mutagen re-attaches and reconciles host ↔
  `ppu_project_mutagen`, bringing `/var/www/html` to the working tree (Round-3 F–I,
  plus the §8c compatibility change, present); web goes healthy; the six service
  containers are (re)started. **No** drush/updatedb/import/provider call (no start
  hooks). **No** default-site DB write. **No** paid API (routing is the internal
  `ppu-osrm` container).
- **Conflict handling / recovery (Condition 2 — "host wins" is NOT asserted as
  established):** `.ddev/mutagen/mutagen.yml` sets `mode: two-way-resolved`, which
  makes the **alpha** endpoint win **content conflicts**; DDEV assigns alpha=host by
  convention, but this is **not** verified live because the mutagen daemon is down
  (`ddev mutagen status` cannot run pre-start). Even if alpha=host holds, two-way-
  resolved only arbitrates files present on **both** sides: a deliberate container-only
  edit to a shared file would be overwritten from the host, and a container-only
  **new** file could propagate onto the host. So the safety comes from the §8d backups,
  not from a conflict rule — and on **any** conflict, unexpected deletion, or
  non-convergence, **STOP and report**; do not force, do not `mutagen reset`, restore
  the module dir / a DB from §8d if the host tree or a database is ever altered.
- **Shared-site/schema risk (from §8c):** the sync makes default checkout code
  require the update_10047 columns its DB lacks. Approval of the sync is NOT
  approval to migrate; the default site must not transact until separately
  migrated. Only the DB-free value-object harness runs in QA verification.

### 8f. Post-approval acceptance (unchanged; read-only + QA-only)

Per-relative-path content parity (src, `.install`, YAML, harness) host vs
container; confirm Round-3 groups F–I physically present; `drush
--uri=https://ppu-qa.ddev.site status` (QA DB-name ≠ default; bootstrap ok),
`development_fixed` guard, zero-cost budget; run the complete A–I harness with a
bounded, then-removed negative control proving nonzero exit; report exit codes.
**No** update_10047, fixtures, payments, paid APIs, commits, destructive cleanup,
or default-site writes.

### 8g. Single approval request (A–E, per the review)

**A. Bounded source compatibility patch (§8c).** In `CheckoutAttemptStore`, a centralized
`snapshotSchemaState()` checks **all eight** `update_10047` columns and returns
**ABSENT / COMPLETE / PARTIAL** (memoized in a private instance property, per connection
+ request; missing-table or inspection error = fail-closed, not legacy). `open()`:
COMPLETE inserts unchanged; ABSENT and PARTIAL log and `return NULL` (its existing
"refused checkout" signal) before any INSERT. Readers: COMPLETE select the full `FIELDS`;
ABSENT select the legacy field set with `hydrate()` unchanged (existing rows read as
truthful legacy); PARTIAL fail closed with a diagnostic — never drop surviving integrity
columns to read as legacy. No DB migration; ships on the same sync; `drush cr` on **QA
only** afterwards.

**B. Backups to the external destination**
(`C:\Users\mo737\ppu-backups\24b-preflight-2026-09-23\` — outside the sync scope and the
authorized workspace, so approve explicitly; restricted access): (i) host tracked +
untracked work — module dir copy, `git bundle --all`, `git diff HEAD` patch, untracked
archive; (ii) container-side synchronized files — read-only archive of the container
tree, or an archive of files unique to / differing from the host **plus a manifest**;
(iii) both databases — consistent read-only `mysqldump` of default `db` and QA via
`docker.exe exec ddev-ppu-db` (credentials never echoed). Record sizes + checksums and
completion; **no** restore-verification claim unless performed; **no** DB data-directory
archiving; **no** volume reset. `sites/default/files` and both volumes persist.

**C. Process-local PATH correction + exact DDEV action + affected services.** One
command, from `C:\Users\mo737\ppu`:
`PATH="/c/Users/mo737/AppData/Local/Programs/DockerDesktop/resources/bin:$PATH" ddev start`
— transient per-command PATH only (no persistent PATH edit, no context change, no WSL
switch, no second registration, no `mutagen reset`). It reattaches mutagen, reconciles
host → `ppu_project_mutagen`, and restarts containers **web, db, redis, osrm, nominatim,
selenium**, deploying the shared tree to **both** the default (customer) site and QA.

**D. Accepted temporary limitation (both sites).** With `update_10047` unapplied, new
snapshot-dependent checkout is **unavailable — refused, fail-closed — on both the
default site and QA** until a **separately approved** migration. The patch prevents the
missing-column crash, not the unavailability; approving the sync means accepting this
limitation on both sites. No default-site DB write; no start hooks fire.

**E. Post-sync file-parity checks + QA-only verification.** Per-relative-path parity
(host vs container) confirming Round-3 groups F–I and the §8c patch are physically
present; then the previously specified QA-only acceptance (§8f) —
`drush --uri=https://ppu-qa.ddev.site status`, `development_fixed` guard, zero-cost
budget, the DB-free value-object harness A–I with a bounded then-removed negative
control — reporting exit codes. On any sync conflict, unexpected deletion, or
non-convergence: **STOP and report**. **No** update_10047, fixtures, payments, paid
APIs, commits, destructive cleanup, or default-site writes.

**This review is not approval.** I will not make source edits, write external backups,
synchronize, or start services — nor apply `update_10047`, touch the default site, or
begin QA verification — until you explicitly confirm. Migration, default-site writes,
paid API calls, real payments, destructive cleanup, and commits remain unauthorized.
Task 24B remains **PARTIAL**.

---

## 9. 2026-09-23 (appended last) — corrected execution record: exit contract fixed, full A–I harness green on QA

**This section is authoritative and supersedes** the "29 passed" figure (§3b), the
exit-propagation note and "BLOCKED" harness line (§6c), the "not executed" F–I
status (§6d), the "container STALE / harness NOT run" finding (§7, §7c), and the
"sync pending" premise of §8. It records only what was actually executed. **No
browser acceptance and no database-integrity claim is made here.**

### 9a. What changed in the harness (Section-2 exit-contract fix)

The harness terminal `exit($fail > 0 ? 1 : 0)` was replaced. A clean run now **falls
through** (no explicit `exit`), so the runner's command exit is 0; a failed
`$check()` sets `$fail > 0` and **throws** `\RuntimeException`, so the runner emits a
genuine nonzero command exit; an unexpected exception is likewise uncaught. All 55
checks (groups A–I) and their expectations are preserved — none removed, suppressed,
or converted to success. A terminal comment records this as **observed behavior of
this installed invocation only**, not a universal Drush claim.

**Why the old contract was unsound (correcting §6c):** under `drush php:script`, any
explicit `exit(N)` — including `exit(0)` — is reported as "Drush command terminated
abnormally" and the command exits **1**; only implicit fall-through returns 0; an
uncaught exception returns a genuine nonzero. The earlier out-of-band
`php -r 'exit(0)' → 0` test did **not** model this runner. The old terminal therefore
exited 1 on a *clean* 55/0 run — indistinguishable from a failing run — which is
exactly why the executable gate was not complete.

### 9b. Execution environment (re-confirmed via native docker.exe; never DDEV)

All commands ran through `docker exec ddev-ppu-web` using the per-user `docker.exe`;
no `ddev` command was invoked (a `ddev exec` on this not-fully-started project would
recreate web+db).

- Container `ddev-ppu-web`; Drupal **10.6.16**; `drush --uri=https://ppu-qa.ddev.site`
  selects QA — **DB `qa`**, site path `sites/qa`; **bootstrap Successful**.
- `IS_DDEV_PROJECT=true` → `RoutingModeResolver::effectiveMode()` returns
  `development_fixed` unconditionally; the zero-cost `FixedDevelopmentRouteProvider`
  is active. The harness itself makes **no** route/HTTP/courier call (pure value
  objects), so **no paid API is reachable** during the run.
- **Host ↔ container harness parity:** SHA-256
  `370a2beba36f2e5fcc1030db6a44ba0f3dd814ec6bf4833a09f178ca643ee55f` on **both** sides
  — the container is no longer STALE (§7c withdrawn). The synced tree carries the
  578-line Round-3 harness (was 564 at §7c; the added lines are the throw terminal and
  the observed-runner note).
- **Observed, not altered:** `ddev-ppu-web` reports healthcheck **unhealthy** while
  `ddev-ppu-db` reports **healthy**; the CLI bootstrap is nonetheless fine (see 9d
  distinction 2). The **ppu-courier** project (`ddev-ppu-courier-web/-db/-selenium-chrome`)
  was observed **UP**, not paused; I did not start it and did not alter it, and the
  harness has no courier interaction. `git diff --check` on tracked files is clean
  (both task files are untracked).

### 9c. Results (outer command exit captured directly, not masked)

Exit status was read as `ec=$?` immediately after each `drush … php:script …` into a
captured variable; the captured text was filtered only for display, so no pipe or
later successful command masked the code.

- **A. `php -l`** on the harness → `No syntax errors detected`.
- **B. Negative control** — a `/tmp` copy of the harness with **one existing
  assertion flipped** (I1: `$bindingRefuses($currentBinding, $currentBinding) === FALSE`
  → `=== TRUE`; replace count asserted = 1), run through the **same runner and
  wrapper**:
  - `[FAIL] I1 :: current-format accepted binding matching the stored binding PROCEEDS`
  - `RESULT : 54 passed, 1 failed, 55 total.`
  - `\RuntimeException`: "1 of 55 value-object probes failed…"
  - **outer command exit = 1**; the control file was then removed (`CONTROL_REMOVED=YES`).
- **C. Normal harness** (unmodified, control removed):
  - `RESULT : 55 passed, 0 failed, 55 total.`
  - `STATUS : Task 24B remains PARTIAL.`
  - **outer command exit = 0**.

Required results met: negative control → failure detected, nonzero exit; normal
harness → 55/0, exit 0. No temporary control remains. A QA `drush cr` was **not**
required — a docs `php:script` probe defines no service, route, schema, or hook.

### 9d. The three distinctions this run keeps explicit

1. **Value-object verification vs database persistence.** VERIFIED: derivation,
   binding identity, fail-closed hydration, and the local refusal predicates, as pure
   value-object checks. NOT verified: any DB write — no checkout-attempt INSERT, no
   transaction, no lock, no reservation. That needs `update_10047` applied plus a
   separately authorized checkout.
2. **CLI bootstrap vs unhealthy web/php-fpm.** VERIFIED: the Drush CLI bootstrapped
   and autoloaded the module's classes. NOT asserted: the web/HTTP surface —
   `ddev-ppu-web` is healthcheck-**unhealthy**; nothing here is a browser or
   served-request result.
3. **Service construction vs actual guarded confirmation.** VERIFIED: the E/I
   predicates mirror the confirm-time comparisons (`hash_equals`, version-aware
   binding refusal) as local logic. NOT verified: the real guarded confirmation — no
   under-lock DB read of `price_snapshot_id` in `DatabaseReservationStore`, no
   `DispatchPlanner::revalidate()` against live data.

**No browser acceptance and no database integrity is claimed.**

### 9e. Migration gate — still closed (see §5 for the approval request)

`update_10047` is **unapplied on both sites**. Live on QA,
`drush --uri=https://ppu-qa.ddev.site updatedb:status` lists **exactly one** pending
hook — `custom_webform_handlers` **10047** — and no others (QA schema 10046). Do not
infer the active `ppu` state from QA; it is unverified per site. No migration,
fixture, checkout, or payment was run. **Task 24B remains PARTIAL.**

> **§9e is superseded for QA by §10 below** (migration executed 2026-09-23 under the
> owner's corrected QA-only authorization). §9e records the pre-migration state and is
> left intact as history. §9b's "ppu-courier … observed **UP**, not paused" is
> **corrected** in §10a: the courier *containers* were up, but its **mutagen sync
> session was `[Paused]`** — a distinction §9b collapsed.

## 10. QA-only migration execution — `update_10047` applied on QA (authorized 2026-09-23)

Scope executed exactly as authorized: the five-step corrected QA-only migration.
Native `docker.exe` into `ddev-ppu-web` only; **no `ddev` command**; QA DB `qa`
(`--uri=https://ppu-qa.ddev.site`) only; default `ppu` untouched; nothing
started/stopped/resumed; no container recreation or infrastructure repair; no
credentials printed. **This does not make the web surface healthy and does not
complete Task 24B.**

### 10a. Pre-flight (Step 1)

- `SELECT DATABASE()` under `--uri=…ppu-qa…` → **`qa`**; site path `sites/qa`.
- `IS_DDEV_PROJECT=true` → `RoutingModeResolver::effectiveMode()` = **`development_fixed`**
  (unchanged); the harness makes no route/HTTP/courier call — no paid API reachable.
- Host ↔ container harness parity SHA-256
  `370a2beba36f2e5fcc1030db6a44ba0f3dd814ec6bf4833a09f178ca643ee55f` (unchanged).
- **ppu-courier mutagen SESSION directly verified `[Paused]`** (Connected No/No) via
  `mutagen.exe sync list` with Windows-form `MUTAGEN_DATA_DIRECTORY`. Running courier
  containers do **not** imply a resumed sync session — this corrects §9b. Not
  paused/stopped/resumed by me. The `ppu` session remained Watching / Connected Yes.

### 10b. Backup (Step 2)

- Fresh, uniquely named archive on host:
  `C:\Users\mo737\ppu-backups\pre-24b-round2-qa-20260923T185636Z.sql.gz`.
- **Consistency method:** every QA base table is InnoDB → `mysqldump
  --single-transaction --quick`. Dump, gzip, and `gzip -t` exit codes were captured
  **independently** (never `mysqldump | gzip`, which hides a dump failure behind gzip).
- **Host-side verification:** size **3,493,054 bytes** (nonzero); sha256
  `201cdf2b2ae39eca199f6abcd34b1a075be26d6d80c64b3401c404b45583f9e2`; `gzip -t` exit 0.
- **Landed by streaming** `docker exec … cat /tmp/<file>.gz > /c/Users/mo737/ppu-backups/<file>.gz`.
  A prior `docker cp` to a Windows-backslash destination under `MSYS_NO_PATHCONV=1`
  reported `CP_EC=0` yet the file never appeared; streaming to the same POSIX `/c/...`
  path the verify step reads guarantees the bytes checked are the bytes written.
- Prior backups preserved (`24b-preflight-2026-09-23/` still present). Container `/tmp`
  plaintext dump + `.gz` removed after the verified copy. No credentials printed.

### 10c. Immediately-before-migration gate (Step 3)

- `updatedb:status` (human **and** `--format=json`): the **complete** pending list is
  **exactly one** entry — `custom_webform_handlers_update_10047` (`hook_update_n`) — no
  other module, no other update, no requirements error.
- Installed schema (`key_value` / `system.schema` / `custom_webform_handlers`) =
  **`i:10046;`** before.
- `--entity-updates=0` **not** used.

### 10d. Migration (Step 4)

- `drush --uri=https://ppu-qa.ddev.site updatedb -y` via native `docker.exe`.
- Only `custom_webform_handlers_update_10047` ran; notice: *"Added the complete priced
  job to Checkout Attempts: quote_route_meters, quote_route_seconds, quote_line_items,
  quote_pair_fingerprint, quote_payload_schema_version, quote_payload_digest,
  quote_job_facts_digest, quote_job_facts."* → `[success] Finished performing updates.`
- **Outer exit `UPDB_EC = 0`** — genuine; `updatedb` is not subject to the `php:script`
  explicit-`exit()` swallow that only affects `php:script` runs.

### 10e. Post-migration verification (Step 5) — all checks pass

- Installed schema now **`i:10047;`**.
- **Table name is `custom_webform_handlers_checkout_attempt`, not bare
  `checkout_attempt`.** The first column check queried the bare name and returned
  **empty** — a *false negative*. A positive control exposed it: `SELECT DATABASE()`
  = `qa`; `information_schema.TABLES LIKE '%checkout_attempt%'` → the real prefixed
  name; bare-name column count = 0. (The identical §3d "before" empty result was the
  same false negative, not proof of absence — a negative needs a positive control.)
- Re-run on the real table, **all eight columns present (8/8)** with definitions
  matching the `update_10047` spec — every column **nullable** (`IS_NULLABLE=YES`):

  | column | expected (install hook) | observed `COLUMN_TYPE` / charset |
  | --- | --- | --- |
  | quote_route_meters | int unsigned, nullable | `int(10) unsigned` / — |
  | quote_route_seconds | int unsigned, nullable | `int(10) unsigned` / — |
  | quote_payload_schema_version | int unsigned, nullable | `int(10) unsigned` / — |
  | quote_pair_fingerprint | varchar_ascii(64), nullable | `varchar(64)` / `ascii` |
  | quote_payload_digest | varchar_ascii(64), nullable | `varchar(64)` / `ascii` |
  | quote_job_facts_digest | varchar_ascii(64), nullable | `varchar(64)` / `ascii` |
  | quote_line_items | text(medium), nullable | `mediumtext` / `utf8mb4` |
  | quote_job_facts | text(medium), nullable | `mediumtext` / `utf8mb4` |

- `updatedb:status` after → `[success] No database updates required.` (nothing pending).
- QA `drush cr` → `[success] Cache rebuild complete.`
- **55-check value-object harness — re-run post-migration 2026-09-23, judged on
  BOTH its `RESULT:` line and its outer exit code:** **`RESULT : 55 passed, 0 failed,
  55 total.`** AND outer **`HARNESS_EC = 0`**. Both are required now; the RESULT line
  alone is no longer treated as sufficient.
  - This harness carries **no explicit `exit()`** (its own source retracts an earlier
    note that claimed otherwise, `round2-value-object-probes.php:533-556`): it signals
    success by *falling off the end*, which lets the `php:script` runner complete and
    exit **0**, and signals failure by *throwing* an uncaught `\RuntimeException` when
    `$fail > 0` (`:572-577`), which reaches the runner's top-level handler and yields a
    genuine **nonzero** exit. Any unexpected exception inside a check is deliberately
    uncaught and also surfaces as nonzero. So for THIS harness `HARNESS_EC = 0` is
    authoritative — it proves all 55 checks ran to completion with none failing and
    nothing thrown mid-run, which the printed RESULT line cannot prove on its own.
    This is consistent with, not contradicted by, the general finding that
    `php:script` normalizes an *explicit* `exit(N)` to 1: the harness was written to
    avoid `exit()` for exactly that reason.
  - Host↔container harness parity re-confirmed at run time: SHA-256
    `370a2beba36f2e5fcc1030db6a44ba0f3dd814ec6bf4833a09f178ca643ee55f` on both sides
    (Mutagen syncs the one file; there is no QA-only copy to drift).
  - The harness bootstraps Drupal for autoload only and makes **no DB write**, so a
    green run is necessary, not sufficient; it prints `STATUS : Task 24B remains
    PARTIAL.` itself.

### 10f. Scope kept, and what is still not done

- **QA only.** The default `ppu` site was **not** migrated or written; it remains at
  schema **10046** while Mutagen syncs the same Round-3 code onto it. This is **not**
  an "unrepaired fatal mismatch." The Round-3 store was built to handle a
  schema-behind table by **controlled refusal**, and that is the path `ppu` relies on
  until it is migrated under its own separate authorization:
  - `CheckoutAttemptStore::snapshotSchemaState()` → `determineSnapshotSchemaState()`
    counts the eight `SNAPSHOT_COLUMNS` via `schema()->fieldExists()`: zero present
    (ppu's case at 10046) → **ABSENT**; some present → **PARTIAL**; the table missing
    or uninspectable → **UNKNOWN** (`CheckoutAttemptStore.php:157-181`).
  - `open()` returns **NULL and logs an error** unless the state is COMPLETE, so a new
    attempt against an ABSENT/PARTIAL/UNKNOWN table is **refused before the insert**
    rather than run into a missing-column exception — its own docblock: *"This averts
    a missing-column crash; it does not restore checkout availability, which only
    applying the migration can."* (`CheckoutAttemptStore.php:215-232`).
  - Readers fail safe by the same probe: `readableFields()` reads the legacy set
    (FIELDS minus the eight) when ABSENT — and `hydrate()` resolves the eight snapshot
    fields to NULL, so existing rows read as legacy figures — and reads **nothing**
    when PARTIAL or UNKNOWN (`CheckoutAttemptStore.php:193-199`).
  - **Honest boundary:** this is read from the Round-3 source, not exercised on `ppu`.
    No default-site probe, read, or write was performed this turn. The supported claim
    is "the store is designed to refuse cleanly on a 10046 table," verified by reading
    the path — not "ppu's live behaviour was observed." Migrating `ppu` remains a
    separate, out-of-scope action.
- No `ddev` command, no container recreation, no infra repair; ppu-courier left
  `[Paused]`; nothing started/stopped/resumed; no manual schema-version edit; no
  auto-restore or DDL retry was needed (no step failed).
- The web/HTTP surface remains healthcheck-**unhealthy**; nothing here asserts it healthy.
- **Still PENDING a separate authorization:** DB-backed attempt insertion, guarded
  confirmation under the calendar lock, and reservation/order persistence. The eight
  columns now *exist* and the value-object logic is green, but no checkout or
  confirmation has written or read them end-to-end.
- **Task 24B remains PARTIAL — not complete.**

## 11. Commerce snapshot integration preflight (2026-09-25) — READ-ONLY

Scope: source + approved read-only container-Drush metadata only. No refresh,
recalculation, entity save, checkout transition or payment operation was invoked
on any existing order. DBHub MCP stays suspended (container-Drush metadata
exception retained). Zero-cost guards and dirty files preserved. No source/config/
schema change, fixture, restart or commit. Evidence discipline: graphify is
AST-only and refreshed 2026-09-25 (YAML/routing/docs outside coverage); GitNexus is
stale on the uncommitted edits (indexed @ `b49c259e`), so every line below is from a
**direct read of LIVE `modules/custom_webform_handlers/src/…` (uncommitted; verify
before citing)** — never a `task-24a-recovery/**` audit copy. This section states
**confirmed gaps, affected files, proposed next scope, and blockers**; it proves no
runtime behaviour.

### 11a. Step 1 — the accepted-snapshot → display chain, three paths

- **PUBLIC** and **STAFF-PRICED review** converge at
  `DispatchCheckoutStarter::open()` → `DispatchCheckoutManager::start()`
  (`DispatchCheckoutManager.php:100`): `workflowGate->allows` (`:106`) → `slotFor`
  (`:113`) → `requestFactory->fromSubmission` (`:118`) → `existingAttempt` dedup
  (`:126`) → `planner->createCheckoutHold` (`:131`) → `orderFactory->create` (`:139`)
  → `recordAttempt` (`:145`). The accepted amount is set once: PUBLIC via
  `QuoteSnapshot::fromQuoteResult`, STAFF via `QuoteSnapshot::fromApprovedReview`
  (review's `quoteAmountCents`).
- **Commerce order/item** — `DispatchOrderFactory::create()`
  (`DispatchOrderFactory.php:67`): one `commerce_order` type `dispatch` (`:46`), one
  `commerce_order_item` type `dispatch_service`, `quantity => 1`,
  `setUnitPrice(new Price($quote->amountDecimal(), $quote->currency), TRUE)` — the
  `TRUE` is Commerce's `overridden_unit_price` lock. Stamps `dispatch_submission_id`
  (`:105`); returns NULL on no store (`:74`) or storage fault (`:108`).
- **Payment/confirm** — `DispatchPaymentProcess` (payment pane, subclass of Commerce
  `PaymentProcess`): `parent::buildPaneForm` authorises inline then advances by
  throwing `NeedsRedirectException` (`:100`); `settleAuthorizedCapacity()` (`:142`)
  gates on `orderHasAuthorization()` (`:163`, reads a `commerce_payment` in state
  `authorization`); `confirmCapacity()` (`:185`) builds `PaidDispatchReference` from
  order id + `dispatch_submission_id` (`:187`) + now → `checkoutManager->confirmPaid`
  (`:199`). `confirmPaid()` (`DispatchCheckoutManager.php:160`) hydrates
  **`$accepted = $attempt->quote`** — the immutable stored snapshot (`fromStored`) —
  and calls `confirmHeld()` (`:195`).
- **Reservation write + link** — `confirmHeld()` (`:339`) →
  `planner->confirmCheckoutReservation($hold,$submission,$accepted->jobFactsDigest,
  $accepted->bindingIdentity())` (`:423`, job-facts + binding under the calendar lock)
  → on success `settlePaid()` (`:467`): capture (`:474`), `attempts->settle` (`:477`),
  `store->linkOrder(reservationId, orderId)` (`:503`), `recordReviewScheduled` (`:512`).
  The four `price_snapshot_*` columns are written verbatim into `dispatch_reservation`.
- **Display (admin)** — `DispatchPlannerAdminController::reservation()`
  (`:186`) → `DatabaseReservationStore::adminReservation()` (`:998`, `STATE_CONFIRMED`
  only, four price columns passed through NULL-preserving into
  `DispatchReservationDetail`, `:1057`) → `detailTable()` (`:1983`) price row (`:2017`)
  → `priceText()` (`:2137`): `number_format($cents/100, 2)` + currency + snapshot id,
  **verbatim, no recalculation**; NULL cents → `"No price was recorded"`.
- **Display (customer)** — `DispatchConfirmation` complete-step pane
  (`DispatchConfirmation.php:68`) shows **order number** (`:84`), **pickup line** =
  order-item title (`:97`/`pickupPromise :170`), **email** (`:106`) and a next-steps
  line — **no monetary figure at all**; `bookingIsConfirmed()` (`:135`) publishes only
  `attempt->state->hasReservation()`. The **only** money a customer sees is Commerce's
  own order-summary `total_price` = the overridden item price = the accepted amount.
- **STAFF MANUAL-REVIEW** (`DispatchManualReviewConfirmForm` → `confirmManualReview`
  → `confirmForStaff`) is **outside** the snapshot/order/payment chain: NULL price
  columns, no `commerce_order`, no payment; emits `DispatchNotification::confirmed`.

### 11b. Step 2 — where identity/quantity/price/currency/total could diverge, and the guard on each

| Axis | Divergence risk | Guard (source) |
| --- | --- | --- |
| Snapshot **identity** | wrong job / stale binding | binding `v3:{snapshotId}:{jobFactsDigest}` + job-facts digest re-checked **under lock** in `confirmCheckoutReservation` → SNAP/JOB refuse |
| **Quantity** | item qty ≠ 1 | built as `1`; `orderPayableMatches` requires `count($items)===1` (`:708`) and `Calculator::compare(qty,'1')===0` (`:716`) |
| **Unit price** | OrderRefresh requotes | `overridden_unit_price = TRUE`; `dispatch_service` has no purchasable entity → OrderRefresh does not requote (**assumption, see G2**) |
| **Adjustments** | tax/promo/fee added post-open | none written; any that move the total are caught by the payable-total guard (below) |
| **Currency** | currency mismatch | `Price::equals` compares number **and** currency |
| **Payable total** | processor/promotion drift | `orderPayableMatches` (`:684`): `getTotalPrice()->equals(new Price($accepted->amountDecimal(), $accepted->currency))` (`:728`), **pre-lock**; FALSE → releaseHold + settleUnpaid(Failed) + voidAuthorization → refuse InvalidRequest |
| **Display/refresh** | recompute from current tariffs | admin `priceText` verbatim; customer sees Commerce order total of the overridden price; replan/refresh in `DatabaseReservationStore` copies `price_snapshot_*` verbatim (≈`:696`) — **no display path recalculates from tariffs** |

### 11c. Step 3 — fail-closed on missing/corrupt current snapshot; legacy readable without fabricated price

Fail-closed (source-established):
- `CheckoutAttemptStore::snapshotSchemaState()` (`:157`) → COMPLETE only when all
  eight `SNAPSHOT_COLUMNS` exist; `open()` (`:215`) returns **NULL + logs** unless
  COMPLETE → a new attempt is refused before insert on an ABSENT/PARTIAL/UNKNOWN
  table (no missing-column crash, no fabricated row).
- `orderFactory->create` returns NULL on no store / storage fault → `start()` cannot
  proceed with a fabricated order.
- `orderPayableMatches` returns **FALSE** (the safe direction) on unreadable/missing
  order, NULL total, `count!=1`, `qty!=1`, or unequal number/currency → void + refuse.
- Binding gate refuses a **current-format** (`v3:`) accepted binding against a
  NULL/empty/mismatched stored id — "missing data is not proof of legacy status"
  (the empty-binding downgrade closed in EVIDENCE.md §10).

Legacy readable without fabrication:
- `adminReservation()` passes NULL price columns through unchanged; `priceText()`
  renders `"No price was recorded"` when cents is NULL — a stated absence, never a
  computed figure.
- `readableFields()`/`hydrate()` on an ABSENT table read the legacy field set and
  resolve the eight snapshot fields to NULL (`CheckoutAttemptStore.php:193`), so
  pre-24B rows read as legacy figures; `QuoteSnapshot::fromStored` accepts a bare
  (legacy) snapshot id at `LEGACY_SCHEMA_VERSION = 0`.

### 11d. Step 4 — entity-save / refresh / subscriber / ECA / notification / payment side-effect inventory

- **Entity saves.** `commerce_order` create+save with `customer_email_overridden` and
  `dispatch_submission_id` metadata (`DispatchOrderFactory::create`); `commerce_order_item`
  create+save with the overridden unit price; `orderFactory->cancel()` (`:165`) applies
  the Commerce `cancel` transition + save (refuses paid/foreign-type, frees no Slot);
  `discard()`/`discardItem()` (`:128`) delete a draft/unpaid order; `recordAttempt()`
  opens a `CheckoutAttempt` and, on open failure, **discards the order + releases the
  Hold** (no orphan payable order).
- **Refresh/recalculation.** OrderRefresh does **not** requote the `dispatch_service`
  item (overridden price, no purchasable entity) — the load-bearing assumption behind
  the whole price lock; **not runtime-verified here (G2)**. No adjustments are written.
- **Payment.** `captureAuthorization()` (`:606`) / `voidAuthorization()` (`:640`) /
  `loadAuthorizationPayment()` (`:741`) via the on-site gateway plugin; capture happens
  **after** the guard commits, so a confirmed Reservation is never released for a
  payment reason — failed capture → `ReconciliationRequired`, booking stands.
- **Subscriber / ECA.** `announceConfirmed()` (`:819`) → `events->emit(new
  DispatchReservationConfirmedEvent(...), EVENT_NAME)` via `DomainEventEmitter`,
  **post-commit** (route warming); the emitter drops events on rollback, so the ECA/
  subscriber reaction cannot fire for an unconfirmed booking.
- **Notification.** `enqueueConfirmation()` (`:783`) → `notifier->notify(
  DispatchNotification::confirmed(...))` on the public confirm path, and again on the
  staff manual-review path (`confirmManualReview` → `notifier->notify`); queue-backed,
  actorUid NULL, failures logged not thrown.
- **Review.** `recordReviewScheduled()` (`:545`) → `ReviewState::Scheduled`
  (staff-priced path only; idempotent; NULL review = silence).
- All post-commit effects (capture, linkOrder, enqueue, announce, review) are wrapped/
  swallowed so none can roll back a committed paid booking.

### 11e. Step 5 — proposed smallest slice + bounded QA round-trip probe (PROPOSAL; not run)

No source change is required for correctness by this preflight — the round-trip is
source-complete but **runtime-unproven** (G1). The smallest **verification** slice is a
QA-only, single-outer-transaction round-trip harness (`drush php:script`, same
discipline as EVIDENCE.md §7/§10.9), judged on its `RESULT:` line **and** `OUTER_EC`:

- Seed one accepted `QuoteSnapshot` (`fromQuoteResult`) via the dispatch profile
  factory, open a `CheckoutAttempt`, build the order/item through `DispatchOrderFactory`,
  then assert, inside ONE outer txn rolled back in `finally`:
  - **A1** order type `dispatch`, exactly one item, item type `dispatch_service`, qty 1.
  - **A2** item unit price `equals` accepted (amount + currency); overridden flag TRUE.
  - **A3** `order->getTotalPrice()->equals(accepted)` — exercises OrderRefresh
    non-requote at runtime (**closes G2**).
  - **A4** `orderPayableMatches(orderId, accepted) === TRUE` on the clean order.
  - **A5** with a synthetic adjustment / mutated total, `orderPayableMatches === FALSE`
    (**exercises G3** direction; assert refuse path voids + InvalidRequest).
  - **A6** after `confirmCheckoutReservation`, `adminReservation()` round-trips the four
    `price_snapshot_*` columns verbatim and `priceText` formatting equals accepted
    (`amount currency, snapshot id`).
  - **A7** NULL/legacy row → `priceText` = `"No price was recorded"` (no fabrication).
  - **A8** ABSENT/PARTIAL schema (or resolved-NULL snapshot) → `open()` refuses /
    `fromStored` legacy — no fabricated price.
- **Baseline / rollback / cleanup:** capture a per-identifier baseline (counts+hashes
  only) of `custom_webform_handlers_checkout_attempt`, `dispatch_reservation`,
  `commerce_order`, `commerce_order_item` over a synthetic id range (e.g. orders
  `899000401–410`) **before** writes; single outer-txn rollback; re-verify zero residue
  from a **separate fresh process** (CHECKER_RESULT CLEAN); remove all temp files
  host + container and verify absence — exactly as §10.9.
- **Capture leg:** with no gateway (G4/E02), assert the manager reaches
  `captureAuthorization` and handles no-payment → `ReconciliationRequired` (booking
  kept); a **true paid** capture/void round-trip is deferred to a test on-site gateway
  fixture under 24E authorization.

### 11f. Confirmed gaps

- **G1 — no end-to-end runtime round-trip proof.** The Commerce order/item build,
  OrderRefresh behaviour, `orderPayableMatches` against a real `getTotalPrice()`, and
  both display legs are source-established only; the §7 harness (91/0) exercised the
  planner/store/guard confirm axis, **not** the Commerce order or display legs.
- **G2 — OrderRefresh non-requote is an assumption**, not verified in this stack.
- **G3 — `orderPayableMatches` is config-coupled to a processor-free `dispatch` order
  type.** No Commerce adjustment is ever written and the guard compares the whole
  total, so **any** processor (tax/promo/fee) enabled on the `dispatch` order type
  makes `getTotalPrice() != accepted` → E08 refuses **every** checkout. Fail-safe
  (never overcharges) but a latent total-availability risk; keep the order type
  processor-free or fold approved tax into the accepted amount (spec §6 "once").
- **G4 — no payment gateway configured (E02).** 0 gateways / 1 default store (memory
  `task24a`); the authorize→capture→void leg cannot be exercised on the wire without a
  test gateway fixture (24E).
- **G5 — public checkout gate closed** (empty `workflowGate` allowlist): no public
  checkout opens today, so the probe must seed the attempt directly or use the
  staff-priced path.

Non-defects (recorded so they are not re-flagged): (i) two admin surfaces / two tables
— only `DispatchPlannerAdminController` over `dispatch_reservation` shows price;
`ReservationAdminController::detail()` over the capacity `reservation` table has no
price columns by design; (ii) the manual-review path carries NULL price columns / no
order / no payment; (iii) the customer confirmation pane shows no monetary figure —
the only customer-visible money is Commerce's own order total of the overridden price.
No display path recalculates from current tariffs.

### 11g. Exact affected files (LIVE, uncommitted)

- `src/Commerce/DispatchOrderFactory.php` — order/item build, overridden price, cancel/discard.
- `src/Commerce/DispatchCheckoutManager.php` — `orderPayableMatches` (G3), confirm/settle/capture/void.
- `src/Dispatch/DispatchPlanner.php` — `confirmCheckoutReservation` (identity/job-facts under lock).
- `src/Dispatch/Storage/DatabaseReservationStore.php` — `adminReservation` verbatim pass-through; replan copy.
- `src/Controller/DispatchPlannerAdminController.php` — `priceText` display leg.
- `src/Checkout/CheckoutAttemptStore.php` — schema gating / fail-closed (G1/G4 seam).
- `src/Plugin/Commerce/CheckoutPane/DispatchPaymentProcess.php`, `DispatchConfirmation.php` — wire entry + customer display.
- `src/Checkout/QuoteSnapshot.php` — `fromQuoteResult`/`fromApprovedReview`/`fromStored`, binding identity.
- Probe fixture: a new throwaway `sb-*.php` under container `/tmp` (removed both sides after) — **not** under `src/`.

### 11h. Proposed next execution scope, and blockers

- **Next scope (stop here until authorized):** run the single bounded QA round-trip
  probe A1–A8 under one rolled-back transaction, plus a decision on the test-gateway
  fixture for the paid capture leg. Nothing else advances; do **not** auto-advance to
  24C–24K.
- **Blockers:** **B1** — the probe adds a **new write surface** (`commerce_order` /
  `commerce_order_item`) beyond §7's planner/store/guard, so it needs explicit owner
  authorization for a bounded QA write-then-rollback. **B2** — a true paid capture
  needs a test on-site payment gateway fixture (E02/24E). **B3** — DBHub MCP suspended
  (container-Drush metadata only). **B4** — public checkout gate closed (seed directly
  or use staff-priced path). **B5** — web/HTTP surface remains healthcheck-unhealthy;
  a browser round-trip stays blocked and separate.

**Preflight verdict:** the accepted-snapshot → attempt → order/item → reservation →
display chain is **source-complete and internally fail-closed**, with the payable-total
guard as the cross-system backstop; it is **not runtime-proven** for the Commerce/display
legs (G1) and carries one latent config-coupling risk (G3) and one environment blocker
for paid capture (G4). **Task 24B remains PARTIAL. Preflight only — no implementation.**

## 12. Bounded QA Commerce snapshot round-trip — EXECUTED (2026-09-25, owner-authorized; single rolled-back transaction)

Preserves §8/§9/§10/§11. This records the **execution** of the §11e proposal under
explicit owner authorization for temporary synthetic draft orders/items, attempts and
reservation fixtures — save/reload and refresh of those fixtures only, inside explicitly
rolled-back transactions, no application-source change. It closes blocker **B1** for a
bounded QA write-then-rollback. Task 24B stays **PARTIAL**.

**Environment / discipline.** `ppu-qa.ddev.site`, DB `qa`, `custom_webform_handlers`
schema 10047 (8 snapshot columns). Ran via `docker exec ddev-ppu-web … drush
--uri=https://ppu-qa.ddev.site php:script` on the existing container (no `ddev`
start/restart/exec; DBHub MCP suspended). Each transfer was SHA-256-verified host↔container.
Judged by the printed `RESULT:` line (`php:script` swallows `exit()`), corroborated by
`OUTER_EC`. No source/config/schema change; no migration; no commit.

**Denial-by-construction (re-asserted in-probe before any write).** host ==
`ppu-qa.ddev.site`; `commerce_payment_gateway` count = 0 (HALT-if->0 gate passed);
`system.mail` = `php_mail`; the order was kept draft (no receipt mail); literal
`DistanceResult::restored()` fixtures (no geocoder/Routes HTTP); ECA `DomainEventEmitter`
drops on rollback; payment path excluded per authorization. eca=y, rules=n,
commerce_payment=y (module present, 0 gateways).

**Transaction / cleanup model.** ONE `startTransaction()`; store/guard/factory writes ran
on the same connection under nested savepoints; `rollBack()` in `finally`; a **separate
fresh process** then re-read the committed DB. InnoDB auto-increment advances do not roll
back, so the burned ids (order 5, item 5, attempt 45, reservations 65/66; the earlier
corrected run burned 4/4/44/63/64) are **expected, not residue**.

### 12a. A1–A8 outcomes (probe `RESULT : 30 passed, 0 failed, 3 deferred`; `OUTER_EC=0`)

- **A1 — real factory build + price lock — PASS.** `DispatchOrderFactory::create(submission #24, slot, contact, S0)` → one `commerce_order`, one `dispatch_service` item, quantity 1 (stored `1.00`), unit-price currency USD, unit-price number `Price::equals` accepted `123.45` (Commerce canonical `123.450000`), and `isUnitPriceOverridden()===TRUE` (the `overridden_unit_price` lock).
- **A2 — snapshot storage round-trip — PASS.** `CheckoutAttempt::opening()` → `CheckoutAttemptStore::open()` reloaded S1=`reloaded->quote`: `S1 == S0` field-by-field (same-class scalar/array props), `S1 !== S0` (distinct instance ⇒ genuinely rehydrated from storage, not the same object); `amountDecimal`, `bindingIdentity` (`v3:…`) and `hasJobFactsIdentity()` all preserved; the order's locked unit price `equals` the **reloaded-from-storage** snapshot amount.
- **A3 — EXPLICIT OrderRefresh, pipeline proven to run — PASS.** Added one UNLOCKED + one LOCKED `custom` adjustment, then called `commerce_order.order_refresh->refresh($order)`. The UNLOCKED adjustment was **stripped** (⇒ the refresh pipeline provably ran — not inferred from `getTotalPrice()`), the LOCKED adjustment **survived** (positive control), the overridden unit price **held** (`equals` 123.45), and the item stayed flagged overridden — runtime evidence that OrderRefresh does not requote the `dispatch_service` overridden price.
- **A4 / A5 — manager payable-match — DEFERRED (honest).** `DispatchCheckoutManager::orderPayableMatches()` is PRIVATE; the authorization forbids reflection/private-method invocation, and A5's rejection path is bound to the excluded payment flow. **G3's guard direction is therefore NOT runtime-exercised — it remains source-established only.** A detected total mismatch would not, by itself, prove manager rejection or voiding.
- **A6 — reservation price-snapshot round-trip — PASS.** `DatabaseReservationStore::claim()` → `confirm()` → `adminReservation()` preserved all four columns verbatim: `price_snapshot_id` (binding identity, fitted to the 128-char column), `price_snapshot_cents`=12345, `price_snapshot_currency`=USD, `price_snapshot_version`=`qa24b-v3`.
- **A7 — display — DATA PASS / RENDER DEFERRED.** A7-DATA: `adminReservation()` exposes a non-null price snapshot (the non-payment display source). A7-render: `DispatchReservationDetail::priceText()` is a PRIVATE helper; with no reflection permitted the rendered string is DEFERRED (source-established in §11a/§11d only).
- **A8 — legacy NULL row, no DDL — PASS.** A reservation written with NULL price columns (modelling a pre-24A row; no schema removal) confirmed and loaded; `adminReservation()` returned all four `price_snapshot_*` as `=== NULL` (not cast to 0/"") — legacy rows remain readable without a fabricated price.

### 12b. Fresh-process absence + rollback proof (`verify.php`: `VERIFY_RESULT : 11 passed, 0 failed`; `OUTER_EC=0`)

A separate bootstrap (its own process/connection, no ambient transaction) re-read the
committed DB: `commerce_order` / `commerce_order_item` /
`custom_webform_handlers_checkout_attempt` counts returned to baseline 0/0/0;
`custom_webform_handlers_dispatch_reservation` returned to baseline **6 == 6**; every
recorded synthetic pk (order 5, item 5, attempt 45, reservations 65/66) **absent**; no
attempt row bound to order 5; and a text-column substring sweep for `qa24b` across all 13
touched tables returned **0 hits** (covering both this run's tag and the earlier corrected
run's). The single outer-transaction rollback left zero residue.

### 12c. First-run correction (disclosed)

The first execution reported `RESULT : 26 passed, 4 failed`. All four failures were
**probe-side string-identity comparisons** against Commerce's canonical decimal
formatting — `Price::getNumber()` returns `123.450000` and quantity is stored `1.00`, so
`=== '123.45'` / `=== '1'` were false while the values were numerically exact. Corrected
to `Price::equals()` (scale-aware, currency-checked) and `bccomp(…, 6)`. **No application
source changed; the stored/overridden values were correct in both runs** — this was a
test-assertion defect, not price drift.

### 12d. Gap status after execution

- **G1 (end-to-end runtime) — PARTIALLY closed.** The Commerce order/item build, the
  explicit order-level OrderRefresh, the attempt storage round-trip, the reservation
  `price_snapshot_*` round-trip, and the admin **data** projection are now runtime-proven
  in-process via the real factory/store. **Still open:** the full public/HTTP checkout
  flow, the payment capture leg, and the admin **render** (`priceText`) + customer display.
- **G2 (OrderRefresh non-requote) — substantially addressed at runtime** for the
  `order_refresh->refresh()` path (refresh provably ran; overridden unit price survived,
  numerically equal to accepted). Not yet proven through a full checkout HTTP flow.
- **G3 (payable-total guard direction) — NOT exercised.** A4/A5 DEFERRED (private method,
  no reflection). Unchanged from §11f: source-established only; keep the `dispatch` order
  type processor-free.
- **G4 (no gateway) — unchanged.** 0 gateways; authorize→capture→void deferred to a test
  on-site gateway fixture under 24E.
- **G5 (public gate closed) — unchanged.** The probe seeded the attempt directly through
  the real factory + submission #24, as the proposal permitted.

### 12e. Explicitly NOT claimed

No browser/public-checkout acceptance; no payment authorization, capture, void, refund,
settlement or payment-pane execution was invoked; no gateway fixture created; no public
gate changed; no schema/DDL change; no migration, API, restart or commit. Temporary probe
files were removed on host + container after the fresh-process absence check (12b).

**Verdict.** The accepted-snapshot → attempt → Commerce order/item (overridden price) →
explicit OrderRefresh → reservation `price_snapshot_*` → admin-data display chain is now
**runtime-proven under a rolled-back QA transaction**, with legacy NULL handling intact and
zero residue. The payable-total guard direction (G3), the paid capture leg (G4), and the
HTTP/browser + render display legs (G1) remain unproven. **Task 24B remains PARTIAL — stop
here; do not advance to 24C–24K.**

### 12f. A7 controller-render verification (2026-09-25, appended; owner-authorized bounded QA probe) — RENDER PROVEN; §12a A6/A7 wording CORRECTED

Preserves §12a–§12e verbatim as the earlier run's record. This is a **separate, narrower**
bounded QA probe authorized specifically to (i) resolve binding provenance and (ii) verify
A7 through the **public display path** — closing the A7-render DEFERRAL recorded in §12a.
Single rolled-back transaction on `ppu-qa.ddev.site` / DB `qa` / `custom_webform_handlers`
schema 10047, via `docker exec ddev-ppu-web … drush --uri=https://ppu-qa.ddev.site
php:script` (no `ddev` start/restart/exec; DBHub MCP suspended). No source/config/schema
change; no migration; no commit. Judged by `A7_RESULT=` / `CHECK_RESULT=` (php:script
swallows exit codes) corroborated by `OUTER_EC`. This is controller-render verification —
**not** HTTP/browser or authorization acceptance.

**Three read-only gates (resolved from source before any write):**
- **Gate 1 — binding provenance.** Current-format `QuoteSnapshot::bindingIdentity()`
  (`:541–546`) = `BINDING_IDENTITY_PREFIX` (`'v3:'`, 3 chars) + 32-hex `snapshotId` + `':'`
  (1) + 64-hex `AcceptedJob::digestForFactsJson()` = **exactly 100 ASCII chars**, stored in
  `price_snapshot_id` = `varchar_ascii(128)`. It **fits by construction with 28 chars of
  headroom — NOT truncated, padded or "fitted"** — and `DatabaseReservationStore` writes/
  reads it as a verbatim pass-through (no `substr()`). **Production storage is UNAFFECTED.**
- **Gate 2 — transport / container identity.** Native `docker` is absent on the git-bash
  PATH; the only git-bash-invokable transport to the one authorized existing container is
  `wsl.exe → docker exec ddev-ppu-web` (container `df2027fc480b`, hostname `ppu-web`, DB
  service `ddev-ppu-db`, both `Up`). QA is selected by `--uri=https://ppu-qa.ddev.site` →
  DB `qa` — a URI selection on the SAME authorized container, **not an environment switch.**
- **Gate 3 — render owner.** `priceText()` is a **private helper of the PUBLIC
  `DispatchPlannerAdminController`** (`:2137`), reached WITHOUT reflection via the public
  route method `::reservation(int)` (`:186`) → `detailTable()` (`:1983`, price row `:2017`,
  gated on `view dispatch financial details`). Rendering **IS** testable through the public
  controller + the real Drupal renderer.

**Run — `A7_RESULT=PASS`, `OUTER_EC=0`.** A genuine v3 binding was built from the real
primitives (equivalent by construction to `:541–546`):
`v3:4e3a4ce118af688f4bfdf1c84974553f:652c7240dd25f0cc3d0b4de3436532ce94c54ba7adf082bff57ee196d1a085b8`,
length **100** (`A7_FORMAT=PASS`; matches `^v3:[a-f0-9]{32}:[a-f0-9]{64}$`). DB `qa`;
pre-write baseline `count=6 max=22 hash=c8f888dc64050670…`; synthetic fixtures
`priced=67 legacy=68` (both id > max).
- **Store round-trip — `A7_ROUNDTRIP=PASS`.** `dispatch_board_reader->reservation(67)`
  returned `priceSnapshotId` byte-for-byte === the binding, `cents=12345`, `currency=USD`.
- **Controller cell — `A7_CONTROLLER_PRICED=PASS`, `A7_CONTROLLER_LEGACY=PASS`.** Under
  uid 1 (`account_switcher`; `A7_PERM_FINANCIAL=1`) the real public
  `DispatchPlannerAdminController::reservation()` build's "Price snapshot" row rendered
  `123.45 USD, snapshot v3:4e3a…a085b8` (priced) and `No price was recorded` (legacy-NULL).
- **Renderer HTML — `A7_RENDER_PRICED=PASS`, `A7_RENDER_LEGACY=PASS`.** The real
  `renderer->renderRoot()` output contained the priced string and the legacy
  `No price was recorded`, respectively.
- **Side-effect denial — `A7_SIDEEFFECT_INTXN=PASS`, `A7_ROLLBACK=done`.** Count 6→8 inside
  the txn; explicit `rollBack()` in `finally`.

**Fresh-process absence + baseline preservation — `CHECK_RESULT=CLEAN`, `OUTER_EC=0`.** A
separate bootstrap (own process / own connection / no ambient transaction) run AFTER the
probe rolled back re-read the committed DB: `VERIFY_DB=qa`, fixtures 67/68 **absent**
(`found=0`), row count **6 == baseline 6**, and the id-set `SHA2` hash **matched** the
pre-write baseline. Cross-connection visibility ⇒ genuine commit-level rollback, not a
static-cache artifact.

**Cleanup.** Both host probe scripts (`a7-probe.php`, `a7-verify.php`) and all container
`/tmp` artifacts (`a7-probe.php`, `a7-verify.php`, `a7_state.json`, and the diagnostic
`persist_probe.txt`) were removed after the fresh-process check; absence confirmed on both
sides (host `HOST_RM_EC=0`, container `CONTAINER_RM_EC=0`). Pre-existing dirty files and the
closed public gate were left untouched.

**Corrections to §12a (historical text preserved above, not overwritten):**
1. **A6 wording.** "binding identity, fitted to the 128-char column" is imprecise. Correct
   statement: the current-format binding is **100 ASCII chars, stored in
   `varchar_ascii(128)`, fitting by construction (28 chars headroom), verbatim pass-through,
   no truncation; production storage unaffected.**
2. **A7-render — DEFERRED → PROVEN.** §12a attributed the render string to
   `DispatchReservationDetail::priceText()` and deferred it as untestable without reflection.
   Correct owner: the **private `priceText()` helper of the PUBLIC
   `DispatchPlannerAdminController`**, reachable via the public `::reservation()` →
   `detailTable()`. A7-render is now **runtime-PROVEN** through the public controller and the
   real renderer (above) — **no reflection, no visibility change, no replacement formatter.**
   The admin-display render leg of G1 is therefore closed.

**Scope unchanged.** A4/A5 (payable-total match, private) remain DEFERRED; the paid-capture
leg (G4), and the full public/HTTP checkout + customer-facing display, remain unproven.
**Task 24B remains PARTIAL — do not advance to 24C–24K.** No commits.




