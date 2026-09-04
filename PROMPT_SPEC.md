> **Superseded for the Commerce/Express Courier roadmap.** Do not execute this
> fixed-slot Webform prompt for the current PRD. Use the sequential prompts in
> [`docs/execution/express-courier-commerce/`](docs/execution/express-courier-commerce/README.md).
> This document is retained only as history for the legacy reservation workflow.

# PROMPT SPEC — Webform Slot Reservation and Administration

## Mission

Implement the reservation and administrator-management phase for the Drupal 10
custom module at exactly:

```text
modules/custom_webform_handlers/
```

The Moving Service Request Webform already has server-authoritative Google
Routes v2 quote calculation. Add a server-authoritative reservation layer for
the configured Preferred Service Date and Preferred Service Time without
altering, clearing, recalculating, or otherwise coupling to existing quote
logic.

Deliver two outcomes:

1. A completed Webform submission can confirm exactly one selected service
   slot, and competing visitors cannot both claim the same slot.
2. Administrators with a dedicated permission can list, filter, inspect, block,
   and release reservation records at
   `/admin/structure/webform/reservations`.

## Non-negotiable constraints

- Work only in `modules/custom_webform_handlers/`, except for module-level
  configuration/schema files that belong to that module. Do not edit Drupal
  core, contrib, Composer files, DDEV configuration, or duplicate module
  directories.
- Preserve all unrelated uncommitted work. The current worktree may already
  contain a partial reservation implementation; inspect it and complete or
  correct it rather than creating parallel reservation systems.
- Use Drupal 10 / PHP 8.4 patterns: dependency injection, `*.services.yml`,
  `*.routing.yml`, `*.permissions.yml`, Form API, CacheableMetadata, and
  `Drupal.behaviors`.
- Do not write or run PHPUnit, Kernel, Functional, FunctionalJavascript,
  Selenium, Playwright, browser, curl, linter, or other test suites. Do not
  change test or Composer files.
- Do not add an external scheduler, cache, Drupal lock service, read-then-write
  locking, diagnostics, probes, or a second persistence mechanism.
- Do not expose hold tokens, token hashes, session/owner values, active-slot
  keys, reservation IDs, or a cached availability snapshot through
  `drupalSettings` or public JSON responses.
- Do not move quote calculation into reservation code and do not make a quote
  error create, release, or invalidate a reservation.

## Architecture to implement

Keep the reservation module deep: controllers, forms, the Webform handler, and
JavaScript must call a small reservation-manager interface. Only its database
adapter may read or write reservation state or contain SQL.

### 1. Canonical schedule definition and slot value

Create or retain these focused PHP types under
`src/Reservation/`:

- `ReservationDefinitionProvider`: resolves the enabled Address and Distance
  handler for a Webform, validates its reservation configuration and referenced
  Webform elements, canonicalizes the configured time options, resource,
  timezone, booking horizon, allowed weekdays, hold duration, and slot
  duration.
- `ReservationDefinition`: immutable schedule data. Its public constraints are
  safe for the browser; it must contain no state or secrets.
- `ReservationSlot`: immutable canonical Webform ID + resource ID + local
  start/end instants + timezone. Its active-slot key must be a deterministic
  SHA-256 value derived from the canonical capacity identity, never a
  browser-submitted arbitrary string.
- `ReservationException`: stable, non-secret error codes such as
  `not_configured`, `invalid_slot`, `slot_unavailable`, `hold_not_found`, and
  administrative not-found/not-active cases.

Configuration must name the Webform date field, time field, hidden hold-token
field, and optional resource field. Reject invalid/missing configuration
fail-closed when reservations are enabled. All public endpoints and final
submission validation must derive the definition and slot on the server; do
not trust a client-provided start timestamp, state, key, or resource outside
the configured element's allowed values.

If the configured time options can overlap for the configured duration, reject
that configuration or derive a capacity key that prevents overlap. Two records
whose service intervals overlap must not be treated as independent capacity.

### 2. Database schema and concurrency authority

Add `hook_schema()` and an idempotent update hook for existing installations
that create one reservation table, for example
`custom_webform_handlers_reservation`. Include:

