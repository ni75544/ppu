<?php

namespace Drupal\custom_webform_handlers\Reservation;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\custom_webform_handlers\Dispatch\Admission\BookingAdmissionStoreInterface;
use Psr\Log\LoggerInterface;

/**
 * Resolves slot contention with a database unique key and nothing else.
 *
 * The whole locking strategy is one unique index on active_slot_key. Two
 * concurrent requests that derive the same Slot derive the same key, both
 * attempt the insert, and the database lets exactly one succeed. There is
 * deliberately no read-then-insert availability check anywhere in this class:
 * such a check can always be overtaken between the read and the write, and it
 * would turn a guarantee into a race. availability() exists only to render the
 * form, and its own docblock says so.
 *
 * A row keeps its active key while it is held or confirmed, and drops it to
 * NULL when it expires or is released. The unique index ignores NULLs, so the
 * table keeps full history while still admitting exactly one live claimant per
 * Slot.
 */
final class DatabaseReservationManager implements ReservationManagerInterface {

  /**
   * The table that owns every Hold and Reservation.
   */
  const TABLE = 'custom_webform_handlers_reservation';

  /**
   * A Slot claimed for a short window while a visitor completes the form.
   */
  const STATE_HELD = 'held';

  /**
   * A Slot claimed permanently by a completed submission.
   */
  const STATE_CONFIRMED = 'confirmed';

  /**
   * A Hold whose window ran out.
   */
  const STATE_EXPIRED = 'expired';

  /**
   * A Hold the owner gave up before completing the form.
   */
  const STATE_RELEASED = 'released';

  /**
   * A Slot an administrator took out of service.
   *
   * It competes for the active key exactly as a Hold or Reservation does, but it
   * belongs to nobody: there is no token to present and no session to own it.
   */
  const STATE_BLOCKED = 'blocked';

  /**
   * The columns an administrator may read.
   *
   * The active key and the two hashes are deliberately absent, so no admin query
   * can select a secret into a render array.
   */
  const RECORD_FIELDS = [
    'reservation_id',
    'webform_id',
    'resource_id',
    'slot_start',
    'slot_end',
    'timezone',
    'state',
    'submission_id',
    'expires_at',
    'created',
    'changed',
  ];

  /**
   * The largest number of local days one calendar read will answer for.
   */
  const CALENDAR_MAX_DAYS = 62;

  /**
   * The largest number of records an administrative list will ever return.
   */
  const ADMIN_LIMIT = 100;

  /**
   * Lowest admission generation this store will persist (Task 24C-7A).
   *
   * Enrolment begins at one; zero is reserved to mean "no generation", which the
   * NULL column already expresses, so a supplied zero is an invalid value rather
   * than a weaker synonym for unenrolled.
   */
  const ADMISSION_GENERATION_MIN = 1;

