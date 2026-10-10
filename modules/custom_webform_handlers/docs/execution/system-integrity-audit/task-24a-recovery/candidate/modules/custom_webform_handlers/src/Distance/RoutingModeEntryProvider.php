<?php

namespace Drupal\custom_webform_handlers\Distance;

/**
 * Entry-point provider routing requests based on current active routing mode.
 */
final class RoutingModeEntryProvider implements DistanceProviderInterface, ScheduledRouteProviderInterface {

  public function __construct(
    private readonly RoutingModeResolver $modeResolver,
    private readonly FixedDevelopmentRouteProvider $fixedProvider,
    private readonly DistanceProviderInterface $liveProvider,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function calculateDistance(string $origin, string $destination): DistanceResult {
    if ($this->modeResolver->isDevelopmentFixed()) {
      return $this->fixedProvider->calculateDistance($origin, $destination);
    }
    return $this->liveProvider->calculateDistance($origin, $destination);
  }

  /**
   * {@inheritdoc}
   */
  public function calculateScheduledRoute(string $origin, string $destination, \DateTimeInterface $departureTime): DistanceResult {
    if ($this->modeResolver->isDevelopmentFixed()) {
      return $this->fixedProvider->calculateScheduledRoute($origin, $destination, $departureTime);
    }
    if ($this->liveProvider instanceof ScheduledRouteProviderInterface) {
      return $this->liveProvider->calculateScheduledRoute($origin, $destination, $departureTime);
    }
    return $this->liveProvider->calculateDistance($origin, $destination);
  }

}
