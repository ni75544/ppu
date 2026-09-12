<?php

namespace Drupal\custom_webform_handlers\Distance;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Site\Settings;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\RequestOptions;
use Psr\Log\LoggerInterface;

/**
 * Shared validation for the coordinate-based routing adapters.
 *
 * No cache, retry, fallback, or budget ledger lives here. A chain must reserve
 * paid allowance before invoking an adapter. The only HTTP operation here is
 * one routing request; the existing resolver owns address geocoding.
 *
 * @internal
 */
abstract class CoordinateRouteProviderBase implements DistanceProviderInterface {

  protected const MAX_SNAP_METERS = 100;

  public function __construct(
    protected readonly ClientInterface $httpClient,
    protected readonly AddressCoordinateResolver $resolver,
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly Settings $settings,
    protected readonly TimeInterface $time,
    protected readonly LoggerInterface $logger,
  ) {}

  /**
   * Returns the allowlisted provider id used in sanitized failures.
   */
  abstract protected function providerId(): string;

  /**
   * Returns validated adapter settings without deciding caller eligibility.
   */
  protected function configuration(bool $scheduled = FALSE): array {
    $settings = RoutingPolicy::adapterSettings(
      $this->configFactory->get('custom_webform_handlers.settings'),
      $this->providerId(),
      $scheduled,
    );
    if (isset($settings['request_allowance']) && $settings['request_allowance'] === 0) {
      $this->fail(DistanceProviderException::BUDGET_EXHAUSTED);
    }

    return $settings;
  }

