<?php

namespace Drupal\custom_webform_handlers\Dispatch\Storage;

use Drupal\custom_webform_handlers\Dispatch\BlackoutResult;
use Drupal\custom_webform_handlers\Dispatch\DispatchBlackout;
use Drupal\custom_webform_handlers\Dispatch\DispatchClaim;
use Drupal\custom_webform_handlers\Dispatch\DispatchClaimSummary;
use Drupal\custom_webform_handlers\Dispatch\DispatchReservationDetail;
use Drupal\custom_webform_handlers\Dispatch\HoldResult;
use Drupal\custom_webform_handlers\Dispatch\ReplanResult;
use Drupal\custom_webform_handlers\Dispatch\ReservationResult;
use Drupal\custom_webform_handlers\Dispatch\Slot;

/**
 * The only storage seam behind the Dispatch Planner.
 *
 * This is not a caller-facing interface. DispatchPlannerInterface remains the
 * three-operation public seam; everything here sits behind it. Splitting them
 * this way keeps one place that owns SQL, expiry, conflict queries, and row
 * mutation, so there is exactly one implementation to audit for the concurrency
 * guarantee.
 *
 * The guarantee itself: claim() takes the Resource Calendar Guard row locks for
 * every affected local day, then reads the schedule, then writes — all in one
 * database transaction. There is no availability read outside that transaction
 * that anything is allowed to act on. occupiedIntervals() exists for building an
 * availability answer and is explicitly advisory: a Slot it implies is free can
 * still lose the race in claim(), which is the only authority.
 *
 * Secrets never cross this seam in stored form. Raw hold tokens and raw
 * anonymous-session owner identifiers are passed in and hashed with SHA-256 by
 * the implementation; nothing here returns a hash or accepts one.
 */
interface ReservationStoreInterface {

  /**
   * Held, and not yet past its expiry.
   */
  const STATE_HELD = 'held';

  /**
   * Confirmed against a completed submission.
   */
  const STATE_CONFIRMED = 'confirmed';

  /**
   * Given up by its owner before confirmation.
   */
  const STATE_RELEASED = 'released';

  /**
   * Lapsed without being confirmed.
   */
  const STATE_EXPIRED = 'expired';

  /**
   * Withdrawn administratively after confirmation.
   */
  const STATE_CANCELLED = 'cancelled';

  /**
   * Moved by staff to a different arrival; superseded by a newer confirmed row.
   *
   * The row is kept rather than updated in place so what was previously promised
   * stays readable, which is the whole audit trail for a Replan. It consumes no
   * capacity: conditionOnLive() admits only confirmed rows and live Holds, so a
   * superseded row constrains nothing and can never double-book its own
   * replacement.
   */
  const STATE_REPLANNED = 'replanned';

  /**
   * Atomically claims one Slot, or reports why it could not be claimed.
   *
   * Inside a single transaction this locks every affected Resource Calendar
   * Guard in deterministic order, re-reads active Dispatch Windows and active
   * Blackouts, checks the candidate's occupied window and both Repositioning
   * margins for overlap, and only then inserts the held row.
   *
   * @param \Drupal\custom_webform_handlers\Dispatch\DispatchClaim $claim
   *   The candidate, carrying every interval the planner derived.
   *
   * @return \Drupal\custom_webform_handlers\Dispatch\HoldResult
   *   The Hold, or a stable refusal reason. A contender that lost the race
   *   receives slot_unavailable, not an error.
   */
  public function claim(DispatchClaim $claim): HoldResult;

