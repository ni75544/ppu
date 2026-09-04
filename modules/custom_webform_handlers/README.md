# Custom Webform Handlers

Current calculator release: `1.1.10`; rate version: `2026-08-production-v1`.

Step 16 clears all computed quote fields before authoritative recalculation, prevents stale totals after route/provider failures, stores classified manual-review metadata, disables caching of preview responses, and reports key/rate readiness in Drupal's Status Report.

Step 15 requires residential access, parking, specialty declaration, stair-flight counts, commercial timing, conditional descriptions, and a valid 10-digit US phone number. The quote calculator independently marks bypassed or incomplete pricing inputs for manual review.

Step 14 adds configurable few-items, 4+ bedroom, commercial timing, and DIY truck-rental rates. It also classifies commercial scope/building constraints, unspecified specialty items, disposal/donation work, and open-ended DIY labor as manual-review conditions while preserving the calculable subtotal.

This module adds address autocomplete, a throttled driving-distance preview,
authoritative server route validation, and preliminary quote calculation to a
Drupal Webform through the **Address and Distance Calculator** handler.

## Security notice

The API key included in the previous JavaScript file was exposed and must be
revoked in Google Cloud. Do not reuse it.

Configure two new restricted keys:

1. Enter the browser key at **Configuration > Web services > Google API
   Settings**. Restrict it by the site's HTTP referrers and required browser
   APIs.
2. Set the server key outside configuration export in `settings.php`:

   ```php
   $settings['custom_webform_handlers_server_api_key'] =
     getenv('CUSTOM_WEBFORM_HANDLERS_SERVER_API_KEY') ?: '';
   ```

   Restrict this key by the web server's outbound IP address and required API.

For browser autocomplete, merely saving a key is not sufficient. Its Google
Cloud project must have billing enabled and **Maps JavaScript API**,
**Places API (New)**, and **Directions API** enabled. The key must be a
**Websites** browser key. Add
the exact staging and production origins to its HTTP-referrer allowlist, for
example `https://example.com/*` and `https://www.example.com/*` when both
hostnames are used. Allow several minutes after changing Google Cloud settings,
then rebuild Drupal caches and reload the form without the browser cache.

If suggestions still do not appear, inspect the browser developer console.
This build reports initialization errors without exposing the key and allows
address autocomplete to initialize even when the distance-preview mapping is
incomplete.

Some Drupal modules or themes load an older Google Maps JavaScript interface
before this module. Version 1.1.2 supports both the current
`PlaceAutocompleteElement` interface and the compatible
`google.maps.places.Autocomplete` interface. Avoid configuring multiple Drupal
modules to load Google Maps independently; ideally, the first Google Maps script
on the page should include the Places library.

Version 1.1.3 also waits for the Places interface after the asynchronous Google
script load event. Google may finish loading the script before `importLibrary()`
and the Places namespace have been exposed, so the script event alone is not
treated as readiness.

## Handler setup

Add the **Address and Distance Calculator** handler to the Webform and map:

- Origin address: `origin_address`
- Destination address: `destination_address`
- Distance: `calculated_distance`
- Canonical distance: `calculated_distance_meters`
- Quote total: `final_calculated_quote`
- Quote currency: `estimated_quote_currency`
- Quote breakdown: `estimated_quote_breakdown`
- Quote status: `estimated_quote_status`
- Quote rate version: `estimated_quote_rate_version`

The legacy distance field may be hidden, numeric, or text. Map the optional
canonical distance, quote total, currency, status, breakdown, and rate-version
fields when those elements have been added to the Webform.

## Upgrade

Back up the site, replace the module files, run database updates, and rebuild
caches. The update hook intentionally removes the legacy shared API key. New
keys must be configured after the update.

This build removes the old public AJAX distance-record endpoint, the unused
Distance Record entity code, and the hardcoded `we` Webform hooks. It does not
drop an existing `distance_record` database table because that could destroy
historical data. Back up and review any existing table separately.

