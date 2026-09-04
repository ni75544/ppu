<?php

namespace Drupal\custom_webform_handlers\Reservation;

/**
 * The only reservation seam the controller and the Webform handler may use.
 *
 * Implementations own every state transition and all SQL. Callers never see a
 * row, a column, or an owner hash: they pass a server-derived Slot and receive
 * either a Hold or a classified ReservationException. Contention is resolved by
 * a database unique key inside the implementation, never by a read-then-insert
 * check, a cache entry, or the lock service.
 */
interface ReservationManagerInterface {

  /**
   * Returns the canonical HH:MM values that are already taken on one day.
   *
   * This is a read model for the form. It is never the authority that resolves
   * contention: a value absent here can still lose the race in hold().
   *
   * @return string[]
   *   Canonical HH:MM values, in the definition's timezone.
   */
  public function availability(ReservationDefinition $definition, \DateTimeImmutable $day): array;

  /**
   * Summarises capacity per local day across a bounded range.
   *
   * A month grid needs one answer per day, not one request per day. This is the
   * same read model as availability(), widened to a range and reduced to counts:
   * a confirmed, blocked, or unexpired held hour is unavailable, and everything
   * else the schedule offers that day is free.
   *
   * It is a read model, not an authority — a day reported with free hours can
   * still lose the race in hold(). It carries counts only: no token, owner,
   * hash, submission, or reservation identifier, so the same array is safe to
   * publish to a visitor and to render for an administrator.
   *
   * Implementations must bound the range they will answer for.
   *
   * @param \DateTimeImmutable $from
   *   First local day, inclusive.
   * @param \DateTimeImmutable $to
   *   Last local day, inclusive.
   *
   * @return array[]
   *   One entry per day, in ascending date order, each carrying 'date',
   *   'weekday_open', 'configured_hours', 'confirmed_hours', 'held_hours',
   *   'blocked_hours', 'unavailable_hours', 'available_hours', and
   *   'is_fully_booked'.
   */
  public function calendar(ReservationDefinition $definition, \DateTimeImmutable $from, \DateTimeImmutable $to): array;

  /**
   * Claims one Slot for a short window, or fails closed.
   *
   * @throws \Drupal\custom_webform_handlers\Reservation\ReservationException
   *   With code slot_unavailable when another Hold or Reservation owns the
   *   Slot's active key.
   */
  public function hold(ReservationSlot $slot, string $ownerId): HeldReservation;

  /**
   * Turns a still-active Hold into a Reservation linked to a submission.
   *
   * The active key is retained, so the Slot stays unavailable.
   *
   * @throws \Drupal\custom_webform_handlers\Reservation\ReservationException
   *   With code hold_not_found when the Hold is unknown or already expired.
   */
  public function confirm(string $holdToken, int $submissionId): void;

  /**
   * Expires every Hold that has run out, freeing each active key.
   *
   * @return int
   *   The number of Holds expired.
   */
  public function releaseExpired(int $now): int;

  /**
   * Releases one Hold that this owner still holds.
   *
   * A confirmed Reservation is never released here: that requires the separate
   * administrative cancellation feature.
   *
   * @return bool
   *   TRUE when a Hold owned by this owner was released.
   */
  public function release(string $holdToken, string $ownerId): bool;

  /**
   * Extends the window of a Hold this owner still holds.
   *
   * A Hold is short by design, but the form it protects is not: a visitor who is
   * still filling in the page that the Hold exists for must not lose the Slot
   * underneath them. This keeps the same row, the same token, and above all the
   * same active key, so nothing about contention changes — it moves the expiry
   * forward and nothing else.
   *
   * A Hold whose window has already closed is never renewed. Its Slot became
   * free the moment it lapsed and another visitor may already have taken it, so
   * resurrecting the claim here would sell one Slot twice. The same applies to a
   * released, expired, or confirmed row, and to a Hold belonging to any other
   * session.
   *
   * @return int|null
   *   The new expiry timestamp, or NULL when nothing was renewed, which callers
   *   must treat as "this session no longer holds this Slot".
   */
  public function renewHold(ReservationSlot $slot, string $holdToken, string $ownerId): ?int;

  /**
   * Tells whether this owner still holds this exact Slot.
   *
   * Used at final submission, so a completed form cannot be accepted against a
   * Hold that expired, was released, or belongs to another session.
   */
  public function verifyHold(ReservationSlot $slot, string $holdToken, string $ownerId): bool;

  /**
   * Lists reservation records for an administrator, newest first.
   *
   * @param array $filters
   *   Any of 'state', 'webform_id', 'resource_id', and a local 'date' in Y-m-d.
   *   Each value is bounded by the implementation; an unusable filter matches
   *   nothing rather than being ignored.
   * @param int $limit
   *   The largest number of records to return, capped by the implementation.
   * @param int $offset
   *   How many matching records to skip, for paging.
   *
   * @return \Drupal\custom_webform_handlers\Reservation\ReservationRecord[]
   *   Records carrying no secret column.
   */
  public function adminList(array $filters, int $limit = 100, int $offset = 0): array;

  /**
   * Returns one reservation record, or NULL when it does not exist.
   */
  public function adminRecord(int $reservationId): ?ReservationRecord;

  /**
   * Takes one Slot out of service without a visitor or a submission.
   *
   * A blocked Slot holds its active key indefinitely, so it competes for that
   * key exactly as a Hold or a Reservation does.
   *
   * @throws \Drupal\custom_webform_handlers\Reservation\ReservationException
   *   With code slot_unavailable when the Slot already has an active claimant.
   */
  public function block(ReservationSlot $slot): void;

  /**
   * Returns one held, confirmed, or blocked Slot to availability.
   *
   * This is the deliberate administrative counterpart to the visitor-facing
   * release, and the only path that can free a confirmed Reservation.
   *
   * @throws \Drupal\custom_webform_handlers\Reservation\ReservationException
   *   With code reservation_not_found when no such record exists, or
   *   reservation_not_active when it is already released or expired.
   */
  public function releaseByAdministrator(int $reservationId): void;

  /**
   * Returns the state of the row that currently owns this Slot's active key.
   *
   * A read model for administrative screens that need to know whether a
   * canonical Slot is already spoken for before offering an action on it. It is
   * never the authority that resolves contention — block() and hold() still
   * compete for the unique key.
   *
   * @return string|null
   *   The owning row's state, or NULL when the Slot has no active claimant.
   */
  public function activeStateForSlot(ReservationSlot $slot): ?string;

  /**
   * Counts reservations per state under the same filters as adminList().
   *
   * @return array
   *   State machine name => count, plus a 'total' key. States with no rows are
   *   present with a zero count, so a dashboard can show the full picture rather
   *   than only what happens to exist.
   */
  public function adminCounts(array $filters): array;

  /**
   * Counts the reservations one filter set matches, for paging.
   */
  public function adminTotal(array $filters): int;

}