  /**
   * Atomically claims one Slot, re-deriving its plan under the guard locks.
   *
   * claim() above re-reads occupancy inside the transaction, but it inserts the
   * intervals it was handed. Those intervals were derived before the locks
   * existed, and the exact Repositioning they encode depends on which jobs
   * neighbour the candidate — which is precisely what a competing claim can have
   * changed in the meantime. A neighbour that appears just outside the previously
   * computed protected span does not overlap it, so claim() would accept the
   * candidate while the drive between the two jobs no longer fits.
   *
   * So this exists for the same reason confirmWithRevalidation() does: the plan is
   * re-derived while the Resource Calendar Guard rows are held, against the
   * neighbours that actually exist, and the claim is written only if the plan the
   * planner proved before the transaction is still the plan the day supports.
   *
   * The callback runs with the guard rows locked, so it may issue route lookups
   * and must therefore be bounded. Like the confirmation callback, it must never
   * open a transaction, take a guard lock, or write.
   *
   * @param \Drupal\custom_webform_handlers\Dispatch\DispatchClaim $claim
   *   The candidate as derived before the transaction. Its intervals are what the
   *   callback is expected to re-prove; they are inserted only if it does.
   * @param callable $revalidate
   *   fn(): \Drupal\custom_webform_handlers\Dispatch\DispatchReason
   *   Returns DispatchReason::Available when re-deriving the plan under the locks
   *   yields the same Dispatch Window and no greater Repositioning than the claim
   *   carries. Any other reason refuses and writes nothing. A throw also refuses
   *   and is not swallowed.
   *
   * @return \Drupal\custom_webform_handlers\Dispatch\HoldResult
   *   The Hold, or the reason there is none.
   */
  public function claimWithRevalidation(DispatchClaim $claim, callable $revalidate): HoldResult;

  /**
   * Turns a live Hold into a confirmed Reservation.
   *
   * Also transactional and also guarded, because confirmation must not race an
   * expiry sweep or a competing claim on the same days.
   *
   * @param string $rawHoldToken
   *   The bearer token as presented. Hashed for comparison, never stored raw.
   * @param string $rawOwnerId
   *   The presenting session's raw identifier, compared by hash.
   * @param int $submissionId
   *   The completed Webform submission to link.
   *
   * @return \Drupal\custom_webform_handlers\Dispatch\ReservationResult
   *   The Reservation, or the reason confirmation was refused.
   */
  public function confirm(string $rawHoldToken, string $rawOwnerId, int $submissionId): ReservationResult;

  /**
   * Confirms a Hold only if a planner callback re-proves it under the locks.
   *
   * This exists because confirm() above proves only that the Hold is still live
   * and still owned. Liveness is not feasibility. Between taking the Hold and
   * completing the submission the day can have changed underneath it: a
   * neighbouring claim can have appeared, a Blackout can have been added, the
   * operating profile can have moved the working interval or a break, a route can
   * have become unpriceable, or the visitor can have edited an address so the
   * Dispatch Window the Hold reserved is no longer the window the work needs.
   * Confirming on liveness alone would honour a plan nothing had re-checked.
   *
   * So the sequence is: open the transaction, take the guard rows for every local
   * day the stored claim touches, re-read the Hold under those locks, hand it to
   * the callback, and write only if the callback says the plan still holds. The
   * feasibility rules stay in the planner; the transaction stays here. Neither
   * side learns the other's mechanics.
   *
   * The callback runs with the guard rows locked, which is the point rather than an
   * accident: the rule is that route and schedule feasibility are recalculated
   * while those rows are held. It follows that the callback may issue route lookups
   * inside the transaction, so it must be bounded, and it must never open a
   * transaction, take a guard lock, or write.
   *
   * @param string $rawHoldToken
   *   The bearer token as presented. Hashed for comparison, never stored raw.
   * @param string $rawOwnerId
   *   The presenting session's raw identifier, compared by hash.
   * @param int $submissionId
   *   The completed Webform submission to link.
   * @param callable $revalidate
   *   fn(\Drupal\custom_webform_handlers\Dispatch\Storage\LockedHold): \Drupal\custom_webform_handlers\Dispatch\DispatchReason
   *   Returns DispatchReason::Available when the stored plan is still feasible, or
   *   the reason it is not. Any other reason refuses the confirmation and leaves
   *   capacity exactly as it was. A throw also refuses and is not swallowed.
   *
   * @return \Drupal\custom_webform_handlers\Dispatch\ReservationResult
   *   The Reservation, or the reason confirmation was refused. Repeating the call
   *   for a Hold already confirmed against this same submission succeeds without
   *   re-running the callback and without touching the row.
   */
  public function confirmWithRevalidation(string $rawHoldToken, string $rawOwnerId, int $submissionId, callable $revalidate): ReservationResult;

