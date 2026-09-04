# Service booking-profile matrix

An audit of every service type the `hg` wizard offers, as configured on
2026-08-31. No application code was changed to produce this document, and
`webform_booking` is not installed.

Two booking modes are allowed:

- **`self_bookable`** — route/work duration *and* capacity are deterministic, so the
  shell may render Slots.
- **`manual_review`** — duration or capacity cannot safely be calculated, so the shell
  renders **zero** Slots and truthful review copy.

No service is marked self-bookable on a guessed fixed duration. Where a duration
cannot be computed from mapped configuration the mode is `manual_review`, and the
reason is named.

Evidence: `service_type` options and `#states` gating read from the active `hg`
Webform (`getElementsDecodedAndFlattened()`); modes and buffers from active
`custom_webform_handlers.dispatch`; duration arithmetic from
`src/Dispatch/Policy/ConfigServiceDurationPolicy.php`; refusal ordering from
`src/Dispatch/Policy/ConfigDispatchEligibility.php`; pricing from
`src/Quote/QuoteCalculator.php` against `custom_webform_handlers.settings : quote.*`;
public wording from the recorded gate runs. Webform and config YAML are outside the
AST-only Graphify coverage and were read directly, as the project rule requires.

## The matrix

| Service type | Booking mode | Duration source | Required facts | Resource/capacity rule | Quote rule | Public result |
| --- | --- | --- | --- | --- | --- | --- |
| `courier` (Express Courier) | **`self_bookable`** | `durations.courier` + live Routes `route_seconds` | `service_type`, `delivery_mode`, `total_weight_lbs` ≤ 30, `courier_prohibited_ack`, both addresses, `preferred_service_date` | `driver-01`, capacity 1, Mon–Wed 08:00–18:00, 30-min grid, 4+4 min reposition/operational, courier buffer 15, promise 30, horizon 30 d | `quote.courier`: base 1900 ¢, 5 included mi, 150 ¢/excess mi → `estimated` | Slots (19 for Tue 2026-09-01), instant estimate `$19.00` |
| `on_demand_delivery` | `manual_review` | **none** — no service class | `items_to_move_sameday`, vehicle selection, both addresses | claims no capacity; no ledger row | `quote.on_demand`: vehicle base, 10 included mi, 100 ¢/excess mi | zero Slots + review copy |
| `local_residential_move` | `manual_review` | `durations.move` — **returns NULL** | `move_size_residential`, access ×2, stairs ×2, `parking_situation`, specialty items | claims no capacity; no ledger row | `quote.local_residential`: size base + travel at 30 mph × 2 × 11000 ¢/h + access fees | zero Slots + review copy |
| `local_commercial_move` | `manual_review` | **none** — no move size collected | `scope_of_move`, `timing_of_move`, both addresses | claims no capacity; no ledger row | `quote.commercial`: base 300000 ¢ + scope/timing + 200 ¢/mi past 50 mi | zero Slots + review copy |
| `long_distance_move` | `manual_review` | **none** — no move size collected | `shipment_size`, `shipment_home_size`, weight | claims no capacity; no ledger row | `quote.long_distance`: base 50000 ¢ + weight × distance × 0.00065 | zero Slots + review copy |
| `specialty_item_move` | `manual_review` | `durations.move.specialty_item_minutes` alone — **insufficient** | `item_to_be_moved`, `item_specifications`, `access_obstacles` | claims no capacity; no ledger row | `quote.specialty`: **always** `specialty_item_requires_review` | zero Slots + review copy, never an estimate |
| `diy_truck_rental` | `manual_review` | **not a driver job at all** | `truck_size`, `rental_duration` | rents a truck, not `driver-01`; capacity model absent | `quote.diy_truck`: **always** `diy_fuel_taxes_require_review` | zero Slots + review copy, never an estimate |

## How the mode is decided today — the profile already exists

There is no `BookingProfile` class, but the concept is already implemented across two
services plus config. This matters for Task 10: what is needed is a **resolver over
what exists**, not a new engine, which is exactly what avoids per-service duplication.

`ConfigDispatchEligibility::assess()` refuses in a fixed order, and every branch is a
refusal — nothing falls through to "probably fine":

1. `self_bookable.service_types` empty → `NotConfigured`
2. service type empty → `IncompleteFacts`
3. service type in `manual_review.service_types` → **`ManualReview`**
4. a declared `vehicle_helper_needs` value → `MultiPersonHandling`
5. any configured `handling_flags` value (`heavy`, `oversized`, `restricted`,
   `multi_person`, `unsupported`) → `ManualReview`
6. service type not in `self_bookable.service_types` → `UnsupportedService`
7. `!hasBothEndpoints()` → `IncompleteFacts`
8. `deliveryMode !== NULL || isCourierService()` → `assessCourier()`, else
   `eligible(CLASS_MOVE)`

