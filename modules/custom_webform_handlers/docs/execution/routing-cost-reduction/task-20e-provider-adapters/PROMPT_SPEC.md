# Task 20E — Add OSRM and Mapbox adapters and the OSRM operating runbook

You are Claude Code CLI executing Task 20E in `C:\Users\mo737\ppu` after 20D.
Read root `CLAUDE.md`, RTK, applicable rules, the routing pack README and source note.

## Objective

Implement the two additional distance adapters behind the existing interface,
preserve the Google implementation, and document an operable private OSRM endpoint.

## Target files

```text
modules/custom_webform_handlers/src/Distance/OsrmDistanceProvider.php
modules/custom_webform_handlers/src/Distance/MapboxDistanceProvider.php
modules/custom_webform_handlers/src/Distance/GoogleRoutesDistanceProvider.php
modules/custom_webform_handlers/custom_webform_handlers.services.yml
modules/custom_webform_handlers/docs/operations/osrm-routing.md
modules/custom_webform_handlers/ops/osrm/compose.yml
```

The module-local compose file is an optional deployment template if no OSRM service
exists. No edits to `.ddev/`, root Composer files, or host-wide Docker configuration
are authorized by this code task. Runtime provisioning follows the runbook and
the infrastructure scope recorded in 20A.

## Required implementation

1. Implement `OsrmDistanceProvider::calculate()` using the shared coordinate
   resolver, injected HTTP client, settings/policy, clock, and logger. Request
   `/route/v1/driving/{lon1},{lat1};{lon2},{lat2}` from the operator endpoint.
   Request one route, no steps/alternatives, and only required geometry. Honor both
   HTTP status and OSRM `code`, including `NoRoute`/`NoSegment`.
2. Implement Mapbox ordinary routing with `mapbox/driving` and the same resolved
   endpoints. Implement `ScheduledRouteProviderInterface` using
   `mapbox/driving-traffic` and the effective `depart_at`. Encode parameters with
   the HTTP client; redact token-bearing URLs from exceptions/logs.
3. Normalize meters, seconds, source, timestamps, and optional geometry. Scheduling
   durations round upward as required by the existing estimator. Reject NaN,
   negative/missing values, incompatible schemas, and excessive coordinate snaps.
   Zero results must follow the existing explicit same-location policy, not evade
   it. Keep `trafficAware=false` for OSRM and ordinary Mapbox driving.
4. For traffic results implement the coverage/degradation policy from 20A. If the
   provider cannot prove suitable traffic support, mark the result accordingly so
   the existing required-traffic rule can refuse it. Merely requesting a traffic
   profile is not sufficient evidence of returned traffic coverage.
5. Retain Google's current class/service ID and its two public methods. Limit
   changes to shared result/error/caching integration. Keep server key handling,
   minimal field masks, time-specific departure identity, and timeout behavior.
   Do not introduce a second Google HTTP client.
6. Each adapter makes at most one upstream routing attempt per invocation;
   chain-level policy owns retry/fallback. Implementations must remain inactive
   until 20F wraps access with cache/budget controls and 20G wires consumers.
7. Document private OSRM deployment: pinned image version/digest, data source and
   extract extent, car profile, MLD preparation, serving command, memory/disk
   needs, health route, data-version tagging, update/swap procedure, and rollback.
   Include NYC routes that may traverse neighboring states: endpoint inclusion in
   a New York extract does not prove the whole route is covered.
8. Use named/external volumes for large data; no PBF/OSRM outputs in Git. If a
   module-local compose template is needed, parameterize its connection to the
   existing Docker network and expose it only to the application or loopback.
   Inspect actual network names instead of inventing them. Imports never run in a
   Drupal request. Do not run long imports or change production infrastructure in
   this bounded adapter task.

## Verification

Inspect interface compatibility, DI, URL construction, units, error paths, and
secret redaction. Rebuild caches without selecting new providers:

```bash
rtk proxy ddev exec vendor/bin/drush cr
```

No automated tests. Provide bounded manual checks for normal route, coverage edge,
unreachable OSRM, Mapbox denial/throttle, malformed result, and unsupported traffic.
Mark endpoint checks pending if infrastructure/credentials are absent. Stop before
live alias cutover; adapter completion is not OSRM deployment completion.
