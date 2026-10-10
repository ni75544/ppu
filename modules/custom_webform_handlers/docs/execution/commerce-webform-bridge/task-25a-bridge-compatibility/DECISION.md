# Task 25A — Configuration-only bridge compatibility decision

**Verdict: B — CUSTOM INTEGRATION REQUIRED / OUTSIDE SCOPE.**

Date of run: 2026-10-07 (America/New_York).
Executor: Claude Code.
Task scope: read-only application/configuration/database inspection and this documentation.
No application PHP/JS/YAML, Composer, schema, active-configuration, or data edit was made.
No install, enablement, migration, submission, fixture, payment, browser probe, paid route
call, cache rebuild, container change, or test suite was run. No Task 25B-25D action was
executed.

The decisive question this task asked — *can maintained contrib, starting with Commerce
Webform Order, connect the existing trusted Webform quote to the existing Commerce checkout
using supported configuration alone* — is answered **no**, at the first mandatory transition.
The first unsupported transition is **submission save → order creation**: the candidate
creates the Commerce order through its own cart path, outside the existing single order
writer and outside the transaction that also claims capacity and records the Checkout
Attempt. Closing that gap requires code — a hook, a service decorator, or an event
subscriber — which `RULES.md` forbids. `RULES.md` is explicit for this case: if a needed
transition requires changing protected code or adding custom glue, return verdict B and do
not install the module to force progress.

---

## 1. Bounded baseline (Step 1)

### 1.1 Target identity

| Fact | Value | How established |
| --- | --- | --- |
| Repository | `C:/Users/mo737/ppu` | environment |
| Branch | `codex-experiments` | `git status` |
| DDEV project | `ppu` (single project, not started/stopped by this task) | `docker ps`; `.ddev/config.yaml` |
| Active site / URI | `https://ppu.ddev.site` (site dir `sites/default`) | Drush `core:status`, `sites.php` |
| Second site / URI | `https://ppu-qa.ddev.site` (site dir `sites/qa`) | `sites.php`; `.ddev/config.yaml` `additional_hostnames` |
| Active module path | `modules/custom_webform_handlers/` | `CLAUDE.md`; `custom_webform_handlers.info.yml` |
| Drupal core | 10.6.16 | `composer.lock`; Drush `core:status` (both sites) |
| `drupal/commerce` | 3.3.8 | `composer.lock` |
| `drupal/webform` | 6.3.0 | `composer.lock` |
| `drupal/address` | 2.0.4 | `composer.lock` |
| `drupal/advancedqueue` | 1.6.0 | `composer.lock` |
| Module schema version | `ppu` 10046 / `ppu-qa` 10049 | `key_value` collection `system.schema`, runtime read |
| Commerce schema versions | `commerce_order` 10303, `commerce_checkout` 8000, `commerce_payment` 10303, `webform` 8649 | runtime read |
| Active handler on the Courier form | `address_distance_handler` (single handler, weight 0) on webform `hg` | `config:get webform.webform.hg handlers` |
| Active checkout flow | `dispatch` (`plugin: dispatch_flow`) enabled; binds order type `dispatch` | `commerce_checkout.commerce_checkout_flow.dispatch.yml`; runtime read of flow and order-type entities |
| Payment gateways | `ppu` 0 gateways (1 orphan `commerce_payment_method`); `ppu-qa` 0 gateways; `commerce_payment_example` module enabled | runtime read |

The earlier audit leads (default schema 10046, QA 10049, pending attempts, non-cart draft
orders) were **reverified only where this decision needed them**, not re-audited. Both
schema numbers reproduce.

### 1.2 Dirty-work baseline (preserved)

*(Corrected 2026-10-08, R1-7: the original said "28 modified files … plus `.gitignore`", i.e. 29
tracked, which is correct; an interim report stated "30", which was a counting artifact from
including the `rtk` banner line — the reconciled figure is 29.)*

`git status` at task start and end: **29 tracked modifications** — 28 files under
`modules/custom_webform_handlers/` plus `.gitignore` — and **16 untracked entries**: the four
`docs/execution/**` packs (including this one), `.claude/`, untracked Task 24B/24C/24H source
(`src/Checkout/AcceptedJob.php`, `src/Dispatch/Admission/`, `src/Dispatch/PreparedHold.php`,
eight `src/Distance/Route*` / `Routing*` files). **Nothing was staged, committed, stashed,
reset, or discarded.** This task wrote only the two documentation files named in
`PROMPT_SPEC.md`: this `DECISION.md` and a status line in `README.md`. Equal counts before and
after establish that **no tracked file was added or removed** and that the modified-file *set* is
unchanged; they do **not** by themselves prove byte-for-byte equality of the pre-existing
dirty work, which was never claimed and is not re-derived here. The one check that was run over
the tracked tree — `git diff --check -- modules/custom_webform_handlers` — exited 0 (§ closeout).

### 1.3 Evidence systems used, and their limits

| System | Used for | Result / limit |
| --- | --- | --- |
| Graphify (`/graphify`, `rtk graphify query/explain`) | Orientation on the checkout handoff chain before raw reads | Query-first satisfied. Graph covers PHP under `modules/custom_webform_handlers/**`. **It does not cover YAML or Markdown** — so service wiring, routes, and the checkout-flow config below are read from source directly and are **not** graph-established. |
| GitNexus MCP | Independent architecture/impact view of `DispatchOrderFactory` | `context` and `impact(upstream)` returned **0 resolved callers** with `epistemic: exact` and an explicit note that absence of edges is not evidence of disuse. The graph does not model the container wiring, so it could not corroborate the single-writer claim; that claim rests on a text search with a positive control (§2.2) and on the service definitions read directly. |
| Context7 MCP | Current Drupal Commerce behavior for order placement, order refresh, and non-product order items | Used. Commerce docs confirm order-item unit price is recalculated during order refresh **only from a purchasable entity**, which corroborates the module's own design note. **Webform is not indexed in Context7**, so the candidate's Webform-handler behavior was established from the candidate's own source (below). |
| DBHub MCP | — | **Not used.** It is suspended by project memory (`dbhub-ro saturates QA connections`). Read-only database inspection was done through the approved `docker exec … drush sql:query` path instead. This is the documented, already-authorized substitute for read-only schema/value discovery, not a silent swap. |
| Matt Pocock skills | `writing-for-agents` (this document), `codebase-design` (interface/seam reading) | Read and applied. Neither is treated as authority to add code. |
| `rtk` proxy | Every shell command | Prefixed as required. |

Every shell command in this task ran through `rtk` and, for Drupal CLI, through the approved
container path (`docker exec ddev-ppu-web … drush --uri=…`), never `ddev exec`, so no
container start/recreate was triggered. The containers were already running.

### 1.4 Isolation limitations (material to 25B, recorded now)

*(Corrected 2026-10-08, R1-6. The original text asserted that the two sites share "the
`keyvalue.expirable` route-operation ledger, and every queue/worker", generalizing from
shared containers to shared *storage*. That is not established and is corrected below into
what was actually verified, item by item.)*

