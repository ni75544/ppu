<?php

namespace Drupal\custom_webform_handlers\Commerce;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsAuthorizationsInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsVoidsInterface;
use Drupal\commerce_price\Calculator;
use Drupal\commerce_price\Price;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Transaction;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\custom_webform_handlers\Checkout\CheckoutAttempt;
use Drupal\custom_webform_handlers\Checkout\CheckoutAttemptStore;
use Drupal\custom_webform_handlers\Checkout\CheckoutContact;
use Drupal\custom_webform_handlers\Checkout\CheckoutOutcome;
use Drupal\custom_webform_handlers\Checkout\CheckoutState;
use Drupal\custom_webform_handlers\Checkout\DispatchCheckoutManagerInterface;
use Drupal\custom_webform_handlers\Checkout\PaidDispatchReference;
use Drupal\custom_webform_handlers\Checkout\QuoteSnapshot;
use Drupal\custom_webform_handlers\Dispatch\Admission\BookingAdmissionStoreInterface;
use Drupal\custom_webform_handlers\Dispatch\BookingWorkflow;
use Drupal\custom_webform_handlers\Dispatch\BookingWorkflowGate;
use Drupal\custom_webform_handlers\Dispatch\DispatchOwner;
use Drupal\custom_webform_handlers\Dispatch\DispatchCheckoutPlannerInterface;
use Drupal\custom_webform_handlers\Dispatch\DispatchReason;
use Drupal\custom_webform_handlers\Dispatch\DispatchRequestFactory;
use Drupal\custom_webform_handlers\Dispatch\Hold;
use Drupal\custom_webform_handlers\Dispatch\Notify\DispatchNotification;
use Drupal\custom_webform_handlers\Dispatch\Notify\DispatchNotifierInterface;
use Drupal\custom_webform_handlers\Dispatch\PreparedHold;
use Drupal\custom_webform_handlers\Dispatch\ReservationResult;
use Drupal\custom_webform_handlers\Dispatch\ResourceProfileFactory;
use Drupal\custom_webform_handlers\Dispatch\Slot;
use Drupal\custom_webform_handlers\Dispatch\Storage\ReservationStoreInterface;
use Drupal\custom_webform_handlers\Event\DispatchReservationConfirmedEvent;
use Drupal\custom_webform_handlers\Event\DomainEventEmitter;
use Drupal\custom_webform_handlers\Review\ReviewManagerInterface;
use Drupal\custom_webform_handlers\Review\ReviewState;
use Drupal\webform\WebformSubmissionInterface;
use Psr\Log\LoggerInterface;

/**
 * The one adapter that composes the Dispatch Planner with Commerce.
 *
 * It decides nothing about capacity, price, or eligibility. The planner still
 * derives the Dispatch Window, the Resource Calendar Guard's transaction still
 * resolves contention, BookingWorkflowGate still answers whether a Webform may
 * use this workflow at all, and QuoteSnapshot can only carry a figure the server
 * quoted. What this class owns is the order of those calls, and the undo when a
 * later one refuses: a Hold with no order, an order with no attempt, and an
 * attempt with no Reservation are each a state a customer must never be left in.
 *
 * Every return is a CheckoutOutcome, and CheckoutOutcome is the publishable
 * shape. Hold expiry, Hold token, owner hash, the internal Dispatch Window, and
 * the Reservation identifier are all reachable inside these methods and none of
 * them is ever returned or logged into a customer-visible message.
 *
 * It subscribes to no Commerce event. A checkout pane on the payment step calls
 * confirmPaid() synchronously, in the same request that authorized the card, so
 * the Hold is still in session and the guard transaction is what decides capacity
 * — not a payment transition, and not a return URL. The money is authorized first
 * and captured only once that transaction has committed a Reservation, so a Slot
 * that cannot be confirmed is never charged for; the one residual gap, a booking
 * confirmed whose capture then fails, is held for reconciliation, never released.
 */
final class DispatchCheckoutManager implements DispatchCheckoutManagerInterface {

  public function __construct(
    private readonly DispatchCheckoutPlannerInterface $planner,
    private readonly ReservationStoreInterface $store,
    private readonly DispatchOwner $owner,
    private readonly DispatchRequestFactory $requestFactory,
    private readonly ResourceProfileFactory $profileFactory,
    private readonly BookingWorkflowGate $workflowGate,
    private readonly CheckoutAttemptStore $attempts,
    private readonly DispatchOrderFactory $orderFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
    private readonly DispatchNotifierInterface $notifier,
    private readonly ReviewManagerInterface $reviews,
    private readonly DomainEventEmitter $events,
    // Booking admission store (Task 24C, Batch 1). Held, not called: this batch is
    // inert wiring, so the constructor only assigns it and no method here reads an
    // admission table. The first call is added later behind booking_admission.enforce,
    // which ships false, so an unmigrated site is unaffected by this injection.
    private readonly BookingAdmissionStoreInterface $bookingAdmissionStore,
    // The shared database connection (Task 24C-8C). start() opens one caller-owned
    // writer transaction on it so the guarded claim, the draft order, and the Checkout
    // Attempt row commit or roll back as a unit. It is the same '@database' the
    // calendar guard, the dispatch reservation store, the checkout-attempt store, and
    // Commerce entity storage all write through, so their writes nest as savepoints
    // under this transaction. It owns no admission call: Task 24C-8C introduces
    // transaction ownership, not booking-admission enforcement.
    private readonly Connection $database,
  ) {}

