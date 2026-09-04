<?php

namespace Drupal\custom_webform_handlers\Dispatch;

/**
 * Which public booking workflow, if any, may act for one Webform.
 *
 * Two public Hold families exist in this module: the older fixed-slot
 * reservation workflow, which is taking real bookings today, and the Dispatch
 * Planner. Both can hold capacity, and neither can see the other's rows. If both
 * were ever live for the same Webform, two guards would be enforcing two
 * different pictures of the same driver's day, and the day would be sold twice.
 *
 * So the answer for a Webform is one of these three and never a combination.
 * There is no case for "both" and no case for "whichever answers first": a
 * caller asks which workflow owns a Webform and acts only if the answer is its
 * own.
 *
 * Disabled is a first-class answer rather than an error. A Webform that no
 * workflow owns is the normal state of most Webforms on the site, and it is also
 * the state a half-applied transfer must land in — see BookingWorkflowGate for
 * why that is safer than falling back.
 */
enum BookingWorkflow: string {

  /**
   * The fixed-slot reservation workflow owns this Webform.
   *
   * This is the safe default for an existing form, because it is what is
   * already enforcing. Nothing in this module promotes a Webform to Dispatch on
   * its own.
   */
  case Legacy = 'legacy';

  /**
   * The Dispatch Planner owns this Webform.
   *
   * Only ever reached by an explicit operator transfer. See
   * BookingWorkflowGate::TRANSFER_KEY.
   */
  case Dispatch = 'dispatch';

  /**
   * No public booking workflow may act for this Webform.
   */
  case Disabled = 'disabled';

}
