<?php

namespace Drupal\custom_webform_handlers\Dispatch\Route;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\custom_webform_handlers\Dispatch\RouteEstimate;
use Drupal\custom_webform_handlers\Distance\DistanceProviderException;
use Drupal\custom_webform_handlers\Distance\DistanceProviderInterface;
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

  public function __construct(
    private readonly DistanceProviderInterface $distanceProvider,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
    private readonly string $purpose,
    private readonly TimeInterface $time,
    private readonly ?DistanceProviderInterface $ordinaryDistanceProvider = NULL,
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
