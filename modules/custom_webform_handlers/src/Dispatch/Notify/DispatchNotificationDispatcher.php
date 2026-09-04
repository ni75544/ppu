<?php

namespace Drupal\custom_webform_handlers\Dispatch\Notify;

use Drupal\Core\Mail\MailManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Sends one committed fact to each of its audiences, and records what happened.
 *
 * This is the only place in the module that hands anything to the mail system, and it
 * runs only on a queue worker. Nothing here is reachable from a web request that a
 * customer or an operator is waiting on, and nothing here is inside a transaction: by
 * the time this class exists, the Reservation has been committed, the lock has been
 * released, and the request that made it has already returned.
 *
 * One audience failing does not stop the other. The customer copy is attempted first —
 * see DispatchEvent::audiences() — so a misconfigured staff address cannot delay the
 * message the person waiting at an address is expecting, and a bounced customer copy
 * still lets the operators hear that a booking moved.
 *
 * Every attempt produces exactly one history row, including the ones where nothing was
 * sent. That is the property the board depends on: a missing row means the worker never
 * ran, and a row saying "not sent" means it ran and decided. Without it, silence and
 * success look identical.
 *
 * At most one copy per audience per event ever reaches a transport, and that is enforced
 * by NotificationClaimInterface rather than by care taken here. A queue job can be
 * delivered to this class more than once — a worker that sent its mail and then died
 * before reporting success has its job released back to the queue and processed again —
 * so "do not send twice" cannot be a property of the calling code. It is a unique index.
 */
final class DispatchNotificationDispatcher {

  /**
   * The module hook_mail() lives in.
   */
  const MAIL_MODULE = 'custom_webform_handlers';

  /**
   * The single mail key this module sends under.
   *
   * One key rather than one per event, because hook_mail() has nothing event-specific
   * to decide: the subject and body arrive already composed. The event and audience
   * travel in the parameters anyway, so hook_mail_alter() can still tell a customer
   * confirmation from a staff review notice.
   */
  const MAIL_KEY = 'dispatch_notification';

  /**
   * The history detail recorded when an announcement had already been attempted.
   *
   * A fixed code rather than a sentence, because it is read by an operator scanning a
   * column and it must mean the same thing every time it appears. Its presence is not
   * a fault: it is the idempotency guard reporting that it did its job.
   */
  const DETAIL_ALREADY_ATTEMPTED = 'already_attempted';

  public function __construct(
    private readonly DispatchNotificationComposer $composer,
    private readonly MailManagerInterface $mailManager,
    private readonly NotificationLogInterface $log,
    private readonly NotificationClaimInterface $claims,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Attempts every audience's copy of one event, and reports what that achieved.
   *
   * Throws only when the claim ledger cannot be reached. That is a deliberate change
   * from the original contract, and the reason is worth stating: without a usable
   * ledger this method cannot tell whether a message has already gone out, and both
   * guesses are wrong — proceeding may send a second copy, declining loses the only
   * copy. Failing is the honest third answer, and the Advanced Queue job type turns it
   * into a retry, on which the ledger is decisive again. Every other failure is caught
   * and returned in the report.
   *
   * Callers that cannot survive an exception must catch it. DispatchNotificationWorker
   * does, because Drupal's core queue runner would otherwise requeue the item forever.
   *
   * The claim is taken after composing and immediately before the transport call, and
   * given back when the transport refuses. Composing first costs nothing on a replay —
   * it is a read — and it keeps a message that was never sendable from consuming the
   * one claim that a later, correctly configured attempt would need.
   */
  public function deliver(DispatchNotification $notification): DeliveryReport {
    $key = $notification->idempotencyKey();
    $sent = 0;
    $skipped = 0;
    $suppressed = 0;
    $failed = 0;
    $failureDetail = '';

    foreach ($notification->event->audiences() as $audience) {
      $message = $this->composer->compose($notification, $audience);

      if (!$message->isSendable()) {
        $skipped++;

        $this->log->record(
          $notification,
          $audience,
          NotificationOutcome::Skipped,
          NotificationRecord::CHANNEL_MAIL,
          $message->skipReason,
        );

        continue;
      }

      if (!$this->claims->claim($key, $audience)) {
        // Somebody already attempted this exact announcement for this exact audience.
        // Recorded as a Skipped attempt so the board still shows one row per audience
        // per run, which is the invariant this class is built on, and so a replay is
        // visible as a replay rather than as silence.
        $suppressed++;

        $this->log->record(
          $notification,
          $audience,
          NotificationOutcome::Skipped,
          NotificationRecord::CHANNEL_MAIL,
          self::DETAIL_ALREADY_ATTEMPTED,
        );

        continue;
      }

      [$outcome, $detail] = $this->send($notification, $message);

      if ($outcome === NotificationOutcome::Sent) {
        $sent++;
      }
      else {
        $failed++;
        $failureDetail = $failureDetail === '' ? $detail : $failureDetail;

        // Released before the failure is recorded, not after. A crash in this gap then
        // leaves no claim and no history row, and the retry sends the message — which
        // loses a line of history. The other order would leave a claim standing for a
        // message that was refused, and the retry would suppress it, which loses the
        // message. Losing history is the cheaper of the two.
        $this->claims->release($key, $audience);
      }

      $this->log->record(
        $notification,
        $audience,
        $outcome,
        NotificationRecord::CHANNEL_MAIL,
        $detail,
      );
    }

    return new DeliveryReport($sent, $skipped, $suppressed, $failed, $failureDetail);
  }

  /**
   * Hands one composed message to the mail system.
   *
   * @return array{0: \Drupal\custom_webform_handlers\Dispatch\Notify\NotificationOutcome, 1: string}
   *   The outcome and an operator-safe detail code.
   */
  private function send(DispatchNotification $notification, DispatchMessage $message): array {
    try {
      $result = $this->mailManager->mail(
        self::MAIL_MODULE,
        self::MAIL_KEY,
        $message->recipient,
        $message->langcode,
        [
          'subject' => $message->subject,
          'body' => $message->body,
          'dispatch_event' => $notification->event->value,
          'dispatch_audience' => $message->audience->value,
        ],
      );
    }
    catch (\Throwable $exception) {
      // The exception class is recorded; the message and the address are not. A log line
      // is a wider audience than the mail was, so it gets less.
      $this->logger->error('A dispatch notification could not be sent for @event on @resource: @type', [
        '@event' => $notification->event->value,
        '@resource' => $notification->resourceId,
        '@type' => get_class($exception),
      ]);

      return [NotificationOutcome::Failed, 'mail_exception'];
    }

    // The mail plugin reports whether the transport accepted the message. That is all
    // this module can honestly claim, which is why the history says "handed to mail".
    return !empty($result['result'])
      ? [NotificationOutcome::Sent, 'accepted']
      : [NotificationOutcome::Failed, 'mail_refused'];
  }

}
