# Express Courier launch runbook

This runbook takes the Commerce-driven **Dispatch Planner** live as the **sole
authority for new bookings**. After it, a new Express Courier booking is created
only through the Dispatch-owned Webform and the Commerce checkout that authorises
a card, confirms capacity, and only then captures money. The conflicting legacy
public fixed-slot / public-Hold path is already retired for new Dispatch-owned
submissions and inert site-wide; this runbook does not re-enable it and the
[rollback runbook](express-courier-rollback.md) never deletes what it left behind.

It is written for a DDEV working copy (`ddev drush …`). On a production host the
same commands run as `vendor/bin/drush …` from the Drupal root. Nothing here runs
tests, and nothing here deletes historical data.

> **Companion documents.** Background workers, queues, ECA reactions, and Redis
> behaviour are owned by [dispatch-workers.md](dispatch-workers.md); this runbook
> references it rather than repeating it. Terminology (Slot, Hold, Reservation,
> Dispatch Request, Checkout Attempt, Manual Review, Review Request, Replan,
> Resource, Dispatch Window) is defined in [CONTEXT.md](../../CONTEXT.md).

---

## 0. The one capacity authority

There is exactly one authority that writes active variable-duration capacity:

> **Dispatch Planner + DatabaseReservationStore + Resource Calendar Guard.**

`DatabaseReservationStore` is the only class that issues Dispatch capacity SQL,
and every write goes through the Resource Calendar Guard's row locks on
`custom_webform_handlers_dispatch_guard`. No Webform handler, Commerce pane or
subscriber, ECA model, controller, browser behaviour, queue worker, notification
flow, or legacy manager may independently write active capacity. The launch does
not add a second authority, and the checks below exist to prove none has appeared.

---

## 1. Operator prerequisites (blockers — do not launch without these)

