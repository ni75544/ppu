<?php

namespace Drupal\custom_webform_handlers\Dispatch;

/**
 * A short-lived claim on one Slot, made while a visitor completes a form.
 *
 * Per CONTEXT.md a Hold expires automatically and is not a completed booking.
 * It carries the bearer token exactly once, on the way back to whoever created
 * it; nothing in this namespace stores, logs, or renders that token, and no
 * value object here should ever be handed to a template.
 */
final readonly class Hold {

  /**
   * @param \Drupal\custom_webform_handlers\Dispatch\Slot $slot
   *   The Slot this Hold claims.
   * @param string $token
   *   The bearer token proving ownership of the claim. Secret.
   * @param int $expiresAt
   *   UTC timestamp after which the claim is void without any further action.
   */
  public function __construct(
    public Slot $slot,
    public string $token,
    public int $expiresAt,
  ) {}

  /**
   * Tells whether the claim is still within its window at a given instant.
   *
   * Convenience only. It is not authority: the planner re-checks ownership and
   * expiry when the Hold is confirmed.
   */
  public function isLiveAt(int $now): bool {
    return $this->expiresAt > $now;
  }

}
