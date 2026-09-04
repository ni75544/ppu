<?php

namespace Drupal\custom_webform_handlers\Plugin\Commerce\CheckoutPane;

use Drupal\commerce\Response\NeedsRedirectException;
use Drupal\commerce_checkout\Attribute\CommerceCheckoutPane;
use Drupal\commerce_checkout\Plugin\Commerce\CheckoutFlow\CheckoutFlowInterface;
use Drupal\commerce_payment\Plugin\Commerce\CheckoutPane\PaymentProcess;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\custom_webform_handlers\Checkout\CheckoutOutcome;
use Drupal\custom_webform_handlers\Checkout\DispatchCheckoutManagerInterface;
use Drupal\custom_webform_handlers\Checkout\PaidDispatchReference;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Authorises the card and then synchronously confirms capacity, in one request.
 *
 * This is the single seam where money and capacity meet, and it is a subclass of
 * Commerce's own payment_process pane rather than a pane placed after it, for a
 * reason that is a fact about how the parent works rather than a preference. On
 * an on-site gateway the parent authorises the card and advances the flow inside
 * buildPaneForm(), and it advances by throwing NeedsRedirectException — during
 * the build, before any following pane on the same step is ever built. A separate
 * "confirm capacity" pane after payment_process would therefore never run in the
 * same request as the authorisation. Subclassing is what keeps authorise, confirm,
 * and capture in one HTTP request, which is what the settled money model requires:
 *
 *   authorize -> synchronously confirm capacity -> capture only after confirmation.
 *
 * The sequence, all inside this one submit:
 *
 *  1. parent::buildPaneForm() authorises the card. With capture set to FALSE in
 *     the flow configuration it takes no money — the Payment is left in the
 *     authorization state — and then advances by throwing NeedsRedirectException.
 *  2. Advancing to the complete step runs DispatchCheckoutFlow::onStepChange(),
 *     which calls settleAuthorizedCapacity() below before it allows the order to
 *     be placed. The manager runs the Resource Calendar Guard transaction and
 *     commits it, then captures the authorization on success or voids it on
 *     refusal — the money model lives in the manager, not here.
 *  3. On a confirmed outcome the flow places the order and the customer reaches
 *     Step 4. On a refusal the flow never places it, so no order receipt is sent,
 *     and the customer is returned to the payment step with an honest "not booked,
 *     not charged" message.
 *
 * Step 2 is asked for by the flow rather than by this pane's catch below, and that
 * ordering is the point of the arrangement. The parent places the order — and so
 * emails the customer their receipt — inside the very redirectToStep() call that
 * authorised the card, so anything this pane does after catching the advance is
 * already too late to stop a confirmation for a booking that did not happen.
 * DispatchCheckoutFlow explains that seam in full. The catch is kept only as a net
 * for a flow configured without the placement gate, where a late confirmation is
 * still better than none; confirmPaid() is idempotent, so being asked twice
 * confirms once.
 *
 * It delegates everything else to the parent. The off-site and manual gateway
 * paths, the decline handling, the failed-payment event — all of it stays the
 * parent's, and only the on-site authorise-and-advance path is extended, because
 * the operator prerequisite for dispatch is an on-site gateway that supports
 * authorize, capture, and void.
 */
#[CommerceCheckoutPane(
  id: 'dispatch_payment_process',
  label: new TranslatableMarkup('Dispatch payment process'),
  default_step: 'payment',
  wrapper_element: 'container',
)]
final class DispatchPaymentProcess extends PaymentProcess {

  /**
   * The one seam that composes planner, storage, and Commerce for payment.
   */
  protected DispatchCheckoutManagerInterface $checkoutManager;