  /**
   * Begins payment for one job at one Slot: gate, Hold, order, attempt.
   *
   * The order of the four is the whole design. The gate is first because a
   * refused Webform must have no effect at all, not an effect that is undone.
   * The Hold is before the order because capacity is the scarce thing and an
   * order for capacity nobody can supply is worse than no order. The attempt row
   * is last because it is what names the other two, and every step after the
   * first knows how to undo the ones before it.
   *
   * @see \Drupal\custom_webform_handlers\Checkout\DispatchCheckoutManagerInterface::start()
   */
  public function start(WebformSubmissionInterface $submission, Slot $slot, CheckoutContact $contact, QuoteSnapshot $quote): CheckoutOutcome {
    // Ownership precedes everything (Task 24C-8C-R1). This use case must own the outermost
    // writer transaction for the whole booking — preparation runs before it, the claim, order,
    // and attempt inside it, and the receipt only after it commits. If a transaction is already
    // open on the shared connection, start() cannot own the outermost commit, so it refuses
    // here — before preparation, before the route provider is ever reached, and before any
    // booking effect — without opening or disturbing any transaction.
    if ($this->database->inTransaction()) {
      $this->logger->error('A dispatch checkout was refused: a database transaction is already open, so start() cannot own the outermost commit.');
      return CheckoutOutcome::refused(DispatchReason::InvalidRequest);
    }

    $submissionId = (int) $submission->id();
    $webformId = (string) ($submission->getWebform()?->id() ?? '');
    if ($submissionId < 1 || $webformId === '') {
      return CheckoutOutcome::refused(DispatchReason::InvalidRequest);
    }

    // Asked here, of the submission's own Webform, and not taken on trust from a
    // caller. Task 2 ships an empty transfer allowlist, so today this is the line
    // that refuses every public checkout — which is the intended state.
    if (!$this->workflowGate->allows($webformId, BookingWorkflow::Dispatch)) {
      return CheckoutOutcome::refused(DispatchReason::NotConfigured);
    }

    // Only the pickup-arrival instant is taken from the caller. Slot's constructor
    // is public, so a caller could otherwise name a Resource or a zone of its own
    // choosing, and capacity would be claimed against whichever van it named.
    $resolved = $this->slotFor($webformId, $slot->startTimestamp);
    if ($resolved === NULL) {
      return CheckoutOutcome::refused(DispatchReason::NotConfigured);
    }

    $request = $this->requestFactory->fromSubmission($submission);
    if ($request === NULL) {
      // The saved submission no longer normalises into a job. Refused before any
      // Hold, because a Hold for a job the planner cannot describe is capacity
      // spent on nothing.
      return CheckoutOutcome::refused(DispatchReason::IncompleteFacts);
    }

    $repeat = $this->existingAttempt($submissionId, $resolved, $quote);
    if ($repeat !== NULL) {
      return $repeat;
    }

    // The three checkout phases are run as separate operations here so a single
    // caller-owned transaction can span the claim, the order, and the attempt, with
    // route preparation before it and the session receipt after its commit. The
    // combined createCheckoutHold() wrapper is deliberately NOT used: it publishes the
    // receipt the moment the claim succeeds — before the order and the attempt exist,
    // and before any commit this method controls.
    //
    // A. Prepare — route I/O and plan derivation, before the writer transaction opens
    // (never inside it). The checkout-only final-route estimator is fixed server-side
    // in this seam and is never a parameter, so the authority boundary is preserved.
    $prepared = $this->planner->prepareCheckoutHold($request, $resolved, $quote);
    if ($prepared instanceof DispatchReason) {
      // The planner's own refusal, passed through unchanged. It has already decided
      // eligibility, grid alignment, working hours, repositioning, and the route facts,
      // and a second opinion here would be a second scheduling authority.
      return CheckoutOutcome::refused($prepared);
    }

    return $this->bindCheckoutHold($prepared, $submission, $submissionId, $resolved, $contact, $quote);
  }

  /**
   * Turns one paid order into one Reservation, once, synchronously.
   *
   * Idempotent by reading the attempt's own state first and by settling with a
   * conditional update rather than a read-then-write: a payment notification that
   * arrives twice confirms once and is told success both times. The gate is
   * deliberately not consulted again here — a configuration change made between
   * payment and confirmation would otherwise refuse a booking the customer has
   * already been charged for, which is strictly worse than honouring it.
   *
   * @see \Drupal\custom_webform_handlers\Checkout\DispatchCheckoutManagerInterface::confirmPaid()
   */
  public function confirmPaid(PaidDispatchReference $paid): CheckoutOutcome {
    $attempt = $this->attempts->loadByOrder($paid->orderId);
    if ($attempt === NULL || $attempt->submissionId !== $paid->submissionId) {
      // Either this order pays for no dispatch job, or the reference names a
      // different job than the attempt does. Neither is confirmable, and guessing
      // which job was meant would confirm capacity for the wrong one.
      return CheckoutOutcome::refused(DispatchReason::InvalidRequest);
    }

    if ($attempt->state->hasReservation()) {
      // Already a Reservation — Paid, or ReconciliationRequired where the booking
      // stands and only its money is unresolved. Either way the capacity question
      // is settled, so the repeat is expected and is answered as success. A second
      // confirmReservation() here would ask the guard to book an already-booked
      // Slot; reading state first is precisely so it is not asked.
      return CheckoutOutcome::confirmed();
    }

    if (!$attempt->isOpen()) {
      $this->logger->error('Order @order was paid but its checkout attempt is @state, so no Reservation was made.', [
        '@order' => $paid->orderId,
        '@state' => $attempt->state->value,
      ]);
      return CheckoutOutcome::refused(DispatchReason::HoldNotFound);
    }

    $submission = $this->loadSubmission($attempt->submissionId);
    if ($submission === NULL) {
      $this->logger->error('Order @order was paid but submission @sid no longer exists, so no Reservation was made.', [
        '@order' => $paid->orderId,
        '@sid' => $attempt->submissionId,
      ]);
      return CheckoutOutcome::refused(DispatchReason::InvalidRequest);
    }

    return $this->confirmHeld($attempt->orderId, $attempt->slot, $submission, $attempt->quote);
  }

  /**
   * Abandons one unpaid attempt, and refuses to touch a paid one.
   *
   * The order matters here too, in the other direction. The Hold is released
   * first and the row is settled second, so a crash between them leaves a lapsing
   * Hold and a pending attempt — recoverable — rather than a free Slot that some
   * row still claims. The Commerce cancel transition is applied last of all,
   * because per the design it decides nothing: capacity was already given back.
   *
   * @see \Drupal\custom_webform_handlers\Checkout\DispatchCheckoutManagerInterface::cancel()
   */
  public function cancel(int $orderId): CheckoutOutcome {
    $attempt = $this->attempts->loadByOrder($orderId);
    if ($attempt === NULL) {
      return CheckoutOutcome::refused(DispatchReason::InvalidRequest);
    }

    if ($attempt->state->hasReservation()) {
      // A confirmed attempt is a Reservation — Paid, or ReconciliationRequired where
      // the booking stands and only its money is unresolved — and this method may
      // not release or modify one. Withdrawing confirmed capacity is staff work with
      // its own audited path; a cancel link must not reach it, least of all one
      // still awaiting reconciliation.
      return CheckoutOutcome::refused(DispatchReason::AlreadyBooked);
    }

    if (!$attempt->isOpen()) {
      // Already cancelled or already failed. Nothing to give back, and saying so
      // as success keeps a repeated cancel from reading as an error.
      return CheckoutOutcome::cancelled();
    }

    $this->releaseHeldSlot($attempt->slot);

    if (!$this->attempts->settle($orderId, CheckoutState::Cancelled)) {
      $current = $this->attempts->loadByOrder($orderId);
      if ($current !== NULL && $current->state->hasReservation()) {
        // The Slot was confirmed while this cancel was in flight. The Reservation
        // won, and a confirmed booking is never cancelled into releasing capacity —
        // whether or not its authorization has been captured yet.
        return CheckoutOutcome::refused(DispatchReason::AlreadyBooked);
      }
      return CheckoutOutcome::cancelled();
    }

    // Nothing has been captured on an unpaid attempt, but the card may have been
    // authorized before the customer abandoned it. Void that authorization so the
    // bank's hold on their money is released rather than left to expire; a no-op
    // when there is nothing authorized.
    $this->voidAuthorization($orderId);

    $this->orderFactory->cancel($orderId);

    return CheckoutOutcome::cancelled();
  }

