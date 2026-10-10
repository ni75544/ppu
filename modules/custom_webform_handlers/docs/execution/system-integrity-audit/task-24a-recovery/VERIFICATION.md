# Task 24A recovery — verification results

All checks below were run against the corrected candidate under
`corrected/candidate/` without applying it to the live module and without any DB
write, migration, or config import. One read-only DB query was run (leg 4) because
DDEV was already up. Each leg records the exact command, the result, and what it
does and does not establish.

## 1. Patch applicability — PASS

Command (from the repo root, with an explicit `--directory` so all paths resolve):

```bash
git apply --check --verbose --directory=modules/custom_webform_handlers modules/custom_webform_handlers/docs/execution/system-integrity-audit/task-24a-recovery/task-24a-recovery-corrected.patch
```

Result: **exit 0**, with a `Checking patch …` line printed for **each of the 16
files** (this is the correction to an earlier run that omitted `--directory`: git
then could not resolve the module-relative paths and silently skipped files, so
its exit 0 did not establish applicability). `git apply --numstat` reports **457
insertions / 9 deletions across 16 files**, 3 new. Applies cleanly against
baseline `b49c259e`.

Establishes: the patch is well-formed and every file applies without conflict to
the current tree. Does **not** establish that the applied result compiles or runs.

## 2. Offline PHP lint — PASS (13/13)

Command (container, PHP 8.4.22, via `run-lint.sh`):

```bash
ddev exec bash modules/custom_webform_handlers/docs/execution/system-integrity-audit/task-24a-recovery/run-lint.sh
```

Result: **13/13 "No syntax errors detected"**, `LINT_STATUS=0`
(see `lint-results.txt`). Covers every `*.php` and the `*.install` file in the
candidate, including the three newly added forwarding files
(`DispatchCheckoutManager.php`, `DispatchPlanner.php`,
`DispatchCheckoutPlannerInterface.php`).

Establishes: syntax validity under PHP 8.4. Does **not** establish that the code
compiles in the Drupal context, that DI resolves, or that runtime behavior is
correct.

## 3. YAML validation — PASS (3/3)

Command (container, Symfony YAML parser Drupal uses, via `run-yaml.php`):

```bash
ddev exec php docs/execution/system-integrity-audit/task-24a-recovery/run-yaml.php
```

Result: **3/3 OK**, `YAML_STATUS=0` (see `yaml-results.txt`) for
`services.yml`, `config/schema/…schema.yml`, and
`config/install/…settings.yml`.

Establishes: the three YAML files are syntactically parseable. Does **not** by
itself establish Drupal typed-config **schema conformance** — that is a distinct
check, run read-only in leg 5 below. Typed-config validation does **not** require
importing configuration.

## 4. Live schema state — READ-ONLY CONFIRMATION

Because DDEV was already running, a read-only check was safe. No hook was executed,
no schema version was changed, no config was imported, and no row was written.

```bash
ddev exec vendor/bin/drush sqlq "SELECT value FROM key_value WHERE collection='system.schema' AND name='custom_webform_handlers'"
ddev exec vendor/bin/drush sqlq "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND COLUMN_NAME LIKE 'price_snapshot%'"
```

Result:

- Module schema version = **`10046`** (both `update_10045` and `update_10046`
  already applied on this environment).
- `custom_webform_handlers_dispatch_reservation` already carries
  `price_snapshot_id`, `price_snapshot_cents`, `price_snapshot_currency`, and
  `price_snapshot_version`.

**Consequence for the restoration plan:** hooks `update_10045` / `update_10046`
must **not** be re-run on this environment. The schema is already at `10046` and
the columns exist. Re-running is unnecessary; lowering the schema version,
importing config, or otherwise mutating the DB is out of scope and not proposed.
The install-file hooks remain in the candidate because they are needed for a
*fresh* install or a site still at `≤10044`; they are guarded
(`fieldExists`/`tableExists`) and therefore no-ops on this already-migrated DB.

## APPLIED — restoration executed (authorized)

The user authorized applying the 16-file patch to restore the recovered baseline.
Done from repo root; patch sha256 `1bdc542b20d6b355b92f753e1fd4cbbb645a352a7ce7955cc63f1edacc2ab324`.

Pre-apply recheck: HEAD `b49c259e`, target files unchanged, `git apply --check
--directory=modules/custom_webform_handlers` exit 0. Apply exit 0. Resulting
working tree: **13 modified + 3 new**, and every applied file is byte-identical to
`corrected/candidate/`. Containers `ddev-ppu-web` / `ddev-ppu-db` healthy (the
unhealthy `ddev-ppu-courier-*` is a separate project, out of scope). No container
start/restart/recreate/volume op was performed. Drush was run directly in the
already-running web container via `docker exec -w /var/www/html ddev-ppu-web …`.

### 5. Cache rebuild — PASS
`drush cr` → "Cache rebuild complete." (the only authorized cache-state op).

