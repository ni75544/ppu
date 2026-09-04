<?php

namespace Drupal\custom_webform_handlers\Plugin\Action;

use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\custom_webform_handlers\Event\DispatchReservationConfirmedEvent;
use Drupal\custom_webform_handlers\Maintenance\MaintenanceQueueInterface;
use Drupal\eca\Attribute\EcaAction;
use Drupal\eca\Plugin\Action\ActionBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Reacts to a confirmed Reservation by enqueuing a route-warm for its day.
 *
 * The whole of this action is one call to one narrow interface. It reads two scalars
 * off the event that triggered it — which Resource, which local day — and asks the
 * maintenance queue to warm that day off the request. It writes no planner table, no
 * Reservation, no Checkout Attempt, no payment entity, and no submission data; the
 * only thing it can do is put a bounded job on a durable queue, which is exactly the
 * boundary requirement 4 draws around an ECA action.
 *
 * The event dependency is injected through create(), because ECA's ActionBase seals
 * its constructor: the ten-argument base constructor is final, so the maintenance
 * queue arrives by a setter after parent::create() has built the instance.
 *
 * The instanceof guard is deliberate and not defensive noise. The ECA model wires
 * this action to the reservation-confirmed derivative alone, but the event plugin
 * inherits the '*' wildcard, so making the action prove its event's class before it
 * reads resource-specific fields is what guarantees it can never warm a route from an
 * event that carries none.
 */
#[Action(
  id: 'custom_webform_handlers_enqueue_route_warm',
  label: new TranslatableMarkup('Enqueue dispatch route warming'),
)]
#[EcaAction(
  description: new TranslatableMarkup('Queues a route-warm for the confirmed Reservation\'s Resource and day.'),
  version_introduced: '1.0.0',
)]
class EnqueueRouteWarmAction extends ActionBase {

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
    if (!$event instanceof DispatchReservationConfirmedEvent) {
      // Wired to the reservation-confirmed derivative, but the plugin's wildcard is
      // broad; a mismatch is silence, not a warm from an event without a Resource.
      return;
    }

    $this->maintenanceQueue->enqueueRouteWarm($event->resourceId, $event->localDate);
  }

}