  /**
   * Answers a repeat of an attempt that already exists, or NULL to carry on.
   *
   * Two indexed lookups, before the planner is asked anything. Without them a
   * double-clicked pay button would ask the guard for a Slot this very session is
   * already holding, be refused because it is taken, and tell the customer their
   * own Hold was unavailable — a refusal that is both wrong and unactionable.
   *
   * The unique indexes stay as the backstop for a genuine race; this is the cheap
   * answer for the common case.
   */
  private function existingAttempt(int $submissionId, Slot $slot, QuoteSnapshot $quote): ?CheckoutOutcome {
    $sameIntent = $this->attempts->loadByIntent($submissionId, $slot, $quote);
    if ($sameIntent !== NULL) {
      if ($sameIntent->state->hasReservation()) {
        // Already booked for this exact intent — Paid, or ReconciliationRequired
        // where the Reservation stands and only its capture is unresolved. Either
        // way the job is booked and a second Hold must not be taken for it.
        return CheckoutOutcome::refused(DispatchReason::AlreadyBooked);
      }
      if ($sameIntent->isOpen()) {
        // The same job, arrival, and price as an attempt already in flight. The
        // order that already exists is the answer, not a second one.
        return CheckoutOutcome::started($sameIntent->orderId);
      }
    }

    if ($this->attempts->openForSubmission($submissionId) !== NULL) {
      // A different arrival or a different price for the same job, with payment
      // already open. One job pays for one arrival at a time; the visitor cancels
      // the first to choose another, which is what cancel() is for.
      return CheckoutOutcome::refused(DispatchReason::AlreadyBooked);
    }

    return NULL;
  }

