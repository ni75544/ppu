# Dispatch dashboard audit

What already exists behind `/admin/structure/webform/dispatch-planner`, what the
reservation ledger already carries, and what a Views-backed Dashboard would need.
Audit only — no Views code, no `hook_views_data()` implementation, no Composer change.

Routing, permissions and roles were read directly from the module's own YAML, because
YAML is outside this repository's AST-only Graphify coverage; controller behaviour and
the ledger shape were read from source and from the live schema.

## 1. Existing routes

### Board reads — GET only, `_permission: 'view dispatch board+administer dispatch planner'`

`+` is OR in a Drupal `_permission` requirement (a comma would be AND and would lock
out anyone holding just one).

| Route | Path | Handler |
| --- | --- | --- |
| `…dispatch_planner` | `/admin/structure/webform/dispatch-planner` | `DispatchPlannerAdminController::board` |
| `…dispatch_planner_day` | `…/dispatch-planner/day` | `::day` |
| `…dispatch_planner_week` | `…/dispatch-planner/week` | `::week` |
| `…dispatch_planner_reservation` | `…/dispatch-planner/reservation/{dispatch_reservation_id}` (`\d+`) | `::reservation` |

The board accepts exactly **two** query arguments, and refuses rather than coerces:

- `?resource=` — must be a string (an array from `resource[]=` is refused outright,
  because "coercion is how *unreadable* quietly becomes *some other Resource*"), and
  bounded by `RESOURCE_ID_MAX_LENGTH`. Absent or empty falls back to the configured
  Resource.
- `?date=` — must match `^\d{4}-\d{2}-\d{2}$` **and** survive a round trip through
  `!Y-m-d`, so `2026-02-31` is refused instead of rolling into March.

There is no state filter, no service-type filter, no submission lookup and no free-text
search. `#cache` is `max-age 0` with contexts `url.query_args, url.path,
user.permissions`.

### Staff mutations — `_permission: 'manage dispatch schedule'`

| Route | Path | Handler / form |
| --- | --- | --- |
| blackout list | `…/dispatch-planner/blackouts` | `DispatchOperationsController::blackouts` |
| blackout add | `…/blackouts/add` | form |
| blackout edit | `…/blackouts/{id}/edit` | form |
| blackout withdraw | `…/blackouts/{id}/withdraw` | form |
| release | `…/claim/{dispatch_reservation_id}/release` | `DispatchReservationReleaseForm` |
| replan | `…/reservation/{dispatch_reservation_id}/replan` | `DispatchReplanForm` |
| replan endpoint | `…/replan` (POST, CSRF header) | `DispatchOperationsController::replanEndpoint` |
| manual-review book | `…/queue/{dispatch_submission_id}/book` | `DispatchManualReviewConfirmForm` |
| resource status | `…/status` | `DispatchResourceStatusForm` |

Review workflow lives on its own permission, `review dispatch quotes`:
`…/review/{review_id}/{begin,approve,decline,cancel}`. The customer-facing tracker
`/quote/track/{code}` and `/quote/track/{code}/pay` are `_access: 'TRUE'` with
`no_cache: TRUE` — the code is a 192-bit credential, and is the only thing standing
between an anonymous request and a quote.

Settings pages: `google_api_settings`, `quote_settings` (`administer site
configuration`); `dispatch_settings`, `dispatch_notification_settings` (`administer
dispatch planner`).

Legacy fixed-slot screens under `/admin/content/webform-reservations`
(`administer custom webform reservations`) operate on the **other** table,
`custom_webform_handlers_reservation`, and are not part of Dispatch.

## 2. Fields already available in `custom_webform_handlers_dispatch_reservation`

24 physical columns, declared by `custom_webform_handlers_schema()`
(`custom_webform_handlers.install:90`). Grouped by what a Dashboard would do with them:

| Group | Columns |
| --- | --- |
| Identity | `dispatch_reservation_id` (PRI, auto-increment), `resource_id`, `webform_id`, `submission_id` |
| Lifecycle | `state`, `created`, `changed`, `expires_at` |
| Time | `pickup_arrival`, `window_start`, `window_end`, `timezone` |
| Margin | `inbound_reposition_seconds`, `outbound_reposition_seconds` |
| Location (PII) | `pickup_location`, `delivery_location` |
| Route | `route_seconds`, `route_meters` |
| Money | `price_snapshot_id`, `price_snapshot_cents`, `price_snapshot_currency` |
| Secrets | `hold_token_hash`, `owner_hash` |
| Commerce | `order_id` |

`state` is one of five values from `ReservationStoreInterface`: `held`, `confirmed`,
`released`, `expired`, `cancelled`. Only `held` and `confirmed` occupy grid time.

### What the board already renders

Timeline table header: `Occupied`, `Dispatch Window`, `Reserved margin`, `Kind`,
`State or reason`, `Reservation`, `Submission`, `Hold expires`, plus an `Operations`
column **only** when `mayManage()` passes. Rows come from `DispatchTimelineEntry`,
which carries `type`, `resourceId`, `startTimestamp`, `endTimestamp`, `state`,
`dispatchReservationId`, `webformId`, `submissionId`, `pickupArrival`, `timezone`,
`inboundRepositionSeconds`, `outboundRepositionSeconds`, `expiresAt`, `reason`.

