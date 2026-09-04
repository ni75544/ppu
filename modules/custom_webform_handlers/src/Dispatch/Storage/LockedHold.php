<?php

namespace Drupal\custom_webform_handlers\Dispatch\Storage;

use Drupal\custom_webform_handlers\Dispatch\Slot;

/**
 * A Hold as it exists on disk, handed to the planner inside the open lock.
 *
 * This is the finalization seam. Confirmation has to prove feasibility again while
 * the Resource Calendar Guard rows are still locked, and feasibility lives in the
 * planner, not in the store. So the store opens the transaction, takes the locks,
 * re-reads the Hold, and passes this object to a planner callback; the planner
 * answers with a reason, and the store performs the one write. Neither side learns
 * the other's mechanics: the planner sees no SQL and the store sees no routing.
 *
 * The Dispatch Window appears here as two instants rather than as a DispatchWindow,
 * because two instants are all the row actually knows. Pickup work, route, delivery
 * work, and the operational buffer are not stored separately, so building a
 * DispatchWindow here would mean inventing components. The planner recomputes the
 * window from current policy and compares the boundaries it derives with the
 * boundaries reserved; a mismatch is a changed plan and refuses.
 *
 * @internal
 *   Passed only from DatabaseReservationStore to a DispatchPlanner callback.
 *   Controllers and the Webform handler never receive it, and neither does any
 *   response: $reservationId is a database identifier and the intervals are
 *   another party's schedule detail.
 */
final readonly class LockedHold {

  /**
   * @param int $reservationId
   *   The row being finalized. Carried only so the planner can exclude this very
   *   claim from its own occupancy read — a Hold that conflicted with itself could
   *   never be confirmed. It must not leave the planner.
   * @param \Drupal\custom_webform_handlers\Dispatch\Slot $slot
   *   The Slot as stored, rebuilt from the persisted pickup arrival and timezone.
   * @param int $windowStart
   *   Reserved Dispatch Window start, in UTC. Equal to the Slot instant.
   * @param int $windowEnd
   *   Reserved Dispatch Window end, in UTC.
   * @param int $inboundRepositionSeconds
   *   Inbound repositioning space as reserved.
   * @param int $outboundRepositionSeconds
   *   Outbound repositioning space as reserved.
   * @param string $webformId
   *   The Webform the claim was made from.
   * @param int $expiresAt
   *   The expiry the locked read proved was still in the future.
   */
  public function __construct(
    public int $reservationId,
    public Slot $slot,
    public int $windowStart,
    public int $windowEnd,
    public int $inboundRepositionSeconds,
    public int $outboundRepositionSeconds,
    public string $webformId,
    public int $expiresAt,
  ) {}

}
