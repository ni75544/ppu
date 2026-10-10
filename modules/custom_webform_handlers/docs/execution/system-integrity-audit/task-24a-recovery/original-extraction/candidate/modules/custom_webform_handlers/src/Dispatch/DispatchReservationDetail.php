<?php

namespace Drupal\custom_webform_handlers\Dispatch;

/**
 * Administrator-safe detail for one confirmed Reservation.
 *
 * What the board's detail page is allowed to know about a booking. The snapshot
 * fields are reproduced verbatim, because a Reservation records what was true
 * when the claim was made and a later configuration change must not rewrite it.
 *
 * The two secret columns are absent by construction. Neither the Hold token hash
 * nor the owner hash has a property here, so no controller, template, or JSON
 * response can leak one however carelessly this object is rendered.
 *
 * A Hold has no detail. It is an anonymous visitor's unfinished attempt, so the
 * store answers NULL for one rather than describing it.
 *
 * Immutable.
 */
final class DispatchReservationDetail {

  /**
   * Constructs Reservation detail.
   *
   * @param int $dispatchReservationId
   *   The claim's identifier.
   * @param string $resourceId
   *   The Resource that carries the claim.
   * @param string $state
   *   The stored claim state, verbatim. Always the confirmed state.
   * @param string $webformId
   *   The Webform the booking was made through.
   * @param int|null $submissionId
   *   The submission behind the booking, when one was saved.
   * @param int $pickupArrival
   *   The Slot instant the visitor booked, in UTC.
   * @param string $timezone
   *   The zone the visitor read that instant in. Never reinterpreted.
   * @param int $windowStart
   *   Start of the derived Dispatch Window, in UTC.
   * @param int $windowEnd
   *   End of the derived Dispatch Window, in UTC.
   * @param int $inboundRepositionSeconds
   *   Repositioning space reserved before the window.
   * @param int $outboundRepositionSeconds
   *   Repositioning space reserved after the window.
   * @param string $pickupLocation
   *   Pickup address snapshot. This customer's own address, for the
   *   administrator handling this booking — never another customer's, which is
   *   why planning neighbours stay behind the planner seam.
   * @param string $deliveryLocation
   *   Delivery address snapshot, on the same basis as $pickupLocation.
   * @param int|null $routeSeconds
   *   Routed duration snapshot, when one was stored.
   * @param int|null $routeMeters
   *   Routed distance snapshot, when one was stored.
   * @param string|null $priceSnapshotId
   *   Identifier of the price snapshot the booking was priced against.
   * @param int|null $priceSnapshotCents
   *   Quoted amount in minor units, as quoted.
   * @param string|null $priceSnapshotCurrency
   * @param string|null $priceSnapshotVersion
   *   Pricing-policy version snapshot.
   *   Currency of the quoted amount.
   * @param int $created
   *   When the row was first written, in UTC.
   * @param int $changed
   *   When the row was last written, in UTC.
   */
  public function __construct(
    public readonly int $dispatchReservationId,
    public readonly string $resourceId,
    public readonly string $state,
    public readonly string $webformId,
    public readonly ?int $submissionId,
    public readonly int $pickupArrival,
    public readonly string $timezone,
    public readonly int $windowStart,
    public readonly int $windowEnd,
    public readonly int $inboundRepositionSeconds,
    public readonly int $outboundRepositionSeconds,
    public readonly string $pickupLocation,
    public readonly string $deliveryLocation,
    public readonly ?int $routeSeconds,
    public readonly ?int $routeMeters,
    public readonly ?string $priceSnapshotId,
    public readonly ?int $priceSnapshotCents,
    public readonly ?string $priceSnapshotCurrency,
    public readonly ?string $priceSnapshotVersion,
    public readonly int $created,
    public readonly int $changed,
  ) {
  }

  /**
   * Start of the time this Reservation really removes from the Resource.
   */
  public function occupiedStart(): int {
    return $this->windowStart - $this->inboundRepositionSeconds;
  }

  /**
   * End of the time this Reservation really removes from the Resource.
   */
  public function occupiedEnd(): int {
    return $this->windowEnd + $this->outboundRepositionSeconds;
  }

}