  /**
   * Moves one confirmed Reservation to a plan the planner has just re-derived.
   *
   * The transactional half of a staff Replan. Every interval arrives already
   * derived, exactly as in claim(): routing knowledge and the operating profile
   * stay in the planner, and the atomic read-and-write sequence stays here.
   *
   * What is locked is the union of the local days the row being superseded
   * touches and the local days the new plan touches, in one transaction, in the
   * deterministic order normaliseDates() imposes. Locking only the destination
   * would leave the origin unserialized, which is where a competing claim would
   * slip into the space this move is about to vacate; locking them separately
   * would open a window in which the job occupies neither.
   *
   * Inside those locks: the row is re-read authoritatively and must still be
   * confirmed and still belong to the submission the planner proved, the
   * destination must be free of every other live claim and of every active
   * Blackout, and the callback must re-prove the plan. Only then is the old row
   * superseded and the new one written — in that order, in the same transaction,
   * so capacity is never released before its replacement exists. Any refusal
   * anywhere in the sequence writes nothing at all.
   *
   * A move to a different Resource is refused rather than attempted: the guard
   * locks are per-Resource, so two Resources cannot be serialized by one call,
   * and a partial move is worse than a refusal.
   *
   * The price snapshot is copied verbatim from the superseded row. Moving the van
   * does not requote the job, and there is no parameter here through which a
   * caller could change what the customer was quoted.
   *
   * @param \Drupal\custom_webform_handlers\Dispatch\Storage\DispatchReplan $replan
   *   The move as the planner derived it.
   * @param callable $revalidate
   *   fn(): \Drupal\custom_webform_handlers\Dispatch\DispatchReason
   *   Re-proves the plan with the guard rows held and the moved Reservation
   *   excluded from occupancy. Returns DispatchReason::Available to proceed. Any
   *   other reason, or a throw, refuses and leaves the original row untouched.
   *
   * @return \Drupal\custom_webform_handlers\Dispatch\ReplanResult
   *   The move, or the recoverable reason nothing changed.
   */
  public function replan(DispatchReplan $replan, callable $revalidate): ReplanResult;

  /**
   * Releases a Hold that this owner still holds.
   *
   * A confirmed Reservation is never released here; that is an administrative
   * action with its own audit trail.
   *
   * @return bool
   *   TRUE when a held claim owned by this owner was released.
   */
  public function release(string $rawHoldToken, string $rawOwnerId): bool;

  /**
   * Extends a live Hold's expiry.
   *
   * @return int|null
   *   The new expiry timestamp, or NULL when nothing was extended because the
   *   Hold had lapsed, been released, been confirmed, or never belonged here.
   */
  public function renew(string $rawHoldToken, string $rawOwnerId, int $holdSeconds): ?int;

  /**
   * Marks every Hold whose window has closed as expired.
   *
   * Expiry is bookkeeping, not the safety mechanism: an unswept lapsed Hold
   * already fails the liveness test in every conflict query, so a sweep that
   * never runs cannot cause a double booking.
   *
   * @return int
   *   How many Holds were expired.
   */
  public function expireDue(int $now): int;

  /**
   * Returns everything that occupies a Resource in a UTC range.
   *
   * Advisory read model for building availability. Never the authority.
   *
   * @param string $resourceId
   *   The Resource whose schedule is read.
   * @param int $from
   *   Start of the UTC range, inclusive of anything overlapping it.
   * @param int $to
   *   End of the UTC range.
   * @param int|null $excludeReservationId
   *   A claim to omit. Used only when revalidating that claim itself, where
   *   including it would make it conflict with its own reserved space and no Hold
   *   could ever be confirmed. Never used to widen availability for anything else.
   *
   * @return \Drupal\custom_webform_handlers\Dispatch\OccupiedInterval[]
   *   Active Dispatch Windows and active Blackouts, ascending by start.
   */
  public function occupiedIntervals(string $resourceId, int $from, int $to, ?int $excludeReservationId = NULL): array;

