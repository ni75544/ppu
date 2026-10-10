# Task 22C — Calibrate chronological feasibility, not a fleet optimizer

You are Claude Code CLI working after Task 22A has proved a specific planner or
storage gap and the user approved that scope.

## Objective

Make one-driver feasibility exact, conservative, and explainable. A public Slot
is offered only when its complete Dispatch Window fits between the immediately
previous and next confirmed constraints for the same Resource.

## Required behaviour

Every candidate includes previous-delivery/depot → new-pickup repositioning and
buffer, pickup handling, customer-route duration, delivery handling,
operational/rest buffer, and new-delivery → next-pickup/depot repositioning and
buffer. It must fit the shift, blackouts, capacity, and horizon. The first
returned offer is the earliest feasible one; confirmed work is never reordered.

Use Task 21A fixed route facts in DDEV and approved prepared/cache-first facts in
production. Do not issue a provider request per visible candidate or any provider
I/O inside the resource calendar guard.

## Expected seams

Confirm actual seams before editing. Likely candidates:

```text
src/Dispatch/DispatchPlanner.php
src/Dispatch/SlotPlan.php
src/Dispatch/Route/PreparedRouteFacts.php
src/Dispatch/Route/DistanceProviderRouteEstimator.php
src/Dispatch/Storage/DatabaseReservationStore.php
src/Dispatch/Storage/ResourceCalendarGuard.php
src/Dispatch/Policy/ConfigServiceDurationPolicy.php
```

Keep one small planner interface: callers request feasible offers; they do not
perform predecessor/successor arithmetic. Persist UTC instants; convert to local
New York time only at the Resource-day edge. Do not disclose other customers'
addresses or schedule details. Unknown data gives no self-bookable offer and may
lead to Manual Review. Do not add fields unless Task 22A and DBHub prove the
existing facts cannot enforce the invariant.

## Direct verification

Use only temporary local diagnostics and remove them. Demonstrate that an 8:00
job blocks a 9:00 request when the full sequence does not fit; the first later
feasible grid time is offered; a candidate preserves time before the next job; a
blackout/shift end removes an offer; unknown route/duration removes an offer; and
a competing claim is refused by the guard. Run allowed lint/YAML/cache/Drush/diff
checks only. No tests, browser automation, live providers, payments, or commits.

