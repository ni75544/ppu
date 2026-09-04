# Dispatch background workers

This module moves work that does **not** decide anything a customer or an operator
depends on being right *now* onto Advanced Queue, and leaves everything that does decide
on the synchronous request path. This document is the operations runbook for that
background work: what runs, how to run it locally, how to watch it, and what does — and
does not — happen when it fails or when Redis is down.

One principle underlies all of it: **the queue is never an authority.** No job here
prices a quote, judges an offer's feasibility, creates a Checkout Attempt, authorizes or
captures a payment, confirms or releases a Reservation, or invalidates public
availability. Those remain synchronous. Every consumer of a job's output has a
deterministic fallback to a configured answer, so a queue that is slow, stopped, or empty
degrades a convenience and never a decision.

## Durability: job state lives in SQL, not Redis

Both queues use Advanced Queue's own durable **`database`** backend (plugin id
`database`, table `advancedqueue`). Job state, retry counts, and failure history are rows
in the site database. Redis on this site is provisioned for Drupal's cache and lock bins,
for route-matrix and ranking read caches, and for worker coordination — **never** for
queue lifecycle. There is no configuration-only Redis backend for Advanced Queue, and a
job whose lifecycle lived in a volatile store could be lost after an authoritative state
change had already committed. See [what happens when Redis is down](#what-happens-when-redis-is-down).

## The two queues

| Queue id | Purpose | Job types | Lease | Cron budget |
| --- | --- | --- | --- | --- |
| `custom_webform_handlers_dispatch` | Post-commit customer notifications | `custom_webform_handlers_dispatch_notify` | 300s | 90s |
| `custom_webform_handlers_maintenance` | Precomputation & nightly aggregation | `custom_webform_handlers_dispatch_route_warm`, `custom_webform_handlers_dispatch_popularity` | 900s | 180s |

They are two queues rather than one because Advanced Queue budgets a cron run **per
queue**: `processing_time` is how long the processor may spend on that queue before it
returns. A slow route-warm batch sharing the dispatch queue's 90-second budget could push
a booking confirmation to the next cron run — an hour, on an hourly cron, before the
customer learns their courier is confirmed. Separate queues make that impossible: a
saturated maintenance queue delays only maintenance.

Both queues are defined in code, in the module's `config/optional`, and are installed
automatically the moment Advanced Queue is enabled. Both are `locked` (they cannot be
deleted from the admin UI) and `stop_when_empty` (a short queue costs a cron run
nothing).

### Job types

| Job type id | Queue | Retries | Retry delay | Duplicates | On success it… |
| --- | --- | --- | --- | --- | --- |
| `custom_webform_handlers_dispatch_notify` | dispatch | 2 | 300s | forbidden | sends one customer notification |
| `custom_webform_handlers_dispatch_route_warm` | maintenance | 1 | 900s | forbidden | warms one Resource's route cache for one local day |
| `custom_webform_handlers_dispatch_popularity` | maintenance | 1 | 1800s | forbidden | replaces the Service Type popularity snapshot |

`allow_duplicates: FALSE` means Advanced Queue fingerprints each job by `(queue, type,
payload)` and collapses a second identical job that is still pending into the first. Every
payload is bounded scalars and a version number — no address, no Webform answer, no
submission, no token, no payment data, no serialized entity — so the queue table, the job
failure history, and any operator's view of either hold nothing they should not.

## How work normally runs

Both queues declare `processor: cron`, so **ordinary Drupal cron drains them.** Every
cron run processes each queue up to its `processing_time` budget. In production this is
the whole story: run cron on a schedule and the background work happens.

```bash
ddev drush cron
```

The nightly popularity recount is also enqueued from `hook_cron`, throttled by a state
timestamp so it is enqueued at most once per ~20 hours regardless of how often cron fires.
An ECA reaction enqueues the same recount opportunistically when a Review Request
transitions; the two drivers overlapping costs nothing, because the job forbids
duplicates and the recount is idempotent. Route warming is enqueued by an ECA reaction
when a Reservation is confirmed. See [where jobs come from](#the-reaction-layer-where-jobs-come-from).

## Running a worker on demand (the repeatable local command)

To process a single queue immediately — the repeatable local worker command — use
Advanced Queue's own drush command. It takes a queue id and a `--timeout` in seconds
(default 90); it processes jobs until the queue is empty or the timeout elapses, then
exits.

```bash
ddev drush advancedqueue:queue:process custom_webform_handlers_maintenance --timeout=180
```

```bash
ddev drush advancedqueue:queue:process custom_webform_handlers_dispatch --timeout=90
```

The `--timeout` is a rough bound: it is only checked between two jobs, so a single job
already in progress runs to completion. Match or undercut the queue's lease (900s
maintenance, 300s dispatch) so a worker cannot still be holding a job past the point where
the lease would let another worker reclaim it.

For continuous local processing, run the command in a loop under a supervisor of your
choice (a shell loop for a scratch session, systemd or supervisord for anything you leave
running). Keep each invocation bounded by `--timeout` rather than writing an unbounded
in-process loop, so every pass releases the database connection and re-reads fresh queue
state:

```bash
while true; do
  ddev drush advancedqueue:queue:process custom_webform_handlers_maintenance --timeout=180
  sleep 60
done
```

## Stopping and restarting a worker

`advancedqueue:queue:process` installs signal handlers when the `pcntl` extension is
available (it is, in DDEV): on **SIGTERM** or **SIGINT** it finishes the job in flight,
declines to claim the next one, and exits cleanly. So:

- **Stop a foreground worker:** press `Ctrl-C` once and wait. It will not abandon a job
  mid-flight. Pressing it twice may kill the process between the database write and the
  lease release, in which case the lease simply expires and the job becomes claimable
  again — no job is lost, and no idempotent job is harmed by a re-run.
- **Stop a supervised worker:** send SIGTERM (`systemctl stop`, `supervisorctl stop`, or
  `kill <pid>` — not `kill -9`).
- **Restart:** start the command again. There is no worker-side state to clear; the queue
  is the state, and it is in the database.

If a worker was killed hard (SIGKILL / power loss) while holding a job, that job stays in
the `processing` state only until its **lease** expires (900s maintenance, 300s dispatch),
after which the next worker or cron run reclaims it. This is why the leases are set to
outlast the slowest plausible run of each queue's jobs.

## Health checks and inspecting the queues

List every queue with a live per-state job count:

```bash
ddev drush advancedqueue:queue:list
```

The `Jobs` column reads `Queued: n | Processing: n | Success: n | Failure: n`. A healthy
system shows near-zero `Queued` and `Processing` shortly after a cron run, a growing
`Success`, and a `Failure` count that is zero or explained.

Read what those columns mean:

- **Queued climbing, Processing at zero** → nothing is draining the queue. Check that cron
  is running (`ddev drush core:requirements`, look at "Cron maintenance tasks") or run a
  worker by hand.
- **Processing stuck above zero** → a worker died holding a job. Wait one lease period; the
  job returns to `Queued` on its own.
- **Failure climbing** → see the next section.

## Retries and permanent failures

A job type distinguishes a **retryable** failure from a **permanent** one, and the two
maintenance jobs draw the line deliberately:

- **Retryable** (returned as a plain failure): the external dependency was briefly
  unreachable — the Google Routes provider for route warming, the database for the
  popularity recount. Advanced Queue re-queues the job after the type's `retry_delay`
  (900s / 1800s) up to `max_retries` (1), then records it as a permanent failure. Both job
  types **return** these failures rather than throwing, precisely so the bounded retry
  happens; a thrown exception is read by Advanced Queue as an immediate permanent failure
  with no retry.
- **Permanent on the first attempt** (failure with retries overridden to 0): the payload
  cannot be read — a version this release does not understand, a missing Resource id, a
  date that is not a date. Retrying would fail identically, so the job is failed once and
  left **visible on the listing** rather than deleted, so an operator can see it.

A permanent failure of any of these jobs changes nothing authoritative. A failed route
warm leaves the day exactly as bookable as it was — the planner asks the provider itself,
synchronously, as it did before this job type existed. A failed popularity recount leaves
the previous snapshot in place, and the ranking's deterministic configured order behind
that.

To retry failed jobs after fixing the underlying cause, re-process the queue; Advanced
Queue's admin UI (**Administration → Configuration → System → Queues**, if the site grants
you access) also lists jobs per queue and can release failed jobs back to `Queued`.

