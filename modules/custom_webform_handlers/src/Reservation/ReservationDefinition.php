<?php

namespace Drupal\custom_webform_handlers\Reservation;

/**
 * The server's schedule rules for one Webform and Resource.
 *
 * This is derived from the Webform's own handler configuration, never from a
 * client-supplied schedule. It is the input to every Slot derivation and to the
 * Availability read model.
 */
final class ReservationDefinition {

  /**
   * @param string[] $timeOptions
   *   Canonical HH:MM option keys, in the order the Webform declares them.
   * @param int[] $allowedWeekdays
   *   ISO-8601 weekday numbers (1 = Monday … 7 = Sunday).
   * @param string[] $resourceOptions
   *   Canonical resource keys, empty when the handler configures no resource
   *   element and every Slot therefore belongs to the "default" Resource.
   */
  public function __construct(
    public readonly string $webformId,
    public readonly string $resourceId,
    public readonly string $timezone,
    public readonly int $durationMinutes,
    public readonly int $holdSeconds,
    public readonly int $bookingWindowDays,
    public readonly array $allowedWeekdays,
    public readonly array $timeOptions,
    public readonly array $resourceOptions = [],
  ) {}

  /**
   * Returns the same definition bound to another Resource.
   */
  public function withResource(string $resourceId): self {
    return new self(
      $this->webformId,
      $resourceId,
      $this->timezone,
      $this->durationMinutes,
      $this->holdSeconds,
      $this->bookingWindowDays,
      $this->allowedWeekdays,
      $this->timeOptions,
      $this->resourceOptions,
    );
  }

  /**
   * Returns the timezone the schedule is expressed in.
   */
  public function timezoneObject(): \DateTimeZone {
    return new \DateTimeZone($this->timezone);
  }

  /**
   * Returns the constraints that are safe to publish to the browser.
   *
   * Deliberately excludes tokens, database identifiers, owner data, and any
   * availability snapshot, so this can travel in a cached render array.
   */
  public function publicConstraints(): array {
    return [
      'timezone' => $this->timezone,
      'duration_minutes' => $this->durationMinutes,
      'hold_seconds' => $this->holdSeconds,
      'booking_window_days' => $this->bookingWindowDays,
      'allowed_weekdays' => array_values($this->allowedWeekdays),
    ];
  }

}