Distance preview and final submission validation now use Google Routes API v2
on the server. The browser no longer calculates distance with the legacy
Distance Matrix service. Existing Twig pricing remains only as a temporary
display layer; the authoritative stored total is calculated by the PHP quote
service. The next Webform YAML phase will replace that Twig pricing display.

All migrated rates are provisional copies of the supplied form values. Review
and approve them before production use.

Administrators can review route limits and quote rates at **Configuration >
Web services > Shipping Quote Settings**. Monetary settings are stored in
cents. Increment the rate version whenever approved production rates change.

## Express Courier setup

Express Courier is the pinned, self-bookable local service. Its machine value is
`courier` and stays `courier` — the dispatch profile, the eligibility policy, and
the quote all key on it, so only the public label reads **Express Courier**.
Beneath it sit three Delivery Modes: Send, Receive, and Store Pickup.

The planner reasons about the customer's answers through the element-key mapping
in `custom_webform_handlers.dispatch` (`request_fields`), never through element
labels — so labels are free text and only the **machine values** must match.

> On a site that already has this module installed, editing `config/install/…`
> does not change the running configuration by itself. Make the active
> `custom_webform_handlers.dispatch` configuration carry the same
> `request_fields` mapping through your configuration-management workflow. The
> element **machine values** must match the mapping exactly.

### Installing the elements

`scripts/update_hg_express_courier.php` writes the elements onto the `hg`
Webform and puts them in the order the planner needs:

```bash
ddev exec vendor/bin/drush scr modules/custom_webform_handlers/scripts/update_hg_express_courier.php
```

It backs the current elements up to `/tmp/hg-elements-before-express-courier.yml`
before touching anything, moves element keys between wizard pages rather than
renaming them — so stored submissions stay readable — and is idempotent: a second
run rewrites the same elements instead of duplicating them.

Every existing service keeps its stored machine value and stays staff-reviewed.
`on_demand_delivery` in particular presumes a vehicle and a helper, which is more
than the single driver dispatch plans against, so it is listed in
`manual_review.service_types` and is never offered as self-bookable.

### What gets installed

Everything below lives in a `courier_details` fieldset shown only when
`service_type` is `courier`. "Required when" is a conditional state, so a customer
choosing another service is never blocked by an Express Courier field.

| Element | Type | Required when |
| --- | --- | --- |
| `delivery_mode` | radios: `courier_send`, `courier_receive`, `courier_store_pickup` | Express Courier |
| `package_description` | textarea | Express Courier |
| `total_weight_lbs` | number | Express Courier |
| `total_weight_unit` | select: `lb`, `kg` | Express Courier |
| `single_driver_handling` | radios: `1`, `0` | never — retired from the create form, see below |
| `pickup_contact_name`, `pickup_contact_phone` | textfield, tel | Express Courier, except Store Pickup |
| `delivery_contact_name`, `delivery_contact_phone` | textfield, tel | Express Courier |
| `pickup_instructions`, `delivery_instructions` | textarea | never |
| `store_pickup_merchant`, `store_pickup_order_reference` | textfield | Store Pickup |
| `store_pickup_name` | textfield | never |
| `order_is_paid_and_ready` | checkbox | Store Pickup |
| `handoff_preference` | radios | Express Courier |
| `courier_urgency` | radios: `standard`, `rush`, `expedited` | never |
| `prohibited_items_ack` | radios: `none`, `restricted`, `unsure` | Express Courier |

Two of these carry more weight than their type suggests.

`single_driver_handling` is no longer asked at all. Express Courier is *defined* as
a one-driver service — the Resource is one driver and one vehicle, and
`self_bookable.max_package_lbs` is a ceiling chosen so one person can carry the
package unaided — so the fact is a property of the service, not a question for the
customer. `self_bookable.single_driver_service_types` names the service types that
carry it, `DispatchRequestFactory` resolves it from there before any element is
read, and `request_fields.single_driver_handling` is empty, which also removes the
element from the payload the Slot widget collects.

