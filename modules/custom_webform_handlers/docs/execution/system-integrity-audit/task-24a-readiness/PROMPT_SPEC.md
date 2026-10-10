# Task 24A — Establish deployment and schema readiness

You are Claude Code CLI in C:/Users/mo737/ppu. Execute this task only. Read ../RULES.md and ../README.md before acting.

## Objective and current defect

Close E01/E02 and establish a safe baseline before application verification. The inspected database is at module schema 10044 and lacks price_snapshot_version, while current store code references it. Update 10046 exists. Update 10045 also exists and defaults routing_mode to live_cached when absent. No Commerce payment gateway configuration was found.

The newer manager/planner snapshot wiring is already present; preserve it.

## Files and authorities

- custom_webform_handlers.install
- config/install/custom_webform_handlers.settings.yml
- config/schema/custom_webform_handlers.schema.yml
- custom_webform_handlers.services.yml
- src/Dispatch/Storage/DatabaseReservationStore.php
- src/Distance/RoutingModeResolver.php — inspect only; accepted 21A boundary
- docs/execution/system-integrity-audit/task-24a-readiness/EVIDENCE.md
- Read-only: active system.schema, required columns/indexes, Commerce checkout flow, order types/store, gateway presence and DDEV service health.

## Execution

1. Inspect actual working-tree changes and live schema. Record update status and required schema-to-code mappings without printing secrets or customer rows.
2. Check whether DDEV is already running before invoking Drush. If lifecycle repair is necessary, report the exact action and obtain separate authority; do not rerun parallel auto-start commands.
3. Verify the effective development routing guard from inside the intended PHP runtime without invoking a provider. Verify environment/config override precedence, not just shipped YAML.
4. Review all pending update hooks and configuration effects. Prepare a reversible local backup and a targeted deployment sequence. Applying reviewed module updates is within this implementation task only once the local database and backup are identified; stop if unrelated updates or infrastructure changes would be included.
5. Apply only the approved sequence. Avoid rewriting applied historical hooks; use the next available hook for any newly discovered corrective migration. Preserve historical NULL snapshot values.
6. Confirm field/index existence and typed configuration schema after the migration. Inspect runtime class/interface signatures and the checkout pane's active configuration.
7. Record missing gateway/store prerequisites without installing or enabling a gateway or charging anything.

## Verification and completion

- Required fields match current reads/writes; installed schema version is recorded truthfully.
- Second migration execution is a no-op; legacy rows are not assigned fabricated prices or versions.
- Guard mode remains development-fixed before and after updates; outbound paid calls remain zero.
- Run relevant PHP/YAML checks, required Drush cache rebuild after changes, and scoped diff checks.
- State whether DDEV web health and CLI bootstrap actually passed. Payment acceptance remains blocked until a supported gateway or authorized local fake is available.

## Red lines

No broad updatedb/config import, volume deletion, secret output, infrastructure repair, routing-mode activation or edits to accepted fixture behavior. Schema readiness is not permission to start another task.
