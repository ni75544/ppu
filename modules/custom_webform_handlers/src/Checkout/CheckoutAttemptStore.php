<?php

namespace Drupal\custom_webform_handlers\Checkout;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\custom_webform_handlers\Dispatch\Slot;
use Psr\Log\LoggerInterface;

/**
 * Durable storage for the private Checkout Attempt lifecycle.
 *
 * A value object alone is not a lifecycle. Payment begins on one request and is
 * asserted on another — by a gateway callback, by a customer returning, possibly
 * by both, possibly twice — and nothing that lives only for a request can tell a
 * repeat from a new booking. This is the row that can.
 *
 * It is a narrow table rather than fields on the Commerce order, and the reason is
 * specific: the invariants this lifecycle needs are uniqueness invariants, and the
 * Field API cannot express one. Commerce owns the order, its state machine, its
 * mail, its billing profile, and its payments, and all of that is reused as it
 * stands. What Commerce cannot do is refuse a second attempt on the same order, or
 * a second attempt on the same intent, at the database level — and a check in PHP
 * is not the same guarantee when two payment notifications arrive together.
 *
 * So there are three unique keys here, and they are the whole justification for
 * the table: one attempt per order, one attempt per intent, and one attempt per
 * Dispatch row. Everything else in it is a snapshot the lifecycle must be able to
 * read back without asking another system what it used to say.
 *
 * The store writes no contact detail, no request payload, and no secret. It never
 * touches the Dispatch reservation table: that belongs to the Resource Calendar
 * Guard, and a second writer there would be a second authority on capacity.
 */
final class CheckoutAttemptStore {

  /**
   * The private lifecycle table. Never joined to from a public response.
   */
  const TABLE = 'custom_webform_handlers_checkout_attempt';

  /**
   * Every column an attempt is rebuilt from. There is no secret among them.
   */
  const FIELDS = [
    'attempt_id',
    'order_id',
    'webform_id',
    'submission_id',
    'resource_id',
    'pickup_arrival',
    'timezone',
    'quote_snapshot_id',
    'quote_amount_cents',
    'quote_currency',
    'quote_rate_version',
    'state',
    'idempotency_key',
    'dispatch_reservation_id',
    'created',
    'changed',
    'settled',
  ];

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Writes a pending attempt, or returns the one that already means this.
   *
   * The collision is the feature. Two payment submissions for the same job at the
   * same arrival for the same price derive the same idempotency key, so the second
   * insert violates a unique index and this returns the first attempt instead of
   * opening a second order. The caller therefore does not have to hold a lock
   * across "look for one" and "make one", which is the window a double-clicked
   * button lives in.
   *
   * NULL means the attempt was neither written nor found, which the caller must
   * treat as a refused checkout: an order with no attempt row cannot be confirmed
   * and must not be left looking payable.
   */
  public function open(CheckoutAttempt $attempt): ?CheckoutAttempt {
    if ($attempt->orderId < 1 || $attempt->submissionId < 1) {
      return NULL;
    }

    try {
      $attemptId = (int) $this->database->insert(self::TABLE)
        ->fields([
          'order_id' => $attempt->orderId,
          'webform_id' => $attempt->slot->webformId,
          'submission_id' => $attempt->submissionId,
          'resource_id' => $attempt->slot->resourceId,
          'pickup_arrival' => $attempt->slot->startTimestamp,
          'timezone' => $attempt->slot->timezone,
          'quote_snapshot_id' => $attempt->quote->snapshotId,
          'quote_amount_cents' => $attempt->quote->amountCents,
          'quote_currency' => $attempt->quote->currency,
          'quote_rate_version' => $attempt->quote->rateVersion,
          'state' => CheckoutState::Pending->value,
          'idempotency_key' => $attempt->idempotencyKey,
          'dispatch_reservation_id' => NULL,
          'created' => $attempt->created,
          'changed' => $attempt->changed,
          'settled' => NULL,
        ])
        ->execute();
    }
    catch (IntegrityConstraintViolationException) {
      // Deliberately not logged as an error. A repeat is an expected outcome of a
      // customer pressing pay twice, not a fault, and the existing row is the
      // correct answer to give them.
      return $this->loadByKey($attempt->idempotencyKey);
    }

    return $attemptId > 0 ? $this->loadByOrder($attempt->orderId) : NULL;
  }

