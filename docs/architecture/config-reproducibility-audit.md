# Configuration reproducibility audit

Task 18A. Audited 2026-09-04 against the working tree at commit `9581b4e2`
(`chore(repo): quarantine local execution artifacts`), with a clean
`git status --short`, no pending database updates, and DDEV web + database
services available.

## 1. Scope and no-mutation statement

This audit answers one question: **can this repository recreate the Express
Courier, Dispatch, Commerce, reservation-dashboard, RBAC and QA behaviour on a
clean Drupal database, without relying on undocumented active-database
configuration?**

It is an evidence-and-documentation task. It changed nothing:

- No `drush cex`, `drush cim`, `drush updb`, `drush cr`, `drush si`, no database
  import, no database drop, no SQL write, and no configuration write.
- No edit to active Drupal configuration, database rows, Composer files, DDEV
  files, module code, or QA scripts.
- No browser or Selenium flow was started, so this audit created no Webform
  submission, order, reservation, Hold, payment, flood counter, or watchdog
  entry.
- Every database observation was a read-only `SELECT` / `COUNT(*)` through
  `drush sqlq`, or a read-only `drush php:script` probe.
- No broad configuration export and no clean install were attempted, per the
  task boundary.
- The only repository change is this file and the single commit that adds it.

**No configuration value appears anywhere in this document.** Where drift had to
be quantified it is reported as *structure*: configuration names, key paths, leaf
counts, and element cardinality. Before comparison every scalar was replaced by a
type-and-length fingerprint, so no value was ever held in a printable form. Keys
such as `google_maps_browser_api_key` are named only as keys; no key material,
credential, token, hash, connection string, absolute host path, address, or
customer datum is reproduced here.

## 2. Evidence sources and coverage limits

| Source | Used for | Limit that applies |
| --- | --- | --- |
| [`config/install/`](../../modules/custom_webform_handlers/config/install) — 15 files | Configuration applied unconditionally at module install | Authoritative for what a clean install lays down |
| [`config/optional/`](../../modules/custom_webform_handlers/config/optional) — 4 files | Configuration applied only when its dependencies are already met | Silently skipped, without error, if a dependency is absent |
| [`custom_webform_handlers.install`](../../modules/custom_webform_handlers/custom_webform_handlers.install) — 4098 lines, 52 functions | `hook_schema()`, `hook_requirements()`, 39 update hooks | The authorship authority for everything not shipped as YAML |
| Module YAML: `.info.yml`, `.routing.yml`, `.services.yml`, `.permissions.yml`, `.links.menu.yml`, `.libraries.yml`, `config/schema/` | Routes, services, permissions, admin menu, libraries, config schema | Outside Graphify coverage; read directly from source, as project policy requires |
| Read-only Drush: `config:status`, `sqlq`, `php:script` | Active configuration names, export state, table row counts | Names and counts only — no configuration entity was dumped |
| Graphify AST graph | PHP / JavaScript symbol relationships | `.yml` and `.md` are document classes the AST pass does not extract, so **absence of a YAML edge proves nothing**; no negative conclusion below rests on Graphify |
| GitNexus MCP | *unavailable during this audit* | `LadybugDB unavailable … Cannot open database in read-only mode while checkpoint is in progress`; its index is also 4 commits behind HEAD. Nothing in this document relies on it |
| DBHub MCP | not configured in this session | Database evidence therefore came from read-only Drush, which is the source this task designates for database state |

Every count of zero below was produced by a command shape that returned a
non-zero count for a known-present needle in the same run. The absence of
`hook_install()` in §3, for instance, was measured beside
`^function custom_webform_handlers_requirements\(` and
`^function custom_webform_handlers_schema\(`, which each returned 1; the
enumeration of *all* function definitions whose name contains `install` was
listed in full rather than counted, so a substring decoy could not be mistaken
for the hook.

## 3. Active and exported configuration summary

| Measure | Count |
| --- | --- |
| Active configuration objects (one collection) | 323 |
| Configuration objects present in the sync directory | 0 |
| `drush config:status` → `Only in DB` | 323 |
| `drush config:status` → `Only in sync directory` | 0 |
| `drush config:status` → differing between active and sync | 0 |
| Configuration objects shipped by this module | 19 — 15 install + 4 optional |
| Shipped objects structurally identical to active | 11 |
| Shipped objects that have drifted from active | 8 |
| Enabled modules recorded in `core.extension` | 69 |
| Default theme / admin theme | `olivero` / `claro` |
| Tables created by `hook_schema()` | 9 |
| Update hooks defined | 39 — `custom_webform_handlers_update_10001` … `_10039` |
| `hook_install()` implementations | 0 |
| `hook_post_update_NAME()` implementations | 0 |
| `hook_modules_installed()` implementations | 0 |

