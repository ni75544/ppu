<?php

namespace Drupal\custom_webform_handlers\Dispatch;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\custom_webform_handlers\Dispatch\Notify\DispatchNotification;
use Drupal\custom_webform_handlers\Dispatch\Notify\DispatchNotifierInterface;
use Drupal\custom_webform_handlers\Dispatch\Storage\ReservationStoreInterface;
use Drupal\webform\WebformSubmissionInterface;
use Psr\Log\LoggerInterface;

/**
 * The one seam staff operations go through.
 *
 * This exists for the same reason DispatchBooking exists on the public path:
 * neither of the two seams underneath it can serve a form directly, and forcing
 * one of them to would damage it. The planner takes a Slot and a submission
 * entity, because that is what it needs to prove a plan; the storage seam takes
 * raw Hold tokens and owner identifiers, because that is how the public path
 * proves ownership. A form has none of those things. It has an identifier from a
 * route, a date and a time somebody typed, and a submit handler.
 *
 * So the translation happens here, once. Every form and the operations endpoint
 * depend on this class and nothing below it, which means:
 *
 * - No form holds a Hold token or an owner identifier. Staff authority is the
 *   route permission, never possession of a secret, so there is nothing for a
 *   form to leak into a page, a log or a redirect.
 * - No form parses a wall-clock time its own way. A typed time becomes an
 *   instant through LocalWallClock, so the same keystrokes mean the same moment
 *   on the Replan form, the Blackout form and the board's drag handler.
 * - No form decides feasibility. Every method here ends in a planner or store
 *   call that does its own locking and its own re-proving, and returns that
 *   answer unchanged. There is no second planning algorithm in this file, and a
 *   refusal is passed on rather than worked around.
 *
 * Nothing here mutates on a read. claim(), blackout(), blackoutsFor() and
 * profile() are the read half and touch nothing; release(), replan(),
 * withholdTime(), withdrawBlackout() and confirmManualReview() are the write
 * half, and each of those is only ever reached from a form submit or a POST.
 *
 * Announcements are made by the five write methods and by nothing else, always as
 * the last thing they do. That position is the whole of the rule: the planner call
 * has returned, so its transaction has committed and its Resource Calendar Guard
 * locks are released, and only then is a notification handed to the notifier —
 * which enqueues and returns rather than sending. Two properties follow, and both
 * matter more than the tidiness. A mail server that has stopped answering can never
 * hold a capacity lock open, because it is not reached while one is held. And a
 * refused operation announces nothing, because there is no committed fact to
 * announce — a Replan the planner declined leaves the Reservation exactly where it
 * was, and telling a customer otherwise would be the one lie this seam could tell.
 *
 * A release and a withdrawal both read their subject before they change it, since
 * afterwards there is nothing left to describe. Those reads take no lock and use the
 * same administrator-safe projections the board uses.
 */
final class DispatchOperations {

