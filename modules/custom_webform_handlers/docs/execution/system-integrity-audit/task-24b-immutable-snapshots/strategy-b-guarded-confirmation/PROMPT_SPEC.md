# Task 24B — Strategy B: guarded reservation binding acceptance

You are Claude Code CLI working in C:/Users/mo737/ppu. Execute this bounded follow-up only. Do not start Task 24C, 24D, 24E or form simplification.

## 1. Read the current evidence first

Read these files completely before implementation or probes:

- CLAUDE.md, applicable AGENTS.md files and C:/Users/mo737/.codex/RTK.md.
- modules/custom_webform_handlers/docs/execution/system-integrity-audit/RULES.md.
- modules/custom_webform_handlers/docs/execution/system-integrity-audit/task-24b-immutable-snapshots/PROMPT_SPEC.md.
- The current-status header, Sections 6 and 9-10, and any later addenda in task-24b-immutable-snapshots/round2-evidence.md.
- task-24b-immutable-snapshots/strategy-a-evidence-20260923.md.
- task-24h-route-cost-boundary/HTTP-ACCEPTANCE-CLOSEOUT.md, including its final addendum.

The shared ChatGPT link ends with the original September 14 audit. Later local evidence supersedes its historical readiness findings.

Documented baseline, to recheck without rerunning completed work:

- Task 24A closed its schema-readiness scope.
- Task 24H records successful bounded QA HTTP/AJAX acceptance, including a real released hold. It is not proof of every browser/gateway path.
- QA applied update_10047; Strategy A recorded 68 passed / 0 failed, outer exit 0, a real checkout-attempt INSERT/reload and mandatory rollback.
- Task 24B remains PARTIAL. Guarded confirmation, persisted-job mismatch rejection, real order/display integration and concurrency/payment acceptance are not established.
- The default site was not migrated to 10047 by that QA run. Its schema-behind behavior is a separate boundary.
- Web HTTP health was unhealthy even though QA Drupal CLI bootstrapped. Do not infer today's health or repair it automatically.

## 2. Objective

Prove that real guarded confirmation binds a reservation to BOTH:

1. The immutable accepted job represented by the reloaded checkout-attempt snapshot.
2. That same snapshot's versioned binding identity persisted on the actual held reservation.

An unchanged valid job must confirm. A different job must be refused even if its price, route duration and reserved window happen to be identical.

Use the existing AcceptedJob, QuoteSnapshot, planner and calendar-store implementation. This is an acceptance-and-targeted-fix task, not a new architecture.

## 3. Scope and execution authority

When the owner sends this prompt for execution, it authorizes one bounded QA-only fixture probe, including synthetic attempt, submission and reservation setup, real guarded confirmation, and explicit rollback of every probe write.

It does NOT authorize real payments, committed customer bookings, migrations, schema/config changes, default-site writes or infrastructure repair.

For this task only, use the already-running DDEV web container through the native Windows Docker CLI, with Drush explicitly targeting --uri=https://ppu-qa.ddev.site and working directory /var/www/html. This is a narrow exception to the generic DDEV wrapper rule to avoid the documented automatic-start behavior.

Discover and verify the installed native docker.exe; do not assume a prior absolute path or container ID is still valid. Every shell command must start with rtk.

No ddev start/restart/exec, Docker recreate/remove, Mutagen start/resume/reset, volume deletion or alternate environment. If existing-container execution is unavailable, stop with a precise blocker.

## 4. Skills and evidence gates

- Use the relevant writing-for-agents skill for evidence/handoff documentation.
- Use Graphify and GitNexus for actual PHP call paths and impact; inspect installed source and service YAML where graph coverage ends.
- Use the applicable codebase-design / gitnexus-exploring skills when tracing the binding seam. If a defect is reproduced, use diagnosing-bugs and applicable impact-analysis guidance before a narrow fix.
- Use Context7 for framework/transaction behavior and DBHub with the dedicated read-only account for schema evidence, as repository policy requires.
- Check tools before claiming they worked. A stale/empty graph is not evidence of an absent caller. A failed required tool remains a blocker for its affected work; this prompt does not grant a blanket substitute-tool exception.
- Subagents may independently inspect transaction cleanup and acceptance coverage. They must not write overlapping files or run concurrent fixture mutations.
- No PHPUnit, Kernel, FunctionalJavascript, Selenium, automated browser suites or permanent new test harness. Only the explicitly authorized temporary direct probe is in scope.