```text
reservation_id       serial primary key
webform_id           varchar_ascii(128), required
resource_id          varchar_ascii(128), required, default "default"
slot_start           UTC Unix timestamp, required
slot_end             UTC Unix timestamp, required
timezone             varchar_ascii(64), required
state                varchar_ascii(16), required
active_slot_key      varchar_ascii(64), nullable, UNIQUE
hold_token_hash      varchar_ascii(64), nullable
owner_hash           varchar_ascii(64), nullable
submission_id        unsigned integer, nullable
expires_at           UTC Unix timestamp, nullable
created / changed    UTC Unix timestamps, required
```

Add indexes appropriate to expiry cleanup, submission lookup, and availability
by `(webform_id, resource_id, slot_start)`.

States are exactly:

- `held`: temporarily owns the active-slot key until `expires_at`.
- `confirmed`: permanently owns the active-slot key and points at the completed
  Webform submission.
- `blocked`: permanently owns the active-slot key and has no owner or token.
- `expired` and `released`: historical only; both must have NULL active-slot
  key, token hash, owner hash, and expiry.

`active_slot_key` is the one and only contention authority. At most one active
row may have a key; nullable unique keys preserve history after release.

On hold or administrator block, first expire lapsed holds in the same database
transaction, then attempt one `INSERT` with the active key. Translate an
integrity-constraint violation into `slot_unavailable` (HTTP 409 for public
mutations). Never decide availability by a SELECT followed by an INSERT.

Store only SHA-256 hashes of cryptographically random hold tokens and of the
server-side anonymous-session owner identifier. Never store or render either
raw secret. A hold is valid only when its key, token hash, owner hash, state,
and unexpired timestamp all match in the same database query/update.

### 3. Reservation-manager interface

Create or retain `ReservationManagerInterface` and a single
`DatabaseReservationManager` adapter. Keep SQL exclusively in the adapter.
Its interface should cover only the use cases below:

- `availability(definition, localDay)`: read booked canonical `HH:MM` options
  for confirmed, blocked, and unexpired held rows. This is presentation only.
- `hold(slot, owner)`, `renewHold(slot, token, owner)`, `verifyHold(slot,
  token, owner)`, `release(token, owner)`, and expiry cleanup.
- `confirm(token, submissionId)`: atomically convert only the matching,
  unexpired `held` row to `confirmed`; make a repeated call for the same
  submission idempotent and reject every other zero-row result.
- administrator reads (list/count/detail/calendar) and administrator block or
  release actions.

Public availability must exclude expired and released rows even if a cleanup
request has not run. The manager may lazily expire holds during mutations; it
must not rely on cron for correctness.

### 4. Public reservation endpoints and browser behavior

Add a `ReservationController` with narrow routes:

- `GET /custom-webform-handlers/reservation/availability/{webform}`: accepts
  canonical date and configured resource, returns only booked canonical time
  values and public schedule information.
- `POST /custom-webform-handlers/reservation/hold`
- `POST /custom-webform-handlers/reservation/renew`
- `POST /custom-webform-handlers/reservation/release`

All mutation routes require Drupal's CSRF request-header token. Validate all
input server-side and return stable JSON error codes; return a raw hold token
only to the same request that successfully created its hold. Remember the token
server-side against the session's private owner identity so an AJAX rebuild can
recover it for that exact slot. Do not include it in settings, availability,
calendar, or administrative responses.

Add a versioned `reservation_slots` asset library with
`js/reservation_slots.js` and `css/reservation_slots.css`. Attach it only when
the handler's reservation settings are enabled and valid. In
`AddressDistanceHandler::alterForm()` publish only:

- Webform machine name;
- configured date, time, token, and optional resource field names;
- public schedule constraints; and
- generated availability, hold, renew, release, and CSRF-token URLs.

Add the altered Webform entity and generated URLs as cacheable dependencies.
The form must attach this reservation behavior whether or not a browser Maps
key exists; that key controls quote preview, not booking correctness.

Implement the behavior beneath the existing quote form selector and use
`once()` / `Drupal.behaviors` so AJAX wizard rebuilds are safe. It must:

