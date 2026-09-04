<?php

namespace Drupal\custom_webform_handlers\Dispatch\Status;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Staff-facing wording for a Resource's reported state.
 *
 * Separated from the enum for the reason DispatchReasonText is separated from
 * DispatchReason: the stored values are stable identifiers and the sentences around
 * them are not, so a rewording must not become a data migration.
 *
 * Every sentence here is written for an operator reading a board. None of it is
 * written for a customer: a customer is told about their own arrival by a
 * notification, and is never shown where the vehicle is, because that is a fact
 * about other people's jobs as much as about theirs.
 */
final class ResourceStatusText {

  /**
   * Names one state in a few words, for a table cell or a select option.
   */
  public static function label(ResourceStatusState $state): TranslatableMarkup {
    return match ($state) {
      ResourceStatusState::Available => new TranslatableMarkup('Available'),
      ResourceStatusState::Unavailable => new TranslatableMarkup('Unavailable'),
      ResourceStatusState::EnRoutePickup => new TranslatableMarkup('En route to pickup'),
      ResourceStatusState::AtPickup => new TranslatableMarkup('At pickup'),
      ResourceStatusState::EnRouteDelivery => new TranslatableMarkup('En route to delivery'),
      ResourceStatusState::Completed => new TranslatableMarkup('Completed'),
    };
  }

  /**
   * Says what recording one state does, and what it deliberately does not do.
   *
   * Shown beside the choice on the staff form. An operator about to report a
   * vehicle unavailable should learn from the form itself that doing so withholds
   * no capacity, rather than from a double booking an hour later.
   */
  public static function consequence(ResourceStatusState $state): TranslatableMarkup {
    return match ($state) {
      ResourceStatusState::Available => new TranslatableMarkup('Working and between jobs. Records an observation only; what is bookable is still decided by the working hours, the closures, and the bookings already on the calendar.'),
      ResourceStatusState::Unavailable => new TranslatableMarkup('Reported unable to work. This withholds no time — to stop the calendar offering it, add a closure, which is checked against the work already booked.'),
      ResourceStatusState::EnRoutePickup => new TranslatableMarkup('Driving to the pickup for the booking named below.'),
      ResourceStatusState::AtPickup => new TranslatableMarkup('At the pickup for the booking named below, doing pickup work.'),
      ResourceStatusState::EnRouteDelivery => new TranslatableMarkup('Driving from the pickup to the delivery for the booking named below.'),
      ResourceStatusState::Completed => new TranslatableMarkup('The booking named below is finished. Its Dispatch Window is left exactly as it was proved: finishing early frees nothing, because the next job was planned against the window and not against a hope about this one.'),
    };
  }

  /**
   * Every state as select options, in the order a day runs through them.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Stored value to label.
   */
  public static function options(): array {
    $options = [];
    foreach (ResourceStatusState::cases() as $state) {
      $options[$state->value] = self::label($state);
    }

    return $options;
  }

}
