<?php

namespace Drupal\custom_webform_handlers\Distance;

use Drupal\Component\Utility\Crypt;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Site\Settings;

/**
 * The routing rules for one purpose, resolved once and then immutable.
 *
 * A purpose is why a route is being measured, and it is the unit policy is written
 * in, because cost and correctness both follow from it: a preview a visitor may
 * trigger by typing cannot be allowed to spend what a staff member's validated
 * final check may. Task 20A's matrix lists six situations; three of them share a
 * policy with another exactly, so this class defines three purposes and the matrix
 * rows collapse onto them:
 *
 * - ordinary — the browser preview, the distance-based price, and scheduling that
 *   existing policy already permits to use a non-traffic estimate. All three ask
 *   the time-independent question, so one chain answers all three.
 * - offer — public Slot offers and the background warming that fills their cache.
 *   Warming exists to make offers cheap; giving it a different provider order
 *   would warm a cache that offers then miss.
 * - final — validated final booking operations only, reached from a server-checked
 *   checkout confirm or a permissioned staff replan.
 *
 * There is deliberately no configuration key for "does this purpose need traffic".
 * Whether a non-traffic duration may be scheduled against is already decided by
 * custom_webform_handlers.dispatch:routing.require_traffic_aware and enforced at
 * DispatchPlanner::routeLeg(); a second key here would be a second answer to a
 * question that already has an owner. What this class decides is narrower and is
 * not a preference at all: which of the two provider contracts the purpose calls.
 *
 * Nothing constructs this class from a service id named in configuration. The
 * chain instances are wired explicitly in custom_webform_handlers.services.yml
 * with an injected map of adapters, so the set of providers that can possibly run
 * is fixed at build time and configuration only chooses an order within it.
 *
 * @see \Drupal\custom_webform_handlers\Distance\DistanceProviderInterface
 * @see \Drupal\custom_webform_handlers\Distance\ScheduledRouteProviderInterface
 */
final class RoutingPolicy {

  /**
   * Preview, distance-based price, and non-traffic scheduling.
   */
  public const PURPOSE_ORDINARY = 'ordinary';

  /**
   * Public Slot offers and the warming that precedes them.
   */
  public const PURPOSE_OFFER = 'offer';

  /**
   * Validated final booking operations, reached only from a checked seam.
   */
  public const PURPOSE_FINAL = 'final';

  public const PURPOSES = [
    self::PURPOSE_ORDINARY,
    self::PURPOSE_OFFER,
    self::PURPOSE_FINAL,
  ];

  /**
   * Self-hosted OSRM. Distance and free-flow duration only, no traffic, no fee.
   */
  public const PROVIDER_OSRM = 'osrm';

  /**
   * Mapbox Directions provider.
   *
   * Metered, and the only paid provider policy permits below the final purpose.
   */
  public const PROVIDER_MAPBOX = 'mapbox';

  /**
   * Google Routes. The final-only and most expensive provider.
   */
  public const PROVIDER_GOOGLE = 'google_routes';

  /**
   * The only provider ids this module will act on.
   *
   * An id outside this list is refused rather than ignored. Ignoring it would let
   * a typo silently shorten a chain, and a chain one provider shorter than the
   * operator believes is exactly how a preview ends up on a billed provider.
   */
  public const PROVIDERS = [
    self::PROVIDER_OSRM,
    self::PROVIDER_MAPBOX,
    self::PROVIDER_GOOGLE,
  ];

  /**
   * Purposes that call calculateScheduled() rather than calculate().
   */
  private const SCHEDULED_PURPOSES = [
    self::PURPOSE_OFFER,
    self::PURPOSE_FINAL,
  ];

  /**
   * Providers that implement the departure-stamped contract at all.
   *
   * OSRM is absent because standard OSRM has no live traffic feed. It is not a
   * degraded member of this list — it cannot answer the question, so naming it in
   * a scheduled purpose is refused rather than quietly filtered.
   */
  private const SCHEDULED_PROVIDERS = [
    self::PROVIDER_MAPBOX,
    self::PROVIDER_GOOGLE,
  ];

  /**
   * The only purpose Google may appear in.
   *
   * Task 20A settled this as policy and not as tuning: Google's allowance for a
   * preview, an ordinary price, a public offer and warming is zero permanently.
   * Refusing the configuration is the point — a warning would leave the operator
   * with a saved chain that quietly bills the most expensive provider for the
   * cheapest request on the site.
   */
  private const GOOGLE_ELIGIBLE_PURPOSES = [
    self::PURPOSE_FINAL,
  ];

  /**
   * Environment-backed Settings key holding the private OSRM base endpoint.
   *
   * A URL and not a host, because the deployment decides its own path prefix. It
   * lives in settings.php rather than configuration so that an exported
   * configuration file never names an internal address, and so that no request,
   * form or drupalSettings value can influence where this module sends addresses.
   */
  public const SETTING_OSRM_BASE_URL = 'custom_webform_handlers_osrm_base_url';

  /**
   * Environment-backed Settings key holding the Mapbox server access token.
   */
  public const SETTING_MAPBOX_TOKEN = 'custom_webform_handlers_mapbox_access_token';

  /**
   * Environment-backed Settings key holding the Google server API key.
   *
   * Already in use by the Google adapter. Named here so the settings form can
   * report whether it is present without reading its value.
   */
  public const SETTING_GOOGLE_KEY = 'custom_webform_handlers_server_api_key';

  /**
   * Which Settings key has to be present before each provider can be reached.
   */
  private const CREDENTIAL_SETTINGS = [
    self::PROVIDER_OSRM => self::SETTING_OSRM_BASE_URL,
    self::PROVIDER_MAPBOX => self::SETTING_MAPBOX_TOKEN,
    self::PROVIDER_GOOGLE => self::SETTING_GOOGLE_KEY,
  ];

  /**
   * Hosts that may never be permitted, whatever an operator allowlists.
   *
   * The public OSRM demonstration server is not a production dependency: it offers
   * no availability undertaking and sending customer addresses to it is a
   * disclosure this site has not agreed to. Task 20E's runbook stands up a private
   * deployment instead.
   */
  private const FORBIDDEN_OSRM_HOSTS = [
    'router.project-osrm.org',
  ];

  /**
   * The route-cache policy version.
   *
   * Prevents a policy change from serving results calculated under the previous
   * one.
   */
  public const POLICY_VERSION = 'v1';

