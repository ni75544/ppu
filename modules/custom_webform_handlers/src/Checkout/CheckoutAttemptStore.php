<?php

namespace Drupal\custom_webform_handlers\Checkout;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\custom_webform_handlers\Dispatch\Slot;
use Psr\Log\LoggerInterface;

/**
 * Durable storage for the private Checkout Attempt lifecycle.
 *
 * A value object alone is not a lifecycle. Payment begins on one request and is
 * asserted on another — by a gateway callback, by a customer returning, possibly
 * by both, possibly twice — and nothing that lives only for a request can tell a
 * repeat from a new booking. This is the row that can.
 *
 * It is a narrow table rather than fields on the Commerce order, and the reason is
 * specific: the invariants this lifecycle needs are uniqueness invariants, and the
 * Field API cannot express one. Commerce owns the order, its state machine, its
 * mail, its billing profile, and its payments, and all of that is reused as it
 * stands. What Commerce cannot do is refuse a second attempt on the same order, or
 * a second attempt on the same intent, at the database level — and a check in PHP
 * is not the same guarantee when two payment notifications arrive together.
 *
 * So there are three unique keys here, and they are the whole justification for
 * the table: one attempt per order, one attempt per intent, and one attempt per
 * Dispatch row. Everything else in it is a snapshot the lifecycle must be able to
 * read back without asking another system what it used to say.
 *
 * The store writes no contact detail, no request payload, and no secret. It never
 * touches the Dispatch reservation table: that belongs to the Resource Calendar
 * Guard, and a second writer there would be a second authority on capacity.
 */
final class CheckoutAttemptStore {

  /**
   * The private lifecycle table. Never joined to from a public response.
   */
  const TABLE = 'custom_webform_handlers_checkout_attempt';

  /**
   * Every column an attempt is rebuilt from. There is no secret among them.
   */
  const FIELDS = [
    'attempt_id',
    'order_id',
    'webform_id',
    'submission_id',
    'resource_id',
    'pickup_arrival',
    'timezone',
    'quote_snapshot_id',
    'quote_amount_cents',
    'quote_currency',
    'quote_rate_version',
    'quote_route_source',
    'quote_route_fingerprint',
    'quote_route_calculated_at',
    'quote_route_meters',
    'quote_route_seconds',
    'quote_line_items',
    'quote_pair_fingerprint',
    'quote_payload_schema_version',
    'quote_payload_digest',
    'quote_job_facts_digest',
    'quote_job_facts',
    'state',
    'idempotency_key',
    'dispatch_reservation_id',
    'created',
    'changed',
    'settled',
  ];

  /**
   * The eight columns update_10047 adds for the Task 24B price snapshot.
   *
   * Kept as its own list so the schema probe and the legacy read-set both derive
   * from one place. These are a strict subset of FIELDS; the legacy column set a
   * pre-24A schema is read through is FIELDS minus exactly these.
   */
  private const SNAPSHOT_COLUMNS = [
    'quote_route_meters',
    'quote_route_seconds',
    'quote_line_items',
    'quote_pair_fingerprint',
    'quote_payload_schema_version',
    'quote_payload_digest',
    'quote_job_facts_digest',
    'quote_job_facts',
  ];

