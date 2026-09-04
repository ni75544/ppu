<?php

namespace Drupal\custom_webform_handlers\Dispatch\Storage;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;

/**
 * Serializes planner mutations by row-locking Resource Calendar Guards.
 *
 * This is the only contention authority in the dispatch layer. It is not the
 * lock service, not a cache lock, not a process-local mutex, and not a fixed
 * hourly key — all of those either live outside the database, and so cannot be
 * part of the same transaction as the write they protect, or assume a fixed slot
 * length that a variable Dispatch Window does not have.
 *
 * What it does, in order, inside one transaction:
 *
 * 1. Upserts a guard row for every affected Resource and local date, because a
 *    row that does not exist cannot be locked. Two contenders racing to create
 *    the same guard are resolved by its unique key on resource and date.
 * 2. Locks those rows FOR UPDATE, in ascending resource-then-date order. The
 *    order is what prevents deadlock: two claims touching an overlapping set of
 *    days always take the same locks in the same sequence, so one simply waits.
 * 3. Runs the caller's work, which may now read the schedule and write to it in
 *    the knowledge that no other claim for these days can interleave.
 *
 * The consequence for a concurrent contender is that it blocks on step 2, and
 * when it proceeds it reads the schedule as the winner left it. It therefore sees
 * the new claim and refuses, rather than reading a stale schedule and double
 * booking. There is no availability SELECT outside this transaction.
 */
final class ResourceCalendarGuard {

  /**
   * The guard table.
   */
  const TABLE = 'custom_webform_handlers_dispatch_guard';

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Runs work while holding the guard rows for one Resource and set of dates.
   *
   * @param string $resourceId
   *   The Resource whose days are being locked.
   * @param string[] $localDates
   *   Y-m-d dates to lock. Every day the candidate interval touches must be
   *   present, including days reached only by repositioning or return to base.
   * @param callable $work
   *   Receives an array of the locked guard rows, keyed by local date, and
   *   returns the operation's result. Anything it throws rolls the transaction
   *   back.
   *
   * @return mixed
   *   Whatever the work returned.
   *
   * @throws \InvalidArgumentException
   *   When no dates are supplied, since unguarded work must not be possible.
   */
  public function withLockedDays(string $resourceId, array $localDates, callable $work): mixed {
    $dates = $this->normaliseDates($localDates);
    if ($resourceId === '' || $dates === []) {
      // Refusing here is deliberate. Returning the work unguarded would make
      // every caller's safety depend on remembering to pass dates.
      throw new \InvalidArgumentException('Guarded work requires a Resource and at least one local date.');
    }

    $now = $this->time->getRequestTime();
    $transaction = $this->database->startTransaction();

    try {
      // Step 1: the rows must exist before they can be locked.
      foreach ($dates as $date) {
        $this->database->merge(self::TABLE)
          ->keys([
            'resource_id' => $resourceId,
            'local_date' => $date,
          ])
          ->fields(['changed' => $now])
          ->insertFields([
            'resource_id' => $resourceId,
            'local_date' => $date,
            'legacy_unplanned' => 0,
            'created' => $now,
            'changed' => $now,
          ])
          ->execute();
      }

      // Step 2: take the locks in a deterministic order.
      $rows = [];
      foreach ($dates as $date) {
        $row = $this->database->select(self::TABLE, 'g')
          ->fields('g', ['guard_id', 'resource_id', 'local_date', 'legacy_unplanned', 'legacy_note'])
          ->condition('g.resource_id', $resourceId)
          ->condition('g.local_date', $date)
          ->forUpdate()
          ->execute()
          ->fetchObject();

        if (!$row) {
          // The upsert above ran in this transaction, so this cannot happen
          // through ordinary contention. Treat it as a failure rather than
          // continuing with one day unguarded.
          throw new \RuntimeException(sprintf('Resource Calendar Guard for %s on %s could not be locked.', $resourceId, $date));
        }
        $rows[$date] = $row;
      }

      // Step 3: the caller now owns these days for the rest of the transaction.
      return $work($rows);
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }
  }

  /**
   * Marks a Resource day as carrying an unproven legacy claim.
   *
   * Used by reconciliation tooling and by the legacy scan. Kept here because the
   * guard table is this class's concern.
   */
  public function flagLegacyUnplanned(string $resourceId, string $localDate, string $note): void {
    $now = $this->time->getRequestTime();
    $this->database->merge(self::TABLE)
      ->keys([
        'resource_id' => $resourceId,
        'local_date' => $localDate,
      ])
      ->fields([
        'legacy_unplanned' => 1,
        'legacy_note' => mb_substr($note, 0, 255),
        'changed' => $now,
      ])
      ->insertFields([
        'resource_id' => $resourceId,
        'local_date' => $localDate,
        'legacy_unplanned' => 1,
        'legacy_note' => mb_substr($note, 0, 255),
        'created' => $now,
        'changed' => $now,
      ])
      ->execute();
  }

  /**
   * Clears the legacy flag once an administrator has reconciled a day.
   *
   * @return bool
   *   TRUE when a flagged day was cleared.
   */
  public function clearLegacyUnplanned(string $resourceId, string $localDate): bool {
    $cleared = $this->database->update(self::TABLE)
      ->fields([
        'legacy_unplanned' => 0,
        'legacy_note' => NULL,
        'changed' => $this->time->getRequestTime(),
      ])
      ->condition('resource_id', $resourceId)
      ->condition('local_date', $localDate)
      ->condition('legacy_unplanned', 1)
      ->execute();

    return (int) $cleared > 0;
  }

  /**
   * Returns the flagged local dates for one Resource within a date range.
   *
   * @return string[]
   *   Y-m-d dates that are closed to self-booking.
   */
  public function legacyUnplannedDates(string $resourceId, string $fromDate, string $toDate): array {
    $dates = $this->database->select(self::TABLE, 'g')
      ->fields('g', ['local_date'])
      ->condition('g.resource_id', $resourceId)
      ->condition('g.legacy_unplanned', 1)
      ->condition('g.local_date', $fromDate, '>=')
      ->condition('g.local_date', $toDate, '<=')
      ->execute()
      ->fetchCol();

    return array_map('strval', $dates);
  }

  /**
   * Sorts, trims, and de-duplicates the requested dates.
   *
   * Sorting here rather than trusting the caller is what makes the lock order
   * deterministic across every code path.
   *
   * @return string[]
   *   Ascending, unique, well-formed Y-m-d dates.
   */
  private function normaliseDates(array $localDates): array {
    $dates = [];
    foreach ($localDates as $date) {
      $date = trim((string) $date);
      if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
        $dates[$date] = $date;
      }
    }
    $dates = array_keys($dates);
    sort($dates, SORT_STRING);
    return $dates;
  }

}