### 6. Service-container resolution — PASS
All four resolve: `routing_mode_resolver` → `RoutingModeResolver`,
`fixed_development_route_provider` → `FixedDevelopmentRouteProvider`,
`customer_route_entry_provider` → `RoutingModeEntryProvider`,
`google_routes_distance_provider` → `GoogleRoutesDistanceProvider`.

### 7. Interface / implementation compatibility — PASS
- `RoutingModeEntryProvider instanceof DistanceProviderInterface` = YES.
- `DispatchPlanner instanceof DispatchCheckoutPlannerInterface` = YES.
- `DispatchPlanner::createCheckoutHold` has **3** params
  (`DispatchRequest`, `Slot`, `QuoteSnapshot`) — the reconstructed interface
  declaration and the concrete implementation agree, and the class instantiates.
- `DispatchClaim` constructor has **14** params, including all four
  `priceSnapshotId/Cents/Currency/Version`.

### 8. Effective mode + entry-point wiring + snapshot forwarding — PASS
- `RoutingModeResolver::isDevelopmentFixed()` = TRUE, `effectiveMode()` =
  `development_fixed` (`IS_DDEV_PROJECT='true'`).
- All three customer entry points resolve the facade
  (`RoutingModeEntryProvider`): `DispatchCheckoutStarter` (injected),
  `DistancePreviewController::create()`, and the `address_distance_handler`
  webform plugin (which also has `RoutingModeResolver` injected).
- Snapshot forwarding: the checkout seam's `createCheckoutHold(…, QuoteSnapshot)`
  and the 14-arg `DispatchClaim` are wired so the four price facts thread into the
  claim.

### 9. Typed-config schema validation — PASS (read-only, no import)
`config.typed`→`createFromNameAndData('custom_webform_handlers.settings', …)`
→`validate()` returned **0 violations**. `routing_mode` is present in the schema
mapping as `type: string`; active value = `live_cached`. **No configuration was
imported** — validation ran read-only against the active config values.

## Not executed (out of scope / by policy)

- **Migration reruns / `updatedb`** — deliberately excluded; schema already at
  `10046` with all `price_snapshot_*` columns (leg 4). No schema-version change.
- **Active configuration / business-data changes** — none. `routing_mode` in the
  DB stays `live_cached`; only the *install/schema source* now carries it.
- **Routing, payment, reservation, or worker path exercise** — not run.
- **External API requests, automated test suites, browser acceptance** — not run.
- **Commits** — none; the restored files are left as uncommitted working-tree
  changes for review.

## Environment note — unexpected DDEV recreate (earlier, during preparation)

During an earlier `ddev exec` in the *preparation* phase, DDEV detected custom
compose configuration and auto-recreated the containers — DDEV's own behavior on
exec, not an explicit restart; it changed no database, schema, or Drupal config,
removed no volume, and the DB returned healthy. During *this* authorized apply
phase, no such event occurred: Drush was run via `docker exec` against the
already-running container specifically to avoid DDEV auto-start.

## 10. Graph-evidence closeout (Graphify + GitNexus)

### 10a. Graphify rebuild — recorded facts

Two invocations were observed, described here **only** as observed; no cause
beyond the observations is asserted.

- **First invocation:** `timeout 120 graphify update .` — **timed out, exit 124**
  (captured verbatim: `GRAPHIFY_EXIT=124`, task-output file recorded
  `Terminated`). The 120 s wall-clock bound elapsed before the command returned.
  This establishes only that the operation did not complete within 120 s on that
  run; it does **not** establish *why* (no process/lock evidence was gathered at
  that moment, so no watcher-lock or other cause is claimed).
- **Later invocation:** `timeout 90 graphify update .` — **completed, exit 0.**
  Captured output (`/tmp/gf_update.txt`, mtime Sep 18 14:27): AST extraction
  `88/88 uncached files`; `warning: 54 source file(s) produced zero nodes`
  (non-code files — launch.json/settings.json etc.); `backed up curated graph
  (5 files) -> 2026-09-18/`; `[graphify watch] Rebuilt: 6972 nodes, 15302 edges,
  537 communities`; `graph.json, graph.html and GRAPH_REPORT.md updated in
  graphify-out`.

  The two facts are simply that the first run exceeded its bound and a later run
  finished; the later run had a *shorter* timeout yet succeeded, so the timeout
  value was not the operative difference. Beyond that, cause is not established.
  The successful run was **not repeated** — its logs and artifacts are reused as
  the evidence below.

- **Generated artifacts** (from the completed run, mtime Sep 18 14:27):
  - `graphify-out/graph.json` — 12,203,793 bytes
  - `graphify-out/graph.html` — 675,326 bytes
  - `graphify-out/GRAPH_REPORT.md` — 95,252 bytes
  - `graphify-out/2026-09-18/` — pre-rebuild backup of the prior curated graph (5 files)

### 10b. Restored symbols present in the rebuilt graph — PASS

`graphify explain "<symbol>"` against the rebuilt graph resolves every restored
symbol at its **current live source path** (the doc-mirror copies under
`task-24a-recovery/{candidate,corrected,original-extraction}/…` are separate
nodes and were filtered out):

