# Task 24C-1 — Booking-admission foundation (inactive, source-only)

Status: **Foundation implemented; admission enforcement inactive.**
Scope: source-only. No migration, no schema creation, no caller activation, no commit.

## 1. What this stage delivers

A per-submission **booking admission** — one durable row per saved Webform
submission that answers "is this job already being booked, and by whom" — as a
registered-but-inert service. Nothing calls it yet. It creates no schema, runs no
hook, subscribes to no event, and issues no query from its constructor. Explicit
invocation without the backing table fails closed (throws
`AdmissionSchemaMissingException`) rather than returning an admission.

Admission is **job exclusivity**, not driver capacity. It is kept in its own store
precisely because the two live capacity families have different writers and
different unique keys, so neither can gate the other, while a single per-submission
record can (see §8).

## 2. Files delivered

| File | Role |
| --- | --- |
| `src/Dispatch/Admission/BookingAdmissionStore.php` | The store (sole executable artifact of substance). |
| `src/Dispatch/Admission/BookingAdmissionStoreInterface.php` | Contract + `TABLE`/owner-kind constants. |
| `src/Dispatch/Admission/AdmissionState.php` | `pending`/`confirmed`/`reconciliation_required`/`released` enum. |
| `src/Dispatch/Admission/AdmissionOutcome.php` | Outcome vocabulary + `isRefusal()`/`isAdmission()`. |
| `src/Dispatch/Admission/AdmissionResult.php` | Outcome + snapshot + reason; `isAdmitted()`/`isRefused()`. |
| `src/Dispatch/Admission/AdmissionRecord.php` | Immutable row snapshot; `fromRow()` returns NULL on a corrupt row. |
| `src/Dispatch/Admission/AdmissionSchemaMissingException.php` | Fail-closed signal when the table is absent. |
| `custom_webform_handlers.services.yml` | Inert `custom_webform_handlers.booking_admission_store` registration. |
| `docs/.../task-24c-booking-admission/FOUNDATION-24C-1.md` | This document. |

The supporting value types and enums existed prior to this stage; the store body
and the service registration are the substantive additions here.

## 3. Proposed schema (documented only — `.install` is untouched)

Table `custom_webform_handlers_dispatch_booking_admission`. **One row per
submission, ordinary PRIMARY KEY on `submission_id`.** No secondary indexes and no
generated columns in this stage — deferred deliberately (see §8 on why a generated
active-key column belongs on a capacity table, not here).

| Column | Type (proposed) | Null | Notes |
| --- | --- | --- | --- |
| `submission_id` | INT UNSIGNED | no | PRIMARY KEY. Stable anonymous job identity = the saved Webform submission id. |
| `generation` | INT UNSIGNED | no | Starts at 1; incremented only on a legitimate re-arm from `released`. Never reset. |
| `state` | VARCHAR(32) | no | One of `pending`, `confirmed`, `reconciliation_required`, `released`. |
| `owner_token_hash` | CHAR(64) | no | sha256 hex of the server-minted owner secret. The secret itself is never stored. |
| `intent_fingerprint` | VARCHAR(255) | no | Deterministic hash of booking intent. Matched in full (not truncated). Not an authenticator. |
| `owner_kind` | VARCHAR(16) | no | `public` \| `staff` \| `manual`. |
| `note` | VARCHAR(255) | yes | Reconciliation reason / release evidence, bounded to 255 chars by the store. |
| `created_at` | INT | no | Request time at first insert. |
| `updated_at` | INT | no | Request time at last mutation. |
| `confirmed_at` | INT | yes | Set when confirmation is recorded; cleared on re-arm. |
| `released_at` | INT | yes | Set on release; cleared on re-arm. |

Sizing note: `intent_fingerprint` is stored and compared at full width; callers
should mint a fixed-width hash (e.g. 64-char sha256 hex). The migration should size
the column to the chosen fingerprint at activation time.

