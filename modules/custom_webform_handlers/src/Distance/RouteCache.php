<?php

namespace Drupal\custom_webform_handlers\Distance;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Psr\Log\LoggerInterface;

/**
 * Owns authoritative route caching and duplicate-miss coordination.
 */
final class RouteCache {

  private const PREFIX = 'custom_webform_handlers:route:v1:';

  private const LOCK_PREFIX = 'custom_webform_handlers:route-miss:';

  public function __construct(
    private readonly CacheBackendInterface $cache,
    private readonly LockBackendInterface $lock,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Builds a directional key from exact validated coordinates and policy.
   */
  public function key(
    RoutingPolicy $policy,
    string $provider,
    ResolvedLocation $origin,
    ResolvedLocation $destination,
    ?int $effectiveDepartureAt,
  ): string {
    $identity = [
      'policy' => $policy->version(),
      'provider' => $provider,
      'profile' => $policy->profile($provider),
      'data_version' => $policy->dataVersion($provider),
      'endpoint' => $policy->endpointFingerprint($provider),
      'origin' => $this->locationIdentity($origin),
      'destination' => $this->locationIdentity($destination),
      'scheduled' => $policy->isScheduled(),
      'departure' => $effectiveDepartureAt,
      'options' => [
        'travel_mode' => 'driving',
        'alternatives' => FALSE,
        'geometry' => 'polyline',
      ],
    ];

    return self::PREFIX . hash('sha256', json_encode(
      $identity,
      JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
    ));
  }

  /**
   * Returns a valid stored route while preserving original provenance.
   */
  public function get(string $cid, string $provider): ?DistanceResult {
    try {
      $item = $this->cache->get($cid);
    }
    catch (\Throwable) {
      $this->logger->warning('The route cache could not be read.');
      return NULL;
    }
    if ($item === FALSE || !is_array($item->data)) {
      return NULL;
    }
    $data = $item->data;
    if (($data['provider'] ?? NULL) !== $provider
      || !isset($data['distance_meters'], $data['duration_seconds'], $data['calculated_at'], $data['metadata'])
      || !is_int($data['distance_meters'])
      || !is_int($data['duration_seconds'])
      || !is_int($data['calculated_at'])
      || !is_array($data['metadata'])
      || !isset($data['metadata']['expires_at'])
      || !is_int($data['metadata']['expires_at'])
      || $data['metadata']['expires_at'] <= $this->time->getCurrentTime()) {
      return NULL;
    }

    try {
      return DistanceResult::restored(
        $data['distance_meters'],
        $data['duration_seconds'],
        $provider,
        $data['calculated_at'],
        $data['metadata'],
        isset($data['encoded_polyline']) && is_string($data['encoded_polyline'])
          ? $data['encoded_polyline'] : NULL,
        !empty($data['traffic_aware']),
      );
    }
    catch (\Throwable) {
      $this->logger->warning('A malformed route cache entry was ignored.');
      return NULL;
    }
  }

  /**
   * Stores only the normalized route contract and its allowed metadata.
   */
  public function set(string $cid, DistanceResult $result, int $ttl): void {
    $now = $this->time->getCurrentTime();
    $expires = $result->expiresAt() ?? ($now + max(0, $ttl));
    $expires = min($expires, $now + max(0, $ttl));
    if ($ttl <= 0 || $expires <= $now) {
      return;
    }
    try {
      $this->cache->set($cid, [
        'distance_meters' => $result->distanceMeters(),
        'duration_seconds' => $result->durationSeconds(),
        'provider' => $result->provider(),
        'calculated_at' => $result->calculatedAt(),
        'encoded_polyline' => $result->encodedPolyline(),
        'traffic_aware' => $result->trafficAware(),
        'metadata' => $result->metadata(),
      ], $expires);
    }
    catch (\Throwable) {
      $this->logger->warning('A valid route result could not be cached.');
    }
  }

  /**
   * Claims the right to fill one miss; NULL means coordination is unavailable.
   */
  public function claim(string $cid, float $lifetime): ?bool {
    try {
      return (bool) $this->lock->acquire(
        self::LOCK_PREFIX . hash('sha256', $cid),
        max(1.0, min(30.0, $lifetime)),
      );
    }
    catch (\Throwable) {
      $this->logger->warning('Route miss coordination is unavailable.');
      return NULL;
    }
  }

  /**
   * Releases a duplicate-miss claim held by this request.
   */
  public function release(string $cid): void {
    try {
      $this->lock->release(self::LOCK_PREFIX . hash('sha256', $cid));
    }
    catch (\Throwable) {
      $this->logger->warning('A route miss coordination lock could not be released.');
    }
  }

  /**
   * Returns the exact, ordered coordinate provenance used in cache identity.
   */
  private function locationIdentity(ResolvedLocation $location): array {
    return [
      'latitude' => $location->latitude(),
      'longitude' => $location->longitude(),
      'precision' => $location->precision(),
      'provider' => $location->providerId(),
      'source' => $location->sourceId(),
      'data_version' => $location->dataVersion(),
      'may_pass' => $location->mayPassToOtherProviders(),
    ];
  }

}
