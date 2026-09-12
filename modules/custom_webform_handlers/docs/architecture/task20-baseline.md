# Task 20A — Routing cost, history, and capability baseline

Recorded 2026-09-05 on branch `codex-experiments`, HEAD `a06e83e2`. Read-only
except for this file and [the provider policy](task20-provider-policy.md).

No provider request was made, no test or browser probe was run, no Composer or
config import was executed, and no cache was rebuilt. Nothing in this document
is a measurement of production traffic. The reported $52+ charge is **not**
treated here as a verified Routes-only invoice; see
[Cost attribution](#cost-attribution-pending).

## Capability ledger

| System | Status now | Evidence, or the exact failure |
| --- | --- | --- |
| Graphify | **Usable**, within AST-only coverage | `explain distance_calculator.js` returned 12 EXTRACTED `contains` edges; `affected "postPreview()"` returned `refreshPreview()` L524 `[calls]` and `scheduleRefresh()` L609 `[indirect_call]`. YAML and Markdown remain outside coverage, so every wiring fact below is labelled as read from source. |
| GitNexus MCP | **FAILED — blocker** | `impact` and then its own suggested fallback `context` both returned `LadybugDB unavailable for C:\Users\mo737\ppu\.gitnexus\lbug. Another process may be rebuilding the index. Retry later. (Runtime exception: Cannot open database in read-only mode while checkpoint is in progress.)` with `impactedCount: null`, `risk: "UNKNOWN"`. Two tools, two attempts. Re-indexing is deep verification and was not requested, so it was not attempted. |
| Context7 MCP | **Partly usable** | OSRM and Mapbox Directions API behaviour resolved. The Mapbox library `/llmstxt/mapbox_llms_txt` returned no match for Directions caching/storage terms, so the persistent-caching permission is **still unresolved**. Its Drupal result is 11.x and is not cited as 10.6 evidence. |
| DBHub MCP | **ABSENT — blocker** | No DBHub tool exists in this session's tool list. Item 9's durable-schema verification cannot be satisfied at all. |
| Installed Drupal source | **Used** | `core/lib/Drupal.php` `const VERSION = '10.6.16'`. `Settings::get()`, `FloodInterface`, `CacheBackendInterface`, `LockBackendInterface` all read from installed 10.6.16 rather than from documentation. |
| DDEV read-only Drush | **Used** | `drush cget` and `SELECT`-only `sql:query`. No export, no import, no state mutation, no secret value printed. |
| Sub-agents, skills, slash commands | **Not used** | Documentation-only task inside one module; the evidence set is small and named. Recorded as evaluated, not as unavailable. |

Consequence: **every impact claim in this document rests on Graphify plus direct
source reads, not on GitNexus.** Where blast radius mattered, the call sites are
enumerated by file and line instead of asserted.

## Scoped Git status

Three untracked entries, no tracked modification:

```text
?? docs/architecture/fast-courier-primary-sources-2026-09-05.md
?? docs/research/fast-pickup-order-flow-plan-2026-09-05.md
?? modules/custom_webform_handlers/docs/
```

This document and the policy document are added inside that third, already
untracked tree. The two root-level untracked documents are unrelated and are
preserved untouched.

## Tasks 1–19: what exists and what is missing

Accessible history is 11 commits (`git log --all --oneline`). What survives per
task, stated exactly:

| Range | Versioned prompt spec | Outcome document | Per-task commit |
| --- | --- | --- | --- |
| Tasks 1–9 | **Yes** — root `docs/execution/express-courier-commerce/task-01-baseline/` … `task-09-cutover-launch/`, each with a `PROMPT_SPEC.md` | partial | No — squashed into `8dc47a1a` (316 files) |
| Task 10 | **No** | Yes — root `docs/architecture/task10-baseline.md`, recorded 2026-08-31 | No |
| Tasks 11–19 | **No** | Partial only, listed below | No |

All of that history lives in the **root** `docs/` tree. This pack lives
module-local instead, to comply with the current scope policy; the paths differ for
that reason, not because a second history exists.

Root `PROMPT_SPEC.md` is the legacy fixed-slot Webform prompt and carries its own
banner: *"Superseded for the Commerce/Express Courier roadmap. Do not execute this
fixed-slot Webform prompt for the current PRD."* It is not Task 1–19 history.

Outcome documents that do exist for the 11–19 range, all under root `docs/`, and
nothing more: `architecture/config-reproducibility-audit.md` (the Task 18A/18B
audit), `releases/2026-09-05-courier-release-attestation.md` (Task 19),
`operations/dispatch-workers.md`, `operations/express-courier-launch.md` — which is
also where the server Routes credential setup landed, per commit `89814129`, rather
than in a file of its own — `operations/express-courier-rollback.md`, and
`incidents/2026-08-30-hg-78-79-submissions.md`. For the rest, the only record is a
commit subject: `9581b4e2`, `8f31ff41`, `895d5c1b`, `3acd7f70`, `14e78609`,
`89814129`, `a06e83e2`.

**Precisely what is missing:** the prompt specifications for Tasks 10 through 19,
and any per-task diff for Tasks 1 through 12. Tasks 1–9's code cannot be reviewed
task-by-task because it arrived in one squashed commit.

The document convention carried forward — Objective, Target files, Required
implementation, Verification — is taken from the surviving Tasks 1–9 specs and
from this pack's own prompts. No historical rule beyond that convention is
reconstructed or inferred here.

## The paid surface is one alias

Every server-side route lookup in the module resolves the single alias
`custom_webform_handlers.distance_provider`, declared at
`custom_webform_handlers.services.yml:14` and today pointing at
`custom_webform_handlers.google_routes_distance_provider`. It has exactly four
consumers, read directly from source because service wiring is outside Graphify's
coverage:

| Consumer | How it obtains the provider |
| --- | --- |
| `DistanceProviderRouteEstimator` | `services.yml:111` constructor argument |
| `DispatchCheckoutStarter` | `services.yml:432` constructor argument |
| `DistancePreviewController` | `DistancePreviewController.php:32`, `$container->get(...)` in `create()` |
| `AddressDistanceHandler` | `AddressDistanceHandler.php:214`, `$container->get(...)` in `create()` |

Two questions exist on that surface: `calculate()` (time-independent) and
`calculateScheduled()` (traffic-aware, departure-stamped). Switching the alias
therefore moves all four consumers at once — which is why the policy document
splits purpose into separately configured instances rather than reassigning one
alias.

## Call-site table

"Reachable provider" is what the call site can bill **today**, before any part of
this pack is implemented.

| # | Call site | Trigger | Server seam | Reachable provider today | Repeats identical facts? |
| --- | --- | --- | --- | --- | --- |
| 1 | Distance preview | `distance_calculator.js` `scheduleRefresh()` L609 → `refreshPreview()` L524 → `postPreview()` L458, settle `REFRESH_DELAY_MS = 400` | POST `custom-webform-handlers/distance-preview`, `routing.yml:43`, `_access: 'TRUE'` + CSRF header → `DistancePreviewController.php:68` `calculate()` | **Google Routes, `TRAFFIC_UNAWARE`** | **Yes** — one request per 400 ms settle while the address is still uncommitted |
| 2 | Address autocomplete / Places | Maps JS bootstrap `loadMaps()` L251 with `libraries=places`; browser key published at `AddressDistanceHandler.php:872`, gated at `:854` | none — browser to Google directly | **Google Places / Maps JS — a different billed product from Routes** | **Yes** — per typing session and per page load. Replacing Routes removes none of it |
| 3 | Route map rendering | `route-map-preview.js`, waits `MAPS_WAIT_MS = 10000` for the calculator's bootstrap | none — draws `google.maps.Polyline` from the server's `encoded_polyline` | **Google Maps JS map load only**; no routing service is called | No — one map load per page |
| 4 | Submission validation and save | Webform `preSave` | `AddressDistanceHandler.php:2135` `calculate()`; failure routes to `markManualReview()` | **Google Routes, `TRAFFIC_UNAWARE`** | Warm read if row 1 already asked the same pair; otherwise the first paid call |
| 5 | Quote recalculation | checkout start | server: `DispatchCheckoutStarter.php:372` `resolveQuote()` → `calculate()`. Browser: `quote-summary-sidebar.js` contains **0** `fetch(`/`XMLHttpRequest` — render-only | **Google Routes, `TRAFFIC_UNAWARE`** (server seam only) | Recomputed once per checkout start; its own docblock calls this "a warm read that reproduces preSave's own result" |
| 6 | Slot offers and polling | `dispatch_slots.js` `requestOffers()`; `scheduleReoffer()` at `REOFFER_DEBOUNCE_MS = 450` on any job change; idle `OFFER_POLL_MS = 45000` | POST `dispatch/offer/{webform}`, `routing.yml:68`, `_access: 'TRUE'` + CSRF → `DispatchBooking::offer()` L85 → `DispatchPlanner::offerSlots()` → `routeLeg()` ×3 at L178, L187, L199 | **Google Routes, `TRAFFIC_AWARE_OPTIMAL`** | **Yes, twice over** — 450 ms re-offer on every job edit and a 45 s idle poll |
| 7 | Private checkout hold and confirmation | hold: anonymous POST. confirm: payment success | `DispatchBooking::hold()` L220 → L240 `createHold()` → `planFor()` L307 → `routeLeg()` ×5 at L834, L861, L887, L898, L923. Confirm: `DispatchCheckoutManager.php:362` → `confirmReservation()` → `planFor()` L702 | **Google Routes, `TRAFFIC_AWARE_OPTIMAL`** | Per attempt. **`renew()` L256 and `release()` L295 reach no route lookup at all** — store and session owner only |
| 8 | Staff Replan | staff action | POST `admin/structure/webform/dispatch-planner/replan`, `routing.yml:379`, `_permission: 'manage dispatch schedule'` + CSRF → `DispatchOperations::replan()` L247 → `planFor()` L484; also `DispatchReplanForm.php:198` | **Google Routes, `TRAFFIC_AWARE_OPTIMAL`** | Per staff attempt; permission-gated |
| 9 | Scheduled warming | `DispatchReservationConfirmedEvent` → `EnqueueRouteWarmAction.php:70` → Advanced Queue `custom_webform_handlers_maintenance` | `DispatchRouteWarmJob.php:134` → `ScheduledRouteWarmer::warm()` L97 `estimate()` | **Google Routes, `TRAFFIC_AWARE_OPTIMAL`** | **Yes** — one enqueue per confirmed Reservation for the same Resource and day |

### Where identical facts get asked twice

**One funnel for every dispatch leg.** Rows 6, 7, 8 and 9 all pass through
`DispatchPlanner::routeLeg()` at `DispatchPlanner.php:1271`, which memoises on
`$origin . "\0" . $destination . "\0" . $departureAt` and refuses a non-traffic-aware
leg while `routing.require_traffic_aware` holds. Its docblock states the reason:
*"That refusal is here, at the one seam every route lookup passes through, so
Offer, Hold, Confirm and Replan cannot diverge on it."* That memo is
**invocation-local**. Two Offer requests one second apart share nothing but the
provider's own cache, so cross-request reuse is entirely a function of
`route_scheduled_cache_ttl`, which is 900 seconds.

**Warming re-asks rather than reusing.** `automated_cron.settings` `interval` reads
`10800` on this site, so an ECA-enqueued warm job can wait up to three hours for a
cron run. The scheduled cache TTL is 900 seconds. By the time the job executes,
the entries it would have reused have expired, and `max_retries: 1` with
`retry_delay: 900` re-asks again after the same window. `DispatchRouteWarmJob`'s
docblock says *"Replay is free… asks the provider about the same pairs, and writes
the same cache entries."* That is true of the ledger and **false of billing** once
the 900-second window has lapsed.

**The preview asks about an address nobody has committed to.** Row 1 fires while
the visitor is still typing. `lastRequestKey` suppresses only a byte-identical
origin/destination/service triple, so every corrected keystroke past the 400 ms
settle is a new paid `TRAFFIC_UNAWARE` request for a job that may never be
submitted.

**Two anonymous endpoints already reach the paid traffic-aware chain.** Of the
four `_access: 'TRUE'` dispatch endpoints, `offer` (`routing.yml:68`) and `hold`
(`routing.yml:79`) both arrive at `DispatchPlanner`. `renew` (`routing.yml:90`) and
`release` (`routing.yml:101`) do not. This is the live condition item 8 of the
prompt exists to constrain, and it is constrained in
[the policy document](task20-provider-policy.md#final-check-eligibility).

**The volatile cache.** `sites/default/settings.ddev.redis.php` sets
`$settings['cache']['default'] = 'cache.backend.redis'`. The `@cache.default` bin
that `GoogleRoutesDistanceProvider` injects is therefore Redis-backed and
volatile: a Redis restart empties every route entry and every address pair is paid
for again. A financial ledger must not be stored there — recorded against Task 20F.

**A negative fact worth stating.** `src/Controller/ReservationController.php`
injects no distance provider, route estimator or planner; its apparent matches are
prose comments. The fixed-slot reservation workflow cannot reach the paid surface
at all, so it is absent from the table by evidence, not by omission.

## Cost attribution: pending

No Google Cloud billing connector, account export, or SKU report is available in
this repository or through any configured tool. Per the prompt, attribution is
**pending** and this document proceeds on code evidence.

What the code establishes is that at least three separately billed Google
activities are live simultaneously: **Routes** `computeRoutes` (rows 1, 4–9),
**Places autocomplete** sessions (row 2), and **Maps JS map loads** (rows 2, 3).
Replacing the routing provider addresses the first family only. Attributing the
whole charge to typing would be wrong in both directions — typing drives row 1
*and* row 2, and rows 6–9 bill without anybody typing.

**No requests-per-address-pair figure and no cache-miss rate are recorded here,
because no measurement of either exists.** No savings percentage is stated. The
prompt permits recording such numbers only where measurements already exist; they
do not.

## Runtime and account inventory

| Item | State established | Owning task if unresolved |
| --- | --- | --- |
| OSRM endpoint | **None.** Zero references to OSRM in the active module's PHP, JS, YAML or config. No host, no service, no config key | 20E |
| OSRM data coverage / version | **Unknown** — no extract, no MLD pipeline, nothing to version | 20E |
| OSRM memory / disk capacity | **Not established.** No sizing fact exists because no deployment exists | 20E |
| Geocoder provider plugins | `geocoder` module **enabled**; 32 provider *plugins* ship in `modules/contrib/geocoder/src/Plugin/Geocoder/Provider/`. But **0 `geocoder-php/*` provider SDKs are installed** and **0 `geocoder_provider` config entities exist** — `config` holds `geocoder.settings` only. `geocoder_field`, `geocoder_address` and `geocoder_geofield` are not enabled | **20D — decisive** |
| Mapbox credentials / plan | **None.** No token, no account, no plan, no remaining-allowance fact | 20E, 20F |
| Google API restrictions | Account-side; **not inspectable** from here. Presence only: the server key resolves through `getenv` at `sites/default/settings.php:910`, backed by a non-empty `CUSTOM_WEBFORM_HANDLERS_SERVER_API_KEY` in `.ddev/.env.web`; a non-empty browser key is stored in active config. No value was printed | 20H |
| Redis cache bins | `redis` enabled; `cache.default` is `cache.backend.redis`, host `redis`, port 6379, with lock and flood container YAMLs. **Volatile** | 20F |
| Worker configuration | Advanced Queue, durable `database` backend: `custom_webform_handlers_dispatch` (lease 300 s, cron budget 90 s) and `custom_webform_handlers_maintenance` (lease 900 s, budget 180 s). `automated_cron` interval `10800`. `custom_webform_handlers_cron()` at `custom_webform_handlers.module:85` performs only the Review sweep and the popularity recount — **it does no route warming** | — |

### The Geocoder blocker, stated plainly

The pack's preferred reuse path — resolve addresses through an installed Drupal
Geocoder provider rather than writing a second geocoding HTTP client — **is not
usable today**. The plugins are present; the PHP SDKs they wrap are not, and no
provider entity is configured. Installing one is a Composer change, which this
pack forbids. Task 20D therefore cannot begin until either a provider SDK is
installed under explicit authority or a different approved coordinate source is
chosen. This is a blocker, not a waiver.

### One security observation, out of scope to fix here

The stale parallel docroot copy `web/sites/default/settings.php:907` still assigns
the server key as an **inline literal**, where the active
`sites/default/settings.php:910` uses `getenv`. `/web/` is ignored at
`.gitignore:86` and `git ls-files web/` returns 0 files, so nothing is published —
but the literal is on disk. Outside this task's write scope; recorded for the
owner of that cleanup, not acted on.

## Reuse decisions

| Facility | Decision | Basis |
| --- | --- | --- |
| `DistanceProviderInterface::calculate(string, string): DistanceResult` | **Reuse unchanged.** New adapters implement it; no signature change | Read from source; four consumers enumerated above |
| `ScheduledRouteProviderInterface` (departure-stamped) | **Reuse unchanged.** Mapbox and the chain implement it; OSRM must not claim it | Graphify `explain GoogleRoutesDistanceProvider` shows both EXTRACTED implements edges |
| `DistanceResult` | **Reuse.** Note it rejects a non-positive distance in its constructor, which is what keeps "no provider error produces a zero-mile route" true today | Read from source |
| Drupal Geocoder | **Chosen as the coordinate source, unusable today.** See the blocker above | `geocoder.settings` is the only config entity; 0 provider SDKs |
| `@cache.default`, `@flood`, core `LockBackendInterface` | **Reuse all three.** Present in installed 10.6.16. Do not add a parallel cache or rate limiter | Installed core source; `preview_rate_limit: 30` already uses flood |
| `DispatchPlanner::routeLeg()` | **Reuse as the single traffic gate.** Add no second route seam beside it | `DispatchPlanner.php:1271`, all 8 dispatch call sites traced to it |
| `QuoteCalculator` / `QuoteSnapshot` | **Reuse.** Quote authority and `rate_version: '2026-08-production-v1'` stay where they are; routing changes distance inputs, never the formula | `config/install/custom_webform_handlers.settings.yml` |
| Advanced Queue `database` backend | **Reuse `custom_webform_handlers_maintenance`.** Add no new queue backend | `config/optional`, root `docs/operations/dispatch-workers.md` |
| Durable budget schema | **NOT VERIFIED — no assumption may be treated as verified.** DBHub is absent, so no table shape, index, or column type has been checked against the live database | Item 9's own condition; blocker against 20F |

## Blockers, filed against their owning task

| Blocker | Owner | Effect if unresolved |
| --- | --- | --- |
| GitNexus MCP `LadybugDB unavailable … checkpoint is in progress` | 20A's own impact requirement, carried into 20C and 20G | Blast-radius evidence for the PHP refactor is unverified. Graphify plus enumerated call sites stand in and are labelled as such |
| DBHub MCP absent from the tool list | **20F** | No durable budget-ledger schema may be proposed as verified. 20F cannot close its schema gate |
| Zero Geocoder provider SDKs, zero provider entities | **20D** | The approved coordinate source does not exist yet; 20D cannot start |
| No OSRM endpoint, data, version or capacity | **20E** | The primary provider does not exist; ordinary quotes cannot leave Google |
| No Mapbox account, token or plan | **20E**, allocation in **20F** | The fallback and the traffic path do not exist. Allocation stays 0 |
| Google key restrictions and billing SKU breakdown unavailable | **20H** | Cost attribution stays pending; activation readiness cannot be asserted |
| Mapbox Directions persistent-caching permission unresolved | **20F** | The Mapbox route cache stays disabled |
| Redis-backed `cache.default` is volatile | **20F** | A budget ledger placed there would reset on restart |

## Task 20B readiness

**Ready, for the frontend-only suppression change, with one stated limit.**

What passes: row 1's full trigger chain is graph-established —
`scheduleRefresh()` L609 `[indirect_call]` → `refreshPreview()` L524 `[calls]` →
`postPreview()` L458 — and the settle constant, the `lastRequestKey` dedupe, the
Places-path divergence, the 450 ms re-offer and the 45 s idle poll are all read at
named lines. The two files 20B must touch are identified, and their attachment
points are read from `custom_webform_handlers.libraries.yml` and
`AddressDistanceHandler.php:854-875`.

What does not pass: **GitNexus contributed nothing.** 20B's readiness rests on
Graphify's JS coverage plus direct reads, which is sufficient for a change confined
to two JavaScript files and no PHP contract. It would not be sufficient for 20C or
20G, and those remain blocked on the impact evidence above.

20B must not be extended into provider work. Until the migration is activated it
reduces the number of existing Google calls; it does not remove Google from
previews, and no percentage may be claimed for it.

