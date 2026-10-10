# Cron Impact Audit (P6) — Closing the Remaining First-Request Cron Gaps

Read-only inspection. Prepared 2026-10-09 (America/New_York), measurements **03:05–03:20Z**.
Repository `C:\Users\mo737\ppu`.

**Nothing was run or changed.** No Drupal bootstrap, no Drush, no cron, no queue worker, no HTTP
request, no application operation. No service started; no container started, stopped, restarted,
recreated, reloaded or signalled. Cron configuration **not** changed. Advanced Queue **not**
repaired. Nothing installed or migrated. No source, database, or historical record altered. The
scheduling engine and driver lock are untouched.

Closes the six open items in [CRON-IMPACT-P5.md](CRON-IMPACT-P5.md) §5. P5's findings are
**preserved**; completed checks were not repeated. Prior evidence: [DIAGNOSIS.md](DIAGNOSIS.md),
[PRECHECK-P2.md](PRECHECK-P2.md), [PRE-REPAIR-P3.md](PRE-REPAIR-P3.md),
[REPAIR_PROMPT.md](REPAIR_PROMPT.md). Governing rules:
[../../commerce-webform-bridge/RULES.md](../../commerce-webform-bridge/RULES.md).

**Task 26B activation remains BLOCKED / NOT AUTHORIZED. Option A remains NOT AUTHORIZED.**

---

## 1. Verdict

**All six items are resolved.** The remaining cron risks are now **understood**.

| # | Open item | Resolution |
| --- | --- | --- |
| 1 | `system_cron`, `node_cron`, `comment_cron`, `history_cron`, `field_cron` not inspected | **RESOLVED** — §3. Three are effectively no-ops on current state; `system_cron` adds **two effects P5 missed** (a fourth outbound call and an `.htaccess` write). |
| 2 | Commerce fetcher conditions and effects | **RESOLVED** — §4. Self-throttled to once per day; state is 19.9 days stale ⇒ fires **exactly once**, Guzzle HTTP. |
| 3 | Search-index work scope | **RESOLVED** — §5. Bounded at `cron_limit = 100` per page per run; **85** items pending, **0 nodes**; writes confined to `search_*` tables. |
| 4 | Advanced Queue behaviour with `advancedqueue_job` absent | **RESOLVED** — §6. Throws at `cleanupQueue()` **before** any job is claimed; contained and logged; cron continues. |
| 5 | Any cron path instantiating `dispatch_checkout_manager` | **RESOLVED — NO**, §7. Full referrer closure is three non-cron entry points. One indirect path exists and is closed by **two independent gates**. **But see §7.3: a newly found containment gap makes that closure load-bearing for availability, not just data.** |
| 6 | Any cron/queue path making a paid Google Routes / Maps / geocoding / external routing request | **RESOLVED — NO**, §8. Blocked **three** independent ways, and the `development_fixed` safeguard is confirmed to hold under **PHP-FPM**, not merely under the Drush CLI that measured it before. |

**Net change to the disclosed effects:** outbound network calls rise from **three to four**, and one
filesystem write is added. **No protected-data verdict changes** — no order, submission, review,
reservation, guard, payment, file or queued job is touched.

---

## 2. Method

Module enablement was **reconfirmed** rather than inherited: both sites' active `core.extension` were
re-read, with a positive control (`system` → enabled) and a negative control (`zzz` → not enabled).
The two lists are **byte-identical** (`cmp -s` → YES), so one analysis covers both sites.
`eca_queue` and `geocoder_field` are **still not enabled**; `geocoder` is.

**Graphify** was queried first per the repository rule. It oriented the routing/queue surface
(`ScheduledRouteWarmer`, `RoutingModeResolver`, `EnqueuePopularityRecountAction`,
`DistanceProviderRouteEstimator`). **One trap it exposed:** several returned nodes point into
`docs/execution/system-integrity-audit/task-24a-recovery/candidate/` and `…/corrected/baseline/`,
which are **recovery copies, not the live module**. Every citation below is to live
`modules/custom_webform_handlers/src/…` or to Drupal core, which Graphify does not cover and which
was therefore read directly from source.

Database reads were confined to active `config` rows, two `key_value` state collections, and
aggregate counts — listed in §10.

---

## 3. Item 1 — the five core cron bodies

