<?php

namespace Drupal\custom_webform_handlers\Dispatch;

use Drupal\custom_webform_handlers\Checkout\QuoteSnapshot;
use Drupal\webform\WebformSubmissionInterface;

/**
 * Final-route planning operations available only to the checkout manager.
 *
 * The public DispatchPlannerInterface deliberately retains offer policy. This
 * separate narrow seam is injected only into the manager that has already
 * validated the saved submission and workflow gate, so an anonymous hold or
 * renew endpoint cannot select the final chain merely by supplying a flag.
 *
 * The same DispatchPlanner service implements both interfaces and both route
 * through its one feasibility algorithm. This is an authority boundary, not a
 * second planner.
 *
 * @internal
 */
interface DispatchCheckoutPlannerInterface {

  /**
   * Claims a checkout Slot using final-route preparation.
   *
   * The accepted quote is decomposed into the price-snapshot facts written with
   * the claim, so the amount, currency, snapshot identity and pricing-policy
   * version are persisted atomically rather than patched onto the Reservation.
   */
  public function createCheckoutHold(DispatchRequest $request, Slot $slot, QuoteSnapshot $quote): HoldResult;

  /**
   * Prepares a checkout hold's claim-ready context before any lock is taken.
   *
   * The first of the three operations createCheckoutHold() composes. It validates
   * the same eligibility, owner, canonical slot/resource and accepted-quote
   * conditions, and derives the plan — the one place final-route provider I/O is
   * allowed to happen — producing a server-owned, request-scoped PreparedHold. It
   * claims no capacity and publishes no receipt.
   *
   * The final-route estimator is fixed server-side here, never taken as a
   * parameter, so reaching this @internal seam is the only way to select the final
   * chain: a public endpoint cannot select it by supplying a flag or fabricating
   * prepared values.
   *
   * The returned PreparedHold carries other customers' schedule detail and the
   * accepted price, so it must never be returned from a controller, serialized into
   * a response, or attached to drupalSettings. A refusal is returned as a bare
   * DispatchReason, which the composing wrapper turns into a refused HoldResult.
   */
  public function prepareCheckoutHold(DispatchRequest $request, Slot $slot, QuoteSnapshot $quote): PreparedHold|DispatchReason;

  /**
   * Claims the prepared checkout hold under the Resource Calendar Guard.
   *
   * The second operation. It consumes the PreparedHold and fetches no additional
   * route facts: the locked revalidation re-reads neighbours only from the facts
   * prepared above and refuses rather than fetching one that was not prepared.
   * Holding a PreparedHold is candidate evidence, not permission to skip the locked
   * revalidation. The accepted price facts are written with the claim atomically.
   * It publishes no session receipt and no cart effect, and it neither owns nor
   * commits any outer transaction, so a future transaction-owning caller can run
   * exactly this step inside its own writer transaction.
   */
  public function claimPreparedCheckoutHold(PreparedHold $prepared): HoldResult;

  /**
   * Publishes the session receipt for a successful checkout hold.
   *
   * The third operation, made explicit so it can be deferred. Hold credentials
   * stay server-side. A future transaction-owning caller invokes this only after
   * its outermost transaction has committed; the present compatibility wrapper
   * calls it as soon as the claim succeeds. Separating this step is not itself
   * proof of post-commit atomicity.
   */
  public function publishCheckoutHoldReceipt(Hold $hold): void;

  /**
   * Confirms a validated paid attempt using final-route preparation.
   *
   * The confirmation is bound to the accepted job on two axes, both proved against
   * persisted authority inside the Resource Calendar Guard's transaction rather than
   * in an unlocked check a caller could skip (Task 24B / E09):
   *
   *  - Job identity. The accepted job-facts digest is the identity the manager read
   *    from the immutable checkout-attempt row the customer accepted — a SHA-256 over
   *    the material job inputs, sealed on that row by its payload digest and proved to
   *    be the identity of the facts stored beside it. The planner recomputes the same
   *    identity from the saved submission and compares the two, so a job edited
   *    between acceptance and confirmation — even one whose price and reserved
   *    duration are unchanged — is refused. An empty string is a genuine legacy
   *    attempt that carries no identity, and this comparison is skipped for it rather
   *    than treated as a mismatch.
   *
   *  - Snapshot binding. The accepted snapshot's binding identity
   *    (QuoteSnapshot::bindingIdentity()) is checked against the price_snapshot_id the
   *    reservation itself persisted when the Hold was created, read under the lock via
   *    LockedHold. This proves the accepted quote being settled is the one THIS
   *    reservation was held for — not merely a snapshot the caller passed alongside the
   *    token — which is what makes the identity digest above authoritative rather than
   *    free-floating. The binding is VERSION-AWARE: a current-format identity carries a
   *    prefix and folds the accepted job identity in, so a NULL/empty/mismatched stored
   *    id REFUSES (missing data is not proof of legacy status); only a genuine legacy
   *    identity, whose reservation predates the column, tolerates an absent stored id,
   *    and a stored legacy id that mismatches still refuses.
   *
   * @param \Drupal\custom_webform_handlers\Dispatch\Hold $hold
   *   The live Hold this session owns.
   * @param \Drupal\webform\WebformSubmissionInterface $submission
   *   The saved submission the job is confirmed from.
   * @param string $acceptedJobFactsDigest
   *   The accepted job's identity to bind the confirmation to, or '' for a legacy
   *   attempt with none.
   * @param string $acceptedSnapshotId
   *   The accepted snapshot's binding identity (QuoteSnapshot::bindingIdentity()) to
   *   bind against the reservation's own persisted price_snapshot_id under the lock. It
   *   is version-aware: a current-format identity refuses a NULL/empty/mismatched stored
   *   id; a legacy identity tolerates an absent one but still refuses a stored mismatch.
   *   '' skips the binding entirely (a pre-24A caller with no snapshot identity at all).
   */
  public function confirmCheckoutReservation(Hold $hold, WebformSubmissionInterface $submission, string $acceptedJobFactsDigest, string $acceptedSnapshotId): ReservationResult;

}
