<?php

namespace Drupal\custom_webform_handlers\Distance;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\custom_webform_handlers\Distance\Storage\DatabaseRoutingBudgetStore;
use Psr\Log\LoggerInterface;

/**
 * Records and reports bounded local routing operations without request data.
 */
final class RoutingUsage {

  public const TABLE = 'custom_webform_handlers_routing_usage';

  private const EVENTS = [
    'allowance_refusal',
    'attempted',
    'cache_hit',
    'circuit_open',
    'failure',
    'fallback',
    'throttle',
  ];

  public function __construct(
    private readonly Connection $database,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly RoutingBudget $routingBudget,
    private readonly ProviderCircuit $providerCircuit,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Increments one fixed-dimension local counter.
   */
  public function record(
    string $event,
    string $provider,
    RoutingPolicy $policy,
    ?string $reason = NULL,
  ): void {
    if (!in_array($event, self::EVENTS, TRUE)
      || !in_array($provider, RoutingPolicy::PROVIDERS, TRUE)
      || !in_array($policy->purpose(), RoutingPolicy::PURPOSES, TRUE)) {
      $this->logger->warning('An invalid routing usage dimension was refused.');
      return;
    }

    $reason = $event === 'fallback' ? $this->machineValue($reason) : '';
    try {
      $window = $this->routingBudget->currentWindow();
      $now = $this->time->getCurrentTime();
      $this->database->merge(self::TABLE)
        ->keys([
          'provider' => $provider,
          'purpose' => $policy->purpose(),
          'event' => $event,
          'reason' => $reason,
          'period_start' => $window['start'],
        ])
        ->fields([
          'period_end' => $window['end'],
          'changed' => $now,
        ])
        ->insertFields([
          'provider' => $provider,
          'purpose' => $policy->purpose(),
          'event' => $event,
          'reason' => $reason,
          'period_start' => $window['start'],
          'period_end' => $window['end'],
          'occurrences' => 1,
          'created' => $now,
          'changed' => $now,
        ])
        ->expression('occurrences', 'occurrences + 1')
        ->execute();
    }
    catch (\Throwable) {
      // Operational counters are observability, never route authority.
      $this->logger->warning('A local routing usage counter could not be recorded.');
    }
  }

  /**
   * Returns the permission-safe current-period operator summary.
   */
  public function snapshot(): array {
    try {
      $window = $this->routingBudget->currentWindow();
      $routing = RoutingPolicy::merge((array) $this->configFactory
        ->get('custom_webform_handlers.settings')
        ->get('routing'));

      return [
        'available' => TRUE,
        'window' => $window,
        'counters' => $this->counterRows($window['start']),
        'budgets' => $this->budgetRows($routing, $window['start']),
        'circuits' => $this->circuitRows(),
      ];
    }
    catch (\Throwable) {
      $this->logger->warning('The local routing usage summary could not be read.');
      return [
        'available' => FALSE,
        'window' => [],
        'counters' => [],
        'budgets' => [],
        'circuits' => [],
      ];
    }
  }

  /**
   * Reads fixed-dimension counters for the current allowance period.
   */
  private function counterRows(int $periodStart): array {
    if (!$this->database->schema()->tableExists(self::TABLE)) {
      return [];
    }

    return $this->database->select(self::TABLE, 'usage')
      ->fields('usage', [
        'provider',
        'purpose',
        'event',
        'reason',
        'occurrences',
        'changed',
      ])
      ->condition('period_start', $periodStart)
      ->orderBy('provider')
      ->orderBy('purpose')
      ->orderBy('event')
      ->orderBy('reason')
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);
  }

