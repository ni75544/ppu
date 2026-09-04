<?php

namespace Drupal\custom_webform_handlers\Dispatch;

/**
 * Whether one Dispatch Request may be self-booked, and under which rules.
 *
 * Internal to the planner. It is not returned by offerSlots(): per CONTEXT.md,
 * Manual Review creates neither a Hold nor a Reservation and consumes no
 * capacity, so a request that lands there has no Slots at all rather than Slots
 * a visitor cannot take. The reason exists so the planner can log and later
 * surface the refusal without inventing an unselectable offer.
 *
 * The service class is what stops a category error: it decides which duration
 * rules apply, so a move can never be timed as a courier job because its
 * addresses are close together.
 *
 * There are two audiences, so there are two answers. Self-bookability is the
 * public one. Staff-bookability is the same question asked on behalf of somebody
 * who has read the request and is accountable for it, and it differs from the
 * public answer in one respect only: work the operator's policy sends to Manual
 * Review is work staff may still book, because sending it to Manual Review means
 * a person should look at it, and a person has. It never differs about facts. A
 * request with no service type, no second endpoint or no configured policy at all
 * is unbookable by everyone, because there is no service class for it and
 * therefore no duration and therefore no honest Dispatch Window.
 */
final readonly class EligibilityOutcome {

  /**
   * Courier: handling at each end plus the route between them.
   */
  const CLASS_COURIER = 'courier';

  /**
   * Residential or local move: duration derived from move-size and access facts.
   */
  const CLASS_MOVE = 'move';

  /**
   * @param bool $selfBookable
   *   Whether Slots may be offered at all.
   * @param \Drupal\custom_webform_handlers\Dispatch\DispatchReason $reason
   *   Available when self-bookable, otherwise the stable, non-secret reason it
   *   is not. On a staff override this stays the policy reason self-service
   *   refused for, so the refusal that staff are overriding is still on the
   *   record and can be logged verbatim.
   * @param string|null $serviceClass
   *   One of the CLASS_* constants when bookable by anyone, otherwise NULL.
   * @param bool $staffBookable
   *   Whether authorized staff may book it deliberately. Always true when it is
   *   self-bookable; additionally true where policy, and not a missing fact,
   *   is what withheld it.
   */
  private function __construct(
    public bool $selfBookable,
    public DispatchReason $reason,
    public ?string $serviceClass,
    public bool $staffBookable,
  ) {}

  /**
   * The request may be self-booked under one service class.
   */
  public static function eligible(string $serviceClass): self {
    return new self(TRUE, DispatchReason::Available, $serviceClass, TRUE);
  }

  /**
   * The request needs staff, and this is why.
   */
  public static function manualReview(DispatchReason $reason): self {
    return new self(FALSE, $reason, NULL, FALSE);
  }

  /**
   * Policy withheld it from self-service, but staff may book it knowingly.
   *
   * Only ever produced for a request whose facts are complete enough to time and
   * route: the service class is required here precisely because a class is what
   * the duration policy needs, so an outcome that cannot name one can never
   * become an override.
   */
  public static function staffOverride(DispatchReason $reason, string $serviceClass): self {
    return new self(FALSE, $reason, $serviceClass, TRUE);
  }

  /**
   * Tells whether this outcome permits planning on one authority's behalf.
   *
   * The single place the two answers are chosen between, so no caller has to
   * remember which flag belongs to which audience.
   */
  public function bookableBy(PlanAuthority $authority): bool {
    return match ($authority) {
      PlanAuthority::Visitor => $this->selfBookable,
      PlanAuthority::Staff => $this->staffBookable,
    };
  }

}
