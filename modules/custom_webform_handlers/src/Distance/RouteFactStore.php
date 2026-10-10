<?php

namespace Drupal\custom_webform_handlers\Distance;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\TempStore\TempStoreException;
use Psr\Log\LoggerInterface;

/**
 * Server-owned, session-scoped custody of one browser's route facts.
 *
 * The preview endpoint is not authoritative, but it must not be forgeable
 * either. Before this store, a "reusable" fact was whatever the browser posted
 * back — a distance blob and a route_fingerprint that is only
 * sha256(normalized addresses), a value any client can compute for any pair.
 * That let a crafted request present a fabricated google_routes fact and be
 * repriced against it, and it made "quote-only" a property of the request body
 * rather than of anything the server had actually produced.
 *
 * Here the authoritative fact lives on the server. When a route is genuinely
 * calculated, the whole DistanceResult is stored under an opaque, unguessable
 * reference and only that reference is handed to the browser. A later
 * quote-only reprice presents the reference; the server rebuilds the fact from
 * its own custody and validates it before use. What the browser sends can no
 * longer establish authenticity — at most it names a fact the server already
 * holds for this same session.
 *
 * Ownership and session scope come from PrivateTempStore itself: each entry is
 * partitioned by owner (the authenticated user, or the anonymous session), so a
 * reference minted in one session is invisible to another even if it leaks. On
 * top of that, retrieve() re-derives and checks a full identity, not just the
 * ordered-endpoint fingerprint: the routing mode the fact was produced under, the
 * purpose it was measured for, the resolved policy version at the time (which
 * folds in the profile, dataset version and endpoint that answered), and the
 * fact's own expiry. A reference that misses, a pair that does not match, a mode
 * or purpose that differs, a policy that has since moved, or a fact past its
 * expiry all return NULL — never a stale, cross-context or differently-configured
 * reuse, and never a signal to go fetch a fresh route.
 *
 * Fact retention never extends the route's own validity. When the provider
 * declared an expiry for the result, the stored fact expires at the earlier of
 * that and the configured TTL, so a cached provider answer and the fact that
 * points at it can never disagree about whether the route is still current.
 *
 * The bounded per-owner map is read, pruned and written under a per-owner lock,
 * because a set() is not a read-modify-write: two requests for the same owner
 * that each read the map, add their own fact and write it back would otherwise
 * lose one of the two facts. The lock is a distinct, fact-store-specific one; it
 * is a leaf, taken last and released immediately, and never wraps a provider call
 * or a transaction.
 */
final class RouteFactStore {

  /**
   * The PrivateTempStore collection route facts live in.
   */
  private const COLLECTION = 'custom_webform_handlers.route_facts';

  /**
   * The single per-owner key holding the bounded reference→record map.
   *
   * One key rather than one key per reference: it keeps the whole set bounded
   * and evictable in a single read/write, and it means an abandoned reference
   * cannot accumulate its own tempstore row.
   */
  private const FACTS_KEY = 'facts';

  /**
   * The most route facts one session may hold at once.
   *
   * A visitor pricing several routes in one sitting is normal; an unbounded map
   * is not. The oldest entry is evicted first, so the pair a visitor is working
   * on now is always the one retained.
   */
  private const MAX_FACTS = 12;

  /**
   * The floor and default lifetime, in seconds, of a stored fact.
   *
   * Reuses route_cache_ttl — the same window the provider's own route cache
   * treats a google_routes result as fresh for — so a stored fact and the cache
   * behind it expire together rather than one outliving the other. Bounded so a
   * misconfigured value cannot make a fact effectively permanent.
   */
  private const MIN_TTL = 60;
  private const MAX_TTL = 86400;
  private const DEFAULT_TTL = 3600;

  /**
   * The most times to retry acquiring the per-owner write lock.
   *
   * The critical section is a single read/prune/write of a small array, so the
   * lock is held for microseconds and contention is rare and brief. A handful of
   * short waits covers a realistic collision; exhausting them fails closed — the
   * store raises RouteFactStoreException and leaves the shared map untouched rather
   * than write it unprotected. An unprotected read-modify-write could drop a
   * concurrent request's fact, which is exactly the loss this lock exists to
   * prevent, so a lock that cannot be acquired is a typed busy result, never a
   * silent unlocked write.
   */
  private const LOCK_ATTEMPTS = 5;