The element itself is kept and retired with `#access_create_roles: []`, so historic
answers still render and can still be edited, and non-Courier behaviour is
unchanged. A genuine two-person job is still refused: a declared
`vehicle_helper_needs` value or a `multi_person` handling flag is Manual Review, and
both are checked before the courier branch. Asking the question instead made the
answer the hidden difference between a passing browser fixture and a real visitor,
who got `Manual review required` for a perfectly valid 10 lb request.

`total_weight_unit` must submit exactly `lb` or `kg`. The server converts from
those two and treats any other value as an **undeclared** weight — guessing the
unit wrong by a factor of 2.2 either refuses a legitimate booking or accepts a
package the driver cannot carry alone.

Package count, dimensions, and the optional package photo are **not** new
elements. The existing `shipment_measurements` fieldset already collects
`item_quantity`, `item_length`, `item_width`, `item_height`, and
`dimension_unit`, and the script adds an Express Courier required state to them
rather than installing a second set. The photo is the existing
`universal_item_file` element, which already stores to the private scheme.

### Store Pickup

The Store Pickup elements sit in a nested fieldset shown only when
`delivery_mode` is `courier_store_pickup`. `order_is_paid_and_ready` is the
load-bearing one: the driver collects only — they cannot pay for an order, wait
for one to be prepared, or place it. The server requires an explicit yes before a
Store Pickup can self-book, and an unchecked box reads as a definite no. Whether
that confirmation is demanded at all is
`self_bookable.store_pickup_requires_paid_and_ready`.

The store's own address is deliberately not collected. Store Pickup uses the
request's pickup address like every other mode, because the planner routes one
pickup and one delivery — a third address would describe a different job.

### Field order

The script puts service, Delivery Mode, addresses, and package facts on step 1,
scheduling and add-ons on step 2, and contact identity on step 3. The planner and
the quote both read the step-1 answers, so those have to be answered before any
Slot or estimate can be calculated.

### What the server does on its own

- **Screening and Manual Review.** Before any calendar is consulted, the server
  classifies the request from the customer's own answers. Every limit below is
  **configured policy, not a promise to a customer or a guarantee about what can
  be carried**: each one is an operator's setting that can be raised, lowered, or
  removed without touching code, and the figures named are only what ships
  installed.

  | Routed to Manual Review | Setting in `custom_webform_handlers.dispatch` |
  | --- | --- |
  | Declared weight above the limit (installed: 30 lb) | `self_bookable.max_package_lbs` |
  | Undeclared or unit-less weight | `self_bookable.require_declared_weight` |
  | Unconfirmed single-driver handling, for a service `self_bookable.single_driver_service_types` does not name | `self_bookable.require_single_driver_handling` |
  | Store Pickup that is not paid-and-ready | `self_bookable.store_pickup_requires_paid_and_ready` |
  | A Delivery Mode not open to self-service | `self_bookable.delivery_modes` |
  | A service not open to self-service | `self_bookable.service_types` |
  | A named staff-only service | `manual_review.service_types` |
  | A vehicle-plus-helper need | `manual_review.vehicle_helper_needs` |
  | Restricted or unknown package contents | `manual_review.handling_flags` |

  Contents screening is worth reading twice, because it is configuration rather
  than code. `prohibited_items_ack` is mapped into
  `request_fields.handling_flags`, and its submitted value is compared verbatim
  against `manual_review.handling_flags` — which lists `unsure` alongside
  `restricted`. A customer who does not know what is in the package has told us
  something, and uncertainty is not permission.

  A Manual Review request keeps its authoritative quote but creates no Hold and
  no Reservation and consumes no capacity; staff follow up.
