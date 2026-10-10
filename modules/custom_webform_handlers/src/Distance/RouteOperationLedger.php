<?php

namespace Drupal\custom_webform_handlers\Distance;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Records what a route operation did, so a repeat of it does not do it again.
 *
 * A route_preview is the one preview operation that may reach a billed provider,
 * and two things can make it happen more than once for a single real intent: the
 * response is lost and the browser retries, or two requests for the same journey
 * arrive at once. Browser-side deduplication narrows both but cannot close them —
 * a retry after a dropped connection has no in-flight marker to collide with, and
 * two tabs share no state. This ledger is the server-side close.
 *
 * It is deliberately not the route fact store. The fact store keeps a small,
 * bounded, per-owner map of reusable facts and evicts the oldest when it fills;
 * if replay protection lived there, pricing a thirteenth journey would silently
 * re-authorize the first. So the ledger is its own store with its own expiry, and
 * evicting a fact can never erase the record that an operation completed.
 *
 * Storage is keyvalue.expirable — a database-backed store with native per-entry
 * expiry (no migration, no new schema, no infrastructure). It is a single shared
 * table, so every key carries its owner and every read re-checks it: an operation
 * is invisible to any owner but the one that created it, which is what keeps one
 * anonymous session from replaying another's, or an anonymous request from
 * replaying an authenticated user's.
 *
 * Route freshness and replay protection are deliberately separated, because they
 * have different lifetimes. A completed operation is stored as two entries: a
 * payload-free marker that lives for the whole operation-retention window
 * (MAX_RECORD_TTL, route-independent), and a payload that carries the reusable
 * fact and lives only as long as the route is valid (capped at the provider's own
 * expiry). When the route expires, the payload entry expires natively with it —
 * the fact is not retained beyond its permitted validity — while the marker lives
 * on, so a repeat of the operation id is still recognised and answered with
 * refresh_required rather than a second provider call. This is the fix for a real
 * weakness: capping the whole record at route validity meant an expired route also
 * erased the record that stops another calculation.
 *
 * Five outcomes are distinguished, because collapsing them re-bills:
 *
 * - completed — the operation finished and its route is still valid; the payload
 *   is present. A replay returns that result with no provider call.
 * - expired — the operation finished but its route validity has passed (payload
 *   retired), or the provider returned an already-stale result. Recognised for the
 *   retention window and answered with refresh_required: replay protection with no
 *   provider call, and never a stale fact priced as fresh.
 * - failed — the operation reached the provider and the provider refused. A
 *   replay returns the classified refusal, again with no provider call: a failed
 *   billed attempt is not a licence to make another.
 * - pending — a worker claimed the operation and has not yet recorded an outcome.
 *   A concurrent request waits on the single-flight lock rather than starting a
 *   second calculation.
 * - unknown — a pending record whose worker is gone (the lock is free but no
 *   outcome was written). This is refused, not retried: the previous attempt may
 *   have been billed, and only a fresh, explicit operation id may try again.
 *
 * The ledger stores no lock and acquires none; the single-flight lock is the
 * controller's, held around the provider call and released before the response.
 * Nothing here runs inside a database transaction or a reservation lock.
 */
final class RouteOperationLedger {

  /**
   * The keyvalue.expirable collection route operations live in.
   */
  private const COLLECTION = 'custom_webform_handlers.route_operations';

  /**
   * A claimed-but-unfinished operation.
   */
  public const STATE_PENDING = 'pending';

  /**
   * An operation that finished and whose result is recorded for replay.
   */
  public const STATE_COMPLETED = 'completed';

  /**
   * An operation that reached the provider and got a classified refusal.
   */
  public const STATE_FAILED = 'failed';

  /**
   * An operation whose route is no longer replayable, its payload retired.
   *
   * Two paths reach this state: a completed operation whose route validity has
   * since passed (its payload expired while the retention marker lives on), and a
   * provider result that was already stale when it arrived (recorded terminal,
   * payload never stored). Both are recognised for the full retention window and
   * both resolve to refresh_required — replay protection without a second provider
   * call — and neither is ever priced or republished as fresh. Only a new,
   * separately authorized operation id may compute the journey again.
   */
  public const STATE_EXPIRED = 'expired';

