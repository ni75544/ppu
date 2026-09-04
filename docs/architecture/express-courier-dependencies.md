# Express Courier — dependency and capability record

Companion to `express-courier-current-state.md`. That document records what the
system *was* at the start of Task 2. This one records what Task 2 *brought in*,
what it decided to reuse rather than rebuild, and which of the evidence systems
the project mandates were actually usable while doing it.

Scope note: Task 2 stabilises foundations. It does not implement Courier quote
calculation (Task 4), a payment owner (Tasks 3 and 6), or the new homepage
(Task 5). Nothing here activates the Dispatch Planner for a public visitor.

---

## 1. Capability Ledger

Every capability the task was expected to consult, whether it was actually
available, and what it was used for. A capability that failed is recorded as a
failure, not silently replaced.

| Category | Capability | Availability / evidence | Use in this task | Outcome, or task-specific reason not used |
| --- | --- | --- | --- | --- |
| Code graph | Graphify AST graph | Available. `graphify explain "BookingWorkflowGate"` returns the node at `src/Dispatch/BookingWorkflowGate.php:34`, degree 22, with `EXTRACTED` in-edges from both public controllers | Located the four pre-existing workflow-exclusion sites; confirmed after the change that `ReservationController` and `DispatchController` both reference the single gate | Used. Coverage limit: AST-only. YAML and Markdown are *not* indexed, so route, service, permission and schema facts below are labelled **read from source** |
| Code graph | `graphify update .` (default path) | **Partially failed.** Three consecutive runs ended `Rebuild failed: [WinError 32] … '..graph.tmp.json.<rand>.tmp'` — the clustering/visualisation step could not complete its atomic write on this Windows host | Needed to refresh the graph after new classes were added | Worked around inside the tool, not outside it: `graphify update . --no-cluster` succeeded (2360 nodes, 4491 edges). Consequence: community labels and `graph.html` are stale until someone runs `graphify cluster-only . --no-viz`. Derived artefacts only; both are git-ignored |
| Architecture / impact | GitNexus MCP | Reachable, but its index is pinned to commit `c365148` and blind to the uncommitted dispatch subsystem, which is most of the code under discussion | Would have been the impact-analysis source | **Not used, and cited for nothing.** An index that cannot see the files being changed cannot answer a blast-radius question about them. Re-index before relying on it in Task 3 |
| Framework docs | Context7 MCP | Reachable. Drupal core material resolves; there is no resolvable library for Advanced Queue or ECA | Drupal-side questions | Used for Drupal. For the Advanced Queue backend question the authority was the installed plugin itself (see §4), which is stronger evidence than documentation anyway |
| Database | DBHub MCP (read-only account) | **Failed.** `-32000: MCP error -32000: Connection closed`; no `mcp__dbhub__*` tool ever appeared | Schema discovery and row counts | **Blocker, recorded not hidden.** Substituted read-only `drush php:eval` against `\Drupal::database()->schema()` and `countQuery()`. That is a narrower capability — no query plans — but it is read-only and it is sufficient for existence and cardinality checks |
| Engineering method | Matt Pocock skills (`.agents/skills/`) | Available | `codebase-design` vocabulary (seam, adapter, leverage, locality) for this document and the class docblocks | Used. TDD not used: the project forbids running test suites unless explicitly requested, and no tests were requested |
| Drupal YAML / routing | Direct source inspection + read-only Drush | Available; the documented fallback where no MCP has coverage | Route requirements, service wiring, schema and install defaults | Used. Route facts verified through `router.route_provider` at runtime rather than by reading YAML alone — see §5 |
| Independent review | Bounded read-only sub-agent | **Unavailable.** Two attempts, both terminated by the gateway before the agent did any work: `Agent terminated early due to an API error: Failed to authenticate. API Error: 403 status code (no body)` | Was to independently identify the update, config, and controller seams under the same Graphify-first constraint | **Not used — recorded as unavailable, per the spec's "otherwise record why it is unavailable".** No substitute agent was invented and no finding in this document is attributed to one. The three seams were therefore verified first-hand instead; §9 states them with citations and is explicit that this is self-review, not independent review |

---

## 2. What was added

Composer constraints (`composer.json`), all resolved without
`--ignore-platform-reqs`; `composer validate --no-check-publish` reports valid
and `composer audit` reports no advisories:

```
drupal/address        ^2.0     drupal/advancedqueue  ^1.6
drupal/commerce       ^3.3     drupal/eca            ^2.1
drupal/geocoder       ^4.0     drupal/redis          ^1.11
```

The scaffold packages were the actual defect: `drupal/core-composer-scaffold`
and `drupal/core-project-message` were pinned at `11.4.5` against a `10.6`
core-recommended. Both were brought to `^10.6`, which is what made the graph
resolvable and the audit meaningful.

Enabled extensions after the task: **65 total — 42 core, 22 contrib, 1 custom.**
Contrib set, as reported by `extension.list.module`:

```
address 2.0.4                 advancedqueue 8.x-1.6
commerce 3.3.8                commerce_number_pattern 3.3.8
commerce_order 3.3.8          commerce_payment 3.3.8
commerce_price 3.3.8          commerce_store 3.3.8
eca 2.1.23                    eca_ui 2.1.23
entity 8.x-1.7                entity_reference_revisions 8.x-1.14
geocoder 8.x-4.35             inline_entity_form 3.0.0
profile 8.x-1.14              redis 8.x-1.11
state_machine 8.x-1.14        token 8.x-1.17
webform 6.3.0                 webform_node 6.3.0
webform_submission_export_import 6.3.0   webform_ui 6.3.0
```

The one custom module resolves at `modules/custom_webform_handlers` — not the
root-level duplicate, which was neither read as evidence nor edited.

Platform: Drupal 10.6.15, PHP 8.4.22, MariaDB 11.8, Drush 13.6.2, DDEV v1.25.3,
docroot `.`, so contrib installs to `modules/contrib/`.

---

## 3. Redis

DDEV add-on `ddev/ddev-redis` **v2.2.0** (`.ddev/addon-metadata/redis/manifest.yaml`),
service name **`redis`**, backend image redis:7, reachable in-container at
`redis:6379`, `Pass: <none>`, not published to the host.

Runtime proof rather than configuration reading — four core services are
Redis-backed:

```
cache.default                    Drupal\redis\Cache\PhpRedis
lock                             Drupal\redis\Lock\PhpRedis
flood                            Drupal\redis\Flood\PhpRedis
cache_tags.invalidator.checksum  Drupal\redis\Cache\RedisCacheTagsChecksum
redis client Redis, ping = true
```

**Consequence worth carrying forward: `flood` now lives in Redis.** Both public
booking controllers rate-limit through `flood` — `ReservationController` at
240/360/60/120/240 per hour per client and `DispatchController` at 240/60/240/120
— so flushing Redis resets every throttle counter. Redis is a cache tier, and a
throttle in a cache tier is a throttle somebody can clear. That is acceptable for
a local environment and it is not acceptable as the only defence in production;
whoever owns the launch environment needs to know that flushing the cache is also
un-throttling the booking endpoints.

---

## 4. The queue

`config/optional/advancedqueue.advancedqueue_queue.custom_webform_handlers_dispatch.yml`,
installed and verified from the container:

```
queue = custom_webform_handlers_dispatch
backend = database    processor = cron    locked = true
backend class = Drupal\advancedqueue\Plugin\AdvancedQueue\Backend\Database
```

`lease_time: 300`, `processing_time: 90`, `stop_when_empty: true`, with an
enforced dependency on `custom_webform_handlers`.

The backend is **SQL, deliberately**. Redis is present and is doing cache, lock,
flood and cache-tag work, and it would have been easy to describe the queue as
"Redis-backed" on the strength of that. It is not. Announcements about confirmed
capacity must survive a cache flush, so the queue sits in `advancedqueue`, a real
table, on the durable connection. No custom queue backend was written.

---

## 5. Schema and configuration

Baseline snapshot **`task02-pre-contrib-redis`** was taken before anything
changed, with `installed_schema: 10008`.

Installed schema is now **10013** and `updatedb:status` reports *No database
updates required* — so 10009 through 10013 all ran and nothing is pending.

| Table | Present | Rows |
| --- | --- | --- |
| `custom_webform_handlers_reservation` (legacy fixed-slot) | yes | **11 — unchanged from baseline** |
| `custom_webform_handlers_dispatch_reservation` | yes | 0 |
| `custom_webform_handlers_dispatch_guard` | yes | 1 |
| `custom_webform_handlers_dispatch_blackout` | yes | 0 |
| `custom_webform_handlers_dispatch_status` | yes | 0 |
| `custom_webform_handlers_dispatch_notification` | yes | 0 |

