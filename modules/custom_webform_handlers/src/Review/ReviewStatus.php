<?php

namespace Drupal\custom_webform_handlers\Review;

/**
 * Everything a tracking page may say about one request, and nothing else.
 *
 * This is the whole of what possession of a tracking code buys. It is built in the
 * manager from a ReviewRequest and, when the request is priced, that price — and it
 * is built by naming each publishable fact, never by filtering a fuller object. That
 * direction matters: a filter has to be updated every time a column is added, and
 * forgetting to is a disclosure. Here a new column publishes nothing until somebody
 * writes a line to publish it.
 *
 * What is deliberately absent, per specification item 9:
 *
 * - Any identity. No name, no email, no phone — not even the customer's own, because
 *   a tracking link may be forwarded, pasted into a ticket, or opened on a shared
 *   screen, and the page adds nothing by reciting details its reader already has.
 * - Any address or route. Not the pickup, not the delivery, not the distance.
 * - Every internal identifier: the review row, the submission, the Webform, the
 *   Dispatch Reservation, and the Commerce order. The order identifier is the one a
 *   payment action would need, and the action is published as a ready-made URL
 *   instead, so the page can send a customer to pay without ever printing the number
 *   they would need to guess at somebody else's.
 * - The driver, the Resource, and any schedule fact at all. A tracker says the work
 *   is scheduled; it does not say when the van is where, because a chain of tracking
 *   pages would otherwise reconstruct a day's route.
 * - The payment state in detail. "Awaiting payment" and "paid" are the customer's
 *   own facts. Whether an authorization was voided, whether a capture failed, and
 *   whether an operator is reconciling it are not, and reconciliation in particular
 *   must read as paid: the booking stands, and the money is staff's problem.
 *
 * The service summary and the staff message are the two free-text fields, and both
 * are rendered as plain text. The summary is composed from an allow-list in the
 * manager, so it is never a copy of a customer's answers.
 */
final readonly class ReviewStatus {

  /**
   * @param string $state
   *   The lifecycle state's stored value, which is a stable vocabulary a template
   *   may switch on. Safe to publish: it says what stage the request is at and
   *   nothing about who else has one.
   * @param string $headline
   *   One short sentence naming the stage, already translated.
   * @param string $detail
   *   What the customer should expect or do next, already translated.
   * @param string $serviceSummary
   *   A staff-safe description of the work, composed from an allow-list.
   * @param string $staffMessage
   *   The note staff wrote for this customer, normalised and bounded. Empty when
   *   none was written.
   * @param string|null $quote
   *   The authoritative price, already formatted for display, or NULL when there is
   *   no offer to show. Formatted rather than passed as cents so no page has to
   *   decide how money reads.
   * @param string|null $paymentUrl
   *   Where to go to pay, or NULL when there is nothing payable. An absolute URL to
   *   this request's own checkout, built in the manager, so the page never composes
   *   one from an order identifier it was not given.
   * @param int $updated
   *   When the request last changed, as a UTC timestamp. The one time value
   *   published, and it is about the request rather than about the schedule.
   */
  public function __construct(
    public string $state,
    public string $headline,
    public string $detail,
    public string $serviceSummary,
    public string $staffMessage,
    public ?string $quote,
    public ?string $paymentUrl,
    public int $updated,
  ) {}

  /**
   * Whether this status offers something to pay.
   */
  public function isPayable(): bool {
    return $this->paymentUrl !== NULL && $this->quote !== NULL;
  }

}
