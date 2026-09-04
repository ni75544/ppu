<?php

namespace Drupal\custom_webform_handlers\Review;

/**
 * A newly opened Review Request together with the one raw code for its customer.
 *
 * This pair exists so that the raw credential has exactly one way out of the
 * manager, and it is a return value rather than a property of ReviewRequest. That
 * is the difference between a secret that is handed to one caller once and a secret
 * that every reader of a request happens to have: ReviewRequest is loaded on the
 * staff board, in the queue, on the tracker, and from cron, and none of those may
 * see a code. Only open() returns this, and only when it created the row.
 *
 * The code is nullable for that reason. A second open() for a job that already has a
 * review returns the existing request with no code attached, because the code was
 * shown to its customer when the request was raised and is not recoverable — the
 * stored form is an HMAC. A caller receiving NULL here has not failed; it has learned
 * that there was nothing new to tell anyone.
 */
final readonly class ReviewIssue {

  public function __construct(
    public ReviewRequest $request,
    public ?string $rawCode,
  ) {}

  /**
   * Whether this call is the one that may show a customer their code.
   */
  public function isNew(): bool {
    return $this->rawCode !== NULL && $this->rawCode !== '';
  }

}
