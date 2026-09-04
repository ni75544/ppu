<?php

namespace Drupal\custom_webform_handlers\Dispatch\Status;

/**
 * Where a Resource says it is in its day.
 *
 * This is an observation, not a plan. A Dispatch Window says when the vehicle is
 * committed; these values say what a human reports is actually happening, and the
 * two are allowed to disagree — that disagreement is the whole reason an operator
 * looks at a board. Nothing in this enum can move a Reservation: a state is
 * recorded, read, and displayed, and any rescheduling it suggests goes through the
 * Replan transaction on staff authority like every other move.
 *
 * The names are stable because they are stored in a database column and read back
 * by later releases. Wording belongs to ResourceStatusText, for the same reason it
 * does for DispatchReason: five screens must not describe one state five ways.
 */
enum ResourceStatusState: string {

  /**
   * Working, and between jobs. The neutral state a day starts in.
   *
   * Distinct from Unavailable, which is a report that the Resource cannot work.
   * Availability for booking is still decided by the operating profile and the
   * calendar, never by this value.
   */
  case Available = 'available';

  /**
   * Reported as unable to work, whatever the calendar says.
   *
   * Deliberately does not withhold capacity. Time is withheld by a Blackout, which
   * is proved against live work and refuses to overwrite it; a status that quietly
   * closed the calendar would be a second, weaker way to do the same thing and the
   * two would drift apart.
   */
  case Unavailable = 'unavailable';

  /**
   * Driving to the pickup for the Reservation named on the record.
   */
  case EnRoutePickup = 'en_route_pickup';

  /**
   * At the pickup, doing pickup work.
   */
  case AtPickup = 'at_pickup';

  /**
   * Driving from the pickup to the delivery.
   */
  case EnRouteDelivery = 'en_route_delivery';

  /**
   * The job named on the record is finished.
   *
   * Reporting this does not release the Reservation or shorten its Dispatch
   * Window. The Reservation stays exactly as it was proved; finishing early frees
   * nothing automatically, because the next job was planned against the window
   * that was proved and not against a hope about this one.
   */
  case Completed = 'completed';

  /**
   * Tells whether this state is about one specific job.
   *
   * The four working states name a Reservation; Available and Unavailable are
   * about the Resource itself. The staff form uses this to decide whether a
   * Reservation identifier is required, so a report of "at pickup" cannot be
   * recorded without saying which pickup.
   */
  public function needsReservation(): bool {
    return match ($this) {
      self::EnRoutePickup, self::AtPickup, self::EnRouteDelivery, self::Completed => TRUE,
      self::Available, self::Unavailable => FALSE,
    };
  }

  /**
   * Tells whether the Resource reports itself able to work at all.
   */
  public function isWorking(): bool {
    return $this !== self::Unavailable;
  }

}
