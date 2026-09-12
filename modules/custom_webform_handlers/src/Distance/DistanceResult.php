<?php

namespace Drupal\custom_webform_handlers\Distance;

/**
 * Immutable normalized route result.
 *
 * The public shape of this object is fixed. toArray() has exactly the seven keys it
 * has always had, because four browser scripts read them — js/distance_calculator.js,
 * js/route-map-preview.js, js/quote-summary-sidebar.js and js/dispatch_slots.js — and
 * the metadata added for the provider chain is deliberately not part of it: a profile
 * name, a dataset version and an endpoint fingerprint describe this site's
 * infrastructure, and the preview response is served to anyone who can reach the form.
 *
 * The metadata is a closed, normalized set. It arrives as one optional array rather
 * than a tail of positional parameters — a constructor whose eighth through
 * fifteenth arguments were nullable scalars would be read wrong eventually — and
 * every value is coerced, charset-restricted and length-capped on the way in. That
 * is the same reasoning as DistanceProviderException::classified(): this and the
 * exception are the two objects that cross the boundary out of an adapter, so
 * neither of them offers a parameter an upstream response body could travel in.
 */
final class DistanceResult {

  /**
   * The route endpoints were used exactly as given.
   */
  public const ACCURACY_EXACT = 'exact';

  /**
   * The provider moved the endpoints to the nearest routable road.
   */
  public const ACCURACY_SNAPPED = 'snapped';

  /**
   * The endpoints came from text and were located approximately.
   */
  public const ACCURACY_APPROXIMATE = 'approximate';

  /**
   * The provider did not say, which is the default and not a claim of quality.
   */
  public const ACCURACY_UNKNOWN = 'unknown';

  private const ACCURACIES = [
    self::ACCURACY_EXACT,
    self::ACCURACY_SNAPPED,
    self::ACCURACY_APPROXIMATE,
    self::ACCURACY_UNKNOWN,
  ];

  /**
   * The original provider calculation time, in UTC.
   */
  private readonly int $calculatedAt;

  /**
   * The optional provider-validated route geometry.
   */
  private readonly ?string $encodedPolyline;

  /**
   * The closed, normalized provider provenance set.
   *
   * @var array<string, mixed>
   */
  private readonly array $metadata;

  /**
   * Constructs a normalized route result.
   *
   * @param int $distanceMeters
   *   The routed distance in meters.
   * @param int $durationSeconds
   *   The routed duration in seconds.
   * @param string $provider
   *   The normalized provider identifier.
   * @param int|null $calculatedAt
   *   The original provider calculation time, or NULL to use the current time.
   * @param string|null $encodedPolyline
   *   Optional provider-validated encoded route geometry.
   * @param bool $trafficAware
   *   Whether traffic conditions at a stated departure informed the duration.
   *   FALSE — the default, and what the quote path always receives — means the
   *   figure is time-independent, which is the fastest the drive is ever likely to
   *   be. A scheduling caller must not treat the two as interchangeable.
   * @param array $metadata
   *   Optional provenance, from the allowlist normalizeMetadata() applies. Unknown
   *   keys are dropped rather than stored, so this parameter cannot be used to
   *   attach an arbitrary payload to a result that later gets logged.
   */
  public function __construct(
    private readonly int $distanceMeters,
    private readonly int $durationSeconds,
    private readonly string $provider = 'google_routes',
    ?int $calculatedAt = NULL,
    ?string $encodedPolyline = NULL,
    private readonly bool $trafficAware = FALSE,
    array $metadata = [],
  ) {
    if ($distanceMeters <= 0) {
      throw new \InvalidArgumentException('Distance must be greater than zero.');
    }
    if ($durationSeconds < 0) {
      throw new \InvalidArgumentException('Duration cannot be negative.');
    }
    $this->calculatedAt = $calculatedAt ?? time();
    $this->encodedPolyline = $encodedPolyline === NULL || trim($encodedPolyline) === ''
      ? NULL
      : trim($encodedPolyline);
    $this->metadata = self::normalizeMetadata($metadata, $this->calculatedAt);
  }

  /**
   * Rebuilds a result that was read back out of a cache.
   *
   * The one difference from the constructor is that $calculatedAt has no default, and
   * that is the whole point of having this. A cache read that calls the constructor
   * and forgets the timestamp gets time() — so a stored route silently reports itself
   * as freshly calculated, and nothing downstream can tell how old the figure it is
   * pricing from actually is. Here the caller cannot forget.
   *
   * @param int $distanceMeters
   *   The routed distance in meters.
   * @param int $durationSeconds
   *   The routed duration in seconds.
   * @param string $provider
   *   The normalized provider identifier.
   * @param int $calculatedAt
   *   The moment the provider was originally asked. Never the moment of the read.
   * @param array $metadata
   *   Optional normalized provider provenance.
   * @param string|null $encodedPolyline
   *   Optional provider-validated encoded route geometry.
   * @param bool $trafficAware
   *   Whether traffic at the requested departure informed the duration.
   */
  public static function restored(
    int $distanceMeters,
    int $durationSeconds,
    string $provider,
    int $calculatedAt,
    array $metadata = [],
    ?string $encodedPolyline = NULL,
    bool $trafficAware = FALSE,
  ): self {
    return new self(
      $distanceMeters,
      $durationSeconds,
      $provider,
      $calculatedAt,
      $encodedPolyline,
      $trafficAware,
      $metadata,
    );
  }

