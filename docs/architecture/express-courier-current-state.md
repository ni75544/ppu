# Express Courier — current-state architecture checkpoint

Task 1 of the Express Courier and Commerce execution pack. Read-only: this document
is the only file the task created. No product code, configuration, schema, or Drupal
state was changed.

Captured 2026-08-22 against branch `codex-experiments` at HEAD `c365148f`, with the
working tree heavily modified and uncommitted.

**Terminology.** This checkpoint uses the `codebase-design` vocabulary
(`.agents/skills/codebase-design/SKILL.md`): *module*, *interface*, *implementation*,
*seam*, *adapter*, *depth*. "Seam" means the place where an interface lives and
behaviour can be substituted; it is not used as a synonym for "boundary".

**Evidence labelling.** Every claim below is marked with how it was established:
`[source]` read directly from a file in the working tree, `[runtime]` returned by a
read-only Drupal bootstrap probe through DDEV, `[graph]` an EXTRACTED Graphify edge,
`[host]` a host CLI probe. Graphify's index is AST-only, so YAML and Markdown are
outside its coverage and are always `[source]`.

---

## 1. Environment and dependency inventory

### 1.1 Toolchain versions

| Component | Version | Evidence |
|---|---|---|
| DDEV | v1.25.3 | `[host]` `ddev version` |
| Docker | 29.7.2 | `[host]` `ddev version` |
| Drupal core | 10.6.15, bootstrap successful | `[runtime]` `drush status` |
| Install profile | `standard` | `[runtime]` `drush status` |
| PHP (container) | 8.4.22, `/usr/bin/php8.4` | `[runtime]` `drush status` |
| Composer (container) | 2.10.2 | `[host]` `ddev exec composer --version` |
| Drush | 13.6.2.0 | `[runtime]` `drush status` |
| Database | MariaDB 11.8, connected | `[host]` `ddev describe`; `[runtime]` `drush status` |
| Git | 2.55.0.windows.3 | `[host]` `git --version` |
| Claude Code CLI | 2.1.240 | `[host]` `claude --version` |
| Node.js (container) | 24 | `[host]` `ddev describe` |

Docroot is `.`, so `modules/custom_webform_handlers/` is the live custom module.
Similarly named directories elsewhere on disk are duplicate artifacts and are neither
inspected nor cited anywhere in this document.

### 1.2 DDEV services — Redis is absent

| Service | State | Detail | Evidence |
|---|---|---|---|
| `web` | OK | drupal10, PHP 8.4, nginx-fpm, docroot `.`, perf mode mutagen | `[host]` `ddev describe` |
| `db` | OK | mariadb:11.8 | `[host]` `ddev describe` |
| `selenium-chrome` | OK | ports 4444/5900/7900/9000 | `[host]` `ddev describe` |
| `xhgui` | stopped | launch on demand | `[host]` `ddev describe` |
| Mailpit | available | `:8026` | `[host]` `ddev describe` |
| **Redis** | **not present** | no service, no add-on config | `[host]` `ddev describe` |

`ddev describe` prints DDEV's generated local database credentials. Its output was
filtered before reading and no credential appears in this document.

Selenium is present in the environment. The execution pack forbids adding Selenium or
FunctionalJavascript coverage, so it is recorded as inventory and was not used.

### 1.3 Drupal core constraint consistency — one real inconsistency

`composer validate --no-check-publish` → `./composer.json is valid` `[host]`.

| Package | `composer.json` constraint | `composer.lock` resolved | Evidence |
|---|---|---|---|
| `drupal/core` | (transitive) | **10.6.15** | `[source]` + `[host]` `composer show --locked` |
| `drupal/core-recommended` | `^10.6` | 10.6.15 | `[source]` composer.json:21 |
| `drupal/core-dev` | `^10.6` | 10.6.15 | `[source]` composer.json:101 |
| `drupal/core-vendor-hardening` | `^10.3` | 10.6.15 | `[source]` composer.json:22 |
| `drupal/core-composer-scaffold` | **`^11.4`** | **11.4.5** | `[source]` composer.json:19 |
| `drupal/core-project-message` | **`^11.4`** | **11.4.5** | `[source]` composer.json:20 |

Two Drupal 11 utility packages are pinned and locked against a Drupal 10.6 core.
Validation passes because neither package declares a `drupal/core` constraint, so the
mismatch is invisible to `composer validate` and will only surface on the next
`composer update` — which is exactly what Task 2 performs. Task 2 must reconcile these
two constraints to `^10.6` before adding any contrib package, or the update will
attempt to drag scaffolding to a Drupal 11 line.

### 1.4 Installed and enabled extensions

48 modules are enabled `[runtime]` `drush pm:list --status=enabled`. `modules/contrib/`
contains exactly one project: `webform` `[source]`.

| Roadmap dependency | Installed | Enabled | Version | Evidence |
|---|---|---|---|---|
| Webform | yes | yes | **6.3.0** | `[runtime]` `extension.list.module` |
| `custom_webform_handlers` | yes | yes | dev, no version key | `[runtime]` |
| Commerce | **no** | no | — | `[source]` `modules/contrib/` |
| Address | **no** | no | — | `[source]` only in Webform's own `require-dev` |
| Geocoder | **no** | no | — | `[source]` absent from composer.lock |
| Advanced Queue | **no** | no | — | `[source]` absent from composer.lock |
| Redis | **no** | no | — | `[source]` absent from composer.lock |
| ECA | **no** | no | — | `[source]` absent from composer.lock |

Enabled Webform sub-modules: `webform_node`, `webform_submission_export_import`,
`webform_ui` `[runtime]`.

Present on disk but **not enabled**, dependencies already satisfied — installable in
Task 2 with no new Composer package `[source]` sub-module `.info.yml` files:
`webform_options_limit`, `webform_scheduled_email`, `webform_submission_log`,
`webform_options_custom`, `webform_attachment`, `webform_schema`, `webform_share`,
`webform_templates`, `webform_image_select`, and `webform_access` (needs `webform` +
`webform_node`, both enabled).

Present on disk but **not installable without a new package**: `webform_cards`
(requires `webform_clientside_validation`, which requires the contrib
`clientside_validation` project — absent) `[source]`; `webform_devel`,
`webform_entity_print`, `webform_jqueryui_*` (each needs an absent contrib project)
`[source]`.

Present on disk but **not installable at all**: `webform_location_geocomplete` ships
only `js/webform_location_geocomplete.element.js` with no `.info.yml`, so it is not a
module in this copy `[source]`. Same for `webform_demo` and `webform_toggles`.

Core `workflows` and `content_moderation` are **not enabled** —
`plugin.manager.workflows.type` is absent from the container `[runtime]`.

### 1.5 API-key readiness — boolean only

A bootstrap probe printed only `configured` / `missing`. No value was read into any
output, log, or this document.

| Credential | Storage | State | Evidence |
|---|---|---|---|
| Browser Maps key | `custom_webform_handlers.settings:google_maps_browser_api_key` | `configured` | `[runtime]` |
| Server Routes key | `Settings::get('custom_webform_handlers_server_api_key')` | `configured` | `[runtime]` |

The separation is intentional and already enforced in code: the browser key is read
from configuration for attachment to the page, and the server key is read only from
`settings.php` and sent as the `X-Goog-Api-Key` header from PHP
`[source]` `GoogleRoutesDistanceProvider.php:142,201`.

### 1.6 Schema and update-hook status — the Dispatch Planner is not installed

| Fact | Value | Evidence |
|---|---|---|
| Installed schema version | **10008** | `[runtime]` `update.update_hook_registry` |
| Update hooks defined | 10001 … 10012 | `[runtime]` + `[source]` `.install` |
| **Pending** | **10009, 10010, 10011, 10012** | `[runtime]` `drush updatedb:status` |

Table existence, names only:

