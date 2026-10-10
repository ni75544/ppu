# Cron Impact Audit (P5) — First-Request Cron Effects Before Web-Service Start

Read-only audit. Prepared 2026-10-09 (America/New_York), measurements **02:35–02:58Z**.
Repository `C:\Users\mo737\ppu`.

**Nothing was started, run, or changed.** No service started; no container started, stopped,
restarted, recreated, reloaded or signalled; no Drush; no DDEV; no Drupal bootstrap; no HTTP request
to any Drupal route; no cron run; no queue worker run; no configuration changed (cron **not**
disabled); no application code edited; no migration; Option A/B **not** authorized or performed;
nothing committed.

Answers the **P5** prerequisite raised in [PRE-REPAIR-P3.md](PRE-REPAIR-P3.md) §6 and recorded in
[REPAIR_PROMPT.md](REPAIR_PROMPT.md) §6 item 5. Prior evidence: [DIAGNOSIS.md](DIAGNOSIS.md),
[PRECHECK-P2.md](PRECHECK-P2.md), [PRE-REPAIR-P3.md](PRE-REPAIR-P3.md). Governing rules:
[../../commerce-webform-bridge/RULES.md](../../commerce-webform-bridge/RULES.md).

**Task 26B activation remains BLOCKED / NOT AUTHORIZED.** The service-start proposal remains
**NOT AUTHORIZED**.

---

## 1. Verdict

**A separate cron mitigation decision IS needed.** The first HTTP request after a service start would
run an overdue cron that makes **three outbound network calls** and **at least one database
deletion**, none of which appeared in the repair proposal's effects analysis.

**The protected-data paths are clear, and each clearance is state-backed and control-verified:**

| Protected asset | Cron path that could touch it | Finding |
| --- | --- | --- |
| **Commerce orders** | `commerce_cart` → `Cron::run()` → **`$order->delete()`** | **WILL NOT RUN.** No order type has a `cart_expiration` third-party setting, so the loop hits `continue` for every type. Verified with a control that proves the matcher works. |
| **Webform submissions** | `webform` → `WebformHooks::cron()` → `webform_submission` storage `purge()` | **WILL NOT DELETE.** All three forms (`contact`, `da`, `hg`) are `"purge";s:4:"none"` on both sites — read as literal values, not inferred from a count. |
| **Review Requests** | `custom_webform_handlers_cron()` → `ReviewManager::expireLapsed()` | **WILL WRITE 0 ROWS** (re-confirmed from P3): every expirable row has `expires_at IS NULL`, which the selection excludes. |
| **Managed files** | `file_cron` | **WILL DELETE 0.** Armed (`temporary_maximum_age = 21600`) but `file_managed` has **0 rows** on both sites. |
| **Queued jobs** (dispatch notifications, cart expiration, orphan purge) | three cron-processed QueueWorkers | **WILL NOT RUN.** The `queue` table is **empty on both sites**, so no worker claims an item. |

So no order, submission, review, file, or queued job is at risk. What *does* happen is listed in §4.

---

## 2. Method and coverage

### 2.1 Enabled-module baseline — and a control that caught my own error

**DBHub is available again** (it was suspended through P1–P3). Its `dbhub_ro` account is correctly
scoped to the **`db`** schema only and is denied `qa` (`SELECT command denied … for table
qa.config`), so DBHub was used for the default site and the authorized read-only fallback
(`docker exec ddev-ppu-db`, `~/.my.cnf`, no credential typed or printed) for the uniform two-site
read.

DBHub read `core.extension` authoritatively: the module array is **`a:68:`**. My fallback regex
returned **70** — it was also matching the *theme* entries, which share the serialized shape.
70 − `olivero` − `claro` = **68**, matching DBHub exactly. **The DBHub control caught a real
over-count in my own extraction**, which is why it is reported rather than quietly corrected.

**68 modules enabled, and the two sites are identical** — `comm -23` and `comm -13` both return
empty, so one analysis covers both sites.

### 2.2 Scan coverage, and its limits

