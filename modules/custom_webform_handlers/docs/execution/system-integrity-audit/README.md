# Task 24: system-integrity audit and execution pack

Audit date: 2026-09-14. Repository: C:/Users/mo737/ppu.
Source baseline: dirty working tree at b49c259e3e3d5452286ffb45d1ec6bf1451c0fcc.

## Current execution pointer - 2026-10-07

The next requested workstream is [Task 25: contrib-only Webform-Commerce bridge](../commerce-webform-bridge/README.md).
Start with its read-only Task 25A decision; later tasks are separately approval-gated.
Preserve the scheduling engine, driver locks, existing Commerce implementation,
and historical data. The Task 24 findings and acceptance history below remain an
audit record, not authorization to resume competing implementation or cleanup.

## Executive decision

The architecture has useful foundations, but it is not ready for a payment-backed release. Complete data integrity and recovery before simplifying the booking form.

Task 22D has progressed beyond the previous report: DispatchCheckoutManager::start now passes QuoteSnapshot to createCheckoutHold, and DispatchPlanner forwards its identifier, cents, currency and version into DispatchClaim. This is useful wiring, but deployment, complete snapshot contents, all-entry-path consistency and immutable request binding are still incomplete.

No double booking or duplicate charge was produced during this audit. The concurrency findings below are source-traced interleavings requiring controlled reproduction. No live provider, payment, browser acceptance or application database mutation was intentionally invoked.

## Architecture observed

Customer form -> non-authoritative distance/price preview
-> saved Webform submission -> DispatchCheckoutStarter -> QuoteSnapshot
-> DispatchCheckoutManager -> planner prepares route facts
-> ResourceCalendarGuard -> held reservation -> Commerce order + attempt
-> authorization -> guarded reservation confirmation -> payment capture
-> checkout-attempt settlement -> order link -> review update -> notification queue.

Staff direct confirmation enters the planner without a QuoteSnapshot or Commerce order.
Staff replan replaces the reservation row and copies its price/order fields.
Legacy fixed-slot reservation services coexist behind BookingWorkflowGate.

The critical gap is between the local transactions: the current resource lock protects a calendar decision, not the entire reservation/payment/attempt/order lifecycle.

## Strengths to retain

- Calendar guard rows serialize resource/day decisions; date keys are acquired in sorted order. The inspected reservation, guard and checkout-attempt tables are InnoDB.
- Claim and confirmation callbacks revalidate using prepared route facts with mayFetch=false. Preserve this boundary.
- Checkout prices are constructed server-side and order items use an explicitly overridden unit price.
- Checkout attempts have unique order, intent-key and reservation indexes. These provide partial idempotency, not submission-wide exclusion.
- The public preview explicitly says authoritative=false; dirty/committed address events and listener teardown already exist.
- Workflow guards, protected operational views, hashed hold ownership and notification deduplication already exist. Extend these rather than adding parallel systems.

## Evidence and findings

P0 means a release blocker; P1 means a high-priority correctness/operational issue.
Line references describe the inspected working tree and must be refreshed by the executor.

