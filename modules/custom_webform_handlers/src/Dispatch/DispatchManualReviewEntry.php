<?php

namespace Drupal\custom_webform_handlers\Dispatch;

use Drupal\custom_webform_handlers\Review\ReviewState;

/**
 * One quote a human has to price, waiting in the board's unscheduled queue.
 *
 * A Manual Review request is not a Reservation and must never be treated as one.
 * It has no Resource, no Dispatch Window, and consumes no capacity: the quote
 * engine gave up on it — a missing address, a route the provider refused, a
 * calculation that threw — and somebody has to look at it. Putting it on the time
 * lane would show capacity being spent by something that spends none.
 *
 * The fields are the minimum an operator needs to find the work: which
 * submission, which Webform, when it arrived, why it stalled, and which Review
 * Request owns it. No name, no address, no contact detail, no price. Reasons are
 * machine keys, filtered to a conservative shape before they reach here, so nothing
 * free-typed can ride into an administrative screen through the reason list.
 *
 * The review identity is what makes this specification item 6 rather than a second
 * queue. The board used to find this work by scanning submissions for a saved quote
 * status, which meant the thing an operator looked at and the thing that recorded
 * their decision were two lists that could disagree. Now the entry names the request,
 * so the row on the board and the authority over its lifecycle are the same object,
 * and the board's operations can be that request's own transitions.
 *
 * Immutable.
 */
final class DispatchManualReviewEntry {

  /**
   * The saved quote status this queue is built from.
   */
  public const STATUS_MANUAL_REVIEW = 'manual_review';

  /**
   * Constructs a Manual Review entry.
   *
   * @param int $submissionId
   *   The Webform submission that still needs pricing.
   * @param string $webformId
   *   The Webform it was submitted through.
   * @param int $submittedTimestamp
   *   When the submission was completed, in UTC. Falls back to its creation
   *   time when a legacy row never recorded a completion.
   * @param string $status
   *   The saved quote status, verbatim. Always the manual-review status.
   * @param string[] $reasons
   *   Machine reason keys, already filtered to a safe shape.
   * @param int $reviewId
   *   The Review Request this row is, and through which every decision on it is
   *   made. Always a real request: the queue is read from the requests themselves.
   * @param \Drupal\custom_webform_handlers\Review\ReviewState $reviewState
   *   Where the request has got to — waiting to be picked up, or picked up by a
   *   member of staff and being priced. Only those two reach a board queue, because
   *   an approved offer is waiting on its customer rather than on staff.
   */
  public function __construct(
    public readonly int $submissionId,
    public readonly string $webformId,
    public readonly int $submittedTimestamp,
    public readonly string $status,
    public readonly array $reasons,
    public readonly int $reviewId,
    public readonly ReviewState $reviewState,
  ) {
  }

}
