<?php

namespace Drupal\custom_webform_handlers\Dispatch;

/**
 * How long the work at each end of one Dispatch Request takes.
 *
 * Separate from the route between them, and separate from the operational buffer
 * the Resource Profile adds afterwards. Keeping the three apart is what stops a
 * long drive from being mistaken for a long job, or a big move from inheriting a
 * courier's handling time because its addresses happen to be close together.
 *
 * There is no "unknown" state here on purpose. A duration policy that cannot
 * determine a figure returns NULL instead of constructing this with a guess, so
 * an unmapped fact can never quietly become zero minutes.
 */
final readonly class ServiceDuration {

  /**
   * @param int $pickupMinutes
   *   Work at the pickup location, before departing for delivery.
   * @param int $deliveryMinutes
   *   Work at the delivery location, after arriving.
   * @param int|null $operationalBufferMinutes
   *   A service-specific end-of-window buffer, or NULL to use the Resource
   *   Profile's. Carried here rather than read from the profile by the planner
   *   because the padding a job needs is a property of the job: an Express
   *   Courier window is short enough that a move's absolute buffer would be a
   *   large fraction of it.
   * @param int|null $promiseMinutes
   *   A service-specific pickup arrival promise width, or NULL to use the
   *   configured global one. Same reasoning: a two-hour promise on a
   *   twenty-minute courier job is not the same offer as on a full-day move.
   */
  public function __construct(
    public int $pickupMinutes,
    public int $deliveryMinutes,
    public ?int $operationalBufferMinutes = NULL,
    public ?int $promiseMinutes = NULL,
  ) {
    if ($pickupMinutes < 0 || $deliveryMinutes < 0) {
      throw new \InvalidArgumentException('Service durations cannot be negative.');
    }
    if ($operationalBufferMinutes !== NULL && $operationalBufferMinutes < 0) {
      throw new \InvalidArgumentException('An operational buffer cannot be negative.');
    }
    // Zero would promise arrival at an exact instant, which no route estimate
    // supports. An unset promise is NULL; a set one has width.
    if ($promiseMinutes !== NULL && $promiseMinutes < 1) {
      throw new \InvalidArgumentException('A pickup promise must be at least one minute wide.');
    }
  }

  /**
   * Returns the total on-site work, excluding the route between the two ends.
   */
  public function totalMinutes(): int {
    return $this->pickupMinutes + $this->deliveryMinutes;
  }

}
