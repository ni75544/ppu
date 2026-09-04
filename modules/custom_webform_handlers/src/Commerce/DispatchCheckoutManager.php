<?php

namespace Drupal\custom_webform_handlers\Commerce;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsAuthorizationsInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsVoidsInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\custom_webform_handlers\Checkout\CheckoutAttempt;
use Drupal\custom_webform_handlers\Checkout\CheckoutAttemptStore;
use Drupal\custom_webform_handlers\Checkout\CheckoutContact;
use Drupal\custom_webform_handlers\Checkout\CheckoutOutcome;
use Drupal\custom_webform_handlers\Checkout\CheckoutState;
use Drupal\custom_webform_handlers\Checkout\DispatchCheckoutManagerInterface;
use Drupal\custom_webform_handlers\Checkout\PaidDispatchReference;
use Drupal\custom_webform_handlers\Checkout\QuoteSnapshot;
use Drupal\custom_webform_handlers\Dispatch\BookingWorkflow;
use Drupal\custom_webform_handlers\Dispatch\BookingWorkflowGate;
use Drupal\custom_webform_handlers\Dispatch\DispatchOwner;
use Drupal\custom_webform_handlers\Dispatch\DispatchPlannerInterface;
use Drupal\custom_webform_handlers\Dispatch\DispatchReason;
use Drupal\custom_webform_handlers\Dispatch\DispatchRequestFactory;
use Drupal\custom_webform_handlers\Dispatch\Hold;
use Drupal\custom_webform_handlers\Dispatch\Notify\DispatchNotification;
use Drupal\custom_webform_handlers\Dispatch\Notify\DispatchNotifierInterface;
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
    private readonly DispatchPlannerInterface $planner,
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

    $held = $this->planner->createHold($request, $resolved);
    if (!$held->isHeld() || $held->hold === NULL) {
      // The planner's own refusal, passed through unchanged. It has already
      // decided grid alignment, working hours, repositioning, and contention, and
      // a second opinion here would be a second scheduling authority.
      return CheckoutOutcome::refused($held->reason);
    }

    $order = $this->orderFactory->create($submission, $resolved, $contact, $quote);
    if ($order === NULL) {
      $this->releaseHold($held->hold);
      return CheckoutOutcome::refused(DispatchReason::NotConfigured);
    }

    return $this->recordAttempt($order, $submissionId, $resolved, $quote, $held->hold);
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

    return $this->confirmHeld($attempt->orderId, $attempt->slot, $submission);
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
   * Writes the lifecycle row, and undoes the order and Hold if it cannot.
   *
   * The unique index can still answer with somebody else's row, which is the true
   * race the pre-flight lookups cannot close. When it does, ours is the duplicate:
   * the draft order is deleted, the Hold this request took is given back, and the
   * customer is sent to the order that won. Releasing our own token cannot strip
   * the winner's Hold, because every claim inserts its own row with its own token.
   */
  private function recordAttempt(OrderInterface $order, int $submissionId, Slot $slot, QuoteSnapshot $quote, Hold $hold): CheckoutOutcome {
    $orderId = (int) $order->id();
    $attempt = $this->attempts->open(
      CheckoutAttempt::opening($orderId, $submissionId, $slot, $quote, $this->time->getRequestTime()),
    );

    if ($attempt === NULL) {
      // No row, so there is no lifecycle. An order left behind here would look
      // payable and could never be confirmed, so it does not survive this method.
      $this->orderFactory->discard($order);
      $this->releaseHold($hold);
      $this->logger->error('A dispatch checkout attempt could not be recorded for order @order; the order and Hold were withdrawn.', [
        '@order' => $orderId,
      ]);
      return CheckoutOutcome::refused(DispatchReason::InvalidRequest);
    }

    if ($attempt->orderId !== $orderId) {
      $this->orderFactory->discard($order);
      $this->releaseHold($hold);
      return CheckoutOutcome::started($attempt->orderId);
    }

    return CheckoutOutcome::started($orderId);
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
  private function confirmHeld(int $orderId, Slot $slot, WebformSubmissionInterface $submission): CheckoutOutcome {
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

    $result = $this->planner->confirmReservation($hold, $submission);
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
