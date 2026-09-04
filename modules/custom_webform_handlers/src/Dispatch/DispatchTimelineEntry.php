<?php

namespace Drupal\custom_webform_handlers\Dispatch;

/**
 * One administrator-safe segment of a Resource's timeline.
 *
 * The Dispatch board answers a scheduling question — what occupies this
 * Resource, and when — so a segment carries only what that question needs. It is
 * built exclusively by the reservation-store adapter, and never carries a Hold
 * token, an owner hash, or a raw database row.
 *
 * How much a segment may say depends on what it is. A confirmed Reservation is a
 * booking the administrator owns, so it names its Webform, its submission, and
 * the Dispatch Window it occupies. A Hold is an anonymous visitor's unfinished
 * attempt, so it reports only the blocked span and when that span lapses: no
 * address, no Webform, no submission, no price. Each kind is built through its
 * own factory, so a Hold cannot be handed a field it may not carry.
 *
 * Immutable.
 */
final class DispatchTimelineEntry {

  /**
   * A confirmed Reservation.
   */
  public const TYPE_RESERVATION = 'reservation';

  /**
   * A Hold that has not yet expired.
   */
  public const TYPE_HOLD = 'hold';

  /**
   * An active administrative Blackout.
   */
  public const TYPE_BLACKOUT = 'blackout';

  /**
   * Constructs a timeline entry.
   *
   * @param string $type
   *   One of the TYPE_* constants: what the board renders this segment as.
   * @param string $resourceId
   *   The Resource whose timeline the segment belongs to.
   * @param int $startTimestamp
   *   Start of the segment in UTC. For a Reservation this is the Dispatch
   *   Window; for a Hold it is the whole blocked span, repositioning included,
   *   because a Hold may not disclose how its time breaks down.
   * @param int $endTimestamp
   *   End of the segment in UTC, on the same basis as $startTimestamp.
   * @param string|null $state
   *   The stored claim state, verbatim. NULL for a Blackout, which has none.
   * @param int|null $dispatchReservationId
   *   Identifier of a confirmed Reservation, for linking to its safe detail.
   * @param string|null $webformId
   *   The Webform a confirmed Reservation was booked through.
   * @param int|null $submissionId
   *   The submission behind a confirmed Reservation, when one was saved.
   * @param int|null $pickupArrival
   *   The Slot instant a confirmed Reservation was booked for, in UTC.
   * @param string|null $timezone
   *   The zone the visitor read that instant in. Never reinterpreted.
   * @param int $inboundRepositionSeconds
   *   Repositioning a confirmed Reservation needs before its window.
   * @param int $outboundRepositionSeconds
   *   Repositioning a confirmed Reservation needs after its window.
   * @param int|null $expiresAt
   *   When a Hold lapses, in UTC.
   * @param string|null $reason
   *   Why a Blackout was recorded.
   */
  private function __construct(
    public readonly string $type,
    public readonly string $resourceId,
    public readonly int $startTimestamp,
    public readonly int $endTimestamp,
    public readonly ?string $state = NULL,
    public readonly ?int $dispatchReservationId = NULL,
    public readonly ?string $webformId = NULL,
    public readonly ?int $submissionId = NULL,
    public readonly ?int $pickupArrival = NULL,
    public readonly ?string $timezone = NULL,
    public readonly int $inboundRepositionSeconds = 0,
    public readonly int $outboundRepositionSeconds = 0,
    public readonly ?int $expiresAt = NULL,
    public readonly ?string $reason = NULL,
  ) {
  }

  /**
   * Builds the segment for a confirmed Reservation.
   */
  public static function reservation(
    string $resourceId,
    string $state,
    int $dispatchReservationId,
    string $webformId,
    ?int $submissionId,
    int $pickupArrival,
    string $timezone,
    int $windowStart,
    int $windowEnd,
    int $inboundRepositionSeconds,
    int $outboundRepositionSeconds
  ): self {
    return new self(
      self::TYPE_RESERVATION,
      $resourceId,
      $windowStart,
      $windowEnd,
      $state,
      $dispatchReservationId,
      $webformId,
      $submissionId,
      $pickupArrival,
      $timezone,
      $inboundRepositionSeconds,
      $outboundRepositionSeconds,
    );
  }

  /**
   * Builds the segment for a Hold that has not expired.
   *
   * @param string $resourceId
   *   The Resource the Hold blocks.
   * @param string $state
   *   The stored claim state, verbatim.
   * @param int $blockedStart
   *   Start of the blocked span in UTC, repositioning already folded in.
   * @param int $blockedEnd
   *   End of the blocked span in UTC, repositioning already folded in.
   * @param int|null $expiresAt
   *   When the Hold lapses, in UTC.
   *
   * @return self
   *   A segment that reveals blocked time and expiry, and nothing further.
   */
  public static function hold(string $resourceId, string $state, int $blockedStart, int $blockedEnd, ?int $expiresAt): self {
    return new self(self::TYPE_HOLD, $resourceId, $blockedStart, $blockedEnd, $state, expiresAt: $expiresAt);
  }

  /**
   * Builds the segment for an active Blackout.
   */
  public static function blackout(string $resourceId, int $startAt, int $endAt, string $reason): self {
    return new self(self::TYPE_BLACKOUT, $resourceId, $startAt, $endAt, reason: $reason);
  }

  /**
   * Start of the time this segment really removes from the Resource, in UTC.
   */
  public function occupiedStart(): int {
    return $this->startTimestamp - $this->inboundRepositionSeconds;
  }

  /**
   * End of the time this segment really removes from the Resource, in UTC.
   */
  public function occupiedEnd(): int {
    return $this->endTimestamp + $this->outboundRepositionSeconds;
  }

}
