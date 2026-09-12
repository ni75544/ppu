# Task 20E — Adapter implementation evidence

Recorded 2026-09-08 (America/New_York). Implementation and container registration
are complete. **Activation and live provider acceptance are not complete.**

## Scope and files

| File | Change |
| --- | --- |
| `src/Distance/CoordinateRouteProviderBase.php` | New shared coordinate, HTTP, response, snap, geometry, and result validation |
| `src/Distance/OsrmDistanceProvider.php` | New ordinary/static OSRM adapter |
| `src/Distance/MapboxDistanceProvider.php` | New ordinary and scheduled Mapbox adapter |
| `src/Distance/RoutingPolicy.php` | Two adapter settings/endpoint helpers; no default or activation change |
| `src/Distance/GoogleRoutesDistanceProvider.php` | Preserved entry points; classified failures, original cache timestamps, upward scheduled-duration rounding, redacted exception handling |
| `custom_webform_handlers.services.yml` | Registered both adapters; injected Google's clock; public alias unchanged |
| `ops/osrm/compose.yml` | Optional private deployment template, not launched |
| `docs/operations/osrm-routing.md` | Dataset, resource, security, preparation, readiness, refresh, and rollback runbook |
| This document | Evidence, limitations, and pending acceptance |

No Task 20B JavaScript, controller, price formula, reservation logic, install hook,
active provider selection, root Composer file, or `.ddev/` source was changed by
this task. Existing unrelated dirty files remain intact. Nothing was committed.

## Implementation decisions

- Existing interfaces remain the caller-facing seam. OSRM implements distance
  only; Mapbox implements distance and scheduled routing. The codebase-design
  skill led to a shared internal base instead of duplicating validation in both
  adapters. It adds no service locator, plugin manager, geocoder client, cache
  ledger, or public endpoint.
- `RoutingPolicy::adapterSettings()` reuses the existing validation and provider
  setting resolution without choosing a purpose. A final Mapbox check must not
  depend on whether Mapbox is enabled in the public-offer order. Purpose
  eligibility and atomic allowance consumption remain Task 20F responsibilities.
- Disabled providers refuse invocation. Mapbox also refuses a zero allocation.
  A positive configured number alone is **not** a durable spending cap; do not
  wire consumers to the raw adapters before the chain exists.
- Each new adapter makes at most one routing HTTP request. Redirects and retries
  are disabled at this layer. The shared resolver owns geocoding. Exceptions
  retain no upstream body, URL, token, address, or previous exception.
- Both endpoints must have address-level precision, unexpired provenance, and
  permission for cross-provider use. OSRM additionally requires its configured
  dataset identity to match both resolved locations.
- Snap distance is capped conservatively at 100 m, both in the request radius and
  by independent returned-coordinate measurement. A failed snap is not terminal
  proof of an invalid customer address. Zero/unroutable results never become a
  zero-price success. Durations round upward for scheduling.
- Polyline5 is requested explicitly and checked for valid encoding, complete
  coordinate pairs, and global bounds before browser reuse. Scheduled Mapbox
  geometry is used for traffic annotations but not included in the result.
- Mapbox scheduled requests carry a UTC `depart_at` using the existing departure
  bucket/lead configuration. Ordinary requests carry no departure and always
  return `trafficAware=false`.