  /**
   * The clock, for the moment a confirmed authorisation is recorded.
   */
  protected TimeInterface $dispatchTime;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition, ?CheckoutFlowInterface $checkout_flow = NULL): self {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition, $checkout_flow);
    $instance->checkoutManager = $container->get('custom_webform_handlers.dispatch_checkout_manager');
    $instance->dispatchTime = $container->get('datetime.time');

    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function buildPaneForm(array $pane_form, FormStateInterface $form_state, array &$complete_form) {
    try {
      // For an on-site gateway this authorises the card and then advances by
      // throwing NeedsRedirectException; it only returns a form on the off-site
      // path, where there is no inline authorisation to confirm against.
      return parent::buildPaneForm($pane_form, $form_state, $complete_form);
    }
    catch (NeedsRedirectException $advance) {
      // Ordinarily there is nothing left to do here: the flow settled capacity
      // before the parent placed the order, so this is either that settled advance
      // or a decline with nothing to settle. Asking again is the net described
      // above, and it is free in the settled case.
      $refusal = $this->settleAuthorizedCapacity();
      if ($refusal !== NULL) {
        // The manager has already voided the authorisation and released the
        // Hold. Replace the parent's advance with a redirect back to the
        // payment step: the booking did not happen and no money moved.
        $this->messenger()->addError($refusal);
        $this->checkoutFlow->redirectToStep($this->getErrorStepId());
      }

      // Confirmed, or a decline with nothing to confirm: let the parent's own
      // advance stand (to Step 4 on success, to the error step on a decline).
      throw $advance;
    }
  }

  /**
   * Confirms this order's Slot when its card is authorised, or says why not.
   *
   * NULL means the booking may proceed: capacity is a committed fact, or there was
   * no live authorisation to confirm against and so nothing to decide. A string is
   * the customer-facing refusal, by which point the manager has voided the
   * authorisation and released the Hold — not booked, not charged.
   *
   * Public because the decision is needed at a moment this pane does not control.
   * DispatchCheckoutFlow calls it from onStepChange() so a refusal can stop the
   * order being placed at all; calling it twice in one request is safe, because
   * confirmPaid() answers a settled attempt with the same success both times.
   *
   * The authorisation guard is load-bearing. The parent throws
   * NeedsRedirectException on two paths — a successful authorisation advancing to
   * the next step, and a declined card being sent back to the error step — and only
   * the first leaves an authorization Payment on the order, because a decline
   * throws out of createPayment() before anything is saved. Confirming capacity
   * without this guard would book an unpaid Slot on a declined card.
   */
  public function settleAuthorizedCapacity(): ?string {
    if (!$this->orderHasAuthorization()) {
      return NULL;
    }

    $outcome = $this->confirmCapacity();
    if ($outcome !== NULL && $outcome->ok) {
      return NULL;
    }

    return $this->confirmErrorMessage();
  }

  /**
   * Tells whether the order carries a live card authorisation.
   *
   * The state is read from each Payment rather than queried, so the check does
   * not depend on whether the state field is exposed to entity-property queries:
   * a voided or captured Payment is not an authorisation, and only a Payment
   * still in the authorization state means the parent has just authorised inline.
   */
  private function orderHasAuthorization(): bool {
    $storage = $this->entityTypeManager->getStorage('commerce_payment');
    $payments = $storage->loadByProperties(['order_id' => $this->order->id()]);
    foreach ($payments as $payment) {
      if ($payment->getState()->getId() === 'authorization') {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Confirms the Reservation for this order's authorised payment.
   *
   * The reference is built from facts the server already owns: the order id, the
   * submission id stamped on the order when it was created, and the moment now.
   * A malformed reference — most concretely a missing submission stamp — yields
   * NULL from the value object's factory, which this returns unchanged so the
   * caller halts rather than confirming against an order it cannot trace back to
   * a job.
   */
  private function confirmCapacity(): ?CheckoutOutcome {
    $orderId = (int) $this->order->id();
    $submissionId = (int) $this->order->getData('dispatch_submission_id');

    $reference = PaidDispatchReference::create(
      $orderId,
      $submissionId,
      $this->dispatchTime->getCurrentTime(),
      'checkout.payment_process.authorized',
    );
    if ($reference === NULL) {
      return NULL;
    }

    return $this->checkoutManager->confirmPaid($reference);
  }

  /**
   * The message shown when capacity could not be confirmed for a paid card.
   *
   * Deliberately about the pickup time and the money, and nothing internal: no
   * Reservation, Resource, or Hold is named. The customer needs to know the
   * booking did not complete, that they were not charged, and that they may try
   * another time.
   */
  private function confirmErrorMessage(): string {
    return (string) $this->t('The pickup time you selected is no longer available, so this booking was not completed and your card was not charged. Please choose another time.');
  }

}
