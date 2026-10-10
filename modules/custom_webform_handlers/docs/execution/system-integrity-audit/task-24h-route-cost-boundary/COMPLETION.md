# Task 24H — completion report

Closes **E13** (dispatch scheduling paths still networking outside 21A's customer
guard) and **E14** (the preview endpoint silently falling through to a live
`calculate()` when a "quote-only" request's fact was missing / expired / invalid),
and adds the **operation identity, deduplication, and replay** layer the review
required on top of the first E14 correction. Task 21A is preserved unchanged.

**Status in one line:** *the anonymous-owner isolation defect is fixed and verified;
all six recorded ownership checks pass on ppu-qa with actual provider-invocation
counting (not calculated_at) and validating JSON parsing; the coordination/transport
layer and true multi-process single-flight remain verified by bounded offline probes;
the broad browser outbound-spy sweep across pricing/date/slot/polling/wizard is the
one outstanding item and is BLOCKED this session (no Selenium permitted; the preview
browser tool is unavailable) — so Task 24H is NOT fully accepted and 24B is NOT
started.*

## 2026-09-19 (later) — anonymous-owner isolation defect: fixed and verified

The prior "10/10 only by priming" acceptance hid, rather than fixed, a real
first-request owner defect (see `A3-DIAGNOSIS.md`). On the first POST from a
cookieless anonymous client, `RouteRequestOwner::token()` derived the owner from an
unstarted session whose id was `''`, yielding `sess:sha256('')` for **every** such
client — a shared bucket, not per-session isolation. This is now corrected.

**The fix (application code, `modules/custom_webform_handlers/`):**

| File | Change |
| --- | --- |
| `src/Distance/RouteRequestOwner.php` | The anonymous identity is now a random 32-byte secret minted once and persisted in the session under `custom_webform_handlers_route_owner`, then hashed into the `sess:<64-hex>` token — never a hash of the (empty-on-first-request) PHP session id. Writing the secret is what makes Drupal persist the lazy session and set its cookie, so the same owner is read back on later requests carrying that cookie, and two independent visitors mint two independent secrets. Mirrors the proven `DispatchOwner` seam. |
| `src/Distance/RouteRequestOwnerException.php` (**new**) | Thrown when no owner can be established (no request context, or a request with no session). |
| `src/Controller/DistancePreviewController.php` | `preview()` now catches `RouteRequestOwnerException` and returns a typed `routing_busy` (503) **before any provider call**, so an unownable request fails safe rather than serving under a shared/empty placeholder. |

Authenticated (`uid:<n>`) ownership, replay/retention, single-flight, and Task 21A's
zero-cost guard are all unchanged. The routing-based **priming POST was removed** from
`http-session-acceptance.sh`; the cold path is now exercised directly.

**Measurement correction (item 2).** "No recompute" is no longer inferred from an
unchanged `calculated_at` — `time()` has one-second precision, so two genuine calls
in one second are indistinguishable by timestamp. A temporary, QA-gated
`[T24H-DIAG]` counter in `FixedDevelopmentRouteProvider::calculate()` (gated on the
`qa` database name so it could never write on the default `db` site) counted **actual
provider invocations**; the acceptance reads it as a delta at each checkpoint. The
counter was removed once, then **re-added** (same QA-gated block) to serve the
outstanding human-operated browser acceptance window, which needs server-side
invocation measurement (`BROWSER-ACCEPTANCE-CHECKLIST.md`). It will be removed for
final tree state once the browser evidence is recorded. The acceptance scripts degrade
gracefully (delta checks report INFO when the counter is absent).

**The six ownership checks — all pass on ppu-qa (QA-isolated `qa` DB, `development_fixed`):**

| # | Check | Evidence | Result |
| --- | --- | --- | --- |
| 1 | Cold anonymous request → unique persisted owner + exactly one calculation | HTTP A1: 200, success, valid reference, **calc delta = 1** | PASS |
| 2 | Same-session replay + quote-only → zero additional calculations | HTTP A2 (quote-only) **delta 0**; A3 (same op id replay) **delta 0** | PASS |
| 3 | Two cold clients, identical inputs + operation id → distinct owners | HTTP C1: own reference ≠ A's, **delta = 1** (computed own route, did not replay A's record) | PASS |
| 4 | Cross-owner reference (both directions) → refused, zero calculations | HTTP B1 (`refresh_required` 409, delta 0); C2 mutual (`refresh_required` both ways, delta 0) | PASS |
| 5 | Unavailable session → typed refusal before any calculation | Controller probe (real `preview()`, session-less request): **503 `routing_busy`, calc delta 0** | PASS |
| 6 | Form-page GET (`/form/hg`) → zero calculations | Controller probe: `/form/hg` 200, **calc delta 0** | PASS |

