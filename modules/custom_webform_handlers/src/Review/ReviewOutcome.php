<?php

namespace Drupal\custom_webform_handlers\Review;

/**
 * What a Review Request move may tell its caller, and nothing more.
 *
 * The same discipline CheckoutOutcome applies, for the same reason: a ReviewRequest
 * names the Dispatch row behind a scheduled job and the order behind a paid one, and
 * handing one to a form or a controller would make "do not publish internals" a
 * matter of each caller remembering which properties to skip. The conversion happens
 * once, in the manager, and this is what comes out of it.
 *
 * Staff callers get more than customer callers, and the difference is not enforced
 * here — it is enforced by which method the caller can reach. Staff forms hold a
 * permission and read the request through the manager's staff methods; the tracker
 * holds a credential and reads a ReviewStatus, which is a different, narrower value
 * object. This outcome exists for the write path, where the caller has already
 * proved it may write, so it carries the request itself on success.
 */
final readonly class ReviewOutcome {

  /**
   * @param bool $ok
   *   Whether the move was made.
   * @param \Drupal\custom_webform_handlers\Review\ReviewReason $reason
   *   ReviewReason::Moved on success, otherwise the stable refusal.
   * @param \Drupal\custom_webform_handlers\Review\ReviewRequest|null $request
   *   The request as it now stands, on success. On a refusal it is the request as
   *   it actually is, when one could be read — which is what lets a form say "this
   *   was already declined" instead of only "that is not allowed" — and NULL when
   *   there was nothing to read.
   */
  private function __construct(
    public bool $ok,
    public ReviewReason $reason,
    public ?ReviewRequest $request,
  ) {}

  /**
   * The move was made, and this is the request it produced.
   */
  public static function moved(ReviewRequest $request): self {
    return new self(TRUE, ReviewReason::Moved, $request);
  }

  /**
   * The move was refused, with the request as it stands when that is known.
   */
  public static function refused(ReviewReason $reason, ?ReviewRequest $request = NULL): self {
    return new self(FALSE, $reason === ReviewReason::Moved ? ReviewReason::InvalidRequest : $reason, $request);
  }

}
