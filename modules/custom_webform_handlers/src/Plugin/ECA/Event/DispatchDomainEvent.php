<?php

namespace Drupal\custom_webform_handlers\Plugin\ECA\Event;

use Drupal\custom_webform_handlers\Event\DispatchReservationConfirmedEvent;
use Drupal\custom_webform_handlers\Event\ReviewRequestTransitionedEvent;
use Drupal\eca\Plugin\ECA\Event\EventBase;

/**
 * Exposes this module's post-commit domain events to ECA.
 *
 * The reaction layer requirement 4 describes is two halves: the module emits a
 * domain event after its authoritative transaction commits, and ECA reacts to it by
 * calling a narrow public interface. This plugin is the bridge between the halves —
 * it tells ECA that the two events DomainEventEmitter dispatches exist, what class
 * each is, and what name each answers to, so a model can list one as its trigger.
 *
 * Nothing here decides anything. The plugin carries no capacity, payment, or
 * availability authority and adds none; it is a declaration, and the reacting
 * actions are held to the same narrow-interface rule the emitter's contract states.
 * The default '*' wildcard from EventBase is inherited unchanged: each derivative
 * already maps to exactly one event name, and each reacting action re-checks the
 * concrete event class with instanceof, so no wildcard narrowing is needed to keep a
 * reaction from firing on the wrong event.
 *
 * @EcaEvent(
 *   id = "custom_webform_handlers_dispatch_domain_event",
 *   deriver = "Drupal\custom_webform_handlers\Plugin\ECA\Event\DispatchDomainEventDeriver"
 * )
 */
class DispatchDomainEvent extends EventBase {

  /**
   * {@inheritdoc}
   */
  public static function definitions(): array {
    $events = [];
    $events['reservation_confirmed'] = [
      'label' => 'Dispatch Reservation confirmed',
      'event_name' => DispatchReservationConfirmedEvent::EVENT_NAME,
      'event_class' => DispatchReservationConfirmedEvent::class,
    ];
    $events['review_transitioned'] = [
      'label' => 'Review Request transitioned',
      'event_name' => ReviewRequestTransitionedEvent::EVENT_NAME,
      'event_class' => ReviewRequestTransitionedEvent::class,
    ];
    return $events;
  }

}
