<?php

namespace Drupal\custom_webform_handlers\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\custom_webform_handlers\Dispatch\Notify\DispatchNotification;
use Drupal\custom_webform_handlers\Dispatch\Notify\DispatchNotificationDispatcher;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drains the dispatch notification queue on cron.
 *
 * The plugin identifier is the queue name QueueDispatchNotifier writes to; that pairing
 * is the whole of the wiring between the two halves, and neither knows anything else
 * about the other.
 *
 * This worker never throws, and the restraint is deliberate. Drupal reads an escaping
 * exception as "try this item again": a permanently unroutable address would then be
 * retried on every cron run for as long as the site exists, and an item whose customer
 * copy had already been accepted would send that copy again on each attempt. So a
 * failure is recorded in the notification history — where the board shows it and an
 * operator can act on it — and the item is finished with. Mail that needs a human is a
 * job for a person reading the board, not for a loop.
 *
 * A thirty-second cron budget, matching core's own translation worker: long enough to
 * clear an ordinary backlog, short enough that a slow mail server cannot eat the whole
 * cron run and starve everything queued behind it.
 */
#[QueueWorker(
  id: 'custom_webform_handlers_dispatch_notification',
  title: new TranslatableMarkup('Dispatch notifications'),
  cron: ['time' => 30],
)]
final class DispatchNotificationWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

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
  public function processItem($data) {
    if (!is_array($data)) {
      // Not something this module wrote. Discarded rather than retried: whatever put it
      // here will not have improved by the next cron run.
      $this->logger->warning('A dispatch notification queue item was not an array and was discarded.');

      return;
    }

    $notification = DispatchNotification::fromQueueItem($data);
    if ($notification === NULL) {
      // An unreadable payload — an event name this release does not know, or an item
      // naming no Resource. Logged by shape only; the payload itself is not written to a
      // log, because a log is read by a wider audience than the queue table is.
      $this->logger->warning('A dispatch notification queue item could not be read and was discarded.');

      return;
    }

    // Composing, sending and recording all happen inside here. The one thing that can
    // escape is an unreachable claim ledger, and that has to be caught rather than
    // allowed to propagate: core's queue runner would read the exception as "retry this
    // item", and this worker's whole design — see the class docblock — rests on never
    // giving it that instruction. Advanced Queue is where a notification is retried
    // deliberately and a bounded number of times; this path only drains what is left.
    try {
      $this->dispatcher->deliver($notification);
    }
    catch (\Throwable $exception) {
      // The class only. The payload named a submission and a Reservation, and a log line
      // reaches further than the queue table did.
      $this->logger->error('A dispatch notification could not be attempted for @event on @resource: @type', [
        '@event' => $notification->event->value,
        '@resource' => $notification->resourceId,
        '@type' => get_class($exception),
      ]);
    }
  }

}
