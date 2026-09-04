<?php

namespace Drupal\custom_webform_handlers\Plugin\views\field;

use Drupal\views\Attribute\ViewsField;
use Drupal\views\ResultRow;

/**
 * A stored ledger value shown only to a role that may read it.
 *
 * Three columns use it: the pickup and delivery addresses, gated on
 * 'view dispatch exact addresses', and the Commerce order pointer, gated on
 * 'view dispatch financial details'. Nothing here decides who may read what —
 * the permission is named per column in the Views data and enforced by
 * DispatchProtectedFieldBase, which withholds by removing the column from the
 * query rather than by blanking a cell.
 *
 * So this class is only a value renderer, and deliberately thin: an address is
 * a string in a column and needs no assembly. What it does add is a distinction
 * an operator has to be able to make. A row with no order recorded renders as
 * this column's own empty text — "No order recorded" — while a role that may
 * not read orders is not shown the column at all. Returning a bare blank for
 * NULL would have made "there is no order" and "you may not see the order" look
 * identical, and only one of those is a fact about the reservation.
 *
 * @see \Drupal\custom_webform_handlers\Plugin\views\field\DispatchProtectedFieldBase
 * @see custom_webform_handlers_views_data()
 */
#[ViewsField("dispatch_protected_text")]
class DispatchProtectedText extends DispatchProtectedFieldBase {

  /**
   * {@inheritdoc}
   *
   * An empty, missing, or non-scalar value renders as the empty string, which
   * advancedRender() replaces with the column's configured empty text. The
   * non-scalar guard is not defensive theatre: every column routed here is a
   * scalar today, and the guard is what keeps a future one that is not from
   * reaching Html::escape() and turning a page of reservations into an error.
   */
  public function render(ResultRow $values) {
    $value = $this->getValue($values);
    if (!is_scalar($value)) {
      return '';
    }

    $value = trim((string) $value);

    return $value === '' ? '' : $this->sanitizeValue($value);
  }

}