**Counting acceptance of record (instrumentation present):** HTTP session acceptance
**29/29**, total provider calculations across the whole run **exactly 2** (A1 + C1,
matching items 1 and 3); controller probe (checks 5 & 6) **6/6**; coordination/
transport/single-flight offline probe **44/44**. A separate re-run with the counter
absent recorded **22 functional checks passed and the 7 counting checks skipped**
(delta checks downgraded to INFO because no counter was present); that run confirms the
functional/JSON/HTTP-status assertions still hold when instrumentation is absent, and
is **not** a renewed proof of zero provider calls — the zero-call evidence is the
counting run above.

**Design confirmed by source, not assumed (`RouteFactStore.php`):** `store()` mints
`reference = bin2hex(random_bytes(32))`, so every reference is a distinct random
64-hex string (two owners never share a reference string), and cross-owner isolation
is enforced by PrivateTempStore partitioning by owner — a different owner reads a
different facts map, so `retrieve()` misses and returns `refresh_required`. The
mandatory guarantee (cross-owner refusal) is asserted directly, not via string
inequality. The response extractor was replaced with a validating `python3` JSON
parser (HTTP status, object shape, and field types checked; missing/malformed data
fails explicitly).

**Outstanding, BLOCKED (item 4).** The broad browser outbound-spy sweep — confirming
that pricing, date, slot, polling, and wizard-rebuild interactions trigger no new
route calculations, with development-mode outbound provider requests staying zero —
is not complete. The only browser driver available on this project is DDEV Selenium,
which this session's policy forbids, and the preview browser tool is unavailable in
this session. Pricing-reprice (the `quote_only` path) IS covered by HTTP check A2
(zero calc); the remaining browser-driven interactions cannot be exercised with the
permitted, available tooling. This is a genuine tooling blocker, reported not hidden.

**Handover for the browser window.** `BROWSER-ACCEPTANCE-CHECKLIST.md` (this
directory) is a step-by-step, human-operated sweep of `https://ppu-qa.ddev.site/form/hg`
covering typing, committed addresses, duplicate selection/blur, pricing changes,
date/slot selection, polling, and wizard rebuilds. It pairs each step with a
server-side counter read (the re-added `[T24H-DIAG]` counter — Browser Network
inspection alone cannot prove a server-side call did not happen), expecting exactly one
calculation for the whole window (the single committed new pair). `development_fixed`
is confirmed active on QA, so external provider requests stay blocked. Once those
numbers are recorded and pass, the checklist's own closeout steps remove the
instrumentation, delete the QA state key, and close 24H. **Until then, Task 24H is not
fully accepted and Task 24B stays gated.**

**This revision (2026-09-19) corrects the prior report against a re-run of every
probe on the actual working tree.** The earlier report's "22/22" controller figure
and its "concurrency is modelled, not raced" boundary were both stale: the final
controller matrix is **40/40**, and a real cross-process race (Probe C, below) now
replaces the modelled concurrency. Two defects were found and fixed while verifying:

- **A stale fail-open docblock on `RouteFactStore::acquire()`.** The prose still
  described the old behaviour ("a caller that gets FALSE still writes") while the
  body already **fails closed** (throws `RouteFactStoreException`, map untouched).
  The docblock was corrected to match the code; behaviour was already right.
- **A ledger key-length overflow that would 500 every completed operation.**
  `RouteOperationLedger::key()`/`identityKey()` concatenated raw sha256 hashes,
  producing `name` values up to 132 chars against a `varchar(128)` column — an
  `SQLSTATE[22001]` on the first `complete()` after billing. Fixed by folding
  owner+id into one sha256 digest (`'op:'`/`'id:'` + 64-hex = 67–68 chars). This
  also proves the prior "44/44" figure predated the identity-pointer feature and had
  never actually been run against the current code; it genuinely passes 44/44 now.

This report supersedes both the pre-correction narrative and the interim
"implementation-complete, server-side verified" verdict. Two defects the review
found in the first E14 fix, and one identity/dedup gap it flagged, are all now
addressed:

1. **Intent was inferred, not declared.** Presence of a field is client-controlled,
   so "quote-only" could be toggled by adding or omitting a key. Intent is now an
   **explicit, named operation** with refusal as the only default.
2. **The reusable fact was browser-held and forgeable.** `route_fingerprint` was
   only `sha256(normalized ordered addresses)`. The authoritative fact now lives on
   the **server**, named by an opaque reference; the browser can at most name a fact
   the server already holds for this owner.
3. **A pair fingerprint + mode was not a full identity, and nothing deduplicated a
   billed route.** Identity now also binds **purpose** and **resolved policy version**
   (profile + dataset version + answering endpoint), caps fact retention at the
   route's own validity, protects the per-owner map against lost concurrent writes,
   and a new **operation ledger** makes `route_preview` idempotent (single-flight +
   replay), with owner scope stated and tested rather than assumed.

## What changed (all under `modules/custom_webform_handlers/`)

| File | Change |
| --- | --- |
| `src/Distance/RouteRequestOwner.php` (**new**) | The one owner deriver shared by the fact store and the ledger. `uid:<n>` for an authenticated user (stable across sessions/devices); `sess:<sha256>` for an anonymous session (started here when absent). `isAuthenticated()` is recorded so scope is a checkable property. |
| `src/Distance/RouteOperationLedger.php` (**new**) | Idempotency + replay store over `keyvalue.expirable` (DB-backed, native per-entry expiry — no migration/schema/infra). Distinguishes pending / completed / failed / unknown / conflict; owner-scoped keys; persists a fact snapshot for replay independent of the fact store. |
| `src/Distance/RouteFactStore.php` | Identity extended to **purpose** + **policy version** (`hash_equals`), retention capped at the route's own `expiresAt()`, and the per-owner read-modify-write serialized under a distinct, leaf, fact-store-only lock. Ctor now `(tempstore.private, config.factory, datetime.time, logger, lock, RouteRequestOwner)`. |
| `src/Controller/DistancePreviewController.php` | `resolvePreview()` makes `route_preview` idempotent: validate `operation_id`, fast-path replay, owner+identity single-flight lock, claim → provider call (strictly between acquire/release, no DB txn / reservation lock) → **persist completion before releasing** → reference. `replay()` renders completed / failed / conflict / unknown outcomes with no provider call. New codes: `routing_busy` (503), `operation_conflict` (409), `operation_unknown` (409), `invalid_operation` (400). quote-only unchanged except purpose is now passed to `retrieve()`. |
| `js/distance_calculator.js` | Mints a per-pair `operation_id` (crypto UUID / random hex / time-random fallback), sends it with every `route_preview`, reuses it for retries of the same journey, and discards it on `refresh_required` so a re-commit buys a genuinely new route. |
| `custom_webform_handlers.services.yml` | Registered `route_request_owner` and `route_operation_ledger`; extended `route_fact_store` with `@lock` + the owner service. |
| `custom_webform_handlers.libraries.yml` | `distance_calculator` 1.1.16 → 1.1.17. |
| `tests/src/Unit/DistancePreviewControllerTest.php` | Constructor calls updated to the new arities (controller = 11 args, fact store = 6). **Structural / constructor-compatibility only**; the 413 oversized-body guard returns before any collaborator is touched, and PHPUnit was not run per policy. |

