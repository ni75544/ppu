<?php

namespace Drupal\custom_webform_handlers\Dispatch;

/**
 * The outcome of turning a Hold into a Reservation.
 *
 * Per CONTEXT.md a Reservation is a confirmed claim linked to a completed
 * Webform submission, and it stays unavailable until an explicit administrative
 * release or a successful Replan. This result reports that the link was made; it
 * is not itself the Reservation.
 *
 * The identifier is opaque on purpose. Callers use it to refer to the
 * Reservation, never to locate a row, and no storage detail travels with it.
 */
final readonly class ReservationResult {

  /**
   * @param string|null $reservationId
   *   An opaque identifier for the confirmed Reservation, or NULL on refusal.
   * @param \Drupal\custom_webform_handlers\Dispatch\Slot|null $slot
   *   The Slot now reserved, or NULL on refusal.
   * @param \Drupal\custom_webform_handlers\Dispatch\DispatchReason $reason
   *   Available on success, otherwise why confirmation was refused.
   * @param int|null $windowStart
   *   Start of the Dispatch Window recorded against the Reservation.
   * @param int|null $windowEnd
   *   End of that window.
   */
  private function __construct(
    public ?string $reservationId,
    public ?Slot $slot,
    public DispatchReason $reason,
    public ?int $windowStart = NULL,
    public ?int $windowEnd = NULL,
  ) {}

  /**
   * The Hold became a Reservation.
   *
   * The window instants are optional so that a refusal-shaped caller and an older
   * confirmation site both keep working, but storage passes them: they are the
   * span that was actually proved and written, and a Slot cannot carry them — it
   * names the pickup arrival and nothing else. Anything that has to state what was
   * promised, an announcement above all, needs the window rather than the arrival
   * alone, and reading it off the result avoids a second query for a value the
   * write already had in hand.
   */
  public static function confirmed(string $reservationId, Slot $slot, ?int $windowStart = NULL, ?int $windowEnd = NULL): self {
    return new self($reservationId, $slot, DispatchReason::Available, $windowStart, $windowEnd);
  }

  /**
   * The Hold did not become a Reservation, and this is why.
   */
  public static function refused(DispatchReason $reason): self {
    return new self(NULL, NULL, $reason);
  }

  /**
   * Tells whether a Reservation now exists.
   */
  public function isConfirmed(): bool {
    return $this->reservationId !== NULL;
  }

}
