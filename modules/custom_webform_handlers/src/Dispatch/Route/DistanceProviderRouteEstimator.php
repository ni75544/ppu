<?php

namespace Drupal\custom_webform_handlers\Dispatch\Route;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\custom_webform_handlers\Dispatch\RouteEstimate;
use Drupal\custom_webform_handlers\Distance\DistanceProviderException;
use Drupal\custom_webform_handlers\Distance\DistanceProviderInterface;
use Drupal\custom_webform_handlers\Distance\FixedDevelopmentRouteProvider;
use Drupal\custom_webform_handlers\Distance\RoutingModeResolver;
use Drupal\custom_webform_handlers\Distance\RoutingPolicy;
use Drupal\custom_webform_handlers\Distance\ScheduledRouteProviderInterface;
use Psr\Log\LoggerInterface;

/**
 * Measures planner legs through the module's existing route provider.
 *
 * The provider is asked the scheduled, traffic-aware question when it can
 * answer one, and the time-independent question when it cannot. Named service
 * instances wrap the offer and final chains. Only this class asks them about a
 * departure time, keeping traffic-priced duration out of approved pricing.
 *
 * What the seam will not do is pass a traffic-free duration off as a scheduled
 * one. When traffic could not be applied — no scheduled capability behind the
 * port, or a provider substituting traffic-unaware routing — the figure is
 * inflated by a configured margin and reported with trafficAware FALSE. The
 * planner then decides whether a margin is good enough or whether the request
 * belongs with staff; either way nothing downstream receives an optimistic
 * number that looks authoritative.
 *
 * Failure is always NULL, never zero: a leg that could not be measured is a leg
 * that cannot be scheduled.
 */
final class DistanceProviderRouteEstimator implements DispatchRouteEstimatorInterface {

  /**
   * Route settings, shared with the provider this wraps.
   */
  private const SETTINGS = 'custom_webform_handlers.settings';

  /**
   * Dispatch policy controlling whether scheduled traffic is mandatory.
   */
  private const DISPATCH_SETTINGS = 'custom_webform_handlers.dispatch';

  /**
   * The smallest margin applied to a duration that carries no traffic.
   *
   * A floor rather than a default: configuration may raise the margin but not
   * remove it, because forwarding a traffic-free figure unchanged creates the
   * silent under-estimate this fallback exists to prevent.
   */
  private const MIN_FALLBACK_PERCENT = 10;

  /**
   * The margin used when configuration names none.
   */
  private const DEFAULT_FALLBACK_PERCENT = 25;

  /**
   * The fixed development fact's reuse window, in seconds.
   *
   * A synthesized expiry: FixedDevelopmentRouteProvider carries none, but
   * accepts() requires a non-NULL, future expiry before any prepared fact may be
   * reused under a lock. One hour keeps a development fact honestly perishable —
   * a stale one is refused and rebuilt, which is free — rather than eternal.
   */
  private const DEVELOPMENT_FIXED_TTL = 3600;