  /**
   * Runs the claim, the order, and the attempt as one caller-owned transaction.
   *
   * The deliberate transaction integration (Task 24C-8C, corrected in 24C-8C-R1).
   * Preparation and the ambient-transaction check already happened in start(), before this
   * method and before any provider call. Here one writer transaction is opened on the shared
   * connection and the three durable effects are written inside it: the guarded claim (which
   * nests its own Resource Calendar Guard transaction as a savepoint), the draft Commerce order
   * and item (whose entity saves run on the same connection and so nest too), and the Checkout
   * Attempt row. They commit or roll back together.
   *
   * Completion is verified, not assumed (Task 24C-8C-R2). The root transaction is completed by
   * destroying its object; an ordinary commit is then established from combined supported evidence
   * — the client was in a transaction immediately before destruction (which excludes a void), a
   * post-transaction callback reports not-rolled-back, and both the Drupal stack and the client are
   * closed afterwards. A void, a rollback, a thrown commit, or a missing signal all withhold the
   * receipt and the started() outcome. Safe disposal after a FAILED rollback is a BLOCKED residual
   * (see the 24C-8C-R2 decision record); it is reported, not resolved here.
   *
   * Admission is not consulted here. Task 24C-8C introduces transaction ownership, not
   * booking-admission enforcement: no acquire()/verify()/confirm()/release() call, no generation
   * supplied, and no admission-row lock. The atomicity comes entirely from the one shared outer
   * transaction, never from an admission lock.
   */
  private function bindCheckoutHold(
    PreparedHold $prepared,
    WebformSubmissionInterface $submission,
    int $submissionId,
    Slot $slot,
    CheckoutContact $contact,
    QuoteSnapshot $quote,
  ): CheckoutOutcome {
    // Defensive: start() already refused an ambient transaction before preparation, so this is
    // only reached with no transaction open. The check is repeated at the boundary that opens
    // the transaction, because owning the outermost transaction is this method's precondition.
    if ($this->database->inTransaction()) {
      $this->logger->error('A dispatch checkout binding was refused for submission @sid: a database transaction is already open, so this manager cannot own the outermost commit.', [
        '@sid' => $submissionId,
      ]);
      return CheckoutOutcome::refused(DispatchReason::InvalidRequest);
    }

    // B. Acquire one caller-owned writer transaction on the shared connection. Acquisition
    // failure is a refusal, not a rollback target: there is no transaction to roll back, so a
    // throw here must never reach the rollback path below.
    try {
      $transaction = $this->database->startTransaction();
    }
    catch (\Throwable $exception) {
      $this->logger->error('A dispatch checkout binding could not open a writer transaction for submission @sid: @type. Nothing was claimed, ordered, or recorded.', [
        '@sid' => $submissionId,
        '@type' => get_class($exception),
      ]);
      return CheckoutOutcome::refused(DispatchReason::InvalidRequest);
    }

    try {
      // C. Claim under the Resource Calendar Guard. The guard opens its own transaction on this
      // same connection, which nests as a savepoint; completing it releases that savepoint but
      // commits nothing. The claim writes no session receipt and performs no route I/O under
      // the lock (its locked revalidation is confined to the facts prepared above).
      $held = $this->planner->claimPreparedCheckoutHold($prepared);
      if (!$held->isHeld() || $held->hold === NULL) {
        // The planner's own capacity refusal, returned rather than thrown. Nothing of ours
        // should persist, so the transaction is rolled back and the reason passed through.
        $this->rollBackBinding($transaction, $submissionId);
        return CheckoutOutcome::refused($held->reason);
      }
      $hold = $held->hold;

      // D. The draft order and its item. Both saves run through Commerce's entity storage on
      // this same connection, so they participate in this transaction and a rollback unwinds
      // their rows too.
      $order = $this->orderFactory->create($submission, $slot, $contact, $quote);
      if ($order === NULL) {
        // A refusal, not a failure to report upward. The claim is rolled back with it, so the
        // Hold row never becomes durable. No separate release is issued: there is no committed
        // row to release and no receipt to forget.
        $this->rollBackBinding($transaction, $submissionId);
        return CheckoutOutcome::refused(DispatchReason::NotConfigured);
      }
      $orderId = (int) $order->id();

      // E. The lifecycle row that names the order and the Hold.
      $attempt = $this->attempts->open(
        CheckoutAttempt::opening($orderId, $submissionId, $slot, $quote, $this->time->getRequestTime()),
      );

      if ($attempt === NULL) {
        // No lifecycle row, so nothing could ever confirm this order. The transaction is rolled
        // back rather than discarding each row by hand. The rollback OUTCOME is recorded, not
        // assumed: the log states whether cleanup was confirmed disposed, unknown, or failed — it
        // does not claim rows were removed merely because a rollback was requested (R2 §2C). The
        // in-memory order keeps its id after a rollback, so its entity caches are reset: that id
        // is not evidence its row survived.
        $cleanup = $this->rollBackBinding($transaction, $submissionId);
        $this->discardOrderCaches($order);
        $this->logger->error('A dispatch checkout attempt could not be recorded for order @order; binding cleanup @cleanup.', [
          '@order' => $orderId,
          '@cleanup' => $cleanup,
        ]);
        return CheckoutOutcome::refused(DispatchReason::InvalidRequest);
      }

      if ($attempt->orderId !== $orderId) {
        // The unique index answered with another request's row: ours is the duplicate. Roll back
        // our new work and resume the winner only through the SAME permitted states the pre-flight
        // lookup honours AND only when our own cleanup is confirmed. Our receipt is never published
        // (publication is the post-commit step below, which this path does not reach), and the
        // winner's records are untouched.
        $cleanup = $this->rollBackBinding($transaction, $submissionId);
        $this->discardOrderCaches($order);
        if ($attempt->state->hasReservation()) {
          // Paid or ReconciliationRequired: already booked. A refusal, so it is safe whatever our
          // cleanup outcome — it opens no booking and makes no durability claim.
          return CheckoutOutcome::refused(DispatchReason::AlreadyBooked);
        }
        if ($attempt->isOpen() && $cleanup === self::ROLLBACK_DISPOSED) {
          // Pending AND our losing work is confirmed disposed: resume the existing order, exactly
          // as existingAttempt() does. started() is WITHHELD when cleanup is unknown or failed,
          // because resuming while our own order/claim might still be partially persisted would
          // return success over an uncertain write (R2 §2C).
          return CheckoutOutcome::started($attempt->orderId);
        }
        // Terminal (Failed/Cancelled), or a Pending winner whose resume cannot be confirmed because
        // our cleanup was unknown/failed. A terminal attempt is never resumed as started();
        // CheckoutAttemptStore::settle() releases the idempotency key on a terminal settlement, so
        // open()'s key-collision reread cannot return a Failed/Cancelled row — a defensive floor,
        // not a reproduced race. Real unique-index contention and isolation remain PENDING.
        $this->logger->error('A dispatch checkout duplicate for order @order resolved to an attempt on order @winner in state @state with cleanup @cleanup; refusing (started() withheld unless Pending with confirmed cleanup).', [
          '@order' => $orderId,
          '@winner' => $attempt->orderId,
          '@state' => $attempt->state->value,
          '@cleanup' => $cleanup,
        ]);
        return CheckoutOutcome::refused(DispatchReason::InvalidRequest);
      }
    }
    catch (\Throwable $exception) {
      // Any thrown failure inside the binding unwinds everything written in it. The rollback is
      // attempted and its OUTCOME recorded distinctly — a rollback that threw, or returned while
      // the client transaction is still open, is reported as failed/unknown, never as "rolled
      // back". The original failure (its type) is preserved and the cleanup outcome logged beside
      // it; only the types are logged, because a storage exception can quote a customer's own
      // facts. This returns a refusal and never reaches the commit, the receipt, or a started()
      // outcome. NOTE (R2 §2D / guarantee 3): a thrown client rollBack() leaves the manager Active,
      // so the Transaction's later destruction could still commit; that residual is BLOCKED on the
      // connection-lifecycle disposal in the decision record, not resolved here.
      $cleanup = $this->rollBackBinding($transaction, $submissionId);
      if (isset($order)) {
        $this->discardOrderCaches($order);
      }
      $this->logger->error('A dispatch checkout binding failed for submission @sid (@type); cleanup @cleanup.', [
        '@sid' => $submissionId,
        '@type' => get_class($exception),
        '@cleanup' => $cleanup,
      ]);
      return CheckoutOutcome::refused(DispatchReason::InvalidRequest);
    }

    // F. Complete the outermost transaction, then VERIFY an ordinary commit before publishing.
    // Destroying the root Transaction drives the installed manager's commit, which is NOT self-
    // evidently durable: processRootCommit() throws on a failed client commit (caught below), and
    // the MySQL driver VOIDS the root WITHOUT throwing when the client is already out of a
    // transaction. A post-transaction callback reports not-rolled-back but conflates Committed and
    // Voided (R2 §2A), so it cannot alone establish an ordinary commit.
    //
    // The commit is established from combined supported evidence: the client was in a transaction
    // IMMEDIATELY BEFORE destruction (which excludes the void branch, since a void requires the
    // client already out), the callback reports not-rolled-back, and both the Drupal stack and the
    // client are out of a transaction afterwards. The observer setup is itself inside the failure
    // handling, because addPostTransactionCallback() throws when no transaction is active and such
    // a setup failure must not bypass the outcome policy (R2 §2E).
    $rootResolved = NULL;
    try {
      $clientInTxBefore = $this->clientInTransaction();
      $manager = $this->database->transactionManager();
      if ($manager !== FALSE) {
        $manager->addPostTransactionCallback(static function (bool $success) use (&$rootResolved): void {
          $rootResolved = $success;
        });
      }
    }
    catch (\Throwable $exception) {
      // The completion observer could not be set up (e.g. no active transaction). The outcome
      // cannot be observed, so success is withheld. A rollback is attempted and its outcome
      // recorded; the booking is neither deleted nor recreated.
      $cleanup = $this->rollBackBinding($transaction, $submissionId);
      $this->logger->error('A dispatch checkout binding for order @order could not set up its completion observer (@type); cleanup @cleanup. No receipt was published.', [
        '@order' => $orderId,
        '@type' => get_class($exception),
        '@cleanup' => $cleanup,
      ]);
      return CheckoutOutcome::refused(DispatchReason::InvalidRequest);
    }

    try {
      unset($transaction);
    }
    catch (\Throwable $exception) {
      // The root commit threw (e.g. TransactionCommitFailedException). The outcome is uncertain:
      // no receipt is published, and the booking is neither deleted nor recreated. A later
      // same-intent retry resolves against whatever did or did not commit, through the pre-flight
      // lookup and the unique index.
      $this->logger->error('A dispatch checkout binding for order @order did not complete its root commit: @type. No receipt was published; nothing was deleted or recreated.', [
        '@order' => $orderId,
        '@type' => get_class($exception),
      ]);
      return CheckoutOutcome::refused(DispatchReason::InvalidRequest);
    }

    $clientInTxAfter = $this->clientInTransaction();
    if ($clientInTxBefore !== TRUE || $rootResolved !== TRUE || $this->database->inTransaction() || $clientInTxAfter === TRUE) {
      // Not an established ordinary commit: the client was not in a transaction before destruction
      // (a void / implicit upstream commit, client_before !== TRUE), the callback reported a
      // rollback or never fired (resolved !== TRUE), or the Drupal/client transaction is still open
      // afterwards. None of these is a durable ordinary commit, so no receipt and no started()
      // outcome; the records are left exactly as they are. A void is NOT treated as a successful
      // implicit commit (R2 §2A).
      $this->logger->error('A dispatch checkout binding for order @order could not confirm an ordinary root commit (client_before=@b, resolved=@r, drupal_in_tx=@d, client_after=@a); no receipt was published.', [
        '@order' => $orderId,
        '@b' => var_export($clientInTxBefore, TRUE),
        '@r' => var_export($rootResolved, TRUE),
        '@d' => var_export($this->database->inTransaction(), TRUE),
        '@a' => var_export($clientInTxAfter, TRUE),
      ]);
      return CheckoutOutcome::refused(DispatchReason::InvalidRequest);
    }

    // G. An ordinary root commit is established (not a void, not a rollback): publish the session
    // receipt, then return success. A publication failure here cannot roll the committed booking
    // back and must not delete or recreate it; it is logged and refused safely. (Real-database
    // atomicity remains PENDING, and safe disposal after a FAILED rollback remains BLOCKED per the
    // 24C-8C-R2 decision record; neither is claimed resolved by this gate.)
    try {
      $this->planner->publishCheckoutHoldReceipt($hold);
    }
    catch (\Throwable $exception) {
      $this->logger->error('Order @order completed its root transaction but its session receipt could not be published: @type. The booking was left intact; it was not deleted or recreated.', [
        '@order' => $orderId,
        '@type' => get_class($exception),
      ]);
      return CheckoutOutcome::refused(DispatchReason::InvalidRequest);
    }

    return CheckoutOutcome::started($orderId);
  }

  /**
   * The rollback outcomes this manager distinguishes (Task 24C-8C-R2 §2B).
   *
   * DISPOSED — rollBack() returned AND the client transaction is confirmed closed, so the
   * uncommitted writes are not durable. UNKNOWN — rollBack() returned but the client transaction
   * is still open or could not be read, so cleanup is not established. FAILED — rollBack() threw,
   * so the manager may remain Active and destruction could still commit (the BLOCKED residual).
   */
  private const ROLLBACK_DISPOSED = 'disposed';
  private const ROLLBACK_UNKNOWN = 'unknown';
  private const ROLLBACK_FAILED = 'failed';