| Surface | Method | Limit |
| --- | --- | --- |
| `hook_cron` (procedural) | `grep -E "^function <module>_cron\("` across each enabled module's `.module` | positive-controlled |
| `hook_cron` (OOP attribute) | `grep -rln "Hook('cron')"` across `core/modules`, `modules/contrib`, custom | Found **5 contrib** files; the equivalent scan over `core/modules` found **none**, i.e. core 10.6.16 still uses the procedural form, which the first scan covered. |
| Cron-processed QueueWorkers | files declaring `QueueWorker` **and** a `cron` key | enabled-module filter applied per §2.3 |
| Cron event subscribers / ECA | active `eca.eca.*` config, trigger plugin IDs only | §3.3 |
| Queue state | `queue` grouped **by name only**; `advancedqueue_job`; no payload or customer row read | — |

**Graphify** was queried first per the repository rule. It oriented the custom-module surface
(`QueueDispatchNotifier`, `ReviewTrackerCacheSubscriber`, the ECA/queue history) but **establishes
nothing here**: its coverage stops at `modules/custom_webform_handlers/**`, so core and contrib cron
code was read directly from source and is labelled as such.

### 2.3 Modules excluded because they are NOT enabled

Each excluded module owns a cron surface that would otherwise apply. Verified individually against
the active module list, with **both** a positive control (`system` → ENABLED) and a negative control
(`zzz_not_a_module` → not enabled):

| Not enabled | Surface that therefore does **not** apply |
| --- | --- |
| `commerce_promotion` | `CommercePromotionHooks::cron()` |
| `webform_scheduled_email` | `WebformScheduledEmailHooks::cron()` — **a mail sender** |
| `eca_queue` | `TaskWorker` cron queue |
| `geocoder_field` | `GeocoderField` queue worker — **an external geocoding caller** |
| `locale` | `LocaleTranslation` queue worker — outbound translation fetch |
| `media` | `ThumbnailDownloader` queue worker |
| core test modules (`cron_queue_test`) | test-only queue workers |

The two in bold are the ones whose absence most reduces risk: no scheduled email and no geocoder
queue can fire.

---

## 3. Tasks assessed

### 3.1 `hook_cron` implementations (applicable, enabled modules only)

| Module | Source | Effect class | Will it act? |
| --- | --- | --- | --- |
| `update` | `core/modules/update/update.module:165` | **Outbound HTTP** — `update_refresh()` + `update_fetch_data()` to drupal.org; writes `update.last_check` | **WILL RUN** (state-backed) — last check **19.9 days** ago vs `interval_days = 1` |
| `announcements_feed` | `core/modules/announcements_feed/announcements_feed.module:114` | **Outbound HTTP** — `announcements_feed.fetcher->fetch(TRUE)`; writes state | **WILL RUN** (state-backed) — last fetch **19.5 days** ago vs `cron_interval = 21600` |
| `commerce` | `commerce.module:190` → `CommerceHooks::cron()` | **Outbound HTTP** — `($this->inboxMessageFetcher)()->fetch()` | **LIKELY RUNS** (source-level). No gate is visible in the hook itself; whether the fetcher self-throttles is **REQUIRES_VERIFICATION** |
| `dblog` | `core/modules/dblog/dblog.module:64` | **DB delete** — prunes `watchdog` to `row_limit` | **WILL DELETE on default** (state-backed): `row_limit = 1000`, `watchdog` = **1045** ⇒ ~45 rows. **No-op on QA** (231 rows) |
| `search` | `core/modules/search/search.module:76` | **DB writes** — `updateIndex()` per indexable search page | **LIKELY RUNS** (state-backed): `search_index` holds **7,598** rows on both sites. Exact write volume **REQUIRES_VERIFICATION** |
| `advancedqueue` | `advancedqueue.module:13` | Processes every queue whose `processor = cron` | **WILL ATTEMPT AND FAIL** — see §3.4 |
| `webform` | `webform.module:108` → `WebformHooks::cron()` | Submission **purge** | **0 deletions** — all forms `purge: none` (§1) |
| `commerce_cart` | `CommerceCartHooks::cron()` → `Cron::run()` | **Order `delete()`** | **0 deletions** — no `cart_expiration` on any order type (§1, §3.2) |
| `file` | `core/modules/file/file.module:450` | Deletes temporary files | **0 deletions** — `file_managed` empty (§1) |
| `custom_webform_handlers` | `custom_webform_handlers.module:85` | Review expiry write; popularity enqueue | **0 review writes**; enqueue gated to a ~20 h window (§1) |
| `node`, `comment`, `history`, `field`, `system` | core `.module` files | Standard core maintenance — history pruning, field purge of deleted field data, system queue/temp/cron-key housekeeping | **Bodies not individually read — REQUIRES_VERIFICATION** (§5) |

