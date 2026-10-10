# Single-resource scheduling and pricing execution pack

This pack makes the start-up constraint explicit: one active driver and vehicle
is one `Resource` with capacity `1`. It does not replace the current planner
merely because it is sophisticated. The module already includes
`ResourceProfile`, `DispatchPlanner`, `SlotPlan`, reservation-neighbour lookup,
a resource/day calendar guard, blackouts, private Holds, and price snapshots.
The work is to prove that those pieces form a safe one-driver timeline, then
fill only demonstrated gaps.

## Customer promise

A customer chooses a pickup-arrival time, not a fictional one-hour appointment.
The server offers that time only if the Resource can complete the prior job,
reposition to the new pickup, perform the new job, observe an operational/rest
buffer, and still reach the following job or required depot return. A later
customer can never make an earlier confirmed customer impossible to serve.

## Canonical model

- **Resource** — a dispatchable driver and vehicle together. Initially this is
  one configured Resource, such as `driver-01-vehicle-01`, with capacity `1`.
- **Dispatch Window** — the occupied interval from pickup arrival through pickup
  handling, customer travel, delivery handling, and the operational/rest buffer.
  It is not a fixed one-hour slot.
- **Repositioning** — the route and configured buffer from a prior delivery (or
  the depot) to the next pickup. It determines feasibility but is not customer
  work.
- **Route fact** — a versioned distance and duration used by a plan. DDEV uses
  Task 21A's deterministic fixed fact. Production uses only the approved
  cache-first live policy; no database lock performs provider I/O.
- **Slot offer** — a customer-selectable pickup-arrival option derived from the
  whole Resource timeline. It is not capacity authority.

## Feasibility algorithm

For candidate pickup-arrival time `P`:

```text
earliest arrival at pickup =
  previous Dispatch Window end
  + previous-delivery/depot -> new-pickup repositioning
  + repositioning buffer

Dispatch Window end =
  P
  + pickup handling
  + pickup -> delivery route duration
  + delivery handling
  + operational/rest buffer
```

The candidate is feasible only when it fits the Resource shift, capacity,
blackouts, booking horizon, next confirmed job, and optional return-to-depot
rule. Unknown route or duration data removes the offer; it never becomes zero
minutes. The first returned offer is the earliest feasible offer. Confirmed work
is never automatically moved or reordered.

## Pricing algorithm

Pricing and feasibility share a route fact but remain separate policies:

```text
base fare
+ distance charge
+ approved time/congestion modifier
+ service and handling modifiers
+ approved toll/parking modifier
+ urgency or scheduled-time modifier
= customer total
```

Every paid Reservation retains its amount, currency, route facts, and
pricing-policy version. Policy changes must not rewrite historical orders.

## Task sequence

### Task 22A — Audit before changing

Read-only evidence task. Prove which existing code creates capacity-one offers,
checks prior and next neighbours, calculates handling/buffer/repositioning, and
locks/revalidates the Resource during payment confirmation. Produce only a gap
list and an exact minimal scope. Use the prompt in
[`task-22a-planner-audit/PROMPT_SPEC.md`](task-22a-planner-audit/PROMPT_SPEC.md).

### Task 22B — One-driver policy configuration

Only after 22A identifies configuration gaps, configure capacity `1`, depot,
working hours, blackouts, booking horizon, 30-minute grid unless existing
business policy says otherwise, return-to-depot policy, and service-specific
pickup/delivery/rest buffers. Keep the values in typed Drupal configuration; do
not invent unmapped service facts. Unmapped requests go to Manual Review.

### Task 22C — Chronological feasibility calibration

Only after a verified planner gap, make each offered Slot account for both
predecessor and successor repositioning. Use prepared route facts, not one
provider request per visible candidate, and never call a provider inside the
calendar-guard transaction. Validate the 8:00-job / 9:00-request case, blackout
and shift-boundary cases, unknown-route refusal, and concurrent claim refusal.

### Task 22D — Versioned price snapshot

Only after a verified quote/snapshot gap, make a small server-authoritative
policy return integer cents plus a customer-safe breakdown. Persist route facts,
input facts, currency, and policy version. Reuse the existing route fact for
quote-only changes; never trust browser totals or alter a paid amount silently.

### Task 22E — Atomic confirmation

Only after a verified confirmation gap, ensure successful payment locks the
Resource/day, rereads neighbours, validates the prepared plan, and atomically
confirms the Reservation or refuses it. Notifications run only after commit.
There is no public Hold timer and no I/O under the lock.

### Task 22F — Simple dispatch operations

After the planner is authoritative, provide staff a chronological New York-local
timeline for one driver with safe blackout, unavailable, delayed-status, and
replan actions. A replan always returns through the same planner and guard;
drag-and-drop is never authority.

## Non-negotiable rules

- Preserve Task 21A's zero-cost DDEV route mode. Development does not call an
  external or paid routing/geocoding provider.
- Show public time in `America/New_York`; persist instants as UTC.
- Keep Holds private; do not introduce public countdowns or timezone selectors.
- Confirm payment synchronously under the existing resource/day calendar guard.
- Do not build a fleet optimizer for one driver. Chronological interval
  feasibility with predecessor/successor checks is the smallest correct model.
- Task 21A changes are currently uncommitted. Preserve them; never overwrite,
  revert, or commit them without explicit user approval.

