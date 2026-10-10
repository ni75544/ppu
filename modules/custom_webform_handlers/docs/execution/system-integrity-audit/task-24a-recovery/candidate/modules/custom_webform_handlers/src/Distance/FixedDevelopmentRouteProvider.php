<?php

namespace Drupal\custom_webform_handlers\Distance;

/**
 * Development-fixed route provider returning zero-cost deterministic facts.
 */
final class FixedDevelopmentRouteProvider implements DistanceProviderInterface, ScheduledRouteProviderInterface {

  /**
   * {@inheritdoc}
   */
  public function calculateDistance(string $origin, string $destination): DistanceResult {
    return new DistanceResult(0, 0, 'development_fixed');
  }

  /**
   * {@inheritdoc}
   */
  public function calculateScheduledRoute(string $origin, string $destination, \DateTimeInterface $departureTime): DistanceResult {
    return new DistanceResult(0, 0, 'development_fixed');
  }

}