  /**
   * Reads the attempt one order pays through, or NULL when there is none.
   *
   * An order with no attempt is not an error state to repair. Commerce orders can
   * exist for reasons this module knows nothing about, and the honest answer for
   * one of those is that it is not a dispatch checkout.
   */
  public function loadByOrder(int $orderId): ?CheckoutAttempt {
    if ($orderId < 1) {
      return NULL;
    }

    $row = $this->database->select(self::TABLE, 'a')
      ->fields('a', self::FIELDS)
      ->condition('order_id', $orderId)
      ->range(0, 1)
      ->execute()
      ->fetchObject();

    return $row === FALSE || $row === NULL ? NULL : $this->hydrate($row);
  }

  /**
   * Moves a pending attempt to a settled state, once.
   *
   * The pending condition is on the UPDATE rather than read first and checked in
   * PHP, so the transition is a single atomic compare-and-set. Two payment
   * notifications racing each other therefore produce exactly one settlement: the
   * loser updates nothing and is told so, which is what lets confirmPaid() treat a
   * repeat as success without confirming twice.
   *
   * FALSE means the row was not pending — already paid, already cancelled, already
   * failed, or absent. It is not distinguished here on purpose: the caller reloads
   * and decides, because "already paid" and "already cancelled" call for opposite
   * answers and only the caller knows which it was hoping for.
   */
  public function settle(int $orderId, CheckoutState $state, ?int $dispatchReservationId = NULL): bool {
    if ($orderId < 1 || !$state->isSettlement()) {
      return FALSE;
    }

    $now = $this->time->getRequestTime();
    $fields = [
      'state' => $state->value,
      'changed' => $now,
      'settled' => $now,
    ];

    // A confirmed attempt names the Dispatch row the store just confirmed, and
    // only ever that one. Paid and ReconciliationRequired are both confirmed —
    // the Reservation exists in each, and they differ only in whether the money
    // was captured — so both carry the identifier. A cancelled or failed attempt
    // never held one to name.
    $confirmed = $state->hasReservation();
    if ($confirmed && $dispatchReservationId !== NULL && $dispatchReservationId > 0) {
      $fields['dispatch_reservation_id'] = $dispatchReservationId;
    }

    // An abandoned or failed attempt gives its intent back. The key is unique for
    // as long as it is set, and a permanently-held key would mean a customer who
    // cancelled, or whose card was declined, could never book that same Slot at
    // that same price again — the unique index would refuse the retry forever. A
    // confirmed attempt keeps its key, which is the case the index exists for —
    // and a Reservation still awaiting capture keeps it too, so an uncapturable
    // booking cannot be re-booked into a second order while an operator resolves
    // the money.
    if (!$confirmed) {
      $fields['idempotency_key'] = NULL;
    }

    $updated = (int) $this->database->update(self::TABLE)
      ->fields($fields)
      ->condition('order_id', $orderId)
      ->condition('state', CheckoutState::Pending->value)
      ->execute();

    return $updated > 0;
  }

  /**
   * Reads the attempt that already means this intent, before an order exists.
   *
   * This is the cheap answer to a double-clicked pay button. Without it the second
   * click would reach the planner, be refused because the Slot it wants is the one
   * it is already holding, and tell a customer their own Hold was unavailable.
   *
   * It finds a pending attempt and a paid one, and cannot find a cancelled or
   * failed one: settling to either releases the key, so the intent is free to be
   * attempted again. That is deliberate, and it is why a declined card does not
   * lock a Slot out of a retry.
   */
  public function loadByIntent(int $submissionId, Slot $slot, QuoteSnapshot $quote): ?CheckoutAttempt {
    if ($submissionId < 1) {
      return NULL;
    }

    return $this->loadByKey(CheckoutAttempt::intentKey($submissionId, $slot, $quote));
  }