Three independent measurements agree on 323: the `drush config:status` row count,
`\Drupal::service('config.storage')->listAll()`, and `SELECT COUNT(*) FROM config`.

**`Only in DB = 323` must be read correctly.** `drush config:status` compares
active storage against the *configuration-sync directory*, not against module
`config/install` defaults. This site's sync directory is Drupal's auto-generated
`sites/default/files/config_<hash>/sync`, which holds no export — already
recorded in [express-courier-launch.md §5](../operations/express-courier-launch.md).
So 323 is a statement about export state, not about source-backing. Source-backing
had to be measured separately, by comparing each of the 19 shipped objects against
its active counterpart leaf by leaf.

Row counts for the operational tables (counts only, no row content):

| Table | Rows |
| --- | --- |
| `custom_webform_handlers_reservation` | 11 |
| `custom_webform_handlers_dispatch_reservation` | 3 |
| `custom_webform_handlers_dispatch_guard` | 13 |
| `custom_webform_handlers_dispatch_status` | 0 |
| `custom_webform_handlers_dispatch_blackout` | 0 |
| `custom_webform_handlers_dispatch_notification` | 5 |
| `custom_webform_handlers_dispatch_notify_claim` | 2 |
| `custom_webform_handlers_checkout_attempt` | 3 |
| `custom_webform_handlers_review_request` | 2 |
| `webform_submission` | 40 |
| `commerce_order` / `commerce_order_item` | 3 / 3 |
| `commerce_store` | 1 |
| `advancedqueue` / `queue` | 17 / 2 |
| `users_field_data` | 5 |

## 4. Required-capability matrix

| Capability | Active configuration families | Source of truth today | Reproducible from clean DB? | Required remediation |
| --- | --- | --- | --- | --- |
| Express Courier Webform `hg` | `webform.webform.hg` | **Active database only.** No shipped file names it; 12 sites in the install file call `Webform::load('hg')` and `custom_webform_handlers_update_10016` returns "The hg webform was not found…" when it is absent | **No** | Ship `config/install/webform.webform.hg.yml`, or build the form from a new `hook_install()` |
| Dispatch booking profiles and availability settings | `custom_webform_handlers.dispatch` | `config/install` **and** 5 `getEditable()` writes spread across update hooks `_10014` … `_10039` | **Partly** | Fold the 8 drifted key paths in §6 back into the shipped default |
| Dispatch reservation ledger and planner tables | none — Schema API, not configuration | `custom_webform_handlers_schema()`, 9 tables | **Yes** | none |
| Commerce order type, order item type, contact fields | `commerce_order.commerce_order_type.dispatch`, `commerce_order.commerce_order_item_type.dispatch_service`, `field.storage.commerce_order.field_dispatch_contact_name` / `_phone`, `field.field.commerce_order.dispatch.field_dispatch_contact_name` / `_phone` | `config/install`, re-asserted by `_custom_webform_handlers_install_commerce_types()` from `_update_10014` and `_update_10018` | **Yes** | none |
| Dispatch checkout flow | `commerce_checkout.commerce_checkout_flow.dispatch` | `config/install`, plus one `getEditable()` write in the install file | **Yes** | none blocking — the drift is `dependencies.module` recalculation |
| Commerce store | none — `commerce_store` is a **content** entity, never configuration | Active database only: 1 row, 0 creators anywhere in module source | **No** | A documented manual creation step, or an install-time creator; a configuration export can never carry it |
| Commerce payment gateway wiring | `commerce_payment.commerce_payment_gateway.*` — **0 objects in source and 0 in the active database** | Documented manual admin step — [launch §1 prerequisite 1 and §10](../operations/express-courier-launch.md) | **No, deliberately** | Keep it manual and documented. A gateway holds credentials and must not be committed |
| Advanced Queue queues and workers | `advancedqueue.advancedqueue_queue.custom_webform_handlers_dispatch`, `…_maintenance` | `config/optional` (both identical to active); the three workers are PHP plugins under `src/Plugin/AdvancedQueue/JobType/` | **Yes**, provided `advancedqueue` is enabled first | Document the enable order, or promote the two queues to `config/install` |
| Dispatch reservation View | `views.view.dispatch_reservations` | `config/install`, then reshaped by `_update_10038` | **Partly** | Fold the 13 active-only display leaves back into the shipped file |
| Dispatch administration menu | none — declared in YAML | `custom_webform_handlers.links.menu.yml` | **Yes** | none |
| Dispatch Viewer / Financial Viewer / Schedule Manager roles | `user.role.dispatch_viewer`, `user.role.dispatch_financial_viewer`, `user.role.dispatch_schedule_manager` | `config/install`; the 8 permissions they grant are declared in `custom_webform_handlers.permissions.yml` | **Partly** | `user.role.dispatch_schedule_manager` differs from the committed file in 4 permission entries |
| Routes exposing the public Courier flow | none — declared in YAML | `custom_webform_handlers.routing.yml`, 38 routes | **Yes** | none |
| Homepage block exposing the public Courier flow | `block.block.hg_express_courier_home` | **Created only inside `custom_webform_handlers_update_10016`**, against the then-current default theme | **No** | Ship the block, or create it from `hook_install()` |
| Google Routes / API environment settings | `custom_webform_handlers.settings` → `google_maps_browser_api_key`; the server key is read via `Settings::get('custom_webform_handlers_server_api_key')` | Browser key: active database. Server key: `settings.php`, outside configuration entirely | **Environment-specific by design** | Keep both keys out of version control; separately, 5 shipped-only `route_*` keys never reached the active site |
| ECA reaction layer | `eca.eca.custom_webform_handlers_popularity`, `eca.eca.custom_webform_handlers_route_warm` | `config/optional` | **Yes**, provided `eca` is enabled | none — drift is `dependencies.module` recalculation |
| Module enablement state | `core.extension` | Active database only. `custom_webform_handlers.info.yml` declares 8 dependencies; 69 modules are actually enabled | **Partly** | Record the enabled-module list as a deploy step, not as committed configuration |
| Selenium / QA-only configuration and test users | no configuration family; 5 rows in `users_field_data` | QA scripts under `qa/` are tracked; the test accounts exist only in the active database | **Not required, and must stay that way** | Never seed QA accounts into shipped configuration |