  /**
   * Rolls back the binding transaction and reports a CONFIRMED outcome, never a presumed one.
   *
   * Normal return from rollBack() does NOT establish a successful rollback: the installed MySQL
   * driver's rollbackClientTransaction() can return FALSE (RollbackFailed) or void, and
   * processRootRollback() discards that bool (R2 §2B). So completion is confirmed from the
   * client-level transaction state: DISPOSED only when the client is no longer in a transaction
   * afterwards; UNKNOWN when it is still open or unreadable; FAILED when rollBack() threw. The
   * caller refuses on anything but DISPOSED and never claims rows were removed merely because a
   * rollback was requested. Only the exception type is logged, never its message.
   *
   * BLOCKED residual (decision record, guarantee 3): a rollBack() that THROWS leaves the manager
   * Active (the driver assigns the state only AFTER the client call returns), so the Transaction's
   * destruction can still processRootCommit() and commit. This method cannot neutralise that
   * without a connection-lifecycle disposal (voidClientTransaction() + a client reset), a scope
   * expansion held for separate authorization; it is reported here, not hidden.
   */
  private function rollBackBinding(Transaction $transaction, int $submissionId): string {
    try {
      $transaction->rollBack();
    }
    catch (\Throwable $exception) {
      $this->logger->error('A dispatch checkout binding rollback for submission @sid threw (@type); cleanup FAILED and the outcome is uncertain. No receipt was published and no row was deleted or recreated.', [
        '@sid' => $submissionId,
        '@type' => get_class($exception),
      ]);
      return self::ROLLBACK_FAILED;
    }

    // Normal return is not proof. Confirm via the client-level transaction state: a closed client
    // transaction means the uncommitted writes are not durable (rolled back or voided); a still-open
    // or unreadable client is UNKNOWN cleanup, not a confirmed rollback.
    $clientInTx = $this->clientInTransaction();
    if ($clientInTx === FALSE) {
      return self::ROLLBACK_DISPOSED;
    }
    $this->logger->error('A dispatch checkout binding rollback for submission @sid returned but did not confirm disposal (client_in_tx=@c); cleanup UNKNOWN. No receipt was published and no row was deleted or recreated.', [
      '@sid' => $submissionId,
      '@c' => var_export($clientInTx, TRUE),
    ]);
    return self::ROLLBACK_UNKNOWN;
  }

  /**
   * Reports whether the client-level (e.g. PDO) connection is in a transaction, or NULL if unknown.
   *
   * The supported evidence the decision record relies on (Connection::getClientConnection() is
   * public). Used only as corroborating evidence — combined with the post-transaction callback and
   * the Drupal-level inTransaction(), never on its own as an acknowledgement of a successful commit.
   * NULL means the client state could not be read, which callers treat as "not established":
   * fail-closed for the commit gate (requires TRUE before / not-TRUE after) and UNKNOWN for rollback
   * disposal (requires an explicit FALSE).
   */
  private function clientInTransaction(): ?bool {
    try {
      $client = $this->database->getClientConnection();
      if (is_object($client) && method_exists($client, 'inTransaction')) {
        return (bool) $client->inTransaction();
      }
    }
    catch (\Throwable) {
    }
    return NULL;
  }

  /**
   * Resets the entity caches for a draft order a rollback has already removed.
   *
   * A rolled-back order leaves the in-memory object carrying its assigned id and
   * fields, so a later load keyed by that id could be served a phantom from the static
   * or persistent entity cache even though no row remains. The order and its items are
   * therefore evicted here. This is cache hygiene, not deletion: the rows are already
   * gone with the transaction, so discard() is deliberately NOT called — it would try to
   * delete rows the rollback removed. Best-effort and swallowed: a cache that cannot be
   * reset must not mask the rollback it follows.
   */
  private function discardOrderCaches(OrderInterface $order): void {
    try {
      $orderId = (int) $order->id();
      $itemIds = [];
      foreach ($order->getItems() as $item) {
        $itemId = (int) $item->id();
        if ($itemId > 0) {
          $itemIds[] = $itemId;
        }
      }
      if ($orderId > 0) {
        $this->entityTypeManager->getStorage('commerce_order')->resetCache([$orderId]);
      }
      if ($itemIds !== []) {
        $this->entityTypeManager->getStorage('commerce_order_item')->resetCache($itemIds);
      }
    }
    catch (\Throwable $exception) {
      $this->logger->warning('The entity caches for a rolled-back dispatch order could not be reset: @type.', [
        '@type' => get_class($exception),
      ]);
    }
  }

