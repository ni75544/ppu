# Task 24C-4 — Booking-Admission Caller-Adoption Plan (implementation-ready)

**Status:** planning only. No application/config change, no fixture, no migration, no
backfill, no activation, no `update_10049`. The store
`custom_webform_handlers.booking_admission_store` stays inert throughout; nothing here
wires it. This revision (24C-4A) corrects five contradictions in the prior draft that
could otherwise recreate duplicate bookings — see the change log at the end of §0.

> **Update (24C-5B, 2026-09-26 — QA only):** `update_10049` has since been applied to the
> QA multisite (`https://ppu-qa.ddev.site`, DB `qa`), moving its module schema **10048 → 10049**.
> The three nullable `admission_generation` binding columns now exist on QA; every value is
> NULL (no row enrolled), no caller is wired, and `booking_admission.enforce` stays off/absent —
> admission remains inert. The "no migration / no `update_10049`" clauses above describe what
> *this plan* changes and are no longer a claim about QA runtime state. Full evidence: the
> "24C-5B execution" section at the end of this document.

**Scope of evidence:** direct source/config inspection + last recorded read-only QA
metadata. The 39/39 (Req-1 sequential gate) and 19/19 (two-session) store probes from
24C-3 are **not** repeated — they are accepted (see [VERIFICATION-24C-3.md](VERIFICATION-24C-3.md)).

## Evidence systems & coverage limits

- **Graphify:** refresh remains **BLOCKED** by the running watch-lock (WinError 32). Per
  the stage exception (extended to this read-only plan), direct-source reads are
  authoritative here; the graph is AST-only with no YAML/Markdown coverage. BLOCKED status
  retained — a coverage limitation, not graph coverage.
- **DBHub:** **SUSPENDED** (least-privilege QA saturation risk). Authorized substitute =
  read-only Drush via the running-container `php:script` route. No QA state was modified.
- **GitNexus / Context7:** not required — internal wiring over code already read.
- **DDL fact (install L566-582):** the admission table has **only** identity/state columns
  (`submission_id` PK, `generation`, `state`, `owner_token_hash`, `intent_fingerprint`,
  `owner_kind`, `note`, four timestamps). It carries **no** pointer to a Hold, order,
  attempt, or Reservation. Binding is one-directional: the *binding* row carries the
  admission `generation`; the admission row never points back. This shapes every batch.
- **Store facts (direct read, [BookingAdmissionStore.php](../../../../src/Dispatch/Admission/BookingAdmissionStore.php)):**
  `acquire()` (L77) is **self-committing** (own txn/savepoint); its `Reused` branch
  (L96-104) compares **owner hash + intent only — never `owner_kind`**. `verify()` (L226)
  **asserts an ambient transaction**, takes `FOR UPDATE`, and checks **owner → generation →
  liveness** (so `RefusedOwner` precedes `RefusedGeneration`). The store hashes whatever
  secret it is handed (`hashSecret`) and can also mint a random one
  (`mintOwnerSecret()` = `bin2hex(random_bytes(32))`).

## 0. The one governing decision (read first)

`isFreshAdmission()` (Admitted only) authorizes a **new** downstream effect; `Reused` means
"resume/return existing work, spawn nothing." The store distinguishes the two but **neither
executes nor suppresses** the downstream effect — that is the binding caller's job
([AdmissionResult.php](../../../../src/Dispatch/Admission/AdmissionResult.php) docblock).

Two independent requirements make recovery correct; **neither is uniquely necessary**:

1. **Owner-credential re-presentability + handoff-stability.** A retry (or a
   public→staff→manual handoff) must present the **same** admission owner, or it self-locks
   to `RefusedOwner` forever (the block Req 3 forbids). This is a *requirement*, not a
   mechanism. **Deriving** the secret from a durable input is *one* sufficient mechanism
   (chosen here, §2); **durably storing** a `mintOwnerSecret()` random secret in the same
   transaction that writes the admission is an equally valid alternative. The prior draft's
   claim that recovery *necessarily requires* a derived secret is withdrawn.
2. **The initial binding — not just confirmation — runs under the admission lock.**
   `isFreshAdmission()` alone does not recover a `Reused` resume, and the **unlocked**
   "no attempt found" precheck (`DispatchCheckoutManager::existingAttempt()`, L126/L265) is
   racy. The sole authority for create-vs-resume is a **locked** `verify()` `FOR UPDATE`
   plus an authoritative binding lookup, both inside one transaction (§3, §4).

**24C-4A corrections (change log):** (1) initial binding protected under the lock, not just
`confirmHeld()`; (2) every writer + credential transition covered, owner secret derived
from `submission_id` **only** (owner_kind dropped from the formula), acting user authed
separately; (3) locked enrollment / controlled backfill replaces the unlocked precheck as
authority; (4) one default-off activation gate, observe-only `acquire()` removed, batches
reconciled; (5) physical-driver capacity overlap is an **activation blocker**, not a note.

**24C-5A corrections (record before writing any caller):** two precisions carried out of
Batch 0 source prep. Both change how a caller must read §3–§4; neither activates anything.

- **A rolled-back write is not an orphan.** The §3 matrix's "orphan Hold / orphan order
  reattached" describes only a record a **prior committed** transaction left behind, or a
  **nontransactional side effect** (an external call, an autocommitting statement). Within
  one request the Hold → order → attempt writes share the binding transaction, so a crash
  *before its commit* rolls them all back — there is nothing to reattach and the retry
  begins at case 1/2, not 3. A caller must distinguish (i) pre-existing committed rows from
  an earlier request and (ii) nontransactional side effects — the only true "orphans" the
  locked reread reattaches — from (iii) same-connection uncommitted writes that simply
  vanish on rollback. Treating (iii) as a committed orphan would fabricate a duplicate.

- **`Reused` still creates the initial binding — reconcile "fresh-only creation."**
  `isFreshAdmission()` gating a *new* effect (§0) must not be read as "only a fresh
  admission ever creates a binding." On a crash-retry the store returns **`Reused`** while
  **no** binding yet exists; the caller must then create the initial Hold → order → attempt
  **under the held `verify()` `FOR UPDATE` lock**. Authority for create-vs-resume is the
  **locked, authoritative binding lookup** (none ⇒ create; found ⇒ resume), never
  `isFreshAdmission()` alone and never the unlocked precheck. Any wording — here or in a
  future caller — of the form "fresh admissions create, reused resume" must be reconciled to
  "creation is gated by the locked binding lookup; `Reused` with no binding creates one."

These are documentation corrections only. The physical-driver capacity gate (**G-CAPACITY**,
§4/§8) and the locked-enrollment / controlled-backfill gate for pre-existing rows (§5) stay
**open and blocking**; nothing here narrows them.

## 1. One per-path binding & transaction table (Req 1)

Families are **mutually exclusive per Webform** (`BookingWorkflowGate`; legacy confirm in
`AddressDistanceHandler::postSave()` gated `reservationEnabled()` L2034, dispatch checkout
in `confirmForm()` gated `dispatchEnabled()` L2125), so exactly one family runs per
submission. Anonymous submissions still get a durable integer `submission_id` at save,
which is before every family surface (B–F). Surface A is the only booking moment with no
`submission_id`, which is why admission cannot begin there.

Owner secret on every path below: `hash_hmac('sha256', (string) submission_id, <site salt>)`
— **`submission_id` only** (see §2). owner_kind is passed to `acquire()` as origin metadata
and is **not** part of the credential.

| # | Surface / writer (file:line) | Binding record — home of record | `admission_generation` lives on | Admission call(s) + lock | Transaction boundary |
|---|---|---|---|---|---|
| A | Public offer/hold, pre-submission — `DispatchController`→`DispatchPlanner::createHold` | Hold (session receipt) | — (no submission) | **none** — no `submission_id` yet | planner guard txn (Hold only) |
| B | Public → checkout — `AddressDistanceHandler::confirmForm` L2134 → `DispatchCheckoutStarter::start` L101 → `open()` L322 → `DispatchCheckoutManager::start` L96 | **`checkout_attempt`** (+ Commerce order, Hold) | **`checkout_attempt`** | `acquire()` (self-committing) **then** locked create/resume: `verify()` `FOR UPDATE` → authoritative `openForSubmission()`/`loadByOrder()` → resume **or** create Hold→order→attempt | ONE outer txn holds the admission-PK lock across the whole create/resume; commit once. Route/quote resolved upstream (`resolveQuote` L422) — outside the lock |
| C | Payable staff / manual-review door — `ReviewPaymentForm::submitForm` L199 → `startFromApprovedReview` L145 → `open()` L322 → `start` L96 | **`checkout_attempt`** (same seam as B) | **`checkout_attempt`** | same as B; `owner_kind = staff`/`manual` (metadata only — identity unchanged) | same as B |
| C2 | **Staff UNPAID confirm** — `DispatchStaffPlanner::confirmForStaff` L149 → `DispatchPlanner::confirmForStaff` L772 | **`dispatch_reservation`** written **directly** — *no* `checkout_attempt`, *no* order | **`dispatch_reservation`** (NOT checkout_attempt) | `acquire()` then `verify()` `FOR UPDATE` inside the planner's Hold→confirm txn, **before** the calendar guard; `confirm()` post-commit | planner guard txn (Hold under guard → confirm); admission lock first |
| B/C confirm | Payment settlement — `DispatchPaymentProcess`→`confirmPaid` L160 → `confirmHeld` defined L339 (called L195) → `settlePaid()` | `dispatch_reservation` bound to the order | `dispatch_reservation` **and** `checkout_attempt` stamped | `verify()` `FOR UPDATE` first inside `confirmHeld()`'s guard txn; `confirm()`/`requireReconciliation()` at `settlePaid()` (self-committing, post-capture) | guard txn = {verify → calendar guard → bind}; settle is a separate post-capture txn |
| E | Legacy fixed-slot confirm — `AddressDistanceHandler::postSave` L2084 → `DatabaseReservationManager::confirm` L297 | legacy **`_reservation`** (unique `active_slot_key`) | legacy **`_reservation`** | `acquire()` then `verify()` `FOR UPDATE` inside the manager's own txn, before the `_reservation` write; `release()` on legacy release L352/cancel | manager own txn; admission lock first |
| F | Staff ops replan/blackout — `DispatchOperations::replan` L209 / `scheduleBlackout` L562 → `DispatchPlanner::replan` L608 | retires old + writes **new `dispatch_reservation`** | new **`dispatch_reservation`** (new generation after re-arm) | `verify()` `FOR UPDATE`; `release()` old then re-arm bumps generation; new binding carries the new generation | replan txn; admission lock first |
| Cancel | Checkout cancel — `DispatchCheckoutManager::cancel` L209 (Hold release→settle Cancelled→void→order cancel) | frees Hold/order | — | `release()` (evidence-backed) after settlement, self-committing | separate post-settlement txn |
| — | `DispatchPlanner::confirmReservation(Hold, submission)` | — | — | **no production caller** (advisory-intent; the Reservation is born via `confirmCheckoutReservation`) | — |

## 2. Durable identity & bindings (Req 2)

**Owner secret — `submission_id` only.**
```
ownerSecret = hash_hmac('sha256', (string) submission_id, <site-private-salt>)
```
The prior formula folded `owner_kind` into the hash, so a public→staff→manual handoff would
change the derived secret and self-lock the retry to `RefusedOwner`. Dropping `owner_kind`
makes the credential **stable across every handoff**, which is exactly what the store
expects: its `Reused` branch (L96-104) compares owner + intent and **never** reads
`owner_kind`. Re-derivable from a durable input ⇒ a crashed-and-retried request presents the
same owner. The store persists only the sha256 hash (`hashSecret`); the salt lives in
settings/environment, never in exported config. (Alternative mechanism, per §0: a random
`mintOwnerSecret()` secret durably stored in the admission-writing txn — also stable, also
sufficient.)

**`owner_kind` is set-once origin metadata, not identity.** It records which door minted the
admission (`public`/`staff`/`manual`) for audit. Changing the door on a live admission does
**not** change ownership (the secret is `submission_id`-derived; the store ignores
`owner_kind` on `Reused`). A door change is therefore descriptive only; it never re-owns a
booking. `owner_kind` is (re)written only when a row is first inserted or **re-armed** from
released — an explicit generation bump, never a silent handoff.

**Authenticate the acting user separately.** The human/staff actor is authorised by Drupal
**RBAC at the route/form boundary** (permissions on the review-payment form, the dispatch
controllers, the staff-ops routes) — orthogonal to admission ownership, which is a
server-process **exclusivity** credential, not a user identity. RBAC answers "may this user
act"; the admission secret answers "is this the same booking job." Neither substitutes for
the other.

**Durable generation reference — per family, not `checkout_attempt` alone.** Generation on
`checkout_attempt` cannot cover writers that bypass it (C2 unpaid staff confirm and F replan
write `dispatch_reservation` directly; E writes legacy `_reservation`). The
`admission_generation` a binding was minted against must therefore colocate on **each**
family's binding row:

| Binding row | Covers paths | New column |
|---|---|---|
| `custom_webform_handlers_checkout_attempt` | B, C, payment-confirm (B/C) | `admission_generation` (int unsigned, nullable) |
| `custom_webform_handlers_dispatch_reservation` | C2 staff-unpaid, payment-confirm, F replan | `admission_generation` (int unsigned, nullable) |
| legacy `_reservation` | E legacy confirm | `admission_generation` (int unsigned, nullable) |

**Alternative:** one shared binding-ledger table `(submission_id, binding_type, binding_id,
admission_generation)` written in the same txn as each binding — a single home instead of
three columns. Either satisfies "a later re-arm invalidates a stale binding via
`verify()`/`confirm()` `RefusedGeneration`." The admission table DDL itself needs **no**
change.

**Snapshots are not rewritten.** `intent_fingerprint` is sourced one-directionally from the
accepted `AcceptedJob` digest (or snapshot `bindingIdentity`), hashed into the admission row
at `acquire()`. Admission never writes back to `QuoteSnapshot`/`AcceptedJob`.

## 3. Crash / retry matrix (Req 3) — crashes between *each* write

Every retry begins with `acquire()` (classify fresh vs reuse) then, inside the binding txn,
a **locked** authoritative reread of the binding home-of-record under `verify()` `FOR UPDATE`
— never a trust of a receipt, never the unlocked `existingAttempt()` precheck. `Reused` /
already-confirmed **resume or return existing work**; a new effect is authorised only on
`isFreshAdmission()`. No same-owner retry is ever blocked (derived secret, §2); no confirmed
booking is ever released for a payment reason.

| Case | Crash point (state on disk) | Locked reread | Conditional mutation | Compensation |
|---|---|---|---|---|
| 1 | Before `acquire()` — nothing | `find()` = NULL | `acquire()` → `Admitted` gen 1; open binding txn; create | none |
| 2 | After `acquire()`, **before** any Hold/order | admission `Pending` gen N; no attempt | `acquire()` → **`Reused`**; `verify()` `FOR UPDATE`; `openForSubmission()` = none → create Hold→order→attempt under the **held lock** | abandoned `Pending` swept by the decoupled pending-sweep (Class A→failed), never time-released |
| 3a | After Hold, **before** order | Hold exists; no order/attempt | under the lock: Hold reread/reused → create order→attempt | orphan Hold reattached, not re-created |
| 3b | After order, **before** attempt | order exists; no attempt | under the lock: `loadByOrder()` finds order → create attempt only, stamp generation | orphan order reattached (unique keys + lock block a duplicate) |
| 3c | After attempt commit, **before** response | attempt exists; admission still `Pending` | `loadByOrder()` = attempt; `confirmPaid()` idempotent (`hasReservation()` short-circuit); `confirm()` `Pending`→`Confirmed` | none |
| 4 | Existing confirmed booking | admission `Confirmed` gen N; Reservation exists | `find()` = `Confirmed`; attempt `hasReservation()` ⇒ **return existing**, `isFreshAdmission()`=false ⇒ spawn nothing | none |
| 5 | Unknown payment outcome | capture indeterminate | `settlePaid()` cannot confirm capture → `requireReconciliation(reason)` → `ReconciliationRequired` (live, exclusivity retained); Reservation retained, **release forbidden** | operator-only; **no automatic release**; **never infer "nothing charged"** |

**Why the unlocked precheck is insufficient (stated plainly).** `existingAttempt()`
(L126/L265) does two indexed lookups with **no** row lock, so two concurrent same-submission
requests can both see "no attempt" and both create — the unique index is the only backstop
and it fires *after* the wasted work. Holding the admission-PK `verify()` `FOR UPDATE` lock
across the reread-and-create serialises the racers on the admission row: the first creates
and commits, the second (now holding the lock) rereads, finds the attempt, and resumes. That
is why create/resume, not only confirmation, must run under the lock.

**`RefusedGeneration` / `RefusedOwner` are business refusals, not blocks.**
`RefusedGeneration` = a re-arm bumped the generation between mint and confirm; the stale
binding is refused and its attempt failed, the current owner unaffected. `RefusedOwner`
under a `submission_id`-derived secret can only be a genuine cross-owner collision, never
self-inflicted.

