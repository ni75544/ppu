<?php

namespace Drupal\custom_webform_handlers\Checkout;

use Drupal\custom_webform_handlers\Dispatch\Slot;

/**
 * One private attempt to pay for one Slot, as this module records it.
 *
 * Per CONTEXT.md selecting a Slot reserves nothing; starting payment creates a
 * private Checkout Attempt and an internal Hold, and only successful payment
 * creates a Reservation. This object is that attempt: it is what makes the middle
 * of that sentence durable, because a value object that lives only for the
 * request is not a lifecycle — a payment notification arriving on a second
 * request would have nothing to recognise.
 *
 * It is private in the same sense a Hold is. It carries the internal Dispatch
 * reservation identifier once one exists, so nothing here may be handed to a
 * controller, a template, or drupalSettings; CheckoutOutcome exists to be the
 * publishable answer, and it is built by naming safe values rather than by
 * filtering this object.
 *
 * What it deliberately does not carry is worth as much as what it does. There is
 * no Hold token and no owner hash: the store hashes those and this module never
 * holds a second copy. There is no Dispatch Window: the internal shape of the
 * Resource's day is not part of a payment record. There are no contact details:
 * Commerce's order owns the recipient and the Webform submission owns the
 * customer, and a third copy could only ever disagree with them. And there is no
 * serialized request payload: the submission identifier below is how the facts
 * are reached, so they stay in the one place that can still be re-normalised by
 * DispatchRequestFactory when the store re-proves feasibility.
 */
final readonly class CheckoutAttempt {

  /**
   * @param int|null $attemptId
   *   The storage identifier, or NULL for an attempt not yet written.
   * @param int $orderId
   *   The canonical Commerce order this attempt pays through.
   * @param int $submissionId
   *   The originating Webform submission. The link is server-owned: it is written
   *   from the saved submission, never from anything a browser sent.
   * @param \Drupal\custom_webform_handlers\Dispatch\Slot $slot
   *   The selected Slot, snapshotted immutably. Stored as the same three facts a
   *   Slot is built from, so the local pickup reading the visitor saw can always
   *   be reproduced exactly even if the Resource Profile's zone changes later.
   * @param \Drupal\custom_webform_handlers\Checkout\QuoteSnapshot $quote
   *   The locked amount this attempt was started against.
   * @param \Drupal\custom_webform_handlers\Checkout\CheckoutState $state
   *   Where the attempt has got to.
   * @param string $idempotencyKey
   *   Derived from the job, the Slot, and the price, so the same intent always
   *   produces the same key. A unique index on it is what makes a double-clicked
   *   pay button reach one order instead of two.
   * @param int|null $dispatchReservationId
   *   The internal Dispatch row that now holds this job's capacity, once the
   *   store has confirmed one. Never published.
   * @param int $created
   *   When payment began.
   * @param int $changed
   *   When the attempt last moved.
   * @param int|null $settled
   *   When it stopped being pending, or NULL while it still is.
   */
  private function __construct(
    public ?int $attemptId,
    public int $orderId,
    public int $submissionId,
    public Slot $slot,
    public QuoteSnapshot $quote,
    public CheckoutState $state,
    public string $idempotencyKey,
    public ?int $dispatchReservationId = NULL,
    public int $created = 0,
    public int $changed = 0,
    public ?int $settled = NULL,
  ) {}

  /**
   * The attempt as it is about to be written, pending and unsettled.
   *
   * The idempotency key is derived here rather than accepted, so no caller can
   * choose one — a caller that could choose it could defeat the unique index by
   * varying it, which is the one thing the key exists to prevent.
   */
  public static function opening(int $orderId, int $submissionId, Slot $slot, QuoteSnapshot $quote, int $now): self {
    return new self(
      NULL,
      $orderId,
      $submissionId,
      $slot,
      $quote,
      CheckoutState::Pending,
      self::intentKey($submissionId, $slot, $quote),
      NULL,
      $now,
      $now,
    );
  }

  /**
   * The attempt as one stored row reads.
   *
   * Every caller of this is inside the store, and the Slot and snapshot are
   * rebuilt through their own validating constructors, so a row that no longer
   * reads as a coherent attempt cannot be reconstructed into one.
   */
  public static function stored(int $attemptId, int $orderId, int $submissionId, Slot $slot, QuoteSnapshot $quote, CheckoutState $state, string $idempotencyKey, ?int $dispatchReservationId, int $created, int $changed, ?int $settled): self {
    return new self(
      $attemptId,
      $orderId,
      $submissionId,
      $slot,
      $quote,
      $state,
      $idempotencyKey,
      $dispatchReservationId,
      $created,
      $changed,
      $settled,
    );
  }

  /**
   * Tells whether this attempt may still become a Reservation.
   */
  public function isOpen(): bool {
    return $this->state->isOpen();
  }

  /**
   * Names one intent: this job, at this arrival, for this price.
   *
   * Deliberately not time-based and deliberately not random. Two requests that
   * mean the same booking must collide, because colliding is how the second one
   * is recognised as a repeat rather than served as a new order. A retry after a
   * genuine requote produces a different price and therefore a different key,
   * which is correct: that is a different intent.
   *
   * Public so the store can look an intent up before an order exists, which is
   * what lets a double-clicked pay button be answered with the order it already
   * opened instead of a second Hold on the same Slot. It takes the three facts and
   * derives the key; there is still no way for a caller to supply a key of its
   * own, which is the property the unique index depends on.
   */
  public static function intentKey(int $submissionId, Slot $slot, QuoteSnapshot $quote): string {
    return substr(hash('sha256', implode('|', [
      $submissionId,
      $slot->webformId,
      $slot->resourceId,
      $slot->startTimestamp,
      $quote->snapshotId,
    ])), 0, 64);
  }

}
