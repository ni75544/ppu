<?php

namespace Drupal\custom_webform_handlers\Plugin\ECA\Event;

use Drupal\eca\Plugin\ECA\Event\EventDeriverBase;

/**
 * Derives one ECA event plugin per domain event this module emits.
 *
 * The deriver and the plugin share a single source of truth: this returns exactly
 * what DispatchDomainEvent::definitions() returns, so the derivatives ECA discovers
 * and the events the plugin declares can never drift apart. EventDeriverBase turns
 * each entry into a plugin derivative keyed by its array key.
 */
class DispatchDomainEventDeriver extends EventDeriverBase {

  /**
   * {@inheritdoc}
   */
  protected function definitions(): array {
    return DispatchDomainEvent::definitions();
  }

}
