# Express Courier — final release attestation

**Date:** 2026-09-05
**Verdict:** RELEASE ATTESTED — COURIER BOOKING READY
**Scope:** Express Courier request → priced estimate → offered Slots, on the commit named in §1.

This document is the release record for one commit on one machine, built from
measurements taken on two surfaces. It states what was proven by a machine, what is
attested by the operator, and what is deliberately out of scope. Nothing here is a
claim about a surface that was not measured, and no gate was relaxed to let a run
pass.

Related records: the operator runbook, `docs/operations/express-courier-launch.md`
(Appendix B covers the server credential), and the reproducibility audit,
`docs/architecture/config-reproducibility-audit.md` (§11.9 records the recovery,
§11.10 the correction that made this attestation necessary).

---

## 1. What is under attestation

| Fact | Value |
| --- | --- |
| Commit | `89814129` — `docs(operations): document server routes credential setup` |
| Branch | `codex-experiments` |
| Active custom module | `custom_webform_handlers`, installed schema version 10040 |
| Drupal core | 10.6.16 |
| Working tree at verification | clean — no staged and no unstaged changes |
| Surfaces measured | the active site and the isolated QA site |

Both sites are served by one Drupal root and one Git working tree; they differ only
by site directory and database. "Same code on both sites" is therefore structural
rather than a coincidence that had to be checked file by file — but the configuration
each site holds in its own database is not, so §3 fingerprints it.

## 2. Verification model — two surfaces, deliberately asymmetric

The refusal scenarios are the reason this is not one surface. A request the planner
routes to Manual Review writes one advisory diagnostic row by design; that is correct
behaviour, not a defect, and it is a write. So the full negative set runs where a
write is acceptable, and the active site is only asked the question a customer
actually asks:

| Surface | Scenarios | Requirement |
| --- | --- | --- |
| QA (isolated database) | full A/B/C set, both widths | every assertion green; advisory rows allowed only if they are exactly the known Manual Review category |
| Active | the valid eligible flow only, both widths | every assertion green **and** zero movement in all 25 protected families |

The active-surface wrapper is incapable of running the refusal scenarios: its
scenario list is asserted against a valid-flow-only pattern before it opens a
browser, so widening it fails loudly instead of quietly turning a read-only gate
into a writing one.

## 3. Configuration and source parity

Compared by structural fingerprint, never by value: each object is canonicalised
(keys sorted, depth first), the value of every key whose *name* is credential-bearing
is replaced before hashing, and only a truncated digest is compared. One object is
known to hold a live browser credential, so a naive whole-object hash of it would
have been a hash of a secret. Per-branch digests localise a difference without
printing the branch.

| Object | Result |
| --- | --- |
| Courier form definition | identical on both sites — same element structure |
| Module settings | identical after credential-name redaction |
| Reservation dashboard view | identical except the per-site UUID branch |
| Schedule-manager role | identical |
| Dispatch configuration | 9 of 10 branches identical; only the resource branch differs |

Inside the resource branch, 11 of 12 keys are byte-identical — capacity, slot grid,
booking horizon, depot, both buffers, return-to-base, timezone, blackout dates and
breaks. The single difference is the working-hours table: the active resource works
one weekday, the QA resource three. That is the documented QA widening, it is the
reason the two surfaces expect different Slot counts, and it is the only parity
difference this release accepts.

Also equal on both sites: the booking-profile allowlist resolves to the single
Courier form, and the Commerce checkout flow resolves to the Dispatch flow.

## 4. QA full-negative acceptance

Six walks — the valid flow, the unattested request and the overweight request, at
desktop and mobile widths — each bracketed individually by the unchanged mutation
sentinel, against the isolated QA site only. The operating day was derived from
Dispatch configuration at run time, not hardcoded.

| Width | Valid flow | Unattested | Overweight | Total |
| --- | --- | --- | --- | --- |
| Desktop | 21/21 | 4/4 | 8/8 | 33/33 |
| Mobile | 21/21 | 4/4 | 8/8 | 33/33 |
| **Both** | | | | **66/66** |

No failed assertion in any walk. The valid flow reached bookable Slots with an
estimate and a successful offer response; the unattested request was refused before
the booking flow, with no Slot widget and no date input in existence; the overweight
request produced zero Slots and a stated Manual Review outcome, refused by the server
as well as by the widget. Across all six walks: no console errors, no
hold/renew/release/countdown/token request, no checkout or payment request, and no
failed Routes authorization.

### 4.1 QA state invariants, and the advisory rows named

Twenty-five protected families were compared across the window: **zero gating
families moved**. No QA business family changed — submissions, drafts, reservations,
holds, orders, order items, payments, checkout attempts, reviews, queue jobs and
flood counters were static throughout.

Two advisory rows were written, and they are reported here separately because Phase 3
allows them only if they are exactly one category:

| Category | Rows | Classification |
| --- | --- | --- |
| Module channel, debug severity, "offer routed to Manual Review" | 2 | exactly the known Manual Review advisory — one per width, from the overweight scenario |
| Module channel, anything else | 0 | — |
| Drupal cron channel — re-run-while-already-running warnings | 15 | Drupal's own concurrency housekeeping |
| Drupal cron channel — hook-bracket and run-completed notices | 17 | Drupal's own housekeeping |
| Error severity or worse, any channel | 0 | — |
| User channel, Webform channel, Routes-authorization failures | 0 | — |

The cron rows were named rather than assumed: the channel used five distinct message
templates in the whole window, every row matched one of the hypothesised core-cron
shapes, and the unnamed residual was zero. These are QA-side housekeeping. They are
not an active production mutation and are not counted as one.

## 5. Active production-surface smoke gate

