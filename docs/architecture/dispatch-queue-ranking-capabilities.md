# Dispatch queue, reaction layer and Service Type ranking — Capability Ledger and Reuse Decision Matrix

Task 8 of the Express Courier / Commerce roadmap. Written **before** any code, as the task
requires, and recording what the installed platform already provides so the build adds only
what is missing.

Companion documents: [`express-courier-dependencies.md`](express-courier-dependencies.md)
(Task 2, whose ledger format this follows) and
[`express-courier-current-state.md`](express-courier-current-state.md).

---

## 1. Method and evidence coverage

| System | Used for | Result |
| --- | --- | --- |
| Graphify | Reverse traversal on the notification, form and route seams | Usable. Graph reflects the working tree. |
| GitNexus MCP | Blast radius | **Failed, twice over — contributed nothing.** As first found the index was dated 2026-08-16 and pinned to commit `c365148f` (145 files / 996 nodes), so the whole uncommitted notification, review, checkout and ranking surface was absent and `impact(DispatchNotificationDispatcher)` answered `Target not found`. Re-indexed per policy with `node .gitnexus/run.cjs analyze . --force --skip-agents-md`, which completed successfully (exit 0, full rebuild). Every read since then fails: `LadybugDB unavailable … Cannot open database in read-only mode while checkpoint is in progress`, via both the MCP tool and the CLI, still failing after a 45 s wait. A long-lived GitNexus process appears to hold the checkpoint — the same class of lock recorded for `graphify watch`. **The fresh index exists on disk and is unreadable this session.** |
| Context7 MCP | Advanced Queue, ECA and Drupal 10.6 API shapes | Superseded by direct reads of the installed contrib source, which is the authoritative copy for the exact versions running here. |
| DBHub MCP | Schema discovery | Not reached in this pass; schema read from `custom_webform_handlers.install` and live Drush instead. Task 2 recorded DBHub failing with `-32000: MCP error -32000: Connection closed`. |
| Direct YAML / Drush | Routing, services, libraries, config, queue state | Primary source. Per the project Graphify rule, YAML and Markdown are outside the graph's extraction coverage, so absence of a YAML edge proves nothing. |

**Graphify coverage limits that matter to this task.** `affected` returned *no inbound nodes*
for `QueueDispatchNotifier`, `AddressDistanceHandler::alterForm()` and
`GoogleRoutesDistanceProvider`. That is the documented limitation, not an absence of
callers: all three are reached through wiring the AST pass cannot see — a `services.yml`
alias for the first and third, and Webform's own plugin invocation for the second. What
Graphify *did* establish, as `EXTRACTED` edges:

- `DispatchNotifierInterface` has exactly one implementer, `QueueDispatchNotifier`
  (`QueueDispatchNotifier.php:29`). Changing how that class enqueues changes no caller.
- `DispatchNotificationDispatcher` has exactly one code-level consumer,
  `DispatchNotificationWorker` (`DispatchNotificationWorker.php:10`, `:40`). That single
  edge is the whole of the legacy delivery path, and it is what requirement 1 replaces.
- `alterForm()` has no in-module caller, so a reorder placed inside it cannot be bypassed
  by another in-module path.

**Labelled blocker: GitNexus contributed no evidence to this task.** Recording it plainly
rather than implying the impact analysis had two independent sources. The project policy
treats an MCP that "returns unusable results" as a blocker *for the part of the task that
requires it* — here, blast radius on the four seams the build touches. That part is covered,
by a system whose coverage is strictly better for this question: Graphify reflects the
**working tree**, where GitNexus's readable index reflected a commit from twelve days ago
that predates every class involved. So the substitute is not a downgrade for this specific
need. Two things follow, and both are honoured below:

- Every dependency claim in §2.5 and §3 cites either a Graphify `EXTRACTED` edge or a direct
  file-and-line read. None rests on GitNexus.
- The re-index itself printed two limitations that argue against reading any absent edge as
  proof, in either tool: `182 property read/write site(s) name a field that IS defined in
  this workspace, but only in another language … an empty result does NOT mean "unused"`,
  and `335 flows reported, but whole flows are MISSING: 388 of 588 candidate entry point(s)
  never ranked … An absent flow does NOT mean the code path does not exist.` Accordingly no
  decision in §3 is justified by an absence alone; each names the positive evidence for it.

