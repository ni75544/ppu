# Task 24 shared execution rules

Read this file fully before executing any Task 24 prompt. Work in C:/Users/mo737/ppu.

## Scope and sequence

- Read the applicable AGENTS.md, CLAUDE.md, RTK instructions and relevant native skills. Follow actual repository policy for Graphify, GitNexus, DBHub, Context7 and file scope.
- Inspect git status and relevant diffs before editing. This audit describes a dirty working tree at b49c259e3e3d5452286ffb45d1ec6bf1451c0fcc on 2026-09-14; refresh every finding against current source and active configuration.
- Execute only the selected task. Preserve unrelated and concurrent changes. No commit, stash, broad cleanup, dependency installation or infrastructure change is included.
- Keep implementation and evidence inside modules/custom_webform_handlers unless the user separately authorizes a precisely named exception.
- Reuse existing Commerce, Webform, planner, stores, queue and policy services. Proposed new classes below are small integration seams, not a replacement platform.

## Hard safety boundaries

- Task 21A is accepted. Preserve its DDEV/development fixed-route policy and existing guard. Do not switch routing_mode, weaken a guard, supply a paid key, enable Google Maps/Places scripts, or call Google/Mapbox/other external providers to make a check pass.
- Prove fixture-only execution at the actual outbound-HTTP boundary before performing dynamic checks. Development quote, scheduling, route warming, staff operations and checkout must be covered. Missing fixture facts fail closed.
- Keep route preparation outside resource/calendar and financial database locks. Inside locks, use prepared validated facts only. No HTTP, geocoding, payment capture/void/refund, mail, queues or slow work inside a lock transaction.
- Commit final local reservation decisions synchronously. Queues may deliver notifications or reconcile external payment status; they must not become the authority that allocates final capacity.
- A database transaction cannot atomically commit a remote payment gateway. Use durable local operation records and provider-supported idempotency; treat ambiguous responses as unknown until reconciled.
- Public displays use America/New_York; timestamps are UTC internally. Preserve explicit DST handling and half-open intervals. No public timezone selectors or hold countdowns.
- Never expose credentials, database authentication hashes, raw gateway exceptions, customer addresses or bearer tracking codes in evidence. An unkeyed address hash is not strong anonymization.

## Verification and environment

- No PHPUnit, Kernel, FunctionalJavascript, Selenium, browser automation suites or other generated/executed test suites.
- Use bounded direct probes, PHP lint, JS syntax checks, YAML/schema validation, scoped Drush inspection and manual browser checks. Concurrency checks use clearly identified synthetic records and local fake payments in an isolated DDEV database, never customer records or real charges.
- Before DDEV commands, inspect project health without starting/restarting it. In this audit, ddev exec unexpectedly initiated startup; parallel invocations caused container recreation conflicts. Run DDEV lifecycle operations serially and only with explicit scope. Do not repair this by deleting a volume.
- Review pending updates and obtain a verified local backup before any authorized migration. Never run blanket updatedb or config import without reviewing every pending change, including 10045's routing default.
- After authorized PHP/config changes, run the repository's required ddev exec vendor/bin/drush cr once the environment is healthy. Run git diff --check scoped to the module. Do not treat cache rebuild as business-logic acceptance.
- Remove only your identified temporary probes and synthetic records, retaining sanitized evidence. No destructive cleanup of shared data.

## Completion report

For each task provide: reproduced baseline, already-correct behavior, changed files, verification commands and actual results, final invariants, remaining blockers, and whether manual browser acceptance occurred. Distinguish source-traced risk, reproduced defect and verified fix. A pending critical check means the task is not fully accepted.
