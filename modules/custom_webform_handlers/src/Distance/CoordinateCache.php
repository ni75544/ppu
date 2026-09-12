<?php

namespace Drupal\custom_webform_handlers\Distance;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Psr\Log\LoggerInterface;

/**
 * Owns request memoization and permitted persistent coordinate storage.
 */
final class CoordinateCache {

  private const PREFIX = 'custom_webform_handlers:coordinate:v1:';

  private const CACHE_TAG = 'custom_webform_handlers:coordinates';

  private const NEGATIVE_CLASSIFICATIONS = [
    DistanceProviderException::ADDRESS_UNUSABLE,
    DistanceProviderException::OUTSIDE_COVERAGE,
  ];

  /**
   * Request-local entries, including misses, so a failed backend is read once.
   *
   * @var array<string, array|null>
   */
  private array $entries = [];

  public function __construct(
    private readonly CacheBackendInterface $cache,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Builds an opaque identity without reducing an address to its postal code.
   */
  public function key(
    string $addressIdentity,
    string $countryCode,
    string $postalCode,
    string $providerId,
    string $dataVersion,
    bool $mayPersist,
    bool $mayPassToOtherProviders,
    bool $mayRenderOnNonOriginMap,
  ): string {
    $identity = json_encode([
      'address' => $addressIdentity,
      'country' => strtoupper(trim($countryCode)),
      'postal' => strtoupper(trim($postalCode)),
      'provider' => trim($providerId),
      'version' => trim($dataVersion),
      'permissions' => [
        'persist' => $mayPersist,
        'cross_provider' => $mayPassToOtherProviders,
        'external_map' => $mayRenderOnNonOriginMap,
      ],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return self::PREFIX . hash('sha256', $identity);
  }

  /**
   * Returns a cached coordinate result when one exists.
   */
  public function getLocation(string $cid, bool $allowPersistent): ?ResolvedLocation {
    $entry = $this->read($cid, $allowPersistent);

    return $entry['location'] ?? NULL;
  }

  /**
   * Returns a memoized customer/coverage failure when one exists.
   */
  public function getFailure(string $cid, bool $allowPersistent): ?string {
    $entry = $this->read($cid, $allowPersistent);
    $classification = $entry['failure'] ?? NULL;

    return is_string($classification) ? $classification : NULL;
  }

  /**
   * Memoizes a result and persists it only when its provenance permits that.
   */
  public function rememberLocation(string $cid, ResolvedLocation $location): void {
    $this->entries[$cid] = ['location' => $location];
    if (!$location->mayPersist() || $location->expiresAt() <= $this->time->getCurrentTime()) {
      return;
    }

    $this->write($cid, [
      'type' => 'location',
      'location' => $location->toCacheArray(),
      'expires_at' => $location->expiresAt(),
    ], $location->expiresAt());
  }

  /**
   * Remembers customer-correctable/coverage failures, never provider outages.
   */
  public function rememberFailure(
    string $cid,
    string $classification,
    int $ttl,
    bool $allowPersistent,
  ): void {
    if (!in_array($classification, self::NEGATIVE_CLASSIFICATIONS, TRUE)) {
      return;
    }
    $expiresAt = $this->time->getCurrentTime() + max(1, $ttl);
    $this->entries[$cid] = [
      'failure' => $classification,
      'expires_at' => $expiresAt,
    ];
    if (!$allowPersistent) {
      return;
    }

    $this->write($cid, [
      'type' => 'failure',
      'classification' => $classification,
      'expires_at' => $expiresAt,
    ], $expiresAt);
  }

  /**
   * Reads and normalizes one request or persistent entry.
   *
   * @return array|null
   *   A normalized request entry, or NULL for a memoized miss.
   */
  private function read(string $cid, bool $allowPersistent): ?array {
    if (array_key_exists($cid, $this->entries)) {
      return $this->entries[$cid];
    }
    if (!$allowPersistent) {
      $this->entries[$cid] = NULL;
      return NULL;
    }

    try {
      $cached = $this->cache->get($cid);
    }
    catch (\Throwable) {
      $this->logger->warning('The coordinate cache could not be read; request-local duplicate suppression remains active.');
      $this->entries[$cid] = NULL;
      return NULL;
    }
    if ($cached === FALSE || !is_array($cached->data)) {
      $this->entries[$cid] = NULL;
      return NULL;
    }

    $payload = $cached->data;
    $expiresAt = (int) ($payload['expires_at'] ?? 0);
    if ($expiresAt <= $this->time->getCurrentTime()) {
      $this->entries[$cid] = NULL;
      return NULL;
    }

    try {
      if (($payload['type'] ?? '') === 'location' && is_array($payload['location'] ?? NULL)) {
        $location = ResolvedLocation::fromCacheArray($payload['location']);
        if ($location->expiresAt() !== $expiresAt) {
          throw new \InvalidArgumentException('Coordinate expiry fields disagree.');
        }
        $this->entries[$cid] = ['location' => $location];
        return $this->entries[$cid];
      }
      $classification = (string) ($payload['classification'] ?? '');
      if (($payload['type'] ?? '') === 'failure'
        && in_array($classification, self::NEGATIVE_CLASSIFICATIONS, TRUE)) {
        $this->entries[$cid] = [
          'failure' => $classification,
          'expires_at' => $expiresAt,
        ];
        return $this->entries[$cid];
      }
    }
    catch (\InvalidArgumentException) {
      // A malformed entry is a miss. Do not surface its stored fields in logs.
    }

    $this->logger->warning('A malformed coordinate-cache entry was ignored.');
    $this->entries[$cid] = NULL;
    return NULL;
  }

  /**
   * Writes one bounded entry without making cache health a request failure.
   */
  private function write(string $cid, array $payload, int $expiresAt): void {
    try {
      $this->cache->set($cid, $payload, $expiresAt, [self::CACHE_TAG]);
    }
    catch (\Throwable) {
      $this->logger->warning('The coordinate cache could not be written; the validated result remains available for this request.');
    }
  }

}