**Deadlock/timeout ≠ refusal.** A transient `innodb_lock_wait`/deadlock on a self-committing
call (`acquire`/`confirm`/`release`/`requireReconciliation`) may be retried **once** (never a
silent replay of a caller-owned txn); a transient failure under `verify()` aborts the
caller's guard txn (binding not written) and surfaces as an ordinary "could not hold
capacity" — offer stands, nothing charged.

## 4. Transaction order & physical-driver capacity (Req 4 + Req 5)

**Lock order — admission PK before calendar, one global order, no external call inside a
lock.**
1. `acquire()` is its **own** self-committing txn, taken before any Hold and any calendar
   lock. Route/geocode already resolved upstream (`resolveQuote`, planner) — external I/O is
   outside every lock and stays there.
2. The **binding txn** (in `start()`/`confirmForStaff()`/legacy confirm) opens, then in
   order: **(a)** `verify()` `FOR UPDATE` — locks the admission PK **first**; **(b)** the
   family calendar/uniqueness contention (`ResourceCalendarGuard` `(resource_id, local_date)`
   ascending for dispatch; unique `active_slot_key` for legacy); **(c)** the binding write,
   stamping `admission_generation`. Admission-row-then-calendar is one consistent order
   across **all** callers ⇒ no lock-order deadlock.
3. `confirm()`/`requireReconciliation()` at settlement are self-committing txns taken
   **after** the guard txn commits and **after** capture — the admission lock is never held
   across the external capture call.

**Initial-binding atomic unit (the fix for Req 1).** `{acquire ⇒ Pending}` commits first;
then `{verify FOR UPDATE at gen N → authoritative lookup → resume or (Hold→order→attempt or
reservation) stamping gen N}` runs inside **one** outer txn that holds the admission-PK lock
across every binding write and commits once. Because the binding writes share that
connection, the row lock is held across them until the single commit; a crash at any step
leaves a partial the next locked retry reconciles (§3, cases 2–3c), never a duplicate.

**Physical-driver capacity is an ACTIVATION BLOCKER (not an informational finding).**
Per-`submission_id` admission guarantees *one submission is booked once*; it says **nothing**
about *two different submissions* consuming the *same driver/resource/time*. The two families
write different tables with different contention (`_dispatch_reservation` via
`ResourceCalendarGuard`, **no unique key**; legacy `_reservation` via unique `active_slot_key`)
and **neither gates the other**. Per-Webform family exclusivity (`BookingWorkflowGate`) does
**not** close this: it stops one form's bookings crossing families, not two forms/families
targeting one real resource. **Activation (Batch 6) is therefore refused unless one of these
holds and is verified by gate G-CAPACITY:**
- **(a) Single capacity authority per physical resource** — an enforced invariant that no
  physical driver/slot is addressable by both families (G-CAPACITY **fails** activation if
  any resource appears in both calendars); **or**
- **(b) Shared cross-family capacity protection** — one capacity key/lock spanning both
  writers, so the two families contend on the same row for one physical resource.
Until (a) or (b) is in place and verified, cross-family double-booking is possible and
**activation is blocked**.

## 5. Safe adoption of existing records (Req 3-enroll)

**Live-census coverage limit (recorded, not guessed).** At writing, the DDEV containers were
**not running** (`docker ps` shows no `ddev-ppu-*`); bringing them up is out of scope (and
risks the `ddev`-recreation trap), so a *fresh* count was not taken. The authorized read-only
route (`docker exec ddev-ppu-web` Drush `php:script`, `--uri=https://ppu-qa.ddev.site`) is
carried as gate **G-INV**. Last recorded census (24C preflight, schema 10047→10048):
admission table **empty (0 rows)**. Treatment rules do not depend on the counts; G-INV only
confirms no adoption edge case is live before activation.

**The unlocked precheck is NOT the durable authority.** A caller reread (`loadByOrder`/
`hasReservation`) is at most a fast-path hint; the durable authority is a **locked**
enrollment — `acquire()` then `verify()` `FOR UPDATE` then the authoritative binding lookup,
exactly as a live path — **or** a controlled backfill migration (Authorization B) that
itemises each row under the same lock discipline. A pre-activation confirmed booking is
enrolled (or backfilled) as `Confirmed` at its binding's generation; it is never left to a
naive first-touch `acquire()` that would return `Admitted` and could authorise a second
effect.

| Existing record | Rule at activation (locked) |
|---|---|
| Pending checkout attempt | next mutation's locked `acquire()`+`verify()` enrolls a fresh `Pending`; safe |
| Held (Hold, no Reservation) | locked reread at confirm; owner derived from `submission_id` ⇒ consistent |
| **Confirmed (Reservation exists), no admission row** | **locked enrollment or backfill to `Confirmed`** at the binding's generation **before** activation; a reread short-circuit is a hint, not the authority |
| ReconciliationRequired (checkout) | enroll as `requireReconciliation()`; **preserve** the unknown-payment state; never auto-release |
| Ownership info unavailable | owner is re-derivable from `submission_id` for any row that has one; a binding with `submission_id` NULL (e.g. a pre-submission Hold) **cannot** be enrolled → flag, do not fabricate an owner |
| Read failure while enrolling a row | **abort that row to `ReconciliationRequired`/skip** — **never** infer "nothing charged" or release from a DB error |
| Conflict: one submission in **both** families | impossible under per-Webform exclusivity; if G-INV finds one, **flag for operator review**, never auto-resolve |

**Read-only inventory at G-INV (no writes, no DDL):** `COUNT(*)` on the admission table;
`state`-grouped counts on `checkout_attempt`; held/confirmed counts on the dispatch and
legacy reservation tables; and a join for any submission carrying **both** a dispatch and a
legacy reservation.

## 6 — Change map (gated; nothing applied by this document)

Every entry below is inert until `booking_admission.enforce` is on (§7). `src/Dispatch/Admission/*`
is unchanged — the store is already correct and accepted (VERIFICATION-24C-3.md).

| File | Change | Notes |
|---|---|---|
| `custom_webform_handlers.install` | `update_10049`: add **nullable** `admission_generation` (INT UNSIGNED) to `checkout_attempt`, `dispatch_reservation`, legacy `_reservation`. Field def **frozen inline** (not read from `hook_schema()`); `tableExists`/`fieldExists` idempotent skip + `SHOW COLUMNS` compatibility check; **fails clearly (`UpdateException`)** on a missing prerequisite table or an incompatible existing column. **No backfill, no index, no ledger table.** | source added (Batch 0); **applied on QA only** — `update_10049` ran, QA schema now **10049**, and all three tables (`checkout_attempt`, `dispatch_reservation`, legacy `_reservation`) now carry a nullable `admission_generation`, every value **NULL** (24C-5B census). The **column-add path is therefore runtime-proven on QA**; the **default site `ppu` is NOT migrated (still 10046)**. Still unexercised: the `tableExists`/`fieldExists` idempotent-skip and `SHOW COLUMNS` compatibility branches, both `UpdateException` failure paths (missing prerequisite table / incompatible existing column), and the **fresh-install schema-stamp path** (a fresh install supplies these columns via `hook_schema()`, not this hook — unverified). 24C-5B counts + keyset digests establish **row-count and keyset preservation, not full-row equality** |
| `config/install/…settings.yml` + `config/schema/…schema.yml` | add `booking_admission.enforce` boolean, **default `false`** | single activation gate |
| `custom_webform_handlers.services.yml` | inject `booking_admission_store` into checkout manager, staff/manual planner, legacy manager | wiring only (Batch 1) |
| `src/Commerce/DispatchCheckoutManager.php` | flag-gated: `acquire()`+locked create/resume in `start()` (L96); `verify()` `FOR UPDATE` in `confirmHeld()` (L339) **before** the calendar guard; `confirm()`/`requireReconciliation()` in `settlePaid()`; `release()` in `cancel()` (L209) | external quote I/O stays upstream of the txn |
| `src/Checkout/DispatchCheckoutStarter.php` | thread `owner_kind` + `submission_id`-derived secret through `open()` (L322) via a shared helper; `resolveQuote()` (L422) stays before `start()` | `startFromApprovedReview()` (L145) uses the same helper |
| `src/Dispatch/DispatchPlanner.php` / `DispatchStaffPlanner` / `DispatchOperations` | flag-gated `acquire`/`verify`/`confirm`/`release` around the **direct** `dispatch_reservation` writes: `confirmForStaff()` (planner L772), replan (L608), blackout (L562) | covers the unpaid + ops writers checkout-attempt generation cannot |
| `ReviewPaymentForm.php:199` | set `owner_kind` = `staff`/`manual` **metadata** only | must not change ownership |
| `DispatchPaymentProcess` | `verify()` only (no new binding) | read-side guard |
| `CheckoutAttempt.php` + `CheckoutAttemptStore.php` | carry/read `admission_generation` on the attempt row | binding stamps the generation it was minted at |
| `AddressDistanceHandler.php` + `DatabaseReservationManager` | legacy fixed-slot family, flag-gated | Batch 5 |
| `settings.php`/env salt | site-private salt for the derived secret; **never exported** | keep out of Git/terminal |

## 7 — Gated implementation sequence (Point 4)

**One** activation gate: the default-off `booking_admission.enforce` flag wraps **every** new
call site. There is **no** "observe-only `acquire()`" — the prior draft's observe-only mode
wrote real ownership while ignoring refusals and is removed. A new call site either runs fully
(flag on) or not at all (flag off). No writer changes behaviour until Batch 6.

| Batch | Content | Active by default? |
|---|---|---|
| 0 | `update_10049` schema + `booking_admission.enforce` flag (off) | inert — **applied on QA (schema 10049), all columns NULL**, no caller |
| 1 | service wiring / injection only — **no calls** | inert |
| 2 | initial-binding protection in `start()`/`open()` (flag-gated) | off |
| 3 | confirmation & settlement — `verify()`/`confirm()`/`requireReconciliation()` (flag-gated) | off |
| 4 | staff-unpaid `confirmForStaff` + replan/blackout (flag-gated) | off |
| 5 | legacy fixed-slot family (flag-gated) | off |
| 6 | **activation** — flip `enforce` on, **BLOCKED until G-CAPACITY passes** | the only batch that changes behaviour |

**Contradiction reconciled.** The earlier draft both called the store "inert until Batch 6" and
described enforcement in Batch 3. Both are true only because Batch 3's `verify()`/`confirm()`
calls live **behind the off flag**: they are compiled and wired in Batch 3 but never execute in
production until Batch 6 flips `enforce`. They are exercised only with the flag turned **on in
QA**, never by a default-off environment. "Inert until Batch 6" is therefore the production
statement; Batches 2–5 are code-present, execution-off.

## 8 — Acceptance gates

**Every gate must produce BOTH** (i) an assertion `RESULT: N/N` line and (ii) a genuine,
**preserved** process exit code `0`. `drush php:script` swallows an explicit `exit(N)` (even 0)
→ "terminated abnormally" + `$?==1`; run acceptance under bare `php` / `docker exec ddev-ppu-web
php`, or a tally-then-`exit` wrapper whose exit is preserved. Judge by the `RESULT:` line **and**
the exit code together — this mirrors 24C-3's 39/39 + 19/19 **and** worker EC 0/0.

| Gate | Proves | Blocking? |
|---|---|---|
| G-INV | read-only census (§5) — admission empty, no cross-family conflict | pre-req |
| G-COMPILE | `php -l` clean on every touched file; `drush cr` EC 0 | yes |
| G-INIT | `update_10049` applies idempotently; columns/ledger present, all NULL | yes |
| G-FRESH | first `acquire()` → fresh admission, generation 1, binding stamps it | yes |
| G-REUSE | same owner+intent → `reused`, generation unchanged | yes |
| G-REARM | released → re-armed on incremented generation | yes |
| G-HANDOFF | public→staff→manual keep one stable owner (secret from `submission_id`); `owner_kind` change does **not** move ownership | yes |
| G-RECON | unknown-payment enrolls as `requireReconciliation()`, never auto-released | yes |
| G-CRASH | kill between each binding write (§3 matrix) → locked reread + `Reused` resume, no duplicate | yes |
| G-CONCURRENCY | two sessions, one submission → exactly one fresh admission, other `refused_owner` | yes |
| G-LOCKORDER | admission PK locked **before** calendar/uniqueness; no lock-order inversion under contention | yes |
| G-STAFF | `confirmForStaff` (unpaid, no checkout_attempt) is gated; direct `dispatch_reservation` write carries `admission_generation` | yes |
| G-LEGACY | legacy fixed-slot family gated; stale generation → `refused_generation` | yes |
| **G-CAPACITY** | **single capacity authority per physical resource, or a shared cross-family capacity key, is enforced** — per-Webform exclusivity alone is insufficient | **BLOCKING — activation forbidden until this passes** |
| G-ADOPT | existing pending/held/confirmed/reconciliation rows enrolled/backfilled (§5) with no fabricated owner and no lost payment state | yes |

**Not repeated here:** the store's own 39/39 (sequential gate) + 19/19 (two-session) are
**accepted** at VERIFICATION-24C-3.md and are not re-run by this integration.

Admission remains inactive; Task 24C incomplete and Task 24B PARTIAL.

---

## 24C-5B execution — QA schema application (`update_10049`), 2026-09-26

**Stage authorization.** 24C-5B authorized validating and applying the inactive binding
schema to **QA only**, via a verified backup, Drupal's update mechanism, and a QA cache
rebuild. No callers, activation, config import, fixtures, external APIs, automated suites,
infrastructure changes, memory edits, or commits. Container startup/recreation was not used
(the running `ddev-ppu-*` container was reused). Evidence systems: Graphify BLOCKED
(watch-lock, WinError 32) → narrow direct-source exception renewed; DBHub SUSPENDED
(`dbhub_ro` saturates QA connections) → authorized read-only QA Drush metadata substitute;
GitNexus/Context7 not required (runtime schema application, not static structure or an
external API contract).

