<?php

namespace Drupal\custom_webform_handlers\Dispatch;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\custom_webform_handlers\Dispatch\Policy\DispatchEligibilityInterface;
use Drupal\custom_webform_handlers\Dispatch\Storage\ReservationStoreInterface;
use Psr\Log\LoggerInterface;

/**
 * The booking operations a request handler is allowed to perform.
 *
 * This exists because neither of the two seams underneath it can serve a controller
 * directly, and forcing one of them to would damage it.
 *
 * DispatchPlannerInterface is three operations — offer, hold, confirm — and its
 * docblock is explicit that anything letting a caller reach or mutate a row is
 * deliberately absent. Renew and Release are row mutations, so they cannot go there.
 * ReservationStoreInterface has them, but is equally explicit that it is not a
 * caller-facing interface: it deals in raw bearer tokens and owner identifiers, and
 * a controller holding either is one refactor away from putting them in a response.
 *
 * So this is the seam that composes them, and the composition is what makes the
 * secrecy structural rather than a rule someone has to remember:
 *
 * - Nothing here accepts a token. A Hold is named by the Slot it was taken for, and
 *   the token is recovered from the session receipt that this same session was
 *   given when it claimed. A browser that has lost its receipt cannot renew or
 *   release by presenting a token it kept, because there is no parameter for one.
 * - Nothing here returns a token. Claiming reports when the Hold expires, which is
 *   what a countdown needs, and the token stays in the session.
 * - Nothing here returns a Dispatch Window, an occupancy interval, or a neighbour.
 *   The published shape of an offer is the customer's own arrival promise and
 *   estimated delivery, so enumerating the endpoint reveals what is bookable and
 *   not how the Resource's day is arranged.
 *
 * Ownership is proved by the database on every call, not by the receipt. The receipt
 * only reproduces a token this session already had; the store still re-checks the
 * token hash, the owner hash, the state, and the expiry inside its own transaction.
 */
final class DispatchBooking {