## 5. Source-of-truth trace and database-only classification

### 5.1 The structural finding

**Drupal never runs `hook_update_N` on a fresh module install.** It stamps the
module's schema version at the highest available number and skips every update
hook. This module has **39 update hooks and no `hook_install()`, no
`hook_post_update_NAME()`, and no `hook_modules_installed()`** — confirmed by
enumerating every function definition in the install file whose name contains
`install`, which yields only three helpers:
`_custom_webform_handlers_install_commerce_types()` (called from `_update_10014`
and `_update_10018`), `custom_webform_handlers_role_install_report()`, and
`custom_webform_handlers_install_maintenance_queue()` (called from
`_update_10024`).

So on a clean database exactly two things happen: `hook_schema()` creates the 9
tables, and the 19 shipped configuration objects are written. **Everything the 39
update hooks would otherwise create does not come into existence at all** — no
error, no warning, just absence. [express-courier-launch.md §5](../operations/express-courier-launch.md)
already states that "the update hooks in §4 are the configuration authority" for
this site; that is precisely why a clean install diverges from it.

### 5.2 Where each origin actually contributes

| Origin | Contribution measured |
| --- | --- |
| `config/install/` | 15 objects, all present in active configuration |
| `config/optional/` | 4 objects, all present in active configuration |
| `*.install` update hooks | Unreachable on a fresh install. On this site they authored `webform.webform.hg`, `block.block.hg_express_courier_home`, the Advanced Queue queue instances, the ECA models, the reservation View reshaping, and 13 `getEditable()` writes across `custom_webform_handlers.settings` (7), `custom_webform_handlers.dispatch` (5), and the Dispatch checkout flow (1) |
| Drupal core / contrib install defaults | The bulk of the 304 active objects this module does not ship; reproducible simply by enabling the same modules |
| DDEV / environment variables / `settings.php` | The Google Routes **server** key, database connection, Redis wiring — correctly outside configuration and outside Git |
| Active database only | The families named in §5.3 and §6 |

### 5.3 Database-only families, sub-classified

