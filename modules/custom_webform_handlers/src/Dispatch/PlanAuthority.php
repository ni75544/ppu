<?php

namespace Drupal\custom_webform_handlers\Dispatch;

/**
 * Who is asking the planner to prove a candidate feasible.
 *
 * This exists so one algorithm can serve two callers without becoming two
 * algorithms. Exactly one question changes with authority — may this kind of job
 * be booked without a human looking at it — and nothing else does. Working
 * hours, breaks, blackouts, the grid, the horizon, duration, the routed legs,
 * both repositioning legs, return-to-base and occupancy are physical facts about
 * a vehicle and a day, and a member of staff cannot make a van drive faster by
 * having a permission.
 *
 * So this is deliberately not a "skip the checks" flag. It selects which
 * eligibility answer the planner reads, and that is the whole of its effect. A
 * staff-authority plan that is refused for OutsideWorkingHours, BreakConflict,
 * SlotUnavailable, RepositioningInfeasible, ReturnToBaseInfeasible,
 * LegacyUnplanned, Blackout or IncompleteFacts is refused for exactly the reason
 * a visitor's would have been.
 */
enum PlanAuthority: string {

  /**
   * A member of the public booking their own job through a Webform.
   *
   * Only self-bookable work is planned. Anything the operator's policy routes to
   * Manual Review has no Slots at all, per CONTEXT.md: it creates neither a Hold
   * nor a Reservation and consumes no capacity.
   */
  case Visitor = 'visitor';

  /**
   * A member of staff acting deliberately, having read the request.
   *
   * Staff may plan work that policy withholds from self-service — a job needing
   * two people, a service type not offered online, an over-limit load — because
   * the reason it was withheld is that somebody should look at it, and somebody
   * has. Staff still cannot plan work whose facts are missing: a request with no
   * service type, no second endpoint, or no derivable duration has no honest
   * window for anyone.
   */
  case Staff = 'staff';

}
