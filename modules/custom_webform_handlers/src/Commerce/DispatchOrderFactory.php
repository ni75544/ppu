<?php

namespace Drupal\custom_webform_handlers\Commerce;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_price\Price;
use Drupal\commerce_store\CurrentStoreInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\custom_webform_handlers\Checkout\CheckoutContact;
use Drupal\custom_webform_handlers\Checkout\QuoteSnapshot;
use Drupal\custom_webform_handlers\Dispatch\Slot;
use Drupal\webform\WebformSubmissionInterface;
use Psr\Log\LoggerInterface;

/**
 * Builds the one Commerce order a dispatch job is paid through.
 *
 * Small on purpose, and it decides nothing. It does not consult the gate, it does
 * not touch capacity, and it cannot be reached without a QuoteSnapshot — which has
 * no public constructor, so the amount it writes can only ever have come from the
 * server's own quote. That is how "the browser never sets an order amount" is a
 * property of the type system here rather than a check somebody has to remember.
 *
 * Everything about the order is Commerce's. The price lock is Commerce's
 * overridden_unit_price flag, not a custom field: OrderRefresh recalculates a unit
 * price only from a purchased entity, and a dispatch_service order item has none,
 * so the figure written here is the figure that stays. The email is Commerce's mail
 * field with Commerce's own customer_email_overridden flag. The order number is
 * Commerce's number pattern. The states are Commerce's order_fulfillment workflow.
 *
 * The contact's name is deliberately not written. A name belongs on the billing
 * profile, and Commerce's address field treats an address with no country code as
 * empty (AddressItem::isEmpty(), address module), so a name-only profile would be
 * stored as nothing at all. The dispatch checkout instead collects the name and
 * phone in its own pane (DispatchContactDetails) and writes them to two typed order
 * fields; inventing a country here to make a name persist on a billing profile
 * would be fabricating billing data for no gain.
 */
final class DispatchOrderFactory {

  /**
   * The order type this module's config/install ships.
   */
  const ORDER_TYPE = 'dispatch';

