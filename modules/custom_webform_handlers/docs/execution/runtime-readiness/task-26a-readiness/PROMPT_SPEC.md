# Task 26A — Existing Runtime Readiness: Read-Only Assessment and Recovery Plan

Repository: `C:\Users\mo737\ppu`. Prepared: 2026-10-08 (America/New_York).

This file is the governing instruction for Task 26A. It is a read-only assessment:
it authorizes inspection and the documentation listed here, and nothing else.

## Relationship to prior tasks

- **Task 25A-R1** is accepted as the corrected compatibility closeout. **Verdict B is retained:**
  the assessed candidate (Commerce Webform Order) is incompatible with the current protected
  implementation under configuration-only constraints. Tasks **25B–25D remain BLOCKED.**
- The corrected decision
  (`commerce-webform-bridge/task-25a-bridge-compatibility/DECISION.md`, including its §7
  correction record) **supersedes** the older Claude memory notes about isolation and container
  staleness. Do not edit memory files.
- Continue with the **existing** Webform–Commerce implementation. Do not install Commerce
  Webform Order or introduce another integration.

## Objective

Determine the **minimum safe sequence** for reconciling three things that are currently out of
step: the working-tree **source**, the compiled **Drupal service container**, and the **database
schema**. Answer four questions:

1. Does the reported 15-versus-16 constructor-argument mismatch still exist?
2. Which schema differences would matter if Drupal rebuilt its service container against the
   current source?
3. Which existing update hooks, if any, can resolve those differences without modifying
   protected scheduling behavior or legacy records?
4. What exact isolation, backup, verification, and recovery prerequisites must precede any
   execution?

Do not repeat the completed contrib compatibility audit.

## Authority and boundaries

Authorized: **read-only inspection** and the documentation files named below.

Preserve **unchanged** (inspection only):

- Scheduling engine, driver/day guard, capacity, Hold/Reservation logic, admission enforcement,
  and transaction/locking implementation.
- Commerce order/item types, checkout/payment implementation, and workflows.
- Historical submissions, orders, items, attempts, reservations, snapshots, uploads, customer
  associations, and financial records.
- The existing `development_fixed` routing safeguards.

**Not authorized** — no source or configuration edits, no Composer operations, no module
installation/enablement, no migrations, no backfills, no cleanup of historical records, no
gateway setup, no paid API calls, no form submissions, no fixtures, no payment operations, no
automated test suites. Specifically do **not** run:

- `drush cr`
- `updb` or individual update hooks
- configuration imports or writes
- cron, queue workers, container restart/recreation, database restores

Do not commit, stash, reset, discard dirty work, update agent memory, or modify root policy
files.

## Evidence tools

- Follow Graphify's query-first rule for PHP/JS analysis; inspect **YAML and SQL directly**
  (Graphify does not cover YAML or SQL beyond a partial dump).
- Record unavailable or stale tools accurately. The committed `graphify-out/graph.json` is
  stale relative to working-tree edits; PHP line references in this pack are read from source,
  not taken from the graph.
- **DBHub fallback (explicitly authorized for this task).** If DBHub remains suspended, bounded
  read-only SQL and Drush inspection through the **already-running, verified** project container
  (`docker exec ddev-ppu-web …`, never `ddev exec`) are the database fallback. Do **not**
  reactivate DBHub or start/recreate containers.

## Execution order

- **Step A — Current baseline.** Inspect Git status/diff without changing files; record revision
  and dirty paths. Verify container health without lifecycle changes. Identify each inspected
  site explicitly: URI, site directory, database identity, schema version, effective routing
  mode. Revalidate the reported default schema `10046` and QA schema `10049`; treat them as
  historical until checked. Stop a check and record the limitation if it would require
  rebuilding services or changing runtime state.
- **Step B — Container/schema dependency.** Compare the `DispatchCheckoutManager` constructor,
  its current service YAML, the existing compiled Drupal service definitions, and host/container
  copies of the files. Distinguish Drupal's compiled service container from Docker
  infrastructure; do not instantiate a business operation to reproduce the error. Inventory the
  complete pending update-hook list per site non-executively; read each hook and its
  dependencies; document tables/columns/indexes/config affected, data transformations, effects on
  scheduling/admission/transactions, prerequisites/retry/recovery, and why each is necessary or
  must stay deferred. A schema version number is not proof a column or constraint exists —
  compare actual schema with specific source requirements. Do not propose blanket `updb` or
  skipping hooks; report a protected-boundary crossing as an approval blocker.
- **Step C — Legacy-preservation evidence.** Cover dispatch and legacy reservation storage,
  including confirmed legacy reservations and guard records. Specify repeatable before/after
  checks for identities, statuses, relationships, amounts/currencies, immutable snapshots, and
  files — not counts alone. Use bounded read-only aggregates and protected integrity comparisons.
  Do not print customer data, addresses, credentials, receipt tokens, or raw snapshots. Do not
  treat zero local payment rows as proof of no external financial activity. Separate legitimate
  concurrent changes from task-caused changes.
- **Step D — Gated execution plan.** Write the smallest justified sequence for later approval,
  with exact target and isolation, private backup scope and isolated restoration rehearsal,
  compatibility prerequisites and reviewed pending-hook manifest, any proposed migration or cache
  rebuild with ordering derived from evidence, direct verification and legacy-integrity checks,
  and recovery checkpoints that preserve legitimate writes after the baseline. Prepare commands
  but do not execute them; distinguish diagnostic reads from future mutating commands. Do not
  claim filesystem/config/DDL/DB changes share one atomic rollback. Missing payment-gateway
  configuration is a separate workstream and is not solved here.

## Deliverables

Under `modules/custom_webform_handlers/docs/execution/runtime-readiness/task-26a-readiness/`:

- `PROMPT_SPEC.md` — this file.
- `READINESS.md` — evidence, compatibility matrix, protected-data checks, findings.
- `NEXT_EXECUTION_PROMPT.md` — the proposed next task, prominently marked
  **NOT AUTHORIZED FOR EXECUTION**.

Also: add only a navigation/status entry to `commerce-webform-bridge/README.md`, keeping verdict
B and the blocked status of 25B–25D unchanged.

## Verification

Validate documentation links, whitespace, and `git diff --check`. Check untracked documents
explicitly (ordinary Git diff does not cover them). Compare relevant protected-file
fingerprints, not merely status counts. No cache rebuild or graph refresh is needed for
Markdown-only changes.

## Stop

Stop after saving the readiness assessment and the proposed execution prompt. **Do not begin the
proposed repair.**