## 4. Migration requirement (not performed here)

- A future, versioned update hook — proposed `hook_update_10048`, to be confirmed as
  (current max + 1) at activation; QA is at `update_10047`, `ppu` at `10046` —
  creates the table above via `hook_schema()` + `$schema->createTable()`.
- `.install` is **left unchanged** in this stage; no executable hook is added.
- A fresh install must gain the table through `hook_schema()` as well, since a fresh
  install stamps the schema version and runs no historical update hook.
- Until that hook runs, the store fails closed on every entry point.

## 5. Lifecycle state machine

```
 acquire (no row) ─────────────▶ pending
                                   │  └── requireReconciliation ─┐
                                confirm                          │
                                   ▼                             ▼
                               confirmed ─ requireReconciliation ─▶ reconciliation_required
                                   │                             │
      release (evidence) ─────┬────┴──────────────┬─────────────┘
      from any live state     ▼                   ▼
                                    released
                                       │
                                       └── acquire ──▶ pending  (generation + 1)

 NOTE: confirm() accepts ONLY a pending row (idempotent on an already-confirmed
 row). It REFUSES reconciliation_required and released with RefusedState /
 reason "not_pending". A reconciliation_required row therefore never returns to
 confirmed or to pending; its only exit is release() → released.
```

- **pending → confirmed**: `confirm()`, idempotent at the expected generation.
- **pending/confirmed → reconciliation_required**: `requireReconciliation()`, a
  *live* transition — exclusivity is retained — requiring a non-empty reason.
- **confirmed → reconciliation_required (one-way)**: reconciliation is reachable
  from confirmed, but `confirm()` does **not** accept a `reconciliation_required`
  row — it refuses with `RefusedState` / reason `not_pending` (confirm only ever
  transitions a *pending* row, and is idempotent on an already-`confirmed` one).
  A `reconciliation_required` row therefore never returns to `confirmed` or to
  `pending`; its sole exit is `release()` → `released`.
- **pending/confirmed/reconciliation_required → released**: `release()`, requires
  non-empty evidence, keeps the row, and **does not** increment the generation.
- **released → pending**: `acquire()` re-arms on `generation + 1`. This is the only
  edge that changes the generation, and `released` is the only state it may start
  from.

`pending`, `confirmed`, and `reconciliation_required` are all **live** (job still
owned and exclusive). `released` is the sole non-live state. Nothing here is
time-based: age, a lapsed Hold, and a missing recorded authorization never move a
row to `released` — only an explicit, evidence-backed `release()` does.

## 6. Ownership and idempotency

- Ownership rests on a **server-minted secret** (`mintOwnerSecret()` →
  `bin2hex(random_bytes(32))`). Only its sha256 hash is stored; comparison is
  constant-time (`hash_equals`).
- The **intent fingerprint** is a deterministic hash of the booking intent used to
  recognise same-intent retries. It is explicitly **not** an authenticator: a
  matching fingerprint under a mismatched owner is still refused (`RefusedOwner`).
- Idempotency: a live row with the **same owner and same intent** is reused
  (`Reused`); a **different intent** under the same owner is refused
  (`RefusedIntent`) rather than silently retargeted; a **different owner** is
  refused (`RefusedOwner`).
- **Fresh admission vs. reuse — the caller's authorization gate.** `acquire()`
  returns `Admitted` **only** for a brand-new grant (first insert at generation 1,
  or a re-arm from `released` at generation + 1). On any *live* row
  (`pending` / `confirmed` / `reconciliation_required`) the same-owner + same-intent
  call returns `Reused` with the state and generation **unchanged**. Both satisfy
  `isAdmitted()` (both are `isAdmission()`), so `isAdmitted()` answers only "does
  this owner hold a live admission" — it is **not** authorization to create a new
  downstream effect. A caller about to mint another hold, order, or payment must
  gate on `AdmissionResult::isFreshAdmission()` (i.e. `outcome === Admitted`); a
  `Reused` result means "you already hold this — do not act again". The store draws
  the distinction; **suppressing the duplicate downstream side effect is an explicit
  integration responsibility** of the binding caller and is **not** proven by any
  store-only probe (runtime evidence: VERIFICATION-24C-2A §G).
