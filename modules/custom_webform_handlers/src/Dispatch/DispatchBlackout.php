<?php

namespace Drupal\custom_webform_handlers\Dispatch;

/**
 * One administrative Blackout: time withheld from the Resource on purpose.
 *
 * A Blackout is operator policy, not customer data. It has no Webform, no
 * submission, no address and no price, and there is nothing here that could
 * disclose one — which is why it is safe to list on an administrative screen
 * beside the schedule it closes.
 *
 * Withdrawn rows are represented, not hidden. Per the install schema a withdrawn
 * Blackout is kept for audit and stops consuming capacity, so an operator has to
 * be able to see that yesterday's closure was lifted and by which replacement.
 * A caller decides how to present that; it does not get to be surprised by it.
 *
 * Immutable.
 */
final readonly class DispatchBlackout {

  /**
   * @param int $blackoutId
   *   The row's identifier.
   * @param string $resourceId
   *   The Resource whose time is withheld.
   * @param int $startAt
   *   First instant withheld, in UTC.
   * @param int $endAt
   *   Last instant withheld, in UTC. Half-open: work starting here is unaffected.
   * @param string $reason
   *   The operator's own note, verbatim and plain text.
   * @param bool $active
   *   Whether this Blackout still consumes capacity. A withdrawn one does not.
   * @param string $timezone
   *   The Resource's IANA zone, so the interval can be rendered as the operator
   *   entered it. Derived from the Resource Profile, not stored on the row.
   * @param int $created
   *   When the row was written, in UTC.
   * @param int $changed
   *   When the row was last written, in UTC.
   */
  public function __construct(
    public int $blackoutId,
    public string $resourceId,
    public int $startAt,
    public int $endAt,
    public string $reason,
    public bool $active,
    public string $timezone,
    public int $created,
    public int $changed,
  ) {}

  /**
   * Returns the local calendar date the Blackout starts on.
   */
  public function localDate(): string {
    return $this->localStart()->format('Y-m-d');
  }

  /**
   * Returns the start instant in the Resource's zone.
   */
  public function localStart(): \DateTimeImmutable {
    return $this->localised($this->startAt);
  }

  /**
   * Returns the end instant in the Resource's zone.
   */
  public function localEnd(): \DateTimeImmutable {
    return $this->localised($this->endAt);
  }

  /**
   * Tells whether the Blackout is wholly in the past at a given instant.
   */
  public function isPast(int $now): bool {
    return $this->endAt <= $now;
  }

  /**
   * Restates one UTC instant in the Resource's zone.
   *
   * Falls back to UTC rather than throwing, because this is a display helper on
   * an administrative list: a profile whose zone has become unreadable is a
   * configuration problem the settings form reports, and it must not be able to
   * take the Blackout list down with it. Nothing schedules anything from here —
   * every scheduling decision resolves its own zone and fails closed.
   */
  private function localised(int $timestamp): \DateTimeImmutable {
    try {
      $zone = new \DateTimeZone($this->timezone);
    }
    catch (\Exception) {
      $zone = new \DateTimeZone('UTC');
    }

    return (new \DateTimeImmutable('@' . $timestamp))->setTimezone($zone);
  }

}
