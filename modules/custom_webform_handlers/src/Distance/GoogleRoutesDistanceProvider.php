<?php

namespace Drupal\custom_webform_handlers\Distance;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Site\Settings;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\RequestOptions;
use Psr\Log\LoggerInterface;

/**
 * Calculates routes using Google Routes API v2 Compute Routes.
 */
final class GoogleRoutesDistanceProvider implements DistanceProviderInterface {

  private const ENDPOINT = 'https://routes.googleapis.com/directions/v2:computeRoutes';

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly CacheBackendInterface $cache,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function calculate(string $origin, string $destination): DistanceResult {
    $origin = $this->normalizeAddress($origin);
    $destination = $this->normalizeAddress($destination);
    if ($origin === '' || $destination === '' || $origin === $destination) {
      throw new DistanceProviderException('invalid_route_input');
    }

    $api_key = (string) Settings::get('custom_webform_handlers_server_api_key', '');
    if ($api_key === '') {
      throw new DistanceProviderException('provider_not_configured');
    }

    // Use a new cache namespace because earlier entries do not contain route
    // geometry, which the map preview needs to draw the validated route.
    $cid = 'custom_webform_handlers:route:v2:' . hash('sha256', mb_strtolower($origin) . "\0" . mb_strtolower($destination));
    if ($cached = $this->cache->get($cid)) {
      $data = $cached->data;
      return new DistanceResult(
        (int) $data['distance_meters'],
        (int) $data['duration_seconds'],
        'google_routes',
        NULL,
        isset($data['encoded_polyline']) ? (string) $data['encoded_polyline'] : NULL,
      );
    }

    $config = $this->configFactory->get('custom_webform_handlers.settings');
    $timeout = max(2, min(20, (int) ($config->get('route_request_timeout') ?: 8)));

    try {
      $response = $this->httpClient->request('POST', self::ENDPOINT, [
        RequestOptions::CONNECT_TIMEOUT => min(5, $timeout),
        RequestOptions::TIMEOUT => $timeout,
        RequestOptions::HTTP_ERRORS => FALSE,
        RequestOptions::HEADERS => [
          'Content-Type' => 'application/json',
          'X-Goog-Api-Key' => $api_key,
          'X-Goog-FieldMask' => 'routes.distanceMeters,routes.duration,routes.polyline.encodedPolyline',
        ],
        RequestOptions::JSON => [
          'origin' => ['address' => $origin],
          'destination' => ['address' => $destination],
          'travelMode' => 'DRIVE',
          'routingPreference' => 'TRAFFIC_UNAWARE',
          'computeAlternativeRoutes' => FALSE,
          'languageCode' => 'en-US',
          'units' => 'IMPERIAL',
        ],
      ]);

      $status = $response->getStatusCode();
      if ($status < 200 || $status >= 300) {
        $this->logger->warning('Routes API returned HTTP status @status.', ['@status' => $status]);
        throw new DistanceProviderException('provider_request_failed');
      }

      $data = json_decode((string) $response->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
      $route = $data['routes'][0] ?? NULL;
      $distance_meters = (int) ($route['distanceMeters'] ?? 0);
      $duration_seconds = $this->parseDuration((string) ($route['duration'] ?? '0s'));
      $encoded_polyline = trim((string) ($route['polyline']['encodedPolyline'] ?? ''));
      if ($distance_meters <= 0) {
        throw new DistanceProviderException('route_not_found');
      }

      $ttl = max(60, min(86400, (int) ($config->get('route_cache_ttl') ?: 3600)));
      $this->cache->set($cid, [
        'distance_meters' => $distance_meters,
        'duration_seconds' => $duration_seconds,
        'encoded_polyline' => $encoded_polyline,
      ], time() + $ttl);

      return new DistanceResult(
        $distance_meters,
        $duration_seconds,
        'google_routes',
        NULL,
        $encoded_polyline,
      );
    }
    catch (DistanceProviderException $exception) {
      throw $exception;
    }
    catch (\Throwable $exception) {
      $this->logger->error('Routes API request failed: @type', ['@type' => get_class($exception)]);
      throw new DistanceProviderException('provider_request_failed', 0, $exception);
    }
  }

  private function normalizeAddress(string $address): string {
    return trim((string) preg_replace('/\s+/u', ' ', $address));
  }

  private function parseDuration(string $duration): int {
    if (preg_match('/^([0-9]+(?:\.[0-9]+)?)s$/', $duration, $matches)) {
      return (int) round((float) $matches[1]);
    }
    return 0;
  }

}
