<?php

namespace Drupal\custom_webform_handlers\Review;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Psr\Log\LoggerInterface;

/**
 * Durable storage for the Review Request lifecycle, and the only issuer of its SQL.
 *
 * A narrow table rather than fields on the Webform submission, for two reasons the
 * Field API cannot serve. The first is uniqueness: one job has one review and one
 * tracking code matches one request, and both are unique indexes here — a check in
 * PHP is not the same guarantee when two members of staff decide at once. The second
 * is that a submission's element values are visible wherever the submission is, and
 * the credential hash must not be.
 *
 * Every move is a compare-and-set: the UPDATE carries the state the caller believed,
 * so a contended decision produces one winner and the loser is told so rather than
 * overwriting. Nothing here decides whether a move is legal — that is the manager's,
 * reading ReviewState — and nothing here reads or writes the Dispatch Reservation
 * table, which belongs to the Resource Calendar Guard. This store records decisions;
 * it never makes one and never touches capacity.
 *
 * The credential hash is written and matched and never selected into a ReviewRequest.
 * hydrate() does not read the column, so there is no object in this module carrying
 * it and no page that could render it by accident.
 */
final class ReviewRequestStore {

  /**
   * The lifecycle table. Never joined to from a public response.
   */
  const TABLE = 'custom_webform_handlers_review_request';

  /**
   * Every column a request is rebuilt from. The credential hash is not among them.
   */
  const FIELDS = [
    'review_id',
    'webform_id',
    'submission_id',
    'state',
    'order_id',
    'dispatch_reservation_id',
    'quote_amount_cents',
    'quote_currency',
    'staff_message',
    'actor_uid',
    'actor_name',
    'created',
    'changed',
    'decided_at',
    'expires_at',
  ];

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Writes a submitted request, or returns the one that already means this job.
   *
   * The collision is the feature, exactly as it is in CheckoutAttemptStore::open():
   * two handler runs for one submission derive the same unique submission key, so the
   * second insert violates the index and this returns the first request instead of
   * opening a second authority on the same work.
   *
   * The distinction the caller needs is which of those happened, because only the
   * insert may show a customer a code. That is why the return is a pair: the request,
   * and TRUE only when this call wrote it.
   *
   * @return array{0: \Drupal\custom_webform_handlers\Review\ReviewRequest, 1: bool}|null
   *   The request and whether it was newly written, or NULL when neither happened.
   */
  public function open(ReviewRequest $request, string $credentialHash): ?array {
    if ($request->submissionId < 1 || $request->webformId === '' || $credentialHash === '') {
      return NULL;
    }

    try {
      $reviewId = (int) $this->database->insert(self::TABLE)
        ->fields([
          'webform_id' => $request->webformId,
          'submission_id' => $request->submissionId,
          'state' => ReviewState::Submitted->value,
          'order_id' => NULL,
          'dispatch_reservation_id' => NULL,
          'quote_amount_cents' => NULL,
          'quote_currency' => NULL,
          'staff_message' => '',
          'lookup_hash' => $credentialHash,
          'actor_uid' => 0,
          'actor_name' => '',
          'created' => $request->created,
          'changed' => $request->changed,
          'decided_at' => NULL,
          'expires_at' => $request->expiresAt,
        ])
        ->execute();
    }
    catch (IntegrityConstraintViolationException) {
      // Not logged as an error. A repeat is the expected outcome of a submission
      // being saved twice, and the existing request is the correct answer.
      $existing = $this->loadBySubmission($request->submissionId);

      return $existing === NULL ? NULL : [$existing, FALSE];
    }
    catch (\Exception $e) {
      $this->logger->error('A Review Request for submission @sid could not be opened: @message', [
        '@sid' => $request->submissionId,
        '@message' => $e->getMessage(),
      ]);

      return NULL;
    }

    $written = $reviewId > 0 ? $this->load($reviewId) : NULL;

    return $written === NULL ? NULL : [$written, TRUE];
  }

