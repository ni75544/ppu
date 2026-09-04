<?php

namespace Drupal\custom_webform_handlers\Dispatch;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Staff-facing wording for the planner's stable refusal reasons.
 *
 * DispatchReason keeps its names stable because they are logged, and says
 * explicitly that the wording belonging to them is not defined there. This is
 * that wording, for one audience: a member of staff looking at a board, who needs
 * to know whether to try another time, release something first, fix a fact on the
 * submission, or change configuration.
 *
 * It lives in one place because five screens can refuse for the same reason and
 * must not describe it differently — an operator comparing two refusals should be
 * comparing the schedule, not the phrasing. Nothing here decides anything; a
 * reason arrives already decided by the planner or the store.
 *
 * No refusal names another customer. The reasons carry no address, no name and no
 * price, and the sentences below add none: where a live claim is in the way, the
 * caller states the identifiers the board already shows, and nothing more.
 */
final class DispatchReasonText {

  /**
   * Explains one reason to a member of staff.
   */
  public static function staff(DispatchReason $reason): TranslatableMarkup {
    return match ($reason) {
      DispatchReason::Available => new TranslatableMarkup('The time is available.'),

      // Policy: the job is bookable, but not by the customer alone.
      DispatchReason::ManualReview => new TranslatableMarkup('This request is held for staff approval by the operating profile, so self-service could not book it.'),
      DispatchReason::OverWeightLimit => new TranslatableMarkup('The declared weight is above the self-service limit.'),
      DispatchReason::MultiPersonHandling => new TranslatableMarkup('The work needs more than the one driver this Resource represents, so it cannot be planned as a single-driver job.'),
      DispatchReason::UnsupportedService => new TranslatableMarkup('That service is not open to self-service booking.'),
      DispatchReason::UnsupportedDeliveryMode => new TranslatableMarkup('That delivery mode is not one the operating profile has opened.'),
      DispatchReason::StorePickupNotReady => new TranslatableMarkup('The store pickup is not recorded as paid for and ready to collect.'),

      // Physical: the schedule itself will not take the work at that time. Every
      // one of these is worth trying another time for.
      DispatchReason::SlotUnavailable => new TranslatableMarkup('Another booking or hold already owns that time. Release or move it first, then try again.'),
      DispatchReason::RepositioningInfeasible => new TranslatableMarkup('The vehicle cannot reach that pickup in time from the job before it.'),
      DispatchReason::OutsideWorkingHours => new TranslatableMarkup('That time falls outside the hours this Resource works on that date.'),
      DispatchReason::BreakConflict => new TranslatableMarkup('The work would run through a configured break.'),
      DispatchReason::Blackout => new TranslatableMarkup('That date is withheld from booking.'),
      DispatchReason::OutsideHorizon => new TranslatableMarkup('That date is outside the booking horizon in the operating profile.'),
      DispatchReason::ReturnToBaseInfeasible => new TranslatableMarkup('The vehicle could not get back to base within working hours afterwards.'),

      // Facts and state: nothing about the time is wrong, so trying another one
      // will not help.
      DispatchReason::IncompleteFacts => new TranslatableMarkup('Something the planner needs to time this job honestly is missing from the submission, so no window could be derived for it.'),
      DispatchReason::InvalidRequest => new TranslatableMarkup('That request could not be read, so nothing was changed.'),
      DispatchReason::HoldNotFound => new TranslatableMarkup('That hold has lapsed or was already given up.'),
      DispatchReason::ReservationNotFound => new TranslatableMarkup('That booking is no longer there to act on. Reload the board to see what it holds now.'),
      DispatchReason::AlreadyBooked => new TranslatableMarkup('This request already has capacity of its own. Look at the booking it has rather than making a second one.'),
      DispatchReason::LegacyUnplanned => new TranslatableMarkup('That day carries a booking made before the Dispatch Planner, and its exact interval cannot be proved. Reconcile the day before scheduling against it.'),
      DispatchReason::NotConfigured => new TranslatableMarkup('The Dispatch Planner is not configured well enough to answer. Check the operating profile.'),
    };
  }

  /**
   * Tells whether trying a different time is worth the operator's effort.
   *
   * A refusal is not one kind of thing. Some say the schedule is full at that
   * moment, which another moment may fix; others say the job cannot be timed at
   * all, or that the module is misconfigured, and offering "try another time" for
   * those wastes an operator's afternoon. Screens use this to decide whether to
   * keep the form open on the same job or send the operator elsewhere.
   */
  public static function anotherTimeMayWork(DispatchReason $reason): bool {
    return match ($reason) {
      DispatchReason::SlotUnavailable,
      DispatchReason::RepositioningInfeasible,
      DispatchReason::OutsideWorkingHours,
      DispatchReason::BreakConflict,
      DispatchReason::Blackout,
      DispatchReason::OutsideHorizon,
      DispatchReason::ReturnToBaseInfeasible,
      DispatchReason::LegacyUnplanned => TRUE,
      default => FALSE,
    };
  }

}
