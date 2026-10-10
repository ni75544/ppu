# Task 24J — Make notifications and operational reconciliation durable

You are Claude Code CLI in C:/Users/mo737/ppu. Read ../RULES.md and ../README.md. Prerequisites: 24D-24F.

## Objective and current defects

Close E16/E17. Notification enqueue errors are caught and logged after settlement, with no durable event ensuring a later retry. A process crash can leave a valid reservation with no confirmation notice. Reconciliation and staff visibility must reflect money and capacity separately and preserve role boundaries.

## Files

- src/Dispatch/Notify/QueueDispatchNotifier.php; DispatchNotificationDispatcher.php
- src/Dispatch/Notify/DatabaseNotificationClaim.php; DatabaseNotificationLog.php
- src/Plugin/AdvancedQueue/JobType/DispatchNotifyJob.php
- src/Commerce/DispatchCheckoutManager.php; src/Event/DomainEventEmitter.php
- src/Controller/DispatchOperationsController.php; DispatchPlannerAdminController.php
- src/Dispatch/DispatchBoardReader.php
- src/Plugin/views/access and src/Plugin/views/field protected projections
- src/Controller/ReviewTrackController.php; src/EventSubscriber/ReviewTrackerCacheSubscriber.php
- permissions/routing/services/schema/update hooks as needed

## Execution

1. Save a minimal durable notification intent with the successful business transaction from 24D. Use a small outbox or equivalent existing transactional record; do not enqueue or send inside the calendar lock.
2. Let the existing queue publish/deliver those intents after commit. Make publication retries idempotent and track pending, delivered, retryable and terminal failure with stable event identities.
3. Preserve recipient privacy and existing deduplication. A confirmed event, later replan and cancellation are distinct ordered facts. Do not deduplicate away a genuine amendment.
4. Define bounded operator views for unknown capture, failed void/refund, pending attempt without valid hold, unlinked reservation/order, stale review pointer and notification backlog.
5. Separate schedule state from payment state; ReconciliationRequired must not appear as Paid in projections or totals. Operators need an actor/reason audit trail for each recovery action.
6. Audit RBAC at routes, service actions, Views fields and cache contexts: Dispatch Viewer, Schedule Manager and Financial/Customer Info Viewer must see only authorized data. A schedule permission must not implicitly authorize refunds or reveal customer contact/prices.
7. Check tracking-code entropy, hashing, expiry/revocation, rate limits, referrer leakage and cache isolation. Keep raw codes/addresses/credentials out of queue evidence and logs.
8. Add useful low-cardinality operational metrics: confirmation conflicts, orphan/recovery states, oldest outbox age and provider-dispatch count. Prefer existing logs/status pages; no new monitoring stack.

## Verification and completion

Simulate queue unavailable at commit, crash before publication, duplicate delivery and retry exhaustion with local fakes. Reservation/payment state stays unchanged and notification delivery eventually recovers or appears as actionable failure.
Manually verify role/access and cached-view separation; test review-code misuse without exposing codes in reports.
Check reconciliation actions are permissioned and idempotent. Run permitted syntax/schema/Drush/diff checks.

## Red lines

No queue-controlled final booking, PII/secret-rich logs, full payloads in public status, mass email, unapproved infrastructure or dependency installation. “Logged an error” is not durable recovery.
