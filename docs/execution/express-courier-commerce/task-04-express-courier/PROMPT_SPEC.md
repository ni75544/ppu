# Task 4 — Implement Express Courier facts, eligibility, duration, and price

You are Claude Code CLI executing Task 4 for the Drupal 10 Express Courier and
Commerce roadmap in `C:\Users\mo737\ppu`. Tasks 1–3 must be complete.

## Objective

Add Express Courier as the pinned, self-bookable local Service Type with Delivery
Modes Send, Receive, and Store Pickup. Extend the existing request, eligibility,
duration, route, and quote implementations; do not create parallel calculators.

This task establishes server behavior and Webform fields. It does not yet build the
new homepage presentation or payment subscriber.

## Canonical targets

```text
modules/custom_webform_handlers/src/Dispatch/DispatchRequest.php
modules/custom_webform_handlers/src/Dispatch/DispatchRequestFactory.php
modules/custom_webform_handlers/src/Dispatch/DeliveryMode.php
modules/custom_webform_handlers/src/Dispatch/Policy/
modules/custom_webform_handlers/src/Quote/
modules/custom_webform_handlers/src/Distance/
modules/custom_webform_handlers/src/Form/QuoteSettingsForm.php
modules/custom_webform_handlers/src/Form/DispatchPlannerSettingsForm.php
modules/custom_webform_handlers/config/install/custom_webform_handlers.settings.yml
modules/custom_webform_handlers/config/schema/custom_webform_handlers.schema.yml
modules/custom_webform_handlers/scripts/
modules/custom_webform_handlers/js/ and css/ only when field behavior requires it
```

Inspect the active Webform entity and existing update scripts before deciding how to
apply element changes. Do not assume a root YAML export is the database authority.

## Required implementation

1. Retain canonical Service Type machine ID `courier` and present it as **Express
   Courier**. Keep Send, Receive, and Store Pickup as Delivery Modes beneath it;
   do not create three unrelated Services or rename the already-installed
   `courier` configuration value.
2. Add conditionally required fields for:

   - package description, count, total weight in pounds, and dimensions;
   - pickup and delivery contact/instructions;
   - Store Pickup merchant, paid-and-ready confirmation, order reference, and
     pickup-name fields;
   - handoff preference and optional package photo;
   - prohibited/restricted-item acknowledgment.

3. Reorder field dependencies so address, Service Type, Delivery Mode, and item
   facts exist before quote/Slot calculation. Keep contact identity for Step 3.
4. Enforce eligibility server-side. Default self-booking policy is total weight at
   or below 30 lb, one-driver handling, one pickup, one delivery, supported local
   route, and no prohibited/uncertain contents. Everything else returns Manual
   Review, creates no Hold, and consumes no Resource capacity.
5. Add configurable Express Courier duration inputs for pickup dwell, delivery
   dwell, operational buffer, promise width, and mode-specific Store Pickup dwell.
   Continue deriving road time through the existing scheduled route adapter.
6. Add configurable, versioned Express Courier pricing: base charge, included
   miles, excess-mile rate, mode surcharge, and optional urgency surcharge. Store
   money as integer cents and include the rate version in every quote/order snapshot.
   Do not invent a client-side total.
7. Confirm the canonical Google adapter sends future departure time, traffic-aware
   preference, and a cache key that includes routing mode and a bounded departure
   bucket. Deepen the current adapter if incomplete; do not issue Google requests
   from quote, planner, controller, or JavaScript code.
8. Preserve existing moving/cargo services. A request requiring unavailable helper
   count, vehicle capacity, or manual inspection must not appear self-bookable.
9. Update administrator copy and README documentation to describe operational
   limits as configurable policy, not guarantees.

## Verification

- Do not create or run automated tests. PHP-lint touched PHP files and inspect the
  server-side eligibility, duration, and quote call paths directly.
- Run:

```bash
ddev drush cr
```

Manually verify in Drupal administration that all new settings validate and save.
Use the Webform UI to confirm conditional required states and that over-limit work
is Manual Review with no Slots. Do not start the homepage redesign.