### What the detail page already renders

17 fact rows: `Reservation`, `Resource`, `State`, `Local timezone`, `Dispatch Window`,
`Occupied, margin included`, `Reserved margin`, `Pickup arrival`, `Pickup`*,
`Delivery`*, `Route`, `Price snapshot`*, `Form`, `Submission`, `Claimed`,
`Last changed`. (* per-permission, see §4.)

### In the ledger but on no screen

- **`order_id`** — never referenced in `DispatchPlannerAdminController` (0 matches).
  The Commerce order linkage exists in data and is invisible to staff. This is the
  single most valuable column a Dashboard could add, and it costs no new storage.
- `route_meters` is rendered only folded into `routeText()`; there is no sortable
  distance column.
- `hold_token_hash` and `owner_hash` are correctly rendered nowhere and must stay that
  way. They are SHA-256 digests of a live capability and an anonymous session
  identifier; the store interface deliberately neither returns nor accepts a hash.

## 3. Required Dashboard columns and filters

The existing board answers one question well — "what is on driver-01's calendar on this
day?" It cannot answer the operational questions staff actually arrive with, because it
is day-scoped, resource-scoped and unsortable. A Dashboard is a *list* view over the
ledger, not a second calendar.

### Columns

| Column | Source | Notes |
| --- | --- | --- |
| Reservation | `dispatch_reservation_id` | link to the existing detail route |
| State | `state` | five known values; render as a label, not raw |
| Resource | `resource_id` | needed the moment a second resource exists |
| Pickup arrival | `pickup_arrival` + `timezone` | the operational sort key; format in the row's own zone, never site-default |
| Dispatch Window | `window_start`, `window_end` | one column, formatted pair |
| Hold expires | `expires_at` | only meaningful for `held`; blank otherwise |
| Form | `webform_id` | multi-Webform readiness, since the allowlist is config |
| Submission | `submission_id` | link **only** when Webform's own access check passes |
| Order | `order_id` | **new disclosure** — link to the Commerce order; financial permission |
| Price | `price_snapshot_cents` + `_currency` | financial permission |
| Route | `route_seconds`, `route_meters` | separately sortable |
| Pickup / Delivery | `pickup_location`, `delivery_location` | address permission |
| Claimed / Changed | `created`, `changed` | audit trail |

### Filters

| Filter | Column | Why it is needed |
| --- | --- | --- |
| State | `state` | "show me everything still `held`" is the most common triage query and is impossible today |
| Date range on pickup arrival | `pickup_arrival` | the board is single-day; a week/month range is the actual planning unit |
| Resource | `resource_id` | exposes what `?resource=` already does, but sortable and combinable |
| Form | `webform_id` | separates services once more than `hg` is allowlisted |
| Expiring holds | `expires_at` < now | surfaces stale holds that silently occupy capacity |
| Has order | `order_id` IS NOT NULL | separates paid work from unpaid holds |
| Submission id | `submission_id` | staff arrive with a submission number, not a reservation id |

Deliberately **not** included: any filter or exposed sort on `hold_token_hash` or
`owner_hash`. A filter on a hash is an oracle for the underlying secret.

Two behaviours the current board has that a Views Dashboard must not lose:

1. **`max-age 0`.** Availability is live; a cached Dashboard row showing a hold that
   expired two minutes ago is worse than no Dashboard. Any view over this table needs
   its cache tags/max-age set deliberately.
2. **Timezone honesty.** Every timestamp is rendered in the row's own `timezone`
   column, not the site default. A Views date formatter defaults to the site timezone
   and would silently misreport a resource in another zone.

## 4. Exact role and PII boundaries

Eight permissions, all `restrict access: true`:

`administer custom webform reservations`, `administer dispatch planner`,
`manage dispatch schedule`, `view dispatch board`, `view dispatch customer contact`,
`view dispatch exact addresses`, `view dispatch financial details`,
`review dispatch quotes`.

The four disclosure permissions split PII **by kind, not by role**, which is what lets a
financial viewer see money without seeing addresses.

| Permission | Checked by | Guards |
| --- | --- | --- |
| `view dispatch board` | route requirement (OR `administer dispatch planner`) | reaching the board at all |
| `manage dispatch schedule` | `mayManage()` (L350) | the `Operations` column, and every mutation route |
| `view dispatch exact addresses` | `mayViewAddresses()` (L384) | `pickup_location`, `delivery_location` |
| `view dispatch financial details` | `mayViewFinancials()` (L398) | `price_snapshot_*` |
| `review dispatch quotes` | `mayReview()` (L369) | review actions |
| `view dispatch customer contact` | **nothing — see below** | *nothing, today* |

A withheld field is rendered as `Not shown to your role` by `withheld()`. It **states
withholding rather than omitting the row**, so staff can see that a fact exists and
that they cannot read it — a Views Dashboard that simply drops the column would lose
that distinction.

Roles the module installs (`drush role:list`):

