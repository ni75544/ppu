# Task 5 — Build Homepage Steps 1–2: route, service, quote, and Slots

You are Claude Code CLI executing Task 5 for the Drupal 10 Express Courier and
Commerce roadmap in `C:\Users\mo737\ppu`. Tasks 1–4 must be complete.

Task 4's server/configuration implementation may be complete while its browser
manual check remains pending when browser control was not explicitly authorized.
Browser authorization is required for the checks below. The dispatch offer endpoint
is itself correctly denied until `BookingWorkflowGate` assigns the form to
`dispatch`, so local verification must use the controlled two-phase assignment in
requirement 11; do not treat that safety property as a reason to skip the offer
checklist.

## Objective

Turn the homepage into the first two stages of the four-step journey while reusing
the existing Webform handler, server quote calculator, Dispatch Booking offer seam,
and Google address autocomplete.

Step 1 collects From, To, and Service Type. Step 2 shows one authoritative quote and
only server-feasible pickup windows. Selecting a Slot does not create a Hold.

## Target files

```text
modules/custom_webform_handlers/src/Plugin/WebformHandler/AddressDistanceHandler.php
modules/custom_webform_handlers/src/Controller/DispatchController.php
modules/custom_webform_handlers/src/Dispatch/DispatchBooking.php
modules/custom_webform_handlers/js/dispatch_slots.js
modules/custom_webform_handlers/js/distance_calculator.js
modules/custom_webform_handlers/js/quote-summary-sidebar.js
modules/custom_webform_handlers/css/
modules/custom_webform_handlers/custom_webform_handlers.libraries.yml
modules/custom_webform_handlers/custom_webform_handlers.routing.yml
modules/custom_webform_handlers/custom_webform_handlers.module
versioned Webform/block configuration or module update script selected after inspection
```

Do not edit duplicate module copies. Preserve unrelated UI and quote work.

## Required implementation

1. Place/embed the active logistics Webform (`hg`, unless the administrator changes
   the configured source form) on the Drupal homepage through supported
   Webform/block configuration. Do not hardcode rendered Webform HTML into Twig.
2. Render the existing `service_type` Webform option as an accessible radio-card
   group; keep its submitted machine value and server validation unchanged. The
   `courier` option must display as **Express Courier** and be pinned first. Until
   Task 8 supplies popularity data, order all remaining configured options
   deterministically. Do not create a second service selector or a client-side
   source of service eligibility.
3. Make Step 1 exactly: From, To, Service Type, and Delivery Mode when Express
   Courier is selected. Google autocomplete must progressively enhance ordinary
   text inputs; server validation remains authoritative if JavaScript fails.
4. Make Step 2 exactly the facts that determine Courier eligibility, duration, or
   price: package description/count/weight/dimensions, prohibited-item
   acknowledgment, applicable urgency, and Store Pickup merchant/ready/order/pickup
   facts; then the server quote and Slot offer. Move purchaser name/phone/email and
   pickup/delivery handoff contacts/instructions, optional package photo, and
   handoff preference to Step 3 because none may alter an offer. Retain each
   existing element key so stored submissions remain readable. Show price, distance,
   pickup promise, and estimated delivery in New York local time.
5. Keep the existing offer endpoint POST + CSRF + no-store. It may return only the
   customer's own quote/offer facts and selectable Slot timestamps. It must not
   return occupied times, schedule neighbours, Dispatch Windows, Hold expiry,
   tokens, owner/session identifiers, Reservation IDs, or another customer's data.
6. Refactor `dispatch_slots.js` so it performs offer/read behavior only. Remove the
   browser hold-on-selection flow, renewal loop, release-on-unload behavior, expiry
   display, and every countdown path. Store only the selected canonical Slot value
   needed when Step 3 starts checkout.
7. Preserve request sequencing and `AbortController` behavior so stale quote/Slot
   responses cannot overwrite newer addresses, service, date, or item facts.
8. Use `Drupal.behaviors` and `once()` for AJAX wizard rebuilds. Restore accessible
   state after request failures and provide keyboard-operable Slot selection.
9. Do not expose a timezone selector. Display a small fixed label such as “Times
   shown in New York local time” only where clarity is needed.
10. Changing any pricing, eligibility, route, date, or duration fact invalidates the
    selected Slot and requires a fresh offer.
11. Use a controlled two-phase workflow-gate assignment for the configured source
    form:

    a. Before the interactive **local DDEV** checklist only, merge that form into
       the active `workflow.dispatch_webforms` allowlist through the documented
       local configuration mechanism and rebuild caches. Do not export this
       temporary assignment or commit it as source configuration. It enables the
       protected offer endpoint; it does not create a Hold or Reservation because
       Task 6's checkout manager is not invoked by Slot selection.
    b. Run the full Task 4 and Task 5 browser checklist, including the offer POST,
       Send/Receive/Store Pickup conditionals, an over-limit Manual Review, fact
       changes invalidating a selected Slot, two-session availability refresh, and
       keyboard/error behavior. Stop at Step 2; do not submit an order or enter
       real customer, payment, or private-address data.
    c. On any failed or incomplete check, remove that temporary local assignment,
       rebuild caches, retain the form as `legacy` or `disabled`, and report the
       evidence. Do not add a versioned production activation.
    d. Only after every check passes, add a separate idempotent update/config
       migration that persistently assigns that form to `dispatch`, run it locally,
       rebuild caches, and confirm the legacy fixed-slot controller is denied for
       the form. Leave every unmigrated form `legacy` or `disabled`.

## Verification

- Run JavaScript syntax/lint checks already configured in the repository; do not add
  Selenium or FunctionalJavascript coverage.
- PHP-lint touched PHP files.
- Run:

```bash
ddev drush cr
```

Manual UI checklist in two browser sessions:

- homepage loads From/To and Express Courier first;
- Send, Receive, and Store Pickup reveal correct fields;
- quote and Slots update after address/item changes;
- no Hold, expiry, countdown, renew, or release request occurs when a Slot is chosen;
- no timezone selector is present;
- keyboard and error states remain usable.

Stop before wiring payment.
