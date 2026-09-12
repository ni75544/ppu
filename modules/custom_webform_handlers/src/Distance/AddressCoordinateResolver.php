<?php

namespace Drupal\custom_webform_handlers\Distance;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Plugin\Factory\DefaultFactory;
use Drupal\Core\Cache\MemoryBackend;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Utility\Token;
use Drupal\geocoder\ConfigurableProviderUsingHandlerWithAdapterBase;
use Drupal\geocoder\GeocoderProviderInterface;
use Drupal\geocoder\GeocoderThrottleInterface;
use Drupal\geocoder\ProviderGeocoderPhpInterface;
use Drupal\geocoder\ProviderPluginManager;
use Geocoder\Exception\InvalidArgument;
use Geocoder\Exception\InvalidCredentials;
use Geocoder\Exception\InvalidServerResponse;
use Geocoder\Exception\OutOfBounds;
use Geocoder\Exception\QuotaExceeded;
use Geocoder\Exception\UnsupportedOperation;
use Geocoder\Location;
use Geocoder\Query\GeocodeQuery;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Log\LoggerInterface;

/**
 * Resolves a committed address through the approved Drupal Geocoder provider.
 *
 * Browser coordinates and place IDs are accepted only as advisory input. They
 * never bypass this server-side lookup or enter coordinate cache identity.
 * The configured contrib plugin is recreated with an in-memory cache backend so
 * contrib's permanent, version-blind geocoder cache cannot override the
 * resolver's expiry, provenance, or storage-rights policy.
 */
final class AddressCoordinateResolver {

  private const APPROVED_PROVIDER = 'ppu_nominatim';

  private const APPROVED_PLUGIN = 'nominatim';

  private const COUNTRY_CODE = 'US';

  private const SUPPORTED_STATES = ['CT', 'NJ', 'NY'];

  /**
   * State names returned by the self-hosted Nominatim extract.
   */
  private const STATE_CODES = [
    'connecticut' => 'CT',
    'new jersey' => 'NJ',
    'new york' => 'NY',
  ];

  private const WEST = -74.35;

  private const SOUTH = 40.45;

  private const EAST = -72.75;

  private const NORTH = 41.20;

  private const POSITIVE_TTL = 2_592_000;

  private const INVALID_TTL = 900;

  private const COVERAGE_TTL = 3_600;

  private const AMBIGUITY_METERS = 250.0;