  /**
   * Constructs the route fact store.
   */
  public function __construct(
    private readonly PrivateTempStoreFactory $tempStoreFactory,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
    private readonly LockBackendInterface $lock,
    private readonly RouteRequestOwner $owner,
  ) {}

  /**
   * Takes custody of one calculated route fact and returns its reference.
   *
   * The reference is 256 bits from the cryptographic source, so it cannot be
   * guessed and does not encode anything about the pair it names. The record
   * keeps the whole result — including the provenance metadata restore() needs —
   * plus the ordered-pair fingerprint, the mode it was produced under, and an
   * expiry derived from route_cache_ttl. Storage failure returns an empty
   * reference rather than throwing: a preview that cannot be made reusable is
   * still a usable preview, it simply cannot be repriced without a fresh route.
   *
   * @param string $origin
   *   The origin address exactly as the caller resolved it.
   * @param string $destination
   *   The destination address exactly as the caller resolved it.
   * @param string $mode
   *   The routing mode in force, a RoutingModeResolver MODE_* value.
   * @param string $purpose
   *   The routing purpose the fact was measured for, a RoutingPolicy PURPOSE_*
   *   value. Recorded and re-checked so a fact measured for one purpose cannot be
   *   repriced as another.
   * @param \Drupal\custom_webform_handlers\Distance\DistanceResult $result
   *   The fact to take custody of.
   *
   * @return string
   *   The opaque reference, or '' when the tempstore write itself failed (a
   *   preview that cannot be made reusable is still usable).
   *
   * @throws \Drupal\custom_webform_handlers\Distance\RouteFactStoreException
   *   When the per-owner lock cannot be acquired. The map is left untouched; the
   *   caller renders a typed busy result and must not treat it as a routing
   *   failure or a reason to call the provider again.
   */
  public function store(
    string $origin,
    string $destination,
    string $mode,
    string $purpose,
    DistanceResult $result,
  ): string {
    $now = $this->time->getCurrentTime();
    $reference = bin2hex(random_bytes(32));
    $record = [
      'pair' => DistanceResult::pairFingerprint($origin, $destination),
      'mode' => $mode,
      'purpose' => $purpose,
      // The resolved policy at storage time, folding profile, dataset version and
      // the answering endpoint into one string. Recorded so a later reprice under
      // a moved policy is refused rather than answered from a stale profile.
      'policy_version' => $this->policyVersion($purpose),
      'stored_at' => $now,
      // Retention never outlives the route's own validity. When the provider gave
      // the result an expiry, cap the fact at the earlier of that and the TTL.
      'expires_at' => $this->cappedExpiry($now, $result->expiresAt()),
      'distance_meters' => $result->distanceMeters(),
      'duration_seconds' => $result->durationSeconds(),
      'provider' => $result->provider(),
      'calculated_at' => $result->calculatedAt(),
      'encoded_polyline' => $result->encodedPolyline(),
      'traffic_aware' => $result->trafficAware(),
      'metadata' => $result->metadata(),
    ];

    $store = $this->tempStoreFactory->get(self::COLLECTION);
    $lockName = $this->lockName();
    // Fail closed: acquire() throws RouteFactStoreException if the lock cannot be
    // taken, before the map is read or written, so a lock failure never becomes an
    // unprotected read-modify-write that could drop a concurrent fact.
    $this->acquire($lockName);
    try {
      $facts = $this->readFacts($store);
      $facts = $this->prune($facts, $now);
      $facts[$reference] = $record;
      if (count($facts) > self::MAX_FACTS) {
        // Evict the oldest so the pair being worked on now is the one kept.
        uasort($facts, static fn (array $a, array $b): int => $a['stored_at'] <=> $b['stored_at']);
        $facts = array_slice($facts, -self::MAX_FACTS, NULL, TRUE);
      }
      $store->set(self::FACTS_KEY, $facts);
    }
    catch (TempStoreException $exception) {
      $this->logger->notice('Route fact could not be stored: @message', [
        '@message' => $exception->getMessage(),
      ]);
      return '';
    }
    finally {
      // The lock is held here unconditionally — acquire() either returned holding
      // it or threw before this try — so it is always released exactly once.
      $this->lock->release($lockName);
    }

    return $reference;
  }

