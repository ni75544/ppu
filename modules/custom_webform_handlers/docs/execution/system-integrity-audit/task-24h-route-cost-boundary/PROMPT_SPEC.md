# Task 24H — Extend zero-cost guarantees to every route consumer

You are Claude Code CLI in C:/Users/mo737/ppu. Read ../RULES.md and ../README.md. Execute after 24A's environment safety check and before other runtime probes.

## Objective and current defects

Close E13/E14 while preserving accepted Task 21A. Customer route entry has fixed/live guarding, but dispatch estimators and route warmers still use ordinary/offer/final chains. Price and schedule can use different providers/facts. Quote-only preview can silently call calculate() when posted cached data expires or fails validation.

A naive switch to FixedDevelopmentRouteProvider is not enough: the estimator currently requires non-NULL expiry and a routing-policy provider allowlist that does not include development_fixed.

## Files

- custom_webform_handlers.services.yml
- src/Distance/RoutingModeEntryProvider.php; RoutingModeResolver.php; FixedDevelopmentRouteProvider.php — inspect accepted behavior; extend at consumers first
- src/Distance/GoogleRoutesDistanceProvider.php; ChainDistanceProvider.php; RouteCache.php
- src/Dispatch/Route/DistanceProviderRouteEstimator.php; ScheduledRouteWarmer.php; PreparedRouteFacts.php
- src/Controller/DistancePreviewController.php
- src/Checkout/DispatchCheckoutStarter.php; src/Plugin/WebformHandler/AddressDistanceHandler.php
- js/distance_calculator.js; dispatch_slots.js
- services/config schema and narrowly scoped route-fact store/adapter if needed

## Execution

1. Map every calculate/calculateScheduled and HTTP call site: preview, preSave, checkout, offer, hold, confirmation, staff replan, maintenance and direct adapter use. Do not infer safety from aliases or JS counters.
2. Preserve 21A and introduce the smallest mode-aware dispatch/route-fact integration that makes every development path deterministic and non-networking. Keep fixture identity explicit; do not relabel synthetic output as google_routes or manufacture real traffic metadata.
3. Define valid development scheduling facts/expiry without weakening live provider eligibility or freshness. Missing fixtures must never activate a fallback network provider.
4. Introduce an explicit quote-only operation. It accepts a valid server-held route fact/reference and performs pricing only. Expired, missing or invalid facts return a typed refresh-required/error result; there is no silent provider fallback.
5. For live cached mode, separate cache/deduplication identity from the browser ledger: ordered normalized endpoints, vehicle/routing profile, route purpose and relevant data/policy/time version. Use a server-side single-flight boundary for concurrent requests and persist completion before replying.
6. Read-only availability consumes prepared facts. Any necessary refresh is separately authorized outside the public date/slot lookup and outside calendar locks; repeated frontend events must not buy routes.
7. Preserve browser values as informational. Confirmed job/price snapshots use server-owned facts from 24B. Handle address A-to-B followed by B-to-A correctly.
8. A lost response may be replayed by operation ID without another external request. Unknown provider outcomes do not trigger blind retries. A blanket “exactly one request forever” promise is not evidence of correct expiry/failure handling.

## Verification and completion

With an outbound spy/denial boundary and offline facts, verify zero external provider calls for typing, commit, duplicate selection/blur, pricing changes, date/time selection, polling, wizard rebuild, preSave, checkout, staff actions and warming.
Prove expired/fake/fixture cached payloads cannot silently call a provider in quote-only mode. Record provider-dispatch counts, not only browser fetch counts.
Live-mode policy may be inspected or exercised with local fakes only; do not make a billed call.
Run permitted PHP/JS/YAML/Drush/diff checks.

## Red lines

No weakening or replacement of 21A, enabling Google Places/maps billing, adding a paid fallback, disabling server validation, persisting provider data beyond approved policy, or network work inside any reservation transaction.
