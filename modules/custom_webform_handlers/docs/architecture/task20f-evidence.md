# Task 20F implementation evidence

Recorded 2026-09-09. Task 20F is implemented and registered. It is deliberately
not connected to the public distance-provider alias; Task 20G owns that change.

## Outcome

- `ChainDistanceProvider` implements both existing route contracts and executes
  one non-recursive, purpose-bound provider order with per-adapter and total
  deadline checks.
- The ordinary and offer service maps structurally omit Google. Only the final
  map contains the chain-specific Google adapter.
- `RouteCache` is the single cache owner for chain traffic. Keys preserve A to B
  order, exact server-resolved coordinates, coordinate provenance and rights,
  provider/profile/dataset/endpoint identity, policy version, effective departure,
  and fixed route options. Stored results retain their original fetch and expiry.
- The existing Google service retains its internal cache for compatibility. A
  separate chain-only service injects `internalCacheEnabled=false`, so allowance
  is never reserved in front of a hidden Google adapter cache hit.
- `ProviderCircuit` records only provider-fault classifications, honors throttling
  cooldown, and permits a configured number of coordinated half-open probes.
- `RoutingBudget` applies provider-wide and purpose-specific limits using a fixed
  configured day/month calendar. Directions and geocoding are separate products.
- `DatabaseRoutingBudgetStore` commits reservations on an independent physical
  database connection before external dispatch. It locks provider aggregate and
  purpose rows in deterministic order, uses `FOR UPDATE NOWAIT`, and conditionally
  increments below the saved limit. A failed ledger is a refusal to spend.
- `AddressCoordinateResolver` reserves its separate geocoder allowance after
  configuration/coverage/cache checks and immediately before `geocodeQuery()`.
  Contrib code was not edited.
- Scheduled departure bucketing now has one owner in `RoutingPolicy`, used by the
  chain, Mapbox, and Google.

## Database migration

DBHub read-only discovery established MariaDB 11.8.8, InnoDB, schema version
`10041`, and no existing budget table. Immediately before mutation, Drush listed
only update `10042`. That update created
`custom_webform_handlers_routing_budget` with:

- an InnoDB engine;
- a unique `(provider, product, purpose, period_start)` identity;
- provider/product/period and period-expiry indexes; and
- unsigned request, period, and limit fields.

The update completed successfully. A second update-status check reported no
pending updates. The table contained zero rows after verification: no allowance
reservation or provider request was made by this task.

## Service-container checks

Drupal instantiated all three chain services, `RoutingBudget`, and `RouteCache`.
Runtime reflection of the explicit provider maps returned:

```text
ordinary: osrm, mapbox
offer:    mapbox
final:    mapbox, google_routes
```

The public `custom_webform_handlers.distance_provider` alias still instantiated
`GoogleRoutesDistanceProvider`. Reflection of the two Google service instances
returned internal-cache flags `[true, false]` for legacy/live and chain-only,
respectively.

## Tooling and verification

- The already-enabled contrib baseline was retained: Commerce, Address,
  Advanced Queue, ECA, Geocoder, Redis, and Webform. No redundant Drupal module
  or root Composer dependency was added for Task 20F.
- Pinned `gitnexus@1.6.11` and `@bytebase/dbhub@1.2.3` were installed globally.
  Claude and Codex MCP definitions now use pinned local executables; DBHub starts
  through a credential-safe launcher that validates the ignored read-only DSN
  and injects it only into the child environment.
- GitNexus completed the final post-change incremental index: 5,712 nodes,
  15,070 edges, 262 clusters, and 487 flows. It found the chain implementing both interfaces
  and the budget store imported by `RoutingBudget`. Interface/DI impact remains
  a documented lower bound, so service YAML was inspected directly.
- Graphify completed its final post-change rebuild: 6,027 nodes, 12,921 edges,
  and 480 communities.
- PHP lint completed for every touched PHP/install file. Drupal PHPCS reported
  zero errors for the reviewed set; its only findings were existing line-length
  warnings in `RoutingPolicy.php` and `GoogleRoutesDistanceProvider.php`. All new
  Task 20F classes were clean.
- `drush cr` completed successfully after service wiring. `git diff --check` for
  the module returned no whitespace errors.

GitNexus full-text search remains unavailable because its optional Windows FTS
extension cannot load the OpenSSL runtime DLLs. Exact symbol context and impact
remain operational and were used; no FTS repair was attempted. Its final run also
reported a non-fatal Windows `EBUSY` while replacing the parse cache after the
knowledge graph had been indexed successfully.

## Pending acceptance

The manual duplicate-miss, circuit-open, all-providers-down, expiry, reversed
route, same-ZIP/different-address, allowance-exhaustion, and cache-loss scenarios
remain pending for Task 20H's bounded activation window. No automated test,
browser probe, stress loop, geocoder request, route request, or shadow comparison
was run here. Provider enablement and public alias changes remain outside this
task.