| ID | Priority / evidence | Finding and impact | Task |
| --- | --- | --- | --- |
| E01 | P0 / live metadata | system.schema is 10044; reservation price_snapshot_version is absent. Current store INSERT/SELECT paths reference it. These operations cannot succeed against this schema. Source update 10046 adds it; 10045 also precedes it and sets a live_cached default if absent. | 24A |
| E02 | P0 / live config | Dispatch checkout flow is active with capture=false and the custom pane. No commerce_payment.commerce_payment_gateway.* config entities were returned. Payment-path acceptance is not established. | 24A, 24E |
| E03 | P0 / source | settlePaid captures at manager:413, settles the attempt at 416, then links the order at 442; reservation confirmation has already committed. cancel:228 releases a hold before settling cancellation. A cancellation or process crash between these steps can leave contradictory reservation/payment/attempt states. | 24D, 24E |
| E04 | P0 / source | confirmHeld:338 and planner confirmFor:447 depend on session-owned hold credentials; planner:519 forgets them after confirmation. There is no durable session-independent completion path among the inspected module callers; the only concrete confirmPaid caller found is the checkout pane. Lost browser/session and crash recovery are incomplete. | 24D, 24E |
| E05 | P0 / source + indexes | existingAttempt performs read-before-write checks; intent uniqueness includes slot and quote. Different intents for the same submission are not excluded by a DB constraint. Staff's liveClaimForSubmission check at planner:702 is also outside a submission-wide lock. Non-overlapping duplicate jobs or cross-day/staff/public races can escape resource/day locking. | 24C |
| E06 | P0 / source | Store renew:737 uses request-start time and renews without the calendar guard. After delay/expiry it can judge a stale hold renewable while a later request sees it expired. existingAttempt also returns a pending order without checking hold expiry. Test revival and permanent-pending checkout behavior. | 24C |
| E07 | P0 / source | Payment pane:143 returns no refusal when no authorization is found, and flow:91 does the same when its custom pane is absent. The authorization lookup selects the first authorization, with no amount/currency/expiry/snapshot match. A completed or unsupported gateway path is not a proven capacity gate. | 24E |
| E08 | P1 / source | QuoteSnapshot stores total, currency, rate version and route provenance, but not complete pricing inputs, line items or route meters/seconds. fromStored:157 deliberately accepts the stored ID without checking a versioned payload digest. Preserve legacy identity while adding verifiable new snapshots. | 24B |
| E09 | P0 / source | Confirmation rebuilds the mutable submission at planner:457; LockedHold and planStillHolds compare slot/timing but not immutable job/quote identity. A changed job with the same reserved duration can satisfy timing checks while stored locations and price still describe the earlier job. | 24B, 24D |
| E10 | P1 / source | Staff confirmation at planner:722 supplies no price snapshot. Approved-review checkout checks offer expiry only at entry. recordScheduled accepts positive order/reservation IDs without independently verifying their relationship. Define financial authority and approval acceptance consistently. | 24F |
| E11 | P1 / source | Store replan:642-688 supersedes a row and inserts a new one with copied price/order. The inspected operation does not advance CheckoutAttempt.reservationId or ReviewRequest.dispatchReservationId. Historical IDs and current operational IDs need explicit lineage. Release/refund semantics also require a defined policy. | 24F |
| E12 | P1 / source | Public feasibleOffers uses a small set of day estimates and fixed reposition padding; authoritative planFor uses actual neighboring endpoints. Break checking covers the job window, not all travel/rest intervals. Service routing uses pickup arrival before loading, and depot routing uses day opening. Offers, traffic timing and driver feasibility can diverge. | 24G |
| E13 | P0 / source wiring | Customer preview/checkout route entry uses the fixed/live facade; dispatch estimators still consume ordinary/offer/final chains containing OSRM/Mapbox/Google adapters. Thus 21A's customer guard does not prove all scheduling paths are fixture-only or share the priced route. Simply wiring the fixed provider into the current estimator also fails its expiry/provider-allowlist checks. | 24H |
| E14 | P0 / source | Preview reusableDistance only accepts client data labeled google_routes with a fresh timestamp. Missing/expired/rejected cached data falls through to calculate() at controller:81-82, including requests intended as quote-only. Browser deduplication is not server-side once-only enforcement. | 24H |
| E15 | P1 / source | JS marks a pair attempted before receiving a result and refuses non-success repeated attempts. Lost responses can strand the customer. JSON endpoint casts address values before type checks; malformed cached payloads and misleading empty-slot results need bounded typed handling. | 24I |
| E16 | P1 / source | QueueDispatchNotifier:101 catches enqueue failure and only logs it; manager emits after settlement and only when it wins. A crash/queue outage can permanently lose confirmation notices. ReconciliationRequired is a state but not an implemented recovery workflow in the inspected callers. | 24E, 24J |
| E17 | P1 / verification gap | Public/staff RBAC, cache isolation, bearer tracking links, aggregate financial totals, resource-status effects and legacy calendar coexistence need a cross-path acceptance matrix. No browser/security behavior is certified from route declarations or graph absence alone. | 24G, 24I, 24J, 24K |

## Minimal target design

Keep existing services. Add durable data/operations only where necessary:

1. One immutable accepted job/price snapshot, referenced by the checkout attempt, Commerce order and reservation. Input changes create a new version; confirmed snapshots do not mutate.
2. One active booking intent per submission/job, enforced at a shared database seam independently of resource/day.
3. Existing resource/day guard remains the calendar authority. In one short local transaction, bind the confirmed reservation, attempt, order relation and a durable payment-operation intent.
4. After commit, execute payment operations with stable idempotency and recorded outcomes. Unknown outcomes remain visible for reconciliation; never free capacity merely because a response was lost.
5. Durable notification intent is saved with the business transition; existing workers deliver it later.
6. Keep one active resource initially. Add explicit resource records only when a second real driver is introduced; capacity=2 is not a substitute for two driver timelines.

No fleet optimizer, microservices, machine learning, generic event-sourcing rewrite or new queue infrastructure is needed.

## Execution order

Read [shared rules](RULES.md) first. Send one PROMPT_SPEC.md at a time to Claude Code.

| Task | Deliverable | Prerequisite |
| --- | --- | --- |
| [24A](task-24a-readiness/PROMPT_SPEC.md) | Deployment/schema readiness and truthful baseline | None |
| [24H](task-24h-route-cost-boundary/PROMPT_SPEC.md) | End-to-end zero-cost boundary and quote-only reuse | 24A environment safety |
| [24B](task-24b-immutable-snapshots/PROMPT_SPEC.md) | Complete immutable job/price snapshot | 24A; dynamic probes after 24H |
| [24C](task-24c-booking-admission/PROMPT_SPEC.md) | Submission-wide admission and safe hold expiry | 24A, 24H |
| [24D](task-24d-atomic-local-settlement/PROMPT_SPEC.md) | Atomic local booking settlement and durable recovery identity | 24B, 24C |
| [24E](task-24e-payment-recovery/PROMPT_SPEC.md) | Payment evidence, idempotency and recovery | 24D |
| [24F](task-24f-staff-review-replans/PROMPT_SPEC.md) | Consistent staff/manual/replan/cancel lifecycle | 24B-24E |
| [24G](task-24g-driver-timeline/PROMPT_SPEC.md) | Driver timeline/offer accuracy and operational policy | 24C, 24H |
| [24I](task-24i-form-boundaries/PROMPT_SPEC.md) | Input validation, retries and clear customer errors | 24B, 24E, 24G, 24H |
| [24J](task-24j-outbox-operations/PROMPT_SPEC.md) | Durable notices, reconciliation views and access checks | 24D-24F |
| [24K](task-24k-release-acceptance/PROMPT_SPEC.md) | Evidence-backed release gate | All preceding tasks |

24B/24C/24G may be developed separately only with non-overlapping file ownership. Run task execution serially by default.

Tasks 24B and 24D complete/harden the concerns of 22D and 22E; they do not restart accepted 21A-22C. Resume 23A+ form simplification after the critical integrity gates pass.

## Audit limits and tool record

- Direct source, service YAML, update hooks, live SELECT-only DB metadata/config and caller searches support the findings. No customer row contents were inspected.
- Graphify was used for orientation. GitNexus reindex completed with exit 0: 6051 nodes, 15723 edges, 265 clusters, 515 flows. It reported incomplete flow extraction and missing Windows FTS dependencies.
- GitNexus MCP search still reported an older branch index after refresh; confirmPaid context was available but marked lower-bound at DI/interface boundaries. No absence-of-caller claim relies on these graphs.
- Intended read-only DDEV cget/updatedb:status commands auto-started DDEV. Parallel startup produced container-name conflicts; the audit stopped those commands. Containers were recreated; no volume deletion was requested or executed. The final Docker status check showed db healthy, web unhealthy and ancillary containers up. Runtime readiness is blocked; any lifecycle repair needs explicit scope in 24A.
- No migration, config import, cache rebuild, lint suite, browser acceptance, paid request or payment experiment completed in this audit. Database metadata was rechecked afterward: schema remains 10044, snapshot-version column remains absent.
- This work creates audit/task documents only; existing module source and configuration changes are preserved. Tool-generated index artifacts were refreshed. GitNexus also appended a generated block to CLAUDE.md and created a generated AGENTS.md; only those verified tool-created additions were removed, restoring the pre-audit instruction-file state.
- Payment integration guidance was cross-checked against the official [Commerce payment gateway guide](https://docs.drupalcommerce.org/v2/developer-guide/payments/creating-payment-gateway/): return and notification handling are distinct gateway integration paths. Compatibility must be established for the actually selected gateway, which is currently unconfigured.
