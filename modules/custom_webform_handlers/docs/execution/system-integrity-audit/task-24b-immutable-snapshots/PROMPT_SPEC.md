# Task 24B — Complete the immutable job and price snapshot

You are Claude Code CLI in C:/Users/mo737/ppu. Read ../RULES.md and ../README.md. Prerequisite: 24A; runtime probes require 24H's zero-cost proof.

## Objective and current defects

Complete the remaining 22D contract, addressing E08/E09. QuoteSnapshot reaches public checkout holds now, but it lacks the complete priced job, route amounts and line-item breakdown. Confirmation rebuilds a mutable submission and checks timing without checking accepted job identity. Snapshot hydration deliberately preserves a stored identifier without payload integrity validation.

## Files

- src/Checkout/QuoteSnapshot.php; CheckoutAttempt.php; CheckoutAttemptStore.php
- src/Quote/QuoteResult.php; QuoteCalculator.php
- src/Checkout/DispatchCheckoutStarter.php
- src/Commerce/DispatchOrderFactory.php; DispatchCheckoutManager.php
- src/Dispatch/DispatchClaim.php; DispatchReservationDetail.php; DispatchPlanner.php
- src/Dispatch/Storage/LockedHold.php; DatabaseReservationStore.php; ReservationStoreInterface.php
- custom_webform_handlers.install, services YAML and configuration schema as required
- New immutable snapshot/request value object or persistence helper only if existing classes cannot own the contract.

## Execution

1. Enumerate every snapshot producer, reader and writer, including approved review, staff, legacy rows and replans. State which paths currently omit fields.
2. Define one versioned accepted snapshot: normalized job/endpoint identity, service/mode, handling facts, raw job-leg meters/seconds, provider/data version and freshness, separately identified scheduling margins, pricing-policy version, currency, integer-cent breakdown and total.
3. Store it durably once and reference it from attempt/order/reservation. Choose the smallest existing storage seam; do not duplicate independent mutable copies or introduce a second class named PriceSnapshot without a reason.
4. Bind checkout to a submission revision or canonical priced-input digest. A change to addresses, service, package, timing modifier or access facts requires an explicit new offer/acceptance before authorization. Contact-only edits need not reprice unless they change the job.
5. Separate schema-versioned payload integrity from legacy snapshot identity. Validate new payloads/digests; read older IDs compatibly and flag incomplete legacy provenance. Never regenerate historical totals from today's tariffs.
6. Ensure Commerce quantity, unit price, adjustments, currency and payable total match the accepted snapshot at authorization/capture. Approved taxes/fees must be represented once; unknown adjustments refuse or require reacceptance.
7. Preserve original accepted snapshots across replans. Route observations may change without silently changing the purchased job or price; subsequent tasks govern amendments.

## Verification and completion

Using fixed facts only, prove: identical priced inputs yield identical cents/breakdown; every applicable modifier changes only expected lines; sum equals total; missing/partial/corrupt snapshots fail closed for new checkout; historical NULLs remain readable; rate changes do not rewrite booked prices.
Reproduce an address/package edit with equal duration between hold and confirmation: it must not confirm under the old snapshot.
Verify round-trip equality through attempt, reservation, order and checkout display. Run scoped syntax/schema/Drush/diff checks.

## Red lines

Preserve 21A and current server-authoritative pricing. No browser total as financial authority, live route request, API inside locks, repricing paid orders, invented historic snapshot or mass rewrite of user submissions.