The six non-courier services are caught at **step 3**, and would be caught again at
**step 6** if step 3 were removed. Two independent gates, both closed.

`ConfigServiceDurationPolicy::durationFor()` then admits only two classes:

```php
return match ($serviceClass) {
  EligibilityOutcome::CLASS_COURIER => $this->courierDuration($config, $request),
  EligibilityOutcome::CLASS_MOVE    => $this->moveDuration($config, $request),
  default => NULL,
};
```

`assessForStaff()` reuses the same assessment and explicitly refuses to override
`IncompleteFacts` or `NotConfigured`, because "without a service class there is no
duration policy to ask". Staff can override a *judgement*, never a missing fact.

## Why moves cannot be self-bookable — computation, not policy

This is the finding that decides Task 10's scope. A move is not held back by an
opinion in config that someone could flip; `moveDuration()` **cannot return a number**
for any real move today. It returns NULL unless *all* of the following hold:

- move size is a key in `durations.move.move_size_minutes`
- `stairs_per_flight_minutes` ≥ 0
- `specialty_item_minutes` ≥ 0
- both stairs counts are non-NULL and ≥ 0
- access is mapped at **both** endpoints in `durations.move.access_minutes`
- parking is mapped in `durations.move.parking_minutes`
- specialty count is non-NULL and ≥ 0
- every declared add-on is mapped in `durations.move.add_on_minutes`

As shipped:

| Input map | State |
| --- | --- |
| `move_size_minutes` | 6 entries (studio 120, 1_br 180, 2_br 300, 3_br 420, 4_br_plus 540, few_items 60) |
| `stairs_per_flight_minutes` | 15 |
| `specialty_item_minutes` | 45 |
| `access_minutes` | **`{}` empty** |
| `parking_minutes` | **`{}` empty** |
| `add_on_minutes` | **`{}` empty** |
| `request_fields.specialty_item_count` | **unmapped (empty)** |

Three empty maps and one unmapped fact, each of which independently forces NULL. The
policy's own docblock states the intent: "Unmapped facts return NULL rather than
contributing zero. The shipped configuration leaves the access, parking, and add-on
maps empty precisely because their Webform machine values are not yet known:
inventing keys here would produce confident timings for facts nobody has priced."

That is the trap Task 10 must not walk into. Filling those three maps with plausible
numbers would flip four services to *apparently* self-bookable while the timings
remain invented — the same class of defect as the quote/Slot contradiction this module
already had to fix once. The correct sequence is: an operator maps the real Webform
values to real minutes, **then** the service becomes a self-bookable candidate.

One mapping gap is deliberate and must not be "fixed":
`request_fields.single_driver_handling` is intentionally empty because
`DispatchRequestFactory::singleDriverHandling()` returns TRUE for any service type in
`self_bookable.single_driver_service_types` **before reading any element** — Express
Courier is *defined* as a one-driver service, and the browser was never a safe source
for that claim. Every other service reads its mapped element; unmapped or unanswered
resolves to FALSE, which routes to staff.

## Pricing is not the blocker — every service is already priced

Worth separating clearly, because it is easy to assume a Manual Review service has no
number behind it. `QuoteCalculator::calculate()` has a branch for **all seven** service
types plus a `default`, and ends with:

```php
$status = $this->manualReviewReasons ? 'manual_review' : 'estimated';
```

So a service can be fully priced and still be `manual_review` — and two services can
*never* be anything else, because their branch pushes a reason unconditionally:

| Service | Unconditional review reason |
| --- | --- |
| `specialty_item_move` | `specialty_item_requires_review` |
| `diy_truck_rental` | `diy_fuel_taxes_require_review` |

The remaining branches raise reasons only on missing or unsupported facts, e.g.
`missing_shipment_weight`, `missing_or_unsupported_move_size`,
`missing_<origin|destination>_access_conditions`, `missing_parking_situation`,
`missing_or_unsupported_commercial_timing`, `missing_vehicle_selection`,
`missing_package_weight`, `missing_delivery_mode`, `package_contents_require_review`.
The `missing_*` prefix is load-bearing: it is how the sidebar tells an **unfinished**
form from a **refused** one.

Two cross-service pricing rules already encode the same caution:

- Courier + any of `packing_services`, `cleaning_services`,
  `disposal_donation_interest` → `courier_add_ons_require_review`. The comment is
  explicit that charging a parcel run for packing "would publish an estimate the
  planner has already declined to serve".
- Non-courier + `disposal_donation_interest` → `disposal_or_donation_requires_review`.

Conclusion: the gap between a Manual Review service and a self-bookable one is
**duration and capacity**, not price.

