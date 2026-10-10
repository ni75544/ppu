# Task 24D — Make local reservation settlement atomic and recoverable

You are Claude Code CLI in C:/Users/mo737/ppu. Read ../RULES.md and ../README.md. Prerequisites: 24B and 24C.

## Objective and current defects

Close E03/E04/E09 at the database boundary. Current confirmation commits the reservation, forgets the session token, captures payment, settles the attempt and links the order in separate steps. Cancellation can settle during those gaps. A process crash can leave a confirmed reservation with a pending/unlinked attempt.

## Files

- src/Commerce/DispatchCheckoutManager.php
- src/Checkout/CheckoutAttemptStore.php; CheckoutAttempt.php; CheckoutState.php
- src/Dispatch/DispatchPlanner.php; DispatchCheckoutPlannerInterface.php
- src/Dispatch/Storage/DatabaseReservationStore.php; ResourceCalendarGuard.php; LockedHold.php
- src/Dispatch/DispatchOwner.php; Checkout/PaidDispatchReference.php
- custom_webform_handlers.install and services YAML
- Small settlement coordinator/operation record if needed; avoid replacing existing planner.

## Execution

1. Document allowed states and failure points for authorization, local confirmation, capture, cancellation and reconciliation. Keep reservation state separate from payment outcome.
2. Prepare route facts and validated payment evidence before locks. Bind confirmation to the accepted snapshot and the durable intent identity.
3. In one short database transaction, acquire the shared admission/calendar locks in the agreed order, reread current states, revalidate feasibility with prepared facts only, confirm capacity, and persist attempt/reservation/order linkage plus the next payment-operation intent.
4. Prove that all writes use the same database connection and that nested Drupal transaction scopes actually roll back together. No early refusal after a partial write may commit a half-transition.
5. After commit, let 24E perform capture/void/reconciliation. An unknown payment result keeps a visible, recoverable state and a deterministic capacity policy.
6. Cancellation competes through the same state machine. It cannot mark an attempt cancelled while another path is confirming/capturing without a defined compensating state.
7. Retry confirmation by durable attempt/reservation identity. Already-committed success must be recoverable without the original browser session or hold token. Recheck idempotent success inside the lock when concurrent requests change state after the pre-read.
8. Persist the basis for eventual notification delivery within the business transaction; delivery remains outside it and is completed in 24J.

## Verification and completion

Inject controlled local failures immediately before/after each write and after commit. Assert either all local booking references exist consistently or none do.
Reproduce confirm vs cancel, duplicate confirm, loss of session after commit, and crash before payment dispatch. Every retry must return the existing truth and allocate no extra capacity.
Instrument critical sections: zero HTTP, zero routing/geocoding, zero payment operations, zero queue/mail calls.
Run direct lint/schema/Drush/diff verification.

## Red lines

Do not wrap the remote gateway call inside a DB/calendar lock. Do not claim distributed atomicity or exactly-once remote execution. No queued final capacity allocation, newly fabricated PaidDispatchReference, automatic capacity release on ambiguous capture, or rewrite of accepted snapshots.