---

## 2. Capability Ledger

### 2.1 Advanced Queue

| Capability | Availability / evidence | Use in this task | Outcome |
| --- | --- | --- | --- |
| Module installed | `advancedqueue` enabled; Task 2 already declares it an enforced dependency of this module. | Sole queue runtime. | Reused. |
| Live queue | `drush advancedqueue:queue:list` → `custom_webform_handlers_dispatch`, all counters `0`. `drush cget` → `backend: database`, `lease_time: 300`, `processor: cron`, `processing_time: 90`, `threshold: {type: 0, limit: 0, state: all}`, `locked: true`, `stop_when_empty: true`. | Carries notification delivery. | **Reused unchanged.** |
| SQL durability | Backend plugin `database`, table `advancedqueue`. | Job state, retries and failure history stay in SQL. | Satisfies "the durable launch backend remains SQL". |
| Redis backend | None installed, and **Task 2 documented and approved none**. | — | **Not added.** The constraint forbids it absent Task 2 approval. |
| JobType plugin API | PHP attribute `#[AdvancedQueueJobType(id, TranslatableMarkup $label, max_retries = 0, retry_delay = 10, allow_duplicates = TRUE)]` (`src/Attribute/AdvancedQueueJobType.php:31-37`). `JobTypeInterface::process(Job): JobResult`. | Three job types. | Native plugin, no custom base. |
| Retry / backoff | `JobResult::failure($message, $max_retries, $retry_delay)`; per-type defaults in the attribute. | Requirement 3. | Native. |
| Permanent failure | `Job::STATE_FAILURE` once attempts exceed `max_retries`; `JobResult::failure()` with `max_retries = 0` fails permanently on the first attempt. | Classification of unroutable work. | Native. |
| Duplicate detection | `Database` backend implements `SupportsDetectingDuplicateJobsInterface` (`Backend/Database.php:21`). `Queue::prepareJob()` consults `allow_duplicates`, sets the fingerprint from `JobTypeBase::createJobFingerprint()` (`hash('tiger128,3', queueId . type . serialize(payload))`) and calls `getDuplicateJobs()` (`Entity/Queue.php:171-183`). | Enqueue-side coalescing on all three job types. | Native; `allow_duplicates: FALSE`. |
| Duplicate scope | `getDuplicateJobs()` filters `state IN (queued, processing)` only (`Backend/Database.php:227`). | Collapses *pending* identical work without ever blocking tonight's re-run after last night's success. | Exactly the needed semantics. |
| Duplicate rejection | Default `handleDuplicateJobs()` throws `DuplicateJobException` (`JobType/JobTypeBase.php:55`). | Caught at each enqueue seam and treated as "already pending". | Not overridden — an explicit catch is clearer than a NULL return through `prepareJob()`. |
| Worker command | `drush advancedqueue:queue:process <queue_id> [--timeout=N]`, default 90 s, `pcntl` SIGTERM/SIGINT aware. | Requirement 9. | Native; no custom Drush command. |
| Inspection | `drush advancedqueue:queue:list`. (`advancedqueue:list` does not exist.) | Requirement 9 health checks. | Native. |

### 2.2 Redis

