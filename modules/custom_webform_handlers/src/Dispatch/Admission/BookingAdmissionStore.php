<?php

namespace Drupal\custom_webform_handlers\Dispatch\Admission;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Psr\Log\LoggerInterface;

/**
 * The durable, fail-closed store for per-submission booking admission.
 *
 * One row per submission, PRIMARY KEY submission_id, is the one job-exclusivity
 * record a booking has — the two driver-capacity families cannot gate each other,
 * and staff, manual, and replan bookings bypass the checkout attempt, so no
 * existing table can answer "is this submission already being booked, and by whom".
 *
 * Task 24C-1 keeps it inert: wired into the container, but with no caller, no hook,
 * no subscriber, and no constructor query. It creates no schema — assertSchema()
 * runs first on every entry point and throws AdmissionSchemaMissingException when
 * the table is absent, so an explicit call on an unmigrated site stops rather than
 * returning "admitted" or a NULL a caller could read as "no admission exists".
 *
 * Concurrency rests on the primary key: a first acquire is a plain INSERT, a
 * duplicate-key race re-reads the winner's committed row under the lock, and every
 * later decision reads FOR UPDATE and writes with a compare-and-set on generation
 * and prior state. Mutations open a transaction that nests as a savepoint under a
 * caller's outer transaction. Nothing is time-based: only release(), with evidence
 * and a matching owner and generation, ends an admission, and it retains the row
 * and leaves the generation for a later re-arm to increment.
 */
final class BookingAdmissionStore implements BookingAdmissionStoreInterface {

  /**
   * Every column an admission row is written from and rebuilt from.
   */
  private const FIELDS = [
    'submission_id', 'generation', 'state', 'owner_token_hash',
    'intent_fingerprint', 'owner_kind', 'note', 'created_at',
    'updated_at', 'confirmed_at', 'released_at',
  ];

  /**
   * The stored width of a note or release-evidence string.
   */
  private const NOTE_MAX = 255;

  /**
   * Memoized schema presence.
   *
   * NULL until proven. A definite result is memoized; a transient inspection
   * failure is not, so a probe error can be retried within the request rather than
   * poisoning the instance into a permanent refusal.
   */
  private ?bool $schemaPresent = NULL;

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Mints a server-owned admission secret.
   *
   * The caller keeps this privately and passes it back; the store only ever sees
   * and persists its hash. A deterministic intent fingerprint is not a substitute —
   * it is not secret, and a matching fingerprint under a mismatched owner is refused.
   */
  public static function mintOwnerSecret(): string {
    return bin2hex(random_bytes(32));
  }

