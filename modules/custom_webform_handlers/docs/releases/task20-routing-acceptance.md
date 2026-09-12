# Task 20 routing acceptance

Bounded acceptance recorded 2026-09-10 (America/New_York). All live addresses
were public landmarks. No browser automation, production provider outage, real
payment, Mapbox request, or Google Routes request was performed.

## Allowances before the live session

| Provider/product | Configured allowance | Durable paid Directions reservations |
| --- | --- | --- |
| OSRM | Not metered per request | Not applicable |
| Mapbox Directions | 0 and disabled | 0 |
| Google Routes | 0 and disabled | 0 |
| Private Nominatim | 100,000/month | Local operational allowance only |

Task 20F had recorded an empty allowance ledger before activation. The provider
flags and zero paid allowances were re-read before this session. They remained at
zero afterward, which proves the bounded local checks did not reserve a paid
request.

## Required matrix

| Scenario | Status | Evidence |
| --- | --- | --- |
| Raw address typing, neither address committed | **Pending** | Requires the isolated QA browser session; source evidence remains Task 20B’s dirty/commit gate. |
| Selection followed by blur, unchanged pair | **Pending** | Requires the isolated QA browser network panel. |
| Repeated valid route cache hit | **Passed** | A repeated ordinary chain call returned OSRM with `attempt_delta=0` and `cache_hit_delta=1`. |
| OSRM healthy, ordinary quote | **Passed** | Ordinary readiness true; positive static distance/duration; provider `osrm`; no paid reservation. |
| OSRM unavailable, Mapbox permitted | **Pending** | Mapbox has no token/allocation and the live local router was not stopped to manufacture a fault. |
| Both preview providers unavailable | **Pending** | Architecture excludes Google from ordinary wiring, but no production provider was stopped for a live fault. |
| Required-traffic offer, Mapbox unavailable | **Passed at provider seam** | Offer provider returned `not_configured`; no static OSRM result or Google reservation was produced. End-to-end browser Slot display remains pending. |
| Eligible final traffic check, Mapbox unavailable | **Pending** | Google is disabled with allowance 0; no paid sandbox window was authorized. |
| Public caller requests final/provider override | **Passed by interface and wiring evidence** | Task 20G exposes no public provider/purpose selector; final routing is available only through the checkout/staff interfaces. No Google reservation occurred. |
| Budget exhausted or durable accounting unavailable | **Pending deliberate-fault check** | The current zero/disabled posture is fail-closed and paid reservations are 0; ledger failure was not injected into the live site. |
| Two distinct addresses in one ZIP / reversed route | **Passed** | Two accepted 10001 landmarks retained different address identities; A→B and B→A produced different authoritative route fingerprints. |
| Dirty input or slow stale response | **Pending** | Requires the isolated QA browser session. |
| Cache outage or concurrent identical requests | **Pending** | No cache service was stopped and no stress/concurrency suite was run. |
| Route map displayed | **Pending** | Requires visual browser acceptance of geometry and attribution. Source evidence confirms the map makes no routing call. |
| Final provider changes price/time | **Pending** | Requires a traffic provider and sandbox checkout; no real payment capture is permitted. |

## Additional local coverage

| Check | Result |
| --- | --- |
| Manhattan in both directions | Positive OSRM distance and duration; ordered fingerprints differ |
| Manhattan to Jersey City | Positive OSRM distance and duration |
| Manhattan to Stamford/extract edge | Positive OSRM distance and duration |
| Washington, DC control | Refused as `outside_coverage`; no fabricated zero route |
| Same ZIP identity | Two accepted 10001 addresses retained different authoritative identities |
| Traffic claim | Every OSRM result reported `traffic_aware=false` |
| Offer/final while traffic providers disabled | Both refused as `not_configured` |

The first full harness used a public 10001 address that this Nominatim dataset
classified as unusable. Replacing that fixture with a resolvable public landmark
made the intended same-ZIP check pass; application code was not changed for the
fixture. One earlier ordinary attempt also recorded a transient provider failure.
Subsequent focused probes and the full acceptance were consistently successful,
and the OSRM circuit finished closed with zero consecutive faults. The local
failure counter is retained rather than erased.

## Operator visibility and schema

- Update `10044` created `custom_webform_handlers_routing_usage`.
- The permissioned Routing Providers form rendered counter, budget, and circuit
  tables successfully.
- Active `custom_webform_handlers.settings` validated with zero typed-config
  violations.
- The local summary ended at four OSRM attempts, eight cache hits, one retained
  failure, and a closed circuit. A later isolated cache proof added one cache hit
  and zero attempts; counters continue accumulating by design.
- Private Nominatim used seven allowance reservations, leaving 99,993 in the
  current monthly window at the recorded snapshot.
- Mapbox and Google Directions reservations remained 0.

These figures are Drupal-local operational observations, not vendor invoice
totals. They contain no address, coordinate, route payload, endpoint, customer
value, or credential.

## Activation verdict

**Performed:** local OSRM-only activation for ordinary quotes.

**Pending:** production activation; any Mapbox or Google activation; public
traffic-aware offers/final checks; browser acceptance; payment/revised-price
acceptance; production cost comparison.

No percentage saving is stated. Comparable before/after billing windows do not
exist, and Places/Maps JavaScript costs must be separated from Routes costs when
they do.

## Final repository and service verification

- PHP syntax checks passed for every Task 20H PHP/install file.
- Drupal and DrupalPractice checks reported zero errors in the changed routing
  set. The settings form retains 89 non-blocking warnings already present in the
  checked file, principally its existing long configuration lines.
- Database update status reported no pending updates after `10044`, and the
  final Drupal cache rebuild completed successfully.
- The OSRM container was running without an OOM event as user `65534:65534`
  with a read-only root filesystem. The bounded health route returned `code=Ok`,
  2,403.3 m, 304.9 seconds, and endpoint snaps of 33.68 m and 49.33 m.
- Private Nominatim `/status` returned status 0 (`OK`) on version 5.3.2.
- Repository whitespace validation passed, Task 20H scratch probes were
  removed, and the secret environment file remained ignored.
- The refreshed code graph contains 6,218 nodes, 13,234 edges, and 485
  communities.