| Table | State | Evidence |
|---|---|---|
| `custom_webform_handlers_reservation` | **present** | `[runtime]` `Schema::tableExists()` |
| `custom_webform_handlers_dispatch_reservation` | **absent** | `[runtime]` |
| `custom_webform_handlers_dispatch_guard` | **absent** | `[runtime]` |
| `custom_webform_handlers_dispatch_blackout` | **absent** | `[runtime]` |
| `custom_webform_handlers_dispatch_status` | **absent** | `[runtime]` |
| `custom_webform_handlers_dispatch_notification` | **absent** | `[runtime]` |

The entire variable-duration Dispatch Planner exists as code and as `hook_schema()`
declarations but has never been installed in this environment. Only the legacy
fixed-slot table is real. Task 1 must not start database updates, so this is reported,
not fixed.

Four names that look like tables are **PHP session keys**, not storage:
`custom_webform_handlers_dispatch_owner`, `custom_webform_handlers_dispatch_holds`,
`custom_webform_handlers_reservation_owner`,
`custom_webform_handlers_reservation_holds` `[source]` `DispatchOwner.php:46,51`,
`ReservationOwner.php:33,38`.

### 1.7 Active configuration diverges from the shipped defaults

Three config objects ship in `config/install/`. Only two are stored `[runtime]`
`configFactory()->listAll()`:

| Config object | Stored | Consequence |
|---|---|---|
| `custom_webform_handlers.settings` | yes, 6 top-level keys | traffic keys missing, see below |
| `custom_webform_handlers.dispatch` | yes, 4 top-level keys | **materially incomplete** |
| `custom_webform_handlers.notifications` | **no — does not exist** | no templates, no recipients |

`config/install` is replayed only at module install, and this module was installed
before those files existed. Update 10009 is the hook that installs the operating
profile from that YAML, and it is pending `[source]` update description.

Active vs shipped dispatch profile `[runtime]` config reads vs `[source]`
`config/install/custom_webform_handlers.dispatch.yml`:

| Key | Shipped | **Active** |
|---|---|---|
| `self_bookable.service_types` | `[courier]` | **`[m1]`** |
| `self_bookable.delivery_modes` | 3 courier modes | 3 courier modes |
| `self_bookable.max_package_lbs` | `30` | `30` |
| `self_bookable.require_declared_weight` | `true` | **`false`** |
| `self_bookable.require_single_driver_handling` | `true` | **`false`** |
| `self_bookable.store_pickup_requires_paid_and_ready` | `true` | **absent** |
| `manual_review.service_types` | 6 values | **`[]`** |
| `manual_review.vehicle_helper_needs` | 3 values | **`[]`** |
| `manual_review.handling_flags` | 5 values | **`[]`** |
| `routing.require_traffic_aware` | `false` | **absent** |
| `holds.hold_seconds` | `900` | **absent** |
| `durations.pickup_work_minutes` | `15` | `10` |
| `durations.delivery_work_minutes` | `15` | `5` |
| `durations.pickup_promise_minutes` | `30` | **absent** |
| `durations.courier` | `{10, 10}` | **absent** |
| `resource.id` | `driver-01-vehicle-01` | `driver-01` |
| `resource.grid_minutes` | `30` | `10` |
| `resource.repositioning_buffer_minutes` | `20` | `4` |
| `resource.operational_buffer_minutes` | `15` | `4` |
| `resource.booking_horizon_days` | `30` | `1` |
| `resource.return_to_base` | `true` | **`false`** |
| `resource.depot_address` | `''` | empty |

Active `custom_webform_handlers.settings` is also missing five route keys that ship
in `config/install`: `route_scheduled_cache_ttl`, `route_traffic_model`,
`route_departure_bucket_seconds`, `route_departure_min_lead_seconds`,
`route_traffic_fallback_inflation_percent` `[runtime]`.

### 1.8 What the active profile does to behaviour — verified in code

Three consequences follow from §1.7 and were each confirmed by reading the consuming
code, not inferred from the config diff.

**No Hold can be claimed.** `holds.hold_seconds` is absent, and
`DispatchHoldWindow::seconds()` returns `NULL` for a non-numeric or non-positive
value `[source]` `DispatchHoldWindow.php:38-53`. Its docblock states the contract:
"A positive number of seconds, or NULL — which every caller must read as 'no Hold may
be claimed or renewed', never as a cue to substitute a number." There is deliberately
no fallback.

**Every real service is forced to Manual Review.** `self_bookable.service_types` is
`['m1']`, and `ConfigDispatchEligibility::assess()` returns
`EligibilityOutcome::manualReview(DispatchReason::UnsupportedService)` when the
submitted service type is not in that list `[source]`
`ConfigDispatchEligibility.php:38-96`. An empty list would instead yield
`DispatchReason::NotConfigured`, so the current state is "configured with a value
nothing matches", not "unconfigured".

**The missing route settings degrade safely.** Each of the five absent keys is read
through a PHP `?:` fallback whose literal equals the shipped default `[source]`
`DistanceProviderRouteEstimator.php:124`, `GoogleRoutesDistanceProvider.php:247`,
`:331`, `:332`, `:358`. This is the opposite of `hold_seconds`, and the asymmetry is
intentional: a route estimate may be conservative, a Hold duration may not be guessed.

### 1.9 Existing external seams

28 service ids are declared, of which 20 are `dispatch_`-prefixed, 4 are
`reservation_`-prefixed and 2 are aliases `[source]`
`custom_webform_handlers.services.yml`. 14 interfaces exist in `src/` `[source]` file
inventory. The seams the roadmap asks about:

| Seam | Interface | Bound implementation | Service ID |
|---|---|---|---|
| Quote calculator | none — concrete | `Quote\QuoteCalculator` | `.quote_calculator` |
| Route provider | `Distance\DistanceProviderInterface` | `Distance\GoogleRoutesDistanceProvider` | `.distance_provider` → alias |
| Scheduled route | `Distance\ScheduledRouteProviderInterface`, `Dispatch\Route\DispatchRouteEstimatorInterface` | `Dispatch\Route\DistanceProviderRouteEstimator` | `.dispatch_route_estimator` |
| Planner | `Dispatch\DispatchPlannerInterface` | `Dispatch\DispatchPlanner` | `.dispatch_planner` |
| Staff planner | `Dispatch\DispatchStaffPlannerInterface` | same object | `.dispatch_staff_planner` → alias |
| Booking (visitor) | none — concrete | `Dispatch\DispatchBooking` | `.dispatch_booking` |
| Staff operations | none — concrete | `Dispatch\DispatchOperations` | `.dispatch_operations` |
| Dispatch store | `Dispatch\Storage\ReservationStoreInterface` | `Dispatch\Storage\DatabaseReservationStore` | `.dispatch_reservation_store` |
| Contention | none — concrete, deliberately | `Dispatch\Storage\ResourceCalendarGuard` | `.dispatch_calendar_guard` |
| Board reader | `Dispatch\DispatchBoardReaderInterface` | `Dispatch\DispatchBoardReader` | `.dispatch_board_reader` |
| Notifier | `Dispatch\Notify\DispatchNotifierInterface` | `Dispatch\Notify\QueueDispatchNotifier` | `.dispatch_notifier` |
| Notification log | `Dispatch\Notify\NotificationLogInterface` | `Notify\DatabaseNotificationLog` | `.dispatch_notification_log` |
| Legacy reservations | `Reservation\ReservationManagerInterface` | `Reservation\DatabaseReservationManager` | `.reservation_manager` |

Policy seams the planner delegates to rather than deciding itself `[source]`
`services.yml:70-84`, `.dispatch_planner` argument list `:125-139`:

