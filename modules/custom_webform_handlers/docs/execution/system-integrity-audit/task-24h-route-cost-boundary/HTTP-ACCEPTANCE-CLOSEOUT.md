# Task 24H — route-cost boundary: HTTP/AJAX acceptance closeout

Date: 2026-09-19. Site: `ppu-qa.ddev.site` only (`ppu` read-only). No commits.

## What was fixed (state persistence across the wizard)

The acceptance gate (`AddressDistanceHandler::assertAcceptedDispatchQuote`) runs on the
page-2 → page-3 advance, **before** the quote-breakdown element (page 3) is rendered. The
route reference already rode a dedicated per-instance carrier (`custom_route_reference`)
present on every page, but the **accepted quote figure** the gate compares against did not:
it lived only in the page-3 breakdown field, so `$accepted` was always absent at the gate
and the gate answered "Your estimate has been updated … submit again to accept" on every
submit — an unclosable handshake over HTTP.

Fix — a second dedicated per-instance carrier, mirroring the reference carrier exactly:

- `AddressDistanceHandler::ACCEPTED_QUOTE_KEY = 'custom_accepted_quote'` — hidden element
  added on **every** wizard page by `alterForm()`, registered in `field_names`.
- Populated on page 1 from the successful preview (`js/distance_calculator.js` →
  `setMappedField('accepted_quote', …)`; also cleared by `clearMappedFields`). Library
  bumped 1.1.19 → 1.1.20.
- Persisted across steps by `validateForm()` → `persistAcceptedQuote()` (FormState build
  value + submitted value + submission element data), re-seeded each render via
  `acceptedQuoteValue()` on `#default_value`, restored on rebuild.
- The gate reads `$accepted` from this carrier first (fallback to the breakdown field for a
  single-page form). On a price change it now also rewrites the carrier so a re-accept is
  reachable in one further submit.
- Bounded/validated (`sanitizeAcceptedQuote`): ≤4096 chars, must decode. It is **evidence of
  the figure the customer saw, never price authority** — the gate re-prices from the
  server-owned route fact named by `custom_route_reference` and only equality of
  `total_cents` + `currency` + `rate_version` lets the save continue. A forged carrier cannot
  lower the price, only trigger a re-accept.

Exact-reference ownership, server-side re-price, per-instance isolation, and "no route
recompute on advancement" are all preserved. No owner/pair recovery. No DI change.

## Bounded HTTP/AJAX reproduction — `wizard-http-repro.sh` (ppu-qa)

One cookie jar, real CSRF tokens, real endpoints. Substantive result: **11/11 pass, exit 0**
with all instrumentation removed; the measured run (counters live) is
`wizard-http-acceptance-20260919T194500Z.log` (**13/13**, incl. the counter deltas):

- cold preview → route_reference + accepted-quote carrier minted; **+1 customer-route calc**.
- page 1 → page 2; page 2 re-renders **both** carriers populated.
- real dispatch **offer** → real offerable arrival (`at=1789992000`, 08:00 Mon 2026-09-21).
- page 2 → **page 3 genuinely reached** (page-3-only field present), **no** "no longer
  available" warning, **no** "submit again" handshake stall, **+0 customer-route calcs** on
  advancement; scheduling-leg increments recorded on their **separate** key (+2 from the
  offer), never conflated with customer-route.
- external routing requests: 0 (development_fixed, offline fixtures).

## Instrumentation removed at acceptance

`[T24H-DIAG]` counters removed from `RoutingModeEntryProvider`, `DistanceProviderRouteEstimator`,
and `FixedDevelopmentRouteProvider`; the `diagRetrieve` logger removed from `RouteFactStore`.
QA state keys deleted: `t24h_customer_route_calls`, `t24h_scheduling_leg_calls`,
`t24h_calc_count`. `php -l` clean on all changed files; `git diff --check` clean; QA `drush cr`
run; graphify rebuilt.

## Out-of-scope blocker surfaced (separately tracked, NOT a route-cost defect)

The reproduction's real `dispatch/hold` step 500s **on QA only**: the reservation INSERT writes
`price_snapshot_version`, a column **absent on QA** (`custom_webform_handlers_dispatch_reservation`).
QA module schema is **10040**; the column is added by **update_10046** (Task 24A). The default
site `ppu` is at **10046** and has the column, so the hold works there — this is a **stale-QA-
database** gap, not a code defect, and it is a **Task-24A schema-readiness** matter, not the
route-cost boundary. Repair needs an update-hook run, which the directive forbids. It does not
gate the page-2 → page-3 advance: the hg dispatch workflow treats the arrival as advisory and
takes the Hold at checkout, so page 3 is reached with the hold blocked (proven above). Reported
in full by the script (never suppressed); no probe Hold was created (INSERT rolled back), so
nothing to release.

## Addendum (2026-09-19, later) — COMPLETE acceptance incl. a real hold

An earlier revision of this doc closed 24H on the route-cost assertions alone while the hold step
was blocked. That was premature: the complete acceptance flow had not passed. Corrected under an
explicit **QA-only migration authorization**.

- Reviewed the existing pending updates **10041–10046** (all idempotent, guarded, this module's own
  tables/config; max hook is 10046 so nothing beyond it runs).
- Verified target with the explicit `https://ppu-qa.ddev.site` URI; took a **verified restorable
  QA-only backup** (`/tmp/ppu-qa-pre10046.sql.gz.gz`, gzip-OK, 116 CREATE TABLEs).
- Applied via Drupal's supported mechanism: `drush --uri=https://ppu-qa.ddev.site updatedb -y` →
  10041–10046 all completed, exit 0. No new migrations, no manual version spoofing, no unrelated
  updates, **default site untouched**.
- Verified: QA module schema **10046**; `price_snapshot_version varchar(64)` present on
  `custom_webform_handlers_dispatch_reservation`; services instantiate
  (`DispatchCheckoutStarter`, `RouteFactStore`) with routing **mode=development_fixed** (DDEV
  forces offline routing despite update_10045 setting `routing_mode=live_cached` in config —
  no paid/live routing). Explicit QA `drush cr`.

**Complete bounded HTTP acceptance — 13/13 pass, exit 0** (measured log
`wizard-http-acceptance-20260919T201557Z.log`; production-clean re-run after instrumentation
removal `wizard-http-acceptance-postremoval-*.log` 11/11): one cookie jar, one cold preview
(**+1 customer-route**), real offer, **real advisory Hold taken (status=success)** through the
existing session-based contract (no public hold token exposed or invented), page-2 submit with
both persistent carriers → **page 3 reached**, no warning, no handshake stall, **+0 customer-route
on advance**, scheduling-leg on its **separate** key (+2), external routing 0 (development_fixed).
Probe Hold **released (status=success)**; QA left with **0 non-expired hg holds** (one expired
probe row was cleaned).

Correction to the prior note: **column presence on the default site `ppu` does NOT prove its holds
succeed** — that was an overclaim. The `ppu` reservation failure remains **unresolved and
separately tracked**; only the QA hold has been proven to work end-to-end here.

## Verdict (final)

Task 24H **route-cost boundary + wizard state-persistence: COMPLETE and proven over the COMPLETE
real HTTP/AJAX flow (incl. a real, released hold) on ppu-qa @ schema 10046**, production code
clean. The `price_snapshot_version` gap that blocked the QA hold is **resolved on QA** by the
authorized migration; the unrelated **`ppu` reservation failure remains open** and separately
tracked. Task 24B remains **paused** pending its QA prerequisites and required GitNexus tooling.
