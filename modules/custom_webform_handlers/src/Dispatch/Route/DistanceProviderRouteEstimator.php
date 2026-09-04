<?php

namespace Drupal\custom_webform_handlers\Dispatch\Route;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\custom_webform_handlers\Dispatch\RouteEstimate;
use Drupal\custom_webform_handlers\Distance\DistanceProviderException;
use Drupal\custom_webform_handlers\Distance\DistanceProviderInterface;
use Drupal\custom_webform_handlers\Distance\ScheduledRouteProviderInterface;
use Psr\Log\LoggerInterface;

/**
 * Measures planner legs through the module's existing route provider.
 *
 * The provider is asked the scheduled, traffic-aware question when it can answer
 * one, and the time-independent question when it cannot. Both paths go to the same
 * Google adapter — quoting and scheduling still share exactly one route client —
 * but only this class ever asks about a departure time, which is what keeps a
 * traffic-priced duration out of the approved pricing rules.
 *
 * What the seam will not do is pass a traffic-free duration off as a scheduled one.
 * When traffic could not be applied — no scheduled capability behind the port, or
 * Google itself substituting traffic-unaware routing — the figure is inflated by a
 * configured margin and reported with trafficAware FALSE. The planner then decides
 * whether a margin is good enough or whether the request belongs with staff; either
 * way nothing downstream receives an optimistic number that looks authoritative.
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
   * The smallest margin applied to a duration that carries no traffic.
   *
   * A floor rather than a default: configuration may raise the margin but not
   * remove it, because a traffic-free figure forwarded unchanged is precisely the
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
      $result = $this->distanceProvider instanceof ScheduledRouteProviderInterface
        ? $this->distanceProvider->calculateScheduled($origin, $destination, $departureAt)
        : $this->distanceProvider->calculate($origin, $destination);
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
      // Zero travel time is not an answer the planner can use, and treating it as
      // one would place a job with no drive between its ends.
      $this->logger->notice('Dispatch route estimate returned no travel time, so the leg is unschedulable.');
      return NULL;
    }

    if (!$result->trafficAware()) {
      $seconds = $this->inflate($seconds);
    }

    // The departure recorded is the one the planner asked about. A provider may
    // have quantized it to a coarser bucket to avoid a billed request per grid
    // candidate; that is its own documented business and does not change which
    // instant this estimate was obtained for.
    return new RouteEstimate($seconds, $result->distanceMeters(), $departureAt, $result->trafficAware());
  }

  /**
   * Applies the conservative margin to a duration that carries no traffic.
   *
   * The margin is deliberately blunt. It is not a traffic model and does not
   * pretend to be one: it is the amount by which a free-flow drive is assumed to be
   * an under-statement, so that a schedule built without traffic data errs towards
   * leaving too much room rather than too little. A planner that is not willing to
   * accept a margin in place of real traffic data can refuse the leg outright
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