| Symbol | Live node location in rebuilt graph |
| --- | --- |
| `RoutingModeResolver` | `modules/custom_webform_handlers/src/Distance/RoutingModeResolver.php` |
| `FixedDevelopmentRouteProvider` | `modules/custom_webform_handlers/src/Distance/FixedDevelopmentRouteProvider.php` |
| `RoutingModeEntryProvider` | `modules/custom_webform_handlers/src/Distance/RoutingModeEntryProvider.php` |
| `createCheckoutHold` (forwarding seam) | `…/src/Dispatch/DispatchCheckoutPlannerInterface.php` **and** `…/src/Dispatch/DispatchPlanner.php` |
| `DispatchCheckoutPlannerInterface` | reached via EXTRACTED `implements` edge: `DispatchPlanner … [implements] … DispatchPlanner.php:L56` |

Establishes: the AST graph now indexes the restored classes and the restored
3-arg `createCheckoutHold` seam at the paths they occupy in the working tree.
Does **not** establish YAML/service wiring (graphify is AST-only; that remains a
documented coverage limit, confirmed by direct file inspection — see
`PROVENANCE.md`).

### 10c. GitNexus — blocker resolved, staleness noted

- **Tool/command:** `mcp__gitnexus__list_repos` and `mcp__gitnexus__context`
  (GitNexus MCP), plus `node .gitnexus/run.cjs analyze --help` / `status`.
- **Actual response — now:** the MCP is **responsive** (the earlier phase's
  unresponsiveness did not recur). `list_repos` returns the `ppu` index with
  `indexedAt: 2026-09-14T05:01:36Z`, `lastCommit: b49c259e`, 6,051 nodes /
  15,723 edges, `embeddings: 0`.
- **Staleness assessment:** the index timestamp predates the Sept 18 restoration
  and records the baseline commit. **However**, `context` queries confirm the
  restored symbols are already present in it at their live paths —
  `FixedDevelopmentRouteProvider` (L40–118), `RoutingModeEntryProvider`
  (L23–52), and `createCheckoutHold#3` at **both** `DispatchPlanner.php:234`
  and `DispatchCheckoutPlannerInterface.php:40`. This is because the Sept 14
  index was built from the working tree that still held the 21A/22D/forwarding
  work (the same tree the audit inspected), and the restoration re-created that
  tree. So the index is usable for these symbols despite its baseline commit tag.
- **Why a decision was required (and its resolution):** a *value*-level refresh
  of the index would need `analyze`, which by default rewrites the `gitnexus`
  section of `CLAUDE.md`/`AGENTS.md` and installs skills — prohibited here. The
  installed CLI **does** provide a safe path: `--index-only` ("Pure index mode:
  skip all file injection (AGENTS.md, CLAUDE.md, skills)"), or the narrower
  `--skip-agents-md` / `--skip-skills`. Because the restored symbols already
  resolve in the current index, **no reindex was run** — the smallest safe action
  is recorded here for when a value-level refresh is genuinely needed:

  ```bash
  node .gitnexus/run.cjs analyze --index-only .
  ```

  (`--index-only` is what keeps the project instruction files untouched. Absent
  that flag, `analyze` must not be run in this repo.) `node run.cjs status`
  hung with no output within 120 s and was stopped; that diagnostic is noted but
  not relied upon, since the MCP `list_repos`/`context` calls succeeded.

## 11. Structural vs behavioral evidence — explicit boundary

Recorded so no reader mistakes structural agreement for behavioral proof:

- **Structural (established):** constructor signatures and DI wiring are
  compatible — `DispatchPlanner instanceof DispatchCheckoutPlannerInterface`,
  the 3-param `createCheckoutHold(DispatchRequest, Slot, QuoteSnapshot)` matches
  between interface and implementation, the 14-param `DispatchClaim` constructor
  carries all four `priceSnapshot*` fields, all four routing services resolve,
  and `isDevelopmentFixed()` = TRUE. (Legs 5–9 + §10.)
- **Source inspection (established):** the checkout seam *forwards its arguments*
  — `DispatchCheckoutManager::start()` passes `$quote` into
  `createCheckoutHold(...)`, and `DispatchPlanner` decomposes it into the four
  price scalars threaded into `new DispatchClaim(...)`. This is established by
  reading the code, not by running it.
- **NOT established (behavioral — remains PENDING under its own tasks):** none of
  the above proves a checkout actually **executes** end-to-end, that a row is
  **persisted** with a populated `price_snapshot_version`, that payment
  **recovery** works, or that **zero outbound requests** occur in
  `development_fixed`. Those are behavioral checks and are deliberately **not**
  run here. They belong to their respective tasks (checkout/persistence/payment
  under Task 24-series behavioral verification; the zero-outbound-request
  guarantee under Task 24H's system-wide zero-cost verification). This recovery
  does **not** expand into payment or reservation testing.

## Retained tooling

`run-lint.sh` and `run-yaml.php` are retained in this directory as the exact
verification harness used above, so the three green legs can be reproduced. They
read only from `corrected/candidate/` and make no changes.
