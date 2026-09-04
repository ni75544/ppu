<?php

namespace Drupal\custom_webform_handlers\Dispatch;

/**
 * The outcome of withholding a span of a Resource's time from booking.
 *
 * A Blackout is refused rather than forced when live claims already occupy the
 * span. Writing it anyway would produce a schedule that is internally impossible
 * — a vehicle simultaneously booked and unavailable — and the whole point of the
 * planner is that such a schedule cannot be created by any path. So the refusal
 * carries the identifiers of the claims that are in the way, and the operator
 * releases or replans them first, deliberately, one at a time.
 *
 * Those identifiers are the only detail a refusal discloses, and they are already
 * on the board beside the interval the operator was looking at when they asked
 * for the Blackout. No address, no Webform, no submission, no customer, no price:
 * knowing that reservation 412 is in the way is enough to go and deal with
 * reservation 412.
 *
 * Immutable.
 */
final readonly class BlackoutResult {

  /**
   * @param int|null $blackoutId
   *   The Blackout now in force, or NULL when none was written.
   * @param int|null $withdrewBlackoutId
   *   The Blackout this one replaced, when the operation was a revision.
   * @param int[] $conflictingReservationIds
   *   Live claims occupying the span, when that is why it was refused.
   * @param \Drupal\custom_webform_handlers\Dispatch\DispatchReason $reason
   *   Available on success, otherwise why the span was not withheld.
   */
  private function __construct(
    public ?int $blackoutId,
    public ?int $withdrewBlackoutId,
    public array $conflictingReservationIds,
    public DispatchReason $reason,
  ) {}

  /**
   * The span is now withheld.
   */
  public static function scheduled(int $blackoutId, ?int $withdrewBlackoutId = NULL): self {
    return new self($blackoutId, $withdrewBlackoutId, [], DispatchReason::Available);
  }

  /**
   * The span was not withheld, and this is why. Nothing was written.
   */
  public static function refused(DispatchReason $reason, array $conflictingReservationIds = []): self {
    $ids = [];
    foreach ($conflictingReservationIds as $id) {
      $id = (int) $id;
      if ($id > 0) {
        $ids[$id] = $id;
      }
    }
    sort($ids);

    return new self(NULL, NULL, array_values($ids), $reason);
  }

  /**
   * Tells whether the span is now withheld.
   */
  public function isScheduled(): bool {
    return $this->blackoutId !== NULL;
  }

}
