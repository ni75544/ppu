<?php

namespace Drupal\custom_webform_handlers\Plugin\Commerce\CheckoutPane;

use Drupal\commerce_checkout\Attribute\CommerceCheckoutPane;
use Drupal\commerce_checkout\Plugin\Commerce\CheckoutPane\CheckoutPaneBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Collects the dispatch contact's name and phone number.
 *
 * Step 3 needs two facts Commerce's own panes cannot store without inventing an
 * address. The email is already handled: contact_information writes it to the
 * order's mail field, which is the address the booking notice is sent to. A name
 * and a phone number have no native home — the only pane that would take them is
 * billing_information, and a billing profile with a name but no country is stored
 * as empty (the reason DispatchOrderFactory refuses to write one). Rather than
 * fabricate a country to make a name persist, this pane writes both to two typed
 * order fields declared for the dispatch bundle, which is exactly what the Field
 * API is for.
 *
 * It collects nothing else. No timezone selector — display time stays the
 * Resource's zone and is never a customer choice — no address, and no serialized
 * Webform payload. The addresses and the Slot were settled server-side before
 * checkout began; re-collecting them here would open a path for a browser to
 * change a fact the price and the capacity were proved against.
 */
#[CommerceCheckoutPane(
  id: 'dispatch_contact_details',
  label: new TranslatableMarkup('Contact details'),
  default_step: 'order_information',
  wrapper_element: 'fieldset',
)]
final class DispatchContactDetails extends CheckoutPaneBase {

  /**
   * The order field the contact name is written to.
   */
  private const NAME_FIELD = 'field_dispatch_contact_name';

  /**
   * The order field the contact phone is written to.
   */
  private const PHONE_FIELD = 'field_dispatch_contact_phone';

  /**
   * {@inheritdoc}
   */
  public function buildPaneForm(array $pane_form, FormStateInterface $form_state, array &$complete_form) {
    $pane_form['contact_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Full name'),
      '#description' => $this->t('The name of the person we should ask for at pickup.'),
      '#required' => TRUE,
      '#maxlength' => 255,
      '#default_value' => $this->fieldValue(self::NAME_FIELD),
    ];
    $pane_form['contact_phone'] = [
      '#type' => 'tel',
      '#title' => $this->t('Phone number'),
      '#description' => $this->t('A number the driver can reach on the day of pickup.'),
      '#required' => TRUE,
      '#maxlength' => 64,
      '#default_value' => $this->fieldValue(self::PHONE_FIELD),
    ];

    return $pane_form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitPaneForm(array &$pane_form, FormStateInterface $form_state, array &$complete_form) {
    $values = $form_state->getValue($pane_form['#parents']);

    // Trimmed, and written only when the field is present on the bundle. The
    // flow saves the order once, after every pane has submitted, so this sets
    // and does not save.
    $this->setField(self::NAME_FIELD, trim((string) ($values['contact_name'] ?? '')));
    $this->setField(self::PHONE_FIELD, trim((string) ($values['contact_phone'] ?? '')));
  }

  /**
   * {@inheritdoc}
   */
  public function buildPaneSummary() {
    $name = $this->fieldValue(self::NAME_FIELD);
    $phone = $this->fieldValue(self::PHONE_FIELD);
    if ($name === '' && $phone === '') {
      return [];
    }

    return [
      '#type' => 'container',
      'name' => ['#markup' => '<div>' . htmlspecialchars($name, ENT_QUOTES) . '</div>'],
      'phone' => ['#markup' => '<div>' . htmlspecialchars($phone, ENT_QUOTES) . '</div>'],
    ];
  }

  /**
   * Reads a stored order field value, or an empty string when it holds none.
   */
  private function fieldValue(string $field): string {
    if (!$this->order->hasField($field)) {
      return '';
    }
    $item = $this->order->get($field)->first();

    return $item !== NULL ? (string) $item->value : '';
  }

  /**
   * Writes a value to an order field only when the bundle declares it.
   *
   * The guard is deliberate: a site that has enabled checkout but not yet run
   * the field install should degrade to collecting nothing rather than fatally
   * on a missing field. The install hook creates both fields, so this is a
   * belt-and-braces check, not an expected path.
   */
  private function setField(string $field, string $value): void {
    if ($this->order->hasField($field)) {
      $this->order->set($field, $value);
    }
  }

}
