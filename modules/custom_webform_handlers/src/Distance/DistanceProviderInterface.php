<?php

namespace Drupal\custom_webform_handlers\Distance;

/**
 * Provides authoritative driving-distance calculations.
 *
 * The time-independent half of the routing contract, and the only half the quote
 * path can reach. There is no departure parameter here and there must never be one:
 * a duration that moves with the hour of the day would make an approved price depend
 * on when the customer happened to fill the form in. An implementation that can also
 * answer for a departure declares ScheduledRouteProviderInterface as well, so the
 * two questions stay separately typed rather than separated by a flag.
 *
 * Three obligations bind every implementation, and they are what makes a chain of
 * them interchangeable:
 *
 * - Failures are raised through DistanceProviderException::classified(), so the
 *   chain can tell an address the customer has not finished typing — which fails
 *   identically everywhere — from a coverage gap or a spent allowance, which say
 *   nothing about the next provider. An upstream response body, request URL or
 *   transport exception must not be passed along with it.
 * - A returned DistanceResult names its own provider and carries its own provenance
 *   metadata: profile, dataset version, endpoint fingerprint, and when it was
 *   fetched. Cache identity is built from those, so a result that does not say what
 *   produced it cannot be cached safely.
 * - A cached result keeps the time it was originally calculated. Reporting a stored
 *   route as freshly calculated hides its age from everything that prices it.
 *
 * @see \Drupal\custom_webform_handlers\Distance\ScheduledRouteProviderInterface
 * @see \Drupal\custom_webform_handlers\Distance\RoutingPolicy
 */
interface DistanceProviderInterface {

  /**
   * Calculates a driving route between two addresses.
   *
   * @param string $origin
   *   Origin address as entered.
   * @param string $destination
   *   Destination address as entered.
   *
   * @return \Drupal\custom_webform_handlers\Distance\DistanceResult
   *   The route, measured without reference to any particular departure time.
   *
   * @throws \Drupal\custom_webform_handlers\Distance\DistanceProviderException
   *   Thrown when no valid route can be calculated. Classified, so that a chain can
   *   decide whether another provider could do better; never carrying an upstream
   *   message.
   */
  public function calculate(string $origin, string $destination): DistanceResult;

}