  /**
   * Existing keys that keep sole ownership of Google's timings.
   *
   * Google's timeout and its two cache lifetimes are already configured, already
   * clamped inside the adapter, and already in service. Restating them under
   * routing.providers.google_routes would create a second answer to a live
   * question, which is how the quote and Slot surfaces contradicted each other in
   * Task 5D. So the new keys carry 0 for Google, meaning "read the existing key",
   * and there is still exactly one place to change a Google timing.
   */
  private const LEGACY_TIMEOUT_KEY = 'route_request_timeout';

  private const LEGACY_CACHE_TTL_KEY = 'route_cache_ttl';

  private const LEGACY_SCHEDULED_CACHE_TTL_KEY = 'route_scheduled_cache_ttl';

  private const MIN_TIMEOUT_MS = 250;

  private const MAX_TIMEOUT_MS = 30000;

  /**
   * The longest a whole chain may be given for one purpose.
   *
   * Higher than any single provider's ceiling because a chain is allowed to try more
   * than one provider, and low enough that a deadline cannot be set past the point
   * where a visitor has already left the page.
   */
  private const MAX_DEADLINE_MS = 60000;

  private const MAX_CACHE_TTL = 2592000;

  private const BILLING_PERIODS = ['day', 'month'];

  private const OSRM_SCHEMES = ['http', 'https'];

  /**
   * Every default this module reads when a key is absent from active config.
   *
   * These values are the same ones config/install ships, and they have to stay the
   * same ones: active configuration on an existing site predates the routing
   * subtree entirely, so until Task 20F's migration runs, every read below comes
   * from here. The correspondence between this array and the installed YAML is
   * checked as part of Task 20C's verification rather than assumed.
   *
   * The defaults enable nothing that costs money. OSRM and Mapbox stay disabled
   * until the rollout explicitly enables a ready provider; every metered
   * allowance ships at 0, which Task 20A fixed as "no calls" and not "no limit".
   * Mapbox result caching ships off and is gated behind an explicit terms
   * acknowledgement. Google is recognized but has an allowance of 0, so the
   * final chain cannot issue a Google request before operator activation.
   */
  public const DEFAULTS = [
    'billing_period' => 'month',
    'billing_timezone' => 'UTC',
    'geocoder' => [
      'provider' => 'ppu_nominatim',
      'version' => 'nycmetro-20260904T202121Z+md5.77b0c1c4',
      'persist' => TRUE,
      'request_allowance' => 100000,
    ],
    'purposes' => [
      self::PURPOSE_ORDINARY => [
        'order' => [self::PROVIDER_OSRM, self::PROVIDER_MAPBOX],
        'deadline_ms' => 4000,
        'request_allowance' => [],
      ],
      self::PURPOSE_OFFER => [
        'order' => [self::PROVIDER_MAPBOX],
        'deadline_ms' => 6000,
        'request_allowance' => [],
      ],
      self::PURPOSE_FINAL => [
        'order' => [self::PROVIDER_MAPBOX, self::PROVIDER_GOOGLE],
        'deadline_ms' => 12000,
        'request_allowance' => [],
      ],
    ],
    'providers' => [
      // OSRM carries no request_allowance, and its absence is the point: a
      // self-hosted extract bills nothing per request, so there is no allowance to
      // reserve and no period to reset. What limits it is its own host — expressed
      // here as a timeout and a circuit, not as money.
      self::PROVIDER_OSRM => [
        'enabled' => FALSE,
        'allowed_schemes' => ['https'],
        'allowed_hosts' => [],
        'profile' => 'driving',
        'timeout_ms' => 2500,
        'cache_results' => TRUE,
        'cache_ttl' => 604800,
        'data_version' => '',
        'circuit_failure_threshold' => 5,
        'circuit_cooldown_seconds' => 60,
        'circuit_half_open_probes' => 1,
      ],
      self::PROVIDER_MAPBOX => [
        'enabled' => FALSE,
        'profile' => 'driving',
        'traffic_profile' => 'driving-traffic',
        'timeout_ms' => 3000,
        'cache_results' => FALSE,
        'cache_terms_recorded' => FALSE,
        'cache_ttl' => 900,
        'data_version' => '',
        'circuit_failure_threshold' => 4,
        'circuit_cooldown_seconds' => 120,
        'circuit_half_open_probes' => 1,
        'request_allowance' => 0,
      ],
      self::PROVIDER_GOOGLE => [
        'enabled' => TRUE,
        'timeout_ms' => 0,
        'cache_results' => TRUE,
        'cache_ttl' => 0,
        'data_version' => '',
        'circuit_failure_threshold' => 3,
        'circuit_cooldown_seconds' => 300,
        'circuit_half_open_probes' => 1,
        'request_allowance' => 0,
      ],
    ],
  ];

  /**
   * Constructs one fully resolved immutable policy.
   *
   * @param string $purpose
   *   One of this class's purpose constants.
   * @param string[] $order
   *   Eligible providers in the order they will be asked. Already filtered to
   *   enabled providers that can answer this purpose's question.
   * @param array $providers
   *   Provider id to its resolved settings, timings included.
   * @param int $deadlineMs
   *   Wall-clock the whole chain may spend for this purpose.
   * @param array $allowances
   *   Provider id to the allowance this purpose alone may spend, where one is set.
   * @param array $geocoder
   *   The resolved geocoder selection.
   * @param string $billingPeriod
   *   Either 'day' or 'month'.
   * @param string $billingTimezone
   *   The calendar a period rolls over on. Never a visitor's timezone.
   * @param string $questionVersion
   *   Cache identity for scheduled bucketing and traffic assumptions.
   */
  private function __construct(
    private readonly string $purpose,
    private readonly array $order,
    private readonly array $providers,
    private readonly int $deadlineMs,
    private readonly array $allowances,
    private readonly array $geocoder,
    private readonly string $billingPeriod,
    private readonly string $billingTimezone,
    private readonly string $questionVersion,
  ) {}