| Policy seam | Interface | Implementation | Service ID |
|---|---|---|---|
| Eligibility | `Dispatch\Policy\DispatchEligibilityInterface` | `Policy\ConfigDispatchEligibility` | `.dispatch_eligibility` |
| Duration | `Dispatch\Policy\ServiceDurationPolicyInterface` | `Policy\ConfigServiceDurationPolicy` | `.dispatch_duration_policy` |
| Resource calendar | `Dispatch\Schedule\ResourceScheduleInterface` | `Schedule\ProfileResourceSchedule` | `.dispatch_resource_schedule` |
| Reported status | `Dispatch\Status\ResourceStatusStoreInterface` | `Status\DatabaseResourceStatusStore` | `.dispatch_resource_status_store` |
| Hold duration | none — concrete | `Dispatch\DispatchHoldWindow` | `.dispatch_hold_window` |

Three container-level properties matter for later tasks and are enforced by the wiring,
not by convention `[source]` `services.yml` comments at `:48-52`, `:171-180`, `:193-209`,
`:230-233`, `:241-247`:

- The board reader receives the store, profile factory, request factory, status store
  and notification log — and **not** the planner, calendar guard or hold window. A read
  path has no route to a capacity mutation.
- `.dispatch_operations` receives `.dispatch_notifier`, never the composer, dispatcher
  or `plugin.manager.mail`. No write path can reach a mail server, so a slow SMTP host
  cannot delay a booking.
- `.dispatch_resource_status_store` is wired to nothing that plans. A reported status
  cannot become a source of capacity.

### 1.10 Courier modes, eligibility limits, durations, traffic, and price

`DeliveryMode` is an enum with exactly three cases, matching the shipped
`self_bookable.delivery_modes` `[source]` `src/Dispatch/DeliveryMode.php:23,28,33`:
`Send = 'courier_send'`, `Receive = 'courier_receive'`,
`StorePickup = 'courier_store_pickup'`. Six other enums exist —
`DispatchReason`, `PlanAuthority`, `Notify\DispatchAudience`, `Notify\DispatchEvent`,
`Notify\NotificationOutcome`, `Status\ResourceStatusState` `[source]`.

Shipped self-booking limits `[source]` same file: `max_package_lbs: 30`,
`require_declared_weight: true`, `require_single_driver_handling: true`,
`store_pickup_requires_paid_and_ready: true`. Shipped Manual Review triggers: six
service types (`on_demand_delivery`, `local_residential_move`,
`local_commercial_move`, `long_distance_move`, `specialty_item_move`,
`diy_truck_rental`), three vehicle/helper needs (`van_1_helper`, `truck_1_helper`,
`truck_2_helpers`), five handling flags (`heavy`, `oversized`, `restricted`,
`multi_person`, `unsupported`). Every one of those three lists is **empty** in the
active profile (§1.7).

Duration policy is configuration-driven with a per-service override tree:
`durations.pickup_work_minutes`, `durations.delivery_work_minutes`,
`durations.pickup_promise_minutes`, and `durations.courier.{pickup,delivery}_handling_minutes`
`[source]`. Repositioning and operational buffers and the grid live under `resource.`
`[source]`.

Traffic is opt-in: `routing.require_traffic_aware` ships `false` `[source]`. The
server-side traffic controls are the five `route_*` keys in
`custom_webform_handlers.settings` (§1.7), read by the Routes adapter and the estimator
`[source]` `GoogleRoutesDistanceProvider.php:247,331,332,358`,
`DistanceProviderRouteEstimator.php:124`.

**Courier has no price.** `QuoteCalculator` contains zero occurrences of the string
`courier` `[source]` `src/Quote/QuoteCalculator.php` (421 lines). Its service switch
covers `long_distance_move` (L32), `local_residential_move` (L60),
`local_commercial_move` (L105), `specialty_item_move` (L158), `on_demand_delivery`
(L179), `diy_truck_rental` (L212), and the default arm is:

```php
      default:
        $this->manualReviewReasons[] = 'missing_or_unsupported_service_type';
```

with `$status = $this->manualReviewReasons ? 'manual_review' : 'estimated';`. The
`quote` config tree has subtrees for currency, rate_version, long_distance,
local_residential, fees, commercial, specialty, on_demand and diy_truck — and no
`courier` `[source]`. So the one service the dispatch profile makes self-bookable is
the one service the quote engine cannot price. This is a hard Task 4 prerequisite.

### 1.11 Routes — every endpoint that offers, holds, renews, releases, or confirms

32 route ids are declared `[source]` `custom_webform_handlers.routing.yml`. 22 carry a
`_permission` requirement, 10 carry `_access: 'TRUE'`, 9 carry
`_csrf_request_header_token: 'TRUE'`. There are no `_custom_access` or `_entity_access`
requirements and no `no_cache` options anywhere in the file `[source]`. Declared
methods: 9 POST-only, 7 GET-only, 7 GET+POST; the remaining 9 rely on Form API /
controller defaults `[source]`.

Permission distribution across the 22 protected routes `[source]`: 9 `manage dispatch
schedule`, 6 `administer dispatch planner`, 5 `administer custom webform reservations`,
2 `administer site configuration`.

The 10 `_access: 'TRUE'` routes are the public capacity surface. Both workflows are
reachable simultaneously:

| Route id | Path | Method | CSRF | Workflow |
|---|---|---|---|---|
| `distance_preview` | `/custom-webform-handlers/distance-preview` | POST | yes | shared quoting |
| `dispatch_offer` | `/custom-webform-handlers/dispatch/offer/{webform}` | POST | yes | Dispatch |
| `dispatch_hold` | `/custom-webform-handlers/dispatch/hold/{webform}` | POST | yes | Dispatch |
| `dispatch_renew` | `/custom-webform-handlers/dispatch/renew/{webform}` | POST | yes | Dispatch |
| `dispatch_release` | `/custom-webform-handlers/dispatch/release/{webform}` | POST | yes | Dispatch |
| `reservation_hold` | `/custom-webform-handlers/reservation/hold` | POST | yes | **legacy** |
| `reservation_renew` | `/custom-webform-handlers/reservation/renew` | POST | yes | **legacy** |
| `reservation_release` | `/custom-webform-handlers/reservation/release` | POST | yes | **legacy** |
| `reservation_availability` | `/custom-webform-handlers/reservation/availability/{webform}` | **GET** | **no** | **legacy** |
| `reservation_calendar` | `/custom-webform-handlers/reservation/calendar/{webform}` | **GET** | **no** | **legacy** |

Two asymmetries are worth carrying into Task 5 and Task 9. First, the Dispatch family
makes *offering* a CSRF-protected POST, while the legacy family exposes two
unauthenticated CSRF-free GET reads of availability. Second, no route in the file sets
`no_cache`, so Task 6's "no-store" requirement for a public tracker or checkout
response is not yet expressed anywhere in routing.

**Nothing in this module confirms capacity on a payment event**, because no payment
integration exists (§1.4). Confirmation today happens in exactly two places: staff
authority through `.dispatch_operations` (`confirm`), and the Webform submission
lifecycle described next.

### 1.12 Where the Webform submission lifecycle confirms a Reservation

The single Webform handler is `address_distance_handler` `[source]`
`src/Plugin/WebformHandler/AddressDistanceHandler.php` annotation, with
`cardinality = CARDINALITY_SINGLE`, `results = RESULTS_IGNORED`,
`submission = SUBMISSION_REQUIRED`. It is confirmed enabled at runtime: the enabled
handler plugin set is `address_distance_handler`, `email`, `remote_post`, `debug`,
`settings`, `action`, `broken` `[runtime]` `plugin.manager.webform.handler`.

The handler is the only writer that runs inside the submission lifecycle, and it
carries a hard interlock `[source]` `AddressDistanceHandler.php:1241`:

```php
return !empty($this->configuration['dispatch_enabled']) && !$this->reservationEnabled();
```

so a single Webform can drive the Dispatch Planner *or* the legacy fixed-slot
reservation, never both. That interlock governs form alter (L599), validate (L914),
`preSave` (L1564) and `postSave` (L1012) only — see §5 for what it does not govern.

