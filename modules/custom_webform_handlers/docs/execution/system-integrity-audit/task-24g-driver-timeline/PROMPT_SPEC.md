# Task 24G — Align offered slots with the real driver timeline

You are Claude Code CLI in C:/Users/mo737/ppu. Read ../RULES.md and ../README.md. Prerequisites: 24C and 24H.

## Objective and current weaknesses

Address E12/E17 while preserving accepted 22A-22C. Public feasibleOffers uses day-level estimates/padding; authoritative planFor uses neighboring routes. Break checks cover the service window while travel may cross a break. Service-leg departure is currently pickup arrival before loading. Config exposes capacity but the factory resolves one resource and the store rejects all overlaps for that resource.

## Files

- src/Dispatch/DispatchPlanner.php; ResourceProfile.php; ResourceProfileFactory.php
- src/Dispatch/ResourceDay.php; LocalWallClock.php; DispatchWindow.php; ServiceDuration.php
- src/Dispatch/Schedule/ProfileResourceSchedule.php
- src/Dispatch/Route/DistanceProviderRouteEstimator.php; PreparedRouteFacts.php
- src/Dispatch/Storage/DatabaseReservationStore.php; PlanningNeighbour.php
- src/Dispatch/DispatchBooking.php; BookingWorkflowGate.php
- src/Dispatch/Status/DatabaseResourceStatusStore.php and planner settings form
- Read-only first: Reservation/DatabaseReservationManager.php and ReservationReconciliation.php

## Execution

1. Write a small timeline contract for depot travel, pickup/loading, loaded travel, unloading, operational buffer, next-job travel, break/rest and end-of-shift return. Separate raw route time from safety margins.
2. Use the correct departure instant for each traffic-sensitive leg; loading occurs before the loaded leg. Account for latest permissible pickup in an arrival promise rather than silently treating its earliest instant as guaranteed.
3. Check every occupied driver interval against working hours, breaks, blackouts and neighboring commitments. Confirm return-to-base protection and overnight boundaries. Missing neighbor/depot facts must fail safely.
4. Reproduce double-counted/obsolete reposition margins when inserting into a gap or replanning. Preserve safety; reclaim obsolete padding only through guarded recomputation of the affected sequence. Do not silently relocate existing bookings.
5. Share feasibility rules between offers and confirmation. Offers may be provisional but must expose an honest not-ready state when prepared facts are missing; dynamic route requests during date browsing are prohibited.
6. Keep a single resource with capacity one for this release. Reject/clarify misleading capacity>1 settings; two real drivers later require separate calendars. Decide whether the business means append-only or safe gap insertion and document the selected existing policy.
7. Inspect how resource unavailability, lateness and staff status reach booking availability. A decorative “unavailable” status must not imply capacity is blocked unless a guarded blackout or schedule change enforces it.
8. Verify that legacy fixed-slot forms cannot allocate the same physical driver through an independent calendar. Preserve historical records and workflow ownership.
9. Validate NYC timezone configuration, spring-forward invalid wall times, fall-back ambiguity, cross-midnight guard dates and same-day lead time using current time.

## Verification and completion

Use distinct offline route facts for Manhattan/Jersey City legs, not one constant for every leg. Include first/last job, two-mile service with long deadhead, loading delay, exact boundary adjacency, break-crossing travel, overdue driver, cancellation between neighbors, blackout vs claim, midnight, DST dates and changed schedule configuration.
Record expected and actual protected intervals and refusal reasons. Prove no overlap, no API under locks, no date-selection API calls and comparable offer/confirmation outcomes.
Measure candidate/query counts over the configured horizon; keep a bounded deterministic planner.

## Red lines

No fleet optimizer, traffic API during availability lookups, negative/zero “unknown” travel, double-counted margin hidden as policy, or capacity bypass for staff. Do not rewrite accepted scheduling code without a reproduced gap.
