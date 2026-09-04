<?php

namespace Drupal\custom_webform_handlers\Dispatch\Policy;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\custom_webform_handlers\Dispatch\DeliveryMode;
use Drupal\custom_webform_handlers\Dispatch\DispatchReason;
use Drupal\custom_webform_handlers\Dispatch\DispatchRequest;
use Drupal\custom_webform_handlers\Dispatch\EligibilityOutcome;
use Drupal\custom_webform_handlers\Dispatch\Profile\BookingProfile;
use Drupal\custom_webform_handlers\Dispatch\Profile\BookingProfileResolverInterface;

/**
 * Applies the operator's configured self-service rules to a Dispatch Request.
 *
 * Every threshold and every list consulted here comes from
 * custom_webform_handlers.dispatch. There is no service name, weight, or flag
 * literal in this class, so opening a new service or changing the courier limit
 * is a configuration change.
 *
 * Which rules apply to a *service* is no longer decided here at all. This class
 * used to read both service-type lists for itself and then infer the Courier
 * parent from the length of one of them; it now asks
 * BookingProfileResolverInterface once and reads the answer off the profile. What
 * stays here is what belongs here: the rules about a *request* — the weight it
 * declared, the endpoints it gave, the helper it needs, the flags it carries.
 *
 * The order of the checks is not arbitrary. Manual Review by service type and by
 * handling flag is decided first, because those are absolute: no amount of light
 * weight or single-driver handling makes a piano move self-bookable. Only once a
 * request is in a self-bookable class are the courier-specific limits applied.
 */
final class ConfigDispatchEligibility implements DispatchEligibilityInterface {

  /**
   * The operating profile.
   */
  const SETTINGS = 'custom_webform_handlers.dispatch';

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly BookingProfileResolverInterface $bookingProfiles,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function assess(DispatchRequest $request): EligibilityOutcome {
    $config = $this->configFactory->get(self::SETTINGS);

    $serviceType = trim($request->serviceType);
    if ($serviceType === '') {
      // Asked before the profile, because "no service chosen" is an unfinished
      // form and the visitor is owed a different sentence than a refusal.
      return EligibilityOutcome::manualReview(DispatchReason::IncompleteFacts);
    }

    $profile = $this->bookingProfiles->resolve($serviceType);
    if (!$profile->hasEligibilityPolicy()) {
      // Nothing has been opened to self-service, so nothing can be offered. This
      // is also the state before the operating profile has been installed, and
      // failing closed there is the point.
      return EligibilityOutcome::manualReview(DispatchReason::NotConfigured);
    }

    // Absolute refusals first. A service the operator declared unbookable
    // outranks anything the visitor declared about the job; a service that is
    // merely not open yet does not, and is refused further down.
    if ($profile->refusesBeforeDeclaredFacts()) {
      return EligibilityOutcome::manualReview($profile->manualReviewReason());
    }

    // Any helper requirement means more people than the single driver this
    // Resource represents. Truck with 2 helpers is caught here along with the
    // rest, by configuration rather than by name.
    $helperNeeds = trim((string) $request->vehicleHelperNeeds);
    if ($helperNeeds !== ''
      && in_array($helperNeeds, $this->stringList($config->get('manual_review.vehicle_helper_needs')), TRUE)) {
      return EligibilityOutcome::manualReview(DispatchReason::MultiPersonHandling);
    }

    $configuredFlags = $this->stringList($config->get('manual_review.handling_flags'));
    foreach ($request->handlingFlags as $flag) {
      if (in_array(trim((string) $flag), $configuredFlags, TRUE)) {
        return EligibilityOutcome::manualReview(DispatchReason::ManualReview);
      }
    }

    if ($profile->isManualReview()) {
      return EligibilityOutcome::manualReview($profile->manualReviewReason());
    }

    // Both endpoints are needed by every class: a Dispatch Window spans pickup
    // work, a route, and delivery work, so half an itinerary has no duration.
    if (!$request->hasBothEndpoints()) {
      return EligibilityOutcome::manualReview(DispatchReason::IncompleteFacts);
    }

    // From here the request is in a self-bookable service. Courier carries the
    // package limits; anything else self-bookable is timed as a move.
    if ($this->isCourierClass($request, $profile)) {
      return $this->assessCourier($config, $request);
    }

    return EligibilityOutcome::eligible(EligibilityOutcome::CLASS_MOVE);
  }

