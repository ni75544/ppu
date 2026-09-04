<?php

namespace Drupal\custom_webform_handlers\Dispatch\Notify;

use Drupal\advancedqueue\Exception\DuplicateJobException;
use Drupal\advancedqueue\Job;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Queue\QueueFactory;
use Psr\Log\LoggerInterface;

/**
 * Writes a committed fact to a durable queue and returns.
 *
 * The whole adapter is a handoff. It composes nothing, addresses nobody, opens no
 * socket and reads no submission: it puts fourteen scalars in a durable queue so that a
 * worker can do all of that later, in a request that no customer and no operator is
 * waiting on.
 *
 * Two destinations, and exactly one is chosen per event. When Advanced Queue is enabled
 * the fact becomes a job of type DispatchNotifyJob, which retries a bounded number of
 * times and leaves a failure visible on the queue listing. When it is not, the fact goes
 * to the core reliable queue that DispatchNotificationWorker drains, which is how this
 * module behaved before and is still a complete path.
 *
 * The exclusivity matters more than either destination. A single decision, taken once
 * per event, is what makes it impossible for one announcement to sit in both queues and
 * be sent twice by two different workers — there is no interval during a deployment when
 * both are written, because this method cannot reach both branches. The core queue is
 * left registered rather than removed precisely so that whatever was enqueued before the
 * switch still gets drained; retiring it is a separate, later decision.
 *
 * Both backends are database-backed and survive a restart. A memory queue would drop a
 * customer's confirmation whenever PHP exited between the submission and the next cron
 * run, and the customer would never learn that it had.
 *
 * Enqueue happens after commit, always. The callers are DispatchOperations, the
 * Webform handler's post-save, and the staff forms — every one of them above the
 * Resource Calendar Guard, and every one already past the point where the planner's
 * transaction returned. Putting the write inside that transaction would be worse than
 * slow: a queue write that rolled back with a failed capacity check would leave a
 * notification promising a Reservation nobody has.
 */
final class QueueDispatchNotifier implements DispatchNotifierInterface {

  /**
   * The core queue the legacy worker plugin drains.
   *
   * Matches the QueueWorker plugin identifier, which is how Drupal pairs the two.
   */
  const QUEUE = 'custom_webform_handlers_dispatch_notification';

  /**
   * The Advanced Queue queue notifications are enqueued to.
   *
   * Shipped as optional configuration, so it exists on any site that has Advanced
   * Queue enabled. Deliberately not the maintenance queue: a confirmation must not wait
   * behind a slow route-warm batch for its share of the cron budget.
   */
  const ADVANCED_QUEUE = 'custom_webform_handlers_dispatch';

  /**
   * The job type that announces one event.
   */
  const JOB_TYPE = 'custom_webform_handlers_dispatch_notify';

  /**
   * Notification settings.
   */
  const SETTINGS = 'custom_webform_handlers.notifications';

  public function __construct(
    private readonly QueueFactory $queueFactory,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function notify(DispatchNotification $notification): void {
    // Checked here rather than in the worker so that switching notifications off
    // stops them accruing, instead of building a backlog that floods people the
    // moment somebody switches it back on.
    if (!$this->enabled()) {
      return;
    }

    if (!$this->wanted($notification->event)) {
      return;
    }

    try {
      $this->moduleHandler->moduleExists('advancedqueue')
        ? $this->enqueueJob($notification)
        : $this->enqueueItem($notification);
    }
    catch (\Throwable $exception) {
      // Logged and swallowed on purpose. See DispatchNotifierInterface: the fact has
      // already committed, so there is nothing for the caller to undo and no useful
      // way for it to react. The identifiers are safe to log; nothing else is logged.
      $this->logger->error('A dispatch notification could not be queued for @event on @resource: @type', [
        '@event' => $notification->event->value,
        '@resource' => $notification->resourceId,
        '@type' => get_class($exception),
      ]);
    }
  }

  /**
   * Enqueues one announcement as an Advanced Queue job.
   *
   * @throws \Throwable
   *   Thrown when the queue cannot be reached or does not exist. Handled by the caller,
   *   which is the only place that knows the fact has already committed.
   */
  private function enqueueJob(DispatchNotification $notification): void {
    $queue = $this->entityTypeManager
      ->getStorage('advancedqueue_queue')
      ->load(self::ADVANCED_QUEUE);

    if ($queue === NULL) {
      // The shipped optional configuration has been deleted. Falling back rather than
      // discarding, because the core queue still has a worker and a lost confirmation
      // is a customer who never hears from us.
      $this->logger->warning('The dispatch notification queue is missing, so @event on @resource was queued for the legacy worker instead.', [
        '@event' => $notification->event->value,
        '@resource' => $notification->resourceId,
      ]);

      $this->enqueueItem($notification);

      return;
    }

    try {
      $queue->enqueueJob(Job::create(self::JOB_TYPE, $notification->toQueueItem()));
    }
    catch (DuplicateJobException) {
      // This exact announcement is already queued or being processed. Not an error and
      // not logged as one: the job type declares allow_duplicates FALSE precisely so
      // that two callers reacting to one commit produce one message. Nothing further to
      // do — the pending job will send it.
    }
  }

  /**
   * Enqueues one announcement for the legacy core queue worker.
   *
   * @throws \Throwable
   *   Thrown when the queue cannot be written to.
   */
  private function enqueueItem(DispatchNotification $notification): void {
    $this->queueFactory->get(self::QUEUE, TRUE)
      ->createItem($notification->toQueueItem());
  }

  /**
   * Tells whether notifications are switched on at all.
   *
   * Defaults to FALSE when the key is absent. An install that has never configured a
   * recipient should send nothing rather than mail the site address by accident.
   */
  private function enabled(): bool {
    return (bool) $this->configFactory
      ->get(self::SETTINGS)
      ->get('enabled');
  }

  /**
   * Tells whether this event is one the site has asked to be told about.
   *
   * Per-event, because the useful configuration is not "notifications on" but "tell
   * customers when their booking moves and tell us when something needs a human". An
   * unlisted event is not sent: adding a case to DispatchEvent must not silently start
   * mailing an existing site.
   */
  private function wanted(DispatchEvent $event): bool {
    $events = $this->configFactory
      ->get(self::SETTINGS)
      ->get('events');

    if (!is_array($events)) {
      return FALSE;
    }

    return (bool) ($events[$event->value] ?? FALSE);
  }

}
