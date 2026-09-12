<?php

namespace Drupal\custom_webform_handlers\Distance;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Returns the first valid, purpose-eligible route within cost and time bounds.
 */
final class ChainDistanceProvider implements DistanceProviderInterface, ScheduledRouteProviderInterface {

  /**
   * The current HTTP request's private, non-serialized result memo.
   */
  private const REQUEST_MEMO_ATTRIBUTE = '_cwh_route_result_memo';

  /**
   * Bounds memory use even when one request evaluates many route pairs.
   */
  private const REQUEST_MEMO_LIMIT = 32;

  /**
   * Constructs a chain whose purpose and providers are fixed by the container.
   *
   * @param string $purpose
   *   A trusted purpose fixed by service-container wiring.
   * @param array<string, object> $providers
   *   Explicit allowlisted provider map; never derived from request data.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The configuration factory.
   * @param \Drupal\custom_webform_handlers\Distance\AddressCoordinateResolver $coordinateResolver
   *   The shared committed-address resolver.
   * @param \Drupal\custom_webform_handlers\Distance\RouteCache $routeCache
   *   The authoritative route cache.
   * @param \Drupal\custom_webform_handlers\Distance\ProviderCircuit $providerCircuit
   *   Provider health coordination.
   * @param \Drupal\custom_webform_handlers\Distance\RoutingBudget $routingBudget
   *   The durable request allowance gate.
   * @param \Drupal\custom_webform_handlers\Distance\RoutingUsage $routingUsage
   *   Local operational counters and operator summary.
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The current HTTP request, used only for non-persistent duplicate reuse.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The clock.
   * @param \Psr\Log\LoggerInterface $logger
   *   The module logger.
   */
  public function __construct(
    private readonly string $purpose,
    private readonly array $providers,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly AddressCoordinateResolver $coordinateResolver,
    private readonly RouteCache $routeCache,
    private readonly ProviderCircuit $providerCircuit,
    private readonly RoutingBudget $routingBudget,
    private readonly RoutingUsage $routingUsage,
    private readonly RequestStack $requestStack,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function calculate(string $origin, string $destination): DistanceResult {
    $policy = $this->policy();
    if ($policy->isScheduled()) {
      throw DistanceProviderException::classified(DistanceProviderException::NOT_CONFIGURED);
    }

    return $this->route($origin, $destination, NULL, $policy);
  }

  /**
   * {@inheritdoc}
   */
  public function calculateScheduled(string $origin, string $destination, int $departureAt): DistanceResult {
    $policy = $this->policy();
    if (!$policy->isScheduled()) {
      throw DistanceProviderException::classified(DistanceProviderException::NOT_CONFIGURED);
    }

    return $this->route($origin, $destination, $departureAt, $policy);
  }

  /**
   * Executes a bounded, non-recursive provider chain.
   */
  private function route(
    string $origin,
    string $destination,
    ?int $departureAt,
    RoutingPolicy $policy,
  ): DistanceResult {
    $started = microtime(TRUE);
    $effectiveDeparture = $departureAt === NULL ? NULL : RoutingPolicy::effectiveDeparture(
      $this->configFactory->get('custom_webform_handlers.settings'),
      $departureAt,
      $this->time->getCurrentTime(),
    );
    $memoKey = $this->requestMemoKey(
      $policy,
      $origin,
      $destination,
      $effectiveDeparture,
    );
    $memoized = $this->requestMemoResult($memoKey);
    if ($memoized !== NULL) {
      return $memoized;
    }

    // Route identity and cross-provider reuse require the same authoritative
    // coordinate pair. Resolve once before provider iteration, never once per
    // fallback. A warm coordinate layer keeps route-cache hits free of geocoder
    // traffic.
    $locations = $this->resolveLocations($origin, $destination);
    $lastFailure = NULL;
    $order = $policy->providerOrder();
    foreach ($order as $position => $provider) {
      if ($provider === RoutingPolicy::PROVIDER_GOOGLE
        && $policy->purpose() !== RoutingPolicy::PURPOSE_FINAL) {
        throw DistanceProviderException::classified(DistanceProviderException::NOT_CONFIGURED, $provider);
      }
      $adapter = $this->providers[$provider] ?? NULL;
      if (!$adapter instanceof DistanceProviderInterface
        || ($policy->isScheduled() && !$adapter instanceof ScheduledRouteProviderInterface)) {
        $lastFailure = DistanceProviderException::classified(DistanceProviderException::NOT_CONFIGURED, $provider);
        $this->metric('failure', $provider, $policy);
        $this->fallbackMetric(
          $position,
          $order,
          $provider,
          $policy,
          DistanceProviderException::NOT_CONFIGURED,
        );
        continue;
      }

      $cacheable = $policy->mayCacheResults($provider);
      try {
        // The same privacy-safe identity coordinates duplicate suppression even
        // when provider terms forbid persisting the result itself.
        $cid = $this->routeCache->key(
          $policy,
          $provider,
          $locations[0],
          $locations[1],
          $effectiveDeparture,
        );
        if ($cacheable) {
          if ($cached = $this->validCachedResult(
            $this->routeCache->get($cid, $provider),
            $provider,
            $policy,
          )) {
            $this->metric('cache_hit', $provider, $policy);
            return $this->rememberForRequest($memoKey, $cached);
          }
        }
      }
      catch (\Throwable) {
        // Cache identity failures cannot discard a usable live path.
        $cid = NULL;
      }

      if (!$this->providerCircuit->allows($provider, $policy)) {
        $lastFailure = DistanceProviderException::classified(DistanceProviderException::THROTTLED, $provider, 1);
        $this->metric('circuit_open', $provider, $policy);
        $this->fallbackMetric($position, $order, $provider, $policy, 'circuit_open');
        continue;
      }
      if ($this->remainingMilliseconds($started, $policy) < $policy->timeoutMs($provider)) {
        $lastFailure = DistanceProviderException::classified(DistanceProviderException::TIMEOUT, $provider);
        $this->metric('failure', $provider, $policy);
        break;
      }

      $claimed = FALSE;
      $claimedCid = NULL;
      if ($cid !== NULL) {
        $claim = $this->routeCache->claim($cid, ($policy->timeoutMs($provider) / 1000) + 1);
        if ($claim === FALSE) {
          if ($cacheable && ($cached = $this->validCachedResult(
            $this->routeCache->get($cid, $provider),
            $provider,
            $policy,
          ))) {
            $this->metric('cache_hit', $provider, $policy);
            return $this->rememberForRequest($memoKey, $cached);
          }
          // Do not turn one duplicate miss into a different paid fallback.
          $this->metric('throttle', $provider, $policy);
          throw DistanceProviderException::classified(DistanceProviderException::THROTTLED, $provider, 1);
        }
        $claimed = $claim === TRUE;
        $claimedCid = $claimed ? $cid : NULL;
        if ($cacheable && $claimed && ($cached = $this->validCachedResult(
          $this->routeCache->get($cid, $provider),
          $provider,
          $policy,
        ))) {
          $this->routeCache->release($cid);
          $this->metric('cache_hit', $provider, $policy);
          return $this->rememberForRequest($memoKey, $cached);
        }
      }

      try {
        if (!$this->routingBudget->reserveRoute($policy, $provider)) {
          throw DistanceProviderException::classified(DistanceProviderException::BUDGET_EXHAUSTED, $provider);
        }
        $this->metric('attempted', $provider, $policy);
        $result = $policy->isScheduled()
          ? $adapter->calculateScheduled($origin, $destination, $effectiveDeparture)
          : $adapter->calculate($origin, $destination);
        $this->assertResult($result, $provider, $policy);
        $this->providerCircuit->recordSuccess($provider, $policy);

        if ($cacheable && $cid !== NULL) {
          // If an adapter had to advance the bucket while work was in flight,
          // store what it actually asked, not the earlier estimate.
          if ($result->effectiveDepartureAt() !== $effectiveDeparture) {
            $cid = $this->routeCache->key(
              $policy,
              $provider,
              $locations[0],
              $locations[1],
              $result->effectiveDepartureAt(),
            );
          }
          $this->routeCache->set($cid, $result, $policy->cacheTtl($provider));
        }
        return $this->rememberForRequest($memoKey, $result);
      }
      catch (DistanceProviderException $exception) {
        $lastFailure = $exception;
        $this->providerCircuit->recordFailure($provider, $policy, $exception);
        if ($exception->classification() === DistanceProviderException::BUDGET_EXHAUSTED) {
          $this->metric('allowance_refusal', $provider, $policy);
        }
        elseif ($exception->classification() === DistanceProviderException::THROTTLED) {
          $this->metric('throttle', $provider, $policy);
        }
        else {
          $this->metric('failure', $provider, $policy);
        }
        if ($exception->isTerminal()) {
          throw $exception;
        }
        $this->fallbackMetric(
          $position,
          $order,
          $provider,
          $policy,
          $exception->classification(),
        );
      }
      catch (\Throwable) {
        $lastFailure = DistanceProviderException::classified(DistanceProviderException::UNCLASSIFIED, $provider);
        $this->providerCircuit->recordFailure($provider, $policy, $lastFailure);
        $this->metric('failure', $provider, $policy);
        $this->fallbackMetric(
          $position,
          $order,
          $provider,
          $policy,
          DistanceProviderException::UNCLASSIFIED,
        );
      }
      finally {
        if ($claimedCid !== NULL) {
          $this->routeCache->release($claimedCid);
        }
      }
    }

    throw $lastFailure
      ?? DistanceProviderException::classified(DistanceProviderException::NOT_CONFIGURED);
  }

  /**
   * Resolves and validates the one coordinate pair used by every fallback.
   *
   * @return array{0: ResolvedLocation, 1: ResolvedLocation}
   *   The ordered origin and destination.
   */
  private function resolveLocations(string $origin, string $destination): array {
    $first = $this->coordinateResolver->resolve($origin);
    $second = $this->coordinateResolver->resolve($destination);
    $now = $this->time->getCurrentTime();
    foreach ([$first, $second] as $location) {
      if ($location->precision() !== ResolvedLocation::PRECISION_ADDRESS
        || !$location->mayPassToOtherProviders()
        || $location->expiresAt() <= $now) {
        throw DistanceProviderException::classified(DistanceProviderException::ADDRESS_UNUSABLE);
      }
    }
    if ($first->latitude() === $second->latitude()
      && $first->longitude() === $second->longitude()) {
      throw DistanceProviderException::classified(DistanceProviderException::ADDRESS_UNUSABLE);
    }

    return [$first, $second];
  }

  /**
   * Refuses adapter output that does not answer the requested purpose.
   */
  private function assertResult(
    DistanceResult $result,
    string $provider,
    RoutingPolicy $policy,
  ): void {
    if ($result->provider() !== $provider) {
      throw DistanceProviderException::classified(DistanceProviderException::MALFORMED_RESPONSE, $provider);
    }
    if ($policy->isScheduled()
      && (!$result->trafficAware() || $result->effectiveDepartureAt() === NULL)) {
      throw DistanceProviderException::classified(DistanceProviderException::OUTSIDE_COVERAGE, $provider);
    }
  }

  /**
   * Treats an incompatible cache entry as a miss, never as route authority.
   */
  private function validCachedResult(
    ?DistanceResult $result,
    string $provider,
    RoutingPolicy $policy,
  ): ?DistanceResult {
    if ($result === NULL) {
      return NULL;
    }
    try {
      $this->assertResult($result, $provider, $policy);
      return $result;
    }
    catch (DistanceProviderException) {
      return NULL;
    }
  }

  /**
   * Resolves policy from immutable configuration and trusted service wiring.
   */
  private function policy(): RoutingPolicy {
    return RoutingPolicy::fromConfig(
      $this->configFactory->get('custom_webform_handlers.settings'),
      $this->purpose,
    );
  }

  /**
   * Builds a private identity without retaining either submitted address.
   */
  private function requestMemoKey(
    RoutingPolicy $policy,
    string $origin,
    string $destination,
    ?int $effectiveDeparture,
  ): string {
    return hash('sha256', implode("\0", [
      $policy->purpose(),
      DistanceResult::pairFingerprint($origin, $destination),
      $effectiveDeparture ?? 0,
    ]));
  }

  /**
   * Returns an identical result already produced in this HTTP request.
   */
  private function requestMemoResult(string $key): ?DistanceResult {
    $request = $this->requestStack->getCurrentRequest();
    if ($request === NULL) {
      return NULL;
    }

    $memo = $request->attributes->get(self::REQUEST_MEMO_ATTRIBUTE, []);
    $result = is_array($memo) ? ($memo[$key] ?? NULL) : NULL;

    return $result instanceof DistanceResult ? $result : NULL;
  }

  /**
   * Reuses a result only for the lifetime of the current HTTP request.
   *
   * This is deliberately not provider caching. It is neither written to a cache
   * backend nor available to another request, process, queue item, or customer.
   * It prevents validation, save, and checkout composition from buying the same
   * route repeatedly or disagreeing about which server result was accepted.
   */
  private function rememberForRequest(string $key, DistanceResult $result): DistanceResult {
    $request = $this->requestStack->getCurrentRequest();
    if ($request === NULL) {
      return $result;
    }

    $memo = $request->attributes->get(self::REQUEST_MEMO_ATTRIBUTE, []);
    $memo = is_array($memo) ? $memo : [];
    if (!isset($memo[$key]) && count($memo) >= self::REQUEST_MEMO_LIMIT) {
      array_shift($memo);
    }
    $memo[$key] = $result;
    $request->attributes->set(self::REQUEST_MEMO_ATTRIBUTE, $memo);

    return $result;
  }

  /**
   * Returns the chain deadline remaining before another adapter may start.
   */
  private function remainingMilliseconds(float $started, RoutingPolicy $policy): int {
    return max(0, $policy->deadlineMs() - (int) ((microtime(TRUE) - $started) * 1000));
  }

  /**
   * Emits fixed-dimension operational metrics with no request data.
   */
  private function metric(
    string $event,
    string $provider,
    RoutingPolicy $policy,
    ?string $reason = NULL,
  ): void {
    $this->routingUsage->record($event, $provider, $policy, $reason);
    $this->logger->info('Routing metric @event for @provider (@purpose).', [
      '@event' => $event,
      '@provider' => $provider,
      '@purpose' => $policy->purpose(),
    ]);
  }

  /**
   * Records a fallback only when another configured provider remains.
   */
  private function fallbackMetric(
    int $position,
    array $order,
    string $provider,
    RoutingPolicy $policy,
    string $reason,
  ): void {
    if ($position < count($order) - 1) {
      $this->metric('fallback', $provider, $policy, $reason);
    }
  }

}