| Role | Grants |
| --- | --- |
| `dispatch_schedule_manager` | manage dispatch schedule, review dispatch quotes, view dispatch board, view dispatch exact addresses |
| `dispatch_viewer` | view dispatch board |
| `dispatch_financial_viewer` | view dispatch board, view dispatch customer contact, view dispatch financial details |

Note the deliberate asymmetry: the schedule manager gets addresses but **not** financial
details or customer contact; the financial viewer gets money and contact but **not**
addresses.

### The finding that matters most for a Dashboard

**`view dispatch customer contact` is declared and granted, but never checked in code.**
`grep -rn "view dispatch customer contact" modules/custom_webform_handlers/` returns
only `custom_webform_handlers.permissions.yml` and two
`config/install/user.role.*.yml` files — never a PHP check. And no admin surface in this
module renders a customer name, email or phone: searching
`DispatchPlannerAdminController` and `ReservationAdminController` for
`full_name|phone_number|'email'|customerEmail|customer_email` returns nothing.

Contact PII is reachable only through Webform's **own** canonical submission route, and
`submissionCell()` links to `entity.webform_submission.canonical` **only when
`$url->access()` passes** — Webform's access check, not this module's.

Consequence, stated plainly: a Views Dashboard that adds a name / email / phone column
would be the **first surface in this module to disclose customer contact**. It must
check `view dispatch customer contact` itself. Nothing upstream will do it, because
nothing upstream ever has. Adding such a column without that check would silently grant
contact PII to every holder of `view dispatch board` — including `dispatch_viewer`,
whose entire purpose is to see the calendar and nothing else.

## 5. Can core Views data be added through `hook_views_data()` without an entity migration?

**Yes.** Confirmed, with a core precedent, and no entity migration is required.

The module has **no** Views integration at all today: no `*.views.inc`, and
`hook_views_data` / `views_data` appear nowhere under
`modules/custom_webform_handlers/`. `views` and `views_ui` are both enabled. There is
also **no content entity type anywhere in the module** — no `@ContentEntityType`
annotation, no `ContentEntityBase` subclass. `custom_webform_handlers_dispatch_reservation`
is a plain `hook_schema()` table (`custom_webform_handlers.install:52`, table at `:90`).

That is exactly the shape core itself exposes to Views. `watchdog` is a plain
non-entity table, and `core/modules/dblog/dblog.views.inc` declares it in ~15 lines:

```php
function dblog_views_data() {
  $data['watchdog']['table']['group'] = t('Watchdog');
  $data['watchdog']['table']['base'] = [
    'field' => 'wid',
    'title' => t('Log entries'),
    'help'  => t('Contains a list of log entries.'),
  ];
  $data['watchdog']['wid'] = [ /* field / filter / argument / sort ids */ ];
  $data['watchdog']['uid'] = [
    'relationship' => [
      'base' => 'users_field_data',
      'base field' => 'uid',
      'id' => 'standard',
    ],
  ];
}
```

Applied here, `custom_webform_handlers_dispatch_reservation` becomes a base table on
`dispatch_reservation_id`, every integer column gets `numeric` filter/sort, timestamps
get `date`, `state` gets `in_operator` with the five known values, and `submission_id`
carries a `relationship` to `webform_submission` — which is a real Views base table
(`WebformSubmission` declares `base_table = "webform_submission"` with a
`WebformSubmissionViewsData` handler), so joining to it is standard, not bespoke.

Read from core and contrib source plus Context7's Drupal reference; `hook_views_data()`
lives in a `.views.inc` file which Views loads itself, so this is a new file inside
`modules/custom_webform_handlers/` and touches nothing else.

### Four things `hook_views_data()` alone will *not* do

Recorded now so they are designed for rather than discovered later:

1. **Per-field permission checks.** `hook_views_data()` declares structure, not access.
   Views enforces access at the *display* level (a permission or role on the display),
   which is all-or-nothing. Per-column withholding — today's
   `mayViewAddresses()` / `mayViewFinancials()` / the currently-unenforced
   `view dispatch customer contact` — needs a custom field handler overriding
   `HandlerBase::access(AccountInterface $account)` (declared on
   `ViewsHandlerInterface`, default TRUE). Declaring the columns with the `standard`
   field plugin would expose addresses and prices to anyone who can reach the display.
2. **`Not shown to your role`.** A handler returning FALSE from `access()` removes the
   column entirely. Reproducing `withheld()` — stating that a fact exists and is being
   withheld — needs the handler to *render* the notice, not to deny the field.
3. **Timezone-correct output.** Views' date formatters use the site timezone. Each row
   carries its own `timezone` column and must be formatted in it.
4. **Live freshness.** The current board is `max-age 0`. A view over held reservations
   with default caching will show expired holds as live.

### Consequences for scope

- No entity migration, no `webform_booking`, no Composer change is needed to build the
  Dashboard.
- The work is one `.views.inc` file plus a small number of custom field handlers for the
  permission-gated columns. It is bounded, and it is additive: the existing board,
  detail, replan, release and blackout routes keep working untouched.
- The first Dashboard column that discloses customer contact must carry its own
  `view dispatch customer contact` check, per §4.




