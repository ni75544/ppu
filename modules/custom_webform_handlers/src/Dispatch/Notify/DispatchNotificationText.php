<?php

namespace Drupal\custom_webform_handlers\Dispatch\Notify;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Staff-facing wording for notification events, audiences and outcomes.
 *
 * Separated from the three enums for the reason DispatchReasonText is separated from
 * DispatchReason: the stored values are stable identifiers and the sentences around
 * them are not, so a rewording must not become a data migration.
 *
 * All of it is written for an operator: these strings name events on a settings form
 * and label rows on the board. The sentences a customer receives are not here — those
 * come from the notification templates, which a site owner edits, because the words a
 * business uses with its own customers are its business and not this module's.
 */
final class DispatchNotificationText {

  /**
   * Names one event in a few words, for a checkbox or a table cell.
   */
  public static function event(DispatchEvent $event): TranslatableMarkup {
    return match ($event) {
      DispatchEvent::ReservationConfirmed => new TranslatableMarkup('Booking confirmed'),
      DispatchEvent::ReservationReplanned => new TranslatableMarkup('Booking moved'),
      DispatchEvent::ReservationReleased => new TranslatableMarkup('Booking released'),
      DispatchEvent::TimeWithheld => new TranslatableMarkup('Time closed'),
      DispatchEvent::TimeReturned => new TranslatableMarkup('Closure withdrawn'),
      DispatchEvent::ManualReviewRaised => new TranslatableMarkup('Request needs review'),
    };
  }

  /**
   * Says who hears about one event and what it is for.
   *
   * Shown beside the switch on the settings form. Somebody deciding whether to turn an
   * event on should learn from the form who receives it, rather than from a customer
   * asking why they were told a vehicle had been closed for the afternoon.
   */
  public static function eventPurpose(DispatchEvent $event): TranslatableMarkup {
    return match ($event) {
      DispatchEvent::ReservationConfirmed => new TranslatableMarkup('Sent to the customer and to staff once a booking has been proved against the calendar. Carries the promised arrival and the window it sits inside.'),
      DispatchEvent::ReservationReplanned => new TranslatableMarkup('Sent to the customer and to staff after staff move a confirmed booking. Carries the new promise; the old one is named for staff only.'),
      DispatchEvent::ReservationReleased => new TranslatableMarkup('Sent to the customer and to staff when a confirmed booking is released and its time returned to the calendar.'),
      DispatchEvent::TimeWithheld => new TranslatableMarkup('Staff only. A closure is a fact about your own capacity and names no booking, so no customer is written to.'),
      DispatchEvent::TimeReturned => new TranslatableMarkup('Staff only. Tells the team that time which had been closed is being offered again.'),
      DispatchEvent::ManualReviewRaised => new TranslatableMarkup('Staff only. A request the planner would not schedule is waiting for a person. The customer has already been shown that their quote is under review, so telling them again adds nothing.'),
    };
  }

  /**
   * Names one audience.
   */
  public static function audience(DispatchAudience $audience): TranslatableMarkup {
    return match ($audience) {
      DispatchAudience::Customer => new TranslatableMarkup('Customer'),
      DispatchAudience::Staff => new TranslatableMarkup('Staff'),
    };
  }

  /**
   * Names one outcome.
   *
   * "Handed to mail" rather than "delivered", because that is all the module knows:
   * the transport accepted the message. A board that said "delivered" would be making
   * a claim about somebody else's mail server.
   */
  public static function outcome(NotificationOutcome $outcome): TranslatableMarkup {
    return match ($outcome) {
      NotificationOutcome::Sent => new TranslatableMarkup('Handed to mail'),
      NotificationOutcome::Failed => new TranslatableMarkup('Failed'),
      NotificationOutcome::Skipped => new TranslatableMarkup('Not sent'),
    };
  }

  /**
   * Every event as checkbox options, in the order a job runs through them.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Stored value to label.
   */
  public static function eventOptions(): array {
    $options = [];
    foreach (DispatchEvent::cases() as $event) {
      $options[$event->value] = self::event($event);
    }

    return $options;
  }

}