The E13 chain/estimator guards (`CoordinateRouteProviderBase`,
`ChainDistanceProvider`, `DistanceProviderRouteEstimator`, and the resolver
injection into both providers, all three chains, and both estimators) are retained
from the earlier E13 pass, unchanged this round.

## Item 1 — explicit operation, corrected completion status

`operation()` accepts only a string in `{route_preview, quote_only}`; anything else
returns `NULL` → `invalid_operation` (HTTP 400) before any provider. There is no
default operation. A `quote_only` request stays quote-only even with no cached
fields — it can only `retrieve()` or refuse.

The status is **implemented and verified offline; browser acceptance pending**. The
offline probes exercise the controller decision paths (Probe B 40/40), the
coordination/transport seams (Probe A 44/44), and real cross-process single-flight
(Probe C 3/3) directly; they do not stand in for the full browser outbound-spy
acceptance or for cross-session HTTP ownership, which remain pending under this
session's no-browser-automation policy.

## Item 2 — full route-fact identity and validity

`RouteFactStore::retrieve()` now refuses (returns `NULL`, never a fetch signal)
unless **all** of the following match the stored record: the ordered-pair
fingerprint (`hash_equals`), the routing **mode**, the **purpose**, the resolved
**policy version** (`hash_equals`; folds profile + dataset version + answering
endpoint + purpose), and the fact's own **expiry**. An incompatible fact is rejected,
not reinterpreted.

- **Retention never extends the route's validity.** `store()` caps `expires_at` at
  the earlier of the configured TTL and the provider-declared `expiresAt()`. A NULL
  provider expiry (fixed-development, or an uncached live answer) leaves the TTL as
  the only ceiling.
- **Concurrent per-owner writes cannot lose a fact.** The read-prune-write of the
  bounded map runs under a distinct per-owner lock (`custom_webform_handlers.route_facts:<hash(owner)>`),
  a leaf lock taken last and released at once, never around a provider call or a
  transaction. If the lock backend is unobtainable it **fails closed**: `acquire()`
  throws `RouteFactStoreException` after `LOCK_ATTEMPTS` waits, before the map is read
  or written, so the caller renders `routing_busy` (503) and the shared map is left
  untouched — a lock that cannot be taken is never a silent unlocked read-modify-write
  that could drop a concurrent request's fact.
- **Owner scope is stated and checkable, not assumed.** `RouteRequestOwner` owns an
  authenticated request by `uid` (stable across sessions) and an anonymous one by a
  session hash. The probe demonstrates that two anonymous sessions and two
  authenticated users are distinct owners; the report does **not** claim "all
  sessions isolated" beyond what is demonstrated (see Item 4 boundary).

## Item 3 — deduplication and replay (implemented)

`RouteOperationLedger` (over the existing `keyvalue.expirable` service — no
migration, no new schema, no infrastructure) binds each `operation_id` to the owner
and the canonical identity `sha256(owner ∥ pair ∥ mode ∥ purpose ∥ policy_version)`:

- **Single-flight.** A first operation takes an owner+identity Redis lock; a
  concurrent duplicate waits and replays the leader's result rather than starting a
  second calculation. The provider call happens strictly between lock acquire and
  release, with **no DB transaction and no reservation lock** in scope.
- **Persist-before-respond.** `complete()` writes the outcome (with its own fact
  snapshot) before the lock is released, so a replay after a lost response is already
  durable and needs no second provider call.
- **Reuse with different inputs is a conflict.** The same id presented for a
  different identity returns `operation_conflict` (409), never the first journey's
  result.
