<?php

namespace Drupal\custom_webform_handlers\Distance;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Site\Settings;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\RequestOptions;
use Psr\Log\LoggerInterface;

/**
 * Calculates routes using Google Routes API v2 Compute Routes.
 *
 * One client, two questions, and the difference between them matters. Quote
 * pricing asks "how far apart are these addresses", which must give the same
 * answer whenever it is asked, so it asks TRAFFIC_UNAWARE. Dispatch scheduling
 * asks "how long will this drive take leaving at 17:00 next Tuesday", which is a
 * different question with a different answer, so it asks TRAFFIC_AWARE_OPTIMAL at
 * an explicit departure time with an explicitly configured traffic model.
 *
 * Both questions run through one private request path, so there is exactly one
 * endpoint, one server-side key, one timeout policy and one error classification
 * to reason about — adding a second Google client would double all four. What the
 * two paths do not share is cache identity: the key below carries the travel mode,
 * the routing preference, the traffic model and the departure bucket, so a
 * traffic-aware answer for one time of day can never be served as the answer for
 * another, nor to the pricing path that asked a time-independent question.
 *
 * The API key is read from settings.php and never from configuration, so it is
 * neither exported nor reachable from the browser.
 */
final class GoogleRoutesDistanceProvider implements DistanceProviderInterface, ScheduledRouteProviderInterface {

  private const ENDPOINT = 'https://routes.googleapis.com/directions/v2:computeRoutes';

  /**
   * The travel mode. Both questions are about the same van.
   *
   * Also a precondition: Google honours a routing preference only for DRIVE and
   * TWO_WHEELER, and a traffic model only for DRIVE.
   */
  private const TRAVEL_MODE = 'DRIVE';

  /**
   * Time-independent routing, for pricing.
   */
  private const PREFERENCE_UNAWARE = 'TRAFFIC_UNAWARE';

  /**
   * Traffic-aware routing at a stated departure, for scheduling.
   */
  private const PREFERENCE_SCHEDULED = 'TRAFFIC_AWARE_OPTIMAL';

  /**
   * The traffic models Google accepts, and the only values that may be sent.
   *
   * An unrecognised configured value is refused rather than forwarded: Google
   * would reject the request, and a rejected request reads as "no route" to the
   * planner, which is a much less useful thing to see in a log than a typo.
   */
  private const TRAFFIC_MODELS = ['BEST_GUESS', 'PESSIMISTIC', 'OPTIMISTIC'];

  /**
   * The model used when configuration names none.
   *
   * Google's own default, blending historical patterns with live conditions and
   * weighting live conditions more heavily the nearer the departure is.
   */
  private const DEFAULT_TRAFFIC_MODEL = 'BEST_GUESS';

  /**
   * Google's signal that it did not compute what was asked for.
   *
   * Returned in fallbackInfo when the server substituted another routing
   * preference — a server error or a latency ceiling on its side. Traffic-unaware
   * is the value that means the duration carries no traffic at all.
   */
  private const FALLBACK_UNAWARE = 'FALLBACK_TRAFFIC_UNAWARE';

  /**
   * Cache namespace.
   *
   * Third generation: entries are now keyed by the full question — addresses,
   * travel mode, routing preference, traffic model and departure bucket — rather
   * than by the address pair alone. Earlier entries are therefore not read, which
   * costs one round of cache misses after deployment and buys the guarantee that
   * no cached answer is reused for a question it does not answer.
   */
  private const CACHE_PREFIX = 'custom_webform_handlers:route:v3:';

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly CacheBackendInterface $cache,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
    private readonly ?TimeInterface $time = NULL,
    private readonly bool $internalCacheEnabled = TRUE,
    private readonly ?RoutingModeResolver $routingModeResolver = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function calculate(string $origin, string $destination): DistanceResult {
    // No departure instant: the pricing question is time-independent, and this
    // path's payload and cache identity contain no time at all.
    return $this->route($origin, $destination, NULL);
  }

  /**
   * {@inheritdoc}
   */
  public function calculateScheduled(string $origin, string $destination, int $departureAt): DistanceResult {
    return $this->route($origin, $destination, $departureAt);
  }