**Deliberate discrepancy against the spec's table names.** The spec names the five
planner tables in short form (`dispatch_reservation` and so on). The shipped
schema prefixes every one with the module name, which is the Drupal convention and
which the already-written update hooks and storage classes already use. Renaming
five live tables to match prose would be a destructive change to satisfy a
naming preference. The names above are the real ones; the spec's are the same
tables.

Configuration after the repair update (10013), read back from active config:

```
self_bookable.service_types = ["courier"]      # was ["m1"], an id no service uses
holds.hold_seconds           = 900             # positive, internal only
resource                     = driver-01, capacity 1, America/New_York
resource.working_hours       = days 1,2,3
notifications                = 6 events, 6 templates, enabled = false
notifications.recipients     = staff / customer_email_field / contact_line, all empty
```

No `timer`, `countdown`, `timezone` or `tz` key exists anywhere in the dispatch
config object. That is checked, not assumed: a case-insensitive scan of the
object's keys returns none. The 900-second hold window is a server-side lease on
capacity and is never sent to a browser, which is what keeps the product rule —
no public Hold countdown — a property of the data rather than a habit of the
front end.

Working hours and breaks were **not invented**. Days 1–3 is what the profile
actually says, and inventing Thursday through Sunday would have been an operator
decision disguised as a repair.

**The planner still fails closed on an incomplete profile.** With
`return_to_base` on and no depot address, `ResourceProfile::requiresDepotConfiguration()`
returns TRUE — demonstrated at runtime through a process-local `$GLOBALS['config']`
override that touched no stored value — and the planner refuses in both
directions: `DispatchPlanner.php:129` offers no Slots at all, and
`DispatchPlanner.php:805` returns `DispatchReason::NotConfigured` rather than
confirming. A rule that cannot be evaluated is not skipped.

---

## 6. One booking authority

### The problem

Two Hold families exist in two tables that cannot see each other: the legacy
fixed-slot rows and the planner's rows. Mutual exclusivity between them was real
but it lived only as a *convention* — a `dispatch_enabled` flag here, a
`reservation_enabled` flag there, checked in four scattered places, and consulted
by neither public controller. If both were ever live for one Webform, two guards
would be enforcing two different pictures of the same driver's day, and the day
would be sold twice.

### The seam

One new class answers the question once: **`BookingWorkflowGate`**, returning a
three-valued **`BookingWorkflow`** — `Legacy`, `Dispatch`, or `Disabled`.
`Disabled` is a first-class answer, not an error.

The gate **does not re-derive** either existing predicate. It calls them:

- `DispatchRequestFactory::dispatchOwns()` still decides what "dispatch is
  configured here" means.
- `ReservationDefinitionProvider::isEnabled()` still decides what "reservations
  are switched on here" means.

The single new decision is an explicit transfer allowlist,
`workflow.dispatch_webforms`, **shipped empty**. A Webform becomes planner-owned
because an operator named it, never because a handler checkbox happened to be
ticked. `decide()` is an if/else, so exactly one workflow comes back per Webform,
and a transferred Webform whose dispatch configuration is incomplete resolves to
`Disabled` rather than falling back to `Legacy` — a fallback there is how a form
ends up taking bookings into the table its operator believed it had stopped using.

### Where it is enforced

Twice, on purpose. A route access checker
(`_custom_webform_handlers_booking_workflow`) is the early guard on the six routes
that carry the Webform in the path; each controller asks the same authority again.
Core combines access checks with `AccessResult::andIf()` unconditionally
(`core/lib/Drupal/Core/Access/AccessManager.php:131-138`; no `_access_mode`
exists in D10.6), so adding the requirement can only narrow a route. The repeat
matters because an interlock that stops two workflows from selling the same
capacity should not be one YAML edit away from being switched off.

Verified at runtime through the real access manager, with a Webform transferred by
process-local override only (stored config confirmed unchanged afterwards):

| Webform | Gate says | Dispatch route | Legacy routes |
| --- | --- | --- | --- |
| transferred, dispatch complete | `dispatch` | allowed | **forbidden** |
| `hg` (live fixed-slot, 11 rows) | `legacy` | **forbidden** | allowed |
| `contact` (no booking handler) | `disabled` | **forbidden** | **forbidden** |

