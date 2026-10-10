# Task 22E — Confirm capacity synchronously at payment success

You are Claude Code CLI working after Task 22A. Do this only if the audit finds a
gap in the existing hold, confirmation, or calendar-guard path.

## Objective

Guarantee that a Resource with capacity `1` cannot receive overlapping confirmed
Reservations, even if two customers reach payment almost simultaneously.

## Required transaction

At successful payment, identify the private checkout attempt and selected Slot;
acquire the resource/day calendar guard; read current valid neighbours; validate
the prepared SlotPlan and price snapshot without external I/O; then atomically
confirm the Reservation and attach it to the paid order, or safely refuse because
capacity changed. Notifications run after commit.

The customer never sees a Hold timer. A failed/expired Hold is never presented as
a confirmed Reservation.

## Expected seams and constraints

Confirm actual callers in the checkout manager/starter, `DispatchPlanner`,
reservation store, calendar guard, Hold, result, and confirmed-event seams. Do
not perform routing/geocoding, queue, mail, browser, or provider work inside the
transaction. Do not double-charge, create duplicate orders, or overwrite a prior
confirmed Reservation. Persist UTC only; display New York local time.

## Verification

Use direct local, non-payment, non-provider evidence: a valid Hold confirms; a
stale Hold refuses; a competing confirmation refuses without changing the first;
and the guarded code path cannot call a route provider. Follow root evidence and
verification rules. No suites, browser automation, live routing, payments, or
commits.