`ppu-qa.ddev.site` is **not installation isolation** — this is the genuine, load-bearing risk
and it stands. `sites.php` maps only the hostname; `.ddev/config.yaml` declares one project with
`additional_hostnames: ["ppu-qa"]`; `docker ps` shows one `ddev-ppu-web` and one `ddev-ppu-db`.
**Code, Composer `vendor/`, and the containers are shared.** Enabling a contributed module on QA
therefore places its **code** on the shared codebase and changes enablement state for a
codebase both sites run — a separate database alone is not installation isolation, exactly as
`RULES.md` warns.

Within that shared infrastructure, however, each site's *storage targets* are resolved
**per site** and must be verified individually rather than assumed shared. What was verified:

| Storage target | Shared or per-site? | Evidence |
| --- | --- | --- |
| Code tree, `vendor/`, containers | **Shared** | `docker ps` (one web, one db); `.ddev/config.yaml`; `sites.php` |
| Database | **Per-site** | `sites/qa/settings.php` sets `$databases['default']['default']['database'] = 'qa'` |
| Public / private files | **Per-site** | `settings.php`: `file_public_path = sites/qa/files`, `file_private_path = sites/qa/private` |
| Config sync directory | **Per-site** | `settings.php`: `config_sync_directory = sites/qa/files/config/sync` |
| Cache key space (Redis) | Redis **service shared**, but **key space separated** | QA `settings.php` sets `$settings['cache_prefix'] = 'qa_'` after the Redis include, with the stated purpose that "a cache or flood key written by one site would be read by the other" |
| `key_value_expire` (the route-operation ledger's backend) | **Per-site (DB-backed, different rows)** | row counts differ: `ppu` 132 vs `qa` 232 |
| `advancedqueue` | **Per-site (DB-backed, different rows)** | row counts differ: `ppu` 24 vs `qa` 18 |
| Enabled modules | **Same set on both today** | `core.extension` read on both: identical for every commerce/webform/eca/custom module checked |
| Hash salt | **Shared** (DDEV-generated, via the shared `settings.ddev.php`) | `settings.php` requires `../default/settings.ddev.php` |
| Routes API key | **Shared** (environment, same `.ddev/.env.web`) | `settings.php` reads `getenv('CUSTOM_WEBFORM_HANDLERS_SERVER_API_KEY')` |

**Conclusion.** The correct statement is: the **codebase and containers are shared**, while the
**database, files, cache key space, and DB-backed ledgers/queues are separated per site** — each
verified above except where noted. The original blanket claim that "every queue/worker" and "the
ledger" are shared was an over-generalization from shared containers and is retracted. A row
count difference establishes the two tables are distinct *data*; it does not by itself prove the
tables are in different schemas (they are, per the database line). One item is left
**UNVERIFIED**: whether any process (e.g. a cron worker or a shared Redis-backed lock) operates
across both sites was not determined, so "no worker is shared" is not claimed either.

Any 25B environment proposal must still treat the shared codebase/vendor/containers as the
material risk, rather than assume QA is isolated.

### 1.5 Observations that qualify or block (recorded, not fixed)

1. **The active site's compiled Drupal service container is stale relative to the uncommitted
   source.** *(Corrected 2026-10-08, R1-8: this is a **Drupal service-container** fact, recorded
   separately from Docker infrastructure. It is not a Docker/container-image staleness and must
   not be read as one.)* Instantiating the `hg` handler on `ppu` throws
   `ArgumentCountError: DispatchCheckoutManager::__construct(): 15 passed … 16 expected`.
   On disk, both `custom_webform_handlers.services.yml` (16 `- ` arguments for
   `dispatch_checkout_manager`) and `DispatchCheckoutManager.php` (16 promoted constructor
   parameters) carry the Task 24C `@database` argument; the **compiled Drupal container on `ppu`
   holds 15**. The QA site loads the handler without error. This is a **pre-existing dirty-work
   condition** — the uncommitted Task 24C-8C transaction-ownership wiring has not been rebuilt
   into `ppu`'s compiled container — and it is **not caused by this task**. It was **not**
   repaired: `drush cr` is forbidden in 25A, and rebuilding is additionally unsafe here because
   it would **activate pending uncommitted code against the current schema** (the container
   would pick up the 16-arg service and the Task 24B/24C source that the live DB schema may not
   yet match), which is a state change only the owner should authorize. Note the two facts are
   distinct: the **Docker** containers are healthy and running; it is the **Drupal** compiled
   service container inside the web container that is stale.
2. **No payment gateway exists on either site**, matching the recorded Task 6 teardown. The
   `commerce_payment_example` module is enabled on `ppu` but no gateway instance is
   configured. *(Corrected 2026-10-08, R1-5: "no money was captured" is **not** inferred from the
   zero local `commerce_payment` rows. A local payment row count is only the local ledger; an
   external gateway could hold an authorization or capture with no local row — e.g. the
   `ReconciliationRequired` state exists precisely for "authorized, not captured locally". What
   is established is narrower and only local: **0 gateway instances configured on either site, 0
   local `commerce_payment` rows on either site.**)* This is **not** a 25A blocker (25A is
   read-only and mints no payment), but a configured sandbox gateway is a prerequisite for any
   25C acceptance that exercises the payment pane, and it must not be "fixed" by installing a
   gateway without separate approval (`RULES.md`).
3. **ECA is bounded.** Two `eca` models exist on `ppu`
   (`custom_webform_handlers_popularity`, `custom_webform_handlers_route_warm`), both
   maintenance-only, and `config/optional/` ships only those two plus queue and geocoder
   config. **No reaction rule creates an order.** This rules ECA out as a second order writer
   and as a configuration lever for this connection.
4. **Self-reference hazard in this document.** Earlier audit prose in this document quotes
   the misspelled *"acceptedQuote"* only as a candidate-report phrasing; the module's actual
   type is `QuoteSnapshot`. The precise name matters and is used below.

---

## 2. The mandatory existing handoff (Step 2)

`RULES.md` requires naming class/method and current source line, input, record produced, next
consumer, and whether the transition is mandatory. All line numbers are from the working tree
at the time of this run and are **read from source**, not graph-established.

### 2.1 Ordered trace

```
saved submission
  → trusted accepted quote            (server-only: RouteFactStore reuse + QuoteCalculator)
  → checkout entry point              DispatchCheckoutStarter::start()            L101
  → capacity claim                    DispatchPlanner::claimPreparedCheckoutHold() (inside guard txn)
  → Commerce order/item               DispatchOrderFactory::create()             L67  (order L87, item L216)
  → CheckoutAttempt                   CheckoutAttemptStore::open()               L289
  → private Hold receipt              DispatchPlanner::publishCheckoutHoldReceipt() (post-commit)
  → Commerce checkout/payment         DispatchCheckoutFlow::onStepChange()      L50
                                      + DispatchPaymentProcess::settleAuthorizedCapacity() L142
  → capacity confirmation             DispatchCheckoutManager::confirmPaid()     L192
```

| # | Transition | Class / method (source) | Input | Record / receipt produced | Next consumer | Mandatory? |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | submission saved → quote | `AddressDistanceHandler` (Webform handler, `hg`) → `DispatchCheckoutStarter::start()` [src/Checkout/DispatchCheckoutStarter.php:101] | saved `WebformSubmissionInterface` | none itself; delegates | `resolveQuote()` → `open()` | **Yes**, for the redirect |
| 2 | quote rebuild + lock | `DispatchCheckoutStarter::resolveQuote()` [DispatchCheckoutStarter.php:422] | submission + settings + `RouteFactStore::retrieve()` [Distance/RouteFactStore.php] + `QuoteCalculator::calculate()` | a `QuoteSnapshot` (`::fromQuoteResult()` / `::fromApprovedReview()`, private ctor) | `manager->start()` | **Yes** — no snapshot, no checkout |
| 3 | accepted-job identity | `AcceptedJob::fromSubmissionData()` [Checkout/AcceptedJob.php], carried into the snapshot | submission data + endpoints | job-facts digest + facts JSON (sealed in the snapshot and the attempt row) | `confirmPaid()` at confirmation | **Yes** (Task 24B/E09) |
| 4 | checkout entry | `DispatchCheckoutStarter::open()` [DispatchCheckoutStarter.php:322] | submission, Slot, contact, snapshot | a `CheckoutOutcome`; session cart id via `cartSession->addCartId()` [:337]; `Url::fromRoute('commerce_checkout.form', …)` [:340] | browser redirect | **Yes** |
| 5 | ownership guard | `DispatchCheckoutManager::start()` [Commerce/DispatchCheckoutManager.php:113] | saved submission + Slot + contact + snapshot | refuses if a transaction is already open [:120] | `bindCheckoutHold()` | **Yes** |
| 6 | gate + facts + idempotency | manager `start()` → `BookingWorkflowGate::allows()` [:134], `DispatchRequestFactory::fromSubmission()` [:146], `existingAttempt()` [:297] | submission, Slot, snapshot | refuse / resume-existing-order | `bindCheckoutHold()` | **Yes** |
| 7 | capacity claim | `DispatchPlanner::claimPreparedCheckoutHold()` [Dispatch/DispatchPlanner.php], called at manager [:384], inside the Resource Calendar Guard transaction | `PreparedHold` prepared **before** the txn [:169] | a `Hold` row (guard transaction) | order creation | **Yes** — capacity first |
| 8 | **order + item creation** | `DispatchOrderFactory::create()` [Commerce/DispatchOrderFactory.php:67]; order `create([...])` [:87], item `create([...])` [:216], `setUnitPrice(..., TRUE)` [:225] | submission, Slot, contact, **`QuoteSnapshot`** | a draft `dispatch` order + one `dispatch_service` item (non-product); order data `dispatch_submission_id` [:105] | CheckoutAttempt | **Yes** — the **single** order writer |
| 9 | lifecycle row | `CheckoutAttemptStore::open()` [Checkout/CheckoutAttemptStore.php:289], called at manager [:407] | a `CheckoutAttempt` built by `CheckoutAttempt::opening()` [:408] | one `custom_webform_handlers_checkout_attempt` row, state `pending`, with the locked snapshot + accepted-job identity | `confirmPaid()`, `DispatchConfirmation` | **Yes** |
| 10 | commit + receipt | manager verifies an ordinary root commit [:534] then `publishCheckoutHoldReceipt()` [:558] | the `Hold` | session receipt (raw Hold token recovered from session, not the row) | payment pane | **Yes** |
| 11 | payment authorization | `DispatchPaymentProcess` (Pane subclass of Commerce `PaymentProcess`) `buildPaneForm()` [Plugin/Commerce/CheckoutPane/DispatchPaymentProcess.php:95] → parent authorises with `capture: false` (flow config) | card at the payment step | a `commerce_payment` in state `authorization` | `settleAuthorizedCapacity()` | **Yes** |
| 12 | placement gate | `DispatchCheckoutFlow::onStepChange()` [Plugin/Commerce/CheckoutFlow/DispatchCheckoutFlow.php:50] → `settleCapacityBeforePlacement()` [:86] | the advancing flow | the order is **not placed** if capacity is refused | parent `onStepChange()` | **Yes** |
| 13 | capacity confirmation | `DispatchPaymentProcess::settleAuthorizedCapacity()` [:142] → `confirmCapacity()` [:185] → `confirmPaid()` [DispatchCheckoutManager.php:192] | a `PaidDispatchReference::create($orderId, $submissionId, $now, $evidence)` [:189] | a `Reservation` (guard txn) then `CheckoutState::Paid` / `ReconciliationRequired`; capture after commit | capture / confirmation pane | **Yes** |

### 2.2 What the trace establishes

- **Exactly one order-creation path.** A text search for `commerce_order'->create` and
  `commerce_order_item'->create` across the module returns **only**
  `DispatchOrderFactory.php:87` and `:216`. A positive control (`CommerceStoreInstaller.php:116`
  creates a *Commerce **store***, not an order) confirms the search behaves — a search that
  finds the store creator but not a hidden order creator is behaving. No `addToCart`,
  `CartProvider`, `cart_provider`, or `carts.cart` call exists anywhere in the module. The one
  order writer is `DispatchOrderFactory::create()`, and it is reached only from
  `DispatchCheckoutManager::bindCheckoutHold()`.
- **Order creation is inside one caller-owned writer transaction with capacity and lifecycle.**
  *(Corrected 2026-10-08, R1-3; the original text claimed "they commit or roll back together",
  which overstates the source.)* `bindCheckoutHold()` [DispatchCheckoutManager.php:347] opens
  one caller-owned writer transaction on the shared `@database` connection [:369], and writes
  the guarded Hold claim [:384], the draft order/item [:396], and the Checkout Attempt [:407]
  **inside it**, so those three writes share a transaction and are intended to commit or roll
  back as one unit. What the source *establishes* is the transaction structure, the ordering,
  and the explicit completion checks (the commit is verified before the receipt is published,
  [:534]→[:558], and four rollback outcomes are distinguished, [:579-624]). What the source
  does **not** establish is the runtime guarantee: the class's own docblocks record
  real-database atomicity as **PENDING** and safe disposal after a *failed* rollback as
  **BLOCKED** ([:483-556], and the referenced 24C-8C-R2 decision record). The intended
  guarantee is that the three writes commit or roll back together; whether they do, under
  failure and concurrency, is **REQUIRES_VERIFICATION** and is not claimed here as
  established.
- **Price provenance is server-owned by construction; a private constructor is not by itself the
  proof.** *(Corrected 2026-10-08, R1-4.)* `QuoteSnapshot`'s constructor is **private**
  (QuoteSnapshot.php:175) and its only doors are `fromQuoteResult()` (a server `QuoteResult`
  from `QuoteCalculator`), `fromApprovedReview()` (a Review Request row a staff form wrote), and
  `fromStored()` (this module's own sealed row); `build()` additionally refuses any new snapshot
  without a valid accepted-job identity [:591]. The **load-bearing** fact is therefore not
  privacy of the constructor — a private constructor only restricts *who may call it*, not what
  the caller passes — but that each surviving door derives its figure from a server-side source
  (`QuoteResult` from stored route facts, or a staff-written Review Request row) and that the
  value is then bound to an accepted-job **identity** recomputed and compared at confirmation
  [confirmPaid → `confirmCheckoutReservation` :780]. The amount is copied onto the item as an
  overridden unit price, and `dispatch_service` has no purchasable entity, so order refresh
  cannot recalculate it (order-item-type config; corroborated by Commerce docs, §1.3). The
  identity-binding is what makes "the price the customer accepted" provable; the sealed payload
  digest (QuoteSnapshot.php:892) is the tamper check. Whether a specific candidate value could
  reach an order amount through any un-reasoned path is a per-candidate question, answered in
  §3.2 against the candidate's own code, not assumed from the constructor's privacy.
- **Confirmation hard-requires the attempt row.** `confirmPaid()` reads
  `attempts->loadByOrder($paid->orderId)` first [DispatchCheckoutManager.php:193] and refuses
  with `InvalidRequest` if the row is missing or names a different submission [:194-199]. It
  also compares the order's **computed** `getTotalPrice()` against the accepted snapshot
  [`:733` → `orderPayableMatches()` :1041] and re-proves the accepted-job identity under the
  guard. There is no path to a Reservation that does not pass through this row and this
  writer.
- **The confirmation seam is pane-driven and synchronous, not event-driven.**
  `DispatchCheckoutManager` "subscribes to no Commerce event" (class docblock); the only
  `EventSubscriber` in the module is `ReviewTrackerCacheSubscriber` (cache headers), and there
  is no `OrderEvents` / `commerce_order.*` subscriber. `confirmPaid()` has exactly one caller,
  `DispatchPaymentProcess` [:199]. The whole point of the custom `DispatchCheckoutFlow` is
  that Commerce places and emails the order *inside* the same call that authorises the card,
  so the placement must be gated on the synchronous capacity confirmation — which is why a
  stock panes-only flow is not equivalent (dispatch.yml disables `payment_process` and
  `completion_message` and replaces them).

---

## 3. Candidate release (Step 3) — inspected without installing

Candidate: **Commerce Webform Order** (`commerce_webform_order`).
Primary source: <https://www.drupal.org/project/commerce_webform_order>.

### 3.0 Source pinning and provenance

*(Added 2026-10-08, R1-2. The original run read the moving `3.x` branch; this correction pins the
behavioral claims to the exact stable release and separates declared compatibility from verified
resolution.)*

| Item | Value |
| --- | --- |
| Pinned release | **`3.0.6`** (git tag `3.0.6`), released 2025-11-05 07:45 UTC |
| Release page | <https://www.drupal.org/project/commerce_webform_order/releases/3.0.6> |
| Archive (tar.gz) | `commerce_webform_order-3.0.6.tar.gz`, 96.17 KB, under `https://ftp.drupal.org/files/projects/` |
| tar.gz SHA-1 | `a28439e7d50a44222a9cb8b0cac25584a84780a3` |
| tar.gz SHA-256 | `ca47eca52e86b5b1d14e111252d75c7b21e36e341c4e67a54fba565f30e8ba6c` |
| Archive (zip) | `commerce_webform_order-3.0.6.zip`, 224.98 KB |
| zip SHA-256 | `a548ed5a99643931ab3e42ee6c238f79ec4193db51cdfb928b2a6126d5fbc2aa` |
| Handler source read at tag | `src/Plugin/WebformHandler/CommerceWebformOrderHandler.php` @ `3.0.6` |
| `composer.json` read at tag | `composer.json` @ `3.0.6` |
| Read method | Remote HTTP GET to stdout / WebFetch only. **Nothing downloaded to disk, no archive extracted, no package code executed, no dependency changed.** |

The security-relevant checksums above are the ones Drupal.org publishes; the archives themselves
were **not** fetched and re-hashed in this run, so the checksum values are transcribed from the
release page rather than independently recomputed. That is recorded as a transcription, not a
verified byte-match.

**Declared dependency constraints (from `composer.json` @ `3.0.6`, quoted fields):** `php >=8.1`;
`drupal/core ^9 || ^10 || ^11`; `drupal/commerce ^2.19 || ^3.0`;
`drupal/commerce_purchasable_entity ^1.0`; `drupal/webform ^6.1`. `minimum-stability: dev`.
`.info.yml` declares module dependencies `commerce`, `commerce_cart`, `commerce_checkout`,
`commerce_order`, `commerce_price`, `commerce_store`, `webform`.

**Declared vs verified — kept strictly apart.** The two paragraphs the original draft merged are
now separate claims:

- **Declared compatibility (established):** the module *declares* support for this stack
  (core 10.6.16 ∈ `^10`; commerce 3.3.8 ∈ `^3.0`; webform 6.3.0 ∈ `^6.1`; php ≥ 8.1). This is a
  statement about the module's own declared constraints.
- **Verified Composer resolution (NOT established):** no `composer require`, `composer why-not`,
  `composer update --dry-run`, or solver run was executed, because that is a dependency change
  and is outside 25A's scope. So the module is **not** claimed here to install successfully or to
  resolve cleanly against the current lockfile. `commerce_purchasable_entity ^1.0` — a **new
  transitive dependency** — is not in the current `composer.json`/`composer.lock`, and whether
  the resolver would accept it, and what else it would drag in, is **REQUIRES_VERIFICATION**.
  This distinction does not change the verdict: the contract failures in §3.2 are independent of
  whether the package resolves.

### 3.1 Version and declared compatibility

| Fact | Value |
| --- | --- |
| Latest stable | `3.0.6`, released 2025-11-05 (project page / releases page) |
| Declared constraints (`composer.json` @ `3.0.6`) | `php >=8.1`; `drupal/core ^9 \|\| ^10 \|\| ^11`; `drupal/commerce ^2.19 \|\| ^3.0`; `drupal/commerce_purchasable_entity ^1.0`; `drupal/webform ^6.1`; `minimum-stability: dev` |
| Module dependencies (`.info.yml`) | `commerce`, `commerce_cart`, `commerce_checkout`, `commerce_order`, `commerce_price`, `commerce_store`, `webform` |
| Install effects | `hook_install()` only (no `hook_schema`, no custom tables); adds one **base field** `commerce_webform_order_submission` (entity_reference → `webform_submission`) to `commerce_order_item`; ships a checkout pane plugin `commerce_webform_order_payment_process`; `post_update` migrations exist |
| New transitive dependency | **`commerce_purchasable_entity`** — not currently present in this project; resolution unverified (§3.0) |
| Security policy | Stable releases are covered by the security advisory policy (project page shield) |
| Maintenance | 581 reported sites; the `3.x`-dev line advanced after 3.0.6 (a price-path change), so `3.x`-dev is **not** what is assessed here — the assessment is pinned to `3.0.6` |

**Package-level compatibility is a *declared* property only.** The declared constraints are
satisfied by this stack's installed versions, but no resolution was actually run (§3.0). That is
a statement about the module's manifest, not about the application contract, and `RULES.md`
requires the two be kept apart.

### 3.2 Configuration-to-contract matrix

Each row cites the candidate's mechanism and the existing contract it must preserve. "Config"
means a site-admin setting; "code" means a PHP hook, subscriber, decorator, or plugin.

| # | Existing contract (from §2) | What the candidate does | Config-only? | Verdict |
| --- | --- | --- | --- | --- |
| 1 | The order is created by `DispatchOrderFactory::create()` — the **only** order writer — inside `DispatchCheckoutManager`'s caller-owned transaction [DispatchCheckoutManager.php:369/396] | Creates the order itself in its Webform handler `postSave()`, via `cartProvider->getCart()/createCart()` then `cartManager->addOrderItem()` | No setting chooses a different creator; the sole alter point hands you the already-built order+item | **Unsupported — second writer** |
| 2 | A `CheckoutAttempt` row is opened for every payable order [CheckoutAttemptStore.php:289] | Creates **no** attempt row (the concept is not in the module) | No | **Unsupported** |
| 3 | `confirmPaid()` refuses any order without a matching attempt row [DispatchCheckoutManager.php:193-199] | — (consequence of #2: the order could never be confirmed) | No | **Unsupported** |
| 4 | Price is bound to an **immutable accepted-quote identity** (`QuoteSnapshot.bindingIdentity()` = `snapshotId:jobFactsDigest`), stored in the CheckoutAttempt row and re-proved at confirmation | Unit price from `order_item.amount` / `.currency`, which resolve through `replaceConfiguration()` → either `:input[name="…"]` values read from `$submission->getData()` **or** token replacement, then `new Price((string)$amount, $currency)` and `setUnitPrice($price, TRUE)` (@ 3.0.6 `postSave()`) | *Which* element supplies the amount is config; making that element the accepted-quote **identity** is code (the selector names a submission element or a token, never the attempt row) | **Unsupported — see the three-way distinction below** |
| 5 | If `amount` is empty/non-numeric the candidate falls back to `$purchasable_entity->getPrice()` (@ 3.0.6 `prepareData()`) | Not the accepted quote — a product **catalogue** price | — | **Unsupported** |
| 6 | A private Hold is claimed before the order, and its receipt is published after commit | Takes no Hold; never touches the Resource Calendar Guard or the reservation store | No | **Unsupported** |
| 7 | The order/item types are `dispatch` / `dispatch_service`, the latter with **no purchasable entity** (`purchasableEntityType: ''`) | The config form marks `purchasable_entity_type` **required**, steering to a variation; runtime tolerates a variation-less item but the UI does not target this shape | No | **Incompatible-by-configuration** |
| 8 | Order placed only after capacity is confirmed (`DispatchCheckoutFlow::onStepChange()` [:50]) | Redirects to `commerce_checkout.form` with `checkout.redirect` defaulting TRUE; order placed by stock Commerce flow semantics | To inherit the dispatch flow the *order type* must be `dispatch` — but #1/#2/#6 still fail | **Insufficient even if routed** |
| 9 | Accepted-job identity bound at confirmation (Task 24B/E09) | Not present | No | **Unsupported** |
| 10 | Confirmation pane proves a Reservation before claiming a booking [DispatchConfirmation.php:135] | No attempt → pane would show "This booking was not completed" | — | **Consistent only with failure** |

**The candidate cannot preserve the checkout-attempt contract, the private Hold receipt, or
the payment/placement contract using stock configuration.** Making any of
this fit requires code: at minimum
`hook_commerce_webform_order_handler_postsave_alter()`, a decorated
`commerce_webform_order.order_item_repository`, or an order event subscriber — all of which
`RULES.md` names as "not a configuration-only solution."

### 3.2b The price question, stated in three parts

*(Added 2026-10-08, R1-4. The original §3.2 row 4 said the candidate "reads a
submission-supplied amount, which the price gate forbids". That collapses three materially
different things into one, and one of them is not a violation.)*

The correction begins with a fact about **this** module, not about the candidate. In the
existing flow a stored submission value is **not** automatically browser-authoritative:

- `AddressDistanceHandler::preSave()` [AddressDistanceHandler.php:2651] clears every
  computed field [:2670 → `clearComputedValues()` :2747], then **re-prices from the
  server-owned route fact** named by the persisted reference [:2704] and writes the result
  back into the submission: `quote_total_field` ← `$quote->totalDecimal()` [:2724],
  currency [:2725], status [:2726], rate version [:2727], breakdown [:2730]. Those values come
  from `QuoteCalculator`, not from the browser.
- `acceptedQuoteValue()` / `sanitizeAcceptedQuote()` treat the browser-posted carrier as a
  **transport only**: bounded JSON that must decode, and the class docblock is explicit that it
  "is never trusted as price authority regardless: the gate re-prices from the server fact and
  only equality permits the save" [:1930-1949].
- The authoritative check at save is the acceptance gate comparing the customer's accepted
  figure against the server re-price, not the posted value.

So the three cases must be distinguished, because only the third is a contract violation:

| Case | What it is | Is it a violation of this module's price gate? |
| --- | --- | --- |
| **(a) Untrusted browser amount** | A figure the client posts and the server accepts as the amount. | **Yes, forbidden.** No path in the existing flow does this. |
| **(b) Server-recomputed submission value** | A stored submission element whose value the *server wrote* (e.g. `final_calculated_quote`) and which `:input[…]` reads back at `postSave()` time. | **Not automatically a violation.** The value's authority depends on what wrote it and when — here `preSave()` wrote it from the server quote. This is the case the original text wrongly lumped in with (a). |
| **(c) Immutable accepted-quote identity** | The `QuoteSnapshot` + `AcceptedJob` binding (`snapshotId:jobFactsDigest`), sealed by a payload digest, stored in the CheckoutAttempt row, and re-proved under the guard at confirmation. | **This is the requirement.** It is what the existing flow actually relies on. |

**Where the candidate lands.** Commerce Webform Order's `replaceConfiguration()` resolves
`order_item.amount` / `.currency` from either an `:input[name="…"]` element value pulled out of
`$submission->getData()` or from **token replacement** (@ 3.0.6
`CommerceWebformOrderHandler::prepareData()` / `replaceConfiguration()` / `postSave()`). Against
the three cases:

- Case (b) is **reachable in principle** — an administrator could point the amount at a
  server-written element such as `final_calculated_quote`, and if that element's value were the
  server's own re-price then the amount would be server-derived rather than browser-supplied.
  This is why the original blanket claim was overstated, and it is corrected here.
- Case (c) is **not reachable**. The candidate has no notion of the accepted-quote **identity**:
  it reads a value out of the submission, at a moment it chooses, and applies
  `setUnitPrice($price, TRUE)`. Nothing binds that figure to the CheckoutAttempt row, nothing
  re-proves it at confirmation, and the identity binding that `confirmPaid()` depends on
  (`confirmCheckoutReservation(…, $accepted->jobFactsDigest, $accepted->bindingIdentity())`
  [DispatchCheckoutManager.php:780]) has no counterpart in the candidate. Even a
  correctly-pointed amount would be a bare number, not a provable identity.
- `setUnitPrice($price, TRUE)` sets the same "overridden" flag the existing flow uses, so the
  price would survive order refresh — but surviving refresh is not the same as being provably
  the accepted quote.

**Therefore the price finding is retained, on the identity, not on the input channel.** The
candidate cannot carry the immutable accepted-quote identity that the confirmation contract
requires. That is the disqualifying fact, and it does not depend on whether the amount element
happens to be server-written.

**REQUIRES_VERIFICATION (unresolved pricing mappings).** Two candidate-side details were not
established and are recorded as unverified rather than assumed:

1. Whether an `:input[name="…"]` selector can be aimed at the **canonical server-written**
   element in this installation's actual field configuration, and what the element's value is at
   the moment `postSave()` runs versus `preSave()`. The candidate's read happens after the
   submission is saved; whether Webform's stored value at that instant is the `preSave()`-written
   figure, a stale earlier value, or an empty string for a computed element is **not
   verified** — it depends on element configuration (computed/hidden elements, `#value`
   handling) that was not inspected.
2. Whether Webform's `tokenManager->replace()` on a numeric amount could resolve to a value the
   gate would not have produced (@ 3.0.6 replaces tokens over *every* config value, including
   literals). Not tested.

Neither point changes the verdict: (c) is unreachable either way.

### 3.2c Item-type support: configuration-UI restriction vs runtime support

*(Added 2026-10-08, R1-4, second half. The original §3.2 row 7 said the UI "does not target this
shape", which conflated two different questions.)*

These are separate claims and are labelled separately:

- **Configuration-UI restriction (established).** The candidate's configuration form marks
  `purchasable_entity_type` as required and its field elements are built with
  `'#other__type' => 'textfield'` (with an in-source `@todo` noting the real order-item field
  type should be used). So an administrator is **steered away** from a variation-less item:
  the shipped UI does not offer this project's `dispatch_service` shape as a first-class choice.
- **Runtime item support (established, and it is *not* a hard blocker).** The runtime tolerates
  a variation-less item: `$prepared_data['purchasable_entity']` defaults to `NULL`, and the item
  is attached only under `if (!empty($data['purchasable_entity']) || !empty($data['title']))`
  (@ 3.0.6 `postSave()`), so "a title + an explicit amount/currency" is a supported runtime path.
  Commerce itself supports it (an `OrderItem` of a custom type with no `purchased_entity` and an
  explicit `unit_price`), which is exactly what `dispatch_service` already is
  (`purchasableEntityType: ''`).
- **What was NOT verified.** Whether a fully variation-less `dispatch_service` item can be
  driven **through the candidate's UI and its add-to-cart path end to end** was not tested and is
  **REQUIRES_VERIFICATION**. Reading source is not running it.

So the accurate statement is: the *UI* steers away from this project's item shape; the
*runtime* does not forbid it. The item-type question is therefore **not** an independent reason
for the verdict — rows 1, 2, and 6 and the §3.2b identity gap are. It is demoted from
"Incompatible-by-configuration" to a configuration-ergonomics finding with a runtime question
outstanding.

### 3.3 Other maintained modules in the same space (named, not deep-dived)

- **Webform Product** (`webform_product`) — closest maintained competitor; ships its own
  order-item type and price options. Same structural issue: it creates the order and would be
  a second writer.
- **Commerce Webform** (`commerce_webform`) — drops selected products into a cart; not
  security-covered; 7.x-oriented.
- **Webform Commerce** (`webform_commerce`) — thin/abandoned bridge.

None of these is a configuration-only bridge to *this* module's quote/attempt/Hold contract,
because that contract is defined by this module's own classes, not by contrib.

---

## 4. Price and single-writer gate (Step 4)

- **Accepted-quote identity:** the existing path binds every order to a `QuoteSnapshot` +
  `AcceptedJob` identity (`bindingIdentity()` = `snapshotId:jobFactsDigest`), sealed by a payload
  digest, **stored in the CheckoutAttempt row**, and re-proved under the guard at confirmation
  (`confirmCheckoutReservation(…, $accepted->jobFactsDigest, $accepted->bindingIdentity())`
  [DispatchCheckoutManager.php:780]; `build()` refuses a new snapshot without a valid identity
  [:591]). *(Corrected 2026-10-08, R1-4: the original text said "the candidate's price comes from
  submitted form data", which is imprecise — see §3.2b. A stored submission value is not
  automatically browser-authoritative, and a private constructor alone is not proof of trusted
  input.)* Commerce Webform Order has **no counterpart to this identity** — it reads a value out
  of the submission at `postSave()` and applies `setUnitPrice($price, TRUE)`. **Fail**, on the
  identity, not on the input channel.
- **One order writer:** the existing module has exactly one order/item creator
  (`DispatchOrderFactory`), reached only from the manager's transaction. The candidate
  introduces an independent creator in a Webform `postSave()`, which would bypass the
  manager, the transaction, the attempt row, and the Hold. **Fail.**
- **No fabrication / no disabling a required handler:** the candidate would either leave
  orders with no attempt row (which `confirmPaid()` then refuses) or require fabricating
  attempts/receipts after the fact — both explicitly rejected by `PROMPT_SPEC.md` Step 4 and
  `RULES.md`. `address_distance_handler` cannot be disabled: it still owns quote validation,
  scheduling integration, and price integrity.

Per `PROMPT_SPEC.md` Step 4 and `RULES.md`, these two failures alone return **verdict B**, and
the module was **not installed to force progress**.

---

## 5. Protected data and future change manifest (Step 5)

### 5.1 Existing records identified (aggregates only; no customer detail, address, token, or credential)

*(Corrected and completed 2026-10-08, R1-5. The original table omitted the legacy
fixed-slot reservation table and the guard/blackout/notification tables, and inferred "no files"
solely from an empty `file_managed`. Both are corrected below. No record was deleted, released,
expired, repaired, or rewritten; all reads were bounded and read-only.)*

Active `ppu` (read-only):

| Family | Count / identity | State & relationship notes |
| --- | --- | --- |
| `webform_submission` | 45 | includes Courier submissions and older move submissions |
| `commerce_order` | 4 (ids 17–20) | all `type=dispatch`, **all `state=draft`**, `order_number` NULL |
| `commerce_order_item` | 4 | all `type=dispatch_service` — exactly one per order (join-verified: 1 item each) |
| `commerce_payment` | 0 | **local** ledger only — see the correction in §1.5.2; no external-money claim is made |
| `commerce_payment_method` | 1 | stored method present; **0 gateways** |
| `custom_webform_handlers_checkout_attempt` | 4 (ids 17–20) | **all `state=pending`**, one per order (join-verified), `dispatch_reservation_id` NULL; submissions 72, 80, 81, 82 |
| `custom_webform_handlers_dispatch_reservation` | 4 (ids 17–20) | resource `driver-01`, **all `state=held`**, `order_id` NULL, `price_snapshot_id` NULL, **`submission_id` NULL** (consistent with the recorded Task 24C finding that Holds carry `submission_id=NULL`) |
| **`custom_webform_handlers_reservation`** (legacy fixed-slot) | **11 (ids 25–35)** | **`confirmed` 4** (submissions 45, 46, 47, 48 — all still present, **0 orphans**), **`held` 1** (id 35), **`released` 6**. Confirmed rows carry a non-NULL `active_slot_key` (UNIQUE); released rows carry NULL `submission_id`/`active_slot_key`. **No `order_id` column exists on this table** — the legacy table predates the Dispatch order link. Read-only. |
| **`custom_webform_handlers_dispatch_guard`** | **14** | driver/day guard rows; **13 normal (`legacy_unplanned=0`)**, **1 legacy (`legacy_unplanned=1`, id 1, resource `default`, 2026-08-28 — the pre-Dispatch fixed-slot resource)**. This is the driver/day guard that protects a Resource's day. |
| **`custom_webform_handlers_dispatch_blackout`** | **0** | no blackout dates |
| **`custom_webform_handlers_dispatch_notification`** | **5** | notification log rows |
| **`custom_webform_handlers_dispatch_notify_claim`** | **2** | claim-ledger rows (one-insert send-once guard) |
| **`custom_webform_handlers_routing_budget` / `_usage`** | **2 / 3** | routing budget and usage rows |
| `custom_webform_handlers_review_request` | 3 | submissions 47 (`submitted`), 74 (`expired`), 85 (`submitted`) |
| `file_managed` | 0 | **corrected — see below** |

**Correction on files.** The original text read "`file_managed` 0 → no managed files" and
implied no files exist. That inference is wrong and is retracted. `file_managed` counts only
files **the file entity system tracks**; on-disk content exists independently:

- `sites/default/files`: **3 files** (including 2 under the hashed
  `config_2AIVzI5W…` config-sync directory)
- `sites/qa/files`: **2 files** (including 1 under `config/sync`)
- `sites/qa/private`: **1 file**

So the accurate statement is: **0 rows in `file_managed`, but non-zero on-disk files** (config
sync artifacts and per-site private/public files). Whether any of these are customer uploads
was **not** determined and is **REQUIRES_VERIFICATION**; they are not claimed to be, nor claimed
not to be.

**Correction on money.** The original text said "no money was captured". The established fact is
narrower and **local only**: **0 configured payment gateways and 0 local `commerce_payment`
rows** on both sites. An external gateway could hold an authorization or capture with no local
row — the `ReconciliationRequired` state exists for exactly that shape — so no claim about
external money is made.

The value/relationship pattern matters more than the counts: the four dispatch orders are
**abandoned drafts** (each with one item and one pending attempt, `order_number` NULL) with
**held** (expiring) Holds, and no local payments. These are not Reservations and must not be read
as bookings. The legacy fixed-slot table is separate and **does** contain four *confirmed*
bookings (submissions 45–48), all with their submissions intact — those are real historical
records and must be preserved.

QA `ppu-qa` (read-only): 5 submissions, 0 orders, 0 attempts, 6 dispatch reservations
(**`confirmed` 2, `released` 4**), 8 review requests, 0 payments, 0 gateways, 0 `file_managed`
rows. The QA **legacy** `custom_webform_handlers_reservation` table returned no state-grouped
rows (empty or unreported in that read — recorded as such, not as "empty"). The two sites' data
differ — QA is not a clone of the active content, so "QA" does not imply disposable.

### 5.2 What a future setup would leave untouched

Under a hypothetical approval, the following would remain untouched by this workstream:
existing submissions and their data; uploads (none today); order/item ids 17–20, their
snapshots/attempts/reservations/reviews and all relationships; accepted amounts/currencies;
the scheduling engine, resource calendar, driver/day guard, Hold/Reservation logic,
concurrency checks, admission enforcement and locking; the Commerce order/item types,
checkout/payment implementation, workflow, customer association and access protection;
server-trusted quotes, immutable pricing evidence, routing controls, and the development-fixed
policy.

### 5.3 Change manifest that *would* have been required (for the record, not proposed)

Should a later, separately-scoped task choose to pursue a contrib bridge, the exact
configuration-only items would be: package `commerce_webform_order:^3.0` (plus transitive
`commerce_purchasable_entity:^1.0`); the handler attached to a webform with its `order_item`
amount/currency/fields settings; an order-item type and an order type; and the Commerce
checkout-flow binding. **These cannot satisfy §2's contracts**, so this manifest is recorded
as insufficient rather than as a proposal. None of it was created.

### 5.4 Schema / environment blockers requiring separate tasks

1. **Stale `ppu` compiled Drupal service container** (§1.5.1): the running site compiles 15
   args for a 16-arg service. This is a **Drupal service-container** fact, **distinct from Docker
   infrastructure** (the Docker containers are healthy). Any runtime work on `ppu` needs a
   decision about rebuilding — out of scope here, and unsafe to do automatically because a
   rebuild would activate pending uncommitted code against the current schema.
2. **No payment gateway** (§1.5.2): any acceptance that exercises the payment pane needs a
   sandbox gateway configured under its own approval.
3. **QA is not isolated** (§1.4): the codebase, `vendor/`, and containers are shared, so a
   genuinely isolated rehearsal environment is a prerequisite for 25B-class work and does not
   exist today. (The databases, files, cache key space, and DB-backed ledgers *are* separated per
   site — verified in §1.4 — but that does not make the codebase isolated.)
4. **Schema drift** between `ppu` (10046) and QA (10049) — recorded, not reconciled here.

### 5.5 Backup / restoration / shared-codebase considerations for 25B

Because the sites share code, `vendor/`, and the containers (and a shared Redis *service*,
though with per-site key spaces — §1.4), a 25B rehearsal cannot be isolated by database alone:
enabling or disabling a module affects the shared codebase, and
`composer.json`/`composer.lock` are root files requiring an exact target exception in any 25B
approval. A rollback must never restore an old database over legitimate newer orders or
reservations (`RULES.md`). No backup or clone was made in 25A or 25A-R1 — doing so would exceed
the read-only scope.

---

## 6. Decision (Step 6)

*(Corrected 2026-10-08, R1-1: verdict **retained**, scope **narrowed**. The rejection rests on
the missing integration with the existing CheckoutAttempt, private Hold receipt, and
payment/capacity contracts — not on any general claim about contributed modules, and not as
authorization for a new custom integration.)*

**Verdict: B — CUSTOM INTEGRATION REQUIRED / OUTSIDE SCOPE.**

- **Assessed candidate.** The module assessed is **Commerce Webform Order (`commerce_webform_order`
  3.0.6)**. It is **incompatible with the current protected implementation under
  configuration-only constraints.** Verdict B is scoped to that: the candidate cannot satisfy
  the existing contracts by stock configuration, and closing the gap requires code.
- **What verdict B rests on (the protected contracts):**
  1. **The CheckoutAttempt contract.** The candidate creates no CheckoutAttempt row, and
     `confirmPaid()` refuses any order without one [DispatchCheckoutManager.php:193-199]. The
     first unsupported transition is therefore *submission save → order creation.*
  2. **The private Hold receipt.** The candidate claims no Hold and publishes no receipt; it
     never reaches the Resource Calendar Guard.
  3. **The payment/capacity contract.** Order placement must be gated on synchronous capacity
     confirmation [DispatchCheckoutFlow::onStepChange() :50]; the candidate redirects to stock
     checkout and does not participate in that gate.
  4. **The accepted-quote identity** (§3.2b): the candidate has no counterpart to the immutable
     `QuoteSnapshot` + `AcceptedJob` binding that confirmation re-proves.
- **Forbidden change required to close it:** custom code — a
  `hook_commerce_webform_order_handler_postsave_alter()` implementation, a decorated
  `order_item_repository`, or an order event subscriber — to hand order creation back to the
  existing manager and to synthesize the Checkout Attempt and Hold. `RULES.md` forbids new
  custom modules/glue and names hooks/subscribers/decorators as non-configuration solutions.
- **What verdict B does NOT say (scope limits).**
  - It does **not** say all contributed modules are unsuitable. It says *this* candidate cannot
    preserve *these* contracts by configuration, for the reasons above. Other modules were named
    but not assessed (§3.3).
  - It does **not** authorize a new custom integration. Adding custom glue is exactly what the
    verdict refuses; no such work is sanctioned here.
- **Runtime was left untouched.** No install, enablement, config import, `updb`, cache rebuild,
  submission, fixture, payment, browser probe, or service lifecycle change occurred.

**This verdict keeps 25B-25D blocked — the R1 correction retains it.** Verdict A is not claimed
and 25B is not requested. Schema repair, legacy cleanup, gateway setup, compiled-container
staleness, and any form redesign remain separate tasks. No competing Task 21-24 work was resumed
or altered.

### Pending proof (deliberately reserved, not a 25A gap)

None of the §2 contract statements is dynamically exercised here — they are read from source
and active configuration. Two source-level claims would need runtime confirmation before any
future bridge work relied on them: (a) that a contrib order reaching `dispatch_payment_process`
is refused by `confirmPaid()` for want of an attempt row, and (b) that the stale `ppu` compiled
container, once rebuilt, still refuses a non-dispatch order at the placement gate. Both are
recorded as **REQUIRES_VERIFICATION**, not established.

---

## 7. Correction record — 2026-10-08 (Task 25A-R1)

**Original outcome preserved above; the corrections below supersede specific statements in §1–§6
and are marked inline where applied. Verdict unchanged: B — still scoped to this candidate.**

This revision was a documentation correction under the same read-only scope as 25A. No
installation, custom integration code, application/configuration change, migration, cache
rebuild, container restart, form submission, or payment occurred, and no Task 25B–25D action was
executed. All reads were bounded and read-only, through the approved `docker exec … drush` path
and remote HTTP GET.

| # | Correction | Where |
| --- | --- | --- |
| 1 | Verdict B **retained and scoped**: rejection now rests explicitly on the CheckoutAttempt, private Hold receipt, and payment/capacity contracts; added explicit "does not say" scope limits (not a general contrib finding; not authorization for new custom integration). | §6 |
| 2 | Candidate evidence **pinned to the exact `3.0.6` tag** (previously the moving `3.x` branch): release URL, archive names and published checksums, exact source paths at the tag. Declared dependency compatibility **separated** from verified Composer resolution (no solver run — resolution is REQUIRES_VERIFICATION). | §3.0, §3.1 |
| 3 | Atomicity claim corrected: "they commit or roll back together" replaced with the source-established **transaction structure and intended guarantee**, with runtime commit/rollback/failure/concurrency marked REQUIRES_VERIFICATION (the class's own docblocks record atomicity as PENDING and post-failed-rollback disposal as BLOCKED). No new probe run. | §2.2 |
| 4 | Price correction: three-way distinction added — **(a)** untrusted browser amount, **(b)** server-recomputed submission value, **(c)** immutable accepted-quote identity. Established that the existing `preSave()` writes the quote into the submission from the server's own `QuoteCalculator`, so a stored value is not automatically browser-authoritative and a private constructor is not by itself proof of trusted input. The price finding is **retained on (c)** (the candidate has no identity binding); unresolved mappings marked REQUIRES_VERIFICATION. Item-type finding split into **configuration-UI restriction** (established) vs **runtime item support** (established as tolerated; end-to-end UI support REQUIRES_VERIFICATION) and demoted from a standalone reason for the verdict. | §2.2, §3.2, §3.2b, §3.2c, §4 |
| 5 | Protected-data inventory completed: **legacy `custom_webform_handlers_reservation`** (11 rows; 4 confirmed / 1 held / 6 released; 0 orphaned submissions), **`_dispatch_guard`** (14; 13 normal + 1 legacy), plus blackout/notification/notify-claim/routing tables. "No money captured" and "no files" inferences **retracted**: local-only money statement (0 gateways, 0 local payment rows) and corrected file statement (`file_managed` 0 **but** non-zero on-disk files). | §5.1 |
| 6 | Isolation wording corrected: shared code/vendor/containers **separated** from **per-site** database, files, config-sync, cache key space (`cache_prefix = 'qa_'`), and DB-backed ledgers/queues (verified by differing row counts). Blanket "every queue/worker is shared" retracted; any cross-site worker left UNVERIFIED. Shared-codebase installation risk retained. | §1.4 |
| 7 | Scope report: the project-memory write is **disclosed** (see below); the tracked/untracked counts **reconciled** (29 tracked = 28 module files + `.gitignore`; 16 untracked); no byte-for-byte preservation claimed from counts alone. No further memory edit made; no earlier write undone. | §1.2, §7 |
| 8 | Current status made unambiguous in `README.md` (25A verdict B/closeout corrected; 25B–25D BLOCKED; await owner decision; no installation authorized); stale "execute 25A now" instruction replaced. The **Drupal compiled-service-container** mismatch recorded **separately from Docker** infrastructure; `drush cr` explicitly not authorized. | `README.md`, §1.5.1, §5.4 |

### 7.1 Project-memory disclosure (R1-7)

During the original 25A run, **four** files were written to the project memory directory
`C:\Users\mo737\.claude\projects\C--Users-mo737-ppu\memory\`:

- `task25a-bridge-verdict-b.md` (type: project) — the verdict B finding
- `qa-not-installation-isolation.md` (type: reference) — the isolation finding
- `ppu-container-stale-vs-task24c.md` (type: project) — the compiled-container finding
- an appended block of three index lines in `MEMORY.md`

**Scope of that write:** those four paths only; nothing under `C:\Users\mo737\ppu` was affected by
them (the memory directory is outside the repository). Per this revision's instruction, **no
further memory edits were made** and the earlier write was **not** undone. Note that
`qa-not-installation-isolation.md` and `ppu-container-stale-vs-task24c.md` contain the same
over-generalizations corrected in §1.4 and §1.5.1 here; they are left in place as the record of
what was written, and this section is the correction of record.

### 7.2 Evidence added in this revision, and its limits

| Item | Value | Method / limit |
| --- | --- | --- |
| Candidate tag checked | `3.0.6` `src/Plugin/WebformHandler/CommerceWebformOrderHandler.php` | remote HTTP GET; methods `postSave`/`confirmForm`/`prepareData`/`replaceConfiguration` verified present; no `submitForm`. Price via `setUnitPrice($data['price'], TRUE)`. |
| Candidate `composer.json` @ `3.0.6` | requires php ≥8.1, core `^9\|\|^10\|\|^11`, commerce `^2.19\|\|^3.0`, `commerce_purchasable_entity ^1.0`, webform `^6.1`; `minimum-stability: dev` | remote read |
| Archive checksums | tar.gz SHA-256 `ca47ec…ba6c`, zip SHA-256 `a548ed…c2aa` | transcribed from the release page; **not** independently re-hashed (archives not fetched) |
| Legacy/guard/blackout/notification/routing tables | counts and relationship integrity as in §5.1 | `drush sql:query`, read-only |
| Per-site storage separation | cache_prefix, DB name, files dirs, config-sync, ledger/queue row counts | `sites/qa/settings.php` + `drush sql:query`, read-only |

**Remaining unverified claims (not resolved by this revision):** Composer resolution of the
candidate and `commerce_purchasable_entity`; whether a variation-less item can be driven through
the candidate's UI end to end; the exact submission value visible to `postSave()` for a
server-written element; token replacement over a numeric amount; whether any worker operates
across both sites; whether any on-disk file is a customer upload. Each is marked
**REQUIRES_VERIFICATION** in place.

### 7.3 Untouched, restated

Scheduling engine, resource calendar, driver/day guard, Hold and Reservation logic, concurrency
checks, admission enforcement and locking, Commerce order/item types, checkout/payment
implementation and workflow, all historical records (including the four legacy *confirmed*
reservations), and the existing dirty work were **not modified**. The corrected statements above
change only what this document *claims*, not any runtime state.