Unknown, malformed and empty Webform ids all resolve to `disabled`.

Controller-level guards: `DispatchController` calls `refuseUnlessDispatch()` in
all four callbacks; `ReservationController` calls `refuseUnlessLegacy()` in
`availability()`, `calendar()`, `hold()` and `renew()`, and `legacyReachable()` in
`release()`.

### Why `release()` is different

`release()` carries a Hold token and a session identity and nothing else. The
session receipt is filed under `ReservationSlot::activeSlotKey()`, which is a
SHA-256 digest and cannot be turned back into a Webform id. Accepting a Webform in
the body would not fix it either — a caller who wanted past the guard would simply
name a form that passes it. So `release()` asks the weaker question it can
actually answer, "is the fixed-slot workflow still enforcing for anything at
all", and that is sound because releasing *narrows* a claim rather than making
one. The worst a permitted release can do is hand back capacity the session
already held.

### Present effect: none, and that is the point

Because the allowlist ships empty, every existing Webform is `legacy` or
`disabled` right now, and all four dispatch routes are forbidden at the router.
Every refusal the gate produces is byte-identical to a refusal the code already
produced — `not_configured` / 404 on the dispatch side, `reservation_not_configured`
/ 422 on the legacy side — so a visitor cannot tell a transferred form from a
never-configured one, and the live fixed-slot workflow behaves exactly as it did
yesterday. Task 5 adds the homepage form to the allowlist once its offer-only
interface exists.

### Two deviations from the spec's canonical target list

1. **`custom_webform_handlers.routing.yml` was edited** although the spec does
   not list it. A route access checker cannot exist without a route requirement
   to apply to. Six routes gained one line each; nothing else in the file moved.
2. **The access checker was placed in `src/Dispatch/`**, not a new `src/Access/`,
   specifically to stay inside the canonical target list.

No interface was introduced for the gate, matching the `final class`,
no-interface precedent of its two siblings.

### No update hook for the new key

`workflow.dispatch_webforms` is absent from active configuration on this
already-installed site, and absent reads as empty, and empty means no Webform may
be `dispatch`. The safe default is therefore already in force without an update.
Adding 10014 to write an empty list would change nothing at runtime and would
contradict the expectation that 10013 is the last update. The key is present in
`config/install/` for fresh installs and declared in `config/schema/`.

---

## 7. Reuse Decision Matrix

Every responsibility Task 2 touched, and whether an existing extension was
configured, wrapped, or bypassed. `INSTALLED` means verified from the container;
`SOURCE` means read from YAML or PHP, which is the documented fallback where
Graphify has no coverage.

| Responsibility | Candidate | ID / class | Version | Duplicate capability? | Disposition | Selected owner / seam | Smallest glue | Evidence |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Dispatch Planner | existing custom | `Dispatch\DispatchPlanner` + `Storage\ResourceCalendarGuard` | in-repo, schema 10013 | No contrib equivalent — capacity planning over route legs and a single Resource calendar is domain logic | **Reuse unchanged** | `custom_webform_handlers.dispatch_planner`, reached only via `dispatch_booking` (visitor) or `dispatch_staff_planner` (staff) | None. Task 2 installed its schema and repaired its config; not one line of algorithm changed | `INSTALLED` schema 10013, 5 tables present; `SOURCE` `services.yml:149-172` |
| Webform handler | existing custom | `address_distance_handler` (`Plugin\WebformHandler\AddressDistanceHandler`) | in-repo | Its settings already carried both `dispatch_enabled` and `reservation_enabled` — the ambiguity Task 2 had to resolve | **Reuse, do not extend** | Handler keeps owning field mapping; workflow ownership moved out to the gate | Gate *calls* the two existing predicates instead of re-reading handler settings | `INSTALLED` handler flags per Webform; `SOURCE` `DispatchRequestFactory::settingsFor()` |
| Commerce | contrib | `commerce`, `commerce_order`, `commerce_payment`, `commerce_price`, `commerce_store`, `commerce_number_pattern` | 3.3.8 | Would duplicate any home-grown order or payment record | **Install only** | Deferred to Tasks 3 and 6 | None in this task | `INSTALLED` |
| Advanced Queue backend | contrib plugin | `Plugin\AdvancedQueue\Backend\Database`, plugin id `database` | 8.x-1.6 | A custom queue backend would duplicate it exactly | **Configure the contrib plugin** | Queue entity `custom_webform_handlers_dispatch` | One `config/optional/` YAML file | `INSTALLED` backend class asserted at runtime |
| Redis integration | contrib + DDEV add-on | `drupal/redis` / `ddev/ddev-redis` | 8.x-1.11 / v2.2.0 | Custom cache or lock wrapper would duplicate it | **Configure only** | `cache.default`, `lock`, `flood`, cache-tags checksum | Container-provided settings; no module code | `INSTALLED` four service classes + `ping` |
| ECA actions | contrib | `eca`, `eca_ui` | 2.1.23 | Could duplicate notification orchestration the module already owns in `Dispatch\Notify\*` | **Install, do not wire** | Existing four-service notify seam keeps ownership | None in this task | `INSTALLED`; `SOURCE` `services.yml:272-305` |
| Public workflow decision | none existed | `Dispatch\BookingWorkflowGate` + `BookingWorkflowAccessCheck` + `BookingWorkflow` | new, this task | It **removes** duplication: the answer previously lived in three places and was consulted by neither controller | **Create — the one new seam** | `custom_webform_handlers.booking_workflow_gate`; checker tagged `access_check`, `applies_to: _custom_webform_handlers_booking_workflow` | 9 guard call sites, 6 route requirement lines, 2 service entries | Graph: `EXTRACTED` in-edges from both controllers. Runtime: three-way table in §6 |