## 5. Files to inspect and narrowly modify only if needed

All paths below are relative to modules/custom_webform_handlers/:

- src/Checkout/AcceptedJob.php
- src/Checkout/QuoteSnapshot.php
- src/Checkout/CheckoutAttempt.php
- src/Checkout/CheckoutAttemptStore.php
- src/Commerce/DispatchCheckoutManager.php — inspect argument forwarding; no payment execution
- src/Dispatch/DispatchCheckoutPlannerInterface.php
- src/Dispatch/DispatchPlanner.php
- src/Dispatch/Storage/DatabaseReservationStore.php
- src/Dispatch/Storage/ResourceCalendarGuard.php
- src/Dispatch/Storage/LockedHold.php
- src/Dispatch/DispatchClaim.php
- src/Dispatch/DispatchRequestFactory.php
- custom_webform_handlers.services.yml — inspect only unless a reproduced defect requires separately scoped wiring changes

Output:
- docs/execution/system-integrity-audit/task-24b-immutable-snapshots/strategy-b-guarded-confirmation/EVIDENCE.md.
- A task-owned temporary probe inside that directory; remove it after verification.
- Update the current-status pointer in round2-evidence.md without erasing historical records.

Preserve all existing dirty files. No commit, reset, stash, broad formatting, unrelated cleanup or dependency installation.

## 6. Read-only preflight before any fixture write

1. Record git status and source identity for the relevant files. Prove host/container SHA-256 parity; shared Mutagen synchronization does not mean QA-only source changes.
2. Verify the selected runtime reports database qa, site path sites/qa and effective mode development_fixed. Reconfirm schema 10047 and the exact required columns on both attempt and reservation tables. Never print secrets or entire environment/config dumps.
3. Verify transaction support, absence of an ambient transaction, relevant triggers and reachable hooks. Determine the real nested-transaction behavior from installed Drupal, not assumptions about destructor timing.
4. Inventory every fixture write and potential non-transactional side effect: entity hooks, ECA, logs, queue, cache, session ownership and notification paths. Install probe-local no-network/side-effect denial where appropriate; do not change global services or production policy.
5. Reserve unused synthetic identifiers and a unique task tag; record an exact cleanup ledger. Use synthetic addresses and fixed route facts only.
6. Prefer real storage and supported public constructors for fixture setup. If a genuine saved submission cannot be seeded without unsuppressed side effects, stop and describe the minimal needed fixture strategy. An unsaved object cannot prove rereading persisted job facts under a lock.
7. Demonstrate a cleanup mechanism before proceeding. If schema, source parity, tools or no-network isolation is unproven, do not write fixtures.

## 7. Probe the real binding path

Use the actual reservation store, planner binding checks and ResourceCalendarGuard. Do not mock the lock, storage reads or comparison predicates. Inject deterministic route facts at the existing estimator boundary; do not call an external provider.

Create each case with fresh, isolated fixture state inside an explicitly rolled-back transaction:

| Case | Required result |
| --- | --- |
| Matching accepted job and persisted composite binding | Confirmation succeeds; reservation fields match the held job and accepted snapshot. |
| Saved pickup/drop-off changed after acceptance, with identical fixed price/duration | Confirmation is refused; no confirmed row is written. |
| Saved material package/service/access fact changed while timing still fits | Confirmation is refused based on job identity, not only timing. |
| Same pricing-output snapshotId, different accepted-job digest | Different composite bindings cannot substitute for one another. |
| Valid snapshot belonging to a different held reservation | Binding mismatch is refused through the real guarded path. |
| Missing/malformed current-format binding | Refused; no fallback into a legacy acceptance branch. |
| Genuine historical legacy record | Preserve the documented legacy behavior; do not fabricate current-format integrity data. |