  /**
   * Returns live neighbouring claims with the addresses needed to route them.
   *
   * The planner's private companion to occupiedIntervals(). Repositioning between
   * two customers' jobs cannot be proved from intervals alone: proving it needs the
   * previous delivery address and the next pickup address, which is exactly what
   * OccupiedInterval refuses to carry so availability answers stay safe to publish.
   *
   * Blackouts are absent by definition. A closure puts nobody on the road, so it has
   * no address to route from; it constrains a candidate as occupancy, through
   * occupiedIntervals(), and not as a neighbour.
   *
   * @param string $resourceId
   *   The Resource whose schedule is read.
   * @param int $from
   *   Start of the UTC range, inclusive of anything overlapping it.
   * @param int $to
   *   End of the UTC range.
   * @param int|null $excludeReservationId
   *   A claim to omit, used only when revalidating that claim itself.
   *
   * @return \Drupal\custom_webform_handlers\Dispatch\Storage\PlanningNeighbour[]
   *   Live claims overlapping the range, ascending by window start. A claim whose
   *   stored address snapshot is missing is still returned, with an empty string,
   *   so the planner refuses it rather than silently planning around a gap.
   *
   * @internal
   *   Planner-only. The returned addresses belong to other customers and must not
   *   reach a controller, a JSON response, a template, or drupalSettings.
   */
  public function planningNeighbours(string $resourceId, int $from, int $to, ?int $excludeReservationId = NULL): array;

  /**
   * Returns the local dates closed to self-booking by an unproven legacy claim.
   *
   * @return string[]
   *   Y-m-d dates an administrator must reconcile before they can be offered.
   */
  public function legacyUnplannedDates(string $resourceId, string $fromDate, string $toDate): array;

  /**
   * Records an administrative Blackout for a Resource.
   *
   * @return int
   *   The new blackout identifier.
   */
  public function addBlackout(string $resourceId, int $startAt, int $endAt, string $reason): int;

  /**
   * Withdraws a Blackout, keeping the row for audit.
   *
   * @return bool
   *   TRUE when an active Blackout was withdrawn.
   */
  public function withdrawBlackout(int $blackoutId): bool;

  /**
   * Withholds a span from booking, but only if nothing live occupies it.
   *
   * addBlackout() above is the unguarded insert and stays that way; this is the
   * operation staff reach. The difference is the one that matters: writing a
   * Blackout over a live claim would produce a schedule in which the vehicle is
   * simultaneously booked and unavailable, so the span is checked against every
   * live claim while the Resource Calendar Guard rows for its local days are
   * held, and refused with the identifiers in the way if any claim is there.
   * Those identifiers are already visible on the board, so naming them discloses
   * nothing further and lets staff release or replan them first.
   *
   * A revision is withdraw-then-insert inside the same locked transaction, so the
   * Resource is never briefly open in a span the operator believes is closed. The
   * conflict check runs before either write, because returning a refusal after
   * withdrawing the old row would commit the withdrawal.
   *
   * Only the interval is checked here. Whether the span falls inside hours the
   * Resource works is a Resource Profile question, and the Profile is
   * configuration this seam does not read; the planner settles it first.
   *
   * @param string $resourceId
   *   The Resource whose time is withheld.
   * @param int $startAt
   *   UTC start.
   * @param int $endAt
   *   UTC end. Must follow the start.
   * @param string $reason
   *   The operator's note, truncated to the column width.
   * @param string $timezone
   *   The Resource's own IANA zone, used to decide which local days to lock.
   *   Supplied by the caller because the Blackout table records instants and a
   *   Resource, never a zone: the zone is a Resource Profile fact.
   * @param int|null $replacesBlackoutId
   *   An active Blackout on the same Resource that this one revises.
   *
   * @return \Drupal\custom_webform_handlers\Dispatch\BlackoutResult
   *   The Blackout now in force, or the reason the span was not withheld.
   */
  public function scheduleBlackoutGuarded(
    string $resourceId,
    int $startAt,
    int $endAt,
    string $reason,
    string $timezone,
    ?int $replacesBlackoutId = NULL,
  ): BlackoutResult;