  /**
   * Whether this request is timed by the Courier duration policy.
   *
   * Which of the two duration classes a service belongs to is the profile's
   * answer, not a guess about how many services happen to be open. The request
   * still gets a say for one reason: a Delivery Mode describes package collection
   * and only the Courier policy knows how to time it, so a request that declared
   * one is Courier-timed whatever its service says.
   *
   * Written once and asked twice, because the public assessment and the staff
   * override have to agree about it. Two copies of this expression would let a
   * job staff book be timed by a different policy than the one that would have
   * timed it online, which is the disagreement this seam exists to prevent.
   */
  private function isCourierClass(DispatchRequest $request, BookingProfile $profile): bool {
    return $request->deliveryMode !== NULL
      || $profile->durationResolver === BookingProfile::DURATION_COURIER;
  }

  /**
   * {@inheritdoc}
   */
  public function assessForStaff(DispatchRequest $request): EligibilityOutcome {
    // One assessment, then a decision about its refusal. Re-deciding the policy
    // here with the Manual Review lists left out would be a second copy of the
    // rules, and the two copies would eventually disagree about which services
    // are open — which is the disagreement that lets an unbookable job be booked.
    $outcome = $this->assess($request);
    if ($outcome->selfBookable) {
      // Staff may book anything the public may book, and there is nothing to
      // override, so the public answer is returned unchanged.
      return $outcome;
    }

    // A fact gap and a missing configuration are not policy refusals, and no
    // authority overrides them: without a service class there is no duration
    // policy to ask, and a Dispatch Window derived from no duration would be a
    // window this module invented.
    if ($outcome->reason === DispatchReason::IncompleteFacts
      || $outcome->reason === DispatchReason::NotConfigured) {
      return $outcome;
    }

    // Reachable: assess() refuses several policy cases before it ever looks at
    // the endpoints, so a job may be here with half an itinerary. Half an
    // itinerary has no route and therefore no duration, for staff as for anyone.
    if (!$request->hasBothEndpoints()) {
      return EligibilityOutcome::manualReview(DispatchReason::IncompleteFacts);
    }

    // The class is decided by the same profile assess() reads, through the same
    // helper, so a job staff book is timed by the same policy that would have
    // timed it online. Nothing else about the courier limits is re-applied:
    // exceeding them is exactly the kind of refusal staff are here to override,
    // and the duration policy will still refuse if it cannot time the job.
    $profile = $this->bookingProfiles->resolve(trim($request->serviceType));
    $serviceClass = $this->isCourierClass($request, $profile)
      ? EligibilityOutcome::CLASS_COURIER
      : EligibilityOutcome::CLASS_MOVE;

    return EligibilityOutcome::staffOverride($outcome->reason, $serviceClass);
  }

