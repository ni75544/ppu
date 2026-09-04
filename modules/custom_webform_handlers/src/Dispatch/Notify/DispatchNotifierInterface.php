<?php

namespace Drupal\custom_webform_handlers\Dispatch\Notify;

/**
 * The one way a committed planner fact leaves the planner.
 *
 * A port, deliberately one method wide. Everything the planner knows about telling
 * somebody something is "here is what happened"; which channel carries it, who is
 * addressed, what the sentence says and whether it worked are all decisions on the
 * far side of this interface, so a change to any of them cannot reach back into a
 * capacity transaction.
 *
 * Two rules make this seam safe, and both are contractual rather than advisory:
 *
 * Nothing here sends. An implementation records the intention and returns; delivery
 * happens later, in another request, on a worker. A mail server that has stopped
 * answering must not be able to hold a database lock open, and it cannot hold one it
 * was never given.
 *
 * Nothing here throws, and nothing here reports failure. The caller has already
 * committed — the Reservation exists, the time is withheld, the customer's form has
 * been submitted — so there is no decision left for it to make and no rollback
 * available to it. An implementation that cannot record the intention logs it and
 * returns normally. This is why the method is void: a return value would invite a
 * caller to branch on it, and the only honest branch at that point is none.
 */
interface DispatchNotifierInterface {

  /**
   * Records that one committed fact should be told to its audiences.
   *
   * Call this after the transaction has committed and the lock is released, never
   * inside one.
   *
   * @param \Drupal\custom_webform_handlers\Dispatch\Notify\DispatchNotification $notification
   *   The fact, carrying identifiers and instants only.
   */
  public function notify(DispatchNotification $notification): void;

}