  /**
   * {@inheritdoc}
   */
  public function acquire(int $submissionId, string $ownerSecret, string $intentFingerprint, string $ownerKind): AdmissionResult {
    $this->assertSchema();
    $this->assertSubmissionId($submissionId);
    $ownerHash = $this->hashSecret($ownerSecret);
    $intent = $this->normaliseFingerprint($intentFingerprint);
    $this->assertOwnerKind($ownerKind);

    $transaction = $this->database->startTransaction();
    try {
      $existing = $this->selectForUpdate($submissionId);
      if ($existing === NULL) {
        return $this->insertPending($submissionId, $ownerHash, $intent, $ownerKind);
      }

      $record = $this->hydrate($existing);
      if ($record === NULL) {
        return AdmissionResult::of(AdmissionOutcome::RefusedState, NULL, 'corrupt_state');
      }

      if ($record->state->isLive()) {
        if (!$this->ownerMatches($record->ownerTokenHash, $ownerHash)) {
          return AdmissionResult::of(AdmissionOutcome::RefusedOwner, $record, 'owner_mismatch');
        }
        if (!hash_equals($record->intentFingerprint, $intent)) {
          return AdmissionResult::of(AdmissionOutcome::RefusedIntent, $record, 'intent_mismatch');
        }
        return AdmissionResult::of(AdmissionOutcome::Reused, $record, 'reused_live');
      }

      return $this->reacquireReleased($record, $ownerHash, $intent, $ownerKind);
    }
    catch (IntegrityConstraintViolationException) {
      // A concurrent first-acquire won the primary-key race and has committed. Re-read
      // its row under the lock and answer against it, so both acquires converge.
      return $this->resolveRace($submissionId, $ownerHash, $intent);
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      throw $e;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function confirm(int $submissionId, string $ownerSecret, int $expectedGeneration): AdmissionResult {
    return $this->transition(
      $submissionId,
      $ownerSecret,
      $expectedGeneration,
      function (AdmissionRecord $record) use ($submissionId, $expectedGeneration): AdmissionResult {
        if ($record->state === AdmissionState::Confirmed) {
          return AdmissionResult::of(AdmissionOutcome::Confirmed, $record, 'already_confirmed');
        }
        if ($record->state !== AdmissionState::Pending) {
          return AdmissionResult::of(AdmissionOutcome::RefusedState, $record, 'not_pending');
        }
        $now = $this->time->getRequestTime();
        $affected = (int) $this->database->update(self::TABLE)
          ->fields([
            'state' => AdmissionState::Confirmed->value,
            'confirmed_at' => $now,
            'updated_at' => $now,
          ])
          ->condition('submission_id', $submissionId)
          ->condition('generation', $expectedGeneration)
          ->condition('state', AdmissionState::Pending->value)
          ->execute();
        if ($affected < 1) {
          return AdmissionResult::of(AdmissionOutcome::RefusedState, $record, 'confirm_lost_race');
        }
        return AdmissionResult::of(AdmissionOutcome::Confirmed, $this->loadRecord($submissionId), 'confirmed');
      },
    );
  }

  /**
   * {@inheritdoc}
   */
  public function requireReconciliation(int $submissionId, string $ownerSecret, int $expectedGeneration, string $reason): AdmissionResult {
    $note = $this->normaliseReason($reason);
    return $this->transition(
      $submissionId,
      $ownerSecret,
      $expectedGeneration,
      function (AdmissionRecord $record) use ($submissionId, $expectedGeneration, $note): AdmissionResult {
        if ($record->state === AdmissionState::ReconciliationRequired) {
          return AdmissionResult::of(AdmissionOutcome::ReconciliationRequired, $record, 'already_reconciliation_required');
        }
        if ($record->state !== AdmissionState::Pending && $record->state !== AdmissionState::Confirmed) {
          return AdmissionResult::of(AdmissionOutcome::RefusedState, $record, 'not_live_transition');
        }
        $now = $this->time->getRequestTime();
        $affected = (int) $this->database->update(self::TABLE)
          ->fields([
            'state' => AdmissionState::ReconciliationRequired->value,
            'note' => $note,
            'updated_at' => $now,
          ])
          ->condition('submission_id', $submissionId)
          ->condition('generation', $expectedGeneration)
          ->condition('state', [AdmissionState::Pending->value, AdmissionState::Confirmed->value], 'IN')
          ->execute();
        if ($affected < 1) {
          return AdmissionResult::of(AdmissionOutcome::RefusedState, $record, 'reconcile_lost_race');
        }
        return AdmissionResult::of(AdmissionOutcome::ReconciliationRequired, $this->loadRecord($submissionId), 'reconciliation_required');
      },
    );
  }

  /**
   * {@inheritdoc}
   */
  public function release(int $submissionId, string $ownerSecret, int $expectedGeneration, string $releaseEvidence): AdmissionResult {
    $evidence = $this->normaliseEvidence($releaseEvidence);
    return $this->transition(
      $submissionId,
      $ownerSecret,
      $expectedGeneration,
      function (AdmissionRecord $record) use ($submissionId, $expectedGeneration, $evidence): AdmissionResult {
        if ($record->state === AdmissionState::Released) {
          return AdmissionResult::of(AdmissionOutcome::Released, $record, 'already_released');
        }
        $now = $this->time->getRequestTime();
        // Generation is not incremented here: a released row keeps its generation as
        // the floor a later re-arm increments past, and the row itself is retained.
        $affected = (int) $this->database->update(self::TABLE)
          ->fields([
            'state' => AdmissionState::Released->value,
            'released_at' => $now,
            'note' => $evidence,
            'updated_at' => $now,
          ])
          ->condition('submission_id', $submissionId)
          ->condition('generation', $expectedGeneration)
          ->condition('state', AdmissionState::Released->value, '<>')
          ->execute();
        if ($affected < 1) {
          return AdmissionResult::of(AdmissionOutcome::RefusedState, $record, 'release_lost_race');
        }
        return AdmissionResult::of(AdmissionOutcome::Released, $this->loadRecord($submissionId), 'released');
      },
    );
  }

  /**
   * {@inheritdoc}
   */
  public function verify(int $submissionId, string $ownerSecret, int $expectedGeneration): AdmissionResult {
    $this->assertSchema();
    $this->assertInTransaction();
    $this->assertSubmissionId($submissionId);
    $ownerHash = $this->hashSecret($ownerSecret);
    $this->assertGeneration($expectedGeneration);

    // A locking read with no write of its own, and the binding primitive. It is
    // required (assertInTransaction() above) to run inside the binding writer's own
    // transaction, so the row lock FOR UPDATE takes is held to that writer's commit
    // and the admission cannot be released between this check and the binding write.
    // It opens no transaction of its own: called with no ambient transaction it
    // refuses before any read, so it can never silently degrade to an unlocked
    // point-in-time answer a caller might trust as a lock it still holds.
    $row = $this->selectForUpdate($submissionId);
    if ($row === NULL) {
      return AdmissionResult::of(AdmissionOutcome::NotFound, NULL, 'not_found');
    }
    $record = $this->hydrate($row);
    if ($record === NULL) {
      return AdmissionResult::of(AdmissionOutcome::RefusedState, NULL, 'corrupt_state');
    }
    if (!$this->ownerMatches($record->ownerTokenHash, $ownerHash)) {
      return AdmissionResult::of(AdmissionOutcome::RefusedOwner, $record, 'owner_mismatch');
    }
    if ($record->generation !== $expectedGeneration) {
      return AdmissionResult::of(AdmissionOutcome::RefusedGeneration, $record, 'generation_mismatch');
    }
    if (!$record->state->isLive()) {
      return AdmissionResult::of(AdmissionOutcome::RefusedState, $record, 'not_live');
    }
    return AdmissionResult::of(AdmissionOutcome::Verified, $record, 'verified');
  }

  /**
   * {@inheritdoc}
   */
  public function find(int $submissionId): ?AdmissionRecord {
    $this->assertSchema();
    $this->assertSubmissionId($submissionId);
    return $this->loadRecord($submissionId);
  }

  /**
   * Shared owner-and-generation gate for confirm, reconcile, and release.
   *
   * Opens the mutation transaction, locks the row, applies the common refusals
   * (missing row, unreadable row, wrong owner, wrong generation), then hands a live
   * record to the per-operation writer. The writer runs its own compare-and-set, so
   * a decision made on the locked read cannot be applied to a row that changed
   * underneath it.
   */
  private function transition(int $submissionId, string $ownerSecret, int $expectedGeneration, callable $apply): AdmissionResult {
    $this->assertSchema();
    $this->assertSubmissionId($submissionId);
    $ownerHash = $this->hashSecret($ownerSecret);
    $this->assertGeneration($expectedGeneration);

    $transaction = $this->database->startTransaction();
    try {
      $row = $this->selectForUpdate($submissionId);
      if ($row === NULL) {
        return AdmissionResult::of(AdmissionOutcome::NotFound, NULL, 'not_found');
      }
      $record = $this->hydrate($row);
      if ($record === NULL) {
        return AdmissionResult::of(AdmissionOutcome::RefusedState, NULL, 'corrupt_state');
      }
      if (!$this->ownerMatches($record->ownerTokenHash, $ownerHash)) {
        return AdmissionResult::of(AdmissionOutcome::RefusedOwner, $record, 'owner_mismatch');
      }
      if ($record->generation !== $expectedGeneration) {
        return AdmissionResult::of(AdmissionOutcome::RefusedGeneration, $record, 'generation_mismatch');
      }
      return $apply($record);
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      throw $e;
    }
  }

  /**
   * Inserts the first admission for a submission at generation 1.
   */
  private function insertPending(int $submissionId, string $ownerHash, string $intent, string $ownerKind): AdmissionResult {
    $now = $this->time->getRequestTime();
    $this->database->insert(self::TABLE)
      ->fields([
        'submission_id' => $submissionId,
        'generation' => 1,
        'state' => AdmissionState::Pending->value,
        'owner_token_hash' => $ownerHash,
        'intent_fingerprint' => $intent,
        'owner_kind' => $ownerKind,
        'note' => NULL,
        'created_at' => $now,
        'updated_at' => $now,
        'confirmed_at' => NULL,
        'released_at' => NULL,
      ])
      ->execute();
    return AdmissionResult::of(AdmissionOutcome::Admitted, $this->loadRecord($submissionId), 'admitted');
  }

  /**
   * Re-arms a released row onto a fresh, incremented generation.
   */
  private function reacquireReleased(AdmissionRecord $record, string $ownerHash, string $intent, string $ownerKind): AdmissionResult {
    $now = $this->time->getRequestTime();
    $affected = (int) $this->database->update(self::TABLE)
      ->fields([
        'generation' => $record->generation + 1,
        'state' => AdmissionState::Pending->value,
        'owner_token_hash' => $ownerHash,
        'intent_fingerprint' => $intent,
        'owner_kind' => $ownerKind,
        'note' => NULL,
        'updated_at' => $now,
        'confirmed_at' => NULL,
        'released_at' => NULL,
      ])
      ->condition('submission_id', $record->submissionId)
      ->condition('generation', $record->generation)
      ->condition('state', AdmissionState::Released->value)
      ->execute();
    if ($affected < 1) {
      return AdmissionResult::of(AdmissionOutcome::RefusedState, $record, 'reacquire_lost_race');
    }
    return AdmissionResult::of(AdmissionOutcome::Admitted, $this->loadRecord($record->submissionId), 'reacquired');
  }

  /**
   * Resolves a primary-key insert race by answering against the committed winner.
   *
   * Reached only from acquire()'s duplicate-key catch. InnoDB does not abort the
   * transaction on a duplicate key, so the still-open transaction can re-read the
   * row the winner committed, now visible under FOR UPDATE. The winner defines the
   * admission: a matching owner and intent is an idempotent reuse, a mismatch is a
   * refusal. A row that is already released is not re-armed here — that needs a
   * write the loser did not set out to make; the caller reacquires explicitly.
   */
  private function resolveRace(int $submissionId, string $ownerHash, string $intent): AdmissionResult {
    $row = $this->selectForUpdate($submissionId);
    if ($row === NULL) {
      return AdmissionResult::of(AdmissionOutcome::RefusedState, NULL, 'race_vanished');
    }
    $record = $this->hydrate($row);
    if ($record === NULL) {
      return AdmissionResult::of(AdmissionOutcome::RefusedState, NULL, 'corrupt_state');
    }
    if (!$record->state->isLive()) {
      return AdmissionResult::of(AdmissionOutcome::RefusedState, $record, 'race_released');
    }
    if (!$this->ownerMatches($record->ownerTokenHash, $ownerHash)) {
      return AdmissionResult::of(AdmissionOutcome::RefusedOwner, $record, 'owner_mismatch');
    }
    if (!hash_equals($record->intentFingerprint, $intent)) {
      return AdmissionResult::of(AdmissionOutcome::RefusedIntent, $record, 'intent_mismatch');
    }
    return AdmissionResult::of(AdmissionOutcome::Reused, $record, 'reused_race');
  }

  /**
   * Reads and row-locks a submission's admission row, or NULL when absent.
   *
   * FOR UPDATE both serialises concurrent mutators on the row and, within an open
   * transaction, sees a committed row a plain snapshot read might miss — the
   * property the insert-race resolution and the binding-writer verify() rely on.
   */
  private function selectForUpdate(int $submissionId): ?object {
    $row = $this->database->select(self::TABLE, 'a')
      ->fields('a', self::FIELDS)
      ->condition('submission_id', $submissionId)
      ->forUpdate()
      ->execute()
      ->fetchObject();
    return $row ?: NULL;
  }

  /**
   * Reads a submission's admission row without a lock, or NULL when absent.
   *
   * The snapshot read behind find() and the post-write reload the mutations return.
   * A reload running inside the same transaction as its write sees that write.
   */
  private function loadRecord(int $submissionId): ?AdmissionRecord {
    $row = $this->database->select(self::TABLE, 'a')
      ->fields('a', self::FIELDS)
      ->condition('submission_id', $submissionId)
      ->execute()
      ->fetchObject();
    return $row ? $this->hydrate($row) : NULL;
  }

  /**
   * Turns a fetched row into a record, logging and nulling an unreadable one.
   *
   * A NULL here is a corrupt or unrecognisable row, which every caller converts to
   * a RefusedState refusal — never to a live or released admission.
   */
  private function hydrate(object $row): ?AdmissionRecord {
    $record = AdmissionRecord::fromRow($row);
    if ($record === NULL) {
      $this->logger->error('Unreadable booking-admission row for submission @id.', [
        '@id' => isset($row->submission_id) ? (string) $row->submission_id : 'unknown',
      ]);
    }
    return $record;
  }

  /**
   * Fails closed unless the admission table is present.
   *
   * The store creates no schema. A definite presence or absence is memoized; a
   * transient inspection failure is not memoized and re-throws, so a probe error
   * is retryable within the request rather than poisoning the instance. An absent
   * table throws rather than letting any entry point answer "admitted" or hand a
   * caller a NULL it might read as "no admission exists".
   */
  private function assertSchema(): void {
    if ($this->schemaPresent === TRUE) {
      return;
    }
    try {
      $present = $this->database->schema()->tableExists(self::TABLE);
    }
    catch (\Exception $e) {
      throw new AdmissionSchemaMissingException(
        'Unable to determine whether the booking-admission table exists.',
        0,
        $e,
      );
    }
    $this->schemaPresent = $present;
    if (!$present) {
      throw AdmissionSchemaMissingException::forTable(self::TABLE);
    }
  }

  /**
   * Hashes an owner secret for storage and comparison; the secret is never stored.
   */
  private function hashSecret(string $ownerSecret): string {
    return hash('sha256', $ownerSecret);
  }

  /**
   * Constant-time comparison of a stored owner hash against a presented one.
   */
  private function ownerMatches(string $storedHash, string $presentedHash): bool {
    return hash_equals($storedHash, $presentedHash);
  }

  /**
   * Normalises a caller's intent fingerprint; it is matched, never truncated.
   *
   * A fingerprint is trimmed and required to be non-empty. It is not shortened:
   * idempotency recognition compares the whole value, so a truncated fingerprint
   * could collide two distinct intents. The DDL must size the column to hold it.
   */
  private function normaliseFingerprint(string $intentFingerprint): string {
    $intent = trim($intentFingerprint);
    if ($intent === '') {
      throw new \InvalidArgumentException('An intent fingerprint is required.');
    }
    return $intent;
  }

  /**
   * Normalises a reconciliation reason: non-empty, then bounded to the note width.
   */
  private function normaliseReason(string $reason): string {
    $trimmed = trim($reason);
    if ($trimmed === '') {
      throw new \InvalidArgumentException('A reconciliation reason is required.');
    }
    return $this->boundNote($trimmed);
  }

  /**
   * Normalises release evidence: non-empty, then bounded to the note width.
   */
  private function normaliseEvidence(string $releaseEvidence): string {
    $trimmed = trim($releaseEvidence);
    if ($trimmed === '') {
      throw new \InvalidArgumentException('Release evidence is required.');
    }
    return $this->boundNote($trimmed);
  }

  /**
   * Bounds a note or evidence string to the stored column width.
   */
  private function boundNote(string $value): string {
    return mb_substr($value, 0, self::NOTE_MAX);
  }

  /**
   * Requires an ambient transaction for the binding primitive verify().
   *
   * verify() is a locking read whose FOR UPDATE lock only means anything while a
   * surrounding transaction holds it open to the binding write. With no active
   * transaction the lock would be taken and released at statement end, leaving the
   * caller a point-in-time answer it could mistake for a lock still held across its
   * own write. That is a binding-writer contract violation — a programming error,
   * not a data condition — so it throws before any read rather than returning a
   * refusal a caller might read as "no admission", and never opens a transaction of
   * its own to paper over the misuse.
   */
  private function assertInTransaction(): void {
    if (!$this->database->inTransaction()) {
      throw new \LogicException('BookingAdmissionStore::verify() must run inside an active database transaction: its FOR UPDATE lock is only held for the duration of the caller\'s transaction, so a standalone call cannot provide the exclusivity a binding write relies on.');
    }
  }

  /**
   * Rejects a non-positive submission id before any query runs.
   */
  private function assertSubmissionId(int $submissionId): void {
    if ($submissionId < 1) {
      throw new \InvalidArgumentException('A positive submission id is required.');
    }
  }

  /**
   * Rejects a non-positive expected generation.
   *
   * Generations start at 1 on first acquire and only increment, so a caller that
   * names generation 0 or below is holding no admission this store ever minted.
   */
  private function assertGeneration(int $expectedGeneration): void {
    if ($expectedGeneration < 1) {
      throw new \InvalidArgumentException('A positive expected generation is required.');
    }
  }

  /**
   * Rejects an owner-kind outside the accepted set.
   */
  private function assertOwnerKind(string $ownerKind): void {
    if (!in_array($ownerKind, self::OWNER_KINDS, TRUE)) {
      throw new \InvalidArgumentException(sprintf('Unknown owner kind "%s".', $ownerKind));
    }
  }

}
