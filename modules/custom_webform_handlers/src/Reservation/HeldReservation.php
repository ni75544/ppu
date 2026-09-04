<?php

namespace Drupal\custom_webform_handlers\Reservation;

/**
 * The result of a successful Hold.
 *
 * The raw bearer token exists only in this object, and only once: the database
 * stores nothing but its SHA-256 hash, so a leaked row cannot be replayed as a
 * Hold and a lost token cannot be recovered.
 */
final class HeldReservation {

  public function __construct(
    public readonly string $token,
    public readonly int $expiresAt,
    public readonly ReservationSlot $slot,
  ) {}

  /**
   * Returns the response body fields that are safe to hand to the browser.
   *
   * Deliberately minimal. The browser already knows which date and time it
   * asked for, and echoing the derived Slot back would invite the JavaScript to
   * treat this response as the authority on the schedule instead of the server.
   */
  public function toArray(): array {
    return [
      'hold_token' => $this->token,
      'expires_at' => $this->expiresAt,
    ];
  }

}