| Hook | Source | What it does | Effect on current state |
| --- | --- | --- | --- |
| `system_cron` | `core/modules/system/system.module:1005-1041` | Flood garbage collection; `garbageCollection()` on **every** cache bin; expirable key-value GC; queue GC for every queue-worker definition; **`file.htaccess_writer->ensure()`**; **security-advisories fetch** when `system.advisories.enabled` | **Two effects P5 did not identify** — see §3.1 and §3.2. GC itself is maintenance-only. |
| `node_cron` | `core/modules/node/node.module:603` | Entity aggregate `MIN(created)`/`MAX(created)` for search ranking → writes `state` | **Trivial**: `node` count is **0** on both sites. One state write. No deletes. |
| `comment_cron` | `core/modules/comment/comment.module:412` | Writes `comment.node_comment_statistics_scale` to `state` | **One state write.** No deletes. |
| `history_cron` | `core/modules/history/history.module:129` | `DELETE FROM history WHERE timestamp < HISTORY_READ_LIMIT` (node-read tracking, 30 days) | **0 deletions**: `history` has **0 rows** on both sites. |
| `field_cron` | `core/modules/field/field.module:167` | `field_purge_batch($limit)` — purges already-**deleted** Field API data | **0 effect**: **0** field storages flagged deleted and **0** `field_deleted_*` tables on both sites. |

So three of the five are state-writes or no-ops, and **none touches orders, submissions,
reservations, guards, payments or files.** The two additions are both in `system_cron`.

### 3.1 NEW — a fourth outbound network call

`system.module:1037-1040`:

```
if (\Drupal::config('system.advisories')->get('enabled')) {
  $fetcher = \Drupal::service('system.sa_fetcher');
  $fetcher->getSecurityAdvisories();
}
```

Measured active config, **both sites**: `"enabled";b:1`, `"interval_hours";i:6`. So the security
advisories fetch **is armed**. No `state` row tracks its last fetch (`key_value` LIKE `%advisor%`
→ **0 rows**), which is consistent with the fetcher using a cache bin rather than state — and the
container cache was restored from the RDB, so whether a cached response suppresses the call is
**REQUIRES_VERIFICATION**. Treat it as **a fourth outbound call to drupal.org**.

### 3.2 NEW — a filesystem write

`system.module:1035`: `\Drupal::service('file.htaccess_writer')->ensure()` writes `.htaccess` files
into Drupal's standard directories (public files, private, temporary, config) when missing. P5
characterised the run as making no filesystem change; that is **wrong for this call**. The specific
target paths and whether any file is actually missing were **not enumerated** —
**REQUIRES_VERIFICATION**. It writes protective `.htaccess` files only; it does not touch managed
files or user content.

---

## 4. Item 2 — the Commerce metadata fetcher

`modules/contrib/commerce/src/InboxMessageFetcher.php`:

| Fact | Evidence |
| --- | --- |
| Reached from cron | `commerce.module:190` → `CommerceHooks::cron()` → `($this->inboxMessageFetcher)()->fetch()` |
| Makes an external HTTP request | constructor takes `ClientInterface $httpClient` (Guzzle), `:44` |
| **Is self-throttled** | `:58-65` — `$fetch_next = $this->state->get('commerce.inbox_messages_cron_last', 0) + static::CRON_INTERVAL;` then `if ($request_time < $fetch_next) { … }`. `CRON_INTERVAL` is documented at `:24` as **once a day**. |
| Will it fire? | **YES, exactly once.** State is `1789799646` (default) / `1789798289` (QA) ≈ **19.9 days** stale against a 1-day interval. |

P5 recorded this gating as unverified. **It is now resolved: gated, and it fires once per cron day,
not once per cron run.** The fetched payload is Commerce vendor messaging; no protected record is
read or written by the fetch itself.

---

## 5. Item 3 — scope of the search-index work

`search_cron` (`core/modules/search/search.module:76`) iterates
`search_page_repository->getIndexableSearchPages()` and calls `$entity->getPlugin()->updateIndex()`.

| Input | Default site | QA site |
| --- | --- | --- |
| Search pages configured | `help_search`, `node_search`, `user_search` | identical |
| `search.settings` → `index.cron_limit` | **100** | **100** |
| `node` rows | **0** | **0** |
| `search_dataset` rows / flagged `reindex` | **85 / 85** | **85 / 85** |
| `search_index` rows (from P5) | 7,598 | 7,598 |