  /**
   * The lease a pending claim holds before it is treated as abandoned.
   *
   * Long enough to cover lock acquisition plus the provider deadline with room to
   * spare, so a live-but-slow worker is never mistaken for a dead one; short
   * enough that a genuinely abandoned operation stops blocking its own id within a
   * couple of minutes. Within the lease a repeat of an abandoned id is refused as
   * unknown; past it the id is forgotten and a repeat is a fresh operation — a
   * bounded ceiling, not an indefinite block.
   */
  private const PENDING_LEASE = 120;

  /**
   * The longest a completed or failed record is kept, absent a tighter cap.
   *
   * A completed record may be capped shorter by the route's own expiry (a replay
   * must never outlive the validity of the fact it replays); this is the ceiling
   * when the route declares none. A failed record is kept only briefly — long
   * enough to absorb the retries of one lost response, not to remember a refusal
   * forever.
   */
  private const MAX_RECORD_TTL = 3600;
  private const FAILED_RECORD_TTL = 120;

  /**
   * Bounds on a caller-supplied operation id.
   *
   * An opaque client token (a UUID in practice). Bounded so a malformed or
   * oversized value is refused as a key rather than stored.
   */
  private const MIN_ID_LENGTH = 8;
  private const MAX_ID_LENGTH = 128;

