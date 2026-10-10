<?php

namespace Drupal\custom_webform_handlers\Dispatch;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\custom_webform_handlers\Dispatch\Policy\DispatchEligibilityInterface;
use Drupal\custom_webform_handlers\Dispatch\Policy\ServiceDurationPolicyInterface;
use Drupal\custom_webform_handlers\Dispatch\Route\DispatchRouteEstimatorInterface;
use Drupal\custom_webform_handlers\Dispatch\Route\PreparedRouteFacts;
use Drupal\custom_webform_handlers\Dispatch\Schedule\ResourceScheduleInterface;
use Drupal\custom_webform_handlers\Dispatch\Storage\DispatchReplan;
use Drupal\custom_webform_handlers\Dispatch\Storage\LockedHold;
use Drupal\custom_webform_handlers\Dispatch\Storage\PlanningNeighbour;
use Drupal\custom_webform_handlers\Dispatch\Storage\ReservationStoreInterface;
use Drupal\webform\WebformSubmissionInterface;
use Psr\Log\LoggerInterface;

/**
 * Plans what one Resource can actually serve on one local date.
 *
 * The premise this class exists to enforce: a Slot is a pickup-arrival option
 * that driver-01-vehicle-01's real schedule can honour, not an hourly preference
 * a visitor picks from a list. Every offer returned here has been proved against
 * the Resource's working interval, breaks, blackouts, booking horizon, existing
 * Dispatch Windows, repositioning space, and — where required — its ability to get
 * back to base before the day ends.
 *
 * Nothing is decided here that belongs elsewhere. Eligibility, on-site durations,
 * route legs, and calendar interpretation each sit behind their own seam, so this
 * class holds the sequence and none of the rules. It contains no hour, weekday,
 * threshold, duration, depot, or buffer literal.
 *
 * Two properties are worth stating plainly because they shape every decision
 * below:
 *
 * - Unknown is never zero. A missing duration, an unroutable leg, an unreadable
 *   day: each removes offers rather than shortening them. The failure mode of this
 *   planner is offering nothing, never offering something the driver cannot do.
 * - Repositioning between neighbouring jobs is enforced as protected margin, not
 *   as a route. The neighbours at claim time are not knowable at offer time, and
 *   the storage seam deliberately exposes occupied intervals without addresses, so
 *   a margin is the honest instrument. It can decline a sequence that would in
 *   fact have worked; it cannot accept one that would not. Depot legs, whose
 *   address is known, are routed exactly.
 *
 * Two audiences, one algorithm. The staff seam is implemented here rather than in
 * a controller or a second planner precisely so that a Replan, a staff
 * confirmation and a visitor's booking all run the derivation in planFor() and are
 * all written by the same storage seam under the same Resource Calendar Guard
 * locks. Authority is carried as a PlanAuthority through every private step, and
 * it changes exactly one answer: which eligibility assessment is read. Nothing
 * physical about a vehicle or a day is relaxed for anybody.
 */
final class DispatchPlanner implements DispatchPlannerInterface, DispatchStaffPlannerInterface, DispatchCheckoutPlannerInterface {

  /**
   * The operating profile.
   */
  const SETTINGS = 'custom_webform_handlers.dispatch';

  /**
   * How far either side of the working interval occupancy is read, in seconds.
   *
   * A Dispatch Window that began yesterday evening, or repositioning that reaches
   * back before the shift starts, still constrains today. Reading a full day of
   * padding either side costs one wider query and removes a class of collision
   * that a tightly scoped read would not see. It is advisory either way: claim()
   * is the only authority.
   */
  const OCCUPANCY_PAD_SECONDS = 86400;

  /**
   * Upper bound on grid candidates examined for one date.
   *
   * A guard against a corrupt profile, not a business rule: a 24-hour day on a
   * five-minute grid is 288 candidates, so this cannot truncate a real schedule.
   */
  const MAX_GRID_CANDIDATES = 400;

