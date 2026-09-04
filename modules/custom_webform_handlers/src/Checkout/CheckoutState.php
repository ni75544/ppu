<?php

namespace Drupal\custom_webform_handlers\Checkout;

/**
 * Where one private Checkout Attempt has got to.
 *
 * These five are the whole lifecycle, and they are deliberately not the Commerce
 * order's states. A Commerce order moves through draft, completed, and canceled
 * on its own workflow; that workflow answers "has the customer finished paying
 * for this order", which is a commerce question. This enum answers "may this
 * attempt still become a Reservation", which is a capacity question, and per
 * CONTEXT.md capacity is settled inside the Resource Calendar Guard transaction
 * and nowhere else. Keeping the two separate is what stops an order state — or an
 * ECA reaction to one, or a payment-return URL — from deciding that a driver's
 * day is sold.
 *
 * Nor are they the Dispatch row's states. A Dispatch row is held, confirmed, or
 * historical, and it is the store that moves it. An attempt in Paid means the
 * store confirmed; it never means the attempt decided.
 *
 * Pending is the only state with anything ahead of it, which is why every
 * transition below is a compare-and-set against it. Once an attempt has settled,
 * it stays settled: a paid attempt cannot be cancelled into releasing capacity a
 * customer has already been charged for, and a cancelled one cannot be revived
 * by a late payment notification arriving twice.
 */
enum CheckoutState: string {

  /**
   * Payment has begun. Capacity may be held, and no Reservation exists yet.
   *
   * The only state from which anything else is reachable.
   */
  case Pending = 'pending';

  /**
   * Payment succeeded and the store confirmed a Reservation for it.
   *
   * Terminal. Reached only after confirmWithRevalidation() returned a
   * Reservation identifier, so this state can never be ahead of the row.
   */
  case Paid = 'paid';

  /**
   * Payment did not succeed, so no Reservation was ever made.
   *
   * Terminal, and not the same as cancelled: nobody withdrew, the payment
   * failed. Any Hold this attempt made lapses on its own.
   */
  case Failed = 'failed';

  /**
   * The attempt was abandoned before payment succeeded.
   *
   * Terminal. Reached by cancel(), which refuses to touch a Paid attempt.
   */
  case Cancelled = 'cancelled';

  /**
   * A Reservation was confirmed, but its authorization could not be captured.
   *
   * Terminal, and the one residual money gap in the authorize-then-capture
   * model. The Resource Calendar Guard transaction committed, so the Reservation
   * exists and is the customer's; the later capturePayment() call is what failed
   * at the gateway. Releasing the Reservation is forbidden — a confirmed booking
   * is never given back by a payment path — so the capacity stands and an
   * operator resolves the money on Commerce's own order: retry the capture, or
   * void and refund. It is a settlement and not an open state, and it keeps its
   * idempotency key exactly as Paid does, so the job cannot be re-booked into a
   * second order while it waits.
   */
  case ReconciliationRequired = 'reconciliation_required';

  /**
   * Tells whether this state can still become something else.
   */
  public function isOpen(): bool {
    return $this === self::Pending;
  }

  /**
   * Tells whether this state is a legal destination for a settling transition.
   *
   * Pending is excluded because settling means leaving it: an attempt cannot
   * transition back to open, and a caller asking for that is asking for a
   * Reservation to become releasable again.
   */
  public function isSettlement(): bool {
    return $this !== self::Pending;
  }

  /**
   * Tells whether a Reservation exists behind this state.
   *
   * True for exactly the two settled states a confirmed booking reaches: Paid,
   * and ReconciliationRequired, where the booking stands but its money is still
   * being resolved. These are the states that name a Dispatch row and keep their
   * idempotency key, and the states confirmPaid() answers as success on a replay.
   * Failed and Cancelled never held a Reservation, and Pending does not hold one
   * yet — so this is the single question "did this attempt become a booking", asked
   * in one place instead of restated as a pair of equality checks wherever it is
   * needed.
   */
  public function hasReservation(): bool {
    return $this === self::Paid || $this === self::ReconciliationRequired;
  }

  /**
   * Returns the state a stored value names, or NULL when it names none.
   *
   * NULL rather than a default. A row whose state cannot be read is not treated
   * as pending — a pending attempt is one that may still take capacity, and an
   * unreadable state must never be granted that.
   */
  public static function fromStored(?string $stored): ?self {
    return $stored === NULL ? NULL : self::tryFrom(trim($stored));
  }

}
