# Task 24F — Unify staff, manual-review and replan integrity

You are Claude Code CLI in C:/Users/mo737/ppu. Read ../RULES.md and ../README.md. Prerequisites: 24B-24E.

## Objective and current defects

Close E10/E11. Staff confirmation omits price snapshot/order facts; review scheduling trusts positive IDs; replan creates a new reservation while checkout/review may still reference the old one. Approval, cancellation and operational release need explicit monetary semantics.

## Files

- src/Dispatch/DispatchOperations.php; DispatchPlanner.php
- src/Dispatch/Storage/DatabaseReservationStore.php; DispatchReplan.php
- src/Form/DispatchManualReviewConfirmForm.php; DispatchReplanForm.php; DispatchReservationReleaseForm.php
- src/Review/ReviewManager.php; ReviewRequestStore.php; ReviewRequest.php; ReviewState.php
- src/Form/ReviewApproveForm.php; ReviewPaymentForm.php; ReviewWithdrawForm.php
- src/Checkout/DispatchCheckoutStarter.php; QuoteSnapshot.php; CheckoutAttemptStore.php
- services, permissions and schema/update hooks only as needed

## Execution

1. Produce a path table for public checkout, approved-review payment, direct staff booking, staff replan and release. For each identify job identity, accepted price, payment authority, calendar authority and actor/audit record.
2. Require staff booking to reference an approved immutable snapshot, or record an explicit authorized unpaid/offline booking with its financial status. NULL price must not look like a paid or free reservation.
3. Use the shared submission admission and calendar settlement from 24C/24D for every capacity mutation. Permissions and UI checks supplement, but do not replace, service-level validation.
4. Define the approval acceptance instant and expiry policy. Persist accepted review revision, price and expiry. Prevent a cancelled/changed offer from silently producing a paid scheduled job; do not automatically revoke an already accepted payment-in-progress offer.
5. Validate review/order/reservation/submission identity and current state before recordScheduled. Preserve failure/reconciliation evidence if one system cannot complete its projection.
6. Add explicit replan lineage: original reservation, replacement, current active reservation and actor/reason. Historical payment evidence stays attached to the original accepted snapshot; current operational pointers resolve the new reservation.
7. Replan using the accepted job unless the customer/staff explicitly creates an amendment. If changed service/time/access facts need a new price, present and accept a new snapshot; never silently alter a paid total.
8. Separate operational release from financial cancellation/refund. Staff action must state which is being performed, require appropriate permission and record a recoverable financial operation outside calendar locks.

## Verification and completion

Check two staff requests for one submission, staff/public competition, review expiration during checkout, approval changed/withdrawn after entry, forged cross-order IDs at the service seam, repeated replans and release of a paid booking.
All paths must have clear financial status and consistent current/historical pointers. A replan preserves the original price record and cannot create two active reservations.
Run allowed direct lint/schema/Drush/diff checks and role-specific manual checks.

## Red lines

No complimentary booking by missing data, automatic repricing, forced refund, edited customer answers to evade approval, or staff bypass of admission/calendar guards. No route/payment call inside locks.
