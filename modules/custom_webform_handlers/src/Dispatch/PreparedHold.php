<?php

namespace Drupal\custom_webform_handlers\Dispatch;

use Drupal\custom_webform_handlers\Dispatch\Route\DispatchRouteEstimatorInterface;

/**
 * Everything a guarded claim needs, prepared before any lock is taken.
 *
 * createHoldFor() always ran three things in one breath: it validated the request
 * and derived a plan (route I/O happens here), it claimed the Slot under the
 * Resource Calendar Guard, and it published a session receipt for the resulting
 * Hold. A future transaction-owning caller needs to run only the middle step
 * inside its writer transaction, with the route preparation before that
 * transaction opens and the receipt after it commits. That is impossible while the
 * three stay welded together inside one method and one set of locals.
 *
 * This is the seam that lets them come apart: the request-scoped result of the
 * preparation step, carrying exactly what the claim step consumes and nothing a
 * response may ever see. It is not a plan — SlotPlan is the plan, and it is in
 * here — it is the whole claim-ready context: the plan, plus the owning session,
 * the Hold lifetime, the authority and estimator the locked revalidation must
 * reuse, and the accepted price facts a checkout claim writes atomically.
 *
 * The route facts inside $plan are the ONLY external answers the claim is allowed
 * to use. The locked revalidation re-reads neighbours from them and performs no
 * provider I/O; a neighbour that needs a leg absent from them refuses rather than
 * fetching one under the lock. So this object is the boundary between "may call a
 * provider" and "may not", made explicit and carried across the lock.
 *
 * @internal
 *   Produced only by DispatchPlanner::prepareHold() and consumed only by its claim
 *   step, which the checkout manager reaches through the @internal
 *   DispatchCheckoutPlannerInterface. Like SlotPlan it carries other customers'
 *   schedule detail and the accepted price, so it must never be returned from a
 *   controller, serialized into a response, or attached to drupalSettings. Holding
 *   one is not an authority to skip locked revalidation, and it is not proof the
 *   Slot is still free: both are decided again under the guard.
 */
final readonly class PreparedHold {

  /**
   * @param \Drupal\custom_webform_handlers\Dispatch\DispatchRequest $request
   *   The normalised job the claim re-reads addresses and neighbours from.
   * @param \Drupal\custom_webform_handlers\Dispatch\SlotPlan $plan
   *   The derived candidate, including the prepared route facts the locked
   *   revalidation is restricted to.
   * @param \Drupal\custom_webform_handlers\Dispatch\ResourceProfile $profile
   *   The Resource the plan was derived against, reused unchanged under the lock.
   * @param string $ownerId
   *   The session that will own the Hold. Proven non-empty during preparation.
   * @param int $holdSeconds
   *   The configured Hold lifetime, resolved during preparation.
   * @param \Drupal\custom_webform_handlers\Dispatch\PlanAuthority $authority
   *   Whose eligibility assessment the locked revalidation reads. Nothing physical
   *   is relaxed for either authority.
   * @param \Drupal\custom_webform_handlers\Dispatch\Route\DispatchRouteEstimatorInterface $estimator
   *   The estimator preparation used, reused by the locked revalidation. It is set
   *   server-side by the preparing method, never supplied by a caller, so a public
   *   path cannot reach the checkout-only final estimator by handing one in here.
   * @param string|null $priceSnapshotId
   *   Checkout only: the accepted quote's binding identity, else NULL.
   * @param int|null $priceSnapshotCents
   *   Checkout only: the accepted amount in minor units, else NULL.
   * @param string|null $priceSnapshotCurrency
   *   Checkout only: the accepted currency, else NULL.
   * @param string|null $priceSnapshotVersion
   *   Checkout only: the pricing-policy version the quote was computed under, else
   *   NULL.
   */
  public function __construct(
    public DispatchRequest $request,
    public SlotPlan $plan,
    public ResourceProfile $profile,
    public string $ownerId,
    public int $holdSeconds,
    public PlanAuthority $authority,
    public DispatchRouteEstimatorInterface $estimator,
    public ?string $priceSnapshotId = NULL,
    public ?int $priceSnapshotCents = NULL,
    public ?string $priceSnapshotCurrency = NULL,
    public ?string $priceSnapshotVersion = NULL,
  ) {}

}
