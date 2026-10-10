# Task 23D — Accept the simple flow without losing operational safety

You are Claude Code CLI working after Tasks 23A–23C.

## Objective

Prepare a bounded, evidence-backed acceptance and rollout record for the simple
Courier flow. This does not authorize production activation, payment capture, or
broad configuration import.

## Acceptance matrix

| Scenario | Expected outcome |
| --- | --- |
| Standard Courier Send | Three short screens; no advanced moving fields |
| Courier Receive | Receiver information appears only when needed |
| Store Pickup | Store/order reference appears; quote/schedule remain intact |
| Oversized or multi-person request | Manual Review; no self-bookable Slot |
| Incomplete address | Clear validation; no route/provider call |
| Committed route | One Task 21A development route fact; quote and earliest Slot |
| Date/slot/package change | Reuses route fact; no provider call |
| Route change | Clears quote/offer and requires a committed pair |
| One-driver conflict | Unavailable time is not selectable; next feasible offer shown |
| Checkout confirmation | Private Hold and synchronous capacity confirmation preserved |
| Mobile/accessibility | Primary action, labels, errors, keyboard, and focus are correct |

## Safeguards and reporting

Verify server-side facts, not browser-only behaviour. Do not claim a browser
check unless it actually ran with approved tooling. Do not expose customer/driver
data in evidence. No public hold timer, timezone selector, typing-triggered route
call, live routing call, or payment is allowed during acceptance.

Record form/config version, expected versus observed provider calls, server
validation path, accessibility findings, operator notes, rollback steps, and
blockers. Run only direct checks allowed by root policy. Do not commit or
activate production.

