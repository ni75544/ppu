<?php

namespace Drupal\custom_webform_handlers\Plugin\Commerce\CheckoutFlow;

use Drupal\commerce_checkout\Plugin\Commerce\CheckoutFlow\MultistepDefault;
use Drupal\commerce_checkout\Attribute\CommerceCheckoutFlow;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\custom_webform_handlers\Plugin\Commerce\CheckoutPane\DispatchPaymentProcess;

/**
 * Commerce's multistep flow, with one addition: no placement without capacity.
 *
 * Everything about the sequence is Commerce's own — this adds no step, no pane,
 * and no configuration. It exists for a single reason, which is a fact about
 * where the parent places the order rather than a preference about flows.
 *
 * CheckoutFlowBase::onStepChange() places the order when the flow advances to the
 * complete step: it dispatches CheckoutEvents::COMPLETION and applies the 'place'
 * transition, and redirectToStep() then saves the order — which is what sends the
 * customer their order receipt, because the dispatch order type has sendReceipt
 * enabled. That all happens inside the parent's
 * PaymentProcess::buildPaneForm() -> redirectToStep($next_step_id) call, which
 * means it happens *before* any code that catches the parent's advance can decide
 * whether the Slot was actually confirmed.
 *
 * A pane that catches the advance and halts afterwards is therefore too late by
 * construction. It can void the authorization and release the Hold — the money and
 * the capacity stay correct — but the order is already placed, so the customer has
 * already been emailed "order confirmed", and CheckoutFlowBase::getStepId() sends
 * every request for a non-draft order to the complete step, so the refused customer
 * is shown the confirmation page no matter which step the halt redirected them to.
 * Both were observed: a refused booking with a voided card produced an "Order #8
 * confirmed" email and a "Your pickup is booked" page.
 *
 * So the gate belongs at the placement itself, which is here. onStepChange() is
 * called before the order is saved, on every path that advances the flow, so
 * refusing here means the order is never placed, the receipt is never sent, and
 * the customer is never told a booking exists. It is also what Task 6 asks for
 * literally: notifications are enqueued only after the capacity commit.
 */
#[CommerceCheckoutFlow(
  id: 'dispatch_flow',
  label: new TranslatableMarkup('Multistep - Dispatch'),
)]
final class DispatchCheckoutFlow extends MultistepDefault {

  /**
   * {@inheritdoc}
   */
  protected function onStepChange($step_id) {
    if ($step_id === 'complete') {
      $refusal = $this->settleCapacityBeforePlacement();
      if ($refusal !== NULL) {
        // Not booked and not charged: the pane's settle has already voided the
        // authorization and released the Hold. Send the customer back to choose
        // another time, and return without calling the parent, so the 'place'
        // transition never runs and no receipt goes out.
        //
        // Re-entering redirectToStep() is deliberate. It sets checkout_step to the
        // error step, calls this method again with a step that places nothing,
        // saves the order once, and throws the redirect the caller needs. The
        // outer redirectToStep() that brought us here never reaches its own save,
        // so 'complete' is never persisted either.
        $this->messenger()->addError($refusal);
        $this->redirectToStep($this->errorStepId());
      }
    }

    parent::onStepChange($step_id);
  }

  /**
   * Confirms the Slot for an authorised card, or says why it was refused.
   *
   * NULL means the order may be placed: either capacity is now a committed fact,
   * or this advance carries no live authorisation to confirm against — a free
   * order, or a flow reaching the complete step with nothing authorised. The
   * decision itself is the payment pane's, and through it the checkout manager's;
   * this only asks at the right moment.
   *
   * A capture that fails is not a refusal. The manager keeps a confirmed
   * Reservation whatever becomes of the money and reports success, holding the
   * attempt for reconciliation, so such an order is placed and confirmed to the
   * customer — the booking is real and only its settlement is open.
   */
  private function settleCapacityBeforePlacement(): ?string {
    $pane = $this->getPane('dispatch_payment_process');
    if (!$pane instanceof DispatchPaymentProcess) {
      // The flow is configured without the dispatch payment pane, so there is no
      // dispatch money model on it to gate. Stock Commerce behaviour stands.
      return NULL;
    }

    return $pane->settleAuthorizedCapacity();
  }

  /**
   * The step a refused customer is returned to, named the way the pane names it.
   *
   * The step holding the payment_information pane, which is where a card is
   * chosen and therefore where a booking is retried — the same step Commerce's own
   * PaymentProcess::getErrorStepId() picks. Falls back to order_information when
   * that pane is disabled, because redirectToStep() rejects '_disabled' and a
   * customer who cannot be told anything is worse than one sent to a step early.
   */
  private function errorStepId(): string {
    $pane = $this->getPane('payment_information');
    $stepId = $pane !== NULL ? $pane->getStepId() : '_disabled';

    return ($stepId === '' || $stepId === '_disabled') ? 'order_information' : $stepId;
  }

}
