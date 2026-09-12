# Task 20 — Routing cost reduction execution pack

Prepared 2026-09-05. This is a proposed implementation sequence, not an implemented
or activated provider migration. Each child directory contains a `PROMPT_SPEC.md`
for Claude Code CLI. Execute one task at a time.

## Objective

Stop routing requests caused by uncommitted address typing, use self-hosted OSRM
for ordinary distance quotes, fall back to Mapbox within explicit limits, and
reserve Google Routes for explicitly permitted traffic-sensitive final checks.
Keep the existing quote calculator, Commerce flow, and capacity authority.

## Evidence and limitations

- Read the root `CLAUDE.md`, `.claude/rules/graphify.md`,
  `.claude/rules/drupal-ddev.md`, `CONTEXT.md` from the preceding review, and the
  existing Commerce execution pack. The available standalone pack covers Tasks
  1–9; searches found later task references, not a complete standalone 11–19 pack.
  Do not represent the missing history as reviewed. Task 20A resolves it.
- Graphify `explain GoogleRoutesDistanceProvider` establishes EXTRACTED
  implementations of both `DistanceProviderInterface` and
  `ScheduledRouteProviderInterface`, and references to the HTTP client, cache,
  configuration, and logger. `explain DistancePreviewController` establishes its
  distance-provider, quote-calculator, and flood-service dependencies.
- Direct YAML inspection, outside graph coverage, establishes that
  `custom_webform_handlers.distance_provider` currently aliases the Google
  implementation and is also injected into the scheduled route estimator and
  checkout starter. Switching this alias has more impact than the preview alone.
- The existing public contract is
  `calculate(string $origin, string $destination): DistanceResult`; the separate
  scheduled contract accepts a departure timestamp. Preserve these contracts.
- GitNexus and DBHub MCP are not callable in the architect's current tool list.
  Their required impact/schema checks are pending, not waived. No live schema,
  runtime provider readiness, account quota, billing breakdown, or performance
  result has been established for this pack.
- Context7 returned primary OSRM and Mapbox documentation. Its Drupal result
  referenced 11.x, so it is not evidence for exact Drupal 10.6 API compatibility;
  the executor must check installed Drupal 10 source and version-matched docs.
- A research sub-agent was requested but failed because of its usage limit.
  The architect completed the linked primary-source research directly. No paid
  routing requests, application changes, cache rebuilds, or tests were performed
  while preparing these documents.

## Architectural decisions

### Separate provider choice by purpose

Use the same chain implementation with separately configured container instances.
Purpose is selected by trusted server wiring, never by a public request parameter.

| Server use | Default eligible sequence after activation | Google policy |
| --- | --- | --- |
| Ordinary preview and final distance-based price | OSRM → Mapbox `driving` | Forbidden |
| Slot offers and background warming requiring traffic | Mapbox `driving-traffic` | Forbidden |
| Final booking validation requiring traffic | Mapbox `driving-traffic` → Google scheduled routing | Allowed only for a validated checkout/staff operation, with budget |
| Scheduling where existing policy explicitly permits non-traffic estimates | OSRM → Mapbox, with existing conservative margin | Forbidden unless traffic becomes an explicit requirement |

OSRM is not tried for a capability it does not have. An OSRM outage must not turn
ordinary previews into Google requests. A failed required-traffic check cannot be
replaced by an unlabelled static duration. Keep existing
`routing.require_traffic_aware` and conservative-duration policy authoritative.

Traffic determines schedule feasibility. It does not silently change the approved
distance-price formula. If final route facts change the price, show the revised
server quote before authorization and create a new accepted snapshot. Never
change an existing paid order or raise the amount during a payment callback.

### Preserve a small distance interface

`ChainDistanceProvider`, `OsrmDistanceProvider`, and `MapboxDistanceProvider`
implement the existing `DistanceProviderInterface`. Retain
`GoogleRoutesDistanceProvider` and its service ID. Mapbox and the chain also
implement `ScheduledRouteProviderInterface`; OSRM does not claim traffic support.

OSRM and Mapbox resolve text through one shared `AddressCoordinateResolver`
adapter over the approved Drupal Geocoder provider. Its request-local memoization
and persistent cache avoid repeating geocoding on fallback. Do not duplicate
geocoding HTTP implementations when an installed provider owns that operation.

Use an explicitly injected provider map in `*.services.yml`. Configuration stores
allowlisted provider IDs, not class names, arbitrary service IDs, or URLs supplied
by visitors. A new Drupal routing-plugin manager is unnecessary for three known
container adapters.

### Two cache layers

1. **Address resolution:** canonical full-address hash, country, postal code,
   geocoder identity/version, and applicable use restrictions → validated
   coordinates, precision, provenance, and original fetch/expiry times.
2. **Route calculation:** ordered endpoint coordinates, provider, routing profile,
   relevant vehicle/avoidance parameters, data version, traffic/departure bucket,
   geometry representation, and policy version → normalized route result.

