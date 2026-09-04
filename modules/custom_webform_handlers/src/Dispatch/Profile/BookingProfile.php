<?php

namespace Drupal\custom_webform_handlers\Dispatch\Profile;

use Drupal\custom_webform_handlers\Dispatch\DispatchReason;

/**
 * Everything one service type decides about being booked, in one immutable value.
 *
 * This exists so that no caller has to know a service by name. Before it, the
 * same question — "is this service bookable, and by what rules?" — was answered
 * in five places from three different configuration keys: the eligibility policy
 * read `self_bookable.service_types` and `manual_review.service_types` and then
 * guessed the Courier parent from `count($services) === 1`; the request factory
 * read `self_bookable.single_driver_service_types`; the Webform handler compared
 * `service_type` to the literal `'courier'`; the quote calculator switched on the
 * raw service string; and the browser received a flattened copy of one of those
 * lists. Five copies of one decision is five chances for them to disagree, and a
 * disagreement here is a published Slot for a job nobody can time.
 *
 * The profile carries exactly nine facts, plus the service type it was resolved
 * for. They are the nine a caller can actually need:
 *
 *   1. booking mode                  $mode
 *   2. canonical requested-date field $requestedDateField
 *   3. canonical selected-Slot field  $selectedSlotField
 *   4. required facts                 $requiredFacts
 *   5. eligibility policy             $eligibility
 *   6. duration resolver identity     $durationResolver
 *   7. capacity / resource rule       $capacity
 *   8. quote strategy                 $quoteStrategy
 *   9. public review-result policy    $reviewResult
 *
 * Readonly is load-bearing rather than decorative: a profile is resolved once
 * per request and then read by the eligibility policy, the planner, the quote
 * calculator and the browser contract. If any one of those could mutate it, the
 * others would be reasoning about a different service than the one the visitor
 * chose. PHP's readonly also freezes the array-shaped facts outright — there is
 * no `$profile->capacity['capacity'] = 99`.
 *
 * Not on the profile, deliberately: the resource calendar, the buffers, the
 * horizon and the price book. Those are properties of the operation, not of the
 * service, and they already have one home each. The profile answers only
 * *whether and by what rules* this service reaches them.
 */
final class BookingProfile {

  /**
   * Review copy comes from the server's per-request assessment.
   *
   * The refusal sentence depends on what the visitor asked for, not on which
   * service they picked, so the widget must ask before it can say anything.
   */
  const REVIEW_OFFER_ASSESSED = 'offer_assessed';

  /**
   * Review copy is fixed and known before any request is made.
   *
   * The service is never bookable, so the widget states that up front instead of
   * posting an offer request whose only possible answer it already knows.
   */
  const REVIEW_ALWAYS = 'always_review';

  /**
   * The two duration resolvers that exist, and the absence of one.
   */
  const DURATION_COURIER = 'courier';
  const DURATION_MOVE = 'move';
  const DURATION_NONE = '';

  /**
   * @param string $serviceType
   *   The service type this profile was resolved for. Identity, not a decision.
   * @param \Drupal\custom_webform_handlers\Dispatch\Profile\BookingMode $mode
   *   Fact 1. Whether Slots may ever be offered for this service.
   * @param string $requestedDateField
   *   Fact 2. The one element that names the requested local date. One canonical
   *   field, so a service can never grow a second date question that disagrees
   *   with the first.
   * @param string $selectedSlotField
   *   Fact 3. The one element that carries the chosen arrival instant.
   * @param array<string, string> $requiredFacts
   *   Fact 4. Fact name => element key, for the facts whose absence makes a
   *   request *unfinished* rather than *refused*. A refusal by policy — an
   *   unticked attestation — is not listed here, because the visitor is owed a
   *   different sentence about it.
   * @param array{policy: string, single_driver_service: bool} $eligibility
   *   Fact 5. Which policy assesses this service, and the one fact the service
   *   answers by definition instead of by asking. An empty policy means no
   *   self-service booking is configured at all.
   * @param string $durationResolver
   *   Fact 6. Which duration resolver can time this service, or DURATION_NONE
   *   when none can. This replaces guessing the Courier parent from the length
   *   of a configuration list.
   * @param array{resource_id: string, capacity: int, claims_capacity: bool} $capacity
   *   Fact 7. Whether this service consumes the shared resource's capacity, and
   *   which resource. A service that claims no capacity writes no ledger row and
   *   cannot take grid time away from one that does.
   * @param string $quoteStrategy
   *   Fact 8. Which price book prices this service. Every service has one, which
   *   is why price is never what makes a service Manual Review.
   * @param array{policy: string, message: string, reason: \Drupal\custom_webform_handlers\Dispatch\DispatchReason} $reviewResult
   *   Fact 9. How the public refusal is worded and which reason it reports.
   */
  public function __construct(
    public readonly string $serviceType,
    public readonly BookingMode $mode,
    public readonly string $requestedDateField,
    public readonly string $selectedSlotField,
    public readonly array $requiredFacts,
    public readonly array $eligibility,
    public readonly string $durationResolver,
    public readonly array $capacity,
    public readonly string $quoteStrategy,
    public readonly array $reviewResult,
  ) {}

