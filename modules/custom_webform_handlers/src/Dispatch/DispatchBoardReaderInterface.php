<?php

namespace Drupal\custom_webform_handlers\Dispatch;

/**
 * The read seam an administrative Dispatch board is built from.
 *
 * Two operations, both read-only. Nothing here claims, confirms, releases,
 * renews, expires, blacks out, or reprices anything: capacity is changed through
 * DispatchPlannerInterface and only there. A board that could mutate the schedule
 * would be a second, unguarded path to the rows the Resource Calendar Guard
 * exists to protect.
 *
 * It is also not a second availability answer. The planner decides what a visitor
 * may book; this describes what is already on the calendar, to a human who is
 * entitled to see it. The two questions read the same rows and must not be
 * confused, so nothing returned here is safe to book against.
 *
 * The return types carry the guarantee. DispatchBoard and
 * DispatchReservationDetail are finished value objects with no entity, no
 * database row, no Hold token, no owner hash, and no PlanningNeighbour anywhere
 * inside them, so a controller or template physically cannot reach past this seam
 * into another customer's data.
 */
interface DispatchBoardReaderInterface {

  /**
   * Reads the board for one Resource over one local calendar range.
   *
   * The range is interpreted as local calendar days in the Resource's own
   * timezone, not as the instants the arguments happen to name: a board is
   * something an operator reads as days, and the days are the Resource's. Both
   * ends are widened to the local day they fall in, and the range is inclusive of
   * the day $to falls in.
   *
   * A request for more than MAX_LOCAL_DAYS is cut to that many days rather than
   * refused, and the board says so through a notice. An unusable request — an
   * unknown Resource, an unconfigured profile, or an end before its start — yields
   * an unavailable board carrying the reason. It is never widened to every
   * Resource and never quietly redirected to the configured default one.
   *
   * @param string $resourceId
   *   The Resource to describe. Not a filter that may be left empty.
   * @param \DateTimeImmutable $from
   *   Any instant on the first local day to cover.
   * @param \DateTimeImmutable $to
   *   Any instant on the last local day to cover, inclusive.
   *
   * @return \Drupal\custom_webform_handlers\Dispatch\DispatchBoard
   *   The board, available or not. Never NULL: the empty state is a board that
   *   states why it is empty.
   */
  public function board(string $resourceId, \DateTimeImmutable $from, \DateTimeImmutable $to): DispatchBoard;

  /**
   * Reads administrator-safe detail for one confirmed Reservation.
   *
   * @param int $dispatchReservationId
   *   The claim to describe.
   *
   * @return \Drupal\custom_webform_handlers\Dispatch\DispatchReservationDetail|null
   *   The Reservation's safe detail, or NULL when no confirmed claim carries that
   *   identifier. A Hold answers NULL exactly as a missing row does, because an
   *   anonymous visitor's unfinished attempt has no detail anyone is entitled to
   *   read.
   */
  public function reservation(int $dispatchReservationId): ?DispatchReservationDetail;

}
