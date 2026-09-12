# Task 20F — Implement fallback, route caching, and durable request limits

You are Claude Code CLI executing Task 20F in `C:\Users\mo737\ppu` after 20E.
Read root `CLAUDE.md`, RTK, applicable rules, the routing pack README, and 20A's
provider policy. DBHub read-only schema discovery is required before schema work.

## Objective

Return the first valid, purpose-eligible route while enforcing freshness,
capabilities, bounded latency, and cost limits even when providers or caches fail.

## Target files

```text
modules/custom_webform_handlers/src/Distance/ChainDistanceProvider.php
modules/custom_webform_handlers/src/Distance/RouteCache.php
modules/custom_webform_handlers/src/Distance/ProviderCircuit.php
modules/custom_webform_handlers/src/Distance/RoutingBudget.php
modules/custom_webform_handlers/src/Distance/Storage/DatabaseRoutingBudgetStore.php
modules/custom_webform_handlers/src/Distance/RoutingPolicy.php
modules/custom_webform_handlers/src/Distance/GoogleRoutesDistanceProvider.php
modules/custom_webform_handlers/custom_webform_handlers.services.yml
modules/custom_webform_handlers/custom_webform_handlers.install
modules/custom_webform_handlers/config/install/custom_webform_handlers.settings.yml
modules/custom_webform_handlers/config/schema/custom_webform_handlers.schema.yml
```

## Required implementation

1. Implement both existing distance and scheduled interfaces on the chain. Inject
   an explicit provider map, immutable purpose policy, shared coordinate resolver,
   cache, clock, logger, circuit coordination, and budget seam. Resolve coordinates
   once where an eligible provider requires them; adapter calls reuse the resolver's
   request memo. No container service locator and no purpose/provider value supplied
   by a visitor.
2. Choose providers by configured order AFTER purpose, capability, geocoder rights,
   coverage, enablement, and budget filtering. Preview/ordinary quote/public offer/
   warming cannot reach Google, even if an administrator enters it in the order.
   Traffic-required requests skip OSRM. Stop on the first valid eligible result.
3. Classify failures. Invalid input stops the request; provider-specific no-route
   or coverage failures may advance to the next eligible provider. Timeout/5xx
   trigger bounded fallback; 429 honors cooldown/Retry-After without sleeping in
   a public request; 401/403/config failures disable that provider until recovery.
   Never cache an outage as proof the address is invalid. No recursive retries.
4. Bound the total chain deadline as well as each adapter timeout. Implement a
   short circuit with limited half-open probes. Coalesce identical cache misses
   with Drupal coordination locks, separate from the calendar's DB authority.
   A waiting visitor gets bounded unavailable/retry behavior, not a long loop.
5. Implement layer 2 route keys per README, preserving A→B order, coordinate
   precision, provider/profile, dataset version, effective departure and route
   options. Namespace provider results; do not reuse Google or static results as
   Mapbox traffic results. Retain original fetch and expiry metadata on cache hits.
6. Persist only fields and lifetimes permitted in 20A. Optional ZIP coarse values
   never enter this authoritative route cache. Provider order/config changes
   invalidate chain selection; OSRM dataset changes invalidate relevant routes.
   Keep cache writes/read failures from discarding an otherwise usable result.
7. Consolidate caching ownership. The Google adapter currently caches internally;
   a chain-wrapped miss must not reserve a paid-call budget for an internal cache
   hit or preserve a disallowed second cache. Either move its cache into the
   shared route-cache seam for this migration or position accounting directly at
   the one actual HTTP-dispatch point. Document the single owner and preserve
   existing call compatibility. No shadow requests for provider comparison.
8. Implement durable allowance reservation with atomic SQL in the budget store,
   using a unique provider/product/purpose/billing-period identity and conditional
   increment below the configured limit. Aggregate provider-wide allowance must
   also cover all purposes. Reserve before each external attempt, including
   retries; keep uncertain/failed attempted calls counted conservatively. Apply the
   same pre-dispatch policy to a billed geocoder using a separate product allowance;
   routing savings must not create unbounded address-resolution charges. Integrate
   at the approved Geocoder adapter/transport seam without editing contrib.
   No cache eviction or Redis restart may reset this allowance. Period rollover
   follows the configured billing calendar, not a visitor timezone.
9. Add only the required budget schema and a new idempotent update hook using the
   next unoccupied number after inspecting current history. Preserve historical
   hooks and all booking tables. If schema access is blocked, stop schema work;
   do not substitute a non-atomic `state` counter and claim a hard spending cap.
10. Aggregate metrics by provider/purpose: attempted calls, cache hits, fallbacks,
    throttles, failures, and allowance refusals. Never store full addresses or
    tokens in metrics. Use account dashboard quotas as a second control because
    this site cannot count outside consumers.

## Verification

No tests, stress loops, or paid traffic generation. Inspect races and error paths;
use DBHub only for read-only schema evidence. For authorized execution of the
module update, first inspect `drush updatedb:status`; do not apply unrelated pending
updates. Run only the reviewed migration through the site's approved procedure,
then:

```bash
rtk proxy ddev exec vendor/bin/drush cr
```

Manual acceptance must cover duplicate misses, circuit open, all providers down,
expired entries, reversed route, two addresses in one ZIP, budget exhaustion, and
cache loss with budgets unchanged. Cache hits must produce zero upstream calls.
Report which checks were actually run. Stop before connecting public consumers.
