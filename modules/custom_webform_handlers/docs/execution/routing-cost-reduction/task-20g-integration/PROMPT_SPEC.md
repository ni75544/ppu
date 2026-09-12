# Task 20G — Integrate purpose-specific routing with quotes and dispatch

You are Claude Code CLI executing Task 20G in `C:\Users\mo737\ppu` after 20B/20F.
Read root `CLAUDE.md`, RTK, applicable rules, and the routing pack README. Task 20A
must establish the consumer impact graph and permitted final-check entry points.

## Objective

Wire the chains into the existing workflow while preserving server price authority,
traffic requirements, payment idempotency, and synchronous capacity confirmation.

## Target files

```text
modules/custom_webform_handlers/custom_webform_handlers.services.yml
modules/custom_webform_handlers/src/Controller/DistancePreviewController.php
modules/custom_webform_handlers/src/Plugin/WebformHandler/AddressDistanceHandler.php
modules/custom_webform_handlers/src/Checkout/DispatchCheckoutStarter.php
modules/custom_webform_handlers/src/Checkout/QuoteSnapshot.php
modules/custom_webform_handlers/src/Dispatch/Route/DistanceProviderRouteEstimator.php
modules/custom_webform_handlers/src/Dispatch/Route/DispatchRouteEstimatorInterface.php
modules/custom_webform_handlers/src/Dispatch/Route/ScheduledRouteWarmer.php
modules/custom_webform_handlers/src/Dispatch/DispatchPlanner.php
modules/custom_webform_handlers/src/Dispatch/DispatchPlannerInterface.php
modules/custom_webform_handlers/src/Commerce/DispatchCheckoutManager.php
modules/custom_webform_handlers/src/Distance/DistanceResult.php
modules/custom_webform_handlers/js/route-map-preview.js
modules/custom_webform_handlers/js/quote-summary-sidebar.js
modules/custom_webform_handlers/config/install/custom_webform_handlers.settings.yml
```

These are bounded potential integration targets, not permission to rewrite every
class. Select exact edits from the verified call graph and document why each is
needed. Keep one planner and one quote calculator.

## Required implementation

1. Wire ordinary preview, saved-submission distance recalculation, and checkout
   distance pricing to the `distance_provider` chain. Wire public offer calculations
   and route warming to the `offer_distance_provider` chain. Resolve every direct
   Google injection identified in 20A; a leftover direct caller can bypass savings.
2. Construct separate offer and final estimator instances using the same existing
   estimator implementation and the named chain services. Make the selected
   estimator an explicit server-owned input to internal planning preparation.
   Reuse a single feasibility algorithm; do not fork the planner into two engines.
3. Permit the final estimator only after the existing checkout manager has
   validated the saved request/attempt and workflow gate, or a staff operation has
   passed its permission gate. Carry that trusted operation context through hold
   preparation and confirmation. Existing public controller hold/renew/release
   calls retain the public policy and cannot select final routing. If the current
   signatures cannot enforce this, introduce the smallest typed context at the
   existing planner seam; document all callers and preserve default public policy.
4. Required traffic remains required. An offer may use an approved Mapbox traffic
   estimate; a final check may try Google only when that suitable result is absent
   and the final operation has allowance. Do not query all providers or call Google
   on every payment when Mapbox already supplied a suitable fresh estimate.
5. Prepare external route facts outside Resource Calendar Guard transactions.
   Under the lock, reread neighbors and revalidate against the prepared route facts,
   endpoint identity, effective departure, freshness, and current policy. A missing
   or changed required leg returns to bounded preparation outside the lock or
   refuses. Never fetch Google inside a locked revalidation cache miss. Keep
   calendar feasibility and reservation writes synchronous and atomic.
6. Continue using the existing quote calculator and money-in-cents policy.
   Server-recompute/validate the payable quote before authorization. If provider
   distance changes the amount, show the revised quote and require acceptance;
   rebuild an unaccepted checkout attempt through the existing lifecycle. Preserve
   accepted snapshots and paid orders. Payment success does not silently reprice.
7. Stamp a snapshot with route source/fingerprint, calculated time, and pricing
   version where permitted; preserve original metadata through replay. Operational
   facts retained on a booking are not an indefinite general-purpose provider
   response cache. Apply the retention policy from 20A.
8. Revalidate the complete occupied window, including handling, repositioning,
   buffers, breaks, and the following booking. A shorter two-point route is not
   proof that the driver can accept another job. Manual Review consumes no capacity.
9. Handle optional geometry separately from numerical routing. Normalize encoding
   and apply provider attribution/display rights. The map must render geometry
   from the same result as the quote or show a clear unavailable state. It must not
   make a second hidden Google directions request to draw an OSRM/Mapbox route.
   Disable unsupported geometry display rather than mixing sources silently.
10. Preserve public JSON privacy, CSRF/flood controls, no public timer/timezone
    controls, and New York/UTC semantics. Background jobs use their restricted
    policy and cannot turn cache warming into paid Google traffic.

## Verification

No automated tests or production payment actions. Review all changed call paths
with Graphify/GitNexus; inspect YAML directly and rebuild caches:

```bash
rtk proxy ddev exec vendor/bin/drush cr
```

Manual acceptance covers preview/final quote parity, revised-price acceptance,
traffic-required refusal, changed neighbors, stale attempts, repeated payment
callbacks, map consistency, and zero Google access from public/warm entry points.
Use sandbox payment data only in an explicitly authorized acceptance session.
Record remaining checks; stop before production activation.