### 3.2 The order-deletion path, in detail

This is the single highest-stakes path found, so its clearance is given in full.

`modules/contrib/commerce/modules/cart/src/Cron.php::run()` loads **all** order types and, for each:

```
$cart_expiration = $order_type->getThirdPartySetting('commerce_cart', 'cart_expiration');
if (empty($cart_expiration)) { continue; }
…
foreach ($order_ids as $order_id) { … $order->delete(); }
```

Measured on **both** sites:

| Order type | `cart_expiration` | `third_party` key | Control (`workflow`) |
| --- | --- | --- | --- |
| `default` | **absent** | absent | **PRESENT — matcher works** |
| `dispatch` | **absent** | PRESENT | **PRESENT — matcher works** |

The control matters: my first attempt at this check used `third_party_settings` as the control token,
which is **not** in the blob, so it returned NO and proved nothing. Re-run with `workflow` — present
in all four rows — the matcher is demonstrated working, and `cart_expiration=absent` is therefore a
real negative, not a pattern failure. **`continue` is taken for every order type; `$order->delete()`
is unreachable.**

### 3.3 Cron event subscribers and ECA

Two ECA models exist in active config on both sites, **both enabled** (`"status";b:1`):

| Model | Trigger event plugin | Action | Cron-triggered? |
| --- | --- | --- | --- |
| `eca.eca.custom_webform_handlers_popularity` | `custom_webform_handlers_dispatch_domain_event:review_transitioned` | `custom_webform_handlers_enqueue_popularity_recount` | **No** — domain event, not cron |
| `eca.eca.custom_webform_handlers_route_warm` | `custom_webform_handlers_dispatch_domain_event:reservation_confirmed` | `custom_webform_handlers_enqueue_route_warm` | **No** — domain event, not cron |

**Neither subscribes to a cron event.** One second-order path exists and is closed by state: cron →
`expireLapsed()` → a review transition would emit `review_transitioned` → the popularity model would
enqueue. Because **0 rows are eligible to transition** (§1), that chain cannot start.

No other cron event subscriber was found among enabled modules.

### 3.4 Queues — depths by name only

The `queue` table is **empty on both sites** (no rows, so no names to list). Therefore none of the
three applicable cron-processed workers executes:

| Worker | Module | Status |
| --- | --- | --- |
| `DispatchNotificationWorker` | `custom_webform_handlers` | enabled; **0 items** ⇒ no run, **no mail** |
| `CartExpiration` | `commerce_cart` | enabled; **0 items**, and nothing can enqueue (§3.2) |
| `OrphanPurger` | `entity_reference_revisions` | enabled; **0 items** |

**Advanced Queue anomaly (newly established).** `advancedqueue` is enabled and **three** queue config
entities exist — `custom_webform_handlers_dispatch`, `custom_webform_handlers_maintenance`,
`default` — and **all three are `processor = cron`** (control: the `id` token matched on all three,
so the matcher works). But the **`advancedqueue_job` table does not exist** in either database
(`information_schema` count = 0).

So `advancedqueue_cron()` will load all three queues and call `processQueue()` on each against a
**missing table**. Expected outcome: a database exception per queue. Drupal's cron service catches
per-implementation exceptions and logs them, so this should **log errors and continue** rather than
abort the request — but that is **REQUIRES_VERIFICATION**; it was not exercised. Either way it adds
watchdog rows, which interacts with the `dblog` pruning in §3.1.

This anomaly is **pre-existing and unrelated to the proposed repair**; it is recorded here because a
cron run is what would surface it.

---

## 4. What a first-request cron would actually do

**State-backed WILL:**

1. **Three outbound network calls** — drupal.org update check (`update`), drupal.org announcements
   feed (`announcements_feed`), and the Commerce inbox fetch (`commerce`, gating unverified).
2. **Delete ~45 `watchdog` rows** on the default site (`dblog`), no-op on QA.
3. **Write search index rows** (`search`, 7,598 rows present; volume unverified).
4. **Log one error per Advanced Queue queue** against the missing `advancedqueue_job` table.
5. **Write cron bookkeeping state** — `system.cron_last`, `update.last_check`,
   `announcements_feed.last_fetch`.