Reading: `node_search` has **nothing to index** (0 nodes). The 85 pending items belong to the other
indexable page(s) — consistent with `help_search` topics. So the first run performs a **bounded
re-index of at most `cron_limit = 100` items per page**, with 85 actually pending.

**Writes are confined to the `search_index`, `search_dataset` and `search_total` tables.** No
external call. No protected table. The exact per-page write count was not measured —
**REQUIRES_VERIFICATION** — but it is hard-bounded by `cron_limit`.

---

## 6. Item 4 — Advanced Queue with `advancedqueue_job` absent

`modules/contrib/advancedqueue/src/Processor::processQueue()` — the **first statement** is:

```
$queue->getBackend()->cleanupQueue();
```

That is reached **before** `$queue->getBackend()->claimJob()` later in the loop. With the
`advancedqueue_job` table absent on both sites (P5, re-confirmed), `cleanupQueue()` raises a database
error immediately, so **no job is ever claimed and no job type is ever instantiated**.

**Containment is confirmed from core source.** `core/lib/Drupal/Core/Cron.php:336-342`:

```
// Do not let an exception thrown by one module disturb another.
try { $hook(); }
catch (\Exception $e) { Error::logException($this->logger, $e); }
```

Drupal database errors (`DatabaseExceptionWrapper`, `PDOException`) are `\Exception` descendants, so
the failure is **caught, logged, and cron continues**. Expected outcome: **three logged exceptions**
(one per `processor = cron` queue — `custom_webform_handlers_dispatch`,
`custom_webform_handlers_maintenance`, `default`) and no job processing.

P5's expectation of graceful degradation is therefore **confirmed from source**. It was not
exercised, so the precise log shape remains **REQUIRES_VERIFICATION**.

This anomaly is **pre-existing and unrelated to the proposed repair**, and this task did not fix it.

---

## 7. Item 5 — can any cron path instantiate `dispatch_checkout_manager`?

**No.**

### 7.1 Complete referrer closure

`custom_webform_handlers.dispatch_checkout_manager` is referenced in exactly **two** places in the
module's service wiring and **one** in PHP:

| Referrer | Kind | Cron-reachable? |
| --- | --- | --- |
| `custom_webform_handlers.services.yml:721` → `custom_webform_handlers.dispatch_checkout_starter` | service argument | only via the starter's own consumers, below |
| `src/Plugin/Commerce/CheckoutPane/DispatchPaymentProcess.php:86` | `#[CommerceCheckoutPane]` plugin | **No** — instantiated only when the Commerce checkout flow renders that pane |

The starter's only consumers:

| Consumer | Kind | Cron-reachable? |
| --- | --- | --- |
| `src/Form/ReviewPaymentForm.php:77` | Form | **No** — built on an HTTP form request |
| `src/Plugin/WebformHandler/AddressDistanceHandler.php:309` | Webform handler plugin | **Not directly** — but see §7.2 |

No `hook_cron` and no cron-processed queue worker reaches any of these. In particular
`DispatchNotificationWorker::create()` pulls only
`custom_webform_handlers.dispatch_notification_dispatcher` and a logger
(`src/Plugin/QueueWorker/DispatchNotificationWorker.php:58-59`), and that dispatcher's arguments
(`services.yml:623-630`) are the notification composer, `@plugin.manager.mail`, the notification log,
the notification claim service, and a logger — **no checkout manager anywhere in the chain**.

Note a subtlety that makes this worth checking at all: `Core\Cron::processQueues()`
(`core/lib/Drupal/Core/Cron.php:200-213`) calls `$queue->createQueue()` and
`$this->queueManager->createInstance($queue_name)` for every worker with a `cron` key — i.e. the
**worker plugin is instantiated even when the queue is empty**. So an empty queue does not by itself
prevent service construction; the dependency chain had to be traced, and it is clean.

### 7.2 The one indirect path, and its two gates

`webform_cron` → `WebformHooks::cron()` → `webform_submission` storage `purge()`. If purge deleted
submissions, Webform would instantiate that form's handlers — including `AddressDistanceHandler` —
which would pull the starter and therefore the manager.

`modules/contrib/webform/src/WebformSubmissionStorage::purge()` gates on **two** conditions
simultaneously:

```
$query->condition('settings.purge', [PURGE_DRAFT, PURGE_COMPLETED, PURGE_ALL], 'IN');
$query->condition('settings.purge_days', 0, '>');
…
if (!empty($webform_ids)) { … }
```

Measured on **both** sites, all three forms:

| Form | `purge` | `purge_days` |
| --- | --- | --- |
| `contact` | `"purge";s:4:"none"` | **key absent** |
| `da` | `"purge";s:4:"none"` | **key absent** |
| `hg` | `"purge";s:4:"none"` | **key absent** |

Both conditions fail independently, so `$webform_ids` is empty and the `if (!empty(...))` body never
runs — **no submission is even loaded, let alone deleted, and no handler is instantiated.**

### 7.3 NEW — a containment gap that makes §7.2 load-bearing for availability

`Core\Cron.php:341` catches **`\Exception`** — **not `\Throwable`**.

`ArgumentCountError` extends `TypeError` extends `Error`, which implements `Throwable` but is **not**
an `Exception`. Therefore, if any cron path on the **default site** did instantiate
`dispatch_checkout_manager` against the currently valid **15-argument** container, the resulting
`ArgumentCountError` would **escape the per-module containment** and be **fatal to the request** —
aborting the remainder of the cron run rather than being logged and skipped.

That does not happen, because §7.1 and §7.2 close every path. But it means the `purge: none` /
`purge_days` absent settings are load-bearing for **availability**, not only for data preservation —
a sharper consequence than P5 recorded. It upgrades P5 stop-condition #2 (see
[REPAIR_PROMPT.md](REPAIR_PROMPT.md) §10.1 item 2) from "would delete submissions" to "would delete
submissions **and** could fatal the request on the default site".

---

## 8. Item 6 — can any cron or queue path make a paid external routing request?

**No.** The path exists in source but is blocked three independent ways, and the safeguard is now
confirmed for the FPM runtime rather than only the CLI one.

### 8.1 The path that exists

| Step | Evidence |
| --- | --- |
| Only live paid provider | `src/Distance/GoogleRoutesDistanceProvider.php` — **3** HTTP-client references. Control: the identical grep over `src/Distance/FixedDevelopmentRouteProvider.php` returns **0**, so the matcher works. |
| A cron-processed queue holds a route-warming job | `src/Plugin/AdvancedQueue/JobType/DispatchRouteWarmJob.php`, placed in the `custom_webform_handlers_maintenance` queue (`src/Maintenance/AdvancedQueueMaintenanceQueue.php:10,47`), and that queue is `processor = cron` (P5). |
| The job reaches the provider chain | `src/Dispatch/Route/ScheduledRouteWarmer.php:38-44` takes a `DispatchRouteEstimatorInterface`, i.e. the estimator → distance-provider chain. |

### 8.2 Three independent blocks

1. **The Advanced Queue backend cannot be read.** `processQueue()` throws at `cleanupQueue()` before
   any `claimJob()` (§6), so `DispatchRouteWarmJob` is never instantiated, let alone run.
2. **The queue is empty, and cron cannot fill it.** Nothing can enqueue: the two ECA models that
   perform the enqueues are triggered by the domain events `review_transitioned` and
   `reservation_confirmed` (P5 §3.3), neither of which a cron run can fire — 0 Review Requests are
   eligible to transition and no reservation is confirmed during cron.
3. **`development_fixed` bypasses the provider chain entirely.**
   `src/Distance/RoutingModeEntryProvider.php:17,33` — "no provider chain: development_fixed
   delegates to the deterministic …" / "The deterministic **no-HTTP** provider used in
   development_fixed mode". And `src/Distance/RoutingModeResolver.php:19-21` — with
   `IS_DDEV_PROJECT=true` present, "the mode is development_fixed **unconditionally**", read at
   `:66` via `getenv('IS_DDEV_PROJECT')`. The stored config says `live_cached` on both sites; the
   environment signal overrides it.

### 8.3 The FPM-versus-CLI nuance, resolved

26A §1.3 measured the effective mode as `development_fixed` **through Drush CLI**. That does not
establish FPM behaviour: `getenv()` in a PHP-FPM worker returns nothing if the pool clears the
environment, and the resolver would then fall back to the stored `live_cached` — which is exactly the
condition under which a paid call becomes possible.

