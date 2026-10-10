<?php

namespace Drupal\custom_webform_handlers\Dispatch;

/**
 * Everything the store needs to claim one Slot, derived by the planner.
 *
 * The division of labour matters. The planner derives every interval here —
 * the Dispatch Window from route and work time, and the Repositioning space on
 * either side of it — because that needs routing knowledge and configuration the
 * store has no business holding. The store then enforces the atomic read and
 * write sequence for those intervals. Neither half can do the other's job, and
 * the store never recomputes an interval it was given.
 *
 * Repositioning is expressed as required space rather than as a route to a
 * specific neighbour. The neighbours are whatever is in the schedule at the
 * instant the claim is attempted, which the planner cannot know when it offers a
 * Slot, so the claim reserves a protected margin and the store refuses anything
 * that intrudes into it. That is deliberately conservative: it can decline a
 * theoretically feasible sequence, and it cannot accept an infeasible one.
 */
final readonly class DispatchClaim {

  /**
   * @param \Drupal\custom_webform_handlers\Dispatch\Slot $slot
   *   The pickup-arrival option being claimed.
   * @param \Drupal\custom_webform_handlers\Dispatch\DispatchWindow $window
   *   The occupied interval, derived server-side.
   * @param int $inboundRepositionSeconds
   *   Space required before pickup arrival, covering travel from a previous
   *   delivery plus the configured repositioning buffer.
   * @param int $outboundRepositionSeconds
   *   Space required after the window ends, covering travel to the next pickup —
   *   or back to base when return-to-base is required — plus the buffer.
   * @param string $ownerId
   *   The raw anonymous-session identifier. Hashed by the store; never stored.
   * @param int $holdSeconds
   *   How long the resulting Hold lives.
   * @param string $pickupLocation
   *   Pickup location snapshot, kept verbatim.
   * @param string $deliveryLocation
   *   Delivery location snapshot, kept verbatim.
   * @param int|null $routeSeconds
   *   Route duration snapshot, when one is known.
   * @param int|null $routeMeters
   *   Route distance snapshot, when one is known.
   * @param string|null $priceSnapshotId
   *   Identifier of the quote this claim was made against, when available.
   * @param int|null $priceSnapshotCents
   *   Quoted amount in minor units, when available.
   * @param string|null $priceSnapshotCurrency
   *   Currency of the quoted amount, when available.
   * @param string|null $priceSnapshotVersion
   *   Pricing-policy version the quote was computed under, when available.
   * @param int|null $admissionGeneration
   *   Booking-admission generation this claim should persist (Task 24C-7A).
   *   Defaults to NULL, and every current business caller leaves it NULL: this is
   *   passive write transport, not an enrolment the planner performs.
   */
  public function __construct(
    public Slot $slot,
    public DispatchWindow $window,
    public int $inboundRepositionSeconds,
    public int $outboundRepositionSeconds,
    public string $ownerId,
    public int $holdSeconds,
    public string $pickupLocation = '',
    public string $deliveryLocation = '',
    public ?int $routeSeconds = NULL,
    public ?int $routeMeters = NULL,
    public ?string $priceSnapshotId = NULL,
    public ?int $priceSnapshotCents = NULL,
    public ?string $priceSnapshotCurrency = NULL,
    public ?string $priceSnapshotVersion = NULL,
    public ?int $admissionGeneration = NULL,
  ) {}

  /**
   * The earliest instant this claim needs the Resource free.
   *
   * Earlier than the Dispatch Window, by the inbound Repositioning space: a
   * previous delivery that ends inside this margin leaves no time to travel.
   */
  public function protectedStart(): int {
    return $this->window->startTimestamp - max(0, $this->inboundRepositionSeconds);
  }

  /**
   * The latest instant this claim needs the Resource free.
   */
  public function protectedEnd(): int {
    return $this->window->endTimestamp + max(0, $this->outboundRepositionSeconds);
  }

  /**
   * Every local service day the protected interval touches.
   *
   * Guards are locked per local day, so a window that runs past local midnight —
   * or whose repositioning does — must lock tomorrow as well. Locking only the
   * pickup date would leave the crossing hours unserialized, which is exactly
   * where two long jobs would collide.
   *
   * @return string[]
   *   Ascending, unique Y-m-d dates in the Slot's timezone.
   */
  public function guardDates(): array {
    $zone = $this->slot->timezoneObject();
    $first = (new \DateTimeImmutable('@' . $this->protectedStart()))->setTimezone($zone);
    $last = (new \DateTimeImmutable('@' . $this->protectedEnd()))->setTimezone($zone);

    $cursor = $first->setTime(0, 0, 0);
    $end = $last->setTime(0, 0, 0);
    $dates = [];
    // Bounded so a corrupt interval cannot spin: no legitimate single claim
    // spans anything like this many days.
    for ($i = 0; $i <= 370 && $cursor <= $end; $i++) {
      $dates[] = $cursor->format('Y-m-d');
      $cursor = $cursor->modify('+1 day');
    }

    return array_values(array_unique($dates));
  }

}
