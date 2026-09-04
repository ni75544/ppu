<?php

namespace Drupal\custom_webform_handlers\Ranking;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\State\StateInterface;

/**
 * The durable home of the latest popularity snapshot.
 *
 * State and not configuration. This is derived data: it is recomputed from bookings on
 * a schedule, it differs on every environment, and it means nothing on a fresh install.
 * Putting it in configuration would make it exportable, which invites a snapshot
 * computed from one site's orders being deployed onto another's, and would show a
 * derived number in a configuration diff on every nightly run.
 *
 * State and not Redis, which is the distinction requirement 8 turns on. Redis holds the
 * read cache in front of this and may be flushed, evicted, or restarted at any moment;
 * losing that costs one recomputation of an order from numbers still on disk. If the
 * snapshot itself lived only in Redis, the same flush would erase the history behind it
 * and the homepage would fall back to configured ordering until the next nightly job —
 * up to a day of degraded output caused by a cache eviction. State is SQL-backed, so a
 * Redis outage cannot reach it.
 *
 * Writing invalidates the read cache rather than waiting for its TTL to lapse. The
 * nightly job is the only writer and it runs once, so the invalidation is cheap and it
 * means a freshly computed order is visible immediately instead of at some unpredictable
 * point over the next hour.
 */
final class StateServicePopularityStore {

  /**
   * The State key the snapshot is stored under.
   */
  const KEY = 'custom_webform_handlers.service_popularity';

  /**
   * The cache tag every cached ordering carries.
   *
   * One tag for the whole feature. The cached entries are orderings of one form's
   * option list; there is no useful partial invalidation, because a new snapshot
   * potentially changes all of them.
   */
  const CACHE_TAG = 'custom_webform_handlers:service_popularity';

  public function __construct(
    private readonly StateInterface $state,
    private readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
  ) {}

  /**
   * Returns the stored snapshot, or NULL when there is none this release can read.
   *
   * NULL is the ordinary state of a site that has not run the nightly job yet, or has
   * run it and found no bookings, or is reading a snapshot from an older format. All
   * three mean the same thing to the caller — order by the configured list — so they
   * are deliberately not distinguished here.
   */
  public function read(): ?ServicePopularitySnapshot {
    return ServicePopularitySnapshot::fromStorage($this->state->get(self::KEY));
  }

  /**
   * Replaces the stored snapshot and drops every cached ordering derived from it.
   *
   * Replaces rather than merges. A snapshot describes one closed window; merging an old
   * window's counts into a new one's would produce a total that matches no window at
   * all, and the minimum-sample threshold would then be measured against a number that
   * overstates the evidence.
   */
  public function write(ServicePopularitySnapshot $snapshot): void {
    $this->state->set(self::KEY, $snapshot->toStorage());

    $this->cacheTagsInvalidator->invalidateTags([self::CACHE_TAG]);
  }

}
