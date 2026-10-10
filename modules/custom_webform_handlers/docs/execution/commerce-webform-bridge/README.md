# Task 25: contrib-only Webform-Commerce bridge execution pack

Prepared: 2026-10-07 (America/New_York). Repository: `C:/Users/mo737/ppu`.

This pack saves the full Claude Code prompts previously discussed in chat. Its
creation is documentation work only: none of Tasks 25A-25D has been executed by
writing these files, and no compatibility or runtime acceptance is claimed.

## Current direction

Connect the existing Webform and server-trusted quote to the existing Commerce
checkout through maintained contrib and supported configuration, if compatible.
Preserve the scheduling engine, driver locks, pricing, payment implementation,
and legacy historical data. No new custom module or custom integration code.

Commerce Webform Order is the first candidate, not an approved replacement.
The decisive question is whether it can preserve the existing checkout-attempt,
capacity-hold receipt, and payment contracts with exactly one order writer.
If configuration cannot do that, stop this workstream and report the gap.

## Execution order and status

Read [RULES.md](RULES.md) completely, then give Claude one prompt at a time.

### Current status (authoritative as of 2026-10-08)

- **25A: verdict B — CUSTOM INTEGRATION REQUIRED / OUTSIDE SCOPE. Closeout corrected 2026-10-08 (Task 25A-R1); verdict retained.**
- **25B–25D: BLOCKED.**
- **26B: BLOCKED / NOT AUTHORIZED FOR EXECUTION.** Its execution plan was corrected 2026-10-08
  (Task 26A-R1, documentation only); six blockers **B1–B6** stand and approval is split into two
  ungranted gates. **B5 diagnosed 2026-10-08** (Task 26B-P1, read-only): supervisord never started
  in the web container; repair proposed, **not authorized**. See the *Related pack — runtime
  readiness* section below.
- **Current action: await an owner decision. No installation is authorized.**

| Order | Task / full prompt | Status when this pack was saved | Current status | Entry gate |
| --- | --- | --- | --- | --- |
| 1 | [25A: bridge compatibility](task-25a-bridge-compatibility/PROMPT_SPEC.md) | Next task; assessment not executed | **DONE — verdict B; closeout corrected 2026-10-08** | Read-only assessment; documentation writes only |
| 2 | [25B: isolated QA setup](task-25b-isolated-qa-setup/PROMPT_SPEC.md) | BLOCKED | **BLOCKED** | 25A verdict A and explicit approval of the exact isolated environment and change manifest |
| 3 | [25C: direct acceptance](task-25c-direct-acceptance/PROMPT_SPEC.md) | BLOCKED | **BLOCKED** | Accepted 25B evidence and explicit approval of the fixture/probe matrix and side effects |
| 4 | [25D: controlled rollout](task-25d-controlled-rollout/PROMPT_SPEC.md) | BLOCKED | **BLOCKED** | Accepted 25C evidence and explicit target, cutover, command, and recovery approval |

A saved prompt, a successful dependency install, or an order reaching checkout
does not satisfy the next gate. Verdict B or C in 25A keeps 25B-25D blocked.
Do not automatically execute the next task after finishing the selected one.

### Execution results (append-only; prior outcomes preserved)

| Task | Date | Verdict / result | Evidence | Scope actually run | Gate effect |
| --- | --- | --- | --- | --- | --- |
| 25A | 2026-10-07 | **B — CUSTOM INTEGRATION REQUIRED / OUTSIDE SCOPE** | [task-25a-bridge-compatibility/DECISION.md](task-25a-bridge-compatibility/DECISION.md) | Read-only application/configuration/database inspection; documentation writes only (`DECISION.md`, this README). No install, enablement, migration, config import, `updb`, cache rebuild, submission, fixture, payment, browser probe, or service change. | 25B-25D remain **BLOCKED**; no 25B approval is requested. |
| 25A-R1 | 2026-10-08 | **B retained; closeout corrected** | [DECISION.md §7](task-25a-bridge-compatibility/DECISION.md) | Documentation correction only (candidate pinned to the `3.0.6` tag; atomicity, price, item-type, isolation, and protected-data statements corrected; scope report reconciled). Read-only inspection; **no** install, no custom code, no config/migration/cache change, no submission/payment, no Task 25B-25D action. | **25B-25D remain BLOCKED**; verdict unchanged; await owner decision. |

25A found that Commerce Webform Order (`commerce_webform_order` 3.0.6) is
package-compatible but cannot preserve the existing checkout-attempt, private
Hold receipt, or payment/capacity contracts through stock configuration: it
creates the order through its own cart path outside the module's single order
writer, produces no `CheckoutAttempt` row, takes no Hold, and has no counterpart
to the immutable accepted-quote identity. The first unsupported transition is
*submission save → order creation*; closing it requires custom code, which the
pack forbids. Because the verdict is B, there is no 25C to carry dynamic proof
and no 25D.

The 2026-10-08 correction (Task 25A-R1) **retained** the verdict and **narrowed**
its scope: it rejects this assessed candidate for these protected contracts only,
does not generalize to all contributed modules, and does **not** authorize a new
custom integration. It also pinned the candidate evidence to the exact `3.0.6`
release, separated declared dependency compatibility from verified Composer
resolution (not run), and replaced over-broad statements about atomicity, price
provenance, item-type support, isolation, and protected data with sourced,
scope-limited ones. See DECISION.md §7.

## Next action

**Await an owner decision.** No installation, custom integration, migration,
cache rebuild, or Task 25B–25D work is authorized by this pack. The stale
"execute 25A now" instruction that previously appeared here has been removed:
25A is complete.

## Related pack — runtime readiness (Task 26A)