**State-backed WILL NOT:** delete or modify any order, submission, review request, reservation,
guard record, payment, or managed file; send any mail; drain any queue; or trigger either ECA model.

**The outbound calls are the material finding**, because the isolation manifest this task chain
operates under ([../task-26a-readiness/READINESS.md](../task-26a-readiness/READINESS.md) §5.1)
requires establishing "that no external call can be made" as a verified boundary. These three are a
different class from the forbidden paid routing/geocoding calls — they are free vendor metadata
fetches — but they are still **egress from a host whose boundary is unverified**, and they were not
disclosed in the repair proposal.

---

## 5. What remains unknown

| Item | Status |
| --- | --- |
| `system_cron`, `node_cron`, `comment_cron`, `history_cron`, `field_cron` bodies | **REQUIRES_VERIFICATION** — identified as applicable but not read individually. All are standard core maintenance (history pruning, purge of already-deleted field data, queue/temp/cron-key housekeeping); none is known to touch orders, submissions, reservations or payments, but that is not established here. |
| Whether the Commerce inbox fetcher self-throttles | **REQUIRES_VERIFICATION** — no gate visible in the hook; the call may be unconditional per cron run. |
| Exact `search` re-index write volume | **REQUIRES_VERIFICATION** — index is populated; per-run `cron_limit` not read. |
| Whether `advancedqueue_cron` degrades gracefully against the missing table | **REQUIRES_VERIFICATION** — expected to log and continue, not exercised. |
| Whether any cron path on the **default** site instantiates `custom_webform_handlers.dispatch_checkout_manager` | **REQUIRES_VERIFICATION** — the default site's compiled container holds **15** arguments, so such a path would raise `ArgumentCountError` (logged per-implementation, not necessarily fatal to the request). Not traced. |
| Behaviour of a second and subsequent cron run | **Out of scope** — this audit covers the *first* run after a service start. |

**Source-level vs state-backed:** every "WILL NOT" in §1 and §4 is **state-backed** — it rests on a
measured value (config setting, row count, queue depth), each with a control. Every "LIKELY" or
"REQUIRES_VERIFICATION" is **source-level only** — the code path exists but current state does not
settle whether or how much it acts.

---

## 6. Is a separate cron mitigation decision needed?

**Yes.** Not because protected data is at risk — §1 clears every protected path with state-backed,
controlled evidence — but because three outbound calls and a log-table deletion are **effects the
authorizer has not been shown**, and one of them (egress) touches an explicitly unverified boundary.

Options, none performed or implemented here:

1. **Accept** §4 as disclosed, acknowledging §5's unenumerated core-maintenance remainder.
2. **Close §5 first** with a further read-only pass over the five unread core cron bodies and the
   Commerce fetcher's gating — the smallest step that would make the picture complete.
3. **Mitigate** by setting `automated_cron.settings.interval` to 0 before the service start. This is
   an **application-configuration edit**, which this task is explicitly forbidden to make
   ("Do not change configuration to disable cron") and which Option A does not authorize. It would
   need its own approval and its own preservation evidence.

---

## 7. Scope

This audit resolves the **P5** prerequisite only. It does **not** resolve, weaken or partially
satisfy **B2** (activated wiring set), **B3** (runtime atomicity and safe disposal after a failed
rollback), **B4** (backup/restore), **B6** (isolation target manifest), or **Task 26B default-site
activation**, which remains **BLOCKED / NOT AUTHORIZED**. **Task 25A verdict B** and Tasks
**25B–25D** remain unchanged and **BLOCKED**. **B5 remains diagnosed and prepared, but not
repaired.**

Findings are scoped to the container generation of 2026-10-08T13:23Z and the data state measured at
02:35–02:58Z on 2026-10-09. Queue depths, `watchdog` counts and cron-last timestamps are **live
values** and must be re-measured immediately before any service start.

---

## 8. Commands run (all read-only; exit codes as observed)