  /**
   * Reads the one attempt still in flight for a job, whatever its intent.
   *
   * One job pays for one arrival at a time. Two pending attempts for a single
   * submission would mean one customer holding two Slots on the same Resource for
   * the same work, which is capacity nobody can buy. The caller refuses the second
   * rather than opening it; a visitor who wants a different arrival cancels first,
   * which is what cancel() is for.
   */
  public function openForSubmission(int $submissionId): ?CheckoutAttempt {
    if ($submissionId < 1) {
      return NULL;
    }

    $row = $this->database->select(self::TABLE, 'a')
      ->fields('a', self::FIELDS)
      ->condition('submission_id', $submissionId)
      ->condition('state', CheckoutState::Pending->value)
      ->orderBy('attempt_id', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchObject();

    return $row === FALSE || $row === NULL ? NULL : $this->hydrate($row);
  }

  /**
   * Reads one attempt by its derived intent key.
   *
   * Private because the key is a derivation, not a name a caller should hold. The
   * two callers above both pass the three facts an intent is made of and let the
   * derivation happen once, so there is no signature anywhere that accepts a key a
   * caller chose — which is what the unique index depends on.
   */
  private function loadByKey(string $idempotencyKey): ?CheckoutAttempt {
    if ($idempotencyKey === '') {
      return NULL;
    }

    $row = $this->database->select(self::TABLE, 'a')
      ->fields('a', self::FIELDS)
      ->condition('idempotency_key', $idempotencyKey)
      ->range(0, 1)
      ->execute()
      ->fetchObject();

    return $row === FALSE || $row === NULL ? NULL : $this->hydrate($row);
  }

  /**
   * Rebuilds one stored row into an attempt, or NULL when it no longer reads.
   *
   * Every part is rebuilt through its own validating constructor rather than
   * assigned, so a row that has stopped being coherent — a zone that is no longer
   * an IANA identifier, an amount that no longer makes a price, a state string
   * nothing recognises — becomes NULL here instead of a plausible-looking attempt.
   * Slot throws on a malformed zone precisely so a stale stored zone can never
   * quietly resolve to UTC, and that throw is answered here rather than passed to
   * a caller with no way to answer it.
   *
   * Logged at warning rather than error: one unreadable row is a data question for
   * an administrator, and the request it interrupted has already been refused.
   */
  private function hydrate(object $row): ?CheckoutAttempt {
    $state = CheckoutState::fromStored(isset($row->state) ? (string) $row->state : NULL);
    $quote = QuoteSnapshot::fromStored(
      isset($row->quote_snapshot_id) ? (string) $row->quote_snapshot_id : NULL,
      isset($row->quote_amount_cents) ? (int) $row->quote_amount_cents : NULL,
      isset($row->quote_currency) ? (string) $row->quote_currency : NULL,
      isset($row->quote_rate_version) ? (string) $row->quote_rate_version : NULL,
    );
    $attemptId = (int) ($row->attempt_id ?? 0);

    if ($state === NULL || $quote === NULL) {
      $this->logger->warning('Checkout attempt @id was not read: its stored state or locked amount is no longer coherent.', [
        '@id' => $attemptId,
      ]);
      return NULL;
    }

    try {
      $slot = new Slot(
        (string) ($row->webform_id ?? ''),
        (string) ($row->resource_id ?? ''),
        (int) ($row->pickup_arrival ?? 0),
        (string) ($row->timezone ?? ''),
      );
    }
    catch (\InvalidArgumentException $e) {
      $this->logger->warning('Checkout attempt @id was not read: @message', [
        '@id' => $attemptId,
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }

    return CheckoutAttempt::stored(
      $attemptId,
      (int) ($row->order_id ?? 0),
      (int) ($row->submission_id ?? 0),
      $slot,
      $quote,
      $state,
      (string) ($row->idempotency_key ?? ''),
      isset($row->dispatch_reservation_id) ? (int) $row->dispatch_reservation_id : NULL,
      (int) ($row->created ?? 0),
      (int) ($row->changed ?? 0),
      isset($row->settled) ? (int) $row->settled : NULL,
    );
  }
}