1. Refresh availability on attachment and whenever date or resource changes.
2. Canonicalize a selected date to `YYYY-MM-DD`; if there is no valid date or a
   configured resource is not selected, restore all prior availability marks.
3. Preserve each time option's original label and disabled state, then mark
   booked options disabled with accessible `aria-disabled` state and a visible
   “Booked” label/class. Restore all original state before applying every new
   response and after a request failure.
4. Clear selected time and the hidden hold token when its time becomes booked;
   show a recoverable message.
5. Use `AbortController` plus request sequencing so an earlier AJAX response
   cannot overwrite the newest date/resource state.
6. Request a hold when a free slot is chosen; on success write the raw token
   only to the configured hidden field. Release the prior hold on slot/date/
   resource change and on unload when possible. Renew a still-selected hold
   while the wizard remains open. Treat a 409 as a recoverable “choose another
   time” outcome.

The browser is only a usability layer. It may be bypassed without weakening
server-side reservation enforcement.

### 5. Webform-handler lifecycle gate

Extend the existing `AddressDistanceHandler`; do not introduce another handler
that can independently reserve the same submission.

- Add configuration defaults, form fields, and schema for enabling
  reservations and naming all referenced Webform fields plus timezone, allowed
  weekdays, booking horizon, slot duration, and hold duration.
- In final non-draft validation, if both configured date and time are empty,
  leave the optional slot unreserved. If either is supplied, require both, a
  canonical slot, a token, and a session owner whose matching hold is still
  valid. Add a Form API error to the time element for any failure.
- Repeat that guard before persistence for a new non-draft submission so
  programmatic saves/imports and admin-added submissions cannot bypass form
  validation. Do not demand a consumed token again for an existing confirmed
  submission being edited.
- On the first completed save only, confirm the held row with the assigned Webform
  submission ID. Confirmation failure is a real failure: do not silently save a
  selected slot as if it were reserved. Confirmation must be idempotent for
  Drupal save lifecycle re-entry.
- Do not modify quote fields in any reservation error path. Place reservation
  checks before quote code that may return early so missing route information
  cannot skip slot enforcement.

### 6. Administrator dashboard

Create a restricted permission:

```yaml
administer custom webform reservations:
  title: 'Administer Webform reservations'
```

Create these routes, all gated by that permission:

```text
GET  /admin/structure/webform/reservations
GET/POST /admin/structure/webform/reservations/block
GET     /admin/structure/webform/reservations/{reservation_id}
GET/POST /admin/structure/webform/reservations/{reservation_id}/release
```

Add a menu link under the Webform administration area. Use a
`ReservationAdminController` for read-only overview/detail pages and dedicated
Form API forms for block/release state changes. Never mutate on GET.

The overview must provide bounded filters for Webform ID, resource, local
service date, and state; use a pager; and show a summary plus a table of:
reservation ID, Webform, resource, local date/time/timezone, state, linked
submission when present, created/changed, and operations. Show no active-key,
token, owner, or hash values. Detail must show the same safe fields and link
to the Webform submission only when one exists. Ensure filter semantics are
shared by list/count/pager so totals cannot drift.

The block form derives its slot from the selected configured Webform/resource/
date/time using `ReservationDefinitionProvider`, then calls `block()`. The
release confirm form may release only an active held, confirmed, or blocked row
through the manager. Both actions must use Form API CSRF protection and report
contention or stale-record errors safely.

### 7. Do not regress quote calculations

- Retain existing Google Routes v2 server-side authority, all quote field
  clearing/recalculation, manual-review metadata, and existing JS/CSS asset
  behavior.
- A reservation disabled or misconfigured on one handler must not alter quote
  rendering or calculations. It should simply not attach the reservation UI;
  an enabled-but-invalid final submission must fail closed.
- Never trust client-computed distance, quote totals, reservation state, or
  availability as authoritative data.

## Completion protocol

Review changed module files for Drupal 10/PHP 8.4 syntax and dependency wiring
only. Do not run a test suite or add tests. Preserve unrelated modifications.

After all code edits, run exactly:

```bash
ddev drush cr
```

Then exit. Do not run `drush updb`, Composer, PHPUnit, Selenium, browser
automation, or any additional command.
