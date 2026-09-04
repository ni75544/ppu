<?php

namespace Drupal\custom_webform_handlers\Dispatch\Notify;

/**
 * The committed planner facts worth telling somebody about.
 *
 * Every case names something that has already happened. There is no case for an
 * attempt, a proposal, or a Hold: a notification is sent because a transaction
 * committed, so the message can never describe a schedule the database does not
 * have. That is also why nothing here is enqueued from inside the Resource Calendar
 * Guard — see QueueDispatchNotifier.
 *
 * The values are stored in a queue item and in the notification history column, so
 * they are stable identifiers and stay in this vocabulary across releases. Wording
 * lives in DispatchNotificationComposer and the notification settings, for the same
 * reason DispatchReasonText is separate from DispatchReason: a rewording must not
 * become a data migration.
 *
 * Deliberately absent: a visitor abandoning an unfinished Hold. Nothing was
 * confirmed, so there is no promise to correct and no operator decision waiting on
 * it; sending mail about it would train people to ignore this channel.
 */
enum DispatchEvent: string {

  /**
   * A Reservation was confirmed for a customer's own booking.
   */
  case ReservationConfirmed = 'reservation_confirmed';

  /**
   * A confirmed Reservation was moved by staff through the Replan transaction.
   *
   * Carries both the new Reservation and the one it superseded, because the customer
   * is being told their promise changed and the operator is being told which row to
   * stop looking for.
   */
  case ReservationReplanned = 'reservation_replanned';

  /**
   * A confirmed Reservation was released and its time returned to the calendar.
   */
  case ReservationReleased = 'reservation_released';

  /**
   * Time was withheld from the calendar by a Blackout.
   *
   * Staff only. A closure is a fact about the business's own capacity and names no
   * customer's booking.
   */
  case TimeWithheld = 'time_withheld';

  /**
   * A Blackout was withdrawn and the time offered again.
   */
  case TimeReturned = 'time_returned';

  /**
   * A request could not be planned automatically and is waiting for a human.
   *
   * Staff only, and deliberately so. The customer has already been shown, on the
   * form, that their quote is preliminary and under review; a second message saying
   * the same thing in mail adds no information and invites a reply the queue cannot
   * act on.
   */
  case ManualReviewRaised = 'manual_review_raised';

  /**
   * Who this event is composed for, in send order.
   *
   * The customer copy is composed first where there is one, so a failure addressing
   * staff cannot delay the message the person waiting at an address is expecting.
   *
   * @return \Drupal\custom_webform_handlers\Dispatch\Notify\DispatchAudience[]
   *   One or two audiences. Never empty.
   */
  public function audiences(): array {
    return match ($this) {
      self::ReservationConfirmed,
      self::ReservationReplanned,
      self::ReservationReleased => [DispatchAudience::Customer, DispatchAudience::Staff],

      self::TimeWithheld,
      self::TimeReturned,
      self::ManualReviewRaised => [DispatchAudience::Staff],
    };
  }

  /**
   * Tells whether a customer is written to at all for this event.
   */
  public function notifiesCustomer(): bool {
    return in_array(DispatchAudience::Customer, $this->audiences(), TRUE);
  }

  /**
   * Tells whether this event is about one Reservation rather than the calendar.
   *
   * Used to decide whether a payload without a Reservation identifier is incomplete.
   * A Blackout and a Manual Review are about capacity and a queue respectively, and
   * neither has a Reservation to name.
   */
  public function namesReservation(): bool {
    return match ($this) {
      self::ReservationConfirmed,
      self::ReservationReplanned,
      self::ReservationReleased => TRUE,

      self::TimeWithheld,
      self::TimeReturned,
      self::ManualReviewRaised => FALSE,
    };
  }

}