Measured: `/etc/php/8.4/fpm/pool.d/*.conf:448` → **`clear_env = no`**, and **0** `env[]` lines (none
needed, because nothing is cleared). `IS_DDEV_PROJECT=true` is present in the container environment.
The inheritance chain is container env → `/start.sh` (which P3 confirmed inherits all 12 DDEV
variables under `docker exec`) → supervisord → php-fpm master → workers. **So `getenv()` resolves in
FPM and the safeguard holds there too.**

Not runtime-observed — `getenv()` inside a live FPM worker cannot be checked without starting FPM and
issuing a request, both forbidden here. **Final step REQUIRES_VERIFICATION; every input is
confirmed.**

### 8.4 Geocoding

`geocoder` is enabled but **`geocoder_field` is not** (re-confirmed, §2), so the `GeocoderField`
cron queue worker is not registered and no geocoding queue can run. No other enabled module
contributes a geocoding cron path.

---

## 9. Updated effect list and remaining unknowns

### 9.1 What a first-request cron will do (consolidated, P5 + P6)

| # | Effect | Class | Basis |
| --- | --- | --- | --- |
| 1 | drupal.org update check | Outbound HTTP | state-backed (19.9 d stale vs 1 d) |
| 2 | drupal.org announcements feed | Outbound HTTP | state-backed (19.5 d vs 6 h) |
| 3 | Commerce inbox fetch | Outbound HTTP | **state-backed, once/day** (§4) |
| 4 | **Security advisories fetch** | Outbound HTTP | **NEW** — config-armed (§3.1); cache suppression unverified |
| 5 | ~45 `watchdog` rows deleted (default only) | DB delete | state-backed |
| 6 | ≤100 search items re-indexed (85 pending) | DB write, `search_*` only | state-backed, hard-bounded (§5) |
| 7 | 3 logged exceptions from Advanced Queue | Log write | source-backed (§6) |
| 8 | **`.htaccess` ensure** in standard directories | **Filesystem write** | **NEW** — source-backed (§3.2) |
| 9 | Cron bookkeeping state writes | State write | source-backed |
| 10 | 2 trivial state writes (`node`, `comment`) | State write | source-backed (§3) |

**Will NOT happen** (unchanged from P5, now with the §7/§8 closures added): no order, submission,
review request, reservation, guard, payment, managed file or queued job is created, modified or
deleted; no mail is sent; no queue is drained; neither ECA model triggers; **no paid routing or
geocoding request is made**; and **no `ArgumentCountError`** is raised.

### 9.2 Remaining unknowns

| Item | Status |
| --- | --- |
| Whether a cached response suppresses the security-advisories fetch | **REQUIRES_VERIFICATION** (§3.1) — no state row; fetcher uses a cache bin |
| Which `.htaccess` paths `ensure()` writes, and whether any is currently missing | **REQUIRES_VERIFICATION** (§3.2) |
| Exact per-page search write volume | **REQUIRES_VERIFICATION** (§5) — hard-bounded at 100/page/run |
| Precise Advanced Queue log shape | **REQUIRES_VERIFICATION** (§6) — containment itself is source-confirmed |
| `getenv('IS_DDEV_PROJECT')` inside a live FPM worker | **REQUIRES_VERIFICATION** (§8.3) — `clear_env = no` confirmed, inheritance chain confirmed |
| Second and subsequent cron runs | **Out of scope** — this audit covers the first run |

**All six P5 items are closed. The residual list contains no unexamined code path** — only
runtime confirmations that cannot be obtained without starting services, plus two bounded-volume
questions.

---

## 10. Scope and commands

### 10.1 Scope

Resolves the **P5 residual** only. Does **not** resolve, weaken or partially satisfy **B2**, **B3**,
**B4**, **B6**, or **Task 26B default-site activation**, which remains **BLOCKED / NOT AUTHORIZED**.
**Task 25A verdict B** and Tasks **25B–25D** remain **BLOCKED**. **B5 remains diagnosed and
prepared, but not repaired.** Option A remains **NOT AUTHORIZED**.

All five P5 stop conditions ([REPAIR_PROMPT.md](REPAIR_PROMPT.md) §10.1) still stand on **live
values** and must be re-measured immediately before any service start; §7.3 strengthens the reason
for stop condition #2.

