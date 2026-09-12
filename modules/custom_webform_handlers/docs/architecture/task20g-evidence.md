# Task 20G integration evidence

Date: 2026-09-10  
Scope: purpose-specific route-chain integration with quote, checkout, dispatch,
and map consumers. Production provider activation remains Task 20H.

## Result

The module now has one ordinary distance chain and two separate scheduled-route
estimator instances. Provider purpose is fixed by the service container and is
never accepted from a request, form value, URL, or queue payload.

| Consumer | Service and purpose | Eligible providers by policy |
| --- | --- | --- |
| Public distance preview | `distance_provider_chain.ordinary` | OSRM, then Mapbox |
| Webform saved/recomputed quote | `distance_provider_chain.ordinary` | OSRM, then Mapbox |
| Checkout price recomputation | `distance_provider_chain.ordinary` | OSRM, then Mapbox |
| Public dispatch offers and public Hold/confirmation | offer estimator | Mapbox traffic only |
| Scheduled route warming | offer estimator | Mapbox traffic only |
| Validated checkout Hold/confirmation | final estimator | Mapbox traffic, then Google guard |
| Staff replan/confirmation | final estimator | Mapbox traffic, then Google guard |

The stable `custom_webform_handlers.distance_provider` alias now names the
ordinary chain. Direct-source search found the two Google adapter registrations
and the final-chain map entry only; no controller, Webform handler, checkout
starter, public planner path, or warmer injects a Google adapter directly.

No new Drupal module, Composer dependency, Codex plugin, or skill was needed for
this integration. The existing Commerce, Webform, Address, Geocoder, Advanced
Queue, Redis, and ECA baseline remains the appropriate contrib-first foundation.

## Final-routing authority

`DispatchPlanner` remains the only feasibility engine. It implements three
narrow contracts:

- `DispatchPlannerInterface` retains the visitor policy and cannot select final
  routing.
- `DispatchCheckoutPlannerInterface` exposes only final checkout Hold and
  confirmation operations. Only `DispatchCheckoutManager` is wired to it.
- `DispatchStaffPlannerInterface` remains behind the existing staff operation
  and permission boundary.

`DispatchCheckoutManager::start()` validates the saved submission identity,
workflow gate, server-derived Resource/Slot, normalized saved request, and
idempotent attempt state before it asks for a final-route Hold. Paid confirmation
loads and validates the saved Checkout Attempt before it reaches final-route
confirmation. Public Hold, renew, release, and offer callers have no method that
accepts an estimator or purpose.

Container verification resolved two distinct
`DistanceProviderRouteEstimator` objects and read their server-injected purposes
as `offer` and `final`. The checkout planner interface resolved to the single
`DispatchPlanner` implementation.

## External route facts and calendar locks

`PreparedRouteFacts` is a request-local, address-free memo keyed by a one-way
hash of the ordered normalized endpoint pair and requested departure. A
`SlotPlan` carries the exact facts used during unlocked preparation.

The claim, confirmation, and replan flows now do this in order:

1. Read the current candidate and neighbors without holding a Resource Calendar
   Guard.
2. Fetch every required service, depot, return, predecessor, and successor route
   fact through the selected trusted estimator.
3. Acquire the Resource Calendar Guard through the reservation store.
4. Re-read authoritative occupancy and neighbors under the lock.
5. Re-run the same `planFor()` algorithm with fetching disabled.
6. Accept only prepared facts whose purpose, endpoint identity, provider policy,
   requested/effective departure, traffic basis, freshness, and route hash still
   match. A new or changed leg refuses synchronously instead of performing I/O.
7. Compare the complete Dispatch Window and both repositioning margins before
   writing the Hold, Reservation, or replan.

The locked callback therefore has no usable provider-fetch path: every call
passes `mayFetch = FALSE`, and `routeLeg()` can only read and validate the
provided `PreparedRouteFacts`. Missing facts return `IncompleteFacts`. Calendar
feasibility and the corresponding reservation write remain synchronous and
atomic inside the store transaction.

`ReservationStoreInterface` documents the no-I/O callback contract, and
`heldForPlanning()` provides only the advisory live Hold facts needed to prepare
confirmation before the guarded re-read. Token and owner comparisons still occur
inside the storage transaction.

## Quote authority and replay

`QuoteCalculator` remains the only price engine and continues to use integer
minor units. When a dispatch Slot is selected, `AddressDistanceHandler` now
recomputes the route and payable quote on the server before submission save,
Hold creation, Commerce order creation, or payment authorization. Browser quote
JSON is acceptance evidence only. A mismatch in amount, currency, or rate
version writes the fresh server result back, stops submission, and asks the
customer to review and submit again. It never makes the browser authoritative.

Manual Review or an unavailable/unpriced route does not invent a payable amount;
the established Manual Review path remains responsible. Paid orders are not
repriced. Existing Checkout Attempt and payment idempotency behavior is
unchanged.