  /**
   * Resolves exact addresses and refuses restricted or stale coordinates.
   *
   * @return \Drupal\custom_webform_handlers\Distance\ResolvedLocation[]
   *   Origin and destination, in that order.
   */
  protected function endpoints(string $origin, string $destination): array {
    $a = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $origin) ?? ''));
    $b = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $destination) ?? ''));
    if ($a === '' || $b === '' || $a === $b) {
      $this->fail(DistanceProviderException::ADDRESS_UNUSABLE);
    }
    $locations = [$this->resolver->resolve($origin), $this->resolver->resolve($destination)];
    foreach ($locations as $location) {
      if (!$location->mayPassToOtherProviders()
        || $location->expiresAt() <= $this->time->getCurrentTime()) {
        $this->fail(DistanceProviderException::NOT_CONFIGURED);
      }
      if ($location->precision() !== ResolvedLocation::PRECISION_ADDRESS) {
        $this->fail(DistanceProviderException::ADDRESS_UNUSABLE);
      }
    }
    if ($locations[0]->latitude() === $locations[1]->latitude()
      && $locations[0]->longitude() === $locations[1]->longitude()) {
      $this->fail(DistanceProviderException::ADDRESS_UNUSABLE);
    }

    return $locations;
  }

  /**
   * Formats ordered coordinates without locale dependence or coarse rounding.
   */
  protected function coordinatePath(array $locations): string {
    $pairs = [];
    foreach ($locations as $location) {
      $pairs[] = json_encode($location->longitude(), JSON_THROW_ON_ERROR)
        . ',' . json_encode($location->latitude(), JSON_THROW_ON_ERROR);
    }

    return implode(';', $pairs);
  }

  /**
   * Executes one bounded GET without retaining upstream messages or exceptions.
   */
  protected function request(string $url, array $query, int $timeoutMs): array {
    try {
      $response = $this->httpClient->request('GET', $url, [
        RequestOptions::QUERY => $query,
        RequestOptions::TIMEOUT => $timeoutMs / 1000,
        RequestOptions::CONNECT_TIMEOUT => min(1, $timeoutMs / 1000),
        RequestOptions::HTTP_ERRORS => FALSE,
        RequestOptions::ALLOW_REDIRECTS => FALSE,
        RequestOptions::HEADERS => ['Accept' => 'application/json'],
      ]);
      $status = $response->getStatusCode();
      if ($status === 429) {
        $retry = trim($response->getHeaderLine('Retry-After'));
        $seconds = ctype_digit($retry) ? (int) $retry : NULL;
        if ($seconds === NULL && strlen($retry) <= 128) {
          $instant = strtotime($retry);
          $seconds = $instant === FALSE ? NULL : max(0, $instant - $this->time->getCurrentTime());
        }
        throw DistanceProviderException::classified(
          DistanceProviderException::THROTTLED, $this->providerId(),
          $seconds === NULL ? NULL : min(86400, $seconds),
        );
      }
      if (in_array($status, [401, 403, 404], TRUE) || ($status >= 300 && $status < 400)) {
        $this->fail(DistanceProviderException::NOT_CONFIGURED);
      }
      if ($status === 408 || $status === 504) {
        $this->fail(DistanceProviderException::TIMEOUT);
      }
      if ($status >= 500) {
        $this->fail(DistanceProviderException::UNCLASSIFIED);
      }
      // Read a bounded body; provider payloads must not exhaust PHP memory.
      $body = $response->getBody()->read(2097153);
      if (strlen($body) > 2097152) {
        $this->fail(DistanceProviderException::MALFORMED_RESPONSE);
      }
      $data = json_decode($body, TRUE, 64, JSON_THROW_ON_ERROR);
      if (!is_array($data) || !is_string($data['code'] ?? NULL)) {
        $this->fail(DistanceProviderException::MALFORMED_RESPONSE);
      }
      if ($data['code'] === 'NoRoute') {
        $this->fail(DistanceProviderException::NO_ROUTE);
      }
      if ($data['code'] === 'NoSegment') {
        // A failed snap is not proof that the customer's address is invalid.
        $this->fail(DistanceProviderException::OUTSIDE_COVERAGE);
      }
      $configuration_codes = [
        'InvalidUrl', 'InvalidService', 'InvalidVersion', 'InvalidOptions',
        'InvalidQuery', 'InvalidValue', 'InvalidInput', 'ProfileNotFound', 'TooBig',
      ];
      if (in_array($data['code'], $configuration_codes, TRUE)) {
        $this->fail(DistanceProviderException::NOT_CONFIGURED);
      }
      if ($status !== 200 || $data['code'] !== 'Ok') {
        $this->fail(DistanceProviderException::MALFORMED_RESPONSE);
      }

      return $data;
    }
    catch (DistanceProviderException $exception) {
      throw $exception;
    }
    catch (ConnectException $exception) {
      $this->fail(($exception->getHandlerContext()['errno'] ?? 0) === 28
        ? DistanceProviderException::TIMEOUT : DistanceProviderException::UNCLASSIFIED);
    }
    catch (\JsonException) {
      $this->fail(DistanceProviderException::MALFORMED_RESPONSE);
    }
    catch (\Throwable) {
      // Never attach the original exception: a Mapbox URL contains its token.
      $this->fail(DistanceProviderException::UNCLASSIFIED);
    }
  }

  /**
   * Validates the common response shape and both independently measured snaps.
   */
  protected function route(array $data, array $locations): array {
    $routes = $data['routes'] ?? NULL;
    $waypoints = $data['waypoints'] ?? NULL;
    if (!is_array($routes) || !array_is_list($routes) || count($routes) !== 1
      || !is_array($routes[0]) || !is_array($waypoints)
      || !array_is_list($waypoints) || count($waypoints) !== 2) {
      $this->fail(DistanceProviderException::MALFORMED_RESPONSE);
    }
    foreach ($waypoints as $index => $waypoint) {
      $point = $waypoint['location'] ?? NULL;
      if (!is_array($point) || !array_is_list($point) || count($point) !== 2) {
        $this->fail(DistanceProviderException::MALFORMED_RESPONSE);
      }
      $lon = $this->number($point[0], -180, 180);
      $lat = $this->number($point[1], -90, 90);
      $location = $locations[$index];
      $delta_lat = deg2rad($lat - $location->latitude());
      $delta_lon = deg2rad($lon - $location->longitude());
      $h = sin($delta_lat / 2) ** 2 + cos(deg2rad($lat))
        * cos(deg2rad($location->latitude())) * sin($delta_lon / 2) ** 2;
      $snap = 12742000 * asin(sqrt(min(1, max(0, $h))));
      if ($snap > self::MAX_SNAP_METERS) {
        $this->fail(DistanceProviderException::NO_ROUTE);
      }
      if (isset($waypoint['distance'])
        && $this->number($waypoint['distance']) > self::MAX_SNAP_METERS) {
        $this->fail(DistanceProviderException::NO_ROUTE);
      }
    }
    $this->number($routes[0]['distance'] ?? NULL);
    $this->number($routes[0]['duration'] ?? NULL);

    return $routes[0];
  }

  /**
   * Accepts JSON numbers, not booleans, numeric strings, NaN, or infinities.
   */
  protected function number(mixed $value, float $min = 0, float $max = 2147483647): float {
    if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value)
      || $value < $min || $value > $max) {
      $this->fail(DistanceProviderException::MALFORMED_RESPONSE);
    }

    return (float) $value;
  }

  /**
   * Produces the stable distance result without ever persisting a route here.
   */
  protected function result(array $route, array $settings, int $fetchedAt, bool $trafficAware = FALSE, ?int $departure = NULL, string $fingerprint = ''): DistanceResult {
    $distance = $this->number($route['distance'] ?? NULL);
    $duration = $this->number($route['duration'] ?? NULL);
    if ($distance === 0.0 || $duration === 0.0) {
      $this->fail(DistanceProviderException::NO_ROUTE);
    }
    $geometry = $this->geometry($route['geometry'] ?? NULL);

    return new DistanceResult(
      max(1, (int) round($distance)), (int) ceil($duration), $this->providerId(),
      $fetchedAt, $departure === NULL ? $geometry : NULL, $trafficAware,
      [
        'fetched_at' => $fetchedAt,
        'expires_at' => $fetchedAt + $settings['cache_ttl'],
        'provider_version' => $this->providerId() === 'osrm' ? 'route-v1' : 'directions-v5',
        'profile' => $settings['profile'],
        'data_version' => $settings['data_version'],
        'endpoint_fingerprint' => $fingerprint,
        'accuracy' => DistanceResult::ACCURACY_SNAPPED,
        'effective_departure_at' => $departure,
      ],
    );
  }

  /**
   * Validates a polyline5 before it can reach the browser's map decoder.
   */
  private function geometry(mixed $geometry): ?string {
    if ($geometry === NULL) {
      return NULL;
    }
    if (!is_string($geometry) || strlen($geometry) > 200000
      || preg_match('/^[\x3f-\x7e]+$/D', $geometry) !== 1) {
      $this->fail(DistanceProviderException::MALFORMED_RESPONSE);
    }
    $length = strlen($geometry);
    $offset = 0;
    $components = 0;
    $point = [0, 0];
    while ($offset < $length) {
      $value = 0;
      $shift = 0;
      do {
        if ($offset >= $length || $shift > 25) {
          $this->fail(DistanceProviderException::MALFORMED_RESPONSE);
        }
        $byte = ord($geometry[$offset++]) - 63;
        $value |= ($byte & 31) << $shift;
        $shift += 5;
      } while ($byte >= 32);
      $axis = $components++ % 2;
      $point[$axis] += ($value & 1) ? ~($value >> 1) : ($value >> 1);
      if (abs($point[$axis]) > ($axis === 0 ? 9000000 : 18000000)) {
        $this->fail(DistanceProviderException::MALFORMED_RESPONSE);
      }
    }
    if ($components < 4 || $components % 2 !== 0) {
      $this->fail(DistanceProviderException::MALFORMED_RESPONSE);
    }

    return $geometry;
  }

  /**
   * Throws a closed, redacted failure; logs no URL, address, body, or token.
   */
  protected function fail(string $classification): never {
    $this->logger->notice('Routing adapter @provider failed (@classification).', [
      '@provider' => $this->providerId(),
      '@classification' => $classification,
    ]);
    throw DistanceProviderException::classified($classification, $this->providerId());
  }

}
