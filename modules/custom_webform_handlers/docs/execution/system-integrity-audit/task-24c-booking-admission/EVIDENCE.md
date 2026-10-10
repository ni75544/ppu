# Task 24C — Booking-Admission Design Correction (READ-ONLY, Turn 3)

Scope: design only. No source/config/schema/migration/fixture/sweep/payment/API/infra/commit this turn.
Task 24B stays PARTIAL. Do NOT advance to 24D. DBHub SUSPENDED; QA metadata via the authorized
read-only drush substitute inside `ddev-ppu-web` (`--uri=https://ppu-qa.ddev.site`, module schema 10047).

Labels: **SE** = source-established (file:line). **RV** = runtime-verified (QA drush, 10047). Anything
else is UNVERIFIED and labelled so.

## E05 verdict (unchanged, now precise)

No submission-scoped UNIQUE exists on either capacity table. [RV: `SHOW CREATE TABLE` on QA —
`checkout_attempt` uniques = `attempt_order`/`attempt_intent`/`attempt_reservation` + plain
`KEY attempt_submission(submission_id,state)`; `dispatch_reservation` has NO unique except PRIMARY.]
The only per-submission protection is the advisory SELECT `liveClaimForSubmission()` run OUTSIDE the
guard transaction → TOCTOU.

## Gap 1 — Shared admission authority (writer map)

Two SQL writers only; every booking path funnels through them [SE: raw grep of both table literals hits
only these two classes + `ServicePopularityAggregator`, which reads only]:

- `checkout_attempt` — written ONLY by `CheckoutAttemptStore` (payment lifecycle).
- `dispatch_reservation` — written ONLY by `DatabaseReservationStore` (capacity lifecycle).

Confirmation writers, all reaching capacity via `DatabaseReservationStore::confirm/confirmWithRevalidation/replan`:

- Public checkout: `DispatchCheckoutManager` → planner → `confirmWithRevalidation`. Prechecks
  `existingAttempt()`/`openForSubmission()` are on the ATTEMPT table, unlocked
  [SE DispatchCheckoutManager.php:126,281].
- Staff: `DispatchOperations::confirmManualReview`:452 → `DispatchPlanner::confirmForStaff`:772. Sole
  submission gate = `liveClaimForSubmission()`:782, UNLOCKED, then `createHoldFor()`+`confirmFor()` under
  the guard [SE].
- Manual review: `DispatchManualReviewConfirmForm::submitForm` → `operations->confirmManualReview` (same
  staff path). Advisory `isAlreadyClaimed()` from `waitingRequest()`:631 → `liveClaimForSubmission()`:653,
  display-time only [SE].
- Replan: `store.replan()` supersede→REPLANNED + new CONFIRMED in one txn [SE interface L199-240; store
  L554-682].
- `recordScheduled()`:552 writes the `review_request` POINTER (order_id/dispatch_reservation_id), NOT
  capacity [SE ReviewManager.php:223].

Consequence: staff/manual/replan NEVER write `checkout_attempt`. An attempt-table unique key CANNOT be the
shared authority (**corrects prior C4**). The DB-enforced per-submission gate must live on the reservation
lifecycle.

RECOMMENDATION — a submission-scoped nullable-unique on `dispatch_reservation`, keyed to CONFIRMED only.
Add a generated column `active_submission_key = CASE WHEN state='confirmed' THEN submission_id ELSE NULL END`
plus a UNIQUE index. This covers ALL confirmation writers (each sets `state=confirmed`+`submission_id`
through the one store) with ONE index. Holds are excluded by construction [SE: `claim()` inserts
`submission_id=NULL, state=held` — store L231-232], so the token-owned hold phase is untouched and only true
double-confirms collide. Retain `liveClaimForSubmission()` as a fast, friendly precheck (UX; it names the
existing claim) but DEMOTE it from safety mechanism to advisory — the unique index is the gate (SQLSTATE
1062). Prefer this over a dedicated `booking_admission` table because the constraint rides the same row
already written under the guard locks → it adds NO new lock-ordering obligation. A dedicated table is needed
ONLY if a second Hold family (the legacy reservation table named by `BookingWorkflowGate`) must share one
cross-table submission gate; that row would then have to be acquired FIRST, before either family's guard locks.