  /**
   * Whether Slots may be offered for this service.
   */
  public function isSelfBookable(): bool {
    return $this->mode === BookingMode::SelfBookable;
  }

  /**
   * Whether this service can only be scheduled by a person.
   */
  public function isManualReview(): bool {
    return $this->mode === BookingMode::ManualReview;
  }

  /**
   * Whether any self-service booking policy is configured at all.
   *
   * Distinct from being Manual Review. A Manual Review service is a decision an
   * operator made about that service; no policy at all is an unconfigured
   * module, and the two owe the caller different reasons.
   */
  public function hasEligibilityPolicy(): bool {
    return trim((string) ($this->eligibility['policy'] ?? '')) !== '';
  }

  /**
   * Which policy service assesses this profile.
   */
  public function eligibilityPolicy(): string {
    return trim((string) ($this->eligibility['policy'] ?? ''));
  }

  /**
   * Whether one-driver handling is true by definition of the service.
   *
   * Express Courier is *defined* as a one-driver job, so the answer is a
   * property of the service and never a question put to the browser — the
   * browser was never a safe source for that claim. Every other service reads
   * its mapped element, and an unmapped or unanswered element resolves to FALSE,
   * which routes to staff.
   */
  public function singleDriverByDefinition(): bool {
    return !empty($this->eligibility['single_driver_service']);
  }

  /**
   * Whether this service consumes the shared resource's capacity.
   */
  public function claimsCapacity(): bool {
    return !empty($this->capacity['claims_capacity']);
  }

  /**
   * The resource this service competes for, or '' when it claims none.
   */
  public function resourceId(): string {
    return $this->claimsCapacity() ? trim((string) ($this->capacity['resource_id'] ?? '')) : '';
  }

  /**
   * How many of this service the resource can run at once, or 0 for none.
   */
  public function capacityLimit(): int {
    return $this->claimsCapacity() ? max(0, (int) ($this->capacity['capacity'] ?? 0)) : 0;
  }

  /**
   * Whether the refusal sentence is known before any request is assessed.
   */
  public function alwaysUnderReview(): bool {
    return $this->isManualReview()
      && (string) ($this->reviewResult['policy'] ?? '') === self::REVIEW_ALWAYS;
  }

  /**
   * The fixed public sentence for an always-reviewed service, or ''.
   *
   * Empty for every service whose refusal wording depends on what was asked,
   * because those must be assessed before anything truthful can be said.
   */
  public function reviewMessage(): string {
    return $this->alwaysUnderReview()
      ? trim((string) ($this->reviewResult['message'] ?? ''))
      : '';
  }

  /**
   * Which reason a Manual Review refusal reports.
   *
   * A service an operator declared unbookable reports ManualReview. A service
   * that simply has not been opened reports UnsupportedService. The two are
   * different statements and the public copy differs, so the distinction the
   * old two-list lookup produced is kept here rather than flattened away.
   */
  public function manualReviewReason(): DispatchReason {
    $reason = $this->reviewResult['reason'] ?? NULL;
    return $reason instanceof DispatchReason ? $reason : DispatchReason::ManualReview;
  }

  /**
   * Whether this refusal outranks the facts the visitor declared about the job.
   *
   * A service an operator has declared Manual Review is an absolute refusal and
   * is stated before the request's own declarations are examined. A service that
   * is merely not open yet is a weaker statement than the visitor's declared
   * two-person handling need, so it is stated after — the more specific reason
   * wins. This preserves the ordering the two-list lookup produced.
   */
  public function refusesBeforeDeclaredFacts(): bool {
    return $this->isManualReview()
      && $this->manualReviewReason() === DispatchReason::ManualReview;
  }

  /**
   * The element keys of the required facts, deduplicated and non-empty.
   */
  public function requiredFactElements(): array {
    $elements = array_filter(array_map(
      static fn ($element): string => trim((string) $element),
      $this->requiredFacts,
    ), static fn (string $element): bool => $element !== '');

    return array_values(array_unique($elements));
  }

  /**
   * The browser's view of this profile.
   *
   * The one place the client contract is written. The widget needs three things
   * to render the shared shell correctly for whichever service the visitor
   * picked — whether Slots are possible, which answers are still missing, and
   * what to say when the answer is "a person will schedule this" — and it gets
   * exactly those, resolved server-side.
   *
   * What is deliberately withheld: the eligibility policy id, the resource id,
   * the capacity limit and the price-book key. None of them changes what the
   * widget renders, and publishing operational identifiers to an anonymous page
   * would be a disclosure with no purpose.
   */
  public function toPublishedArray(): array {
    return [
      'mode' => $this->mode->value,
      'required_facts' => $this->requiredFactElements(),
      'always_review' => $this->alwaysUnderReview(),
      'review_message' => $this->reviewMessage(),
    ];
  }

}
