# Task 25C - Direct QA acceptance of the unchanged booking contracts

You are Claude Code CLI. Read [shared rules](../RULES.md), the Task 25A decision,
and Task 25B QA setup evidence fully. Use only the explicitly approved isolated
QA codebase/site/database, never default-site or customer booking traffic.

## Objective and hard entry gate

Verify that the configured contrib bridge preserves trusted pricing, one order
per accepted intent, customer isolation, recovery, and the existing driver guard.
Preserve historical data and all application implementations/configuration.

**Initial status: BLOCKED. Saving or opening this prompt is not probe authority.**

Require accepted 25B evidence and explicit human approval of:

- Exact target, manual/direct scenarios, synthetic identities/records, dates,
  write effects, and cleanup/retention policy.
- Any diagnostic tooling or temporary probe files and their bounded locations.
- Payment scenario scope: default is no payment calls; only a separately approved
  existing local test mechanism or named sandbox gateway may be exercised.
- Outbound email/webhook/queue/cron containment and the zero-cost route boundary.

Missing approval or missing safe payment tooling means BLOCKED for those cases.
Do not install/configure a gateway, write a mock backend, change PHP/JS/config,
or weaken acceptance criteria to finish. No automated test suites.

## Target files and data

Documentation outputs:

- `task-25c-direct-acceptance/ACCEPTANCE.md`.
- `README.md`, status/evidence link only.

Write only explicitly approved synthetic records through real application
interfaces in isolated QA. Diagnostic probes must not become custom integration
code or alter internals through reflection/direct SQL to simulate success.
Any temporary script must be individually approved, inventory-tracked, and kept
outside application autoloading. No core/contrib/custom source edits.

## Step 1 - Reconfirm the starting point

Read policies/skills and inspect Git status. Verify target identity, package and
config fingerprints, isolation, backup/recovery readiness, and absence of
concurrent unapproved writers. Record protected legacy record/value/reference
baselines independently before fixtures. Never treat all QA data as disposable.

Verify that route lookup, scheduling, checkout, route warming, staff actions, and
background work cannot call paid providers. Verify that mail, payment, and webhook
effects are contained. Stop before dynamic work if these boundaries are unproven.

## Step 2 - Approve and identify the exact fixture/probe matrix

Use public synthetic addresses/contacts and approved future windows that do not
modify or displace historical records. Reuse the existing resource policy; do not
add a driver or alter hours/buffers to manufacture availability.

Record each fixture's job/quote, submission, order, attempt, Hold, Reservation,
payment-test, and review IDs as they arise. Separate fixture artifacts from baseline
records. Distinguish actual database writes from browser-only state.

Use bounded direct calls and manual browser interactions. No PHPUnit, Kernel,
FunctionalJavascript, Selenium, generated test suites, or unbounded polling.
Two sequential users are not proof of concurrency.

## Step 3 - Execute the approved acceptance cases

For every case record setup, real entry point, expected result, observed result,
record IDs, relevant counter changes, and PASS/FAIL/BLOCKED/NOT RUN.

| ID | Case | Required evidence |
| --- | --- | --- |
| A01 | Incomplete addresses and typing | Validation intact; no paid route calls or new permanent order from typing |
| A02 | Committed pair and quote preview | Correct server facts/price; no permanent order solely from calculation |
| A03 | Date, slot, package, and duplicate-event interactions | Stored route reused; actual provider-call count remains zero in fixed mode |
| A04 | Explicit Continue | Exactly one intended item/order; accepted amount/currency/job snapshot preserved |
| A05 | Browser price/metadata tampering | Server rejects or ignores tampering; no underpriced or mismatched accepted order |
| A06 | Double-click, duplicate POST, and retry after interrupted response | Idempotent intended order/attempt/Hold result, not a second job |
| A07 | Different intent and same-user two tabs | Approved separate-job/cart behavior; no silent item combining or lost job |
| A08 | Two independent customers | No cross-user order, route-fact, Hold receipt, or checkout access |
| A09 | Concurrent requests for the same driver capacity | Actual overlapping requests; only feasible capacity wins through unchanged guard |
| A10 | Compatible sequential jobs | Existing travel/work/buffer/break rules honored without scheduler changes |
| A11 | Hold expiry, browser close, abandoned and resumed checkout | Existing safe refusal/recovery; no revived expired conflicting Hold or duplicate Reservation |
| A12 | Session loss and interrupted handoff | No fabricated receipt/attempt; no false success over missing durable prerequisites |
| A13 | Payment decline/authorization/capture failure/repeated notification | Approved test mechanism only; existing capacity/payment invariants and truthful reconciliation |
| A14 | Successful payment-backed confirmation | Approved test mechanism only; one Reservation and coherent order/attempt/payment relationships |
| A15 | Manual Review, uploads, guest redirect, and final metadata | Existing applicable contracts and stored references preserved |
| A16 | Legacy preservation | Independent post-run comparison of original records, values, relationships, and files |

Narrow expected outcomes to the actually configured current contracts before
execution. If an existing defect makes a required case fail, record it rather
than implementing a fix or declaring it irrelevant. Concurrency evidence must
include both request outcomes and persisted identities/state, not merely HTTP 200.

Do not hold a database transaction open while waiting for another customer or an
external payment. Exercise the existing application locking; do not replace it
with probe-created global locks. Final Reservation decisions remain synchronous.

## Step 4 - Verify effects and fixture cleanup independently

Use a fresh process/connection for post-run checks so a caller's transaction view
cannot hide residual records. If an approved probe uses an outer transaction,
retain and roll back its handle in finally, dispose it, then independently verify
cleanup; never assume that this covers HTTP/session/filesystem/queue side effects.

Remove only approved task-owned temporary files and synthetic records using the
approved lifecycle. No blanket truncation, wildcard delete, arbitrary release,
or restore over the baseline. Keep records when normal deletion is unsafe or
unapproved and report their exact retained IDs and effects without secrets.

Compare protected source/config, historical identifiers/values/references, and
uploads again. Unexpected drift stops acceptance and is not auto-repaired.

## Step 5 - Close acceptance honestly

Write ACCEPTANCE.md with the approved matrix, all outcomes, independent checks,
provider counters, fixture inventory/cleanup, commands/exit codes, evidence limits,
and explicit browser/payment/concurrency completion statements.

No source/config changes means no routine cache rebuild or graph refresh.
Run documentation diff/whitespace checks and verify preserved dirty work.

Report **ACCEPTED FOR ROLLOUT REVIEW** only when all required cases pass and no
critical unknown remains. Source inspection, successful installation, or lint
cannot substitute for an unrun payment or concurrent-capacity case. Otherwise
report PARTIAL/BLOCKED/FAIL and keep 25D blocked.

Do not change application code to fix failures, commit, or execute Task 25D.
Any discovered fix requires a separately approved scope under the user's protected
scheduling/Commerce/no-custom-code constraints.
