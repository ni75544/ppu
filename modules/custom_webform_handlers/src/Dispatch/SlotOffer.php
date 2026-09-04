<?php

namespace Drupal\custom_webform_handlers\Dispatch;

/**
 * One selectable Slot, with the window it would occupy.
 *
 * An offer means the planner has already found this Slot server-feasible: it is
 * on the grid, inside working hours and the horizon, clear of breaks, blackouts
 * and existing Dispatch Windows, reachable by Repositioning, and returnable to
 * base if that is required. There is no such thing as an unselectable offer, and
 * no reason field, because a Slot that cannot be taken is simply not offered.
 *
 * That is a deliberate narrowing of the contract. Publishing refused grid times
 * with reasons invites a caller to render them as choices — greyed out, but still
 * present and still clickable by anything that ignores the flag — and it leaks
 * the shape of the Resource's day to anyone who asks. A caller that receives an
 * empty list may say so in its own words; what it must not do is treat an
 * unselectable grid time as an offer.
 *
 * Selectability is still only a read model, exactly as Availability is in
 * CONTEXT.md. It never resolves contention: a Slot offered here can still lose
 * the race in createHold().
 */
final readonly class SlotOffer {

  /**
   * @param \Drupal\custom_webform_handlers\Dispatch\Slot $slot
   *   The pickup-arrival option, known feasible at the time it was offered.
   * @param \Drupal\custom_webform_handlers\Dispatch\DispatchWindow $window
   *   The window this Slot would occupy, derived server-side. Never NULL: an
   *   offer whose window could not be derived is not an offer.
   * @param int $pickupPromiseEnd
   *   The end of the arrival promise, in UTC. The promise runs from the Slot's
   *   own instant to here, so a customer is told a realistic arrival range
   *   rather than a single minute the traffic will not honour.
   * @param int $estimatedDeliveryAt
   *   When the Resource is expected to reach the delivery location, in UTC.
   *   Derived, and an estimate: it is not part of what the Slot guarantees.
   */
  public function __construct(
    public Slot $slot,
    public DispatchWindow $window,
    public int $pickupPromiseEnd,
    public int $estimatedDeliveryAt,
  ) {}

  /**
   * Returns the start of the arrival promise, in UTC.
   */
  public function pickupPromiseStart(): int {
    return $this->slot->startTimestamp;
  }

  /**
   * Returns the estimated on-site service duration, in whole minutes.
   *
   * The work at both ends. It excludes the operational buffer, which protects the
   * Resource's next job rather than serving this customer.
   */
  public function estimatedServiceMinutes(): int {
    return $this->window->pickupWorkMinutes + $this->window->deliveryWorkMinutes;
  }

}
