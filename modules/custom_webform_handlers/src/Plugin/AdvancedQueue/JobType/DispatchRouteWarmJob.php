<?php

namespace Drupal\custom_webform_handlers\Plugin\AdvancedQueue\JobType;

use Drupal\advancedqueue\Attribute\AdvancedQueueJobType;
use Drupal\advancedqueue\Job;
use Drupal\advancedqueue\JobResult;
use Drupal\advancedqueue\Plugin\AdvancedQueue\JobType\JobTypeBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\custom_webform_handlers\Dispatch\Route\ScheduledRouteWarmer;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Warms the route cache for one Resource's day, so a later read does not pay for it.
 *
 * The payload is two scalars and a version: which Resource, which local date. That is
 * not brevity for its own sake — it is what keeps the addresses out. The journeys this
 * job measures are other customers' pickup and delivery locations, and if the payload
 * named them they would sit in the queue table, in the job's failure history, and in
 * any operator's view of either, for as long as the row survived. Instead the worker
 * reads them from the claims themselves at the moment it needs them, uses them to form
 * provider requests, and discards them. See ScheduledRouteWarmer.
 *
 * Replay is free. Warming a day that is already warm re-reads the same claims, asks
 * the provider about the same pairs, and writes the same cache entries: no aggregate
 * is incremented, no row is appended, nothing is counted twice. That is why this job
 * type needs no idempotency key of its own, unlike the notification type, which had to
 * prove a message had not already gone out.
 *
 * Retry is reserved for the provider being unreachable. A day where some legs measured
 * and some did not is almost always a day containing an address the provider cannot
 * geocode, and the next attempt would fail on it identically; retrying that would
 * spend three passes to reach the same partial answer. A day where every leg failed
 * and there were legs to try looks like an outage, and an outage is what a bounded
 * retry exists for. A payload that names no Resource, or a date that is not a date, is
 * permanent on the first attempt: it will name the same thing in fifteen minutes.
 *
 * Not an authority over anything, and this is the job type where that matters most,
 * because it is the one that talks to a paid external API. It takes no lock, writes no
 * planner table, and cannot create, move, price or release a Slot. Every leg failing
 * leaves the day exactly as bookable as it was — the planner will simply ask the
 * provider itself, synchronously, as it did before this job type existed.
 */
#[AdvancedQueueJobType(
  id: 'custom_webform_handlers_dispatch_route_warm',
  label: new TranslatableMarkup('Dispatch route warming'),
  max_retries: 1,
  retry_delay: 900,
  allow_duplicates: FALSE,
)]
final class DispatchRouteWarmJob extends JobTypeBase implements ContainerFactoryPluginInterface {

  /**
   * The payload version this job type understands.
   *
   * Read before anything else and refused when it does not match. A release that
   * changes what the payload means increments this, and jobs enqueued by the previous
   * release then fail visibly on the queue listing instead of being reinterpreted
   * under the new meaning.
   */
  const PAYLOAD_VERSION = 1;

  /**
   * Reported when the payload cannot be turned into a day to warm.
   */
  const FAILURE_UNREADABLE = 'payload_unreadable';

  /**
   * Reported when no leg of the day could be measured at all.
   */
  const FAILURE_UNREACHABLE = 'provider_unreachable';

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly ScheduledRouteWarmer $warmer,
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
      $container->get('custom_webform_handlers.dispatch_route_warmer'),
      $container->get('logger.channel.custom_webform_handlers'),
    );
  }

  /**
   * Builds the payload for one Resource and one local date.
   *
   * Offered as a static so that every enqueue site produces the same shape and the
   * version is set in one place. A payload assembled by hand elsewhere would be the
   * obvious way for an address to end up in the queue table.
   *
   * @return array{v: int, resource: string, date: string}
   *   Bounded, scalar, serializable, and containing nothing a queue table should not
   *   hold: no Webform answer, no address, no submission, no token, no entity.
   */
  public static function payload(string $resourceId, string $date): array {
    return [
      'v' => self::PAYLOAD_VERSION,
      'resource' => $resourceId,
      'date' => $date,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function process(Job $job): JobResult {
    $payload = $job->getPayload();

    $version = $payload['v'] ?? NULL;
    $resourceId = $payload['resource'] ?? NULL;
    $date = $payload['date'] ?? NULL;

    if ($version !== self::PAYLOAD_VERSION || !is_string($resourceId) || $resourceId === '' || !is_string($date)) {
      $this->logger->warning('A route warming job could not be read and was failed permanently.');

      return JobResult::failure(self::FAILURE_UNREADABLE, 0);
    }

    try {
      $report = $this->warmer->warm($resourceId, $date);
    }
    catch (\Throwable $exception) {
      // Returned rather than thrown. Advanced Queue reads a thrown exception as an
      // immediate permanent failure with no retry, which would turn a database that is
      // briefly unreachable — the likeliest cause of an exception escaping the warmer,
      // since it reads claims before it routes anything — into a warming pass silently
      // never made. The class only: the message may carry an address.
      $this->logger->error('A route warming job for @resource on @date could not be attempted: @type', [
        '@resource' => $resourceId,
        '@date' => $date,
        '@type' => get_class($exception),
      ]);

      return JobResult::failure(self::FAILURE_UNREACHABLE);
    }

    if ($report === NULL) {
      // The Resource does not exist, has no usable profile, or the date is not a date.
      // Permanent on the first attempt, and visible on the listing rather than deleted.
      return JobResult::failure(self::FAILURE_UNREADABLE, 0);
    }

    $message = $resourceId . ' ' . $date . ': ' . $report->summary();

    if ($report->isTotalFailure()) {
      // Retried once at the type's own delay, then recorded as a permanent failure. A
      // retry re-asks the provider about the same pairs; anything that succeeded in the
      // meantime is served from its own cache entry, so retrying cannot cost twice.
      return JobResult::failure($message);
    }

    // A day with nothing scheduled is a success that says so. Reporting an empty day as
    // a failure would fill the listing with the ordinary state of every Thursday on a
    // profile that works Monday to Wednesday.
    return JobResult::success($message);
  }

}
