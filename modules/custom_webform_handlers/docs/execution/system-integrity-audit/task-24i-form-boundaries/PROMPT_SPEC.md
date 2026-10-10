# Task 24I — Harden address, booking and recovery interaction boundaries

You are Claude Code CLI in C:/Users/mo737/ppu. Read ../RULES.md and ../README.md. Prerequisites: 24B, 24E, 24G and 24H.

## Objective and current weaknesses

Close E15 and customer-facing E12/E17. A failed attempted route can strand the same address pair in JS. The preview endpoint casts addresses before checking input types. Empty-slot responses can conflate bad facts, routing outages and real lack of capacity. Previously reported autocomplete fill and required-field errors need actual browser acceptance.

## Files

- js/address_autocomplete.js; distance_calculator.js; dispatch_slots.js
- src/Controller/AddressSuggestionController.php; DistancePreviewController.php; DispatchController.php
- src/Dispatch/DispatchRequestFactory.php; DispatchBooking.php; DispatchReasonText.php
- src/Plugin/WebformHandler/AddressDistanceHandler.php
- src/Plugin/Commerce/CheckoutPane/DispatchConfirmation.php and relevant forms
- custom_webform_handlers.libraries.yml; routing/schema only as required

## Execution

1. Reproduce the current flow with self-hosted suggestions and fixed routes. Verify which physical input holds the canonical address and which structured fields Webform actually requires. Do not restore Places autocomplete.
2. Validate JSON object shape and scalar types before casts; enforce bounded address/list/body lengths, valid UTF-8/coordinates/dates, supported service/mode and finite bounded package dimensions/weight.
3. Keep address commitment separate from typing. Reject ambiguous/incomplete input; distinguish invalid address, unsupported service area and unavailable geocoder. Dataset coverage is not automatically business coverage.
4. Ensure one owner for dirty/commit events and complete detach cleanup. Repeated AJAX/wizard attachment must not duplicate listeners, timers or route work. Older autocomplete/quote/slot responses must not overwrite newer selections.
5. Recover a lost response using 24H's server operation/result. Do not force customers to alter a valid address to retry, or clear the ledger in a way that triggers another paid request.
6. Clear dependent slot/price state after material job changes while preserving contact input and recoverable order identity. Service changes must remove stale hidden inputs without erasing necessary handling facts.
7. Return distinct bounded outcome codes for truly full day, incomplete facts, preparation unavailable, invalid input, conflict/expired hold and reconciliation pending. Public copy remains simple; admin diagnostics retain the precise internal reason.
8. Keep sensitive quote/booking/review responses private and non-cacheable; audit anonymous CSRF, session ownership, throttling and resource exhaustion at public endpoints.

## Verification and completion

Manual browser matrix: select both endpoints, verify full structured fill, plain-text blur, incomplete input, duplicate commit, change service, change date, choose slot, back/forward wizard, AJAX rebuild, two tabs, offline/online transition, stale response, expired hold and lost payment return.
Include direct malformed scalar/array JSON, overlong input, same endpoint, ambiguous/NYC-border address and bad date checks with zero provider calls.
Confirm keyboard/screen-reader errors identify the correct input and server validation remains authoritative.
Record actual browser acceptance or keep it pending; run allowed JS/PHP/YAML/Drush/diff checks.

## Red lines

No broad visual redesign, new mandatory customer steps, paid address lookup, public hold timer, client-only validation or claim that a generic “no times” message proves the driver is full.