  /**
   * Reads one Blackout, active or withdrawn.
   *
   * Withdrawn rows are returned because they are the audit trail: an operator
   * looking at what closed a day needs to see what was withdrawn as well as what
   * still stands. The active flag says which it is.
   *
   * @param int $blackoutId
   *   The Blackout to read.
   * @param string $timezone
   *   The Resource's own IANA zone, for the same reason as above.
   *
   * @return \Drupal\custom_webform_handlers\Dispatch\DispatchBlackout|null
   *   The Blackout, or NULL when no row carries that identifier.
   */
  public function loadBlackout(int $blackoutId, string $timezone): ?DispatchBlackout;

  /**
   * Lists the active Blackouts overlapping a UTC range.
   *
   * Withdrawn rows are absent here, unlike loadBlackout(): this builds the list an
   * operator manages, and a withdrawn closure is not something there is anything
   * left to do to.
   *
   * @param string $resourceId
   *   The Resource whose closures are listed.
   * @param int $from
   *   Start of the UTC range, inclusive of anything overlapping it.
   * @param int $to
   *   End of the UTC range.
   * @param string $timezone
   *   The Resource's own IANA zone.
   *
   * @return \Drupal\custom_webform_handlers\Dispatch\DispatchBlackout[]
   *   Ascending by start. An unusable range yields none, and is never widened.
   */
  public function listBlackouts(string $resourceId, int $from, int $to, string $timezone): array;

  /**
   * Rebuilds the Slot one stored claim was made against.
   *
   * @throws \Drupal\custom_webform_handlers\Dispatch\Storage\StoredSlotException
   *   When the row cannot yield a trustworthy Slot, most often because its
   *   stored timezone is no longer recognised. No default zone is substituted.
   */
  public function slotForReservation(int $dispatchReservationId): Slot;

  /**
   * Tells whether one submission already has capacity of its own.
   *
   * Confirmation is keyed by Hold token on the public path, and one submission
   * reaches it once, so nothing there needed this. A staff confirmation is keyed
   * by submission instead — staff pick a job and a time — and the same job can sit
   * in two browser tabs, or be confirmed by two people who both saw it waiting.
   * Without this check the second attempt would create a second Reservation for
   * one move, and the vehicle would be sent to it twice.
   *
   * Live means what it means everywhere else in this seam: a confirmed row, or a
   * Hold that has not lapsed. Released, expired, cancelled and superseded rows are
   * history and hold nothing, so a job whose earlier booking was cancelled can be
   * booked again.
   *
   * @param int $submissionId
   *   The submission to look for.
   *
   * @return int|null
   *   The identifier of a live claim on that submission, or NULL when none holds
   *   capacity. The identifier alone: the caller needs to know that something is
   *   there, not to read a claim it has not been shown.
   */
  public function liveClaimForSubmission(int $submissionId): ?int;

  /**
   * Records which Commerce order paid for one confirmed Reservation.
   *
   * A pointer, written after capacity has already moved, never a decision. The
   * store transaction remains the only authority on whether the Reservation
   * exists; this says who paid for it, so the Dispatch board and a Replan can name
   * the order without a second table to join through.
   *
   * The write belongs here because this seam owns its own table. A checkout
   * adapter reaching for a Connection to set one column would make a second writer
   * on the reservation table, and there is exactly one.
   *
   * Idempotent for the same order, so a repeated payment notification changes
   * nothing. Refused for a different order: an order reference that could be
   * overwritten would let a later payment take over capacity an earlier one bought.
   *
   * @param int $dispatchReservationId
   *   The confirmed Reservation the order paid for.
   * @param int $orderId
   *   The canonical Commerce order identifier.
   *
   * @return bool
   *   TRUE when the row now names this order — including when it already did.
   *   FALSE when there is no confirmed row, or it names a different order.
   */
  public function linkOrder(int $dispatchReservationId, int $orderId): bool;

