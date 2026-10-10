# Task 24K — Run the integrity release gate before simplifying the form

You are Claude Code CLI in C:/Users/mo737/ppu. Read ../RULES.md and ../README.md. Prerequisite: accepted evidence for 24A-24J, including 24H.

## Objective

Turn the preceding fixes into a bounded, repeatable acceptance record. This task verifies the system; it does not authorize another refactor or a production release.

## Files and artifacts

- Read all prior Task 24 EVIDENCE.md files and the actual changed module files.
- Write docs/execution/system-integrity-audit/task-24k-release-acceptance/EVIDENCE.md.
- Write a short RELEASE_CHECKLIST.md and remaining-blocker list in this task directory.
- Application edits are limited to a separately identified/reproduced defect within the approved task scope; otherwise return it to its owning task.

## Execution and acceptance matrix

1. Baseline: exact source state, active configuration, required schema/indexes, effective fixed-route guard, single-resource policy, enabled checkout flow and local fake/supported gateway capabilities.
2. Concurrency: two customers claim one window; same submission/different windows; staff/public race; renewal vs expiry/new booking; confirm vs cancel; confirm vs blackout; simultaneous replans; cross-midnight acquisition.
3. Money: snapshot round-trip, modifier totals, currency/amount mismatch, changed job between hold and payment, duplicate authorization/capture notification, ambiguous capture, failed void and unpaid staff booking.
4. Recovery: close browser before/after authorization, lose session, crash before/after each local commit boundary, lose provider/payment response, expire hold during payment, queue outage and out-of-order notifications.
5. Timeline: depot/neighbor deadhead, loading/unloading, breaks, NYC buffers, end-of-shift return, first/last job, exact adjacency, daylight-saving transitions, unavailable driver and legacy calendar coexistence.
6. Customer flow: address selection and field fill, required-field validation, pricing changes, dates/slots, wizard/AJAX rebuild, two tabs and helpful empty/error/recovery states.
7. Security: cross-session access, anonymous CSRF/rate limits, malformed JSON, role/Views/cache isolation and tracking-code handling.
8. Cost: count outbound provider dispatches over every path, including route warming and staff tools. The development count must be zero.
9. Cleanup: remove only task-created probe files/records by exact validated IDs/paths, preserve sanitized evidence, run allowed lint/schema/cache/diff checks and report remaining dirty files.

Use direct local probes and manual browser checks only; no generated or executed automated suites. Concurrent probes must use independent DB connections and deterministic barriers, not merely two sequential calls. Do not use real cards or actual external routes.

## Completion criteria

- For each scenario list expected invariant, observed result, exact command/manual steps and PASS/FAIL/BLOCKED/PENDING.
- Zero overlapping live reservations for one physical driver; one active job intent; no silently inconsistent snapshot/order/payment references.
- No live-provider request in development or inside locks; no duplicate effective payment operation under the supported integration; ambiguous results remain recoverable and visible.
- All P0 findings are verified fixed. Any unexecuted concurrency/browser/gateway scenario remains explicitly unaccepted.
- Record query counts and lock durations for the small-driver workload; index changes require measured evidence, not guesswork.
- Recommend whether 23A+ simplification may resume. Use the existing quote/booking contract as its boundary; UI simplification must not reopen integrity issues.

## Red lines

No production cutover, real payment/API experiment, commit, broad cleanup or claim of full acceptance based only on lint, cache rebuild, source searches or graph counts.
