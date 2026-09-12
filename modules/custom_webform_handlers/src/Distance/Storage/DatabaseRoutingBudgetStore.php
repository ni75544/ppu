<?php

namespace Drupal\custom_webform_handlers\Distance\Storage;

use Drupal\Core\Database\Connection;
use Psr\Log\LoggerInterface;

/**
 * Atomically reserves durable external-request allowances.
 *
 * An independent physical connection commits the reservation before the HTTP
 * attempt and keeps it committed if a surrounding booking transaction rolls
 * back. Ambiguous and failed external attempts therefore remain counted.
 */
final class DatabaseRoutingBudgetStore {

  public const TABLE = 'custom_webform_handlers_routing_budget';

  /**
   * Connection whose commits are independent from caller transactions.
   */
  private ?Connection $budgetConnection = NULL;

  public function __construct(
    private readonly Connection $database,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Reserves provider-wide and purpose-specific allowance in one transaction.
   */
  public function reserve(
    string $provider,
    string $product,
    string $purpose,
    int $periodStart,
    int $periodEnd,
    ?int $purposeLimit,
    ?int $aggregateLimit,
    int $now,
  ): bool {
    $this->assertMachineName($provider, 'provider');
    $this->assertMachineName($product, 'product');
    $this->assertMachineName($purpose, 'purpose');
    if ($purpose === '__all__' || $periodStart <= 0 || $periodEnd <= $periodStart || $now <= 0) {
      throw new \InvalidArgumentException('Routing budget identity is invalid.');
    }
    foreach ([$purposeLimit, $aggregateLimit] as $limit) {
      if ($limit !== NULL && $limit < 0) {
        throw new \InvalidArgumentException('Routing budget limits cannot be negative.');
      }
    }
    if ($purposeLimit === NULL && $aggregateLimit === NULL) {
      return TRUE;
    }

    $connection = $this->connection();
    $limits = [
      '__all__' => $aggregateLimit,
      $purpose => $purposeLimit,
    ];
    ksort($limits, SORT_STRING);
    $transaction = $connection->startTransaction();
    try {
      foreach ($limits as $rowPurpose => $limit) {
        $connection->query(
          'INSERT INTO {' . self::TABLE . '} '
          . '(provider, product, purpose, period_start, period_end, requests, limit_snapshot, created, changed) '
          . 'VALUES (:provider, :product, :purpose, :period_start, :period_end, 0, :limit_snapshot, :created, :changed) '
          . 'ON DUPLICATE KEY UPDATE period_end = VALUES(period_end), '
          . 'limit_snapshot = VALUES(limit_snapshot), changed = VALUES(changed)',
          [
            ':provider' => $provider,
            ':product' => $product,
            ':purpose' => $rowPurpose,
            ':period_start' => $periodStart,
            ':period_end' => $periodEnd,
            ':limit_snapshot' => $limit,
            ':created' => $now,
            ':changed' => $now,
          ],
        );
      }

      $locked = $connection->query(
        'SELECT purpose FROM {' . self::TABLE . '} '
        . 'WHERE provider = :provider AND product = :product '
        . 'AND period_start = :period_start AND purpose IN (:aggregate, :purpose) '
        . 'ORDER BY purpose FOR UPDATE NOWAIT',
        [
          ':provider' => $provider,
          ':product' => $product,
          ':period_start' => $periodStart,
          ':aggregate' => '__all__',
          ':purpose' => $purpose,
        ],
      )->fetchAllAssoc('purpose');
      if (count($locked) !== 2) {
        throw new \RuntimeException('Routing budget rows could not be locked.');
      }

      foreach ($limits as $rowPurpose => $limit) {
        $update = $connection->update(self::TABLE)
          ->condition('provider', $provider)
          ->condition('product', $product)
          ->condition('purpose', $rowPurpose)
          ->condition('period_start', $periodStart)
          ->expression('requests', 'requests + 1')
          ->fields(['changed' => $now]);
        if ($limit !== NULL) {
          $update->condition('requests', $limit, '<');
        }
        if ((int) $update->execute() !== 1) {
          $transaction->rollBack();
          return FALSE;
        }
      }

      // Drupal commits a transaction when its wrapper leaves scope.
      unset($transaction);
      return TRUE;
    }
    catch (\Throwable $exception) {
      if (isset($transaction)) {
        try {
          $transaction->rollBack();
        }
        catch (\Throwable) {
        }
      }
      $this->logger->error('A routing allowance could not be reserved safely.');
      throw new \RuntimeException('Routing allowance storage is unavailable.');
    }
  }

  /**
   * Opens a transaction boundary independent from the caller's connection.
   */
  private function connection(): Connection {
    if ($this->budgetConnection !== NULL) {
      return $this->budgetConnection;
    }
    $options = $this->database->getConnectionOptions();
    $namespace = $options['namespace'] ?? NULL;
    $class = is_string($namespace) ? $namespace . '\\Connection' : '';
    if ($class === '' || !is_a($class, Connection::class, TRUE)) {
      throw new \RuntimeException('The routing allowance database driver is unavailable.');
    }

    try {
      $client = $class::open($options);
      $connection = new $class($client, $options);
      $connection->setTarget('custom_webform_handlers_budget');
      $connection->setKey('default');
      // The supported site database is MariaDB. Keep contention bounded;
      // NOWAIT is primary and this is the defensive session cap.
      $connection->query('SET SESSION innodb_lock_wait_timeout = 1');
      $this->budgetConnection = $connection;
    }
    catch (\Throwable) {
      $this->logger->error('The independent routing allowance connection could not be opened.');
      throw new \RuntimeException('Routing allowance storage is unavailable.');
    }

    return $this->budgetConnection;
  }

  /**
   * Refuses values that could escape the budget key namespace.
   */
  private function assertMachineName(string $value, string $field): void {
    if (preg_match('/^[a-z0-9_]{1,64}$/', $value) !== 1) {
      throw new \InvalidArgumentException('Invalid routing budget ' . $field . '.');
    }
  }

}