| Capability | Availability / evidence | Use in this task | Outcome |
| --- | --- | --- | --- |
| Backend live | `sites/default/settings.php:915-917` includes `settings.ddev.redis.php`, which sets `$settings['cache']['default'] = 'cache.backend.redis'` **and** a `bootstrap_container_definition` placing `cache.container` and `cache_tags_provider.container` on Redis. Live: `get_class(\Drupal::service('cache.default'))` → `Drupal\redis\Cache\PhpRedis`. | Ranking and route read caches land on Redis automatically through `@cache.default`. | Reused. |
| Access method | Drupal cache API only. No raw PhpRedis client anywhere in the module. | Keeps Redis an implementation detail of a bin. | No client added. |
| Lock backend | Drupal lock, already Redis-eligible through the same settings include. | Untouched — planner capacity locking is Task 3/5 authority and is not being moved. | Not changed. |
| Existing versioned keys | `GoogleRoutesDistanceProvider::CACHE_PREFIX = 'custom_webform_handlers:route:v3:'`; cid is `PREFIX . hash('sha256', …)` (`:298`). | Route precompute writes through the *existing* cache. | **Requirement 8 already satisfied for routes.** |
| Existing bounded TTL | `:246-248` — `max(60, min(3600, route_scheduled_cache_ttl ?: 900))` for scheduled, `max(60, min(86400, route_cache_ttl ?: 3600))` otherwise. | Same clamp shape reused for ranking. | Reused pattern. |
| Failure semantics | Redis holds **no** capacity, payment or Reservation state today, and this task adds none. | Requirement 8. | Structural, not defensive. |
| **Outage caveat** | Because the bootstrap container cache is on Redis, stopping Redis is a **site-wide bootstrap failure**, not a graceful homepage degradation. | Verification step 5 must be reported as what it actually proves. | See §5. |

### 2.3 ECA

| Capability | Availability / evidence | Use in this task | Outcome |
| --- | --- | --- | --- |
| Core module | `eca` 2.1.23 + `eca_ui` enabled. | Reaction layer runtime. | Reused. |
| Submodules | `eca_base`, `eca_content`, `eca_workflow`, `eca_queue`, `eca_log`, `eca_misc`, `eca_modeller_bpmn` all **disabled**. | — | **None enabled.** No new contrib module is required, and none is added. |
| Config-only models | `@EcaModeller(id = "fallback")` (`src/Plugin/ECA/Modeller/Fallback.php`); `eca.eca.*` schema is `id, modeller, label, version, weight, events, conditions, gateways, actions` (`config/schema/eca.schema.yml`). | Two models shipped as config with `modeller: fallback`. | Executable without a BPMN modeller. |
| Event plugins | `#[EcaEvent(id, event_name, event_class, subscriber_priority, tags, label, category, deriver, description, …)]`. `Drupal\eca\EventSubscriber\DynamicSubscriber` subscribes to whatever event names enabled ECA event plugins declare. | One deriver-based plugin exposing this module's two post-commit domain events. | Module-provided plugin; no contrib event needed. |
| Event filtering **without** `eca_base` | No condition plugins ship in ECA core, so `to_state`-style discrimination cannot use a condition. `eca_workflow`'s `WorkflowEvent` shows the sanctioned alternative: the event plugin itself exposes configuration and only fires on a match. | The review-transition event plugin exposes `to_state`; the reservation event exposes `resource_id`. | Native pattern, copied from ECA's own submodule. |
| Actions | ECA's action manager consumes core `#[Action]` plugins natively. `Drupal\eca\Service\Actions::actions()` accepts any definition without `confirm_form_route_name` (`:92-102`); `type` is only used to decide whether to render an entity-token field (`:177-186`), so a non-entity action needs no `type`. | Two core `#[Action]` plugins that enqueue maintenance work. | Native; no ECA-specific action base. |
| Post-commit guarantee | Drupal 10.6.15 ships `TransactionManagerInterface::addPostTransactionCallback()` (`TransactionManagerBase.php:407`). | Domain events dispatch from that callback, so they fire only after the authoritative transaction commits. | **Native core seam** — requirement 4 needs no custom transaction bookkeeping. |

### 2.4 Commerce and Webform