  /**
   * The request-local provider instance with no persistent contrib cache.
   */
  private ?ProviderGeocoderPhpInterface $provider = NULL;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ?ProviderPluginManager $providerPluginManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LanguageManagerInterface $languageManager,
    private readonly TypedConfigManagerInterface $typedConfigManager,
    private readonly ?ClientInterface $httpAdapter,
    private readonly ?GeocoderThrottleInterface $throttle,
    private readonly Token $token,
    private readonly CoordinateCache $cache,
    private readonly RoutingBudget $routingBudget,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Returns non-authoritative address suggestions from the approved provider.
   *
   * Suggestions only help a visitor finish the text field. They do not expose
   * coordinates, populate the authoritative coordinate cache, or bypass the
   * normal resolve() call made for a distance or checkout calculation. The
   * public controller applies its own request throttle before calling here.
   *
   * @return string[]
   *   Complete, normalized street-address labels inside the service region.
   */
  public function suggest(string $query, int $limit = 5): array {
    $identity = $this->normalizeFullAddress($query);
    if ($identity === '' || mb_strlen($identity, 'UTF-8') < 6
      || mb_strlen($identity, 'UTF-8') > 200) {
      return [];
    }

    $parts = preg_split('/\s+/u', $identity, -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($parts) || count($parts) < 2) {
      return [];
    }

    $geocoder = $this->geocoderSettings();
    $provider = $this->provider($geocoder);
    $limit = max(1, min(5, $limit));

    try {
      $results = $provider->geocodeQuery(
        GeocodeQuery::create($this->providerQuery($identity))
          ->withLocale('en')
          ->withLimit($limit)
          ->withData('countrycodes', [strtolower(self::COUNTRY_CODE)])
          // Nominatim orders viewbox as left, top, right, bottom.
          ->withData('viewbox', [self::WEST, self::NORTH, self::EAST, self::SOUTH])
          ->withData('bounded', TRUE),
      );
    }
    catch (InvalidCredentials) {
      throw DistanceProviderException::classified(DistanceProviderException::NOT_CONFIGURED);
    }
    catch (QuotaExceeded) {
      throw DistanceProviderException::classified(DistanceProviderException::THROTTLED);
    }
    catch (InvalidArgument | UnsupportedOperation | OutOfBounds) {
      return [];
    }
    catch (NetworkExceptionInterface) {
      throw DistanceProviderException::classified(DistanceProviderException::TIMEOUT);
    }
    catch (InvalidServerResponse) {
      throw DistanceProviderException::classified(DistanceProviderException::MALFORMED_RESPONSE);
    }
    catch (\Throwable) {
      $this->logger->warning('The address suggestion provider failed without a safe classification.');
      throw DistanceProviderException::classified(DistanceProviderException::UNCLASSIFIED);
    }

    $requestedNumber = $this->houseNumber($identity);
    $outsideCandidate = FALSE;
    $suggestions = [];
    foreach ($results as $candidate) {
      if (!$candidate instanceof Location) {
        continue;
      }
      $normalized = $this->candidate($candidate, $requestedNumber, '', $outsideCandidate);
      if ($normalized === NULL) {
        continue;
      }

      // COUNTRY_CODE is resolver provenance, not useful form input. Omitting it
      // also avoids treating a trailing "US" as a two-letter state token in the
      // intentionally conservative outside-coverage precheck.
      $value = preg_replace('/,\s*US$/u', '', (string) $normalized['canonical_address']) ?? '';
      $value = trim($value);
      if ($value === '') {
        continue;
      }
      $key = mb_strtolower($value, 'UTF-8');
      $suggestions[$key] = $value;
      if (count($suggestions) >= $limit) {
        break;
      }
    }

    return array_values($suggestions);
  }