**Preflight (all gates PASS).** QA identity confirmed — URI `https://ppu-qa.ddev.site`,
DB `qa`, site `sites/qa` (never the default `ppu`). Pending updates: **only**
`custom_webform_handlers 10049` (`updatedb:status`). Pre-migration module schema **10048**.
Routing effectively zero-cost (`routing_mode: live_cached`, but `IS_DDEV_PROJECT` forces
`development_fixed` at runtime, and the hook itself issues no route request). Enforcement
inert (`booking_admission` absent on QA active config ⇒ disabled — "opt-in and never
inferred"). All three prerequisite binding tables present; admission authority table
`custom_webform_handlers_dispatch_booking_admission` present. The admission `generation`
column is `int(10) unsigned`, so the binding columns' `int unsigned` range matches.

**Source validation + narrow correction.** `update_10049` freezes the field inline
(`int` / `unsigned` / `not null` = FALSE), fails clearly (`UpdateException`) on a missing
prerequisite table, and is idempotent + partial-rerun-safe via `fieldExists`. Its
existing-column compatibility check was strengthened this stage to require — in addition to
base `int` + `unsigned` + nullable — a **NULL default** and the **absence of
`auto_increment` / `generated`** behaviour: a non-NULL default would silently enrol every
existing row, and a generated/auto-increment column is not the plain nullable store a
binding write records into. This changed no behaviour on QA (all three tables lacked the
column → all took `addField`). Fresh-install defs (`custom_webform_handlers.install`
L75 / L110 / L360) confirmed identical to the migration's frozen definition. Gates before
apply: `php -l` clean, YAML valid, `git diff --check` EC 0; host==container source parity.

**Backup (outside Git, contents never printed).** `ddev export-db --database=qa` →
`C:/Users/mo737/qa-24c5b-backup/qa-pre-update10049.sql.gz` (outside the repo working tree),
3,493,897 bytes, `gzip -t` OK, SHA-256
`a4e4ecf0959e826fbac81ff2120f17e220fe329126b8b160d01e4f81b3cdd355`.

**Apply.** `drush --uri=…qa updatedb -y` ran **only**
`custom_webform_handlers_update_10049` → column "added to" `…_checkout_attempt`,
`…_dispatch_reservation`, `…_reservation`; "[success] Finished performing updates." Then
`drush --uri=…qa cr` → "[success] Cache rebuild complete."

**Independent post-migration verification (all PASS).**

| Check | Result |
|---|---|
| QA module schema | **10049** |
| `…_checkout_attempt.admission_generation` | `int(10) unsigned`, NULLABLE = YES, DEFAULT NULL, EXTRA empty |
| `…_dispatch_reservation.admission_generation` | `int(10) unsigned`, NULLABLE = YES, DEFAULT NULL, EXTRA empty |
| `…_reservation.admission_generation` | `int(10) unsigned`, NULLABLE = YES, DEFAULT NULL, EXTRA empty |
| Enrolled rows (`admission_generation IS NOT NULL`) | **0** on all three — every existing row unenrolled |
| Row counts (before → after) | checkout_attempt 0 → 0; dispatch_reservation 6 → 6; reservation 0 → 0 |
| PK identifier digest (before → after) | checkout_attempt `NULL_EMPTY` → `NULL_EMPTY`; dispatch_reservation `3eb0d3c8…e922e89f` → `3eb0d3c8…e922e89f`; reservation `NULL_EMPTY` → `NULL_EMPTY` (unchanged) |
| Admission authority table | COUNT 0 → 0, digest `NULL_EMPTY` (unchanged) |
| Routing | zero-cost — `routing_mode: live_cached` (unchanged), Google allowance unset, `development_fixed` at runtime; no route request issued |
| Enforcement | inactive — `booking_admission` absent on QA active config |
| Cache rebuild / final `git diff --check` | both EC 0 |

**No ledger table, no index, no backfill, and no existing-data repair** were performed — the
additive nullable columns are the entire change, exactly as authorized.

**Status split.**
- **Completed:** 24C-5B — `update_10049` validated (with the narrow compatibility
  strengthening) and applied to **QA only**; QA schema 10049; three nullable `INT UNSIGNED`
  DEFAULT-NULL binding columns present and fully unenrolled; baseline counts / digests and
  admission rows unchanged; routing zero-cost; enforcement inactive; `cr` + `git diff --check`
  clean; verified QA backup retained outside Git.
- **Pending:** caller wiring / activation batches (1–6); the **G-CAPACITY** activation
  blocker; and application of `update_10049` to any non-QA environment (still unapplied on the
  default `ppu` site and on fresh installs until run there).
- **Blocked:** none for this stage.

Admission remains inactive; **Task 24C remains incomplete; Task 24B remains PARTIAL.**

---

## 24C-6A — Batch 1 caller-wiring runtime verification (QA, 2026-09-26)

**Scope.** Runtime confirmation that the Batch-1 injection of
`custom_webform_handlers.booking_admission_store` into the three transaction-owning
consumers is inert wiring only. No source edits this stage (Drush/reads only); no
migration, cache-data change, activation, or commit.

**Execution path (corrects earlier windows).** Prior "docker absent ⇒ containers
unreachable ⇒ runtime blocked" notes are **historical**. The `ppu` DDEV project is
running; `rtk ddev drush --uri=https://ppu-qa.ddev.site …` attaches to the live web
container with no start/recreate. `docker` is off the Git-Bash PATH, so native
`docker exec` is unavailable and `ddev exec` stayed unused; all in-container work ran
through `ddev drush ev`.

**QA identity — all PASS.** URI `https://ppu-qa.ddev.site`; DB `qa`; site `sites/qa`;
schema **10049**; effective routing **`development_fixed`**
(`RoutingModeResolver::effectiveMode()`); enforcement **absent**
(`booking_admission.enforce` = `NULL` ⇒ disabled).

**Source parity — host vs container SHA-256 identical** (mutagen in sync; in-container
`modpath = modules/custom_webform_handlers`, i.e. top-level, not `web/`):

| File | SHA-256 (host == container) |
|---|---|
| `src/Commerce/DispatchCheckoutManager.php` | `9b26774c9df3…46c6` |
| `src/Dispatch/DispatchPlanner.php` | `efed916e027f…2d1f` |
| `src/Reservation/DatabaseReservationManager.php` | `7700efbfd018…7977` |
| `custom_webform_handlers.services.yml` | `2651fdaccd65…e829` |

**Gates — all PASS.** `php -l` (in-container) on the three constructors → exit 0,
"No syntax errors detected" ×3; `Yaml::parseFile(services.yml)` → VALID; `drush cr` →
"Cache rebuild complete" (recompiled the container with the 15/15/4-arg constructors +
appended store arg); `git diff --check` → EC 0.
**Container resolution (wiring proof) — all three constructed; store held, not called.**
Each consumer was resolved through the real QA container and its private
`bookingAdmissionStore` property read by reflection (property read — **no store or
admission method invoked**):

| Service | Class | Injected store |
|---|---|---|
| `…dispatch_checkout_manager` | `DispatchCheckoutManager` | `…\Admission\BookingAdmissionStore` |
| `…dispatch_planner` | `DispatchPlanner` | `…\Admission\BookingAdmissionStore` |
| `…reservation_manager` | `DatabaseReservationManager` | `…\Admission\BookingAdmissionStore` |

**No-mutation recheck (before → after `cr` + resolution — unchanged).**

| Check | Before | After |
|---|---|---|
| `…_checkout_attempt` rows | 0 | 0 |
| `…_dispatch_reservation` rows | 6 | 6 |
| `…_reservation` rows | 0 | 0 |
| Module schema | 10049 | 10049 |
| `booking_admission.enforce` | NULL (absent ⇒ disabled) | NULL |
| Effective routing mode | `development_fixed` | `development_fixed` |

Admission authority table `custom_webform_handlers_dispatch_booking_admission` was **not
accessed** (store never called); its 24C-5B census (COUNT 0) stands.

**Evidence systems + limitations.**
- **Graphify** AST refresh **succeeded**: `graphify update .` EC 0 → 5852 nodes /
  11513 edges / 495 communities (AST-only, no LLM). The 54 zero-node files are the known
  YAML/JSON/Markdown coverage gap, not a failure. The services.yml store-argument edges
  remain **outside Graphify coverage** — the injection is established by direct source +
  host/container hash parity + live container resolution, not a graph edge.
- **DBHub** remains **suspended**; QA Drush metadata was the authorized substitute for all
  schema/row/config reads this stage.
- **Context7** not required this stage (no external framework-behaviour question).

**Result — Batch 1 runtime verified on QA.** The three transaction-owning consumers
construct through the container and hold `BookingAdmissionStore`; no admission table, row
count, schema version, or enforcement flag changed. **Admission remains inactive.** Batch 2
not started. Application to non-QA (`ppu` still schema 10046; fresh installs) and the
**G-CAPACITY** activation blocker remain pending.

Admission remains inactive; **Task 24C remains incomplete; Task 24B remains PARTIAL.**

---

## 24C-7A — Passive admission-generation persistence verification (QA, 2026-10-01)

**Scope.** Runtime verification that the *generation transport* layer — passive carriage of
`admission_generation` through the three binding stores and their real I/O objects —
persists correctly end to end. **Transport only; enforcement is reported separately (below)
and stays absent/off.** No source edits this stage (transport was authored in prior cycles;
all module files remain uncommitted `M`). No migration, backfill, active-config change, live
booking, payment, external API, automated suite, infrastructure change, memory edit, or
commit.

**Execution path.** `rtk docker exec ddev-ppu-web bash -c 'cd /var/www/html && drush
--uri=https://ppu-qa.ddev.site …'` — attaches to the already-running web container with no
start/recreate (`ddev exec` / `ddev drush` can trigger a full container recreation on stale
mutagen; `docker exec` avoids it).

**QA identity (fresh-process reconfirm at closeout — PASS).** DB `qa`; site `sites/qa`;
module schema **10049**; effective routing **`development_fixed`**
(`RoutingModeResolver::effectiveMode()`, fail-closed, no external route request);
enforcement **absent** — `booking_admission.enforce` = `NULL` ⇒ disabled.

### Generation transport — source (uncommitted `M`; this verification's inputs)

Seven files carry the passive transport (`HeldReservation.php` carries the hold token only,
not the generation, so it is not transport-bearing but participates in the legacy
round-trip); the schema column is defined in `custom_webform_handlers.install`
(`update_10049`):

| File | Transport role |
|---|---|
| `src/Reservation/DatabaseReservationManager.php` | legacy `hold(..., ?int $admissionGeneration = NULL)`; `adminList()` conditionally selects + reads the column |
| `src/Reservation/ReservationRecord.php` | 12th readonly prop `admissionGeneration:?int`; `fromRow()` keeps an unselected/NULL column NULL (never 0) |
| `src/Checkout/CheckoutAttempt.php` | 12th ctor param `?int $admissionGeneration`; `opening()` always NULL, `stored()` carries an explicit value |
| `src/Checkout/CheckoutAttemptStore.php` | `open()` range-checks before any write; conflict refusal via idempotency-key reread; INSERT stamps the column only when present + non-NULL; `settle()` generation-free |
| `src/Dispatch/DispatchClaim.php` | 15th ctor param carries the generation into the dispatch store |
| `src/Dispatch/Storage/DatabaseReservationStore.php` | `claim()` range-checks (L214) + missing-column `RuntimeException` (L215-221); INSERT under the calendar guard |
| `src/Dispatch/Storage/LockedHold.php` | 10th ctor param carries the generation on the locked hold |

Range contract (all three stores): `ADMISSION_GENERATION_MIN = 1`,
`ADMISSION_GENERATION_MAX = 4294967295` (the `int(10) unsigned` ceiling); per-store
`assertGenerationInRange(?int)` accepts NULL, throws `\InvalidArgumentException` on
`< MIN || > MAX`. **NULL = unenrolled** — never generation zero, never authorization.

### Runtime evidence (prior-cycle fixture, re-stated; genuine interfaces, zero reflection)

A single fixture drove the **two runtime-exercised** binding stores — **checkout and
legacy** — through their **real non-payment persistence interfaces** inside **one outer
`startTransaction()` rolled back in `finally`**; no reflection-based object mutation was used
as persistence proof. Result: **`ASSERTIONS: pass=35 fail=0` → `RESULT: PASS`**. The
**dispatch store was not driven at runtime in 7A** (its transport was inspection-backed; see
Limitations) and is now runtime-covered by **24C-7B** (below). Behaviours covered:

- **NULL-compatibility + round-trip** — a legacy hold with no generation persists NULL; a
  checkout attempt (explicit generation 5) and a legacy hold (explicit generation 7)
  round-trip the exact value back out.
- **Omitted-value preservation** — ordinary updates (`renewHold`, `confirm`, checkout
  `settle` → Paid) leave an existing generation untouched; an omitted/NULL input never
  clears a persisted generation.
- **Conflicting-value refusal without mutation** — a second checkout `open()` for the same
  idempotency key carrying a *different* non-NULL generation is refused (returns NULL; the
  conflicting order row is never created) while the original row keeps its generation; the
  legacy duplicate-key collision throws and leaves the prior confirmed row (gen 7) unchanged.
- **Invalid-generation rejection** — `0` and `MAX+1` throw `\InvalidArgumentException`
  **before any write** on both legacy and checkout paths; no row is created.

**In-transaction write-landing** (pre-rollback): checkout attempts count 2, legacy
reservations count 2; dispatch `6 / max_pk 22 / keyset 3eb0d3c8813fdf7fe519e81be922e89f`
unchanged; admission authority table count 0 unchanged. (The in-txn checkout `max_pk` 47 is
harmless InnoDB AUTO_INCREMENT advancement from the deliberately-failed collision/invalid
inserts — not a committed row; it reverts to NULL over actual rows after rollback.)

**Independent fresh-process rollback verification** (prior cycle): a separate baseline
process re-run *after* the rollback found all four tables reverted exactly — checkout
`0 / NULL`, dispatch `6 / 22 / 3eb0d3c8813fdf7fe519e81be922e89f`, legacy `0 / NULL`,
admission `0`; identity and config unchanged.

**Closeout-time baseline recheck** (fresh process, post-cleanup, post-`cr`, 2026-10-01):
`IDENTITY db=qa site=sites/qa schema=10049 effmode=development_fixed enforce=NULL`; checkout
`0 / NULL`, dispatch `6 / 3eb0d3c8813fdf7fe519e81be922e89f`, legacy `0 / NULL`, admission
`0 / NULL` — baselines intact, nothing drifted.

### Validation gates — all PASS

| Gate | Result |
|---|---|
| `php -l` (7 transport files + `HeldReservation.php` + `custom_webform_handlers.install`) | 9/9 "No syntax errors detected" |
| YAML parse (`services.yml`, `config/schema`, `libraries.yml`, `info.yml`, `config/install/settings.yml`) | `YAML_LINT: PASS` |
| QA `drush cr` | "Cache rebuild complete." (EC 0) |
| Source parity (host vs container SHA-1, all 9 files) | identical — e.g. `DatabaseReservationStore.php 18df1654…`, `CheckoutAttemptStore.php 661c9a13…`, `install 2257fc81…` |
| Graphify AST refresh (`graphify update .`) | succeeded, EC 0 — 5861 nodes / 11542 edges / 489 communities (AST-only; no watch-lock this run) |
| `git diff --check` | EC 0 (only a benign `.gitignore` LF→CRLF notice — not a whitespace/conflict error) |

### Cleanup

Temp verification scripts `.claude/tmp/task-24c-7a/baseline.php` and `…/verify.php` deleted;
a fresh `ls` confirms the directory is empty. Both were git-ignored scratch tooling; no
repository file was affected.

### Limitations (inspection-only / scope boundaries — not MCP failures)

- **Dispatch store not driven at runtime in 7A — now superseded by 24C-7B.** `claim()` →
  `claimLocked()` wraps its only INSERT in `ResourceCalendarGuard::withLockedDays()`
  (`DatabaseReservationStore.php` L223). In 7A the dispatch INSERT was **not** executed and
  its transport was confirmed by **inspection** (range check L214, missing-column
  `RuntimeException` L215-221, `DispatchClaim` 15th param, `LockedHold` 10th param), so 7A
  runtime coverage spanned **2 of 3** binding tables (checkout + legacy). **Correction to the
  earlier locking claim:** the guard does **not** row-lock the six protected dispatch rows. It
  upserts and `SELECT … FOR UPDATE`-locks rows in the **separate guard table**
  (`custom_webform_handlers_dispatch_guard`) keyed by **(resource_id, local_date)** for the
  supplied resource/date only; the overlap check (`hasReservationConflict()`,
  `DatabaseReservationStore.php` L1569) is a **resource-scoped** read of the reservation table
  (`r.resource_id = :resource`), not a lock on the six protected rows. Because the 24C-7B
  fixtures use a **distinct synthetic resource** (`zzz-synthetic-24c7b`), the guard lock and
  the conflict query never touch the six `driver-01` protected rows — confirmed by the
  fresh-process rollback verifier finding the dispatch table's full-row digest, keyset, and
  count byte-for-byte unchanged (below). 24C-7B now provides the runtime coverage of the third
  binding table.
- **`CheckoutAttempt::opening()` cannot carry a generation** (always NULL by construction);
  the explicit-generation checkout path is exercised through `CheckoutAttempt::stored()`.
- **Missing-column / unmigrated-schema branches are inspection-only.** QA at schema 10049 has
  `admission_generation` on all three binding tables, so the "explicit generation on a schema
  lacking the column → clear failure before writing" (`RuntimeException`) and the "legacy call
  without a generation stays compatible with an unmigrated schema" branches cannot be
  runtime-exercised without altering the schema (forbidden). Both are confirmed by source
  inspection. This is a scope boundary, not a tool failure.
- **DBHub** stayed **SUSPENDED**; read-only QA Drush metadata was the authorized substitute
  for every schema/row/config read.

### Fresh-install wording correction

Distinguish two paths earlier wording blurred:
- **Fresh-schema (new install):** Drupal stamps the module at its current schema version and
  runs **no** update hooks, so `admission_generation` exists on a fresh install only if the
  install-time `hook_schema()` defines it — **not** via `update_10049`. This path is
  **unverified** here.
- **Existing-site update execution:** `update_10049` adds the column to an already-installed
  site. This verification ran against **QA, an existing site already migrated to 10049** — a
  runtime-proven existing-site state, *not* a fresh install.

### Transport vs enforcement (reported separately, per directive)

- **Transport:** PRESENT and VERIFIED — passive carriage of `admission_generation` persists,
  round-trips, preserves on omit, refuses conflicts without mutation, and rejects
  out-of-range values; runtime-proven on checkout + legacy, inspection-confirmed on dispatch.
- **Enforcement:** ABSENT / OFF — `booking_admission.enforce` = NULL. No admission
  acquisition/verification, ownership-secret derivation, booking decision, or
  business-supplied generation value was exercised. **Transport carriage does not imply
  enforcement**, and nothing in this stage activates it.

### Status

Generation-transport persistence is **verified (passive)**. Initial-binding atomicity,
enrollment/backfill of existing rows, and physical-driver capacity (**G-CAPACITY**) remain
**open**. Batch 2 (caller integration behind the default-off `enforce` flag) is **not
started**. Application of `update_10049` beyond QA (`ppu` still schema 10046; fresh installs)
remains pending.

Admission remains inactive; **Task 24C remains incomplete; Task 24B remains PARTIAL.**

---

## 24C-7B — Dispatch generation-transport runtime verification (QA, 2026-10-01)

**Scope.** Completes 24C-7A by driving the **dispatch** binding store at runtime — the one
table 7A left inspection-backed. Passive carriage of `admission_generation` through the real
`DatabaseReservationStore` + `ResourceCalendarGuard` on the same QA connection. **Transport
only; enforcement stays absent/off and is reported separately.** No source edits (the three
editable transport files — `DispatchClaim.php`, `DatabaseReservationStore.php`,
`LockedHold.php` — needed no passive-transport fix; all module files remain uncommitted `M`).
No migration, backfill, active-config change, enforcement activation, live booking, payment,
external API, automated suite, infrastructure change, memory edit, or commit.

**QA identity (fresh-process reconfirm — PASS).** DB `qa`; site `sites/qa`; module schema
**10049**; effective routing **`development_fixed`**; enforcement **absent**
(`booking_admission.enforce` = `NULL`). Four exercised source files host==container SHA-1
identical (`DatabaseReservationStore 18df1654…`, `DispatchClaim aa8a7159…`, `LockedHold
5577bb18…`, `ResourceCalendarGuard cd6c0d48…`).

**Fixture isolation.** One unused synthetic resource `zzz-synthetic-24c7b` + local date
`2027-02-02`, verified absent from reservations, guards, blackouts, and resource-status
before any write (the only existing resource is `driver-01`). Three **non-overlapping**
windows (09:00 / 11:00 / 14:00 local America/New_York, each +1800 s; inbound = outbound = 0 ⇒
`guardDates()` = `['2027-02-02']` ⇒ **one shared guard row**). ≤3 dispatch rows + 1 guard row,
all through the real store/guard, inside **one outer `startTransaction()` explicitly
`rollBack()`-ed in `finally`** (the guard's internal `startTransaction()` nests as a savepoint
under it). The store received an in-memory `\Psr\Log\AbstractLogger` via normal DI; the guard
takes no logger.

### Runtime evidence — `ASSERTIONS: pass=31 fail=0` → `RESULT: ALL-PASS`

Genuine interfaces only; zero reflection. Synthetic reservation ids A=69, B=70, C=71 (one
shared guard row). Behaviours covered:

- **Omitted generation persists as NULL (Row A).** `claim()` with generation NULL held;
  `heldForPlanning()` read `admissionGeneration === NULL` (never 0).
- **Explicit generation round-trips (Row B, gen 7).** `claim()` held; `heldForPlanning()` read
  exactly `7`.
- **Supported maximum round-trips (Row C, gen 4294967295).** `claim()` held;
  `heldForPlanning()` read exactly `4294967295`.
- **`confirmWithRevalidation()` hands the exact persisted generation to the callback.** The
  `LockedHold` passed to the revalidation closure carried `admissionGeneration === 7` and the
  synthetic reservation id; confirmation was returned `Available` **only** for the synthetic
  fixture id (fail-closed `SlotUnavailable` otherwise). Row B confirmed; the stored generation
  stayed `7` through the confirm (observed state `confirmed`).
- **Omitted-value preservation on renew/release.** `renew()` and `release()` left Row A's NULL
  and Row C's MAX generation untouched (re-read by `dispatch_reservation_id`, since `release()`
  nulls the token/owner hashes); Row A observed state `released`.
