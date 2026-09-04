<?php

namespace Drupal\custom_webform_handlers\Maintenance;

/**
 * The narrow seam an ECA action calls to hand background work to the queue.
 *
 * Requirement 4 limits what an ECA action may do to "narrow public module
 * Interfaces only" — an action must not write a planner table, a Reservation, a
 * Checkout Attempt, a payment entity, or Webform submission data. This interface is
 * that seam for the two maintenance reactions: it enqueues, and enqueuing is the
 * whole of it. Nothing reachable through here decides capacity, moves money, or
 * changes availability; a queued job is never any of those authorities, and this is
 * where that boundary is drawn for the reaction layer.
 *
 * Both methods are total and silent by design. A queue that is unavailable, a
 * duplicate that is collapsed, an Advanced Queue that is not installed — none of
 * these is the caller's problem, because none of them may be allowed to fail an
 * authoritative transaction that has already committed. The implementation swallows
 * and logs; the caller enqueues and returns.
 */
interface MaintenanceQueueInterface {

  /**
   * Asks for one Resource's day to be route-warmed, off the request.
   *
   * @param string $resourceId
   *   The Resource whose day to warm.
   * @param string $date
   *   The local date to warm, as Y-m-d in the Resource's own timezone.
   */
  public function enqueueRouteWarm(string $resourceId, string $date): void;

  /**
   * Asks for the Service Type popularity snapshot to be recomputed, off the request.
   */
  public function enqueuePopularityRecount(): void;

}