  /**
   * The one order-item type a dispatch order carries.
   */
  const ORDER_ITEM_TYPE = 'dispatch_service';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly CurrentStoreInterface $currentStore,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Opens a draft order for one job at one Slot, or NULL if it cannot.
   *
   * NULL is a refusal, not a failure to report upward as an exception: the caller
   * is a use case that must undo an internal Hold when this does not work, and it
   * can only do that if it is told plainly. Today NULL is also the honest answer
   * for a site with no Commerce store, which is the state this one is in.
   */
  public function create(WebformSubmissionInterface $submission, Slot $slot, CheckoutContact $contact, QuoteSnapshot $quote): ?OrderInterface {
    $submissionId = (int) $submission->id();
    if ($submissionId < 1) {
      return NULL;
    }

    $store = $this->currentStore->getStore();
    if ($store === NULL) {
      $this->logger->warning('A dispatch checkout was refused for submission @sid: Commerce resolves no store, so no order can be opened.', [
        '@sid' => $submissionId,
      ]);
      return NULL;
    }

    $item = NULL;
    try {
      $item = $this->createItem($slot, $quote);
      $customerId = (int) $submission->getOwnerId();

      /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
      $order = $this->entityTypeManager->getStorage('commerce_order')->create([
        'type' => self::ORDER_TYPE,
        'store_id' => $store->id(),
        'uid' => $customerId,
        'mail' => $contact->email,
        'order_items' => [$item],
      ]);

      // Commerce's own flag, and the reason the address the visitor gave survives.
      // Without it OrderRefresh overwrites the order email with the account email
      // for any authenticated customer.
      $order->setData('customer_email_overridden', TRUE);

      // The submission this order pays for, stamped where the confirmation seam can
      // read it back. When payment authorizes, the confirm trigger holds only the
      // order — this is how it recovers which job to confirm without a second store
      // to consult. It is order metadata, not a field, so it never renders on a
      // receipt and never claims to be billing data.
      $order->setData('dispatch_submission_id', $submissionId);
      $order->save();
    }
    catch (EntityStorageException | \InvalidArgumentException $e) {
      $this->discardItem($item);
      $this->logger->error('A dispatch order could not be opened for submission @sid: @message', [
        '@sid' => $submissionId,
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }

    return $order;
  }

  /**
   * Removes a draft order that never became a booking.
   *
   * Refuses anything that is no longer a draft, and refuses anything paid, so a
   * failed step late in checkout cannot delete an order a customer has been
   * charged for. Commerce deletes the order items itself in Order::postDelete(),
   * so there is nothing else to clean up here.
   */
  public function discard(?OrderInterface $order): bool {
    if ($order === NULL || $order->isNew()) {
      return FALSE;
    }

    if ($order->getState()->getId() !== 'draft' || $order->isPaid()) {
      return FALSE;
    }

    try {
      $order->delete();
    }
    catch (EntityStorageException $e) {
      $this->logger->error('A draft dispatch order @id could not be discarded: @message', [
        '@id' => (int) $order->id(),
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Applies Commerce's own cancel transition to an unpaid dispatch order.
   *
   * Cancellation rather than deletion, because a customer who abandoned a checkout
   * is part of the record and a deleted order cannot be asked about later. It
   * decides nothing about capacity: by the time this runs the Hold has already been
   * given back, and per the design an order state change is never what frees a
   * Slot.
   *
   * The transition is Commerce's `cancel` from the order_fulfillment workflow, and
   * whether it is available is Commerce's answer, not a condition rewritten here.
   * FALSE means nothing was changed — a paid order, a foreign order type, or a
   * state the workflow does not allow cancelling from.
   */
  public function cancel(int $orderId): bool {
    if ($orderId < 1) {
      return FALSE;
    }

    try {
      /** @var \Drupal\commerce_order\Entity\OrderInterface|null $order */
      $order = $this->entityTypeManager->getStorage('commerce_order')->load($orderId);
    }
    catch (\Exception) {
      $this->logger->error('Commerce order storage was unavailable, so order @id could not be cancelled.', [
        '@id' => $orderId,
      ]);
      return FALSE;
    }

    if (!$order instanceof OrderInterface || $order->bundle() !== self::ORDER_TYPE) {
      return FALSE;
    }

    if ($order->isPaid()) {
      // A paid order belongs to a Reservation. Cancelling it here would leave a
      // confirmed booking attached to a cancelled order.
      return FALSE;
    }

    $state = $order->get('state')->first();
    if ($state === NULL || !$state->isTransitionAllowed('cancel')) {
      return FALSE;
    }

    try {
      $state->applyTransitionById('cancel');
      $order->save();
    }
    catch (EntityStorageException | \InvalidArgumentException $e) {
      $this->logger->error('Dispatch order @id could not be cancelled: @message', [
        '@id' => $orderId,
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Builds and saves the single line item, priced from the snapshot.
   */
  private function createItem(Slot $slot, QuoteSnapshot $quote): OrderItemInterface {
    /** @var \Drupal\commerce_order\Entity\OrderItemInterface $item */
    $item = $this->entityTypeManager->getStorage('commerce_order_item')->create([
      'type' => self::ORDER_ITEM_TYPE,
      'title' => $this->title($slot),
      'quantity' => 1,
    ]);

    // TRUE is Commerce's price lock. It marks the unit price as overridden, which
    // is the same facility a manual order adjustment uses, so no order processor
    // and no custom field are needed to keep the quoted figure.
    $item->setUnitPrice(new Price($quote->amountDecimal(), $quote->currency), TRUE);
    $item->save();

    return $item;
  }

  /**
   * Deletes an order item that never reached an order.
   */
  private function discardItem(?OrderItemInterface $item): void {
    if ($item === NULL || $item->isNew()) {
      return;
    }

    try {
      $item->delete();
    }
    catch (EntityStorageException $e) {
      $this->logger->error('An orphaned dispatch order item @id could not be removed: @message', [
        '@id' => (int) $item->id(),
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Names the line item by its pickup arrival, in the Slot's own zone.
   *
   * The Slot carries the zone it was built in, so the reading here is the same
   * local time the visitor chose even if the Resource Profile's zone is changed
   * afterwards. The instant itself stays UTC in storage, per the project rule that
   * public pages show New York local time and canonical instants are stored in UTC.
   *
   * It names no address, no customer, and no Resource. An order item title is
   * customer-visible on a Commerce receipt, and a Resource identifier is an
   * internal fact about which van is doing the work.
   */
  private function title(Slot $slot): string {
    $local = (new \DateTimeImmutable('@' . $slot->startTimestamp))
      ->setTimezone(new \DateTimeZone($slot->timezone))
      ->format('D, j M Y, g:i A');

    return 'Dispatch pickup, ' . $local;
  }

}