  /**
   * Moves a request from one state to another, once.
   *
   * The expected state is a condition on the UPDATE rather than read first and checked
   * in PHP, so the transition is a single atomic compare-and-set and two decisions
   * racing produce exactly one. FALSE means the row was not in the expected state —
   * already moved, or absent — and the caller reloads to find out which, because
   * "somebody else approved it" and "somebody else declined it" call for different
   * things to be said and only the caller knows which it was hoping for.
   *
   * @param int $reviewId
   *   The request to move.
   * @param \Drupal\custom_webform_handlers\Review\ReviewState $from
   *   The state the caller read and is betting on.
   * @param \Drupal\custom_webform_handlers\Review\ReviewState $to
   *   Where it is going. Legality was already decided by the manager.
   * @param array $extra
   *   Columns the move also writes — a price, an order, a Reservation, a message.
   *   Bounded to the writable set below, so a caller cannot reach a column this
   *   store does not mean to let a move touch.
   */
  public function move(int $reviewId, ReviewState $from, ReviewState $to, array $extra = []): bool {
    if ($reviewId < 1) {
      return FALSE;
    }

    $now = $this->time->getRequestTime();
    $fields = [
      'state' => $to->value,
      'changed' => $now,
    ];

    // A move out of an open state is a decision, and it is stamped as one. Expiry is
    // a decision nobody made, so it is stamped too — an operator looking at a lapsed
    // request needs to know when it lapsed, not when it arrived.
    if (!$to->isOpen()) {
      $fields['decided_at'] = $now;
    }

    // Only these columns may be written by a move, and each is bounded here rather
    // than trusted from the caller. staff_message is normalised through the value
    // object's own bound, so no move can store text the tracker would not show.
    $writable = [
      'order_id',
      'dispatch_reservation_id',
      'quote_amount_cents',
      'quote_currency',
      'staff_message',
      'actor_uid',
      'actor_name',
      'expires_at',
    ];
    foreach ($writable as $column) {
      if (!array_key_exists($column, $extra)) {
        continue;
      }
      $value = $extra[$column];
      $fields[$column] = match ($column) {
        'staff_message' => ReviewRequest::normalizeMessage(is_string($value) ? $value : NULL),
        'quote_currency' => ReviewRequest::normalizeCurrency(is_string($value) ? $value : NULL),
        'actor_name' => mb_substr(trim((string) $value), 0, 128),
        default => $value === NULL ? NULL : max(0, (int) $value),
      };
    }

    try {
      $updated = (int) $this->database->update(self::TABLE)
        ->fields($fields)
        ->condition('review_id', $reviewId)
        ->condition('state', $from->value)
        ->execute();
    }
    catch (\Exception $e) {
      $this->logger->error('Review Request @id could not be moved from @from to @to: @message', [
        '@id' => $reviewId,
        '@from' => $from->value,
        '@to' => $to->value,
        '@message' => $e->getMessage(),
      ]);

      return FALSE;
    }

    return $updated > 0;
  }

  /**
   * Reads one request by its own identifier.
   */
  public function load(int $reviewId): ?ReviewRequest {
    if ($reviewId < 1) {
      return NULL;
    }

    return $this->one(['review_id' => $reviewId]);
  }

  /**
   * Reads the request for one job, or NULL when that job has none.
   *
   * A submission with no review is not an error to repair. Most submissions are
   * priced automatically and never need one.
   */
  public function loadBySubmission(int $submissionId): ?ReviewRequest {
    if ($submissionId < 1) {
      return NULL;
    }

    return $this->one(['submission_id' => $submissionId]);
  }

  /**
   * Reads the request a hashed tracking code names, or NULL when it names none.
   *
   * The hash is matched as an equality condition on an indexed column, so every
   * lookup costs the same whether it hits or misses and the endpoint above it cannot
   * be timed into revealing which. An empty hash is refused before it becomes a
   * query, because the hash of nothing would otherwise match rows written from
   * nothing.
   */
  public function loadByCredential(string $credentialHash): ?ReviewRequest {
    if ($credentialHash === '') {
      return NULL;
    }

    return $this->one(['lookup_hash' => $credentialHash]);
  }

  /**
   * Reads the requests awaiting a staff decision, oldest first.
   *
   * Oldest first because this is a work queue and the oldest undecided job is the one
   * a customer has been waiting longest for. Approved requests are absent: the staff
   * decision on those has been made, and what is outstanding is a payment.
   *
   * @return \Drupal\custom_webform_handlers\Review\ReviewRequest[]
   *   The open requests, keyed by review identifier.
   */
  public function openQueue(int $limit = 100): array {
    $limit = max(1, min(500, $limit));
    $open = [ReviewState::Submitted->value, ReviewState::UnderReview->value];

    try {
      $rows = $this->database->select(self::TABLE, 'r')
        ->fields('r', self::FIELDS)
        ->condition('state', $open, 'IN')
        ->orderBy('created', 'ASC')
        ->orderBy('review_id', 'ASC')
        ->range(0, $limit)
        ->execute()
        ->fetchAll();
    }
    catch (\Exception $e) {
      $this->logger->error('The Review Request queue could not be read: @message', [
        '@message' => $e->getMessage(),
      ]);

      return [];
    }

    $requests = [];
    foreach ($rows as $row) {
      $request = $this->hydrate($row);
      if ($request !== NULL) {
        $requests[$request->reviewId] = $request;
      }
    }

    return $requests;
  }