  /**
   * Resolves the policy one purpose will run under.
   *
   * @param \Drupal\Core\Config\ImmutableConfig $settings
   *   The custom_webform_handlers.settings object. The whole object and not the
   *   routing subtree, because Google's timings still live at its top level.
   * @param string $purpose
   *   One of this class's purpose constants.
   *
   * @return self
   *   The resolved, immutable policy.
   *
   * @throws \Drupal\custom_webform_handlers\Distance\DistanceProviderException
   *   Thrown when the purpose is unknown, the stored configuration is structurally
   *   invalid, or no provider is eligible to serve the purpose. An empty chain is a
   *   configuration failure and is raised as one: returning a policy that can ask
   *   nobody would turn a missing provider into a silent zero-mile route.
   */
  public static function fromConfig(ImmutableConfig $settings, string $purpose): self {
    if (!in_array($purpose, self::PURPOSES, TRUE)) {
      throw DistanceProviderException::classified(DistanceProviderException::NOT_CONFIGURED);
    }
    $stored = $settings->get('routing');
    $routing = self::merge(is_array($stored) ? $stored : []);
    if (self::validate($routing) !== []) {
      throw DistanceProviderException::classified(DistanceProviderException::NOT_CONFIGURED);
    }

    $scheduled = in_array($purpose, self::SCHEDULED_PURPOSES, TRUE);
    $providers = [];
    foreach (self::PROVIDERS as $id) {
      $providers[$id] = self::resolveProvider($id, $routing['providers'][$id], $settings, $scheduled);
    }

    $order = [];
    foreach ($routing['purposes'][$purpose]['order'] as $id) {
      if (!in_array($id, self::PROVIDERS, TRUE) || !$providers[$id]['enabled']) {
        continue;
      }
      // A provider that cannot answer this purpose's question is dropped here as
      // well as refused at save time, because a stored configuration can predate
      // the rule that refuses it.
      if ($scheduled && !in_array($id, self::SCHEDULED_PROVIDERS, TRUE)) {
        continue;
      }
      if ($id === self::PROVIDER_GOOGLE && !in_array($purpose, self::GOOGLE_ELIGIBLE_PURPOSES, TRUE)) {
        continue;
      }
      $order[] = $id;
    }
    if ($order === []) {
      throw DistanceProviderException::classified(DistanceProviderException::NOT_CONFIGURED);
    }

    $allowances = [];
    foreach ($routing['purposes'][$purpose]['request_allowance'] as $id => $value) {
      if (in_array($id, self::PROVIDERS, TRUE)) {
        $allowances[$id] = max(0, (int) $value);
      }
    }

    return new self(
      $purpose,
      $order,
      $providers,
      (int) $routing['purposes'][$purpose]['deadline_ms'],
      $allowances,
      $routing['geocoder'],
      (string) $routing['billing_period'],
      (string) $routing['billing_timezone'],
      implode(':', [
        max(60, min(3600, (int) ($settings->get('route_departure_bucket_seconds') ?: 1800))),
        max(0, min(3600, (int) ($settings->get('route_departure_min_lead_seconds') ?: 60))),
        strtoupper(trim((string) $settings->get('route_traffic_model'))),
      ]),
    );
  }

  /**
   * Resolves the fixed billing calendar without selecting a routing purpose.
   *
   * The geocoder is a separately metered product and can need this calendar
   * before any directions purpose is eligible. It must not borrow a visitor's
   * timezone or require an enabled route provider merely to find period bounds.
   *
   * @return array{period: string, timezone: string}
   *   The validated period and timezone.
   */
  public static function budgetCalendar(ImmutableConfig $settings): array {
    $stored = $settings->get('routing');
    $routing = self::merge(is_array($stored) ? $stored : []);
    $period = (string) $routing['billing_period'];
    $timezone = (string) $routing['billing_timezone'];
    try {
      new \DateTimeZone($timezone);
    }
    catch (\Throwable) {
      throw DistanceProviderException::classified(DistanceProviderException::NOT_CONFIGURED);
    }
    if (!in_array($period, ['day', 'month'], TRUE)) {
      throw DistanceProviderException::classified(DistanceProviderException::NOT_CONFIGURED);
    }

    return ['period' => $period, 'timezone' => $timezone];
  }

  /**
   * Returns the exact future departure bucket every scheduled adapter must use.
   */
  public static function effectiveDeparture(ImmutableConfig $settings, int $departureAt, int $now): int {
    $bucket = max(60, min(3600, (int) ($settings->get('route_departure_bucket_seconds') ?: 1800)));
    $lead = max(0, min(3600, (int) ($settings->get('route_departure_min_lead_seconds') ?: 60)));
    $floor = $now + $lead;
    $aligned = intdiv(max($departureAt, $floor), $bucket) * $bucket;

    return $aligned < $floor ? $aligned + $bucket : $aligned;
  }

  /**
   * Fills a stored routing subtree in from the defaults, key by key.
   *
   * Deliberately not array_merge_recursive or a blanket array_replace_recursive
   * over unknown depth: an ordered list must be replaced whole, never merged, or a
   * saved two-provider order would silently inherit a third entry from the
   * defaults. Only keys the defaults define are read, so an unknown key that has
   * survived in active configuration cannot influence a routing decision.
   *
   * @param array $stored
   *   The routing subtree as stored, which on a site that predates Task 20C is
   *   simply absent.
   *
   * @return array
   *   A complete subtree with every key present.
   */
  public static function merge(array $stored): array {
    $merged = self::DEFAULTS;
    foreach (['billing_period', 'billing_timezone'] as $key) {
      if (isset($stored[$key]) && is_string($stored[$key])) {
        $merged[$key] = $stored[$key];
      }
    }
    foreach (array_keys(self::DEFAULTS['geocoder']) as $key) {
      if (isset($stored['geocoder'][$key])) {
        $merged['geocoder'][$key] = $stored['geocoder'][$key];
      }
    }
    foreach (self::PURPOSES as $purpose) {
      foreach (array_keys(self::DEFAULTS['purposes'][$purpose]) as $key) {
        if (isset($stored['purposes'][$purpose][$key])) {
          $merged['purposes'][$purpose][$key] = $stored['purposes'][$purpose][$key];
        }
      }
    }
    foreach (self::PROVIDERS as $provider) {
      foreach (array_keys(self::DEFAULTS['providers'][$provider]) as $key) {
        if (isset($stored['providers'][$provider][$key])) {
          $merged['providers'][$provider][$key] = $stored['providers'][$provider][$key];
        }
      }
    }

    return $merged;
  }

