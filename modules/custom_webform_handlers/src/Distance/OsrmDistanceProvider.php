<?php

namespace Drupal\custom_webform_handlers\Distance;

use Drupal\Component\Utility\Crypt;
use Drupal\Core\Site\Settings;

/**
 * Measures static driving routes using this operator's private OSRM graph.
 */
final class OsrmDistanceProvider extends CoordinateRouteProviderBase {

  /**
   * {@inheritdoc}
   */
  protected function providerId(): string {
    return RoutingPolicy::PROVIDER_OSRM;
  }

  /**
   * {@inheritdoc}
   */
  public function calculate(string $origin, string $destination): DistanceResult {
    $settings = $this->configuration();
    $endpoint = RoutingPolicy::adapterOsrmEndpoint($settings);
    if ($settings['data_version'] === '' || strlen($settings['data_version']) > 64) {
      $this->fail(DistanceProviderException::NOT_CONFIGURED);
    }
    $locations = $this->endpoints($origin, $destination);
    foreach ($locations as $location) {
      if ($location->dataVersion() !== $settings['data_version']) {
        $this->fail(DistanceProviderException::NOT_CONFIGURED);
      }
    }
    $fetched_at = $this->time->getCurrentTime();
    $data = $this->request($endpoint . '/route/v1/driving/' . $this->coordinatePath($locations), [
      'alternatives' => 'false',
      'steps' => 'false',
      'overview' => 'simplified',
      'geometries' => 'polyline',
      'generate_hints' => 'false',
      'radiuses' => self::MAX_SNAP_METERS . ';' . self::MAX_SNAP_METERS,
    ], $settings['timeout_ms']);

    return $this->result(
      $this->route($data, $locations), $settings, $fetched_at, FALSE, NULL,
      substr(Crypt::hmacBase64($endpoint, Settings::getHashSalt()), 0, 12),
    );
  }

}