`postSave()` is the only place a Reservation is created, and its own comment says so:
"The one place a Reservation is created. It is here, and not in a request a browser can
make, because confirming needs the submission's own identifier" `[source]`
`AddressDistanceHandler.php:1031-1033`. Its gates, in order `[source]` `:1015-1052`:

1. `!$webform_submission->isCompleted()` → return. Drafts retain only a Hold, in both
   workflows.
2. `$update` → return. Only the save that creates the submission may consume a Hold, so
   an administrative edit of an already-reserved booking is never asked for a token
   again.
3. `if ($this->dispatchEnabled())` → `confirmDispatchReservation()`, and if that returns
   false, `announceDispatchManualReview()` `[source]` `:1042-1047`, `:1427`.
4. `if (!$this->reservationEnabled()) return;` → otherwise the legacy branch reads its
   four configured element fields and calls `reservationManager->confirm()` `[source]`
   `:1100`, after `verifyHold()` `[source]` `:1004`, `:1178`.

The dispatch confirm reaches the planner directly, not through the visitor booking seam:
the handler injects `DispatchPlannerInterface` `[source]` `:97`, `:135` and calls
`$this->dispatchPlanner->confirmReservation($hold, $submission)` `[source]` `:1359`.
The graph does not establish this edge: `graphify path "AddressDistanceHandler"
"DispatchPlanner"` returns "No directed path found" `[graph]`, because the dependency is
an interface-typed property filled from a container string in `create()` — the same
YAML-invisibility limitation recorded in the header. So `DispatchBooking` owns
offer/hold/renew/release from HTTP, and confirmation is a submission-lifecycle event —
which is exactly the seam Task 6 must move behind a paid Commerce order without
introducing a second writer.

Manual Review is announced from `postSave` rather than from `preSave`, where the review
flag is written, because "an announcement naming a submission that may yet be rolled
back would be an announcement about nothing" `[source]` `:1038-1041`. That announcement
fires only when `confirmDispatchReservation()` fails, and today the active
configuration guarantees it always fails (§1.8).

### 1.13 Permissions and roles — none of Task 7's three roles exist

The module declares exactly three permissions, all `restrict access: true` `[source]`
`custom_webform_handlers.permissions.yml`:

| Permission | Grants | Routes using it |
|---|---|---|
| `administer custom webform reservations` | legacy slot view/block/release | 5 |
| `administer dispatch planner` | operating profile + board **read** | 6 |
| `manage dispatch schedule` | withhold, release, replan, book staff-held requests | 9 |

The split between the last two is already deliberate `[source]` permissions.yml:15-20:
"reading a schedule and changing one are different jobs", and `manage dispatch schedule`
"grants no configuration rights, so a dispatcher can run the board without being able to
redefine working hours or reopen a service to self-booking."

Roles present in this site: `anonymous`, `authenticated`, `content_editor`,
`administrator` `[runtime]` `user_role` storage. **None of Task 7's three roles exists**
— `Schedule Manager`, `Dispatch Viewer`, `Financial / Customer Info Viewer` are all
absent, and no permission today separates customer contact detail, exact addresses, or
financial data from schedule mutation. Two of the requested three cannot be fully
expressed until Commerce exists (§1.4), so Task 7's `Financial / Customer Info Viewer`
is `unresolved` on that dependency, not on permission design.

Quote settings sit behind core `administer site configuration` (2 routes), which the
permission file itself flags as broader than the dispatch profile deserves `[source]`
permissions.yml:6-9.

---

## 2. Native Capability Ledger

Every category the task requires has at least one row. Where a capability was available
and deliberately not activated, the reason is specific to Task 1, not generic.

| Category | Capability | Available | Evidence/probe | Used for | Outcome / task-specific reason not used |
|---|---|---|---|---|---|
| Skills | `graphify` skill + CLI (`.claude/skills/graphify/SKILL.md`) | yes | registered in `.claude/CLAUDE.md`; `graphify query`/`path` ran | Orienting on seams before any raw read; testing the handler→planner dependency | 156-node subgraph returned for the seam question, truncated at its ~2000-token budget. `path "AddressDistanceHandler" "DispatchPlanner"` returned **"No directed path found"**, which corrected a citation in §1.12 from `[graph]` to `[source]` |
| Skills | `codebase-design` (`.agents/skills/codebase-design/SKILL.md`) | yes | read from disk | Report vocabulary | Header terminology note adopted module/interface/implementation/seam/adapter/depth; "boundary" avoided per that skill's explicit instruction |
| Skills | `tdd` (`.agents/skills/tdd/SKILL.md`) | yes | surfaced as a document node in the graph query | — | **Not activated:** Task 1 writes no code and is forbidden to run a test suite, so there is no behaviour to drive from a test |
| Skills | `gitnexus` skill pack | partial | 6 sub-skills present, no root `SKILL.md` | — | Not invocable as one skill; its CLI was used instead (row below) |
| Plugins/MCP | `plugin:context7` | yes, Connected | `claude mcp list` → `✔ Connected` | `resolve-library-id` for Advanced Queue; `query-docs` on `/drupal/core` for lock/transaction/`forUpdate()` | Corpus thin: only `/drupal/drupal` and `/drupal/core` resolved, no Advanced Queue or ECA library — which **reinforces** the `unresolved` rows in §3 rather than filling them. The lock query returned only a marginal `INSTALL.mysql.txt` snippet, so the capacity-locking decision was re-grounded in directly-read core source |
| Plugins/MCP | `context7` (OAuth variant) | no | `claude mcp list` → "Needs authentication" | — | Reported as its exact status per project policy; not activated, and no credential was supplied during a read-only task |
| Plugins/MCP | `gitnexus` MCP | partial | `claude mcp list` → `✔ Connected`, but no `mcp__gitnexus__*` tool exposed | CLI fallback: `status`, one bounded `query` | Index is pinned to commit `c365148` and reports "up-to-date", yet **all dispatch work is uncommitted**, so the index cannot see it. `query "DispatchNotificationWorker"` exceeded a 120 s bound, was backgrounded (`br4f6z5hk`) and returned no results at exit 0 — confirming the blind spot. Re-indexing not run: long-running and forbidden without explicit request. Architecture claims therefore rest on Graphify plus direct source |
| Plugins/MCP | `dbhub` MCP (read-only DB account) | **no** | `claude mcp list` → `✘ Failed to connect — -32000: MCP error -32000: Connection closed`; no `mcp__dbhub__*` tools | — | **Blocker**, reported in §6. Substituted with read-only `drush php:eval` over `Schema::tableExists()`, `update.update_hook_registry` and `configFactory()->listAll()`, plus direct reading of `.install`. §1.6 and §1.7 rest on that substitute |
| Plugins/MCP | `github`, `notion` MCP | disabled | absent from `claude mcp list` output as active servers | — | **Not activated:** Task 1 opens no issue, PR or external document, and creating one would violate the read-only rule |
| Plugins/MCP | `playwright` MCP | loaded | server present | — | **Not activated:** browser probes are forbidden by project policy and by this task; §1.11 endpoint facts come from routing YAML, not from HTTP traffic |

