# Task 24C — Enforce one active booking intent and safe hold expiry

You are Claude Code CLI in C:/Users/mo737/ppu. Read ../RULES.md and ../README.md. Prerequisites: 24A and 24H.

## Objective and current defects

Close E05/E06. Resource/day locks prevent overlapping claims, but do not prevent two different non-overlapping bookings for the same submission. existingAttempt and staff liveClaimForSubmission perform unlocked admission reads. Hold renewal uses request-start time outside the calendar guard; pending checkout lookup ignores hold validity.

## Files

- src/Checkout/CheckoutAttempt.php; CheckoutAttemptStore.php
- src/Commerce/DispatchCheckoutManager.php
- src/Dispatch/DispatchPlanner.php; DispatchBooking.php; DispatchOwner.php
- src/Dispatch/Storage/DatabaseReservationStore.php; ResourceCalendarGuard.php; ReservationStoreInterface.php
- custom_webform_handlers.install and services YAML
- A small booking-intent/lease store if needed; reuse the attempt/store model where possible.

## Execution

1. Reproduce with isolated synthetic records: same submission and same intent twice; same submission with different slots/quotes; staff vs public; requests crossing midnight/different resource-days.
2. Introduce a database-enforced single active intent per job/submission at the shared admission seam. A unique nullable active key or small keyed admission row is preferable to a global application mutex. Check existing duplicates before adding constraints; report them rather than deleting.
3. Define when an intent opens, becomes confirmed, expires, is superseded or is cancelled. Non-overlapping slots do not justify duplicate reservations for one job.
4. Establish one documented lock order shared by admission, calendar, settlement and staff mutations. Avoid starting a calendar lock then taking an admission lock in one path and reversing it elsewhere.
5. Evaluate hold validity with current wall-clock time inside the authoritative mutation boundary. Renewal must not revive an expired hold or overlap capacity claimed after expiry. Release/expiry must remain conditional on current state.
6. Pending checkout reuse must verify a valid hold or durable settled reservation. Expired attempts need deterministic cleanup/new-intent behavior, not an endless redirect to an unusable order.
7. Bound abandoned holds and renewal lifetime. Browser heartbeat or a tracking code must not indefinitely monopolize the only driver. Payment-in-progress and unknown outcomes use the explicit state policy introduced in 24D/24E.

## Verification and completion

Use two bounded CLI/browser sessions with local fixtures and an explicit barrier. Demonstrate one active intent for all same-job races, one capacity winner for overlap, no renewal after expiry, no stranded pending attempt, and no leaked draft orders/holds after losing admission.
Include an expired-hold race where another request books the released capacity before a delayed renewal runs.
Check unique indexes and transaction rollback directly. Record created synthetic IDs and cleanup only those.

## Red lines

No paid calls, public countdown, global fleet lock, uniqueness on all historical submission rows, or deletion of conflicting customer records. A cache lock alone is insufficient as the durable uniqueness authority.