  /**
   * Highest admission generation the physical column can hold (Task 24C-7A).
   *
   * The column is int(10) unsigned, so this is its ceiling. Validating against it
   * turns a silent out-of-range truncation into a clear refusal.
   */
  const ADMISSION_GENERATION_MAX = 4294967295;

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
    // Booking admission store (Task 24C, Batch 1). Held, not called: this batch is
    // inert wiring, so the constructor only assigns it and no method here reads an
    // admission table. The first call is added later behind booking_admission.enforce,
    // which ships false, so an unmigrated site is unaffected by this injection.
    private readonly BookingAdmissionStoreInterface $bookingAdmissionStore,
  ) {}

  /**
   * Whether the admission_generation column exists, memoised per instance.
   *
   * NULL until first probed, kept on the instance and never in a cache bin that
   * could outlive this connection's schema. Fail-closed, so an unmigrated or
   * unreadable schema reads as "absent" and a generation-free legacy hold() stays
   * compatible with a schema that predates update_10049.
   */
  private ?bool $hasAdmissionGenerationColumn = NULL;

  /**
   * {@inheritdoc}
   */
  public function availability(ReservationDefinition $definition, \DateTimeImmutable $day): array {
    $timezone = $definition->timezoneObject();
    $start = (new \DateTimeImmutable($day->format('Y-m-d') . ' 00:00:00', $timezone));
    $end = $start->modify('+1 day');
    $now = $this->now();

    $query = $this->database->select(self::TABLE, 'r')
      ->fields('r', ['slot_start']);
    $query->condition('r.webform_id', $definition->webformId);
    $query->condition('r.resource_id', $definition->resourceId);
    $query->isNotNull('r.active_slot_key');
    $query->condition('r.slot_start', $start->getTimestamp(), '>=');
    $query->condition('r.slot_start', $end->getTimestamp(), '<');

    // A held row past its window is already free; it just has not been swept
    // yet. Reporting it as booked would hide a Slot that hold() would accept.
    // A blocked row never expires, so it reads as booked for as long as it
    // stands.
    $live = $query->orConditionGroup()
      ->condition('r.state', self::STATE_CONFIRMED)
      ->condition('r.state', self::STATE_BLOCKED)
      ->condition($query->andConditionGroup()
        ->condition('r.state', self::STATE_HELD)
        ->condition('r.expires_at', $now, '>'));
    $query->condition($live);

    $booked = [];
    foreach ($query->execute()->fetchCol() as $timestamp) {
      $booked[] = (new \DateTimeImmutable('@' . (int) $timestamp))
        ->setTimezone($timezone)
        ->format('H:i');
    }

    $booked = array_values(array_unique($booked));
    sort($booked);
    return $booked;
  }

  /**
   * {@inheritdoc}
   */
  public function calendar(ReservationDefinition $definition, \DateTimeImmutable $from, \DateTimeImmutable $to): array {
    $timezone = $definition->timezoneObject();
    // Normalise both ends to local midnight, so the range is whole local days
    // whatever time of day the caller happened to pass in.
    $first = new \DateTimeImmutable($from->format('Y-m-d') . ' 00:00:00', $timezone);
    $last = new \DateTimeImmutable($to->format('Y-m-d') . ' 00:00:00', $timezone);
    if ($last < $first) {
      return [];
    }

    // Bounded here as well as at the endpoint. A read model that can be asked
    // for an unbounded range is one query away from scanning the whole table.
    $ceiling = $first->modify('+' . (self::CALENDAR_MAX_DAYS - 1) . ' days');
    if ($last > $ceiling) {
      $last = $ceiling;
    }
    $endExclusive = $last->modify('+1 day');
    $now = $this->now();

    // One query for the whole range, then bucketed in PHP by the row's own
    // timezone. Grouping in SQL cannot do this: the local day and hour of a row
    // depend on the zone stored with it, which SQL here does not interpret.
    $query = $this->database->select(self::TABLE, 'r')
      ->fields('r', ['slot_start', 'state', 'timezone']);
    $query->condition('r.webform_id', $definition->webformId);
    $query->condition('r.resource_id', $definition->resourceId);
    $query->isNotNull('r.active_slot_key');
    $query->condition('r.slot_start', $first->getTimestamp(), '>=');
    $query->condition('r.slot_start', $endExclusive->getTimestamp(), '<');

    // Exactly the liveness rule availability() applies, so a day's counts and a
    // day's time options can never disagree about what is taken.
    $live = $query->orConditionGroup()
      ->condition('r.state', self::STATE_CONFIRMED)
      ->condition('r.state', self::STATE_BLOCKED)
      ->condition($query->andConditionGroup()
        ->condition('r.state', self::STATE_HELD)
        ->condition('r.expires_at', $now, '>'));
    $query->condition($live);

    $taken = [];
    foreach ($query->execute() as $row) {
      $local = (new \DateTimeImmutable('@' . (int) $row->slot_start))->setTimezone($timezone);
      $date = $local->format('Y-m-d');
      $hour = $local->format('H:i');
      $state = (string) $row->state;
      // One hour, one state. A confirmed row outranks a block, and a block
      // outranks a hold, so an hour is never counted twice.
      $current = $taken[$date][$hour] ?? '';
      if ($current === '' || $this->calendarRank($state) > $this->calendarRank($current)) {
        $taken[$date][$hour] = $state;
      }
    }

    $configured = array_values(array_unique($definition->timeOptions));
    $days = [];
    for ($day = $first; $day <= $last; $day = $day->modify('+1 day')) {
      $date = $day->format('Y-m-d');
      // A closed weekday offers nothing, so it has no free hours to report. It
      // is reported as closed rather than as booked, because nothing is holding
      // it and no administrator action would open it.
      $open = in_array((int) $day->format('N'), $definition->allowedWeekdays, TRUE);

      $counts = [
        self::STATE_CONFIRMED => 0,
        self::STATE_BLOCKED => 0,
        self::STATE_HELD => 0,
      ];
      if ($open) {
        foreach ($configured as $hour) {
          $state = $taken[$date][$hour] ?? '';
          if (isset($counts[$state])) {
            $counts[$state]++;
          }
        }
      }

      $configuredHours = $open ? count($configured) : 0;
      $unavailable = $counts[self::STATE_CONFIRMED]
        + $counts[self::STATE_BLOCKED]
        + $counts[self::STATE_HELD];
      $available = max(0, $configuredHours - $unavailable);

      $days[] = [
        'date' => $date,
        'weekday_open' => $open,
        'configured_hours' => $configuredHours,
        'confirmed_hours' => $counts[self::STATE_CONFIRMED],
        'held_hours' => $counts[self::STATE_HELD],
        'blocked_hours' => $counts[self::STATE_BLOCKED],
        'unavailable_hours' => $unavailable,
        'available_hours' => $available,
        'is_fully_booked' => $available === 0,
      ];
    }

    return $days;
  }

  /**
   * Orders the three live states, so one hour is counted under one of them.
   */
  private function calendarRank(string $state): int {
    return match ($state) {
      self::STATE_CONFIRMED => 3,
      self::STATE_BLOCKED => 2,
      self::STATE_HELD => 1,
      default => 0,
    };
  }

  /**
   * {@inheritdoc}
   */
  public function hold(ReservationSlot $slot, string $ownerId, ?int $admissionGeneration = NULL): HeldReservation {
    $now = $this->now();
    $token = bin2hex(random_bytes(32));
    $expires = $now + max(1, $slot->holdSeconds);

    // Task 24C-7A: validate any explicitly supplied admission generation before any
    // transaction opens. The interface (ReservationManagerInterface::hold) does not
    // declare this parameter; it is an optional implementation-only trailing
    // argument, which LSP permits, so no interface caller is affected and no business
    // caller supplies one. A non-NULL value therefore came from a caller that
    // deliberately passed it: an out-of-range value is a programming error and
    // throws, and an explicit generation on a schema that predates the column fails
    // clearly here rather than being silently dropped by the insert. Passive
    // transport only — nothing here consults or sets booking admission.
    $this->assertGenerationInRange($admissionGeneration);
    $present = $this->hasAdmissionGenerationColumn();
    if ($admissionGeneration !== NULL && !$present) {
      throw new \RuntimeException(sprintf(
        'Admission generation %d was supplied but the %s table has no admission_generation column; refusing to persist.',
        $admissionGeneration,
        self::TABLE,
      ));
    }

    $transaction = $this->database->startTransaction();
    try {
      // Sweep first, so a Slot whose Hold has lapsed is genuinely free before
      // the insert competes for its key.
      $this->expire($now);

      $fields = [
        'webform_id' => $slot->webformId,
        'resource_id' => $slot->resourceId,
        'slot_start' => $slot->startTimestamp,
        'slot_end' => $slot->endTimestamp,
        'timezone' => $slot->timezone,
        'state' => self::STATE_HELD,
        'active_slot_key' => $slot->activeSlotKey(),
        'hold_token_hash' => $this->hash($token),
        'owner_hash' => $this->hash($ownerId),
        'submission_id' => NULL,
        'expires_at' => $expires,
        'created' => $now,
        'changed' => $now,
      ];
      // Task 24C-7A: a new Hold enrols in no admission generation, so the column is
      // written only when a generation was explicitly supplied — which no business
      // caller does — and only when the migrated column exists. Omitting it defaults
      // the column to NULL (unenrolled).
      if ($present && $admissionGeneration !== NULL) {
        $fields['admission_generation'] = $admissionGeneration;
      }
      $this->database->insert(self::TABLE)
        ->fields($fields)
        ->execute();
    }
    catch (IntegrityConstraintViolationException $exception) {
      // Someone else owns this Slot's active key. This is the expected losing
      // path under concurrency, not an error worth alarming about.
      $transaction->rollBack();
      throw new ReservationException(ReservationException::SLOT_UNAVAILABLE, $exception);
    }

    return new HeldReservation($token, $expires, $slot);
  }

  /**
   * {@inheritdoc}
   */
  public function confirm(string $holdToken, int $submissionId): void {
    $now = $this->now();

    $updated = $this->database->update(self::TABLE)
      ->fields([
        'state' => self::STATE_CONFIRMED,
        'submission_id' => $submissionId,
        'expires_at' => NULL,
        'changed' => $now,
      ])
      ->condition('hold_token_hash', $this->hash($holdToken))
      ->condition('state', self::STATE_HELD)
      ->condition('expires_at', $now, '>')
      ->execute();

    if ((int) $updated > 0) {
      return;
    }

    // Zero rows can also mean this exact confirmation already succeeded: Webform
    // may call postSave() more than once for one submission, and a second call
    // must not read as a failed reservation. Anything else — an unknown token, a
    // lapsed Hold, or a row confirmed against a different submission — is a real
    // failure and stays one.
    if ($this->isConfirmedFor($holdToken, $submissionId)) {
      return;
    }

    throw new ReservationException(ReservationException::HOLD_NOT_FOUND);
  }

  /**
   * Tells whether this token already confirms this exact submission.
   */
  private function isConfirmedFor(string $holdToken, int $submissionId): bool {
    $query = $this->database->select(self::TABLE, 'r')
      ->fields('r', ['reservation_id'])
      ->condition('r.hold_token_hash', $this->hash($holdToken))
      ->condition('r.state', self::STATE_CONFIRMED)
      ->condition('r.submission_id', $submissionId)
      ->range(0, 1);

    return (bool) $query->execute()->fetchField();
  }

  /**
   * {@inheritdoc}
   */
  public function releaseExpired(int $now): int {
    return $this->expire($now);
  }

  /**
   * {@inheritdoc}
   */
  public function release(string $holdToken, string $ownerId): bool {
    $released = $this->database->update(self::TABLE)
      ->fields([
        'state' => self::STATE_RELEASED,
        'active_slot_key' => NULL,
        'hold_token_hash' => NULL,
        'owner_hash' => NULL,
        'expires_at' => NULL,
        'changed' => $this->now(),
      ])
      ->condition('hold_token_hash', $this->hash($holdToken))
      ->condition('owner_hash', $this->hash($ownerId))
      // Only a Hold. A confirmed Reservation and a blocked Slot are released by
      // the administrative path, never by a visitor.
      ->condition('state', self::STATE_HELD)
      ->execute();

    return (int) $released > 0;
  }

  /**
   * {@inheritdoc}
   */
  public function renewHold(ReservationSlot $slot, string $holdToken, string $ownerId): ?int {
    if ($holdToken === '' || $ownerId === '') {
      return NULL;
    }

    $now = $this->now();
    $expires = $now + max(1, $slot->holdSeconds);

    // One statement, and deliberately not a read followed by a write: the same
    // conditions that decide whether this session still holds the Slot are the
    // conditions the update matches on, so a Hold that lapses between a check
    // and a write cannot be extended.
    //
    // active_slot_key is matched but never assigned. That is what keeps the
    // locking semantics untouched: this cannot create a key, move one, or take
    // one from another row, so it cannot manufacture a claim on a Slot. The
    // unique index remains the only thing resolving contention.
    $renewed = $this->database->update(self::TABLE)
      ->fields([
        'expires_at' => $expires,
        'changed' => $now,
      ])
      ->condition('active_slot_key', $slot->activeSlotKey())
      ->condition('hold_token_hash', $this->hash($holdToken))
      ->condition('owner_hash', $this->hash($ownerId))
      // Only a Hold. A confirmed Reservation has no window to extend, and a
      // blocked Slot belongs to an administrator, not to a session.
      ->condition('state', self::STATE_HELD)
      // Strictly unexpired. A lapsed Hold is already free — expire() may not
      // have swept it yet, but another visitor's hold() would sweep it and take
      // the Slot, so extending it here would hand out the same Slot twice.
      ->condition('expires_at', $now, '>')
      ->execute();

    return (int) $renewed > 0 ? $expires : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function verifyHold(ReservationSlot $slot, string $holdToken, string $ownerId): bool {
    if ($holdToken === '') {
      return FALSE;
    }

    $query = $this->database->select(self::TABLE, 'r');
    $query->addExpression('1', 'found');
    $query->condition('r.active_slot_key', $slot->activeSlotKey());
    $query->condition('r.hold_token_hash', $this->hash($holdToken));
    $query->condition('r.owner_hash', $this->hash($ownerId));
    $query->condition('r.state', self::STATE_HELD);
    $query->condition('r.expires_at', $this->now(), '>');
    $query->range(0, 1);

    return (bool) $query->execute()->fetchField();
  }

  /**
   * {@inheritdoc}
   */
  public function adminList(array $filters, int $limit = self::ADMIN_LIMIT, int $offset = 0): array {
    $query = $this->database->select(self::TABLE, 'r')
      ->fields('r', self::RECORD_FIELDS);
    // Task 24C-7A: carry the admission generation into each admin record when the
    // migrated column exists; fromRow() keeps an absent or NULL column as NULL
    // (unenrolled). RECORD_FIELDS itself is unchanged, so no secret is widened.
    if ($this->hasAdmissionGenerationColumn()) {
      $query->addField('r', 'admission_generation');
    }

    $date = $this->applyAdminFilters($query, $filters);
    if ($date === NULL) {
      return [];
    }

    $limit = max(1, min($limit, self::ADMIN_LIMIT));
    $offset = max(0, $offset);
    $query->orderBy('r.slot_start', 'DESC');
    $query->orderBy('r.reservation_id', 'DESC');
    // The local-date filter can discard rows after the fact, so fetch the cap
    // and trim below rather than returning a short page. That same discarding
    // makes a SQL offset wrong for the dated case, because it would skip rows
    // that the timezone check was going to drop anyway; skip below instead.
    if ($date === '') {
      $query->range($offset, $limit);
    }
    else {
      $query->range(0, self::ADMIN_LIMIT);
    }

    $records = [];
    $skipped = 0;
    foreach ($query->execute() as $row) {
      $record = ReservationRecord::fromRow($row);
      if ($date !== '' && $record->localDate() !== $date) {
        continue;
      }
      // Only the dated branch still owes an offset; the plain branch already
      // applied it in SQL.
      if ($date !== '' && $skipped < $offset) {
        $skipped++;
        continue;
      }
      $records[] = $record;
      if (count($records) >= $limit) {
        break;
      }
    }

    return $records;
  }

  /**
   * {@inheritdoc}
   */
  public function adminCounts(array $filters): array {
    $counts = array_fill_keys([
      self::STATE_HELD,
      self::STATE_CONFIRMED,
      self::STATE_BLOCKED,
      self::STATE_RELEASED,
      self::STATE_EXPIRED,
    ], 0);
    $counts['total'] = 0;

    $query = $this->database->select(self::TABLE, 'r');
    $date = $this->applyAdminFilters($query, $filters);
    if ($date === NULL) {
      return $counts;
    }

    if ($date === '') {
      $query->addField('r', 'state');
      $query->addExpression('COUNT(*)', 'reservation_count');
      $query->groupBy('r.state');

      foreach ($query->execute() as $row) {
        $count = (int) $row->reservation_count;
        $state = (string) $row->state;
        $counts[$state] = ($counts[$state] ?? 0) + $count;
        $counts['total'] += $count;
      }

      return $counts;
    }

    // SQL can only bound the possible UTC interval. The stored row's timezone
    // determines whether it belongs to the requested local calendar day.
    $query->fields('r', self::RECORD_FIELDS);
    foreach ($query->execute() as $row) {
      $record = ReservationRecord::fromRow($row);
      if ($record->localDate() !== $date) {
        continue;
      }

      $counts[$record->state] = ($counts[$record->state] ?? 0) + 1;
      $counts['total']++;
    }

    return $counts;
  }

  /**
   * {@inheritdoc}
   */
  public function adminTotal(array $filters): int {
    $query = $this->database->select(self::TABLE, 'r');
    $date = $this->applyAdminFilters($query, $filters);
    if ($date === NULL) {
      return 0;
    }

    if ($date === '') {
      return (int) $query->countQuery()->execute()->fetchField();
    }

    // Keep the local-date semantics identical to adminList() and adminCounts().
    $query->fields('r', self::RECORD_FIELDS);
    $total = 0;
    foreach ($query->execute() as $row) {
      if (ReservationRecord::fromRow($row)->localDate() === $date) {
        $total++;
      }
    }

    return $total;
  }

  /**
   * {@inheritdoc}
   */
  public function adminRecord(int $reservationId): ?ReservationRecord {
    $query = $this->database->select(self::TABLE, 'r')
      ->fields('r', self::RECORD_FIELDS)
      ->condition('r.reservation_id', $reservationId)
      ->range(0, 1);
    // Task 24C-7A: carry the admission generation when the migrated column exists;
    // fromRow() keeps an absent or NULL column as NULL (unenrolled).
    if ($this->hasAdmissionGenerationColumn()) {
      $query->addField('r', 'admission_generation');
    }
    $row = $query->execute()->fetchObject();

    return $row ? ReservationRecord::fromRow($row) : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function block(ReservationSlot $slot): void {
    $now = $this->now();

    $transaction = $this->database->startTransaction();
    try {
      // Same order as hold(): a Slot whose Hold has lapsed is genuinely free
      // before this insert competes for its key.
      $this->expire($now);

      $this->database->insert(self::TABLE)
        ->fields([
          'webform_id' => $slot->webformId,
          'resource_id' => $slot->resourceId,
          'slot_start' => $slot->startTimestamp,
          'slot_end' => $slot->endTimestamp,
          'timezone' => $slot->timezone,
          'state' => self::STATE_BLOCKED,
          'active_slot_key' => $slot->activeSlotKey(),
          // A block belongs to nobody and never lapses: there is no token to
          // present, no session to own it, and no expiry to sweep.
          'hold_token_hash' => NULL,
          'owner_hash' => NULL,
          'submission_id' => NULL,
          'expires_at' => NULL,
          'created' => $now,
          'changed' => $now,
        ])
        ->execute();
    }
    catch (IntegrityConstraintViolationException $exception) {
      // The same unique key that stops two visitors also stops an administrator
      // from blocking a Slot somebody already claimed.
      $transaction->rollBack();
      throw new ReservationException(ReservationException::SLOT_UNAVAILABLE, $exception);
    }

    $this->logger->notice('Blocked reservation slot @start for webform @webform.', [
      '@start' => $slot->startTimestamp,
      '@webform' => $slot->webformId,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function releaseByAdministrator(int $reservationId): void {
    $now = $this->now();

    $transaction = $this->database->startTransaction();
    // A Hold whose window has closed is already free; it just has not been
    // swept. Sweeping first means such a row becomes 'expired' through the
    // normal path and is then reported as inactive, rather than being recorded
    // as an administrative release of something nobody held.
    $this->expire($now);

    $record = $this->adminRecord($reservationId);
    if (!$record) {
      throw new ReservationException(ReservationException::RESERVATION_NOT_FOUND);
    }

    $released = $this->database->update(self::TABLE)
      ->fields([
        'state' => self::STATE_RELEASED,
        // Dropping the active key is what actually returns the Slot to
        // availability; the state alone is only a label.
        'active_slot_key' => NULL,
        'hold_token_hash' => NULL,
        'owner_hash' => NULL,
        'expires_at' => NULL,
        'changed' => $now,
      ])
      ->condition('reservation_id', $reservationId)
      // Re-checked in the same statement, so a Hold that expired between the
      // read and the write cannot be reported as an administrative release.
      ->condition('state', [self::STATE_HELD, self::STATE_CONFIRMED, self::STATE_BLOCKED], 'IN')
      ->execute();

    if ((int) $released === 0) {
      $transaction->rollBack();
      throw new ReservationException(ReservationException::RESERVATION_NOT_ACTIVE);
    }

    $this->logger->notice('Released reservation @id from state @state by administrator.', [
      '@id' => $reservationId,
      '@state' => $record->state,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function activeStateForSlot(ReservationSlot $slot): ?string {
    // Expire first, so a lapsed Hold is not reported as an owner of the Slot.
    $this->expire($this->now());

    $state = $this->database->select(self::TABLE, 'r')
      ->fields('r', ['state'])
      ->condition('r.active_slot_key', $slot->activeSlotKey())
      ->range(0, 1)
      ->execute()
      ->fetchField();

    return $state === FALSE || $state === NULL ? NULL : (string) $state;
  }

  /**
   * Frees the active key of every Hold whose window has closed.
   */
  private function expire(int $now): int {
    $expired = $this->database->update(self::TABLE)
      ->fields([
        'state' => self::STATE_EXPIRED,
        'active_slot_key' => NULL,
        'hold_token_hash' => NULL,
        // The owner hash has no purpose once the Hold is gone, and keeping it
        // would leave a session fingerprint on a historical row forever.
        'owner_hash' => NULL,
        'changed' => $now,
      ])
      ->condition('state', self::STATE_HELD)
      ->condition('expires_at', $now, '<=')
      ->execute();

    $expired = (int) $expired;
    if ($expired > 0) {
      $this->logger->info('Expired @count reservation hold(s).', ['@count' => $expired]);
    }
    return $expired;
  }

  /**
   * Hashes a secret so the table never stores a replayable value.
   */
  private function hash(string $value): string {
    return hash('sha256', $value);
  }

  /**
   * Trims one administrative filter, or reports it as unusable.
   *
   * A value longer than its column can never match a stored row. Returning an
   * empty string here would read as "no filter given" and quietly widen a
   * malformed request to every record, so an over-long value is rejected
   * outright instead.
   *
   * @return string|null
   *   The trimmed value, or NULL when it cannot match anything.
   */
  private function boundedFilter(mixed $value, int $maxLength): ?string {
    $value = trim((string) $value);
    return strlen($value) > $maxLength ? NULL : $value;
  }

  /**
   * Applies the shared administrative filters to a query on the reservation
   * table, aliased as 'r'.
   *
   * adminList(), adminCounts(), and adminTotal() must agree about what a filter
   * set matches, so the interpretation lives here once rather than in three
   * places that could drift apart.
   *
   * @return string|null
   *   The validated local 'date' filter ('' when absent), or NULL when any
   *   filter is unusable and the caller should match nothing at all.
   */
  private function applyAdminFilters($query, array $filters): ?string {
    $state = $this->boundedFilter($filters['state'] ?? '', 16);
    $webformId = $this->boundedFilter($filters['webform_id'] ?? '', 128);
    $resourceId = $this->boundedFilter($filters['resource_id'] ?? '', 128);

    // An unusable filter matches nothing. Dropping it instead would turn a
    // malformed request into a list of every reservation on the site.
    if ($state === NULL || $webformId === NULL || $resourceId === NULL) {
      return NULL;
    }

    if ($state !== '') {
      // An unknown-but-short state simply matches no rows, which is the honest
      // answer for a state the table does not use.
      $query->condition('r.state', $state);
    }

    if ($webformId !== '') {
      $query->condition('r.webform_id', $webformId);
    }

    if ($resourceId !== '') {
      $query->condition('r.resource_id', $resourceId);
    }

    $date = trim((string) ($filters['date'] ?? ''));
    if ($date !== '') {
      if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return NULL;
      }
      // Rows may be stored under different timezones, and a single UTC window
      // cannot express "this local day" for all of them. Bound the query to the
      // widest possible span, then keep only the rows whose own timezone agrees.
      $query->condition('r.slot_start', strtotime($date . ' 00:00:00 UTC') - 86400, '>=');
      $query->condition('r.slot_start', strtotime($date . ' 00:00:00 UTC') + (2 * 86400), '<');
    }

    return $date;
  }

  private function now(): int {
    return (int) $this->time->getRequestTime();
  }

  /**
   * Whether the reservation table carries the admission_generation column.
   *
   * Probed once with fieldExists() and memoised on the instance. Fail-closed: any
   * schema error is read as "absent", so an unmigrated or unreadable schema makes
   * this store omit the column on write and skip it on read rather than guess. This
   * is the only thing that lets a generation-free hold() call stay compatible with
   * a schema that predates update_10049.
   */
  private function hasAdmissionGenerationColumn(): bool {
    if ($this->hasAdmissionGenerationColumn === NULL) {
      try {
        $this->hasAdmissionGenerationColumn = $this->database->schema()
          ->fieldExists(self::TABLE, 'admission_generation');
      }
      catch (\Exception) {
        $this->hasAdmissionGenerationColumn = FALSE;
      }
    }

    return $this->hasAdmissionGenerationColumn;
  }

  /**
   * Rejects an admission generation that the column could not faithfully hold.
   *
   * NULL is always allowed: it is how an unenrolled row is expressed. A supplied
   * value must be a positive integer within the unsigned-column range; zero and
   * negatives are invalid rather than weaker synonyms for unenrolled, and a value
   * above the ceiling would be truncated by the column. Validates the value only —
   * it neither reads nor writes admission state and authorises nothing.
   */
  private function assertGenerationInRange(?int $generation): void {
    if ($generation === NULL) {
      return;
    }
    if ($generation < self::ADMISSION_GENERATION_MIN || $generation > self::ADMISSION_GENERATION_MAX) {
      throw new \InvalidArgumentException(sprintf(
        'Admission generation %d is out of range (%d-%d).',
        $generation,
        self::ADMISSION_GENERATION_MIN,
        self::ADMISSION_GENERATION_MAX,
      ));
    }
  }

}
