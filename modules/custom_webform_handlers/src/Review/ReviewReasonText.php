<?php

namespace Drupal\custom_webform_handlers\Review;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Staff-facing wording for the review manager's stable refusal reasons.
 *
 * ReviewReason keeps its names stable because they are logged, and says explicitly
 * that the wording belonging to them is not defined there. This is that wording, for
 * one audience: a member of staff who pressed a button on the board and was told no,
 * and needs to know whether to correct what they typed, reload and look at the row,
 * or fetch somebody who can.
 *
 * It is the counterpart of DispatchReasonText and is kept separate for the same
 * reason the two enums are: an operator reading "another booking owns that time"
 * beside "somebody else already declined this" would be reading two different kinds
 * of no in the same voice. The division is by vocabulary, not by screen.
 *
 * There is no customer sentence anywhere in this file, and there must not be. What a
 * customer reads is built by the manager from the state a request is in, never from
 * the reason a staff move was refused: several of these reasons say something about
 * how the site is behaving internally, and one of them — Contended — says something
 * about a colleague. The tracker publishes states; this explains refusals.
 *
 * No refusal names another customer, a price, or an address. Where the row itself is
 * what needs looking at, the sentence says so and stops.
 */
final class ReviewReasonText {

  /**
   * Explains one refusal to a member of staff.
   */
  public static function staff(ReviewReason $reason): TranslatableMarkup {
    return match ($reason) {
      ReviewReason::Moved => new TranslatableMarkup('The request was updated.'),

      // The row is not where the operator thought it was. Reloading is the remedy
      // for all of these, and the form in front of them is the wrong form now.
      ReviewReason::NotFound => new TranslatableMarkup('That review request is no longer there to act on. Reload the board to see what it holds now.'),
      // Covers both directions, because the state machine refuses both with the one
      // reason: a decision made too late, and one made too early. Naming only "already
      // decided" would mislead a quoter who has simply not taken the job yet.
      ReviewReason::IllegalTransition => new TranslatableMarkup('That decision is not available from the state this request is in — it may have been decided already, or it may need taking under review first. Reload it and look at where it stands.'),
      ReviewReason::Contended => new TranslatableMarkup('Somebody else decided this request a moment before you did, so your decision was not recorded. Reload it to see theirs.'),
      ReviewReason::AlreadyOpen => new TranslatableMarkup('This job already has a review of its own. Work on the one it has rather than raising a second.'),

      // What somebody typed, or did not type. The state was right and the input was
      // not, so the same form is still the right place to be.
      ReviewReason::QuoteRequired => new TranslatableMarkup('Approving an offer needs a total for the customer to pay. Enter the amount and the currency, then approve.'),
      ReviewReason::InvalidRequest => new TranslatableMarkup('That could not be read as a decision, so nothing was changed.'),

      // Not reachable from any staff form, and worded as the invariant it protects
      // rather than as something an operator can fix by trying harder.
      ReviewReason::ReservationRequired => new TranslatableMarkup('A request becomes scheduled only when a paid booking has confirmed capacity for it. It cannot be scheduled by deciding to.'),

      // A fault, not a decision. Nothing was written, so the offer or the queue entry
      // is exactly as it was before the button was pressed.
      ReviewReason::StorageFailed => new TranslatableMarkup('That decision could not be recorded, so nothing was changed. Try again, and tell somebody if it keeps happening.'),
    };
  }

  /**
   * Tells whether the form the operator is looking at is still the right one.
   *
   * A refusal is not one kind of thing. Some say the input was wrong, or that nothing
   * was written at all — in those the request is still in the state the operator
   * believed, so keeping the form open lets them fix it and press again. The rest say
   * the row itself has moved, been decided by a colleague, or gone, and keeping a
   * stale form open for those invites a second attempt that must fail the same way.
   * Screens use this to choose between a rebuild and a redirect to the board.
   */
  public static function staffCanFixHere(ReviewReason $reason): bool {
    return match ($reason) {
      ReviewReason::QuoteRequired,
      ReviewReason::InvalidRequest,
      ReviewReason::StorageFailed => TRUE,
      default => FALSE,
    };
  }

}