  /**
   * Confirms this session's Hold on a Slot and records the Reservation on it.
   *
   * The raw Hold token is recovered from the session receipt rather than stored on
   * the attempt row: the store keeps only hashes, and a lifecycle row holding a
   * usable token would be a second copy of a credential. Payment confirmation is
   * synchronous and in-session, so the receipt is there to be read — the same way
   * DispatchBooking reaches a Hold it did not create the request for.
   *
   * The receipt is not forgotten here. DispatchPlanner::confirmFor() consumes it
   * itself once the store returns a Reservation, and a second forget would be this
   * class taking over ownership of a decision the planner already makes.
   */
  private function confirmHeld(int $orderId, Slot $slot, WebformSubmissionInterface $submission, QuoteSnapshot $accepted): CheckoutOutcome {
    $hold = $this->owner->heldSlot($slot);
    if ($hold === NULL) {
      // Authorized, but this session holds no receipt for the Slot — a new session,
      // a cleared cookie. The Hold cannot be proved to release it, so it lapses on
      // its own; the authorization, though, is on the order and is voided so no
      // money is taken for a booking that cannot be made. The attempt is failed —
      // unless a concurrent confirmation for this order already settled it, in which
      // case that Reservation is the customer's and the honest answer is success,
      // with nothing here to void.
      if (!$this->settleUnpaid($orderId, CheckoutState::Failed)) {
        $current = $this->attempts->loadByOrder($orderId);
        if ($current !== NULL && $current->state->hasReservation()) {
          return CheckoutOutcome::confirmed();
        }
        return CheckoutOutcome::refused(DispatchReason::HoldNotFound);
      }

      $this->voidAuthorization($orderId);
      $this->logger->warning('Order @order was authorized but this session holds no receipt for its Slot, so the authorization was voided and no Reservation was made.', [
        '@order' => $orderId,
      ]);
      return CheckoutOutcome::refused(DispatchReason::HoldNotFound);
    }

    // The money about to be captured must still be the money the customer agreed
    // to (Task 24B / E08). The snapshot's figure was written onto the order item as
    // an overridden unit price, which OrderRefresh leaves alone — but an order
    // processor, a promotion, a tax, or an added item could still move the payable
    // total between opening the order and confirming it, and the total is what the
    // capture will take. So the order's own computed getTotalPrice(), the number
    // Commerce would actually charge, is compared with the accepted amount and
    // currency here. This is cross-system I/O and read-only, so it runs pre-lock:
    // loading a Commerce order inside the Resource Calendar Guard's transaction
    // would be exactly the external work the locked path forbids. A mismatch takes
    // the same undo as any refusal below — Hold back, attempt failed, authorization
    // voided — so no capacity is booked and no money is taken.
    if (!$this->orderPayableMatches($orderId, $accepted)) {
      $this->releaseHold($hold);

      if (!$this->settleUnpaid($orderId, CheckoutState::Failed)) {
        // A concurrent confirmation for this order won the race. If it left a
        // Reservation that booking is the customer's; answer with the truth and do
        // not void the authorization it captured.
        $current = $this->attempts->loadByOrder($orderId);
        if ($current !== NULL && $current->state->hasReservation()) {
          return CheckoutOutcome::confirmed();
        }
        return CheckoutOutcome::refused(DispatchReason::InvalidRequest);
      }

      $this->voidAuthorization($orderId);
      $this->logger->warning('Order @order was authorized but its payable total no longer matches the accepted quote, so the authorization was voided and no Reservation was made.', [
        '@order' => $orderId,
      ]);
      return CheckoutOutcome::refused(DispatchReason::InvalidRequest);
    }

    // The accepted job must still be the job now on the submission (Task 24B /
    // E09). The planner re-proves the Slot timing under its own locks, but timing
    // is all it re-proves: an edited address, service, package, or access fact that
    // did not change the reserved duration would otherwise confirm under the price
    // the customer accepted for the old job. Both accepted-job axes are carried into
    // the confirmation seam so the comparisons happen inside the guard transaction,
    // against the authoritative stored plan, rather than in an unlocked check here
    // that a second caller could bypass:
    //   - the job-facts digest, which the planner recomputes from the saved
    //     submission INSIDE the locked callback (reading inputs, never re-pricing)
    //     and refuses on mismatch;
    //   - the snapshot binding identity — QuoteSnapshot::bindingIdentity(), NOT the
    //     bare snapshot id — which the planner binds under the lock to the
    //     reservation's own persisted price_snapshot_id, so the accepted quote being
    //     settled is provably the one this reservation was held for.
    // $accepted is the immutable attempt row's snapshot, hydrated through fromStored()
    // and sealed by its payload digest, so both values are the ones the customer
    // accepted, not anything recomputed against today's mutable config. The binding is
    // VERSION-AWARE: a current-format snapshot's binding identity carries a version
    // prefix, and the seam REFUSES it against a NULL/empty/mismatched stored id —
    // missing data is not proof of legacy status. Only a genuine legacy attempt, whose
    // snapshot carries no versioned identity, is skipped rather than false-refused. A
    // mismatch on either axis refuses with InvalidRequest, which the failure path below
    // voids and reports exactly as any other planner refusal. This narrows the
    // confirm-window race but does not fully close it; the planner documents the
    // residual and why full closure is migration-gated.
    $result = $this->planner->confirmCheckoutReservation($hold, $submission, $accepted->jobFactsDigest, $accepted->bindingIdentity());
    if (!$result->isConfirmed() || $result->reservationId === NULL) {
      // Give the Hold back first: whatever becomes of the money, the capacity this
      // request took returns to the calendar. release() is ownership-checked and
      // no-ops on a Hold a concurrent winner has already consumed into a Reservation.
      $this->releaseHold($hold);

      if (!$this->settleUnpaid($orderId, CheckoutState::Failed)) {
        // No longer pending: a concurrent confirmation for this same order won the
        // race between confirmPaid() reading it open and here. If it left a
        // Reservation, that booking is the customer's — answer with the truth and do
        // not void the authorization it captured.
        $current = $this->attempts->loadByOrder($orderId);
        if ($current !== NULL && $current->state->hasReservation()) {
          return CheckoutOutcome::confirmed();
        }
        return CheckoutOutcome::refused($result->reason);
      }

      // This refusal is ours to own. Under authorize-first no money has moved — the
      // card is only authorized — so the authorization is voided and the customer is
      // told plainly: not booked, not charged. The reason is the planner's, unaltered.
      $this->voidAuthorization($orderId);
      $this->logger->warning('Order @order was authorized but its Slot could not be confirmed, so the authorization was voided: @reason.', [
        '@order' => $orderId,
        '@reason' => $result->reason->value,
      ]);
      return CheckoutOutcome::refused($result->reason);
    }

    return $this->settlePaid($orderId, $result, $submission);
  }

  /**
   * Captures the money, settles the attempt, and never releases the Reservation.
   *
   * Reached only once the guard transaction has committed, so the Reservation
   * already exists and the answer is confirmed no matter what follows. Capture is
   * attempted first and the attempt settles to Paid on success, or to
   * ReconciliationRequired when the gateway could not take the money — the booking
   * is kept either way, because a confirmed Reservation is never given back for a
   * payment reason. Every write below is a record or a notice, none of them a
   * decision, so each failure is logged and none is published.
   */
  private function settlePaid(int $orderId, ReservationResult $result, WebformSubmissionInterface $submission): CheckoutOutcome {
    $reservationId = (int) $result->reservationId;

    // Capture before settling, so the state the attempt lands in records what
    // actually happened to the money. The guard transaction has already committed:
    // the Reservation exists and nothing here can release it. The only open question
    // is whether the authorization captured.
    $captured = $this->captureAuthorization($orderId);
    $state = $captured ? CheckoutState::Paid : CheckoutState::ReconciliationRequired;

    $settled = $this->attempts->settle($orderId, $state, $reservationId);
    if (!$settled) {
      // Not pending any more, ordinarily because a concurrent notification settled
      // it first — the idempotency this is built for. Only a settle to a state that
      // carries no Reservation would be wrong here, and that alone is logged.
      $current = $this->attempts->loadByOrder($orderId);
      if ($current === NULL || !$current->state->hasReservation()) {
        $this->logger->error('Reservation @reservation was confirmed for order @order, but the attempt row did not settle as a confirmed booking.', [
          '@reservation' => $reservationId,
          '@order' => $orderId,
        ]);
      }
    }

    if (!$captured) {
      // Confirmed but not captured — the one residual money gap in the authorize-
      // then-capture model. The Reservation stands, because a confirmed booking is
      // never released for a payment reason; an operator resolves the authorization
      // on Commerce's own order, retrying the capture or voiding and refunding it.
      // The ReconciliationRequired attempt row is the durable, queryable record.
      $this->logger->error('Order @order is confirmed as Reservation @reservation but its authorization could not be captured; the attempt is held for reconciliation.', [
        '@order' => $orderId,
        '@reservation' => $reservationId,
      ]);
    }

    if (!$this->store->linkOrder($reservationId, $orderId)) {
      // A pointer, not a decision. The Reservation stands without it; what is lost
      // is the Dispatch board's ability to name the order, so it is a warning.
      $this->logger->warning('Reservation @reservation could not be linked to order @order.', [
        '@reservation' => $reservationId,
        '@order' => $orderId,
      ]);
    }

    $this->recordReviewScheduled($submission, $orderId, $reservationId);

    // Post-commit, and only on the settle this request actually won, so a replayed
    // or raced confirmation cannot enqueue a second notice. Enqueue-only: a
    // notification failure can never roll back a paid Reservation.
    if ($settled) {
      $this->enqueueConfirmation($result, $submission, $reservationId);
      $this->announceConfirmed($result, $submission, $orderId, $reservationId);
    }

    return CheckoutOutcome::confirmed();
  }