- **Express Courier durations.** A Dispatch Window is dwell at the pickup, the
  drive, dwell at the delivery, and padding — and Express Courier keeps its own
  figures for all of that, because a package handoff is not a move. They live at
  **Dispatch Planner settings > Express Courier durations**, and the installed
  values are operating estimates an operator is expected to tune, not promises:

  | Figure | Key under `durations.courier` | Installed |
  | --- | --- | --- |
  | Pickup dwell | `pickup_handling_minutes` | 10 min |
  | Delivery dwell | `delivery_handling_minutes` | 10 min |
  | Store Pickup dwell | `store_pickup_dwell_minutes` | 15 min |
  | Operational buffer | `operational_buffer_minutes` | 15 min |
  | Promise width | `promise_minutes` | 30 min |

  The Store Pickup figure **replaces** the pickup dwell rather than adding to it:
  collecting a paid order from a counter is a different job from taking a package
  from a customer's hands — there is a queue, a clerk, and an order to be found —
  so it is that job's dwell, not a surcharge on another one.

  A `0` anywhere in this section means "no Express Courier figure", and the
  planner falls back to the general figure above it or to the Resource Profile.
  An operator who never opens the section keeps today's behaviour exactly.

  Road time is **not** enterable here and never will be. It comes from the
  scheduled route service, per leg, priced at the instant that leg is actually
  driven — see the next bullet.
- **Traffic-aware travel time.** A drive leg is measured for the instant it will
  actually be driven, not as a time-independent distance: the scheduling request
  asks Google Routes for `DRIVE` with `TRAFFIC_AWARE_OPTIMAL` and the traffic
  model set in `route_traffic_model` (`BEST_GUESS`, `PESSIMISTIC`, or
  `OPTIMISTIC` — raise it to `PESSIMISTIC` where a late arrival costs more than
  idle time). Departure instants are floored to
  `route_departure_bucket_seconds`, so measuring a whole working day costs a
  handful of billed requests rather than one per candidate arrival, and every
  cached route is keyed by its addresses, travel mode, routing preference,
  traffic model **and** that bucket — a route priced for the evening peak is
  never reused for a morning one. Quoting is untouched: it keeps asking the
  time-independent question, so an approved price never moves with the hour the
  form happened to be filled in.
- **Never a silent under-estimate.** When Google cannot price traffic it
  substitutes traffic-unaware routing rather than failing, so the server trusts
  the response and not the request. A leg that comes back with no traffic behind
  it is widened by `route_traffic_fallback_inflation_percent` before anything is
  scheduled against it (a floor of 10% applies whatever is configured), and a
  leg that cannot be measured at all yields no Slots rather than a zero-length
  drive. Set `routing.require_traffic_aware` to `true` in
  `custom_webform_handlers.dispatch` to be stricter still: a leg with no traffic
  data behind it then offers nothing, and the request reaches staff instead of
  being placed on an assumption.
- **Fresh availability on change.** Because the Courier mapping keys are the
  availability widget's watched fields, changing the delivery mode or any mapped
  package fact automatically requests fresh Slots and releases any Hold that no
  longer matches. No extra configuration.
- **Express Courier pricing.** Express Courier is priced from distance and
  Delivery Mode rather than from crew size, because it is always one driver. The
  rate lives at **Shipping Quote Settings > Express Courier rates**, every figure
  is stored as **integer cents**, and the installed numbers are provisional
  copies awaiting approval like every other rate in this module:

  | Component | Key under `quote.courier` | Installed |
  | --- | --- | --- |
  | Base charge | `base_cents` | 1900 |
  | Miles the base covers | `included_miles` | 5 |
  | Rate beyond that | `excess_distance_cents_per_mile` | 150 |
  | Per-mode surcharge | `mode_surcharge_cents` | Store Pickup 500, others 0 |
  | Optional urgency surcharge | `urgency_surcharge_cents` | rush 750, expedited 1500 |

  An urgency tier not listed is not surcharged rather than refused — urgency is an
  upsell, not a fact the quote depends on.

  Changing any figure means changing `quote.rate_version` as well. The version is
  stamped into every quote the server writes, so a total stored months ago can be
  read back against the rates that actually produced it instead of against
  today's.

  The weight limit is deliberately **not** re-applied here. Eligibility owns
  `self_bookable.max_package_lbs`, and a second threshold in the calculator would
  be a second authority for one rule — the two would eventually disagree, and a
  disagreement about a limit is how an over-limit package gets booked. The
  calculator records the declared weight and prices the work. Its one refusal is
  about contents rather than booking: an unclear `prohibited_items_ack` suppresses
  the estimate, because a package nobody will describe cannot honestly be priced.

  Dispatch feasibility never consults a price or makes a pricing round-trip. What
  a job costs and whether it fits in the day are separate questions.

