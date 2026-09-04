<?php

namespace Drupal\custom_webform_handlers\Dispatch;

/**
 * A Courier delivery offering, which determines its pickup rules.
 *
 * These are the three Delivery Modes named in CONTEXT.md, expressed as the
 * machine values the planner and its configuration both use. They are namespaced
 * under the Courier parent service on purpose: Courier is the self-bookable
 * service, and a bare "send" would not say which service it belonged to once
 * other services gain modes of their own.
 *
 * Nothing is persisted under these values yet — the Webform carries no
 * delivery_mode element — so these are free to be canonical from the outset. No
 * existing saved Webform value is touched by naming them this way.
 */
enum DeliveryMode: string {

  /**
   * The customer hands the package over at their own pickup location.
   */
  case Send = 'courier_send';

  /**
   * The package is collected from a third party on the customer's behalf.
   */
  case Receive = 'courier_receive';

  /**
   * The package is collected from a store or counter the operator nominates.
   */
  case StorePickup = 'courier_store_pickup';

}