| Family (name only) | Sub-classification |
| --- | --- |
| `system.theme`, and the core/contrib defaults among the other 304 | **Safe core default** — recreated by enabling the same modules |
| `custom_webform_handlers.settings` → `google_maps_browser_api_key`; the `Settings::get()` server key; database and Redis wiring | **Environment-specific local setting** — must stay out of Git |
| `webform.webform.hg` | **Required portable product configuration** |
| `block.block.hg_express_courier_home` | **Required portable product configuration** |
| `commerce_store` (content entity, 1 row) | **Required portable product configuration**, not expressible as config export |
| `core.extension` — 69 enabled modules vs 8 declared dependencies | **Required portable product configuration**, as a deploy step |
| The 8 drift loci in §6 within already-shipped objects | **Required portable product configuration** — shipped, but stale |
| 5 rows in `users_field_data`, QA fixtures | **QA-test-only data** — must never be shipped |
| `webform.webform.da` | **Unknown, needs a decision** — database-only, zero references in module source, and not a contrib-shipped form |

## 6. Blockers

Nine blockers, in the order a clean install would hit them. Each is a name, a
count, or a key path — never a value.

**B1 — `webform.webform.hg` exists only in the active database.** No file in
`config/install/` or `config/optional/` defines it (0, against a positive control
of 1 for `views.view.dispatch_reservations.yml`). The entire Express Courier
journey, the Dispatch allowlist target, the shared date shell and every browser
gate address this one form. On a clean database it is absent, and
`custom_webform_handlers_update_10016` — which would not run anyway — is written
to return "The hg webform was not found…" and change nothing.

**B2 — `block.block.hg_express_courier_home` is created only inside
`custom_webform_handlers_update_10016`** (block storage `create()` at install
file line ~1600). Without it the homepage does not expose the Courier flow.

**B3 — `custom_webform_handlers.dispatch` ships closed.** The shipped default
carries **0 elements** at `workflow.dispatch_webforms`; the active site carries
**1**. The Dispatch allowlist — the gate that makes Dispatch own the form — was
opened only by an update hook. A clean install would boot with Dispatch
governing nothing. Ten further key paths differ: `resource.id`,
`resource.working_hours`, `resource.breaks`, `resource.operational_buffer_minutes`,
`resource.repositioning_buffer_minutes`, `resource.return_to_base`,
`durations.delivery_work_minutes`, `manual_review.handling_flags`, and
`recipients.customer_email_field`. Shipped 143 leaves, active 132.

**B4 — `views.view.dispatch_reservations` drifted after `_update_10038`.** 13
active-only leaves under `display.default` and `display.page_1`, and 2
shipped-only leaves under `dependencies.module`. The Reservation Dashboard a
clean install renders is not the dashboard that passed acceptance.

**B5 — `user.role.dispatch_schedule_manager` differs from its committed file in 4
permission entries** (`permissions.0` … `permissions.3`). The other two Dispatch
roles are identical to source.

**B6 — `custom_webform_handlers.settings` has 5 shipped-only keys that never
reached the active site**: `route_departure_bucket_seconds`,
`route_departure_min_lead_seconds`, `route_scheduled_cache_ttl`,
`route_traffic_fallback_inflation_percent`, `route_traffic_model`. A clean install
would run with different routing behaviour from the site that was accepted — in
this one direction, the *shipped* file is ahead of the database.

**B7 — the `commerce_store` content entity has no creator in source.** 1 row
active, 0 module code paths that create one. Commerce cannot complete an order
without a store, and no configuration export can carry it.

**B8 — `core.extension` is database-only.** 69 modules are enabled; the module's
`.info.yml` declares 8 dependencies. The remaining enablement state is neither
committed nor documented as an ordered deploy step.

**B9 — `webform.webform.da` is unowned.** Database-only, 0 references in module
source, and not shipped by contrib. It must be classified before any export is
packaged, so it is neither silently shipped nor silently lost.

Not a blocker, recorded to prevent a false alarm: **0
`commerce_payment.commerce_payment_gateway.*` objects exist in source *and* 0 in
the active database.** Nothing is missing from Git that exists on the site. A
gateway is a documented manual admin step in
[launch §1 and §10](../operations/express-courier-launch.md) and holds
credentials, so it must stay uncommitted.

## 7. Measured drift in the 19 already-shipped families