- Every mutation checks, in order: submission id > 0, table present, the row under
  `FOR UPDATE`, owner match, expected generation, and a permitted prior state.
- Future Hold / Attempt / Reservation bindings **must carry the generation** the
  admission was at when minted, so a later re-arm (which increments the generation)
  invalidates a binding minted against an earlier one.

## 7. Transaction ownership and the admission-before-calendar lock order

- **Self-contained mutations** (`acquire`, `confirm`, `requireReconciliation`,
  `release`) each open their own transaction, take the admission row under
  `FOR UPDATE`, run a generation + prior-state CAS, and commit. A duplicate-key
  insert race on the PRIMARY KEY is caught and re-resolved by re-reading the
  committed winner under `FOR UPDATE` (`resolveRace()`); InnoDB does not abort the
  surrounding transaction for a caught constraint violation.
- **`verify()` is the binding primitive** and is *non-mutating by design*. A future
  binding writer calls it **inside the writer's own transaction** so the admission
  row's `FOR UPDATE` lock is **held across the binding write**. This is no longer a
  convention the caller must remember: as of Task 24C-2A, `verify()` calls
  `assertInTransaction()` after `assertSchema()` and **before any read**, throwing
  `\LogicException` when `Connection::inTransaction()` is false. Called with no
  ambient transaction it refuses before it reads — it never opens a transaction of
  its own and never issues the `FOR UPDATE` select — so it cannot silently degrade
  to an unlocked point-in-time answer a caller might mistake for a lock it still
  holds. (Proven at runtime, not just claimed: VERIFICATION-24C-2A §A.)
- **Lock order — admission before calendar.** A binding writer must:
  1. open its transaction;
  2. `verify()` the admission → acquires the admission row lock;
  3. **then** take the `ResourceCalendarGuard` calendar lock (resource_id / local_date);
  4. write the binding, carrying the verified generation;
  5. commit (releasing admission and calendar locks together).

  This single global ordering — **admission lock first, calendar lock second** —
  must be honoured by every writer to avoid lock-order-inversion deadlock, and it
  serialises a re-arm (generation bump) against binding creation.
- `find()` is a plain unlocked read for reporting; it takes no lock and must not be
  used as a gate.

## 8. Job exclusivity vs. driver capacity — why a dedicated table

Two live capacity families exist, with different writers and different unique keys;
**neither can gate the other, and a single capacity table cannot bind both**:

| Family | Writer | Unique key | Submission-scoped? |
| --- | --- | --- | --- |
| `custom_webform_handlers_dispatch_reservation` | `DatabaseReservationStore` | guard-serialized by resource_id / local_date | No submission-unique key. |
| `custom_webform_handlers_reservation` | `DatabaseReservationManager` | slot-scoped unique | No per-submission gate. |

`custom_webform_handlers_checkout_attempt` cannot host admission either: it is the
**public-path table only**, and staff / manual / replan bookings bypass the checkout
attempt entirely (hence `owner_kind ∈ {public, staff, manual}`). A per-submission
job-exclusivity record therefore needs its **own** table —
`custom_webform_handlers_dispatch_booking_admission` — that outlives any single Hold,
Attempt, or Reservation the job passes through. This is also why **no generated
active-key column** lives here: an active-key / dedup column belongs on the capacity
table whose writes it constrains, not on the job-exclusivity record.

## 9. Fail-closed and inactivity guarantees

- **Fail closed on absent schema.** Every entry point calls `assertSchema()` first;
  if `tableExists()` is false it throws `AdmissionSchemaMissingException`. A
  *transient* inspection error also throws (it is **not** memoized); only a definite
  present/absent result is cached in `?bool $schemaPresent`. An absent table never
  degrades to an "admitted" answer.
