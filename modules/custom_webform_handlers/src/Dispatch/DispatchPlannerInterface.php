<?php

namespace Drupal\custom_webform_handlers\Dispatch;

use Drupal\webform\WebformSubmissionInterface;

/**
 * The only dispatch-planning seam a caller may use.
 *
 * Three operations, in the order a booking actually happens: find out what is
 * offerable, claim one, then confirm it. Everything else the planner does —
 * deriving windows, checking Repositioning, honouring working hours, breaks,
 * blackouts, buffers and the horizon, resolving contention — happens behind
 * these three calls and is not addressable from outside.
 *
 * What a caller must understand about the model:
 *
 * - A Slot is a pickup-arrival option: the instant the Resource reaches the
 *   customer's pickup location. It is not the length of the job.
 * - A Dispatch Window is the Resource's occupied interval and is always derived
 *   server-side from the Dispatch Request and the Resource Profile. Callers must
 *   never persist a window, and must never trust one that a browser calculated.
 *   A window arriving from a client is treated as absent, not as input.
 * - offerSlots() returns only feasible, selectable Slots. Unselectable grid times
 *   are not offers and are never published, so an empty result means nothing is
 *   bookable rather than that something was withheld.
 * - Availability is a read model. A Slot offered as selectable may still lose the
 *   race in createHold(), because contention is resolved when the claim is made
 *   and not when the offer is read.
 * - Manual Review is an outcome of planning, not a claim. It produces no Hold and
 *   no Reservation and consumes no capacity, so it yields no Slots at all rather
 *   than Slots a visitor cannot take.
 *
 * Deliberately absent from this interface: anything that would let a caller
 * reach a row, mutate one, calculate a quote, ask about browser state, or test a
 * single conflict in isolation. A caller that could check one conflict would
 * inevitably check it, act on the answer, and be overtaken between the two —
 * which is precisely the race the planner exists to prevent. Quote calculation
 * remains on the separate Google Routes v2 path and never enters here.
 */
interface DispatchPlannerInterface {

  /**
   * Returns every server-feasible Slot on one local date.
   *
   * Only selectable Slots are returned. A grid time that is outside working
   * hours, inside a break, blacked out, past the horizon, already occupied,
   * unreachable by Repositioning, or unable to return to base is not an offer and
   * does not appear — not as a refused entry, and not with a reason. Callers
   * therefore cannot render unselectable times as choices, and the shape of the
   * Resource's day is not published to whoever asks.
   *
   * An empty array is a complete and legitimate answer: nothing is bookable on
   * that date. A caller may say so in its own words, but must not infer that some
   * grid time is available anyway.
   *
   * Nothing is offered at all when the Dispatch Request is not self-bookable —
   * Manual Review consumes no capacity, so it has no Slots — or when the Resource
   * Profile is not configured well enough to plan, such as return-to-base with no
   * depot address.
   *
   * @param \Drupal\custom_webform_handlers\Dispatch\DispatchRequest $request
   *   The normalized operational facts to plan against.
   * @param string $localDate
   *   A Y-m-d date read in the Resource Profile's timezone.
   * @param \Drupal\custom_webform_handlers\Dispatch\ResourceProfile $profile
   *   The Resource whose capacity is being offered.
   *
   * @return \Drupal\custom_webform_handlers\Dispatch\SlotOffer[]
   *   Feasible, selectable offers in ascending pickup-arrival order. Possibly
   *   empty.
   */
  public function offerSlots(DispatchRequest $request, string $localDate, ResourceProfile $profile): array;

  /**
   * Claims one Slot for a short window, or reports why it could not be claimed.
   *
   * The Slot is re-derived and re-validated here. Being handed a Slot is not
   * permission to have it: eligibility, the window, the schedule, and capacity
   * are all checked again at this point, because an offer may be stale by the
   * time it is acted on.
   *
   * A refusal is returned rather than thrown. Losing a race is the expected
   * outcome for one of two visitors, not an exceptional condition.
   *
   * @param \Drupal\custom_webform_handlers\Dispatch\DispatchRequest $request
   *   The request the claim is for.
   * @param \Drupal\custom_webform_handlers\Dispatch\Slot $slot
   *   The Slot to claim.
   *
   * @return \Drupal\custom_webform_handlers\Dispatch\HoldResult
   *   The Hold, or the reason there is none.
   */
  public function createHold(DispatchRequest $request, Slot $slot): HoldResult;

  /**
   * Turns a live Hold into a Reservation linked to a completed submission.
   *
   * The Hold must still exist, still be unexpired, and still belong to the
   * session presenting it. A Reservation created here stays unavailable until an
   * explicit administrative release or a successful Replan.
   *
   * @param \Drupal\custom_webform_handlers\Dispatch\Hold $hold
   *   The claim to confirm.
   * @param \Drupal\webform\WebformSubmissionInterface $submission
   *   The completed submission the Reservation is linked to.
   *
   * @return \Drupal\custom_webform_handlers\Dispatch\ReservationResult
   *   The confirmed Reservation, or the reason confirmation was refused.
   */
  public function confirmReservation(Hold $hold, WebformSubmissionInterface $submission): ReservationResult;

}