| Capability | Availability / evidence | Use in this task | Outcome |
| --- | --- | --- | --- |
| Dispatch order type | `commerce_order.commerce_order_type.dispatch.yml:26` → workflow `order_fulfillment`. Order type `dispatch`, item type `dispatch_service` (`DispatchOrderFactory.php:46,51`). | Population for the popularity aggregate. | Read only. |
| Order state reachable | Checkout applies only `place`. **Nothing in the module ever applies `fulfill`,** so no dispatch order reaches `completed`. | A literal `state = 'completed'` aggregate would return nothing. | **Deviation — see §4.1.** |
| Service Type on the order | **Absent.** No order or order-item field records it. Order `data` carries only `customer_email_overridden` (`:98`) and `dispatch_submission_id` (`:105`). | Aggregate must join out to the submission. | Join path below. |
| Service Type of record | `webform_submission_data` where `name = 'service_type' AND property = '' AND delta = 0`; value is the machine name. | Aggregate group-by key. | Read only, aggregate counts only. |
| Paid signal | `custom_webform_handlers_checkout_attempt.state` (`pending, paid, failed, cancelled, reconciliation_required`, `CheckoutState.php:43,73`) with nullable `settled`. Unique keys on `order_id`, `idempotency_key`, `dispatch_reservation_id`; index `attempt_submission` on `(submission_id, state)`. | Rolling-window cutoff and the order↔submission bridge. | Read only. |
| Enabled Service Type universe | `config/install/custom_webform_handlers.dispatch.yml` — `self_bookable.service_types: [courier]`; `manual_review.service_types: [on_demand_delivery, local_residential_move, local_commercial_move, long_distance_move, specialty_item_move, diy_truck_rental]`. | The set to rank, **and** the deterministic configured baseline order. | **Reused as the fallback order — no new config list needed.** |
| Service Type element | `request_fields.service_type: service_type` (`:22`). Element is `#type: radios`, `courier` pre-pended and `''` option removed by `update_10016` (`.install:1387-1411`), class `custom-service-cards`. `update_10016` never authors `#options` and never sets `#options__properties`. | Render-time reorder target. | Uncontested. |
| Render seam | `AddressDistanceHandler::alterForm()` (`:639`) is the only public-form seam. `:658` attaches `custom_webform_handlers/service_cards` **above both early returns** (`:667-682` missing address fields, `:771-778` empty browser key). | Reorder inserted immediately after `:658`, so it runs on every load. | Single insertion point. |
| Competing orderers | None. No `hook_form_alter` in `.module` (only `hook_mail` at `:23` and `hook_cron` at `:68`); no `#pre_render` / `#process` / `#after_build`; `service_cards` library is **CSS-only** (`libraries.yml:130`); no JS reads or reorders `service_type`. | Requirement 7's "no second selector, no client-side ranking authority". | Structurally satisfied. |

### 2.5 Existing custom seams

| Seam | Evidence | Use in this task | Outcome |
| --- | --- | --- | --- |
| `DispatchNotifierInterface::notify()` | One implementer, `QueueDispatchNotifier` (Graphify `implements` edge). **The single enqueue site in the module** — grep for `createItem` finds only this one and `DispatchOrderFactory::createItem()`, which builds a Commerce order item. | Switch its body from `QueueFactory` to Advanced Queue. | **This is why dual-enqueueing is structurally impossible** — there is exactly one place that could do it. |
| `DispatchNotification` payload | 13 scalars (`toQueueItem()` `:297-313`); `fromQueueItem()` `:331-357` treats a stored item as untrusted input; `identifier()` bounds a machine name to 128 chars and `[a-z0-9_]`; `clip()` bounds detail to 255. Docblock: no address, name, telephone, price, Hold token, owner hash or session id. | Notification job payload. | **Requirement 2's prohibition already holds by construction.** Only a payload `version` and an idempotency key are added. |
| `DispatchNotificationDispatcher::deliver()` | `:58-84` — loops `event->audiences()`, composes, sends, records exactly one history row per attempt including skips. Never throws. | Called by the new JobType; the per-audience loop is where idempotency must sit. | Reused; delivery logic not rewritten. |
| `NotificationLogInterface` / `DatabaseNotificationLog` | Table `custom_webform_handlers_dispatch_notification`; insert + select only, no UPDATE, no DELETE; `record()` swallows every throwable so a history write cannot turn a delivered message into a duplicated one; `DETAIL_MAX_LENGTH 255`, `READ_LIMIT 50`. | Requirement 3's outcome record. | Reused. One narrow additive method and one nullable column + unique key — see §4.2. |
| `NotificationOutcome` | `Sent` / `Failed` / `Skipped`, with `needsAttention()`. | Queue outcome classification reuses these three values rather than inventing a parallel vocabulary. | Reused. |
| `DispatchNotificationWorker` | Core `#[QueueWorker(id: 'custom_webform_handlers_dispatch_notification', cron: ['time' => 30])]`. Graphify: the only consumer of the dispatcher. | Kept **drain-only** so in-flight legacy items still deliver; loses its enqueue source. | Deleting it is legacy cutover, i.e. Task 9. Not touched here beyond leaving it able to drain. |
| `ScheduledRouteProviderInterface::calculateScheduled()` | `GoogleRoutesDistanceProvider:112`; writes the versioned, TTL-clamped cache entry itself. | Route precompute = call this for a bounded pair set. | **Reused — the precompute job warms an existing cache and adds no cache of its own.** |
| `ReviewManagerInterface` | Registered by interface in `services.yml`, the module's own precedent for a narrow public contract. Its docblock records that no reaction rule is given a reference to it, "which is how item 1's 'not in forms or ECA' is enforced: there is no state-setting operation to invoke." | The precedent to copy for the queue-facing interface ECA actions call. | Followed. **ECA is still given no reference to `ReviewManagerInterface`.** |
| `hook_cron` | Already exists (`.module:68-80`), added by Task 7 for review expiry. | Nightly popularity enqueue and the AQ cron processor hang off existing cron; no new scheduler. | Extended, not replaced. |
| Update hooks | Highest installed is `custom_webform_handlers_update_10023()` (`.install:1968`); live `system.schema` = **10023**. | Next hook is **10024**. | **Task brief says "10020" — that is stale. See §4.4.** |

