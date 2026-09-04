<?php

namespace Drupal\custom_webform_handlers\Dispatch\Schedule;

use Drupal\custom_webform_handlers\Dispatch\ResourceDay;
use Drupal\custom_webform_handlers\Dispatch\ResourceProfile;

/**
 * Interprets a Resource Profile's local calendar rules for one date.
 *
 * A seam because calendar interpretation is the part most likely to grow: today
 * it is weekday hours, breaks, blackout dates, and a horizon, and tomorrow it may
 * be seasonal hours or per-Resource exceptions. The planner should not change when
 * that happens.
 *
 * All resolution happens here, so the planner receives UTC integers and never
 * performs timezone arithmetic of its own.
 */
interface ResourceScheduleInterface {

  /**
   * Resolves one local date into a working day, or refuses it.
   *
   * @param \Drupal\custom_webform_handlers\Dispatch\ResourceProfile $profile
   *   The Resource whose rules apply.
   * @param string $localDate
   *   A Y-m-d date, read in the profile's timezone.
   *
   * @return \Drupal\custom_webform_handlers\Dispatch\ResourceDay|null
   *   The working day, or NULL when the date is malformed, outside the booking
   *   horizon, a closed weekday, a configured blackout, or has no usable working
   *   interval. A refusal is never partial: there is no such thing as a day that
   *   is open but unusable.
   */
  public function dayFor(ResourceProfile $profile, string $localDate): ?ResourceDay;

}
