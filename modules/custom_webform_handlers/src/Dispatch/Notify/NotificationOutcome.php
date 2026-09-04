<?php

namespace Drupal\custom_webform_handlers\Dispatch\Notify;

/**
 * What became of one attempt to tell somebody something.
 *
 * Recorded because the interesting question on a board is not whether the system
 * intended to send a confirmation but whether the customer got one. An operator about
 * to telephone somebody needs to know which it was.
 *
 * The values are stored, so they are stable. Wording lives in
 * DispatchNotificationText.
 */
enum NotificationOutcome: string {

  /**
   * Handed to the mail system, which accepted it.
   *
   * As strong a claim as this module can honestly make. Acceptance by the transport
   * is not delivery to a person, and the history says "sent" rather than "delivered"
   * for exactly that reason.
   */
  case Sent = 'sent';

  /**
   * The mail system refused it, or composing it threw.
   *
   * A failed attempt is recorded and not retried indefinitely. See
   * DispatchNotificationWorker: a permanently bad recipient that requeued itself would
   * spin on every cron run, and the row here is what makes the failure visible instead.
   */
  case Failed = 'failed';

  /**
   * Nothing was sent, on purpose.
   *
   * Not an error. A configuration with no recipient for an audience, a payload whose
   * submission has since been deleted, or a customer copy for a booking with no
   * contact address all land here — the fact that no message went out is itself worth
   * showing on the board, because otherwise silence looks identical to success.
   */
  case Skipped = 'skipped';

  /**
   * Tells whether an operator should look at this row.
   */
  public function needsAttention(): bool {
    return $this !== self::Sent;
  }

}
