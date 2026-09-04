<?php

namespace Drupal\custom_webform_handlers\Maintenance;

use Drupal\advancedqueue\Exception\DuplicateJobException;
use Drupal\advancedqueue\Job;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\custom_webform_handlers\Plugin\AdvancedQueue\JobType\DispatchPopularityJob;
use Drupal\custom_webform_handlers\Plugin\AdvancedQueue\JobType\DispatchRouteWarmJob;
use Psr\Log\LoggerInterface;

/**
 * Hands maintenance work to Advanced Queue's SQL-backed durable queue, or drops it.
 *
 * A handoff and nothing else, in the same shape as QueueDispatchNotifier: it builds
 * one bounded scalar payload from a job type's own static and enqueues it. It reads
 * no submission, takes no lock, and writes no planner table — the seam requirement 4
 * lets an ECA action reach, and the seam the cron sweep reaches too, kept identical
 * so both callers get the same guarantees.
 *
 * Silent on every failure, because both callers have already committed the fact that
 * prompted the enqueue. When Advanced Queue is not installed there is nowhere durable
 * to put the work and the request must not fail for it, so the call is a logged
 * no-op: the route cache stays cold and the synchronous planner warms it on demand,
 * exactly as it did before this module had a queue. A missing queue entity is the
 * same story — the shipped optional configuration was deleted — and is warned rather
 * than thrown. A DuplicateJobException is not an error at all: both job types declare
 * allow_duplicates FALSE precisely so that a nightly enqueue racing an ECA reaction
 * collapses to one pending job instead of two, and the recompute and the warm are
 * both idempotent, so a collapsed duplicate loses nothing.
 */
final class AdvancedQueueMaintenanceQueue implements MaintenanceQueueInterface {

  /**
   * The Advanced Queue queue maintenance work is enqueued to.
   *
   * Its own queue, separate from the notification queue, so a slow route-warm batch
   * cannot spend the cron budget a customer's confirmation is waiting on. Shipped as
   * optional configuration and installed by update 10024.
   */
  const QUEUE_ID = 'custom_webform_handlers_maintenance';

  /**
   * The job type that warms one Resource's day.
   *
   * Matches the AdvancedQueueJobType id on DispatchRouteWarmJob; named here because
   * Job::create() takes the id as a string.
   */
  const JOB_ROUTE_WARM = 'custom_webform_handlers_dispatch_route_warm';

  /**
   * The job type that recomputes the popularity snapshot.
   *
   * Matches the AdvancedQueueJobType id on DispatchPopularityJob.
   */
  const JOB_POPULARITY = 'custom_webform_handlers_dispatch_popularity';

  public function __construct(
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function enqueueRouteWarm(string $resourceId, string $date): void {
    if ($resourceId === '' || $date === '') {
      return;
    }

    $this->enqueue(
      self::JOB_ROUTE_WARM,
      DispatchRouteWarmJob::payload($resourceId, $date),
      ['@resource' => $resourceId, '@date' => $date],
    );
  }

  /**
   * {@inheritdoc}
   */
  public function enqueuePopularityRecount(): void {
    $this->enqueue(
      self::JOB_POPULARITY,
      DispatchPopularityJob::payload(),
      [],
    );
  }

  /**
   * Enqueues one job of a given type, and contains every way that can fail.
   *
   * @param string $jobTypeId
   *   The Advanced Queue job type to create.
   * @param array $payload
   *   The bounded scalar payload, built by the job type's own static.
   * @param array $context
   *   Identifier placeholders for the failure log; never any payload contents
   *   beyond identifiers already safe to record.
   */
  private function enqueue(string $jobTypeId, array $payload, array $context): void {
    // Gated, not assumed. Advanced Queue is an optional dependency; without it there
    // is no durable backend to accept the work, and the caller has already committed,
    // so the only safe thing is to do nothing and say so once.
    if (!$this->moduleHandler->moduleExists('advancedqueue')) {
      return;
    }

    try {
      $queue = $this->entityTypeManager
        ->getStorage('advancedqueue_queue')
        ->load(self::QUEUE_ID);

      if ($queue === NULL) {
        // The shipped optional configuration has been deleted. Warned, not thrown:
        // the work is maintenance, its absence degrades to the synchronous path, and
        // failing the committed caller for it would be worse than skipping it.
        $this->logger->warning('The maintenance queue is missing, so a @type job was not enqueued.', [
          '@type' => $jobTypeId,
        ]);

        return;
      }

      $queue->enqueueJob(Job::create($jobTypeId, $payload));
    }
    catch (DuplicateJobException) {
      // The same work is already queued or in flight. Not an error: allow_duplicates
      // is FALSE by design, and both job types are idempotent, so the pending job
      // does the work once and this call correctly adds nothing.
    }
    catch (\Throwable $exception) {
      // Anything else — storage unreachable, a malformed queue entity — is logged by
      // class and swallowed. The caller committed before it enqueued; there is
      // nothing to undo and no authority lost by a maintenance job not running.
      $this->logger->error('A @type maintenance job could not be enqueued: @class', [
        '@type' => $jobTypeId,
        '@class' => get_class($exception),
      ] + $context);
    }
  }

}