Method: flatten the shipped YAML and `\Drupal::config($name)->getRawData()` to
leaf paths; replace every scalar with a type-and-length fingerprint; compare.
`uuid` and `_core` are excluded as install-time noise. Counts are leaves;
`@depth2` lists key paths, which are structure, not values.

| Shipped object | Only in shipped | Only in active | Changed | Depth-2 loci |
| --- | --- | --- | --- | --- |
| `custom_webform_handlers.dispatch` | 14 | 3 | 6 | `workflow.dispatch_webforms`, `resource.*`, `manual_review.handling_flags`, `recipients.customer_email_field`, `durations.delivery_work_minutes` |
| `views.view.dispatch_reservations` | 2 | 13 | 0 | `dependencies.module`; `display.default`, `display.page_1` |
| `custom_webform_handlers.settings` | 5 | 0 | 1 | the five `route_*` keys; `google_maps_browser_api_key` |
| `user.role.dispatch_schedule_manager` | 0 | 0 | 4 | `permissions.0` … `permissions.3` |
| `commerce_checkout.commerce_checkout_flow.dispatch` | 1 | 0 | 2 | `dependencies.module` |
| `commerce_order.commerce_order_item_type.dispatch_service` | 0 | 1 | 0 | `dependencies.module` |
| `eca.eca.custom_webform_handlers_popularity` | 1 | 0 | 0 | `dependencies.module` |
| `eca.eca.custom_webform_handlers_route_warm` | 1 | 0 | 0 | `dependencies.module` |

The other **11 are structurally identical** to the active site and need no work:
`commerce_order.commerce_order_type.dispatch`, the four
`field.*.commerce_order…field_dispatch_contact_*` objects,
`custom_webform_handlers.notifications`, `custom_webform_handlers.ranking`,
`user.role.dispatch_viewer`, `user.role.dispatch_financial_viewer`, and both
`advancedqueue.advancedqueue_queue.custom_webform_handlers_*` queues.

Allowlist cardinality, counts only:

| Key path | Shipped | Active |
| --- | --- | --- |
| `workflow.dispatch_webforms` | 0 | 1 |
| `booking_profiles.services` | 7 | 7 |
| `self_bookable.single_driver_service_types` | 1 | 1 |
| `manual_review.service_types` | 6 | 6 |

Four of the eight drifted objects differ **only** under `dependencies.module`,
which Drupal recalculates on save. Those are benign. The four that are not benign
are B3, B4, B5 and B6.

## 8. Minimal ordered remediation plan for Task 18B

Ordered so each step is provable before the next. No step requires a broad
`drush cex`, a database dump, or an unreviewed export.

1. **Classify `webform.webform.da`** (B9). Decide keep-and-ship, or
   document-and-drop. Everything downstream depends on knowing whether it is
   product configuration.
2. **Add `hook_install()`** to `custom_webform_handlers.install`, delegating to
   the same idempotent helpers the update hooks already call. This is the single
   change that makes fresh installs and upgraded installs converge.
3. **Ship `webform.webform.hg`** as `config/install/webform.webform.hg.yml`
   (B1), reviewed field by field so no submission data, test value, or key rides
   along.
4. **Ship `block.block.hg_express_courier_home`** (B2), or create it from
   `hook_install()` against the then-current default theme.
5. **Reconcile the four non-benign drifted objects** (B3, B4, B5, B6): open the
   Dispatch allowlist in the shipped default, fold the 13 View display leaves
   back, align the 4 Schedule Manager permission entries, and decide whether the
   5 shipped-only `route_*` keys are the intended behaviour — then seed them.
6. **Give `commerce_store` a reproducible origin** (B7): an install-time creator,
   or a documented manual step promoted into
   [express-courier-launch.md §1](../operations/express-courier-launch.md).
7. **Record the enable order** (B8) as an ordered deploy step covering the 69
   enabled modules, keeping `core.extension` out of version control.
8. **Prove it on a clean isolated database** — a throwaway site, never `ppu` and
   never `ppu-qa`: install the module, then assert that the Dispatch allowlist is
   non-empty, the Courier form and homepage block exist, the three roles carry
   their committed permissions, the View renders, and the two queues exist.
9. **Re-run the browser gate at both widths on the clean site**, sentinel-wrapped
   and write-free on any read-only target, and only then attest the release.

## 9. Verdict

NOT YET REPRODUCIBLE