  /**
   * Resolves one full address at most once in this request cycle.
   *
   * @param string $address
   *   The committed full address. A postal code alone is never sufficient.
   * @param array $browserHints
   *   Optional browser place ID/coordinates. Advisory only and never
   *   authoritative.
   *
   * @throws \Drupal\custom_webform_handlers\Distance\DistanceProviderException
   *   Raised with a bounded classification and no upstream response details.
   */
  public function resolve(
    string $address,
    array $browserHints = [],
  ): ResolvedLocation {
    // Deliberately do not read values from $browserHints. A range-valid
    // coordinate can still name a cheaper nearby endpoint, and Google place
    // provenance is not licensed as a shared OSRM/Mapbox coordinate source.
    unset($browserHints);

    $identity = $this->normalizeFullAddress($address);
    if ($identity === '' || mb_strlen($identity, 'UTF-8') > 500
      || preg_match('/\bP\.?\s*O\.?\s+Box\b/iu', $identity) === 1) {
      throw DistanceProviderException::classified(DistanceProviderException::ADDRESS_UNUSABLE);
    }

    $postalCode = $this->postalContext($identity);
    $geocoder = $this->geocoderSettings();
    $providerId = (string) $geocoder['provider'];
    $dataVersion = (string) $geocoder['version'];
    $mayPersist = (bool) $geocoder['persist'];
    $mayPass = $providerId === self::APPROVED_PROVIDER;
    $mayRender = $providerId === self::APPROVED_PROVIDER;

    $cid = $this->cache->key(
      $identity,
      self::COUNTRY_CODE,
      $postalCode,
      $providerId,
      $dataVersion,
      $mayPersist,
      $mayPass,
      $mayRender,
    );
    if ($cached = $this->cache->getLocation($cid, $mayPersist)) {
      return $cached;
    }
    if ($classification = $this->cache->getFailure($cid, $mayPersist)) {
      throw DistanceProviderException::classified($classification);
    }

    if ($this->isClearlyOutsideCoverage($identity, $postalCode)) {
      return $this->fail(
        $cid,
        DistanceProviderException::OUTSIDE_COVERAGE,
        self::COVERAGE_TTL,
        $mayPersist,
      );
    }

    $provider = $this->provider($geocoder);
    if (!$this->routingBudget->reserveGeocoder(
      $providerId,
      (int) $geocoder['request_allowance'],
    )) {
      throw DistanceProviderException::classified(DistanceProviderException::BUDGET_EXHAUSTED);
    }

    try {
      $results = $provider->geocodeQuery(
        GeocodeQuery::create($this->providerQuery($identity))
          ->withLocale('en')
          ->withLimit(5)
          ->withData('countrycodes', [strtolower(self::COUNTRY_CODE)])
          // Nominatim orders viewbox as left, top, right, bottom.
          ->withData('viewbox', [self::WEST, self::NORTH, self::EAST, self::SOUTH])
          ->withData('bounded', TRUE),
      );
    }
    catch (InvalidCredentials) {
      throw DistanceProviderException::classified(DistanceProviderException::NOT_CONFIGURED);
    }
    catch (QuotaExceeded) {
      throw DistanceProviderException::classified(DistanceProviderException::THROTTLED);
    }
    catch (InvalidArgument | UnsupportedOperation | OutOfBounds) {
      return $this->fail(
        $cid,
        DistanceProviderException::ADDRESS_UNUSABLE,
        self::INVALID_TTL,
        $mayPersist,
      );
    }
    catch (NetworkExceptionInterface) {
      throw DistanceProviderException::classified(DistanceProviderException::TIMEOUT);
    }
    catch (InvalidServerResponse) {
      throw DistanceProviderException::classified(DistanceProviderException::MALFORMED_RESPONSE);
    }
    catch (\Throwable) {
      $this->logger->warning('The configured coordinate provider failed without a safe classification.');
      throw DistanceProviderException::classified(DistanceProviderException::UNCLASSIFIED);
    }

    $requestedNumber = $this->houseNumber($identity);
    $candidates = [];
    $outsideCandidate = FALSE;
    foreach ($results as $candidate) {
      if (!$candidate instanceof Location) {
        continue;
      }
      $normalized = $this->candidate($candidate, $requestedNumber, $postalCode, $outsideCandidate);
      if ($normalized !== NULL) {
        $candidates[] = $normalized;
      }
    }

    if ($candidates === []) {
      return $this->fail(
        $cid,
        $outsideCandidate
          ? DistanceProviderException::OUTSIDE_COVERAGE
          : DistanceProviderException::ADDRESS_UNUSABLE,
        $outsideCandidate ? self::COVERAGE_TTL : self::INVALID_TTL,
        $mayPersist,
      );
    }

    $selected = $candidates[0];
    foreach (array_slice($candidates, 1) as $candidate) {
      if ($this->distanceBetween($selected, $candidate) > self::AMBIGUITY_METERS) {
        return $this->fail(
          $cid,
          DistanceProviderException::ADDRESS_UNUSABLE,
          self::INVALID_TTL,
          $mayPersist,
        );
      }
    }

    $fetchedAt = $this->time->getCurrentTime();
    try {
      $location = new ResolvedLocation(
        $selected['latitude'],
        $selected['longitude'],
        $identity,
        $selected['canonical_address'],
        $selected['country_code'],
        $selected['postal_code'],
        ResolvedLocation::PRECISION_ADDRESS,
        $providerId,
        $selected['source_id'],
        $dataVersion,
        $fetchedAt,
        $fetchedAt + self::POSITIVE_TTL,
        $mayPersist,
        $mayPass,
        $mayRender,
      );
    }
    catch (\InvalidArgumentException) {
      throw DistanceProviderException::classified(DistanceProviderException::MALFORMED_RESPONSE);
    }
    $this->cache->rememberLocation($cid, $location);

    return $location;
  }

