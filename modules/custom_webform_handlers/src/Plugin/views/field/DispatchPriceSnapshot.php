<?php

namespace Drupal\custom_webform_handlers\Plugin\views\field;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\views\Attribute\ViewsField;
use Drupal\views\ResultRow;

/**
 * The recorded price, published only with its currency and its snapshot.
 *
 * One column out of three stored ones, gated on 'view dispatch financial
 * details'. The three are assembled here rather than declared as three fields
 * because a bare amount is not a price: 45.00 is a different fact in USD than in
 * CAD, and without the snapshot identifier there is no way to tie the figure
 * back to the quote the customer accepted. Declaring price_snapshot_currency and
 * price_snapshot_id as fields of their own would also have let a later View
 * reassemble the amount from an ungated numeric column, so the Views data does
 * not declare them at all — they arrive as additional fields of this one.
 *
 * The wording is the Dispatch Planner's own — "45.00 USD, snapshot 7" — because
 * an operator reading a price on the board and the same price on this dashboard
 * must not have to work out whether they are reading the same thing. It departs
 * from the board in one place only: where the board prints a blank for a
 * currency or snapshot it does not have, this names the gap. A dashboard cell
 * reading "45.00 , snapshot" tells an operator nothing about which of the two
 * columns is missing, and a row missing either is a row worth noticing.
 *
 * Nothing is repriced. The amount is rendered from the cents that were stored
 * when the claim was made, in the currency stored with them.
 *
 * @see \Drupal\custom_webform_handlers\Controller\DispatchPlannerAdminController::priceText()
 * @see custom_webform_handlers_views_data()
 */
#[ViewsField("dispatch_price_snapshot")]
class DispatchPriceSnapshot extends DispatchProtectedFieldBase {

  /**
   * {@inheritdoc}
   *
   * A row with no recorded amount renders as the empty string, which
   * advancedRender() replaces with this column's empty text — "No price was
   * recorded", the board's wording for the same absence. A Hold taken before
   * checkout legitimately has no price, so this is an ordinary row, not a
   * defect.
   */
  public function render(ResultRow $values) {
    $cents = $this->getValue($values);
    if (!is_numeric($cents)) {
      return '';
    }

    return new TranslatableMarkup('@amount @currency, snapshot @snapshot', [
      '@amount' => number_format(((int) $cents) / 100, 2),
      '@currency' => $this->recorded($this->getValue($values, 'price_snapshot_currency')),
      '@snapshot' => $this->recorded($this->getValue($values, 'price_snapshot_id')),
    ]);
  }

  /**
   * One part of the price, or a note that it was not stored.
   *
   * @param mixed $value
   *   The raw column value off the row.
   *
   * @return string|\Drupal\Core\StringTranslation\TranslatableMarkup
   *   The value, or "(not recorded)". Both are placed in a translated string by
   *   the caller, which escapes a plain string and passes translated markup
   *   through, so neither can inject markup into the cell.
   */
  private function recorded(mixed $value): string|TranslatableMarkup {
    if (!is_scalar($value)) {
      return new TranslatableMarkup('(not recorded)');
    }

    $value = trim((string) $value);

    return $value === '' ? new TranslatableMarkup('(not recorded)') : $value;
  }

}
