# Task 26A-R1 — Correct the Runtime Execution Plan Before Approval

Repository: `C:\Users\mo737\ppu`. Prepared: 2026-10-08 (America/New_York).

This file is the governing instruction for **Task 26A-R1**. It is a bounded read-only inspection
plus documentation correction. Task 26A's evidence is retained; only its **proposed execution and
recovery sequence** is corrected.

Unchanged by this task: **Task 25A verdict B**, **Tasks 25B–25D BLOCKED**, and **Task 26B
BLOCKED / NOT AUTHORIZED FOR EXECUTION**.

## Authority and boundaries

Authorized: bounded read-only inspection, and edits to the four documentation files listed under
*Deliverables*.

Preserved unchanged (inspection only): all application source, Drupal configuration, databases,
scheduling, driver locks, Commerce behaviour, historical records, and the `development_fixed`
safeguards.

Not authorized — and not performed: migrations, `drush cr`, update-hook execution, installations,
container lifecycle changes, backup restoration, fixtures, payments, external routing calls,
automated suites, commits, or memory edits. Dirty work is preserved.

## What was wrong, and what this task corrects

Task 26A produced sound baseline evidence (the 15-vs-16 compiled-container mismatch, the schema
matrix, the per-hook inventory). Its **gated execution plan** contained six safety gaps:

| # | Gap in Task 26A | Correction required |
| --- | --- | --- |
| 1 | `updatedb --no-post-updates` proposed | The option is **not defined** in the installed Drush. Remove it. |
| 2 | Checkpoint "updates complete, old container retained until the subsequent `cr`" | `updatedb` **clears caches by default**, which invalidates the compiled container. The checkpoint does not exist. |
| 3 | Updater described as three isolated DDL operations | Trace the real lifecycle: bootstrap under the **update kernel**, requirements check, confirmations, schema-version bookkeeping, hook batch, post-updates, maintenance-mode changes, cache handling. |
| 4 | B3 (transaction atomicity) and B4 (backup/restore) resolved together by one rehearsal | Separate them. A successful restore does not prove atomicity. |
| 5 | Isolation inferred from a database clone, `--uri`, and one Redis key | Require an explicit target manifest and verify every shared component's boundary. |
| 6 | Automatic recovery by dropping new columns/tables or restoring an old compiled container; `/tmp/cols.php` referenced with no reviewed contents | Remove the automatic-reversal claims; make every diagnostic self-contained and inventory the scratch artefacts. |

## Required evidence discipline

- Pin every lifecycle conclusion to the **installed** source, at file and line.
- Mark behaviour that was not exercised **REQUIRES_VERIFICATION**. Do not execute the proposal.
- Do not substitute another flag for `--no-post-updates` without establishing its complete effects.
- Identify the unhealthy web container's failed check with bounded, redacted evidence; otherwise
  leave its effect `REQUIRES_VERIFICATION`. Do not repair infrastructure, and do not assume a
  read-only healthcheck inspection requires a restart.
- Treat unexplained drift as a stop condition, not as automatic proof this task caused it.
- Do not print scratch-file contents, and do not describe scratch-file creation as
  "no filesystem change".

## Evidence sources used

| Source | Path | Used for |
| --- | --- | --- |
| Installed Drush command | `vendor/drush/drush/src/Commands/core/UpdateDBCommands.php` | Option set, confirmations, batch, post-updates, maintenance mode, cache flush |
| Drush update kernel | `vendor/drush/drush/src/Drupal/UpdateKernel.php` | Thin subclass; behaviour is core's |
| Core update kernel | `core/lib/Drupal/Core/Update/UpdateKernel.php` | Forced in-process rebuild; `cacheDrupalContainer()` returns `FALSE` |
| Core kernel | `core/lib/Drupal/Core/DrupalKernel.php` | `invalidateContainer()`, `rebuildContainer()`, `getContainerCacheKey()` |
| Core cache flush | `core/includes/common.inc` | `drupal_flush_all_caches()` bin deletion and container invalidation |
| Drush bootstrap | `vendor/drush/drush/src/Boot/DrupalBoot8.php`, `src/Boot/Kernels.php` | Kernel selection, `prod` environment, dumping disabled for the update kernel |
| Option negation | `vendor/consolidation/annotated-command/src/Parser/CommandInfo.php` | Implicit `--no-<option>` generation for default-`true` booleans |
| Redis cache backend | `modules/contrib/redis/src/Cache/CacheBase.php` | `deleteAll()` is a timestamp marker, not key deletion; key prefixing |
| Module hooks | `modules/custom_webform_handlers/custom_webform_handlers.install` | Per-hook preflight / DDL / partial completion / retry |
| Transaction owner | `modules/custom_webform_handlers/src/Commerce/DispatchCheckoutManager.php` | The 16th argument `@database` is the transaction owner |
| Prior records | `commerce-webform-bridge/task-25a-bridge-compatibility/DECISION.md`, `system-integrity-audit/task-24c-booking-admission/INTEGRATION-24C-4.md` | Atomicity PENDING / failed-rollback disposal BLOCKED |
| Live, read-only | `docker inspect ddev-ppu-web`; container `/healthcheck.sh`; container `/tmp` listing | Failed healthchecks; scratch-artefact inventory |

The live docroot is the repository root (`.ddev/config.yaml` → `docroot: .`), so root `core/` at
version **10.6.16** is authoritative. The parallel `web/core/` tree is **10.6.15** and is not the
live core; the two `UpdateKernel.php` copies are byte-identical, so no conclusion here depends on
which was read. Installed Drush is **13.6.2** (`composer.lock`).

Graphify was queried first per the repository rule. It did not establish any conclusion in this
task: the graph excludes YAML and the `vendor/` tree, and is stale relative to the working tree.
Every lifecycle statement is **read from source**, not graph-established. GitNexus, Context7, and
DBHub were not required; DBHub remains **suspended**, and bounded read-only inspection through the
already-running container (`docker exec ddev-ppu-web`, never `ddev exec`) was the fallback.

## Deliverables

Under `modules/custom_webform_handlers/docs/execution/`:

- `runtime-readiness/task-26a-readiness/TASK-26A-R1-PROMPT_SPEC.md` — this file.
- `runtime-readiness/task-26a-readiness/READINESS.md` — inline corrections plus a dated
  correction record (§10). Original evidence preserved.
- `runtime-readiness/task-26a-readiness/NEXT_EXECUTION_PROMPT.md` — corrected blockers, lifecycle,
  diagnostics, approvals, success criteria, stop conditions, and recovery, plus a dated correction
  record (§10). Still **NOT AUTHORIZED FOR EXECUTION**.
- `commerce-webform-bridge/README.md` — status/navigation entry only.

## Verification

Validate documentation links and whitespace, including untracked files (ordinary `git diff` does
not cover them), and run `git diff --check`. No cache rebuild or graph refresh is needed for
Markdown-only changes.

## Stop

Stop after the documentation corrections. Do **not** execute Task 26B or any proposed repair.
