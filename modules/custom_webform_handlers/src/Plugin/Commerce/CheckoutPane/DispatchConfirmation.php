<?php

namespace Drupal\custom_webform_handlers\Plugin\Commerce\CheckoutPane;

use Drupal\commerce_checkout\Attribute\CommerceCheckoutPane;
use Drupal\commerce_checkout\Plugin\Commerce\CheckoutFlow\CheckoutFlowInterface;
use Drupal\commerce_checkout\Plugin\Commerce\CheckoutPane\CheckoutPaneBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\custom_webform_handlers\Checkout\CheckoutAttemptStore;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Step 4: the booking is confirmed, said in the customer's own terms.
 *
 * This pane is reachable only through the complete step, which the flow only
 * advances to after DispatchPaymentProcess authorised the card, the manager
 * committed the Reservation under the Resource Calendar Guard, and the capture
 * succeeded or was held for reconciliation. So by the time this renders the booking
 * should be a fact — but "should be" is not something a page that tells a customer
 * their money bought them a pickup may rely on, so it is checked here rather than
 * assumed. The check is one read of the customer's own Checkout Attempt: it either
 * carries a Reservation or it does not, and only the first is told as a booking.
 *
 * That guard is not theoretical. Commerce places the order — and so emails the
 * receipt — inside the same call that authorises the card, before any code can
 * decide whether the Slot was confirmed, and CheckoutFlowBase::getStepId() sends
 * every request for a placed order to the complete step. A refused booking with a
 * voided card therefore used to land here and be told "Your pickup is booked".
 * DispatchCheckoutFlow now refuses the placement itself, which is the real fix; this
 * pane declining to claim a booking it cannot prove is the second line.
 *
 * It reads only what a Commerce receipt already shows the customer: the order
 * number, the pickup line the order item was named with, and the email the notice
 * went to. It names no Reservation, no Resource, no Hold, and no submission — those
 * are internal facts about which van does the work and which record it came from,
 * and a confirmation screen is not where they belong. Everything shown is loaded
 * from the customer's own order under Commerce's own checkout access, and the one
 * added read is that same order's attempt row, of which nothing but "is there a
 * booking" is ever published.
 */
#[CommerceCheckoutPane(
  id: 'dispatch_confirmation',
  label: new TranslatableMarkup('Dispatch confirmation'),
  default_step: 'complete',
  wrapper_element: 'container',
)]
final class DispatchConfirmation extends CheckoutPaneBase {

  /**
   * The private Checkout Attempt row for this order, read to prove the booking.
   */
  protected CheckoutAttemptStore $attempts;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition, ?CheckoutFlowInterface $checkout_flow = NULL) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition, $checkout_flow);
    $instance->attempts = $container->get('custom_webform_handlers.checkout_attempt_store');

    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function buildPaneForm(array $pane_form, FormStateInterface $form_state, array &$complete_form) {
    $pane_form['#attributes']['class'][] = 'dispatch-confirmation';

    if (!$this->bookingIsConfirmed()) {
      return $this->buildUnbookedNotice($pane_form);
    }

    $pane_form['heading'] = [
      '#type' => 'html_tag',
      '#tag' => 'h2',
      '#value' => $this->t('Your pickup is booked'),
    ];

    // The Commerce order number, which is the customer's own reference and is
    // assigned by the time the flow reaches the complete step. Guarded all the
    // same, so a misconfiguration shows one fewer line rather than an empty label.
    $orderNumber = $this->order->getOrderNumber();
    if ($orderNumber !== NULL && $orderNumber !== '') {
      $pane_form['order_number'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Confirmation number: @number', ['@number' => $orderNumber]),
      ];
    }

    // The pickup line is the order item's own title — "Dispatch pickup, <local
    // time>", named in the Slot's zone when the order was created. It is read
    // back rather than reformatted here so the confirmation cannot disagree with
    // the receipt about the one fact that matters to the customer.
    $pickup = $this->pickupPromise();
    if ($pickup !== '') {
      $pane_form['pickup'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Pickup: @when', ['@when' => $pickup]),
      ];
    }

    $email = $this->order->getEmail();
    if ($email !== NULL && $email !== '') {
      $pane_form['email'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('We have emailed your confirmation to @email.', ['@email' => $email]),
      ];
    }

    $pane_form['next_steps'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Your driver will call the phone number you gave, on the day of pickup. There is nothing more you need to do.'),
    ];

    return $pane_form;
  }

  /**
   * Whether this order's own attempt row carries a confirmed Reservation.
   *
   * Paid and ReconciliationRequired both do: in the second the booking stands and
   * only its money is unresolved, which is a matter for an operator and not
   * something to worry the customer with on a page about their pickup.
   *
   * A missing attempt row answers FALSE. Every dispatch checkout opens one before
   * payment begins, so an order reaching this pane without one is an order whose
   * booking cannot be proved, and the honest thing is not to claim it.
   */
  private function bookingIsConfirmed(): bool {
    $attempt = $this->attempts->loadByOrder((int) $this->order->id());

    return $attempt !== NULL && $attempt->state->hasReservation();
  }

  /**
   * What a customer is told when the order reached this page without a booking.
   *
   * The same two facts the payment step would have given them, because they are
   * the two that matter and neither is internal: the booking did not complete, and
   * the money was not taken. No order number and no pickup line, since naming
   * either would read as a confirmation of something that does not exist.
   */
  private function buildUnbookedNotice(array $pane_form): array {
    $pane_form['heading'] = [
      '#type' => 'html_tag',
      '#tag' => 'h2',
      '#value' => $this->t('This booking was not completed'),
    ];
    $pane_form['detail'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('The pickup time you selected could not be confirmed, so no booking was made and your card was not charged. Please start again and choose another time.'),
    ];

    return $pane_form;
  }

  /**
   * The customer-facing pickup line, taken from the order's own first line item.
   *
   * An empty string when the order has no item to name, which the caller renders
   * as one fewer line rather than an empty label.
   */
  private function pickupPromise(): string {
    foreach ($this->order->getItems() as $item) {
      $title = trim((string) $item->getTitle());
      if ($title !== '') {
        return $title;
      }
    }

    return '';
  }

}