## What happens when Redis is down

Because queue lifecycle is in SQL, a Redis outage does **not** lose jobs, change any job's
state, or stop a worker from claiming and completing jobs. What degrades is only what
Redis actually backs on this site:

- **Cache and lock bins** fall back to their database equivalents (slower, still correct).
- **Route-matrix / ranking read caches** miss, so readers recompute or fall back to the
  configured answer — the route warm job's whole purpose is to populate a cache, so with
  Redis down it simply warms nothing useful, which is a no-op, not a failure.
- **The ranking read cache** misses, but the popularity **snapshot itself lives in
  database-backed State, not Redis**, so it is still read and Express Courier is still
  pinned first — the pin is applied in code while ordering the snapshot, so a Redis outage
  cannot demote it. Only a fully cold ranking (no snapshot yet, or fewer than the minimum
  samples) returns the operator's configured order unchanged, and there Express Courier
  leads only if it is configured that way.

No booking, payment, capacity decision, or Reservation depends on Redis. Restore Redis and
the caches refill on their own; nothing needs replaying.

> **Local note.** Redis is wired into this site's bootstrap container, so it cannot be
> fully removed from the request path without editing settings — a hard stop of the local
> Redis *service* degrades it to the fallbacks above rather than removing it from the
> picture. Treat "Redis down" here as "Redis unreachable", which is exactly the failure
> mode the fallbacks are written for.

