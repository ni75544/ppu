<?php

namespace Drupal\custom_webform_handlers\Dispatch;

/**
 * The outcome of moving one existing Reservation to a different pickup arrival.
 *
 * Replan is not an edit. A Reservation records what was proved about a job at a
 * particular time, and the schedule it sits in is what other bookings were
 * proved against, so a move is performed as a supersede-and-insert: the original
 * row is retired in the state that says so, and a new Reservation is written for
 * the new arrival inside the same locked transaction. That is why there are two
 * identifiers here. The board links to the new one; the old one is still there,
 * still readable, still carrying the window, repositioning and route snapshot the
 * original plan was proved against.
 *
 * A refusal carries a reason and nothing else, because a refused Replan changed
 * nothing at all: the original Reservation is exactly as it was, at the time it
 * was, and the caller's job is to say why the move could not be made rather than
 * to work around it. Every reason here is one of the planner's existing stable,
 * non-secret reasons — a refusal never discloses whose booking was in the way.
 *
 * Immutable.
 */
final readonly class ReplanResult {

  /**
   * @param int|null $reservationId
   *   The new Reservation, or NULL when nothing moved.
   * @param int|null $supersededReservationId
   *   The retired original, or NULL when nothing moved. Retained for audit.
   * @param \Drupal\custom_webform_handlers\Dispatch\Slot|null $slot
   *   The pickup arrival now reserved, or NULL when nothing moved.
   * @param \Drupal\custom_webform_handlers\Dispatch\DispatchReason $reason
   *   Available on success, otherwise why the move was refused.
   */
  private function __construct(
    public ?int $reservationId,
    public ?int $supersededReservationId,
    public ?Slot $slot,
    public DispatchReason $reason,
  ) {}

  /**
   * The Reservation now sits at a new, proved pickup arrival.
   */
  public static function moved(int $reservationId, int $supersededReservationId, Slot $slot): self {
    return new self($reservationId, $supersededReservationId, $slot, DispatchReason::Available);
  }

  /**
   * Nothing moved, and this is why. The original Reservation is untouched.
   */
  public static function refused(DispatchReason $reason): self {
    return new self(NULL, NULL, NULL, $reason);
  }

  /**
   * Tells whether the Reservation actually moved.
   */
  public function isMoved(): bool {
    return $this->reservationId !== NULL;
  }

}
