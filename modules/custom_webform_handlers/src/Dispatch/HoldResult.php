<?php

namespace Drupal\custom_webform_handlers\Dispatch;

/**
 * The outcome of trying to claim a Slot.
 *
 * A refusal is a normal competitive outcome, not an error, so it is returned
 * rather than thrown: two visitors deriving the same Slot is expected, and
 * exactly one of them must lose.
 */
final readonly class HoldResult {

  /**
   * @param \Drupal\custom_webform_handlers\Dispatch\Hold|null $hold
   *   The claim when one was made, otherwise NULL.
   * @param \Drupal\custom_webform_handlers\Dispatch\DispatchReason $reason
   *   Available on success, otherwise why the claim was refused.
   */
  private function __construct(
    public ?Hold $hold,
    public DispatchReason $reason,
  ) {}

  /**
   * The Slot was claimed.
   */
  public static function held(Hold $hold): self {
    return new self($hold, DispatchReason::Available);
  }

  /**
   * The Slot was not claimed, and this is why.
   */
  public static function refused(DispatchReason $reason): self {
    return new self(NULL, $reason);
  }

  /**
   * Tells whether a claim exists.
   */
  public function isHeld(): bool {
    return $this->hold !== NULL;
  }

}
