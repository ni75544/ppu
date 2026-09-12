# Fast-pickup courier order flow — proposed architecture

Date: 2026-09-05. Status: proposal for review, not authorization to implement.

## Evidence and limits

This review inspected versioned Webform configuration, the custom module's planning,
pricing, checkout and resource-status code, and the September 5 release record.
It did not run Drupal, process payments, execute queues or verify deployed configuration.
The release record's acceptance scope is quotes and offered Slots, not a completed
payment or an actual delivery. Historical architecture notes are not assumed to describe
the latest code.

The exact [reference order URL](https://www.longislanddelivery.com/neworder.html)
could not be reliably inspected: direct retrieval failed and browser content inspection
timed out. The [same site's public homepage](https://www.longislanddelivery.com/)
publishes a quote-request sequence ending in dispatcher follow-up. That is useful
structural evidence, but not proof of the exact order page, its conditional fields,
payment behavior or backend matching. No reference form was submitted.

Platform facts and primary documentation links are in
[the accompanying source note](../architecture/fast-courier-primary-sources-2026-09-05.md).

## Recommendation

Extend the existing Express Courier service with an ASAP timing mode alongside its
scheduled mode. Preserve one planner and one resource-capacity ledger. Begin with
dispatch-assisted operation; live GPS, automatic driver acceptance, multi-driver
matching, route batching and multiple stops are later scope.

Keep the existing service machine ID `courier`. Do not rename it to `express_courier`,
and do not repurpose `on_demand_delivery`: that existing offering is explicitly
Manual Review. Its name does not establish operational equivalence to ASAP Courier.

For the proposed model, distinguish:

- Service: what work is eligible, priced and timed (`courier`).
- Delivery mode: `courier_send`, `courier_receive`, `courier_store_pickup`.
- Timing mode: proposed ASAP or scheduled. This is not the existing BookingMode,
  which means self-bookable versus manual-review.
- Urgency: a separately defined service promise and price rule. Start with Standard.
- Readiness: the earliest time the item can be collected; not the driver's arrival.
- Pickup Slot: the existing server-derived arrival option, not the whole job duration.
- Dispatch Window: occupied work interval, with travel between jobs proved separately.
- Operational progress: reported execution, distinct from paid booking and capacity.

These are proposed distinctions. No canonical glossary or accepted ADR is changed.

## Proposed four-stage public journey

### 1. Route and service — homepage

Collect `origin_address`, `destination_address`, `service_type`, `delivery_mode`, and
a proposed `pickup_timing_mode`. Pin Express Courier first; popularity may reorder
other offerings without changing a customer's current selection.

Preserve From and To as physical transport direction in every delivery mode. Receive
changes who the requester is, not which endpoint is the pickup. Use server-side draft
state or an opaque reference when moving into Webform; do not put addresses/contact
details into query strings or analytics events.

Do not require account creation. Address-only prices are preliminary because the
package and handling facts are not yet known.

### 2. Package, feasibility, price and pickup choice

Use structured facts for eligibility; prose alone cannot determine capacity.

| Group | Existing fields to reuse | Proposed additions or adaptation |
| --- | --- | --- |
| Package | `total_weight_lbs`, `courier_prohibited_ack`; inspect existing quantity/dimension elements before adding duplicates | Positive package count, configured dimensions/vehicle-fit limits, handling/access facts; optional description if operationally useful |
| Pickup | `origin_address`, later `pickup_contact_*`, `pickup_instructions` | Unit/entrance and loading/access facts; collect anything that changes duration before the quote |
| Delivery | `destination_address`, later `delivery_contact_*`, `delivery_instructions`, `handoff_preference` | Unit/entrance and unloading/access facts; recipient availability |
| Store collection | `store_pickup_merchant`, `store_pickup_order_reference`, `store_pickup_name`, `order_is_paid_and_ready` | Opening/collection cutoff if needed; collector authorization, with no store-account credentials |
| Timing | `preferred_service_date`, `dispatch_slot` | ASAP versus scheduled, item readiness; optional delivery deadline only after a deadline-feasibility policy exists |
| Price | Existing QuoteCalculator and quote summary | Itemized approved charges, route basis, estimated pickup and delivery windows, authoritative total at checkout |

For ASAP, derive today's local date on the server; avoid a second visible calendar.
Offer the earliest feasible pickup window, with later alternatives. For scheduled,
retain one date and the existing Slot widget. Changing route, mode, package, handling,
readiness or service level invalidates the previous offer and quote as appropriate.

Do not expose free-text deadlines as guaranteed delivery promises. If a deadline is
supported, prove delivery completion by it; merely checking that it is after pickup
is insufficient. A pickup window is an arrival range, not a promise that the whole
delivery is finished within that range.

Explicit public results: incomplete details, feasible offer, no current capacity,
temporary routing failure, or manual review. These must not collapse into one generic
error. Known prohibited goods must never become automatically approved through review.

### 3. Contact, review and Commerce payment

Collect requester name, phone and email once. Reuse/prefill the existing Commerce
contact panes; resolve pickup and recipient contacts separately. Send may copy the
requester to pickup. Receive should support the requester as recipient while retaining
the third-party pickup contact. Store Pickup uses merchant/order collection details.

Preserve the existing checkout order type, service order item, price snapshot and
payment flow. The four stages are a public journey, not a requirement to replace
Commerce's internal checkout-step machinery. Consolidate duplicated contact entry
without removing gateway-required billing, review or authentication requirements.

Revalidate the selected offer server-side before starting payment. A changed price
or time needs renewed customer agreement. Never silently substitute a later Slot.
Use the existing private checkout claim; no public hold countdown or renewal UI.

### 4. Confirmation and operational progress

Show a paid booking confirmation only when the payment/reservation outcome supports
that wording. Then expose bounded customer progress: confirmed, assigned/accepted,
en route to pickup, picked up, en route to delivery, delivered, or exception.
These labels are proposed future customer-facing semantics, not a claim they are all
implemented today. Show status freshness. A customer tracker should not disclose the
driver's other bookings or another customer's addresses.

Manual Review retains its separate Submitted → Under Review → Approved → Scheduled
path. Approval is not a capacity reservation; scheduling/payment must still revalidate
the selected time.

## What already exists and what actually changes

All paths below are under `modules/custom_webform_handlers/` unless noted.

| Existing component | Proposed responsibility |
| --- | --- |
| `config/install/webform.webform.hg.yml` and homepage block | Adapt progressive fields/steps; preserve machine values and non-courier paths |
| `src/Plugin/WebformHandler/AddressDistanceHandler.php` | Extend normalization and conditional validation; no second reservation implementation |
| `src/Dispatch/DispatchRequestFactory.php` and `DispatchRequest.php` | Normalize new timing/readiness/handling facts; distrust hidden/browser-calculated values |
| `src/Dispatch/Profile/ConfigBookingProfileResolver.php` | Retain per-service eligibility, duration and shared resource rules |
| `src/Dispatch/DispatchPlanner.php`, `Schedule/`, `Policy/`, `Route/` | Add earliest-feasible selection and operational readiness constraints behind the same planning authority |
| `src/Dispatch/Storage/ResourceCalendarGuard.php` and `DatabaseReservationStore.php` | Continue deciding capacity atomically for scheduled and ASAP bookings |
| `src/Quote/QuoteCalculator.php`, `src/Checkout/QuoteSnapshot.php` | Reuse integer-money price rules and immutable checkout amount; bind new offer facts to the attempt |
| `src/Commerce/DispatchCheckoutManager.php`, Commerce flow/payment pane | Preserve authorization, synchronous reservation and capture orchestration; verify failure paths |
| `src/Dispatch/Status/` and admin board | Add an explicit validated readiness input and progress integration, not an alternate calendar |
| Existing Review, Notify, AdvancedQueue JobTypes, ECA Actions and Views permissions | Extend only where new events/data require it |

Important code findings:

1. Rush/Expedited elements exist historically, but the current handler forces
   `courier_urgency` to `standard`. Revealing the radios alone would make the preview,
   saved request and checkout disagree. Any later speed tier must change and verify
   every relevant normalization, quote, planning and checkout path together.
2. ResourceStatus is intentionally a staff observation without coordinates and is
   not read by the planner. Available does not open capacity; Completed does not
   release it. A live dispatch feature therefore needs explicit new policy, not
   reinterpretation of the existing status flag.
3. Versioned resource configuration uses capacity one and a 30-minute grid, with
   Monday-only hours. Confirm actual operator hours before advertising daily service.
   Do not broaden them automatically. Both offers and claims enforce grid alignment.
4. Dispatch jobs use Advanced Queue's `database` backend in versioned configuration.
   Retain that unless installed plugin inventory and durability requirements justify
   another backend. Redis cache availability does not establish Redis queue support.

## ASAP planning contract

ASAP means the earliest feasible pickup arrival, not arrival now and not preference
over a confirmed customer. The planner needs the current clock, item readiness,
working hours, trusted resource readiness/location with freshness, existing work,
handling durations and traffic-aware travel.

For each candidate:

1. Establish when and where the driver can depart. Start from a fresh staff-confirmed
   position/readiness or a valid preceding commitment; unknown/stale state must not
   produce a confident near-term arrival promise.
2. Prove travel from that position to pickup, allowing dispatch lead and item readiness.
3. Add pickup handling, pickup-to-delivery travel, delivery handling and operational buffer.
4. Prove travel from delivery to the next confirmed pickup. Reject any insertion that
   would break that commitment, a break, blackout, shift boundary or required depot return.
5. Use the same eligibility, timing and readiness rules at offer, private claim,
   confirmation and staff Replan. Readiness changes must participate in the guarded
   validation/version checks; checking only when rendering the UI is insufficient.

Travel duration should correspond to each leg's expected departure, including loading
before the service leg. The current offer path uses a day-level route seed; accurate
ASAP promises need bounded candidate-time refresh rather than trusting that seed as
a live prediction. Route warming may help latency but does not become authoritative.

Start on the existing grid and label the result honestly as the next available
pickup. Finer/off-grid ASAP arrival is a later shared-planner change: changing only
the widget would publish times that the claim path rejects. If a public window spans
several possible arrivals, protect its latest promised arrival or a documented
equivalent conservative bound, not only the optimistic earliest instant.

Example using illustrative durations, not new configuration: an 08:00 pickup plus
10 minutes loading, 20 minutes driving, 10 minutes delivery and 10 minutes buffer
finishes at 08:50. If travel to the next pickup takes 25 minutes, that pickup cannot
be at 09:00. The earliest is 09:15, or 09:30 on a 30-minute grid, subject to later
commitments. There is no universal rule to block exactly one additional hour.

Operational delay should narrow/close ASAP offers and alert staff. Changing existing
commitments remains an explicit guarded Replan; reporting early completion must not
silently make overlapping capacity sellable.

## Data ownership, price and payment

Keep Webform as request intake, Commerce as monetary authority and the existing
reservation store as capacity authority. Operational events report execution.
Add only the missing facts and indexes; do not create duplicate orders, calendars or
an external dispatch service for the one-driver pilot.

A future short-lived offer reference should bind normalized request facts, chosen
Slot, route observation/departure, relevant schedule/readiness version and rate
version. It is advisory until claimed. The current QuoteSnapshot identifies an
amount/currency/rate version; it is not a route fingerprint or evidence of availability.
Separate offer freshness from the locked checkout amount. Snapshot the disclosed
line items when needed for receipts/audit without persisting unnecessary Google data.

Reuse the current base + excess-mile + configured delivery-mode charge. Add an urgency
fee only after an operator defines a feasible distinct promise. Do not reduce physical
travel time because the customer pays more. Explicitly decide whether repositioning,
access work, tolls and waiting are included, charged under disclosed rules, or reviewed.
No new tariffs are selected by this proposal.

The code currently orchestrates authorization → synchronous capacity confirmation →
capture. Preserve that model where the chosen gateway supports it. Payment and local
SQL cannot share one distributed atomic transaction. Use short database transactions
around the private durable claim and confirmation, never one transaction spanning
customer interaction or payment network calls. Audit bounded route calls under the
calendar guard before raising throughput.

Verify authorization decline, expired claims, duplicate/reordered callbacks, return
without a browser session, confirmation conflict, capture failure and reconciliation.
Retain protected capacity while a committed reservation's capture outcome is uncertain;
do not automatically release or dispatch it as paid. Make its exception visible to
staff. Notify after commit, with durable recovery for missed enqueue and idempotent
delivery. Queue failure never reverses or duplicates a confirmed reservation.

## Phased implementation and verification

| Phase | Work | Exit evidence |
| --- | --- | --- |
| 1. Baseline and operating policy | Confirm active Webform/config/plugin IDs, gateway authorize/capture capability, workdays, service area, package limits, readiness source and MVP promise. Record existing paid-flow behavior. | Read-only Drush/config/plugin checks; operator-approved policy; unchanged scheduled baseline |
| 2. Form structure | Adapt homepage and Webform fields, contact reuse and timing choice. Extend request factory and schemas. Preserve conditional state and historical keys. | Manual Send/Receive/Store Pickup walks; back/forward and service changes; invalid inputs refused on the server |
| 3. ASAP feasibility | Add readiness/location freshness, dispatch lead and earliest-feasible selection behind DispatchPlanner. Preserve shared guard and current grid initially. | Isolated QA examples around previous/next jobs, shift boundaries, no capacity, stale status and delayed driver |
| 4. Price/offer binding | Connect eligible offers to versioned quotes; invalidate on changed facts; keep Standard initially. | Matching preview, saved request, Commerce line item and receipt; tampered/expired offer refused |
| 5. Checkout and confirmation | Adapt existing claim/payment orchestration only where required. Expose reconciliation exceptions and reliable post-commit notification. | Manual sandbox payment outcomes, duplicate callback replay and two-browser contention; one winning reservation/charge |
| 6. Dispatch and tracker | Extend board filters/progress, dispatcher acknowledgement, customer tracking, alerts and permissions. Driver status changes do not directly mutate capacity. | Authorized status transitions; no contact/financial leakage between roles; staff can recover a delayed or failed job |
| 7. Limited launch | Enable ASAP behind a flag for the existing courier/resource only. Observe promised versus actual pickup, missed acknowledgements, quote latency, route cost and conflicts. | Operator sign-off, clear disabling procedure preserving existing bookings; scheduled path unaffected |

Follow the project's no-automated-test rule: no PHPUnit, FunctionalJavascript or
Selenium suites. Use targeted lint/Drush diagnostics, `ddev drush cr` after code/config
changes and documented manual UI checks on isolated QA with a sandbox payment gateway.
Cache rebuild alone does not verify routing, concurrency or payment behavior.
Run database/config updates only as scoped implementation steps on the intended site;
editing config/install YAML alone does not update an already-installed site. Do not
run broad imports or mutate customer data as part of this planning review.

## Validation checklist

- Address autocomplete is assistance, not validation: require resolvable physical
  endpoints, supported geography and serviceable routes; separate unit/access details.
  Autocomplete, geocoding and Google's Address Validation product are not interchangeable.
- Reject missing/zero/negative/non-finite weight or dimensions, invalid units, unknown
  modes and crafted hidden totals. Apply one configured eligibility ceiling (currently
  up to 30 lb), package count/vehicle fit and handling constraints server-side.
- Distinguish an out-of-policy package from unavailable capacity and a temporary route
  timeout. Uncertainty must not become zero travel or a free/confirmed booking.
- Store orders must be paid and collectable; recipient/store closing times and loading
  access can invalidate an otherwise short route. Do not collect shopping credentials.
- Handle midnight, DST ambiguity, past instants, readiness after requested pickup,
  missed cutoffs and return-to-base. Display America/New_York; persist UTC; no dropdown.
- Changing facts while checkout is open must not reuse a stale job/price/claim. Replays,
  multi-tab submits, cancellations and payment recovery must remain idempotent.
- Bound API calls, timeouts and precompute fan-out. Respect Google caching/storage terms
  for the selected API and separate browser and server credential restrictions.
- Preserve CSRF/access checks, rate limiting and unguessable/revocable tracking access.
  Keep addresses, phones and financial data out of public availability responses and logs.

## Review decisions

Recommended first scope: one Standard courier service, all three existing delivery
modes, ASAP plus scheduled, existing 30-minute grid, staff-confirmed fresh readiness,
one pickup/drop-off, and explicit no-capacity alternatives. Before implementation,
agree actual operating hours/area, readiness update procedure, fit/handling limits,
pickup promise and paid-booking dispatch-acknowledgement policy.

Urgency tiers, hard delivery deadlines, real-time GPS and automated multi-driver
assignment remain separate phases unless expressly added to the first release.
