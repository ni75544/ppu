<?php

namespace Drupal\custom_webform_handlers\Dispatch;

/**
 * A driving estimate between two points, for one departure time.
 *
 * Carries only what scheduling and locked revalidation need. The provider's raw
 * payload and geometry are deliberately left behind at the estimator seam. The
 * small provenance set below exists so a prepared fact can be rejected after a
 * policy change or expiry without asking an external provider while calendar
 * locks are held.
 *
 * The departure time is recorded because the same pair of addresses does not
 * take the same time at 08:00 and at 17:00. The estimator's adapter
 * seam asks its route chain about that hour.
 */
final readonly class RouteEstimate {

  /**
   * Constructs an immutable route estimate.
   *
   * @param int $seconds
   *   Driving duration.
   * @param int $meters
   *   Driving distance.
   * @param int $departureAt
   *   The departure instant this estimate was requested for, in UTC.
   * @param bool $trafficAware
   *   Whether traffic at the departure instant actually informed the figure.
   *   FALSE does not mean the figure is unusable — the estimator widens a
   *   traffic-free duration by a conservative margin before reporting it — but
   *   it means no traffic data stands behind it, and the planner is entitled
   *   to refuse to schedule against it.
   * @param string $purpose
   *   The trusted routing purpose of the estimator that produced the fact.
   * @param string $provider
   *   The normalized provider identifier.
   * @param string $routeFingerprint
   *   A one-way identity over endpoints, provider, and effective departure.
   * @param int $calculatedAt
   *   When the route was originally calculated, in UTC.
   * @param int $fetchedAt
   *   When the provider was originally asked, in UTC.
   * @param int|null $expiresAt
   *   The last instant at which this prepared fact may be reused.
   * @param int|null $effectiveDepartureAt
   *   The departure instant the provider actually routed for.
   * @param string $endpointFingerprint
   *   A salted provider-endpoint identity, never an endpoint URL.
   */
  public function __construct(
    public int $seconds,
    public int $meters,
    public int $departureAt,
    public bool $trafficAware = FALSE,
    public string $purpose = '',
    public string $provider = '',
    public string $routeFingerprint = '',
    public int $calculatedAt = 0,
    public int $fetchedAt = 0,
    public ?int $expiresAt = NULL,
    public ?int $effectiveDepartureAt = NULL,
    public string $endpointFingerprint = '',
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
