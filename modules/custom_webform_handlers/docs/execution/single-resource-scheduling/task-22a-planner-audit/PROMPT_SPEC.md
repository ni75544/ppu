# Task 22A — Audit the one-resource planner before changing it

You are Claude Code CLI working in `C:\Users\mo737\ppu`.

## Objective

Establish whether the existing dispatch planner already enforces a safe
one-driver timeline. Produce an evidence-backed gap list and a minimal follow-up
recommendation. This task is read-only: do not change source, configuration,
documentation, Git state, Drupal state, DDEV, credentials, containers, or
external services.

## Product rule to audit

The initial Resource has capacity `1`. A candidate pickup time is valid only if
the driver can finish the prior Dispatch Window, reposition from its delivery or
the depot to the new pickup, perform pickup handling/customer route/delivery
handling/rest buffer, and still reach the next confirmed pickup or required depot
return inside New York-local work hours. Unknown route or duration facts must
deny the offer, never become zero minutes. Payment confirmation must atomically
reread the same capacity without provider I/O.

## Required inspection

Read root `CLAUDE.md`, RTK instructions, `CONTEXT.md`, Task 21A's actual diff,
and the scheduling-pack README. Preserve all dirty Task 21A files.

Inspect at minimum:

```text
src/Dispatch/DispatchPlanner.php
src/Dispatch/ResourceProfile.php
src/Dispatch/ResourceProfileFactory.php
src/Dispatch/SlotPlan.php
src/Dispatch/OccupiedInterval.php
src/Dispatch/Route/DistanceProviderRouteEstimator.php
src/Dispatch/Policy/ConfigServiceDurationPolicy.php
src/Dispatch/Storage/DatabaseReservationStore.php
src/Dispatch/Storage/ResourceCalendarGuard.php
src/Checkout/DispatchCheckoutStarter.php
src/Commerce/DispatchCheckoutManager.php
config/install/custom_webform_handlers.dispatch.yml
custom_webform_handlers.services.yml
```

Use Graphify first for PHP paths; GitNexus for impact; direct YAML inspection for
Drupal wiring; and DBHub's read-only account for actual reservation/guard table
and index facts. Report an unavailable mandatory evidence system as a blocker,
not permission to guess.

## Questions to answer with evidence

1. Which configured Resource handles Express Courier, and is capacity actually
   `1`?
2. Does each offer check predecessor and successor feasibility, including depot
   legs when required?
3. Which persisted fields hold the UTC Dispatch Window, repositioning, route
   duration/distance, price snapshot, and state?
4. Which operation locks the Resource/day and revalidates at payment success?
5. Can a route provider run under that lock?
6. Where do handling duration, traffic policy, rest/operational buffer,
   blackouts, shifts, and Manual Review enter the decision?
7. Is the outcome chronological earliest-feasible planning, with no automatic
   reordering of confirmed work?
8. What is the smallest possible change, if any?

## Reporting and stop boundary

Run only read-only source, Git, DBHub, Graphify, GitNexus, and non-mutating
Drush inspection. Do not run tests, browser automation, provider calls, or
database writes.

Report current branch/HEAD and dirty-file ownership; one prior-job → candidate
job → next-job timeline trace; each requirement as `satisfied`, `partial`, or
`gap`; the exact minimal scope for Tasks 22B–22E; evidence-system limits; and
blockers. Stop without editing or committing.

