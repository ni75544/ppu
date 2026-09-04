<?php

namespace Drupal\custom_webform_handlers\Dispatch;

/**
 * One Resource and the schedule rules that govern it.
 *
 * A Resource partitions Slot capacity, and the initial model has capacity one
 * per Resource and Slot. The first production Resource is driver-01-vehicle-01.
 *
 * Everything on this object arrives from configuration. Nothing here is a
 * default expressed in PHP: working hours, the depot, buffers, the grid, the
 * horizon, and the return-to-base requirement are all supplied by the caller
 * that read them, so an operator can change the operating profile without a code
 * change.
 *
 * The constructor validates shape, and refuses a timezone it cannot resolve. It
 * does not validate policy: whether the hours make commercial sense is the
 * operator's business, but whether they can be read at all is not.
 */
final readonly class ResourceProfile {

  /**
   * @param string $resourceId
   *   The canonical Resource identifier, for example driver-01-vehicle-01.
   * @param int $capacity
   *   Concurrent Dispatch Windows this Resource can serve. One, initially.
   * @param string $timezone
   *   The single IANA timezone every local time for this Resource is read in.
   * @param string $depotAddress
   *   Where the Resource starts and, when required, must return to.
   * @param int $gridMinutes
   *   The pickup-arrival grid. Slots exist only on this cadence.
   * @param array<string, array{start: string, end: string}> $workingHours
   *   ISO-8601 weekday number => local HH:MM start and end. A weekday absent
   *   from this map is not worked.
   * @param array<int, array{start: string, end: string}> $breaks
   *   Local HH:MM intervals inside working hours that no Dispatch Window may
   *   overlap.
   * @param string[] $blackoutDates
   *   Local Y-m-d dates on which this Resource offers nothing.
   * @param int $repositioningBufferMinutes
   *   Padding added to Repositioning between consecutive Reservations.
   * @param int $operationalBufferMinutes
   *   Padding at the end of every Dispatch Window before the Resource is free.
   * @param int $bookingHorizonDays
   *   How far ahead of today Slots may be offered.
   * @param bool $returnToBase
   *   Whether the Resource must be able to reach the depot within working hours
   *   after its last Dispatch Window.
   */
  public function __construct(
    public string $resourceId,
    public int $capacity,
    public string $timezone,
    public string $depotAddress,
    public int $gridMinutes,
    public array $workingHours,
    public array $breaks,
    public array $blackoutDates,
    public int $repositioningBufferMinutes,
    public int $operationalBufferMinutes,
    public int $bookingHorizonDays,
    public bool $returnToBase,
  ) {
    // Fail closed on the timezone. Every local reading in the planner — working
    // hours, breaks, blackouts, the grid, the horizon — is resolved through this
    // zone, so falling back to UTC would not degrade gracefully: it would move
    // every Slot by the offset and offer times the business does not work,
    // silently and self-consistently. A profile that cannot be resolved must not
    // exist.
    if (!in_array($timezone, \DateTimeZone::listIdentifiers(), TRUE)) {
      throw new \InvalidArgumentException(sprintf(
        'Resource "%s" has timezone "%s", which is not an IANA timezone this server recognises. The Dispatch Planner will not substitute a default.',
        $resourceId,
        $timezone
      ));
    }
  }

  /**
   * Returns the Resource's timezone.
   *
   * Always resolvable: an unrecognised zone was refused at construction, so
   * there is no fallback here and no way for a bad zone to reach this point.
   */
  public function timezoneObject(): \DateTimeZone {
    return new \DateTimeZone($this->timezone);
  }

  /**
   * Tells whether the profile still needs a depot before it can plan.
   *
   * Return-to-base cannot be evaluated without somewhere to return to, so this
   * combination must yield no Slots rather than Slots that ignore the rule. The
   * planner treats a TRUE here as not-configured and offers nothing.
   */
  public function requiresDepotConfiguration(): bool {
    return $this->returnToBase && trim($this->depotAddress) === '';
  }

  /**
   * Tells whether this Resource works on one ISO-8601 weekday number.
   */
  public function worksWeekday(int $isoWeekday): bool {
    return isset($this->workingHours[(string) $isoWeekday]);
  }

  /**
   * Returns the local working interval for one weekday, or NULL.
   *
   * @return array{start: string, end: string}|null
   *   Local HH:MM bounds, or NULL when the weekday is not worked.
   */
  public function workingHoursFor(int $isoWeekday): ?array {
    return $this->workingHours[(string) $isoWeekday] ?? NULL;
  }

  /**
   * Tells whether one local Y-m-d date is blacked out.
   */
  public function isBlackout(string $localDate): bool {
    return in_array($localDate, $this->blackoutDates, TRUE);
  }

}
