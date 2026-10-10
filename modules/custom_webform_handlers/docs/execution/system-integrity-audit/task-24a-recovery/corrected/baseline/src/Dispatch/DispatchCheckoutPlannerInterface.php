<?php

namespace Drupal\custom_webform_handlers\Dispatch;

use Drupal\webform\WebformSubmissionInterface;

/**
 * Final-route planning operations available only to the checkout manager.
 *
 * The public DispatchPlannerInterface deliberately retains offer policy. This
 * separate narrow seam is injected only into the manager that has already
 * validated the saved submission and workflow gate, so an anonymous hold or
 * renew endpoint cannot select the final chain merely by supplying a flag.
 *
 * The same DispatchPlanner service implements both interfaces and both route
 * through its one feasibility algorithm. This is an authority boundary, not a
 * second planner.
 *
 * @internal
 */
interface DispatchCheckoutPlannerInterface {

  /**
   * Claims a checkout Slot using final-route preparation.
   */
  public function createCheckoutHold(DispatchRequest $request, Slot $slot): HoldResult;

  /**
   * Confirms a validated paid attempt using final-route preparation.
   */
  public function confirmCheckoutReservation(Hold $hold, WebformSubmissionInterface $submission): ReservationResult;

}