## Capacity is a single driver — and that is a Task 10 constraint

`resource.capacity` is `1` and there is exactly one resource, `driver-01`. Whatever
becomes self-bookable next competes for the *same* Mon–Wed 08:00–18:00 grid as Courier.
A 3-hour residential move consumes six 30-minute cells that Courier can no longer sell,
and `return_to_base` is `false` with an empty `depot_address`, so repositioning is
computed endpoint-to-endpoint with a flat 4-minute buffer. Adding a long-duration
service to a capacity-1 resource is a scheduling-policy decision, not a configuration
detail — it must be decided before, not during, implementation.

## The shared shell — what each service must show

All services will eventually render the same one-date / one-Slot-widget shell. The
three public states Courier already publishes are the contract, and each is published
in two places (the widget and the sidebar):

| State | Slot widget | Sidebar | How it is distinguished |
| --- | --- | --- | --- |
| **unfinished** | no Slots, "Select a date to see available pickup times." | no estimate yet / complete remaining details | reason codes are `missing_*` |
| **eligible** | Slots rendered, one date input | `Estimate updated`, a total | `manual_review: false`, `incomplete: false` |
| **refused** | zero Slots, refusal stated in words | estimate needs review / needs a person | reasons present, none `missing_*` |

For a Manual Review service the shell must land in **refused**, not **unfinished** —
truthful copy means "a person will price and schedule this", never "finish the form".
The existing gate already asserts this separation for Courier scenario C: "no
incomplete-details wording, this is a real refusal".

Elements each service gates today, from the live `hg` `#states` (the facts the shell
would have to carry per service):

| Service type | Gated elements |
| --- | --- |
| `courier` | 18: `delivery_mode`, `courier_details`, `total_weight_lbs`, `courier_prohibited_ack`, `store_pickup_details`, `store_pickup_merchant`, `store_pickup_order_reference`, `order_is_paid_and_ready`, `pickup_same_as_sender`, `pickup_contact_*`, `pickup_instructions`, `delivery_contact_*`, `delivery_instructions`, `handoff_preference` |
| `courier_store_pickup` (delivery mode) | 4 |
| `local_residential_move` | 6: `local_residential_move_details`, `move_size_residential`, `origin_access_conditions`, `destination_access_conditions`, `parking_situation`, `specialty_items_residential` |
| `local_commercial_move` | 3: `scope_of_move`, `timing_of_move` |
| `long_distance_move` | 3: `shipment_size`, `shipment_home_size` |
| `specialty_item_move` | 4: `item_to_be_moved`, `item_specifications`, `access_obstacles` |
| `diy_truck_rental` | 3: `truck_size`, `rental_duration` |
| `on_demand_delivery` | 2: `items_to_move_sameday` |

`preferred_service_date` is the single canonical Courier date (`update_10033`).
`preferred_service_time` and `date_flexibility` belong to the *other* services and are
currently `#states`-hidden on a courier request — present, inert, and absent from the
offer body. Consolidating a second service onto the shell means deciding which of those
two questions survives, at the Webform source, never with CSS.

## Services safe for a one-service pilot

| Service | Safe as | Why |
| --- | --- | --- |
| `courier` | **`self_bookable`** — the reference, unchanged | Already deterministic end to end and green 66/66 at both widths. It is the control, not the pilot; it must not regress. |
| `on_demand_delivery` | **`manual_review` on the shared shell** | Safest first migration. Same two-endpoint shape as Courier, already in `manual_review.service_types`, no move-size/access/parking/add-on map involved, priced already (`quote.on_demand`), claims no capacity, and its only gated fact is `items_to_move_sameday`. Migrating it exercises the shell's zero-Slot path without touching duration arithmetic. |

Nothing else is safe yet, and specifically:

- `local_residential_move` — needs `access_minutes`, `parking_minutes`,
  `add_on_minutes` and `request_fields.specialty_item_count` filled by an operator
  before it is even a self-bookable *candidate*. Safe on the shell only in
  `manual_review`, and it is the heaviest migration (6 gated elements plus the
  `date_flexibility` conflict).
- `local_commercial_move`, `long_distance_move` — no move size is collected at all, so
  there is no duration model to extend, only one to invent.
- `specialty_item_move`, `diy_truck_rental` — can never reach `estimated`; their quote
  branches raise a review reason unconditionally. Manual Review is their permanent
  mode, not a waypoint.
- `diy_truck_rental` additionally does not consume `driver-01` at all. It rents a
  truck. Putting it on a driver-capacity grid would be modelling the wrong resource.

**Recommended pilot: exactly one service, `on_demand_delivery`, in `manual_review`
mode, with `courier` retained unchanged as the reference.** No service should be moved
to `self_bookable` in this pilot.