  public function __construct(
    private readonly DispatchEligibilityInterface $eligibility,
    private readonly ServiceDurationPolicyInterface $durationPolicy,
    private readonly DispatchRouteEstimatorInterface $offerRouteEstimator,
    private readonly DispatchRouteEstimatorInterface $finalRouteEstimator,
    private readonly ResourceScheduleInterface $schedule,
    private readonly ReservationStoreInterface $store,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
    private readonly ResourceProfileFactory $profileFactory,
    private readonly DispatchRequestFactory $requestFactory,
    private readonly DispatchOwner $owner,
    private readonly DispatchHoldWindow $holdWindow,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function offerSlots(DispatchRequest $request, string $localDate, ResourceProfile $profile): array {
    // 1. Is this request self-bookable at all, and under which timing rules?
    //
    // Manual Review consumes no capacity, so it yields no Slots rather than Slots
    // a visitor cannot take. The reason stays internal: it is logged, not
    // published, because publishing per-Slot refusals invites a caller to render
    // them as choices and leaks the shape of the Resource's day.
    $outcome = $this->eligibility->assess($request);
    if (!$outcome->selfBookable || $outcome->serviceClass === NULL) {
      $this->logger->debug('Dispatch planning declined for @webform: @reason.', [
        '@webform' => $request->webformId,
        '@reason' => $outcome->reason->value,
      ]);
      return [];
    }

    // 2. Is the Resource configured well enough to plan against?
    if ($profile->capacity < 1) {
      return [];
    }
    if ($profile->requiresDepotConfiguration()) {
      // Return-to-base with nowhere to return to cannot be evaluated, and a rule
      // that cannot be evaluated must not be skipped.
      $this->logger->warning('Resource @resource requires return to base but has no depot address, so no Slots can be offered.', [
        '@resource' => $profile->resourceId,
      ]);
      return [];
    }

    // 3. Is this date a working day for this Resource?
    $day = $this->schedule->dayFor($profile, $localDate);
    if ($day === NULL) {
      return [];
    }

    // 4. Is the day free of unproven legacy claims?
    //
    // A day flagged legacy_unplanned carries an active claim from the older
    // fixed-slot table, which holds no route data. A feasible Dispatch Window
    // cannot be proven around it, so the day stays closed until an administrator
    // reconciles it.
    if ($this->store->legacyUnplannedDates($profile->resourceId, $day->localDate, $day->localDate) !== []) {
      $this->logger->debug('Resource @resource has an unreconciled legacy claim on @date, so no Slots are offered.', [
        '@resource' => $profile->resourceId,
        '@date' => $day->localDate,
      ]);
      return [];
    }

    // 5. How long is the work at each end?
    $duration = $this->durationPolicy->durationFor($request, $outcome->serviceClass);
    if ($duration === NULL) {
      $this->logger->notice('Dispatch duration undetermined for @webform under class @class, so no Slots are offered.', [
        '@webform' => $request->webformId,
        '@class' => $outcome->serviceClass,
      ]);
      return [];
    }

    $promiseMinutes = $this->promiseMinutesFor($duration);
    if ($promiseMinutes === NULL) {
      // An arrival promise is part of what an offer states. Inventing a width
      // would put a number in front of a customer that no operator chose.
      $this->logger->error('No pickup arrival promise is configured for this service, so no Slots can be offered.');
      return [];
    }

    // 6. Route legs. At most three per invocation, memoized below.
    $facts = new PreparedRouteFacts();
    $serviceLeg = $this->routeLeg($facts, $this->offerRouteEstimator, $request->pickupAddress, $request->deliveryAddress, $day->workStart, TRUE);
    if ($serviceLeg === NULL) {
      // Without the pickup-to-delivery leg there is no Dispatch Window to place.
      return [];
    }

    $depot = trim($profile->depotAddress);
    $depotLeg = NULL;
    if ($depot !== '') {
      $depotLeg = $this->routeLeg($facts, $this->offerRouteEstimator, $depot, $request->pickupAddress, $day->workStart, TRUE);
      if ($depotLeg === NULL) {
        // The first job of the day departs from the depot. Unable to price that
        // leg, the planner cannot prove any candidate is reachable.
        return [];
      }
    }

    $returnLeg = NULL;
    if ($profile->returnToBase) {
      // Guaranteed non-empty: requiresDepotConfiguration() already refused
      // return-to-base without a depot.
      $returnLeg = $this->routeLeg($facts, $this->offerRouteEstimator, $request->deliveryAddress, $depot, $day->workEnd, TRUE);
      if ($returnLeg === NULL) {
        return [];
      }
    }

    $occupied = $this->store->occupiedIntervals(
      $profile->resourceId,
      $day->workStart - self::OCCUPANCY_PAD_SECONDS,
      $day->workEnd + self::OCCUPANCY_PAD_SECONDS,
    );

    // 7. Walk the grid and keep only what the sequence can serve.
    $offers = $this->feasibleOffers(
      $request,
      $profile,
      $day,
      $duration,
      $serviceLeg,
      $depotLeg,
      $returnLeg,
      $occupied,
      $promiseMinutes,
    );

    usort(
      $offers,
      static fn(SlotOffer $a, SlotOffer $b): int => $a->slot->startTimestamp <=> $b->slot->startTimestamp,
    );

    return $offers;
  }

  /**
   * {@inheritdoc}
   */
  public function createHold(DispatchRequest $request, Slot $slot): HoldResult {
    return $this->createHoldFor($request, $slot, PlanAuthority::Visitor, $this->offerRouteEstimator);
  }

  /**
   * {@inheritdoc}
   */
  public function createCheckoutHold(DispatchRequest $request, Slot $slot): HoldResult {
    return $this->createHoldFor($request, $slot, PlanAuthority::Visitor, $this->finalRouteEstimator);
  }

  /**
   * Claims one Slot on one authority's behalf.
   *
   * Contention is resolved here, not at offer time. offerSlots() proved this
   * candidate against a margin, because the neighbours were not knowable then;
   * this proves it against the neighbours that actually exist, and hands the
   * exact intervals it derived to the storage seam, which locks the days and
   * decides the race.
   *
   * Shared by the public booking path and by staff confirmation, so work a member
   * of staff books is claimed by the same code, against the same schedule, under
   * the same guard locks as work a visitor books. Everything that is not the
   * eligibility gate applies identically to both, including the requirement for a
   * session to own the Hold: a staff Hold is owned by the operator's own session
   * for the moments it exists, and is released rather than left to lapse if the
   * confirmation that follows it does not complete.
   */
  private function createHoldFor(
    DispatchRequest $request,
    Slot $slot,
    PlanAuthority $authority,
    DispatchRouteEstimatorInterface $estimator,
  ): HoldResult {
    // 1. Does dispatch own this Webform at all?
    //
    // A Webform still running the older fixed-slot workflow is refused here, so
    // the two guards can never both enforce the same submission.
    if (!$this->dispatchOwnsWebform($request->webformId)) {
      return HoldResult::refused(DispatchReason::NotConfigured);
    }

    // 2. Is there a session to own the Hold?
    //
    // An empty owner means no Hold may be claimed. It is not a reason to claim
    // one that nobody owns, which nothing could later confirm or release.
    $ownerId = $this->owner->ownerId();
    if ($ownerId === '') {
      $this->logger->notice('A Dispatch Hold was requested for @webform without a session to own it; refusing.', [
        '@webform' => $request->webformId,
      ]);
      return HoldResult::refused(DispatchReason::InvalidRequest);
    }

    $holdSeconds = $this->holdSeconds();
    if ($holdSeconds === NULL) {
      // A Hold with no configured lifetime would be a Hold with a lifetime this
      // class invented. Capacity is held for as long as an operator said, or not
      // at all.
      $this->logger->error('holds.hold_seconds is not configured, so no Dispatch Hold can be claimed.');
      return HoldResult::refused(DispatchReason::NotConfigured);
    }

    // 3. Normalise the Slot against the Resource it names.
    $normalised = $this->normalisedSlot($slot);
    if ($normalised === NULL) {
      return HoldResult::refused(DispatchReason::InvalidRequest);
    }
    [$slot, $profile] = $normalised;

    if ($slot->webformId !== $request->webformId) {
      // The Slot was issued for a different form than the one claiming it.
      $this->logger->warning('A Dispatch Hold for @webform presented a Slot issued for @slot_webform; refusing.', [
        '@webform' => $request->webformId,
        '@slot_webform' => $slot->webformId,
      ]);
      return HoldResult::refused(DispatchReason::InvalidRequest);
    }

    // 4. Derive the plan exactly, against the neighbours that actually exist.
    //
    // Advisory at this point: the guard rows are not held yet, so everything read
    // here can change before the claim is written. It is derived now because the
    // storage seam needs a candidate to know which days to lock.
    $plan = $this->preparePlanFor($request, $slot, $profile, NULL, $authority, $estimator);
    if ($plan instanceof DispatchReason) {
      return HoldResult::refused($plan);
    }

    // 5. Claim it, re-deriving the same plan while the guard rows are locked.
    //
    // The pre-transaction plan is never claimed on its own authority. Under the
    // locks the neighbours are re-read and every required leg must match a fresh
    // fact prepared above. The claim is written only if the window and protected
    // margins still fit. A competing neighbour absent from that prepared set makes
    // the callback refuse without performing provider I/O.
    $result = $this->store->claimWithRevalidation(
      new DispatchClaim(
        $plan->offer->slot,
        $plan->offer->window,
        $plan->inboundRepositionSeconds,
        $plan->outboundRepositionSeconds,
        $ownerId,
        $holdSeconds,
        $request->pickupAddress,
        $request->deliveryAddress,
        $plan->routeSeconds,
        $plan->routeMeters,
      ),
      // Read-only and provider-free: all external facts were prepared before the
      // Resource Calendar Guard was acquired.
      fn(): DispatchReason => $this->replanUnderLock($request, $plan, $profile, NULL, $authority, $estimator),
    );

    // 6. Keep the receipt in the session that owns it.
    //
    // This is what lets the submission path find its own Hold without the token
    // travelling through a form element, so the token need never be rendered.
    if ($result->hold !== NULL) {
      $this->owner->rememberHold($result->hold->slot, $result->hold->token, $result->hold->expiresAt);
    }

    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function confirmReservation(Hold $hold, WebformSubmissionInterface $submission): ReservationResult {
    return $this->confirmFor($hold, $submission, PlanAuthority::Visitor, $this->offerRouteEstimator);
  }

  /**
   * {@inheritdoc}
   */
  public function confirmCheckoutReservation(Hold $hold, WebformSubmissionInterface $submission): ReservationResult {
    return $this->confirmFor($hold, $submission, PlanAuthority::Visitor, $this->finalRouteEstimator);
  }

  /**
   * Confirms a live Hold on one authority's behalf.
   *
   * Liveness is not feasibility. That a Hold is unexpired and owned says only
   * that capacity is still reserved for this session; it says nothing about
   * whether the job the customer finally described still fits there. So the
   * request is rebuilt from what the submission actually stores, feasibility is
   * re-run against it inside the seam's transaction while the days are locked,
   * and the boundaries derived are compared with the boundaries reserved. A
   * changed plan refuses rather than quietly confirming a window that was proved
   * for a different job.
   */
  private function confirmFor(
    Hold $hold,
    WebformSubmissionInterface $submission,
    PlanAuthority $authority,
    DispatchRouteEstimatorInterface $estimator,
  ): ReservationResult {
    $submissionId = (int) $submission->id();
    if ($submissionId < 1) {
      // An unsaved submission has no identity to confirm against, and the seam
      // stores that identity as the link between the job and the capacity.
      $this->logger->warning('A Dispatch confirmation was attempted for an unsaved submission; refusing.');
      return ReservationResult::refused(DispatchReason::InvalidRequest);
    }

    $token = trim($hold->token);
    if ($token === '') {
      return ReservationResult::refused(DispatchReason::HoldNotFound);
    }

    // Ownership is proved by the store under its own locks. Reading the identity
    // here only decides whether there is one to present at all.
    $ownerId = $this->owner->ownerId();
    if ($ownerId === '') {
      $this->logger->notice('A Dispatch confirmation was attempted without a session to prove ownership; refusing.');
      return ReservationResult::refused(DispatchReason::HoldNotFound);
    }

    // Rebuilt from the saved submission, never from the Hold. The Hold reserved
    // capacity for a job described a certain way; only the submission says what
    // the job became. A NULL here also covers the cutover: settingsFor() returns
    // nothing unless this Webform is dispatch-enabled.
    $request = $this->requestFactory->fromSubmission($submission);
    if ($request === NULL || !$this->dispatchOwnsWebform($request->webformId)) {
      $this->logger->warning('Submission @sid could not be rebuilt into a dispatch-owned request, so its Hold was not confirmed.', [
        '@sid' => $submissionId,
      ]);
      return ReservationResult::refused(DispatchReason::NotConfigured);
    }

    // Read the Hold once without authority, solely to prepare every route fact the
    // locked revalidation may need. The store re-reads it under the guard below;
    // identity and plan comparisons make this advisory read safe to race.
    $preparedHold = $this->store->heldForPlanning($token, $ownerId);
    if ($preparedHold === NULL || $preparedHold->webformId !== $request->webformId) {
      return ReservationResult::refused(DispatchReason::HoldNotFound);
    }
    $normalised = $this->normalisedSlot($preparedHold->slot);
    if ($normalised === NULL) {
      return ReservationResult::refused(DispatchReason::NotConfigured);
    }
    [$preparedSlot, $profile] = $normalised;
    $preparedPlan = $this->preparePlanFor(
      $request,
      $preparedSlot,
      $profile,
      $preparedHold->reservationId,
      $authority,
      $estimator,
    );
    if ($preparedPlan instanceof DispatchReason) {
      return ReservationResult::refused($preparedPlan);
    }
    $preparedReason = $this->planStillHolds(
      $preparedPlan,
      $preparedHold->windowStart,
      $preparedHold->windowEnd,
      $preparedHold->inboundRepositionSeconds,
      $preparedHold->outboundRepositionSeconds,
      $request->webformId,
    );
    if ($preparedReason !== DispatchReason::Available) {
      return ReservationResult::refused($preparedReason);
    }

    $result = $this->store->confirmWithRevalidation(
      $token,
      $ownerId,
      $submissionId,
      // Read-only and provider-free. A neighbour not represented in the prepared
      // set refuses instead of becoming a network call under the lock.
      fn(LockedHold $locked): DispatchReason => $this->revalidate(
        $locked,
        $preparedHold,
        $preparedPlan,
        $request,
        $authority,
        $estimator,
      ),
    );

    if ($result->reservationId !== NULL) {
      // The Hold is consumed. Keeping the receipt would let a later submission
      // present a token for a Hold that no longer exists.
      $this->owner->forgetHold($token);
    }

    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function replan(int $dispatchReservationId, Slot $proposed, WebformSubmissionInterface $submission): ReplanResult {
    if ($dispatchReservationId < 1) {
      return ReplanResult::refused(DispatchReason::InvalidRequest);
    }

    $submissionId = (int) $submission->id();
    if ($submissionId < 1) {
      // Nothing unsaved can own a Reservation, so this is not its submission.
      return ReplanResult::refused(DispatchReason::InvalidRequest);
    }

    // What is actually there to move, read through the administrator-safe seam.
    // It reports nothing for a lapsed Hold, a released row, or one already
    // superseded: none of those hold capacity, so none of them can be moved.
    $claim = $this->store->adminClaim($dispatchReservationId);
    if ($claim === NULL || !$claim->isConfirmed()) {
      return ReplanResult::refused(DispatchReason::ReservationNotFound);
    }

    // The submission arrives from the caller, so it is checked against the row
    // rather than believed. A request rebuilt from the wrong submission would
    // derive a perfectly honest window for a different job and write it here.
    if ($claim->submissionId !== $submissionId) {
      $this->logger->warning('A Dispatch Replan of Reservation @id named submission @sid, which is not the one it records; refusing.', [
        '@id' => $dispatchReservationId,
        '@sid' => $submissionId,
      ]);
      return ReplanResult::refused(DispatchReason::InvalidRequest);
    }

    // Rebuilt from the submission, exactly as confirmation rebuilds it. This is
    // why a board drag is only a proposal: the addresses, service, weight and
    // handling that decide the duration and the route all come from here, and the
    // only thing the caller contributes is a candidate instant.
    $request = $this->requestFactory->fromSubmission($submission);
    if ($request === NULL || !$this->dispatchOwnsWebform($request->webformId)) {
      $this->logger->warning('Reservation @id could not be rebuilt into a dispatch-owned request, so it was not replanned.', [
        '@id' => $dispatchReservationId,
      ]);
      return ReplanResult::refused(DispatchReason::NotConfigured);
    }

    if ($claim->webformId !== $request->webformId
      || $proposed->webformId !== $request->webformId
      || $proposed->resourceId !== $claim->resourceId) {
      // A move changes when work happens, never which form it came through and
      // never which vehicle does it. Moving work between Resources would mean
      // vacating one calendar and filling another in a single transaction; that is
      // a release and a fresh booking, and the storage seam refuses it too.
      return ReplanResult::refused(DispatchReason::InvalidRequest);
    }

    $normalised = $this->normalisedSlot($proposed);
    if ($normalised === NULL) {
      return ReplanResult::refused(DispatchReason::InvalidRequest);
    }
    [$slot, $profile] = $normalised;

    // Derived by the one algorithm, with this Reservation left out of the
    // occupancy read so it cannot conflict with the space it is leaving. Every
    // other live claim, Blackout, break, working hour, horizon rule, predecessor
    // and successor Repositioning leg and return-to-base rule still applies.
    $plan = $this->preparePlanFor($request, $slot, $profile, $dispatchReservationId, PlanAuthority::Staff, $this->finalRouteEstimator);
    if ($plan instanceof DispatchReason) {
      return ReplanResult::refused($plan);
    }

    // Advisory until the seam locks the old and the new local days together and
    // the closure re-proves this same plan while they are held. What gets written
    // is the Dispatch Window derived here; nothing the caller sent describes one.
    return $this->store->replan(
      new DispatchReplan(
        $dispatchReservationId,
        $submissionId,
        $plan->offer->slot,
        $plan->offer->window,
        $plan->inboundRepositionSeconds,
        $plan->outboundRepositionSeconds,
        $request->pickupAddress,
        $request->deliveryAddress,
        $plan->routeSeconds,
        $plan->routeMeters,
      ),
      // Read-only and provider-free: the final-route facts above are the only
      // route answers this locked callback may consume.
      fn(): DispatchReason => $this->replanUnderLock($request, $plan, $profile, $dispatchReservationId, PlanAuthority::Staff, $this->finalRouteEstimator),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function scheduleBlackout(
    string $resourceId,
    string $localDate,
    string $startTime,
    string $endTime,
    string $reason,
    ?int $replacesBlackoutId = NULL,
  ): BlackoutResult {
    $profile = $this->profileFactory->createForResource(trim($resourceId));
    if ($profile === NULL) {
      // A Blackout removes time from one Resource's calendar. Without a Profile
      // there is no zone to read the local times in and no working day to check
      // them against, so there is nothing here to write.
      $this->logger->warning('A Dispatch Blackout named a Resource this installation does not operate; refusing.');
      return BlackoutResult::refused(DispatchReason::NotConfigured);
    }

    try {
      $zone = new \DateTimeZone($profile->timezone);
    }
    catch (\Exception) {
      $this->logger->error('Resource @resource is configured with an unusable timezone, so no Blackout can be scheduled.', [
        '@resource' => $profile->resourceId,
      ]);
      return BlackoutResult::refused(DispatchReason::NotConfigured);
    }

    $startAt = $this->localInstant($localDate, $startTime, $zone);
    $endAt = $this->localInstant($localDate, $endTime, $zone);
    if ($startAt === NULL || $endAt === NULL || $endAt <= $startAt) {
      // Interval order is settled here rather than left to the store, so an
      // operator who transposed the two times is told so instead of being given a
      // row that closes nothing. A span crossing midnight is refused by
      // construction, because both times are read on the one local date.
      return BlackoutResult::refused(DispatchReason::InvalidRequest);
    }

    // Working-hours policy, asked of the same calendar seam that decides whether a
    // Slot may be offered at all. NULL covers a malformed date, a date outside the
    // booking horizon, a weekday this Resource does not work, a configured
    // blackout, and a day with no usable interval — and a Blackout over hours
    // nobody works removes nothing while making the board claim it did.
    $day = $this->schedule->dayFor($profile, $localDate);
    if ($day === NULL) {
      return BlackoutResult::refused(DispatchReason::OutsideWorkingHours);
    }

    // Overlap, not containment. An operator closing a whole day may well give
    // times wider than the working interval, and that closure is meaningful; a
    // span lying entirely before the first or after the last working instant is
    // not, so it is refused rather than stored as a closure of nothing.
    if ($startAt >= $day->workEnd || $endAt <= $day->workStart) {
      return BlackoutResult::refused(DispatchReason::OutsideWorkingHours);
    }

    // Schedule impact is decided by the store, under the Resource Calendar Guard
    // rows for every local day the span touches, because deciding it here would
    // mean reading occupancy that nothing is holding still. A span over live work
    // is refused naming the claims in the way, never written over them.
    return $this->store->scheduleBlackoutGuarded(
      $profile->resourceId,
      $startAt,
      $endAt,
      $reason,
      $profile->timezone,
      $replacesBlackoutId,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function confirmForStaff(WebformSubmissionInterface $submission, Slot $slot): ReservationResult {
    $submissionId = (int) $submission->id();
    if ($submissionId < 1) {
      return ReservationResult::refused(DispatchReason::InvalidRequest);
    }

    // One job, one claim. Public confirmation is keyed by Hold token and one Hold
    // confirms once; this is keyed by the submission instead, so the same waiting
    // request can be confirmed from two tabs, or by two people who both saw it
    // waiting, and the vehicle would be sent to one move twice.
    if ($this->store->liveClaimForSubmission($submissionId) !== NULL) {
      return ReservationResult::refused(DispatchReason::AlreadyBooked);
    }

    $request = $this->requestFactory->fromSubmission($submission);
    if ($request === NULL || !$this->dispatchOwnsWebform($request->webformId)) {
      $this->logger->warning('Submission @sid could not be rebuilt into a dispatch-owned request, so staff could not book it.', [
        '@sid' => $submissionId,
      ]);
      return ReservationResult::refused(DispatchReason::NotConfigured);
    }

    if ($slot->webformId !== $request->webformId) {
      return ReservationResult::refused(DispatchReason::InvalidRequest);
    }

    // The same two-step transaction the public path runs, with staff authority
    // threaded through both halves. Nothing here re-implements a claim: the Hold
    // is taken under the guard locks, and the plan is re-proved under them again
    // at confirmation, so work staff book is proved exactly as often.
    $held = $this->createHoldFor($request, $slot, PlanAuthority::Staff, $this->finalRouteEstimator);
    if ($held->hold === NULL) {
      return ReservationResult::refused($held->reason);
    }

    $result = $this->confirmFor($held->hold, $submission, PlanAuthority::Staff, $this->finalRouteEstimator);
    if ($result->reservationId === NULL) {
      // The Hold was taken moments ago and is not going to be confirmed, so it is
      // handed back rather than left to lapse. Leaving it would withhold capacity
      // for the whole configured lifetime and show the board a Resource busy with
      // work that does not exist.
      $this->store->release($held->hold->token, $this->owner->ownerId());
      $this->owner->forgetHold($held->hold->token);
    }

    return $result;
  }

  /**
   * Resolves one local date and wall-clock time into a UTC instant.
   *
   * Strict on purpose, and strict elsewhere: the rule lives in LocalWallClock so
   * that a Blackout boundary here, a Replan arrival and a staff confirmation all
   * read a typed time identically. Refusing rather than correcting is the point —
   * a rolled-over time or a local time daylight saving skipped would otherwise
   * close a span on a moment the operator never chose.
   */
  private function localInstant(string $localDate, string $time, \DateTimeZone $zone): ?int {
    return LocalWallClock::instant($localDate, $time, $zone);
  }

  /**
   * Re-proves a locked Hold against the job the submission describes.
   *
   * Runs inside the storage seam's transaction with the Hold's guard days locked,
   * so the occupancy it reads cannot change under it and no concurrent claim can
   * take the space it is about to confirm.
   *
   * @return \Drupal\custom_webform_handlers\Dispatch\DispatchReason
   *   Available to confirm, or the reason the plan no longer holds.
   */
  private function revalidate(
    LockedHold $locked,
    LockedHold $preparedHold,
    SlotPlan $expected,
    DispatchRequest $request,
    PlanAuthority $authority,
    DispatchRouteEstimatorInterface $estimator,
  ): DispatchReason {
    if ($locked->reservationId !== $preparedHold->reservationId
      || $locked->webformId !== $request->webformId
      || $locked->slot->resourceId !== $preparedHold->slot->resourceId
      || $locked->slot->webformId !== $preparedHold->slot->webformId
      || $locked->slot->startTimestamp !== $preparedHold->slot->startTimestamp) {
      // The Hold belongs to a different form than the submission confirming it.
      return DispatchReason::InvalidRequest;
    }

    $normalised = $this->normalisedSlot($locked->slot);
    if ($normalised === NULL) {
      return DispatchReason::NotConfigured;
    }
    [$slot, $profile] = $normalised;

    // The reservation id is excluded from the occupancy read for one reason: a
    // Hold that conflicted with itself could never be confirmed. It is used here
    // and goes no further.
    $plan = $this->planFor(
      $request,
      $slot,
      $profile,
      $locked->reservationId,
      $authority,
      $estimator,
      $expected->routeFacts,
      FALSE,
    );
    if ($plan instanceof DispatchReason) {
      return $plan;
    }

    return $this->planStillHolds(
      $plan,
      $locked->windowStart,
      $locked->windowEnd,
      $locked->inboundRepositionSeconds,
      $locked->outboundRepositionSeconds,
      $request->webformId,
    );
  }

  /**
   * Re-derives a plan under the claim locks and reports whether it still holds.
   *
   * The claim-path counterpart to revalidate(). There is no stored row to compare
   * against yet, so the comparison is against the plan the planner derived moments
   * earlier, outside the transaction. Same question either way: is the interval
   * about to be written still the interval the day supports?
   */
  private function replanUnderLock(
    DispatchRequest $request,
    SlotPlan $expected,
    ResourceProfile $profile,
    ?int $excludeReservationId,
    PlanAuthority $authority,
    DispatchRouteEstimatorInterface $estimator,
  ): DispatchReason {
    $plan = $this->planFor(
      $request,
      $expected->offer->slot,
      $profile,
      $excludeReservationId,
      $authority,
      $estimator,
      $expected->routeFacts,
      FALSE,
    );
    if ($plan instanceof DispatchReason) {
      return $plan;
    }

    return $this->planStillHolds(
      $plan,
      $expected->offer->window->startTimestamp,
      $expected->offer->window->endTimestamp,
      $expected->inboundRepositionSeconds,
      $expected->outboundRepositionSeconds,
      $request->webformId,
    );
  }

  /**
   * Compares a freshly derived plan with the intervals already committed to.
   *
   * Used by both locked revalidation paths, so a claim and a confirmation apply
   * one definition of "the plan changed" rather than two that can drift apart.
   *
   * @return \Drupal\custom_webform_handlers\Dispatch\DispatchReason
   *   Available when the derived plan fits inside what was reserved, or the reason
   *   it does not.
   */
  private function planStillHolds(
    SlotPlan $plan,
    int $windowStart,
    int $windowEnd,
    int $inboundRepositionSeconds,
    int $outboundRepositionSeconds,
    string $webformId,
  ): DispatchReason {
    // The window reserved and the window now derived must be the same interval.
    // A different one means the facts changed — a longer job, a different route,
    // a re-configured buffer — and the capacity in question was proved for the job
    // as it was, not as it is.
    if ($plan->offer->window->startTimestamp !== $windowStart
      || $plan->offer->window->endTimestamp !== $windowEnd) {
      $this->logger->notice('Dispatch revalidation refused a plan on @webform: the recomputed Dispatch Window no longer matches the interval reserved.', [
        '@webform' => $webformId,
      ]);
      return DispatchReason::SlotUnavailable;
    }

    // Repositioning may have become cheaper, which is harmless: the reserved
    // margin then simply protects more than the plan needs. Needing more than was
    // reserved is not harmless — the space beyond the reservation was never
    // protected, and another claim may already hold it.
    if ($plan->inboundRepositionSeconds > $inboundRepositionSeconds
      || $plan->outboundRepositionSeconds > $outboundRepositionSeconds) {
      $this->logger->notice('Dispatch revalidation refused a plan on @webform: the sequence now needs more repositioning space than was reserved.', [
        '@webform' => $webformId,
      ]);
      return DispatchReason::RepositioningInfeasible;
    }

    return DispatchReason::Available;
  }

  /**
   * Proves one candidate exactly, with its real neighbours.
   *
   * This is the claim-time counterpart to feasibleOffers(). The schedule, break,
   * blackout, horizon, return-to-base and occupancy rules are the same ones; what
   * differs is repositioning. At offer time the neighbours are not knowable and a
   * margin is the honest instrument. Here exactly one candidate is in question, so
   * the two adjacent jobs can be read and their legs routed — which both refuses
   * sequences the margin would have allowed and accepts ones it would have
   * declined.
   *
   * @param \Drupal\custom_webform_handlers\Dispatch\DispatchRequest $request
   *   The normalized request being scheduled.
   * @param \Drupal\custom_webform_handlers\Dispatch\Slot $slot
   *   The exact candidate pickup arrival.
   * @param \Drupal\custom_webform_handlers\Dispatch\ResourceProfile $profile
   *   The selected resource's operating profile.
   * @param int|null $excludeReservationId
   *   A reservation to leave out of the occupancy read, used only so a Hold being
   *   confirmed, or a Reservation being moved, does not conflict with itself.
   * @param \Drupal\custom_webform_handlers\Dispatch\PlanAuthority $authority
   *   Who is asking. Selects which eligibility answer is read and has no other
   *   effect: every check below this gate is a fact about the vehicle and the day.
   * @param \Drupal\custom_webform_handlers\Dispatch\Route\DispatchRouteEstimatorInterface $estimator
   *   The trusted offer or final route estimator selected by the caller.
   *
   * @return \Drupal\custom_webform_handlers\Dispatch\SlotPlan|\Drupal\custom_webform_handlers\Dispatch\DispatchReason
   *   The proved plan, or the reason the candidate is not servable.
   */
  private function preparePlanFor(
    DispatchRequest $request,
    Slot $slot,
    ResourceProfile $profile,
    ?int $excludeReservationId,
    PlanAuthority $authority,
    DispatchRouteEstimatorInterface $estimator,
  ): SlotPlan|DispatchReason {
    return $this->planFor(
      $request,
      $slot,
      $profile,
      $excludeReservationId,
      $authority,
      $estimator,
      new PreparedRouteFacts(),
      TRUE,
    );
  }

  /**
   * Runs the single feasibility algorithm from prepared or fetchable route facts.
   *
   * $mayFetch is TRUE only before a Resource Calendar Guard transaction. Locked
   * callbacks pass FALSE and therefore either find an exact fresh fact prepared
   * above or refuse; no provider can be reached from the locked path.
   */
  private function planFor(
    DispatchRequest $request,
    Slot $slot,
    ResourceProfile $profile,
    ?int $excludeReservationId,
    PlanAuthority $authority,
    DispatchRouteEstimatorInterface $estimator,
    PreparedRouteFacts $facts,
    bool $mayFetch,
  ): SlotPlan|DispatchReason {
    // Eligibility, first and unconditionally. Whether this job may be booked at
    // all is not a question the presence of a Slot answers.
    //
    // This is the only line in the derivation that authority reaches. A service
    // class is required from both answers, because the duration policy is asked
    // for one and a window derived without a duration would be a window this
    // module invented — which is why a staff override that cannot name a class is
    // refused here exactly as a visitor's would be.
    $outcome = $authority === PlanAuthority::Staff
      ? $this->eligibility->assessForStaff($request)
      : $this->eligibility->assess($request);
    if (!$outcome->bookableBy($authority) || $outcome->serviceClass === NULL) {
      return $outcome->reason;
    }

    if ($profile->capacity < 1 || $profile->requiresDepotConfiguration()) {
      return DispatchReason::NotConfigured;
    }

    $day = $this->schedule->dayFor($profile, $slot->localDate());
    if ($day === NULL) {
      // dayFor() refuses a non-working day, a blackout date, a date outside the
      // horizon, a past date, and a malformed calendar alike, and does not say
      // which. Outside working hours is the honest summary of "this Resource
      // does not work then".
      return DispatchReason::OutsideWorkingHours;
    }
    if (!$slot->isAlignedTo($day->gridMinutes)) {
      return DispatchReason::SlotUnavailable;
    }
    if ($this->store->legacyUnplannedDates($profile->resourceId, $day->localDate, $day->localDate) !== []) {
      return DispatchReason::LegacyUnplanned;
    }

    $duration = $this->durationPolicy->durationFor($request, $outcome->serviceClass);
    if ($duration === NULL) {
      return DispatchReason::IncompleteFacts;
    }
    $promiseMinutes = $this->promiseMinutesFor($duration);
    if ($promiseMinutes === NULL) {
      return DispatchReason::NotConfigured;
    }

    $serviceLeg = $this->routeLeg($facts, $estimator, $request->pickupAddress, $request->deliveryAddress, $slot->startTimestamp, $mayFetch);
    if ($serviceLeg === NULL) {
      // Unroutable is unknown, and unknown never shortens a window.
      return DispatchReason::IncompleteFacts;
    }

    $routeMinutes = $serviceLeg->minutesCeiling();
    $operationalBuffer = $this->operationalBufferFor($duration, $profile);
    $windowMinutes = $duration->pickupMinutes + $routeMinutes + $duration->deliveryMinutes + $operationalBuffer;
    $start = $slot->startTimestamp;
    $end = $start + $windowMinutes * 60;

    if ($start <= $this->time->getRequestTime()) {
      // A pickup arrival that has already passed cannot be served, whether it is
      // being claimed or confirmed.
      return DispatchReason::SlotUnavailable;
    }
    if (!$day->contains($start, $end)) {
      return DispatchReason::OutsideWorkingHours;
    }
    if ($day->overlapsBreak($start, $end)) {
      return DispatchReason::BreakConflict;
    }

    $depot = trim($profile->depotAddress);
    if ($profile->returnToBase) {
      // Guaranteed non-empty: requiresDepotConfiguration() refused above.
      $returnLeg = $this->routeLeg($facts, $estimator, $request->deliveryAddress, $depot, $end, $mayFetch);
      if ($returnLeg === NULL) {
        return DispatchReason::IncompleteFacts;
      }
      if ($end + $returnLeg->minutesCeiling() * 60 > $day->workEnd) {
        return DispatchReason::ReturnToBaseInfeasible;
      }
    }

    $from = $day->workStart - self::OCCUPANCY_PAD_SECONDS;
    $to = $day->workEnd + self::OCCUPANCY_PAD_SECONDS;
    $occupied = $this->store->occupiedIntervals($profile->resourceId, $from, $to, $excludeReservationId);
    $neighbours = $this->store->planningNeighbours($profile->resourceId, $from, $to, $excludeReservationId);

    $repositionSeconds = max(0, $profile->repositioningBufferMinutes) * 60;

    // Inbound. The job the Resource is coming from, routed exactly; the depot
    // when nothing precedes this candidate on the day.
    $previous = $this->previousNeighbour($neighbours, $start);
    if ($previous !== NULL) {
      $origin = trim($previous->deliveryLocation);
      if ($origin === '') {
        // A neighbour with no stored address cannot be routed, and an
        // unroutable leg is unknown rather than free.
        return DispatchReason::IncompleteFacts;
      }
      $inboundLeg = $this->routeLeg($facts, $estimator, $origin, $request->pickupAddress, $previous->windowEnd, $mayFetch);
      if ($inboundLeg === NULL) {
        return DispatchReason::IncompleteFacts;
      }
      $inboundSeconds = $inboundLeg->minutesCeiling() * 60 + $repositionSeconds;
      if ($start - $inboundSeconds < $previous->windowEnd) {
        // The drive does not fit in the gap the previous job leaves.
        return DispatchReason::RepositioningInfeasible;
      }
    }
    elseif ($depot !== '') {
      $depotLeg = $this->routeLeg($facts, $estimator, $depot, $request->pickupAddress, $day->workStart, $mayFetch);
      if ($depotLeg === NULL) {
        return DispatchReason::IncompleteFacts;
      }
      $inboundSeconds = $depotLeg->minutesCeiling() * 60 + $repositionSeconds;
      if ($start - $inboundSeconds < $day->workStart) {
        // The driver cannot leave the depot early enough to arrive on time.
        return DispatchReason::RepositioningInfeasible;
      }
    }
    else {
      // No depot and nothing preceding: there is no departure point to prove a
      // first job against, so only the configured margin applies. That
      // combination is an operator choice, not a gap to fill with a guess.
      $inboundSeconds = $repositionSeconds;
    }

    // Outbound. The job the Resource is going to, routed exactly; the margin
    // alone when nothing follows.
    $next = $this->nextNeighbour($neighbours, $end);
    if ($next !== NULL) {
      $destination = trim($next->pickupLocation);
      if ($destination === '') {
        return DispatchReason::IncompleteFacts;
      }
      $outboundLeg = $this->routeLeg($facts, $estimator, $request->deliveryAddress, $destination, $end, $mayFetch);
      if ($outboundLeg === NULL) {
        return DispatchReason::IncompleteFacts;
      }
      $outboundSeconds = $outboundLeg->minutesCeiling() * 60 + $repositionSeconds;
      if ($end + $outboundSeconds > $next->windowStart) {
        return DispatchReason::RepositioningInfeasible;
      }
    }
    else {
      $outboundSeconds = $repositionSeconds;
    }

    // Occupancy last, over the interval the claim will actually protect. The
    // neighbour checks above prove the two adjacent gaps; this proves nothing
    // else — a Blackout, a Hold placed since the offer was read — sits inside
    // the protected span.
    if ($this->intrudes($occupied, $start - $inboundSeconds, $end + $outboundSeconds)) {
      return DispatchReason::SlotUnavailable;
    }

    return new SlotPlan(
      new SlotOffer(
        $slot,
        new DispatchWindow(
          $start,
          $end,
          $duration->pickupMinutes,
          $routeMinutes,
          $duration->deliveryMinutes,
          $operationalBuffer,
        ),
        $start + $promiseMinutes * 60,
        $start + ($duration->pickupMinutes + $routeMinutes) * 60,
      ),
      $inboundSeconds,
      $outboundSeconds,
      $serviceLeg->seconds,
      $serviceLeg->meters,
      $facts,
    );
  }

  /**
   * Returns the Slot restated in its Resource's own terms, with that Resource.
   *
   * A Slot arrives carrying a Resource id and a timezone, and neither can be
   * taken at face value: the timezone decides which local date the schedule is
   * read for, so a Slot naming a zone the Resource does not operate in would be
   * planned against the wrong day. The profile is authoritative for both, and a
   * Slot naming a Resource this installation does not run is refused outright
   * rather than silently planned against whichever Resource is configured.
   *
   * @return array{0: \Drupal\custom_webform_handlers\Dispatch\Slot, 1: \Drupal\custom_webform_handlers\Dispatch\ResourceProfile}|null
   *   The normalised Slot and its profile, or NULL when neither can be trusted.
   */
  private function normalisedSlot(Slot $slot): ?array {
    $profile = $this->profileFactory->createForResource($slot->resourceId);
    if ($profile === NULL) {
      $this->logger->warning('A Dispatch Slot named a Resource this installation does not operate; refusing.');
      return NULL;
    }

    if ($slot->timezone === $profile->timezone) {
      return [$slot, $profile];
    }

    try {
      $normalised = new Slot($slot->webformId, $profile->resourceId, $slot->startTimestamp, $profile->timezone);
    }
    catch (\InvalidArgumentException) {
      // A profile with an unusable timezone is a broken profile, not a Slot to
      // fall back on a default zone for.
      $this->logger->error('Resource @resource is configured with an unusable timezone, so no Slot can be planned.', [
        '@resource' => $profile->resourceId,
      ]);
      return NULL;
    }

    return [$normalised, $profile];
  }

  /**
   * Returns the job the Resource is coming from, or NULL for the day's first.
   *
   * The latest neighbour that has finished by the time this candidate opens. A
   * neighbour that overlaps the candidate is not a predecessor and is left to the
   * occupancy check, which refuses it.
   *
   * @param \Drupal\custom_webform_handlers\Dispatch\Storage\PlanningNeighbour[] $neighbours
   *   Neighbouring Dispatch Windows, with the addresses their legs need.
   * @param int $candidateStart
   *   The candidate Dispatch Window start, in UTC.
   */
  private function previousNeighbour(array $neighbours, int $candidateStart): ?PlanningNeighbour {
    $previous = NULL;
    foreach ($neighbours as $neighbour) {
      if ($neighbour->windowEnd > $candidateStart) {
        continue;
      }
      if ($previous === NULL || $neighbour->windowEnd > $previous->windowEnd) {
        $previous = $neighbour;
      }
    }

    return $previous;
  }

  /**
   * Returns the job the Resource is going to, or NULL when nothing follows.
   *
   * @param \Drupal\custom_webform_handlers\Dispatch\Storage\PlanningNeighbour[] $neighbours
   *   Neighbouring Dispatch Windows, with the addresses their legs need.
   * @param int $candidateEnd
   *   The candidate Dispatch Window end, in UTC.
   */
  private function nextNeighbour(array $neighbours, int $candidateEnd): ?PlanningNeighbour {
    $next = NULL;
    foreach ($neighbours as $neighbour) {
      if ($neighbour->windowStart < $candidateEnd) {
        continue;
      }
      if ($next === NULL || $neighbour->windowStart < $next->windowStart) {
        $next = $neighbour;
      }
    }

    return $next;
  }

  /**
   * Tells whether the dispatch planner, and not the older guard, owns a Webform.
   *
   * The rule itself lives on DispatchRequestFactory, which already decides what
   * "dispatch is configured here" means, so the planner, the booking endpoints, and
   * the Webform handler all read one definition and cannot disagree about which
   * workflow is enforcing. What this adds is the operator-facing half: a Webform with
   * both switches on is a misconfiguration that resolves silently in favour of the
   * guard already taking bookings, and silently is the wrong way for capacity
   * enforcement to change hands.
   */
  private function dispatchOwnsWebform(string $webformId): bool {
    if ($this->requestFactory->hasConflictingGuards($webformId)) {
      $this->logger->error('Webform @webform has both the fixed-slot reservation guard and dispatch enabled. Dispatch is standing down so only one guard enforces; disable reservation_enabled to complete the cutover.', [
        '@webform' => $webformId,
      ]);
      return FALSE;
    }

    return $this->requestFactory->dispatchOwns($webformId);
  }

  /**
   * Returns how long a Dispatch Hold lives, in seconds, or NULL.
   */
  private function holdSeconds(): ?int {
    return $this->holdWindow->seconds();
  }

  /**
   * Tests every grid candidate on one day and returns the feasible ones.
   *
   * @param \Drupal\custom_webform_handlers\Dispatch\DispatchRequest $request
   *   The normalized request being offered.
   * @param \Drupal\custom_webform_handlers\Dispatch\ResourceProfile $profile
   *   The resource's operating profile.
   * @param \Drupal\custom_webform_handlers\Dispatch\ResourceDay $day
   *   The local operating-day definition.
   * @param \Drupal\custom_webform_handlers\Dispatch\ServiceDuration $duration
   *   The normalized service work duration.
   * @param \Drupal\custom_webform_handlers\Dispatch\RouteEstimate $serviceLeg
   *   The prepared pickup-to-destination route.
   * @param \Drupal\custom_webform_handlers\Dispatch\RouteEstimate|null $depotLeg
   *   The prepared depot-to-pickup route, when required.
   * @param \Drupal\custom_webform_handlers\Dispatch\RouteEstimate|null $returnLeg
   *   The prepared destination-to-depot route, when required.
   * @param \Drupal\custom_webform_handlers\Dispatch\OccupiedInterval[] $occupied
   *   Advisory occupancy, including administrative Blackouts.
   * @param int $promiseMinutes
   *   The maximum customer-facing pickup promise.
   *
   * @return \Drupal\custom_webform_handlers\Dispatch\SlotOffer[]
   *   Feasible offers, in the order the grid produced them.
   */
  private function feasibleOffers(
    DispatchRequest $request,
    ResourceProfile $profile,
    ResourceDay $day,
    ServiceDuration $duration,
    RouteEstimate $serviceLeg,
    ?RouteEstimate $depotLeg,
    ?RouteEstimate $returnLeg,
    array $occupied,
    int $promiseMinutes,
  ): array {
    $routeMinutes = $serviceLeg->minutesCeiling();
    $operationalBuffer = $this->operationalBufferFor($duration, $profile);
    $windowMinutes = $duration->pickupMinutes + $routeMinutes + $duration->deliveryMinutes + $operationalBuffer;
    $repositionSeconds = max(0, $profile->repositioningBufferMinutes) * 60;
    $returnSeconds = $returnLeg === NULL ? 0 : $returnLeg->minutesCeiling() * 60;
    $now = $this->time->getRequestTime();

    // Stepped on the local clock so the published cadence follows the wall clock
    // an operator configured. Adding minutes to a local instant advances real
    // elapsed time, which is what a driver experiences across a DST transition;
    // any candidate that consequently lands off the local grid is dropped below
    // rather than published as an off-cadence time.
    $cursor = (new \DateTimeImmutable('@' . $day->workStart))
      ->setTimezone($profile->timezoneObject());

    $offers = [];
    for ($step = 0; $step < self::MAX_GRID_CANDIDATES; $step++) {
      $start = $cursor->getTimestamp();
      if ($start > $day->workEnd) {
        break;
      }

      $next = $cursor->modify('+' . $day->gridMinutes . ' minutes');
      if ($next->getTimestamp() <= $start) {
        // Defensive: a cursor that cannot advance would loop forever.
        break;
      }
      $cursor = $next;

      // A pickup time that has already passed is not bookable, whatever the
      // schedule says about it.
      if ($start <= $now) {
        continue;
      }

      $end = $start + $windowMinutes * 60;

      // The whole occupied window must sit inside the working interval, not just
      // its start: a job that begins at 17:30 and runs three hours is not a
      // 17:30 opening.
      if (!$day->contains($start, $end)) {
        continue;
      }
      if ($day->overlapsBreak($start, $end)) {
        continue;
      }

      // Return to base, when required, must also land inside working hours. The
      // operational buffer is already inside $end; the return drive is not, and
      // it is not padded with the repositioning buffer because returning to base
      // is not travel between two customers' jobs.
      if ($profile->returnToBase && $end + $returnSeconds > $day->workEnd) {
        continue;
      }

      // Inbound space. A candidate that follows another job needs travel room
      // from an unknown previous delivery, which is enforced as margin. A
      // candidate that is the day's first job departs the depot, whose address is
      // known, so that leg is proved exactly against the start of the shift.
      $inboundSeconds = $repositionSeconds;
      if (!$this->hasEarlierJob($occupied, $day->workStart, $start)) {
        if ($depotLeg !== NULL) {
          $inboundSeconds = $depotLeg->minutesCeiling() * 60 + $repositionSeconds;
          if ($start - $inboundSeconds < $day->workStart) {
            // The driver cannot leave the depot early enough to arrive on time.
            continue;
          }
        }
        // With no depot configured and no return-to-base requirement there is no
        // departure point to prove a first job against, so only the margin
        // applies. That combination is an operator choice, and the planner does
        // not invent a depot to compensate.
      }

      if ($this->intrudes($occupied, $start - $inboundSeconds, $end + $repositionSeconds)) {
        continue;
      }

      $slot = new Slot($request->webformId, $profile->resourceId, $start, $profile->timezone);
      if (!$slot->isAlignedTo($day->gridMinutes)) {
        // Only reachable when local-clock arithmetic crossed a transition that
        // is not a whole multiple of the grid. Publishing such a time would show
        // a cadence the operator did not configure.
        continue;
      }

      $offers[] = new SlotOffer(
        $slot,
        new DispatchWindow(
          $start,
          $end,
          $duration->pickupMinutes,
          $routeMinutes,
          $duration->deliveryMinutes,
          $operationalBuffer,
        ),
        $start + $promiseMinutes * 60,
        $start + ($duration->pickupMinutes + $routeMinutes) * 60,
      );
    }

    return $offers;
  }

  /**
   * Tells whether the Resource is already out on a job before this candidate.
   *
   * Blackouts are excluded deliberately: an administrative closure does not put
   * the driver on the road, so a candidate after one still starts from the depot.
   *
   * @param \Drupal\custom_webform_handlers\Dispatch\OccupiedInterval[] $occupied
   *   Advisory occupancy.
   * @param int $dayStart
   *   The local operating day's start, represented in UTC.
   * @param int $candidateStart
   *   The candidate pickup arrival, in UTC.
   */
  private function hasEarlierJob(array $occupied, int $dayStart, int $candidateStart): bool {
    foreach ($occupied as $interval) {
      if ($interval->source === OccupiedInterval::SOURCE_BLACKOUT) {
        continue;
      }
      if ($interval->endTimestamp <= $candidateStart && $interval->endTimestamp > $dayStart) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Tells whether anything occupies the Resource inside a protected interval.
   *
   * The interval includes the repositioning margins, so a neighbouring job that
   * leaves no travel room is a conflict rather than a tight fit. Blackouts are
   * treated the same way: a closure that overlaps only the margin still means the
   * Resource is not free when the sequence needs it.
   *
   * Capacity above one is not exploited here. Any overlap refuses the candidate,
   * which under-offers for a multi-capacity Resource and over-offers for none.
   *
   * @param \Drupal\custom_webform_handlers\Dispatch\OccupiedInterval[] $occupied
   *   Advisory occupancy.
   * @param int $protectedStart
   *   The beginning of the protected capacity interval.
   * @param int $protectedEnd
   *   The end of the protected capacity interval.
   */
  private function intrudes(array $occupied, int $protectedStart, int $protectedEnd): bool {
    foreach ($occupied as $interval) {
      if ($interval->intersects($protectedStart, $protectedEnd)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Estimates one leg, reusing an estimate already made this invocation.
   *
   * The memo is local to a single offerSlots() or planFor() call, so nothing is
   * carried between requests and a schedule change cannot be served from a stale
   * figure.
   *
   * The departure instant is part of the key, and has to be: the estimator is
   * traffic-aware, so the same pair of addresses is a different answer at 08:00 and
   * at 17:00. Keying on the pair alone would hand every leg on the day the traffic
   * conditions of whichever leg happened to be measured first. This costs nothing in
   * requests — no two lookups in either caller share both a pair and an instant —
   * and the adapter's own bucketing is what keeps a day of scheduling down to a
   * handful of billed calls.
   *
   * A leg whose figure carries no traffic data is refused outright when the
   * operating profile requires traffic-aware scheduling. That refusal is here, at the
   * one seam every route lookup passes through, so Offer, Hold, Confirm and Replan
   * cannot diverge on it.
   *
   * PreparedRouteFacts is the invocation-local memo. When $mayFetch is FALSE this
   * method is a pure validation read and cannot reach the provider.
   */
  private function routeLeg(
    PreparedRouteFacts $facts,
    DispatchRouteEstimatorInterface $estimator,
    string $origin,
    string $destination,
    int $departureAt,
    bool $mayFetch,
  ): ?RouteEstimate {
    $estimate = $facts->find($origin, $destination, $departureAt);
    if ($estimate === NULL && $mayFetch) {
      $estimate = $estimator->estimate($origin, $destination, $departureAt);
      if ($estimate !== NULL) {
        $facts->remember($origin, $destination, $departureAt, $estimate);
      }
    }

    if ($estimate === NULL
      || !$estimator->accepts($estimate, $origin, $destination, $departureAt)) {
      return NULL;
    }

    if (!$estimate->trafficAware && $this->requireTrafficAware()) {
      // The profile says a schedule may only be built on real traffic data. The
      // leg is treated as unmeasurable rather than accepted with a margin, which
      // withholds the Slot and leaves the request for staff to place by hand.
      $this->logger->notice('Route leg is not traffic-aware and routing.require_traffic_aware is set, so the leg is refused.');
      return NULL;
    }

    return $estimate;
  }

  /**
   * Returns whether only traffic-aware legs may be scheduled against.
   *
   * Defaults to FALSE, which accepts the estimator's conservatively widened figure
   * when traffic data is unavailable. Setting it makes the planner strict instead:
   * no traffic data, no Slots, and the request reaches staff rather than being
   * placed on an assumption.
   */
  private function requireTrafficAware(): bool {
    return (bool) $this->configFactory->get(self::SETTINGS)->get('routing.require_traffic_aware');
  }

  /**
   * Returns the end-of-window buffer to apply, in minutes.
   *
   * A service-specific buffer wins over the Resource Profile's, because how much
   * padding a job needs is a property of the job rather than of the van doing it:
   * a twenty-minute courier run and a full-day move do not want the same absolute
   * figure. NULL on the duration means the service declined to override, so the
   * profile's figure stands and today's behaviour is unchanged.
   */
  private function operationalBufferFor(ServiceDuration $duration, ResourceProfile $profile): int {
    return max(0, $duration->operationalBufferMinutes ?? $profile->operationalBufferMinutes);
  }

  /**
   * Returns the arrival promise width to state, in minutes, or NULL.
   *
   * Same precedence as the buffer, and the same reason. NULL from both the service
   * and the global setting is still fatal to an offer: an arrival promise is part
   * of what an offer says, and a width nobody configured is not one to invent.
   */
  private function promiseMinutesFor(ServiceDuration $duration): ?int {
    return $duration->promiseMinutes ?? $this->pickupPromiseMinutes();
  }

  /**
   * Returns the configured width of the arrival promise, or NULL.
   */
  private function pickupPromiseMinutes(): ?int {
    $minutes = $this->configFactory->get(self::SETTINGS)->get('durations.pickup_promise_minutes');
    if (!is_numeric($minutes)) {
      return NULL;
    }
    $minutes = (int) $minutes;

    return $minutes > 0 ? $minutes : NULL;
  }

}
