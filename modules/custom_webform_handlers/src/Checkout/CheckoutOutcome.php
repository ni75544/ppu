<?php

namespace Drupal\custom_webform_handlers\Checkout;

use Drupal\custom_webform_handlers\Dispatch\DispatchReason;

/**
 * What a checkout use case may tell its caller, and nothing more.
 *
 * The same discipline as BookingOutcome, for the same reason: CheckoutAttempt
 * carries the internal Dispatch reservation identifier and Hold recovery goes
 * through the session, so handing the attempt to a controller would make "do not
 * publish internals" a matter of the controller remembering which fields to skip.
 * The conversion happens once, in the adapter, and this is what comes out.
 *
 * Nothing here ever carries a Hold token, an owner hash, a Hold expiry, a
 * Dispatch Window, or a Reservation identifier. Public pages have no Hold
 * countdown to render and no internal window to draw, so there is nothing they
 * could legitimately do with any of them.
 *
 * The order identifier is the one exception, and only on a started checkout.
 * Commerce's own checkout and payment routes are keyed by the order, so a caller
 * that may not learn it cannot send the customer to pay. That is a different
 * thing from the schedule responses CONTEXT.md keeps order identifiers out of:
 * those answer "when could this be collected" for anyone who asks, while this
 * answers "here is the order you just opened" to the one session that opened it.
 */
final readonly class CheckoutOutcome {

  /**
   * @param bool $ok
   *   Whether the use case did what was asked.
   * @param \Drupal\custom_webform_handlers\Dispatch\DispatchReason $reason
   *   Available on success, otherwise the stable refusal reason. The planner's
   *   vocabulary is reused rather than extended: a checkout is refused for the
   *   same operational reasons a claim is, and a second enum would only make two
   *   names for "the Slot went".
   * @param array $data
   *   Publishable payload. Every value here was chosen for publication; it is
   *   never a filtered view of a CheckoutAttempt.
   */
  private function __construct(
    public bool $ok,
    public DispatchReason $reason,
    public array $data,
  ) {}

  /**
   * An order exists, capacity is held, and payment may begin.
   */
  public static function started(int $orderId): self {
    return new self(TRUE, DispatchReason::Available, [
      'order_id' => $orderId,
      'state' => CheckoutState::Pending->value,
    ]);
  }

  /**
   * Payment succeeded and the store confirmed a Reservation.
   *
   * The Reservation identifier is deliberately absent. A customer needs to know
   * the booking exists; the row it lives in is an internal fact, and the
   * administrative board is where a Reservation is named.
   */
  public static function confirmed(): self {
    return new self(TRUE, DispatchReason::Available, [
      'state' => CheckoutState::Paid->value,
    ]);
  }

  /**
   * The attempt was abandoned before payment succeeded.
   */
  public static function cancelled(): self {
    return new self(TRUE, DispatchReason::Available, [
      'state' => CheckoutState::Cancelled->value,
    ]);
  }

  /**
   * Nothing happened, and this is the stable reason.
   *
   * A refusal carries no data at all, so a refused checkout cannot leak the
   * order it declined to open.
   */
  public static function refused(DispatchReason $reason): self {
    return new self(FALSE, $reason, []);
  }

}
