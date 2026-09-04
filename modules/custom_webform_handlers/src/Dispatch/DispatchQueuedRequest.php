<?php

namespace Drupal\custom_webform_handlers\Dispatch;

/**
 * The little a staff confirmation screen may know about a waiting request.
 *
 * A request held for Manual Review is a completed Webform submission that the
 * quote engine would not price and that consumes no capacity. Booking one is a
 * deliberate staff act, so the screen that does it has to show enough to identify
 * the work and no more: which submission, which Webform, when it arrived, and
 * whether something already holds capacity for it.
 *
 * The submission entity itself is deliberately not what travels here. It carries
 * every answer the customer typed — names, both addresses, contact details — and a
 * form that held one would eventually render one. So the projection is made once,
 * inside the operations seam, and the form receives only these four facts.
 *
 * The fourth is the interesting one. The same waiting job can sit open in two
 * browser tabs, or be picked up by two dispatchers who both saw it in the queue.
 * The planner refuses the second attempt on its own — that is what AlreadyBooked
 * is for — but a screen that can see the claim beforehand can say so before
 * anybody types a time, and can link to the booking that already exists rather
 * than inviting a duplicate. The identifier is all that is disclosed: enough to
 * know something is there, not enough to read a claim the operator was not shown.
 *
 * Immutable.
 */
final readonly class DispatchQueuedRequest {

  /**
   * @param int $submissionId
   *   The waiting submission.
   * @param string $webformId
   *   The Webform it arrived through.
   * @param int $submittedTimestamp
   *   When it was completed, in UTC. Falls back to its creation time when a
   *   legacy row never recorded a completion.
   * @param int|null $liveClaimId
   *   A claim already holding capacity for this submission, or NULL when none
   *   does. Released, expired, cancelled and superseded rows hold nothing, so a
   *   job whose earlier booking was cancelled reads as free to book again.
   */
  public function __construct(
    public int $submissionId,
    public string $webformId,
    public int $submittedTimestamp,
    public ?int $liveClaimId,
  ) {}

  /**
   * Tells whether this request already has capacity of its own.
   */
  public function isAlreadyClaimed(): bool {
    return $this->liveClaimId !== NULL;
  }

}