  /**
   * Returns the administrator-safe timeline for one Resource and UTC range.
   *
   * The Dispatch board's only source of schedule data. It reports the occupancy
   * the planner reasons about, reduced to what an administrator may safely be
   * shown: confirmed Reservations with their card fields, live Holds as blocked
   * time and an expiry, and Blackouts with their reason.
   *
   * A Hold arrives deliberately impoverished — Resource, blocked interval,
   * state, expiry — because the visitor who created it has not booked anything
   * and their address, Webform, submission, and price are nobody's business
   * until they do. Neither secret hash column is ever selected.
   *
   * Range membership is decided on the padded interval, as occupiedIntervals()
   * decides it, so a claim whose repositioning reaches into the range is shown
   * even when its Dispatch Window falls outside it.
   *
   * @param string $resourceId
   *   The Resource whose schedule is read.
   * @param int $from
   *   Start of the UTC range, inclusive of anything overlapping it.
   * @param int $to
   *   End of the UTC range.
   *
   * @return \Drupal\custom_webform_handlers\Dispatch\DispatchTimelineEntry[]
   *   Entries ascending by the time they occupy. Expired Holds are absent. An
   *   unusable request — an empty Resource, or an end at or before its start —
   *   yields no entries; it is never widened to every Resource or to a span the
   *   caller did not ask for.
   */
  public function adminTimeline(string $resourceId, int $from, int $to): array;

  /**
   * Returns administrator-safe detail for one confirmed Reservation.
   *
   * @param int $dispatchReservationId
   *   The claim to describe.
   *
   * @return \Drupal\custom_webform_handlers\Dispatch\DispatchReservationDetail|null
   *   The Reservation's safe detail, or NULL when no confirmed claim carries
   *   that identifier. A missing row and a Hold are answered identically,
   *   because a Hold has no detail an administrator is entitled to read.
   */
  public function adminReservation(int $dispatchReservationId): ?DispatchReservationDetail;

  /**
   * Returns the little that may be said about one claim, Hold or Reservation.
   *
   * adminReservation() above answers NULL for a Hold on purpose, because a Hold
   * has no detail an administrator is entitled to read. But a Hold can be
   * released, and a confirmation form has to be able to say what it is about to
   * release without lying about it. This is that summary and nothing more.
   *
   * What a Hold discloses here is what the board already discloses: the Resource,
   * the interval it blocks, its state, and when it lapses. It discloses no
   * address, no Webform, no submission and no price, and the returned object has
   * no field for any of them when the claim is a Hold. Neither secret hash column
   * is selected in either case.
   *
   * @param int $dispatchReservationId
   *   The claim to summarise.
   *
   * @return \Drupal\custom_webform_handlers\Dispatch\DispatchClaimSummary|null
   *   The summary, or NULL when no row carries that identifier or the row holds
   *   nothing — an expired, released, cancelled or superseded row is history, and
   *   there is nothing left to do to it.
   */
  public function adminClaim(int $dispatchReservationId): ?DispatchClaimSummary;

  /**
   * Frees the capacity one claim holds, on staff authority, keeping the row.
   *
   * release() above is the visitor's operation and is keyed by the Hold token,
   * which is how it proves the caller owns what it is releasing. Staff hold no
   * token, so this is keyed by identifier — and because it is, it takes the
   * Resource Calendar Guard rows for the claim's local days before it re-reads and
   * writes. That is not for the sake of the release itself, which only ever frees
   * space; it is so a release and a Replan of the same row cannot both believe
   * they won. Under the locks one of them re-reads the row after the other wrote
   * it, and the state condition on each write settles it.
   *
   * The row is never deleted. A released Hold becomes released and a released
   * Reservation becomes cancelled, both with their hold token, owner and expiry
   * cleared, so the history stays readable while holding no capacity.
   *
   * @param int $dispatchReservationId
   *   The claim to free.
   * @param string $expectedState
   *   The state the caller was shown, either held or confirmed. The write is
   *   refused unless the locked re-read still finds that state. This is what stops
   *   a form that offered to drop an unfinished Hold from cancelling a booking the
   *   customer completed while the confirmation page sat open.
   *
   * @return string|null
   *   The state now recorded — released for a Hold, cancelled for a Reservation —
   *   or NULL when nothing was freed because the row was gone, already history, or
   *   no longer in the state the caller was shown.
   */
  public function adminRelease(int $dispatchReservationId, string $expectedState): ?string;

}