Reload the accepted snapshot through a fresh CheckoutAttemptStore instance before using it. Supply bindingIdentity() through the actual current method contract, not a hand-constructed expected string.

For every case, record:

- Which persisted fixture was read and whether its job identity was recomputed.
- That the real calendar guard acquired the relevant row locks and ran its revalidation callback.
- That the decisive persisted read/comparison occurred within that guarded transaction. An outer transaction alone does not prove calendar locking.
- Return reason and before/after reservation state.
- Unchanged accepted price, currency, version and payload/binding identities.
- External provider/payment/notification dispatch attempts for the exercised path: zero.

A refusal before reaching the guarded comparison is not evidence that the under-lock identity check works. If necessary use a narrowly scoped probe observer at an existing seam, without changing business decisions. Do not use reflection to invoke private predicates instead of the real path.

This sequential probe does not establish concurrent double-booking prevention, exactly-once callbacks or crash durability.

## 8. Fix only a reproduced binding defect

First capture a failing case with a working unchanged-job positive control. Repair the smallest owning method, preserving current interfaces where possible.

No broad pricing/planner refactor. No new reservation identity column or update hook here; the current versioned composite-binding design must be evaluated as implemented.

Keep zero-cost routing and all source/config compatibility guards intact. If the required correction exceeds these limits or affects schema/checkout payment flow, stop with the evidence and a bounded follow-up proposal.

If PHP source changes, repeat source parity, relevant lint, required graph refresh and a QA-only cache rebuild through the approved existing-container execution path. Never use the default-site cache command. Re-run only the affected bounded probe.

## 9. Mandatory rollback and independent cleanup proof

- Roll back explicitly in finally before releasing the transaction object. Do not rely solely on object destruction.
- Successful confirmation is observed inside the probe transaction; it is never a committed customer reservation.
- Prove no active transaction remains and the same connection finds no ledger fixtures.
- Use a separate QA Drush process after the probe exits to verify zero retained task-created attempts, reservations, submissions and guard rows by exact ledger identifiers. Existing guard rows must be preserved.
- Restore/clear only probe-local session/cache artifacts, if any; report them. Do not delete logs or historical QA rows.
- Remove only the temporary probe file on host and container and verify its absence.
- Capture the actual outer command exit. Failure must throw/return nonzero; a printed PASS/FAIL count or unconditional exit(0) is not sufficient.
- Report any auto-increment gap as expected rollback residue, not a retained record.

If cleanup cannot be proven, report the exact fixture ledger and stop. Never conceal a partial run or repair it with table-wide deletion.

## 10. Completion report and stop boundary

Write a concise evidence report with:

1. Verified environment/source/tool status and any limits.
2. Case-by-case PASS / FAIL / BLOCKED with actual exit codes.
3. Positive control and guarded rejection evidence.
4. Exact affected files and any reproduced defect/fix.
5. Explicit rollback plus independent fixture-absence results.
6. Zero-call evidence scope: the exercised path, not an unmeasured whole-stack claim.
7. Remaining Task 24B checks.

Use the final status:
- Strategy B: COMPLETE only if every applicable binding case and cleanup check passed.
- Task 24B: PARTIAL while real Commerce order/checkout-display integration and other required acceptance remain pending.

Do not rerun update_10047, restart Strategy A, install a gateway, execute confirmPaid/capture/void/refund, claim browser acceptance, or advance automatically into Tasks 24C-24K.

## Non-negotiable invariants

- Task 21A and the Task 24H zero-cost boundary remain intact.
- No external API or payment call, especially inside any lock transaction.
- Final local reservation confirmation remains synchronous.
- No public hold countdown or timezone selector; America/New_York display, UTC storage.
- No fabricated historic snapshots, mutable paid price or acceptance of client-supplied totals.
