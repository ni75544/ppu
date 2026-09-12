# Fast courier architecture: primary-source notes

Research date: 2026-09-05. Scope: documented Drupal extension points, payment semantics, queue backend constraints, and Google Routes behavior. This note does not assess the reference website or assert which code is currently deployed in this project.

## Verified documentation facts

### Payment and order lifecycle

Commerce distinguishes payment authorization from completion/capture. Only completed payments contribute to the order's total paid amount. An authorized payment is therefore not proof of a fully paid order. [Commerce payment entities and states](https://docs.drupalcommerce.org/v2/developer-guide/payments/payments/)

`CheckoutEvents::COMPLETION` describes completion of checkout; `OrderEvents::ORDER_PAID` (`commerce_order.order.paid`) describes an order becoming fully paid. Order placement has its own workflow transitions. These are separate events with separate meanings. [Commerce event reference](https://docs.drupalcommerce.org/v2/developer-guide/events/)

### Form and checkout extension points

Webform supports handler plugins under `src/Plugin/WebformHandler`, based on `WebformHandlerBase`, for element alterations, server validation, and submission actions. [Webform validation cookbook](https://www.drupal.org/docs/8/modules/webform/webform-cookbook/how-to-add-custom-validation-to-a-webform-element)

Commerce provides checkout flow plugins using `CheckoutFlowWithPanesBase` and checkout pane plugins using `CheckoutPaneBase`. Panes collect additional information or present summaries and can be assigned to configured checkout steps. [Commerce checkout recipes](https://docs.drupalcommerce.org/v2/developer-guide/checkout/checkout/)

### Advanced Queue

Advanced Queue separates queue backend plugins from JobType plugins. It provides retries, delayed processing, job states/results, and worker commands. Its project page mentions Redis as an example of a possible backend; that example alone does not establish a bundled Redis adapter. [Advanced Queue project](https://www.drupal.org/project/advancedqueue)

In the project's Redis support discussion, maintainers explain that an alternate backend requires code implementing `BackendInterface`, commonly through `BackendBase`; the existing Views integration relies on a SQL backend. This discussion dates to 2022-2023, so inspect the installed release's backend definitions before deciding whether newer adapters satisfy the project's requirements. [Advanced Queue Redis support discussion](https://www.drupal.org/project/advancedqueue/issues/3300258)

### Traffic-aware route estimates

Google Routes supports `TRAFFIC_AWARE` with performance optimizations and `TRAFFIC_AWARE_OPTIMAL` with more exhaustive route search and greater latency. Traffic-aware `duration` includes traffic effects; `staticDuration` is distinct. Near-term departure estimates give more weight to live traffic; future estimates give more weight to historical patterns. [Google Routes traffic preferences](https://developers.google.com/maps/documentation/routes/config_trade_offs)

For Compute Routes, omitted `departureTime` defaults to request time. A driving route cannot use a departure time in the past. `arrivalTime` is not a driving delivery-deadline solver; it applies to transit. [Compute Routes REST reference](https://developers.google.com/maps/documentation/routes/reference/rest/v2/TopLevel/computeRoutes)

Compute Route Matrix calculates multiple origin/destination combinations. Limits vary by mode/preference: the REST guide currently lists 625 non-transit elements, or 100 with `TRAFFIC_AWARE_OPTIMAL`, and at most 50 address/place-ID waypoints. Errors can affect individual elements even when other combinations succeed. The documented gRPC stream is not available through REST. [Compute Route Matrix guide](https://developers.google.com/maps/documentation/routes/compute_route_matrix)

## Recommendations inferred for this project

1. Keep one reservation authority for scheduled and ASAP requests. Treat ASAP as selection of the earliest feasible pickup window using the same driver's existing commitments; do not create a separate capacity calendar. Current location, availability, item readiness, travel to pickup, handling, delivery, and travel to the following job all influence the result.
2. Use a thin Webform handler to normalize intake and call existing pricing/planning services. Reuse Commerce contact/payment panes and add a delivery summary pane only where needed. Do not put reservation confirmation into both a Webform submit action and an independent Commerce callback.
3. The trusted Commerce payment lifecycle should invoke one idempotent reservation-confirmation service. A success page, an order being placed, and a payment authorization cannot independently establish a paid reservation. Gateway capability determines whether authorize/capture can support the desired booking policy.
4. Use a durable private checkout claim plus short database transactions to protect capacity during payment. Do not keep a SQL transaction open across a browser redirect or payment network request. Payment gateway state cannot participate in a local database transaction; handle late callbacks, duplicates, conflicts, and capture/confirmation failures with explicit recovery. The public interface needs no countdown.
5. Route lookup, a firm quote, and feasible ASAP pickup must finish before presenting an actionable offer. Start with a bounded synchronous route call for the relevant legs, with timeouts and a clear unavailable/retry response. Matrix precomputation and notifications may run in background jobs, but a queued result is not yet confirmed availability. Prefer existing SQL queue persistence unless the installed backend inventory proves a supported alternative.
6. Evaluate each route leg at its expected departure: driver-to-pickup first, pickup-to-drop-off after readiness/loading, then repositioning to the next reservation. Refresh an obsolete ASAP estimate instead of sending a historical driving departure. Service urgency changes eligibility, dispatch priority, and/or price policy; it must not artificially shorten Google travel duration.

## Limits and verification handoff

- These sources establish platform capabilities, not this repository's current registration of services, enabled plugins, payment gateway configuration, Redis configuration, or working driver-state integration. Verify those locally before naming implementation targets.
- Commerce checkout recipes contain older scaffolding examples. Use the installed Drupal/Commerce code signatures and dependency injection conventions rather than copying version-specific generator commands.
- The queue issue is historical evidence of a design constraint, not proof that no third-party Redis adapter now exists.
- Different Google API products can document different limits; use the REST Routes API limits for this project's REST client. API quotas, billing, key restrictions, permitted response storage, and terms require checking against the actual project configuration before deployment.
- No paid API calls, customer orders, payment mutations, queue execution, or application changes were performed for this note.