| Purpose | Command shape | Exit | Result |
| --- | --- | --- | --- |
| Query-first orientation | `graphify query "hook_cron queue worker cron event subscriber scheduled task"` | 0 | Oriented the custom-module surface; establishes nothing for core/contrib (coverage limit) |
| Governing rules | `rtk read …/commerce-webform-bridge/RULES.md` | 0 | §2, §7 constraints |
| **DBHub availability** | `mcp__dbhub__search_objects` (table, pattern `config`) | ok | **Available**; scoped to schema `db` |
| DBHub authoritative module count | `mcp__dbhub__execute_sql` on `db.config` `core.extension` | ok | `a:68:`, blob 1,645 B |
| DBHub `qa` attempt | same, `UNION` across `db` + `qa` | **denied** | `SELECT command denied to user 'dbhub_ro'` for `qa.config` — correct least privilege |
| DBHub ECA models | `mcp__dbhub__execute_sql` on `db.config` `eca.eca.%` | ok | 2 models |
| Module lists, both sites | `rtk docker exec ddev-ppu-db … mysql … core.extension` + `grep`/`sed`/`comm` | 0 | 70 raw → **68** after removing theme entries; sites identical |
| `hook_cron` procedural scan | `grep -E "^function <m>_cron\("` over 68 modules, with positive control | 0 | 14 found |
| `hook_cron` attribute scan | `grep -rln "Hook('cron')"` | 0 | 5 contrib files; 2 excluded as not enabled |
| QueueWorker cron scan | `grep -rln QueueWorker` + `grep -qE "cron\s*[:=]"` | 0 | 15 candidates → 3 applicable |
| Enablement verification | `grep -qx` per module against the active list, with positive **and** negative controls | 0 | §2.3 |
| Queue depths **by name only** | `SELECT name, COUNT(*) … GROUP BY name`; `advancedqueue_job` existence via `information_schema` | 0 | `queue` empty both sites; `advancedqueue_job` absent |
| ECA trigger plugins | `grep -oE '"plugin";s:[0-9]+:"…"'` on the two model blobs — **plugin IDs only, no payloads** | 0 | §3.3 |
| Advanced Queue processors | `config LIKE 'advancedqueue.advancedqueue_queue.%'` with control | 0 | 3 queues, all `processor=cron` |
| **Order-type `cart_expiration`** | `config LIKE 'commerce_order.commerce_order_type.%'`, `LIKE '%cart_expiration%'` with a **matching** control (`workflow`) | 0 | **absent on both types, both sites** |
| Webform purge | per-form literal `"purge";s:N:"…"` values | 0 | `none` ×3, both sites |
| Cron bodies | `grep -n` + `sed -n "L,+Np"` on file/dblog/update/announcements_feed/search/advancedqueue/commerce/webform/commerce_cart/Cron.php | 0 | §3.1 |
| State gates | `system.file`, `dblog.settings`, `update.settings`, `announcements_feed.settings`; `key_value` `update.last_check`, `announcements_feed.last_fetch`; counts of `watchdog`, `file_managed`, `search_index` | 0 | §3.1, §4 |

Database access was limited to the records this audit needs: active `config` rows (`core.extension`,
`automated_cron.settings`, `system.file`, `dblog.settings`, `update.settings`,
`announcements_feed.settings`, `webform.webform.*`, `commerce_order.commerce_order_type.*`,
`eca.eca.*`, `advancedqueue.advancedqueue_queue.*`), two `key_value` state rows, and aggregate counts
over `queue`, `watchdog`, `file_managed`, `search_index` and
`custom_webform_handlers_review_request`. **No customer record, no serialized queue payload, and no
submission content was read or printed.** No credential was typed or printed; the `db` container
authenticated from its own `~/.my.cnf`.

**Difficulties, none changing state:** (1) my module-list regex over-counted by 2 by matching theme
entries — caught by the DBHub authoritative count. (2) A heredoc-based awk cron-body scanner returned
0 lines for every module (the recurring backslash mangling on this host); replaced with the
`grep -n` + `sed` approach. (3) The first `cart_expiration` check used a control token absent from the
blob, so its negative proved nothing; re-run with a token present in every row. (4) `SUBSTRING` with
a wrong offset truncated order-type names to `fault`/`spatch`; replaced with `REPLACE`. (5) A
`CONCAT` containing `SUM(status=0)` over an empty `file_managed` returned `NULL`, hiding the count;
re-queried as a plain `COUNT(*)` → **0**.
