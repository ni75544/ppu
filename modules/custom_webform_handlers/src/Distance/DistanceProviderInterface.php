<?php

namespace Drupal\custom_webform_handlers\Distance;

/**
 * Provides authoritative driving-distance calculations.
 */
interface DistanceProviderInterface {

  /**
   * Calculates a driving route between two addresses.
   *
   * @throws \Drupal\custom_webform_handlers\Distance\DistanceProviderException
   *   Thrown when no valid route can be calculated.
   */
  public function calculate(string $origin, string $destination): DistanceResult;

}