- **Invalid generation rejected before any write.** `0` and `MAX+1` (4294967296) each threw
  `\InvalidArgumentException` at the pre-lock range check (`assertGenerationInRange()`, L214).
  Source inspection confirms that guard runs before the method reaches any INSERT or the
  guard/lock path, so no write statement for the rejected value is reached; row observation
  afterward found the synthetic reservation and guard counts both still `0`. Together these
  evidence that no row for the rejected generation persisted — **not** "zero SQL executed,"
  which a surviving-row count alone cannot establish (a statement can run and be rolled back
  while leaving an identical count).
- **Overlapping claim refused without changing the original binding — capacity-conflict
  evidence, NOT generation-specific enforcement.** With Row C held, a valid overlapping claim
  (14:10–14:40, generation 3) was refused `SlotUnavailable`; no 4th synthetic reservation was
  written and Row C's full row (bracketed before/after) was unchanged. The refusal comes from
  the **resource-scoped** overlap check, independent of any generation value.

**Logger:** `LOGGER_RECORDS=0` — the `SlotUnavailable` overlap path and the successful
revalidation path emit no log; nothing was written to dblog.

**In-process post-rollback recount:** synthetic reservations `0`, synthetic guard rows `0`.

**Main-probe exit code — not recorded.** This section records the main 31-assertion driver's
`RESULT: ALL-PASS` assertion line but did not retain its preserved process exit code. Per the
§8 gate rule a genuine exit `0` is required alongside the RESULT line; for the main probe that
exit code is **unrecorded here and must not be inferred from the 31/0 assertion total**. The
only genuine, preserved exit code captured in 7B is the independent cleanup verifier's
`GENUINE_EXIT=0` (below), whose path forces a non-zero exit on mismatch.

### Independent cleanup proof (fresh process, `SNAP_MODE=verify`)

A separate drush process re-ran the baseline verifier **after** the rollback across all five
tables (checkout, dispatch, legacy, admission, calendar-guard), comparing **count, keyset
SHA-256, and full-row SHA-256** plus synthetic absence:

- `VERIFY_PASS=19 VERIFY_FAIL=0` → `RESULT=CLEAN`; **`GENUINE_EXIT=0`** (the verifier throws an
  uncaught `\RuntimeException` on any mismatch, which would force a non-zero exit — it did
  not).
- All five tables returned to **identical final contents** — matching count, keyset SHA-256,
  and full-row SHA-256 against baseline (dispatch count 6 with both digests unchanged ⇒ the six
  protected `driver-01` rows at rest are untouched); synthetic absence
  `reservation / guard / blackout / status = 0`. Matching digests establish that the **resting
  contents equal baseline**, not that no transient lock was taken or no row was written and
  rolled back during the probe — a digest sampled after commit/rollback cannot distinguish a
  value that was never written from one written and then reverted.
- **AUTO_INCREMENT advancement (reported, not asserted):** dispatch `69 → 72` (Δ 3, the three
  synthetic inserts), guard `53 → 54` (Δ 1, the one shared upsert). InnoDB does not roll
  counters back; this is counter advancement over **zero surviving rows**, not survival —
  distinct from the row digests, which matched exactly. Counters were **not** reset.

**Temporary diagnostics removed** from host and container (`inspect.php`, `snapshot.php`,
`probe.php`, `baseline.json` under `.claude/tmp/task-24c-7b/`); the directory no longer exists
on either side.

### Validation gates

| Gate | Result |
|---|---|
| `php -l` (`DispatchClaim.php`, `DatabaseReservationStore.php`, `LockedHold.php`) | 3/3 "No syntax errors detected" |
| Source parity (host vs container SHA-1, all 3 editable files) | identical — `DispatchClaim.php aa8a7159…`, `DatabaseReservationStore.php 18df1654…`, `LockedHold.php 5577bb18…` |
| `git diff --check` | EC 0 (no whitespace/conflict errors) |
| QA `drush cr` | "Cache rebuild complete." (EC 0) |
| Graphify AST refresh | **not required** — no source edit this stage (no passive-transport defect demonstrated); graph still reflects the unchanged tree |
| YAML validation | **n/a** — no YAML changed this stage |
| DBHub | **SUSPENDED** — authorized read-only QA Drush metadata used throughout |

No source edits were made: the three editable transport files needed no fix, so these gates
confirm the working tree is unchanged rather than re-verifying a change.

### Limitations (inspection-only / scope boundaries — not MCP failures)

- **Missing-column / unmigrated-schema branch stays inspection-only.** QA at schema 10049 has
  `admission_generation` on the reservation table, so the explicit-generation-on-a-missing-
  column `RuntimeException` (`DatabaseReservationStore.php` L215-221) cannot be
  runtime-exercised without altering the schema (forbidden). Confirmed by source inspection.
- **Fresh-install path stays unverified.** This ran against QA, an **existing site** migrated
  to 10049; whether a brand-new install carries the column depends on install-time
  `hook_schema()`, not `update_10049`, and is not exercised here.
- **`CheckoutAttempt::opening()` carried no generation at 7B time (always NULL by
  construction).** `stored()` verification does **not** prove normal creation-path integration —
  retained as a **Batch 2 prerequisite**. *(Updated by 24C-8A below: `opening()` now accepts an
  optional trailing `admissionGeneration` (default NULL), so the creation path is now capable of
  carrying a generation. No caller supplies one yet, so runtime creation-path integration remains
  the open Batch 2 prerequisite — the signature change alone does not discharge it.)*
- **Initial-binding atomicity, enrollment/backfill, and physical-driver capacity
  (G-CAPACITY)** remain **open**; Batch 2 caller integration is **not started**.

### Transport vs enforcement (reported separately, per directive)

- **Transport:** PRESENT and VERIFIED — passive carriage of `admission_generation` on the
  **dispatch** store persists, round-trips (NULL / 7 / MAX), preserves on
  confirm/renew/release, rejects out-of-range values before any write, and refuses an
  overlapping claim on resource-capacity grounds without mutating the original. With 7A this
  completes runtime transport coverage across **all three binding tables** (checkout, legacy,
  dispatch).
- **Enforcement:** ABSENT / OFF — `booking_admission.enforce` = NULL. No admission
  acquisition/verification, ownership-secret derivation, booking decision, or
  business-supplied generation was exercised. **Transport carriage does not imply enforcement.**

### Status

Dispatch generation-transport persistence is **verified (passive)**. Initial-binding
atomicity, enrollment/backfill, and **G-CAPACITY** remain **open**; Batch 2 is **not started**.
Application beyond QA (`ppu` still schema 10046; fresh installs) remains pending.

Admission remains inactive; **Task 24C remains incomplete; Task 24B remains PARTIAL.**

---

## 24C-8A — Batch 2 initial-binding contract and creation-path preparation

Contract resolved; creation-path signature prepared. **Enforcement NOT activated.** Batch 2
caller integration and runtime atomicity remain unverified. The one source change in this task
is the `CheckoutAttempt::opening()` signature (§H); everything else here is a forward contract
or an in-place correction to the sections above.

### A. Canonical admission intent (one derivation, both doors)

- **One derivation, selected.** The admission intent fingerprint is the material-INPUT digest
  `AcceptedJob::fromSubmissionData($submission->getData(), $origin, $destination)->digest`, which equals
  `QuoteSnapshot::jobFactsDigest` (the snapshot stores the accepted job's digest in that property —
  `QuoteSnapshot.php`). It is `hash('sha256', VERSION . '|' . $factsJson)` over normalized endpoints +
  `AddressDistanceHandler::QUOTE_INPUT_FIELDS` (`AcceptedJob::digestForFactsJson()`), versioned by the
  `AcceptedJob` VERSION prefix. It names *what* is booked, not *who* books it — not an authenticator.
- **Three identities, NOT interchangeable.** The earlier "(equivalently … `bindingIdentity()`)" wording
  is **withdrawn**: `jobFactsDigest` (material inputs), `QuoteSnapshot::snapshotId` (sha256 of six pricing
  *output* fields), and the composite `QuoteSnapshot::bindingIdentity()` = `'v3:' . snapshotId . ':' .
  jobFactsDigest` are three distinct values, and `CheckoutAttempt::intentKey()` is a fourth
  (`sha256(submissionId|webformId|resourceId|startTimestamp|snapshotId[|jobFactsDigest])`,
  `CheckoutAttempt.php` L190-207). The admission fingerprint is **only** `jobFactsDigest`; the Hold /
  Reservation binds the composite `bindingIdentity()`; the attempt's idempotency binds `intentKey()`.
  They move independently (matrix below), so none may be substituted for another.
- **Compare persisted-accepted vs recomputed-current.** The locked admission decision recomputes
  `jobFactsDigest` from the *current* saved submission and compares it against the digest **persisted** on
  the existing binding's accepted snapshot — not two recomputations and not two live reads. The confirm
  path already does exactly this comparison, carrying `acceptedJobFactsDigest` + `acceptedSnapshotId` into
  revalidation (`DispatchPlanner::confirmCheckoutReservation()` L426-428, `confirmFor()` L442+).
- **Both doors derive it identically.** Public checkout (`DispatchCheckoutStarter::start()`) and
  approved-review entry (`startFromApprovedReview()`) read the saved submission through the same factory,
  so the recomputed fingerprint is identical across doors.
- **Independent of staff / session / door.** `owner_kind` (`public` / `staff` / `manual`) is set-once
  origin metadata, never folded into the identity or the ownership secret.
- **Identity "missing" is NOT fail-closed at the factory (correction).** The claim that "insufficient
  material facts yield no digest" is **withdrawn**. `AcceptedJob::fromSubmissionData()` **skips** missing
  (`!array_key_exists`) and empty (`'' | []`) fields and still returns `new self(digest, $facts)`; it fails
  closed only on an unrepresentable value shape or a JSON-encode failure. So a digest existing does **not**
  prove the material facts are complete. Completeness is a **separate caller gate** before admission: today
  `DispatchCheckoutManager::start()` refuses with `IncompleteFacts` when
  `DispatchRequestFactory::fromSubmission()` returns NULL (L124-130), and that factory-level normalization —
  not `AcceptedJob` — is the field-completeness authority. Admission must require that gate to have passed;
  it must not infer completeness from the mere existence of a digest.
- **Change matrix (one fact changes at a time).**
  - *Price only* (rate-table / pricing-output change, same material inputs): `jobFactsDigest` **unchanged**
    (same admission intent); `snapshotId`, `bindingIdentity()`, and `intentKey()` **change**.
  - *Arrival time only*: arrival is **not** an `AcceptedJob` input, so `jobFactsDigest` **unchanged** (same
    admission intent); `intentKey()` **changes** (it folds `startTimestamp`). Two arrival times for the same
    material job therefore share one admission fingerprint — admission exclusivity is per material job (and,
    by table PK, per submission), not per arrival.
  - *Resource only*: `resourceId` is **not** an `AcceptedJob` input, so `jobFactsDigest` **unchanged**;
    `intentKey()` **changes** (it folds `resourceId`).
  - *Material job* (address / package / handling / service — a `QUOTE_INPUT_FIELDS` member): `jobFactsDigest`
    **changes** ⇒ new admission intent; `bindingIdentity()` and `intentKey()` change too. A changed intent is
    a **new** intent, never a silent continuation of the old binding (see §D, §E rows b/d).

### B. Ownership secret (what proves *who* booked)

- **Design (primary): submission-only HMAC.** Ownership proves *who* booked, kept separate from identity.
  The selected construction is `hash_hmac('sha256', (string) $submissionId, <site salt>)` — keyed on
  `submissionId` only, with `owner_kind` deliberately dropped from the secret (§2). It is self-contained:
  no global pepper, derivable by any door for the same submission, and never shown, logged, or published.
- **What the store actually does (secret-source-agnostic).** `BookingAdmissionStore::acquire(int $submissionId,
  string $ownerSecret, string $intentFingerprint, string $ownerKind)` (L77) **receives** the owner secret from
  its caller; it neither generates nor durably preserves one. It stores only `hashSecret($ownerSecret)` =
  `hash('sha256', $ownerSecret)` (L469-471 — a plain SHA-256 of whatever secret it was handed, **not** itself an
  HMAC) and compares with `ownerMatches()` = `hash_equals(...)` (L476-478). Because it hashes the caller's secret,
  the submission-only HMAC above is fully compatible: the caller computes the HMAC and passes it in as
  `$ownerSecret`, and only its SHA-256 lives in the row. The cleartext never reaches the store.
- **`mintOwnerSecret()` is NOT this design's source.** `mintOwnerSecret()` = `bin2hex(random_bytes(32))` (L70-72)
  exists as a helper, but **`acquire()` never calls it**. A random per-acquire secret would not be recoverable by a
  later request and so could not satisfy the submission-only, door-independent requirement. Its existence must not
  be read as evidence that `acquire()` mints or durably keeps a recoverable random credential — it does neither.
- **Ownership ≠ authorization.** `owner_kind` (public/staff/manual) is origin metadata, and actor authorization
  (RBAC) is enforced elsewhere; neither is folded into the ownership secret or the admission identity.
- **Rotation / loss (explicit recovery concern).** Rotating the site salt or losing the stored hash makes
  `ownerMatches()` fail ⇒ `RefusedOwner`. The admission is then **stranded live**: neither matched nor silently
  re-minted, and ordinary `release()` **cannot** recover it, because `release()` → `transition()` itself gates on
  owner-match + generation (L278-306, owner check L294-296) — the very credential that is now unavailable. Recovery
  is operator reconciliation, not `release()`. A rotation withdraws ownership by design; it never transfers it.

### C. Authoritative binding lookup (locked), and what is *not* proof

- **Locked discovery, and the limit of what the lock guarantees.** Under the writer transaction (§F) the
  binding is discovered by the store's own lookups — `CheckoutAttemptStore::loadByIntent()`,
  `openForSubmission()`, `loadByOrder()` — read while the admission PK row lock is held. The admission-row
  lock **serializes participating writers**: any competing writer that also takes that row's `FOR UPDATE`
  blocks until this transaction ends, so a binding another such writer would create cannot appear between
  this read and this decision. It does **not** automatically freeze other tables, nor make an ordinary
  (unlocked) lookup authoritative — a reader that never takes the admission lock, or a table no
  participating writer routes through it, is unaffected. The read is authoritative only because a
  participant performs it under the held lock that every competing writer must also serialize against, not
  because the lock froze the rows.
- **Discovery is order-id-keyed, not submission-keyed.** `CheckoutAttemptStore::loadByOrder(int $orderId)`
  (L416) resolves an attempt from an **already-known order id**; it does **not** discover an order from a
  submission id. `openForSubmission(int)` (L527) finds only a **PENDING** attempt (DESC, LIMIT 0,1 — not
  settled ones); `loadByIntent()` (L510) finds pending+paid, not cancelled/failed. There is **no**
  submission→order index in the attempt store, so a *committed order that never received an attempt* cannot
  be discovered from a submission id by any existing lookup. The order's `dispatch_submission_id` metadata is
  a per-order reverse handle, usable only once the order id is already in hand.
- **Order without an attempt.** A real state (order committed, attempt write not committed). Where it is
  reachable (the order id is known) it is handled as "existing binding, needs reconciliation," never as "no
  binding." Where only a submission id is in hand, discovering such an order is **unresolved** (see blocker
  below) and must be treated as reconciliation, not asserted as absence.