Why the guard alone is insufficient [SE]: the Resource Calendar Guard serializes by `(resource_id,
local_date)`, NOT by submission. Two confirmations of one submission on DIFFERENT resource-days take DIFFERENT
guard locks and run concurrently → the guard cannot prevent the double-confirm. The submission-scoped unique
index is the missing cross-day serialization point.

Decisive linkage fact [SE store L1411-1428]: `liveClaimForSubmission` selects `WHERE submission_id=? AND
<live>`; because Holds carry `submission_id=NULL`, it can only ever match CONFIRMED rows — so keying the index
to `state='confirmed'` loses no protection the advisory check gave, and enforces it structurally.

## Gap 2 — Ownership across the lifecycle

Invariant: at most one `dispatch_reservation` row per submission carries a non-NULL admission key at any
committed instant, and it is the row in `state=confirmed`. The key does NOT evaporate at confirmation
(**corrects the checkout_attempt proposal**, where success=`paid` erased it); it persists for the whole
confirmed lifetime and is released only by a deliberate history transition.

| Phase | reservation state | Holds key? | Frees / keeps | Lock |
|---|---|---|---|---|
| pending (attempt) | no confirmed row; Hold=held, submission_id NULL | No | n/a | guard on Hold |
| confirmed | confirmed | YES (=submission_id) | 2nd confirmed → 1062 | guard + unique index |
| reconciliation (attempt) | payment-side only; no reservation key | No | acquires key only if it later confirms a Hold | — |
| cancellation | confirmed→cancelled (adminRelease, row KEPT) | released | re-booking allowed | guard + CAS |
| expiry | held→expired (Holds only; confirmed never expires, expires_at NULL) | never held it | untouched | row lock |
| replanning | supersede confirmed→REPLANNED + new CONFIRMED, ONE txn | transfers atomically | history KEPT | guard; UPDATE-old(key→NULL) THEN INSERT-new(key set) → no self-collision |

`replan()` is the ownership-transfer mechanism and preserves history [SE interface L199-240]; cross-resource
replan is refused [SE].

## Gap 3 — Safe expiration (never infer payment failure)

The reservation admission key is held ONLY by confirmed rows, so freeing it NEVER requires judging a payment
outcome — it is released only by explicit release/cancel/replan, never by age or a sweep. This structurally
avoids the "age / hold-expiry ≠ payment failure" trap on the capacity side. (**Corrects prior C4**, which
coupled the key to a bounded pending sweep.)

The pending sweep, if any, is a SEPARATE payment-hygiene concern on `checkout_attempt`, fully decoupled from
admission:

- Do NOT terminalize payment-in-progress / unknown-outcome pending attempts to free anything.
- Class A (safely abandoned, determinable-negative — no authorization recorded): conditional
  `state=pending AND <no auth recorded> AND age>bound → failed`.
- Class B (unknown outcome / authorization may have succeeded): → `reconciliation_required` (existing state),
  resolved by reconciliation, NEVER terminalized.
- Retry: a returning customer re-runs the two-step against the same order / `idempotency_key` (no duplicate
  charge). Payment recovery itself is out of scope this task.

## Gap 4 — Renewal correctness (retraction + synchronization point)

RETRACT the unconditional "no guard lock is correct." Corrected:

- `renew()` [SE store L751-776] is a single conditional UPDATE `... WHERE hold_token_hash=? AND owner_hash=?
  AND state=HELD AND expires_at > $now`. The InnoDB ROW lock on the matched Hold + the `state=HELD` predicate
  IS the synchronization point against `expireDue()` [store L781-798]: the two are mutually exclusive per row,
  so an EXPIRED row can never be revived and there is no lost update. `renew` needs no GUARD (resource-day)
  lock because it moves no capacity between days and mutates only its own Hold's expiry — the row lock
  suffices. THAT much of the prior claim stands.
