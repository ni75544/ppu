<?php

namespace Drupal\custom_webform_handlers\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Announces that a Review Request changed state, after the change has committed.
 *
 * Four scalars and no more. A listener that wants the submission's answers, the
 * customer's profile, or the offer's price loads them itself from the identifiers
 * carried here; none of that travels on the event. An event is delivered to
 * whatever happens to be listening, and the module's rule for the reaction layer
 * is that a background listener receives references, never the private data behind
 * them — the same discipline the queue payloads keep, for the same reason.
 *
 * Emitted only from ReviewManager::decide(), the one method that performs a checked
 * transition, and only once its compare-and-set has returned success — so a
 * listener may treat the transition as a fact that has happened, not a decision
 * still in flight. Expiry is deliberately not announced: the cron sweep bypasses
 * decide(), and a lapsed offer is not something any reaction downstream needs to
 * hear about.
 */
final class ReviewRequestTransitionedEvent extends Event {

  /**
   * The name this event is dispatched under.
   *
   * The ECA event plugin subscribes to this exact string, so it lives on the event
   * class where the emitter and the plugin can both name it without a literal
   * drifting between two files.
   */
  const EVENT_NAME = 'custom_webform_handlers.review_request_transitioned';

  public function __construct(
    public readonly int $reviewId,
    public readonly string $fromState,
    public readonly string $toState,
    public readonly int $submissionId,
  ) {}

}
