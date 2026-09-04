<?php

namespace Drupal\custom_webform_handlers\Dispatch\Storage;

use Drupal\custom_webform_handlers\Dispatch\DispatchWindow;
use Drupal\custom_webform_handlers\Dispatch\Slot;

/**
 * Everything the store needs to move one Reservation, derived by the planner.
 *
 * The same division of labour as DispatchClaim, for the same reason: the planner
 * derives every interval here because that needs routing knowledge and
 * configuration the store has no business holding, and the store then enforces
 * the atomic read-and-write sequence for those intervals. The store never
 * recomputes an interval it was given, and it never accepts one from anywhere
 * else — in particular, never from a browser. A Dispatch Window that arrived over
 * HTTP is not a Dispatch Window.
 *
 * Two things are deliberately absent.
 *
 * There is no owner and no hold lifetime, because a Replan does not create a
 * claim anybody owns for a while: the new row is confirmed from the instant it is
 * written, exactly as the row it replaces was, and it does not expire.
 *
 * There is no price. A Reservation records what was quoted when the customer
 * booked, and moving the van does not requote the job — so the store copies the
 * price snapshot from the row being superseded, verbatim, and this object has no
 * field through which a caller could change it.
 *
 * The submission identifier is carried so the store can prove, under its own
 * locks, that the row it is about to move is still the row that belongs to the
 * job the planner proved. A caller that loaded one Reservation and then asked to
 * move another is refused rather than obeyed.
 */
final readonly class DispatchReplan {

  /**
   * @param int $reservationId
   *   The confirmed Reservation being moved.
   * @param int $submissionId
   *   The submission that Reservation must still belong to.
   * @param \Drupal\custom_webform_handlers\Dispatch\Slot $slot
   *   The new pickup arrival, already normalised against its Resource.
   * @param \Drupal\custom_webform_handlers\Dispatch\DispatchWindow $window
   *   The new occupied interval, derived server-side.
   * @param int $inboundRepositionSeconds
   *   Space required before the new window opens.
   * @param int $outboundRepositionSeconds
   *   Space required after the new window closes.
   * @param string $pickupLocation
   *   Pickup location the new plan was proved against, kept verbatim. Taken from
   *   the submission rather than the old row: if the customer's address changed,
   *   the route that was just measured is the route to the new one, and other
   *   jobs are sequenced against these addresses.
   * @param string $deliveryLocation
   *   Delivery location, on the same basis.
   * @param int|null $routeSeconds
   *   Freshly measured route duration, when one is known.
   * @param int|null $routeMeters
   *   Freshly measured route distance, when one is known.
   */
  public function __construct(
    public int $reservationId,
    public int $submissionId,
    public Slot $slot,
    public DispatchWindow $window,
    public int $inboundRepositionSeconds,
    public int $outboundRepositionSeconds,
    public string $pickupLocation = '',
    public string $deliveryLocation = '',
    public ?int $routeSeconds = NULL,
    public ?int $routeMeters = NULL,
  ) {}

  /**
   * The earliest instant the moved Reservation needs the Resource free.
   */
  public function protectedStart(): int {
    return $this->window->startTimestamp - max(0, $this->inboundRepositionSeconds);
  }

  /**
   * The latest instant the moved Reservation needs the Resource free.
   */
  public function protectedEnd(): int {
    return $this->window->endTimestamp + max(0, $this->outboundRepositionSeconds);
  }

  /**
   * Every local service day the new protected interval touches.
   *
   * The store unions these with the days the row being superseded touches, and
   * locks the whole union in one transaction. Locking only the destination would
   * leave the origin unserialized, which is precisely where a competing claim
   * would slip into the space this move is about to vacate.
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
    // Bounded so a corrupt interval cannot spin, exactly as on DispatchClaim.
    for ($i = 0; $i <= 370 && $cursor <= $end; $i++) {
      $dates[] = $cursor->format('Y-m-d');
      $cursor = $cursor->modify('+1 day');
    }

    return array_values(array_unique($dates));
  }

}