  /**
   * Returns the configured provider with contrib's persistent cache replaced.
   */
  private function provider(array $geocoder): ProviderGeocoderPhpInterface {
    if ($this->provider !== NULL) {
      return $this->provider;
    }
    if ($this->providerPluginManager === NULL || $this->httpAdapter === NULL || $this->throttle === NULL) {
      throw DistanceProviderException::classified(DistanceProviderException::NOT_CONFIGURED);
    }

    try {
      $entity = $this->entityTypeManager
        ->getStorage('geocoder_provider')
        ->load((string) $geocoder['provider']);
      if (!$entity instanceof GeocoderProviderInterface) {
        throw new \UnexpectedValueException('Configured geocoder entity is unavailable.');
      }
      $data = $entity->toArray();
      $pluginId = trim((string) ($data['plugin'] ?? ''));
      $configuration = (array) ($data['configuration'] ?? []);
      if ($pluginId !== self::APPROVED_PLUGIN) {
        throw new \UnexpectedValueException('Configured geocoder plugin is not approved.');
      }
      $rootUrl = trim((string) ($configuration['rootUrl'] ?? ''));
      $host = strtolower((string) parse_url($rootUrl, PHP_URL_HOST));
      if ($host === '' || $host === 'nominatim.openstreetmap.org') {
        throw new \UnexpectedValueException('Configured geocoder endpoint is not self-hosted.');
      }
      if (!str_contains((string) ($configuration['userAgent'] ?? ''), (string) $geocoder['version'])) {
        throw new \UnexpectedValueException('Configured geocoder dataset version does not match its provider entity.');
      }

      $definition = $this->providerPluginManager->getDefinition($pluginId);
      $class = DefaultFactory::getPluginClass(
        $pluginId,
        $definition,
        ProviderGeocoderPhpInterface::class,
      );
      if (!is_a($class, ConfigurableProviderUsingHandlerWithAdapterBase::class, TRUE)) {
        throw new \UnexpectedValueException('Configured geocoder cannot be instantiated with an isolated cache.');
      }

      $plugin = new $class(
        $configuration,
        $pluginId,
        $definition,
        $this->configFactory,
        new MemoryBackend($this->time),
        $this->languageManager,
        $this->typedConfigManager,
        $this->httpAdapter,
        $this->throttle,
        $this->token,
      );
      if (!$plugin instanceof ProviderGeocoderPhpInterface) {
        throw new \UnexpectedValueException('Configured geocoder has an incompatible contract.');
      }
      $this->provider = $plugin;
    }
    catch (DistanceProviderException $exception) {
      throw $exception;
    }
    catch (\Throwable) {
      $this->logger->warning('The approved coordinate provider could not be constructed.');
      throw DistanceProviderException::classified(DistanceProviderException::NOT_CONFIGURED);
    }

    return $this->provider;
  }

  /**
   * Reads and validates the independent geocoder selection.
   */
  private function geocoderSettings(): array {
    $stored = $this->configFactory
      ->get('custom_webform_handlers.settings')
      ->get('routing.geocoder');
    $geocoder = array_replace(
      RoutingPolicy::DEFAULTS['geocoder'],
      is_array($stored) ? $stored : [],
    );
    $provider = trim((string) $geocoder['provider']);
    $version = trim((string) $geocoder['version']);
    if ($provider !== self::APPROVED_PROVIDER
      || preg_match('/^[A-Za-z0-9._:+-]{1,96}$/', $version) !== 1
      || empty($geocoder['persist'])) {
      throw DistanceProviderException::classified(DistanceProviderException::NOT_CONFIGURED);
    }
    if ((int) $geocoder['request_allowance'] <= 0) {
      throw DistanceProviderException::classified(DistanceProviderException::BUDGET_EXHAUSTED);
    }

    return [
      'provider' => $provider,
      'version' => $version,
      'persist' => TRUE,
      'request_allowance' => (int) $geocoder['request_allowance'],
    ];
  }

