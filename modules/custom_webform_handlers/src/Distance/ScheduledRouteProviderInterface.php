<?php

namespace Drupal\custom_webform_handlers\Distance;

/**
 * A route measured for a specific future departure, with traffic taken into
 * account.
 *
 * This is deliberately a second, separate capability rather than an optional
 * parameter on DistanceProviderInterface::calculate(). Quote pricing and dispatch
 * scheduling read the same Google adapter, and only one of them is authorized to
 * ask about traffic: a traffic-aware figure moves with the hour of the day, and
 * feeding one into the approved pricing rules would silently make a quote depend
 * on when the customer filled the form in. Keeping the scheduled question on its
 * own interface makes that mistake impossible to make by accident — the quote path
 * is typed against a contract that cannot express a departure time at all.
 *
 * Implemented by the existing Google adapter, not by a second Google client. The
 * two methods share one endpoint, one key, one timeout policy and one error
 * classification; they differ only in the routing preference they ask for, the
 * fields they request back, and therefore in their cache identity.
 *
 * @see \Drupal\custom_webform_handlers\Distance\DistanceProviderInterface
 * @see \Drupal\custom_webform_handlers\Dispatch\Route\DistanceProviderRouteEstimator
 */
interface ScheduledRouteProviderInterface {

  /**
   * Calculates a driving route as it is expected to be at a future instant.
   *
   * The departure instant is a request, not a promise: providers quantize it to a
   * configured bucket so that scheduling a day does not issue one billed request
   * per grid candidate, and they may move it forward to satisfy the provider's own
   * "must be in the future" rule. The returned result records what was actually
   * asked, so a caller never has to assume.
   *
   * A result whose trafficAware() is FALSE is a real route that was not priced
   * against traffic. It must be treated as optimistic at peak and never as
   * equivalent to a scheduled figure.
   *
   * @param string $origin
   *   Origin address as entered.
   * @param string $destination
   *   Destination address as entered.
   * @param int $departureAt
   *   Intended departure as a UNIX timestamp.
   *
   * @return \Drupal\custom_webform_handlers\Distance\DistanceResult
   *   The route, carrying whether traffic actually informed the duration.
   *
   * @throws \Drupal\custom_webform_handlers\Distance\DistanceProviderException
   *   Thrown when no valid route can be calculated. Never returns a zero or
   *   traffic-free duration in place of a scheduled one.
   */
  public function calculateScheduled(string $origin, string $destination, int $departureAt): DistanceResult;

}