  public function __construct(
    private readonly DispatchPlannerInterface $planner,
    private readonly ReservationStoreInterface $store,
    private readonly DispatchOwner $owner,
    private readonly DispatchRequestFactory $requestFactory,
    private readonly ResourceProfileFactory $profileFactory,
    private readonly DispatchHoldWindow $holdWindow,
    private readonly DispatchEligibilityInterface $eligibility,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Returns the selectable Slots on one local date.
   *
   * The values are the visitor's current, unsaved answers, which is what makes this
   * useful mid-form: what is offerable depends on the job, and the job is not saved
   * yet. They are not trusted as facts — DispatchRequestFactory normalises them
   * server-side through the configured element mapping, exactly as it will later
   * normalise the saved submission. Nothing a browser sends describes a window.
   *
   * Two different answers arrive as two different payloads. "Nothing is free on this
   * date" is an empty Slot list; "this job is not self-bookable at all" is the
   * manual_review flag. The planner cannot distinguish them for a caller — it
   * returns no Slots for either — and collapsing them would put a visitor whose job
   * needs staff into an endless hunt through dates that can never offer anything.
   *
   * A third answer is separated for the same reason: "this request is not finished
   * yet" is the incomplete flag, with manual_review FALSE. It offers nothing, so it
   * is exactly as fail-safe as the refusal it used to be folded into, but a visitor
   * who has not reached the weight question has not asked staff for anything and
   * must not be told that staff will price their job.
   *
   * The manual_review flag stays a collapsed signal rather than the underlying
   * reason. review_code names the refusal only where the visitor's own declared
   * answer is the cause; offer is cheap and repeatable, so publishing
   * "over_weight_limit" would let a caller binary-search the configured weight limit
   * by resubmitting, and that reason collapses into a generic code instead. See
   * publicReviewCode(). Eligibility is also assessed here before any calendar is
   * consulted, from the customer's own answers alone, so saying that staff must look
   * at it reveals nothing about the Resource's day or anybody else's booking.
   */
  public function offer(string $webformId, array $values, string $localDate): BookingOutcome {
    if (!$this->dispatchOwns($webformId)) {
      return BookingOutcome::refused(DispatchReason::NotConfigured);
    }

    if (!$this->isLocalDate($localDate)) {
      return BookingOutcome::refused(DispatchReason::InvalidRequest);
    }

    // The day to plan is the day the customer's own form carries, not the day the
    // request body names. The two normally agree — the widget reads that element to
    // build the request — and when they do not, the body is the one that can be
    // edited without leaving a trace in the submission. Refusing the disagreement
    // rather than preferring one side keeps the offered times and the submitted
    // date provably the same fact, which is what the arrival check at validation
    // time then has something to compare against.
    //
    // An unmapped element leaves $localDate as the only date there is; that is a
    // configuration without a canonical date field, and it behaves as it did
    // before one existed.
    if ($this->requestFactory->requestedDateElement() !== '') {
      $requested = $this->requestFactory->requestedDate($values);
      if ($requested === '' || !$this->isLocalDate($requested) || $requested !== $localDate) {
        return BookingOutcome::refused(DispatchReason::InvalidRequest);
      }
      $localDate = $requested;
    }

    $request = $this->requestFactory->fromValues($webformId, $values);
    if ($request === NULL) {
      return BookingOutcome::refused(DispatchReason::InvalidRequest);
    }

    $profile = $this->profileFactory->create();
    if ($profile === NULL) {
      return BookingOutcome::refused(DispatchReason::NotConfigured);
    }

    // Asked before the planner is, because the planner answers a request that
    // needs staff the same way it answers a fully-booked day, and the visitor has
    // to be told which one happened.
    $eligibility = $this->eligibility->assess($request);
    if (!$eligibility->selfBookable) {
      $this->logger->debug('Dispatch offer routed Webform @webform to Manual Review: @reason', [
        '@webform' => $webformId,
        '@reason' => $eligibility->reason->value,
      ]);

      // A request that has not been finished yet is not a request staff have to
      // price. Both answers offer nothing, so the refusal is as fail-safe as it
      // was; they are separated because the visitor is owed a different sentence.
      // Telling someone mid-form that their job needs manual review is wrong
      // about their job and teaches them to stop filling the form in.
      if ($eligibility->reason === DispatchReason::IncompleteFacts) {
        return BookingOutcome::success([
          'date' => $localDate,
          'timezone' => $profile->timezone,
          'slots' => [],
          'manual_review' => FALSE,
          'incomplete' => TRUE,
        ]);
      }

      return BookingOutcome::success([
        'date' => $localDate,
        'timezone' => $profile->timezone,
        'slots' => [],
        'manual_review' => TRUE,
        'incomplete' => FALSE,
        'review_code' => $this->publicReviewCode($eligibility->reason),
      ]);
    }

    $slots = [];
    foreach ($this->planner->offerSlots($request, $localDate, $profile) as $offer) {
      $slots[] = [
        // The pickup arrival instant, which is the only value the browser sends
        // back to claim. Absolute, so a client-side zone cannot shift it.
        'at' => $offer->slot->startTimestamp,
        'time' => $offer->slot->localTime(),
        'promise_start' => $offer->pickupPromiseStart(),
        'promise_end' => $offer->pickupPromiseEnd,
        'estimated_delivery_at' => $offer->estimatedDeliveryAt,
      ];
    }

    return BookingOutcome::success([
      'date' => $localDate,
      'timezone' => $profile->timezone,
      'slots' => $slots,
      'manual_review' => FALSE,
      'incomplete' => FALSE,
    ]);
  }

  /**
   * Names a refusal publicly only when the visitor's own answer is the cause.
   *
   * The reason enum is staff-facing and stays that way: offer is cheap and
   * repeatable, so a code that named a configured threshold would let a caller
   * binary-search it by resubmitting. A code that repeats a declaration the
   * visitor just made discloses nothing they do not already know, and it is the
   * only way to tell them which answer to revisit — so those, and only those,
   * are published. Everything else, over_weight_limit included, collapses into
   * one generic code.
   */
  private function publicReviewCode(DispatchReason $reason): string {
    return match ($reason) {
      DispatchReason::MultiPersonHandling => 'two_person_handling',
      DispatchReason::ManualReview => 'contents_or_add_ons',
      DispatchReason::StorePickupNotReady => 'store_pickup_not_ready',
      DispatchReason::UnsupportedDeliveryMode => 'unsupported_delivery_mode',
      DispatchReason::UnsupportedService => 'unsupported_service',
      default => 'staff_review',
    };
  }

  /**
   * Claims one Slot for this session.
   *
   * An empty result from offer() and a refusal here are different events: the first
   * says nothing was bookable when the visitor looked, the second says this Slot is
   * not claimable now. Contention is resolved here and not at offer time, so losing
   * the race is an ordinary outcome that arrives as a reason rather than an error.
   *
   * Eligibility is re-assessed rather than assumed. This endpoint takes a pickup
   * instant from the browser, and until now it took it on trust: nothing between
   * here and the Hold asked whether *this request* was ever self-bookable, so a
   * crafted or stale arrival posted for a Manual Review service — On-Demand
   * Delivery, or any of the five services nobody is piloting — would have been
   * held against the driver's day. The assessment is the same one offer() ran, from
   * the same policy and therefore the same BookingProfile, so a Slot the customer
   * legitimately saw is unaffected and there is no second copy of the rules to
   * disagree with the first.
   */
  public function hold(string $webformId, array $values, int $pickupArrival): BookingOutcome {
    if (!$this->dispatchOwns($webformId)) {
      return BookingOutcome::refused(DispatchReason::NotConfigured);
    }

    $request = $this->requestFactory->fromValues($webformId, $values);
    if ($request === NULL) {
      return BookingOutcome::refused(DispatchReason::InvalidRequest);
    }

    $eligibility = $this->eligibility->assess($request);
    if (!$eligibility->selfBookable) {
      return BookingOutcome::refused($eligibility->reason);
    }

    $slot = $this->slotFor($webformId, $pickupArrival);
    if ($slot === NULL) {
      return BookingOutcome::refused(DispatchReason::InvalidRequest);
    }

    $result = $this->planner->createHold($request, $slot);
    if ($result->hold === NULL) {
      return BookingOutcome::refused($result->reason);
    }

    // The token is already in the session; what the browser gets is the deadline.
    return BookingOutcome::success($this->receipt($result->hold));
  }

  /**
   * Extends the Hold this session took on one Slot.
   *
   * Named by Slot, because the only Hold a session may extend is one it already has
   * a receipt for. There is no request shape here in which a caller supplies a token
   * for a Hold it was not given.
   */
  public function renew(string $webformId, int $pickupArrival): BookingOutcome {
    $held = $this->ownedHold($webformId, $pickupArrival);
    if (!$held instanceof Hold) {
      return $held;
    }

    $ownerId = $this->owner->ownerId();
    if ($ownerId === '') {
      return BookingOutcome::refused(DispatchReason::HoldNotFound);
    }

    $holdSeconds = $this->holdWindow->seconds();
    if ($holdSeconds === NULL) {
      return BookingOutcome::refused(DispatchReason::NotConfigured);
    }

    $expiresAt = $this->store->renew($held->token, $ownerId, $holdSeconds);
    if ($expiresAt === NULL) {
      // Lapsed, released, confirmed, or never this session's. The receipt is
      // stale either way, and keeping it would let the submission path present a
      // token for a Hold that no longer exists.
      $this->owner->forgetHold($held->token);
      return BookingOutcome::refused(DispatchReason::HoldNotFound);
    }

    $renewed = new Hold($held->slot, $held->token, $expiresAt);
    $this->owner->rememberHold($renewed->slot, $renewed->token, $renewed->expiresAt);

    return BookingOutcome::success($this->receipt($renewed));
  }

  /**
   * Gives up the Hold this session took on one Slot.
   *
   * Releasing returns capacity to everyone else immediately rather than waiting out
   * the expiry, which is the whole reason a visitor changing their mind should reach
   * this endpoint. A confirmed Reservation is never released here; that is an
   * administrative action with its own audit trail.
   */
  public function release(string $webformId, int $pickupArrival): BookingOutcome {
    $held = $this->ownedHold($webformId, $pickupArrival);
    if (!$held instanceof Hold) {
      return $held;
    }

    $ownerId = $this->owner->ownerId();
    if ($ownerId === '') {
      return BookingOutcome::refused(DispatchReason::HoldNotFound);
    }

    $released = $this->store->release($held->token, $ownerId);

    // Dropped whatever the store said. If it released, the Hold is gone; if it
    // did not, this receipt does not name a live Hold this session owns, so it is
    // stale. Neither case is one where keeping it helps.
    $this->owner->forgetHold($held->token);

    return $released
      ? BookingOutcome::success(['at' => $held->slot->startTimestamp])
      : BookingOutcome::refused(DispatchReason::HoldNotFound);
  }

  /**
   * Resolves the Hold this session recorded for a Slot, or the refusal to return.
   *
   * @return \Drupal\custom_webform_handlers\Dispatch\Hold|\Drupal\custom_webform_handlers\Dispatch\BookingOutcome
   *   The receipt, or the outcome the caller should return unchanged.
   */
  private function ownedHold(string $webformId, int $pickupArrival): Hold|BookingOutcome {
    if (!$this->dispatchOwns($webformId)) {
      return BookingOutcome::refused(DispatchReason::NotConfigured);
    }

    $slot = $this->slotFor($webformId, $pickupArrival);
    if ($slot === NULL) {
      return BookingOutcome::refused(DispatchReason::InvalidRequest);
    }

    $hold = $this->owner->heldSlot($slot);
    if ($hold === NULL) {
      // No receipt, so this session was never given a token for this Slot. Said
      // the same way whether the Slot is held by somebody else or by nobody, so
      // the endpoint cannot be used to probe who holds what.
      return BookingOutcome::refused(DispatchReason::HoldNotFound);
    }

    return $hold;
  }

  /**
   * Describes a live Hold in the only terms a browser needs.
   *
   * The deadline and the seconds remaining, so a countdown does not depend on the
   * visitor's clock agreeing with the server's. No token, and no window.
   */
  private function receipt(Hold $hold): array {
    return [
      'at' => $hold->slot->startTimestamp,
      'time' => $hold->slot->localTime(),
      'expires_at' => $hold->expiresAt,
      'expires_in' => max(0, $hold->expiresAt - $this->time->getCurrentTime()),
    ];
  }

  /**
   * Rebuilds the Slot a pickup-arrival instant names, or NULL.
   *
   * The Resource and the timezone come from the operating profile, never from the
   * request: the browser names an instant, and the server decides whose capacity
   * that instant belongs to. Grid alignment, working hours, and every other rule are
   * left to the planner, which re-derives them under its own locks.
   */
  private function slotFor(string $webformId, int $pickupArrival): ?Slot {
    if ($pickupArrival < 1) {
      return NULL;
    }

    $profile = $this->profileFactory->create();
    if ($profile === NULL) {
      return NULL;
    }

    try {
      return new Slot($webformId, $profile->resourceId, $pickupArrival, $profile->timezone);
    }
    catch (\InvalidArgumentException $e) {
      // The profile's zone is not one this server recognises. Slot refuses to
      // substitute UTC, and so does this: an instant read in the wrong zone
      // passes every check and puts the driver at the wrong hour.
      $this->logger->error('Dispatch booking could not build a Slot for Webform @webform: @message', [
        '@webform' => $webformId,
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Applies the cutover interlock, from the one definition of it.
   */
  private function dispatchOwns(string $webformId): bool {
    return $this->requestFactory->dispatchOwns($webformId);
  }

  /**
   * Tells whether a string is a real Y-m-d calendar date.
   *
   * Round-tripped rather than pattern-matched, so '2026-02-30' is refused instead
   * of rolling forward into March and quietly planning a different day.
   */
  private function isLocalDate(string $localDate): bool {
    $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $localDate, new \DateTimeZone('UTC'));

    return $parsed !== FALSE && $parsed->format('Y-m-d') === $localDate;
  }

}
