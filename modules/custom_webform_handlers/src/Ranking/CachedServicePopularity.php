<?php

namespace Drupal\custom_webform_handlers\Ranking;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Psr\Log\LoggerInterface;

/**
 * Serves the display order from cache, from the stored snapshot, or from the input.
 *
 * Three sources tried in that order, and the third cannot fail. Redis holds the answer
 * for an hour because the question is asked on every render of a public page and the
 * answer changes once a night; the stored snapshot is consulted when the cache has
 * nothing; and when there is no usable snapshot the configured order the caller passed
 * in is returned unchanged. Every failure mode lands on that last branch, which is why
 * this class never throws and never returns a short list.
 *
 * On the Redis boundary, precisely. This site keeps Redis in its bootstrap container
 * definition, so a Redis daemon that is completely absent is a boot failure and not
 * something any application code gets a chance to handle — that is a deployment fact
 * about this environment, not a property of this class. What this class does handle is
 * every failure that can occur once a request is running: a connection reset mid-render,
 * an eviction between the set and the get, a value written by an older release. Each of
 * those degrades to the stored snapshot or to the configured order, and none of them
 * reaches the page as an error.
 *
 * A cache hit is still checked. The key already contains a hash of the exact service
 * list, so a mismatched entry should be impossible; the membership check is here anyway
 * because "never hide a service" is a promise this feature makes to the form, and a
 * promise that depends on a cache being uncorrupted is weaker than one that does not.
 */
final class CachedServicePopularity implements ServicePopularityInterface {

  /**
   * The ranking settings.
   */
  const SETTINGS = 'custom_webform_handlers.ranking';

  /**
   * The cache key namespace, versioned in the key itself.
   *
   * The version moves whenever the meaning of a cached value changes — a different
   * ordering rule, a different pin, a different payload shape. Entries written by an
   * older release are then unreachable rather than reinterpreted, and they expire on
   * their own TTL without anyone having to flush a bin during a deployment. This mirrors
   * the route cache's custom_webform_handlers:route:v3: prefix for the same reason.
   */
  const CACHE_PREFIX = 'custom_webform_handlers:ranking:v1:order:';

  /**
   * The default cache lifetime in seconds.
   */
  const DEFAULT_TTL = 3600;

  /**
   * The default minimum number of bookings before any reordering happens.
   *
   * Twenty-five. Below that a single booking is worth more than four per cent of the
   * total and can move a rank on its own, which is movement a visitor would perceive as
   * noise rather than as popularity.
   */
  const DEFAULT_MINIMUM_SAMPLES = 25;

  public function __construct(
    private readonly CacheBackendInterface $cache,
    private readonly StateServicePopularityStore $store,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function order(array $serviceIds): array {
    $ids = [];
    foreach ($serviceIds as $id) {
      if (is_string($id) && $id !== '' && !in_array($id, $ids, TRUE)) {
        $ids[] = $id;
      }
    }

    // Nothing to order, and nothing that could go wrong later either. Returned before
    // any setting is read or any backend is touched, so a one-service form costs the
    // same as no feature at all.
    if (count($ids) < 2) {
      return $ids;
    }

    $settings = $this->configFactory->get(self::SETTINGS);

    // Switched off means the configured order, immediately. The switch exists so that an
    // operator who dislikes what popularity is doing to their page can stop it without a
    // deployment, and it has to be the first thing checked or it would not be a switch.
    if ($settings->get('enabled') === FALSE) {
      return $ids;
    }

    $cid = self::CACHE_PREFIX . hash('sha256', implode("\0", $ids));

    $cached = $this->cached($cid, $ids);
    if ($cached !== NULL) {
      return $cached;
    }

    $snapshot = $this->snapshot();

    // No history, not enough history, or an unreadable snapshot. All three are ordinary
    // and all three have the same answer, which is the order the form already has.
    if ($snapshot === NULL || !$snapshot->isUsable($this->minimumSamples($settings))) {
      return $ids;
    }

    $ordered = $snapshot->order($ids);
    $this->remember($cid, $ordered, $settings);

    return $ordered;
  }

  /**
   * Returns a cached ordering of exactly these services, or NULL.
   *
   * NULL covers a miss, an unusable value, and an unreachable cache without
   * distinguishing them, because the caller's next step is the same for all three.
   */
  private function cached(string $cid, array $ids): ?array {
    try {
      $item = $this->cache->get($cid);
    }
    catch (\Throwable $exception) {
      // Logged once at warning, not error: the page is about to be correct anyway, by a
      // slower route. Only the class, because the message may carry connection detail.
      $this->logger->warning('The service popularity cache could not be read, so the configured order was used: @type', [
        '@type' => get_class($exception),
      ]);

      return NULL;
    }

    if ($item === FALSE || !is_array($item->data)) {
      return NULL;
    }

    // Same members, same count, or it is not an ordering of this list.
    if (count($item->data) !== count($ids) || array_diff($ids, $item->data) !== []) {
      return NULL;
    }

    return array_values($item->data);
  }

  /**
   * Stores an ordering, and shrugs if it cannot.
   *
   * A failure to cache costs one grouped read of a State value on the next render. It is
   * not worth a log line at error, and it is certainly not worth failing a page over.
   */
  private function remember(string $cid, array $ordered, ImmutableConfig $settings): void {
    try {
      $this->cache->set(
        $cid,
        $ordered,
        $this->time->getRequestTime() + $this->ttl($settings),
        [StateServicePopularityStore::CACHE_TAG],
      );
    }
    catch (\Throwable) {
      // Deliberately silent. See above.
    }
  }

  /**
   * Reads the stored snapshot, treating any failure as no snapshot.
   */
  private function snapshot(): ?ServicePopularitySnapshot {
    try {
      return $this->store->read();
    }
    catch (\Throwable $exception) {
      $this->logger->warning('The service popularity snapshot could not be read, so the configured order was used: @type', [
        '@type' => get_class($exception),
      ]);

      return NULL;
    }
  }

  /**
   * The cache lifetime, bounded at both ends.
   *
   * Never unlimited. An entry that outlives the nightly job it was derived from would
   * pin the page to a window that has closed, and the tag invalidation the store
   * performs is a courtesy rather than a guarantee — a cache backend may be flushed,
   * replaced, or shared, and the TTL is what makes staleness finite without it.
   */
  private function ttl(ImmutableConfig $settings): int {
    return max(60, min(86400, (int) ($settings->get('cache_ttl') ?: self::DEFAULT_TTL)));
  }

  /**
   * The minimum number of bookings before any reordering happens.
   */
  private function minimumSamples(ImmutableConfig $settings): int {
    return max(1, (int) ($settings->get('minimum_samples') ?: self::DEFAULT_MINIMUM_SAMPLES));
  }

}