---

## 3. Reuse Decision Matrix

"Prefer native Drupal plugins, handlers, event subscribers, queue workers, and ECA actions.
Add narrow custom Interfaces only where no supported contrib seam exists."

| Need | Contrib / core seam considered | Decision | Why |
| --- | --- | --- | --- |
| Durable job runtime | Advanced Queue `database` backend | **Reuse** | Already live and Task-2-approved. Redis backend forbidden absent Task 2 approval. |
| Notification queue | Existing `custom_webform_handlers_dispatch` | **Reuse unchanged** | Correct settings already: `locked`, `stop_when_empty`, 90 s budget, 300 s lease. |
| Bulk / nightly queue | Same queue vs. a second queue | **Add one queue**, `custom_webform_handlers_maintenance` | `processing_time` is per queue. A route-warm batch of *n* Google calls inside the notification queue's 90 s budget would push a booking confirmation to the next cron run — on an hourly cron, an hour's delay for the customer. Separating budgets is the only way to keep the latency guarantee the queue existed to provide. |
| Job definitions | `#[AdvancedQueueJobType]` | **Native plugin ×3** | No custom base class; `JobTypeBase` supplies fingerprinting. |
| Retry / backoff / permanent failure | Attribute defaults + `JobResult::failure()` | **Native** | Nothing to build. |
| Enqueue-side dedupe | `allow_duplicates: FALSE` + `SupportsDetectingDuplicateJobsInterface` | **Native** | Collapses identical *pending* work; scope is `queued`/`processing` only, so nightly re-runs still enqueue. |
| Process-side dedupe (replay safety) | None in contrib | **Narrow addition** — one nullable column, one unique key, one interface method | AQ's fingerprint stops a second *enqueue*; it cannot stop a *replay* of an already-leased job. Claim-before-send is the only way a replayed notification job sends nothing twice. See §4.2. |
| Post-commit event dispatch | `TransactionManagerInterface::addPostTransactionCallback()` | **Native core** | Requirement 4's "only after their authoritative transaction commits", guaranteed by core rather than by convention. |
| Domain events | Symfony `Event` + `EventDispatcherInterface` | **Native** ×2 event classes in `src/Event/` | Plain events; ECA subscribes via `DynamicSubscriber`. |
| ECA reaction | `eca_workflow` / `eca_content` | **Not enabled, not added.** ECA core + module-provided `#[EcaEvent]` + core `#[Action]` + `modeller: fallback` | Zero new contrib modules. `eca_workflow` reacts to core workflows; the Review Request state machine is this module's own, so its own event plugin is the correct provider. |
| ECA event filtering | condition plugins (`eca_base`) | **Event-plugin configuration instead** | `eca_base` is disabled; `eca_workflow::WorkflowEvent` establishes event-level `from_state`/`to_state` configuration as ECA's own sanctioned pattern. Avoids enabling a module for one comparison. |
| ECA-callable surface | `ReviewManagerInterface` | **New narrow interface**, maintenance-enqueue only | Requirement 4 forbids ECA writing planner tables, Reservations, Checkout Attempts, payments or submission data. An interface whose only verbs enqueue non-authoritative background work cannot violate that, and `ReviewManagerInterface` stays unreferenced by ECA. |
| Route precompute cache | `GoogleRoutesDistanceProvider`'s existing `custom_webform_handlers:route:v3:` | **Reuse** | Already versioned and TTL-clamped. The job calls `calculateScheduled()`; the provider warms itself. |
| Ranking durable store | `State` (SQL `key_value`) | **Reuse core State** | The ranking *fact* must survive a Redis flush. State is SQL, so Redis is never the only copy. |
| Ranking read cache | `@cache.default` (Redis) with `custom_webform_handlers:ranking:v1:` | **Reuse the route provider's pattern** | Same versioned-prefix + clamped-TTL shape; no new bin, no new service. |
| Ranking reader contract | none | **New narrow interface** `ServicePopularityInterface` | Requirement 5 asks for it explicitly ("small popularity-reader Interface"). |
| Configured fallback order | new config list vs. existing dispatch profile | **Derive from the existing profile** | `self_bookable.service_types` then `manual_review.service_types`, in config order, `courier` forced first. A second list would be a second source of truth that could disagree with eligibility. |
| Express Courier pinning | config flag vs. constant | **PHP constant** | Requirement 6 says *permanently* rank 1. A config flag is an invitation to unpin it. |
| Worker process | custom Drush command / systemd | **`drush advancedqueue:queue:process`** | Already exists, already signal-aware. |

