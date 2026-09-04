<?php

namespace Drupal\custom_webform_handlers\Dispatch\Route;

use Drupal\custom_webform_handlers\Dispatch\RouteEstimate;

/**
 * Estimates driving time between two points for a given departure.
 *
 * The departure time is load-bearing: the same pair of addresses is not the same
 * journey at 08:00 and at 17:00, and an implementation is expected to ask its
 * provider about the hour it was given rather than about an average day. Because
 * the seam was shaped this way from the outset, becoming traffic-aware was a change
 * inside one adapter and none at all to the callers.
 *
 * Implementations must not leak the provider's raw payload. Only duration,
 * distance, and whether the departure time influenced the answer cross this seam.
 *
 * An implementation that could not obtain a traffic-aware figure must say so
 * through RouteEstimate::$trafficAware and must not return the traffic-free
 * duration unmodified. Either widen it conservatively or return NULL; a figure that
 * is optimistic but indistinguishable from a scheduled one is the one outcome this
 * contract forbids.
 */
interface DispatchRouteEstimatorInterface {

  /**
   * Estimates one leg.
   *
   * @param string $origin
   *   Where the Resource departs from.
   * @param string $destination
   *   Where the Resource is heading.
   * @param int $departureAt
   *   When the Resource departs, in UTC. An implementation may quantize this to a
   *   coarser bucket before asking its provider, so that measuring a whole day
   *   does not cost one request per candidate arrival.
   *
   * @return \Drupal\custom_webform_handlers\Dispatch\RouteEstimate|null
   *   The estimate, or NULL when no route could be established. NULL must be
   *   treated as "cannot schedule this leg", never as zero travel time.
   */
  public function estimate(string $origin, string $destination, int $departureAt): ?RouteEstimate;

}