- **Not authoritative proof.** Session receipts (remembered hold ids, cart effects) and any **unlocked**
  precheck (`existingAttempt()` / the unlocked `loadBy*` used for UX) are advisory. A `hydrate()` that returns
  NULL on an incoherent row (logged warning) is **"unreadable / unassociable," not "absent."** Absence is only
  what a locked, successful read authoritatively establishes.
- **CONCRETE BLOCKER — live Hold not associable by submission (unresolved).** A **Hold** is inserted with
  `submission_id = NULL` (`DatabaseReservationStore`, L271) at `state = 'held'`, and only a **confirmed**
  transition writes `submission_id` (L374-375 / L533-534). `liveClaimForSubmission(int)` keys on
  `r.submission_id` (L1486), so it can match a *confirmed* reservation but **never a live Hold** (whose
  `submission_id` is NULL). There is therefore no existing mechanism to associate an unlapsed Hold with its
  submission at the locked lookup. Per the R1 contract this is **marked unresolved**, not closed by mandating
  schema: the smallest supported resolution must be assessed against existing relationships first (e.g. reaching
  the Hold via a known order/attempt, or via the owner session receipt) **before** any new submission-addressable
  Hold column is proposed. No schema change is made or required here, and absence of such a lookup is **not** proof
  that no Hold exists (§E row d).

### D. Reuse vs changed-intent vs different-owner (three distinct cases)

- **Reuse (same owner + same intent).** Same ownership secret (`ownerMatches()` true) and same
  intent digest (§A). This is the double-click / retry case: resume the existing binding through its
  permitted state rules; never mint a second order. The idempotency key (which excludes generation)
  is what makes the second request collide onto the first.
- **Changed intent (same submission, different material facts).** `ownerMatches()` may still be true,
  but the digest differs. This is **not** reuse — it is a new intent. It does not resume the prior
  binding and does not silently overwrite it; it is routed by §E (row b vs row d) under the existing
  permitted-state rules.
- **Different owner.** `ownerMatches()` false ⇒ `RefusedOwner`, regardless of intent. A non-owner never
  resumes or replaces another owner's binding.

### E. Decision table (locked verification outcomes)

| # | Observed state (under lock) | Action |
|---|---|---|
| a | Pending admission, **authoritatively established absence of relevant binding artifacts** (a locked, successful read found none — distinct from an unreadable / unassociable / partial / corrupt read, which is **not** absence and routes to row d) | Initial creation **may proceed** after the locked reread confirms absence — **whether admission acquisition was `Fresh` or `Reused`** (`Reused` still creates the *initial* binding; "fresh-only creation" is retired, see §0). |
| b | **Coherent matching binding** (same owner, same intent, consistent artifacts) | **Resume only** through the existing permitted state-transition rules. Create nothing new. |
| c | Existing binding with **NULL admission generation** | Enrollment requires **separately authorized** action. Do **not** duplicate the binding and do **not** silently stamp a generation onto it. |
| d | **Stale / conflicting generation**, or **inconsistent artifacts** (e.g. order without attempt, changed intent, unassociable live Hold per §C) | **Refuse or reconcile without replacing** the existing artifacts. Never delete-and-recreate to force coherence. |
| e | Admission already **Confirmed** or in **reconciliation** | **No new booking effects.** Do not open a new attempt, Hold, order, or cart effect. |

### F. Transaction design (lock order, shared connection, nontransactional audit)

1. **Prepare route facts first, outside all locks.** `DispatchPlanner::preparePlanFor()` (the pre-lock
   preparation already present) resolves route cost/feasibility **before** any admission or calendar
   lock is taken, so slow route work never runs inside a lock window.
2. **Acquire admission separately.** `BookingAdmissionStore::acquire()` is **self-committing** (its own
   transaction) and returns an `AdmissionResult` whose `isFreshAdmission()` distinguishes `Fresh` from
   `Reused`. Acquisition is a distinct step from verification.
3. **Verify under the writer transaction, admission-PK lock first.** The binding write opens the
   writer transaction, then calls `BookingAdmissionStore::verify()`, which asserts it is in a
   transaction (`assertInTransaction()` → `\LogicException` if not), takes `FOR UPDATE` on the
   admission PK, and opens no transaction of its own. The admission row lock is acquired **before** the
   calendar/guard uniqueness lock and **retained** across the authoritative reread (§C) and the binding
   persistence. **Admission-before-calendar lock order is preserved.**
4. **Shared connection and what actually rolls back (atomicity audit, corrected).** The admission store, the
   checkout-attempt store, the dispatch-reservation store, and the calendar-guard table all write through the
   **same injected Drupal `Connection`** — all four services take `'@database'`
   (`custom_webform_handlers.services.yml` L327 / L639 / L309), the site's default connection — so under one
   outer transaction they nest as savepoints and roll back together.
   - **Correction — Commerce saves *are* transactional on that connection.** The earlier "not transactional
     relative to that connection" claim is **withdrawn**. `$order->save()` / `$item->save()` run through
     `SqlContentEntityStorage::save()`, which **opens a database transaction**
     (`$this->database->startTransaction()`, core `SqlContentEntityStorage.php` L805) and rolls it back on
     exception (L813-814). Commerce's `OrderStorage::save()`
     (`modules/contrib/commerce/modules/order/src/OrderStorage.php` L177-185) only wraps `parent::save()` in a
     try/finally that releases an update lock; it opens **no** transaction of its own, and
     `CommerceContentEntityStorage` does **not** override `save()` — both inherit the core transaction. Entity
     storage uses the default `database` connection, i.e. the **same** connection as the stores above, so an
     order/item save invoked **inside** the outer writer transaction nests as a savepoint and participates: an
     outer rollback unwinds its rows too.
   - **Four distinct effects, only some of which rollback touches.**
     (a) *Same-connection DB writes* (order base/data/revision/field + order-item tables, admission, attempt,
     reservation, guard): participate via savepoint nesting; unwound by an outer rollback.
     (b) *Previously committed artifacts* (an order / Hold committed in an **earlier** transaction or request):
     outside this transaction's reach — these are the genuine reconciliation candidates, not rollback material.
     (c) *External / non-DB hook effects*: not undone by a DB rollback — e.g. the replica kill-switch
     (`SqlContentEntityStorage::save()` L809), Commerce entity events (`CommerceContentEntityStorage::invokeHook()`
     L55-62), and the **ORDER_PAID** event dispatched from `OrderStorage::doOrderPreSave()` on a non-paid→paid
     transition (L137-146). The draft-creation path does **not** pay, so ORDER_PAID does not fire there; it is a
     confirm-time concern, not a draft-creation one.
     (d) *Post-rollback in-memory state*: after a rollback the PHP `$order` object still carries its `id()` and
     fields, so it "looks saved" though its rows are gone — code must not treat the in-memory object as proof of a
     durable row.
   - **Nested ≠ committed.** Completing (destructing) a nested transaction releases its savepoint but does **not**
     commit; only the **outermost** transaction's commit makes any of (a) durable. Therefore an uncommitted order
     written inside the same outer transaction is **not** automatically a durable orphan requiring reconciliation —
     an outer rollback removes it. A durable orphan arises from (b) or (c), or from a code path that commits the
     order in its own transaction before the rest.
   - **Current code uses compensating undo, not a shared transaction.** `DispatchCheckoutManager::start()` runs the
     steps sequentially — Hold (L137), order (L145), attempt (L151) — and undoes earlier steps explicitly on a later
     failure (`releaseHold()` L147; `discard()` + `releaseHold()` on a failed/duplicate attempt, L315-316 /
     L324-325). The single-outer-transaction atomicity described here is the **Batch-2 target**, not today's
     behavior; the audit point is to move those entity saves inside the writer transaction (so (a) applies to them)
     while handling (b)/(c)/(d) deliberately. Holding the admission-row lock alone does **not** freeze these other
     tables — atomicity comes from the shared outer transaction, not from the lock.
5. **Publish side effects only after commit.** Session hold receipts (`owner->rememberHold()`) and cart
   effects are published **after** a successful commit, never before — so a rolled-back attempt leaves
   no remembered receipt (rollback ≠ orphan), while a previously **committed** order/Hold or a
   nontransactional entity save is a real artifact to **reattach**, not to recreate (§0).

### G. `DispatchPlanner::createCheckoutHold()` — describe, do **not** implement

- **Why wrapping is insufficient.** Today `createCheckoutHold()` (L243-265) → `createHoldFor()`
  (L309-414) runs, in one flow: `preparePlanFor()` (pre-lock, L369), then
  `store->claimWithRevalidation(new DispatchClaim(...), $replanUnderLock)` (L381), then
  `owner->rememberHold()` (post-commit, L409). Simply wrapping that whole call in an admission
  transaction would (i) hold the admission lock across the **pre-lock** route preparation, defeating
  step F1 and widening the lock window; (ii) take the calendar/guard lock inside
  `claimWithRevalidation` **before** an admission lock the wrapper only opened around the outside,
  which cannot guarantee the F3 admission-PK-first order; and (iii) leave `rememberHold()` to publish a
  receipt whose commit boundary the wrapper does not actually control.
- **Smallest separation using existing structures (future task).** Split the method along the seams it
  already has:
  1. **Prepare** — call `preparePlanFor()` and build the `DispatchClaim`/`$replanUnderLock` closure
     with no lock held (unchanged work, just hoisted out of the locked region).
  2. **Claim under lock** — open the writer transaction, run `verify()` (admission PK `FOR UPDATE`
     first), then `claimWithRevalidation(...)` (calendar/guard uniqueness) inside the **same**
     transaction, so the two locks nest in the F3 order and the authoritative reread sees the locked
     state.
  3. **Post-commit** — move `rememberHold()` / cart effects strictly after the commit returns.
- **Not implemented in 24C-8A; superseded by 24C-8B.** As of 24C-8A this was forward design only — the
  refactor was **not** performed and `createCheckoutHold()` was unchanged on disk. The "why wrapping is
  insufficient" bullets above (and their L243-265 / L309-414 / L369 / L381 / L409 references) therefore
  describe the **pre-24C-8B** shape and are retained as historical evidence; the live line map has since
  moved. See the 24C-8B update below.

**24C-8B update — phase separation implemented (no transaction wrapper).** The three operations are now
split in `DispatchPlanner` and declared on the `@internal` `DispatchCheckoutPlannerInterface`
(`prepareCheckoutHold()` → `claimPreparedCheckoutHold()` → `publishCheckoutHoldReceipt()`), carried by one
small request-scoped `PreparedHold` value object. This is **only** the phase separation the governing task
authorized; the admission transaction wrapper, `verify()` / `acquire()` wiring, and generation stamping are
**deliberately not** added here.

- **Preparation** (`prepareCheckoutHold()`) runs the same eligibility / owner / canonical-slot /
  accepted-quote gates and `preparePlanFor()` route I/O **before any lock**, and fixes the checkout-only
  final-route estimator **server-side** (never a parameter), so a public endpoint cannot reach the final
  chain by supplying a flag or a fabricated prepared value. It claims nothing and publishes nothing; the
  `PreparedHold` it returns is server-only — never returned from a controller, serialized, or attached to
  drupalSettings.
- **Claim** (`claimPreparedCheckoutHold()`) consumes the `PreparedHold` and runs `claimWithRevalidation()`
  under the calendar/guard lock with a **provider-free** locked reread — a neighbour needing a leg absent
  from the prepared facts **refuses rather than fetching** under the lock. It returns the existing
  `HoldResult`, publishes no receipt and no cart effect, and neither owns nor commits any outer transaction.
- **Receipt publication** (`publishCheckoutHoldReceipt()`) is the explicit post-success step
  (`rememberHold()`); a future transaction-owning caller invokes it **only after its outermost commit**.
- **Compatibility preserved.** `createCheckoutHold()` remains a wrapper composing the three in the existing
  order with the existing outcomes; `createHoldFor()` is recomposed over the same three private helpers, so
  the Visitor (`createHold()`) and Staff booking paths are behaviour-preserved. The sole manager caller
  (`DispatchCheckoutManager::start()`) is unchanged.
- **Reframed remaining problems.** Verifying admission **before** calling the planner can establish the
  admission-before-calendar ordering; the remaining problems the phase separation removes are
  **provider-capable preparation** (route I/O now hoisted out of the lock window) and **premature receipt
  publication** (now a separate, deferrable step). This separation is **not** itself proof of post-commit
  atomicity or concurrency safety.
- **Forward sequence** (replaces the earlier "wrap `start()` now"): **phase separation (done, 24C-8B) →
  deliberate transaction integration → bounded atomicity verification.**

### H. Creation-path signature preparation (the one source change)

- **Change made.** `CheckoutAttempt::opening()` now accepts an optional trailing
  `?int $admissionGeneration = NULL`, forwarded by name (`admissionGeneration: $admissionGeneration`) to the
  constructor, which carries it **passively** — the constructor performs no validation of the generation and
  derives nothing from it (promoted-property carriage only). Range admissibility is enforced solely at the row
  write (`CheckoutAttemptStore::assertGenerationInRange()`, MIN 1 / MAX 4294967295, NULL always allowed),
  **not** on the object. (The earlier "validating constructor" wording is **withdrawn**; no object-level
  validation was or should be added to match it.) Docblocks on `opening()` and on the constructor
  `$admissionGeneration` parameter describe this passive carriage.
- **Invariants preserved.** `intentKey()` / idempotency derivation is unchanged (generation is **not**
  folded in). `stored()` and all `QuoteSnapshot` / `Slot` semantics are unchanged. Generation-range
  validation still lives **only** in `CheckoutAttemptStore::assertGenerationInRange()` (MIN 1, MAX
  4294967295) — `opening()` performs no range check, by design (a value object cannot know its target
  schema).
- **No activation.** No caller supplies a generation in this task; the sole live caller
  (`DispatchCheckoutManager::recordAttempt()`, L306) still calls the 5-argument form and is unchanged.
  No admission-store calls, config keys, schema changes, or new orchestration were added.

### I. Object-only verification (24C-8A, Step 5)

**Vehicle.** A pure value-object probe run inside the already-running container via
`drush php:script` (needs the module autoloader; `php:script` swallows the process exit code, so
the probe is judged by its printed `RESULT:` line, with no explicit `exit()`). It builds one valid
`Slot('hg','resource_1',1735732800,'UTC')` and one legacy `QuoteSnapshot::fromStored('objprobe…',
1000,'USD','rate-v1')` (versionless, no-new-format branch → `hasJobFactsIdentity() === FALSE`), then
opens an attempt for every admission-generation variant. No orders / Holds / payments / reservations,
no business-table writes, no external requests; the Drush bootstrap performs only cache/config reads.
Runtime: **PHP 8.4.22, 64-bit** (`PHP_INT_SIZE=8`), so `4294967296` is a genuine `int`, not a float.

**Independent derivation.** `CheckoutAttempt::intentKey($sid,$slot,$quote)` computed outside `opening()`
= `781c80cef95627b61c44f9c6af88888ecbf5d556e98907c9f2b4f865f157cc4a`.

| generation argument | opened? | `admissionGeneration` stamped | `idempotencyKey` |
| --- | --- | --- | --- |
| omitted (5-arg call) | yes, no throw | `NULL` | = independent derivation |
| `NULL` | yes, no throw | `NULL` | = independent derivation |
| `7` | yes, no throw | `7` | = independent derivation |
| `4294967295` (store MAX) | yes, no throw | `4294967295` | = independent derivation |
| `0` (below store MIN) | **yes, no throw** | `0` | = independent derivation |
| `4294967296` (store MAX+1) | **yes, no throw** | `4294967296` | = independent derivation |

- **Object layer never range-checks.** `0` and `4294967296` are stamped verbatim and do **not** throw at
  `opening()`; admissibility is enforced only at the row write (`CheckoutAttemptStore::assertGenerationInRange()`).
- **Generation is not in the key.** All six `idempotencyKey`s are byte-identical to each other and to the
  independent derivation (generation is excluded from `intentKey()`).
- **Everything else unchanged per variant:** `quote` and `slot` are the same instances passed in; `state` =
  `Pending`; `attemptId` / `dispatchReservationId` / `settled` = `NULL`; `orderId` / `submissionId` /
  `created` / `changed` stamped as supplied.
- **Score: `RESULT: pass=75 fail=0`** — and no more than that. This is **object-only, legacy-snapshot**
  coverage (a versionless `fromStored` snapshot with `hasJobFactsIdentity() === FALSE`). It proves the value
  object's passive carriage and `intentKey()` stability; it proves **neither** persistence / round-trip through
  `CheckoutAttemptStore`, **nor** acceptance of a current-format (identity-bearing) snapshot, **nor**
  initial-binding atomicity. The printed `pass=75 fail=0` line is a value the probe computed and echoed, **not**
  a captured process exit code — `drush php:script` swallows the real exit status, so the `RESULT:` line is the
  only trustworthy signal and a non-zero PHP exit would not have surfaced through `$?`.

**Parity note (honest).** The first probe run scored 71/4: the four failures were all *generation-stamped*
checks, because the container copy of `CheckoutAttempt.php` was **stale** (Mutagen lag) — container
`sha256 3d623c56…` vs host `5d49ff55…`, and the container's `opening()` was still the pre-Step-3 5-argument
form, so a passed generation was an ignored extra positional argument. The authoritative host file was
synced into the running container by a direct byte-for-byte stdin push (new container `sha256` =
host `5d49ff55…`); **no container restart/recreate and no Mutagen repair were performed.** The 75/0 result
above is the re-run against the synced, host-identical code.