  /**
   * Resolves one provider's settings for one purpose.
   *
   * Two resolutions happen here rather than at the point of use. The profile is
   * chosen once, so no adapter has to re-decide whether this purpose is the
   * traffic-aware one — the research note is explicit that a profile named
   * "driving-traffic" is a profile choice and not a licence to claim traffic
   * awareness, and keeping the decision here keeps the two from drifting apart.
   * Google's zero timings resolve to the existing keys, so that nothing downstream
   * has to know the inheritance rule exists.
   */
  private static function resolveProvider(string $id, array $stored, ImmutableConfig $settings, bool $scheduled): array {
    $resolved = $stored;
    $resolved['enabled'] = (bool) ($stored['enabled'] ?? FALSE);
    $resolved['cache_results'] = (bool) ($stored['cache_results'] ?? FALSE);
    $resolved['data_version'] = trim((string) ($stored['data_version'] ?? ''));
    $resolved['request_allowance'] = isset($stored['request_allowance'])
      ? max(0, (int) $stored['request_allowance'])
      : NULL;

    if ($id === self::PROVIDER_MAPBOX) {
      $resolved['profile'] = $scheduled
        ? (string) ($stored['traffic_profile'] ?? 'driving-traffic')
        : (string) ($stored['profile'] ?? 'driving');
      // Result caching stays off until an operator has recorded that the terms
      // permit it. Task 20A read the published terms and could not establish that
      // they do, so the acknowledgement is a separate, deliberate act.
      $resolved['cache_results'] = $resolved['cache_results']
        && (bool) ($stored['cache_terms_recorded'] ?? FALSE);
    }

    $timeout = (int) ($stored['timeout_ms'] ?? 0);
    if ($id === self::PROVIDER_GOOGLE && $timeout === 0) {
      $timeout = 1000 * max(2, min(20, (int) $settings->get(self::LEGACY_TIMEOUT_KEY) ?: 8));
    }
    $resolved['timeout_ms'] = max(self::MIN_TIMEOUT_MS, min(self::MAX_TIMEOUT_MS, $timeout));

    $ttl = (int) ($stored['cache_ttl'] ?? 0);
    if ($id === self::PROVIDER_GOOGLE && $ttl === 0) {
      $ttl = $scheduled
        ? max(60, min(3600, (int) $settings->get(self::LEGACY_SCHEDULED_CACHE_TTL_KEY) ?: 900))
        : max(60, min(86400, (int) $settings->get(self::LEGACY_CACHE_TTL_KEY) ?: 3600));
    }
    $resolved['cache_ttl'] = max(0, min(self::MAX_CACHE_TTL, $ttl));

    return $resolved;
  }

  /**
   * Resolves one adapter's settings without choosing a caller's purpose.
   *
   * Both scheduled chain instances share the Mapbox adapter. Resolving an offer
   * policy inside that adapter would incorrectly make final checks depend on
   * the offer provider order. Eligibility and allowance reservation remain the
   * chain's responsibility; these settings are not authorization to spend.
   */
  public static function adapterSettings(ImmutableConfig $settings, string $provider, bool $scheduled = FALSE): array {
    if (!in_array($provider, self::PROVIDERS, TRUE)
      || ($scheduled && !in_array($provider, self::SCHEDULED_PROVIDERS, TRUE))) {
      throw DistanceProviderException::classified(DistanceProviderException::NOT_CONFIGURED);
    }
    $stored = $settings->get('routing');
    $routing = self::merge(is_array($stored) ? $stored : []);
    if (self::validate($routing) !== [] || !$routing['providers'][$provider]['enabled']) {
      throw DistanceProviderException::classified(DistanceProviderException::NOT_CONFIGURED, $provider);
    }

    return self::resolveProvider($provider, $routing['providers'][$provider], $settings, $scheduled);
  }

  /**
   * Reuses the endpoint allowlist for a purpose-independent OSRM adapter.
   */
  public static function adapterOsrmEndpoint(array $settings): string {
    $endpoint = self::resolveOsrmEndpoint($settings);
    if ($endpoint === NULL) {
      throw DistanceProviderException::classified(DistanceProviderException::NOT_CONFIGURED, self::PROVIDER_OSRM);
    }

    return $endpoint;
  }

  /**
   * The purpose this policy was resolved for.
   */
  public function purpose(): string {
    return $this->purpose;
  }

  /**
   * Whether this purpose asks calculateScheduled() rather than calculate().
   */
  public function isScheduled(): bool {
    return in_array($this->purpose, self::SCHEDULED_PURPOSES, TRUE);
  }

  /**
   * Providers to ask, in order, already filtered to those eligible to be asked.
   *
   * @return string[]
   *   One or more allowlisted provider ids. Never empty — an empty chain is a
   *   configuration failure raised at resolution time.
   */
  public function providerOrder(): array {
    return $this->order;
  }

  /**
   * Wall-clock the whole chain may spend on this purpose, in milliseconds.
   */
  public function deadlineMs(): int {
    return $this->deadlineMs;
  }

  /**
   * One provider's timeout, in milliseconds.
   */
  public function timeoutMs(string $provider): int {
    return (int) ($this->providers[$provider]['timeout_ms'] ?? self::MIN_TIMEOUT_MS);
  }

  /**
   * The routing profile to ask this provider for, chosen for this purpose.
   */
  public function profile(string $provider): string {
    return (string) ($this->providers[$provider]['profile'] ?? '');
  }

  /**
   * Whether this provider's results may be written to a cache at all.
   *
   * A permission and not a preference. Caching a provider's answer keeps a copy of
   * its product, which the terms behind that product may or may not allow, so an
   * unresolved answer here reads FALSE.
   */
  public function mayCacheResults(string $provider): bool {
    return (bool) ($this->providers[$provider]['cache_results'] ?? FALSE);
  }

  /**
   * How long a cached result from this provider stays usable, in seconds.
   */
  public function cacheTtl(string $provider): int {
    return (int) ($this->providers[$provider]['cache_ttl'] ?? 0);
  }

  /**
   * The operator-recorded dataset version, which participates in cache identity.
   *
   * An empty string is meaningful: it says the deployment has not been stamped, so
   * a cache built before a data change cannot be told apart from one built after.
   */
  public function dataVersion(string $provider): string {
    return (string) ($this->providers[$provider]['data_version'] ?? '');
  }

  /**
   * Circuit-breaker thresholds for one provider.
   *
   * @return array{failures: int, cooldown: int, probes: int}
   *   Consecutive faults that open the breaker, seconds it stays open, and how
   *   many probes may test a half-open provider.
   */
  public function circuit(string $provider): array {
    return [
      'failures' => (int) ($this->providers[$provider]['circuit_failure_threshold'] ?? 1),
      'cooldown' => (int) ($this->providers[$provider]['circuit_cooldown_seconds'] ?? 60),
      'probes' => (int) ($this->providers[$provider]['circuit_half_open_probes'] ?? 1),
    ];
  }

