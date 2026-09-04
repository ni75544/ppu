<?php

namespace Drupal\custom_webform_handlers\Plugin\AdvancedQueue\JobType;

use Drupal\advancedqueue\Attribute\AdvancedQueueJobType;
use Drupal\advancedqueue\Job;
use Drupal\advancedqueue\JobResult;
use Drupal\advancedqueue\Plugin\AdvancedQueue\JobType\JobTypeBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\custom_webform_handlers\Ranking\ServicePopularityAggregator;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Recounts, once a night, how often each Service Type was actually bought.
 *
 * The payload is a version and nothing else. There is one snapshot for the site and
 * the job's whole input is "recount it", so a payload with a Resource, a date range or
 * a service list in it would only create ways for two nightly runs to disagree. The
 * window comes from configuration, where an operator can see it, and the counts come
 * from the database.
 *
 * Replay cannot double anything, structurally rather than by convention. The aggregator
 * issues one grouped query and hands the result to a store that replaces the stored
 * snapshot; there is no counter to increment and no row to append, so running this job
 * five times in a minute leaves precisely what running it once leaves. That is the
 * property requirement 3 asks for, and it is a consequence of the storage shape rather
 * than of anything this class does.
 *
 * Failure is retried because the only thing that can fail is the database. The
 * aggregator deliberately lets a database error propagate instead of returning empty
 * counts, and this job type is the reason that is safe: a propagated error becomes a
 * returned failure and a bounded retry, whereas empty counts would have been written
 * over good history as though they were a real result and would have read as an
 * ordinary night with no bookings.
 *
 * Not an authority, and nothing downstream of it is either. The snapshot decides the
 * order of cards on a page. It cannot hide a service, change what a visitor may book,
 * alter a price, or touch a Reservation — and when it is missing, stale, or below the
 * sample threshold, the form's own configured order is used, which is the order the
 * page had before this feature existed.
 */
#[AdvancedQueueJobType(
  id: 'custom_webform_handlers_dispatch_popularity',
  label: new TranslatableMarkup('Service popularity recount'),
  max_retries: 1,
  retry_delay: 1800,
  allow_duplicates: FALSE,
)]
final class DispatchPopularityJob extends JobTypeBase implements ContainerFactoryPluginInterface {

  /**
   * The payload version this job type understands.
   */
  const PAYLOAD_VERSION = 1;

  /**
   * Reported when the payload was written by a release this one does not understand.
   */
  const FAILURE_UNREADABLE = 'payload_unreadable';

  /**
   * Reported when the counts could not be read or stored.
   */
  const FAILURE_UNAVAILABLE = 'recount_unavailable';

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly ServicePopularityAggregator $aggregator,
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
      $container->get('custom_webform_handlers.service_popularity_aggregator'),
      $container->get('logger.channel.custom_webform_handlers'),
    );
  }

  /**
   * Builds the payload.
   *
   * A version alone, and a static so that the one enqueue site and any future one
   * cannot drift. Combined with allow_duplicates FALSE this also means a second nightly
   * enqueue while the first is still pending is collapsed by the fingerprint rather
   * than queued: identical payload, identical type, identical queue.
   *
   * @return array{v: int}
   */
  public static function payload(): array {
    return ['v' => self::PAYLOAD_VERSION];
  }

  /**
   * {@inheritdoc}
   */
  public function process(Job $job): JobResult {
    $payload = $job->getPayload();

    if (($payload['v'] ?? NULL) !== self::PAYLOAD_VERSION) {
      $this->logger->warning('A service popularity job could not be read and was failed permanently.');

      return JobResult::failure(self::FAILURE_UNREADABLE, 0);
    }

    try {
      $snapshot = $this->aggregator->recompute();
    }
    catch (\Throwable $exception) {
      // Returned, never rethrown: a thrown exception is a permanent failure with no
      // retry in Advanced Queue, and the condition being reported here — the database
      // being briefly unreachable — is the textbook case for retrying. The class only,
      // because a database exception message can carry the query and its values.
      $this->logger->error('A service popularity recount could not be completed: @type', [
        '@type' => get_class($exception),
      ]);

      return JobResult::failure(self::FAILURE_UNAVAILABLE);
    }

    // Counts, never services. The message is stored on the job row and read by anyone
    // with queue access, and while a service machine name is hardly a secret, the
    // number of bookings each one has is commercial information that does not need to
    // be in an operations listing to make this job's outcome legible.
    $message = sprintf(
      '%d bookings across %d services over %d days',
      $snapshot->totalBookings(),
      count($snapshot->counts),
      $snapshot->windowDays,
    );

    // Zero bookings is a success. It is the correct and expected result on a site whose
    // paid dispatch history has not started yet, and the snapshot is written rather
    // than skipped so that "the job ran and found nothing" stays distinguishable from
    // "the job has never run" — which are the two states an operator wondering why the
    // cards have not moved needs to tell apart.
    return JobResult::success($message);
  }

}