### 10.2 Commands run (all read-only; exit codes as observed)

| Purpose | Command shape | Exit | Result |
| --- | --- | --- | --- |
| Query-first orientation | `graphify query "route warm queue worker popularity recount distance provider routing mode resolver paid request"` | 0 | Oriented; exposed the recovery-copy citation trap (§2) |
| **Enablement reconfirm** | `mysql … core.extension` both sites + `grep -qx` per module, positive **and** negative controls, `cmp -s` | 0 | identical lists; `eca_queue`/`geocoder_field` still not enabled |
| Five core cron bodies | `grep -n "^function X_cron("` + `sed -n "L,+16p"` on `system`, `node`, `comment`, `history`, `field` | 0 | §3 |
| `system_cron` remainder | `sed -n '1021,1045p' core/modules/system/system.module` | 0 | §3.1, §3.2 |
| Cron containment + queue processing | `sed`/`grep` on `core/lib/Drupal/Core/Cron.php` | 0 | §6, §7.1, §7.3 |
| Commerce fetcher | `grep -n` on `modules/contrib/commerce/src/InboxMessageFetcher.php` | 0 | §4 |
| Advanced Queue processor | `sed` on `modules/contrib/advancedqueue/src/Processor.php` | 0 | §6 |
| Checkout-manager closure | `grep -n` for the service id in `custom_webform_handlers.services.yml` and `src/**.php`, then for `dispatch_checkout_starter`, with a positive control | 0 | §7.1 |
| Worker dependency chain | `sed` on `DispatchNotificationWorker.php:45-70`; `grep -A8` on the dispatcher service | 0 | §7.1 |
| Webform purge gate | `sed` on `modules/contrib/webform/src/WebformSubmissionStorage.php::purge()` | 0 | §7.2 |
| Route-warm chain | `grep -rn` for `route_warm`/`RouteWarm` in live `src`; `ls src/Plugin/AdvancedQueue/JobType/`; `sed` on `ScheduledRouteWarmer.php` | 0 | §8.1 |
| Paid-provider HTTP refs | `grep -cE "httpClient\|Client\|curl\|file_get_contents\|->request\("` on `FixedDevelopmentRouteProvider.php` (**0**) vs `GoogleRoutesDistanceProvider.php` (**3**, control) | 0 | §8.2 |
| Routing-mode safeguard | `grep -n` on live `RoutingModeResolver.php`, `RoutingModeEntryProvider.php`; `echo ${IS_DDEV_PROJECT}` in the web container | 0 | §8.2 |
| **FPM env passthrough** | `grep -rn "^\s*clear_env\|^\s*env\["` on `/etc/php/8.4/fpm/pool.d/*.conf` | 0 | **`clear_env = no`**, 0 `env[]` lines (§8.3) |
| Active config + state | `system.advisories`, `search.settings`, `search.page.*`, `webform.webform.*` (`purge`, `purge_days`), `custom_webform_handlers.settings` (`routing_mode`); `key_value` state `commerce.inbox_messages_cron_last`, `%advisor%` | 0 | §3–§8 |
| Aggregate counts | `history`, `node`, `search_dataset` (total + `reindex<>0`), deleted field storages, `field_deleted_*` via `information_schema` | 0 | §3, §5 |

Database access was limited to the records needed for these six points: active `config` rows, two
`key_value` state names, and aggregate counts. **No customer record, no serialized queue payload and
no submission content was read or printed.** No credential was typed or printed; the `db` container
authenticated from its own `~/.my.cnf`. DBHub was not needed this round (its `dbhub_ro` account
reaches only the `db` schema, and every check here had to cover both sites uniformly).

**Difficulties, none changing state:** (1) Graphify returned `…/task-24a-recovery/candidate/` and
`…/corrected/baseline/` paths for `RoutingModeResolver` and `GoogleRoutesDistanceProvider`; citations
were redirected to the live `src/` files. (2) A `GROUP_CONCAT` + `REGEXP_SUBSTR` query for
`purge_days` returned a mangled `--------------`; re-queried per form, which showed the key is
**absent** rather than zero — a stronger result than the original query would have given. (3) The
`ScheduledRouteWarmer` service definition was not found under the id guessed from the class name, so
its dependencies were read from the constructor instead.
