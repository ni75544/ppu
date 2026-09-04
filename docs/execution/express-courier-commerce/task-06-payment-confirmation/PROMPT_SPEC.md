# Task 6 — Build Steps 3–4: contact, payment, and atomic confirmation

You are Claude Code CLI executing Task 6 for the Drupal 10 Express Courier and
Commerce roadmap in `C:\Users\mo737\ppu`. Tasks 1–5 must be complete. Task 3
confirmed that `commerce_checkout` is installed but not enabled and that the
dispatch order type has no checkout-flow configuration. This task owns enabling and
configuring that native Commerce facility before exposing Step 3.

## Objective

Complete the checkout journey through Drupal Commerce's native checkout-flow and
payment-plugin facilities. Step 3 collects contact details and starts payment for
the selected server-derived quote/Slot. Step 4 is shown only after an authoritative,
idempotent payment-success transition synchronously confirms the Reservation.

This is the critical money/capacity task. Do not delegate confirmation to Advanced
Queue, Redis, ECA, cron, JavaScript, or the payment-return page.

## Target files

```text
modules/custom_webform_handlers/src/Checkout/
modules/custom_webform_handlers/src/Commerce/
modules/custom_webform_handlers/src/Dispatch/
modules/custom_webform_handlers/src/Dispatch/Storage/
modules/custom_webform_handlers/src/Plugin/WebformHandler/AddressDistanceHandler.php
modules/custom_webform_handlers/src/Controller/
modules/custom_webform_handlers/config/install/
modules/custom_webform_handlers/config/schema/
modules/custom_webform_handlers/custom_webform_handlers.install
modules/custom_webform_handlers/custom_webform_handlers.services.yml
modules/custom_webform_handlers/custom_webform_handlers.routing.yml
modules/custom_webform_handlers/custom_webform_handlers.permissions.yml
modules/custom_webform_handlers/js/ and css/ for Steps 3–4 only
```

## Mandatory capability and Drupal-reuse gate

Before implementation, complete the Capability Ledger and Drupal Reuse Decision
Matrix required by the roadmap README for this task. Inspect and configure the
installed Commerce checkout-flow plugin, checkout panes, payment gateway plugin,
payment-state transition, order fields, and customer-profile facilities before
writing custom code. Record the selected plugin IDs/config entities, why direct
order checkout does or does not require `commerce_cart`, and why the existing
`DispatchCheckoutManagerInterface`, Resource Calendar Guard, and
`BookingWorkflowGate` remain the authoritative modules at their seams.

## Required transaction flow

1. Enable `commerce_checkout` and configure a Commerce checkout flow for the
   `dispatch` order type. Reuse its supported pane system and order checkout route;
   do not build a parallel checkout controller or accept browser-supplied totals.
   Do not enable `commerce_cart` unless the selected, documented Commerce path
   actually requires it—Task 3 creates a dispatch order directly rather than a
   cart. Add an idempotent update/config migration so existing installations receive
   the checkout configuration; `config/install` alone is for fresh installs.
2. Model Step 3's contact facts without inventing billing data. Use the Commerce
   order email field for email and typed dispatch order fields for name and phone
   when a configured Commerce pane cannot store them. Do not create a name-only
   billing Address profile or fabricate a country/address merely to satisfy an
   Address field. Store no timezone choice and no arbitrary serialized Webform
   payload.
3. Configure the selected payment gateway through Commerce's plugin/configuration
   mechanism. Use a local non-live gateway only for manual verification, never
   commit gateway credentials, and document the production operator configuration
   separately from source-controlled configuration.

4. On “Continue to payment,” rebuild the Dispatch Request and quote server-side,
   verify the selected Slot is still canonical, and create/update the Commerce order
   with the authoritative locked price snapshot.
5. Immediately before handing control to the payment gateway, create a private
   Checkout Attempt and internal Hold through `DispatchCheckoutManagerInterface`.
   The manager's own `BookingWorkflowGate` check is mandatory; a controller must
   not be relied upon to establish dispatch ownership. No public response may
   include Hold duration, expiry, token, or timer data.
6. Subscribe to the authoritative Commerce payment success/state event. Treat the
   browser return URL only as navigation; it cannot confirm payment or capacity.
7. On payment success, synchronously call `confirmPaid(order reference)`. Inside the
   existing Resource Calendar Guard transaction, verify the internal Hold, rebuild
   feasibility, transition exactly one dispatch row to confirmed, attach the order
   and submission references, and commit.
8. Make webhook/event replay idempotent. Repeating success for the same paid order
   must return the same confirmed result and never create a second Reservation.
9. On payment failure/cancel, release only that Checkout Attempt's internal Hold and
   mark the attempt failed/cancelled. Do not release a confirmed Reservation.
10. Account for the gateway/database transaction gap. Prefer authorization followed
   by capture after confirmation when the chosen gateway supports it. If a captured
   payment cannot be confirmed, retain the Checkout Attempt's `paid` state and set
   a durable reconciliation-required fact (do not overload `failed` or silently
   report success). Provide an explicit void/refund/admin path.
11. Invalidate public availability cache tags synchronously after commit. Enqueue
   admin/customer notifications only after commit; notification failure cannot roll
   back a paid Reservation.
12. Remove Webform-save confirmation as the new-flow authority. Saving the Webform
   records a Dispatch Request/contact facts; it does not create a Reservation.
13. Confirmation page content is loaded from the customer's own order/Reservation
    access check and shows order reference, service, pickup promise, and next steps.
    Do not expose internal IDs or full public schedule state.

## Verification

Do not create or run automated tests. PHP-lint touched files and directly inspect
the transaction/error paths for two competing Checkout Attempts, idempotent payment
success, failed/cancelled release, stale/lost Holds, and post-commit notification
failure. Then run:

```bash
ddev drush en commerce_checkout -y
ddev drush updb -y
ddev drush cr
```

Using a local Commerce test payment gateway, manually exercise success, failure,
back-button/replay, and two-session contention. Verify no public countdown appears
and the admin board changes immediately on confirmed payment. Verify the checkout
form contains name, phone, email, and payment fields but no timezone selector or
invented billing address. Stop before Manual Review/RBAC work.