> Superseded on 2026-09-04 by [§11.7](#117-verdict). Kept as the audit's original
> finding, because the remediation is only legible against it.

Nine required capabilities fail the test. Two of them — the Express Courier form
`webform.webform.hg` and the homepage block `block.block.hg_express_courier_home`
— exist only in the active database, and the mechanism that created them, an
update hook, is skipped entirely on a fresh install. A third, the Dispatch
allowlist inside `custom_webform_handlers.dispatch`, ships with 0 entries where
the running site has 1, so a clean install would boot with Dispatch governing
nothing. A fourth, the `commerce_store` content entity, cannot be expressed as
configuration at all and has no creator in source.

The decision rule is not satisfied: a required capability that exists only in the
active database makes the project not reproducible, however correctly the current
local site behaves.

## 10. What this does and does not overturn

The Express Courier and Dispatch browser acceptance results remain what they were:
green at desktop and mobile widths, mutation-sentinel-wrapped, on the site that
was actually built. Nothing in this audit re-opens them, and nothing here reports
a functional defect on the running site.

But a green gate on the built site is not evidence of clean-environment
reproducibility. Those runs exercised configuration that 39 update hooks had
already applied to a database that existed before the run. They cannot speak for a
database that has never seen those hooks. Until Task 18B closes the nine blockers
and proves the result on a clean isolated database, "it works here" is the only
claim the evidence supports.

## 11. Task 18B resolution — 2026-09-04

Section 9's verdict is superseded by [§11.7](#117-verdict) below. The nine
blockers it counted are closed; a tenth condition, outside configuration, is not.

### 11.1 Each blocker family and its committed source of truth

| Blocker | Committed source of truth | State |
| --- | --- | --- |
| B1 `webform.webform.hg` | `config/install/webform.webform.hg.yml` | added |
| B2 `block.block.hg_express_courier_home` | `config/install/block.block.hg_express_courier_home.yml` | added |
| B3 `custom_webform_handlers.dispatch` ships closed | `config/install/custom_webform_handlers.dispatch.yml` — `workflow.dispatch_webforms` now carries 1 entry, `hg` | reconciled |
| B4 `views.view.dispatch_reservations` drift | `config/install/views.view.dispatch_reservations.yml` | reconciled |
| B5 `user.role.dispatch_schedule_manager` drift | `config/install/user.role.dispatch_schedule_manager.yml` | reconciled |
| B6 `custom_webform_handlers.settings` drift | `config/install/custom_webform_handlers.settings.yml` | reconciled |
| B7 `commerce_store` has no creator | `src/Install/CommerceStoreInstaller.php`, invoked from `custom_webform_handlers_install()` | added |
| B8 `core.extension` is database-only | `custom_webform_handlers.info.yml` — 9 declared dependencies | added |
| B9 `webform.webform.da` unowned | classified, deliberately not packaged — [§11.8](#118-the-legacy-artifact-left-where-it-is) | classified |

B1 and B2 were the two objects that existed only in the active database. Both are
now files, so the mechanism that created them — an update hook a fresh install
skips — is no longer the only path to them.

B8 is answered by declared dependencies, not by versioning `core.extension`. The
clean install named the set that is actually required, which is what the
`.info.yml` now carries; the remaining enabled modules on the active site are
site-level choices that a module has no standing to assert.

B9 is the one blocker closed by a decision rather than a file.

### 11.2 Environment-only values excluded, by category

Nothing in the packaged baseline carries an environment value. Excluded, by
category only:

- Google Maps browser and server API keys — the browser key ships as an empty
  string in `custom_webform_handlers.settings`; the server key is read from
  `Settings` and has no configuration object at all, by design.
- Payment gateway credentials — no `commerce_payment_gateway` object is packaged,
  matching the active site, where none exists either.
- Absolute filesystem paths, database connection strings, and site identity
  (`uuid`, `_core.default_config_hash`).
- Derived Views cache and render metadata that Views recalculates on save.
- Customer data, review codes, session identifiers and tokens: no submission,
  reservation, order, payment or review row is packaged, and no content entity is
  exported.

Retained deliberately, because they are operational and not environmental: every
`route_*` tuning key in `custom_webform_handlers.settings`, the whole Dispatch
resource profile, the grid, buffers, horizon, hold seconds, duration model,
eligibility policy and the New York public-time / UTC storage split.

### 11.3 Drift reconciliation, classified

Every difference between a shipped file and its active value was classified before
being resolved. No active required value was discarded.

**Required portable value — active wins.** `custom_webform_handlers.dispatch`
carried a fictional shipped default: a resource id that does not exist, a six-day
week, a lunch break, buffers roughly four times the real ones, and an empty
allowlist. Reconciled to the active, verified values at `resource.id`,
`resource.working_hours`, `resource.breaks`,
`resource.repositioning_buffer_minutes`, `resource.operational_buffer_minutes`,
`resource.return_to_base`, `durations.pickup_work_minutes`,
`durations.delivery_work_minutes` and `workflow.dispatch_webforms`.

**Required portable value — shipped wins, and the active site was behind.** Two
cases, and both are findings rather than bookkeeping:

- `recipients.customer_email_field` had no schema entry, so a value the running
  site depends on could not be expressed in a packaged file at all. The key is now
  shipped and `config/schema/custom_webform_handlers.schema.yml` declares it.
- `manual_review.handling_flags` shipped 6 entries against 5 active. The missing
  one is `unsure`, which is not decorative: the module's own handler maps an
  attestation that is absent, unchecked or crafted to `unsure` before Dispatch sees
  the request, and the request factory reads it as the customer declining to
  vouch. With the flag absent from the list, that request is read as a clean
  answer instead of routing to Manual Review. `custom_webform_handlers_update_10040`
  adds it by addition only, and both local sites now carry 6.

**Obsolete shipped default.** `views.view.dispatch_reservations` listed `user` and
`views` under `dependencies.module`; `user` was left over from the core `perm`
access plugin that the custom `dispatch_board_audience` plugin replaced, and Views
does not list itself. Removed.

**No value change, but a false-drift source.** `quote.round_trip_multiplier`
shipped as `2` where the schema declares a float, so it installed as an integer and
every later comparison saw a type change where no value had changed; it now ships
as `2.0`. `user.role.dispatch_schedule_manager` held the same four permissions in a
different order from the one Drupal stores; reordered, set unchanged.

B4, B5 and B6 are therefore downgraded: measured as drift, they were an
authored-order difference, a type mismatch, and a stale `dependencies.module`
entry — none of them a capability a clean install would have lacked. B6's five
"shipped-only" routing keys were the shipped file being ahead of a stored copy
that predates them, so they are retained as-is.

### 11.4 Fresh-install bootstrap

A fresh install runs no `hook_update_N` at all: Drupal stamps the schema version
and skips every one of them. The clean install therefore had to work from
`hook_schema()`, `config/install`, `config/optional` and `hook_install()` alone,
and that is what was built.

`custom_webform_handlers_install()` calls `CommerceStoreInstaller::ensureStore()`
through the `custom_webform_handlers.store_installer` service. It is idempotent by
construction — it returns `kept` when any store already exists and only creates one
when none does, so it can never produce a second store — and it is separate from
the 39 historical migration hooks, which remain untouched migration logic for
already-installed sites. Core runs `hook_install()` after `config/install` is
imported, so the packaged Dispatch profile is already present when the store is
created and the store is given the profile's own timezone.

The two Advanced Queue queues needed no bootstrap code in either direction.
`ConfigInstaller::installDefaultConfig()` installs an extension's own
`config/optional`, and also installs *other* extensions' optional config that
depends on it — so enabling `advancedqueue` after the module materialised both
queue objects with no code involved. Advanced Queue and ECA stay optional by
design: neither is a declared dependency, and their objects live in
`config/optional` precisely so a site without them still installs.

One install-time requirement is worth naming because it is invisible in the module:
`block.block.hg_express_courier_home` is placed in an Olivero region, so the theme
must be present and default when the block is installed. On the standard profile it
is, which is why the clean install placed the block without intervention.

### 11.5 The clean-install proof

Method. A throwaway database whose name begins `task18b_repro_`, proven before
creation to be neither the active database nor an existing one, and a temporary
site directory under `sites/task18b_repro_*` only — the active site's settings were
never edited, and the temporary directory was named after its own hostname so Drush
resolved it without a `sites.php` change. Drupal was installed into it with the
standard profile using the code already on disk, then `custom_webform_handlers` was
enabled through the ordinary module-install path. Nothing was copied from the active
database and no broad configuration export was taken at any point.

Result: **22 assertions, 0 failures.** By name and count only —

- the module installed, and its schema version was stamped at the newest hook
  without any hook running;
- all six required objects present: `webform.webform.hg`,
  `block.block.hg_express_courier_home`, `custom_webform_handlers.dispatch`,
  `custom_webform_handlers.settings`, `views.view.dispatch_reservations`,
  `user.role.dispatch_schedule_manager`;
- the Dispatch allowlist non-empty, 1 entry;
- `webform.webform.da` absent, and referred to by 0 packaged objects;
- every table `hook_schema()` declares: 9 declared, 0 missing;
- exactly 1 Commerce store, exactly 1 flagged default, resolvable through
  `commerce_store.current_store`, on the packaged timezone, in USD, of the expected
  bundle, with at least one currency imported;
- the Dispatch role loaded with 4 permissions and not an admin role;
- both Advanced Queue objects present after the optional module was enabled.

Table growth across the run: 59 core, 105 after the module, 106 after the optional
queue module.

Cleanup. The temporary database was dropped and its temporary grant revoked, the
temporary site directory removed, and the container-private key stash destroyed,
each step guarded by an explicit name test and by a proof that the active database
name differs from the target. Afterwards: 0 databases and 0 directories matching
`task18b_repro_*` remain; the active database is present with its table count
unchanged; the active and QA site directories are intact. The active database was
never exported, copied, imported, replaced, truncated or modified.

### 11.6 The remaining condition, which is not configuration

The availability probe — a non-persistent, read-only `offerSlots()` call with
public landmark fixture coordinates on a future operating date derived from the
packaged working hours, never hardcoded — returned **0 eligible slots** on the
clean site. Everything the packaged configuration governs succeeded: the resource
profile built from packaged configuration alone, the operating date was derived
from it, and the fixture request passed eligibility with both endpoints and a weight
inside the limit. The single failure is the external route estimate.

Diagnosed, not worked around. A slot cannot exist without the pickup→delivery route
leg, and the route provider returned HTTP 403 `PERMISSION_DENIED`. Classified by
reason, the response is a referrer restriction — not a disabled service, not a
blocked IP, not an invalid key, not billing. The environment's other Google key,
which is a different value, classifies identically. Both keys available here are
browser-referrer-restricted, which the Routes API refuses for a server-side call.

The control that settles the cause: the same probe, on the fully-configured QA site
that scored a green browser gate on 2026-08-30, returns the identical 403 and the
identical 0 slots. The same configuration that produced real slots six days ago
produces none today, on a site this task never repackaged. This is an environment
credential regression, not a packaging defect, and the correct fix is a
server-authorized key supplied by the environment — never a key committed to Git.

Write discipline held throughout. On the clean site the only table that moved was
`watchdog`, by the two rows recording the route failure; `tables_added=0` and all
seven prohibited families — submission, reservation, hold, order, payment, queue
job — were unchanged. The same held on the QA control.

### 11.7 Verdict

**Configuration: REPRODUCIBLE.** A database that has never seen an update hook now
receives every required configuration object, the Dispatch schema, the Dispatch
role, the Reservation Dashboard, the Express Courier form, its homepage placement,
and exactly one Commerce store, from versioned module code alone — proven at 22
assertions with 0 failures on a throwaway database, with no active-database copy
and no broad export.

**End-to-end Courier booking: NOT YET PROVEN, for one non-configuration reason.**
The ≥1-eligible-slot proof cannot pass anywhere in this environment, including on
the site that passed it six days ago, because no Google key available here is
authorized for server-side Routes calls. The sentinel-wrapped browser gate run
against the QA site scored 78 of 112 assertions, red at both widths — 39/56 desktop
and 39/56 mobile — and every failure is downstream of zero slots and an unrefreshed
estimate. The gate wrote no submission, reservation, order, checkout attempt or
review row; the one gated probe that moved was the error-log watermark, recording
the route failure itself.

The honest form of the verdict is therefore two-part. The reproducibility blockers
this audit raised are closed and proven. Release attestation stays blocked on a
credential the repository must never contain.

### 11.8 The legacy artifact, left where it is

`webform.webform.da` is deliberately **not packaged**. It is referred to by 0
packaged objects, and the clean install asserted both its absence and the absence of
any dependency on it. It is equally deliberately **not deleted** from the active
database, where it remains a legacy artifact and nothing else. It was neither
shipped by accident nor lost by accident, which was the whole point of classifying
it before any export was written.










