<?php

namespace Drupal\custom_webform_handlers\Dispatch\Status;

/**
 * The append-only log of reported Resource states.
 *
 * Append-only on purpose, and the interface says so rather than leaving it to the
 * implementation: there is no update and no delete here, because the question later
 * is not only where the vehicle is but what was reported and when. A correction is
 * a new record that supersedes the last one by being newer, which is also how a
 * board can show that a driver reported themselves unavailable and then available
 * again twenty minutes later.
 *
 * Nothing on this seam takes a lock or touches the Resource Calendar Guard. A
 * status is not capacity: it cannot conflict with a Reservation, so it needs no
 * serialisation against one, and giving it one would put a write in the path of a
 * read that the board performs on every request.
 */
interface ResourceStatusStoreInterface {

  /**
   * Appends one observation and returns it as stored.
   *
   * @param string $resourceId
   *   The Resource being reported on.
   * @param \Drupal\custom_webform_handlers\Dispatch\Status\ResourceStatusState $state
   *   What is being reported.
   * @param int|null $dispatchReservationId
   *   The Reservation it is about, or NULL.
   * @param string $note
   *   Operator-facing free text. Truncated rather than refused.
   * @param int $actorUid
   *   The asserting account.
   * @param string $actorName
   *   That account's name now.
   *
   * @return \Drupal\custom_webform_handlers\Dispatch\Status\ResourceStatus|null
   *   The written record, or NULL when the write could not be performed.
   */
  public function record(
    string $resourceId,
    ResourceStatusState $state,
    ?int $dispatchReservationId,
    string $note,
    int $actorUid,
    string $actorName,
  ): ?ResourceStatus;

  /**
   * The most recent observation for one Resource, or NULL when there is none.
   *
   * NULL means nobody has reported anything, which is not the same as Available:
   * an unreported Resource is unknown, and a board says so rather than asserting
   * that a vehicle nobody has heard from is fine.
   */
  public function current(string $resourceId): ?ResourceStatus;

  /**
   * The newest observations for one Resource, most recent first.
   *
   * @param string $resourceId
   *   The Resource.
   * @param int $limit
   *   How many rows at most. Bounded by the implementation.
   *
   * @return \Drupal\custom_webform_handlers\Dispatch\Status\ResourceStatus[]
   *   Records, newest first.
   */
  public function recent(string $resourceId, int $limit): array;

}
