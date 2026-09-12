# Task 20C — Define provider contracts and purpose-specific configuration

You are Claude Code CLI executing Task 20C in `C:\Users\mo737\ppu` after 20A.
Read root `CLAUDE.md`, RTK, applicable rules, and the routing-cost-reduction README.

## Objective

Prepare compatible provider contracts, result metadata, and validated configuration
for OSRM, Mapbox, and Google without activating the chain or changing tariffs.

## Target files

```text
modules/custom_webform_handlers/src/Distance/DistanceProviderInterface.php
modules/custom_webform_handlers/src/Distance/ScheduledRouteProviderInterface.php
modules/custom_webform_handlers/src/Distance/DistanceResult.php
modules/custom_webform_handlers/src/Distance/DistanceProviderException.php
modules/custom_webform_handlers/src/Distance/RoutingPolicy.php
modules/custom_webform_handlers/src/Form/RoutingProviderSettingsForm.php
modules/custom_webform_handlers/config/install/custom_webform_handlers.settings.yml
modules/custom_webform_handlers/config/schema/custom_webform_handlers.schema.yml
modules/custom_webform_handlers/custom_webform_handlers.services.yml
modules/custom_webform_handlers/custom_webform_handlers.routing.yml
modules/custom_webform_handlers/custom_webform_handlers.links.menu.yml
modules/custom_webform_handlers/custom_webform_handlers.permissions.yml
```

## Required implementation

1. Preserve `DistanceProviderInterface::calculate(string, string): DistanceResult`
   and the separate `calculateScheduled(string, string, int)` capability. Do not
   force OSRM to pretend to provide traffic or break existing callers/test files.
2. Preserve `DistanceResult` accessors and existing constructor compatibility.
   Add optional internal metadata only where needed: original fetch/expiry time,
   provider/profile/data version, endpoint fingerprint, accuracy, and effective
   departure. Preserve existing public JSON keys; expose only deliberately
   selected safe rendering fields. Cache hits retain original calculated time.
3. Define stable failure classification without raw HTTP bodies or secret URLs:
   incomplete/ambiguous address, outside coverage, no route, timeout, throttled,
   auth/configuration failure, malformed response, and budget exhausted. Preserve
   existing exception handling until adapters/chain are switched together.
4. Add a typed `routing` subtree to `custom_webform_handlers.settings`. Specify
   provider enablement/order by purpose, profiles, timeouts/total deadline,
   geocoder selection, provider-specific cache permissions/TTLs, circuit thresholds,
   and request budgets. Keep existing keys readable during the staged rollout.
5. Use allowlisted IDs `osrm`, `mapbox`, `google_routes`. Reject duplicates,
   unknown IDs, negative TTL/budgets, and sequences that put Google into a preview,
   ordinary-price, public-offer, or warming policy. An empty eligible chain fails
   clearly. Defaults do not silently enable optional paid providers.
6. Keep credentials in environment-backed Settings, never exported config or
   drupalSettings. Browser keys and server tokens remain separate. OSRM's base
   endpoint comes from trusted operator configuration, with a permitted host and
   scheme; no public request can set a URL. Avoid exposing internal hostnames.
7. Extend the existing administrator settings organization using a restricted
   permission and Form API. Explain provider reachability, allowance, and caching
   rights without printing keys. Config validation happens on save and at runtime.
8. Plan explicit container entries for provider adapters and three chain instances:
   `distance_provider` for ordinary quotes, `offer_distance_provider` for offers
   and warming, and `final_distance_provider` for validated final operations.
   Keep the active alias unchanged until 20G; do not wire classes that do not yet
   exist. Use injected provider maps, not dynamic service lookups from config.

## Verification

Inspect schema/default correspondence and container construction using bounded
Drush metadata reads. No tests, dependency updates, config exports, or provider
HTTP calls. Rebuild caches after edits:

```bash
rtk proxy ddev exec vendor/bin/drush cr
```

Completion: existing Google-backed behavior and JSON remain compatible; new
configuration can be validated without activating unavailable providers. Record
contrib/core reuse and pending runtime prerequisites. Stop before adapters.
