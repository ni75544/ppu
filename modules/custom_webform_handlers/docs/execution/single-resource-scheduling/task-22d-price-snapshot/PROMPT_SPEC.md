# Task 22D — Make dynamic pricing small, versioned, and auditable

You are Claude Code CLI working after Task 22A. Implement this only if the audit
proves a gap in the existing quote or price-snapshot contract.

## Objective

Provide a small server-authoritative pricing policy that uses the same route fact
as scheduling without making price logic responsible for capacity planning.

## Required policy

The pricing module accepts validated route distance/duration, service class,
delivery mode, configured handling/access modifiers, scheduled-time modifier,
policy version, and currency. It returns one immutable quote snapshot in integer
cents and a customer-safe breakdown:

```text
base fare + distance rate + service/handling modifier
+ approved time/congestion modifier + approved toll/parking modifier
= total, subject to minimum fare
```

Do not add opaque surge pricing, machine-learning scoring, or a price rule for
every UI field. Unmapped complexity belongs in Manual Review.

## Required behaviour

- DDEV uses Task 21A's fixed route fact; production uses the approved cache-first
  route policy.
- Persist amount, currency, route facts, input facts, and pricing-policy version
  at the existing quote/reservation snapshot seam.
- A paid order retains its snapshot. A replan may propose a revised quote but may
  not silently change a paid total.
- Quote-only recalculation reuses a valid route fact and never invokes a provider.
- Browser totals are informational; checkout accepts the server snapshot only.

## Expected seams and verification

Confirm actual callers before editing. Likely seams include `src/Quote`,
`src/Checkout/QuoteSnapshot.php`, checkout manager/starter, reservation storage,
and quote configuration/schema. Use deterministic fixed route facts to prove
identical inputs yield identical cents/version; changed inputs alter only the
expected portion; later UI interaction makes no provider call; stored snapshots
remain unchanged; and missing facts refuse a self-bookable quote. Follow root
Graphify/GitNexus/DBHub/YAML rules and run only allowed lint/cache/Drush/diff
checks. No suites, payments, live providers, or commits.

