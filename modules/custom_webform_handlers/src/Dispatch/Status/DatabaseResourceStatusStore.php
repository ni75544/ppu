<?php

namespace Drupal\custom_webform_handlers\Dispatch\Status;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Psr\Log\LoggerInterface;

/**
 * The only place Resource Status SQL lives.
 *
 * Three deliberate absences, each of which would be a defect rather than a
 * simplification:
 *
 * There is no lock. A status row cannot conflict with anything — no two states
 * occupy the same capacity — so serialising it against the Resource Calendar Guard
 * would put a write in the path of a board read that already holds no lock, and buy
 * nothing.
 *
 * There is no UPDATE and no DELETE. A later report supersedes an earlier one by
 * being newer, so a correction adds a row instead of losing the fact that the
 * earlier assertion was made. The board reads the newest row and the audit trail
 * keeps the rest.
 *
 * There is no join to a Reservation, a submission, or a Webform. A record names a
 * Reservation by identifier and stops there, so no query on this seam can reach an
 * address, a price, or another customer's detail even if a caller asked it to.
 *
 * Writes never throw at the caller. A status is an observation, not capacity: a
 * failed write must not take down the form an operator is standing at a vehicle
 * using, so a failure is logged by exception type and answered as NULL.
 */
final class DatabaseResourceStatusStore implements ResourceStatusStoreInterface {

  /**
   * The Resource Status log.
   */
  const TABLE = 'custom_webform_handlers_dispatch_status';

  /**
   * How much operator note is stored.
   *
   * Matches the column. Truncating rather than refusing is the right trade for a
   * field somebody is typing one-handed: the first 255 characters of a note are
   * worth more than a validation error that loses all of it.
   */
  const NOTE_MAX_LENGTH = 255;

  /**
   * The most rows any single read will return.
   *
   * A ceiling rather than a default, so a caller cannot ask the board to render an
   * unbounded history by passing a large number.
   */
  const READ_LIMIT = 50;

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function record(
    string $resourceId,
    ResourceStatusState $state,
    ?int $dispatchReservationId,
    string $note,
    int $actorUid,
    string $actorName,
  ): ?ResourceStatus {
    $resourceId = trim($resourceId);
    if ($resourceId === '') {
      return NULL;
    }

    // A non-positive identifier is not a Reservation. Storing it as one would let a
    // board offer a link to row zero.
    $reservationId = ($dispatchReservationId !== NULL && $dispatchReservationId > 0)
      ? $dispatchReservationId
      : NULL;

    $recordedAt = $this->time->getRequestTime();
    $note = $this->clip($note, self::NOTE_MAX_LENGTH);
    $actorName = $this->clip($actorName, self::NOTE_MAX_LENGTH);

    try {
      $id = (int) $this->database->insert(self::TABLE)
        ->fields([
          'resource_id' => $resourceId,
          'state' => $state->value,
          'dispatch_reservation_id' => $reservationId,
          'note' => $note,
          'actor_uid' => max(0, $actorUid),
          'actor_name' => $actorName,
          'recorded_at' => $recordedAt,
          'source' => ResourceStatus::SOURCE_STAFF,
        ])
        ->execute();
    }
    catch (\Throwable $exception) {
      $this->logger->error('A Resource Status could not be recorded for @resource: @type', [
        '@resource' => $resourceId,
        '@type' => get_class($exception),
      ]);

      return NULL;
    }

    return new ResourceStatus(
      $id,
      $resourceId,
      $state,
      $reservationId,
      $note,
      max(0, $actorUid),
      $actorName,
      $recordedAt,
      ResourceStatus::SOURCE_STAFF,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function current(string $resourceId): ?ResourceStatus {
    $records = $this->recent($resourceId, 1);

    return $records === [] ? NULL : $records[0];
  }

  /**
   * {@inheritdoc}
   */
  public function recent(string $resourceId, int $limit): array {
    $resourceId = trim($resourceId);
    if ($resourceId === '') {
      // An empty Resource must never widen into every Resource: a board asking
      // about nothing gets nothing, not somebody else's vehicle.
      return [];
    }

    $limit = max(1, min(self::READ_LIMIT, $limit));

    try {
      $rows = $this->database->select(self::TABLE, 's')
        ->fields('s', [
          'id',
          'resource_id',
          'state',
          'dispatch_reservation_id',
          'note',
          'actor_uid',
          'actor_name',
          'recorded_at',
          'source',
        ])
        ->condition('s.resource_id', $resourceId)
        // Newest first, with the serial as the tie-break: two reports inside one
        // request time are ordered by the sequence they were written in, so a
        // correction never renders as though it came before what it corrects.
        ->orderBy('s.recorded_at', 'DESC')
        ->orderBy('s.id', 'DESC')
        ->range(0, $limit)
        ->execute()
        ->fetchAll();
    }
    catch (\Throwable $exception) {
      $this->logger->error('Resource Status history could not be read for @resource: @type', [
        '@resource' => $resourceId,
        '@type' => get_class($exception),
      ]);

      return [];
    }

    $records = [];
    foreach ($rows as $row) {
      // An unreadable row is skipped rather than guessed at. See
      // ResourceStatus::fromRow().
      $record = ResourceStatus::fromRow($row);
      if ($record !== NULL) {
        $records[] = $record;
      }
    }

    return $records;
  }

  /**
   * Trims and shortens one stored string to what its column holds.
   */
  private function clip(string $value, int $length): string {
    $value = trim($value);

    return mb_strlen($value) > $length ? mb_substr($value, 0, $length) : $value;
  }

}
