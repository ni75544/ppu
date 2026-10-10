<?php

namespace Drupal\custom_webform_handlers\Reservation;

/**
 * One reservation row, reduced to what an administrator may see.
 *
 * The table also stores an active slot key, a hold token hash, and an owner
 * hash. None of them appear here: the key is an internal locking detail, and the
 * two hashes are secrets whose only purpose is to be compared against a value
 * the holder already has. A record that cannot carry them cannot leak them into
 * a page, a log line, or a cached render array.
 */
final class ReservationRecord {

  public function __construct(
    public readonly int $reservationId,
    public readonly string $webformId,
    public readonly string $resourceId,
    public readonly int $slotStart,
    public readonly int $slotEnd,
    public readonly string $timezone,
    public readonly string $state,
    public readonly ?int $submissionId,
    public readonly ?int $expiresAt,
    public readonly int $created,
    public readonly int $changed,
    // Booking-admission generation (Task 24C-7A). NULL for a row that was never
    // enrolled and for one read without the column; passive carriage only.
    public readonly ?int $admissionGeneration = NULL,
  ) {}

  /**
   * Builds a record from one selected row.
   *
   * Only the columns named here are read, so widening the query cannot quietly
   * widen what an administrator's screen can show.
   */
  public static function fromRow(object $row): self {
    return new self(
      (int) $row->reservation_id,
      (string) $row->webform_id,
      (string) $row->resource_id,
      (int) $row->slot_start,
      (int) $row->slot_end,
      (string) $row->timezone,
      (string) $row->state,
      $row->submission_id === NULL ? NULL : (int) $row->submission_id,
      // A timestamp, not a secret: an administrator needs to see how long a Hold
      // still stands. Confirmed and blocked rows carry no expiry at all.
      ($row->expires_at ?? NULL) === NULL ? NULL : (int) $row->expires_at,
      (int) $row->created,
      (int) $row->changed,
      // Present only when the reader selected it (migrated schema); isset() keeps
      // an unselected or NULL column as NULL rather than zero (Task 24C-7A).
      isset($row->admission_generation) ? (int) $row->admission_generation : NULL,
    );
  }

  /**
   * Returns the Slot's local calendar date, as the visitor saw it.
   */
  public function localDate(): string {
    return $this->localStart()->format('Y-m-d');
  }

  /**
   * Returns the Slot's local start time, as the visitor saw it.
   */
  public function localTime(): string {
    return $this->localStart()->format('H:i');
  }

  /**
   * Returns the Slot's local end time.
   */
  public function localEndTime(): string {
    return (new \DateTimeImmutable('@' . $this->slotEnd))
      ->setTimezone($this->timezoneObject())
      ->format('H:i');
  }

  /**
   * Tells whether this record still holds its Slot out of availability.
   *
   * An expired or released row is history: it owns no active key, so there is
   * nothing left for an administrator to release.
   */
  public function isActive(): bool {
    return in_array($this->state, [
      DatabaseReservationManager::STATE_HELD,
      DatabaseReservationManager::STATE_CONFIRMED,
      DatabaseReservationManager::STATE_BLOCKED,
    ], TRUE);
  }

  /**
   * Returns the Slot's own timezone, falling back to UTC if it is unknown.
   */
  public function timezoneObject(): \DateTimeZone {
    try {
      return new \DateTimeZone($this->timezone);
    }
    catch (\Exception) {
      return new \DateTimeZone('UTC');
    }
  }

  private function localStart(): \DateTimeImmutable {
    return (new \DateTimeImmutable('@' . $this->slotStart))
      ->setTimezone($this->timezoneObject());
  }

}
