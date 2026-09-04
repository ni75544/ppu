<?php

namespace Drupal\custom_webform_handlers\Dispatch\Policy;

use Drupal\custom_webform_handlers\Dispatch\DispatchRequest;
use Drupal\custom_webform_handlers\Dispatch\ServiceDuration;

/**
 * Decides how long the work at each end of a Dispatch Request takes.
 *
 * Deliberately knows nothing about routes, calendars, or capacity. It answers
 * only "how long is the work", so the planner can add the route and the buffers
 * around it. That separation is what prevents the category error this policy
 * exists to avoid: a move's duration comes from what is being moved and how the
 * building is accessed, never from how short the drive happens to be.
 *
 * A policy that cannot determine a figure returns NULL. It must never substitute
 * zero, because zero minutes of work schedules a driver to arrive and leave in
 * the same instant and silently overbooks the day.
 */
interface ServiceDurationPolicyInterface {

  /**
   * Returns the on-site durations for one request under one service class.
   *
   * @param \Drupal\custom_webform_handlers\Dispatch\DispatchRequest $request
   *   The normalized request.
   * @param string $serviceClass
   *   One of the EligibilityOutcome CLASS_* constants.
   *
   * @return \Drupal\custom_webform_handlers\Dispatch\ServiceDuration|null
   *   The durations, or NULL when a required fact is unknown or unmapped.
   */
  public function durationFor(DispatchRequest $request, string $serviceClass): ?ServiceDuration;

}