  /**
   * Applies the Courier-specific limits.
   */
  private function assessCourier($config, DispatchRequest $request): EligibilityOutcome {
    $mode = $request->deliveryMode;
    if ($mode === NULL) {
      // Courier without a Delivery Mode does not describe how the package is
      // collected, which is exactly what the mode determines.
      return EligibilityOutcome::manualReview(DispatchReason::IncompleteFacts);
    }

    $allowedModes = $this->stringList($config->get('self_bookable.delivery_modes'));
    if (!in_array($mode->value, $allowedModes, TRUE)) {
      return EligibilityOutcome::manualReview(DispatchReason::UnsupportedDeliveryMode);
    }

    // Weight. An undeclared weight is unknown, not light.
    $requireWeight = (bool) $config->get('self_bookable.require_declared_weight');
    if ($requireWeight && !$request->hasDeclaredWeight()) {
      return EligibilityOutcome::manualReview(DispatchReason::IncompleteFacts);
    }
    if ($request->hasDeclaredWeight()) {
      $limit = (int) $config->get('self_bookable.max_package_lbs');
      if ($limit < 1) {
        return EligibilityOutcome::manualReview(DispatchReason::NotConfigured);
      }
      if ($request->declaredWeightLbs === NULL || $request->declaredWeightLbs <= 0.0) {
        return EligibilityOutcome::manualReview(DispatchReason::IncompleteFacts);
      }
      if ($request->declaredWeightLbs > (float) $limit) {
        return EligibilityOutcome::manualReview(DispatchReason::OverWeightLimit);
      }
    }

    // One-driver handling. The rule stays, and stays enforced; what changed is who
    // answers it. For a service the profile lists in
    // self_bookable.single_driver_service_types, DispatchRequestFactory resolves
    // this fact TRUE from the service policy — Express Courier is one driver, one
    // vehicle, under a weight ceiling chosen so one person can carry the package —
    // so a Courier request satisfies it by definition and the customer is never
    // asked. The check is kept rather than deleted because the invariant is real
    // and because any service type the policy does not name still has to prove it.
    //
    // A genuine two-person job is still refused, and refused before this line is
    // reached: manual_review.vehicle_helper_needs catches a declared helper need
    // and manual_review.handling_flags catches a multi_person marker, both in
    // assess() above. The weight ceiling immediately above is likewise untouched.
    if ((bool) $config->get('self_bookable.require_single_driver_handling')
      && !$request->singleDriverHandling) {
      return EligibilityOutcome::manualReview(DispatchReason::MultiPersonHandling);
    }

    // Contents. The quote refuses to price a package whose contents are not
    // affirmed, so offering Slots for that same package would hand the visitor an
    // arrival time for a job the estimate just sent to staff - the contradiction
    // the add-on check below exists to prevent, in its other form.
    //
    // Asked as a required affirmation rather than as a Handling Flag because the
    // failure here is silence: a flag refuses when a known-bad marker is present,
    // and an unchecked box submits no marker at all. Both an unanswered question
    // and an answered-no are refused, because neither is an affirmation.
    if ((bool) $config->get('self_bookable.require_contents_attestation')
      && $request->contentsAttested !== TRUE) {
      return EligibilityOutcome::manualReview(DispatchReason::ManualReview);
    }

    // Store Pickup has one extra precondition: something must actually be
    // collectable when the driver arrives.
    if ($mode === DeliveryMode::StorePickup
      && (bool) $config->get('self_bookable.store_pickup_requires_paid_and_ready')
      && $request->storePickupPaidAndReady !== TRUE) {
      return EligibilityOutcome::manualReview(DispatchReason::StorePickupNotReady);
    }

    // Add-ons. The universal add-on questions are move products: the quote
    // calculator sends disposal or donation interest straight to staff, and the
    // courier duration rules have no add-on map to time any of them against. A
    // courier request that selected one is therefore a request this module can
    // neither price nor schedule by itself, and serving Slots for it would
    // contradict the quote the same request just returned — the contradiction
    // this check exists to prevent.
    //
    // Asked here rather than left to the duration policy on purpose: an untimed
    // job and a fully-booked day both come back from the planner as an empty
    // Slot list, and the visitor has to be told which one happened.
    //
    // Only the courier map is read, so an operator who prices and times a courier
    // add-on re-enables self-booking by mapping it, with no code change. The
    // acceptance rule matches ConfigServiceDurationPolicy::intMap(): an add-on
    // mapped to a malformed value is unmapped rather than free, so eligibility and
    // the duration policy cannot disagree about what is timed.
    $timedAddOns = [];
    $configuredAddOns = $config->get('durations.courier.add_on_minutes');
    if (is_array($configuredAddOns)) {
      foreach ($configuredAddOns as $addOn => $minutes) {
        $addOn = trim((string) $addOn);
        if ($addOn !== '' && is_numeric($minutes) && (int) $minutes >= 0) {
          $timedAddOns[$addOn] = TRUE;
        }
      }
    }
    foreach ($request->addOns as $addOn) {
      $addOn = trim((string) $addOn);
      if ($addOn === '' || $this->isUnselectedMarker($addOn)) {
        continue;
      }
      if (!isset($timedAddOns[$addOn])) {
        return EligibilityOutcome::manualReview(DispatchReason::ManualReview);
      }
    }

    return EligibilityOutcome::eligible(EligibilityOutcome::CLASS_COURIER);
  }

  /**
   * Whether a collected marker means "the visitor did not select this".
   *
   * DispatchRequestFactory keeps flag values verbatim so that a configured Manual
   * Review rule cannot silently stop matching, which is right for a Handling Flag
   * whose values are operator-chosen machine names. An add-on marker is different:
   * an unchecked Webform checkbox submits '0', and reading that as a selected
   * add-on named "0" would send every courier request to staff. The interpretation
   * is kept here, at the one caller that needs it, instead of in the shared
   * collector where it would change what Handling Flag rules match.
   */
  private function isUnselectedMarker(string $value): bool {
    return in_array(mb_strtolower($value), ['0', 'false', 'no', 'off'], TRUE);
  }

  /**
   * Normalises a configured list into trimmed, non-empty strings.
   *
   * @return string[]
   */
  private function stringList(mixed $value): array {
    if (!is_array($value)) {
      return [];
    }
    $list = [];
    foreach ($value as $item) {
      $item = trim((string) $item);
      if ($item !== '') {
        $list[] = $item;
      }
    }
    return array_values(array_unique($list));
  }

}
