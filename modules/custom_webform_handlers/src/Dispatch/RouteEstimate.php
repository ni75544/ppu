<?php

namespace Drupal\custom_webform_handlers\Dispatch;

/**
 * A driving estimate between two points, for one departure time.
 *
 * Carries only what scheduling needs. The provider's raw payload, geometry, and
 * cache metadata are deliberately left behind at the estimator seam, so nothing
 * downstream can leak a route response into an offer.
 *
 * The departure time is recorded because the same pair of addresses does not take
 * the same time at 08:00 and at 17:00, and the adapter behind the estimator seam
 * asks Google about that specific hour.
 */
final readonly class RouteEstimate {

  /**
   * @param int $seconds
   *   Driving duration.
   * @param int $meters
   *   Driving distance.
   * @param int $departureAt
   *   The departure instant this estimate was requested for, in UTC.
   * @param bool $trafficAware
   *   Whether traffic at the departure instant actually informed the figure.
   *   FALSE does not mean the figure is unusable — the estimator widens a
   *   traffic-free duration by a conservative margin before reporting it — but it
   *   does mean no traffic data stands behind it, and the planner is entitled to
   *   refuse to schedule against it.
   */
  public function __construct(
    public int $seconds,
    public int $meters,
    public int $departureAt,
    public bool $trafficAware = FALSE,
  ) {
    if ($seconds < 0) {
      throw new \InvalidArgumentException('A route cannot take negative time.');
    }
  }

  /**
   * Returns the duration rounded up to whole minutes.
   *
   * Rounded up rather than to nearest: scheduling must never assume the driver
   * arrives sooner than the estimate allows.
   */
  public function minutesCeiling(): int {
    return (int) ceil($this->seconds / 60);
  }

}
