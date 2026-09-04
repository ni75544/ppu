<?php

namespace Drupal\custom_webform_handlers\Review;

/**
 * The only thing that may move a Review Request.
 *
 * This interface is specification item 1's "one manager" made literal. Every legal
 * transition is a method here, no method takes a destination state as an argument,
 * and the implementation is the only caller of ReviewState::allows(). A form holds a
 * route permission and whatever somebody typed; it calls a method on this and is told
 * yes or no. ECA is given no service reference to it at all, which is how item 1's
 * "not in forms or ECA" is enforced rather than merely asked for: there is no
 * reaction rule that can set a state, because setting a state is not an operation
 * this module exposes.
 *
 * The methods are named for the decision, not for the destination. beginReview() is
 * not "move to under_review": it means a member of staff has taken the job, and the
 * state is a consequence. That is what keeps the audit trail readable — every row
 * records an intent somebody had, not a field somebody set.
 *
 * Two of the seven states are not reachable through any staff method here.
 * ReviewState::Scheduled is reachable only through recordScheduled(), which requires
 * the Dispatch row the guard already committed, so no member of staff can schedule a
 * job by deciding to. ReviewState::Expired is reachable only through expireLapsed(),
 * which takes no request identifier at all, so no operator can expire a named
 * request out of turn.
 */
interface ReviewManagerInterface {

  /**
   * Opens the request for a job the quote engine could not price.
   *
   * Idempotent by intent rather than by luck: one job has one review, enforced by a
   * unique index on the submission, and a second call for the same submission
   * returns the existing request rather than a second authority on it. The raw
   * tracking code is therefore minted exactly once, on the call that created the
   * row, and is returned only from that call — a caller asking again gets the
   * request and no credential, because the credential was already shown to its
   * customer and this module cannot show it twice.
   *
   * @param string $webformId
   *   The Webform the job came from.
   * @param int $submissionId
   *   The saved submission. Never a draft: a request nobody finished submitting is
   *   not work.
   *
   * @return \Drupal\custom_webform_handlers\Review\ReviewIssue|null
   *   The request and, when this call created it, the one raw code to show its
   *   customer. NULL when the request could neither be opened nor found, which the
   *   caller must treat as no review having been raised.
   */
  public function open(string $webformId, int $submissionId): ?ReviewIssue;

  /**
   * Records that a member of staff has taken this request.
   *
   * Legal from Submitted only. Two people opening the same job at once produce one
   * winner and one ReviewReason::Contended, so the queue cannot hand the same work
   * to two quoters.
   */
  public function beginReview(int $reviewId): ReviewOutcome;

  /**
   * Records an authoritative quote and offers it to the customer.
   *
   * The one method that writes a price, and it writes it in the same compare-and-set
   * that moves the state, so an approved request is never briefly approved and
   * unpriced. Refused with ReviewReason::QuoteRequired when the amount or the
   * currency is not a price.
   *
   * Approval consumes no capacity, holds no Slot, and creates no Reservation. That is
   * specification item 2, and it is true here by omission: this interface has no
   * access to the planner or the reservation store, so there is no capacity call an
   * implementation of it could make.
   *
   * @param int $reviewId
   *   The request.
   * @param int $amountCents
   *   The authoritative total, in minor units.
   * @param string $currency
   *   The three-letter currency code.
   * @param string $staffMessage
   *   What to tell the customer. Bounded and normalised before storage.
   */
  public function approve(int $reviewId, int $amountCents, string $currency, string $staffMessage): ReviewOutcome;

  /**
   * Records that staff will not do this work, or not on these terms.
   *
   * Legal from Submitted, UnderReview, and Approved — an offer may be withdrawn
   * before it is paid for.
   */
  public function decline(int $reviewId, string $staffMessage): ReviewOutcome;

  /**
   * Records that the request is no longer wanted.
   *
   * Legal from the same three states as decline(), and kept separate from it because
   * "we will not" and "never mind" are different facts about the same job and the
   * customer is told them differently.
   */
  public function cancel(int $reviewId, string $staffMessage): ReviewOutcome;

