<?php

namespace Drupal\custom_webform_handlers\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Announces that a paid Reservation was confirmed, after the money settled.
 *
 * Five scalars: the Reservation, its Resource, its order, the submission that
 * bought it, and the local date the work falls on. The date is resolved in the
 * Resource's own timezone at the emission site, because "which day" is a question
 * only that Resource's calendar can answer, and a listener handed a bare timestamp
 * would have to guess the zone. Those two — Resource and day — are exactly what a
 * route-warming reaction needs, and carrying them as scalars is what keeps a pickup
 * or delivery address off the event and out of anything a listener writes.
 *
 * Emitted from DispatchCheckoutManager::settlePaid(), and only on the settle a
 * request actually won: the same guard that stops a replayed confirmation from
 * sending a second notice stops it from announcing a second time. By the time it is
 * emitted the Resource Calendar Guard's transaction has committed, so the
 * Reservation this names already exists and nothing a listener does can unmake it —
 * a reaction is a consequence of the booking, never a condition of it.
 */
final class DispatchReservationConfirmedEvent extends Event {

  /**
   * The name this event is dispatched under.
   *
   * Subscribed to by the ECA event plugin; kept on the class so the emitter and the
   * plugin share one source for the string.
   */
  const EVENT_NAME = 'custom_webform_handlers.dispatch_reservation_confirmed';

  public function __construct(
    public readonly int $reservationId,
    public readonly string $resourceId,
    public readonly int $orderId,
    public readonly int $submissionId,
    public readonly string $localDate,
  ) {}

}
