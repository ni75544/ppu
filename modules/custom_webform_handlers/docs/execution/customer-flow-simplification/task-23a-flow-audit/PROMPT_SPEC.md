# Task 23A — Reduce the public flow to the minimum necessary facts

You are Claude Code CLI working in `C:\Users\mo737\ppu`.

## Objective

Audit the current customer-facing Webform and Commerce journey. Produce a
field-by-field minimum-data map for a simple three-screen Express Courier flow.
This task is read-only. Do not change source, config, active Webforms, database
data, DDEV, credentials, routes, payments, or Git state.

## Desired public flow

1. **Where and what:** pickup, delivery, service, and the one package/delivery
   mode fact necessary for eligibility.
2. **Price and pickup:** server quote plus earliest feasible pickup; reveal more
   times only when the customer requests them.
3. **Contact and payment:** name, phone, email, final summary, existing Commerce
   checkout, and synchronous reservation confirmation.

Access, stairs, parking, specialty-item, notes, and move-specific facts must be
conditional or a Manual Review branch, not a required wall of fields for a simple
Courier request.

## Required inspection

Read root policy, `CONTEXT.md`, the actual Task 21A diff, both execution-pack
READMEs, and the active/exported form configuration. Use Graphify and GitNexus
for PHP/JS callers, direct YAML inspection for form/library/routing wiring, and
read-only Drush or DBHub only when root policy requires it.

Inventory at minimum:

```text
webform.webform.hg.yml and the active equivalent
src/Plugin/WebformHandler/AddressDistanceHandler.php
src/Checkout/DispatchCheckoutStarter.php
src/Commerce/DispatchCheckoutManager.php
src/Dispatch/DispatchBooking.php
src/Dispatch/Policy/ConfigDispatchEligibility.php
src/Dispatch/Policy/ConfigServiceDurationPolicy.php
js/address_autocomplete.js
js/distance_calculator.js
js/dispatch_slots.js
custom_webform_handlers.libraries.yml
```

## Required report

For every public field, report:

- current screen or wizard step;
- machine name and all server-side consumers;
- whether it is required for immediate quote, eligibility, scheduling, checkout,
  dispatch, receipt, or only advanced/manual review;
- whether it may be hidden until needed, moved, defaulted safely, or must stay
  visible;
- whether removing it would break a server rule or lose operational data.

Define the proposed three-screen field list and Manual Review branch. State the
route-change invalidation rule and confirm Task 21A's requirement that date, slot,
and package changes never create a new route call.

Report no mockups and make no edits. Include changed files as `none`, evidence
systems and limits, the field inventory, data-loss risks, and the exact minimal
target scope for Task 23B. Stop without committing.

