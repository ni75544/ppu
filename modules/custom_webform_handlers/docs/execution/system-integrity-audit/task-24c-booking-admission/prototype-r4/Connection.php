<?php

declare(strict_types=1);

namespace Drupal\custom_webform_handlers\Prototype\R4;

use Drupal\Core\Database\Transaction\TransactionManagerInterface;
use Drupal\mysql\Driver\Database\mysql\Connection as MysqlConnection;

/**
 * Task 24C-8C-R4 prototype — an inherited MySQL connection reporting the prototype manager.
 *
 * NOT application code. It changes exactly one thing: which transaction manager the connection
 * builds. Everything else (client handling, prefixing, driver behaviour) is inherited unchanged.
 * Loaded only by the standalone demonstration; never registered as a Drupal connection or used by
 * normal execution.
 */
final class Connection extends MysqlConnection {

  /**
   * {@inheritdoc}
   *
   * Returns the owned-root manager. The base Connection::transactionManager() memoises this.
   */
  protected function driverTransactionManager(): TransactionManagerInterface {
    return new TransactionManager($this);
  }

}
