# Express Courier rollback runbook

This runbook restores service if the Commerce-driven Dispatch launch goes wrong.
Its first rule is the one it never breaks:

> **Rollback never deletes paid Commerce orders, confirmed Dispatch Reservations,
> Review Request records, legacy fixed-slot rows, legacy tables, or audit
> history.** It withdraws new behaviour; it does not erase what already happened.

It is written for `ddev drush …`; on production the same commands run as
`vendor/bin/drush …` from the Drupal root. It pairs with the
[launch runbook](express-courier-launch.md) and defers queue/ECA/Redis behaviour
to [dispatch-workers.md](dispatch-workers.md). Terminology is defined in
[CONTEXT.md](../../CONTEXT.md).

---

## 0. Choose the smallest rollback that works

Roll back in tiers, smallest first. Most launch problems are resolved at Tier 1
without touching code or data.

| Tier | Use when | Reverses |
| --- | --- | --- |
| **1 — Freeze new bookings** | The platform is healthy but you must stop *new* Dispatch bookings (a pricing error, a gateway problem, a capacity miscalculation). | Configuration only; no code, no data. |
| **2 — Revert module code** | A defect is in this release's code and the previous release was sound. | Code to the previous tag; data untouched. |
| **3 — Restore the pre-deploy snapshot** | The database itself is wrong and no code revert repairs it. | Everything, back to the §2 snapshot in the launch runbook. Last resort. |

Tiers 1 and 2 are non-destructive by construction. Tier 3 is non-destructive only
if you follow §4 exactly, because a naive restore over a live database discards
orders taken after the snapshot.

---

## 1. Tier 1 — Freeze new bookings without a code change

The fastest, safest response. Removing the Dispatch webform from the allowlist
returns it to the untransferred state: the `BookingWorkflowGate` decides
`Disabled` (never a legacy fallback), the offer-only widget stops attaching, and
the booking routes refuse new submissions — while **read-only historical
administration stays available** and **every existing Reservation, order, and
review record is untouched**.

```bash
# Inspect the current allowlist (expected: hg).
ddev drush config:get custom_webform_handlers.dispatch workflow.dispatch_webforms

# Freeze: set the allowlist to empty.
ddev drush config:set custom_webform_handlers.dispatch workflow.dispatch_webforms '[]' -y
ddev drush cr
```

To freeze payments instead of the whole form, put the on-site gateway into test
mode or disable it in *Commerce → Configuration → Payment gateways*; checkout then
cannot authorise, so no new Reservation is confirmed, and nothing already
confirmed is affected.

**Do not** re-enable any legacy public booking path as a substitute for freezing.
The legacy fixed-slot / public-Hold surface is retired deliberately; turning it
back on would restore a second capacity writer. Freezing is the correct fallback,
not reactivation.

To resume, restore the allowlist:

```bash
ddev drush config:set custom_webform_handlers.dispatch workflow.dispatch_webforms '["hg"]' -y
ddev drush cr
```

---

## 2. Tier 2 — Revert the module code

When the fault is in this release's PHP/JS and the previous release was sound.

```bash
# Deploy the previous release artifact / tag, then:
ddev composer install --no-dev --optimize-autoloader
ddev drush cr
```

Rules for a code revert:

- **Do not run destructive down-hooks.** Reverting code does not require dropping
  tables or unwinding schema. The update hooks through
  `custom_webform_handlers_update_10026` created tables and configuration; leave
  them in place. A previous release simply does not use anything newer, and the
  extra tables hold data you must keep.
- **Leave the database as it is.** Confirmed Reservations, Checkout Attempts,
  orders, and review records created under the new release remain valid and
  readable; the previous release reads its own subset.
- If the previous release predates a schema column a live row depends on, prefer
  Tier 1 (freeze) over forcing an incompatible code downgrade, and escalate.

After a code revert, re-run the §5 verification.

---

## 3. Tier 3 — Restore the pre-deploy snapshot (last resort)