**Supporting checks.** `php -l CheckoutAttempt.php` → *No syntax errors detected*. `git diff --check` on the
two changed files → clean (RC 0). `graphify update .` → RC 0, 90/90 AST files, graph 5886 nodes / 11560
edges (YAML/Markdown remain outside AST coverage — a known limitation, not a failure, so this contract and
the docblocks are confirmed by direct read, not by a graph edge). `drush cr` succeeded through the running container on
`ppu-qa` (the QA-only scope of this task) and, **outside that scope**, on the default `ppu` site; the
default-site rebuild is recorded here as outside the preceding QA-only scope, and no further default-site
operation was performed or should be undone.

### J. Remaining blockers (unchanged by this task)

- **Live Hold not associable by submission** (§C) — needs a submission-addressable handle on the Hold
  row; schema work, out of scope.
- **Batch 2 caller integration + runtime atomicity.** The §G prepare / claim / publish **phase separation is
  now implemented (24C-8B)**; what remains is *deliberate transaction integration* then *bounded atomicity
  verification*. No caller supplies a generation, and the entity-save atomicity audit (§F4) is undischarged.
  Forward sequence: **phase separation (done) → deliberate transaction integration → bounded atomicity
  verification** — not "wrap `start()` now."
- **G-CAPACITY** — not implemented.
- **Enforcement activation, enrollment / backfill, migrations, `ppu` application, fresh-install
  provisioning** — all deferred.

Admission remains inactive; **Task 24C remains incomplete; Task 24B remains PARTIAL.**

---

## 24C-8C — Checkout binding transaction integrated (the §G claim-under-writer-transaction step)

The §G "deliberate transaction integration" is implemented in `DispatchCheckoutManager::start()`.
The combined `createCheckoutHold()` wrapper is no longer used on this path; `start()` runs the
three separated 24C-8B operations itself across **one caller-owned writer transaction**. **No
admission call, generation, flag, or enforcement is added — 24C-8C is transaction ownership only**
(Section 5 of the governing task). The atomicity comes from the shared outer transaction, never
from an admission lock.

### Files changed (uncommitted; existing dirty work preserved; no commit)

| File | Change |
|---|---|
| `src/Commerce/DispatchCheckoutManager.php` | Inject `Drupal\Core\Database\Connection` (16th ctor arg). `start()` calls `prepareCheckoutHold()` **before** the transaction, then delegates the write to a new private `bindCheckoutHold()`; the old `recordAttempt()` is replaced by `bindCheckoutHold()` + a new `discardOrderCaches()`. |
| `custom_webform_handlers.services.yml` | Append `@database` as the 16th argument of `custom_webform_handlers.dispatch_checkout_manager`. |

Both files were already `M` from Batch-1 admission wiring; the 24C-8C delta sits on top of that
preserved work. The 8B deliverables (`PreparedHold.php`, this doc) remain untracked.

### Transaction ownership & ambient-transaction policy

- **A** `prepareCheckoutHold()` (route I/O; final-route estimator fixed server-side) runs **before**
  any transaction. **B** one `startTransaction()` on the shared `@database`. **C**
  `claimPreparedCheckoutHold()` — the guard's own `startTransaction()` nests as a **savepoint**.
  **D** the draft Commerce order/item (entity saves on the same connection → nested). **E** the
  Checkout Attempt row. **F** the root transaction committed by `unset($transaction)`. **G** only
  then `publishCheckoutHoldReceipt()` (session receipt) and return `started(orderId)`.
- **Verified installed semantics (Drupal 10.6.16 `TransactionManagerBase`):** a nested
  `startTransaction()` is a `SAVEPOINT`; releasing it is **not** a commit — only the root
  transaction's destruct path (`processRootCommit()`) issues the durable `COMMIT`. The claim
  succeeding is never treated as proof of durability; the receipt is published strictly after the
  commit point.
- **Ambient policy:** `bindCheckoutHold()` refuses — before opening the transaction, before any
  booking effect — if `Connection::inTransaction()` is already TRUE: it must own the outermost
  transaction, never treat a nested savepoint as a durable commit. Both production callers reach
  `start()` with no transaction open — `AddressDistanceHandler::confirmForm()` runs **after** the
  submission save committed (deliberately not `postSave()`), and `ReviewPaymentForm::submitForm()`
  is a plain submit — so the guard is defence-in-depth and neither caller's contract changes.

### Failure / duplicate / publication behaviour (compensation replaced with rollback)

- Claim refusal, order `NULL`, attempt `NULL`, or any thrown exception → **roll back the one
  transaction** (unwinding claim + order + attempt together) and refuse. The old explicit
  `releaseHold()` / `orderFactory->discard()` compensation is gone from this path: rollback already
  removed those rows, so re-discarding is not attempted. A rolled-back order's in-memory id is
  **not** trusted — its entity caches are reset (`discardOrderCaches()`), per §F4(d).