## Replay and idempotency

Every job in these queues is safe to run more than once, by construction rather than by
convention:

- **Route warm** re-reads the same claims, asks the provider about the same pairs, and
  writes the same cache entries. No aggregate is incremented and no row is appended, so
  warming an already-warm day counts nothing twice.
- **Popularity recount** issues one grouped query and hands the result to a store that
  **replaces** the snapshot. There is no counter to increment and no row to append, so
  running it five times in a minute leaves exactly what running it once leaves.
- **Notify** carries an idempotency key and records that a message went out, so a replayed
  notification sends nothing rather than a second copy.

Combined with `allow_duplicates: FALSE`, this means a replayed or double-enqueued job
duplicates nothing, and — because no job writes a planner table, a Reservation row, a
Checkout Attempt, a payment entity, or Webform submission data — **a queue failure cannot
change a Reservation, a Checkout Attempt, a payment, or public availability.**

## The reaction layer: where jobs come from

Maintenance jobs are enqueued by an **ECA** reaction layer that listens for two domain
events. Each event is dispatched **only after its authoritative transaction has already
committed**, so a reaction can read what happened and can never change whether it happened:

| Domain event | Emitted when | ECA model | Enqueues |
| --- | --- | --- | --- |
| `custom_webform_handlers.dispatch_reservation_confirmed` | a paid Reservation is settled | `custom_webform_handlers_route_warm` | one route warm for that Resource and local day |
| `custom_webform_handlers.review_request_transitioned` | a Review Request changes state | `custom_webform_handlers_popularity` | one popularity recount |

The ECA actions call narrow module interfaces only (`MaintenanceQueueInterface`); they do
not write planner tables, Reservation rows, Checkout Attempts, payment entities, or Webform
submission data. The nightly popularity recount is **also** driven by `hook_cron` (the
dependable driver, so the snapshot refreshes even on a day with no review transitions); the
ECA path is opportunistic. If ECA is not installed, the events are still emitted and cron
still drives the recount — only the opportunistic enqueue is absent.
