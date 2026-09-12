# Task 20B — Stop routing during uncommitted address typing

You are Claude Code CLI executing Task 20B in `C:\Users\mo737\ppu`.
Task 20A's frontend impact checks must be complete. Read root `CLAUDE.md`, RTK,
applicable rules, and the routing-cost-reduction README. This change may ship
before the provider migration is ready.

## Objective

Suppress redundant frontend routing immediately. An input event invalidates stale
results; a committed address pair requests a new quote. Preserve ordinary text
entry, autocomplete selection, AJAX wizard rebuilds, and server authority.

## Target files

```text
modules/custom_webform_handlers/js/distance_calculator.js
modules/custom_webform_handlers/js/dispatch_slots.js
modules/custom_webform_handlers/js/quote-summary-sidebar.js
modules/custom_webform_handlers/src/Plugin/WebformHandler/AddressDistanceHandler.php
modules/custom_webform_handlers/src/Controller/DistancePreviewController.php
modules/custom_webform_handlers/custom_webform_handlers.libraries.yml
modules/custom_webform_handlers/config/install/custom_webform_handlers.settings.yml
modules/custom_webform_handlers/config/schema/custom_webform_handlers.schema.yml
modules/custom_webform_handlers/docs/architecture/task20b-evidence.md
```

Modify only files necessary for this behavior; use Graphify/GitNexus before source
changes and direct YAML inspection. Reuse existing debounce, AbortController,
request sequencing, flood protection, and Drupal behaviors.

## Required implementation

1. Make raw address `input` clear route/quote/selected Slot immediately and cancel
   pending work. It must not dispatch a route request. Abort is UI cleanup: it
   does not undo an HTTP request already billed upstream.
2. Commit on a valid autocomplete selection or delegated `focusout` for ordinary
   text inputs (`blur` does not bubble). Support keyboard selection and an explicit
   calculate/continue action when the widget does not expose a reliable blur.
   Coalesce event bursts with one configurable debounce, initially 600 ms.
3. Require both normalized addresses to be complete enough for server resolution.
   Use one last-requested/last-successful address-pair fingerprint and an in-flight
   marker. Selection followed by change and blur must cause at most one preview
   request for unchanged facts. An invalidated old response cannot restore results.
4. Retain the current Places widget initially. Do not claim the new debounce
   controls the widget's own prediction requests. Document separately its session
   behavior, selected-place field mask, and map-load costs from Task 20A. Fetch
   only required place fields where the installed API supports that change.
5. Coordinate `dispatch_slots.js` with committed-route state. It must not bypass
   the quote gate by requesting schedule routes for partial addresses. Suspend
   offer polling while input is dirty, the form is detached, or the tab hidden.
   After a committed pair is resolved, normal date/item-driven offers may resume.
6. Package/service edits can change price/eligibility without changing the route.
   Reuse the existing valid route and server cache for that pair; never trust a
   browser-provided distance to calculate an authoritative price.
7. Check behavior attach/detach triggers and clean up timers, requests, and named
   event listeners. Preserve `Drupal.behaviors`, `once()`, accessible status text,
   and no-JavaScript server validation. Avoid adding a frontend framework.
8. Preserve CSRF, request-size bounds, flood checks, and no-store responses. A
   server-side normalized fingerprint reduces duplicate work across browser
   requests; client debouncing alone is not the cost control.

## Verification

No automated tests or browser probes. Inspect changed JS paths and wiring; refresh
Graphify after relevant source changes. Run the required bounded cache rebuild:

```bash
rtk proxy ddev exec vendor/bin/drush cr
```

Manual acceptance instructions: typing 20 characters produces zero new preview or
offer requests before commit; selection+blur produces one preview; unchanged
repeat blur produces none; changing either endpoint clears the selected Slot;
slow earlier responses are ignored; an AJAX step rebuild does not duplicate
listeners; complete manual addresses still work. Record checks actually performed
and leave unperformed UI checks pending. Stop before changing the provider alias.
