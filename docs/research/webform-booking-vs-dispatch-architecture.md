# Webform Booking versus the Dispatch planner

## Decision

Do **not** replace the Dispatch planner with `webform_booking`, and do not put
both widgets on a dispatch Webform. `webform_booking` is a maintained fit for
fixed-duration, seat-counted appointments; this project needs a route-aware,
variable-duration, single-driver resource calendar with a separate Commerce
authorization -> capacity confirmation -> capture boundary. It would create a
second slot authority and reintroduce the duplicate-date/slot failure class.

Use the existing Dispatch planner as the sole capacity authority. Refactor the
public UI so every self-bookable service calls the same Dispatch offer/selection
adapter, parametrised by a service profile; use a Drupal View over the existing
Dispatch reservation ledger for the admin list, rather than treating Webform
submission values as the reservation system of record.

## What `webform_booking` provides

Version 1.2.0 is a stable Drupal 10/11 release (30 July 2026), requires Webform,
and is security-advisory covered. Its own Composer metadata requires
`drupal/core:^10 || ^11` and `drupal/webform:^6.0`.

- [Project page](https://www.drupal.org/project/webform_booking) (features,
  support status, compatibility, and install command).
- [1.2.0 release/tag](https://git.drupalcode.org/project/webform_booking/-/tags)
  (timezone fixes, SQL-scoped availability, access checks, PayPal handling, and
  the project's documented remaining cancel-token/open-availability weaknesses).
- [Composer metadata](https://git.drupalcode.org/project/webform_booking/-/raw/1.2.0/composer.json)
  and [module metadata](https://git.drupalcode.org/project/webform_booking/-/raw/1.2.0/webform_booking.info.yml).

The module supplies one `webform_booking` element. The element's configuration
describes fixed calendar geometry: start/end dates, excluded weekdays/dates/time
periods, a fixed time-interval grid, fixed slot duration, seats per slot, and
optional consecutive slots. The module returns availability through public day
and slot endpoints, validates a selected slot against capacity at Webform
validation time, and stores a booking in Webform submission data as a value
such as `YYYY-MM-DD HH:MM|seats` (or the multi-slot equivalent).

- [Booking element](https://git.drupalcode.org/project/webform_booking/-/blob/1.2.0/src/Plugin/WebformElement/WebformBooking.php)
- [Availability controller](https://git.drupalcode.org/project/webform_booking/-/blob/1.2.0/src/Controller/WebformBookingController.php)
- [Wall-clock slot grid](https://git.drupalcode.org/project/webform_booking/-/blob/1.2.0/src/SlotGrid.php)
- [Seat availability query](https://git.drupalcode.org/project/webform_booking/-/blob/1.2.0/src/SlotAvailability.php)
- [Routes](https://git.drupalcode.org/project/webform_booking/-/blob/1.2.0/webform_booking.routing.yml)

It does **not** model a driver, vehicle, depot, route matrix, travel/reposition
time, service-specific work duration, a variable occupied window, quote
snapshot, Manual Review, or Drupal Commerce. Its source has a PayPal-specific
order/capture path, not a Commerce integration. Capacity is read from prior
Webform submission data by webform and element key; the module must not be
assumed to provide the project's transactionally serialized
authorize -> confirm-capacity -> capture flow without a separate concurrency
design and proof.

This is also a poor fit for the project's no-public-Hold rule: its model is
selected fixed slot -> submit/validate a Webform, whereas Dispatch must offer
only route-feasible times and reserve capacity atomically at the payment
boundary. The module's releases explicitly document a PayPal integration and
known cancellation/availability endpoint limitations; neither is a substitute
for the current Commerce/Dispatch boundary.

## Why the existing planner remains authoritative

This module already stores a Dispatch reservation as a resource-specific,
variable UTC window with route and price snapshots. Its schema explicitly says
overlaps cannot be expressed by a single fixed-slot key and uses ordered
Resource Calendar Guard locks to serialize calendar operations:

- `modules/custom_webform_handlers/custom_webform_handlers.install:90-176`
- `modules/custom_webform_handlers/src/Dispatch/Storage/DatabaseReservationStore.php`
- `modules/custom_webform_handlers/src/Dispatch/DispatchBooking.php`

`DispatchBooking::offer()` builds a server-normalized request, assesses
eligibility, and asks the planner for route-feasible Slots. It distinguishes
incomplete facts from Manual Review, and carries the selected local date as a
server-checked contract. That is the correct seam for every service; copying
per-service JavaScript or adding an independent booking element is not.

## Standardize all services on the Courier experience

1. Freeze the Courier UI/data contract as the reference: one canonical date
   field, one Dispatch Slot list, selected Slot timestamp, and a server-owned
   quote/eligibility result.
2. Create one declarative service-profile map in the custom module. Each profile
   names service eligibility limits, required facts, duration/route policy,
   contact/form variant, labels, Manual Review conditions, and whether it is
   self-bookable. It must not duplicate slot logic.
3. Create one server-side `BookingContext`/normalizer used by the Webform
   handler, preview endpoint, offer endpoint, selected-slot validation, and
   checkout starter. It converts the profile's visible fields to canonical
   Dispatch values and rejects date/Slot disagreement.
4. Keep exactly one reusable Webform container/adapter and one JavaScript
   behavior. It receives a profile and calls the existing Dispatch offer API;
   it never calculates capacity or copies service-specific rules into the
   browser.
5. Migrate one non-Courier service in an isolated browser-tested task, then
   repeat service-by-service. Retire the legacy reservation UI only after the
   new Dispatch flow passes desktop/mobile, incomplete, Manual Review,
   capacity-conflict, and payment-boundary checks.
6. Do not install `webform_booking` on `hg` or on a service that already uses
   Dispatch. A separately isolated spike may evaluate it only for a future
   static appointment service.

Drupal Webform remains the correct collection/rendering layer: its official
documentation describes element conditions, handlers, submission access, and
Views integration, but those features do not replace the reservation authority.
See [Webform features](https://www.drupal.org/docs/contributed-modules/webform/webform-features).

## Drupal-native admin dashboard

The initial implementation should be a versioned Drupal View whose base table
is `custom_webform_handlers_dispatch_reservation`, not a View that reverse
parses arbitrary Webform submission values. Add first-party Views integration
in the custom module with `hook_views_data()` and declare narrow relationships
to the Webform submission and Commerce order identifiers. Drupal core documents
that hook as the mechanism for exposing a custom database table to Views; an
entity instead uses `EntityViewsDataInterface`.

- [Drupal core `hook_views_data()` API](https://api.drupal.org/api/drupal/core%21modules%21views%21views.api.php/function/hook_views_data/main)

Provide a read-only page and block, for example `/admin/dispatch/reservations`,
with filters for local date range, resource, state, Webform/service, and Manual
Review/payment status. Show pickup arrival in America/New_York, service/mode,
window, reservation status, order/submission reference, and **only** the
customer fields allowed to the viewing role. The detail route can use the
existing `DispatchBoardReader` / planner controller for more data rather than
placing all PII in the grid.

Apply access at two layers: View permission plus Webform-submission access for
customer fields. `Dispatch Viewer` receives schedule/status columns;
`Schedule Manager` receives planning actions; `Financial / Customer Info Viewer`
receives the explicitly approved contact and order fields. Do not expose raw
customer contacts in a calendar tooltip or a broadly accessible View export.

The module already has `DispatchBoardReader`, `DispatchPlannerAdminController`,
reservation detail routes, and the Dispatch reservation/status/blackout tables.
Enhance that authoritative surface incrementally. Do **not** migrate the ledger
to a new content entity merely to obtain Views: a future entity conversion is
reasonable only if revisions, fieldable extensions, JSON:API, generic entity
workflows, or entity-level access become actual requirements. At present,
`hook_views_data()` is the smaller, lower-risk Drupal-native integration.

For comparison only, `webform_booking` ships an admin View based on
`webform_submission` and an **experimental** calendar submodule. Those are
appropriate for its own static booking values, not the project’s PII/status-rich
route-capacity board:

- [Shipped booking-submissions View](https://git.drupalcode.org/project/webform_booking/-/blob/1.2.0/config/install/views.view.booking_submissions.yml)
- [Calendar submodule](https://git.drupalcode.org/project/webform_booking/-/tree/1.2.0/modules/webform_booking_calendar)

## Execution boundary

Before implementation, Claude Code should produce a read-only inventory of the
current Courier contract and the first target service: element keys, handler
consumers, profile differences, offer/checkout routes, schema, existing Views
data, and role permissions. It should then create one small, browser-gated
standardization task. No installation, migration, or removal of the legacy
reservation paths should occur based on this research note alone.
