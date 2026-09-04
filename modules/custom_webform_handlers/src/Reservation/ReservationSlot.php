<?php

namespace Drupal\custom_webform_handlers\Reservation;

/**
 * One canonical, single-capacity interval, expressed in UTC.
 *
 * A Slot is server-derived: only ReservationDefinitionProvider builds one, from
 * the Webform's own handler configuration. The browser may display a Slot but
 * can neither define nor reserve one, so nothing here is constructed from raw
 * request input.
 */
final class ReservationSlot {

  public function __construct(
    public readonly string $webformId,
    public readonly string $resourceId,
    public readonly int $startTimestamp,
    public readonly int $endTimestamp,
    public readonly string $timezone,
    public readonly int $holdSeconds,
  ) {}

  /**
   * Returns the value the database unique key uses to resolve contention.
   *
   * This is the whole locking mechanism: two requests deriving the same Slot
   * derive the same key, and the unique index lets exactly one of them own it.
   */
  public function activeSlotKey(): string {
    return hash('sha256', implode('|', [
      $this->webformId,
      $this->resourceId,
      (string) $this->startTimestamp,
    ]));
  }

  /**
   * Returns the canonical HH:MM label for this Slot in its own timezone.
   */
  public function localTime(): string {
    return $this->localStart()->format('H:i');
  }

  /**
   * Returns the canonical Y-m-d date for this Slot in its own timezone.
   */
  public function localDate(): string {
    return $this->localStart()->format('Y-m-d');
  }

  private function localStart(): \DateTimeImmutable {
    return (new \DateTimeImmutable('@' . $this->startTimestamp))
      ->setTimezone(new \DateTimeZone($this->timezone));
  }

}