  /**
   * Measures one route, optionally as it is expected to be at a future instant.
   *
   * @param string $origin
   *   Origin address as entered.
   * @param string $destination
   *   Destination address as entered.
   * @param int|null $departureAt
   *   NULL asks the time-independent pricing question, whose payload and cache
   *   identity contain no time. A UNIX timestamp asks the traffic-aware
   *   scheduling question, quantized and moved into the future first.
   *
   * @return \Drupal\custom_webform_handlers\Distance\DistanceResult
   *   The measured route.
   *
   * @throws \Drupal\custom_webform_handlers\Distance\DistanceProviderException
   *   Thrown for unusable input, a missing key, a failed request, or a response
   *   that does not describe a usable route.
   */
  private function route(string $origin, string $destination, ?int $departureAt): DistanceResult {
    // Fail closed in development_fixed mode. If a caller is ever wired directly
    // to this adapter instead of through the customer-route entry facade, it
    // still cannot dial out: the mode is refused here, before any address
    // handling, key read or request is attempted. NOT_CONFIGURED is a classified
    // non-billing failure — not a provider fault — and one of the closed public
    // codes js/distance_calculator.js already handles.
    if ($this->routingModeResolver?->isDevelopmentFixed()) {
      throw DistanceProviderException::classified(DistanceProviderException::NOT_CONFIGURED, 'google_routes');
    }

    $origin = $this->normalizeAddress($origin);
    $destination = $this->normalizeAddress($destination);
    if ($origin === '' || $destination === '' || $origin === $destination) {
      throw DistanceProviderException::classified(DistanceProviderException::ADDRESS_UNUSABLE, 'google_routes');
    }

    $api_key = (string) Settings::get('custom_webform_handlers_server_api_key', '');
    if ($api_key === '') {
      throw DistanceProviderException::classified(DistanceProviderException::NOT_CONFIGURED, 'google_routes');
    }

    $config = $this->configFactory->get('custom_webform_handlers.settings');
    $scheduled = $departureAt !== NULL;
    $preference = $scheduled ? self::PREFERENCE_SCHEDULED : self::PREFERENCE_UNAWARE;
    $model = $scheduled ? $this->trafficModel($config) : '';
    $departure = $scheduled ? $this->departureInstant($config, (int) $departureAt) : 0;

    $cid = $this->cacheIdentity($origin, $destination, $preference, $model, $departure);
    if ($this->internalCacheEnabled && ($cached = $this->cache->get($cid))) {
      $data = $cached->data;
      // Older entries did not retain fetch provenance. Leave them untouched,
      // but refresh this pair once instead of inventing an original timestamp.
      if (is_array($data) && isset($data['calculated_at'], $data['metadata'])
        && is_int($data['calculated_at']) && $data['calculated_at'] > 0
        && is_array($data['metadata'])) {
        return DistanceResult::restored(
          (int) $data['distance_meters'],
          (int) $data['duration_seconds'],
          'google_routes',
          $data['calculated_at'],
          $data['metadata'],
          isset($data['encoded_polyline']) ? (string) $data['encoded_polyline'] : NULL,
          !empty($data['traffic_aware']),
        );
      }
    }

    $timeout = max(2, min(20, (int) ($config->get('route_request_timeout') ?: 8)));

    // One minimal mask per question. The scheduled mask carries no geometry — the
    // scheduling seam transports duration and distance only — and asks instead for
    // staticDuration, the traffic-free floor, and for fallbackInfo, which is
    // Google stating whether it honoured the preference it was sent.
    $mask = $scheduled
      ? 'routes.duration,routes.staticDuration,routes.distanceMeters,fallbackInfo.routingMode'
      : 'routes.distanceMeters,routes.duration,routes.polyline.encodedPolyline';

    $payload = [
      'origin' => ['address' => $origin],
      'destination' => ['address' => $destination],
      'travelMode' => self::TRAVEL_MODE,
      'routingPreference' => $preference,
      'computeAlternativeRoutes' => FALSE,
      'languageCode' => 'en-US',
      'units' => 'IMPERIAL',
    ];

    if ($scheduled) {
      // RFC 3339, Z-normalized, and in the future. Google accepts a past
      // departureTime only for TRANSIT; for DRIVE it is an error, which is why
      // departureInstant() clamps rather than trusting the caller's instant.
      $payload['departureTime'] = gmdate('Y-m-d\TH:i:s\Z', $departure);
      $payload['trafficModel'] = $model;
    }

    $fetched_at = $this->now();
    try {
      $response = $this->httpClient->request('POST', self::ENDPOINT, [
        RequestOptions::CONNECT_TIMEOUT => min(5, $timeout),
        RequestOptions::TIMEOUT => $timeout,
        RequestOptions::HTTP_ERRORS => FALSE,
        RequestOptions::ALLOW_REDIRECTS => FALSE,
        RequestOptions::HEADERS => [
          'Content-Type' => 'application/json',
          'X-Goog-Api-Key' => $api_key,
          'X-Goog-FieldMask' => $mask,
        ],
        RequestOptions::JSON => $payload,
      ]);

      $status = $response->getStatusCode();
      if ($status < 200 || $status >= 300) {
        $this->logger->warning('Routes API returned HTTP status @status.', ['@status' => $status]);
        $classification = match ($status) {
          401, 403, 404 => DistanceProviderException::NOT_CONFIGURED,
          408, 504 => DistanceProviderException::TIMEOUT,
          429 => DistanceProviderException::THROTTLED,
          default => DistanceProviderException::UNCLASSIFIED,
        };
        $retry = trim($response->getHeaderLine('Retry-After'));
        throw DistanceProviderException::classified($classification, 'google_routes',
          $status === 429 && ctype_digit($retry) ? min(86400, (int) $retry) : NULL);
      }

      $data = json_decode((string) $response->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
      if (!is_array($data) || (isset($data['routes']) && !is_array($data['routes']))) {
        throw DistanceProviderException::classified(DistanceProviderException::MALFORMED_RESPONSE, 'google_routes');
      }
      $route = $data['routes'][0] ?? NULL;
      if ($route === NULL) {
        throw DistanceProviderException::classified(DistanceProviderException::NO_ROUTE, 'google_routes');
      }
      if (!is_array($route) || !is_int($route['distanceMeters'] ?? NULL)
        || $route['distanceMeters'] < 0 || !is_string($route['duration'] ?? NULL)) {
        throw DistanceProviderException::classified(DistanceProviderException::MALFORMED_RESPONSE, 'google_routes');
      }
      $distance_meters = $route['distanceMeters'];
      $duration_seconds = $this->parseDuration($route['duration'], $scheduled);
      $encoded_polyline = $route['polyline']['encodedPolyline'] ?? '';
      if (!is_string($encoded_polyline)) {
        throw DistanceProviderException::classified(DistanceProviderException::MALFORMED_RESPONSE, 'google_routes');
      }
      $encoded_polyline = trim($encoded_polyline);
      if ($distance_meters === 0) {
        throw DistanceProviderException::classified(DistanceProviderException::NO_ROUTE, 'google_routes');
      }

      $traffic_aware = FALSE;
      if ($scheduled) {
        // Never below the traffic-free time. A scheduled duration under the
        // free-flow one would say traffic made the drive quicker than an empty
        // road; the higher of the two is the only figure worth planning against.
        if (!is_string($route['staticDuration'] ?? NULL)) {
          throw DistanceProviderException::classified(DistanceProviderException::MALFORMED_RESPONSE, 'google_routes');
        }
        $static_seconds = $this->parseDuration($route['staticDuration'], TRUE);
        $duration_seconds = max($duration_seconds, $static_seconds);

        // Google reports a substituted routing preference rather than failing, so
        // a traffic-aware request can succeed and still return a traffic-free
        // duration. Believing the request instead of the response here is exactly
        // how a schedule comes to be built on peak-hour optimism.
        $fallback = $data['fallbackInfo'] ?? NULL;
        $traffic_aware = $fallback === NULL || (is_array($fallback)
          && ($fallback['routingMode'] ?? '') === 'FALLBACK_TRAFFIC_AWARE');
        if (!$traffic_aware) {
          $this->logger->warning('Routes API substituted traffic-unaware routing, so this scheduled duration carries no traffic.');
        }

        if ($duration_seconds <= 0) {
          // A length with no time is not a schedulable answer, and passing zero
          // back would read as "no travel time at all" to the planner.
          throw DistanceProviderException::classified(DistanceProviderException::NO_ROUTE, 'google_routes');
        }
      }

      $ttl = $scheduled
        ? max(60, min(3600, (int) ($config->get('route_scheduled_cache_ttl') ?: 900)))
        : max(60, min(86400, (int) ($config->get('route_cache_ttl') ?: 3600)));

      $metadata = [
        'fetched_at' => $fetched_at,
        'expires_at' => $fetched_at + $ttl,
        'provider_version' => 'routes-v2',
        'profile' => $preference,
        'effective_departure_at' => $scheduled ? $departure : NULL,
      ];
      if ($this->internalCacheEnabled) {
        try {
          $this->cache->set($cid, [
            'distance_meters' => $distance_meters,
            'duration_seconds' => $duration_seconds,
            'encoded_polyline' => $encoded_polyline,
            'traffic_aware' => $traffic_aware,
            'calculated_at' => $fetched_at,
            'metadata' => $metadata,
          ], $fetched_at + $ttl);
        }
        catch (\Throwable) {
          // A cache-write failure must not turn a successful billed route into
          // another paid fallback attempt. The result itself remains usable.
          $this->logger->warning('Routes API result could not be cached.');
        }
      }

      return new DistanceResult(
        $distance_meters,
        $duration_seconds,
        'google_routes',
        $fetched_at,
        $encoded_polyline,
        $traffic_aware,
        $metadata,
      );
    }
    catch (DistanceProviderException $exception) {
      throw $exception;
    }
    catch (ConnectException $exception) {
      throw DistanceProviderException::classified(
        ($exception->getHandlerContext()['errno'] ?? 0) === 28
          ? DistanceProviderException::TIMEOUT : DistanceProviderException::UNCLASSIFIED,
        'google_routes',
      );
    }
    catch (\JsonException) {
      throw DistanceProviderException::classified(DistanceProviderException::MALFORMED_RESPONSE, 'google_routes');
    }
    catch (\Throwable $exception) {
      $this->logger->error('Routes API request failed: @type', ['@type' => get_class($exception)]);
      throw DistanceProviderException::classified(DistanceProviderException::UNCLASSIFIED, 'google_routes');
    }
  }

  /**
   * Builds the cache identity for one question.
   *
   * Everything that can change the answer is in the hash. Addresses are folded to
   * lower case because they are already whitespace-normalized and Google does not
   * distinguish the case; mode, preference and model are in because they select
   * different arithmetic on Google's side; and the departure bucket is in because
   * a traffic-aware route is only an answer about the hour it was asked for.
   *
   * @param string $origin
   *   Normalized origin address.
   * @param string $destination
   *   Normalized destination address.
   * @param string $preference
   *   The routing preference that will be sent.
   * @param string $model
   *   The traffic model that will be sent, or the empty string when none is.
   * @param int $departure
   *   The bucketed departure instant, or 0 for a time-independent question.
   *
   * @return string
   *   The cache ID.
   */
  private function cacheIdentity(string $origin, string $destination, string $preference, string $model, int $departure): string {
    return self::CACHE_PREFIX . hash('sha256', implode("\0", [
      mb_strtolower($origin),
      mb_strtolower($destination),
      self::TRAVEL_MODE,
      $preference,
      $model,
      (string) $departure,
    ]));
  }

  /**
   * Resolves the departure instant that will be both sent and cached against.
   *
   * Two jobs, and they have to be done in this order. First the instant is moved
   * far enough forward to satisfy Google's rule that a DRIVE departure is not in
   * the past — a planner legitimately asks about a working day whose start has
   * already gone by. Then it is floored to a configured bucket, so that measuring a
   * day does not issue one billed request per grid candidate; if flooring puts it
   * back behind the future floor, it steps up one bucket rather than down.
   *
   * The returned instant is what the payload carries and what the cache key
   * contains, so the two can never describe different times.
   *
   * @param \Drupal\Core\Config\ImmutableConfig $config
   *   Route settings.
   * @param int $departureAt
   *   The caller's intended departure.
   *
   * @return int
   *   A future, bucket-aligned UNIX timestamp.
   */
  private function departureInstant(ImmutableConfig $config, int $departureAt): int {
    return RoutingPolicy::effectiveDeparture($config, $departureAt, $this->now());
  }

  /**
   * Returns the configured traffic model.
   *
   * Configured explicitly rather than left to Google's default, because which
   * assumption the schedule is built on is an operational decision: BEST_GUESS
   * blends live and historical conditions, PESSIMISTIC deliberately over-states
   * the drive, OPTIMISTIC under-states it. An unrecognised value is refused and
   * logged by name so the mistake is visible, and the default is used rather than
   * forwarding something Google would reject.
   *
   * @param \Drupal\Core\Config\ImmutableConfig $config
   *   Route settings.
   *
   * @return string
   *   One of self::TRAFFIC_MODELS.
   */
  private function trafficModel(ImmutableConfig $config): string {
    $model = strtoupper(trim((string) $config->get('route_traffic_model')));
    if ($model === '') {
      return self::DEFAULT_TRAFFIC_MODEL;
    }

    if (!in_array($model, self::TRAFFIC_MODELS, TRUE)) {
      $this->logger->warning('Unrecognised route_traffic_model @model; using @default.', [
        '@model' => $model,
        '@default' => self::DEFAULT_TRAFFIC_MODEL,
      ]);
      return self::DEFAULT_TRAFFIC_MODEL;
    }

    return $model;
  }

  /**
   * Normalizes whitespace without changing Google's address interpretation.
   */
  private function normalizeAddress(string $address): string {
    return trim((string) preg_replace('/\s+/u', ' ', $address));
  }

  /**
   * Parses Google's duration format, rounding scheduling durations upward.
   */
  private function parseDuration(string $duration, bool $roundUp = FALSE): int {
    if (preg_match('/^([0-9]+(?:\.[0-9]+)?)s$/', $duration, $matches)) {
      $seconds = (float) $matches[1];
      if (is_finite($seconds) && $seconds <= 2147483647) {
        return (int) ($roundUp ? ceil($seconds) : round($seconds));
      }
    }
    throw DistanceProviderException::classified(DistanceProviderException::MALFORMED_RESPONSE, 'google_routes');
  }

  /**
   * Uses the injected clock; optional to preserve existing direct construction.
   */
  private function now(): int {
    return $this->time?->getCurrentTime() ?? time();
  }

}