  /**
   * Returns the routed distance in meters.
   */
  public function distanceMeters(): int {
    return $this->distanceMeters;
  }

  /**
   * Returns the routed distance in statute miles.
   */
  public function distanceMiles(): float {
    return $this->distanceMeters / 1609.344;
  }

  /**
   * Returns the routed distance in kilometers.
   */
  public function distanceKilometers(): float {
    return $this->distanceMeters / 1000;
  }

  /**
   * Returns the routed duration in seconds.
   */
  public function durationSeconds(): int {
    return $this->durationSeconds;
  }

  /**
   * Returns the normalized provider identifier.
   */
  public function provider(): string {
    return $this->provider;
  }

  /**
   * Returns the original provider calculation time, in UTC.
   */
  public function calculatedAt(): int {
    return $this->calculatedAt;
  }

  /**
   * Returns the server-validated route geometry, when available.
   */
  public function encodedPolyline(): ?string {
    return $this->encodedPolyline;
  }

  /**
   * Returns whether traffic at a stated departure informed the duration.
   */
  public function trafficAware(): bool {
    return $this->trafficAware;
  }

  /**
   * Returns when the provider was originally asked.
   *
   * Equal to calculatedAt() unless an adapter distinguishes them. Both are the
   * original ask and neither moves when a cached copy is read, so a caller can tell
   * how old a figure is rather than how recently it was fetched from a cache.
   */
  public function fetchedAt(): int {
    return $this->metadata['fetched_at'];
  }

  /**
   * Returns when a stored copy of this result stops being usable, if it is stored.
   */
  public function expiresAt(): ?int {
    return $this->metadata['expires_at'];
  }

  /**
   * Returns the provider's own API version, as the adapter recorded it.
   */
  public function providerVersion(): string {
    return $this->metadata['provider_version'];
  }

  /**
   * Returns the routing profile that produced this result.
   */
  public function profile(): string {
    return $this->metadata['profile'];
  }

  /**
   * Returns the operator-recorded dataset version behind this result.
   */
  public function dataVersion(): string {
    return $this->metadata['data_version'];
  }

  /**
   * Returns the salted fingerprint of the endpoint that answered, never the URL.
   */
  public function endpointFingerprint(): string {
    return $this->metadata['endpoint_fingerprint'];
  }

  /**
   * Returns how precisely the endpoints were located.
   *
   * One of this class's accuracy constants. ACCURACY_UNKNOWN is the default and says
   * the provider did not tell us — it is not a claim that the route was accurate.
   */
  public function accuracy(): string {
    return $this->metadata['accuracy'];
  }

  /**
   * Returns the departure the provider actually routed for, when there was one.
   *
   * Not necessarily the departure that was asked for. A provider given a departure in
   * the past may route for now instead, and a schedule that believed otherwise would
   * be pricing a drive at the wrong time of day.
   */
  public function effectiveDepartureAt(): ?int {
    return $this->metadata['effective_departure_at'];
  }

  /**
   * Returns the whole normalized metadata set, for server code only.
   *
   * Not safe to serialize into a response: it names a profile, a dataset version and
   * an endpoint fingerprint. Cache identity, logging and the provider chain read it;
   * anything rendering to a browser wants displayMetadata() instead.
   */
  public function metadata(): array {
    return $this->metadata;
  }

  /**
   * Returns the public shape of this result.
   *
   * The traffic basis is deliberately absent. This array is serialized straight
   * into the browser's distance-preview response, and how the schedule prices a
   * drive is operational detail the customer's page has no use for.
   */
  public function toArray(): array {
    return [
      'distance_meters' => $this->distanceMeters(),
      'distance_miles' => round($this->distanceMiles(), 2),
      'distance_kilometers' => round($this->distanceKilometers(), 2),
      'duration_seconds' => $this->durationSeconds(),
      'provider' => $this->provider(),
      'calculated_at' => $this->calculatedAt(),
      'encoded_polyline' => $this->encodedPolyline(),
    ];
  }