  /**
   * Records that this request's offer was paid for and capacity was confirmed.
   *
   * The only path to ReviewState::Scheduled, and the only method here that takes a
   * Dispatch Reservation identifier. It is called by the paid checkout flow after the
   * Resource Calendar Guard transaction has committed, so the Reservation it names
   * already exists; the manager records the fact and does not re-decide it. Refused
   * with ReviewReason::ReservationRequired when no Reservation is named, which is
   * what stops this becoming a way to schedule a job without capacity.
   *
   * @param int $reviewId
   *   The request.
   * @param int $orderId
   *   The Commerce order the offer was paid through.
   * @param int $dispatchReservationId
   *   The Dispatch row the guard confirmed.
   */
  public function recordScheduled(int $reviewId, int $orderId, int $dispatchReservationId): ReviewOutcome;

  /**
   * Lapses every request whose deadline has passed.
   *
   * Takes no identifier, so it cannot be aimed. Only states for which
   * ReviewState::mayExpire() is TRUE are swept, so a scheduled booking is never
   * expired out from under a customer who paid for it.
   *
   * @param int $limit
   *   The most requests to lapse in one run, so an unattended sweep is bounded.
   *
   * @return int
   *   How many were lapsed.
   */
  public function expireLapsed(int $limit = 50): int;

  /**
   * Reads one request for a member of staff who may see it.
   *
   * Access is the caller's to prove. This returns the request to anybody who asks,
   * because the only callers are routes that already carry a permission, and putting
   * a second access check here would mean two answers to who may read a review.
   */
  public function load(int $reviewId): ?ReviewRequest;

  /**
   * Reads the request for one job, or NULL when that job has none.
   */
  public function loadBySubmission(int $submissionId): ?ReviewRequest;

  /**
   * Reads the open work queue, oldest first.
   *
   * Open means a staff decision is outstanding — Submitted or UnderReview — so an
   * approved offer awaiting payment is not queued work. This is what the dispatch
   * board's Manual Review section is built from, per specification item 6: one queue,
   * read from the requests themselves.
   *
   * @param int $limit
   *   The most entries to return.
   *
   * @return \Drupal\custom_webform_handlers\Review\ReviewRequest[]
   *   Requests awaiting a decision, oldest first.
   */
  public function openQueue(int $limit = 100): array;

  /**
   * Answers a tracking code with the status of the one request it names.
   *
   * The code is hashed before it is matched and is never stored, logged, or returned.
   * A code that matches nothing and a code that is not shaped like a code produce the
   * identical NULL, so the endpoint cannot be used to learn whether a code exists —
   * which is specification item 4's non-disclosure requirement, answered in the one
   * place that could otherwise leak it.
   */
  public function trackByCode(string $rawCode): ?ReviewStatus;

  /**
   * Returns the request a tracking code may pay for, or NULL.
   *
   * The one method that hands a whole ReviewRequest to a caller holding a credential
   * rather than a permission, and it is deliberately the narrowest possible door.
   * Everything trackByCode() refuses, this refuses identically; on top of that it
   * refuses anything that is not an approved, priced offer still inside its deadline.
   * So the only request it will ever return is one whose single legitimate use is
   * DispatchCheckoutStarter::startFromApprovedReview().
   *
   * It exists because paying needs the offer itself and publishing needs a projection
   * of it, and one method cannot honestly be both. Keeping them apart is what lets the
   * customer-facing page go on being built from ReviewStatus — which publishes no
   * identifier at all — while the payment action still has something authoritative to
   * act on. The value object it returns cannot itself disclose anything: nothing
   * renders a ReviewRequest to a customer.
   *
   * The deadline is judged here as well as in the sweep, because the sweep runs on
   * cron and an offer must not become payable merely because a page was left open.
   */
  public function payableByCode(string $rawCode): ?ReviewRequest;

}