---

## 4. Interpretations and documented deviations

### 4.1 "Completed/paid Dispatch Commerce orders" is read as **paid**

Requirement 5 says to recompute "from completed/paid Dispatch Commerce orders". Taken
literally against `state = 'completed'` the aggregate returns nothing, because checkout
applies only the `place` transition and nothing in the module ever applies `fulfill`. The
`order_fulfillment` workflow has a `completed` state that no code path reaches.

So the population is **paid**, and it is read from the module's own settlement record rather
than from the order workflow:

```
custom_webform_handlers_checkout_attempt a
  state IN ('paid', 'reconciliation_required')
  AND a.settled BETWEEN :window_start AND :window_end
JOIN commerce_order o ON o.order_id = a.order_id AND o.type = 'dispatch'
JOIN webform_submission_data d
  ON d.sid = a.submission_id
 AND d.name = 'service_type' AND d.property = '' AND d.delta = 0
GROUP BY d.value
```

`reconciliation_required` is included because it means *money was taken* and the capacity
side needs a human — excluding it would under-count exactly the orders that most certainly
paid. Only `COUNT(*)` per `d.value` leaves the query; no submission id, no order id, no
amount.

### 4.2 The audit log gains a delivery-claim, and only that

Advanced Queue's fingerprint prevents a duplicate *enqueue*. It cannot prevent a *replay* —
a job whose lease expired mid-send, or one an operator retries. Replaying today would send
a second copy, because the send happens before `record()` and `record()` has no unique key.

Addition: a nullable `idempotency_key` column on
`custom_webform_handlers_dispatch_notification` plus a unique key over
`(idempotency_key, audience)`, and one narrow method on `NotificationLogInterface` that
claims that pair and reports whether the claim was new. The dispatcher claims before
composing; a failed claim means the copy was already attempted, so it does nothing. The key
is **derived from the payload**, not random, so a replay computes the same key.

Nullable is deliberate: MariaDB permits repeated NULLs in a unique index, so legacy rows and
any un-keyed record still insert freely — the same property `attempt_intent` already relies
on.

This makes the log the authority on *whether a message was already attempted*. It does not
make it a capacity, payment or availability authority: nothing reads it to decide whether
time is free, whether money was taken, or whether a Reservation stands.

### 4.3 Redis is the read cache; State is the fact