- Mapbox's conservative local acceptance rule requires known numeric congestion
  for every non-zero annotated segment and a complete distance sum within
  rounding tolerance. Missing, unknown, or inconsistent annotations yield
  `trafficAware=false`; zero congestion is a valid known observation. This is an
  application acceptance rule, not a guarantee of road-by-road live sensing.
  The existing required-traffic scheduling rule remains authoritative.
  [Mapbox Directions contract](https://docs.mapbox.com/api/navigation/directions/).
- No new route cache is introduced. In particular, Mapbox persistent caching
  remains disabled. Google keeps its existing cache namespace, key separation,
  timeout policy, server key source, and minimal field masks. New entries retain
  original fetch time and effective departure. Legacy entries lacking provenance
  refresh on their next use; this can cause one additional billed miss per such
  pair, rather than pretending a timestamp is known. There was no cache purge.
- Google response decoding now distinguishes malformed data, no route, missing
  configuration, throttling, timeout, and unclassified failure. Unknown fallback
  modes do not claim traffic support. Cache-write failure does not discard a
  successful billed route. Its existing ordinary path remains live until the
  later cutover; zero in the new chain allocation does not yet govern that path.
  [Google route/fallback contract](https://developers.google.com/maps/documentation/routes/reference/rest/v2/FallbackInfo).

## Verification actually performed

| Check | Observed result |
| --- | --- |
| PHP syntax, all five changed/new PHP files | Pass |
| Drupal PHPCS, three new PHP classes | 0 errors, 0 warnings; exit 0 |
| Drupal PHPCS, Google class | 0 errors; 23 existing long-comment warnings remain |
| Drupal PHPCS, RoutingPolicy | Existing 3 documentation errors and 125 long-comment warnings, outside the added methods; no broad cleanup performed |
| `ddev exec vendor/bin/drush cr` after coherent source edits | Success |
| Read-only Drush service construction | All three concrete adapters instantiate |
| Interface checks | OSRM distance=true/scheduled=false; Mapbox and Google both=true |
| Live alias object identity | Still the existing Google provider |
| Effective defaults/active settings | OSRM=false; Mapbox=false; Mapbox allocation=0; Google chain allocation=0 |
| Running versions | Drupal 10.6.16; PHP 8.4.22 |
| Host/container SHA-256 comparison | All five PHP files and services YAML match |
| Compose syntax validation | Exit 0, no deployment; validation-only image tag/output-volume name |
| Git whitespace check | Clean |
| Nominatim readiness after DDEV restart | HTTP 200 from web container |
| Paid routing/geocoding requests | None made by this task |
| Automated suites/browser probes | None generated or run |

The first inline Drush inspection failed at shell quoting before PHP ran; the
explicit `ddev exec --raw` retry succeeded. The successful results above come
from that retry, not from assuming the initial command worked.

## Capability ledger and evidence limits

- **Graphify:** queried before source edits; EXTRACTED edges show Google's two
  interfaces and dependencies. Refreshed after source changes: 5,952 nodes,
  12,733 edges, 470 communities. It records Mapbox's inheritance and scheduled
  interface. YAML and Markdown remain outside graph coverage, so wiring and
  documentation claims come from direct reads and Drush, not missing graph edges.
- **GitNexus MCP:** absent from the native tool list, but the local server was
  reached over its actual stdio MCP protocol. Initialization, tool discovery,
  repository context, and exact-symbol impact calls succeeded. A fresh
  `analyze . --force --index-only` completed before PHP changes with 5,543 nodes,
  14,473 edges, 254 clusters, 473 flows. RoutingPolicy impact found its settings
  form; Google and the resolver had no resolved callers and returned UNKNOWN
  risk, not a claim of safety. YAML reads supply the DI relationships.
  Full-text search remains unavailable due to the Windows FTS dependency error;
  process extraction also reports omitted flows. The pre-edit GitNexus index is
  not presented as an index of the final new adapter code.
- **Context7:** OSRM and Mapbox primary API excerpts retrieved. The Drupal query
  requested a 10.x library but returned main/11.x examples; these were not copied
  as proof of Drupal 10.6 behavior. Installed core service declarations and
  Settings/Time source were checked directly, then container construction verified
  against the running version.
- **Primary documentation:** official OSRM, Mapbox, and Google pages checked;
  references are linked above and in the runbook. No account allowance or pricing
  claim was inferred from a published free tier.
- **DBHub:** unavailable; no SQL/schema changes or new database-table assumptions
  are part of Task 20E. The durable ledger remains outside this task.
- **Skills:** codebase-design used for the adapter seam and shared validation;
  diagnosing-bugs used for bounded startup/synchronization observations, with the
  repository's no-tests rule taking precedence. No sub-agent was used.

## Local environment observations

The first cache-rebuild invocation caused DDEV to recreate/start its existing
containers before running Drush. No database volume was deleted. Nominatim
remained on the same data/hardening configuration and returned HTTP 200 afterward.

Mutagen reports two existing root-level transition problems: a Windows-invalid
filename and `nul`. These were not deleted or renamed. Both endpoints remain
connected and watching; matching hashes establish that this task's changed PHP
and service wiring did reach the container. Do not describe the overall sync
state as clean merely because these files match.

## Pending acceptance and stop boundary

| Scenario | Expected result | Status |
| --- | --- | --- |
| Ordinary Manhattan route, both directions | Positive distance/time, static flag, plausible geometry | Pending private OSRM deployment |
| Jersey City and extract edge | Valid covered route or explicit non-terminal route/coverage failure | Pending |
| OSRM unreachable or timed out | Classified failure; one routing attempt; no direct Google fallback | Pending authorized bounded check |
| Mapbox invalid credentials / 429 | NOT_CONFIGURED / THROTTLED, redacted output and retry delay | Pending credentials, allocation, chain gate, authorization |
| Missing/negative/NaN measures or bad geometry | MALFORMED_RESPONSE, no successful zero quote | Pending bounded fault checks |
| Snap exceeding 100 m | NO_ROUTE; no payable estimate | Pending |
| Unknown/absent traffic annotations | Static/degraded result, refused when traffic is required | Pending |
| Google cache restore and scheduled fractional duration | Original timestamps retained; scheduled seconds rounded upward | Source-inspected only; live behavior pending |

The local GHCR image-metadata request timed out, so no OSRM image digest is
invented or claimed verified. The template requires an operator-supplied immutable
image reference and prepared external volume. No OSRM image was pulled, OSRM volume
created, router started, or long import performed.

Next is Task 20F's chain/cache/circuit/durable-budget work, with its own required
schema evidence and migration gates. Task 20G owns consumer wiring. Task 20H and
the OSRM provisioning runbook own live readiness and activation. These adapters
must remain inactive until those controls are in place.
