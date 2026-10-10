# Task 25D - Explicitly approved controlled rollout

You are Claude Code CLI. Read [shared rules](../RULES.md), the Task 25A decision,
Task 25B setup evidence, and Task 25C acceptance evidence completely.

## Objective and hard entry gate

Deploy only the accepted contrib/configuration bridge to the exact approved
target while preserving historical records, in-flight checkout, scheduling,
driver locks, pricing, and existing Commerce payment/workflow implementation.

**Initial status: BLOCKED. This document does not authorize deployment.**

Require all of:

1. Accepted 25C evidence with no critical pending browser, payment, isolation,
   concurrency, retry, or historical-integrity scenario.
2. Explicit approval naming target environment/site/database, final package and
   configuration artifacts, permitted paths, exact deployment sequence, pending
   hook/schema effects, traffic/writer handling, observation window, and recovery.
3. A proven recovery procedure that preserves legitimate writes after cutover.
4. An approved plan for existing sessions, pending attempts, Holds, Reservations,
   workers, and gateway callbacks. No bulk conversion or cancellation.

If any gate is missing, write only the blocker/approval checklist. Do not stage
dependencies, enable a module, alter traffic, change configuration, or run updates.

## Target files and data

Apply only the previously approved and accepted dependency/configuration manifest
using the approved deployment mechanism. Runtime source for custom scheduling,
locks, quote logic, and existing Commerce implementations remains unchanged.
No new modules of our own, PHP/JS glue, patches, custom schemas, or refactoring.

Root Composer/deployment paths need exact approved policy exceptions. Database
changes are limited to reviewed supported contrib installation effects approved
for this target. Existing schema reconciliation is a separate task, not bundled.

Documentation outputs:

- `task-25d-controlled-rollout/ROLLOUT.md`.
- `README.md`, status/evidence link only.

No cleanup, legacy-code deletion, expired-attempt repair, abandoned-cart conversion,
submission purge, price rewrite, or historical-reference change is included.

## Step 1 - Revalidate the accepted artifacts and target

Read policies/skills, inspect Git status, and compare artifact versions, hashes,
config manifest, and protected source with the accepted QA baseline. Resolve
environment-specific values explicitly; do not import QA secrets or fixture IDs.

Verify target identity, package compatibility, complete pending-update inventory,
health, routes, gateway mode, queue/cron behavior, and legacy relationships through
approved safe checks. Historical audit schema values are not a deployment manifest.

Stop if production/default readiness differs from the accepted prerequisites or
an unexpected package/hook/config change is pending. Do not apply blanket `updb`
or configuration import, invoke hooks out of sequence, or repair infrastructure.

## Step 2 - Establish cutover and recovery readiness

Create and verify the approved coherent backup/checkpoint of code/lockfile,
configuration, database, and uploads as applicable, stored privately. Reconfirm
the restoration rehearsal from isolated QA applies to these exact artifacts.

Inventory current in-flight orders, attempts, Holds, active sessions, and pending
gateway/queue work without exposing customer data. Define which writer paths may
continue, which must drain, and how the single order writer changes safely.

Use only approved traffic/maintenance controls. A new-request pause alone does
not stop cron, workers, or payment callbacks. Do not invent flags, pause unrelated
services, globally kill connections, or cancel customer work to simplify cutover.

Document recovery for both:

- Before any new transactional writes: exact restoration checkpoint and limits.
- After new orders/payment/Reservations exist: preserve those writes and use the
  approved compatible rollback or forward-recovery/reconciliation procedure.

Never plan to restore an old whole database over legitimate newer bookings. If
the proposed recovery cannot preserve them, rollout is blocked. No claim of a
single atomic transaction across Composer, filesystem, configuration, DDL, and
remote payment operations is permitted.

## Step 3 - Apply the approved deployment in bounded checkpoints

Execute the exact reviewed commands serially against the explicit target. Use
the accepted lockfile/artifacts, not an unbounded dependency solver update.
Inspect actual package, hook, schema, and configuration effects at each checkpoint.

Enable only the reviewed contrib/dependencies and exact bridge mappings. Use the
approved configuration transition so there is never a live interval with two
order creators. Preserve required old-handler duties, CheckoutAttempts, private
Hold receipts, payment prerequisites, and scheduling/driver guards.

After authorized Drupal configuration changes, run the required explicit-site
DDEV cache rebuild when the approved target is DDEV and healthy. A non-DDEV
deployment needs its own approved command policy; this prompt grants no remote
host access or platform exception. Do not start/recreate containers incidentally.

Stop at the first unexpected effect. Use only the approved recovery path; do not
improvise destructive restores, dependency removals, or customer-order edits.

## Step 4 - Perform only the approved post-deployment checks

Check target/module/config identity, one order-writer wiring, trusted price
mapping, existing checkout target, and unchanged protected source/config.
Compare the protected historical records and relationships independently.

Perform a customer-flow or payment smoke check only if its exact synthetic
identity, data effects, environment, and gateway operation were approved. No live
charge, capture, void, refund, paid route call, or public submission is implied
by deployment approval. Unapproved checks remain NOT RUN; do not pretend otherwise.

Preserve fixed development routing on development/DDEV targets. Do not change a
target's route mode, keys, browser maps, budgets, or payment credentials as part
of bridge rollout. If that target's approved operating mode prevents a safe smoke
check, record the limitation rather than changing it.

Observe only for the approved bounded interval, including relevant errors,
duplicate-order indicators, stuck handoffs, and reference consistency. Do not
start unattended watchers, new automations, cron jobs, or unbounded monitoring.

## Step 5 - Close or recover without losing newer data

On failure, preserve logs/identities privately and follow the approved recovery
checkpoint. Check current writes again before any rollback action. Unknown
gateway outcomes remain unknown pending approved reconciliation; never void or
refund blindly. Do not release driver capacity merely because a response was lost.

On success, reconcile final configuration/package versions, protected historical
invariants, in-flight/new record continuity, and fixture disposition. Preserve
unrelated dirty work and report all changed paths and commands/exit codes.

Write ROLLOUT.md with approvals, checkpoints, exact deployment diff, backup and
recovery evidence, observations, post-cutover writes, any rollback actions,
remaining limitations, and final status. Update README without erasing history.

Report DEPLOYED only for the explicitly approved target and actually completed
scope. Otherwise report BLOCKED/PARTIAL/RECOVERED with precise evidence. No commit,
legacy cleanup, deletion, redesign, or additional deployment is authorized.
