<?php

namespace Drupal\custom_webform_handlers\Dispatch;

/**
 * Why the planner reached the answer it did.
 *
 * These classify the outcome of a claim: createHold() and confirmReservation()
 * each return one, so a refusal is always explained rather than merely negative.
 * The names are stable because they are logged and may be surfaced to staff; the
 * customer-facing wording that corresponds to them is not defined here.
 *
 * They are deliberately not attached to Slot Offers. offerSlots() returns only
 * feasible Slots, so there is no refused offer to explain — a grid time that
 * fails any of these tests is simply absent from the result. The same reasons
 * still apply when a claim is attempted, because a Slot can stop being feasible
 * between being offered and being taken.
 *
 * Manual Review is one reason among many rather than a separate result type: per
 * CONTEXT.md it creates neither a Hold nor a Reservation and consumes no Resource
 * capacity, so it is an outcome of planning, not a claim.
 */
enum DispatchReason: string {

  /**
   * The claim succeeded.
   */
  case Available = 'available';

  /**
   * The Dispatch Request is not self-bookable and needs staff approval.
   */
  case ManualReview = 'manual_review';

  /**
   * The declared package exceeds the configured self-service weight limit.
   */
  case OverWeightLimit = 'over_weight_limit';

  /**
   * The work needs more than the one driver this Resource represents.
   */
  case MultiPersonHandling = 'multi_person_handling';

  /**
   * The service type is not offered for self-service booking at all.
   */
  case UnsupportedService = 'unsupported_service';

  /**
   * Another Hold or Reservation already owns this Slot's capacity.
   */
  case SlotUnavailable = 'slot_unavailable';

  /**
   * The Resource cannot reach this pickup from its previous Dispatch Window.
   */
  case RepositioningInfeasible = 'repositioning_infeasible';

  /**
   * The Dispatch Window would run past the end of working hours.
   */
  case OutsideWorkingHours = 'outside_working_hours';

  /**
   * The Dispatch Window would overlap a configured break.
   */
  case BreakConflict = 'break_conflict';

  /**
   * The local date is a configured blackout date.
   */
  case Blackout = 'blackout';

  /**
   * The local date falls outside the configured booking horizon.
   */
  case OutsideHorizon = 'outside_horizon';

  /**
   * The Resource could not return to base within working hours.
   */
  case ReturnToBaseInfeasible = 'return_to_base_infeasible';

  /**
   * The Hold has lapsed, was released, or belongs to another session.
   */
  case HoldNotFound = 'hold_not_found';

  /**
   * The request could not be normalised into a planner-usable shape.
   */
  case InvalidRequest = 'invalid_request';

  /**
   * A fact the duration policy needs was missing or malformed.
   *
   * Distinct from an unsupported service: the service may well be bookable, but
   * something needed to time it honestly is unknown. An unknown fact is never
   * read as zero, so the request goes to staff rather than being scheduled short.
   */
  case IncompleteFacts = 'incomplete_facts';

  /**
   * A Store Pickup was not confirmed as paid for and ready to collect.
   */
  case StorePickupNotReady = 'store_pickup_not_ready';

  /**
   * The Delivery Mode is not one an operator has opened to self-booking.
   */
  case UnsupportedDeliveryMode = 'unsupported_delivery_mode';

  /**
   * The Resource's day carries an active legacy claim of unproven feasibility.
   *
   * Set by the legacy scan, not by planning. Such a day stays closed to
   * self-booking until an administrator reconciles it, because the older table
   * holds no route data and a feasible Dispatch Window cannot be proven for it.
   */
  case LegacyUnplanned = 'legacy_unplanned';

  /**
   * The planner is not configured well enough to answer.
   */
  case NotConfigured = 'not_configured';

  /**
   * The Reservation a staff operation names is not there to act on.
   *
   * Distinct from an invalid request, which is malformed input, and from a lost
   * race, which is well-formed input that another claim beat. This is well-formed
   * input naming a row that no longer holds capacity — already released,
   * cancelled, or superseded by an earlier move — and the remedy is to reload the
   * board rather than to correct anything.
   */
  case ReservationNotFound = 'reservation_not_found';

  /**
   * The job already has capacity of its own, so a second claim would duplicate it.
   *
   * Only reachable on the staff confirmation path. Public confirmation is keyed by
   * Hold token, and one Hold confirms once; a staff confirmation is keyed by the
   * submission, so the same waiting job can be confirmed twice — in two tabs, or by
   * two people who both saw it waiting — and the vehicle would be sent to one move
   * twice. Not a refusal of the plan: the plan may be perfectly feasible, and the
   * booking that already exists is the one to look at.
   */
  case AlreadyBooked = 'already_booked';

}