  /**
   * Rebuilds a stored fact for reuse, or NULL when it cannot be trusted.
   *
   * Every check below is a refusal, not a repair: a reference that is not a
   * string, is unknown to this session, names a different ordered pair, was
   * produced under a different routing mode, or has expired all yield NULL. NULL
   * is the whole contract — the caller must treat it as "no reusable fact" and
   * must not read it as permission to fetch a new route.
   *
   * @param mixed $reference
   *   The reference the browser presented. Typed as mixed because it arrives
   *   from an untrusted JSON body and is validated here rather than by the
   *   caller.
   * @param string $origin
   *   The origin address of the pair being repriced.
   * @param string $destination
   *   The destination address of the pair being repriced.
   * @param string $mode
   *   The routing mode currently in force.
   * @param string $purpose
   *   The routing purpose the reprice is for. Must match the purpose the fact was
   *   stored under, so a fact is never repriced as a purpose it was not measured
   *   for.
   *
   * @return \Drupal\custom_webform_handlers\Distance\DistanceResult|null
   *   The rebuilt fact, or NULL when no trustworthy fact is held.
   */
  public function retrieve(
    mixed $reference,
    string $origin,
    string $destination,
    string $mode,
    string $purpose,
  ): ?DistanceResult {
    if (!is_string($reference) || $reference === '' || strlen($reference) > 128) {
      return NULL;
    }

    $store = $this->tempStoreFactory->get(self::COLLECTION);
    $facts = $this->readFacts($store);
    $record = $facts[$reference] ?? NULL;
    if (!is_array($record)) {
      return NULL;
    }

    return $this->rebuildIfValid($record, $origin, $destination, $mode, $purpose);
  }

  /**
   * Validates one stored record against the request and rebuilds it, or NULL.
   *
   * The single gate retrieve() runs its candidate through, so a fact is reused only
   * when its expiry, routing mode, purpose, resolved policy version and ordered-pair
   * fingerprint all match — never reinterpreted, never repaired. Any mismatch is a
   * refusal (NULL), which the caller must read as "no reusable fact", not as
   * permission to fetch a new route. Kept as its own method so the reference lookup
   * validates by exactly these rules and nothing re-selects a fact by pair alone.
   */
  private function rebuildIfValid(
    array $record,
    string $origin,
    string $destination,
    string $mode,
    string $purpose,
  ): ?DistanceResult {
    $now = $this->time->getCurrentTime();
    $expiresAt = (int) ($record['expires_at'] ?? 0);
    if ($expiresAt <= $now) {
      return NULL;
    }
    if (($record['mode'] ?? '') !== $mode) {
      return NULL;
    }
    // A fact measured for one purpose is not a fact for another. Ordinary is the
    // only purpose this endpoint ever stores, but the check is explicit rather
    // than assumed so a future caller cannot silently reprice across purposes.
    if (($record['purpose'] ?? '') !== $purpose) {
      return NULL;
    }
    // The policy the fact was produced under must still be the policy in force. A
    // profile, dataset version or endpoint change moves the version string, and a
    // fact from the old one is incompatible — refused, not reinterpreted. Compared
    // with hash_equals because the stored value can be attacker-influenced only
    // via a fact this session itself produced, but constant-time comparison keeps
    // the check uniform with the pair check below.
    if (!hash_equals((string) ($record['policy_version'] ?? ''), $this->policyVersion($purpose))) {
      return NULL;
    }
    if (!hash_equals(
      DistanceResult::pairFingerprint($origin, $destination),
      (string) ($record['pair'] ?? ''),
    )) {
      return NULL;
    }

    $meters = (int) ($record['distance_meters'] ?? 0);
    $seconds = (int) ($record['duration_seconds'] ?? 0);
    $calculatedAt = (int) ($record['calculated_at'] ?? 0);
    if ($meters <= 0 || $seconds < 0 || $calculatedAt <= 0) {
      return NULL;
    }

    try {
      return DistanceResult::restored(
        $meters,
        $seconds,
        (string) ($record['provider'] ?? ''),
        $calculatedAt,
        is_array($record['metadata'] ?? NULL) ? $record['metadata'] : [],
        is_string($record['encoded_polyline'] ?? NULL) ? $record['encoded_polyline'] : NULL,
        (bool) ($record['traffic_aware'] ?? FALSE),
      );
    }
    catch (\InvalidArgumentException) {
      return NULL;
    }
  }