`ChainDistanceProvider` also keeps a bounded successful-result memo on the
current Symfony HTTP Request. Its key contains only a one-way address-pair
identity, purpose, and effective departure. The result is not written to Drupal
cache, is not serialized, is unavailable to another request/process/queue item,
and therefore does not override provider persistence policy. It ensures the
validation, pre-save, and checkout-start recomputations in one submission request
reuse the exact accepted route result and cannot buy the same fallback three
times. CLI/background operations have no current HTTP Request and receive no
such memo.

An automatic `QuoteSnapshot` now retains bounded route provenance:

- normalized route provider;
- SHA-256 route fingerprint;
- original route calculation time;
- existing pricing/rate version.

Update `10043` added nullable `quote_route_source`,
`quote_route_fingerprint`, and `quote_route_calculated_at` columns. Existing
attempts remain readable with empty provenance, while stored replay preserves
the original snapshot id and the provenance fields that were written. The
snapshot stores no address, provider payload, endpoint URL, or geometry.

## Map consistency and attribution

`route-map-preview.js` makes no network request and does not instantiate a
Google Directions service. It consumes the geometry already present on the
same `DistanceResult` as the numerical quote. Google geometry may draw on the
existing Google map. OSRM and Mapbox geometry are not silently mixed onto that
surface; the widget keeps the numerical route, shows geometry as unavailable,
and renders the applicable OpenStreetMap or Mapbox attribution link. The library
version is `1.1.12`.

`quote-summary-sidebar.js` required no change: it already consumes the quote
result emitted by the calculator and performs no provider call.

## Verification performed

- Drupal container compilation and cache rebuild: successful.
- Database updates: none pending after `10043` was applied.
- Live schema read: all three nullable route-provenance columns exist with the
  expected types.
- PHP syntax: all touched PHP/install files passed.
- JavaScript syntax: `route-map-preview.js` passed the bundled Node check.
- Drupal/DrupalPractice PHPCS: zero errors across the reviewed Task 20G set; all
  newly introduced classes are clean of errors and warnings.
- Module whitespace check: clean.
- Direct call-site search: no direct Google consumer remains outside the private
  adapter registrations and final-chain map.
- Graphify rebuild: 6,185 nodes, 13,157 edges, 489 communities. Its refreshed
  graph identifies one `DispatchPlanner`, the new checkout interface, the shared
  `planFor()` algorithm, `PreparedRouteFacts`, and the locked revalidation paths.
- GitNexus incremental index: 5,777 nodes, 15,269 edges, 262 clusters, 493 flows.
  `DistanceProviderInterface` has a medium, lower-bound impact of 11 symbols and
  identifies the controller, handler, checkout starter, estimator, chain, and
  adapters. The checkout-only planner interface has an exact low impact of two:
  `DispatchPlanner` and `DispatchCheckoutManager`.
- GitNexus `detect-changes --scope all` reports the accumulated uncommitted
  Task 20 work as 34 indexed files, 234 symbols, and 132 affected flows with a
  critical aggregate risk. That is not treated as a clean regression verdict:
  it spans the earlier provider contracts, adapters, policy, browser throttling,
  and this integration, and its high-risk payment/dispatch paths remain subject
  to the pending bounded acceptance below.

GitNexus full-text search remains unavailable because the optional LadybugDB FTS
extension cannot load its Windows OpenSSL runtime dependency. Symbol context,
impact analysis, and indexing completed successfully without FTS. DBHub was not
exposed as a callable tool in this session; the schema verification used a
read-only Drush SQL metadata query and did not read customer rows.

GitNexus reports direct impact for `ChainDistanceProvider` as unknown because
its consumers are reached through interface-typed dependency injection. That is
not interpreted as unused: service-YAML inspection confirms three chain
instances, and direct search confirms the controller, Webform handler, checkout
starter, planner estimators, and warmer consumers described above.

## Activation boundary and pending acceptance

Active configuration intentionally has no provider activation values beyond the
previously selected geocoder; `RoutingPolicy` therefore uses its fail-closed
defaults. OSRM and Mapbox are disabled and all metered allowances are zero. The
new consumer wiring is complete, but ordinary/offer/final chains must not be
treated as production-ready until Task 20H performs bounded readiness checks and
explicitly activates approved providers. No paid route request or production
payment action was performed here.

The following manual acceptance remains pending for Task 20H's explicitly
authorized sandbox window:

- preview and final quote parity;
- revised-price display and second-submit acceptance;
- traffic-required refusal;
- a neighbor changing between preparation and lock acquisition;
- missing/new prepared route leg refusal without locked provider I/O;
- stale and replayed Checkout Attempts;
- repeated payment-success callbacks;
- map geometry/attribution consistency;
- zero Google requests from public preview, public offers, and warming;
- final-chain Mapbox hit without an unnecessary Google fallback;
- final-chain bounded Google fallback only after allowance and authority gates.

Production activation, allowance changes, provider cache-rights decisions,
traffic replay, and rollback rehearsal remain Task 20H.