Only when the database is itself wrong. The snapshot taken in
[launch runbook §2](express-courier-launch.md#2-take-the-rollback-point-non-destructive)
is the restore point.

```bash
ddev import-db --file=../backups/ppu-pre-express-courier-YYYYMMDD-HHMM.sql.gz
ddev drush cr
```

**The paid-order guarantee, stated plainly.** The snapshot predates the deploy,
so it contains no order or Reservation created after go-live. Importing it does
not *delete* those rows from history — it replaces the current database with an
earlier one. Any order taken between the snapshot and the rollback therefore
exists **only** in:

1. the payment gateway's own records, and
2. the current (about-to-be-replaced) database.

So before a Tier 3 restore you **must** preserve post-snapshot financial data:

```bash
# Capture the current database first — this is the record of everything
# that happened during the failed launch. Never overwrite it.
ddev export-db --file=../backups/ppu-failed-launch-$(date +%Y%m%d-%H%M).sql.gz
```

Then restore the snapshot, and reconcile any post-snapshot paid orders from the
gateway and the preserved dump by hand. Never treat Tier 3 as a way to make
unwanted orders disappear: the money moved, and the customer is owed either the
service or a refund through the gateway.

---

## 4. Data you must never delete

These tables are owned by this module and every one of them holds data a rollback
must preserve. **No rollback tier drops any of them.**

| Table | Holds | Never do |
| --- | --- | --- |
| `custom_webform_handlers_reservation` | Legacy fixed-slot reservations (historical) | Drop or truncate |
| `custom_webform_handlers_dispatch_reservation` | Confirmed Dispatch Reservations | Drop, truncate, or delete confirmed rows |
| `custom_webform_handlers_dispatch_guard` | Resource Calendar Guard locks/occupancy | Drop |
| `custom_webform_handlers_dispatch_blackout` | Closures | Drop |
| `custom_webform_handlers_dispatch_status` | Dispatch status records | Drop |
| `custom_webform_handlers_dispatch_notification` / `_dispatch_notify_claim` | Notification + idempotency records | Drop |
| `custom_webform_handlers_checkout_attempt` | Checkout Attempts / internal Holds | Drop or delete rows tied to orders |
| `custom_webform_handlers_review_request` | Manual Review / Review Request records | Drop or delete |

Also off-limits to any rollback: Commerce's own `commerce_order` and
`commerce_payment` tables (paid orders and payments), Webform submission storage,
and the `advancedqueue` job table. Freeing capacity, when needed, is done through
the admin *release* action, which **retains and marks** the row rather than
deleting it — because the later question is not only what is booked but what was.

---

## 5. Payment reconciliation during rollback

The settled-money model makes rollback financially clean, because capture happens
only *after* capacity is a committed fact:

- **Authorised but not captured** (a launch stopped mid-checkout, or the §13
  capture-failure state in the launch runbook): the money was never taken. **Void**
  the authorisation in the gateway UI; the Hold expires or is released. No refund
  is owed because no charge occurred.
- **Captured with a confirmed Reservation:** a completed, valid booking. Do not
  delete it in a rollback. If the booking must be undone, **release** the
  Reservation through the admin action (which retains the row) and **refund**
  through the gateway — money out the same way it came in.
- **Never** resolve a payment discrepancy by deleting an order or a Reservation
  row. The gateway is a second book of record; the two must agree, and deleting a
  row hides a charge rather than reversing it.

Reconcile authorisations first (safe to void), captures second (refund, never
delete).

---

## 6. Queue, ECA, and Redis during rollback

None of these is an authority, so none blocks a rollback:

- **Queues** hold only post-commit convenience work (notifications, cache warming).
  A pending or failed job cannot have changed a Reservation, a payment, or public
  availability. You may let cron drain them or leave them; nothing needs replaying
  for correctness. See [dispatch-workers.md](dispatch-workers.md#replay-and-idempotency).
- **ECA** reactions fire only after their authoritative transaction commits and
  call read-only interfaces; disabling ECA during rollback loses only the
  opportunistic enqueue, and `hook_cron` still drives the popularity recount.
- **Redis** holds no authority. If you restore a snapshot, flush caches
  (`ddev drush cr`) so no stale read cache survives the restore.

---

## 7. Post-rollback verification

```bash
ddev drush status
ddev drush config:get custom_webform_handlers.dispatch workflow.dispatch_webforms
ddev drush advancedqueue:queue:list
git diff --check
```

Confirm:

- The allowlist reflects the intended state — empty if you froze at Tier 1,
  `["hg"]` if you resumed or reverted code without freezing.
- Existing Reservations and orders are still present and readable (spot-check the
  admin board and a known paid order).
- Queues are draining and `Failure` is zero or explained.

---

## 8. What a rollback must never do

- Delete paid Commerce orders, confirmed Dispatch Reservations, Review Request
  records, legacy fixed-slot rows, legacy tables, or audit history.
- Drop or truncate any table in §4.
- Re-enable a legacy public booking path, public Hold tokens, renewal,
  release-on-unload, expiry data, or a countdown UI as a substitute for freezing.
- Expose a timezone selector; public time stays New York local and storage stays
  canonical UTC.
- Let any legacy, queue, or ECA path write active Dispatch capacity.
- Change the active `hg` Dispatch assignment except as an explicit, documented
  step of the rollback you are performing (Tier 1 freeze).
- Resolve a payment mismatch by deleting a row instead of voiding or refunding
  through the gateway.
