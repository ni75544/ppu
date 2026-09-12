<?php

namespace Drupal\custom_webform_handlers\Distance;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\State\StateInterface;
use Psr\Log\LoggerInterface;

/**
 * Coordinates short provider circuits with bounded half-open probes.
 */
final class ProviderCircuit {

  private const STATE_PREFIX = 'custom_webform_handlers.routing_circuit.';

  private const LOCK_PREFIX = 'custom_webform_handlers:routing-circuit:';

  /**
   * Probe locks held by this request, keyed by circuit state key.
   *
   * @var array<string, string>
   */
  private array $heldProbes = [];

  public function __construct(
    private readonly StateInterface $state,
    private readonly LockBackendInterface $lock,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Returns whether one provider may be attempted now.
   */
  public function allows(string $provider, RoutingPolicy $policy): bool {
    $key = $this->stateKey($provider, $policy);
    $value = $this->value($key);
    $openUntil = (int) ($value['open_until'] ?? 0);
    $now = $this->time->getCurrentTime();
    if ($openUntil === 0) {
      return TRUE;
    }
    if ($openUntil > $now) {
      return FALSE;
    }

    $settings = $policy->circuit($provider);
    for ($probe = 0; $probe < $settings['probes']; $probe++) {
      $lockName = self::LOCK_PREFIX . hash('sha256', $key . ':' . $probe);
      try {
        if ($this->lock->acquire($lockName, max(1, $settings['cooldown']))) {
          $this->heldProbes[$key] = $lockName;
          return TRUE;
        }
      }
      catch (\Throwable) {
        $this->logger->warning('Routing circuit probe coordination is unavailable.');
        return FALSE;
      }
    }

    return FALSE;
  }

  /**
   * Closes a provider's circuit after a valid route.
   */
  public function recordSuccess(string $provider, RoutingPolicy $policy): void {
    $key = $this->stateKey($provider, $policy);
    try {
      $this->state->delete($key);
    }
    catch (\Throwable) {
      $this->logger->warning('A routing circuit success could not be recorded.');
    }
    $this->releaseProbe($key);
  }

  /**
   * Applies the exception classification to provider health.
   */
  public function recordFailure(
    string $provider,
    RoutingPolicy $policy,
    DistanceProviderException $exception,
  ): void {
    $key = $this->stateKey($provider, $policy);
    $settings = $policy->circuit($provider);
    $now = $this->time->getCurrentTime();
    $lockName = self::LOCK_PREFIX . 'state:' . hash('sha256', $key);
    try {
      if (!$this->lock->acquire($lockName, 2.0)) {
        return;
      }
      $value = $this->value($key);
      $failures = (int) ($value['failures'] ?? 0);
      $openUntil = 0;
      if ($exception->classification() === DistanceProviderException::THROTTLED) {
        $openUntil = $now + max(
          1,
          $exception->retryAfterSeconds() ?? $settings['cooldown'],
        );
      }
      elseif ($exception->disablesProvider()) {
        $failures = $settings['failures'];
        $openUntil = $now + $settings['cooldown'];
      }
      elseif ($exception->indicatesProviderFault()) {
        $failures++;
        if ($failures >= $settings['failures']) {
          $openUntil = $now + $settings['cooldown'];
        }
      }

      if ($openUntil > 0 || $failures > 0) {
        $this->state->set($key, [
          'failures' => $failures,
          'open_until' => $openUntil,
        ]);
      }
    }
    catch (\Throwable) {
      $this->logger->warning('A routing circuit failure could not be recorded.');
    }
    finally {
      try {
        $this->lock->release($lockName);
      }
      catch (\Throwable) {
      }
      $this->releaseProbe($key);
    }
  }

  /**
   * Returns a request-data-free view of one provider circuit.
   *
   * @return array{state: string, failures: int, open_until: int}
   *   The current state, consecutive provider faults, and reopen instant.
   */
  public function status(string $provider, RoutingPolicy $policy): array {
    $value = $this->value($this->stateKey($provider, $policy));
    $failures = max(0, (int) ($value['failures'] ?? 0));
    $openUntil = max(0, (int) ($value['open_until'] ?? 0));
    $now = $this->time->getCurrentTime();
    if ($openUntil > $now) {
      $state = 'open';
    }
    elseif ($openUntil > 0) {
      $state = 'half_open';
    }
    elseif ($failures > 0) {
      $state = 'degraded';
    }
    else {
      $state = 'closed';
    }

    return [
      'state' => $state,
      'failures' => $failures,
      'open_until' => $openUntil,
    ];
  }

  /**
   * Returns a sanitized circuit value.
   */
  private function value(string $key): array {
    try {
      $value = $this->state->get($key, []);
      return is_array($value) ? $value : [];
    }
    catch (\Throwable) {
      $this->logger->warning('Routing circuit state could not be read.');
      return ['failures' => 0, 'open_until' => PHP_INT_MAX];
    }
  }

  /**
   * Builds a policy-versioned state key without request data.
   */
  private function stateKey(string $provider, RoutingPolicy $policy): string {
    return self::STATE_PREFIX . hash('sha256', implode(':', [
      $provider,
      $policy->purpose(),
      $policy->version(),
    ]));
  }

  /**
   * Releases this request's half-open probe, when one was claimed.
   */
  private function releaseProbe(string $key): void {
    if (!isset($this->heldProbes[$key])) {
      return;
    }
    try {
      $this->lock->release($this->heldProbes[$key]);
    }
    catch (\Throwable) {
    }
    unset($this->heldProbes[$key]);
  }

}
