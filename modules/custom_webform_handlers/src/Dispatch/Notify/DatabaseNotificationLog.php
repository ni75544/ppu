<?php

namespace Drupal\custom_webform_handlers\Dispatch\Notify;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Psr\Log\LoggerInterface;

/**
 * The only place notification history SQL lives.
 *
 * Insert and select, and nothing else. There is no UPDATE, because an attempt that has
 * happened cannot un-happen; there is no DELETE, because the row explaining why a
 * customer never heard from us is the row somebody will want in three weeks. There is
 * no join to a submission or a Webform either: the log names them by identifier and
 * stops, so no query on this seam can reach an address or a price even if a caller
 * asked it to.
 *
 * No column here holds a recipient or a message body. See NotificationRecord for why
 * that is a design decision rather than an omission.
 *
 * Writes never throw at the caller. The caller is a queue worker that has just handed
 * a real message to a real mail server: if recording that fact failed and the failure
 * propagated, the worker would treat a sent notification as unsent and send it again.
 * A silent history is a much smaller problem than a customer receiving four copies of
 * the same confirmation.
 */
final class DatabaseNotificationLog implements NotificationLogInterface {

  /**
   * The notification history.
   */
  const TABLE = 'custom_webform_handlers_dispatch_notification';

  /**
   * How much operator-safe detail is stored.
   */
  const DETAIL_MAX_LENGTH = 255;

  /**
   * The most rows any single read will return.
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
    DispatchNotification $notification,
    DispatchAudience $audience,
    NotificationOutcome $outcome,
    string $channel,
    string $detail,
  ): void {
    $resourceId = trim($notification->resourceId);
    if ($resourceId === '') {
      return;
    }

    try {
      $this->database->insert(self::TABLE)
        ->fields([
          'event' => $notification->event->value,
          'audience' => $audience->value,
          'channel' => $this->clip($channel, 32),
          'outcome' => $outcome->value,
          'resource_id' => $resourceId,
          'dispatch_reservation_id' => $notification->dispatchReservationId,
          'submission_id' => $notification->submissionId,
          'occurred_at' => $notification->occurredAt,
          'recorded_at' => $this->time->getRequestTime(),
          'detail' => $this->clip($detail, self::DETAIL_MAX_LENGTH),
        ])
        ->execute();
    }
    catch (\Throwable $exception) {
      // Swallowed on purpose. See the class docblock: a history write must not be able
      // to turn a delivered message into a duplicated one.
      $this->logger->error('A dispatch notification history row could not be written for @event on @resource: @type', [
        '@event' => $notification->event->value,
        '@resource' => $resourceId,
        '@type' => get_class($exception),
      ]);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function recent(string $resourceId, int $limit): array {
    $resourceId = trim($resourceId);
    if ($resourceId === '') {
      // An empty Resource must never widen into every Resource: a board asking about
      // nothing gets nothing, not another vehicle's history.
      return [];
    }

    $limit = max(1, min(self::READ_LIMIT, $limit));

    try {
      $rows = $this->database->select(self::TABLE, 'n')
        ->fields('n', [
          'id',
          'event',
          'audience',
          'channel',
          'outcome',
          'resource_id',
          'dispatch_reservation_id',
          'submission_id',
          'occurred_at',
          'recorded_at',
          'detail',
        ])
        ->condition('n.resource_id', $resourceId)
        // Newest attempt first, with the serial as the tie-break so the two audiences
        // of one event keep the order they were sent in.
        ->orderBy('n.recorded_at', 'DESC')
        ->orderBy('n.id', 'DESC')
        ->range(0, $limit)
        ->execute()
        ->fetchAll();
    }
    catch (\Throwable $exception) {
      $this->logger->error('Dispatch notification history could not be read for @resource: @type', [
        '@resource' => $resourceId,
        '@type' => get_class($exception),
      ]);

      return [];
    }

    $records = [];
    foreach ($rows as $row) {
      $record = NotificationRecord::fromRow($row);
      if ($record !== NULL) {
        $records[] = $record;
      }
    }

    return $records;
  }

  /**
   * {@inheritdoc}
   */
  public function attentionCount(string $resourceId, int $limit): int {
    $resourceId = trim($resourceId);
    if ($resourceId === '') {
      return 0;
    }

    $limit = max(1, min(self::READ_LIMIT, $limit));

    try {
      // One column, bounded by the same ceiling as a read, counted here rather than in
      // SQL. A limited COUNT needs a subquery, and fifty single-column values is not
      // worth one: the ceiling is what keeps this cheap, not the arithmetic.
      $outcomes = $this->database->select(self::TABLE, 'n')
        ->fields('n', ['outcome'])
        ->condition('n.resource_id', $resourceId)
        ->orderBy('n.recorded_at', 'DESC')
        ->orderBy('n.id', 'DESC')
        ->range(0, $limit)
        ->execute()
        ->fetchCol();
    }
    catch (\Throwable $exception) {
      $this->logger->error('Dispatch notification history could not be counted for @resource: @type', [
        '@resource' => $resourceId,
        '@type' => get_class($exception),
      ]);

      return 0;
    }

    $attention = 0;
    foreach ($outcomes as $value) {
      $outcome = NotificationOutcome::tryFrom((string) $value);
      // An unrecognised outcome counts as needing attention. A row this release cannot
      // read is exactly the kind of thing an operator should be told about, and
      // counting it as fine would hide it.
      if ($outcome === NULL || $outcome->needsAttention()) {
        $attention++;
      }
    }

    return $attention;
  }

  /**
   * Trims and shortens one stored string to what its column holds.
   */
  private function clip(string $value, int $length): string {
    $value = trim($value);

    return mb_strlen($value) > $length ? mb_substr($value, 0, $length) : $value;
  }

}