Requirement 7 wants a Redis miss to fall back to deterministic configured ordering, while
requirement 8 confines Redis to read caches. Both hold at once because the reader has three
layers, each deterministic:

1. **Redis** (`@cache.default`, `custom_webform_handlers:ranking:v1:…`) — rendered order.
2. **State** (SQL) — the durable snapshot the nightly job wrote.
3. **Configured order** — `courier`, then the dispatch profile's own lists in config order.

A Redis miss or a thrown cache exception re-derives from State with no customer-visible
change. If State holds no usable snapshot — never recomputed, or total samples below the
configured minimum — the reader returns layer 3. A miss therefore can never produce a
*non-deterministic* order, and Redis is never the only copy of anything.

### 4.4 The next update hook is 10024, not 10021

The brief states "the current highest installed hook is `10020`". It is `10023`
(`.install:1968`), and live `system.schema` for this module is `10023`. Following the
brief's own instruction to inspect before choosing, the new hook is
`custom_webform_handlers_update_10024()`.

### 4.5 ECA models ship in `config/optional/`, not the config-sync directory

The brief names "ECA config exports in the configured config-sync directory". That directory
is
`sites/default/files/config_2AIVzI5WCoXofGiZWYtIMGj_nYjhmdRatBj_HsNJ_m-D6Wm0S-njBYa97hJmyOL0ChgbMarkOg/sync`
— un-versioned, inside public files, and currently empty. Shipping there would put the
reaction layer outside Git and inside a web-served directory.

The models ship in the module's `config/optional/` instead, mirroring how Task 2 shipped the
Advanced Queue queue. `config/optional/` is chosen over `config/install/` so a site without
`eca` enabled still installs cleanly.

### 4.6 Route precompute must read the **active** resource profile

`config/install/custom_webform_handlers.dispatch.yml` ships a Mon–Fri 08:00–18:00 + Sat
profile, but the **active** profile on this install is Mon–Wed 01:00–05:20. A precompute job
that assumed the shipped profile would warm cache entries for departure buckets the planner
never asks about. The job derives its bounded pair-and-departure set from the active profile
through the existing `ResourceProfileFactory`, and warms nothing when the profile offers no
open interval in the horizon.

---

## 5. Selected worker command and process

No dedicated worker container, no `.ddev/` service, no systemd unit. Both queues are
`processor: cron`, so ordinary cron already drains them; the explicit command exists for
bounded, observable runs.

| Purpose | Command |
| --- | --- |
| Drain notifications, bounded | `ddev exec vendor/bin/drush advancedqueue:queue:process custom_webform_handlers_dispatch --timeout=30` |
| Drain maintenance, bounded | `ddev exec vendor/bin/drush advancedqueue:queue:process custom_webform_handlers_maintenance --timeout=60` |
| Inspect counters | `ddev exec vendor/bin/drush advancedqueue:queue:list` |

`--timeout` is chosen over an unbounded run deliberately: an unbounded process holds a lease
for as long as it lives, and a bounded invocation is what the verification steps can
actually assert on. A dedicated always-on worker is **not** added, because nothing in this
task has a latency requirement tighter than a cron interval, and an extra long-lived process
would be a new operational surface with no capability behind it. Full runbook: `docs/operations/dispatch-workers.md`.

---

## 6. What verification step 5 can honestly prove

The brief asks to "temporarily stop only the local DDEV Redis service, verify the homepage
retains deterministic ordering and checkout fails safely or remains authoritative, then
restore Redis immediately."

On this install the homepage cannot retain anything with Redis stopped. `settings.ddev.redis.php`
places `cache.container` **and** `cache_tags_provider.container` in the bootstrap container
definition, so Drupal cannot boot without Redis. Stopping it produces a site-wide failure,
not a degraded homepage. Reporting a graceful degradation from that experiment would be
reporting something that did not happen.

What is provable, and what will be reported instead:

- **Redis miss / cache exception → deterministic order.** Exercised by invalidating the
  ranking cache entry and by making the cache layer throw, both with the site up. This is
  the failure mode requirement 7 is actually about.
- **State-empty → configured order.** Exercised by clearing the ranking snapshot.
- **Below minimum samples → configured order.** Exercised by raising the threshold above the
  live sample count.