## Staff dispatch operations

Operating the schedule is a separate grant from reading it. The board and its
detail pages require **administer dispatch planner**; every operation below
requires **manage dispatch schedule**, so an operator who may only read sees the
board with no controls on it, and each operation re-checks the permission on its
own route rather than trusting the page that linked to it.

- **Closures.** *Dispatch closures* withholds a span of one Resource's day, and
  revises or lifts one already in force. The times are read in that Resource's
  own zone, the span must fall inside the working day, and a closure over live
  work is refused naming the Reservations in the way — never written over them.
  A revision withdraws and re-inserts inside one transaction, so there is no
  instant at which the Resource is open in a span staff have been told is shut.
- **Freeing capacity.** A held or confirmed Reservation can be released from its
  detail page. The row is retained and marked rather than deleted, because the
  question later is not only what is booked but what was.
- **Replanning.** A Reservation can be moved to a new arrival time. The server
  reloads the Reservation, rebuilds its request from the submission that owns it,
  locks the old and the new local days together, and re-proves the whole plan —
  route, duration, both repositioning legs, working hours, breaks, closures and
  the return to base — with the Reservation being moved excluded from the day it
  is leaving. If it is feasible the derived window and route snapshot are
  replaced atomically and the old row is retained as superseded; if not, nothing
  changes and the refusal says whether another time is worth trying. The quoted
  price is copied verbatim: the amount does not change because the van comes at a
  different hour.
- **Booking a Manual Review request.** Requests routed to Manual Review consume
  no capacity until staff book them, and this screen is the only thing that can:
  it runs the same two-step planner transaction the public path runs. Staff give
  an explicit date and time rather than pick from offered Slots, because a
  request in Manual Review yields none by definition.
- **Dragging on the board.** With JavaScript on, a movable row can be dragged
  onto another day, or armed with its **Move** button and placed with that day's
  own button; Escape stands it down. A drag proposes one thing — that Reservation,
  that day, at the hour it already arrives — and changing the hour is the Replan
  form's job, which the same row links to. It is an enhancement over links that
  already work: nothing on the board is reachable only by dragging.

No operation runs on a GET. Every one of them mutates through the planner and the
reservation store inside the Resource Calendar Guard locks, and a refusal has
changed nothing at all.

## Operations runbooks

Deploying, running, and rolling back the Commerce-driven Dispatch service is
documented under `docs/operations/`:

- [Express Courier launch](../../docs/operations/express-courier-launch.md) —
  taking the Dispatch Planner live as the sole authority for new bookings:
  operator prerequisites (including the on-site payment gateway), the deploy
  sequence, pre-launch verification, smoke checks, the failure-behaviour matrix,
  and monitoring.
- [Express Courier rollback](../../docs/operations/express-courier-rollback.md) —
  restoring service in tiers without deleting paid orders, confirmed
  Reservations, review records, legacy tables, or audit history.
- [Dispatch background workers](../../docs/operations/dispatch-workers.md) — the
  Advanced Queue queues, the ECA reaction layer, and what does and does not happen
  when a worker or Redis is down.