  /**
   * Converts one provider candidate into a validated address result.
   *
   * @return array|null
   *   Normalized address candidate data, or NULL when it is not authoritative.
   */
  private function candidate(
    Location $candidate,
    string $requestedNumber,
    string $requestedPostal,
    bool &$outsideCandidate,
  ): ?array {
    $coordinates = $candidate->getCoordinates();
    if ($coordinates === NULL) {
      return NULL;
    }
    $latitude = $coordinates->getLatitude();
    $longitude = $coordinates->getLongitude();
    if (!is_finite($latitude) || !is_finite($longitude)
      || $latitude < -90 || $latitude > 90
      || $longitude < -180 || $longitude > 180) {
      return NULL;
    }

    $country = $candidate->getCountry();
    $countryCode = strtoupper(trim((string) ($country?->getCode() ?? '')));
    if ($countryCode !== self::COUNTRY_CODE
      || $longitude < self::WEST || $longitude > self::EAST
      || $latitude < self::SOUTH || $latitude > self::NORTH) {
      $outsideCandidate = TRUE;
      return NULL;
    }

    $streetNumber = trim((string) $candidate->getStreetNumber());
    $streetName = trim((string) $candidate->getStreetName());
    $postalCode = $this->normalizePostal((string) $candidate->getPostalCode());
    if ($streetNumber === '' || $streetName === '') {
      return NULL;
    }
    if ($requestedNumber !== '' && $this->comparableNumber($streetNumber) !== $requestedNumber) {
      return NULL;
    }
    if ($requestedPostal !== '' && $postalCode !== $requestedPostal) {
      return NULL;
    }

    $locality = trim((string) $candidate->getLocality());
    $stateCode = $this->stateCode($candidate, $postalCode);
    $canonical = implode(', ', array_filter([
      trim($streetNumber . ' ' . $streetName),
      $locality,
      trim($stateCode . ' ' . $postalCode),
      $countryCode,
    ], static fn (string $value): bool => $value !== ''));
    $sourceId = strtolower(trim((string) $candidate->getProvidedBy()));
    if (method_exists($candidate, 'getOSMType') && method_exists($candidate, 'getOSMId')) {
      $osmType = strtolower(trim((string) $candidate->getOSMType()));
      $osmId = (int) $candidate->getOSMId();
      if ($osmType !== '' && $osmId > 0) {
        $sourceId = 'osm:' . $osmType . ':' . $osmId;
      }
    }
    if ($canonical === '' || $sourceId === '') {
      return NULL;
    }

    return [
      'latitude' => $latitude,
      'longitude' => $longitude,
      'canonical_address' => $canonical,
      'country_code' => $countryCode,
      'postal_code' => $postalCode,
      'source_id' => $sourceId,
    ];
  }

  /**
   * Returns the supported two-letter state code for one provider candidate.
   */
  private function stateCode(Location $candidate, string $postalCode): string {
    $levels = $candidate->getAdminLevels();
    if ($levels->has(1)) {
      $level = $levels->get(1);
      $code = strtoupper(trim((string) $level->getCode()));
      if (in_array($code, self::SUPPORTED_STATES, TRUE)) {
        return $code;
      }

      $name = mb_strtolower(trim($level->getName()), 'UTF-8');
      if (isset(self::STATE_CODES[$name])) {
        return self::STATE_CODES[$name];
      }
    }

    // The localized extract has no admin_level=4 boundary, so Nominatim omits
    // the state field on otherwise complete street candidates. A five-digit US
    // postal code remains authoritative enough for the three-state service
    // region and prevents suggestions from dropping the state abbreviation.
    $prefix = $postalCode === '' ? 0 : (int) substr($postalCode, 0, 3);
    if ($prefix >= 64 && $prefix <= 69) {
      return 'CT';
    }
    if ($prefix >= 70 && $prefix <= 89) {
      return 'NJ';
    }
    if ($prefix >= 100 && $prefix <= 149) {
      return 'NY';
    }

    return '';
  }

  /**
   * Throws a cacheable input/coverage classification after memoizing it.
   */
  private function fail(
    string $cid,
    string $classification,
    int $ttl,
    bool $mayPersist,
  ): never {
    $this->cache->rememberFailure($cid, $classification, $ttl, $mayPersist);
    throw DistanceProviderException::classified($classification);
  }

