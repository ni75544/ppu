<?php

namespace Drupal\custom_webform_handlers\Plugin\views\field;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\views\Plugin\views\field\FieldPluginBase;

/**
 * A ledger column a role either sees or never asks the database for.
 *
 * Withholding here is removal, not masking. Views calls access() on every
 * handler in ViewExecutable::_initHandler() and unsets the ones that refuse
 * before the query is built, so a refused column is never selected, never
 * reaches a ResultRow, and never lands in the rendered page, its attachments,
 * or its cache entry. A placeholder cell reading "not shown to your role" would
 * have had to fetch the value first, which is the thing this class exists to
 * prevent.
 *
 * The permission is read from the 'dispatch permission' key in the Views data
 * definition rather than through core's own 'access callback' mechanism, which
 * has two silent failure modes here: HandlerBase::access() gates the callback on
 * function_exists(), so a static method is ignored and access is granted; and it
 * merges arguments as [$account] + $args, an array union that drops whatever
 * sits at key 0. A silent failure in this class publishes a customer's address,
 * so the check is written out rather than configured.
 *
 * Fail closed: a subclass whose definition names no permission shows nothing to
 * anybody, including an administrator. A forgotten declaration then surfaces as
 * a missing column instead of as a disclosure.
 *
 * @see \Drupal\views\ViewExecutable::_initHandler()
 * @see custom_webform_handlers_views_data()
 */
abstract class DispatchProtectedFieldBase extends FieldPluginBase implements CacheableDependencyInterface {

  /**
   * {@inheritdoc}
   */
  public function access(AccountInterface $account): bool {
    $permission = $this->definition['dispatch permission'] ?? NULL;
    if (!is_string($permission) || $permission === '') {
      return FALSE;
    }

    return $account->hasPermission($permission);
  }

  /**
   * {@inheritdoc}
   *
   * Never orderable, whatever the Views data says. Ordering by a withheld column
   * discloses its order, and an order is enough to rank prices or place an
   * address alphabetically without ever rendering either. The Views data
   * declares no filter, no sort and no argument for these columns; this is the
   * second lock on the same door, because core's clickSortable() defaults to
   * TRUE for any definition that simply forgets to say otherwise.
   */
  public function clickSortable(): bool {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   *
   * Two roles are served structurally different pages from one URL — different
   * columns, and a different SELECT behind them — so the render cache has to
   * vary by permission or the first visitor's page would be handed to the
   * second.
   */
  public function getCacheContexts(): array {
    return ['user.permissions'];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags(): array {
    return [];
  }

  /**
   * {@inheritdoc}
   *
   * Freshness is the display's business, not this handler's. The Reservation
   * Dashboard runs the 'none' cache plugin, which reports a zero max-age for the
   * whole view, because a Hold lapses against a wall clock and no ledger write
   * invalidates a cache tag: a cached board would keep showing capacity that has
   * already been given back.
   */
  public function getCacheMaxAge(): int {
    return Cache::PERMANENT;
  }

}
