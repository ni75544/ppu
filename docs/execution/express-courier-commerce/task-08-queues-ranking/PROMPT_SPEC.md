# Task 8 — Add Advanced Queue jobs, Redis integration, ECA reactions, and ranking

You are Claude Code CLI executing Task 8 for the Drupal 10 Express Courier and
Commerce roadmap in `C:\Users\mo737\ppu`. Tasks 1–7 must be complete.

## Objective

Move non-authoritative background work to Advanced Queue, use Redis for the workloads
it safely owns, configure ECA as a reaction layer, and add stable popularity ranking
while keeping Express Courier pinned first.

## Architecture constraint

Advanced Queue's launch backend is SQL for durable job state, retry history, and
administrative visibility. Redis is used for Drupal cache/lock, route-cache data,
short-lived ranking/read caches, and worker coordination. Do not implement a custom
Advanced Queue Redis backend in this task unless a maintained, security-covered,
Drupal-10-compatible backend was documented and approved in Task 2.

## Target files

```text
modules/custom_webform_handlers/src/Plugin/AdvancedQueue/JobType/
modules/custom_webform_handlers/src/Dispatch/Notify/
modules/custom_webform_handlers/src/Ranking/
modules/custom_webform_handlers/src/Event/
modules/custom_webform_handlers/config/install/
modules/custom_webform_handlers/config/schema/
modules/custom_webform_handlers/custom_webform_handlers.services.yml
modules/custom_webform_handlers/custom_webform_handlers.module
ECA model/config exports under the site's configuration-sync directory
.ddev/ worker configuration only if a dedicated worker process is added
docs/operations/dispatch-workers.md
```

## Required implementation

1. Replace the custom module's core QueueWorker notification path with Advanced
   Queue JobType plugins. Migrate safely; do not enqueue every event into both old
   and new workers.
2. Implement bounded, serializable, idempotent job payloads for:

   - customer/admin notifications;
   - traffic-aware route-matrix/cache precomputation;
   - nightly Service Type popularity recomputation.

   Payloads carry IDs and event versions, not customer profiles, raw form answers,
   API keys, Hold tokens, or entire entity objects.
3. Configure retry/backoff and permanent-failure classification. Record notification
   outcomes in the existing audit log without making that log a capacity authority.
4. Keep these operations synchronous and outside every queue: current quote needed
   for checkout, offer feasibility, Checkout Attempt creation, payment success,
   Reservation confirmation, release, Replan, and public availability invalidation.
5. Add a popularity reader with a small Interface. Recompute scores nightly from
   completed/paid Commerce dispatch orders using a documented rolling window,
   minimum-sample threshold, and deterministic tie-breaker. Store aggregate counts
   only.
6. Express Courier is always pinned at rank 1. Rank the remaining enabled Service
   Types by the aggregate score; never hide a Service or reorder an in-progress
   checkout.
7. Cache ranking and route-matrix read data in Redis with bounded TTLs and versioned
   keys. A Redis miss/failure falls back safely to configured order or synchronous
   route calculation; it cannot create capacity or change a paid order.
8. Emit domain events only after authoritative commits. Configure ECA 2.1 models to
   react to Review Request transitions and confirmed Reservations for messaging or
   staff workflow. ECA actions must call public/narrow module Interfaces and must not
   write planner tables directly.
9. Configure a repeatable Advanced Queue worker command/process and document health,
   retry, failure inspection, and restart procedures.

## Verification

- Do not create or run automated tests. PHP-lint touched files and inspect ranking
  stability and job-idempotency paths directly.
- Run a bounded worker invocation and verify success/retry/failure states without
  printing payload secrets.
- Run:

```bash
ddev drush cr
```

Stop Redis temporarily and verify the homepage retains configured deterministic
Service ordering and authoritative checkout remains available or fails safely.
Verify replaying a job does not send duplicate customer messages. Stop before
legacy cutover.
