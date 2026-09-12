<?php

namespace Drupal\custom_webform_handlers\Distance;

/**
 * Immutable, server-validated coordinates for one committed address.
 *
 * This object is deliberately richer than a latitude/longitude pair. A caller
 * also receives the exact submitted-address identity, the provider provenance,
 * the original fetch and expiry instants, and the permissions that govern
 * reuse. Those facts must survive a cache round trip unchanged.
 */
final class ResolvedLocation {

  public const PRECISION_ADDRESS = 'address';

  public const PRECISION_STREET = 'street';

  public const PRECISION_POSTAL = 'postal';

  public const PRECISION_LOCALITY = 'locality';

  public const PRECISION_UNKNOWN = 'unknown';

  private const PRECISIONS = [
    self::PRECISION_ADDRESS,
    self::PRECISION_STREET,
    self::PRECISION_POSTAL,
    self::PRECISION_LOCALITY,
    self::PRECISION_UNKNOWN,
  ];

  public function __construct(
    private readonly float $latitude,
    private readonly float $longitude,
    private readonly string $addressIdentity,
    private readonly string $canonicalAddress,
    private readonly string $countryCode,
    private readonly string $postalCode,
    private readonly string $precision,
    private readonly string $providerId,
    private readonly string $sourceId,
    private readonly string $dataVersion,
    private readonly int $fetchedAt,
    private readonly int $expiresAt,
    private readonly bool $mayPersist,
    private readonly bool $mayPassToOtherProviders,
    private readonly bool $mayRenderOnNonOriginMap,
  ) {
    if (!is_finite($latitude) || $latitude < -90 || $latitude > 90) {
      throw new \InvalidArgumentException('Latitude is outside its valid range.');
    }
    if (!is_finite($longitude) || $longitude < -180 || $longitude > 180) {
      throw new \InvalidArgumentException('Longitude is outside its valid range.');
    }
    if (self::cleanAddress($addressIdentity) === '' || self::cleanAddress($canonicalAddress) === '') {
      throw new \InvalidArgumentException('Address identity and canonical address are required.');
    }
    if ($addressIdentity !== self::cleanAddress($addressIdentity)
      || $canonicalAddress !== self::cleanAddress($canonicalAddress)) {
      throw new \InvalidArgumentException('Address values are not normalized.');
    }
    if (preg_match('/^[A-Z]{2}$/', $countryCode) !== 1) {
      throw new \InvalidArgumentException('Country code must be ISO alpha-2.');
    }
    if ($postalCode !== '' && preg_match('/^[A-Z0-9 -]{2,16}$/', $postalCode) !== 1) {
      throw new \InvalidArgumentException('Postal code contains unsupported characters.');
    }
    if (!in_array($precision, self::PRECISIONS, TRUE)) {
      throw new \InvalidArgumentException('Coordinate precision is not recognized.');
    }
    if (preg_match('/^[a-z0-9_]{1,64}$/', $providerId) !== 1) {
      throw new \InvalidArgumentException('Provider id is not a machine name.');
    }
    if (self::cleanIdentifier($sourceId, 128) === '' || self::cleanIdentifier($dataVersion, 96) === '') {
      throw new \InvalidArgumentException('Coordinate provenance is incomplete.');
    }
    if ($sourceId !== self::cleanIdentifier($sourceId, 128)
      || $dataVersion !== self::cleanIdentifier($dataVersion, 96)) {
      throw new \InvalidArgumentException('Coordinate provenance is not normalized.');
    }
    if ($fetchedAt <= 0 || $expiresAt <= $fetchedAt) {
      throw new \InvalidArgumentException('Coordinate timestamps are invalid.');
    }
  }

  /**
   * Returns the latitude.
   */
  public function latitude(): float {
    return $this->latitude;
  }

  /**
   * Returns the longitude.
   */
  public function longitude(): float {
    return $this->longitude;
  }

  /**
   * The normalized full submitted address used for cache identity.
   */
  public function addressIdentity(): string {
    return $this->addressIdentity;
  }

  /**
   * Returns the canonical provider address.
   */
  public function canonicalAddress(): string {
    return $this->canonicalAddress;
  }

