<?php

namespace Drupal\custom_webform_handlers\Dispatch\Storage;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Human labels for the six Dispatch reservation states.
 *
 * Split out of any one screen because the ledger's states are now read in two
 * places — the Dispatch Planner board and the read-only Reservation Dashboard —
 * and a state whose wording differs between them is a state an operator has to
 * translate in their head. The machine names are the interface's constants, so
 * a state added there without a label here fails visibly rather than silently
 * dropping out of a filter.
 *
 * Not the legacy fixed-slot reservation table's states. That table has its own
 * lifecycle and its own labels in ReservationAdminController; the two must not
 * be conflated.
 *
 * There is deliberately no single-state lookup here. Both readers take the whole
 * map — the Views in_operator filter to build its select, core's machine_name
 * field to label a cell — and both already print an unmapped machine name
 * verbatim, which is the right answer for a state this class has not been
 * taught: a row in an unknown state is exactly the row an operator most needs to
 * see, and blanking it or translating it to "Unknown" would hide it.
 *
 * @see \Drupal\custom_webform_handlers\Dispatch\Storage\ReservationStoreInterface
 * @see custom_webform_handlers_views_data()
 */
final class ReservationStateLabels {

  /**
   * Every state a ledger row may carry, in lifecycle order.
   *
   * Ordered as a row travels rather than alphabetically: the two states that
   * occupy time come first, because they are what an operator filters for.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup[]
   *   Labels keyed by the stored machine name.
   */
  public static function options(): array {
    return [
      ReservationStoreInterface::STATE_HELD => new TranslatableMarkup('Held'),
      ReservationStoreInterface::STATE_CONFIRMED => new TranslatableMarkup('Confirmed'),
      ReservationStoreInterface::STATE_RELEASED => new TranslatableMarkup('Released'),
      ReservationStoreInterface::STATE_EXPIRED => new TranslatableMarkup('Expired'),
      ReservationStoreInterface::STATE_CANCELLED => new TranslatableMarkup('Cancelled'),
      ReservationStoreInterface::STATE_REPLANNED => new TranslatableMarkup('Replanned'),
    ];
  }

}