  /**
   * Snapshot-schema states, decided by how many SNAPSHOT_COLUMNS the table has.
   *
   * COMPLETE (all eight) is the only state that opens a new attempt. ABSENT (none)
   * is a genuine pre-24A schema, read as a legacy figure. PARTIAL (some) and
   * UNKNOWN (no table, or the schema could not be inspected) are fail-closed:
   * missing data is never treated as proof of legacy status.
   */
  private const SNAPSHOT_SCHEMA_COMPLETE = 'complete';
  private const SNAPSHOT_SCHEMA_ABSENT = 'absent';
  private const SNAPSHOT_SCHEMA_PARTIAL = 'partial';
  private const SNAPSHOT_SCHEMA_UNKNOWN = 'unknown';

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
  ) {}

  /**
   * Memoized snapshot-schema state for this store instance.
   *
   * A CheckoutAttemptStore is constructed once per request with its injected
   * Connection, and the table's shape cannot change within a request without a
   * migration this class never performs. So the probe runs at most once and is
   * remembered on the instance — never in a static or a cache bin, which would
   * outlive the connection whose schema it describes.
   */
  private ?string $snapshotSchemaState = NULL;

  /**
   * Whether the admission_generation column exists, memoised per instance.
   *
   * Independent of the snapshot-schema probe above: a site can carry the Task 24B
   * snapshot columns (update_10047) yet predate the admission column (update_10049),
   * so each is proven on its own. NULL until first probed, kept on the instance and
   * never in a cache bin that could outlive this connection's schema.
   */
  private ?bool $hasAdmissionGenerationColumn = NULL;

  /**
   * Reports whether the table carries the Task 24B snapshot columns.
   *
   * Logged once per request when the answer is anything but COMPLETE, because the
   * result is memoized: a legacy table is a warning, a partial or uninspectable
   * one an error, and the callers below turn each into a refusal rather than an
   * uncaught schema exception.
   */
  private function snapshotSchemaState(): string {
    if ($this->snapshotSchemaState !== NULL) {
      return $this->snapshotSchemaState;
    }

    $state = $this->determineSnapshotSchemaState();
    if ($state === self::SNAPSHOT_SCHEMA_ABSENT) {
      $this->logger->warning('Checkout attempt table is at a pre-24A schema: the eight Task 24B snapshot columns are absent. New attempts are refused until update_10047 runs; existing rows are read as legacy figures.');
    }
    elseif ($state !== self::SNAPSHOT_SCHEMA_COMPLETE) {
      $this->logger->error('Checkout attempt table snapshot schema is "@state", not complete: update_10047 is unapplied or partial, or the schema could not be inspected. New attempts are refused and rows are not read.', [
        '@state' => $state,
      ]);
    }

    return $this->snapshotSchemaState = $state;
  }

  /**
   * Counts the snapshot columns and maps the count to a state.
   *
   * A missing table or any inspection failure is UNKNOWN, not ABSENT: we did not
   * prove the columns are gone, only that we could not confirm they are present.
   */
  private function determineSnapshotSchemaState(): string {
    try {
      $schema = $this->database->schema();
      if (!$schema->tableExists(self::TABLE)) {
        return self::SNAPSHOT_SCHEMA_UNKNOWN;
      }
      $present = 0;
      foreach (self::SNAPSHOT_COLUMNS as $column) {
        if ($schema->fieldExists(self::TABLE, $column)) {
          $present++;
        }
      }
    }
    catch (\Exception) {
      return self::SNAPSHOT_SCHEMA_UNKNOWN;
    }

    if ($present === 0) {
      return self::SNAPSHOT_SCHEMA_ABSENT;
    }
    if ($present === count(self::SNAPSHOT_COLUMNS)) {
      return self::SNAPSHOT_SCHEMA_COMPLETE;
    }
    return self::SNAPSHOT_SCHEMA_PARTIAL;
  }

  /**
   * Whether the checkout-attempt table carries the admission_generation column.
   *
   * Probed once with fieldExists() and memoised on the instance. Fail-closed: any
   * schema error is read as "absent", so an unmigrated or unreadable schema makes
   * the store omit the column on write and skip it on read rather than guess. This
   * is separate from the snapshot-schema probe because the two migrations are
   * independent, and it is the only thing that lets a generation-free call stay
   * compatible with a schema that predates update_10049.
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
   * NULL is always allowed: it is how an unenrolled attempt is expressed. A supplied
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

  /**
   * The columns a reader may SELECT, or NULL when it must not read at all.
   *
   * COMPLETE reads every column. ABSENT reads the legacy set — FIELDS minus the
   * eight snapshot columns — and hydrate() resolves the eight it cannot see to
   * NULL, which fromStored() treats as a legacy figure. PARTIAL and UNKNOWN read
   * nothing: a half-migrated table still holds some integrity columns, and
   * dropping them to read the row as legacy would discard the very facts Task 24B
   * added, so a reader fails closed instead.
   */
  private function readableFields(): ?array {
    return match ($this->snapshotSchemaState()) {
      self::SNAPSHOT_SCHEMA_COMPLETE => self::FIELDS,
      self::SNAPSHOT_SCHEMA_ABSENT => array_values(array_diff(self::FIELDS, self::SNAPSHOT_COLUMNS)),
      default => NULL,
    };
  }

  /**
   * Writes a pending attempt, or returns the one that already means this.
   *
   * The collision is the feature. Two payment submissions for the same job at the
   * same arrival for the same price derive the same idempotency key, so the second
   * insert violates a unique index and this returns the first attempt instead of
   * opening a second order. The caller therefore does not have to hold a lock
   * across "look for one" and "make one", which is the window a double-clicked
   * button lives in.
   *
   * NULL means the attempt was neither written nor found, which the caller must
   * treat as a refused checkout: an order with no attempt row cannot be confirmed
   * and must not be left looking payable.
   */
  public function open(CheckoutAttempt $attempt): ?CheckoutAttempt {
    if ($attempt->orderId < 1 || $attempt->submissionId < 1) {
      return NULL;
    }

    // Task 24C-7A: validate any explicitly supplied admission generation as an input,
    // up front. No business caller sets one, so a non-NULL value here came from a
    // caller that deliberately did; an out-of-range value is a programming error and
    // throws before anything is written. Passive transport only — this neither
    // consults nor sets booking admission.
    $this->assertGenerationInRange($attempt->admissionGeneration);

    // The insert below writes all eight Task 24B snapshot columns unconditionally.
    // If update_10047 has not run (ABSENT), has run only partway (PARTIAL), or the
    // schema could not be inspected (UNKNOWN), that insert throws an uncaught schema
    // exception — so refuse first. recordAttempt() reverses the Hold and draft order
    // this call would have recorded against when it gets NULL, so the checkout fails
    // closed with no partial write. This averts a missing-column crash; it does not
    // restore checkout availability, which only applying the migration can.
    if ($this->snapshotSchemaState() !== self::SNAPSHOT_SCHEMA_COMPLETE) {
      $this->logger->error('Checkout attempt for order @order was not opened: the snapshot schema is not complete.', [
        '@order' => $attempt->orderId,
      ]);
      return NULL;
    }

    // Task 24C-7A: the admission_generation column (update_10049) is independent of
    // the snapshot columns gated above — a site can be snapshot-COMPLETE yet predate
    // 10049 — so probe it on its own. An explicit generation on a schema without the
    // column fails clearly here rather than being dropped by the insert below.
    $present = $this->hasAdmissionGenerationColumn();
    if ($attempt->admissionGeneration !== NULL && !$present) {
      throw new \RuntimeException(sprintf(
        'Admission generation %d was supplied but the %s table has no admission_generation column; refusing to persist.',
        $attempt->admissionGeneration,
        self::TABLE,
      ));
    }

    try {
      $fields = [
        'order_id' => $attempt->orderId,
        'webform_id' => $attempt->slot->webformId,
        'submission_id' => $attempt->submissionId,
        'resource_id' => $attempt->slot->resourceId,
        'pickup_arrival' => $attempt->slot->startTimestamp,
        'timezone' => $attempt->slot->timezone,
        'quote_snapshot_id' => $attempt->quote->snapshotId,
        'quote_amount_cents' => $attempt->quote->amountCents,
        'quote_currency' => $attempt->quote->currency,
        'quote_rate_version' => $attempt->quote->rateVersion,
        'quote_route_source' => $attempt->quote->routeSource ?: NULL,
        'quote_route_fingerprint' => $attempt->quote->routeFingerprint ?: NULL,
        'quote_route_calculated_at' => $attempt->quote->routeCalculatedAt ?: NULL,
        // The complete priced job (Task 24B / E08). Meters and seconds are the raw
        // job leg the price was computed from; the line items are the canonical
        // integer-cent breakdown the total is the sum of; the pair fingerprint is
        // the endpoint identity an address edit must change. The schema version and
        // digest seal all of it, so fromStored() can refuse a row whose figures no
        // longer hash to what was written. A staff-approved or legacy figure carries
        // none of the route facts, so those columns take NULL.
        'quote_route_meters' => $attempt->quote->routeMeters,
        'quote_route_seconds' => $attempt->quote->routeSeconds,
        'quote_line_items' => $attempt->quote->lineItemsJson(),
        'quote_pair_fingerprint' => $attempt->quote->pairFingerprint ?: NULL,
        'quote_payload_schema_version' => $attempt->quote->schemaVersion,
        'quote_payload_digest' => $attempt->quote->payloadDigest,
        // The accepted-job identity (Task 24B / E09). NULL for a genuine legacy
        // figure that carries none; a value for every snapshot this module writes
        // today, automatic or staff-approved, so confirmation can recompute it from
        // the saved submission and refuse an intervening job edit that left the
        // price and the reserved duration unchanged.
        'quote_job_facts_digest' => $attempt->quote->jobFactsDigest ?: NULL,
        // The canonical accepted facts the digest above was computed over (Task
        // 24B / E09). The digest names the job; these ARE the job — what the
        // customer accepted, retained so it can be reconstructed and so fromStored()
        // can re-derive the digest from them and prove the two correspond. NULL for
        // a legacy figure that carries neither.
        'quote_job_facts' => $attempt->quote->jobFactsJson ?: NULL,
        'state' => CheckoutState::Pending->value,
        'idempotency_key' => $attempt->idempotencyKey,
        'dispatch_reservation_id' => NULL,
        'created' => $attempt->created,
        'changed' => $attempt->changed,
        'settled' => NULL,
      ];
      // Task 24C-7A: a new attempt enrols in no admission generation, so the column
      // is written only when a generation was explicitly supplied — which no business
      // caller does — and only when the migrated column exists. Omitting it defaults
      // the column to NULL (unenrolled).
      if ($present && $attempt->admissionGeneration !== NULL) {
        $fields['admission_generation'] = $attempt->admissionGeneration;
      }
      $attemptId = (int) $this->database->insert(self::TABLE)
        ->fields($fields)
        ->execute();
    }
    catch (IntegrityConstraintViolationException) {
      // Deliberately not logged as an error. A repeat is an expected outcome of a
      // customer pressing pay twice, not a fault, and the existing row is the
      // correct answer to give them.
      $existing = $this->loadByKey($attempt->idempotencyKey);
      // Task 24C-7A: a replay must never rebind admission. If this attempt carries an
      // explicit generation that disagrees with the stored row's — including trying
      // to enrol a row that is still NULL — refuse without mutation rather than hand
      // back a row under a generation it does not hold. A replay that carries no
      // generation, or the same one, is the ordinary idempotent answer and returns
      // the existing row untouched. No business caller sets a generation today, so
      // this branch is reached only by a caller that explicitly did.
      if ($attempt->admissionGeneration !== NULL
        && $existing !== NULL
        && $existing->admissionGeneration !== $attempt->admissionGeneration) {
        return NULL;
      }
      return $existing;
    }

    return $attemptId > 0 ? $this->loadByOrder($attempt->orderId) : NULL;
  }

  /**
   * Reads the attempt one order pays through, or NULL when there is none.
   *
   * An order with no attempt is not an error state to repair. Commerce orders can
   * exist for reasons this module knows nothing about, and the honest answer for
   * one of those is that it is not a dispatch checkout.
   */
  public function loadByOrder(int $orderId): ?CheckoutAttempt {
    if ($orderId < 1) {
      return NULL;
    }

    $fields = $this->readableFields();
    if ($fields === NULL) {
      return NULL;
    }

    $query = $this->database->select(self::TABLE, 'a')
      ->fields('a', $fields)
      ->condition('order_id', $orderId)
      ->range(0, 1);
    // Task 24C-7A: read the admission generation only when the migrated column
    // exists, so a pre-10049 schema still reads cleanly; hydrate() resolves an
    // absent or NULL column to NULL (unenrolled).
    if ($this->hasAdmissionGenerationColumn()) {
      $query->addField('a', 'admission_generation');
    }
    $row = $query->execute()->fetchObject();

    return $row === FALSE || $row === NULL ? NULL : $this->hydrate($row);
  }

  /**
   * Moves a pending attempt to a settled state, once.
   *
   * The pending condition is on the UPDATE rather than read first and checked in
   * PHP, so the transition is a single atomic compare-and-set. Two payment
   * notifications racing each other therefore produce exactly one settlement: the
   * loser updates nothing and is told so, which is what lets confirmPaid() treat a
   * repeat as success without confirming twice.
   *
   * FALSE means the row was not pending — already paid, already cancelled, already
   * failed, or absent. It is not distinguished here on purpose: the caller reloads
   * and decides, because "already paid" and "already cancelled" call for opposite
   * answers and only the caller knows which it was hoping for.
   */
  public function settle(int $orderId, CheckoutState $state, ?int $dispatchReservationId = NULL): bool {
    if ($orderId < 1 || !$state->isSettlement()) {
      return FALSE;
    }

    $now = $this->time->getRequestTime();
    $fields = [
      'state' => $state->value,
      'changed' => $now,
      'settled' => $now,
    ];

    // A confirmed attempt names the Dispatch row the store just confirmed, and
    // only ever that one. Paid and ReconciliationRequired are both confirmed —
    // the Reservation exists in each, and they differ only in whether the money
    // was captured — so both carry the identifier. A cancelled or failed attempt
    // never held one to name.
    $confirmed = $state->hasReservation();
    if ($confirmed && $dispatchReservationId !== NULL && $dispatchReservationId > 0) {
      $fields['dispatch_reservation_id'] = $dispatchReservationId;
    }

    // An abandoned or failed attempt gives its intent back. The key is unique for
    // as long as it is set, and a permanently-held key would mean a customer who
    // cancelled, or whose card was declined, could never book that same Slot at
    // that same price again — the unique index would refuse the retry forever. A
    // confirmed attempt keeps its key, which is the case the index exists for —
    // and a Reservation still awaiting capture keeps it too, so an uncapturable
    // booking cannot be re-booked into a second order while an operator resolves
    // the money.
    if (!$confirmed) {
      $fields['idempotency_key'] = NULL;
    }

    $updated = (int) $this->database->update(self::TABLE)
      ->fields($fields)
      ->condition('order_id', $orderId)
      ->condition('state', CheckoutState::Pending->value)
      ->execute();

    return $updated > 0;
  }

  /**
   * Reads the attempt that already means this intent, before an order exists.
   *
   * This is the cheap answer to a double-clicked pay button. Without it the second
   * click would reach the planner, be refused because the Slot it wants is the one
   * it is already holding, and tell a customer their own Hold was unavailable.
   *
   * It finds a pending attempt and a paid one, and cannot find a cancelled or
   * failed one: settling to either releases the key, so the intent is free to be
   * attempted again. That is deliberate, and it is why a declined card does not
   * lock a Slot out of a retry.
   */
  public function loadByIntent(int $submissionId, Slot $slot, QuoteSnapshot $quote): ?CheckoutAttempt {
    if ($submissionId < 1) {
      return NULL;
    }

    return $this->loadByKey(CheckoutAttempt::intentKey($submissionId, $slot, $quote));
  }

  /**
   * Reads the one attempt still in flight for a job, whatever its intent.
   *
   * One job pays for one arrival at a time. Two pending attempts for a single
   * submission would mean one customer holding two Slots on the same Resource for
   * the same work, which is capacity nobody can buy. The caller refuses the second
   * rather than opening it; a visitor who wants a different arrival cancels first,
   * which is what cancel() is for.
   */
  public function openForSubmission(int $submissionId): ?CheckoutAttempt {
    if ($submissionId < 1) {
      return NULL;
    }

    $fields = $this->readableFields();
    if ($fields === NULL) {
      return NULL;
    }

    $query = $this->database->select(self::TABLE, 'a')
      ->fields('a', $fields)
      ->condition('submission_id', $submissionId)
      ->condition('state', CheckoutState::Pending->value)
      ->orderBy('attempt_id', 'DESC')
      ->range(0, 1);
    // Task 24C-7A: read the admission generation only when the migrated column
    // exists, so a pre-10049 schema still reads cleanly; hydrate() resolves an
    // absent or NULL column to NULL (unenrolled).
    if ($this->hasAdmissionGenerationColumn()) {
      $query->addField('a', 'admission_generation');
    }
    $row = $query->execute()->fetchObject();

    return $row === FALSE || $row === NULL ? NULL : $this->hydrate($row);
  }

  /**
   * Reads one attempt by its derived intent key.
   *
   * Private because the key is a derivation, not a name a caller should hold. The
   * two callers above both pass the three facts an intent is made of and let the
   * derivation happen once, so there is no signature anywhere that accepts a key a
   * caller chose — which is what the unique index depends on.
   */
  private function loadByKey(string $idempotencyKey): ?CheckoutAttempt {
    if ($idempotencyKey === '') {
      return NULL;
    }

    $fields = $this->readableFields();
    if ($fields === NULL) {
      return NULL;
    }

    $query = $this->database->select(self::TABLE, 'a')
      ->fields('a', $fields)
      ->condition('idempotency_key', $idempotencyKey)
      ->range(0, 1);
    // Task 24C-7A: read the admission generation only when the migrated column
    // exists, so a pre-10049 schema still reads cleanly; hydrate() resolves an
    // absent or NULL column to NULL (unenrolled).
    if ($this->hasAdmissionGenerationColumn()) {
      $query->addField('a', 'admission_generation');
    }
    $row = $query->execute()->fetchObject();

    return $row === FALSE || $row === NULL ? NULL : $this->hydrate($row);
  }

  /**
   * Rebuilds one stored row into an attempt, or NULL when it no longer reads.
   *
   * Every part is rebuilt through its own validating constructor rather than
   * assigned, so a row that has stopped being coherent — a zone that is no longer
   * an IANA identifier, an amount that no longer makes a price, a state string
   * nothing recognises — becomes NULL here instead of a plausible-looking attempt.
   * Slot throws on a malformed zone precisely so a stale stored zone can never
   * quietly resolve to UTC, and that throw is answered here rather than passed to
   * a caller with no way to answer it.
   *
   * Logged at warning rather than error: one unreadable row is a data question for
   * an administrator, and the request it interrupted has already been refused.
   */
  private function hydrate(object $row): ?CheckoutAttempt {
    $state = CheckoutState::fromStored(isset($row->state) ? (string) $row->state : NULL);
    $quote = QuoteSnapshot::fromStored(
      isset($row->quote_snapshot_id) ? (string) $row->quote_snapshot_id : NULL,
      isset($row->quote_amount_cents) ? (int) $row->quote_amount_cents : NULL,
      isset($row->quote_currency) ? (string) $row->quote_currency : NULL,
      isset($row->quote_rate_version) ? (string) $row->quote_rate_version : NULL,
      isset($row->quote_route_source) ? (string) $row->quote_route_source : NULL,
      isset($row->quote_route_fingerprint) ? (string) $row->quote_route_fingerprint : NULL,
      isset($row->quote_route_calculated_at) ? (int) $row->quote_route_calculated_at : NULL,
      isset($row->quote_route_meters) ? (int) $row->quote_route_meters : NULL,
      isset($row->quote_route_seconds) ? (int) $row->quote_route_seconds : NULL,
      isset($row->quote_line_items) ? (string) $row->quote_line_items : NULL,
      isset($row->quote_pair_fingerprint) ? (string) $row->quote_pair_fingerprint : NULL,
      isset($row->quote_payload_schema_version) ? (int) $row->quote_payload_schema_version : NULL,
      isset($row->quote_payload_digest) ? (string) $row->quote_payload_digest : NULL,
      isset($row->quote_job_facts_digest) ? (string) $row->quote_job_facts_digest : NULL,
      isset($row->quote_job_facts) ? (string) $row->quote_job_facts : NULL,
    );
    $attemptId = (int) ($row->attempt_id ?? 0);

    if ($state === NULL || $quote === NULL) {
      $this->logger->warning('Checkout attempt @id was not read: its stored state or locked amount is no longer coherent.', [
        '@id' => $attemptId,
      ]);
      return NULL;
    }

    try {
      $slot = new Slot(
        (string) ($row->webform_id ?? ''),
        (string) ($row->resource_id ?? ''),
        (int) ($row->pickup_arrival ?? 0),
        (string) ($row->timezone ?? ''),
      );
    }
    catch (\InvalidArgumentException $e) {
      $this->logger->warning('Checkout attempt @id was not read: @message', [
        '@id' => $attemptId,
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }

    return CheckoutAttempt::stored(
      $attemptId,
      (int) ($row->order_id ?? 0),
      (int) ($row->submission_id ?? 0),
      $slot,
      $quote,
      $state,
      (string) ($row->idempotency_key ?? ''),
      isset($row->dispatch_reservation_id) ? (int) $row->dispatch_reservation_id : NULL,
      (int) ($row->created ?? 0),
      (int) ($row->changed ?? 0),
      isset($row->settled) ? (int) $row->settled : NULL,
      // Task 24C-7A: present only when the reader selected it (migrated schema);
      // isset keeps an unselected or NULL column as NULL, never zero.
      isset($row->admission_generation) ? (int) $row->admission_generation : NULL,
    );
  }

}
