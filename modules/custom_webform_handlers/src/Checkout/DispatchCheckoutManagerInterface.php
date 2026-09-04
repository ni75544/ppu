<?php

namespace Drupal\custom_webform_handlers\Checkout;

use Drupal\custom_webform_handlers\Dispatch\Slot;
use Drupal\webform\WebformSubmissionInterface;

/**
 * The three things that can happen to a private Checkout Attempt.
 *
 * Payment begins, payment succeeds, or the customer goes away. There is no fourth
 * use case here on purpose: anything else a caller might want — reading a
 * Reservation, renewing a Hold, moving a booking — already belongs to a seam that
 * exists, and adding it here would make this the second place that can reach
 * capacity. Per CONTEXT.md capacity is resolved inside the Resource Calendar
 * Guard transaction, so the implementation of these three composes the existing
 * planner and store rather than deciding anything itself.
 *
 * An interface for one implementation, which this module does not normally do. It
 * is here because Task 6's Commerce event subscriber must be able to depend on
 * the confirmation use case without depending on the Commerce adapter that
 * performs it — the subscriber is on Commerce's side of the seam, and the adapter
 * is the seam.
 *
 * On the shape of start(). The roadmap names its inputs as the request, the
 * selected Slot, and the contact, and two of those are expressed differently
 * here for reasons worth stating.
 *
 * The request arrives as the saved Webform submission rather than as a
 * DispatchRequest, because DispatchRequestFactory is the module's one normalizer
 * of Webform and customer facts, and the implementation calls it. A caller that
 * passed a pre-built DispatchRequest could pass one that disagrees with the row —
 * a lighter package, a nearer address — and the request that reaches the planner
 * must be the one the saved submission actually supports. This is the same rule
 * DispatchPlanner::confirmReservation() already follows when it rebuilds the
 * request from the submission instead of trusting the Hold.
 *
 * The locked amount is a fourth input rather than something the implementation
 * derives, because the authoritative Express Courier price is Task 4's work and
 * does not exist yet. It is a QuoteSnapshot rather than a number, and a
 * QuoteSnapshot has no public constructor: the only way to make one is from the
 * server's own QuoteResult. So a browser cannot set an order amount by any route,
 * including this one, and no controller has to be trusted to check.
 */
interface DispatchCheckoutManagerInterface {

  /**
   * Begins payment for one job at one Slot, claiming capacity as it goes.
   *
   * The implementation consults BookingWorkflowGate itself and refuses unless the
   * submission's own Webform is assigned to the Dispatch workflow. It does not
   * accept a caller's assurance that the check was already made: the gate is the
   * sole answer to that question, the transfer list is shipped empty, and a
   * checkout that could be started by a controller forgetting to ask is a
   * checkout that can hold a driver's day for a form nobody transferred.
   *
   * On refusal nothing has happened: no order, no order item, no Hold, no
   * Reservation, and no attempt row.
   *
   * Repeating the call with the same submission, Slot, and price returns the
   * order already opened for it rather than opening a second one. That is what
   * makes a double-submitted payment form harmless, and it is enforced by a unique
   * index rather than by a check the caller could skip.
   *
   * @param \Drupal\webform\WebformSubmissionInterface $submission
   *   The saved submission this attempt pays for.
   * @param \Drupal\custom_webform_handlers\Dispatch\Slot $slot
   *   The Slot the visitor selected. Advisory until the store proves it: the
   *   planner re-derives the Dispatch Window and the store re-checks contention.
   * @param \Drupal\custom_webform_handlers\Checkout\CheckoutContact $contact
   *   Who Commerce should address about the order.
   * @param \Drupal\custom_webform_handlers\Checkout\QuoteSnapshot $quote
   *   The server's locked amount, copied into the order item verbatim.
   *
   * @return \Drupal\custom_webform_handlers\Checkout\CheckoutOutcome
   *   Started with the order identifier, or refused with a stable reason.
   */
  public function start(WebformSubmissionInterface $submission, Slot $slot, CheckoutContact $contact, QuoteSnapshot $quote): CheckoutOutcome;

  /**
   * Turns a paid order into a Reservation, once, whatever happens twice.
   *
   * Synchronous and idempotent, per CONTEXT.md: it is never deferred to cron, a
   * queue, ECA, JavaScript, or a payment-return page, because a booking that is
   * confirmed later is a booking that was sold in the meantime. Called again for
   * an attempt already paid, it reports the same success without touching the row.
   *
   * The reference names the order and the submission. Everything else the
   * confirmation needs — which Slot, at what price — comes from the immutable
   * attempt row, so a payment cannot confirm an arrival other than the one that
   * was held.
   *
   * @param \Drupal\custom_webform_handlers\Checkout\PaidDispatchReference $paid
   *   The narrow fact that this order has been paid for.
   *
   * @return \Drupal\custom_webform_handlers\Checkout\CheckoutOutcome
   *   Confirmed, or refused with a stable reason.
   */
  public function confirmPaid(PaidDispatchReference $paid): CheckoutOutcome;

  /**
   * Abandons an attempt that has not been paid for.
   *
   * Refuses a paid attempt outright. A confirmed Reservation is released by an
   * administrator through the existing operations seam, with the audit trail that
   * implies; a cancellation route that could reach one would let a stale browser
   * tab or a repeated gateway callback give away capacity a customer has been
   * charged for.
   *
   * @param int $orderId
   *   The canonical Commerce order identifier.
   *
   * @return \Drupal\custom_webform_handlers\Checkout\CheckoutOutcome
   *   Cancelled, or refused with a stable reason.
   */
  public function cancel(int $orderId): CheckoutOutcome;

}
