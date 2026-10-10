# Task 24A — Deployment and schema readiness: evidence

Static readiness review: 2026-09-15. Application and verification: 2026-09-16.
Repository: C:/Users/mo737/ppu. Branch: codex-experiments. Source baseline
refreshed against the current dirty working tree (audit baseline was b49c259e on
2026-09-14).

Closes **E01** (schema 10044 lacked `price_snapshot_version` while store code
references it) and records **E02** (dispatch checkout flow active with
`capture: false` and a custom pane, but no
`commerce_payment.commerce_payment_gateway.*` configuration).

> **Acceptance status: ACCEPTED for Task 24A.**
> The approved migration ({10045, 10046}) was applied to the isolated DDEV
> database after a verified reversible snapshot, and every critical check passed
> live: schema advanced to 10046, `price_snapshot_version` exists, the six
> reservation indexes are intact, the routing guard remains `development_fixed`
> before and after, the typed-config schema resolves, and a second update run is
> a no-op. E02's gateway prerequisite is recorded and remains a **24E** gate, not
> a 24A blocker (24A's charge was only to record it, install nothing, charge
> nothing).

## Evidence systems used and coverage limits

- **Graphify** (query-first, AST-only graph): oriented on `DatabaseReservationStore`,
  `DispatchCheckoutManager`, and the claim/detail value objects. The graph reflects
  committed state only; the uncommitted `price_snapshot_version` additions do not
  appear as edges, so the working-tree diffs are authoritative for them. YAML and
  Markdown are outside graph coverage — service wiring, the checkout flow, and
  config schema were confirmed by reading the YAML directly.
- **GitNexus**: architecture/impact orientation (per audit: 6051 nodes / 15723
  edges on the committed tree; reindex not re-run this session because no source
  was changed).
- **Direct source / diff inspection** (sanctioned fallback for changed files and
  YAML): the authoritative evidence for every uncommitted change cited below.
- **Context7**: Drupal Commerce payment-gateway integration model
  (authorize-then-capture, `SupportsAuthorizationsInterface`) — corroborates the
  gateway-agnostic capture path.
- **Live Drush inspection** (read-only, in-container via
  `docker exec ddev-ppu-web … vendor/bin/drush`): schema version, column/index
  existence, typed-config schema, runtime signatures, active checkout config, and
  gateway/store presence — all quoted below as live-verified on 2026-09-16.

No secrets, credentials, customer rows, addresses, tracking codes, gateway
settings, or store contents are reproduced in this document — only schema
metadata, config keys/steps, entity IDs and counts.

## Step 1 — Working-tree changes and required schema-to-code mappings

### E01 reproduced live, then closed

Before the migration, live `system.schema` for `custom_webform_handlers` =
**10044** and column `price_snapshot_version` on
`custom_webform_handlers_dispatch_reservation` was **absent** (verified live
2026-09-16, not merely audit-sourced). The current store references the column in
five places, so any INSERT that names it and any SELECT built on
`RESERVATION_FIELDS` would error with "Unknown column" against schema 10044:

| # | Site | File:line (working tree) | Reference |
| --- | --- | --- | --- |
| 1 | `RESERVATION_FIELDS` constant | `src/Dispatch/Storage/DatabaseReservationStore.php:116` | `'price_snapshot_version',` |
| 2 | claim / claimLocked INSERT | `…/DatabaseReservationStore.php:236` | `'price_snapshot_version' => $claim->priceSnapshotVersion,` |
| 3 | replan INSERT (verbatim copy) | `…/DatabaseReservationStore.php:679` | `'price_snapshot_version' => $current->price_snapshot_version,` |
| 4 | adminReservation SELECT list | `…/DatabaseReservationStore.php:1004` | `'price_snapshot_version',` |
| 5 | adminReservation hydration | `…/DatabaseReservationStore.php:1041` | `$row->price_snapshot_version === NULL ? NULL : (string) $row->price_snapshot_version,` |

Because `RESERVATION_FIELDS` (site 1) backs the claim/confirm/replan read paths,
the break was not limited to the admin read — it was any reservation read or write
on an un-migrated updated install. After the migration (below) the column exists,
so all five sites resolve.

### Read/write endpoints and type agreement (verified at runtime)

The column and both PHP endpoints are nullable strings — types agree end to end,
and NULL is representable, so historical rows keep NULL. Runtime reflection
confirmed both signatures live:

- Column: `custom_webform_handlers.install:120` — `price_snapshot_version` =
  `varchar_ascii`, length 64, `not null => FALSE`.
- Write endpoint (claim): `src/Dispatch/DispatchClaim.php` — promoted constructor
  property `public ?string $priceSnapshotVersion = NULL`. Runtime type: `?string`.