  /**
   * Reads the requests whose deadline has passed and which may still lapse.
   *
   * Scheduled requests are excluded by the state filter, not by a check afterwards:
   * a paid booking must never be a candidate for expiry, so it is never selected.
   *
   * @return \Drupal\custom_webform_handlers\Review\ReviewRequest[]
   *   Lapsed requests, oldest deadline first.
   */
  public function lapsed(int $now, int $limit = 50): array {
    $limit = max(1, min(500, $limit));
    $expirable = array_values(array_map(
      static fn (ReviewState $state): string => $state->value,
      array_filter(ReviewState::cases(), static fn (ReviewState $state): bool => $state->mayExpire()),
    ));

    try {
      $rows = $this->database->select(self::TABLE, 'r')
        ->fields('r', self::FIELDS)
        ->condition('state', $expirable, 'IN')
        ->isNotNull('expires_at')
        ->condition('expires_at', $now, '<=')
        ->orderBy('expires_at', 'ASC')
        ->range(0, $limit)
        ->execute()
        ->fetchAll();
    }
    catch (\Exception $e) {
      $this->logger->error('Lapsed Review Requests could not be read: @message', [
        '@message' => $e->getMessage(),
      ]);

      return [];
    }

    $requests = [];
    foreach ($rows as $row) {
      $request = $this->hydrate($row);
      if ($request !== NULL) {
        $requests[] = $request;
      }
    }

    return $requests;
  }

  /**
   * How many requests stand in each state, for the board's own summary.
   *
   * A count and nothing else, so a read-only viewer can be shown how much work is
   * outstanding without being shown whose it is.
   *
   * @return array<string, int>
   *   State value to count, for states with at least one request.
   */
  public function countByState(): array {
    try {
      $select = $this->database->select(self::TABLE, 'r');
      $select->addField('r', 'state', 'state');
      $select->addExpression('COUNT(r.review_id)', 'total');
      $select->groupBy('r.state');
      $rows = $select->execute()->fetchAll();
    }
    catch (\Exception $e) {
      $this->logger->error('Review Request counts could not be read: @message', [
        '@message' => $e->getMessage(),
      ]);

      return [];
    }

    $counts = [];
    foreach ($rows as $row) {
      $state = ReviewState::fromStored(isset($row->state) ? (string) $row->state : NULL);
      if ($state !== NULL) {
        $counts[$state->value] = (int) ($row->total ?? 0);
      }
    }

    return $counts;
  }

  /**
   * Reads one row on an exact-match condition, or NULL.
   *
   * Private, and the single point every single-row read goes through, so the field
   * list — which excludes the credential hash — cannot be forgotten at one call site.
   */
  private function one(array $conditions): ?ReviewRequest {
    try {
      $select = $this->database->select(self::TABLE, 'r')->fields('r', self::FIELDS);
      foreach ($conditions as $column => $value) {
        $select->condition($column, $value);
      }
      $row = $select->range(0, 1)->execute()->fetchObject();
    }
    catch (\Exception $e) {
      $this->logger->error('A Review Request could not be read: @message', [
        '@message' => $e->getMessage(),
      ]);

      return NULL;
    }

    return $row === FALSE || $row === NULL ? NULL : $this->hydrate($row);
  }

  /**
   * Rebuilds one stored row, or NULL when it no longer reads as a request.
   *
   * A row whose state string nothing recognises becomes NULL rather than a default,
   * for the reason ReviewState::fromStored() gives: an unreadable state must never be
   * treated as open work, because open work can be approved.
   *
   * Logged at warning. One unreadable row is a data question for an administrator,
   * and the request that hit it has already been refused.
   */
  private function hydrate(object $row): ?ReviewRequest {
    $reviewId = (int) ($row->review_id ?? 0);
    $state = ReviewState::fromStored(isset($row->state) ? (string) $row->state : NULL);
    if ($state === NULL) {
      $this->logger->warning('Review Request @id was not read: its stored state is not one this module recognises.', [
        '@id' => $reviewId,
      ]);

      return NULL;
    }

    return ReviewRequest::stored(
      $reviewId,
      (string) ($row->webform_id ?? ''),
      (int) ($row->submission_id ?? 0),
      $state,
      isset($row->order_id) ? (int) $row->order_id : NULL,
      isset($row->dispatch_reservation_id) ? (int) $row->dispatch_reservation_id : NULL,
      isset($row->quote_amount_cents) ? (int) $row->quote_amount_cents : NULL,
      isset($row->quote_currency) ? (string) $row->quote_currency : NULL,
      (string) ($row->staff_message ?? ''),
      (int) ($row->actor_uid ?? 0),
      (string) ($row->actor_name ?? ''),
      (int) ($row->created ?? 0),
      (int) ($row->changed ?? 0),
      isset($row->decided_at) ? (int) $row->decided_at : NULL,
      isset($row->expires_at) ? (int) $row->expires_at : NULL,
    );
  }

}