| # | Prerequisite | Why launch fails without it |
| --- | --- | --- |
| 1 | **An on-site Commerce payment gateway that supports authorize, capture, and void.** As shipped the site has **zero** payment gateways configured. | The settled-money model authorises, confirms capacity, then captures. An off-site or capture-only gateway cannot do the "authorise now, capture after confirmation" sequence, and no gateway at all means no checkout can complete. |
| 2 | **Google Routes server credential supplied by the environment**, read by `settings.php` through `getenv()` and therefore outside both Git and the configuration export, plus a **browser key** in *Configuration → Web services → Google API Settings*. | The planner prices traffic-aware legs server-side; with no server credential the provider throws `provider_not_configured` and the planner can offer no Slots at all. The two credentials must be different values with different restrictions. Setup, restrictions and the failure ladder are in [Appendix B](#appendix-b--the-server-routes-credential); see also the module [README](../../modules/custom_webform_handlers/README.md#security-notice) for required Google APIs. |
| 3 | **Redis reachable** (or accepted degraded mode). | Redis backs cache/lock bins and read caches only. It is not required for capacity, payment, or the queue, but on this build it is wired into the bootstrap container — see §7. |
| 4 | **Cron scheduled** (drains both Advanced Queue queues). | Notifications, route warming, and the popularity recount run on cron. See [dispatch-workers.md](dispatch-workers.md#how-work-normally-runs). |
| 5 | **Contrib present and enabled:** `commerce_payment`, `commerce_checkout`, `advancedqueue`, and (optional but recommended) `eca` + its event/action providers. | The checkout flow, panes, queues, and reaction layer depend on them. |

Configure the gateway last, in a maintenance window, so no customer reaches a
half-configured checkout. The gateway is the only launch prerequisite that must
be created by hand in the admin UI; everything else is applied by the deploy
below.

---

## 2. Take the rollback point (non-destructive)

Snapshot the database **before** deploying. This snapshot is the rollback point
referenced throughout the [rollback runbook](express-courier-rollback.md); it
predates the deploy, so it can contain no order or Reservation created after
go-live, and restoring it can never delete one.

```bash
ddev export-db --file=../backups/ppu-pre-express-courier-$(date +%Y%m%d-%H%M).sql.gz
```

On production, take a full database dump with the host's standard tooling and
store it off the web root. Record the dump path in the deploy ticket.

---

## 3. Deploy the code

```bash
ddev composer validate --no-check-publish
ddev composer audit
ddev composer install --no-dev --optimize-autoloader
```

`composer validate` and `composer audit` are gates: stop and investigate a schema
error or a flagged advisory before continuing. On production run
`composer install --no-dev` from the release artifact, not an in-place update of a
live tree.

---

## 4. Apply update hooks

All schema and configuration the cutover needs are already carried by the
module's update hooks, the highest of which is
`custom_webform_handlers_update_10026`. Applying updates brings a fresh
environment to that tip. No new hook is introduced by this launch.

```bash
ddev drush updb -y
```

`updb` is authoritative here: it runs each pending `hook_update_N` and any
`hook_post_update_*`, and those hooks are what write the cutover's configuration
(for example the `dispatch_flow` checkout flow set by `update_10020`). Review the
pending list `updb` prints before answering yes.

---

## 5. Configuration import (conditional — read this)

**This site is not managed by configuration import.** Its configuration-sync
directory is the auto-generated `sites/default/files/config_<hash>/sync` and does
not hold an exported copy of the active configuration. Running `drush cim` against
it would import almost nothing and could revert live state.

- **Do run** `ddev drush cim -y` **only** if your deployment genuinely manages
  the active site's configuration through a tracked sync directory. This project
  does not, so the standard verification sequence's `cim` step is intentionally
  skipped and the update hooks in §4 are the configuration authority.
- If your fork *has* adopted config-in-code, import first and then let hooks run.

Either way, the module's own install/optional configuration (checkout flow,
queues, panes) is applied by module install and the update hooks, not by `cim`.

---

## 6. Rebuild caches

```bash
ddev drush cr
```

Run this after every update-hook or configuration change, and again at the end of
the deploy.

---

## 7. Redis

Redis is provisioned for Drupal's cache and lock bins, for the route-matrix and
ranking read caches, and for worker coordination — **never** for queue lifecycle
or for any capacity, payment, or Reservation decision.

- **Local (DDEV).** Redis is wired into this site's bootstrap container. A hard
  stop of the local Redis *service* degrades it to the database fallbacks
  (slower, still correct); removing Redis from `settings.php` entirely while the
  bootstrap still references it causes a white screen. Treat "Redis down" as
  "Redis unreachable," which is the failure mode the fallbacks are written for.
- **Production.** Point the site at the managed Redis endpoint in `settings.php`
  and confirm it is reachable before opening checkout. If it is down at launch,
  the site still books, pays, and confirms capacity — only cache hit-rates and
  read-model warmth suffer.

Full detail: [dispatch-workers.md → what happens when Redis is
down](dispatch-workers.md#what-happens-when-redis-is-down).

---

## 8. Advanced Queue workers

Both queues use Advanced Queue's durable `database` backend (table
`advancedqueue`), so ordinary Drupal cron drains them and there is nothing to
start for a correct launch:

```bash
ddev drush cron
ddev drush advancedqueue:queue:list
```

| Queue | Purpose | Lease | Cron budget |
| --- | --- | --- | --- |
| `custom_webform_handlers_dispatch` | post-commit customer notifications | 300s | 90s |
| `custom_webform_handlers_maintenance` | route warming + popularity recount | 900s | 180s |

For on-demand or supervised processing, and for what each column of
`queue:list` means, follow
[dispatch-workers.md](dispatch-workers.md#running-a-worker-on-demand-the-repeatable-local-command).
The queue is never an authority: a stopped queue delays a notification or a cache
warm, never a booking, a payment, or a capacity decision.

---

## 9. ECA reaction layer

Two read-only reactions enqueue maintenance work after their authoritative
transaction has already committed:

| Domain event | ECA model | Enqueues |
| --- | --- | --- |
| `custom_webform_handlers.dispatch_reservation_confirmed` | `custom_webform_handlers_route_warm` | one route warm for the Resource + local day |
| `custom_webform_handlers.review_request_transitioned` | `custom_webform_handlers_popularity` | one popularity recount |

Confirm the models are present after deploy:

```bash
ddev drush eca:list
```

If ECA is not installed the events are still emitted and `hook_cron` still drives
the popularity recount; only the opportunistic enqueue is absent. The ECA actions
call narrow module interfaces and **cannot** write planner tables, Reservation
rows, Checkout Attempts, or payment entities. Detail:
[dispatch-workers.md → the reaction
layer](dispatch-workers.md#the-reaction-layer-where-jobs-come-from).

---

## 10. Payment gateway and webhook setup

1. **Create one on-site gateway** (test mode first) that supports authorize,
   capture, and void, at *Commerce → Configuration → Payment gateways*.
2. **Confirm the Dispatch checkout flow is `dispatch_flow`** and that its payment
   pane runs with **capture = FALSE**, so a card is authorised (not charged)
   before capacity is confirmed:

   ```bash
   ddev drush config:get commerce_checkout.commerce_checkout_flow.dispatch
   ```

   The Dispatch flow's `dispatch_payment_process` pane authorises, then the flow
   calls `settleAuthorizedCapacity()` before the order is placed, then capture
   runs only after the Resource Calendar Guard has committed. A refused Slot
   voids the authorisation, releases the Hold, places no order, and sends no
   receipt.
3. **Webhook / IPN.** If the gateway posts asynchronous notifications, register
   its webhook endpoint per the payment provider's module and restrict it to the
   gateway's source. The dispatch model does not depend on an async webhook to
   confirm capacity — confirmation is synchronous inside the authorising request —
   but the gateway may still use one for settlement and dispute events.
4. **Go live** on the gateway only after the smoke checks in §12 pass in test
   mode.

---

## 11. Pre-launch verification (prove Dispatch owns the form)

Before opening the site, prove the active homepage Webform is Dispatch-owned and
that no Webform is still in legacy-reservation mode. These are read-only.

```bash
# The Dispatch allowlist. Expected: a single entry, hg.
ddev drush config:get custom_webform_handlers.dispatch workflow.dispatch_webforms

# The Dispatch checkout flow plugin. Expected: dispatch_flow.
ddev drush config:get commerce_checkout.commerce_checkout_flow.dispatch plugin
```

Expected end-state:

- `workflow.dispatch_webforms` lists **only** the Dispatch webform(s) (`hg` as
  shipped). A form on this list attaches the offer-only `dispatch_slots` library
  and no legacy Hold widget; a form not on it that still carries the handler is a
  quote-only form and books nothing.
- The Dispatch webform has `dispatch_enabled = TRUE` and reservation disabled, so
  its handler asserts **no** Hold at submission save — the internal Hold is
  created at checkout start and the Reservation is confirmed by the payment path.
- No Webform is in legacy-reservation mode, so the legacy availability/calendar
  routes are unreachable (they refuse everyone) and the legacy fixed-slot surface
  is inert. Read-only historical administration remains permission-gated.

---

## 12. Smoke checks

Run these against the deployed environment (test-mode gateway) before announcing
launch. Each maps to an acceptance requirement.

- **Express Courier Send / Receive / Store Pickup** each produce a Slot offer and
  a server-priced quote carrying currency and rate version.
- **An over-30-lb package** (or any configured Manual Review trigger) is routed to
  **Manual Review**: it creates no Hold and no Reservation, and it appears in the
  customer's tracker as a Review Request (Submitted → Under Review).
- **A successful payment** authorises, confirms capacity, captures, places the
  order, and immediately shows the Slot as unavailable on the admin board and in
  public availability.
- **A failed / declined payment** places no order, sends no receipt, and leaves
  no Reservation.
- **A cancelled or replayed payment** confirms capacity at most once (no double
  Reservation, no double charge, no second receipt).
- **Two overlapping-window attempts** contend correctly: the Resource Calendar
  Guard confirms one and returns the other to the payment step with "not booked,
  not charged."
- **The public form shows no Hold token, no countdown, and no timezone selector.**
  Public time is the fixed New York local-time label; storage remains canonical
  UTC.
- **The three staff roles** behave as granted: reading the board requires
  *administer dispatch planner*; every mutation requires *manage dispatch
  schedule*.

---

## 13. Failure behaviour (what stays authoritative when something breaks)

This matrix is the operator's reference for a bad day. In every row, no active
capacity is written by anything other than the Dispatch authority, and no paid
order or confirmed Reservation is silently lost.

| Scenario | What the system does | What stays true | Operator action |
| --- | --- | --- | --- |
| **Card authorisation declined** | Parent pane throws out of payment creation before any Payment is saved; capacity is never confirmed; no order placed; no receipt. | No authorisation exists, so no Slot is booked on a declined card. | None — customer retries or picks another time. |
| **Capacity refused after authorisation** (stale Slot / contention) | Manager voids the authorisation, releases the Hold, flow does not place the order; customer sees "not booked, not charged." | No money captured; Slot stays available to whoever holds the guard lock. | None — expected contention outcome. |
| **Capture fails after capacity confirmed** | Reservation is a committed fact; an authorisation remains uncaptured. | The Reservation exists and is paid-intent; money is authorised, not captured. | Capture or void the authorisation in the gateway UI; reconcile against the order. This is the one state that needs a human. |
| **Payment replay / double submit** | `confirmPaid()` is idempotent (keyed reference); notify carries an idempotency key; `allow_duplicates: FALSE`. | Exactly one Reservation, one charge, one receipt. | None. |
| **Route provider (Google Routes) failure** | Traffic-unaware legs are widened by the configured inflation floor; an unmeasurable leg yields **no** Slots rather than a zero-length drive. Quoting is untouched. | The planner never schedules against an under-estimated drive. | Optionally set `routing.require_traffic_aware = true` to refuse traffic-blind legs entirely. |
| **Redis unavailable** | Cache/lock fall back to the database; read caches miss and recompute or use the configured answer; the popularity snapshot is read from DB State. | No booking, payment, capacity, or Reservation depends on Redis. | Restore Redis; caches refill on their own. |
| **Worker / queue outage** | Jobs wait durably in `advancedqueue`; leases reclaim jobs from dead workers; the synchronous path is unaffected. | Notifications are delayed, not lost; capacity and payment already happened synchronously. | Run cron or a worker; see dispatch-workers.md. |
| **Notification failure** | Notify job retries twice then is left visibly failed; the booking already confirmed synchronously. | The Reservation is unaffected; no duplicate is sent on replay. | Re-process the dispatch queue after fixing the cause. |
| **Manual Review** | Request creates no Hold/Reservation and consumes no capacity; staff book it from the admin screen with the same two-step planner transaction. | Capacity moves only when staff book. | Book from *Booking a Manual Review request*. |
| **Replan failure** | Server re-proves the whole affected Resource schedule under locks; if infeasible nothing changes and the refusal explains why; price is copied verbatim. | A failed Replan changes nothing; the original Reservation stands. | Try another time or leave as-is. |
| **Legacy historical claim unresolved** | The conservative `legacy_unplanned` resource-day guard withholds capacity; staff reconcile via the historical-claim form. Legacy rows are retained and labelled. | No new legacy Hold can be created; historical data is untouched. | Reconcile the named claim; never delete the row. |

---

## 14. Monitoring after launch

- **Queues:** `ddev drush advancedqueue:queue:list` — near-zero `Queued`/`Processing`
  shortly after cron, growing `Success`, `Failure` at zero or explained.
- **Payments:** watch for authorisations that never captured (the §13 capture-failure
  row) and reconcile them daily during the first week.
- **Capacity contention:** a rising rate of "not booked, not charged" refusals means
  Slots are being offered faster than they confirm — review Resource profiles and
  offer freshness, not the payment path.
- **Cron:** confirm it is firing (`ddev drush core:requirements`, "Cron maintenance
  tasks"); a stalled cron stalls notifications and read-model warmth only.
- **Status report:** *Reports → Status report* surfaces Google key/rate readiness.

---

## 15. If launch goes wrong

Go to the [rollback runbook](express-courier-rollback.md). It restores service
without deleting paid orders, confirmed Reservations, review records, legacy
tables, or audit history, and it explains how to freeze new bookings without any
code change as the fastest first response.

---

## Appendix A — verification sequence

The project's standard post-deploy verification, with `cim` deliberately omitted
(see §5):

```bash
ddev composer validate --no-check-publish
ddev composer audit
ddev drush updb -y
ddev drush cr
ddev drush status
git diff --check
```

---

## Appendix B — the server Routes credential

The planner needs **two** Google credentials, and they are not interchangeable:

| Credential | Where it lives | Restricted by | Used for |
| --- | --- | --- | --- |
| **Server Routes credential** | the **process environment** of the web container / host, read by `settings.php` through `getenv()` | caller address (application restriction) + Routes API only | server-side traffic-aware leg measurement inside the planner |
| **Browser key** | Drupal configuration, *Configuration → Web services → Google API Settings* | HTTP referrer + the browser-facing Maps/Places APIs | the address autocomplete widget on the public form |

They must be **different values**. A single credential cannot satisfy both
restriction models at once: a referrer-restricted key is refused for a
server-to-server call, and an address-restricted key is refused for a browser
call.

### B.1 Why the server credential is environment-supplied

The value is read from the environment and never written into a tracked file:

- **Not Drupal configuration**, so it cannot be captured by `drush cex` and
  cannot leak through a configuration export artifact.
- **Not an inline literal in `settings.php`**, so rotating it on the cloud side
  actually reaches the runtime, and no working copy carries a live credential.
- **Never committed.** The environment file that supplies it is git-ignored.

Both site variants read the same seam, so an environment-side rotation applies to
each of them the next time PHP starts. See
[config-reproducibility-audit.md](../architecture/config-reproducibility-audit.md)
for the reproducibility consequence: the credential is deliberately the one
runtime input that is *not* reproducible from versioned code, and it is supplied
per environment instead.

### B.2 Setting it up

1. In the cloud console, create a credential restricted to the **Routes API**
   only.
2. Add an **application restriction** listing every egress address the site
   actually leaves from. This is not always the address you expect — a split
   tunnel, VPN, or NAT can present a different address per destination, and the
   refusal does not disclose which address the provider was seen to use. List
   every address you observe, and re-check after any network change.
3. Publish the value into the web container's environment under the name the
   `getenv()` seam in `settings.php` reads. Do not paste it into
   `settings.php`, module configuration, or a Composer or DDEV file that is
   tracked.
4. Restart the containers (or the PHP process) so the new environment is read.
5. Configure the **browser key** separately in the admin UI.

### B.3 Verifying it, in this order

Each step is read-only and none of them prints the value. Stop at the first
failure; a later step cannot pass while an earlier one fails.

**1. Presence.** Ask only whether the variable is set, and read the exit status —
never the value:

```bash
ddev exec sh -lc 'test -n "${CUSTOM_WEBFORM_HANDLERS_SERVER_API_KEY:-}" && printf "PRESENT\n" || { printf "ABSENT\n"; exit 1; }'
```

`ABSENT` means the environment never reached PHP; nothing downstream can work.

**2. Runtime readiness.** The status report resolves both credentials through the
same code the planner uses:

```bash
ddev drush core:requirements --filter='title*=Google'
```

Both the server credential and the browser key should read as configured. A
"missing server credential" verdict here with `PRESENT` above means
`settings.php` is not reading the environment on this site.

**3. A direct provider probe.** Measure one leg between two public landmarks in
both routing modes (traffic-aware and traffic-unaware). Require **HTTP 200**, a
`SUCCESS` status, and a non-empty distance *and* duration in each mode. This is
the step that separates "the credential is present" from "the credential is
accepted for this API from this address."

**4. A read-only availability probe.** Ask the planner for a Slot offer on a
future operating day derived from the Resource's own working hours (never a
hardcoded date), with a fixture that is not a Manual Review trigger. Require an
instant estimate, a distance, a duration, and **at least one Slot**. Compare
whole-database row counts before and after: a read-only probe must leave every
protected family unchanged.

Only after step 4 passes is it meaningful to run the browser acceptance gate.

### B.4 Classifying a failure

Read the failure by its reason, not by the symptom. Every row below produces the
same customer-visible symptom — **no Slots offered** — and each needs a different
fix:

| Reason | What it means | Fix |
| --- | --- | --- |
| `provider_not_configured` | the runtime resolved an empty credential | the environment variable is absent or PHP was not restarted; re-run B.3 step 1 |
| HTTP 403, permission denied | the credential exists but is refused for this call | the application restriction does not list the address the call actually left from, or the credential is not enabled for the Routes API |
| HTTP 400, invalid argument | the request body was rejected | the address field is geocoded, so it needs a street address; a bare coordinate pair is refused |
| HTTP 429 | quota or rate limit | wait, then review quota; the planner widens traffic-unaware legs meanwhile |
| HTTP 200 but an unmeasurable leg | the route could not be measured | expected for unreachable pairs — the planner offers **no** Slot rather than a zero-length drive |
| Slots offered but priced traffic-unaware | traffic-aware measurement failed while the fallback succeeded | see §13; optionally set `routing.require_traffic_aware = true` to refuse traffic-blind legs outright |

A 403 is the only row that points at the credential's restrictions rather than at
the request or the environment.

### B.5 Handling rules and the one limitation

- Never `printenv` the variable, `echo` it, hash it, or copy it into a ticket,
  a QA artifact, or a commit. Presence checks report a word and an exit status.
- Never read the environment file's contents to "check" the value; check only
  whether the variable is set.
- Never introduce a second seam. One `getenv()` read in `settings.php` feeding one
  settings key is the whole contract; a second copy is how two sites end up
  sharing one credential and an environment-side rotation reaches neither.
- Rotate on the cloud side, then restart PHP. No code change is involved.

**Limitation.** The runtime can prove the credential is *present* and *accepted*
(B.3 steps 1–3), and it can prove the server credential and the browser key are
different values. It cannot prove what the credential's restrictions contain —
that requires cloud tooling or console access. Where neither is available, record
restriction verification as **operator-attested** and say so; do not present it as
machine-proven.

### B.6 Before claiming a release: verify on two surfaces

B.3 verifies the credential. A release claim is a bigger question, and it cannot be
answered on the live site alone, for one structural reason: a request the planner
routes to Manual Review writes one advisory diagnostic row by design. Running the
refusal cases against the live site therefore *has* to produce a write, and the only
ways to make that window look clean are to weaken the guardrail or to explain the row
away — both of which destroy the thing you were trying to measure.

Split the question instead:

| Surface | Run | Requirement |
| --- | --- | --- |
| QA (isolated database) | the full valid / unattested / overweight set, both widths | every assertion green; the only advisory rows allowed are exactly the Manual Review category, counted and reported separately |
| Live | the valid eligible flow only, both widths | every assertion green **and** zero movement in every protected family |

Two rules make the split honest. The live-surface wrapper must be *incapable* of
running the refusal cases — assert the scenario list against a valid-flow-only pattern
before opening a browser, so widening it fails loudly. And a movement on the live
surface is never explained away as advisory, cron, expected or harmless: classify it
and stop, or fix it. Housekeeping rows written by Drupal's own cron are named and
counted where they occur, not waived.

The worked example is `docs/releases/2026-09-05-courier-release-attestation.md`.




