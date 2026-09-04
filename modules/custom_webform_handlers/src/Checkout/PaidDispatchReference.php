<?php

namespace Drupal\custom_webform_handlers\Checkout;

/**
 * The narrow fact that one dispatch order has been paid for.
 *
 * This is what crosses the confirmation seam now, in place of a Webform
 * submission entity. The seam used to be reached one way only — a handler holding
 * the submission it had just saved — and that was enough while the form was the
 * only thing that could complete a booking. Payment changes who arrives: in Task
 * 6 a Commerce event subscriber will confirm, and an event subscriber holds an
 * order, not a form. Widening the seam to accept whichever object each caller
 * happens to be holding would give the confirmation path two shapes to trust.
 *
 * So the seam takes identifiers and audit facts, and the adapter resolves the
 * submission itself. That is deliberately not the same as moving the coupling one
 * level down: DispatchPlanner::confirmReservation() still takes the submission
 * entity, because it rebuilds the Dispatch Request from the saved row with
 * DispatchRequestFactory and re-proves feasibility under the guard locks before
 * capacity moves. It must keep that, and it must keep doing it from the
 * submission rather than from anything a caller passed, which is exactly why the
 * entity stays in its signature and stays out of this one.
 *
 * What is absent is the point. No amount: the attempt row holds the locked
 * snapshot, and a reference that carried its own figure could disagree with it.
 * No Slot: the attempt row holds that too, immutably, and a payment cannot be
 * allowed to name a different arrival than the one that was held. No contact
 * details, no token, no Reservation identifier.
 */
final readonly class PaidDispatchReference {

  /**
   * @param int $orderId
   *   The canonical Commerce order identifier.
   * @param int $submissionId
   *   The originating Webform submission identifier.
   * @param int $paidAt
   *   When payment was asserted, as a UTC timestamp.
   * @param string $evidence
   *   A short, stable machine name for what asserted payment — the Commerce event
   *   or gateway that did it. Audit only: it explains the confirmation in a log,
   *   and nothing in the confirmation path branches on it. Capacity is settled by
   *   the store transaction, so no string here can decide an outcome.
   */
  private function __construct(
    public int $orderId,
    public int $submissionId,
    public int $paidAt,
    public string $evidence,
  ) {}

  /**
   * Builds a reference, or NULL when it would not name a real payment.
   *
   * NULL rather than an exception: a malformed reference is refused input at a
   * seam a subscriber will call, and a subscriber cannot usefully catch anything.
   */
  public static function create(int $orderId, int $submissionId, int $paidAt, string $evidence): ?self {
    $evidence = trim($evidence);
    if ($orderId < 1 || $submissionId < 1 || $paidAt < 1 || $evidence === '') {
      return NULL;
    }

    if (preg_match('/^[a-z0-9_.:-]{1,128}$/', $evidence) !== 1) {
      return NULL;
    }

    return new self($orderId, $submissionId, $paidAt, $evidence);
  }

}
