# Task 23C — Add progressive details and a clean Manual Review branch

You are Claude Code CLI working after Task 23B. Do not begin until the minimal
Courier flow is accepted and Task 23A identifies remaining required operational
facts.

## Objective

Keep the fast customer path small while reliably collecting facts dispatch needs
for special cases.

## Required interaction policy

| Request | Public details |
| --- | --- |
| Courier Send | Item type/weight; instructions only when required |
| Courier Receive | Receiver/contact and instructions when required |
| Store Pickup | Store/order reference and receiving contact |
| Oversized, multi-person, specialty, unmapped access | Manual Review, not a longer instant-booking form |

Use existing Webform conditional visibility and server validation. Hiding a
field must not bypass eligibility, duration, pricing, or dispatch policy.

## Constraints

- Do not duplicate the same fact in JavaScript, Webform, and PHP. Keep one
  machine field and one server-side policy consumer per fact.
- Do not expose capacity, other bookings, hidden price inputs, or internal review
  reasons.
- Optional instructions must not block an ordinary Courier quote.
- Do not introduce a public timer, timezone selector, external route call, or
  extra payment step.
- Preserve review tracking: Submitted → Under Review → Approved → Scheduled.

## Verification

Prove each delivery mode exposes only its required details; bad/missing special
case facts lead to Manual Review; and the ordinary Courier path remains short.
Verify server policies receive unchanged canonical machine values. Run only root
policy's allowed source/YAML/lint/cache/Drush/diff checks. No suites, browser
automation, live routing, payments, or commits.