The 25A findings above are the compatibility side of the picture. The **runtime** side
(the source / compiled Drupal service container / database schema being out of step) is
assessed separately by Task 26A, whose evidence does **not** change anything in this pack:

- [Task 26A — runtime readiness](../runtime-readiness/task-26a-readiness/READINESS.md)
  — read-only assessment. Confirms the default site's compiled service container still holds
  **15** arguments for `dispatch_checkout_manager` (missing `@database`) versus **16** on QA,
  identifies `update_10047/10048/10049` as the additive schema prerequisite, and specifies the
  preservation checks and the gated (not-authorized) recovery plan.
- [Task 26B — proposed next execution prompt](../runtime-readiness/task-26a-readiness/NEXT_EXECUTION_PROMPT.md)
  — marked **NOT AUTHORIZED FOR EXECUTION** and **BLOCKED** pending isolation, approval of the
  activated wiring set, and a backup/restore rehearsal.
- [Task 26A-R1 — execution-plan correction](../runtime-readiness/task-26a-readiness/TASK-26A-R1-PROMPT_SPEC.md)
  (2026-10-08) — **documentation correction only**, bounded read-only inspection. Task 26A's
  evidence stands; its **execution and recovery sequence** had six safety gaps, now corrected
  ([READINESS.md §10](../runtime-readiness/task-26a-readiness/READINESS.md),
  [NEXT_EXECUTION_PROMPT.md §10](../runtime-readiness/task-26a-readiness/NEXT_EXECUTION_PROMPT.md)).
  Pinned to installed Drush **13.6.2** / core **10.6.16**: `updatedb --no-post-updates` is not a
  defined option; `updatedb` **clears caches by default**, so the proposed "schema applied,
  container still old" checkpoint does not exist and activation happens at `updatedb` rather than
  at a later `drush cr`. **B3** (runtime atomicity, safe disposal after a failed rollback) is
  separated from **B4** (backup/restore) — a successful restore does not prove B3 — and two new
  blockers are added: **B5** the unhealthy web container (`phpstatus:FAILED`, `mailpit:FAILED`) and
  **B6** a verified isolation target manifest. Approval is split into **Gate 1** (isolated
  setup/rehearsal) and **Gate 2** (default-site activation); **neither is granted.**
- [Task 26B-P1 — web-container health diagnosis](../runtime-readiness/task-26b-p1-web-health/DIAGNOSIS.md)
  (2026-10-08) — read-only diagnosis of blocker **B5**; **no repair performed**. Both failures
  **REPRODUCE** with one established cause: the web container's **supervisord is not running**, so
  `nginx`, `php-fpm` and `mailpit` never started and nothing listens on `:80` or `:8025` (curl exit
  7, not an HTTP error and not a timeout). The container was started 2026-10-08T02:14:30Z **without
  DDEV's `/start.sh` phase**, leaving DDEV's log-pipe helper `/pre-start.sh` as PID 1. Nothing is
  misconfigured, and the image's own self-heal path cannot fire because `supervisorctl` cannot reach
  an absent supervisord. This also explains why Task 26A's PHP **CLI** bootstrap succeeded while
  HTTP was dead. Repair proposal:
  [REPAIR_PROMPT.md](../runtime-readiness/task-26b-p1-web-health/REPAIR_PROMPT.md) — **PROPOSED —
  NOT AUTHORIZED FOR EXECUTION.** It carries a gate interaction that must not be missed: Redis
  restarted in the same second as web, so if the default site's cached **15-argument** container was
  lost, restoring PHP-FPM would itself trigger the **B2/B3-gated activation**. A read-only Redis
  pre-check decides that, and must run first.

**Status unchanged:** verdict **B** is retained; **25B–25D remain BLOCKED**; no installation or
custom integration is authorized. Task **26B remains BLOCKED / NOT AUTHORIZED FOR EXECUTION**, and
neither the 26A-R1 correction nor the 26B-P1 diagnosis authorizes any repair. **B5 is now diagnosed
but not repaired**; B1–B4 and B6 are untouched.

## Relationship to earlier packs

- [Task 24](../system-integrity-audit/README.md) remains historical evidence and
  an open-issue record. This pack changes the next execution priority, not its
  acceptance history. Do not resume competing architecture changes automatically.
- [Task 22](../single-resource-scheduling/README.md) scheduling/driver protection
  is preserved; this pack does not authorize changes to it.
- [Task 23](../customer-flow-simplification/README.md) form redesign is deferred;
  connecting the existing flow is not authorization to redesign or delete fields.
- Schema reconciliation, historical cleanup, legacy-code deletion, gateway setup,
  and infrastructure repair require separately scoped tasks if actually needed.

## Evidence files produced during execution, not pre-filled results

| Task | Evidence deliverable |
| --- | --- |
| 25A | `task-25a-bridge-compatibility/DECISION.md` |
| 25B | `task-25b-isolated-qa-setup/QA-SETUP.md` |
| 25C | `task-25c-direct-acceptance/ACCEPTANCE.md` |
| 25D | `task-25d-controlled-rollout/ROLLOUT.md` |

Executors update the status table with the evidence path, date, and exact scope
only after the selected task runs. Preserve prior outcomes when appending a new
attempt. Missing evidence means NOT RUN or REQUIRES_VERIFICATION, never PASS.

## Atomicity and historical-data rule

Preserve existing short database transactions and driver locking. Composer,
filesystem, configuration, schema, and external payments do not form one atomic
transaction. Use isolated rehearsal and coordinated, recoverable deployment.
Never restore an old database over legitimate new orders or reservations.

Saving this pack does not authorize any database restoration or mutation.