  /**
   * Tells the review that its offer was paid for and capacity is committed.
   *
   * The only path to ReviewState::Scheduled, and the reason specification item 2 is
   * an invariant rather than a hope: approval prepares an offer and consumes nothing,
   * and the transition to Scheduled is reachable only from here — after the Resource
   * Calendar Guard's transaction has committed the Reservation this names. Nothing is
   * re-decided; the Reservation already exists and the manager records that it does.
   *
   * A job with no review is the ordinary case. Most dispatch bookings are priced by
   * the quote engine and paid without a member of staff ever seeing them, and those
   * have nothing to record — so NULL here is silence, not a fault.
   *
   * Every failure is logged and none is published, on the same principle as the rest
   * of settlePaid(): the booking is confirmed and paid, and no bookkeeping failure
   * after that point may be turned into a customer-facing refusal or be allowed to
   * throw its way back into a checkout that has already taken money. What is lost by
   * a failure here is the request's own state, which an operator can see is wrong on
   * the board because the job is paid and the request still reads as approved.
   */
  private function recordReviewScheduled(WebformSubmissionInterface $submission, int $orderId, int $reservationId): void {
    try {
      $review = $this->reviews->loadBySubmission((int) $submission->id());
      if ($review === NULL) {
        return;
      }

      $outcome = $this->reviews->recordScheduled($review->reviewId, $orderId, $reservationId);
      if ($outcome->ok) {
        return;
      }

      if ($outcome->request?->state === ReviewState::Scheduled) {
        // Already recorded, ordinarily because a concurrent notification for this
        // same order got here first. That is the idempotency this path is built for
        // and not a condition to report: the request says exactly what it should.
        return;
      }

      $this->logger->error('Order @order is confirmed as Reservation @reservation but Review Request @review could not be recorded as scheduled: @reason.', [
        '@order' => $orderId,
        '@reservation' => $reservationId,
        '@review' => $review->reviewId,
        '@reason' => $outcome->reason->value,
      ]);
    }
    catch (\Throwable $exception) {
      // Caught rather than propagated, because the money is captured and the Slot is
      // held. The type is logged and the message is not: a storage exception can
      // quote the values it was given, and those include a customer's own facts.
      $this->logger->error('Order @order is confirmed as Reservation @reservation but its Review Request could not be read or recorded: @type.', [
        '@order' => $orderId,
        '@reservation' => $reservationId,
        '@type' => get_class($exception),
      ]);
    }
  }

  /**
   * Settles an attempt to a terminal unpaid state, reporting whether it won.
   *
   * A thin pass-through to the store's compare-and-set, named so the confirm paths
   * read as intent rather than mechanism. FALSE means the row was no longer pending
   * — the caller re-reads to see whether a concurrent confirmation settled it into a
   * Reservation that must be left alone.
   */
  private function settleUnpaid(int $orderId, CheckoutState $state): bool {
    return $this->attempts->settle($orderId, $state);
  }