- The DEFECT that survives is the TIME SOURCE: `$now = getRequestTime()` is request-start. Between
  request-start and row-lock acquisition, wall-clock advances; a Hold still in `state=HELD` whose true expiry
  has passed but which `expireDue` has not yet swept can satisfy the stale `expires_at > $now` and be extended
  past real expiry. Sampling `getCurrentTime()` in PHP BEFORE a blocking UPDATE is still stale by the
  lock-wait interval on a contended row.
- Authoritative point = the row-lock / write instant. Smallest safe mechanism: evaluate liveness against the
  DB clock INSIDE the UPDATE predicate (e.g. `expires_at > UNIX_TIMESTAMP()`), so validity is decided AT the
  locked write, not in PHP beforehand. DI-preserving fallback: inject `getCurrentTime()` and DOCUMENT the
  residual lock-wait staleness bound. NOT a guard lock. NOT runtime-reproduced (QA has 0 held rows — RV).

## Exact target files (every edit WITHHELD this turn)

- `custom_webform_handlers.install` — NEW `hook_update_10048()`: add the generated column
  `active_submission_key` + UNIQUE index on `dispatch_reservation`; mirror in `hook_schema()` for fresh
  installs. **[SCHEMA]**
- `src/Dispatch/Storage/DatabaseReservationStore.php` — catch SQLSTATE 1062 on the admission index in
  `confirm`/`confirmWithRevalidation`/`replan` → map to `DispatchReason::AlreadyBooked`; tighten `renew()`
  predicate clock (DB-side, or injected `getCurrentTime()`). **[WRITE]**
- `src/Dispatch/DispatchPlanner.php` — keep the advisory `liveClaimForSubmission()` precheck; ensure the 1062
  refusal surfaces as `AlreadyBooked` through `confirmForStaff`/`confirmFor`. **[WRITE]**
- `src/Dispatch/Storage/ReservationStoreInterface.php` — document the one-confirmed-per-submission invariant.
  **[DOC]**
- (DEFERRED, separate) `src/Checkout/CheckoutAttemptStore.php` + install — Class-A-only bounded pending
  sweep; NOT part of admission, Gap-3 hygiene only.

No config/schema YAML change: the gate is a DB index, not exported config — the module's
`settings`/`schema` YAML are unrelated [read-from-source].

## Migration proposal (authorization WITHHELD)

One update hook (≈10048), idempotent and additive:

1. Pre-check and REPORT any submission already holding >1 confirmed row (never delete — "report conflicts,
   never delete").
2. Add the generated column (prefer VIRTUAL to avoid a STORED table rebuild; `dispatch_reservation` is tiny —
   AUTO_INCREMENT=69 [RV] — so cost is negligible either way).
3. Add the UNIQUE index (nullable-active pattern; repeated NULLs are allowed on MariaDB/InnoDB — in-repo
   precedent `attempt_intent`/`attempt_reservation`).
4. Bump schema 10047→10048; mirror in `hook_schema()`.

Rollback = drop index + column. MariaDB caveat: a STORED generated column may copy the table; VIRTUAL + index
is lighter. Requires explicit schema authorization.

## Acceptance cases (bounded probe plan; real join columns)

Join keys (Gap-5 fix): `checkout_attempt.attempt_id` (PK) / `.dispatch_reservation_id` ↔
`dispatch_reservation.dispatch_reservation_id`; submission dimension via `dispatch_reservation.submission_id`.
The earlier `ca.id` join was ERROR 1054 → UNVERIFIED; it must be RERUN with `attempt_id`, not reported as a
passed vacuous check.

Four races:

- **A. public/public** — two paid confirms, one submission, incl. different resource-days: post-fix exactly
  one confirmed (other → 1062); pre-fix two confirmed (reproduces E05).
- **B. staff/public** — `confirmForStaff` + public confirm concurrent, one submission: one wins, other refused.
- **C. confirmed-job/new-attempt** — submission already confirmed; a new attempt / staff confirm is refused
  (advisory names the claim; the DB blocks a 2nd confirmed).
- **D. expired-hold/reclaimed-capacity** — a Hold at wall-clock expiry: assert `renew` cannot extend a row
  `expireDue` moved to EXPIRED (state guard); post clock-fix `renew` refuses a wall-clock-expired Hold; a
  fresh guarded claim takes the freed slot exactly once.

Harness: committed synthetic submission + baseline rows via a fixture ledger (two drush processes = two
connections; B sees A only if committed). External barrier (sentinel row/file) aligns both AT the write;
bounded barrier wait + `innodb_lock_wait_timeout`. Cleanup = explicit committed DELETE by the ledger (a peer's
committed row survives the other's rollback); NEVER touch the 6 pre-existing QA reservations [RV]. Classify
outcomes by SQLSTATE: intended = **1062 on the admission index** (proof of enforcement), distinct from 1213
deadlock / 1205 lock-wait / connection errors (infra, not enforcement).

## Required authorization (to leave design)

- Schema: one update hook (10048) + `hook_schema` mirror. **WITHHELD.**
- Write: `DatabaseReservationStore` (1062 mapping + renew clock), `DispatchPlanner` (refusal surfacing),
  interface doc. **WITHHELD.**
- Fixtures + write + a running QA site for the bounded probe (committed synthetic rows + ledger teardown).
  **WITHHELD.**
- All READ-ONLY this turn; nothing above executed. DBHub stays suspended.

## Turn 4 — Design correction (READ-ONLY). Supersedes Turn-3 where marked.

Semantics ESTABLISHED via Context7 official MariaDB docs (clock + e1062) [DOC] and SE reads of both
capacity writers + the live handler. DBHub SUSPENDED. Task 24B PARTIAL. NO source/schema/migration/
fixture/payment/API/infra/memory/commit executed this turn.

### C0 — DECISIVE NEW FACT: two live capacity families, both reachable from the live handler

`AddressDistanceHandler` (the active Webform handler) injects AND calls BOTH capacity writers [SE
AddressDistanceHandler.php:303 `reservation_manager`, :309 `dispatch_checkout_starter`;
:1675/:2084/:2485 reservationManager hold/verify/confirm; :2134 `checkoutStarter->start`]:

- `dispatch_reservation` — writer `DatabaseReservationStore`. Serialized by ResourceCalendarGuard per
  (resource_id, local_date). Reclaims expired-HELD by PREDICATE, never sweeps; NO integrity catch; NO
  submission-scoped unique.
- `custom_webform_handlers_reservation` — writer `DatabaseReservationManager`. ONE unique index on
  `active_slot_key` (SLOT-scoped, NOT submission). SWEEPS expired holds before every op [SE :264/:346/
  :581/:627/:667]. Catches EVERY `IntegrityConstraintViolationException` → single reason
  SLOT_UNAVAILABLE [SE :284-289, :603-607].

Consequence (corrects Turn-3 Gap-1/C4 decisively): a per-submission gate on EITHER capacity table
cannot bind writers of the OTHER, and a confirmed-only unique on `dispatch_reservation` collides only
at confirmation — exactly the counterexample. The shared authority MUST sit ABOVE both writers and be
acquired BEFORE any hold or payable order.

### Gap 1 (CORRECTED) — one shared admission authority, acquired up front

RECOMMENDATION: a dedicated per-submission admission record in a NEW table `dispatch_booking_admission`
— NOT a column on either capacity table (C0: a column binds only its own writer). One row per live
booking subject.

- Stable job identity (incl. anonymous): the saved Webform submission id is the durable, server-
  assigned job identity — it exists before checkout on both entries [SE DispatchCheckoutManager.php:126
  uses `submissionId`; AddressDistanceHandler.php:2084 `confirm($holdToken,$submissionId)`]. The
  anonymous browser proves continued ownership via an `owner_token_hash` derived from the same secret as
  the existing Hold owner token / attempt `idempotency_key`; a different browser cannot release/confirm
  it. The pre-submission token-owned Hold phase (reservation-manager holds carry submission_id NULL) is
  UNCHANGED; admission is acquired at the instant a submission id exists and BEFORE a competing
  hold/order.
- Acquisition = INSERT `(submission_id, generation, owner_token_hash, state='acquired', acquired_at)`
  under a UNIQUE index on the active admission key (`submission_id` WHILE state IN(acquired,confirmed),
  NULL once released — nullable-active pattern, repeated NULLs allowed, in-repo precedent
  `attempt_intent`/`active_slot_key`). Two concurrent acquisitions for one submission → the second takes
  errno 1062 on the admission key → refused AT ADMISSION, before either creates a hold/order.
- Acquired up front by ALL paths, before holds/orders:
  - Public: `DispatchCheckoutManager::start` BEFORE `planner->createCheckoutHold` and
    `orderFactory->create` [SE :131, :139].
  - Staff: `DispatchPlanner::confirmForStaff` BEFORE `createHoldFor`/`confirmFor`; the admission INSERT
    REPLACES the unlocked `liveClaimForSubmission()` as the authority [SE :782].
  - Manual review: `DispatchOperations::confirmManualReview` (same staff path).
  - Reservation-manager Webform path: `AddressDistanceHandler` BEFORE `reservationManager->hold/confirm`
    [SE :2084] — the SECOND family, otherwise ungated by admission.
- Ownership/generation (CAS): every confirm/release/renew/replan carries the
  `(submission_id, generation, owner_token_hash)` it believes it owns; the write is conditional
  `WHERE submission_id=? AND generation=? AND owner_token_hash=?`. A stale request (older generation or
  wrong token) matches zero rows → no-op → cannot release a replacement's admission.
- Lifecycle: the admission persists through pending → confirmation → reconciliation; released ONLY by
  explicit cancel/release/replan (never by hold expiry or payment ambiguity — Gap 2/3).
- Cancellation: state='released', active key → NULL; re-booking allowed.
- Atomic replan transfer: ONE txn supersedes the old capacity row and bumps `generation` (old
  generation invalidated, new owns the admission). Same submission_id row stays → no self-collision.
- Confirmed-only capacity index = SUPPLEMENT (defence-in-depth vs a double-confirm that bypassed
  admission), NOT the primary gate. Retain `liveClaimForSubmission()` as advisory UX only.

### Gap 2 (CORRECTED) — safe abandonment: separate job exclusivity from driver-capacity

RETRACT "no authorization recorded = safely abandoned" (Turn-3 Gap-3 Class A). A payment may be in
flight or its success response lost.

- Job exclusivity = the admission record (one payable booking per submission). NEVER released by hold
  expiry, age, or absence of a recorded authorization. Release requires POSITIVE evidence: (a) payment
  provably never started, (b) a definitive terminal gateway outcome, or (c) explicit human
  reconciliation.
- Driver-capacity retention = the Hold's slot. A Hold MAY lapse by its own expiry to free the SLOT for
  others WITHOUT releasing the admission — the customer keeps exclusive booking intent while a specific
  slot hold lapses, and no second payable booking for that submission can be created.
- Unknown outcome (in-flight / lost response) → attempt to `reconciliation_required` (existing state),
  admission HELD, NEVER terminalized. Determinable-negative (gateway confirms no charge / no intent) is
  the ONLY safe path to release.
- Reclamation/expiry therefore acts on driver-capacity only; admission moves only on evidence-based
  transitions. Uncertainty cannot silently create another payable booking.

### Gap 3/4 (CORRECTED) — renewal synchronization; clock semantics ESTABLISHED

ESTABLISHED [Context7, MariaDB official docs]:
- `NOW()`/`UNIX_TIMESTAMP()` return a CONSTANT = the time the statement BEGAN to execute; unchanged
  across the statement (proven by `SELECT NOW(),SLEEP(2),NOW()` returning equal values). Affected by
  `SET timestamp`.
- `SYSDATE()` returns the time at which it EXECUTES (differs within one statement across `SLEEP`); it is
  non-deterministic, unsafe for statement-based replication, NOT affected by `SET timestamp`, and
  INDEXES CANNOT be used to evaluate expressions referring to it.

RETRACT the Turn-3 fix "`expires_at > UNIX_TIMESTAMP()` decides validity AT the locked write." It does
NOT: `UNIX_TIMESTAMP()`/`NOW()` is frozen at STATEMENT START — for a lock-waiting UPDATE that is BEFORE
the row lock is acquired. The predicate COMPARISON happens as InnoDB examines the (now-locked) row, but
against a STALE statement-start clock. Only `SYSDATE()` evaluates at row-examination time, and its
caveats (non-indexable, replication-unsafe) make it a poor gate. An in-predicate clock swap does not
satisfy the invariant.

Reclamation-without-sweep is REAL and BY DESIGN on the dispatch family [SE
DatabaseReservationStore.php:1568-1575 `conditionOnLive()`: "Evaluating expiry here rather than trusting
a sweep is what makes a stalled cron harmless" — excludes expired-HELD by `expires_at > now` predicate].
`expireDue()` [SE :781] has NO caller in the module (hook_cron does only the Review sweep + popularity
recount [SE custom_webform_handlers.module:42; docs task20-baseline]) — dispatch holds are NEVER swept;
reclamation is 100% predicate-based. Contrast: the reservation-manager family SWEEPS first (C0).

Invariant to enforce: renewal and reclamation must judge liveness at the SAME instant. Today
availability uses a per-query `$now` while `renew()` uses request-start `getRequestTime()` [SE :751-776]
and NOTHING sweeps → a Hold past true expiry is treated as free by availability yet may be extended by
`renew()` on its staler clock. A documented stale-clock fallback does NOT satisfy this (per Req 3).

Locks + order (consistent, deadlock-free): admission row (if touched) → resource-calendar guard rows
ordered by (resource_id, local_date) → the Hold row. `renew()` moves no capacity between days and
mutates only its own Hold's `expires_at`, so it needs only the Hold ROW lock, not the guard — that part
of the Turn-3 claim STANDS.
- Correct mechanism (lock-then-revalidate): `SELECT ... FOR UPDATE` the Hold row; read a fresh clock in
  PHP AFTER the lock returns; evaluate `state=HELD AND expires_at > $freshNow`; then conditional UPDATE.
  Validity is decided against a clock no earlier than lock acquisition, closing the lock-wait window.
- Defence: wire `expireDue()` (or fold the sweep into the guarded write) so an expired-past-HELD row
  cannot be revived — reclamation predicate and renewal `state=HELD` guard then agree by construction.

### Gap 5 (CORRECTED) — constraint-name-specific error classification

ESTABLISHED [Context7]: errno `1062` = NATIVE error number; SQLSTATE `23000`; `ER_DUP_ENTRY`; message
`Duplicate entry '%s' for key '%s'` (the violated KEY NAME is in the message). SQLSTATE `23000` is the
whole INTEGRITY-VIOLATION class — it also covers FK (1451/1452) and NOT NULL (1048), not only
duplicates.

Therefore (corrects every Turn-3 "SQLSTATE 1062"): Drupal surfaces these as
`IntegrityConstraintViolationException` (SQLSTATE-23000 family). Do NOT map the whole exception to one
reason. Classify by driver errno `1062` AND the violated key name (from PDO `errorInfo[1]`/`[2]`):
- 1062 on the ADMISSION unique key → `AlreadyBooked` (job exclusivity).
- 1062 on a capacity key (`active_slot_key`, or a confirmed-only supplement) → the capacity reason
  (`SLOT_UNAVAILABLE` / confirmed-collision).
- Any other 23000 (FK / NOT NULL) → internal error, surfaced distinctly; NEVER silently "already
  booked".
This corrects the live catch-alls: `DatabaseReservationManager` maps EVERY 23000 → SLOT_UNAVAILABLE [SE
:284-289, :603-607]; `DatabaseReservationStore` has NO catch, so a new admission/supplement 1062 there
would 500 unless a key-name-specific catch is added.

### Gap 6 (CORRECTED) — acceptance scope starts at ADMISSION

- A. Duplicate holds/orders (public/public, one submission): two concurrent `start()` → exactly one
  acquires admission; the other takes 1062-on-admission-key and is refused BEFORE any second hold/order.
  Assert exactly one hold + one order (pre-fix: two — the counterexample).
- B. Staff/public competition: `confirmForStaff` + public `start` concurrent → one acquires admission,
  the other refused at admission.
- C. Confirmed-job re-entry: admission already held/confirmed → new attempt/staff confirm refused at
  admission (advisory names the claim; the INSERT collides).
- D. Unknown-payment ownership: payment in-flight/lost → admission stays held, attempt →
  `reconciliation_required`; assert NO second payable booking, admission NOT released by hold expiry.
- E. Delayed renewal: a Hold at wall-clock expiry with an induced lock-wait → assert `renew()` refuses
  once liveness is judged post-lock on a fresh clock; a reclaimed slot is not double-sold; sweep and
  predicate agree.

Harness: committed synthetic submission + admission/baseline rows via a fixture ledger (two drush
processes = two connections; B sees A only if committed). External barrier aligns both AT the admission
INSERT; bounded wait + `innodb_lock_wait_timeout`. Classify by errno 1062 + KEY NAME on the admission
index (proof of admission enforcement), distinct from 1213/1205/connection errors. Cleanup = explicit
committed DELETE by the ledger; NEVER touch the pre-existing QA rows [RV]. The `checkout_attempt` ↔
`dispatch_reservation` join stays UNVERIFIED until rerun with `attempt_id`/`dispatch_reservation_id`
(earlier `ca.id` = ERROR 1054), not reported as a passed vacuous check.

### Exact target files (every edit WITHHELD this turn)

- `custom_webform_handlers.install` — NEW `hook_update_10048()`: create `dispatch_booking_admission`
  (submission_id, generation, owner_token_hash, state, acquired_at, released_at) + UNIQUE on the active
  admission key; OPTIONAL confirmed-only supplement index on `dispatch_reservation`; mirror both in
  `hook_schema()`. **[SCHEMA]**
- NEW `src/Dispatch/Admission/BookingAdmissionStore.php` (+ interface) — acquire/confirm/release/
  replan-transfer with generation CAS and 1062+key-name classification. **[WRITE/NEW]**
- `src/Commerce/DispatchCheckoutManager.php` — acquire admission before `createCheckoutHold`/
  `orderFactory->create` [:131,:139]. **[WRITE]**
- `src/Dispatch/DispatchPlanner.php` — acquire admission in `confirmForStaff` as the authority; keep
  `liveClaimForSubmission()` advisory [:782]. **[WRITE]**
- `src/Plugin/WebformHandler/AddressDistanceHandler.php` — acquire admission before
  `reservationManager->hold/confirm` [:2084]. **[WRITE]**
- `src/Reservation/DatabaseReservationManager.php` — narrow catch-all to 1062 + `active_slot_key`;
  rethrow other 23000 [:284-289,:603-607]. **[WRITE]**
- `src/Dispatch/Storage/DatabaseReservationStore.php` — `renew()` lock-then-revalidate on a fresh
  post-lock clock; wire the sweep; key-name-specific 1062 handling [:751-776,:1568-1575,:781]. **[WRITE]**
- `src/Checkout/CheckoutAttemptStore.php` — reconciliation-aware; no admission release on ambiguity.
  **[WRITE]**
- Interfaces/interface docblocks: one-active-admission-per-submission invariant. **[DOC]**
### Migration proposal (authorization WITHHELD)

One idempotent, additive hook (10048): (1) pre-check and REPORT any submission already holding >1 live
payable booking (never delete — "report conflicts, never delete"); (2) create `dispatch_booking_admission`
+ UNIQUE active-key; (3) OPTIONAL confirmed-only supplement on `dispatch_reservation`; (4) bump schema
10047→10048; mirror in `hook_schema()`. Rollback = drop table/index. Backfill: seed one admission per
existing live confirmed reservation so current bookings own their admission.

### Required authorization (unchanged — WITHHELD)

Schema (10048 + `hook_schema` mirror), Write (new admission store + 6 edited classes), Fixtures + a
running QA site for the admission-first probes. ALL READ-ONLY this turn; DBHub SUSPENDED; Task 24B
PARTIAL; do NOT advance to 24D.



