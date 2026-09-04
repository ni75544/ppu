<?php

namespace Drupal\custom_webform_handlers\Dispatch;

use Drupal\custom_webform_handlers\Dispatch\Storage\ReservationStoreInterface;

/**
 * The little that an administrative release operation may know about a claim.
 *
 * Release has to work on both kinds of live claim — a visitor's unfinished Hold
 * and a confirmed Reservation — and those two are allowed to disclose very
 * different amounts. A Reservation belongs to a customer the administrator is
 * handling, so its Webform and submission may be named. A Hold is an anonymous
 * attempt by somebody still filling a form in; per CONTEXT.md it may disclose
 * only the Resource, the interval it blocks, its state and when it expires.
 *
 * That difference is enforced by construction rather than by asking callers to
 * remember it. There are two factories and no public constructor, and the Hold
 * factory has no parameter for a Webform, a submission, a Dispatch Window or a
 * pickup arrival — so a Hold cannot be given one by a caller that was not paying
 * attention.
 *
 * The two secret columns have no property here either, so no form, template or
 * log line can render one however carelessly this object is used. Neither has
 * the price snapshot or either address: releasing capacity does not require
 * knowing what the job cost or where it was going.
 *
 * The timezone is present for both states, and that discloses nothing about a
 * customer: a dispatch claim is always stored in its Resource's own zone —
 * DispatchPlanner::normalisedSlot() rebuilds the Slot in that zone before any
 * claim is written — so it is a fact about the vehicle, not about whoever booked
 * it. It is here because an interval shown to an operator in the wrong zone is
 * worse than no interval at all.
 *
 * Immutable.
 */
final readonly class DispatchClaimSummary {

  /**
   * @param int $dispatchReservationId
   *   The claim's identifier.
   * @param string $resourceId
   *   The Resource whose capacity the claim consumes.
   * @param string $state
   *   The stored state, verbatim. Only a live claim is summarised at all, so
   *   this is the held or the confirmed state and never a terminal one.
   * @param int $occupiedStart
   *   First instant the claim removes from the Resource, repositioning included.
   * @param int $occupiedEnd
   *   Last instant the claim removes from the Resource, repositioning included.
   * @param string $timezone
   *   The Resource's IANA zone, for rendering the interval as an operator reads
   *   it.
   * @param int|null $expiresAt
   *   When a Hold lapses, or NULL for a Reservation, which does not expire.
   * @param int|null $pickupArrival
   *   The booked Slot instant. Confirmed claims only.
   * @param int|null $windowStart
   *   Start of the derived Dispatch Window. Confirmed claims only.
   * @param int|null $windowEnd
   *   End of the derived Dispatch Window. Confirmed claims only.
   * @param string|null $webformId
   *   The Webform the booking was made through. Confirmed claims only.
   * @param int|null $submissionId
   *   The submission behind the booking. Confirmed claims only.
   */
  private function __construct(
    public int $dispatchReservationId,
    public string $resourceId,
    public string $state,
    public int $occupiedStart,
    public int $occupiedEnd,
    public string $timezone,
    public ?int $expiresAt,
    public ?int $pickupArrival,
    public ?int $windowStart,
    public ?int $windowEnd,
    public ?string $webformId,
    public ?int $submissionId,
  ) {}

  /**
   * Summarises a confirmed Reservation, which may name its own booking.
   */
  public static function reservation(
    int $dispatchReservationId,
    string $resourceId,
    int $occupiedStart,
    int $occupiedEnd,
    string $timezone,
    int $pickupArrival,
    int $windowStart,
    int $windowEnd,
    string $webformId,
    ?int $submissionId,
  ): self {
    return new self(
      $dispatchReservationId,
      $resourceId,
      ReservationStoreInterface::STATE_CONFIRMED,
      $occupiedStart,
      $occupiedEnd,
      $timezone,
      // A Reservation does not expire. Only an administrative release or a
      // successful Replan ends one.
      NULL,
      $pickupArrival,
      $windowStart,
      $windowEnd,
      $webformId,
      $submissionId,
    );
  }

  /**
   * Summarises a live Hold: a Resource, a blocked interval, and an expiry.
   *
   * Everything a Hold is not allowed to disclose is absent from the signature,
   * so no caller can supply it.
   */
  public static function hold(
    int $dispatchReservationId,
    string $resourceId,
    int $occupiedStart,
    int $occupiedEnd,
    string $timezone,
    int $expiresAt,
  ): self {
    return new self(
      $dispatchReservationId,
      $resourceId,
      ReservationStoreInterface::STATE_HELD,
      $occupiedStart,
      $occupiedEnd,
      $timezone,
      $expiresAt,
      NULL,
      NULL,
      NULL,
      NULL,
      NULL,
    );
  }

  /**
   * Tells whether this is a Reservation rather than an unfinished Hold.
   */
  public function isConfirmed(): bool {
    return $this->state === ReservationStoreInterface::STATE_CONFIRMED;
  }

}
