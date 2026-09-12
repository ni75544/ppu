<?php

namespace Drupal\custom_webform_handlers\Distance;

/**
 * Measures ordinary and departure-specific routes with Mapbox Directions v5.
 */
final class MapboxDistanceProvider extends CoordinateRouteProviderBase implements ScheduledRouteProviderInterface {

  private const ENDPOINT = 'https://api.mapbox.com/directions/v5/mapbox/';

  /**
   * {@inheritdoc}
   */
  protected function providerId(): string {
    return RoutingPolicy::PROVIDER_MAPBOX;
  }

  /**
   * {@inheritdoc}
   */
  public function calculate(string $origin, string $destination): DistanceResult {
    return $this->calculateRoute($origin, $destination, NULL);
  }

  /**
   * {@inheritdoc}
   */
  public function calculateScheduled(string $origin, string $destination, int $departureAt): DistanceResult {
    return $this->calculateRoute($origin, $destination, $departureAt);
  }

  /**
   * Performs one attempt; neither schedules nor retries anything itself.
   */
  private function calculateRoute(string $origin, string $destination, ?int $departureAt): DistanceResult {
    $scheduled = $departureAt !== NULL;
    $settings = $this->configuration($scheduled);
    $token = trim((string) $this->settings->get(RoutingPolicy::SETTING_MAPBOX_TOKEN, ''));
    if ($token === '') {
      $this->fail(DistanceProviderException::NOT_CONFIGURED);
    }
    $locations = $this->endpoints($origin, $destination);
    $query = [
      'access_token' => $token,
      'alternatives' => 'false',
      'steps' => 'false',
      'overview' => $scheduled ? 'full' : 'simplified',
      'geometries' => 'polyline',
      'waypoints_per_route' => 'false',
      'radiuses' => self::MAX_SNAP_METERS . ';' . self::MAX_SNAP_METERS,
    ];
    $departure = NULL;
    if ($scheduled) {
      $config = $this->configFactory->get('custom_webform_handlers.settings');
      $departure = RoutingPolicy::effectiveDeparture(
        $config,
        $departureAt,
        $this->time->getCurrentTime(),
      );
      $query['depart_at'] = gmdate('Y-m-d\TH:i:s\Z', $departure);
      $query['annotations'] = 'congestion_numeric,distance';
    }
    $fetched_at = $this->time->getCurrentTime();
    $data = $this->request(self::ENDPOINT . $settings['profile'] . '/' . $this->coordinatePath($locations), $query, $settings['timeout_ms']);
    $route = $this->route($data, $locations);

    return $this->result($route, $settings, $fetched_at,
      $scheduled && $this->hasTrafficCoverage($route), $departure);
  }

  /**
   * Requires traffic evidence for every non-zero segment, not a profile name.
   *
   * Conservative local acceptance rule: any unknown, absent, or inconsistent
   * annotation degrades the whole result. Numeric zero is known uncongested
   * traffic, not missing data. No arbitrary percentage threshold is invented.
   * Required-traffic scheduling can therefore refuse the degraded result.
   */
  private function hasTrafficCoverage(array $route): bool {
    $legs = $route['legs'] ?? NULL;
    if (!is_array($legs) || !array_is_list($legs) || count($legs) !== 1) {
      return FALSE;
    }
    $annotation = $legs[0]['annotation'] ?? [];
    $distances = $annotation['distance'] ?? NULL;
    $congestion = $annotation['congestion_numeric'] ?? NULL;
    if (!is_array($distances) || !array_is_list($distances) || $distances === []
      || !is_array($congestion) || !array_is_list($congestion)
      || count($distances) !== count($congestion)) {
      return FALSE;
    }
    $total = 0.0;
    foreach ($distances as $index => $distance) {
      if ((!is_int($distance) && !is_float($distance)) || !is_finite((float) $distance) || $distance < 0) {
        return FALSE;
      }
      $value = $congestion[$index];
      if ($distance > 0 && ((!is_int($value) && !is_float($value))
        || !is_finite((float) $value) || $value < 0 || $value > 100)) {
        return FALSE;
      }
      $total += $distance;
    }

    // Reject a partial annotation array masquerading as full-route coverage.
    return $total > 0 && abs($total - $route['distance']) <= max(1, count($distances) * 0.1);
  }

}
