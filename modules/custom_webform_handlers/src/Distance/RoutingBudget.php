<?php

namespace Drupal\custom_webform_handlers\Distance;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\custom_webform_handlers\Distance\Storage\DatabaseRoutingBudgetStore;
use Psr\Log\LoggerInterface;

/**
 * Reserves durable per-product allowances immediately before external dispatch.
 */
final class RoutingBudget {

  private const PRODUCT_DIRECTIONS = 'directions';

  private const PRODUCT_GEOCODING = 'geocoding';

  public function __construct(
    private readonly DatabaseRoutingBudgetStore $store,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Reserves one directions attempt under aggregate and purpose limits.
   */
  public function reserveRoute(RoutingPolicy $policy, string $provider): bool {
    return $this->reserve(
      $provider,
      self::PRODUCT_DIRECTIONS,
      $policy->purpose(),
      $policy->purposeAllowance($provider),
      $policy->providerWideAllowance($provider),
      $policy->billingPeriod(),
      $policy->billingTimezone(),
    );
  }

  /**
   * Reserves one separately metered committed-address geocoding attempt.
   */
  public function reserveGeocoder(string $provider, int $allowance): bool {
    try {
      $calendar = RoutingPolicy::budgetCalendar(
        $this->configFactory->get('custom_webform_handlers.settings'),
      );
    }
    catch (DistanceProviderException) {
      $this->logger->warning('The geocoding allowance calendar is invalid.');
      return FALSE;
    }

    return $this->reserve(
      $provider,
      self::PRODUCT_GEOCODING,
      'resolve',
      $allowance,
      $allowance,
      $calendar['period'],
      $calendar['timezone'],
    );
  }

  /**
   * Returns the fixed current allowance window used by every local counter.
   *
   * @return array{period: string, timezone: string, start: int, end: int}
   *   The configured calendar and current period bounds.
   */
  public function currentWindow(): array {
    $calendar = RoutingPolicy::budgetCalendar(
      $this->configFactory->get('custom_webform_handlers.settings'),
    );

    return $this->window(
      $calendar['period'],
      $calendar['timezone'],
      $this->time->getCurrentTime(),
    );
  }

  /**
   * Computes fixed calendar bounds and delegates the atomic reservation.
   */
  private function reserve(
    string $provider,
    string $product,
    string $purpose,
    ?int $purposeLimit,
    ?int $aggregateLimit,
    string $period,
    string $timezone,
  ): bool {
    if ($purposeLimit === NULL && $aggregateLimit === NULL) {
      return TRUE;
    }
    $now = $this->time->getCurrentTime();
    try {
      $window = $this->window($period, $timezone, $now);

      return $this->store->reserve(
        $provider,
        $product,
        $purpose,
        $window['start'],
        $window['end'],
        $purposeLimit,
        $aggregateLimit,
        $now,
      );
    }
    catch (\Throwable) {
      // Failure is closed: an unavailable ledger is not permission to spend.
      $this->logger->error('An external request allowance was refused because its durable ledger is unavailable.', [
        '@provider' => $provider,
        '@product' => $product,
        '@purpose' => $purpose,
      ]);
      return FALSE;
    }
  }

  /**
   * Calculates one operator-selected calendar window.
   */
  private function window(string $period, string $timezone, int $now): array {
    $zone = new \DateTimeZone($timezone);
    $instant = (new \DateTimeImmutable('@' . $now))->setTimezone($zone);
    if ($period === 'day') {
      $start = $instant->setTime(0, 0);
      $end = $start->modify('+1 day');
    }
    elseif ($period === 'month') {
      $start = $instant->modify('first day of this month')->setTime(0, 0);
      $end = $start->modify('first day of next month');
    }
    else {
      throw new \InvalidArgumentException('Unsupported routing billing period.');
    }

    return [
      'period' => $period,
      'timezone' => $timezone,
      'start' => $start->getTimestamp(),
      'end' => $end->getTimestamp(),
    ];
  }

}
