<?php

namespace Drupal\custom_webform_handlers\Dispatch;

/**
 * One Resource's working day, resolved from local rules into UTC instants.
 *
 * The Resource Profile states working hours as local wall-clock strings. Those
 * cannot be compared with a Dispatch Window until they are resolved against the
 * actual calendar date, because the offset can differ from one date to the next.
 * Resolving once, here, means every downstream comparison is a plain integer
 * comparison and no scheduling arithmetic ever touches a timezone again.
 */
final readonly class ResourceDay {

  /**
   * @param string $localDate
   *   The Y-m-d this day represents, in the Resource's zone.
   * @param int $workStart
   *   First instant of the working interval, in UTC.
   * @param int $workEnd
   *   Last instant of the working interval, in UTC. Work must finish by here.
   * @param array<int, array{start: int, end: int}> $breaks
   *   Break intervals in UTC, ascending, that no Dispatch Window may overlap.
   * @param int $gridMinutes
   *   The pickup-arrival cadence for this day.
   */
  public function __construct(
    public string $localDate,
    public int $workStart,
    public int $workEnd,
    public array $breaks,
    public int $gridMinutes,
  ) {}

  /**
   * Tells whether an interval falls entirely inside the working interval.
   */
  public function contains(int $start, int $end): bool {
    return $start >= $this->workStart && $end <= $this->workEnd;
  }

  /**
   * Tells whether an interval overlaps any break.
   *
   * Half-open: work that ends exactly when a break starts is contiguous with it,
   * not in conflict.
   */
  public function overlapsBreak(int $start, int $end): bool {
    foreach ($this->breaks as $break) {
      if ($start < $break['end'] && $break['start'] < $end) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
