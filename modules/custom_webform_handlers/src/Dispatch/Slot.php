<?php

namespace Drupal\custom_webform_handlers\Dispatch;

/**
 * A server-canonical pickup-arrival option.
 *
 * A Slot is the moment the Resource arrives at the customer's pickup location —
 * not the length of the job, and not the customer's whole appointment. What the
 * Resource is then occupied by is the Dispatch Window, which is derived
 * separately and server-side.
 *
 * The instant is held in UTC, and the timezone is carried alongside it so the
 * local wall-clock reading a visitor saw can always be reproduced exactly, even
 * if the Resource Profile's zone is changed afterwards.
 *
 * Per CONTEXT.md the browser may display a Slot but cannot define or reserve
 * one, so nothing here is constructed from browser input without the planner
 * re-deriving it.
 */
final readonly class Slot {

  /**
   * @param string $webformId
   *   The Webform this Slot belongs to.
   * @param string $resourceId
   *   The Resource whose capacity this Slot consumes.
   * @param int $startTimestamp
   *   Pickup arrival, as a UTC timestamp.
   * @param string $timezone
   *   The IANA zone the local reading below is expressed in.
   */
  public function __construct(
    public string $webformId,
    public string $resourceId,
    public int $startTimestamp,
    public string $timezone,
  ) {
    // Fail closed, including when this Slot is being reconstructed from a stored
    // row. A stale or malformed stored zone must never resolve to UTC: the
    // instant would stay the same while the local pickup time moved by the
    // offset, producing a schedule that is entirely self-consistent and wrong —
    // every check would pass and the driver would arrive at the wrong hour.
    if (!in_array($timezone, \DateTimeZone::listIdentifiers(), TRUE)) {
      throw new \InvalidArgumentException(sprintf(
        'Slot for Webform "%s" on Resource "%s" has timezone "%s", which is not an IANA timezone this server recognises. No default is substituted.',
        $webformId,
        $resourceId,
        $timezone
      ));
    }
  }

  /**
   * Returns the Slot's timezone.
   *
   * Always resolvable: an unrecognised zone was refused at construction.
   */
  public function timezoneObject(): \DateTimeZone {
    return new \DateTimeZone($this->timezone);
  }

  /**
   * Returns pickup arrival as the visitor read it.
   */
  public function localStart(): \DateTimeImmutable {
    return (new \DateTimeImmutable('@' . $this->startTimestamp))
      ->setTimezone($this->timezoneObject());
  }

  /**
   * Returns the local calendar date of pickup arrival.
   */
  public function localDate(): string {
    return $this->localStart()->format('Y-m-d');
  }

  /**
   * Returns the local HH:MM of pickup arrival.
   */
  public function localTime(): string {
    return $this->localStart()->format('H:i');
  }

  /**
   * Tells whether pickup arrival sits on a grid of this many minutes.
   *
   * Measured against local midnight rather than the epoch, because a zone whose
   * offset is not a whole number of hours would otherwise read as off-grid.
   */
  public function isAlignedTo(int $gridMinutes): bool {
    if ($gridMinutes <= 0) {
      return FALSE;
    }
    $local = $this->localStart();
    $minutesIntoDay = ((int) $local->format('G')) * 60 + (int) $local->format('i');
    return (int) $local->format('s') === 0 && $minutesIntoDay % $gridMinutes === 0;
  }

}
