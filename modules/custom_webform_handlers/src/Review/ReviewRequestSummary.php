<?php

namespace Drupal\custom_webform_handlers\Review;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * What a staff screen may show about one Review Request.
 *
 * Three forms decide the same request, and each one has to put the job in front of
 * an operator before asking them to decide it. Written three times, the header would
 * drift — and the thing that must not drift is which columns of a request each
 * screen is allowed to print. Specification item 8 splits disclosure into contact
 * detail, exact addresses, and money; the first two are not on a Review Request at
 * all, so the whole of item 8's reach into these screens is the money flag below,
 * and it is worth having exactly one file where that can be checked.
 *
 * Composed by naming each row, never by looping over a request's properties, for the
 * reason ReviewManager::publish() gives about the customer's projection: a column
 * added to the row later prints nothing until somebody writes a line here.
 *
 * The identifiers this does print — the review id, the Webform, the submission, and
 * a Reservation when there is one — are internal numbers shown to a member of staff
 * who already holds the pricing permission, and are the same class of thing the board
 * has always shown. Item 9 keeps them off public pages, and no customer-facing page
 * is built from this.
 */
final class ReviewRequestSummary {

  /**
   * The rows a staff decision screen puts above its buttons.
   *
   * @param \Drupal\custom_webform_handlers\Review\ReviewRequest $request
   *   The request being decided.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $dates
   *   Used for every timestamp, so a review reads in the same format as the rest of
   *   the administrative interface rather than in one this file invented.
   * @param bool $maySeeMoney
   *   Whether the account looking at this holds 'view dispatch financial details'.
   *   FALSE omits the quote and the order entirely rather than blanking them: a row
   *   reading "hidden" tells a reader a figure exists, which is itself a disclosure
   *   about the job.
   *
   * @return array[]
   *   Rows for a '#type' => 'table' element, each a label and a value.
   */
  public static function rows(ReviewRequest $request, DateFormatterInterface $dates, bool $maySeeMoney): array {
    $rows = [
      [new TranslatableMarkup('Request'), $request->reviewId],
      [
        new TranslatableMarkup('Job'),
        new TranslatableMarkup('@webform · submission @sid', [
          '@webform' => $request->webformId,
          '@sid' => $request->submissionId,
        ]),
      ],
      [new TranslatableMarkup('State'), self::state($request->state)],
      [new TranslatableMarkup('Arrived'), $dates->format($request->created, 'short')],
      [new TranslatableMarkup('Last change'), $dates->format($request->changed, 'short')],
      [new TranslatableMarkup('Last decided by'), self::decidedBy($request, $dates)],
    ];

    // The deadline is shown to whoever may decide, because it is what makes a decision
    // urgent, and because an operator who cannot see it will not understand why a
    // request they were looking at yesterday has lapsed.
    if ($request->expiresAt !== NULL) {
      $rows[] = [
        $request->state === ReviewState::Approved
          ? new TranslatableMarkup('Offer stands until')
          : new TranslatableMarkup('Lapses'),
        $dates->format($request->expiresAt, 'short'),
      ];
    }

    // A Reservation is capacity, not money, and it is only ever here on a request the
    // paid flow already scheduled. It is shown so a member of staff can tell a
    // scheduled job from an offer without going to the board.
    if ($request->dispatchReservationId !== NULL) {
      $rows[] = [new TranslatableMarkup('Reservation'), $request->dispatchReservationId];
    }

    if (!$maySeeMoney) {
      return $rows;
    }

    if ($request->hasQuote()) {
      $rows[] = [
        new TranslatableMarkup('Quote'),
        new TranslatableMarkup('@amount @currency', [
          '@amount' => number_format((int) $request->quoteAmountCents / 100, 2, '.', ','),
          '@currency' => (string) $request->quoteCurrency,
        ]),
      ];
    }

    if ($request->orderId !== NULL) {
      $rows[] = [new TranslatableMarkup('Order'), $request->orderId];
    }

    return $rows;
  }

  /**
   * Staff-facing wording for one state.
   *
   * ReviewState's case names are stable because they are stored and logged, and it
   * says the wording belonging to them is not defined there. This is that wording,
   * and it describes what is outstanding rather than restating the machine name: an
   * operator reading a board wants to know whose turn it is.
   */
  public static function state(ReviewState $state): TranslatableMarkup {
    return match ($state) {
      ReviewState::Submitted => new TranslatableMarkup('Waiting for somebody to take it'),
      ReviewState::UnderReview => new TranslatableMarkup('Under review — being priced'),
      ReviewState::Approved => new TranslatableMarkup('Offered to the customer, awaiting payment'),
      ReviewState::Scheduled => new TranslatableMarkup('Paid and scheduled'),
      ReviewState::Declined => new TranslatableMarkup('Declined'),
      ReviewState::Cancelled => new TranslatableMarkup('Cancelled'),
      ReviewState::Expired => new TranslatableMarkup('Lapsed — nobody decided in time'),
    };
  }

  /**
   * Who last decided this, and when.
   *
   * The stored name is used rather than a loaded account, so the audit trail still
   * reads correctly after the account has been renamed or deleted — which is the
   * reason ReviewManager records both columns in the first place.
   */
  private static function decidedBy(ReviewRequest $request, DateFormatterInterface $dates): TranslatableMarkup {
    if ($request->actorName === '' || $request->decidedAt === NULL) {
      return new TranslatableMarkup('Nobody yet');
    }

    return new TranslatableMarkup('@who, @when', [
      '@who' => $request->actorName,
      '@when' => $dates->format($request->decidedAt, 'short'),
    ]);
  }

}