---

## 8. Still required from an operator

None of these are defects, and none of them block Task 3. They are decisions that
belong to a person, and Task 2 deliberately did not make them.

- **Working hours cover days 1–3 only.** That is a test profile. Real trading days
  must be entered before the planner offers a realistic week.
- **A depot address is required only if `return_to_base` is switched on.** It is
  currently off, so the empty depot is consistent. Turning return-to-base on
  without a depot silently yields zero Slots — by design, and worth knowing before
  someone toggles it and reports the planner as broken.
- **Notification recipients are empty and notifications are disabled.** All six
  events and templates exist. It stays off until somebody names a recipient,
  because a default of on would mail the site address the first time a booking
  confirmed.
- **`workflow.dispatch_webforms` is empty.** Task 5 adds the homepage form.
  Until then the planner is unreachable from the public web, which is the
  intended state.
- **Redis holds the flood counters.** See §3. Decide the production posture before
  launch.
- **Graphify's clustering pass is stale.** `graphify cluster-only . --no-viz`
  refreshes it when the host stops locking the temp file.

---

## 9. Seam review — self-verified, not independent

The spec asks for a bounded read-only sub-agent to identify these seams
independently. It was attempted twice and refused by the gateway both times
(§1). **What follows is therefore self-review, and it carries the weakness that
implies:** the same reasoning that wrote the change checked it. Every claim below
cites a line so a second reader can disagree with it cheaply. Graphify does not
index YAML, so route, schema and config facts are marked **read from source**.

### (a) Update seam — can any update touch legacy rows?

`custom_webform_handlers.install` defines updates **10001 – 10013**; **10013 is
the highest**, which matches the installed schema. The five that Task 2 ran:

| Update | What it does | Can it alter or delete a legacy row? |
| --- | --- | --- |
| 10009 (`:457`) | Installs the dispatch profile by reading `config/install`, and only when `$config->isNew()` (`:461`) | **No.** Config only; no database call |
| 10010 (`:493`) | Creates three dispatch tables when absent (`:505-508`); flags legacy days | **No — read-only against it.** The legacy access is `$database->select('custom_webform_handlers_reservation', 'r')` (`:515`); the only write is `->merge('custom_webform_handlers_dispatch_guard')` (`:558`). No insert, update, delete or drop names that table anywhere in the file |
| 10011 (`:597`) | Creates the status and notification tables when absent (`:606-609`) | **No.** Nothing else is read or written |
| 10012 (`:630`) | Installs notification settings, `isNew()`-guarded (`:634`) | **No.** Config only |
| 10013 (`:681`) | Seeds absent defaults and enforces invariants (`:709-712`) | **No.** Config only |