  public function __construct(
    private readonly DistanceProviderInterface $distanceProvider,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
    private readonly string $purpose,
    private readonly TimeInterface $time,
    private readonly ?DistanceProviderInterface $ordinaryDistanceProvider = NULL,
    private readonly ?RoutingModeResolver $routingModeResolver = NULL,
    private readonly ?DistanceProviderInterface $developmentProvider = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function estimate(string $origin, string $destination, int $departureAt): ?RouteEstimate {
    $origin = trim($origin);
    $destination = trim($destination);

    if ($origin === '' || $destination === '') {
      return NULL;
    }

    // In development_fixed mode the scheduling chain must not be reached: its
    // adapters fail closed at the socket, so calling it would only ever return
    // NULL here and dispatch offers/slots would silently have no estimate. The
    // deterministic development provider answers instead — no HTTP, an explicit
    // development_fixed provider id, and a synthesized expiry so the fact is
    // still perishable. Missing the provider fails closed (NULL), never a chain.
    if ($this->routingModeResolver?->isDevelopmentFixed()) {
      return $this->fixedDevelopmentEstimate($origin, $destination, $departureAt);
    }

    try {
      // The planner owns the final decision about whether a traffic-free route
      // may be scheduled. This seam selects the matching provider contract: a
      // strict profile uses the purpose-specific scheduled chain, while a
      // profile that explicitly permits conservative fallback uses the ordinary
      // OSRM-first chain and applies the configured safety margin below.
      $useOrdinary = !$this->trafficAwareRequired()
        && $this->ordinaryDistanceProvider !== NULL;
      $provider = $useOrdinary
        ? $this->ordinaryDistanceProvider
        : $this->distanceProvider;
      // ChainDistanceProvider implements both contracts because its trusted
      // service purpose decides which one is legal. An ordinary chain must be
      // called through calculate() even though the object also exposes the
      // scheduled method for the offer/final instances.
      $result = $useOrdinary
        ? $provider->calculate($origin, $destination)
        : ($provider instanceof ScheduledRouteProviderInterface
          ? $provider->calculateScheduled($origin, $destination, $departureAt)
          : $provider->calculate($origin, $destination));
    }
    catch (DistanceProviderException $exception) {
      $this->logger->notice('Dispatch route estimate unavailable: @code', [
        '@code' => $exception->getMessage(),
      ]);
      return NULL;
    }
    catch (\Throwable $exception) {
      $this->logger->error('Dispatch route estimate failed with @type.', [
        '@type' => get_class($exception),
      ]);
      return NULL;
    }

    $seconds = $result->durationSeconds();
    if ($seconds <= 0) {
      // Zero travel time is not an answer the planner can use. Treating it as
      // one would place a job with no drive between its ends.
      $this->logger->notice(
        'Dispatch route estimate returned no travel time; leg is unschedulable.',
      );
      return NULL;
    }

    if (!$result->trafficAware()) {
      $seconds = $this->inflate($seconds);
    }

    $effectiveDeparture = $result->effectiveDepartureAt();
    $fingerprint = $this->fingerprint(
      $origin,
      $destination,
      $departureAt,
      $effectiveDeparture,
      $result->provider(),
      $result->endpointFingerprint(),
    );

    return new RouteEstimate(
      $seconds,
      $result->distanceMeters(),
      $departureAt,
      $result->trafficAware(),
      $this->purpose,
      $result->provider(),
      $fingerprint,
      $result->calculatedAt(),
      $result->fetchedAt(),
      $result->expiresAt(),
      $effectiveDeparture,
      $result->endpointFingerprint(),
    );
  }

  /**
   * Builds a deterministic scheduling estimate without any network request.
   *
   * The development_fixed counterpart to the chain branch of estimate(). It asks
   * the fixed development provider — never the offer/final/ordinary chain, whose
   * adapters fail closed at the socket in this mode — and shapes the answer into
   * a RouteEstimate the planner and its locked revalidation accept:
   *
   * - The provider id is the fixture's own (development_fixed), which accepts()
   *   admits only while the resolver confirms the mode. It is never laundered
   *   into a live provider id, so a fixture can never masquerade as real traffic.
   * - Fixtures are time-independent: there is no effective departure, so that
   *   field is NULL on both the produced fact and the fingerprint, and the
   *   traffic-free duration is inflated by the same conservative margin the
   *   chain branch applies rather than passed off as scheduled.
   * - A fact carries a synthesized, finite expiry so it stays perishable under a
   *   lock; a stale development fact is refused and rebuilt, which costs nothing.
   *
   * A missing development provider returns NULL — a leg that cannot be measured
   * fails closed, exactly as an unmeasurable live leg does; it never falls
   * through to a networked chain.
   */
  private function fixedDevelopmentEstimate(
    string $origin,
    string $destination,
    int $departureAt,
  ): ?RouteEstimate {
    if ($this->developmentProvider === NULL) {
      $this->logger->notice(
        'Dispatch route estimate requested in development_fixed mode with no development provider wired; leg is unschedulable.',
      );
      return NULL;
    }

    try {
      $result = $this->developmentProvider->calculate($origin, $destination);
    }
    catch (DistanceProviderException $exception) {
      $this->logger->notice('Dispatch development route estimate unavailable: @code', [
        '@code' => $exception->getMessage(),
      ]);
      return NULL;
    }
    catch (\Throwable $exception) {
      $this->logger->error('Dispatch development route estimate failed with @type.', [
        '@type' => get_class($exception),
      ]);
      return NULL;
    }

    $seconds = $result->durationSeconds();
    if ($seconds <= 0) {
      return NULL;
    }
    if (!$result->trafficAware()) {
      $seconds = $this->inflate($seconds);
    }

    $now = $this->time->getCurrentTime();
    $fingerprint = $this->fingerprint(
      $origin,
      $destination,
      $departureAt,
      NULL,
      $result->provider(),
      $result->endpointFingerprint(),
    );

    return new RouteEstimate(
      $seconds,
      $result->distanceMeters(),
      $departureAt,
      $result->trafficAware(),
      $this->purpose,
      $result->provider(),
      $fingerprint,
      $result->calculatedAt(),
      $result->fetchedAt(),
      $now + self::DEVELOPMENT_FIXED_TTL,
      NULL,
      $result->endpointFingerprint(),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function accepts(
    RouteEstimate $estimate,
    string $origin,
    string $destination,
    int $departureAt,
  ): bool {
    if ($estimate->purpose !== $this->purpose
      || $estimate->departureAt !== $departureAt
      || $estimate->seconds <= 0
      || $estimate->meters <= 0
      || $estimate->calculatedAt <= 0
      || $estimate->fetchedAt <= 0) {
      return FALSE;
    }

    $now = $this->time->getCurrentTime();
    if ($estimate->fetchedAt > $now
      || $estimate->expiresAt === NULL
      || $estimate->expiresAt <= $now) {
      return FALSE;
    }

    // A development_fixed fact is accepted only when the environment is actually
    // in that mode, and only for the fixed provider's own id. The live provider
    // allowlist (providerOrder()) never contains development_fixed, so without
    // this branch a fixture fact would be rejected in dev and re-estimated on
    // every locked revalidation. The perishability, purpose, departure, seconds
    // and fingerprint checks above and below still apply — this narrows the
    // provider gate, it does not relax freshness. Live eligibility is untouched:
    // the bypass is unreachable unless isDevelopmentFixed() is TRUE. Fixtures are
    // time-independent, so the effective departure they routed for is always NULL.
    if ($this->routingModeResolver?->isDevelopmentFixed()
      && $estimate->provider === FixedDevelopmentRouteProvider::PROVIDER_ID) {
      return $estimate->effectiveDepartureAt === NULL
        && hash_equals(
          $estimate->routeFingerprint,
          $this->fingerprint(
            trim($origin),
            trim($destination),
            $departureAt,
            NULL,
            $estimate->provider,
            $estimate->endpointFingerprint,
          ),
        );
    }

    try {
      $settings = $this->configFactory->get(self::SETTINGS);
      $policy = RoutingPolicy::fromConfig(
        $settings,
        $estimate->trafficAware ? $this->purpose : RoutingPolicy::PURPOSE_ORDINARY,
      );
      $effectiveDeparture = $estimate->trafficAware
        ? RoutingPolicy::effectiveDeparture($settings, $departureAt, $now)
        : NULL;
    }
    catch (DistanceProviderException) {
      return FALSE;
    }

    if (!in_array($estimate->provider, $policy->providerOrder(), TRUE)
      || $estimate->effectiveDepartureAt !== $effectiveDeparture) {
      return FALSE;
    }

    return hash_equals(
      $estimate->routeFingerprint,
      $this->fingerprint(
        trim($origin),
        trim($destination),
        $departureAt,
        $effectiveDeparture,
        $estimate->provider,
        $estimate->endpointFingerprint,
      ),
    );
  }

  /**
   * Returns whether the active dispatch profile requires live traffic.
   *
   * The planner independently enforces this under locked revalidation. Reading
   * it here only avoids a paid scheduled request when the operator has already
   * chosen the conservative ordinary-route policy.
   */
  private function trafficAwareRequired(): bool {
    return (bool) $this->configFactory
      ->get(self::DISPATCH_SETTINGS)
      ->get('routing.require_traffic_aware');
  }

  /**
   * Builds the identity checked again during locked revalidation.
   */
  private function fingerprint(
    string $origin,
    string $destination,
    int $departureAt,
    ?int $effectiveDepartureAt,
    string $provider,
    string $endpointFingerprint,
  ): string {
    $normalize = static function (string $value): string {
      $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);

      return mb_strtolower($value, 'UTF-8');
    };

    return hash('sha256', implode("\0", [
      $normalize($origin),
      $normalize($destination),
      $departureAt,
      $effectiveDepartureAt ?? 0,
      $provider,
      $endpointFingerprint,
      $this->purpose,
    ]));
  }

  /**
   * Applies the conservative margin to a duration that carries no traffic.
   *
   * The margin is deliberately blunt. It is not a traffic model and does not
   * pretend to be one: it assumes a free-flow drive is under-stated, so a
   * schedule built without traffic data errs towards leaving too much room.
   * A planner unwilling to accept a margin
   * instead of real traffic data can refuse the leg outright
   * instead, which is what routing.require_traffic_aware is for.
   *
   * @param int $seconds
   *   The traffic-free duration.
   *
   * @return int
   *   The inflated duration, always strictly greater than the input.
   */
  private function inflate(int $seconds): int {
    $configured = (int) ($this->configFactory->get(self::SETTINGS)
      ->get('route_traffic_fallback_inflation_percent') ?: self::DEFAULT_FALLBACK_PERCENT);
    $percent = max(self::MIN_FALLBACK_PERCENT, min(200, $configured));

    $this->logger->notice('Dispatch route estimate is not traffic-aware; inflating by @percent% for scheduling.', [
      '@percent' => $percent,
    ]);

    // Rounded up so the margin cannot vanish on a short leg.
    return (int) ceil($seconds * (100 + $percent) / 100);
  }

}
