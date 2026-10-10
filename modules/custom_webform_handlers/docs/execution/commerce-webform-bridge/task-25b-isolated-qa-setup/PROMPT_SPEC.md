# Task 25B - Approved contrib-only setup in isolated QA

You are Claude Code CLI. Start in `C:\Users\mo737\ppu` to read instructions,
then operate only in the exact isolated target explicitly approved by the user.
Read [shared rules](../RULES.md) and the completed Task 25A decision fully.

## Objective and hard entry gate

Install and configure only the approved maintained-contrib connection in an
isolated QA environment. Preserve the existing scheduling, driver locks,
Commerce checkout/payment implementation, trusted quote, and historical data.
No new custom module, adapter, PHP/JS integration code, or core/contrib patch.

**Initial status: BLOCKED. This saved prompt is not installation authority.**

Before any mutation, require all of:

1. Task 25A verdict A with source-backed proof of one order writer and preserved
   CheckoutAttempt, Hold receipt, pricing, and payment/capacity contracts.
2. Explicit human approval naming the target path/site/database, dependency
   versions and complete package diff, exact config manifest, reviewed schema
   effects, backup/restore scope, and any exceptions to repository file scope.
3. Isolated application code and vendor tree as well as database, file storage,
   cache/session namespaces, queues, workers, and external integrations.
4. Current safety prerequisites and a recoverable baseline.

If anything is absent, write only the missing-gate report; do not prepare a
container, clone data, install a package, or amend application configuration.

## Target files and data

Allowed after approval: only the exact 25A manifest in the named isolated copy.
This may include its `composer.json`/`composer.lock`, installed unpatched contrib
packages, and the specific bridge handler/mapping configuration. Root-file and
environment exceptions must be expressly approved, not inferred from this list.

No edits to custom-module source, protected service wiring, scheduling settings,
existing Commerce workflows/panes, or legacy records. New fields are not a
default allowance: any contrib-provided or configured field must be in the
approved installation/config manifest and preserve existing bundle behavior.

Documentation outputs in this pack:

- `task-25b-isolated-qa-setup/QA-SETUP.md`.
- `README.md`, status/evidence link only.

Private backups contain sensitive data: store only in the approved private
location, outside committed evidence. Do not add them to Git.

## Step 1 - Revalidate authority, identity, and isolation

Read policies/skills; inspect dirty files and relevant diffs. Refresh the source
and config fingerprints behind 25A. If protected contracts changed, return to
25A rather than applying an outdated plan.

Verify actual resolved paths, container mounts, site/URI/database, DB users,
uploads, Redis/cache namespaces, queue consumers, cron, email, and gateway modes.
QA must not write to default-site code, vendor, files, sessions, or shared queues.
Do not use a second database in the same writable codebase as proof of isolation.

Inspect health without implicitly starting services. If isolation or health needs
unapproved infrastructure work, stop and request a separately bounded task.

Completion: exact target and isolation evidence match the approval; all other
sites are outside the proposed write surface.

## Step 2 - Capture and rehearse recovery before setup

Capture the approved baseline: dirty-code state, dependency lock, configuration,
database, uploaded files as needed, and legacy record/value/reference invariants.
Preserve identifiers, accepted prices, snapshots, attempts, and Reservations.
Verify backup completeness/checksums; do not expose secrets or customer data.

Rehearse restoration only in the explicitly approved disposable recovery target,
not over the original QA/default database. Verify restored identity, schema,
config, record relationships, and protected files. A dump file alone is not a
verified restoration. If the recovery target is not approved, stop this gate.

Define how to undo task-owned config/dependency changes without losing existing
dirty work or later legitimate records. No destructive rollback is implicit.

Completion: a coherent recovery point and rehearsed procedure exist for exactly
the proposed setup. Do not promise atomicity across Composer, files, and DDL.

## Step 3 - Review dependency and installation effects

Compare the proposed solver result against the approved package manifest using a
supported non-mutating inspection method with no unreviewed scripts/plugins.
Do not assume a dry-run label prevents every ancillary side effect.

Inspect the exact candidate release, install hooks, entity/base-field changes,
default configuration, dependency enablement, Composer scripts/plugins, and all
pending update hooks. Stop on any additional package, upgrade/downgrade, patch,
hook, or config entity not included in approval.

Existing schema mismatch requires a separate authorized reconciliation task.
Do not run blanket `updb` or call isolated update-hook functions arbitrarily.
If the approved supported workflow cannot be scoped safely, stop.

Completion: exact dependency/install effects are understood, approved, and
reproducible; no unrelated pending update will be swept into setup.

## Step 4 - Install the approved dependency set

Use the approved Composer/DDEV commands serially in the isolated target. Install
only the candidate and reviewed dependencies. Keep core/Commerce/Webform versions
and all other packages unchanged unless an exact exception was approved.

Enable only the approved modules against the explicit QA URI. Record actual
commands, outputs, exit codes, package lock changes, and installation effects.
After authorized Drupal configuration changes, perform the required site-specific
cache rebuild once the environment is healthy. Stop on errors; no blanket repair.

Do not import a demo recipe, create products/stores/gateways, or enable unrelated
submodules merely because the candidate's examples use them.

## Step 5 - Apply only the proven bridge configuration

Apply the exact 25A-approved handler order, trusted price source, currency,
shipment mappings, order/item types, redirect, and cart policy.

Prove one order writer before activation. Do not leave old and new creators active
together. The configuration switch must not remove essential responsibilities of
the existing handler. If that cannot be done with the approved stock settings,
stop with incompatibility; do not write glue or disable whole handlers blindly.

Preserve attempts, Hold receipts, capacity confirmation, and the existing payment
flow. Keep item combining and cart clearing/finalization disabled unless their
exact reviewed behavior was approved. Submission synchronization deletion remains
disabled throughout Task 25, without a setup exception. Do not enable purge or
abandoned-data cleanup during installation; those require a separate task.

No customer submission, payment, or capacity fixture is authorized in 25B.

## Step 6 - Validate configuration without booking traffic

Verify dependency versions, plugin discovery, schema/config validity, exact
handler settings, mappings, target flow, and single-writer wiring with scoped
inspection. Confirm that no protected source or configuration changed.

Compare legacy identities, values, relationships, and uploads with the baseline;
installation may add only the explicitly reviewed technical/schema artifacts.
Report unexpected drift and stop without attempting cleanup or restoration over
another actor's work. Recheck default-site isolation without mutating that site.

Run scoped diff/whitespace checks and applicable non-executing config validation.
Do not claim browser, payment, capacity concurrency, or booking atomicity acceptance.

## Exit gate and report

Write QA-SETUP.md with approvals, environment/isolation evidence, backup and
restoration proof, exact dependency/config diff, reviewed installation effects,
commands/exit codes, protected-source/data comparisons, and remaining blockers.

Report **QA SETUP COMPLETE, ACCEPTANCE PENDING** only if all gates passed.
Otherwise report BLOCKED or PARTIAL and the precise last completed checkpoint.
Keep 25C blocked until the user approves its direct acceptance scope. No commit,
source refactor, automatic fixture creation, rollback, or Task 25C execution.
