<?php

namespace Drupal\custom_webform_handlers\Reservation;

use Drupal\Core\Database\Connection;

/**
 * Finds completed submissions that claim a Slot no reservation protects.
 *
 * Submissions completed while the reservation workflow was off carry a chosen
 * date and time but no Hold, so nothing in the reservation table stands between
 * them and the next visitor who picks the same Slot. This class only *reports*
 * them: it creates nothing, confirms nothing, and picks no winner when several
 * submissions claim one Slot. Every decision stays with an administrator.
 *
 * Canonicalisation is not reimplemented here. A candidate's Slot is derived by
 * ReservationDefinitionProvider exactly as a live booking would derive it, which
 * also supplies the eligibility rule for free: if the provider refuses the date
 * and time — a past day, a closed weekday, a time the form no longer offers —
 * then no visitor can book that Slot either, so there is nothing to protect.
 */
final class ReservationReconciliation {

  /**
   * The largest number of candidates one screen will consider.
   */
  const CANDIDATE_LIMIT = 100;

  /**
   * A Slot no active reservation covers: still bookable by anyone.
   */
  const STATUS_UNPROTECTED = 'unprotected';

  /**
   * A Slot an active reservation already covers.
   */
  const STATUS_PROTECTED = 'protected';

  public function __construct(
    private readonly Connection $database,
    private readonly ReservationDefinitionProvider $definitionProvider,
    private readonly ReservationManagerInterface $reservationManager,
  ) {}

  /**
   * Returns the unprotected historical claims on one Webform, newest first.
   *
   * @return array[]
   *   Each candidate carries only safe values: 'sid', 'changed', 'date',
   *   'time', 'timezone', 'resource', 'status', 'state' (the owning
   *   reservation's state when protected), 'conflict_sids', and the resolved
   *   ReservationSlot under 'slot' for the confirm form to re-derive against.
   */
  public function candidates(string $webformId, int $limit = self::CANDIDATE_LIMIT): array {
    try {
      $definition = $this->definitionProvider->definition($webformId);
    }
    catch (ReservationException) {
      // Reservations are not configured, so nothing can be reconciled.
      return [];
    }

    $fields = $this->definitionProvider->fieldNames($webformId);
    $dateField = (string) ($fields['date'] ?? '');
    $timeField = (string) ($fields['time'] ?? '');
    if ($dateField === '' || $timeField === '') {
      return [];
    }

    $limit = max(1, min($limit, self::CANDIDATE_LIMIT));
    $candidates = [];
    $bySlot = [];

    foreach ($this->rows($webformId, $dateField, $timeField, $limit) as $row) {
      try {
        $slot = $this->definitionProvider->slot($definition, (string) $row->reservation_date, (string) $row->reservation_time);
      }
      catch (ReservationException) {
        // Not a Slot a visitor could book, so it cannot be double-booked and
        // needs no protection. Deliberately skipped rather than listed as an
        // action an administrator cannot usefully take.
        continue;
      }

      $state = $this->reservationManager->activeStateForSlot($slot);
      $key = $slot->activeSlotKey();

      $candidate = [
        'sid' => (int) $row->sid,
        'changed' => (int) $row->changed,
        'date' => $slot->localDate(),
        'time' => $slot->localTime(),
        'timezone' => $definition->timezone,
        'resource' => $definition->resourceId,
        'status' => $state === NULL ? self::STATUS_UNPROTECTED : self::STATUS_PROTECTED,
        'state' => $state,
        'slot' => $slot,
        'conflict_sids' => [],
      ];

      $candidates[] = $candidate;
      $bySlot[$key][] = (int) $row->sid;
    }

    // A Slot claimed by more than one historical submission is a conflict an
    // administrator has to settle: protecting the Slot stops future visitors,
    // but it does not decide which of these submissions owns it.
    foreach ($candidates as $index => $candidate) {
      $key = $candidate['slot']->activeSlotKey();
      $sids = $bySlot[$key] ?? [];
      if (count($sids) > 1) {
        $candidates[$index]['conflict_sids'] = array_values(array_diff($sids, [$candidate['sid']]));
      }
    }

    return $candidates;
  }

  /**
   * Returns one candidate by submission ID, or NULL when it is not eligible.
   *
   * The confirm form re-derives through this, so the action can never act on a
   * Slot that a stale listing showed.
   */
  public function candidate(string $webformId, int $sid): ?array {
    foreach ($this->candidates($webformId) as $candidate) {
      if ($candidate['sid'] === $sid) {
        return $candidate;
      }
    }
    return NULL;
  }

  /**
   * Reads the completed submissions that selected both a date and a time.
   *
   * Only the two configured element values are read. No other submission data
   * is touched, so nothing unrelated can reach an administrative screen.
   */
  private function rows(string $webformId, string $dateField, string $timeField, int $limit): iterable {
    $query = $this->database->select('webform_submission', 's');
    $query->fields('s', ['sid', 'changed']);

    $date = $query->join('webform_submission_data', 'd', 'd.sid = s.sid AND d.webform_id = s.webform_id AND d.name = :date_name', [
      ':date_name' => $dateField,
    ]);
    $time = $query->join('webform_submission_data', 't', 't.sid = s.sid AND t.webform_id = s.webform_id AND t.name = :time_name', [
      ':time_name' => $timeField,
    ]);

    $query->addField($date, 'value', 'reservation_date');
    $query->addField($time, 'value', 'reservation_time');
    $query->condition('s.webform_id', $webformId);
    $query->condition('s.in_draft', 0);
    $query->isNotNull('s.completed');
    $query->condition($date . '.value', '', '<>');
    $query->condition($time . '.value', '', '<>');
    // A submission that already produced a reservation is not a candidate.
    $query->notExists(
      $this->database->select(DatabaseReservationManager::TABLE, 'r')
        ->fields('r', ['reservation_id'])
        ->where('r.submission_id = s.sid')
    );
    $query->orderBy('s.sid', 'DESC');
    $query->range(0, $limit);

    return $query->execute();
  }

}