Postal codes can partition the first cache, but are not sufficient keys. Two
addresses sharing a ZIP code are different jobs. Optional ZIP-centroid estimates
belong in an explicitly approximate namespace, disabled initially, and cannot
produce payable quotes, confirmable Slots, or reservation facts.

Cache hits retain their original fetched time and provenance. They must not look
newly calculated. An address-pair route is directional; A→B is not B→A. Exact
coordinate identity uses the resolver's canonical precision without additional
coarse rounding. Cache policy must account for provider rights as well as freshness.

### Cost limits are part of correctness

Set Google allowance to zero for preview, quote, public offer, and background
purposes. Optional providers remain disabled until credentials, permissible use,
and a site allocation are configured. Every paid HTTP attempt, including a retry,
reserves allowance before transmission. Account-level quotas remain necessary:
this site's ledger cannot see other applications sharing an account.

Use short timeouts, a total chain deadline, retry classification, circuit breakers,
and duplicate-miss suppression. Redis/cache loss must not reset a financial limit
or trigger an unrestricted paid fallback. No provider error produces a zero-mile
route. Public error messages explain whether an address needs correction or the
estimate is temporarily unavailable.

## Task order

| Task | Outcome | Depends on |
| --- | --- | --- |
| [20A](task-20a-baseline/PROMPT_SPEC.md) | Tool/history/call-path inventory and cost attribution | None |
| [20B](task-20b-request-suppression/PROMPT_SPEC.md) | Immediate suppression of typing/duplicate requests | 20A relevant frontend checks |
| [20C](task-20c-contracts-config/PROMPT_SPEC.md) | Provider contracts, container design, typed configuration | 20A |
| [20D](task-20d-coordinate-resolution/PROMPT_SPEC.md) | Approved coordinate resolution and first cache layer | 20C |
| [20E](task-20e-provider-adapters/PROMPT_SPEC.md) | OSRM/Mapbox adapters and OSRM operating runbook | 20D |
| [20F](task-20f-chain-cache-budgets/PROMPT_SPEC.md) | Fallback chain, route cache, circuit/budget controls | 20E |
| [20G](task-20g-integration/PROMPT_SPEC.md) | Preview, scheduling, checkout, and map integration | 20B, 20F |
| [20H](task-20h-rollout/PROMPT_SPEC.md) | Bounded acceptance, activation instructions, rollback | 20G + runtime readiness |

20B can ship as a small cost-reduction change before OSRM is ready. Until the
provider migration is activated, it reduces the number of existing Google calls;
it does not claim to have removed Google from previews.

## Shared execution rules

Read `CLAUDE.md`, the RTK instructions it inherits, applicable `.claude/rules/`,
this README, and the individual prompt before execution. Preserve the existing
Task format: Objective, Target files, Required implementation, Verification.

- Write only beneath `modules/custom_webform_handlers/`. This pack intentionally
  lives inside the module to comply with the current policy, although older packs
  live in root `docs/`. Preserve existing unrelated untracked documents.
- Ignore duplicate module directories. Preserve unrelated changes. No broad
  formatting, commits, resets, stash operations, Composer changes, Drupal upgrade,
  global config export/import, or DDEV reconfiguration in these tasks.
- Evaluate native skills, slash commands, tools, plugins, and independent
  sub-agent opportunities. Record availability and results. Use Graphify first for
  supported source; GitNexus for impact; Context7 for documented API behavior;
  DBHub with its read-only account when schema/SQL facts are required. A required
  unavailable system blocks its dependent implementation, not unrelated document
  preparation. Do not declare a configured tool operational without calling it.
- Inspect YAML directly: it is outside the current graph's coverage. Record
  contrib reuse decisions, particularly Geocoder, Drupal cache/flood services,
  Commerce checkout panes, and existing queue jobs.
- Use dependency injection and PHP 8.4/Drupal 10.6-compatible APIs. JavaScript uses
  `Drupal.behaviors` and `once()`. Use `apply_patch` or the executor's native patch
  editor; prefix shell commands with `rtk` and run Drupal commands through DDEV.
- Preserve New York display time, UTC storage, no public timezone selector, no
  public hold timer, synchronous capacity decisions, and quote authority.
- Preserve existing tests. Do not generate or run automated tests, browser probes,
  or broad scans. Listed manual scenarios are acceptance instructions for a human
  or an explicitly authorized bounded session, not permission for a new suite.
- After a coherent Drupal source/config edit run
  `rtk proxy ddev exec vendor/bin/drush cr`. Run no unrelated update hook. Task 20F
  names a targeted migration gate; active DB mutation needs that task's explicit
  execution authority and a reviewed pending-update list. Documentation-only
  preparation does not need a cache rebuild.
- Refresh Graphify after relevant PHP/JS changes under its documented code-only
  workflow. Keep generated indexes, map datasets, secrets, and local exports out
  of source control.
- Finish with files changed, verification actually performed, pending checks,
  Capability Ledger, Reuse Decisions, and blockers. Stop at the task boundary.

## Sources

Read [the provider research note](../../research/routing-provider-costs-2026-09-05.md)
before implementing provider requests, persistent caching, or map rendering.
