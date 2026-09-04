# Task 3 — Add the Commerce order model and Checkout Attempt seam

You are Claude Code CLI executing Task 3 for the Drupal 10 Express Courier and
Commerce roadmap in `C:\Users\mo737\ppu`. Task 2's contrib modules, Redis service,
database updates, and `BookingWorkflowGate` must be complete. The Task 2 transfer
allowlist is deliberately empty; no public Webform is yet allowed to start checkout.

## Objective

Connect Drupal Commerce to the existing deep Dispatch Planner without letting
Commerce, Webform, JavaScript, or a controller become a second scheduling authority.
Introduce the private Checkout Attempt lifecycle needed to claim capacity only when
payment begins.

No public flow changes in this task.

## Mandatory capability and Drupal-reuse gate

Before implementation, complete the Capability Ledger and Drupal Reuse Decision
Matrix required by the roadmap README for this task. In particular, inspect and
reuse Commerce's order, order-item, checkout-flow, payment, customer-profile, and
state-machine facilities before adding custom storage. Record why the existing
Dispatch Planner, Resource Calendar Guard, and `BookingWorkflowGate` remain the
authoritative modules at their respective seams. Do not create a parallel order,
hold, or workflow implementation.

## Existing code to retain

- `DispatchPlannerInterface`, `DispatchPlanner`, and its policy/route/schedule seams.
- `ReservationStoreInterface`, `DatabaseReservationStore`, and Resource Calendar
  Guard row locking as the only variable-duration contention authority.
- `DispatchRequestFactory` as the normalizer of Webform/customer facts.
- `BookingWorkflowGate` as the sole answer to whether a Webform may use the
  dispatch workflow.
- Existing quote calculation and traffic-aware route support.
- Historical fixed-slot and Dispatch rows. Do not delete or rewrite history.

## Target files

Create or modify only coherent files under:

```text
modules/custom_webform_handlers/src/Checkout/
modules/custom_webform_handlers/src/Commerce/
modules/custom_webform_handlers/src/Dispatch/
modules/custom_webform_handlers/src/Dispatch/Storage/
modules/custom_webform_handlers/config/install/
modules/custom_webform_handlers/config/schema/
modules/custom_webform_handlers/custom_webform_handlers.install
modules/custom_webform_handlers/custom_webform_handlers.services.yml
modules/custom_webform_handlers/custom_webform_handlers.info.yml
```

## Required design

1. Add a Commerce order type `dispatch` and order-item type `dispatch_service`.
   Dynamic authoritative prices are copied into the order item as locked price
   snapshots; the browser never sets an order amount.
2. Link each dispatch Commerce order to its originating Webform submission/Dispatch
   Request using a server-owned field or module schema. Do not store raw request
   payloads as arbitrary serialized PHP.
3. Add an immutable `CheckoutAttempt` value object and a small
   `DispatchCheckoutManagerInterface` with only these use cases:

   ```text
   start(request, selected Slot, contact) -> checkout/order result
   confirmPaid(order reference) -> Reservation result
   cancel(order reference) -> cancellation result
   ```

4. Persist the private Checkout Attempt durably; a value object alone is not a
   lifecycle. Reuse Commerce order fields/state where they provide the required
   association and auditability. Add a narrow custom table only if the Reuse
   Decision Matrix proves Commerce cannot enforce the required uniqueness/indexes.
   Store a canonical order reference, immutable selected-Slot/quote snapshot,
   dispatch reference, lifecycle state, and idempotency facts—not duplicated raw
   contact details or arbitrary serialized request payloads.
5. Implement one adapter that composes the existing planner/store with Commerce.
   `start()` must consult `BookingWorkflowGate` itself and fail with no order, Hold,
   or Reservation effect unless the request's concrete Webform is assigned
   `dispatch`; it may not trust a controller or caller to have checked first. It may
   create an internal held Dispatch row when payment starts. It must not publish
   Hold expiry, token, owner hash, internal window, or Reservation ID.
6. Extend the dispatch reservation schema through an idempotent update hook with a
   nullable Commerce order reference and indexes/uniqueness needed for idempotent
   payment processing. Preserve the existing submission reference and history.
7. Model states explicitly: Checkout Attempt `pending`, `paid`, `failed`, or
   `cancelled`; Dispatch capacity remains `held`, `confirmed`, or historical. A
   pending/failed order is never presented as a Reservation. `cancel()` must not
   release or modify a paid/confirmed attempt.
8. Replace direct Webform-submission coupling at the planner confirmation seam with
   a narrow, immutable paid-dispatch reference containing only canonical order ID,
   submission ID, and audit facts. A Commerce event subscriber will use it in Task
   6; do not subscribe yet.
9. Do not let Commerce order state, ECA state, or a payment-return URL decide
   capacity. The store transaction remains authoritative.

## Verification

- Do not create or run automated tests of any kind. PHP-lint every touched PHP
  file and inspect the new manager interface/adapter call paths directly.
- Run:

```bash
ddev drush updb -y
ddev drush cr
ddev drush pm:list --status=enabled
```

Use Drush or safe SQL metadata inspection to confirm new columns/indexes and the
private lifecycle storage exist; do not display customer rows. Confirm the container
builds, existing quote/dispatch routes still resolve, and an empty Task 2 dispatch
allowlist prevents any public checkout start. Stop before adding Courier fields or
changing the homepage.