- **Four outcomes are distinguished.** completed (replay result) / failed (replay
  the classified refusal) / pending (wait on the lock) / **unknown** (an abandoned
  pending claim past its lease → refuse, never auto-authorize a new billed request).
- **Eviction independence.** The ledger is a separate store from the fact map, so
  evicting the oldest fact cannot erase an operation's replay protection.
- **Frontend.** The browser mints one `operation_id` per committed pair, reuses it
  for retries of the same journey, and drops it only on `refresh_required` so the
  required re-commit buys a genuinely new route.

## Item 4 — bounded offline verification

Two complementary, this-session probes; controller-spy coverage is kept separate
from adapter/coordination coverage as the directive requires.

**A. Coordination + transport probe** (`route-dedup-offline-probe.php`, this
directory) drives the **real** `RouteOperationLedger` over the real
`keyvalue.expirable`, the **real** `RouteFactStore` over the real PrivateTempStore
and lock, and `RouteRequestOwner`, with a movable clock and a call-counting provider.
**44/44 PASS.** Highlights:

| Group | Result |
| --- | --- |
| Transport seam (GuzzleHttp\ClientInterface) | a direct Guzzle request is **counted (1, then 2) and blocked before any network I/O**; the real `GoogleRoutesDistanceProvider` injects that exact seam (reflection-verified) |
| Dev-fixed facade | routes to the fixed provider (**counted separately = 1**), **0 outbound** transport attempts; container facade stays `development_fixed` |
| Quote-only / fact store | valid `retrieve()` returns the fact with **0 provider calls**; mismatched pair / mode / purpose / non-string / **expired** all refuse, still 0 calls |
| Replay (R1) | completed op replays from snapshot, **no second provider call** |
| Single-flight (R2) | leader claims; follower's `claimPending` is refused and sees pending; after completion the follower replays — **one calculation shared** |
| Conflict (R3) | same id, different identity → `conflict` |
| Unknown (R4) | pending past its lease → `unknown` (no auto-authorize) |
| Failure (R5) | failed op replays the classified code, **no provider call** |
| Eviction independence (R6) | operation replay survives overflowing/evicting the fact map |
| Owner scope (O1–O3) | operation invisible to a different anonymous owner and a different authenticated owner; `uid:` vs `sess:<64-hex>` token derivation; two distinct sessions → two owners |

**Totals: 44/44. provider calculate() calls = 22 (all model/route_preview, never a
replay). transport outbound attempts = 2, all blocked before network. mode =
`development_fixed`.**

**B. Controller-path probe** (`route-controller-offline-probe.php`, this directory,
**40/40 PASS**) drives the **real** `DistancePreviewController::preview()` end-to-end
with a call-counting provider and the real ledger / fact store / owner / resolver.
This is the final matrix and supersedes the earlier "22/22" figure. It covers: the
operation gate and `operation_id` validation; quote-only refusals; first-calc
reference mint; operation-id replay; identity-level dedup; `operation_conflict`;
failure replay; oversized-body **413** and rate-limit **429**; the **fail-closed lock**
path (a lock backend that refuses raises `RouteFactStoreException` → `routing_busy`
with the shared map untouched); **persist-before-publish** (a fact-store failure after
`complete()` leaves the completion durable so a retry replays without a second call);
and transport interception through the **real** `GoogleRoutesDistanceProvider` driven
against a spy `GuzzleHttp\Client` (counted, then blocked before network I/O).
**Counts: provider calculate() = 4, real-adapter+control outbound = 2 (both blocked),
mode = `development_fixed`.**

**C. True multi-process race** (`route-race-orchestrator.sh` + `route-race-worker.php`,
this directory, **3/3 PASS**) spawns independent `drush php:script` **OS processes**
that share the DB-backed ledger (`keyvalue.expirable`) and the Redis single-flight
lock, and counts the true cross-process provider-call total via a shared
`keyvalue.expirable` collection (one unique key per genuine `calculate()`). This
**replaces the earlier modelled concurrency**:

| Scenario | 4/3 concurrent workers | Provider calls | Result |
| --- | --- | --- | --- |
| Same owner, **same** operation_id | 4-way | **1** | single-flight collapses the duplicates |
| Same owner, **different** operation_ids, same journey | 4-way | **1** | identity-level reuse collapses distinct ids |
| **Different** journeys, unique ids | 3-way | **3** | distinct identities never share |

A fixture defect surfaced and was fixed during this run: the fake result carried a
year-2001 `expires_at`, and the ledger caps a completed record's TTL at
`(expires_at − now)`, so a past expiry collapsed the dedup window to one second — long
enough for a single in-process replay but not for workers `ddev exec` cold-starts
seconds apart, which made scenario 1 intermittently bill 3. Live-relative timestamps
(`now`, `now + 900`) fixed the fixture; the controller was never at fault. With the
fixture corrected, the race is deterministic 3/3 across repeated runs.

**Remaining boundary.** True **cross-session PrivateTempStore** isolation across real
HTTP sessions, and the full **browser outbound-spy** acceptance, remain exercisable
only under the pending browser pass (see Item 5 / Remaining). The real provider could
not be driven to its socket because the server credential is intentionally absent, so
the counted-and-blocked transport control uses the same `GuzzleHttp\Client` class and
handler stack the real adapter injects (reflection-verified).

## Item 5 — requirement → evidence → status map

| Task 24H requirement | Evidence | Status |
| --- | --- | --- |
| Explicit operation; missing/invalid never routes | `operation()` closed-set gate; Probe B | **Implemented + verified** |
| Quote-only stays quote-only with no cached fields | Probe A (0 calls), Probe B | **Implemented + verified** |
| Invalid quote-only facts → typed error, no provider | Probe A refusals | **Implemented + verified** |
| Server-owned fact, opaque reference | `RouteFactStore` + PrivateTempStore | **Implemented + verified (single process)** |
| Full identity: pair + mode + purpose + policy version | `retrieve()` `hash_equals` checks; Probe A | **Implemented + verified** |
| Retention ≤ route's own validity | `cappedExpiry()`; Probe A expiry case | **Implemented + verified** |
| Per-owner map safe under concurrent RMW | per-owner leaf lock in `store()`; fail-closed `acquire()`; Probe B lock case | **Implemented + verified** |
| Owner vs anonymous-session scope stated & shown | `RouteRequestOwner`; Probe A O1–O3 | **Implemented + verified (single process; cross-session HTTP pending)** |
| Dedup: concurrent same-identity share one call | ledger single-flight; Probe A R2; **Probe C (real multi-process, 3/3)** | **Implemented + verified (real cross-process race)** |
| Operation-ID bound to owner + identity; reuse w/ diff inputs → conflict | `routeIdentity()` + ledger; Probe A R3 | **Implemented + verified** |
| Persist completion before responding → replay after lost response | `complete()` before lock release; Probe A R1 | **Implemented + verified** |
| Distinguish pending/completed/failed/unknown | ledger states; Probe A R1/R2/R4/R5 | **Implemented + verified** |
| Timeout/abandoned op never auto-authorizes a billed request | `unknown` refusal; Probe A R4 | **Implemented + verified** |
| Evicting a fact never erases op-replay protection | separate stores; Probe A R6 | **Implemented + verified** |
| Routing-specific coordination, not reservation/calendar locks; no provider work in a txn | distinct lock names; call outside any txn | **Implemented (by construction)** |
| No migration/infra outside authority | `keyvalue.expirable` + `lock` are existing services | **Satisfied (none required)** |
| Transport interception at the real seam + positive control | Probe A transport group (counted+blocked) | **Implemented + verified** |
| Dev routing: fixed-provider counted separately, 0 outbound | Probe A dev-fixed group | **Implemented + verified** |
| Library version bumped | `1.1.17` | **Implemented** |
| Anonymous first-request owner is unique + persisted (not `sess:sha256('')`) | RouteRequestOwner fix; HTTP A1/C1 counter deltas; controller probe | **Fixed + verified** |
| Six recorded ownership checks (cold/replay/two-client/cross-ref/no-session/form-GET) | HTTP acceptance 29/29 + controller probe 6/6, actual calc counting | **Verified** |
| Full outbound-spy **browser** acceptance across all callers (pricing/date/slot/polling/wizard) | — | **BLOCKED (no Selenium this session; preview browser unavailable)** |
| True cross-process contention | Probe C (independent `drush` processes, shared Redis lock + DB ledger, 3/3) | **Implemented + verified** |
| True cross-session PrivateTempStore ownership across HTTP | — | **Pending (needs browser pass)** |

