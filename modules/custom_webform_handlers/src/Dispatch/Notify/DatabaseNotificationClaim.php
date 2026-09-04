<?php

namespace Drupal\custom_webform_handlers\Dispatch\Notify;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\IntegrityConstraintViolationException;

/**
 * The claim ledger, in SQL, with the unique index doing the deciding.
 *
 * One INSERT and nothing clever. The race is resolved by the database rejecting the
 * second row, not by this class reading first and then writing — a read-then-write
 * would let two workers both read "unclaimed" and both send.
 *
 * There is no UPDATE. A claim is created or it is discarded; it never changes meaning
 * in place, so there is no state machine here to get wrong. The two deletions are
 * narrow and both deliberate: self::release() gives back a single claim whose message
 * the transport refused, and self::prune() is the bulk retention sweep an operator
 * invokes. Nothing else removes a row, and in particular nothing removes one on cron.
 *
 * No recipient, no body, no reservation identifier, no submission identifier. This
 * table holds a hash, an audience word and a timestamp, so it discloses nothing at
 * all about whose booking it concerns — which is stricter than the history beside it,
 * and appropriate for a row whose only job is to say "taken".
 */
final class DatabaseNotificationClaim implements NotificationClaimInterface {

  /**
   * The claim ledger.
   */
  const TABLE = 'custom_webform_handlers_dispatch_notify_claim';

  /**
   * The most rows one prune will remove.
   *
   * A ceiling rather than an unbounded DELETE, so an operator running this on a table
   * that has grown for years does not hold a lock for minutes. Running it twice is
   * the intended way to remove more.
   */
  const PRUNE_LIMIT = 5000;

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function claim(string $key, DispatchAudience $audience): bool {
    $key = trim($key);
    if ($key === '') {
      // No key means nothing to be exclusive about. Refusing is the safe answer: a
      // caller that could not derive a key has a payload this release cannot read,
      // and sending mail about it is worse than sending none.
      return FALSE;
    }

    try {
      $this->database->insert(self::TABLE)
        ->fields([
          'idempotency_key' => $key,
          'audience' => $audience->value,
          'claimed_at' => $this->time->getRequestTime(),
        ])
        ->execute();
    }
    catch (IntegrityConstraintViolationException) {
      // The unique key refused it, so somebody else got here first. This is the one
      // decisive negative: not an error, and deliberately not logged at error level
      // by this class — the caller records a Skipped attempt in the history, which is
      // where an operator looks.
      return FALSE;
    }

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function claimed(string $key, DispatchAudience $audience): bool {
    $key = trim($key);
    if ($key === '') {
      return FALSE;
    }

    return (bool) $this->database->select(self::TABLE, 'c')
      ->fields('c', ['idempotency_key'])
      ->condition('c.idempotency_key', $key)
      ->condition('c.audience', $audience->value)
      ->range(0, 1)
      ->execute()
      ->fetchField();
  }

  /**
   * {@inheritdoc}
   */
  public function release(string $key, DispatchAudience $audience): void {
    $key = trim($key);
    if ($key === '') {
      return;
    }

    // Deliberately not wrapped in a try/catch. A release that cannot reach the
    // database leaves a claim standing for a message that was not sent, and the caller
    // needs to know: it is about to record a failure and return a retryable result,
    // and a retry that will certainly be suppressed is worse than a job that fails
    // loudly here. The exception propagates for the same reason self::claim()'s does.
    $this->database->delete(self::TABLE)
      ->condition('idempotency_key', $key)
      ->condition('audience', $audience->value)
      ->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function prune(int $before): int {
    if ($before <= 0) {
      return 0;
    }

    // Selected then deleted by primary key, rather than a bare ranged DELETE, because
    // DELETE ... LIMIT is not portable through Drupal's query builder.
    $ids = $this->database->select(self::TABLE, 'c')
      ->fields('c', ['id'])
      ->condition('c.claimed_at', $before, '<')
      ->orderBy('c.id')
      ->range(0, self::PRUNE_LIMIT)
      ->execute()
      ->fetchCol();

    if ($ids === []) {
      return 0;
    }

    return (int) $this->database->delete(self::TABLE)
      ->condition('id', $ids, 'IN')
      ->execute();
  }

}