- Read endpoint (admin): `src/Dispatch/DispatchReservationDetail.php` — promoted
  constructor property `public readonly ?string $priceSnapshotVersion`. Runtime
  type: `?string`.

### Schema-to-code mapping (why fresh installs differ from updated installs)

- `custom_webform_handlers_schema()` **defines** the column at `install:120`, so a
  **fresh install** stamps the current schema version and already has the column
  (Drupal skips update hooks on fresh install). The store code is correct there.
- An **updated install at 10044** lacked the column until hook **10046** ran. That
  was the only population where the store code was broken — exactly this
  environment, now migrated to 10046.

## Step 2 — DDEV / Docker health and lifecycle (reported transparently)

- At the start of this session the Docker daemon was reachable and the project
  was up, but `ddev-ppu-web` was still reporting `health: starting`
  (mailpit/phpstatus warming). Drush CLI bootstrap was nevertheless confirmed
  working in-container: `drush status` → Drupal 10.6.16, DB Connected, bootstrap
  Successful, PHP 8.4.
- **Lifecycle note — the snapshot triggered a serial project bring-up.** Taking
  the reversible backup with `ddev snapshot --name=pre-24a-migration` caused DDEV
  to judge the project "unhealthy" (web still warming) and, by its own design,
  **start/recreate the project to a healthy state before snapshotting**. This was
  a single, serial, foreground command — not the parallel auto-start pattern that
  produced the audit's container-name conflicts. Containers were **recreated**
  (new instances), but Docker named volumes — including the MariaDB data volume —
  **persist across recreation**; **no volume was deleted**. The DB state was
  re-verified byte-for-byte after the recreate (schema 10044, column absent,
  routing_mode NULL — identical to before), so the snapshot's restore point
  equals the true pre-migration state. This stayed within the granted authority
  ("bring the environment up serially … a single `ddev start`, no volume
  deletion, no parallel auto-start") and crossed none of the red lines.
- After the recreate the project is fully healthy: DDEV reported
  `Waiting for containers to become ready: [web db]... ready in 7.1s`, and every
  subsequent Drush command succeeded. **DDEV web health and CLI bootstrap both
  passed.**

## Step 3 — Effective development routing guard (proven at runtime)

Guard precedence, from `src/Distance/RoutingModeResolver.php:72-84`
(`effectiveMode()`):

1. `IS_DDEV_PROJECT === 'true'` → `development_fixed` **unconditionally**, returned
   at `RoutingModeResolver.php:73` **before configuration is read**.
2. Otherwise `custom_webform_handlers.settings:routing_mode` selects the mode,
   defaulting to `live_cached`; a `settings.php` config override can pin it on a
   non-DDEV server.

**Runtime proof (in-container `drush php:script`, no provider invoked — the
resolver reads only `getenv('IS_DDEV_PROJECT')` and config):**

| Fact | Before migration | After migration |
| --- | --- | --- |
| `IS_DDEV_PROJECT` | `'true'` | `'true'` |
| `effectiveMode()` | `development_fixed` | `development_fixed` |
| `isDevelopmentFixed()` | `true` | `true` |
| `config routing_mode` | `NULL` | `'live_cached'` |

The decisive evidence: **before** the migration the guard returned
`development_fixed` while config was `NULL`, and **after** hook 10045 set the
config default to `live_cached` the guard **still** returned `development_fixed`.
The environment branch short-circuits first, so 10045's config default cannot and
did not change the effective mode in DDEV. This is not a routing-mode activation.
The wiring is fail-closed: `custom_webform_handlers.services.yml` injects the
resolver into `customer_route_entry_provider` **and** into both Google adapters,
so even a caller wired directly to an adapter is refused in `development_fixed`.

## Step 4 — Pending update hooks and configuration effects (reviewed, then applied)

The module's pending hooks were **exactly** {10045, 10046} — the install file ends
at 10046 (`custom_webform_handlers.install:4386`); nothing follows.
`drush updatedb:status`, run before applying (and again after the recreate),
confirmed these two `custom_webform_handlers` hooks were the **only** pending
updates — **no core or contrib update** was pending, satisfying the "no unrelated
updates" red line.

### Hook 10045 — routing default (`install:4350`)

```
if ($config->get('routing_mode') !== NULL) { return; }   // never overwrites an override
$config->set('routing_mode', 'live_cached')->save();
```

Idempotent; sets the config default only when unset. Because active
`routing_mode` was `NULL`, the hook did real work and returned
"Set the default customer routing mode to live_cached." Per step 3 this does
**not** activate live routing in DDEV (the environment forces `development_fixed`).
Not a routing-mode activation; does not touch accepted 21A behavior.

### Hook 10046 — pricing-policy version column (`install:4370`)

Guards on `tableExists` and `!fieldExists` (idempotent — a second run is a no-op),
then `addField()` using the `hook_schema()` definition at `install:120`
(nullable `varchar_ascii(64)`, no default). Existing rows receive NULL — **no
fabricated prices or versions**; historical NULLs preserved. The hook returned
"Added the pricing-policy version to Dispatch Reservation price snapshots."

### Configuration diffs (all within `modules/custom_webform_handlers/`)

- `config/install/custom_webform_handlers.settings.yml` (+7): adds
  `routing_mode: live_cached` with a comment documenting the DDEV code-force. No
  key/secret.
- `config/schema/custom_webform_handlers.schema.yml` (+3): adds the `routing_mode`
  typed-config string entry (label only). Verified effective post-migration
  (typed datatype resolves to `string`).
- `custom_webform_handlers.services.yml` (+32/-1): adds
  `routing_mode_resolver`, `fixed_development_route_provider`,
  `customer_route_entry_provider`; rewires `DispatchCheckoutStarter` from
  `google_routes_distance_provider` to `customer_route_entry_provider`; injects the
  resolver into both Google adapters. No infrastructure/provider is enabled.

### Reversible backup taken (step 4.2)

`ddev snapshot --name=pre-24a-migration` → exit 0,
"Created database snapshot pre-24a-migration". Restorable with
`ddev snapshot restore pre-24a-migration`. Captured state: schema 10044, column
absent, routing_mode NULL (re-verified after the recreate, above). DB credentials
were never printed (the DDEV-native snapshot handles them internally).

## Step 5 — Applied the approved sequence (actual results)

Because `updatedb:status` showed {10045, 10046} as the sole pending updates,
`drush updatedb -y` applied exactly those two:

```
[notice] Update started: custom_webform_handlers_update_10045
[notice] Set the default customer routing mode to live_cached.
[notice] Update completed: custom_webform_handlers_update_10045
[notice] Update started: custom_webform_handlers_update_10046
[notice] Added the pricing-policy version to Dispatch Reservation price snapshots.
[notice] Update completed: custom_webform_handlers_update_10046
[success] Finished performing updates.
```

No historical hook was rewritten; no corrective migration was needed (so no new
hook number was consumed). The mandated cache rebuild followed:
`drush cr` → "Cache rebuild complete."

## Step 6 — Post-migration confirmation (actual results)

Live in-container verification after `drush cr`:

| Check | Result | Verdict |
| --- | --- | --- |
| Installed schema version | `10046` | recorded truthfully |
| `price_snapshot_version` column exists | `true` | E01 closed |
| Reservation indexes | `PRIMARY, dispatch_overlap, dispatch_expiry, dispatch_token, dispatch_submission, dispatch_webform, dispatch_order` | all intact; new column not indexed, no key added |
| `routing_mode` typed-config datatype | `string` | typed schema effective (was UNRESOLVED pre-apply because the key was absent) |
| `effectiveMode()` / `isDevelopmentFixed()` | `development_fixed` / `true` | guard unchanged before and after |
| `DispatchClaim::$priceSnapshotVersion` | `?string` | matches column |
| `DispatchReservationDetail::$priceSnapshotVersion` | `?string` | matches column |
| Second update run | `updatedb:status` → "No database updates required." | no-op / idempotent |

Checkout pane active configuration (live
`commerce_checkout.commerce_checkout_flow.dispatch`):

| Pane / key | Active value |
| --- | --- |
| flow exists | `true` |
| `dispatch_payment_process.step` | `payment` |
| `dispatch_payment_process.capture` | `false` |
| `payment_process.step` | `_disabled` |
| `payment_information.step` | `order_information` |

The active config matches the shipped file: the custom `dispatch_payment_process`
pane (capture: false) is on the payment step, stock `payment_process` is disabled,
and card collection stays on the details step.

## Step 7 — Missing gateway / store prerequisites (recorded live; nothing installed)

E02 confirmed live, without installing, enabling, or charging anything:

- **Payment gateways: `payment_gateway_count = 0`** (no
  `commerce_payment.commerce_payment_gateway.*` entity IDs returned). The module
  ships no gateway config; `config/install/` (17 files) provides the checkout
  flow, order type, order-item type, and dispatch contact fields only.
- **Store: `store_count = 1`, `has_default_store = true`.** A default Commerce
  store exists live (created imperatively by `CommerceStoreInstaller` in
  `hook_install`, not shipped as config). So the store prerequisite is already
  satisfied; only the gateway is missing. (This refines the earlier
  DB-unreachable note that left store presence unknown.)
- The shipped flow **requires** a configured payment gateway to authorize a card,
  which is absent. The capture path is **gateway-agnostic**:
  `DispatchCheckoutManager::captureAuthorization()`
  (`src/Commerce/DispatchCheckoutManager.php:545`) resolves the gateway from the
  payment entity (`$payment->getPaymentGateway()?->getPlugin()`) and gates on
  `SupportsAuthorizationsInterface` + `canCapturePayment()`. No gateway ID is
  hardcoded, so any supported gateway (or an authorized local fake, in an isolated
  DDEV DB) satisfies the code once configured.

**Prerequisite for payment-path acceptance (deferred to 24E, not this task):** a
supported `commerce_payment_gateway` config entity (or an authorized local test
gateway in the isolated DDEV database). The default store requirement is met. This
task records the gap only; it installs nothing and charges nothing.

## Changed files relevant to 24A (all pre-existing working-tree changes; preserved)

- `custom_webform_handlers.install` (adds hooks 10045, 10046)
- `config/install/custom_webform_handlers.settings.yml`
- `config/schema/custom_webform_handlers.schema.yml`
- `custom_webform_handlers.services.yml`
- `src/Dispatch/Storage/DatabaseReservationStore.php`
- `src/Dispatch/DispatchClaim.php`
- `src/Dispatch/DispatchReservationDetail.php`
- `src/Distance/RoutingModeResolver.php` (new; inspect-only, accepted 21A boundary)

**No file was edited by Task 24A.** The migration was a runtime DB/config
operation only. `git status` for the module is identical to the session-start
snapshot (13 modified tracked files, 6 untracked entries). No commit, stash,
reset, or discard was performed; unrelated uncommitted changes are preserved.

## Verification commands run this session and actual results

- `docker exec … drush status` → Drupal 10.6.16, DB Connected, bootstrap
  Successful, PHP 8.4.
- `docker exec … drush updatedb:status` (before, and again after the recreate) →
  only `custom_webform_handlers` 10045 and 10046 pending; no core/contrib.
- `drush php:script` read-only probe (before) → schema 10044, column absent,
  6 indexes + PRIMARY, `development_fixed`, config NULL, endpoints `?string`.
- `ddev snapshot --name=pre-24a-migration` → exit 0; restore point recorded.
- `drush updatedb -y` → applied 10045 and 10046 with the return messages quoted in
  step 5; "Finished performing updates."
- `drush cr` → "Cache rebuild complete."
- `drush php:script` read-only probe (after) → schema 10046, column present,
  6 indexes + PRIMARY unchanged, `development_fixed`, config `live_cached`, typed
  datatype `string`, endpoints `?string`.
- `drush updatedb:status` (after) → "No database updates required."
- Checkout-pane + gateway/store probe → values in steps 6 and 7.
- `git diff --check -- modules/custom_webform_handlers/` → no whitespace errors.
- `git status --short -- modules/custom_webform_handlers/` → unchanged set.
- Temporary in-container probes (`/tmp/probe_24a*.php`) removed after use.
- No `config import`, no broad `updatedb` beyond the two approved hooks, no
  PHPUnit/Kernel/Functional, no browser automation, no paid request, no payment
  experiment, no volume deletion.

## Final invariants (realized)

- **Now (schema 10046):** `price_snapshot_version` exists (nullable); all five
  store sites resolve; historical rows keep NULL; a repeat update run is a no-op;
  fresh installs remain correct (column defined in `hook_schema()`).
- The customer routing default is `live_cached`, while DDEV still resolves
  `development_fixed` — proven before and after — so outbound paid routing calls
  remain zero. No provider or payment path was executed.
- No payment gateway is configured; a default store exists. Payment-path
  acceptance stays blocked pending a gateway (**24E**).

## Remaining blockers

1. **Payment gateway prerequisite (E02) — a 24E gate, not a 24A blocker.** No
   `commerce_payment_gateway` config entity exists. 24A's charge was to record
   this, which it does; installing/enabling a gateway or charging is explicitly
   out of scope here.

Distinctions per RULES: E01 was a **reproduced defect** (schema 10044 lacked the
column live) and is now a **verified fix** (column present at 10046, all checks
green). E02 is a **source-traced + live-confirmed gap** (zero gateways), recorded
for 24E. Manual browser acceptance: **not performed** (not requested; this is a
schema/deployment-readiness task with no browser-observable surface). No critical
24A check remains pending, so Task 24A is fully accepted on its own scope.
