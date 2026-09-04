<?php

namespace Drupal\custom_webform_handlers\Reservation;

/**
 * A classified, safe-to-surface reservation failure.
 *
 * The message is always a machine code, never prose: callers map it to
 * user-facing copy, and the HTTP layer maps it to a status. Nothing here ever
 * carries owner, token, or database detail.
 */
final class ReservationException extends \RuntimeException {

  /**
   * The Slot already has an active Hold or Reservation.
   */
  const SLOT_UNAVAILABLE = 'slot_unavailable';

  /**
   * The Hold does not exist, is expired, or belongs to another session.
   */
  const HOLD_NOT_FOUND = 'hold_not_found';

  /**
   * The Webform has no usable reservation configuration.
   */
  const NOT_CONFIGURED = 'reservation_not_configured';

  /**
   * The submitted date is malformed.
   */
  const INVALID_DATE = 'invalid_date';

  /**
   * The submitted time is not one of the configured options.
   */
  const INVALID_TIME = 'invalid_time';

  /**
   * The submitted resource is not one of the configured options.
   */
  const INVALID_RESOURCE = 'invalid_resource';

  /**
   * The date falls outside the configured booking horizon.
   */
  const OUTSIDE_HORIZON = 'outside_booking_window';

  /**
   * The date falls on a weekday that is not open for booking.
   */
  const WEEKDAY_CLOSED = 'weekday_not_available';

  /**
   * The local time does not exist, because a DST jump skipped it.
   */
  const TIME_NONEXISTENT = 'local_time_nonexistent';

  /**
   * The local time occurs twice, because a DST fall-back repeats it.
   */
  const TIME_AMBIGUOUS = 'local_time_ambiguous';

  /**
   * No reservation record exists for the requested identifier.
   */
  const RESERVATION_NOT_FOUND = 'reservation_not_found';

  /**
   * The reservation record is already released or expired.
   */
  const RESERVATION_NOT_ACTIVE = 'reservation_not_active';

  public function __construct(string $code, ?\Throwable $previous = NULL) {
    parent::__construct($code, 0, $previous);
  }

  /**
   * Returns the machine code this failure carries.
   */
  public function getReservationCode(): string {
    return $this->getMessage();
  }

}
