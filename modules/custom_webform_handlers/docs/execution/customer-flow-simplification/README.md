# Simple customer booking flow execution pack

The customer experience should be small even when the dispatch engine is not.
The server continues to collect, validate, price, schedule, reserve, and audit
operational facts. The public flow asks only for facts required now and reveals
detail only when the selected service requires it.

## Recommended public flow

### Screen 1 — Where and what

1. Pickup address
2. Delivery address
3. Service cards, with **Express Courier** first
4. One short service fact, such as package type/weight or delivery mode

The customer should reach a quote with only these facts. Do not ask for date,
contact information, access conditions, stairs, parking, notes, or payment
before the system knows the route and eligible service.

### Screen 2 — Price and pickup choice

Show one recommended safe option prominently:

```text
Express Courier
$XX.XX
Earliest safe pickup: Today, 11:00–11:30 AM
[Choose this time]
[See other available times]
```

The additional-times control reveals a small list or a date chooser only after
the customer asks. Do not show unavailable hours, driver locations, other
customers, or a complex calendar by default.

### Screen 3 — Contact and payment

Collect name, mobile number, and email, then enter the existing Commerce
checkout. Show a concise final summary: route, selected pickup promise, service,
and total. Payment success follows the current synchronous confirmation path.

## Progressive details

Keep advanced facts behind an optional “Add delivery instructions” control. A
field becomes required only when its service rule requires it:

| Situation | Ask now |
| --- | --- |
| Courier Send | Item type/weight; instructions only when required |
| Courier Receive | Receiver/contact and delivery instructions when required |
| Store Pickup | Store name/order reference and receiving contact |
| Heavy, oversized, or multi-person move | Manual Review, not a longer instant-booking form |

Existing Webform fields may remain server-side and conditional. Do not delete
operational data or force every service through the Courier journey.

## Simplicity rules

- One primary action per screen: **Get quote**, **Choose pickup**, then **Pay**.
- Express Courier is first and may be preselected only when eligible.
- Use direct labels: “Pickup address,” “Delivery address,” “What are you
  sending?”, and “Earliest available pickup.”
- Do not expose capacity, route calculations, driver position, other bookings,
  internal review reasons, or hidden price inputs.
- A route change clearly invalidates the old quote and pickup offer.
- Date, slot, and package/service changes reuse the current route fact and do
  not trigger a distance-provider call.
- Preserve `America/New_York` public display, UTC storage, no public timezone
  selector, private Holds, and no public hold countdown.
- If a request is not self-bookable, show one clear outcome: “We’ll review this
  request and contact you,” using the existing Review Request lifecycle.

## Task sequence

### Task 23A — Minimum-data flow audit

Read-only field-by-field inventory. Identify each public field's machine name,
server consumer, and whether it is needed for immediate quote, eligibility,
scheduling, checkout, dispatch, or only Manual Review. This is the recommended
next customer-facing task. Use
[`task-23a-flow-audit/PROMPT_SPEC.md`](task-23a-flow-audit/PROMPT_SPEC.md).

### Task 23B — Route-first Courier flow

Use the Task 23A map to implement the three screens with existing Webform,
handler, dispatch-slot, and Commerce authority. Do not create a second booking
application or a frontend framework. Use
[`task-23b-minimum-quote-flow/PROMPT_SPEC.md`](task-23b-minimum-quote-flow/PROMPT_SPEC.md).

### Task 23C — Conditional details and Manual Review

Show delivery-mode details only when needed. Unmapped operational complexity,
heavy items, and multi-person work route to Manual Review instead of making the
fast Courier path long.

### Task 23D — Acceptance and rollout

Record an evidence-backed acceptance matrix: field preservation, accessibility,
mobile flow, one-driver availability, route-call count, review routing, and
synchronous confirmation. Do not claim browser acceptance unless it was actually
performed with approved tooling.