  /**
   * Normalizes only whitespace, commas, control characters, and case.
   */
  private function normalizeFullAddress(string $address): string {
    $address = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', trim($address)) ?? '';
    $address = preg_replace('/\s+/u', ' ', $address) ?? '';
    $address = preg_replace('/\s*,\s*/u', ', ', $address) ?? '';

    return mb_strtolower(rtrim($address, " ,\t\n\r\0\x0B"), 'UTF-8');
  }

  /**
   * Extracts a US five-digit postal-code partition from the full identity.
   */
  private function postalContext(string $address): string {
    return preg_match('/\b(\d{5})(?:-\d{4})?\b/', $address, $matches) === 1
      ? $matches[1]
      : '';
  }

  /**
   * Normalizes a provider postal code to its five-digit partition.
   */
  private function normalizePostal(string $postalCode): string {
    return preg_match('/\b(\d{5})(?:-\d{4})?\b/', $postalCode, $matches) === 1
      ? $matches[1]
      : '';
  }

  /**
   * Extracts the submitted building number without mistaking a business name.
   */
  private function houseNumber(string $address): string {
    return preg_match(
      '/^(?:[^,]+,\s*)?(\d{1,6}(?:-\d{1,6})?[a-z]?)\s+[\p{L}\d]/iu',
      $address,
      $matches,
    ) === 1
      ? $this->comparableNumber($matches[1])
      : '';
  }

  /**
   * Produces the comparison form of a building number.
   */
  private function comparableNumber(string $number): string {
    return strtolower(preg_replace('/[^0-9a-z-]+/i', '', trim($number)) ?? '');
  }

  /**
   * Builds the provider query while preserving the complete cache identity.
   */
  private function providerQuery(string $identity): string {
    // This extract has no admin_level=4 boundary, and Nominatim can return zero
    // candidates when a valid NY/NJ/CT abbreviation remains in the free-text
    // query.
    // It remains in cache identity; only the provider query omits it.
    $query = preg_replace(
      '/,\s*(?:ny|nj|ct)(?=\s+\d{5}(?:-\d{4})?(?:\s*,|\s*$))/iu',
      ',',
      $identity,
    ) ?? $identity;

    return preg_replace('/,\s*(?:ny|nj|ct)\s*$/iu', '', $query) ?? $query;
  }

  /**
   * Detects explicit geography that the bounded extract cannot contain.
   */
  private function isClearlyOutsideCoverage(
    string $address,
    string $postalCode,
  ): bool {
    if (preg_match('/,\s*(canada|mexico|united kingdom|uk)\s*$/iu', $address) === 1) {
      return TRUE;
    }
    if (preg_match(
      '/,\s*([a-z]{2})(?:\s+\d{5}(?:-\d{4})?)?(?:\s*,\s*(?:usa|us|united states))?\s*$/iu',
      $address,
      $matches,
    ) === 1 && !in_array(strtoupper($matches[1]), self::SUPPORTED_STATES, TRUE)) {
      return TRUE;
    }
    if ($postalCode !== '') {
      $prefix = (int) substr($postalCode, 0, 3);
      return !(($prefix >= 64 && $prefix <= 79) || ($prefix >= 100 && $prefix <= 119));
    }

    return FALSE;
  }

  /**
   * Returns great-circle separation used to reject ambiguous candidates.
   */
  private function distanceBetween(array $first, array $second): float {
    $latitude1 = deg2rad((float) $first['latitude']);
    $latitude2 = deg2rad((float) $second['latitude']);
    $latitudeDelta = $latitude2 - $latitude1;
    $longitudeDelta = deg2rad((float) $second['longitude'] - (float) $first['longitude']);
    $a = sin($latitudeDelta / 2) ** 2
      + cos($latitude1) * cos($latitude2) * sin($longitudeDelta / 2) ** 2;

    return 6_371_000 * 2 * atan2(sqrt($a), sqrt(max(0.0, 1 - $a)));
  }

}
