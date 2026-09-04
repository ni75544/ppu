<?php

namespace Drupal\custom_webform_handlers\Review;

/**
 * Where one Review Request has got to.
 *
 * A Review Request is the staff-side lifecycle of a job the quote engine could
 * not price on its own. It is deliberately not a Reservation, not a Hold, and not
 * a Commerce order state, because it answers a different question from all three:
 * "has a human agreed to do this work, and on what terms". Capacity is settled
 * inside the Resource Calendar Guard and nowhere else, and money is settled by
 * Commerce; this enum settles neither, which is the whole point of keeping it
 * separate. An approved request is an offer, not a booking.
 *
 * The seven cases below are the entire lifecycle. Four of them are terminal, and
 * the two that are not — Submitted and UnderReview — are the only states in which
 * a decision is still outstanding. Approved sits between the two: it is a live
 * offer that can still be withdrawn or paid for, so it is neither open work nor
 * finished work, and it is the only state Scheduled is reachable from.
 *
 * The transition table lives here, in one place, as a statement of shape. It is
 * not enforcement: ReviewManagerInterface is the only thing that may move a
 * request, and it is the only caller of allowedNext(). Forms ask the manager, ECA
 * is not given a path to it at all, and no form or reaction rule may restate the
 * table — a second copy of a state machine is a second answer to "may this job be
 * declined after it was approved", and those two answers will eventually differ.
 */
enum ReviewState: string {

  /**
   * The customer's request arrived and nobody has picked it up yet.
   *
   * The state every Review Request opens in. Reachable from nothing.
   */
  case Submitted = 'submitted';

  /**
   * A member of staff has taken the request and is working out a price.
   *
   * Open work, and the only thing it adds over Submitted is that somebody owns it.
   * That distinction is what stops two people quoting the same job.
   */
  case UnderReview = 'under_review';

  /**
   * Staff prepared an offer: an authoritative quote the customer may now pay.
   *
   * Not a booking. No Slot is held, no Reservation exists, and no capacity is
   * consumed — consumesCapacity() is FALSE here on purpose. Approval means the
   * work has a price and a willing operator, and nothing about a driver's day is
   * decided by it. The customer converts the offer by paying, which is the only
   * path to Scheduled.
   */
  case Approved = 'approved';

  /**
   * The offer was paid for and the paid flow confirmed a Reservation.
   *
   * Terminal, and the only state that consumes capacity. It is reached exclusively
   * from Approved, and exclusively by the checkout path, after the Resource
   * Calendar Guard transaction has committed and a Dispatch row exists. Nothing
   * moves out of it: reversing a scheduled job would mean giving back a confirmed
   * Reservation for a review reason, and a confirmed booking is never given back
   * that way. An operator who must stop the vehicle uses the Reservation's own
   * release operation, which is a capacity decision made in the capacity system.
   */
  case Scheduled = 'scheduled';

  /**
   * Staff will not do this work, or will not do it on these terms.
   *
   * Terminal. Reachable from every non-terminal state, including Approved — an
   * offer can be withdrawn before it is paid for.
   */
  case Declined = 'declined';

  /**
   * The customer withdrew, or staff withdrew it on their behalf.
   *
   * Terminal, and distinct from Declined for the reason a refund is distinct from
   * a refusal: nobody said no to the work, it simply is not wanted. The two are
   * told apart in the audit trail and shown differently to the customer.
   */
  case Cancelled = 'cancelled';

  /**
   * Nobody decided in time, so the request lapsed.
   *
   * Terminal. Set by the scheduled sweep and by nothing else, and only for a
   * request that was still open when its deadline passed. An approved offer can
   * lapse too: an unpaid quote does not stay valid forever.
   */
  case Expired = 'expired';

  /**
   * Tells whether a decision on this request is still outstanding.
   *
   * Approved is excluded: the staff decision has been made and what is outstanding
   * is the customer's payment. That is why an approved request does not appear in
   * the work queue while still being live enough to move.
   */
  public function isOpen(): bool {
    return $this === self::Submitted || $this === self::UnderReview;
  }

  /**
   * Tells whether anything at all is still reachable from this state.
   */
  public function isTerminal(): bool {
    return $this->allowedNext() === [];
  }

  /**
   * Tells whether a request in this state has taken a driver's time.
   *
   * True for exactly one state. This is the invariant Task 7 exists to protect:
   * approving a job must never consume capacity, so if this ever answers TRUE for
   * Approved, staff will have been given a way to sell a Slot without the guard.
   */
  public function consumesCapacity(): bool {
    return $this === self::Scheduled;
  }

  /**
   * Tells whether an unattended sweep may lapse a request in this state.
   *
   * Scheduled is excluded because it is a paid booking, and the terminal states
   * are excluded because they are already decided. Approved is included: an offer
   * nobody paid for stops standing after its deadline.
   */
  public function mayExpire(): bool {
    return $this === self::Submitted || $this === self::UnderReview || $this === self::Approved;
  }

  /**
   * The states this one may legally move to.
   *
   * Read only by ReviewManagerInterface's implementation. Scheduled is absent from
   * every list but Approved's, so no path exists by which a request that was never
   * offered a price becomes a booking, and Scheduled's own list is empty so no path
   * exists out of one.
   *
   * @return \Drupal\custom_webform_handlers\Review\ReviewState[]
   *   The legal destinations, empty when this state is terminal.
   */
  public function allowedNext(): array {
    return match ($this) {
      self::Submitted => [self::UnderReview, self::Declined, self::Cancelled, self::Expired],
      self::UnderReview => [self::Approved, self::Declined, self::Cancelled, self::Expired],
      self::Approved => [self::Scheduled, self::Declined, self::Cancelled, self::Expired],
      self::Scheduled, self::Declined, self::Cancelled, self::Expired => [],
    };
  }

  /**
   * Tells whether this state may move to the given one.
   */
  public function allows(self $next): bool {
    return in_array($next, $this->allowedNext(), TRUE);
  }

  /**
   * Returns the state a stored value names, or NULL when it names none.
   *
   * NULL rather than a default, for the reason CheckoutState::fromStored() gives:
   * a row whose state cannot be read must not be treated as open work. An
   * unreadable state is refused, not guessed at, so a corrupted row can never be
   * approved into an offer nobody meant to make.
   */
  public static function fromStored(?string $stored): ?self {
    return $stored === NULL ? NULL : self::tryFrom(trim($stored));
  }

}