| Slash commands / extensions | Project slash commands | none | no `.claude/commands/` directory | — | `/graphify` resolves through the skill instead; nothing else in Task 1 maps to a command |
| Slash commands / extensions | Project agent definitions | none | no `.claude/agents/` directory | — | The sub-agent row below used a harness-provided agent type, so no project definition was needed and none was created |
| Slash commands / extensions | Terminal-dialog commands (`/permissions`, `/config`, `/doctor`, `/hooks`) | no | unavailable in this session type | — | **Not activated:** each mutates session or tooling configuration, which Task 1 forbids |
| Search/edit tools | `Glob` | yes | ran | Enumerating the 14 `*Interface.php` files and the module file inventory | Produced the §1.9 seam table's interface list |
| Search/edit tools | `Grep` / `grep`+`awk` via Bash | yes | ran | Enum discovery, routing tallies, handler lifecycle method locations, public-route paths | Produced §1.10–§1.12. One `awk` attempt at the routing `methods:` column printed empty and was replaced with a `grep -oE … \| uniq -c` tally |
| Search/edit tools | `Read` | yes | ran | `PROMPT_SPEC.md`, `services.yml`, `permissions.yml`, `dispatch.yml`, targeted handler ranges, core `SelectInterface`/`DatabaseLockBackend` | Every `[source]` citation in this document |
| Search/edit tools | `Write` / `Edit` | yes | ran | This document only, written in chunks to respect the tool's content-size limit | No product file, YAML, or configuration was written; §7 verification confirms it |
| Git integration | `git status` / `git diff` | yes | ran | Baseline captured **outside** the repository at `/tmp/task01-initial-status.txt` (49,126 lines, `--untracked-files=all`) so that baselining could not itself alter the tree | Final `-uall` count still 49,126; `git diff --check` exits 0 with only a CRLF advisory on `.gitignore`; 18 tracked files modified, all pre-existing and untouched by Task 1 |
| Git integration | `gh` CLI | no | `command -v gh` → not on PATH | — | **Not activated:** Task 1 creates no branch, commit or PR, and project policy forbids committing without instruction |
| CLI tools | `ddev` v1.25.3 | yes | `ddev version` | `describe`, `drush status`, `composer validate`, `drush pm:list` | §1.1–§1.4. `describe` output was filtered before reading because it prints DDEV's generated database credentials |
| CLI tools | `drush` 13.6.2.0 | yes | `ddev drush status` | `pm:list`, `updatedb:status`, read-only `php:eval` | §1.4–§1.7 and §1.12's runtime handler set. **`drush plugin:list` does not exist** — "There are no commands defined in the 'plugin' namespace." — so the spec's documented substitution was applied: read-only inspection of 18 plugin-manager service IDs plus enumeration of actual plugin IDs |
| CLI tools | `composer` 2.10.2 (container) | yes | `ddev exec composer --version` | `validate --no-check-publish`, `show --locked` | §1.3's constraint inconsistency. No package was installed or updated |
| CLI tools | `php` 8.4.22 (container) | yes | `drush status` | Only through `drush php:eval` | **No `php -l`, no PHPUnit:** project policy forbids linters and tests unless explicitly requested, and Task 1 forbids running a test suite |
| CLI tools | `node` 24 (container) | yes | `ddev describe` | — | **Not activated:** no JavaScript is built, linted or changed by a read-only inventory |
| CLI tools | `rtk` token-optimised command proxy | yes | `command -v rtk` → `/c/Users/mo737/.local/bin/rtk` | — | Present and confirmed on PATH. Probes in this task were run unproxied so their exact stdout could be quoted verbatim as evidence; recorded here rather than claimed as used |
| Sub-agents | `Agent` tool, `Explore` agent type | yes | one agent dispatched | **Exactly one** bounded read-only audit of Drupal plugin/contrib reuse opportunities in the live module, as Step 1 authorises | Returned plugin IDs and source paths, and **three of its findings were wrong** and were corrected by direct runtime verification: it offered `options_limit` and `scheduled_email` as reusable handlers (both on disk, **not enabled**); it offered `webform_card`, `webform_options_custom`, `webform_options_custom_entity` as elements (none appear among the 82 enabled element IDs); and it claimed `webform_access` was unusable for want of Webform nodes though `webform_node` **is** enabled — the genuinely blocked sub-module is `webform_cards`, which needs the absent contrib `clientside_validation`. It edited no file and did not repeat the primary inventory |
| Sub-agents | `Workflow` / multi-agent orchestration | yes | tool exposed | — | **Not activated:** the session instruction requires explicit user opt-in, and the spec authorises exactly one sub-agent — a fan-out would have exceeded that |

---

## 3. Drupal Reuse Decision Matrix

### 3.1 Plugin-manager inventory — the `drush plugin:list` substitute