  /**
   * Returns the metadata fields that are safe to render.
   *
   * A deliberately chosen two, and the choice is what makes it safe — this is not
   * "the metadata minus the obvious secrets", it is the two fields that describe the
   * customer's own route rather than this site's infrastructure. How precisely their
   * addresses were located, and what time the drive was assumed to start, are facts
   * about their booking.
   *
   * The other six are excluded for a reason each: profile, provider_version and
   * data_version identify which product and which dataset this site is paying for;
   * endpoint_fingerprint is derived from a host that lives in settings.php precisely
   * so it is not published; fetched_at and expires_at describe the caching behaviour,
   * which tells a reader how to time requests to miss the cache.
   *
   * Kept out of toArray() on purpose. That array's key set is a contract with four
   * browser scripts, and a caller that wants these fields should have to say so.
   */
  public function displayMetadata(): array {
    return [
      'accuracy' => $this->accuracy(),
      'effective_departure_at' => $this->effectiveDepartureAt(),
    ];
  }

  /**
   * Returns the stable identity of one ordered, normalized address pair.
   *
   * This is an identity aid, not anonymization. It contains no address text and
   * grants no retention right over the values from which it was derived.
   */
  public static function pairFingerprint(string $origin, string $destination): string {
    return hash('sha256', implode("\0", [
      mb_strtolower(self::normalizeAddressText($origin), 'UTF-8'),
      mb_strtolower(self::normalizeAddressText($destination), 'UTF-8'),
    ]));
  }

  /**
   * Returns an audit identity for this exact normalized route result.
   */
  public function routeFingerprint(string $origin, string $destination): string {
    return hash('sha256', implode("\0", [
      self::pairFingerprint($origin, $destination),
      $this->provider,
      $this->endpointFingerprint(),
      $this->dataVersion(),
      $this->distanceMeters,
      $this->durationSeconds,
      $this->effectiveDepartureAt() ?? 0,
      $this->calculatedAt,
    ]));
  }

  /**
   * Canonicalizes harmless address punctuation for cache and audit identity.
   */
  public static function normalizeAddressText(string $value): string {
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    $value = preg_replace('/\s*,\s*/u', ', ', $value) ?? $value;
    $value = preg_replace('/(?:,\s*){2,}/u', ', ', $value) ?? $value;

    return trim($value, " \t\n\r\0\x0B,");
  }

  /**
   * Coerces a metadata array into the eight keys this class stores.
   *
   * Every value is rebuilt rather than accepted. The keys are a closed set, so an
   * unknown one is dropped; the four string fields are trimmed, restricted to a
   * machine-name charset and capped at 64 characters, so a provider's error text or a
   * signed URL cannot survive being passed in as a "profile"; the timestamps are cast
   * and refused if they are not positive; and accuracy has to be one of this class's
   * own constants or it becomes ACCURACY_UNKNOWN.
   *
   * @param array $metadata
   *   Whatever the adapter supplied.
   * @param int $calculatedAt
   *   The already-resolved calculation time, which fetched_at defaults to so that the
   *   two never disagree by accident.
   */
  private static function normalizeMetadata(array $metadata, int $calculatedAt): array {
    $fetchedAt = isset($metadata['fetched_at']) ? (int) $metadata['fetched_at'] : 0;
    $expiresAt = isset($metadata['expires_at']) ? (int) $metadata['expires_at'] : 0;
    $departure = isset($metadata['effective_departure_at']) ? (int) $metadata['effective_departure_at'] : 0;
    $accuracy = (string) ($metadata['accuracy'] ?? self::ACCURACY_UNKNOWN);

    return [
      'fetched_at' => $fetchedAt > 0 ? $fetchedAt : $calculatedAt,
      'expires_at' => $expiresAt > 0 ? $expiresAt : NULL,
      'provider_version' => self::normalizeToken($metadata['provider_version'] ?? ''),
      'profile' => self::normalizeToken($metadata['profile'] ?? ''),
      'data_version' => self::normalizeToken($metadata['data_version'] ?? ''),
      'endpoint_fingerprint' => self::normalizeToken($metadata['endpoint_fingerprint'] ?? ''),
      'accuracy' => in_array($accuracy, self::ACCURACIES, TRUE) ? $accuracy : self::ACCURACY_UNKNOWN,
      'effective_departure_at' => $departure > 0 ? $departure : NULL,
    ];
  }

  /**
   * Reduces a metadata string to a short, printable token or nothing at all.
   *
   * Restrictive on purpose. These four fields end up in cache keys and log lines, and
   * a permissive filter here would be the one place an upstream string could reach
   * either. Anything with a character outside the allowed set is discarded whole
   * rather than stripped down, because a value that had to be edited to be safe is a
   * value this module did not understand.
   */
  private static function normalizeToken(mixed $value): string {
    if (!is_string($value) && !is_int($value)) {
      return '';
    }
    $token = trim((string) $value);

    return $token !== '' && strlen($token) <= 64 && preg_match('/^[A-Za-z0-9._+\/-]+$/', $token) === 1
      ? $token
      : '';
  }

}
