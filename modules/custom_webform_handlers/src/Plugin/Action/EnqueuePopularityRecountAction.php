<?php

namespace Drupal\custom_webform_handlers\Plugin\Action;

use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\custom_webform_handlers\Event\ReviewRequestTransitionedEvent;
use Drupal\custom_webform_handlers\Maintenance\MaintenanceQueueInterface;
use Drupal\eca\Attribute\EcaAction;
use Drupal\eca\Plugin\Action\ActionBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Reacts to a Review Request transition by asking for a popularity recount.
 *
 * A recount takes no input: there is one snapshot for the site, the window comes
 * from configuration, and the counts come from the database, so this action passes
 * nothing from the event and only asks the maintenance queue to recompute. It writes
 * no planner table, no Reservation, no payment, and no submission data — the single
 * thing it does is enqueue, which is the boundary requirement 4 holds an ECA action
 * to.
 *
 * This reaction is opportunistic, not the authority on freshness. The nightly cron
 * enqueue is what guarantees the snapshot is recomputed on a schedule an operator can
 * see; reacting to a review moving is a cheap way to let a burst of decisions refresh
 * the ranking sooner. Because the job type declares allow_duplicates FALSE and the
 * recompute is idempotent, a reaction landing on top of the nightly job collapses to
 * one run and changes nothing — over-enqueuing here is harmless by construction.
 *
 * The maintenance queue is injected through create(): ECA's ActionBase constructor is
 * final, so extra dependencies arrive by a setter after parent::create().
 */
#[Action(
  id: 'custom_webform_handlers_enqueue_popularity_recount',
  label: new TranslatableMarkup('Enqueue service popularity recount'),
)]
#[EcaAction(
  description: new TranslatableMarkup('Queues a recount of the Service Type popularity snapshot.'),
  version_introduced: '1.0.0',
)]
class EnqueuePopularityRecountAction extends ActionBase {

  /**
   * The narrow seam that hands work to the durable queue.
   *
   * @var \Drupal\custom_webform_handlers\Maintenance\MaintenanceQueueInterface
   */
  protected MaintenanceQueueInterface $maintenanceQueue;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->maintenanceQueue = $container->get('custom_webform_handlers.maintenance_queue');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function execute(): void {
    $event = $this->getEvent();
    if (!$event instanceof ReviewRequestTransitionedEvent) {
      // Wired to the review-transitioned derivative; a mismatch under the plugin's
      // broad wildcard is a no-op rather than a stray recount.
      return;
    }

    $this->maintenanceQueue->enqueuePopularityRecount();
  }

}