The valid eligible flow only, at both widths, against the active site — each width
bracketed by the unchanged sentinel, and the whole run bracketed again by the
25-family comparison.

| Width | Assertions | Sentinel |
| --- | --- | --- |
| Desktop | 21/21 | green — no gating count or maximum id moved across 20 probes |
| Mobile | 21/21 | green — no gating count or maximum id moved across 20 probes |
| **Both** | **42/42** | |

What the walks proved on the active surface: the derived operating day was accepted;
**17 bookable Slots** were offered on the active resource's 30-minute grid, none of
them unbookable; exactly one enabled date element, `preferred_service_date`; exactly
one pickup-time heading and exactly one Slot widget; the sidebar showed an estimate
rather than a review, with no Manual Review wording anywhere; the server called the
request schedulable; no console errors; and no hold, reservation, renew, release,
checkout or payment request was made. Nothing was selected, so no capacity was
claimed — Slot availability is evidenced by every offered control being bookable, not
by clicking one.

### 5.1 Routes and availability, measured directly on the active site

Both production questions were asked through the real runtime seam, in the same
bracket as the browser walks:

| Question | Result |
| --- | --- |
| Pricing route, traffic-unaware | HTTP 200, success, distance and duration returned |
| Scheduled route, traffic-aware optimal | HTTP 200, success, distance and duration returned |
| Authorization refusal reasons | all six counters zero — no referer, address, invalid-key, service-disabled, billing or unclassified reason |
| Read-only availability probe | estimate $19.00, 1.53 mi, 718 s, provider `google_routes`, **17** eligible Slots, one date control, no Manual Review |
| Tables touched by the probe | 116 before, 116 after, none added, none changed |

### 5.2 Active protected-family invariant

**Zero changes.** Twenty-five families compared before and after the entire active
window: gating families moved zero, and the advisory families moved zero as well — so
this surface produced no watchdog row of any category, not merely no business row.

Two properties of the active site make that number readable rather than lucky.
Drupal's automated cron is configured with a zero interval there, so an ordinary page
request does not trigger a cron run; and the log table sits at its configured row
limit, so watchdog probes are scored on maximum row identifier, under which an insert
is always visible and only Drupal's own eviction is not.

## 6. Release dependency checks

| Check | Result |
| --- | --- |
| `composer validate` | `./composer.json is valid` |
| `composer audit --locked` | no security vulnerability advisories found |
| Pending database updates, active site | none required |
| Pending database updates, QA site | none required |
| `git diff --check`, `git diff --cached --check` | clean, both |
| Working tree before this document was written | clean — zero entries |

## 7. Credential facts — machine-proven versus operator-attested

The Courier planner prices legs server-side with a Google Routes credential that is
supplied by the process environment and read through a `getenv()` seam, so it is
outside both Git and the configuration export. Appendix B of the runbook is the
operator-facing version of this; what follows is the evidence status.

**Machine-proven:**

- the environment variable is present — established by exit status alone, never by
  printing, reading, hashing or copying the value;
- both sites resolve the credential through the environment seam, and no
  key-shaped literal remains in versioned code or in the configuration export;
- the server credential and the browser credential are different values — proven by
  a boolean equality refusal that reads neither;
- the credential is authorized for server-side Routes calls from this caller, in both
  routing modes, with every refusal-reason counter at zero (§5.1).

**Operator-attested, not machine-verified:**

- the *contents* of the credential's restrictions — which caller addresses the
  application restriction lists, and that the API allowlist is Routes only. The
  cloud CLI is unavailable in this environment and no console read was performed, so
  this document does not claim to have verified them.

No credential value, prefix, length, hash, or identifier appears in this document, in
the runbook, in the audit, or in any artifact produced by this verification.

## 8. Configuration reproducibility

**REPRODUCIBLE**, unchanged from the audit's Task 18B verdict: every configuration
object the booking path depends on is backed by versioned module code or an update
hook, proven by rebuilding onto a throwaway database. The server credential remains
the one runtime input deliberately *not* reproducible from versioned code, which is
the point of supplying it from the environment.

## 9. Known limitations

1. **External key restrictions are operator-attested** (§7). This is the one gap
   between "the credential works" and "the credential is correctly locked down".
2. **The active surface was smoke-tested with the valid flow only.** The refusal
   behaviours are proven on QA, because proving them writes an advisory diagnostic
   row by design. A future change to refusal logic must be re-proven on QA; it is
   not covered by the active gate.
3. **The two surfaces work different weekdays** (§3), so their Slot counts differ by
   design — 17 on the active resource, 19 on QA. A count comparison across surfaces
   is meaningless; each surface is compared against its own configuration.
4. **Watchdog families are scored on maximum row identifier** on the active site,
   because the log is a capped ring buffer. Inserts remain visible; rows leaving
   under Drupal's own eviction do not gate.
5. **The first active A/B/C window of the preceding task did not meet raw
   no-mutation criteria.** Its two movements were classified rather than waived, and
   no claim in this attestation rests on that window — the two-surface model in §2
   exists precisely because of it.

## 10. Verdict

**RELEASE ATTESTED — COURIER BOOKING READY**, for the commit in §1, on the evidence in
§3–§6, subject to the limitations in §9.

A customer can complete an Express Courier request on the active site: the request is
priced from a live traffic-aware route, bookable Slots are offered on the resource's
own grid, one date control governs the booking, and neither the browser nor the server
routes a valid request to Manual Review. Genuinely ineligible requests are refused —
proven on QA at both widths, on both the client and the server side. The active
verification moved nothing: no submission, draft, reservation, hold, order, order
item, payment, checkout attempt, review, queue job, flood counter, session or log row
of any category.

Feature work is closed. Adding to it requires a new change request.





