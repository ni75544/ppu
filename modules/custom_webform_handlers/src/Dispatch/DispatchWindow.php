<?php

namespace Drupal\custom_webform_handlers\Dispatch;

/**
 * The Resource's occupied interval for one Dispatch Request.
 *
 * Per CONTEXT.md this runs from the customer's pickup arrival through pickup
 * work, the route, delivery work, and the required operational buffer. It is
 * what actually consumes Resource capacity; the Slot is only its starting
 * instant.
 *
 * It is always derived server-side. A window that arrived from a browser is not
 * a window, and callers must never persist or trust one — the planner recomputes
 * it from the Dispatch Request and the Resource Profile every time.
 *
 * Repositioning is deliberately not part of this interval. Per CONTEXT.md it is
 * travel between one window's end and the next Reservation's pickup: it decides
 * feasibility but is not the customer-requested service, so it is not billed
 * into the window a customer sees.
 */
final readonly class DispatchWindow {

  /**
   * @param int $startTimestamp
   *   Pickup arrival, in UTC. Identical to the Slot's instant.
   * @param int $endTimestamp
   *   The instant the Resource becomes free, operational buffer included.
   * @param int $pickupWorkMinutes
   *   Time on site at pickup.
   * @param int $routeMinutes
   *   Server-derived travel time from pickup to delivery.
   * @param int $deliveryWorkMinutes
   *   Time on site at delivery.
   * @param int $operationalBufferMinutes
   *   Configured padding after delivery work.
   */
  public function __construct(
    public int $startTimestamp,
    public int $endTimestamp,
    public int $pickupWorkMinutes,
    public int $routeMinutes,
    public int $deliveryWorkMinutes,
    public int $operationalBufferMinutes,
  ) {}

  /**
   * Returns how long the Resource is occupied, in whole minutes.
   */
  public function durationMinutes(): int {
    return (int) max(0, ($this->endTimestamp - $this->startTimestamp) / 60);
  }

  /**
   * Tells whether this window overlaps another at all.
   *
   * Touching ends do not overlap: a window ending exactly when the next begins
   * is contiguous, which is what the operational buffer exists to make safe.
   */
  public function overlaps(DispatchWindow $other): bool {
    return $this->startTimestamp < $other->endTimestamp
      && $other->startTimestamp < $this->endTimestamp;
  }

}
