<?php

namespace Drupal\custom_webform_handlers\Plugin\AdvancedQueue\JobType;

use Drupal\advancedqueue\Attribute\AdvancedQueueJobType;
use Drupal\advancedqueue\Job;
use Drupal\advancedqueue\JobResult;
use Drupal\advancedqueue\Plugin\AdvancedQueue\JobType\JobTypeBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\custom_webform_handlers\Dispatch\Notify\DispatchNotification;
use Drupal\custom_webform_handlers\Dispatch\Notify\DispatchNotificationDispatcher;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Announces one committed dispatch fact, with bounded retries and a visible outcome.
 *
 * Replaces the core QueueWorker for every newly enqueued notification. The old worker
 * remains registered and drains whatever is still sitting in the core queue table, but
 * nothing writes to that queue any more, so the two cannot both act on one event — see
 * QueueDispatchNotifier, which is the module's only enqueue site.
 *
 * The reason for moving is retry, and the core queue could not give it. Drupal reads an
 * escaping exception as "run this item again, forever", which for a permanently
 * unroutable address is an unbounded loop and for an already-accepted customer copy is a
 * duplicate on every cron run. The old worker therefore swallowed everything and
 * finished with the item — correct, given the tools, but it meant a mail server being
 * briefly unreachable lost the announcement permanently. Here a failure is retried a
 * bounded number of times with a delay, and then recorded as a permanent failure that an
 * operator can see on the queue listing without it ever having sent anything twice.
 *
 * Nothing in this class throws, and that is not tidiness. Advanced Queue's processor
 * treats a thrown exception as an immediate permanent failure with no retry, whereas a
 * returned JobResult::failure() is compared against the retry count and rescheduled. So
 * throwing would silently convert every transient error — a mail server timing out, a
 * lost database connection, the claim ledger being briefly unreachable — into exactly
 * the permanent loss this job type exists to prevent. Every failure is returned.
 *
 * The job message is counts and codes. It is stored on the job row, listed in the
 * administrative queue UI and read by anyone with queue access, which is a wider
 * audience than the mail had. So no recipient, no subject, no body, and none of the
 * operator-entered detail text that travels in the payload.
 *
 * Not an authority over anything. By the time a job of this type runs, the Reservation
 * is committed, the payment is captured and the locks are long released; this sends mail
 * and writes history rows. A permanent failure here leaves capacity, payment and
 * availability exactly as they were.
 */
#[AdvancedQueueJobType(
  id: 'custom_webform_handlers_dispatch_notify',
  label: new TranslatableMarkup('Dispatch notification'),
  max_retries: 2,
  retry_delay: 300,
  allow_duplicates: FALSE,
)]
final class DispatchNotifyJob extends JobTypeBase implements ContainerFactoryPluginInterface {

  /**
   * Reported when the payload cannot be turned into an announcement.
   */
  const FAILURE_UNREADABLE = 'payload_unreadable';

  /**
   * Reported when the attempt could not be carried out at all.
   */
  const FAILURE_UNAVAILABLE = 'delivery_unavailable';

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly DispatchNotificationDispatcher $dispatcher,
    private readonly LoggerInterface $logger,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('custom_webform_handlers.dispatch_notification_dispatcher'),
      $container->get('logger.channel.custom_webform_handlers'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function process(Job $job): JobResult {
    $notification = DispatchNotification::fromQueueItem($job->getPayload());

    if ($notification === NULL) {
      // Permanent on the first attempt, by passing a retry ceiling of zero. This is
      // the one failure retrying cannot fix: the payload names an event this release
      // does not recognise, or no Resource at all, and it will still name the same
      // thing in five minutes. Failing it now puts it on the queue listing where it is
      // visible, rather than deleting it as the core worker had to.
      $this->logger->warning('A dispatch notification job could not be read and was failed permanently.');

      return JobResult::failure(self::FAILURE_UNREADABLE, 0);
    }

    try {
      $report = $this->dispatcher->deliver($notification);
    }
    catch (\Throwable $exception) {
      // Returned, never rethrown: see the class docblock. The claim ledger being
      // unreachable is the expected case here, and it is precisely the case that must
      // be retried, because until the ledger answers this job cannot tell whether its
      // message has already gone out.
      $this->logger->error('A dispatch notification job for @event on @resource could not be attempted: @type', [
        '@event' => $notification->event->value,
        '@resource' => $notification->resourceId,
        '@type' => get_class($exception),
      ]);

      return JobResult::failure(self::FAILURE_UNAVAILABLE);
    }

    $message = $notification->event->value . ' on ' . $notification->resourceId . ': ' . $report->summary();

    if (!$report->isComplete()) {
      // Retried at the type's own delay until the ceiling, then recorded as a
      // permanent failure. Each retry re-reads the claim ledger, so the audiences that
      // already succeeded are suppressed and only the ones that failed are attempted
      // again — which is why retrying a partial failure cannot produce a duplicate.
      return JobResult::failure($message);
    }

    // A replay is a success and says so. Nothing was sent because everything had
    // already been sent, and reporting that as a failure would put a healthy queue on
    // the listing as though something were wrong with it.
    return JobResult::success($message);
  }

}