- **Duplicate** (unique index returns another request's attempt): roll back our new work and resume
  the winner **through the established permitted-state check** (`hasReservation()` ⇒ `AlreadyBooked`,
  else `started(winnerOrderId)`); the losing hold's receipt is **never** published.
- **Commit vs publication kept separate:** an uncertain commit (throw from `unset`) is treated as
  **unresolved** — no receipt, booking neither deleted nor recreated. A publication failure **after**
  a durable commit cannot roll the booking back: it is logged and refused safely (the committed Hold
  lapses, nothing captured), never deleted or re-created.

### Verification (QA `ppu-qa.ddev.site`; no business-table writes)

| Check | Result |
|---|---|
| `php -l` `DispatchCheckoutManager.php` | No syntax errors (EC 0) |
| `Yaml::parseFile(services.yml)` | `YAML_OK` |
| Service-container construction (`\Drupal::service(...dispatch_checkout_manager)`) | resolves; injected `database` is a `mysql\Connection`; ctor arity 16 |
| QA `drush cr` | "Cache rebuild complete." (EC 0) |
| Host↔container SHA-256 (both changed runtime files) | identical |
| `graphify update .` | EC 0 (graph indexes the new `bindCheckoutHold()`) |
| `git diff --check` | EC 0 (only a benign `.gitignore` LF/CRLF notice) |
| Bounded control-flow probe (recording doubles; reflection into `bindCheckoutHold()`) | **`RESULT: pass=28 fail=0`** |

The probe covers, with recording collaborators and no DB: ambient-transaction refusal (no
transaction opened, no claim, no receipt); claim refusal (one transaction, rolled back, reason
passed through, no receipt); claim exception (caught → rolled back, no receipt); order-creation
failure (rolled back, `NotConfigured`, no attempt, no receipt); plus static control-flow assertions
over the method source for the A–G ordering, receipt-after-commit, exactly one publication, no
`releaseHold`/`discard` in the binding, and ≥5 rollback branches. These are 28 **new** assertions,
not a reproduction of 8B's accepted 27.

### PENDING (bounded real-DB verification scope) & unchanged blockers

- **Real rollback/commit atomicity, order-success ordering, attempt persistence, duplicate
  resolution against a live unique index, and post-commit receipt publication are PENDING** — a
  recording double establishes call ordering, not transaction atomicity, and this task forbids
  business-table writes. Proposed bounded follow-up (owner-authorized, QA, verified backup first,
  as 24C-5B/7B): one real transaction driving a single claim+order+attempt that asserts (a) a forced
  rollback leaves **zero** new rows across guard / dispatch_reservation / commerce_order /
  commerce_order_item / checkout_attempt (fresh-process recount), and (b) a success commits all and
  only then is the receipt readable — then rolls the fixture back.
- A duplicate resolved under REPEATABLE READ may surface as a refusal rather than a resume if the
  winner's committed row is outside this transaction's snapshot; the control flow is correct and the
  unlocked pre-flight catches the retry — real concurrency behaviour is part of the PENDING scope.
- **Admission enforcement** (acquire/verify/confirm/release, generations, flags, enrollment/backfill),
  **G-CAPACITY**, and application beyond QA (`ppu` still schema 10046; fresh installs) remain **open
  and deferred**, unchanged by this task.

Admission remains inactive; **Task 24C remains incomplete; Task 24B remains PARTIAL.**

---

## 24C-8C-R1 — Transaction-boundary corrections

Four correctness gaps in 24C-8C's `DispatchCheckoutManager` transaction owner are closed. Only
`src/Commerce/DispatchCheckoutManager.php` changed (uncommitted); `services.yml` was NOT touched
(the `@database` injection from 24C-8C suffices). No admission, migration, backfill, business-table
write, payment, or commit. Framework facts below are read from the installed source (Drupal 10.6.16).

### Corrective delta (one file)

| Area | 24C-8C (as shipped) | 24C-8C-R1 (corrected) |
|---|---|---|
| Ambient guard | inside `bindCheckoutHold()`, **after** `prepareCheckoutHold()` | moved to the **top of `start()`**, before preparation and the route provider; a defensive copy is retained at the binding boundary |
| Transaction acquisition | `startTransaction()` outside the `try` | wrapped — an acquisition failure refuses and never reaches a rollback (there is no transaction to roll back) |
| Rollback reporting | `try { rollBack(); } catch {}` then logged "rolled back" | new `rollBackBinding()` reports whether the rollback **completed**; a rollback that throws is logged "did not complete", never "rolled back"; the original failure is preserved |
| Commit boundary | "`unset()` did not throw ⇒ committed" | supported completion check — a post-transaction callback (fires only at ROOT resolution) must report not-rolled-back **and** `inTransaction()` must be false, else no receipt and no `started()` |
| Duplicate resolve | `hasReservation() ? AlreadyBooked : started()` | aligned to the pre-flight's permitted states: `hasReservation()`→AlreadyBooked, `isOpen()`→started, terminal (Failed/Cancelled)→refuse |

### §2 Ambient rejection before preparation

`start()` refuses at its first statement when `Connection::inTransaction()` is true — before
`prepareCheckoutHold()` (route I/O) and before any booking effect, opening and disturbing no
transaction. Verified through the PUBLIC `start()` (not only the private binding): with a
transaction already open, preparation is not called, the claim is not called, no transaction is
opened, and the outcome is a controlled refusal. Planner authority and route-selection policy are
unchanged.

### §3 Acquisition failure and honest rollback reporting

Acquisition is wrapped: a `startTransaction()` throw refuses with no rollback attempt.
`rollBackBinding()` distinguishes rollback **requested → completed** (returns TRUE) from
**failed/unknown** (logs "did not complete" with only the exception type, returns FALSE); it never
reports completion from a swallowed exception. Every failure branch returns a refusal and never
reaches the commit, the receipt, or `started()`.

Installed-source boundary (read, not assumed): `TransactionManagerBase::rollback()` on a root
transaction that **returns normally** voids its stack item, so the Transaction's later destruction
cannot commit. A `rollBack()` that **throws** leaves the client in `RollbackFailed`, where
destruction raises `TransactionOutOfOrderException` (a surfaced error) rather than silently
committing. The only path that could instead commit on destruction requires a corrupted multi-item
stack — which this binding never creates, because the guard's nested savepoint is always released
before the root rollback. Fully neutralising a failed rollback's destruction would require a broader
connection-lifecycle change (reconnect/void) outside this correction's scope; that residual is
**reported here, not hidden**.

### §4 Verified commit boundary (not "unset didn't throw")

`unset($transaction)` is no longer treated as proof of a durable commit. Installed source:
`mysql\TransactionManager::processRootCommit()` **voids without throwing** when the client is no
longer in a transaction (`if (!getClientConnection()->inTransaction()) { voidClientTransaction(); return; }`),
and `TransactionManagerBase::processPostTransactionCallbacks()` passes `$success = (state ===
Committed || state === Voided)`. So the supported signals are a post-transaction callback
(registered while in the transaction; fires only at ROOT resolution, never on a savepoint release)
reporting not-rolled-back, and `Connection::inTransaction()` reporting the connection closed. The
receipt is published only when BOTH hold; a commit that throws, a rollback (`$success` false), a
missing signal (no manager / callback never fired), or a still-open connection all withhold the
receipt and `started()`.

**Documented limitation (per directive):** the supported `$success` conflates **Committed** and
**Voided**, and the state enum's getter is protected (reflection is forbidden), so the public API
cannot distinguish an ordinary commit from a void. For this DML-only path a void is not expected,
but **real commit-versus-void durability is NOT established by this code or by a recording probe —
it is PENDING** real-database verification. A nested-savepoint completion is never presented as a
root commit (the callback fires only at root resolution).

### §5 Duplicate handling aligned to permitted states

The in-transaction duplicate branch now mirrors `existingAttempt()`: `hasReservation()`
(Paid/ReconciliationRequired) → `AlreadyBooked`; `isOpen()` (Pending) → resume via `started()`;
Failed/Cancelled → refuse, never resumed as `started()`. This is a **defensive state-contract floor,
not a reproduced race**: `CheckoutAttemptStore::settle()` releases the idempotency key on a terminal
settlement, so `open()`'s key-collision reread cannot return a Failed/Cancelled row to begin with.
The winning transaction's records are never mutated and the losing hold's receipt is never
published. **Real unique-index contention and transaction-isolation behaviour remain PENDING.**

### §6 Side-effect and recovery claims, corrected

- **Draft order/item side effects — coverage widened beyond this module** (read-only; no module
  disabled, nothing reconfigured). Commerce's own `OrderReceiptSubscriber` sends email on the
  **place** transition (`->send()`), not on draft creation; the other order subscribers (number,
  timestamp, profile) are place-gated or DB-only. `ORDER_PAID` fires only on a non-paid→paid
  transition, which draft creation is not. **ECA is enabled**; the two active ECA models
  (`custom_webform_handlers_popularity`, `custom_webform_handlers_route_warm`) trigger on this
  module's **post-commit domain events** (`review_transitioned`, `reservation_confirmed`), **not** on
  `commerce_order` insert/presave. No email, queue, or external effect was found reachable during
  draft order/item creation. **Limitation:** this covers the modules enabled on QA now; a future
  contrib subscriber or ECA model bound to `commerce_order` insert/presave with a nontransactional
  action is not structurally prevented and would need its own review. The 24C-8C "no non-transactional
  side effect (this module only)" wording is **superseded** by this wider but still bounded inspection.
- **Age-out claim corrected.** On a post-commit publication failure the committed **Hold** lapses on
  its own via its `expires_at` (liveness-gated), so capacity returns and nothing is captured. The
  committed draft **order** and **pending attempt** are durable records that do **not** automatically
  "age out" — no cleanup path for them is claimed here (pending-attempt sweeping is a separate,
  prior-task concern). The 24C-8C phrase "committed rows left to age out" is **withdrawn**: only the
  Hold lapses; the order/attempt persist until a separately-owned path handles them.
- **Publication failure stays distinct from rollback.** A publication failure after a resolved root
  transaction cannot roll the booking back and does not delete or recreate it; it is logged and
  refused safely. No new recovery workflow is introduced by this correction.

### Verification (QA `ppu-qa.ddev.site`; identity re-confirmed: DB `qa`, site `sites/qa`, schema 10049, `development_fixed`, `booking_admission.enforce` = NULL)

| Check | Result |
|---|---|
| `php -l` `DispatchCheckoutManager.php` | No syntax errors (EC 0) |
| YAML | n/a — `services.yml` unchanged this task |
| Service construction (`\Drupal::service(...)`) | resolves; ctor arity 16; `database` is `mysql\Connection`; `bindCheckoutHold` + `rollBackBinding` present |
| QA `drush cr` | "Cache rebuild complete." (EC 0) |
| Host↔container SHA-256 (changed file) | identical (`c0a47775…`) |
| `graphify update .` | EC 0 |
| `git diff --check` | EC 0 (only the benign `.gitignore` LF/CRLF notice) |
| Bounded probe | **`RESULT: pass=41 fail=0`, `DRUSH_EXIT=0`** |

- **Dynamically exercised** (recording doubles, no DB): ambient rejection via public `start()` (no
  prep/claim/transaction); transaction acquisition failure; claim refusal (rollback completed, reason
  passed through, no receipt); order-creation failure; rollback-failure reporting (reported "did not
  complete", never "rolled back", still refused).
- **Source-inspected** (static over the method source): the A–G ordering through the completion gate;
  the ambient guard preceding preparation in `start()`; the completion check (post-transaction callback
  + `inTransaction` re-check gating the receipt); the duplicate permitted-state branch; honest rollback
  reporting (`rollBackBinding` on every failure branch, no raw `rollBack()` in the body, no false
  "rolled back", no `releaseHold`/`discard`); one receipt publication.
- **PENDING** (not run here): real commit/rollback atomicity, commit-vs-void, order-success ordering,
  attempt persistence, duplicate resolution against a live unique index, and publication after a real
  commit. A recording double establishes call ordering, not durability.

### Corrected database-verification follow-up (prepared, NOT run)

The 24C-8C follow-up ("one transaction that commits then rolls back") was **self-contradictory**: a
genuinely committed fixture cannot be rolled back through its own transaction, and wrapping the
binding in an outer transaction would either trip the new ambient guard in `start()` or prevent a
genuine root commit from being observed. It is replaced by three separate, separately-authorized
tracks:

1. **Forced-failure rollback checks.** Drive one real claim+order+attempt, force a failure at each
   step, and assert a fresh process finds **zero** new rows across guard / `dispatch_reservation` /
   `commerce_order` / `commerce_order_item` / `checkout_attempt`. The transaction is never committed,
   so nothing needs cleanup.
2. **Genuine commit checks.** Allow one binding to actually commit, then assert (fresh process) that
   all rows are present and the receipt was published only after the commit — with **separately
   authorized** fixture cleanup (an explicit delete of the created order/attempt and release of the
   Hold) OR on an explicitly authorized isolated database. A committed fixture is NOT undone by
   re-wrapping it in a transaction.
3. **Real concurrency / duplicate checks.** Two real sessions on one intent against the live unique
   index, asserting exactly one booking and that the loser resumes/refuses by permitted state — plus
   commit-vs-void behaviour under an induced implicit commit.

Each needs a verified backup first (as 24C-5B/7B) and owner authorization; none is run under this task.

### Status

**Real-database verification: NOT RUN.** Admission enforcement, enrollment/backfill, and G-CAPACITY
remain deferred. Admission remains inactive; **Task 24C remains incomplete; Task 24B remains PARTIAL.**

---

## 24C-8C-R2 — Transaction-outcome safety and decision gate

### R1 reclassified PARTIAL

R1 is recorded as **PARTIAL**, not complete. Its 41 assertions were **23 dynamically exercised +
18 source-inspection**; real commit/rollback atomicity, failure-safe destruction, and the success
path were never exercised. R1 also left four outcome-safety defects, resolved or escalated below.

### Decision record (contract established BEFORE the rewrite)

Installed contract read from `mysql\Driver\Database\mysql\TransactionManager` + `TransactionManagerBase`
(Drupal 10.6.16), using only supported interfaces: `Connection::getClientConnection()` (**public**),
`Connection::inTransaction()`, `TransactionManagerInterface::addPostTransactionCallback()` /
`::voidClientTransaction()`.

Driver facts:
- `processRootCommit()` **voids without throwing** when `getClientConnection()->inTransaction()` is
  already FALSE; otherwise commits (`commitClientTransaction()` → `CommitFailed` →
  `TransactionCommitFailedException`).
- `processPostTransactionCallbacks()` passes `$success = (Committed || Voided)` — the callback alone
  **cannot** distinguish an ordinary commit from a void.
- `rollbackClientTransaction()` calls the client `rollBack()` **before** assigning the state; a
  **throw** there skips the assignment, leaving the manager **Active**, so the Transaction's
  destruction can `processRootCommit()` and **commit even on a single-item stack**. `RollbackFailed`
  is only the client-returned-FALSE path.
- `processRootRollback()` **discards** `rollbackClientTransaction()`'s bool, so a normal return does
  not establish a successful rollback (it may be `RollbackFailed`, or a void).
- `rollbackClientSavepoint()` / `releaseClientSavepoint()` can themselves throw — the earlier
  "nested savepoints are always released" claim is **withdrawn**.

Contract against the three required guarantees:

1. **Ordinary root commit before receipt — ESTABLISHABLE caller-side.** Evidence combined (no single
   check suffices): `getClientConnection()->inTransaction() === TRUE` immediately **before**
   destruction (excludes the void branch, which requires the client already out), **and** a
   post-transaction callback reporting not-rolled-back, **and** both the Drupal stack and the client
   not-in-transaction **after**. Void and rollback are rejected. → implemented in §2A below.
2. **Confirmed rollback vs unknown — ESTABLISHABLE caller-side.** After `rollBack()` returns, the
   client transaction is **confirmed disposed only when `getClientConnection()->inTransaction() ===
   FALSE`**; a still-open client, an indeterminate client, or a thrown `rollBack()` is UNKNOWN/FAILED
   and is never reported as rolled back. → implemented in §2B/§2C below.
3. **Safe disposal after a rollback FAILURE — NOT establishable caller-side; BLOCKED.** A thrown
   client `rollBack()` leaves the manager **Active**, and destruction can then commit. Neutralising
   it needs forcing disposal — `voidClientTransaction()` to stop the destruct-commit **plus a client
   connection reset** so the lingering server transaction is actually rolled back (void only updates
   manager bookkeeping, it does not roll the server back). That is a **connection-lifecycle change**,
   beyond a caller/logging correction, so it is **NOT implemented here and transaction integration
   stays BLOCKED on it.**

**Smallest proposed change for (3)** (separately authorized; NOT performed under this task):
- *File:* `src/Commerce/DispatchCheckoutManager.php` (plus, if a shared helper is wanted, a narrowly
  scoped connection-lifecycle utility — no core/contrib edit).
- *Change:* on a thrown/failed client rollback, force-dispose the root transaction via the supported
  `TransactionManagerInterface::voidClientTransaction()` and reset the client connection so the
  server transaction cannot be committed by destruction, then refuse.
- *Verification:* a recording client/connection driving the **installed** manager through a thrown
  client `rollBack()`, asserting the root is disposed (no commit) and no `started()`/receipt — plus
  the authorized real-DB track once the contract is accepted.

**Permitted and done now** (caller/logging corrections within the existing manager — explicitly NOT a
resolution of the broader guarantee): the §2A void-rejecting success gate, the §2B/§2C explicit
rollback-outcome semantics and caller handling, and the §2E observer-setup failure handling.
Real-database atomicity remains PENDING; guarantee (3) remains BLOCKED.

### Corrective delta (one file: `src/Commerce/DispatchCheckoutManager.php`)

| § | Defect (R1) | Correction (R2) |
|---|---|---|
| 2A | gate accepted callback-TRUE + `!inTransaction()` → a VOID passed | gate now requires an ordinary commit: client was in a transaction **before** destruction (excludes void), the callback is not-rolled-back, AND the Drupal stack + client are closed after. Void → no receipt/`started()` |
| 2B | `rollBackBinding()` returned TRUE on any normal return | returns an explicit outcome — `ROLLBACK_DISPOSED` (client confirmed closed) / `ROLLBACK_UNKNOWN` (still open or unreadable) / `ROLLBACK_FAILED` — via the client-level `inTransaction()` evidence |
| 2C | attempt-NULL ignored the rollback result and logged "was rolled back"; duplicate could return `started()` after unknown cleanup | every caller records the outcome: attempt-NULL logs the cleanup outcome; the duplicate returns `started()` **only** for a Pending winner with **confirmed disposal**, else refuses; `failed`/`unknown` never yields `started()`; no "was rolled back" claim; no compensating delete/recreate |
| 2D | destructor-safety explanation was wrong | corrected: a thrown client rollback leaves the manager **Active** → destruction can commit; nested savepoints are NOT guaranteed released. Safe disposal is **BLOCKED** (see decision record) |
| 2E | observer setup ran outside failure handling | `transactionManager()`/`addPostTransactionCallback()` moved inside a try; a setup throw refuses (with cleanup) rather than bypassing the outcome policy |

### Verification (QA `ppu-qa.ddev.site`; identity reconfirmed after the container resumed)

| Check | Result |
|---|---|
| QA identity | DB `qa`, `sites/qa`, schema 10049, `development_fixed`, `enforce` NULL |
| `php -l` (`DispatchCheckoutManager.php`) | No syntax errors (EC 0) |
| Service construction | resolves; ctor 16; `rollBackBinding` + `clientInTransaction` present; `database` is `mysql\Connection` |
| QA `drush cr` | "Cache rebuild complete." (EC 0) |
| Host↔container SHA-256 (changed file) | identical (`b7251503…`) |
| `git diff --check` | EC 0 (benign `.gitignore` LF/CRLF notice) |
| Graphify `update .` | EC 0 |
| Bounded outcome probe | **`RESULT: pass=42 fail=0`, `DRUSH_EXIT=0`** |

- **Dynamically exercised** (recording client + recording connection, no real DB): public `start()`
  ambient rejection; `clientInTransaction()` evidence (in → TRUE, out → FALSE, no-client → NULL,
  throws → NULL); `rollBackBinding()` outcome classification — DISPOSED (client closed), UNKNOWN
  (client still open — the discarded returned-FALSE/void case), FAILED (rollback throws) — with
  rollback call counts; acquisition failure; claim refusal; order failure. **Counters:** rollback
  outcomes exercised = 3; dynamic publication/commit/`started()` reached = **0** (the commit gate was
  not entered). This covers §4's "rollback returning FALSE" and "client rollback throwing" cases.
- **Source-inspected** (labelled): the §2A void-rejecting gate; §2E observer setup in a try; §2B/§2C
  outcome constants + caller gating; the ordering and no-compensating-delete invariants.
- **NOT exercised by this probe** (labelled — reachable but needing a heavy harness or a DB write,
  not "impossible"): ordinary commit (one commit, receipt after), voided completion (no success/
  receipt), duplicate resume after failed/unknown rollback, attempt-NULL logging, and
  completion-callback registration failure. Reaching the commit gate requires order+attempt success,
  which needs real Commerce DB writes (forbidden) or a disproportionate entity + query-builder fake
  harness.
- **Container note:** the `ddev-ppu-*` containers were found **exited (255)** after a host sleep
  across the date change; the existing stopped containers were resumed with plain `docker start`
  (no `ddev start`, no recreation) to restore the prior running state, and identity + parity were
  re-confirmed after.

**Real-database verification: NOT RUN.** Guarantee (3) remains BLOCKED; the transaction integration
stays blocked on it. Admission enforcement, enrollment/backfill, and G-CAPACITY remain deferred.
Admission remains inactive; **Task 24C remains incomplete; Task 24B remains PARTIAL.**

---

## 24C-8C-R3 — Transaction contract: evidence-first

No application-source edit and no transaction-lifecycle implementation this task. Evidence from the
installed Drupal 10.6.16 framework and an isolated lifecycle demonstration; the R2 assessment is
corrected and one concrete implementation proposal is recorded. **R2 remains PARTIAL; transaction
integration remains BLOCKED; real-database verification remains NOT RUN.**

### §1 Corrections to the R2 assessment

1. **Unknown post-commit state passes the gate (unresolved FAIL-OPEN).** The R2 gate rejects
   `clientInTxAfter === TRUE` but permits `clientInTxAfter === NULL`. NULL means the client state
   could **not** be determined — it does not satisfy the requirement that the client transaction is
   **confirmed closed**. So an unreadable post-completion client state still passes the gate and
   permits a receipt. Recorded as an **unresolved fail-open condition**, not a resolved guarantee.
2. **A closed transaction does not prove a rollback (guarantee 2 WITHDRAWN).** `rollBackBinding()`
   returns `ROLLBACK_DISPOSED` when the client reports no active transaction — but an **earlier
   implicit commit followed by a voided rollback produces the same observation** (demonstrated
   below). Therefore "no active transaction" does **not** prove our writes were rolled back, does
   **not** establish that the losing order/claim disappeared, and must **not** by itself authorize a
   duplicate resume. **Guarantee 2 is withdrawn**; connection state is distinguished from persistence
   outcome. (The R2 code paths that gate `started()` on `ROLLBACK_DISPOSED` therefore rest on an
   unproven signal and require the §4 work before they may be relied on.)

### §2 Why "void + reset" is NOT a safe abort (do not implement yet)

- **`Database::closeConnection()` COMMITS, it does not abort.** Installed source (L484-506) calls
  `$connection->commitAll()` (L491/L498) before `unset(self::$connections[...])` and
  `gc_collect_cycles()`. It is not a safe abort, and `unset` only drops the registry reference —
  other injected references retain the same `Connection` object, so a "reset" would leave those
  holders pointing at a connection whose state was silently committed.
- **`voidClientTransaction()` is driver-only and does not roll back.** Its interface docblock
  (`TransactionManagerInterface` L99-107) states it *"should only be called internally by a database
  driver"*, and its documented purpose is to handle a server-side auto-commit (e.g. MySQL DDL) by
  *"void[ing] the remaining items on the stack"* — bookkeeping only; it does **not** roll the server
  transaction back. It also sets state `Voided`, and `processPostTransactionCallbacks()` passes
  `$success = TRUE` for **Voided** as well as Committed — so every existing completion callback would
  read an **abort as success**. `push()`/`unpile()`/`rollback()` carry the same driver-only
  restriction (L39/L59/L81); only `inTransaction()` and `addPostTransactionCallback()` are
  application-intended. Public visibility does not make a method intended for application-level
  lifecycle management.

### §3 Isolated lifecycle demonstration (real installed manager, recording client)

The **real** `mysql\TransactionManager` (+ `TransactionManagerBase`) was driven over a recording
client/connection, isolated from the live Drupal database — no real connection, no business-table
write, no provider call, no Commerce application. **`RESULT: pass=20 fail=0`**; the probe's
`DRUSH_EXIT=1` is a teardown artifact (a Transaction left un-`unset` in one scenario trips the
framework's own `assert()` at process exit, **after** the RESULT line) and is not an assertion
failure. Trace:

| # | Scenario | Commit/rollback calls | Callback value | Persistence outcome |
|---|---|---|---|---|
| 1 | Ordinary commit | commit 1 / rollback 0 | TRUE | **known-committed** |
| 2 | Successful rollback | rollback 1 / commit 0 | FALSE | known-rolled-back |
| 3 | Client rollback returns **FALSE** | rollback 1 / commit 0; destruction → `TransactionOutOfOrderException` | not a success | **UNKNOWN** (framework does not confirm) |
| 4 | Client rollback **throws** before state assignment | destruction → **commit 1** | (no success) | **UNKNOWN — DESTRUCTOR-CONTINUE: the data is committed during destruction** |
| 5 | Client already outside a transaction | rollback **0** / commit 0 | **TRUE** | **UNKNOWN** (void; may have implicitly committed) |
| 6 | Voided completion | commit 0 / rollback 0 | **TRUE** | **UNKNOWN** — Committed/Voided conflated |
| 7 | Unreadable post-completion client state | commit 1 | — | **UNKNOWN** (no confirmed-closed signal) |
| 8 | Observer-registration failure | — | — | `addPostTransactionCallback()` throws `LogicException` with no active transaction |

**Counterexample (the §3 requirement):** a client is "no active transaction" **both** when the work
**committed** (scenario 1) **and** when it **rolled back** (scenario 2) — the closed-connection
observation is identical; only the **callback** distinguishes them. Hence **connection state ≠
persistence outcome**, and a closed connection by itself cannot authorize `started()`.
Scenario 4 is the dangerous residual: a thrown client rollback let **destruction commit the data**.

### §4 Proposed implementation and required authority

- **Guarantees 1 and 2 are NOT implementable through the currently permitted public interfaces.** The
  supported signal (`addPostTransactionCallback`) conflates Committed and Voided (scenario 6); the
  authoritative state getter is `protected`; and a normal `rollback()` return does not confirm a
  rollback (scenarios 3/5). A **driver-provided or connection-level signal** is required.
- **Guarantee 3 (failed cleanup must not destructor-commit) cannot be fixed caller-side** (scenario
  4) — it needs a driver/connection-level abort that also neutralises **every** existing completion
  callback and **every** holder of the shared `Connection` reference.
- **Smallest proposed change (requires authority — NO core/contrib edit is permitted today):** add a
  narrowly scoped connection-lifecycle capability that (a) surfaces an **ordinary-commit vs
  void/uncertain** distinction from the driver, and (b) can **abort** an in-flight transaction so
  destruction cannot commit — e.g. a public accessor on the transaction manager exposing the outcome
  state (Committed/Voided/RolledBack/RollbackFailed) plus a driver-supported abort. Affected files:
  a **core/contrib driver change** (`core/lib/Drupal/Core/Database/Transaction/*`,
  `core/modules/mysql/src/Driver/Database/mysql/TransactionManager.php`) and a small
  `src/Commerce/DispatchCheckoutManager.php` successor. Verification: the §3 lifecycle demonstration
  extended to every scenario with the new signal, then the authorized real-DB track.
- **Additional authority required:** explicit permission to make a **core/contrib driver change**
  (currently forbidden by project policy and this directive), **or** an executed decision to accept
  the residual (not permitted — it silently weakens the guarantee), **or** an explicitly authorized
  **isolated database** for real acceptance. Until one is granted the contract is unresolved and
  transaction integration stays **BLOCKED**. Nothing is silently relaxed and no checkout is disabled.

### §5 Scope, container lifecycle, and status

- **Only** `INTEGRATION-24C-4.md` was updated. No application-source edit, business-table write,
  migration, payment, external API, configuration change, memory edit, core/contrib edit, or commit.
  The temporary lifecycle diagnostic was **task-scoped and removed** (host + container verified
  gone). `development_fixed`, inactive admission enforcement, and the suspended DBHub boundary hold.
- **Container lifecycle actions (recorded as such):** earlier this session the `ddev-ppu-*`
  containers were found **exited (255)** and were **resumed with plain `docker start`** (no
  `ddev start`, no recreation). That host-sleep was an **inference** from the simultaneous exit
  timestamp; no independent evidence (host sleep log) established it, so it is recorded as an
  inference, not a fact.

**R2 remains PARTIAL. Transaction integration remains BLOCKED. Real-database verification remains NOT
RUN.** Admission enforcement, enrollment/backfill, and G-CAPACITY remain deferred. Admission remains
inactive; **Task 24C remains incomplete; Task 24B remains PARTIAL.**

---

## 24C-8C-R3A — Clean lifecycle evidence and a concrete non-core proposal

No application-source edit; the R3 evidence is corrected, the demonstration is re-run clean, and one
implementation-ready proposal is recorded. Diagnostics were task-scoped and removed. **R2 remains
PARTIAL; transaction integration remains BLOCKED; real-database verification remains NOT RUN.**

### §1 Corrected evidence terminology (R3)

The R3 run reported `pass=20 fail=0` **but the process exited 1 because transaction teardown
failed** (a leaked stack item tripped the framework's own `assert()` at shutdown). Recorded
precisely as:

- **Scenario observations captured.**
- **Probe lifecycle/teardown NOT CLEAN.**
- **Full verification NOT passed.**

Destruction is central to this investigation, so a teardown failure is **not** dismissed for
occurring after the RESULT line. Terminology corrected: the R3 scenario-4 note "the data committed"
is replaced with — **"The installed manager invoked `commit()` on the recording client during
destruction; the recording model recorded a simulated commit."** **No real database persistence was
demonstrated** (the client is a recording double).

### §2 Clean isolated demonstration (real installed manager, child-per-scenario)

The **real** `mysql\TransactionManager` (+ `TransactionManagerBase`) was run over a recording
client/connection, isolated from the live database — no real connection, no business-table write, no
provider call. Each scenario runs in its **own child process**, so a scenario that deliberately
leaves the manager stack dirty cannot leak state or crash the parent; the parent validates each
child's exit status and expected error. Assertions are **not** disabled, errors are **not**
suppressed, and no fake success replaces the installed logic. Run with bare `php` (not
`drush php:script`) so each child's exit code is genuine.

**`RESULT: pass=23 fail=0`, `PARENT_EXIT=0`** (parent exits non-zero if any assertion fails).

| # | Scenario | child_exit | Observation (counters) |
|---|---|---|---|
| 1 | Ordinary commit | 0 | `commit=1 rollback=0 cbCalls=1 cbVal=true client_in_tx=false` |
| 2 | Successful rollback | 0 | `commit=0 rollback=1 cbCalls=1 cbVal=false client_in_tx=false` |
| 3 | Client rollback returns **FALSE** | **255** (expected) | `commit=0 rollback=1 cbCalls=0 cbVal=NULL rollback-threw=no unset-threw=TransactionOutOfOrderException client_in_tx=true` |
| 4 | Client rollback **throws** | 0 | `commit=1 rollback=1 cbCalls=1 cbVal=true rollback-threw=yes unset-threw=no client_in_tx=false` |
| 5 | Client already outside a transaction | 0 | `commit=0 rollback=0 cbCalls=1 cbVal=true` |
| 6 | Voided completion | 0 | `commit=0 rollback=0 cbCalls=1 cbVal=true client_in_tx=true` |
| 7 | Unreadable post-completion client state | 0 | `commit=1 rollback=0 cbCalls=1 cbVal=true post-state-read-threw=RuntimeException` |
| 8 | Observer-registration failure | 0 | `register-threw=LogicException commit=0 rollback=0` |

Findings, with teardown separated from observations:

- **Scenario 3** is the one deliberately-dirty case: `rollBack()` returns normally (client
  `RollbackFailed`), the callback **never fires** (`cbCalls=0`), the client stays `in_tx=true`, and
  destruction throws `TransactionOutOfOrderException`. Its **non-zero exit is the expected result**
  and is accounted for; the child leaves the stack intact on purpose.
- **Scenario 4** (rollback throws before the state assignment): destruction **invokes `commit()` on
  the recording client** — the simulated destructor-continue. The child exits 0 because destruction
  completes; the danger is the outcome, recorded as a **simulated** commit.
- **Scenario 5** (client already out): no client rollback call, callback **TRUE** — void conflation.
- **Scenario 6** (explicit void): callback **TRUE**, no commit and no rollback, client still
  `in_tx=true` (the server transaction is not rolled back).
- **Scenario 7**: a `commit=1` occurred yet a post-completion client read throws — no
  confirmed-closed signal is obtainable.
- **No cross-scenario leakage:** the parent process ran no transaction; every clean scenario exited
  0 with a clean final harness exit, and the single expected-failure scenario is explicitly
  accounted for.

### §3 Driver-extension mechanism investigated — proposal (design only, nothing created or activated)

The installed source provides a **non-core extension seam** (read directly):

- `Connection::driverTransactionManager()` is **`protected`** (base throws; the MySQL driver returns
  `new TransactionManager($this)`), and `Connection::transactionManager()` memoises it.
- `Database::parseConnectionInfo()` rewrites `namespace`/`autoload` **only** when they are empty or
  a core default, so a **custom namespace + autoload** in `$databases` survives.
- `Database::openConnection()` resolves `$driver_class = $info['namespace'] . '\\Connection'` and
  instantiates it; `addConnectionInfo()` registers `addPsr4($namespace.'\\', $app_root.'/'.$autoload)`.

So **"core edits are required" is NOT established.** A narrowly scoped custom driver that
**inherits** the installed MySQL implementation is viable. Concrete recommendation:

1. **Exact classes / methods / files** (all new, none in core):
   - `web/modules/…/src/Driver/Database/ProbeMysql/Connection.php` — `final class Connection extends
     \Drupal\mysql\Driver\Database\mysql\Connection`, overriding only
     `protected function driverTransactionManager(): TransactionManagerInterface { return new
     TransactionManager($this); }`.
   - `…/ProbeMysql/TransactionManager.php` — `final class TransactionManager extends
     \Drupal\mysql\Driver\Database\mysql\TransactionManager`, adding the outcome API below.
   - `$databases['default']['default']['namespace']` (or a dedicated connection key) +
     `['autoload']` in `settings.php` — a **configuration** change, listed as a separate approval.
2. **Outcome owned by the root transaction, not a global "last outcome."** Add per-root bookkeeping
   keyed by the existing `rootId`, set inside `processRootCommit()`/`processRootRollback()`, exposed
   via a **public** `rootOutcome(): RootOutcome` enum (`Committed` / `Voided` / `RolledBack` /
   `RollbackFailed` / `Uncertain`). A global "last outcome" is explicitly rejected — the manager can
   be nested/interleaved, so the outcome must belong to the specific root the caller opened.
3. **Ordinary commit vs void/rollback/uncertain.** The subclass reads the parent's transaction state
   at each root transition (`setConnectionTransactionState` is the existing hook) and records
   `Committed` only on a real client commit, `Voided` on `voidClientTransaction()`, `RolledBack` /
   `RollbackFailed` on the rollback branches — so checkout can require `Committed` specifically.
4. **Prevention of destructor-driven commit after rollback failure.** Override `processRootCommit()`
   so it **does not commit** when the root is not in an intact `Active` state from a successful
   precondition; on a `RollbackFailed`/uncertain root it records `Uncertain` and performs **no**
   client commit. This is the specific missing capability today (scenario 4): the base manager calls
   the client `commit()` during destruction with no caller hook.
5. **Existing completion callbacks.** The subclass preserves `processPostTransactionCallbacks()`'s
   contract but must **not** pass `TRUE` for a void/uncertain root: it maps outcome → success so a
   callback receives `TRUE` only for `Committed`. (The base implementation passes `TRUE` for
   Committed **or** Voided — the conflation scenario 6 demonstrates.)
6. **Existing references to the shared connection.** Every holder of the same `Connection` object
   (all default-connection users, not only checkout) sees the new manager, because the manager is
   memoised on that Connection. That is why the change is **not checkout-only**: the completion
   callback `TRUE`/`FALSE` semantics change for **all** consumers, so activation must be assessed
   globally. `Database::closeConnection()` still `commitAll()`s, so the proposal must not rely on
   close-as-abort.
7. **Terminate rather than unsafe recovery.** When the root outcome is `Uncertain` or
   `RollbackFailed`, the proposal has the caller **refuse/terminate the request path** (fail-closed)
   rather than attempt recovery — no "reset"/"reconnect"/"dispose" is specified or assumed.
8. **Bootstrap / autoload / activation scope / maintenance.** The `namespace`+`autoload` in
   `$databases` registers the new classes (PSR-4) with **no** core class override; activation is a
   **settings change** limited to the chosen connection key; maintenance is a small inherited
   subclass that must track the Drupal transaction-manager contract across core updates (a
   maintenance cost, and a reason to keep it minimal).

**Missing capability if the subclass cannot deliver (stated precisely, not substituted):** the
parent exposes no public accessor for the client transaction **outcome state**
(`getConnectionTransactionState()` is `protected`; the enum is unreachable) and no public abort. If
inheritance cannot legally override `processRootCommit()`/`processRootRollback()` to withhold a
commit and to read that state, then the exact missing capability is **a supported (non-reflection)
way to read the root client-transaction outcome and to prevent a destructor-driven commit** — the
proposal names that precisely rather than "abort"/"reset".

### §4 Separate implementation authority from verification authority

The R3 "core/driver change **OR** isolated database" either/or is **withdrawn**; they are different
decisions:

- **Implementation authority** — permits changing transaction behavior (the §3 driver extension
  and/or a core/contrib change). **Not authorized here.**
- **Activation authority** — permits the settings/`$databases` change that would load the extension.
  **Not authorized here** (listed separately).
- **Verification authority** — permits an isolated database to *test* the behavior. A test database
  does **not** repair an unresolved production contract, so it cannot substitute for implementation
  authority.

Every one of these is a **separate** approval request; none is granted under this task.

### §5 Scope, container lifecycle, and status

- **Only** `INTEGRATION-24C-4.md` updated. No application-source change, core/contrib patch,
  business-table write, migration, payment, external API, connection reset, container lifecycle
  operation, memory edit, or commit. The task-scoped diagnostic was **removed** (host + container
  verified gone). `development_fixed`, inactive admission enforcement, and the suspended DBHub
  boundary hold. The web container was already **running** (reported unhealthy but exec-capable); it
  was **not** repaired or restarted.

**Completed:** corrected R3 terminology; clean lifecycle demonstration (`pass=23 fail=0`,
`PARENT_EXIT=0`); driver-extension investigation + one concrete proposal; authority separation.
**Pending:** the §3 subclass prototype and its unit-level demonstration. **Blocked:** the production
contract (guarantees 1–3) — needs implementation authority; **R2 remains PARTIAL; transaction
integration remains BLOCKED; real-database verification remains NOT RUN.**

Admission enforcement, enrollment/backfill, and G-CAPACITY remain deferred. Admission remains
inactive; **Task 24C remains incomplete; Task 24B remains PARTIAL.**

## 24C-8C-R4 — Isolated transaction-driver prototype, without activation

Builds **exactly one** candidate from the R3A §3 proposal and answers one question: *can an inherited
MySQL driver extension establish an owned-root outcome and prevent destructor-driven commit after a
failed rollback, without modifying core and without changing unrelated transactions?*

### §1 Application frozen

`src/Commerce/DispatchCheckoutManager.php` is **byte-identical** to the R2 baseline —
SHA-256 `b7251503286a641565d43ed0e618532c5caa94327cae23eb446b86f37a0a399e`. `services.yml`
(`920581e3…263e5f`) is unchanged. Nothing in R4 touches application source, core, or contrib.

### §2 The candidate (five files, all under `prototype-r4/`)

| File | Role |
| --- | --- |
| `RootOutcome.php` | `enum` — NotCompleted / Committed / RolledBack / Voided / Failed / Uncertain |
| `RootHandle.php` | Opaque handle: caller holds an id, asks the manager for *that* id's outcome |
| `TransactionManager.php` | `extends Drupal\mysql\…\TransactionManager` — the whole candidate |
| `Connection.php` | `extends Drupal\mysql\…\Connection`; overrides only `driverTransactionManager()` |
| `demo.php` | Standalone comparative demonstration + child-per-scenario harness |

**Inherited, not copied.** The candidate extends the shipped MySQL manager and calls `parent::` in
every override. It reads **no** parent private state (`$rootId`, `$stack`, `$voidedItems`,
`$postTransactionCallbacks`) — those are private in `TransactionManagerBase`, as R3A recorded — and
uses no reflection, no bound-closure capture of parent internals, and no copied core internals. It
uses only the documented protected hooks (`addStackItem`, `voidStackItem`, `processRootCommit`,
`rollback`, `unpile`, `getConnectionTransactionState`, `setConnectionTransactionState`).

Methods overridden and why:

- `addStackItem()` — observe the id of each new **Root** (parent still stores it).
- `beginRoot()` — public entry: pushes a root and returns `[Transaction, RootHandle]`.
- `voidStackItem()` — observe a voided id so an owned root ended by `voidClientTransaction()` is
  classified **Voided**, not left NotCompleted. No behaviour change.
- `unpile()` / `rollback()` — record which id is resolving; classify the rollback.
- `processRootCommit()` — **suppress the client commit** when this root's rollback already failed.
- `addPostTransactionCallback()` — deliver success to an owned root **only on a confirmed commit**.

Ownership is established in **one** place (`beginRoot()`). Every other recording site checks
`isOwned()` first, so a root pushed through the inherited `push()` is never adopted mid-flight.

### §3 Safety properties proven (offline, recording client — no database)

`RESULT: pass=36 fail=0`, `PARENT_EXIT=0`. Each scenario runs in its own child process.

| Property | Evidence |
| --- | --- |
| Ordinary success commits **exactly once**; callback TRUE | `commit=1 rollback=0 cbVal=true outcome=committed` |
| Confirmed rollback commits **nothing**; callback FALSE | `commit=0 rollback=1 cbVal=false outcome=rolled_back` |
| **Failed rollback produces no later commit** (the defect) | installed `commit=1` vs candidate `commit=0 outcome=failed` |
| A returns-FALSE rollback is not a success | `commit=0 … outcome=failed`; both variants still exit 255 on the out-of-order `unset()` |
| Void is **not** delivered as success | installed `cbVal=true` vs candidate `cbVal=false outcome=voided`; `commit=0` |
| Uncertainty never grants success | `outcome=uncertain` for an unknown/old handle |
| Outcomes stay with the **correct** root | `A=committed B=rolled_back unknown=uncertain distinctHandles=true` |
| Releasing a savepoint is **not** root commit | `after_push=not_completed after_savepoint_outcome=not_completed … outcome=committed`; savepoint SQL executed (`SAVEPOINT`, `ROLLBACK TO SAVEPOINT`, `RELEASE SAVEPOINT`) |
| Unowned roots are untouched (the control) | `cbVal=true commit=1` — identical to installed |
| A callback throw after commit is contained | installed child **255** (exception escapes); candidate **0**, `callbackErrors=RuntimeException`, still `commit=1` |
| Registration outside a transaction is unchanged | both throw `LogicException` |

Expected child failures are **asserted**, not merely printed: `rollback-false` exits 255 in both
variants with the same exception; `callback-failure-after-commit` exits 255 installed and 0 candidate.

### §4 What suppression does **not** do (the honest limit)

Suppressing the destructor commit **prevents a commit**; it does **not** roll anything back. The
retained-reference scenario shows a client transaction left **open** after suppression
(`client_still_open_after_suppression=true`, `commit=0`). So the veto is real but the connection is
then in an indeterminate state and **must be terminated** — safe continuation on that connection is
**not established by this candidate**. This is the same open question R2 raised, and the prototype
does not answer it.

### §5 Decision

**VIABLE WITHIN SCOPE, for the specific question asked.** An inherited MySQL driver extension can
establish an owned-root outcome and prevent destructor-driven commit, with no core modification and
no change to unowned-root behaviour. It is **not** a drop-in: it changes the connection class, needs
activation authority, and cannot be judged for database atomicity without a real database.

**Not established here:** whether a suppressed transaction actually rolled back *on the wire*
(the recording client is not the server); behaviour under concurrent/nested Drupal roots; the
destructor ordering of the manager itself; and the R2 open question of what a caller does with an
indeterminate connection.

### §6 Scope, authority, and status

Only `prototype-r4/**` and this document changed. No application source, core/contrib patch,
driver activation, live business-table write, migration, payment, external API, connection reset,
container lifecycle operation, memory edit, or commit. The prototype is **inert** — referenced
nowhere outside `prototype-r4/` (grepped) and never registered with Drupal. `git diff --check`
clean; `graphify update .` re-run (5986 nodes, exit 0).

- **Implementation authority** — the driver subclass itself: **not required to keep this artifact**;
  **required** to adopt it.
- **Activation authority** — the `$databases`/settings change to load it: **not requested**.
- **Verification authority** — an isolated database: **not requested**; cannot substitute for the
  production-contract decision.

Admission enforcement, enrollment/backfill, and G-CAPACITY remain deferred. Admission remains
inactive; **R2 remains PARTIAL; Task 24C remains incomplete; Task 24B remains PARTIAL; real-database
verification remains NOT RUN.**

**Completed:** the R4 candidate; `pass=36 fail=0` comparative demonstration with exit-code assertions;
parity + inertness verification.
**Pending:** the production-contract decision; the indeterminate-connection question of §4.
**Blocked:** driver activation and real-database verification — both need authority not granted here.