- **Fail closed on corrupt rows.** `AdmissionRecord::fromRow()` returns NULL for an
  unreadable state or a non-positive submission id; the store logs it and turns it
  into a refusal (`RefusedState`), never a live or a released admission.
- **`isRefused()` is not the negation of `isAdmitted()`.** A caller deciding "may I
  book" must test `isAdmitted()` positively; `Confirmed`, `Released`, `Verified`,
  and the transition outcomes are neither an admission grant nor a refusal.
- **Inactive registration.** The service has no tag, no caller, no subscriber, no
  hook, and no constructor query; it creates no schema. It is `public: true` only so
  a future authorized caller can be wired without reopening the services file. The
  container compiles it (verified by `drush cr`) but instantiates it for no one.
- **No flow touched.** Neither booking flow, the payment flow, renewal, nor cron is
  modified by this stage.

## 10. Renewal correctness without cron

Admission has **no time-based expiry**, so renewal correctness does not depend on a
background job. A `pending` admission is resolved only by an explicit `confirm()`,
`release()`, or `requireReconciliation()`, or by a re-arm from `released`; its
generation CAS and explicit lifecycle carry every invariant on their own.

A sweep, **if** one is ever added, is for operator *visibility and cleanup* of
abandoned pendings (e.g. Class A → failed, Class B → reconciliation_required) — it is
**not** the synchronization mechanism and must never be relied on for correctness.

## 11. Integration gates required before activation (requirement 5 — UNRESOLVED)

None of the following is satisfied by this stage; each must be covered before any
caller is wired:

1. **Both capacity families + real routing.** Admission must be validated against
   `dispatch_reservation` (via `DatabaseReservationStore`) *and*
   `reservation` (via `DatabaseReservationManager`), under actual routing conditions
   — not one family in isolation.
2. **Safe anonymous-job identity.** Confirm `submission_id` is a stable, safe job
   identity for anonymous bookings, including how a not-yet-persisted submission is
   handled (admission requires a saved submission id).
3. **Pre-existing in-flight records.** Behaviour against existing
   pending / held / confirmed / reconciliation records at activation time
   (coexistence and any backfill).
4. **Conflict reporting.** `RefusedOwner` / `RefusedIntent` / `RefusedState` /
   `RefusedGeneration` / `NotFound` must be surfaced to operators/UX, not swallowed.
5. **Failure compensation.** Defined compensation when a downstream binding write
   fails after `verify()` (how the admission is released or reconciled).
6. **Renewal without cron** must hold end-to-end, and a sweep must not be its
   synchronization mechanism (see §10).

## 12. Verification performed

All checks run against the already-running DDEV container via `docker exec`
(`ddev exec` is avoided — it recreates web+db and breaks mutagen). No infrastructure
was started or recreated; no automated suites or fixtures were run.

| Check | Command (abridged) | Result |
| --- | --- | --- |
| PHP syntax, all 7 admission files | `docker exec ddev-ppu-web … 'for f in *.php; do php -l "$f"; done'` | `No syntax errors detected` ×7 |
| services.yml parses + block present | `Yaml::parseFile(...services.yml)` + isset check | `PARSE_OK: booking_admission_store present` |
| Whitespace / conflict markers | `git diff --check` | `EXIT=0` (only a benign LF→CRLF advisory on unrelated `.gitignore`) |
| Container loads the inert service | `docker exec ddev-ppu-web … drush cr` | `[success] Cache rebuild complete.` `EXIT=0` |

Unrelated dirty changes were preserved; the only tracked modification introduced by
this stage is the inert service block in `custom_webform_handlers.services.yml`, with
the new `src/Dispatch/Admission/` files untracked.

---

**Foundation implemented; admission enforcement inactive. Task 24C incomplete; Task 24B remains PARTIAL.**