`ddev drush plugin:list` does not exist in Drush 13.6.2 ("There are no commands defined
in the 'plugin' namespace."), so the spec's documented substitution was used: read-only
container inspection of plugin-manager service IDs, then enumeration of actual plugin
IDs `[runtime]`.

| Plugin manager service | State | Definitions |
|---|---|---|
| `plugin.manager.webform.element` | present | **82** |
| `plugin.manager.webform.handler` | present | **7** |
| `plugin.manager.views.field` | present | 53 |
| `plugin.manager.mail` | present | 4 |
| `plugin.manager.queue_worker` | present | **1** |
| `plugin.manager.workflows.type` | **ABSENT** | — |
| `plugin.manager.commerce_payment_gateway` | **ABSENT** | — |
| `plugin.manager.advancedqueue_backend` | **ABSENT** | — |
| `plugin.manager.eca.event` | **ABSENT** | — |
| `plugin.manager.geocoder.provider` | **ABSENT** | — |

Two of those numbers carry weight. The enabled Webform **handler** IDs are exactly
`address_distance_handler`, `email`, `remote_post`, `debug`, `settings`, `action`,
`broken` `[runtime]` — so any handler-based reuse proposal naming `options_limit` or
`scheduled_email` is naming a sub-module that is on disk and **not enabled** (§1.4). And
the single registered **QueueWorker** in this entire site is this module's own
`custom_webform_handlers_dispatch_notification` `[source]`
`src/Plugin/QueueWorker/DispatchNotificationWorker.php`:

```php
#[QueueWorker(
  id: 'custom_webform_handlers_dispatch_notification',
  title: new TranslatableMarkup('Dispatch notifications'),
  cron: ['time' => 30],
)]
```

Element IDs checked individually: `webform_address` **present**, `webform_time`
present, `webform_select_other` present; `address` (the `drupal/address` field type)
**absent**; `webform_address_composite` absent — it does not exist under that name
`[runtime]`. Core `views` and `views_ui` are **enabled**; `workflows` and
`content_moderation` are **not** `[runtime]`.

Zero occurrences of `popular`, `ranking`, `rank_` or `trending` exist anywhere in the
module's `src/`, `js/` or YAML `[source]` grep tally 0.

### 3.2 The matrix

Ten columns as specified. Every row is either an evidence-backed disposition or an
explicit `unresolved` naming the evidence still needed. No row proposes a second planner
or a new custom module.

| Required behavior | Core/contrib candidate | Existing custom candidate | Plugin/submodule/handler ID | Enabled/available version | Existing duplicate | Disposition | Selected owner/seam | Smallest required glue | Evidence |
|---|---|---|---|---|---|---|---|---|---|
| **Address capture** | Webform composite element; `drupal/address` field | `AddressDistanceHandler` element pickers, `DispatchRequestFactory` | `webform_address` element; `address` field type | Webform **6.3.0** enabled; `drupal/address` **not installed** — appears only inside Webform's own `require-dev` at composer.lock:1411 | yes — the handler restricts address pickers to `textfield` elements only, so a composite cannot currently be selected `[source]` `AddressDistanceHandler.php:194-216` | **reuse** `webform_address`; `drupal/address` **unresolved** | Webform element for capture; `.dispatch_request_factory` for turning stored values into facts | either widen the handler's allowed element types to admit the composite, or keep `textfield` and map composite sub-values inside `DispatchRequestFactory` | `[runtime]` element ID present; `[source]` handler gating |
| **Geocoding / autocomplete** | `drupal/geocoder` providers/widgets | `GoogleRoutesDistanceProvider` (server key) + browser Maps key attached to `js/distance_calculator.js` | `plugin.manager.geocoder.provider` | **ABSENT** — no such service in the container; package not in composer.lock | no | **unresolved** for Geocoder; **retain** the existing Google adapter meanwhile | `custom_webform_handlers.distance_provider` alias | none today; a Geocoder decision needs the installed provider IDs, which Task 2 must produce before any is cited | `[runtime]` manager absent; `[source]` services.yml:6-15 |
| **Quote input** | Webform elements + `hook_webform_submission_presave` | `QuoteCalculator`, config-driven rate trees | `custom_webform_handlers.quote_calculator` | in module, enabled | no | **retain and extend** | `.quote_calculator`, called from the handler's `preSave()` | add a `courier` arm to the service switch and a `quote.courier` rate subtree; today the default arm records `missing_or_unsupported_service_type` | `[source]` `QuoteCalculator.php:32,60,105,158,179,212` + default arm |
| **Commerce checkout / payment** | `commerce_order` types, `commerce_checkout` flows/panes, `commerce_payment` gateways + events, `state_machine` | **none — no payment code exists anywhere in the module** | `plugin.manager.commerce_payment_gateway` | **ABSENT** | no | **unresolved** | to be decided in Task 3; the confirm seam it must drive already exists | a Checkout Attempt adapter that calls the existing Hold path and, on payment success, the existing confirm path — no second planner | `[runtime]` manager absent; `[source]` `modules/contrib/` holds only `webform` |
| **Slot offering** | none in core or enabled contrib owns variable-duration slotting. `webform_options_limit` counts option usage but takes **no lock**: `getOptionsTotals()` uses `COUNT(value)`/`groupBy('value')` with zero `forUpdate`/`startTransaction`/`->lock` matches, and the sub-module is not enabled | `DispatchPlanner` behind `DispatchBooking` | `custom_webform_handlers.dispatch_planner`, `.dispatch_booking` | in module, enabled | no | **retain — authority** | `.dispatch_booking` for visitors, `.dispatch_staff_planner` alias for staff | presentation only; Task 5 renders offers, it does not compute them | `[source]` `OptionsLimitWebformHandler.php:1290,1296,1297`; `[source]` services.yml:158-169 |

| **Capacity locking** | `SelectInterface::forUpdate()` — the row-lock primitive, already used; `Drupal\Core\Lock\DatabaseLockBackend` — available but the wrong tool: it writes its own `semaphore` table from six separate sites, so it cannot be part of the transaction as the write it would protect | `ResourceCalendarGuard` | `custom_webform_handlers.dispatch_calendar_guard` | core 10.6.15; guard in module | **yes — the legacy `DatabaseReservationManager` writes a second store with no shared lock (§5)** | **retain the guard; reuse the core `forUpdate()` primitive inside it** | `.dispatch_calendar_guard` — "the only contention authority in the dispatch layer" | none. Task 6 must route payment confirmation *through* the guard rather than beside it | `[source]` `core/lib/Drupal/Core/Database/Query/SelectInterface.php:632`; `core/lib/Drupal/Core/Lock/DatabaseLockBackend.php:20` + write sites L53,71,131,148,171; `ResourceCalendarGuard.php:64,73,78,101` and its docblock L8-30 |
| **Manual Review workflow** | core `workflows` + `content_moderation`; Commerce `state_machine` | `DispatchManualReviewEntry`, `DispatchManualReviewConfirmForm`, `announceDispatchManualReview()`, the `manual_review` quote status | `plugin.manager.workflows.type` | **ABSENT — core `workflows` not enabled**; `state_machine` unresolved with Commerce | partial — the board already lists Manual Review entries, and Task 7 must not add a second queue beside them | **wrap and extend the existing PHP manager**; `workflows` **deferred** (Task 1 may not enable modules, and Task 7 requires the manager to work with ECA disabled) | `.dispatch_operations` for staff transitions | one transition-enforcing manager Interface plus the state/credential-hash schema Task 7 specifies; the board integrates with it rather than duplicating it | `[runtime]` manager absent; `[source]` `AddressDistanceHandler.php:1044,1427`; services.yml:210-220 |
| **Notifications** | core `plugin.manager.mail` (4 definitions), `webform_scheduled_email` sub-module | four-service split: `QueueDispatchNotifier` → queue → `DispatchNotificationWorker` → `DispatchNotificationDispatcher` + composer + `DatabaseNotificationLog` | `.dispatch_notifier`, `.dispatch_notification_composer`, `.dispatch_notification_dispatcher`, `.dispatch_notification_log`; QueueWorker `custom_webform_handlers_dispatch_notification` | core enabled; `webform_scheduled_email` on disk, **not enabled** | no | **retain**; `webform_scheduled_email` **defer** — it schedules mail from a submission, which would be a second sender for the same event | `.dispatch_notifier` is the only notification service any write path may hold | **configuration, not code**: `custom_webform_handlers.notifications` does not exist, so there are no templates or recipients until update 10009 runs (§1.7) | `[runtime]` config object absent, mail manager present; `[source]` services.yml:248-281 |
| **Background jobs** | core Queue API + `QueueWorker` plugins (**1** registered site-wide, this module's own); `advancedqueue` backends/queues/JobTypes | `DispatchNotificationWorker`, `cron: ['time' => 30]` | `plugin.manager.advancedqueue_backend` | **ABSENT**; core queue enabled | no | **retain core QueueWorker now; Advanced Queue `unresolved`** — its backend/queue/JobType IDs cannot be cited until Task 2 installs it, and the roadmap already rules out a configuration-only Redis backend | `.dispatch_notifier` → core queue → worker | Task 8 only; and by product invariant a queue may never decide price, eligibility, payment success or capacity | `[runtime]` manager absent, 1 QueueWorker; `[source]` worker attribute |
| **RBAC / admin Views** | `user_role` config entities + permissions; `views` and `views_ui` **enabled**; Webform's Views data | 3 permissions; `DispatchPlannerAdminController` renders the board in PHP | `views` (53 field plugins); Webform adds only `webform_submission_bulk_form` to its Views data | core enabled | no | **reuse core roles/permissions; retain the PHP board** | permissions.yml + `user_role` entities; board stays in `DispatchPlannerAdminController` | Task 7 creates the three roles and splits the two broad dispatch permissions. Note that Webform exposes **no element values** to Views, so an admin View cannot leak submission data by default — a custom Views integration would have to add that surface deliberately | `[runtime]` views enabled; `[source]` `WebformSubmissionViewsData.php:22`; permissions.yml |
| **Popularity ranking** | none in core; core `state`/`cache` for counters; Views aggregation over dispatch tables | **none — zero `popular`/`ranking`/`rank_`/`trending` occurrences in the module** | — | — | no | **defer to Task 8**, `unresolved` on whether ECA/Advanced Queue participate | not yet assigned; it must not be the planner | a read-side counter fed *after* a committed capacity change; by invariant it may not influence eligibility, price or capacity | `[source]` grep tally 0; dispatch tables absent per §1.6 |

---

## 4. Current authority map

One owner per decision. "Writer" means the class that performs the state change;
"decision owner" means the class that decides the answer.

| Decision | Single owner today | Reached through | Notes |
|---|---|---|---|
| **Quote** | `Quote\QuoteCalculator` | the handler's `preSave()` | Config-driven; **no `courier` arm**, so the only self-bookable service returns `status = 'manual_review'` (§1.10) |
| **Route** | `Distance\GoogleRoutesDistanceProvider` | `.distance_provider` alias, wrapped by `DistanceProviderRouteEstimator` for scheduling | Quoting and scheduling share exactly one route adapter by design `[source]` services.yml:85-100. Server key travels only as an `X-Goog-Api-Key` header from PHP |
| **Eligibility** | `Dispatch\Policy\ConfigDispatchEligibility` | injected into both the planner and `DispatchBooking` | Injected twice deliberately, so the seam can tell "day is full" from "needs staff" `[source]` services.yml:154-157 |
| **Slot offer** | `Dispatch\DispatchPlanner` | `.dispatch_booking` (visitor), `.dispatch_staff_planner` alias (staff) | The planner "holds the sequence and none of the rules" `[source]` services.yml:124. One algorithm, two audiences, same rows, same locks |
| **Capacity** | `Dispatch\Storage\ResourceCalendarGuard` | only via `DatabaseReservationStore` | The single contention authority. Not the lock service, not a cache lock, not a fixed hourly key `[source]` docblock L8-30 |
| **Payment** | **none — no owner exists** | — | `unresolved`. No Commerce, no gateway plugin manager, no payment code. Tasks 3 and 6 must assign this without creating a second capacity writer |
| **Review Request** | `Dispatch\DispatchOperations` (+ `DispatchManualReviewEntry`, `DispatchManualReviewConfirmForm`) | staff routes behind `manage dispatch schedule` | Announcement is emitted from `postSave()`, never from `preSave()`, so no message can name a rolled-back submission `[source]` `AddressDistanceHandler.php:1038-1041` |
| **Notification** | `Notify\QueueDispatchNotifier` | `.dispatch_notifier` only | It enqueues and nothing more; composing, sending and recording happen later in the worker, after the capacity transaction committed and released its guard `[source]` services.yml:241-247 |
| **Confirmation of a Reservation** | `DispatchPlanner::confirmReservation()` for dispatch; `DatabaseReservationManager::confirm()` for legacy | the handler's `postSave()`, creating-save only | **Two writers for one concept** — the subject of §5 |

Two properties of this map are worth preserving verbatim into Tasks 3–6. Capacity has
exactly one owner and it is a database-transaction row lock, so any new confirmation
trigger must run *inside* that transaction rather than beside it. And every read seam —
the board reader, the status store, the notification log — is wired so that it has no
path to a capacity mutation, which is what stops a reported status or a sent message
from becoming a second answer about availability.

---

## 5. Legacy fixed-slot versus variable-duration Dispatch — exact overlap

### 5.1 What is duplicated

| Concern | Legacy fixed-slot | Variable-duration Dispatch |
|---|---|---|
| Table | `custom_webform_handlers_reservation` — **present** | `..._dispatch_reservation` + 4 more — **all absent** (§1.6) |
| Manager | `Reservation\DatabaseReservationManager` | `Storage\DatabaseReservationStore` |
| Contention mechanism | a **unique index** — `reservation_active_slot` on `active_slot_key` — plus 3 `startTransaction()` sites, with **no `forUpdate()` and no lock service** | a **row lock** taken on the Resource Calendar Guard inside the same transaction as the write |
| Session identity | `custom_webform_handlers_reservation_owner` / `..._reservation_holds` | `custom_webform_handlers_dispatch_owner` / `..._dispatch_holds` |
| Public endpoints | `reservation_hold`, `_renew`, `_release` (POST+CSRF) **and** `reservation_availability`, `reservation_calendar` (GET, no CSRF) | `dispatch_offer`, `_hold`, `_renew`, `_release` (all POST+CSRF) |
| Confirmation writer | `DatabaseReservationManager::confirm()` | `DispatchPlanner::confirmReservation()` |
| Supporting services | 4 `reservation_`-prefixed services | 20 `dispatch_`-prefixed services |

Evidence: `[source]` `DatabaseReservationManager.php:31,260,577,622`;
`custom_webform_handlers.install:72-76,155-156`; `ResourceCalendarGuard.php:64,73,78,101`;
`services.yml:22-46` vs `:53-281`; `[runtime]` table existence.

The coexistence is partly deliberate. `services.yml:48-52` states the dispatch storage
services "do not replace the older reservation services above, which continue to serve
the existing fixed-slot workflow untouched", and the guard schema carries a
`legacy_unplanned` column with three dedicated methods — `flagLegacyUnplanned()` L129,
`clearLegacyUnplanned()` L158, `legacyUnplannedDates()` L179 `[source]` — so the newer
subsystem already knows the older one exists.

### 5.2 Duplicate-authority risks, in severity order

1. **Both public endpoint families are simultaneously reachable.** The handler interlock
   (`dispatchEnabled()` requires `!reservationEnabled()`) governs only form alter,
   validate, `preSave` and `postSave`. Neither `DispatchController` nor
   `ReservationController` consults the other workflow's flag — grep for
   `dispatch_enabled|reservation_enabled` returns **0** hits in each `[source]`. All ten
   routes carry `_access: 'TRUE'`, so a client can drive both hold families against two
   stores, under two session identities, with **no shared lock** between them.
2. **Two confirmation writers for one concept.** Today they are separated only by
   per-Webform configuration, not by structure. Task 6 introduces a third trigger
   (payment), so this must be reduced to one writer before that lands.
3. **Two contention mechanisms with different guarantees.** A unique index rejects a
   duplicate *after* the fact; a row lock serialises *before* it. Only the second can
   express a variable Dispatch Window, which is exactly the reasoning the guard's
   docblock records.
4. **The legacy family leaks more by default.** `reservation_availability` and
   `reservation_calendar` are unauthenticated CSRF-free GET reads. The dispatch family
   deliberately made offering a POST. Any Task 9 cutover should retire the GET pair
   rather than port it.
5. **Only the legacy store actually exists in this database.** Until updates 10009–10012
   run, the code path that is *architecturally* authoritative has no tables, while the
   path slated for retirement holds all the live rows.

---

## 6. Task 2 prerequisites, unresolved decisions, and blockers

### 6.1 Task 2 prerequisite checklist

Ordered, because two of these will break the others if done later.

1. **Reconcile the Drupal 11 scaffolding pins first.** Change
   `drupal/core-composer-scaffold` and `drupal/core-project-message` from `^11.4` to
   `^10.6` **before** requiring any contrib package. `composer validate` passes today
   only because neither declares a `drupal/core` constraint, so the mismatch surfaces on
   the first `composer update` — which is Task 2's own first action (§1.3).
2. **Provision the Redis DDEV service.** It does not exist: no service, no add-on
   configuration (§1.2).
3. **Install the absent contrib stack**, pinning ECA to the **2.1** line for Drupal 10:
   Commerce, Address, Geocoder, Advanced Queue, Redis, ECA. All six are absent from
   `composer.lock` and from `modules/contrib/`, which holds only `webform` (§1.4).
4. **Decide who runs updates 10009–10012** — see §6.2, decision D1. Nothing in Tasks 3–9
   works against the dispatch tables until they exist (§1.6).
5. **Re-verify the operating profile after 10009 runs.** Specifically that
   `holds.hold_seconds` is positive and `self_bookable.service_types` is `[courier]`, not
   `[m1]`; and that `custom_webform_handlers.notifications` now exists (§1.7, §1.8).
6. **Note the free wins.** Ten Webform sub-modules are on disk with satisfied
   dependencies and can be enabled with no new package if wanted:
   `webform_options_limit`, `webform_scheduled_email`, `webform_submission_log`,
   `webform_options_custom`, `webform_attachment`, `webform_schema`, `webform_share`,
   `webform_templates`, `webform_image_select`, `webform_access` (§1.4). `webform_cards`
   is **not** among them — it needs the absent contrib `clientside_validation`.

### 6.2 Unresolved decisions

| # | Decision | Evidence still needed |
|---|---|---|
| D1 | Which task runs the pending dispatch updates 10009–10012 | Task 1 is forbidden to start database updates, and the roadmap says "Other tasks must not run unrelated updates" — yet these hooks predate all nine tasks. This needs an explicit owner; Task 2 is the natural one because Task 3 assumes the schema |
| D2 | Commerce order/order-item types, Checkout Flow and pane IDs, payment gateway and payment event plugin IDs, `state_machine` workflow IDs | `plugin.manager.commerce_payment_gateway` is absent; no plugin surface may be cited until Task 2 installs the package |
| D3 | Geocoder provider and widget IDs | `plugin.manager.geocoder.provider` absent |
| D4 | Advanced Queue backend, queue and JobType IDs | `plugin.manager.advancedqueue_backend` absent. The roadmap already fixes the SQL backend at launch and rules out a configuration-only Redis backend |
| D5 | ECA event/condition/action IDs, and whether ECA participates at all | `plugin.manager.eca.event` absent. Context7 has no ECA library, so nothing may be quoted from memory |
| D6 | Whether core `workflows` is enabled for the Review Request lifecycle | `plugin.manager.workflows.type` is absent from the container. Task 7 requires its PHP manager to be authoritative with ECA disabled, so `workflows` is optional rather than required |
| D7 | Owner of popularity ranking | No such code exists; by invariant it may not influence price, eligibility or capacity |
| D8 | How address capture is expressed | `webform_address` is available now; `drupal/address` is not installed. The handler currently admits only `textfield` address pickers |

### 6.3 Explicit blockers

| # | Blocker | Effect | Status after Task 1 |
|---|---|---|---|
| B1 | **DBHub MCP fails to connect** — `-32000: MCP error -32000: Connection closed`; no `mcp__dbhub__*` tool is exposed | The project's mandated read-only schema and query-plan tool is unavailable. Any later task that needs a query plan (the guard's row-lock behaviour under contention, for one) cannot get one this way | **Open.** Task 1 substituted read-only `drush php:eval` over `Schema::tableExists()`, `update.update_hook_registry` and `configFactory()->listAll()`, plus direct reading of `.install`, and labelled every such claim `[runtime]` or `[source]` |
| B2 | **The Dispatch Planner has no tables in this database.** Schema 10008 installed; 10009–10012 pending; all five dispatch tables absent while the legacy table is present | Every Task 3–9 behaviour that reads or writes dispatch storage will fail until updates run | **Open by design.** Task 1 is forbidden to start database updates. Owner is decision D1 |
| B3 | **`custom_webform_handlers.notifications` does not exist** as stored configuration | No template and no recipient for any notification; the worker has nothing to compose from | **Open.** Update 10009 installs it |
| B4 | **The active operating profile makes the planner inert**: `self_bookable.service_types = ['m1']` matches no real service, and `holds.hold_seconds` is absent so `DispatchHoldWindow::seconds()` returns `NULL` | Nothing can be offered and no Hold can be claimed in this environment right now. Any manual verification of Tasks 5–6 against the current config will look like a code failure when it is a configuration state | **Open.** Documented in §1.7–§1.8 with the code paths that produce it |
| B5 | **Two Drupal 11 packages pinned and locked against Drupal 10.6 core** | The next `composer update` may try to drag scaffolding onto the Drupal 11 line | **Open.** First item of Task 2's checklist |
| B6 | **Courier is planner-eligible but unpriceable**: no `courier` arm in `QuoteCalculator`, no `quote.courier` rate tree | Every courier submission resolves to `manual_review` with reason `missing_or_unsupported_service_type`, which is precisely what the handler's Manual Review announcement gates on | **Open.** Hard Task 4 prerequisite |
| B7 | **GitNexus index is pinned to commit `c365148`** and reports "up-to-date", while all dispatch work is uncommitted | Its architecture answers cannot see the code under review; a bounded query for an uncommitted class returned no results at exit 0 | **Open.** Re-indexing was deliberately not run — long-running work needs explicit authorisation. Architecture claims here rest on Graphify plus direct source instead |
| B8 | **No payment owner exists** | The roadmap's central invariant — only successful payment creates a Reservation — has no implementation to attach to | **Open.** Tasks 3 and 6, constrained by §4 and §5 to avoid a second capacity writer |

None of these blockers was fixed by Task 1, and none could be: every remedy is either a
package install, a database update, a configuration write, or a long-running re-index,
and this task forbids all four.

---

## 7. Verification and scope confirmation

### 7.1 Commands run

Read-only throughout. `[host]` `ddev version`, `git --version`, `claude --version`,
`claude plugin list`, `claude mcp list`, `command -v rtk`, `command -v gh`,
`ddev describe` (credential-filtered), `ddev exec composer --version`,
`ddev composer validate --no-check-publish`, `composer show --locked`,
`git status --short --untracked-files=all`, `git diff --check`, `graphify query`,
`graphify path`. `[runtime]` `ddev drush status`, `ddev drush pm:list --status=enabled`,
`ddev drush updatedb:status --format=json`, and read-only `ddev drush php:eval` probes
against `Schema::tableExists()`, `update.update_hook_registry`,
`configFactory()->listAll()`, `moduleHandler()->moduleExists()`, and the plugin-manager
definition counts in §3.1.

Substitutions, both documented by the spec: `ddev drush plugin:list` does not exist in
Drush 13.6.2 → container plugin-manager inspection (§3.1). DBHub MCP failed to connect →
read-only `drush php:eval` plus direct `.install` reading (§6.3 B1).

`ddev drush cr` was **not** run. No Drupal state changed, so there is nothing to rebuild.

### 7.2 Evidence systems and their coverage limits

- **Graphify** — used first for structural orientation. AST-only in this project, so YAML
  and Markdown are outside its coverage; every routing, services, permissions and config
  claim here is labelled `[source]` from direct reading, never graph-established. One
  claim was corrected because of it (§1.12).
- **GitNexus** — index pinned to `c365148`, blind to the uncommitted dispatch work
  (§6.3 B7). Not cited for any claim.
- **Context7** — queried; corpus has no Advanced Queue or ECA library, and the core lock
  query returned nothing usable, so the affected claims were re-grounded in directly-read
  core source (§2).
- **DBHub** — unavailable, blocker B1.
- **`codebase-design` skill** — supplied the report's vocabulary.

### 7.3 Working-tree diff — exactly one file

```text
git diff --check                       → exit 0
                                         (one advisory: LF→CRLF on .gitignore, pre-existing)
baseline  git status --short -uall      → 49,126 lines  (captured to /tmp, outside the repo)
final     git status --short -uall      → 49,127 lines
diff of the two                         → 19752a19753
                                          > ?? docs/architecture/express-courier-current-state.md
```

The single added line is the allowed checkpoint document. 18 tracked files remain
modified, exactly as they were before Task 1 began; no product code, YAML, configuration,
schema or dependency file was formatted, normalised, reverted or repaired. Nothing was
committed, reset, stashed or discarded. No package was installed, no module enabled, no
database update started, no configuration exported, no test run.

### 7.4 Secret and customer-data review

The finished document was scanned for credential, token and PII patterns — `passw`,
`secret`, `AIza`, `Bearer`, any 32+ character opaque string, `hold_token_hash`,
`owner_hash`, email addresses and phone-number shapes. Every hit is a long *identifier*
(table, service, config-key or plugin name) or the settings **key name**
`custom_webform_handlers_server_api_key` reported as `configured`. **No credential
value, no Hold token, no hash and no customer datum appears anywhere in this document.**
`ddev describe`'s output was filtered before it was ever read, so DDEV's generated
database password never entered the transcript either.

### 7.5 Completion checklist

| Required section | Where | Present |
|---|---|---|
| 1. Environment and dependency inventory | §1.1–§1.13 | yes |
| 2. Native Capability Ledger, every category | §2 — skills, plugins/MCP, slash commands/extensions, search/edit, Git, CLI, sub-agents | yes |
| 3. Drupal Reuse Decision Matrix, every behavior | §3.2 — 11 rows, 10 columns | yes |
| 4. Current authority map | §4 — quote, route, eligibility, Slot offer, capacity, payment, Review Request, notification, plus confirmation | yes |
| 5. Legacy-versus-Dispatch overlap and duplicate-authority risks | §5.1–§5.2 | yes |
| 6. Task 2 prerequisites, unresolved decisions, explicit blockers | §6.1–§6.3 | yes |

Every custom responsibility the roadmap proposes for Tasks 2–9 carries either an
evidence-backed disposition or an explicit `unresolved` with the missing evidence named.
No second planner and no new custom module is proposed anywhere in this document.
