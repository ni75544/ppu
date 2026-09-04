<?php

namespace Drupal\custom_webform_handlers\Dispatch;

/**
 * One interval during which a Resource is not free, as a read model.
 *
 * Returned by the store so the planner can decide what to offer without ever
 * seeing a row. It carries no token hash, no owner hash, no submission link, and
 * no price: nothing here is a secret, so the same value may be used to build an
 * availability answer.
 *
 * Times are UTC timestamps and never depend on a stored timezone, so an interval
 * remains trustworthy even if the row it came from has a corrupt zone.
 */
final readonly class OccupiedInterval {

  /**
   * A Dispatch Window belonging to a held Hold.
   */
  const SOURCE_HELD = 'held';

  /**
   * A Dispatch Window belonging to a confirmed Reservation.
   */
  const SOURCE_CONFIRMED = 'confirmed';

  /**
   * An administrative Resource Blackout.
   */
  const SOURCE_BLACKOUT = 'blackout';

  /**
   * @param string $resourceId
   *   The Resource that is occupied.
   * @param int $startTimestamp
   *   When the Resource stops being free, in UTC.
   * @param int $endTimestamp
   *   When the Resource becomes free again, in UTC.
   * @param string $source
   *   One of the SOURCE_* constants, so a caller can distinguish a booking from
   *   an administrative closure without inspecting storage.
   * @param string $reason
   *   Free text for a blackout, empty otherwise.
   */
  public function __construct(
    public string $resourceId,
    public int $startTimestamp,
    public int $endTimestamp,
    public string $source,
    public string $reason = '',
  ) {}

  /**
   * Tells whether this interval intersects a half-open range.
   *
   * Touching ends do not intersect: an interval ending exactly when another
   * begins is contiguous, not overlapping.
   */
  public function intersects(int $start, int $end): bool {
    return $this->startTimestamp < $end && $start < $this->endTimestamp;
  }

}