  /**
   * The billed request allowance for this provider and purpose, per period.
   *
   * NULL means the provider is not metered and has no allowance to reserve, which
   * is only true of the self-hosted one. Zero means no calls: Task 20A fixed that
   * reading, so a caller must never treat 0 as "unlimited" or as "not configured
   * yet". The narrower of the purpose allowance and the provider-wide allowance
   * applies, because the provider-wide figure has to cover every purpose at once.
   */
  public function requestAllowance(string $provider): ?int {
    $aggregate = $this->providers[$provider]['request_allowance'] ?? NULL;
    if ($aggregate === NULL) {
      return NULL;
    }
    $aggregate = (int) $aggregate;

    return isset($this->allowances[$provider])
      ? min($aggregate, (int) $this->allowances[$provider])
      : $aggregate;
  }

  /**
   * This purpose's own share of a provider allowance, when one is configured.
   */
  public function purposeAllowance(string $provider): ?int {
    return array_key_exists($provider, $this->allowances)
      ? (int) $this->allowances[$provider]
      : NULL;
  }

  /**
   * The provider-wide allowance, before this purpose's own limit narrows it.
   */
  public function providerWideAllowance(string $provider): ?int {
    $aggregate = $this->providers[$provider]['request_allowance'] ?? NULL;

    return $aggregate === NULL ? NULL : (int) $aggregate;
  }

  /**
   * The geocoder selected to turn a committed address into coordinates.
   *
   * An empty string means none is selected, which Task 20D treats as a blocker
   * rather than a default: guessing a geocoder would guess a billing product.
   */
  public function geocoderProvider(): string {
    return trim((string) ($this->geocoder['provider'] ?? ''));
  }

  /**
   * The geocoder's operator-recorded version, part of coordinate cache identity.
   */
  public function geocoderVersion(): string {
    return trim((string) ($this->geocoder['version'] ?? ''));
  }

  /**
   * Whether resolved coordinates may be kept in a persistent store.
   *
   * Separate from route caching and separately licensed. Task 20A records that
   * Google Places and Mapbox temporary geocoding results must not be persisted, so
   * this ships FALSE and turning it on is a statement about a specific geocoder's
   * terms.
   */
  public function geocoderMayPersist(): bool {
    return (bool) ($this->geocoder['persist'] ?? FALSE);
  }

  /**
   * The geocoder's own billed allowance per period, which is a separate product.
   */
  public function geocoderAllowance(): int {
    return max(0, (int) ($this->geocoder['request_allowance'] ?? 0));
  }

  /**
   * Whether allowances reset daily or monthly.
   */
  public function billingPeriod(): string {
    return $this->billingPeriod;
  }

  /**
   * The calendar an allowance period rolls over on.
   *
   * A fixed configured zone, never the visitor's: a period that moved with whoever
   * happened to be on the site would reset a spending limit whenever a request
   * arrived from far enough east.
   */
  public function billingTimezone(): string {
    return $this->billingTimezone;
  }

  /**
   * Whether the credential or endpoint a provider needs is present.
   *
   * Presence only. The value is never returned, logged or rendered, so an
   * administrator can see that a key is installed without the page becoming a
   * place a key can be read from.
   */
  public static function credentialPresent(string $provider): bool {
    $key = self::CREDENTIAL_SETTINGS[$provider] ?? NULL;

    return $key !== NULL && trim((string) Settings::get($key, '')) !== '';
  }

  /**
   * The validated OSRM base endpoint, or a classified failure.
   *
   * The URL arrives from settings.php, which only an operator with filesystem
   * access can write, and it is still checked against the operator's own allowlist
   * before it is used. That is not distrust of settings.php: it is so that a typo
   * or a copied line from another environment is refused here, where the failure is
   * a classified NOT_CONFIGURED, rather than sending customer addresses to whatever
   * host the typo named.
   *
   * A query string or a fragment is refused because this module composes its own,
   * and userinfo is refused because a credential does not belong in a URL that a
   * later log line could quote.
   *
   * @return string
   *   The base URL with any trailing slash removed.
   *
   * @throws \Drupal\custom_webform_handlers\Distance\DistanceProviderException
   *   Thrown when the endpoint is absent, unparseable, or outside the allowlist.
   */
  public function osrmEndpoint(): string {
    $endpoint = self::resolveOsrmEndpoint($this->providers[self::PROVIDER_OSRM] ?? []);
    if ($endpoint === NULL) {
      throw DistanceProviderException::classified(
        DistanceProviderException::NOT_CONFIGURED,
        self::PROVIDER_OSRM
      );
    }

    return $endpoint;
  }

