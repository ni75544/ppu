# Task 24E — Verify payment evidence and recover interrupted checkout

You are Claude Code CLI in C:/Users/mo737/ppu. Read ../RULES.md and ../README.md. Prerequisite: 24D. No actual gateway was configured in the audit; use local fake payments until separately authorized otherwise.

## Objective and current defects

Close E02/E03/E04/E07/E16. The payment lookup takes the first authorization without checking its amount/currency/expiry. A missing authorization or missing custom pane returns no refusal. Capture happens before a durable settlement claim, failures collapse to booleans, and the public error promises the card was not charged even when remote results may be uncertain.

## Files

- src/Plugin/Commerce/CheckoutPane/DispatchPaymentProcess.php
- src/Plugin/Commerce/CheckoutFlow/DispatchCheckoutFlow.php
- src/Plugin/Commerce/CheckoutPane/DispatchConfirmation.php
- src/Commerce/DispatchCheckoutManager.php; DispatchOrderFactory.php
- src/Checkout/PaidDispatchReference.php; CheckoutState.php; CheckoutAttemptStore.php
- config/install/commerce_checkout.commerce_checkout_flow.dispatch.yml
- services/routing/schema and narrowly scoped gateway-event/recovery integration as required

## Execution

1. Inventory supported gateway capabilities and Commerce lifecycle paths: on-site authorization, off-site return/notification, already-completed payments, partial payments, declined/expired authorization and manual/offline payments. Explicitly reject unsupported paths.
2. Verify trusted payment identity, order, customer/order access, gateway, test/live mode, amount, currency, authorization expiry and accepted snapshot. Never accept a browser success flag or the mere existence of an authorization.
3. Checkout completion must prove the booking outcome. Missing pane/evidence must not silently allow a dispatch order to be placed. Preserve explicit approved offline-payment behavior only if business policy authorizes it.
4. Claim a durable payment operation once, commit locally, then call the gateway outside locks using a stable idempotency key where supported. Persist remote reference and result; on timeout query/reconcile before retrying capture or void.
5. Make signed notifications/verified Commerce payment transitions and browser returns converge on the same idempotent service. Callback completion must work without the originating session. Follow the selected gateway's verification contract.
6. Reconcile unknown captures, failed voids, duplicate/out-of-order callbacks and process crashes. Add a bounded operator retry/status path. Reservation allocation remains synchronous; a background reconciler must not become an alternative booking authority.
7. If authorization succeeds after the hold expires, re-evaluate capacity synchronously or void according to the explicit policy. A captured-but-unbookable result requires visible refund/reconciliation handling; never double-book to hide a payment problem.
8. Replace unconditional “not charged” text with outcome-specific messages. ReconciliationRequired is not settled-paid, even when capacity is reserved.

## Verification and completion

Local fake gateway scenarios: duplicate callback, wrong amount/currency/order, two authorizations, already captured, stale authorization, cancel/confirm race, lost capture response with successful remote result, failed void, browser closed, session lost and delayed return.
Prove at most one effective capture under supported idempotency, durable unknown outcomes, correct reservations and truthful customer/admin messages.
Any missing real-gateway/sandbox acceptance remains a release blocker; do not silently enable a gateway.

## Red lines

No real charges, refunds, live credentials, unsigned callbacks, gateway call inside a lock, “timeout means not charged,” or removal of a confirmed booking solely because the browser disconnected.