Two details in 10010 are worth stating because they are the ones that could have
gone wrong quietly. The select lists six columns — `reservation_id`,
`resource_id`, `slot_start`, `timezone`, `state`, `expires_at` (`:516`) — and
**neither secret-hash column is among them.** And "active" is defined as
confirmed, or held *and* `expires_at > $now` (`:519-524`), so an expired Hold
withholds nothing. Where a stored timezone will not parse, the update flags the
UTC day and both neighbours rather than substituting UTC (`:546-553`): it
over-blocks instead of silently moving a real claim to the wrong day.

Idempotence is structural, not incidental: tables are created only when absent
and the guard rows go in through `merge()`, so re-running changes nothing.

### (b) Config seam — do install and schema agree?

Read from source. `config/install/` ships three objects —
`custom_webform_handlers.settings.yml`, `.notifications.yml`, `.dispatch.yml`.
`config/schema/` ships one file, `custom_webform_handlers.schema.yml`, covering
all three. `config/optional/` ships the Advanced Queue entity from §4.

Top-level keys of `custom_webform_handlers.dispatch`:

```
install file : request_fields  resource  self_bookable  manual_review  routing  holds  durations  workflow
schema mapping: request_fields  resource  self_bookable  manual_review  routing  holds  durations  workflow
```

**They agree — same members, same order.** `workflow` is the key Task 2 added, and
it is present in both, which is the part that mattered: a stored key with no
schema entry passes silently in production and fails config validation later.

### (c) Controller seam — every public booking endpoint

Read from source, `custom_webform_handlers.routing.yml`. Nine public endpoints;
the admin routes are excluded because they sit behind permissions, not the
workflow gate.

| Route | Path | Method | Webform arrives via | Controller | Route requirement |
| --- | --- | --- | --- | --- | --- |
| `dispatch_offer` | `/custom-webform-handlers/dispatch/offer/{webform}` | POST | path | `DispatchController::offer` | `dispatch` |
| `dispatch_hold` | `…/dispatch/hold/{webform}` | POST | path | `DispatchController::hold` | `dispatch` |
| `dispatch_renew` | `…/dispatch/renew/{webform}` | POST | path | `DispatchController::renew` | `dispatch` |
| `dispatch_release` | `…/dispatch/release/{webform}` | POST | path | `DispatchController::release` | `dispatch` |
| `reservation_availability` | `…/reservation/availability/{webform}` | GET | path | `ReservationController::availability` | `legacy` |
| `reservation_calendar` | `…/reservation/calendar/{webform}` | GET | path | `ReservationController::calendar` | `legacy` |
| `reservation_hold` | `…/reservation/hold` | POST | **body** | `ReservationController::hold` | none possible |
| `reservation_renew` | `…/reservation/renew` | POST | **body** | `ReservationController::renew` | none possible |
| `reservation_release` | `…/reservation/release` | POST | **neither** | `ReservationController::release` | none possible |

The three body-named POSTs carry no route requirement, and that is a fact about
the paths rather than an omission: a router cannot read a JSON body, so there is
nothing for a route checker to match on. Confirmed at runtime — their requirement
resolves to `NULL` through `router.route_provider`. It is exactly why the
controller guard exists as well as the route guard.

Guard call sites, nine in total:

```
DispatchController      helper refuseUnlessDispatch  :120
                        offer :139   hold :167   renew :200   release :223
ReservationController   helper refuseUnlessLegacy    :116
                        availability :134   calendar :191   hold :295   renew :368
                        release :426  -- legacyReachable(), not per-Webform
```

`ReservationController::release()` is the single callback that is **not** gated
per Webform, and the reason the code gives is that it cannot be: the request
carries a Hold token and a session identity, and the session receipt is filed
under `ReservationSlot::activeSlotKey()`, a SHA-256 digest that cannot be
reversed into a Webform id. Accepting a Webform in the body would not repair it
either — a caller who wanted past the guard would name a form that passes. So it
asks the weaker question it can answer, and that is defensible because releasing
narrows a claim rather than making one.

**One thing this review would flag if an independent reviewer had run it:** every
`{webform}` slug is un-upcast, because `routing.yml` declares no `parameters:`
option for it. The access checker reads `getRawParameters()` deliberately for
that reason, and the gate loads the entity itself. That is consistent today, but
adding a `parameters:` upcast to any of those six routes later would change what
the checker receives, and nothing in the file says so out loud.


