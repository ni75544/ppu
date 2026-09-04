<?php

namespace Drupal\custom_webform_handlers\Review;

/**
 * Why a Review Request move was refused.
 *
 * A separate vocabulary from DispatchReason, and deliberately so. DispatchReason
 * answers "why can this Slot not be claimed" — a question about a driver's day,
 * asked of the planner. These answer "why can this request not move" — a question
 * about a staff decision, asked of the manager. The two are refused for disjoint
 * sets of causes, and one enum holding both would put "the van cannot get there in
 * time" next to "somebody else already declined this", inviting a caller to handle
 * them as if they were the same kind of no.
 *
 * The one place the two vocabularies meet is scheduling, and they meet by
 * composition rather than by merging: the paid checkout flow settles capacity in
 * the planner's terms and then tells the manager that a Reservation exists. The
 * manager never re-decides capacity, so it never has a planner reason to report.
 *
 * The names are stable. They are logged, they are shown to staff, and — for the
 * three the tracker is allowed to answer with — they are the basis of what a
 * customer is told. What a customer actually reads is chosen in the tracker, not
 * here, because a refusal reason is an internal classification and several of these
 * must never be published at all.
 */
enum ReviewReason: string {

  /**
   * The move was made.
   */
  case Moved = 'moved';

  /**
   * No Review Request exists for what the caller named.
   *
   * The tracker answers with this for a code that matches nothing, and it is the
   * same answer for a code that never existed and a code whose request was purged.
   * Telling those apart would tell a caller whether a code was ever valid.
   */
  case NotFound = 'not_found';

  /**
   * The request is in a state this move is not reachable from.
   *
   * The single refusal the state machine itself produces. It covers approving
   * something nobody is reviewing, declining something already declined, and
   * scheduling something that was never offered a price — all of which are the same
   * fact: ReviewState::allows() said no.
   */
  case IllegalTransition = 'illegal_transition';

  /**
   * Approval was asked for without an authoritative quote to approve.
   *
   * Distinct from an illegal transition, because the state was right and what was
   * missing was the offer. An approved request with no price is a request a customer
   * would be invited to pay an unknown amount for.
   */
  case QuoteRequired = 'quote_required';

  /**
   * Scheduling was asked for without a confirmed Reservation to schedule against.
   *
   * The invariant behind specification item 2, stated as a refusal: Scheduled is the
   * one state that consumes capacity, and it is only ever reached by presenting the
   * Dispatch row the Resource Calendar Guard already committed. A caller that cannot
   * name one is asking the manager to invent capacity.
   */
  case ReservationRequired = 'reservation_required';

  /**
   * A request for this job already exists, so a second would be a second authority.
   *
   * One job, one review. Two open requests for one submission would let two members
   * of staff quote the same work and both be right.
   */
  case AlreadyOpen = 'already_open';

  /**
   * Another writer moved the request between it being read and this move.
   *
   * Every move is a compare-and-set on the state the caller believed, so two people
   * deciding at once produce exactly one decision and the loser is told this rather
   * than silently overwriting. The remedy is to reload and look at what happened.
   */
  case Contended = 'contended';

  /**
   * The input could not be normalised into something the manager could act on.
   */
  case InvalidRequest = 'invalid_request';

  /**
   * The move could not be recorded. A storage fault, not a decision.
   */
  case StorageFailed = 'storage_failed';

}