- **Redis holds no authority.** Structural, and checkable by inspection: no capacity,
  payment, Reservation, Checkout Attempt or availability decision reads a cache bin. The
  ranking cache holds an ordered list of machine names and nothing else.
- **The outage itself** will be run and reported as what it is: a bootstrap failure, which is
  a fail-closed outcome — with Redis down the site serves no checkout at all, so it cannot
  serve an unauthoritative one.

---

## 7. Surface to be built

Names fixed here so the build, the runbook and the completion report cannot drift apart.

### Queues

| Queue id | Status | Backend | Lease | Processor | Budget |
| --- | --- | --- | --- | --- | --- |
| `custom_webform_handlers_dispatch` | existing, unchanged | `database` | 300 s | cron | 90 s |
| `custom_webform_handlers_maintenance` | new | `database` | 600 s | cron | 60 s |

### JobType plugins — `src/Plugin/AdvancedQueue/JobType/`

| Plugin id | Queue | `max_retries` | `retry_delay` | `allow_duplicates` |
| --- | --- | --- | --- | --- |
| `custom_webform_handlers_dispatch_notify` | dispatch | 2 | 300 s | FALSE |
| `custom_webform_handlers_dispatch_route_warm` | maintenance | 1 | 900 s | FALSE |
| `custom_webform_handlers_dispatch_popularity` | maintenance | 1 | 1800 s | FALSE |

None collides with the legacy core QueueWorker id
`custom_webform_handlers_dispatch_notification`, which stays as a drain.

### Payloads

Every payload carries `v` (integer version, `1` at introduction) and scalars only.

| Job | Payload keys |
| --- | --- |
| notify | `v`, `k` (derived idempotency key), plus the 13 existing `DispatchNotification::toQueueItem()` scalars |
| route warm | `v`, `resource`, `origin`, `destination`, `departure_at`, `scheduled` |
| popularity | `v`, `window_days`, `window_end` |

No raw Webform answers, customer profiles, API keys, Hold tokens, payment data or serialized
entities appear in any of them — for the notification payload that is already guaranteed by
`DispatchNotification`'s constructor and validators.

### Domain events — `src/Event/`

| Event name | Class | Emitted from |
| --- | --- | --- |
| `custom_webform_handlers.review_request_transitioned` | `ReviewRequestTransitionedEvent` | post-commit callback around the Review Request transaction |
| `custom_webform_handlers.dispatch_reservation_confirmed` | `DispatchReservationConfirmedEvent` | post-commit callback around Reservation confirmation |

### ECA

| Model id | Reacts to | Action |
| --- | --- | --- |
| `custom_webform_handlers_reservation_confirmed` | reservation confirmed | enqueue route warm |
| `custom_webform_handlers_review_transitioned` | review transition, `to_state` filtered | enqueue popularity recompute |

Both `modeller: fallback`, shipped in `config/optional/`. Actions are core `#[Action]`
plugins calling one narrow interface whose only verbs enqueue maintenance jobs.

### Redis keys

| Prefix | Bin | TTL |
| --- | --- | --- |
| `custom_webform_handlers:route:v3:` | `cache.default` | existing clamp, 60–3600 s scheduled / 60–86400 s unaware |
| `custom_webform_handlers:ranking:v1:` | `cache.default` | new clamp, 60–86400 s from `ranking.cache_ttl` |

### Ranking — `src/Ranking/`

`ServicePopularityInterface` (reader), `ServicePopularitySnapshot` (value object),
`ServicePopularityAggregator` (the SQL of §4.1), a State-backed store, and a cached reader
implementing the §4.3 fallback chain. `courier` is pinned rank 1 by constant.

New config `custom_webform_handlers.ranking`: `enabled`, `window_days`, `minimum_samples`,
`cache_ttl`. Ordering baseline is derived from the existing dispatch profile, so no order
list is duplicated into this object.

### Update hook

`custom_webform_handlers_update_10024()` — installs the new queue and ranking config objects
on already-installed sites (`config/install/` only applies at install time) and adds the
`idempotency_key` column and unique key of §4.2. Idempotent, additive, and touching no Task
5/6/7 behaviour.
