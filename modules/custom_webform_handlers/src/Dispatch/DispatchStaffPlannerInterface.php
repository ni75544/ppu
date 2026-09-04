<?php

namespace Drupal\custom_webform_handlers\Dispatch;

use Drupal\webform\WebformSubmissionInterface;

/**
 * The three things authorized staff may ask the planner to change.
 *
 * A separate seam, and deliberately not extra methods on
 * DispatchPlannerInterface. That interface is what the public booking path talks
 * to, and its own docblock rules out anything that would let a caller reach a
 * row or mutate one — so widening it would put staff-only mutation within reach
 * of every endpoint an anonymous visitor can call. This follows the precedent set
 * by DispatchBoardReaderInterface: a distinct audience gets a distinct, narrow
 * interface, implemented by the same planner so there is still exactly one
 * planning algorithm in the module.
 *
 * That last point is the whole design. Nothing here re-implements feasibility.
 * Every method below routes through the same private derivation the visitor path
 * uses — the same working hours, breaks, blackouts, grid, horizon, durations,
 * routed legs, predecessor and successor repositioning, return-to-base rule and
 * occupancy read — and the same storage seam, under the same Resource Calendar
 * Guard locks. What staff authority changes is one question, asked once: whether
 * work the operator's policy withholds from self-service may be planned at all.
 * It cannot make an infeasible route feasible, and no method here can be reached
 * by a GET request.
 *
 * Absent from this interface, on purpose:
 *
 * - Anything that writes a Dispatch Window supplied by a caller. Windows are
 *   derived, every time, from the Dispatch Request the server rebuilds itself.
 * - Anything that releases capacity. Release is a storage-seam operation with no
 *   planning content: it frees a row under the same locks and needs no route,
 *   duration or feasibility decision, so it lives on ReservationStoreInterface
 *   where the locking does.
 * - Anything that repairs, recalculates or requotes a price.
 */
interface DispatchStaffPlannerInterface {

  /**
   * Moves one confirmed Reservation to a different pickup arrival.
   *
   * The caller supplies an identifier and a proposed arrival, and nothing else
   * that matters: the Dispatch Request is rebuilt from the submission on the
   * server, the Dispatch Window and both repositioning legs are re-derived, and
   * the route is re-measured. A proposed arrival is a question, never an
   * instruction.
   *
   * Feasibility is re-proved with the Reservation being moved excluded from the
   * occupancy read — otherwise a job would always be found to conflict with
   * itself — and with every other live claim and active Blackout still applying.
   * The old and new local days are locked together in one transaction, so the
   * space being vacated cannot be taken by a competing claim while this move is
   * deciding whether it needs it.
   *
   * On success the original row is retired in the superseded state and a new
   * confirmed Reservation carries the new arrival, window and route snapshot, so
   * what was previously promised remains readable. On refusal nothing whatsoever
   * changes and the reason is one the caller may show to staff.
   *
   * @param int $dispatchReservationId
   *   The confirmed Reservation to move.
   * @param \Drupal\custom_webform_handlers\Dispatch\Slot $proposed
   *   The proposed pickup arrival. Re-normalised against the Resource Profile,
   *   and refused outright if it names a different Resource or Webform than the
   *   Reservation does.
   * @param \Drupal\webform\WebformSubmissionInterface $submission
   *   The submission the Reservation belongs to, loaded by the caller. Refused if
   *   it is not the submission the stored row records.
   *
   * @return \Drupal\custom_webform_handlers\Dispatch\ReplanResult
   *   The move, or the recoverable reason it was refused.
   */
  public function replan(int $dispatchReservationId, Slot $proposed, WebformSubmissionInterface $submission): ReplanResult;

  /**
   * Withholds a span of one local day from booking.
   *
   * Validation is the operator-facing half of this method and it happens before
   * anything is written: the Resource must be one this installation operates, its
   * Profile must resolve a usable timezone, the local date and both wall-clock
   * times must parse in that zone, the end must follow the start, and the span
   * must overlap a working interval the Resource actually has — a Blackout over
   * hours nobody works removes nothing and would misrepresent the board.
   *
   * Then the Resource Calendar Guard rows for the span's local days are locked,
   * and the span is refused if any live claim occupies it. Writing it anyway
   * would produce a schedule in which the vehicle is simultaneously booked and
   * unavailable, and no path in this module is allowed to create one. The refusal
   * names the claims in the way so staff can release or replan them first.
   *
   * @param string $resourceId
   *   The Resource whose time is withheld.
   * @param string $localDate
   *   The Y-m-d the span falls on, read in the Resource's own zone.
   * @param string $startTime
   *   Local HH:MM the span opens.
   * @param string $endTime
   *   Local HH:MM the span closes. Must be later the same day; a closure that
   *   spans midnight is two Blackouts, so each one stays inside one working day
   *   and can be checked against that day's hours.
   * @param string $reason
   *   The operator's note. Stored verbatim as plain text, truncated by the store.
   * @param int|null $replacesBlackoutId
   *   An existing active Blackout this one revises. Withdrawn and replaced inside
   *   the same locked transaction, so the Resource is never briefly open in a
   *   span the operator believes is closed.
   *
   * @return \Drupal\custom_webform_handlers\Dispatch\BlackoutResult
   *   The Blackout now in force, or why the span was not withheld.
   */
  public function scheduleBlackout(
    string $resourceId,
    string $localDate,
    string $startTime,
    string $endTime,
    string $reason,
    ?int $replacesBlackoutId = NULL,
  ): BlackoutResult;

  /**
   * Books a request that self-service refuses, on a member of staff's authority.
   *
   * This is the only way a Manual Review request becomes a Reservation, and it
   * runs the same two-step planner transaction the public path runs: capacity is
   * claimed as a Hold under the Resource Calendar Guard locks, then confirmed
   * against the saved submission with the plan re-proved while the locks are
   * still held. Until a member of staff completes it the request consumes no
   * capacity at all — per CONTEXT.md Manual Review creates neither a Hold nor a
   * Reservation — and there is no row for it anywhere.
   *
   * Staff authority relaxes exactly one thing: the policy gate that withholds
   * this class of work from self-service. Every physical check runs unchanged, so
   * a request that will not fit the day is refused here just as firmly as it
   * would have been online, and a request whose facts are incomplete is refused
   * because no honest window can be derived for it by anyone.
   *
   * @param \Drupal\webform\WebformSubmissionInterface $submission
   *   The saved submission to book. The Dispatch Request is rebuilt from it here;
   *   nothing about the job is taken from the caller.
   * @param \Drupal\custom_webform_handlers\Dispatch\Slot $slot
   *   The pickup arrival staff chose. Re-normalised against the Resource Profile
   *   and re-proved like any other candidate.
   *
   * @return \Drupal\custom_webform_handlers\Dispatch\ReservationResult
   *   The Reservation, or the reason it could not be created.
   */
  public function confirmForStaff(WebformSubmissionInterface $submission, Slot $slot): ReservationResult;

}