  /**
   * Returns the ISO alpha-2 country code.
   */
  public function countryCode(): string {
    return $this->countryCode;
  }

  /**
   * Returns the provider postal code.
   */
  public function postalCode(): string {
    return $this->postalCode;
  }

  /**
   * Returns the coordinate precision classification.
   */
  public function precision(): string {
    return $this->precision;
  }

  /**
   * Returns the configured Drupal Geocoder provider entity id.
   */
  public function providerId(): string {
    return $this->providerId;
  }

  /**
   * Returns the provider feature id that supplied the coordinates.
   */
  public function sourceId(): string {
    return $this->sourceId;
  }

  /**
   * Returns the operator-recorded dataset version.
   */
  public function dataVersion(): string {
    return $this->dataVersion;
  }

  /**
   * Returns the original provider fetch time.
   */
  public function fetchedAt(): int {
    return $this->fetchedAt;
  }

  /**
   * Returns the instant after which the result is stale.
   */
  public function expiresAt(): int {
    return $this->expiresAt;
  }

  /**
   * Whether the result may be stored beyond the request.
   */
  public function mayPersist(): bool {
    return $this->mayPersist;
  }

  /**
   * Whether another routing provider may receive the coordinates.
   */
  public function mayPassToOtherProviders(): bool {
    return $this->mayPassToOtherProviders;
  }

  /**
   * Whether the coordinates may be rendered on a non-origin map.
   */
  public function mayRenderOnNonOriginMap(): bool {
    return $this->mayRenderOnNonOriginMap;
  }

  /**
   * Returns the closed server-side shape stored in the coordinate cache.
   */
  public function toCacheArray(): array {
    return [
      'latitude' => $this->latitude,
      'longitude' => $this->longitude,
      'address_identity' => $this->addressIdentity,
      'canonical_address' => $this->canonicalAddress,
      'country_code' => $this->countryCode,
      'postal_code' => $this->postalCode,
      'precision' => $this->precision,
      'provider_id' => $this->providerId,
      'source_id' => $this->sourceId,
      'data_version' => $this->dataVersion,
      'fetched_at' => $this->fetchedAt,
      'expires_at' => $this->expiresAt,
      'may_persist' => $this->mayPersist,
      'may_pass_to_other_providers' => $this->mayPassToOtherProviders,
      'may_render_on_non_origin_map' => $this->mayRenderOnNonOriginMap,
    ];
  }

  /**
   * Rebuilds a result without changing its original provenance timestamps.
   */
  public static function fromCacheArray(array $data): self {
    return new self(
      (float) ($data['latitude'] ?? NAN),
      (float) ($data['longitude'] ?? NAN),
      self::cleanAddress((string) ($data['address_identity'] ?? '')),
      self::cleanAddress((string) ($data['canonical_address'] ?? '')),
      strtoupper(trim((string) ($data['country_code'] ?? ''))),
      strtoupper(trim((string) ($data['postal_code'] ?? ''))),
      trim((string) ($data['precision'] ?? '')),
      trim((string) ($data['provider_id'] ?? '')),
      self::cleanIdentifier((string) ($data['source_id'] ?? ''), 128),
      self::cleanIdentifier((string) ($data['data_version'] ?? ''), 96),
      (int) ($data['fetched_at'] ?? 0),
      (int) ($data['expires_at'] ?? 0),
      !empty($data['may_persist']),
      !empty($data['may_pass_to_other_providers']),
      !empty($data['may_render_on_non_origin_map']),
    );
  }

  /**
   * Normalizes an address value without discarding address tokens.
   */
  private static function cleanAddress(string $value): string {
    $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', trim($value)) ?? '';
    $value = preg_replace('/\s+/u', ' ', $value) ?? '';

    return mb_substr($value, 0, 500, 'UTF-8');
  }

  /**
   * Restricts an infrastructure identifier to a closed character set.
   */
  private static function cleanIdentifier(string $value, int $length): string {
    $value = preg_replace('/[^A-Za-z0-9._:+-]+/', '', trim($value)) ?? '';

    return substr($value, 0, $length);
  }

}