  /**
   * Captures this order's authorization, or FALSE when it could not be captured.
   *
   * Runs only after the guard transaction has committed, so it never holds a row
   * lock across the gateway call. FALSE is not an exception to propagate: the
   * Reservation already exists, and the caller turns a failed capture into a
   * reconciliation fact rather than a torn-down booking — so every failure mode, a
   * declined capture or an unreachable gateway alike, is caught and reported as
   * "not captured". Nothing but a payment the gateway still holds in authorization
   * is touched, and the gateway's own guard is honoured before the call.
   */
  private function captureAuthorization(int $orderId): bool {
    $payment = $this->loadAuthorizationPayment($orderId);
    if ($payment === NULL) {
      return FALSE;
    }

    try {
      $plugin = $payment->getPaymentGateway()?->getPlugin();
      if (!$plugin instanceof SupportsAuthorizationsInterface || !$plugin->canCapturePayment($payment)) {
        return FALSE;
      }
      $plugin->capturePayment($payment);
    }
    catch (\Throwable $e) {
      $this->logger->error('The authorization for order @order could not be captured: @message', [
        '@order' => $orderId,
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Voids this order's outstanding authorization, if it has one.
   *
   * Best effort and silent by design: a no-op when nothing is authorized — a
   * declined card left no payment, or a prior capture already completed it — and a
   * swallow of every failure, because this runs on the refusal and cancel paths
   * where there is nothing left to roll back and a throw would only bury the
   * refusal the caller is returning. The gateway's own guard is honoured before the
   * call, so a payment past its authorization state is left untouched.
   */
  private function voidAuthorization(int $orderId): void {
    $payment = $this->loadAuthorizationPayment($orderId);
    if ($payment === NULL) {
      return;
    }

    try {
      $plugin = $payment->getPaymentGateway()?->getPlugin();
      if (!$plugin instanceof SupportsVoidsInterface || !$plugin->canVoidPayment($payment)) {
        return;
      }
      $plugin->voidPayment($payment);
    }
    catch (\Throwable $e) {
      $this->logger->warning('The authorization for order @order could not be voided: @message', [
        '@order' => $orderId,
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Whether the order's payable total is still the accepted amount and currency.
   *
   * Verifies what will actually be charged, not what was written. The snapshot's
   * figure is set as the order item's overridden unit price, so OrderRefresh does
   * not recalculate it from a purchased entity — a dispatch item has none. But the
   * amount a capture takes is the order's getTotalPrice(), which is the sum over
   * items of quantity times unit price plus every adjustment, and an adjustment a
   * processor, a promotion, or a tax added after the order was opened is not
   * visible on the unit price at all. So the order is loaded and three things are
   * checked against the accepted snapshot: that it still carries exactly the one
   * priced item the quote was for, that the item's quantity is still one, and that
   * the computed total equals the accepted amount in the accepted currency.
   * Price::equals() compares the number and the currency together and never throws
   * on a currency it does not share, so a total drifted in either dimension is a
   * mismatch rather than an exception.
   *
   * FALSE on anything unreadable or unequal: a missing order, a storage fault, a
   * NULL total, an item count or quantity that is not one, or a total that is not
   * the accepted figure. The caller treats FALSE as a refusal that voids the
   * authorization and books nothing, which is the safe direction — a booking is
   * declined, never confirmed against a total the customer did not accept.
   */
  private function orderPayableMatches(int $orderId, QuoteSnapshot $accepted): bool {
    if ($orderId < 1) {
      return FALSE;
    }

    try {
      $order = $this->entityTypeManager->getStorage('commerce_order')->load($orderId);
    }
    catch (\Exception $e) {
      $this->logger->error('Commerce order storage was unavailable while verifying the payable total for order @order: @message', [
        '@order' => $orderId,
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }

    if (!$order instanceof OrderInterface) {
      return FALSE;
    }

    // Exactly the one priced item the quote was for. A second item — added by a
    // later processor or a tampered order — would be charged for on the same
    // capture, so its presence alone means the payable job is not the quoted one.
    $items = $order->getItems();
    if (count($items) !== 1) {
      return FALSE;
    }

    // Quantity is a decimal string, compared as one so "1", "1.0", and "1.00" read
    // alike and only a genuine change of count fails. A quantity other than one
    // multiplies the accepted unit price into a total the customer never accepted.
    $item = reset($items);
    if (Calculator::compare((string) $item->getQuantity(), '1') !== 0) {
      return FALSE;
    }

    // The number a capture will actually take — quantity times unit price plus
    // every adjustment. Compared whole, in both amount and currency, with the
    // figure the customer accepted.
    $total = $order->getTotalPrice();
    if ($total === NULL) {
      return FALSE;
    }

    return $total->equals(new Price($accepted->amountDecimal(), $accepted->currency));
  }

  /**
   * Loads the one payment this order holds in the gateway's authorization state.
   *
   * There is at most one: a dispatch order authorizes a single card once, on the
   * payment step. NULL means none is outstanding — nothing to capture and nothing
   * to void — which both callers treat as their no-op, not as an error. Storage
   * being unavailable is logged and answered as NULL for the same reason capture
   * and void swallow their failures: a money-plumbing fault must not surface as a
   * fatal over a Reservation that already exists.
   */
  private function loadAuthorizationPayment(int $orderId): ?PaymentInterface {
    if ($orderId < 1) {
      return NULL;
    }

    try {
      $order = $this->entityTypeManager->getStorage('commerce_order')->load($orderId);
      if (!$order instanceof OrderInterface) {
        return NULL;
      }

      /** @var \Drupal\commerce_payment\PaymentStorageInterface $payments */
      $payments = $this->entityTypeManager->getStorage('commerce_payment');
      $loaded = $payments->loadMultipleByOrder($order);
    }
    catch (\Exception $e) {
      $this->logger->error('Payment storage was unavailable while resolving the authorization for order @order: @message', [
        '@order' => $orderId,
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }

    foreach ($loaded as $payment) {
      if ($payment->getState()->getId() === 'authorization') {
        return $payment;
      }
    }

    return NULL;
  }

  /**
   * Enqueues the confirmed-booking notice, after commit, or stays silent.
   *
   * A confirmed visitor result carries a full Dispatch Window; if any part of it is
   * absent this enqueues nothing rather than promise a pickup it cannot state. The
   * Reservation is unaffected either way — a notice is never a precondition for a
   * booking — and the notifier neither sends nor throws here: it only puts a record
   * on a queue for a worker to deliver once the request is over. actorUid is NULL
   * because a paying customer, not a staff member, is who confirmed this.
   */
  private function enqueueConfirmation(ReservationResult $result, WebformSubmissionInterface $submission, int $reservationId): void {
    $slot = $result->slot;
    if ($slot === NULL || $result->windowStart === NULL || $result->windowEnd === NULL || $reservationId < 1) {
      return;
    }

    $this->notifier->notify(DispatchNotification::confirmed(
      $slot->resourceId,
      $slot->timezone,
      $this->time->getRequestTime(),
      $reservationId,
      (int) $submission->id(),
      $slot->webformId,
      $slot->startTimestamp,
      $result->windowStart,
      $result->windowEnd,
      NULL,
    ));
  }

  /**
   * Announces the committed Reservation so a background reaction can warm its route.
   *
   * A sibling to enqueueConfirmation, on the same seam and for the same reason: the
   * Resource Calendar Guard's transaction has already resolved, so the Reservation
   * this names exists and a listener cannot unmake it. It carries the Resource and the
   * day, never an address — the day resolved in the Resource's own timezone, because
   * "which local day" is a question that Resource's calendar answers and a listener
   * given a bare timestamp would have to guess.
   *
   * Wrapped and swallowed on the same principle the rest of settlePaid() follows:
   * every write after the capture is a record or a notice, none of them a decision, so
   * a bad timezone or a failed announcement is logged by class and the paid booking is
   * left exactly as confirmed. A result without a Slot is silence — there is no day to
   * warm and nothing to announce.
   */
  private function announceConfirmed(ReservationResult $result, WebformSubmissionInterface $submission, int $orderId, int $reservationId): void {
    $slot = $result->slot;
    if ($slot === NULL || $reservationId < 1) {
      return;
    }

    try {
      $localDate = (new \DateTimeImmutable('@' . $slot->startTimestamp))
        ->setTimezone(new \DateTimeZone($slot->timezone))
        ->format('Y-m-d');

      $this->events->emit(
        new DispatchReservationConfirmedEvent(
          $reservationId,
          $slot->resourceId,
          $orderId,
          (int) $submission->id(),
          $localDate,
        ),
        DispatchReservationConfirmedEvent::EVENT_NAME,
      );
    }
    catch (\Throwable $exception) {
      // The booking is confirmed and paid; an announcement that could not be built or
      // dispatched changes none of that. Logged by class, because the values it choked
      // on are the Resource's, and swallowed, because there is nothing to undo.
      $this->logger->error('The confirmation of Reservation @reservation for order @order could not be announced: @class.', [
        '@reservation' => $reservationId,
        '@order' => $orderId,
        '@class' => get_class($exception),
      ]);
    }
  }

  /**
   * Gives back a Hold this request took and will not be paying for.
   *
   * Ownership is proved by the store, so a session with no identity cannot release
   * anything — and must not, because a release that skipped ownership would be a
   * way to free somebody else's capacity. The receipt is dropped either way: a
   * receipt for a Hold this request has abandoned would let a later request
   * present a token for capacity it no longer owns.
   */
  private function releaseHold(Hold $hold): void {
    $ownerId = $this->owner->ownerId();
    if ($ownerId !== '') {
      $this->store->release($hold->token, $ownerId);
    }

    $this->owner->forgetHold($hold->token);
  }

  /**
   * Releases the Hold this session recorded for a Slot, if it still has one.
   *
   * A cancel arriving without the receipt — a new session, a cleared cookie —
   * releases nothing, and that is the honest outcome: the token cannot be proved,
   * so the Hold lapses on its own schedule instead. Capacity is returned either
   * way, which is why the attempt is settled regardless.
   */
  private function releaseHeldSlot(Slot $slot): void {
    $hold = $this->owner->heldSlot($slot);
    if ($hold !== NULL) {
      $this->releaseHold($hold);
    }
  }

  /**
   * Rebuilds the Slot a pickup-arrival instant names, or NULL.
   *
   * The Resource and the timezone come from the operating profile, never from the
   * caller, for the reason DispatchBooking gives: the caller names an instant and
   * the server decides whose capacity that instant belongs to. Everything else —
   * grid alignment, working hours, repositioning — is left to the planner, which
   * re-derives it under its own locks.
   */
  private function slotFor(string $webformId, int $pickupArrival): ?Slot {
    if ($pickupArrival < 1) {
      return NULL;
    }

    $profile = $this->profileFactory->create();
    if ($profile === NULL) {
      return NULL;
    }

    try {
      return new Slot($webformId, $profile->resourceId, $pickupArrival, $profile->timezone);
    }
    catch (\InvalidArgumentException $e) {
      // Slot refuses to substitute UTC for a zone this server does not recognise,
      // and so does this: an instant read in the wrong zone passes every check and
      // then puts the driver at the wrong hour.
      $this->logger->error('A dispatch checkout could not build a Slot for Webform @webform: @message', [
        '@webform' => $webformId,
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Loads the submission one attempt was opened for, or NULL.
   *
   * Read from the identifier the attempt row stores, which was written from the
   * saved submission, so the job confirmed is the job the server recorded — never
   * one a payment notification named.
   */
  private function loadSubmission(int $submissionId): ?WebformSubmissionInterface {
    if ($submissionId < 1) {
      return NULL;
    }

    try {
      $submission = $this->entityTypeManager
        ->getStorage('webform_submission')
        ->load($submissionId);
    }
    catch (\Exception) {
      // Missing submission storage is a broken installation, not an operator
      // error, and it must not surface as a white screen over a paid order.
      $this->logger->error('Webform submission storage was unavailable, so submission @sid could not be read.', [
        '@sid' => $submissionId,
      ]);
      return NULL;
    }

    return $submission instanceof WebformSubmissionInterface ? $submission : NULL;
  }

}
