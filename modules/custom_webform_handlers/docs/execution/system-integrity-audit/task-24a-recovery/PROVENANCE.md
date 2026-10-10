# Task 24A recovery — provenance and evidence discipline

This document records where the recovered content comes from, what the evidence
does and does not establish, and the resolution of the six required recovery
checks. It is deliberately conservative: claims are scoped to what the artifacts
actually prove.

## Where the work went

The Task 21A, Task 22D, and price-snapshot-forwarding changes are absent from the
working tree at baseline `b49c259e`. They were **never committed, stashed, or
placed on a branch**, so Git never held a copy. The durable records are:

- the Claude authoring session transcripts (21A: `a02d06f0`/`ce0c4f4d`; 22D:
  `09006154`), which contain the exact Write/Edit payloads; and
- the Codex Sept 14 audit session, which read the then-current working tree
  verbatim — that read is the source for the price-snapshot forwarding, which was
  authored in Codex (not Claude) and confirmed present at audit time by
  `README.md:10`.

Recovery therefore reconstructs the working-tree state from those records, not
from any Git object.

## Corrected evidence-wording statements

Three earlier statements overclaimed. Corrected here:

1. **Reflog.** The reflog shows no reference rollback (no `reset`, no branch
   deletion that would have orphaned a commit). That is true but weak: it cannot
   establish that uncommitted working-tree files were never discarded. The reflog
   only tracks *reference* movement. The accurate finding is narrower — the work
   was never committed/stashed/branched, so there was never a Git object for the
   reflog to have moved away from.

2. **Dependency signatures.** The recovered constructors and service arguments
   match the signatures the consuming code expects (e.g. the 7th
   `?RoutingModeResolver` ctor arg, the `customer_route_entry_provider` service
   graph). Matching signatures does **not** prove the code compiles or that
   dependency injection resolves at runtime. Offline `php -l` proves syntax only;
   container-level DI resolution was not exercised (see `VERIFICATION.md`).

3. **Checksums / provenance table.** Byte-for-byte agreement between the candidate
   and the transcript payloads proves the candidate matches *what the authoring
   sessions recorded*. It does **not** prove the candidate is the complete accepted
   implementation — if the accepted version included edits made outside these
   transcripts (or later hand-adjustments never logged), those would not appear
   here. No evidence of such edits was found, but their absence cannot be proven
   from the available artifacts.

## The six required recovery checks

### 1. `routing_mode` typed-config schema — INCLUDED

`config/schema/custom_webform_handlers.schema.yml` gains `routing_mode: type:
string`, and `config/install/custom_webform_handlers.settings.yml` ships
`routing_mode: live_cached`. `update_10045` sets the default on existing sites.

### 2. Google adapter development guard + injection — INCLUDED, both instances

`GoogleRoutesDistanceProvider` gains the 7th ctor param
`?RoutingModeResolver $routingModeResolver = NULL` and a fail-closed guard at the
top of `route()` that throws `NOT_CONFIGURED` when `isDevelopmentFixed()`. In
`services.yml`, **both** Google adapter service definitions receive
`- '@custom_webform_handlers.routing_mode_resolver'` (the ordinary adapter and the
chain-member adapter), so neither can emit an outbound request in
`development_fixed`.

### 3. All three customer entry points use the facade — VERIFIED

- `DistancePreviewController::create()` → `customer_route_entry_provider`.
- `AddressDistanceHandler::create()` → `customer_route_entry_provider` (plus the
  `routing_mode_resolver` for the browser-Maps gate).
- `DispatchCheckoutStarter` (services.yml arg 5) → rewired google →
  `customer_route_entry_provider`.

### 4. Complete `price_snapshot_version` data path — INCLUDED

- Value objects: `DispatchClaim::$priceSnapshotVersion`,
  `DispatchReservationDetail::$priceSnapshotVersion`.
- Store: `REPLAN_FIELDS` list, claim INSERT, replan verbatim copy,
  `adminReservation()` SELECT, `adminReservation()` hydration.
- Schema + migration: install schema column (`varchar_ascii`/64/nullable) and
  `update_10046` idempotent `addField`.

### 5. Snapshot forwarding through planner/manager — RECOVERED (correcting a prior error)

**This finding was previously wrong and is corrected here.** An earlier pass
concluded that price-snapshot forwarding "never existed by design," relying on a
Task 22D design summary (`a02d06f0…jsonl:2101`) that predated the forwarding work.
A design summary saying something is out of scope does not establish that it was
never implemented afterward.

Direct inspection of the Sept 14 audit's working-tree reads
(`~/.codex/sessions/2026/09/14/…`) and the committed audit record
(`docs/execution/system-integrity-audit/README.md:10`) shows the forwarding *did*
exist in the tree the audit inspected:

- `DispatchCheckoutManager::start()` forwarded its `QuoteSnapshot $quote` into
  `createCheckoutHold($request, $resolved, $quote)` (line 129).
- `DispatchCheckoutPlannerInterface::createCheckoutHold()` accepted the snapshot.
- `DispatchPlanner::createCheckoutHold()` decomposed the quote into its four
  facts (`snapshotId`, `amountCents`, `currency`, `rateVersion`) and threaded them
  through `createHoldFor()` into `new DispatchClaim(...)`, so the amount, currency,
  snapshot identity and pricing-policy version are written atomically with the
  claim. Public and staff callers pass NULL.

This forwarding is **absent from the working tree at `b49c259e`** (baseline
`createCheckoutHold` is 2-arg; `createHoldFor` has no price params; the
`DispatchClaim` call passes 10 positional args). It is therefore a genuine
recovery gap, now included in the candidate.

**Provenance of the recovery:**

- The `DispatchPlanner` body/signature/docblock/claim-args and the
  `DispatchCheckoutManager` call are **recovered verbatim** from the Sept 14
  working-tree reads captured in the Codex session log.
- The `DispatchCheckoutPlannerInterface` changed declaration is a **labeled
  minimal reconstruction**: no session ever read the interface's post-change
  signature verbatim, but the typed 3-arg call at the manager cannot compile
  unless the interface declares the third parameter. The reconstruction is forced
  by the call site; the accompanying docblock is authored, not recovered.

### 6. Fixture values preserved — VERIFIED

`FixedDevelopmentRouteProvider` carries `FALLBACK_DISTANCE_METERS = 19312`,
`FALLBACK_DURATION_SECONDS = 1680`, `PROVIDER_ID = 'development_fixed'`,
`DATA_VERSION = 'ddev-fixed-v1'`, all byte-identical to the authoring Write.

## Coverage limits (graphify / evidence systems)

Per the project rules, graphify is AST-only: it indexes PHP and JS but **not**
YAML or Markdown. The `services.yml`, schema, and settings edits are therefore
outside graph coverage and were confirmed by direct file inspection against the
transcript payloads, not by a graph edge. This is a documented coverage limit,
not a tool failure.