  /**
   * Applies the allowlist to the Settings-backed endpoint, without raising.
   *
   * Two callers need this answer for different reasons: osrmEndpoint() needs a URL or
   * a classified failure, and readiness() needs to tell an administrator that the
   * endpoint is missing or outside the allowlist — while it is still a page that
   * never prints the endpoint itself.
   *
   * @param array $osrm
   *   The OSRM provider settings, for their allowed schemes and hosts.
   *
   * @return string|null
   *   The base URL with any trailing slash removed, or NULL when it is absent,
   *   unparseable, or not permitted.
   */
  private static function resolveOsrmEndpoint(array $osrm): ?string {
    $url = trim((string) Settings::get(self::SETTING_OSRM_BASE_URL, ''));
    if ($url === '') {
      return NULL;
    }
    $parts = parse_url($url);
    if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
      return NULL;
    }
    if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
      return NULL;
    }
    $scheme = strtolower($parts['scheme']);
    $host = strtolower($parts['host']);
    $schemes = array_map('strtolower', (array) ($osrm['allowed_schemes'] ?? []));
    $hosts = array_map('strtolower', (array) ($osrm['allowed_hosts'] ?? []));
    if (!in_array($scheme, $schemes, TRUE) || !in_array($host, $hosts, TRUE)) {
      return NULL;
    }
    if (in_array($host, self::FORBIDDEN_OSRM_HOSTS, TRUE)) {
      return NULL;
    }

    return rtrim($url, '/');
  }

  /**
   * A non-reversible fingerprint of the endpoint a provider will be asked at.
   *
   * Belongs in a cache key, and is safe in a log line. It is HMAC'd with the site
   * hash salt rather than plainly digested because a hostname is short enough to
   * recover from an unsalted hash by guessing, and the reason the endpoint lives in
   * settings.php at all is that this site does not publish it.
   *
   * An absent or refused endpoint fingerprints as an empty string rather than
   * throwing: a caller assembling a cache key has already decided to ask, and the
   * refusal is osrmEndpoint()'s to raise.
   */
  public function endpointFingerprint(string $provider): string {
    if ($provider !== self::PROVIDER_OSRM) {
      // The metered providers answer at their vendor's own published endpoint, so
      // there is nothing deployment-specific about them to fingerprint.
      return '';
    }
    try {
      $endpoint = $this->osrmEndpoint();
    }
    catch (DistanceProviderException) {
      return '';
    }

    return substr(Crypt::hmacBase64($endpoint, Settings::getHashSalt()), 0, 12);
  }

  /**
   * A fingerprint of everything about this policy that changes what a route means.
   *
   * Task 20F builds cache identity on this, so that a result calculated under one
   * profile, dataset or endpoint can never be served after the policy moved to
   * another. The purpose is part of it because the same two addresses legitimately
   * have two different answers — a free-flow one and a departure-stamped one — and
   * those must not share a cache slot.
   *
   * Only ids, profiles, operator-recorded version strings and salted endpoint
   * fingerprints contribute. No credential, hostname, allowance or timing is in it,
   * so the value is safe wherever a cache key is safe.
   */
  public function version(): string {
    $parts = [self::POLICY_VERSION, $this->purpose, $this->questionVersion];
    foreach ($this->order as $id) {
      $parts[] = implode(':', [
        $id,
        $this->profile($id),
        $this->dataVersion($id),
        $this->endpointFingerprint($id),
      ]);
    }
    $parts[] = 'geo:' . $this->geocoderProvider() . ':' . $this->geocoderVersion();

    return implode('|', $parts);
  }

  /**
   * Structural errors that must block a save.
   *
   * Keys are Form API nested element names ('purposes][final][order'), so the
   * settings form can attach each message to the element that caused it without
   * translating paths on the way.
   *
   * One thing here is deliberately not an error: a purpose whose eligible chain
   * comes out empty. The configuration this task ships has exactly that shape —
   * OSRM and Mapbox are disabled because neither exists yet — so refusing it at save
   * time would make the shipped defaults unsaveable and would force an operator to
   * enable a provider before they were allowed to record an order for it. It is a
   * runtime failure instead: fromConfig() raises NOT_CONFIGURED, and readiness()
   * explains it on the form before anyone relies on the purpose.
   *
   * @param array $routing
   *   A routing subtree, whole or partial.
   *
   * @return array<string, string>
   *   Element path to message. Empty when the subtree is structurally valid.
   */
  public static function validate(array $routing): array {
    $routing = self::merge($routing);
    $errors = [];
    self::validateGlobals($routing, $errors);
    self::validateProviders($routing, $errors);
    self::validatePurposes($routing, $errors);

    return $errors;
  }

  /**
   * Checks the allowance period, its calendar, and the geocoder selection.
   *
   * The geocoder id is checked for shape and not against an allowlist. Task 20D owns
   * coordinate resolution and will bind ids to adapters; an allowlist written here
   * would name adapters that do not exist, and enforcing it would be enforcing a
   * guess. What can be checked now is that the value is a machine name, so it cannot
   * carry a URL or a key.
   */
  private static function validateGlobals(array $routing, array &$errors): void {
    if (!in_array($routing['billing_period'], self::BILLING_PERIODS, TRUE)) {
      $errors['billing_period'] = 'Choose either a daily or a monthly allowance period.';
    }
    if (!in_array((string) $routing['billing_timezone'], timezone_identifiers_list(), TRUE)) {
      $errors['billing_timezone'] = 'Enter a recognised timezone identifier, for example UTC or America/Toronto.';
    }
    $geocoder = $routing['geocoder'];
    $provider = trim((string) $geocoder['provider']);
    if ($provider !== '' && preg_match('/^[a-z0-9_]+$/', $provider) !== 1) {
      $errors['geocoder][provider'] = 'Use a lowercase machine name for the geocoder, or leave it unselected.';
    }
    if ((int) $geocoder['request_allowance'] < 0) {
      $errors['geocoder][request_allowance'] = 'A request allowance cannot be negative. Zero means no requests.';
    }
    // A stored coordinate outlives the request that produced it, so it can only be
    // attributed to a dataset if the dataset was named. With no version recorded, a
    // cache filled before a geocoder changed its data cannot be told from one filled
    // after, and there is no way to expire only the wrong half.
    if (!empty($geocoder['persist']) && trim((string) $geocoder['version']) === '') {
      $errors['geocoder][version'] = 'Record the geocoder data version before allowing coordinates to be stored.';
    }
  }

  /**
   * Checks each provider's own timings, cache rights, circuit and allowance.
   *
   * Google's timeout and cache lifetime may be 0, which is not a zero but a
   * reference: it means "keep reading the existing route_request_timeout and
   * route_cache_ttl keys", so that a Google timing still has one owner. For OSRM and
   * Mapbox there is no key to defer to, so 0 there is a real zero and is refused.
   */
  private static function validateProviders(array $routing, array &$errors): void {
    foreach (self::PROVIDERS as $id) {
      $provider = $routing['providers'][$id];
      $inherits = $id === self::PROVIDER_GOOGLE;
      $timeout = (int) $provider['timeout_ms'];
      if (!($inherits && $timeout === 0) && ($timeout < self::MIN_TIMEOUT_MS || $timeout > self::MAX_TIMEOUT_MS)) {
        $errors['providers][' . $id . '][timeout_ms'] = 'Set a timeout between '
          . self::MIN_TIMEOUT_MS . ' and ' . self::MAX_TIMEOUT_MS . ' milliseconds.';
      }
      $ttl = (int) $provider['cache_ttl'];
      if ($ttl < 0 || $ttl > self::MAX_CACHE_TTL) {
        $errors['providers][' . $id . '][cache_ttl'] = 'Set a cache lifetime between 0 and '
          . self::MAX_CACHE_TTL . ' seconds.';
      }
      elseif (!empty($provider['cache_results']) && $ttl === 0 && !$inherits) {
        $errors['providers][' . $id . '][cache_ttl'] = 'A lifetime of zero stores nothing. Set a lifetime, or turn caching off.';
      }
      if ((int) $provider['circuit_failure_threshold'] < 1) {
        $errors['providers][' . $id . '][circuit_failure_threshold'] = 'A breaker needs at least one failure to open on.';
      }
      if ((int) $provider['circuit_cooldown_seconds'] < 1) {
        $errors['providers][' . $id . '][circuit_cooldown_seconds'] = 'An open breaker has to stay open for at least a second.';
      }
      if ((int) $provider['circuit_half_open_probes'] < 1) {
        $errors['providers][' . $id . '][circuit_half_open_probes'] = 'A half-open breaker needs at least one probe to close on.';
      }
      if (isset($provider['request_allowance']) && (int) $provider['request_allowance'] < 0) {
        $errors['providers][' . $id . '][request_allowance'] = 'A request allowance cannot be negative. Zero means no requests.';
      }
      foreach (['profile', 'traffic_profile'] as $key) {
        if (isset($provider[$key]) && preg_match('/^[a-z0-9-]+$/', (string) $provider[$key]) !== 1) {
          $errors['providers][' . $id . '][' . $key] = 'Use the provider\'s own profile name, in lowercase.';
        }
      }
    }
    self::validateMapbox($routing['providers'][self::PROVIDER_MAPBOX], $errors);
    self::validateOsrm($routing['providers'][self::PROVIDER_OSRM], $errors);
  }

  /**
   * Refuses persistent Mapbox caching that no one has claimed a right to.
   *
   * Task 20A read the published terms and could not establish that storing Mapbox
   * route results is permitted. Rather than encode that uncertainty as a comment
   * beside a default, the right is split in two: an operator who has checked their
   * own contract records that here, and only then does cache_results take effect.
   */
  private static function validateMapbox(array $provider, array &$errors): void {
    if (!empty($provider['cache_results']) && empty($provider['cache_terms_recorded'])) {
      $errors['providers][mapbox][cache_terms_recorded'] =
        'Confirm that your Mapbox terms permit storing route results before enabling the cache.';
    }
  }

  /**
   * Refuses an OSRM allowlist that would let addresses leave for the wrong host.
   *
   * The endpoint itself is not configuration — it comes from settings.php — but the
   * allowlist it is checked against is, which is why an operator can tighten where
   * this module is willing to send addresses without touching the filesystem, and
   * cannot loosen it to the public demonstration server at all.
   */
  private static function validateOsrm(array $provider, array &$errors): void {
    $schemes = array_map('strtolower', array_filter((array) $provider['allowed_schemes'], 'is_string'));
    if ($schemes === [] || array_diff($schemes, self::OSRM_SCHEMES) !== []) {
      $errors['providers][osrm][allowed_schemes'] = 'Permit http, https, or both — nothing else.';
    }
    $hosts = [];
    foreach ((array) $provider['allowed_hosts'] as $host) {
      $host = strtolower(trim((string) $host));
      if ($host === '') {
        continue;
      }
      if (in_array($host, self::FORBIDDEN_OSRM_HOSTS, TRUE)) {
        $errors['providers][osrm][allowed_hosts'] =
          'The public OSRM demonstration server cannot be permitted. Deploy a private instance.';
        continue;
      }
      // A bare host, so that a pasted URL cannot smuggle a path, a port-shaped
      // credential or a second host past the check in osrmEndpoint().
      if (preg_match('/^[a-z0-9.-]+$/', $host) !== 1) {
        $errors['providers][osrm][allowed_hosts'] = 'List host names only, without a scheme, port or path.';
        continue;
      }
      $hosts[] = $host;
    }
    if (!empty($provider['enabled']) && $hosts === []) {
      $errors['providers][osrm][allowed_hosts'] = 'Name the host of your OSRM deployment before enabling it.';
    }
  }

  /**
   * Checks each purpose's provider order, deadline and per-purpose allowances.
   *
   * Three rules here are refusals and not warnings, because each one describes a
   * configuration whose cost or correctness is wrong in a way the operator would not
   * see. Google outside the final purpose bills the most expensive provider for the
   * cheapest request on the site. OSRM inside a scheduled purpose names a provider
   * that cannot answer the question at all. And a duplicate id makes a chain the
   * operator reads as two attempts behave as one.
   */
  private static function validatePurposes(array $routing, array &$errors): void {
    foreach (self::PURPOSES as $purpose) {
      $path = 'purposes][' . $purpose;
      $settings = $routing['purposes'][$purpose];
      $scheduled = in_array($purpose, self::SCHEDULED_PURPOSES, TRUE);
      $order = array_values(array_filter((array) $settings['order'], 'is_string'));
      $seen = [];
      foreach ($order as $id) {
        if (!in_array($id, self::PROVIDERS, TRUE)) {
          $errors[$path . '][order'] = 'Unknown provider "' . $id . '". Use osrm, mapbox or google_routes.';
          continue;
        }
        if (isset($seen[$id])) {
          $errors[$path . '][order'] = 'Name each provider once. A repeated provider is still one attempt.';
          continue;
        }
        $seen[$id] = TRUE;
        if ($id === self::PROVIDER_GOOGLE && !in_array($purpose, self::GOOGLE_ELIGIBLE_PURPOSES, TRUE)) {
          $errors[$path . '][order'] = 'Google Routes is permitted only for validated final operations.';
        }
        if ($scheduled && !in_array($id, self::SCHEDULED_PROVIDERS, TRUE)) {
          $errors[$path . '][order'] = 'This purpose needs a departure-stamped route, which ' . $id . ' cannot provide.';
        }
      }
      self::validatePurposeTiming($purpose, $settings, $order, $routing['providers'], $errors);
      self::validatePurposeAllowances($purpose, $settings, $routing['providers'], $errors);
    }
  }

  /**
   * Checks that the deadline is sane and that the chain can fit inside it.
   *
   * A provider whose own timeout is longer than the whole purpose's deadline can
   * never fail over: the deadline expires first, so every provider named after it is
   * unreachable. That is a chain the operator wrote and the code would silently not
   * run, which is the exact shape of defect this task exists to remove.
   */
  private static function validatePurposeTiming(string $purpose, array $settings, array $order, array $providers, array &$errors): void {
    $path = 'purposes][' . $purpose;
    $deadline = (int) $settings['deadline_ms'];
    if ($deadline < self::MIN_TIMEOUT_MS || $deadline > self::MAX_DEADLINE_MS) {
      $errors[$path . '][deadline_ms'] = 'Set a deadline between ' . self::MIN_TIMEOUT_MS
        . ' and ' . self::MAX_DEADLINE_MS . ' milliseconds.';

      return;
    }
    foreach ($order as $id) {
      $timeout = (int) ($providers[$id]['timeout_ms'] ?? 0);
      // Google's 0 is a reference to the existing key, not a timeout of nothing, so
      // it cannot be compared against a deadline here. validateProviders() has
      // already accepted it, and resolveProvider() is where it becomes a number.
      if ($timeout > 0 && $timeout > $deadline) {
        $errors[$path . '][deadline_ms'] = 'The deadline is shorter than ' . $id
          . "'s own timeout, so nothing after it in the order could ever be asked.";

        return;
      }
    }
  }

  /**
   * Checks the allowances one purpose may spend against the provider-wide figures.
   *
   * A per-purpose allowance is a sub-limit, so it can only ever be narrower than the
   * provider-wide one — a purpose permitted more than the provider is permitted in
   * total reads as an increase and is not one, and an operator who set it would think
   * they had raised a ceiling they had not.
   *
   * Naming an unmetered provider here is refused rather than ignored. OSRM has no
   * allowance because a self-hosted extract bills nothing per request; an operator
   * writing a number against it has misunderstood which provider costs money, and
   * accepting the key silently would leave that misunderstanding in place.
   */
  private static function validatePurposeAllowances(string $purpose, array $settings, array $providers, array &$errors): void {
    $path = 'purposes][' . $purpose . '][request_allowance';
    foreach ((array) $settings['request_allowance'] as $id => $value) {
      if (!in_array($id, self::PROVIDERS, TRUE)) {
        $errors[$path] = 'Unknown provider "' . $id . '" in this purpose\'s allowances.';
        continue;
      }
      if (!array_key_exists('request_allowance', $providers[$id])) {
        $errors[$path] = $id . ' is not metered per request, so it has no allowance to divide.';
        continue;
      }
      $value = (int) $value;
      if ($value < 0) {
        $errors[$path] = 'A request allowance cannot be negative. Zero means no requests.';
        continue;
      }
      $aggregate = (int) $providers[$id]['request_allowance'];
      if ($value > $aggregate) {
        $errors[$path] = 'This purpose is allowed more ' . $id . ' requests than ' . $id
          . ' is allowed in total. Raise the provider-wide allowance first.';
      }
    }
  }

  /**
   * What each purpose could actually do today, and what stops it.
   *
   * This is the honest half of the settings form. A configuration can be perfectly
   * valid and still serve nothing — every provider in the order disabled, a token
   * that was never installed, an allowance of zero, an OSRM host that is allowlisted
   * but has no endpoint behind it — and none of those are save-time errors. So they
   * are reported, in the operator's terms, on the page where they can be fixed.
   *
   * Nothing here reads a credential's value. Presence is the only fact taken from
   * Settings, and the OSRM endpoint is reported as permitted or not, never printed:
   * the page says whether this site can reach a provider, not how.
   *
   * @param array $routing
   *   A routing subtree, whole or partial.
   *
   * @return array<string, array{ready: bool, chain: string[], blockers: string[], notes: string[]}>
   *   Keyed by purpose.
   */
  public static function readiness(array $routing): array {
    $routing = self::merge($routing);
    $report = [];
    foreach (self::PURPOSES as $purpose) {
      $scheduled = in_array($purpose, self::SCHEDULED_PURPOSES, TRUE);
      $chain = [];
      $blockers = [];
      $notes = [];
      foreach ((array) $routing['purposes'][$purpose]['order'] as $id) {
        if (!in_array($id, self::PROVIDERS, TRUE)) {
          continue;
        }
        if (empty($routing['providers'][$id]['enabled'])) {
          $blockers[] = $id . ' is named in the order but is switched off.';
          continue;
        }
        if ($scheduled && !in_array($id, self::SCHEDULED_PROVIDERS, TRUE)) {
          $blockers[] = $id . ' cannot answer for a departure time, so it is skipped here.';
          continue;
        }
        if ($id === self::PROVIDER_GOOGLE && !in_array($purpose, self::GOOGLE_ELIGIBLE_PURPOSES, TRUE)) {
          $blockers[] = 'Google Routes is not permitted for this purpose and is skipped.';
          continue;
        }
        $chain[] = $id;
      }
      $report[$purpose] = self::purposeReadiness($purpose, $chain, $routing, $blockers, $notes);
    }

    return $report;
  }

  /**
   * Turns one purpose's eligible chain into a reachable-or-not verdict.
   *
   * A provider counts as reachable only if it could be asked *and* is permitted to
   * spend something. That second half is why an allowance of zero appears as a
   * blocker and not as a note: zero means no calls, so a chain of one provider with
   * a zero allowance is a chain that cannot run, however healthy the provider is.
   */
  private static function purposeReadiness(string $purpose, array $chain, array $routing, array $blockers, array $notes): array {
    $reachable = 0;
    foreach ($chain as $id) {
      $provider = $routing['providers'][$id];
      if (!self::credentialPresent($id)) {
        $blockers[] = $id === self::PROVIDER_OSRM
          ? 'No OSRM endpoint is installed in settings.php.'
          : ucfirst($id) . "'s server credential is not installed in settings.php.";
        continue;
      }
      if ($id === self::PROVIDER_OSRM && self::resolveOsrmEndpoint($provider) === NULL) {
        $blockers[] = 'The installed OSRM endpoint is outside the permitted schemes and hosts.';
        continue;
      }
      $allowance = self::effectiveAllowance($id, $purpose, $routing);
      if ($allowance === 0) {
        $blockers[] = ucfirst($id) . ' has an allowance of zero for this purpose, so it will not be called.';
        continue;
      }
      $notes[] = $allowance === NULL
        ? ucfirst($id) . ' is self-hosted: limited by its timeout and breaker, not by an allowance.'
        : ucfirst($id) . ' may make up to ' . $allowance . ' requests per ' . $routing['billing_period'] . '.';
      $mayCache = !empty($provider['cache_results'])
        && ($id !== self::PROVIDER_MAPBOX || !empty($provider['cache_terms_recorded']));
      $notes[] = $mayCache
        ? ucfirst($id) . ' results may be stored for ' . (int) $provider['cache_ttl'] . ' seconds.'
        : ucfirst($id) . ' results are not stored, so every request is a fresh one.';
      $reachable++;
    }
    if ($chain === []) {
      $blockers[] = 'No provider is eligible for this purpose, so it cannot be served at all.';
    }

    return [
      'ready' => $reachable > 0,
      'chain' => $chain,
      'blockers' => $blockers,
      'notes' => $notes,
    ];
  }

  /**
   * The narrower of a provider's own allowance and this purpose's share of it.
   *
   * The static twin of requestAllowance(), and it exists because readiness() reports
   * on a subtree that may not be saved yet, so there is no resolved policy to ask.
   * NULL still means unmetered and 0 still means no calls.
   */
  private static function effectiveAllowance(string $provider, string $purpose, array $routing): ?int {
    if (!array_key_exists('request_allowance', $routing['providers'][$provider])) {
      return NULL;
    }
    $aggregate = max(0, (int) $routing['providers'][$provider]['request_allowance']);
    $share = $routing['purposes'][$purpose]['request_allowance'][$provider] ?? NULL;

    return $share === NULL ? $aggregate : min($aggregate, max(0, (int) $share));
  }

}