  /**
   * Reads the bounded reference→record map, tolerating an absent or bad value.
   */
  private function readFacts(object $store): array {
    $facts = $store->get(self::FACTS_KEY);

    return is_array($facts) ? $facts : [];
  }

  /**
   * Drops expired records so the map does not carry dead weight into a write.
   */
  private function prune(array $facts, int $now): array {
    return array_filter(
      $facts,
      static fn ($record): bool => is_array($record) && (int) ($record['expires_at'] ?? 0) > $now,
    );
  }

  /**
   * Returns the fact lifetime, bounded, reusing the route cache TTL setting.
   */
  private function ttl(): int {
    $configured = (int) ($this->configFactory
      ->get('custom_webform_handlers.settings')
      ->get('route_cache_ttl') ?: self::DEFAULT_TTL);

    return max(self::MIN_TTL, min(self::MAX_TTL, $configured));
  }

  /**
   * The fact's expiry, never later than the route's own declared validity.
   *
   * The configured TTL is a ceiling on how long this store will vouch for a fact,
   * but it must not extend the route beyond what the provider said the route is
   * good for. When the result declared an expiry, the fact expires at the earlier
   * of the two; when it did not (a NULL expiresAt, as the fixed development
   * provider and any uncached live answer give), there is no upstream cap and the
   * configured TTL stands.
   */
  private function cappedExpiry(int $now, ?int $resultExpiresAt): int {
    $ttlExpiry = $now + $this->ttl();
    if ($resultExpiresAt !== NULL && $resultExpiresAt > $now) {
      return min($ttlExpiry, $resultExpiresAt);
    }

    return $ttlExpiry;
  }

  /**
   * The resolved policy version for a purpose, or '' when there is no policy.
   *
   * RoutingPolicy::fromConfig() raises NOT_CONFIGURED when no provider is eligible
   * for the purpose — which is exactly the state a development-fixed deployment is
   * in, its live chains disabled. That is not an error here: it means "there is no
   * live policy to bind to", and the empty string is a deterministic stand-in that
   * store() and retrieve() both compute the same way, so a fact stored and read
   * back under the same non-policy mode still matches. The routing mode check is
   * what guards reuse in that state; policy_version guards it under a live policy.
   */
  private function policyVersion(string $purpose): string {
    try {
      return RoutingPolicy::fromConfig(
        $this->configFactory->get('custom_webform_handlers.settings'),
        $purpose,
      )->version();
    }
    catch (DistanceProviderException) {
      return '';
    }
  }

  /**
   * The per-owner name of the fact-map write lock.
   *
   * Owner-scoped, so two different owners writing their own maps never contend,
   * and fact-store-specific, so it can never be confused with the controller's
   * single-flight lock or any reservation lock.
   */
  private function lockName(): string {
    return 'custom_webform_handlers.route_facts:' . hash('sha256', $this->owner->token());
  }

  /**
   * Acquires the write lock, waiting briefly, then fails closed.
   *
   * Returns holding the lock, or throws RouteFactStoreException when it cannot be
   * taken within LOCK_ATTEMPTS. It never returns without the lock: an unprotected
   * read-modify-write of the shared map could drop a concurrent request's fact,
   * which is the exact loss this lock exists to prevent, so a lock that cannot be
   * acquired is surfaced as a typed busy result rather than silently proceeding
   * unlocked. The give-up is logged so the backend fault is visible.
   *
   * @throws \Drupal\custom_webform_handlers\Distance\RouteFactStoreException
   *   When the lock cannot be acquired; the caller renders a busy response and
   *   the map is left untouched.
   */
  private function acquire(string $name): void {
    for ($attempt = 0; $attempt < self::LOCK_ATTEMPTS; $attempt++) {
      if ($this->lock->acquire($name)) {
        return;
      }
      $this->lock->wait($name, 1);
    }
    // Fail closed. Dropping a concurrent request's fact is the exact loss this lock
    // prevents, so a lock that cannot be acquired is a typed busy result, not an
    // unprotected write. The map is untouched; the caller renders a busy response.
    $this->logger->warning('Route fact map write refused: lock @name unavailable after @n attempts.', [
      '@name' => $name,
      '@n' => self::LOCK_ATTEMPTS,
    ]);
    throw new RouteFactStoreException('Route fact store lock unavailable.');
  }

}