  /**
   * Constructs the route operation ledger.
   */
  public function __construct(
    private readonly KeyValueExpirableFactoryInterface $keyValueExpirableFactory,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Whether a value is acceptable as an operation id.
   *
   * The controller checks this before doing anything else, so a request that
   * omits the id or sends a malformed one is refused before a lock is taken or a
   * provider is reached.
   */
  public function isValidId(mixed $operationId): bool {
    return is_string($operationId)
      && strlen($operationId) >= self::MIN_ID_LENGTH
      && strlen($operationId) <= self::MAX_ID_LENGTH
      && preg_match('/^[A-Za-z0-9._-]+$/', $operationId) === 1;
  }

  /**
   * Reads the recorded outcome of an operation for this owner and identity.
   *
   * This is the fast path and the replay path both: it is called before the
   * single-flight lock (to answer a repeat without waiting) and again inside it
   * (to answer a repeat that arrived while the first was computing). It never
   * writes and never calls a provider.
   *
   * The returned outcome is one of:
   * - ['state' => completed, 'snapshot' => [...]] — replay this result.
   * - ['state' => failed, 'code' => '<classified>'] — replay this refusal.
   * - ['state' => pending] — a claim is live; wait on the lock.
   * - ['state' => unknown] — a pending record is abandoned; refuse.
   * - ['state' => conflict] — this id exists but names a different identity.
   * - NULL — no record; the caller may claim and compute.
   *
   * @param string $owner
   *   The owner token from RouteRequestOwner.
   * @param string $operationId
   *   The caller-supplied operation id, already validated.
   * @param string $identityHash
   *   The canonical identity this operation is bound to.
   *
   * @return array{state: string, snapshot?: array, code?: string}|null
   *   The recorded outcome, or NULL when nothing is recorded.
   */
  public function lookup(string $owner, string $operationId, string $identityHash): ?array {
    $record = $this->read($owner, $operationId);
    if ($record === NULL) {
      return NULL;
    }

    // An id that names a different identity than it did before is a reuse with
    // different inputs, which must never be answered from the first identity's
    // result. The caller turns this into a conflict; it is never a provider call.
    if (!hash_equals((string) ($record['identity'] ?? ''), $identityHash)) {
      return ['state' => 'conflict'];
    }
    // An owner mismatch cannot normally happen — the key is owner-scoped — but the
    // owner is re-checked so a completed operation provably cannot cross the
    // authenticated/anonymous boundary even on a token collision.
    if (!hash_equals((string) ($record['owner'] ?? ''), $owner)) {
      return ['state' => 'conflict'];
    }

    $state = (string) ($record['state'] ?? '');
    if ($state === self::STATE_COMPLETED) {
      // A completed marker is only replayable while its route is still valid and
      // its payload still held. Past the route's validity the payload entry has
      // expired natively (the fact is not retained beyond permitted validity), so
      // the marker resolves to expired: recognised, but answered with
      // refresh_required rather than a stale fact or a fresh provider call.
      $resultExpiresAt = (int) ($record['result_expires_at'] ?? 0);
      if ($resultExpiresAt !== 0 && $resultExpiresAt <= $this->time->getCurrentTime()) {
        return ['state' => self::STATE_EXPIRED];
      }
      $payload = $this->readPayload($owner, $operationId);
      if ($payload === NULL) {
        // Marker says completed and the route clock says still valid, but the
        // payload is gone (evicted, or a clock edge). Fail safe to expired: never
        // recompute under an id already marked done.
        return ['state' => self::STATE_EXPIRED];
      }
      return ['state' => self::STATE_COMPLETED, 'snapshot' => $payload];
    }
    if ($state === self::STATE_EXPIRED) {
      return ['state' => self::STATE_EXPIRED];
    }
    if ($state === self::STATE_FAILED) {
      return ['state' => self::STATE_FAILED, 'code' => (string) ($record['code'] ?? DistanceProviderException::CODE_REQUEST_FAILED)];
    }
    if ($state === self::STATE_PENDING) {
      // A pending record whose lease has passed is an abandoned claim: its worker
      // never recorded an outcome. The caller (holding the lock, so no live worker
      // owns it) turns this into a refusal, never a fresh billed attempt.
      $leaseExpires = (int) ($record['lease_expires'] ?? 0);
      return ['state' => $leaseExpires > $this->time->getCurrentTime() ? self::STATE_PENDING : 'unknown'];
    }

    return NULL;
  }

  /**
   * Atomically claims an operation as pending, or reports why it could not.
   *
   * Called only while the caller holds the single-flight lock, so the sole race
   * this must survive is the narrow one between the lock's own backends. The
   * claim is a set-if-not-exists, so exactly one caller can create the pending
   * record; a caller that loses learns so and re-reads rather than computing.
   *
   * @return bool
   *   TRUE when this caller now owns a fresh pending record and may compute.
   *   FALSE when a record already exists (the caller must re-lookup to see what).
   */
  public function claimPending(string $owner, string $operationId, string $identityHash): bool {
    $store = $this->keyValueExpirableFactory->get(self::COLLECTION);
    $now = $this->time->getCurrentTime();
    $record = [
      'owner' => $owner,
      'identity' => $identityHash,
      'state' => self::STATE_PENDING,
      'lease_expires' => $now + self::PENDING_LEASE,
      'claimed_at' => $now,
    ];

    return $store->setWithExpireIfNotExists($this->key($owner, $operationId), $record, self::PENDING_LEASE);
  }

  /**
   * Records that an operation completed, persisting its result for replay.
   *
   * Called before the response is sent and before the single-flight lock is
   * released, so a replay that arrives after a lost response finds the outcome
   * already durable. The record's lifetime is capped so a replay can never
   * outlive the validity of the fact it would replay.
   *
   * @param string $owner
   *   The owner token.
   * @param string $operationId
   *   The operation id.
   * @param string $identityHash
   *   The identity the operation was bound to.
   * @param array $snapshot
   *   The full fact fields needed to rebuild the result without a provider.
   * @param int|null $resultExpiresAt
   *   The route's own expiry, when it declares one, as an upper bound on how long
   *   the completed record may be replayed.
   */
  public function complete(string $owner, string $operationId, string $identityHash, array $snapshot, ?int $resultExpiresAt): void {
    $store = $this->keyValueExpirableFactory->get(self::COLLECTION);
    $now = $this->time->getCurrentTime();

    // The marker lives for the whole operation-retention window, route-independent.
    // It carries no payload — only enough to recognise the id and, from
    // result_expires_at, to tell whether its route is still valid. So a repeat of
    // the id is recognised for the full window even after the route (and its
    // payload) have expired.
    $markerTtl = self::MAX_RECORD_TTL;

    // The payload lives only as long as the route is valid: capped at the earlier
    // of the retention window and the provider's declared expiry, so the fact is
    // never retained beyond its permitted validity. A NULL provider expiry (fixed
    // development, or an uncached live answer) has no upstream cap, so the payload
    // then shares the retention window — after which the id is legitimately
    // forgotten and a fresh, separately authorized operation may compute again.
    $payloadTtl = $markerTtl;
    if ($resultExpiresAt !== NULL) {
      $payloadTtl = min($payloadTtl, max(1, $resultExpiresAt - $now));
    }

    $store->setWithExpire($this->key($owner, $operationId), [
      'owner' => $owner,
      'identity' => $identityHash,
      'state' => self::STATE_COMPLETED,
      // Recorded on the marker so lookup() can tell a still-valid route from an
      // expired one without the payload. 0 means "no upstream expiry" (payload
      // TTL is the only bound), which lookup() treats as valid until the payload
      // itself is gone.
      'result_expires_at' => $resultExpiresAt ?? 0,
      'completed_at' => $now,
    ], $markerTtl);

    // The payload — the reusable fact — under its own route-validity-bounded TTL.
    $store->setWithExpire($this->payloadKey($owner, $operationId), [
      'owner' => $owner,
      'identity' => $identityHash,
      'snapshot' => $snapshot,
    ], $payloadTtl);

    // An identity-level pointer to this result. Two requests for the same journey
    // by the same owner have the same identity but different operation ids; without
    // this the second one would calculate again. The single-flight caller reads it
    // under the lock and binds the second id to the first's result. It shares the
    // payload's route-validity TTL and records result_expires_at, so identity-level
    // reuse can never outlive the route it reuses.
    $store->setWithExpire($this->identityKey($owner, $identityHash), [
      'owner' => $owner,
      'identity' => $identityHash,
      'snapshot' => $snapshot,
      'result_expires_at' => $resultExpiresAt ?? 0,
      'completed_at' => $now,
    ], $payloadTtl);
  }

  /**
   * Records a completed operation whose route was already expired on arrival.
   *
   * A provider result whose declared expiry is already in the past is not a fact
   * that may be priced, scheduled or republished — it is stale on receipt. Rather
   * than store a payload with a one-second replay window, the operation is recorded
   * as a terminal expired outcome: no payload, no identity pointer (nothing to
   * reuse), kept for the retention window so a repeat of the id returns
   * refresh_required with no second provider call. A fresh, separately authorized
   * operation id is the only way to attempt the journey again.
   */
  public function recordExpired(string $owner, string $operationId, string $identityHash): void {
    $store = $this->keyValueExpirableFactory->get(self::COLLECTION);
    $store->setWithExpire($this->key($owner, $operationId), [
      'owner' => $owner,
      'identity' => $identityHash,
      'state' => self::STATE_EXPIRED,
      'result_expires_at' => $this->time->getCurrentTime(),
      'recorded_at' => $this->time->getCurrentTime(),
    ], self::MAX_RECORD_TTL);
  }

  /**
   * Reads a completed result for this owner and journey identity, if any exists.
   *
   * The identity-level counterpart to lookup(): where lookup() answers "has THIS
   * operation id been recorded", this answers "has ANY operation for this exact
   * journey completed and is still retained". The single-flight caller consults it
   * under the lock, after finding the operation id itself unrecorded, so a second
   * operation id for the same journey reuses the first calculation rather than
   * making its own. Only a completed result is shared this way — a failed or pending
   * operation is operation-id-scoped, so a fresh id may still attempt the journey.
   *
   * @return array{state: string, snapshot: array}|null
   *   ['state' => completed, 'snapshot' => [...]] to reuse, or NULL when no retained
   *   completion exists for this identity.
   */
  public function lookupIdentity(string $owner, string $identityHash): ?array {
    $record = $this->keyValueExpirableFactory->get(self::COLLECTION)->get($this->identityKey($owner, $identityHash));
    if (!is_array($record)) {
      return NULL;
    }
    // Re-check both sides of the scope even though the key is owner+identity hashed,
    // so a reuse provably cannot cross an owner or a journey on a key collision.
    if (!hash_equals((string) ($record['owner'] ?? ''), $owner)
      || !hash_equals((string) ($record['identity'] ?? ''), $identityHash)) {
      return NULL;
    }
    // Only reuse a route that is still valid. The pointer shares the payload's
    // route-validity TTL, so an expired route's pointer has normally expired
    // already; this is defence in depth against a clock edge, so a stale route is
    // never reused for a fresh operation id.
    $resultExpiresAt = (int) ($record['result_expires_at'] ?? 0);
    if ($resultExpiresAt !== 0 && $resultExpiresAt <= $this->time->getCurrentTime()) {
      return NULL;
    }
    $snapshot = $record['snapshot'] ?? NULL;
    if (!is_array($snapshot) || $snapshot === []) {
      return NULL;
    }

    return ['state' => self::STATE_COMPLETED, 'snapshot' => $snapshot];
  }

  /**
   * Reads the route-validity-bounded payload for an owner's operation id.
   *
   * Separate from the marker so the fact is retained only as long as the route is
   * valid and expires natively when it is not. Returns the reusable snapshot, or
   * NULL when the payload has expired or is unreadable — the marker's state, not
   * this, decides whether that absence is an expired completion or nothing at all.
   */
  private function readPayload(string $owner, string $operationId): ?array {
    $record = $this->keyValueExpirableFactory->get(self::COLLECTION)->get($this->payloadKey($owner, $operationId));
    if (!is_array($record)) {
      return NULL;
    }
    if (!hash_equals((string) ($record['owner'] ?? ''), $owner)) {
      return NULL;
    }
    $snapshot = $record['snapshot'] ?? NULL;

    return is_array($snapshot) && $snapshot !== [] ? $snapshot : NULL;
  }

  /**
   * Records that an operation reached the provider and was refused.
   *
   * Kept only briefly: long enough that the retries of one lost response return
   * the same classified refusal instead of making a second billed attempt, not so
   * long that a transient refusal is remembered as permanent. The client must mint
   * a fresh operation id to try the journey again once the record has expired.
   */
  public function fail(string $owner, string $operationId, string $identityHash, string $code): void {
    $store = $this->keyValueExpirableFactory->get(self::COLLECTION);
    $record = [
      'owner' => $owner,
      'identity' => $identityHash,
      'state' => self::STATE_FAILED,
      'code' => $code,
      'failed_at' => $this->time->getCurrentTime(),
    ];
    $store->setWithExpire($this->key($owner, $operationId), $record, self::FAILED_RECORD_TTL);
  }

  /**
   * Reads and shape-checks the raw record for an owner's operation id.
   */
  private function read(string $owner, string $operationId): ?array {
    $record = $this->keyValueExpirableFactory->get(self::COLLECTION)->get($this->key($owner, $operationId));

    return is_array($record) ? $record : NULL;
  }

  /**
   * Builds the owner-scoped storage key for an operation id.
   *
   * The owner is folded into the key, not merely stored in the value, so two
   * owners' operations cannot collide even before the value's own owner check
   * runs. Owner and operation id are hashed together into one fixed-width digest
   * rather than concatenated raw: the keyvalue.expirable `name` column is
   * varchar(128), and a validated operation id may be up to 128 characters on its
   * own, so a raw `hash:id` key would overflow the column and throw on write. The
   * digest is a constant 68 characters ('op:' + 64-hex), well within the column,
   * and its collision resistance is that of the underlying sha256. The 'op:'
   * namespace keeps it disjoint from the identity key below.
   */
  private function key(string $owner, string $operationId): string {
    return 'op:' . hash('sha256', $owner . "\0" . $operationId);
  }

  /**
   * Builds the owner-scoped storage key for an operation's route-validity payload.
   *
   * A distinct namespace ('pay:') from the retention marker so the two never
   * collide and so the payload can carry its own, shorter, route-validity TTL and
   * expire natively when the route does — while the 'op:' marker lives on for the
   * retention window. Owner and operation id are folded into one fixed-width digest
   * for the same varchar(128) reason key() documents.
   */
  private function payloadKey(string $owner, string $operationId): string {
    return 'pay:' . hash('sha256', $owner . "\0" . $operationId);
  }

  /**
   * Builds the owner-scoped storage key for a journey identity.
   *
   * A distinct namespace ('id:') from the operation-id key so an identity pointer
   * and an operation record can never collide. Owner and identity are hashed
   * together into one fixed-width digest for the same reason key() does: 'id:' +
   * a raw owner hash + ':' + the identity hash is 132 characters, past the
   * varchar(128) `name` column; the folded digest is a constant 67 characters.
   */
  private function identityKey(string $owner, string $identityHash): string {
    return 'id:' . hash('sha256', $owner . "\0" . $identityHash);
  }

}