  /**
   * Constructs the staff operations seam.
   *
   * @param \Drupal\custom_webform_handlers\Dispatch\DispatchStaffPlannerInterface $planner
   *   The narrow staff contract: replan, withhold, confirm on staff authority.
   * @param \Drupal\custom_webform_handlers\Dispatch\Storage\ReservationStoreInterface $store
   *   Reached for the administrative reads and the administrative release only.
   * @param \Drupal\custom_webform_handlers\Dispatch\ResourceProfileFactory $profileFactory
   *   Resolves a Resource, and refuses one this installation does not operate.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Loads the submission that describes the job being planned.
   * @param \Drupal\custom_webform_handlers\Dispatch\Notify\DispatchNotifierInterface $notifier
   *   Given committed facts, after the locks are gone. It enqueues and returns; it
   *   cannot send, cannot fail back to this class, and is never called on a refusal.
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   *   Read only to record which account caused a committed fact. Authority is the
   *   route permission — nothing here consults this account to decide anything.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   Stamps an announcement with when the fact became true. Only the notify path
   *   uses it: no scheduling decision in this class asks what time it is.
   * @param \Psr\Log\LoggerInterface $logger
   *   Records what staff did, including the operations that changed nothing.
   */
  public function __construct(
    private readonly DispatchStaffPlannerInterface $planner,
    private readonly ReservationStoreInterface $store,
    private readonly ResourceProfileFactory $profileFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly DispatchNotifierInterface $notifier,
    private readonly AccountInterface $currentUser,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Reads the little that may be said about one claim, Hold or Reservation.
   *
   * What a confirmation page needs: enough to describe truthfully what is about
   * to be released, and no more. A Hold reports its Resource, the span it blocks,
   * its state and its expiry; nothing here reaches a customer's address, Webform,
   * submission or price for a Hold, because the visitor who created it has not
   * booked anything.
   *
   * @param int $dispatchReservationId
   *   The claim to summarise.
   *
   * @return \Drupal\custom_webform_handlers\Dispatch\DispatchClaimSummary|null
   *   The summary, or NULL when nothing carrying that identifier still holds
   *   capacity. A released, expired, cancelled or superseded row answers NULL:
   *   there is nothing left to do to it, so there is nothing to confirm.
   */
  public function claim(int $dispatchReservationId): ?DispatchClaimSummary {
    if ($dispatchReservationId < 1) {
      return NULL;
    }

    return $this->store->adminClaim($dispatchReservationId);
  }

  /**
   * Frees the capacity one claim holds, on staff authority.
   *
   * The expected state is the state the operator was shown, and it is carried
   * through to the locked re-read rather than dropped: a form that offered to
   * drop an unfinished Hold must not cancel a booking the customer completed
   * while the confirmation page sat open. A stale page therefore changes nothing
   * and says so.
   *
   * @param int $dispatchReservationId
   *   The claim to free.
   * @param string $expectedState
   *   The state the operator was shown, held or confirmed.
   *
   * @return string|null
   *   The state now recorded, or NULL when nothing was freed.
   */
  public function release(int $dispatchReservationId, string $expectedState): ?string {
    if ($dispatchReservationId < 1) {
      return NULL;
    }

    // Read before the release rather than after it, because afterwards there is
    // nothing left to describe: a freed row answers NULL to adminClaim(), so its
    // Resource, its zone and the arrival somebody was promised would all be gone by
    // the time an announcement needed them. The read takes no lock and sees only the
    // administrator-safe projection the board itself is built from.
    $before = $this->store->adminClaim($dispatchReservationId);

    $state = $this->store->adminRelease($dispatchReservationId, $expectedState);
    if ($state === NULL) {
      $this->logger->notice('A staff release of Dispatch claim @id freed nothing: it was gone, already history, or no longer @state.', [
        '@id' => $dispatchReservationId,
        '@state' => $expectedState,
      ]);

      return NULL;
    }

    $this->logger->notice('Dispatch claim @id was freed on staff authority and is now recorded as @state.', [
      '@id' => $dispatchReservationId,
      '@state' => $state,
    ]);

    // Announced only for a Reservation somebody was promised a time for. Dropping
    // an unfinished Hold tells nobody anything: no promise was ever made about it,
    // and a Hold deliberately carries no Webform, submission or arrival to put in a
    // message. This is the last statement in the method, so the store's own
    // transaction has committed and its Resource Calendar Guard rows are free.
    if ($before !== NULL && $before->isConfirmed()) {
      $this->notifier->notify(DispatchNotification::released(
        $before->resourceId,
        $before->timezone,
        $this->time->getRequestTime(),
        $dispatchReservationId,
        $before->submissionId,
        $before->webformId,
        $before->pickupArrival,
        $before->windowStart,
        $before->windowEnd,
        $state,
        (int) $this->currentUser->id(),
      ));
    }

    return $state;
  }

  /**
   * Moves one confirmed Reservation to a different pickup arrival.
   *
   * The caller contributes an identifier, a local date and a local time. It does
   * not contribute a Dispatch Window, a duration, a route, or any repositioning
   * figure, and there is nowhere here to pass one: the Reservation names its own
   * submission, the submission describes the job, and the planner derives the
   * window from those against the Resource's real calendar. That is what makes a
   * board drag a proposal rather than a move.
   *
   * @param int $dispatchReservationId
   *   The Reservation to move.
   * @param string $localDate
   *   The proposed arrival date, in the Resource's own zone.
   * @param string $localTime
   *   The proposed arrival time, in the Resource's own zone.
   *
   * @return \Drupal\custom_webform_handlers\Dispatch\ReplanResult
   *   The move, or the recoverable reason it was refused. A refusal changed
   *   nothing: the Reservation is exactly where and when it was.
   */
  public function replan(int $dispatchReservationId, string $localDate, string $localTime): ReplanResult {
    if ($dispatchReservationId < 1) {
      return ReplanResult::refused(DispatchReason::InvalidRequest);
    }

    $claim = $this->store->adminClaim($dispatchReservationId);
    if ($claim === NULL || !$claim->isConfirmed()) {
      // A lapsed Hold, a released row and a superseded row all answer NULL here,
      // and none of them holds capacity to move. A live Hold answers, but is not
      // confirmed: an unfinished attempt is released, never rescheduled on the
      // visitor's behalf.
      return ReplanResult::refused(DispatchReason::ReservationNotFound);
    }

    if ($claim->submissionId === NULL || $claim->webformId === NULL) {
      // Without a submission there is no job description, so there is no honest
      // duration and no route — and inventing either is the one thing a Replan
      // must not do. Such a Reservation can be released; it cannot be moved.
      $this->logger->warning('Reservation @id records no submission, so it cannot be replanned.', [
        '@id' => $dispatchReservationId,
      ]);

      return ReplanResult::refused(DispatchReason::IncompleteFacts);
    }

    $submission = $this->submission($claim->submissionId);
    if ($submission === NULL) {
      return ReplanResult::refused(DispatchReason::ReservationNotFound);
    }

    // Read in the Reservation's own recorded zone, not the site's and not the
    // operator's. The board shows local time in that zone, so the time typed
    // against a row must mean what the row means by it.
    $slot = $this->proposedSlot($claim->webformId, $claim->resourceId, $claim->timezone, $localDate, $localTime);
    if ($slot === NULL) {
      return ReplanResult::refused(DispatchReason::InvalidRequest);
    }

    $result = $this->planner->replan($dispatchReservationId, $slot, $submission);
    if ($result->isMoved()) {
      $this->logger->notice('Reservation @old was replanned onto @date @time and is now Reservation @new.', [
        '@old' => $dispatchReservationId,
        '@new' => $result->reservationId,
        '@date' => $localDate,
        '@time' => $localTime,
      ]);

      // The Dispatch Window is read back off the new row rather than taken from the
      // Slot, because a Slot carries the pickup arrival and nothing else: the window
      // is the planner's own answer against the Resource's real calendar, and a
      // message about a move has to state the window that was actually proved. The
      // read happens after the planner returned, so its transaction has committed
      // and its guard rows are released; this read holds no lock of its own.
      $moved = $result->reservationId === NULL
        ? NULL
        : $this->store->adminClaim($result->reservationId);

      if ($moved !== NULL && $moved->pickupArrival !== NULL && $moved->windowStart !== NULL && $moved->windowEnd !== NULL) {
        $this->notifier->notify(DispatchNotification::replanned(
          $moved->resourceId,
          $moved->timezone,
          $this->time->getRequestTime(),
          $moved->dispatchReservationId,
          $dispatchReservationId,
          $moved->submissionId,
          $moved->webformId,
          $moved->pickupArrival,
          $moved->windowStart,
          $moved->windowEnd,
          (int) $this->currentUser->id(),
        ));
      }
    }
    else {
      $this->logger->notice('A Replan of Reservation @id onto @date @time was refused: @reason. Nothing changed.', [
        '@id' => $dispatchReservationId,
        '@date' => $localDate,
        '@time' => $localTime,
        '@reason' => $result->reason->value,
      ]);
    }

    return $result;
  }

  /**
   * Withholds a span of one Resource's day from booking.
   *
   * Validation is not repeated here. The planner resolves the Resource, reads
   * both times in that Resource's zone, settles interval order, checks the span
   * against the working day, and then hands the schedule-impact question to the
   * store, which answers it under the Resource Calendar Guard rows for every
   * local day the span touches. Re-deciding any of that in this class would be a
   * second copy of the rules, and the copies would eventually disagree.
   *
   * @param string $resourceId
   *   The Resource whose time is withheld.
   * @param string $localDate
   *   The local date being closed.
   * @param string $startTime
   *   Local start, H:i.
   * @param string $endTime
   *   Local end, H:i. Must follow the start.
   * @param string $reason
   *   The operator's note.
   * @param int|null $replacesBlackoutId
   *   An active Blackout on the same Resource this one revises.
   *
   * @return \Drupal\custom_webform_handlers\Dispatch\BlackoutResult
   *   The Blackout now in force, or why the span was not withheld. A refusal
   *   naming live claims wrote nothing and withdrew nothing.
   */
  public function withholdTime(
    string $resourceId,
    string $localDate,
    string $startTime,
    string $endTime,
    string $reason,
    ?int $replacesBlackoutId = NULL,
  ): BlackoutResult {
    $result = $this->planner->scheduleBlackout(
      $resourceId,
      $localDate,
      $startTime,
      $endTime,
      $reason,
      $replacesBlackoutId,
    );

    if ($result->isScheduled()) {
      $this->logger->notice('Blackout @id withholds @date @from–@until on Resource @resource.', [
        '@id' => $result->blackoutId,
        '@date' => $localDate,
        '@from' => $startTime,
        '@until' => $endTime,
        '@resource' => $resourceId,
      ]);

      // Read the written Blackout back for its exact instants and its Resource's
      // own zone, instead of re-deriving them from the local strings this method was
      // handed. The planner settled interval order and resolved the zone, and an
      // announcement has to say what was actually withheld rather than what was
      // asked for. Resolving through this class's own reader also means a Resource
      // this installation does not operate answers NULL and is announced to nobody.
      $blackout = $result->blackoutId === NULL
        ? NULL
        : $this->blackout($result->blackoutId, $resourceId);

      if ($blackout !== NULL) {
        $this->notifier->notify(DispatchNotification::timeWithheld(
          $blackout->resourceId,
          $blackout->timezone,
          $this->time->getRequestTime(),
          $blackout->startAt,
          $blackout->endAt,
          $blackout->reason,
          (int) $this->currentUser->id(),
        ));
      }
    }
    else {
      $this->logger->notice('A Blackout on @date @from–@until was refused: @reason. Nothing was withheld.', [
        '@date' => $localDate,
        '@from' => $startTime,
        '@until' => $endTime,
        '@reason' => $result->reason->value,
      ]);
    }

    return $result;
  }

  /**
   * Lifts an active Blackout, keeping the row as the audit trail.
   *
   * @return bool
   *   TRUE when an active Blackout was lifted. FALSE when it was already
   *   withdrawn or never existed, which is not an error worth interrupting an
   *   operator over: either way the span is open now.
   */
  public function withdrawBlackout(int $blackoutId): bool {
    if ($blackoutId < 1) {
      return FALSE;
    }

    // Read before the withdrawal, for the same reason a release reads first: the
    // instants and the Resource's zone have to be in hand while the row is still the
    // active Blackout. Resolved against the configured Resource, which is what the
    // closure listing means by carrying none — a Blackout belonging to a Resource
    // this installation does not operate answers NULL here, so no announcement can
    // invent a zone for one.
    $before = $this->blackout($blackoutId, '');

    $withdrawn = $this->store->withdrawBlackout($blackoutId);
    $this->logger->notice($withdrawn
      ? 'Blackout @id was withdrawn on staff authority; the span is open again.'
      : 'A withdrawal of Blackout @id changed nothing: it was already withdrawn or never recorded.', [
        '@id' => $blackoutId,
      ]);

    // A withdrawal that changed nothing announces nothing: the span was already
    // open, so there is no new committed fact to report.
    if ($withdrawn && $before !== NULL) {
      $this->notifier->notify(DispatchNotification::timeReturned(
        $before->resourceId,
        $before->timezone,
        $this->time->getRequestTime(),
        $before->reason,
        (int) $this->currentUser->id(),
      ));
    }

    return $withdrawn;
  }

  /**
   * Books a waiting request on staff authority, at a time staff choose.
   *
   * The same two-step planner transaction the public path runs: a Hold is taken
   * under the guard locks and the plan is re-proved under them again at
   * confirmation. Manual Review consumes no capacity until this succeeds, and it
   * is the only way a Manual Review request gets any — there is no path that
   * quietly promotes one.
   *
   * Staff supply an explicit date and time rather than pick from offered Slots,
   * and that is deliberate. Slot offers are gated by the public eligibility
   * answer, so a request sitting in Manual Review yields none by definition; a
   * picker here would either be empty or would have to re-decide eligibility with
   * the refusals left out, which is the second copy of the rules again. Instead
   * the planner is asked about one specific time and its refusal is shown.
   *
   * @param int $submissionId
   *   The waiting submission.
   * @param string $resourceId
   *   The Resource to book, or an empty string for the configured one.
   * @param string $localDate
   *   Pickup arrival date, in the Resource's zone.
   * @param string $localTime
   *   Pickup arrival time, in the Resource's zone.
   *
   * @return \Drupal\custom_webform_handlers\Dispatch\ReservationResult
   *   The Reservation, or why it was refused.
   */
  public function confirmManualReview(int $submissionId, string $resourceId, string $localDate, string $localTime): ReservationResult {
    if ($submissionId < 1) {
      return ReservationResult::refused(DispatchReason::InvalidRequest);
    }

    $submission = $this->submission($submissionId);
    if ($submission === NULL) {
      return ReservationResult::refused(DispatchReason::InvalidRequest);
    }

    $profile = $this->profile($resourceId);
    if ($profile === NULL) {
      return ReservationResult::refused(DispatchReason::NotConfigured);
    }

    $webform = $submission->getWebform();
    if ($webform === NULL) {
      return ReservationResult::refused(DispatchReason::InvalidRequest);
    }

    $slot = $this->proposedSlot((string) $webform->id(), $profile->resourceId, $profile->timezone, $localDate, $localTime);
    if ($slot === NULL) {
      return ReservationResult::refused(DispatchReason::InvalidRequest);
    }

    $result = $this->planner->confirmForStaff($submission, $slot);
    if ($result->reservationId === NULL) {
      $this->logger->notice('A staff booking of submission @sid at @date @time was refused: @reason.', [
        '@sid' => $submissionId,
        '@date' => $localDate,
        '@time' => $localTime,
        '@reason' => $result->reason->value,
      ]);
    }
    else {
      $this->logger->notice('Submission @sid was booked on staff authority as Reservation @id at @date @time.', [
        '@sid' => $submissionId,
        '@id' => $result->reservationId,
        '@date' => $localDate,
        '@time' => $localTime,
      ]);

      // A staff booking is announced as a confirmation, because that is what it is
      // to the customer: the request that was waiting now has a promised arrival and
      // a proved window. Both come off the result the planner returned rather than
      // from a second read, and rather than from the local strings staff typed — the
      // planner resolved those against the Resource's own calendar, and the window it
      // wrote is the only honest thing to promise. This result reports its identifier
      // as a string where the rest of the subsystem uses an integer, so the cast
      // happens once, here, and the value is guarded rather than trusted.
      $reservationId = (int) $result->reservationId;

      if ($reservationId > 0 && $result->slot !== NULL && $result->windowStart !== NULL && $result->windowEnd !== NULL) {
        $this->notifier->notify(DispatchNotification::confirmed(
          $result->slot->resourceId,
          $result->slot->timezone,
          $this->time->getRequestTime(),
          $reservationId,
          $submissionId,
          $result->slot->webformId,
          $result->slot->startTimestamp,
          $result->windowStart,
          $result->windowEnd,
          (int) $this->currentUser->id(),
        ));
      }
    }

    return $result;
  }

  /**
   * Resolves a Resource Profile, falling back to the configured Resource.
   *
   * An unknown identifier answers NULL rather than the configured Resource. A
   * form that asked about a Resource this site does not operate must be refused,
   * not quietly answered about a different vehicle.
   *
   * @param string $resourceId
   *   A Resource identifier, or an empty string for the configured one.
   *
   * @return \Drupal\custom_webform_handlers\Dispatch\ResourceProfile|null
   *   The Profile, or NULL when there is none to act on.
   */
  public function profile(string $resourceId): ?ResourceProfile {
    $resourceId = trim($resourceId);

    return $resourceId === ''
      ? $this->profileFactory->create()
      : $this->profileFactory->createForResource($resourceId);
  }

  /**
   * Reads one Blackout, active or withdrawn, in its Resource's own zone.
   *
   * The zone is a Resource Profile fact and the Blackout row records none, so it
   * has to be supplied — which means the Resource has to be known before the row
   * is read. If the row turns out to belong to a different Resource than the
   * caller named, it is refused rather than rendered: stating one Resource's
   * interval in another's zone would misreport the hours by the offset between
   * them, and an operator would withdraw or revise the wrong closure.
   *
   * @param int $blackoutId
   *   The Blackout to read.
   * @param string $resourceId
   *   The Resource the caller believes it belongs to.
   *
   * @return \Drupal\custom_webform_handlers\Dispatch\DispatchBlackout|null
   *   The Blackout, or NULL when there is no such row on that Resource.
   */
  public function blackout(int $blackoutId, string $resourceId): ?DispatchBlackout {
    if ($blackoutId < 1) {
      return NULL;
    }

    $profile = $this->profile($resourceId);
    if ($profile === NULL) {
      return NULL;
    }

    $blackout = $this->store->loadBlackout($blackoutId, $profile->timezone);
    if ($blackout === NULL) {
      return NULL;
    }

    if ($blackout->resourceId !== $profile->resourceId) {
      $this->logger->warning('Blackout @id belongs to another Resource than the one it was requested for; it was not shown.', [
        '@id' => $blackoutId,
      ]);

      return NULL;
    }

    return $blackout;
  }

  /**
   * Lists the active Blackouts overlapping a UTC range.
   *
   * @param string $resourceId
   *   The Resource whose closures are listed.
   * @param int $from
   *   Start of the UTC range.
   * @param int $to
   *   End of the UTC range.
   *
   * @return \Drupal\custom_webform_handlers\Dispatch\DispatchBlackout[]
   *   Ascending by start. An unknown Resource or an unusable range yields none,
   *   and neither is ever widened into a list of everything.
   */
  public function blackoutsFor(string $resourceId, int $from, int $to): array {
    $profile = $this->profile($resourceId);
    if ($profile === NULL || $to <= $from) {
      return [];
    }

    return $this->store->listBlackouts($profile->resourceId, $from, $to, $profile->timezone);
  }

  /**
   * Projects one waiting request down to what a confirmation screen may show.
   *
   * The submission entity is loaded here and stays here. It holds every answer the
   * customer gave — both addresses, their name, how to contact them — and a form
   * that received one would eventually render one, so what leaves this method is
   * four facts: the submission, the Webform, when it arrived, and whether anything
   * already holds capacity for it.
   *
   * The last of those is a courtesy rather than a safeguard. The planner refuses a
   * second booking for the same submission on its own, under the locks, which is
   * the check that actually prevents a vehicle being sent twice; this only lets the
   * screen say so before an operator types a time.
   *
   * @param int $submissionId
   *   The submission to describe.
   *
   * @return \Drupal\custom_webform_handlers\Dispatch\DispatchQueuedRequest|null
   *   The projection, or NULL when there is no such submission to act on.
   */
  public function waitingRequest(int $submissionId): ?DispatchQueuedRequest {
    $submission = $this->submission($submissionId);
    if ($submission === NULL) {
      return NULL;
    }

    $webform = $submission->getWebform();
    if ($webform === NULL) {
      return NULL;
    }

    // A legacy row may never have recorded a completion, in which case the creation
    // time is the honest answer for when the request arrived.
    $submitted = (int) $submission->getCompletedTime();
    if ($submitted < 1) {
      $submitted = (int) $submission->getCreatedTime();
    }

    return new DispatchQueuedRequest(
      $submissionId,
      (string) $webform->id(),
      $submitted,
      $this->store->liveClaimForSubmission($submissionId),
    );
  }

  /**
   * Builds the Slot a caller is proposing, or refuses their input.
   *
   * A Slot is a pickup arrival and nothing else — no duration, no window, no
   * route — so this is the widest thing a caller is ever allowed to contribute.
   * The zone comes from the row or the Profile, never from the request, and an
   * unrecognised zone refuses instead of falling back to UTC: the instant would
   * survive while the local pickup time moved by the offset, and every downstream
   * check would then pass on a schedule that sends the driver at the wrong hour.
   */
  private function proposedSlot(string $webformId, string $resourceId, string $timezone, string $localDate, string $localTime): ?Slot {
    try {
      $zone = new \DateTimeZone($timezone);
    }
    catch (\Exception) {
      $this->logger->error('Resource @resource carries an unusable timezone, so no Slot could be read against it.', [
        '@resource' => $resourceId,
      ]);

      return NULL;
    }

    $instant = LocalWallClock::instant($localDate, $localTime, $zone);
    if ($instant === NULL) {
      return NULL;
    }

    try {
      return new Slot($webformId, $resourceId, $instant, $timezone);
    }
    catch (\InvalidArgumentException) {
      return NULL;
    }
  }

  /**
   * Loads the submission a Replan or a staff booking names.
   *
   * The entity is loaded rather than trusted from the request, because it is the
   * only record of what the job actually is: the addresses, the service, the
   * declared weight and the handling that decide both the duration and the route.
   * A caller that could substitute one would be choosing the plan.
   */
  private function submission(int $submissionId): ?WebformSubmissionInterface {
    if ($submissionId < 1) {
      return NULL;
    }

    try {
      $submission = $this->entityTypeManager
        ->getStorage('webform_submission')
        ->load($submissionId);
    }
    catch (\Exception) {
      // A missing Webform submission storage is a broken installation, not an
      // operator error, and it must not surface as a white screen over a board.
      $this->logger->error('Webform submission storage was unavailable, so submission @sid could not be read.', [
        '@sid' => $submissionId,
      ]);

      return NULL;
    }

    return $submission instanceof WebformSubmissionInterface ? $submission : NULL;
  }

}