## Graph evidence (scoped to the active module)

`graphify update .` (AST-only, no API cost) → exit 0; graph rebuilt this session to
**7070 nodes / 15548 edges / 536 communities**. The new active-source nodes resolve to
`src/Distance/RouteOperationLedger.php`, `src/Distance/RouteRequestOwner.php`, and the
controller's `resolvePreview` / `replay` / `routeIdentity` / `snapshot` members under
`src/Controller/DistancePreviewController.php`; `RouteFactStore` ctor at L107.
`GoogleRoutesDistanceProvider` and `RoutingModeEntryProvider` also resolve to the
`task-24a-recovery/{baseline,candidate}` copies on disk — a known parallel-tree
indexing artifact; the runtime autoloader binds the **active** `src/...` classes, as
the 44/44 probe run over those namespaces demonstrates. A responding graph does not
by itself establish freshness — this graph was rebuilt against the edited source
this session. YAML service wiring is outside the AST graph's coverage and was
confirmed by direct inspection and by `drush cr` + a container instantiation probe
(all three new/extended services resolve to their classes; `isValidId` behaves).

## Remaining / pending (task not yet fully accepted)

- **The anonymous-owner isolation defect is fixed and its six recorded ownership
  checks pass** on ppu-qa with actual provider-invocation counting and validating
  JSON parsing (see the 2026-09-19 section above). Cross-session HTTP ownership — the
  gap Probe A could only show single-process — is now demonstrated over real HTTP
  sessions by the cold-jar acceptance (A1/A3 same owner across requests; B1/C1/C2
  distinct owners).
- **BLOCKED — broad outbound-spy / denial-boundary browser sweep** across typing,
  commit, duplicate selection/blur, date/time, polling, wizard rebuild, preSave,
  checkout, staff actions, and warming, counting provider-dispatch counts. The only
  browser driver on this project is DDEV Selenium, forbidden this session; the preview
  browser tool is unavailable here. The customer pricing-reprice path (`quote_only`)
  is covered by HTTP check A2 (zero calc); the remaining browser-driven interactions
  cannot be exercised with permitted, available tooling.
- No migration, config import, infrastructure lifecycle change, or commit was
  performed. `routing_mode` active config is unchanged; schema stays 10046. The
  temporary `[T24H-DIAG]` counter was removed and its QA state key deleted; the QA
  database is the only site any runtime verification touched.

**Verdict:** The reopened Task 24H defect — anonymous first-request owner isolation —
is **fixed and verified**: all six recorded ownership checks pass on ppu-qa with
actual calc counting (HTTP 29/29, total exactly 2 calculations; controller probe 6/6;
offline probe 44/44), a cold cookieless request now mints a unique persisted owner and
computes exactly once, replays and cross-owner references incur zero calculations, and
an unownable request fails safe with `routing_busy` before any provider call. Task 24H
is **NOT fully accepted**: the broad browser outbound-spy sweep (item 4) is blocked by
the no-Selenium policy and unavailable preview browser, so **Task 24B is not started**.
No commit was made.