  /**
   * Combines configured limits with durable reservations already consumed.
   */
  private function budgetRows(array $routing, int $periodStart): array {
    $actual = [];
    if ($this->database->schema()->tableExists(DatabaseRoutingBudgetStore::TABLE)) {
      $query = $this->database->select(DatabaseRoutingBudgetStore::TABLE, 'budget')
        ->fields('budget', [
          'provider',
          'product',
          'purpose',
          'requests',
          'limit_snapshot',
        ])
        ->condition('period_start', $periodStart);
      foreach ($query->execute()->fetchAll(\PDO::FETCH_ASSOC) as $row) {
        $actual[$this->budgetKey($row['provider'], $row['product'], $row['purpose'])] = $row;
      }
    }

    $expected = [];
    $geocoder = trim((string) $routing['geocoder']['provider']);
    if ($geocoder !== '') {
      $limit = max(0, (int) $routing['geocoder']['request_allowance']);
      $expected[$this->budgetKey($geocoder, 'geocoding', '__all__')] = [
        'provider' => $geocoder,
        'product' => 'geocoding',
        'purpose' => '__all__',
        'limit' => $limit,
      ];
      $expected[$this->budgetKey($geocoder, 'geocoding', 'resolve')] = [
        'provider' => $geocoder,
        'product' => 'geocoding',
        'purpose' => 'resolve',
        'limit' => $limit,
      ];
    }

    foreach ([RoutingPolicy::PROVIDER_MAPBOX, RoutingPolicy::PROVIDER_GOOGLE] as $provider) {
      $providerLimit = max(0, (int) $routing['providers'][$provider]['request_allowance']);
      $expected[$this->budgetKey($provider, 'directions', '__all__')] = [
        'provider' => $provider,
        'product' => 'directions',
        'purpose' => '__all__',
        'limit' => $providerLimit,
      ];
      foreach (RoutingPolicy::PURPOSES as $purpose) {
        if (!in_array($provider, (array) $routing['purposes'][$purpose]['order'], TRUE)) {
          continue;
        }
        $shares = (array) $routing['purposes'][$purpose]['request_allowance'];
        $expected[$this->budgetKey($provider, 'directions', $purpose)] = [
          'provider' => $provider,
          'product' => 'directions',
          'purpose' => $purpose,
          'limit' => array_key_exists($provider, $shares)
            ? max(0, (int) $shares[$provider])
            : NULL,
        ];
      }
    }

    foreach ($actual as $key => $row) {
      if (!isset($expected[$key])) {
        $expected[$key] = [
          'provider' => $row['provider'],
          'product' => $row['product'],
          'purpose' => $row['purpose'],
          'limit' => $row['limit_snapshot'] === NULL
            ? NULL
            : (int) $row['limit_snapshot'],
        ];
      }
    }

    $rows = [];
    foreach ($expected as $key => $row) {
      $used = (int) ($actual[$key]['requests'] ?? 0);
      $limit = $row['limit'];
      $row['used'] = $used;
      $row['remaining'] = $limit === NULL ? NULL : max(0, $limit - $used);
      $rows[] = $row;
    }
    usort($rows, static fn (array $left, array $right): int => [
      $left['provider'],
      $left['product'],
      $left['purpose'],
    ] <=> [
      $right['provider'],
      $right['product'],
      $right['purpose'],
    ]);

    return $rows;
  }

  /**
   * Reads every active purpose/provider circuit without exposing its state key.
   */
  private function circuitRows(): array {
    $settings = $this->configFactory->get('custom_webform_handlers.settings');
    $rows = [];
    foreach (RoutingPolicy::PURPOSES as $purpose) {
      try {
        $policy = RoutingPolicy::fromConfig($settings, $purpose);
      }
      catch (DistanceProviderException) {
        continue;
      }
      foreach ($policy->providerOrder() as $provider) {
        $rows[] = ['provider' => $provider, 'purpose' => $purpose]
          + $this->providerCircuit->status($provider, $policy);
      }
    }

    return $rows;
  }

  /**
   * Builds a bounded identity for a durable budget row.
   */
  private function budgetKey(string $provider, string $product, string $purpose): string {
    return implode(':', [$provider, $product, $purpose]);
  }

  /**
   * Normalizes a code-owned reason into a bounded machine value.
   */
  private function machineValue(?string $value): string {
    $value = strtolower(trim((string) $value));
    return preg_match('/^[a-z0-9_]{1,64}$/', $value) === 1
      ? $value
      : DistanceProviderException::UNCLASSIFIED;
  }

}
