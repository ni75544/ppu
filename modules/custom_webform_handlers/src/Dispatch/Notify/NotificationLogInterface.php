<?php

namespace Drupal\custom_webform_handlers\Dispatch\Notify;

/**
 * The append-only history of notification attempts.
 *
 * Append-only for the same reason the Resource Status log is: the question later is
 * not only whether the customer was told but what the system tried and when. A retry
 * adds a row; it does not overwrite the failure it is retrying.
 *
 * No lock, and no participation in any capacity transaction. Every write here happens
 * on a queue worker, long after the planner committed, and every read happens on a
 * board that holds no lock either.
 *
 * Nothing on this seam accepts or returns a recipient. See NotificationRecord: the
 * address is resolved at send time, used, and dropped, and the log records that an
 * audience was written to rather than who.
 */
interface NotificationLogInterface {

  /**
   * Appends one attempt.
   *
   * Never throws. The caller is a queue worker that has already sent, or failed to
   * send, a real message; a history write that threw would turn a delivered
   * notification into a requeued one and mail the customer twice.
   *
   * @param \Drupal\custom_webform_handlers\Dispatch\Notify\DispatchNotification $notification
   *   The fact that was being told.
   * @param \Drupal\custom_webform_handlers\Dispatch\Notify\DispatchAudience $audience
   *   Who it was written for.
   * @param \Drupal\custom_webform_handlers\Dispatch\Notify\NotificationOutcome $outcome
   *   What became of it.
   * @param string $channel
   *   How it was carried. See NotificationRecord::CHANNEL_MAIL.
   * @param string $detail
   *   Operator-safe reason or failure code. Truncated rather than refused.
   */
  public function record(
    DispatchNotification $notification,
    DispatchAudience $audience,
    NotificationOutcome $outcome,
    string $channel,
    string $detail,
  ): void;

  /**
   * The newest attempts for one Resource, most recent first.
   *
   * @param string $resourceId
   *   The Resource.
   * @param int $limit
   *   How many rows at most. Bounded by the implementation.
   *
   * @return \Drupal\custom_webform_handlers\Dispatch\Notify\NotificationRecord[]
   *   Records, newest first.
   */
  public function recent(string $resourceId, int $limit): array;

  /**
   * How many of the newest attempts for one Resource need an operator's attention.
   *
   * A count rather than a list, because the board's summary line wants a number and
   * pulling rows to count them would render a table nobody asked for. Bounded by the
   * same ceiling as self::recent(), so this is "failures among the recent history" and
   * not "failures ever" — an install that has been sending mail for two years should
   * not have to read its whole log to draw one heading.
   */
  public function attentionCount(string $resourceId, int $limit): int;

}
