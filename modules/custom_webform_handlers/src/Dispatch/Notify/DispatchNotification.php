<?php

namespace Drupal\custom_webform_handlers\Dispatch\Notify;

/**
 * One committed planner fact, on its way to somebody who should hear about it.
 *
 * Identifiers and instants only. A queue is a durable table: whatever is put in it
 * sits on disk until a worker runs, survives a failed send, and is readable by
 * anything with database access. So this payload carries no address, no customer
 * name, no telephone number, no price, no Hold token, no owner hash and no session
 * identifier. Customer-safe contact data is resolved at send time from the submission
 * that owns the Reservation, under the access rules that already govern it, and it is
 * gone again when the item is deleted.
 *
 * The scheduling facts are the deliberate exception. A release or a Replan cannot
 * look up its own arrival promise afterwards — the safe admin reader answers NULL for
 * anything that is not a live confirmed Reservation — so the arrival, the window and
 * the timezone are captured while the fact is still true and travel with the event. A
 * message about a cancelled booking still has to be able to say which booking.
 *
 * Immutable, and built only through the factory named for its event, so a Blackout
 * payload cannot carry a Reservation identifier and a confirmation cannot be built
 * without the arrival it is promising.
 */
final readonly class DispatchNotification {

  /**
   * The shape of the payload this release writes.
   *
   * Stamped into every queue item so a worker can tell what it is holding rather
   * than inferring it from which keys happen to be present. A reader refuses a
   * version above this one — see self::fromQueueItem(). Items written before this
   * constant existed carry no stamp at all and are read as version 1, which is what
   * they are: the field set has not changed, only the labelling of it.
   *
   * Bump this when the meaning of a field changes, and note that
   * self::idempotencyKey() is derived from the stamped payload, so a bump
   * deliberately changes every key. That is the safe direction: a new release
   * re-sending one in-flight notification is a nuisance, whereas a new release
   * silently matching an old key it computed differently would suppress a real one.
   */
  const PAYLOAD_VERSION = 1;

  /**
   * How much operator-safe detail travels with an event.
   *
   * Matches the history column. The detail is a short reason or state code written
   * for an operator, never a sentence assembled out of customer data.
   */
  const DETAIL_MAX_LENGTH = 255;


  /**
   * Private on purpose: build one through the factory named for its event.
   *
   * @param \Drupal\custom_webform_handlers\Dispatch\Notify\DispatchEvent $event
   *   What committed.
   * @param string $resourceId
   *   The Resource it happened on.
   * @param string $timezone
   *   The operating timezone the instants below should be read in, captured with
   *   them so a later profile change cannot reword an already-sent promise.
   * @param int $occurredAt
   *   When the transaction committed.
   * @param int|null $dispatchReservationId
   *   The Reservation, when the event is about one.
   * @param int|null $supersededReservationId
   *   The Reservation a Replan replaced.
   * @param int|null $submissionId
   *   The submission that owns the booking, used at send time to resolve contact
   *   data. An identifier here, never the data itself.
   * @param string|null $webformId
   *   That submission's Webform.
   * @param int|null $pickupArrival
   *   The promised pickup arrival.
   * @param int|null $windowStart
   *   Start of the proved Dispatch Window.
   * @param int|null $windowEnd
   *   End of the proved Dispatch Window.
   * @param string|null $detail
   *   Operator-safe reason or state code. See self::DETAIL_MAX_LENGTH.
   * @param int|null $actorUid
   *   The account that caused it, or NULL when a visitor did.
   */
  private function __construct(
    public DispatchEvent $event,
    public string $resourceId,
    public string $timezone,
    public int $occurredAt,
    public ?int $dispatchReservationId,
    public ?int $supersededReservationId,
    public ?int $submissionId,
    public ?string $webformId,
    public ?int $pickupArrival,
    public ?int $windowStart,
    public ?int $windowEnd,
    public ?string $detail,
    public ?int $actorUid,
  ) {}

  /**
   * A Reservation was confirmed for a customer.
   *
   * The arrival and the window are required rather than nullable: a confirmation
   * whose whole content is a promise about a time has nothing to say without one.
   */
  public static function confirmed(
    string $resourceId,
    string $timezone,
    int $occurredAt,
    int $dispatchReservationId,
    ?int $submissionId,
    ?string $webformId,
    int $pickupArrival,
    int $windowStart,
    int $windowEnd,
    ?int $actorUid = NULL,
  ): self {
    return new self(
      DispatchEvent::ReservationConfirmed,
      trim($resourceId),
      trim($timezone),
      $occurredAt,
      $dispatchReservationId,
      NULL,
      self::positive($submissionId),
      self::identifier($webformId),
      $pickupArrival,
      $windowStart,
      $windowEnd,
      NULL,
      self::positive($actorUid),
    );
  }

  /**
   * Staff moved a confirmed Reservation through the Replan transaction.
   *
   * Both identifiers are carried. The customer copy is about the new promise; the
   * staff copy needs the superseded row to explain what stopped being true.
   */
  public static function replanned(
    string $resourceId,
    string $timezone,
    int $occurredAt,
    int $dispatchReservationId,
    int $supersededReservationId,
    ?int $submissionId,
    ?string $webformId,
    int $pickupArrival,
    int $windowStart,
    int $windowEnd,
    ?int $actorUid = NULL,
  ): self {
    return new self(
      DispatchEvent::ReservationReplanned,
      trim($resourceId),
      trim($timezone),
      $occurredAt,
      $dispatchReservationId,
      self::positive($supersededReservationId),
      self::positive($submissionId),
      self::identifier($webformId),
      $pickupArrival,
      $windowStart,
      $windowEnd,
      NULL,
      self::positive($actorUid),
    );
  }

  /**
   * A confirmed Reservation was released and its time returned to the calendar.
   *
   * The window is nullable here because a release is the one event whose subject is
   * already gone: the arrival is passed in if the caller still holds it, and the
   * message says less rather than guessing when it does not.
   */
  public static function released(
    string $resourceId,
    string $timezone,
    int $occurredAt,
    int $dispatchReservationId,
    ?int $submissionId,
    ?string $webformId,
    ?int $pickupArrival,
    ?int $windowStart,
    ?int $windowEnd,
    string $detail,
    ?int $actorUid = NULL,
  ): self {
    return new self(
      DispatchEvent::ReservationReleased,
      trim($resourceId),
      trim($timezone),
      $occurredAt,
      $dispatchReservationId,
      NULL,
      self::positive($submissionId),
      self::identifier($webformId),
      self::positive($pickupArrival),
      self::positive($windowStart),
      self::positive($windowEnd),
      self::clip($detail),
      self::positive($actorUid),
    );
  }

  /**
   * A Blackout withheld time from the calendar.
   *
   * Names no Reservation and no submission. A closure is a fact about the business's
   * own capacity, and the interval it covers belongs to nobody's booking.
   */
  public static function timeWithheld(
    string $resourceId,
    string $timezone,
    int $occurredAt,
    int $windowStart,
    int $windowEnd,
    string $detail,
    ?int $actorUid = NULL,
  ): self {
    return new self(
      DispatchEvent::TimeWithheld,
      trim($resourceId),
      trim($timezone),
      $occurredAt,
      NULL,
      NULL,
      NULL,
      NULL,
      NULL,
      $windowStart,
      $windowEnd,
      self::clip($detail),
      self::positive($actorUid),
    );
  }

  /**
   * A Blackout was withdrawn and the time offered again.
   */
  public static function timeReturned(
    string $resourceId,
    string $timezone,
    int $occurredAt,
    string $detail,
    ?int $actorUid = NULL,
  ): self {
    return new self(
      DispatchEvent::TimeReturned,
      trim($resourceId),
      trim($timezone),
      $occurredAt,
      NULL,
      NULL,
      NULL,
      NULL,
      NULL,
      NULL,
      NULL,
      self::clip($detail),
      self::positive($actorUid),
    );
  }

  /**
   * A request could not be planned and is waiting for an operator.
   *
   * There is no Reservation to name, because Manual Review is precisely the state of
   * not having one. The submission is named so an operator can open the request from
   * the board; the reason is the safe code the planner already recorded, never a
   * sentence built out of the customer's answers.
   *
   * No actor. Nobody decided this: the planner declined, and the request queued
   * itself.
   */
  public static function manualReview(
    string $resourceId,
    string $timezone,
    int $occurredAt,
    ?int $submissionId,
    ?string $webformId,
    string $detail,
  ): self {
    return new self(
      DispatchEvent::ManualReviewRaised,
      trim($resourceId),
      trim($timezone),
      $occurredAt,
      NULL,
      NULL,
      self::positive($submissionId),
      self::identifier($webformId),
      NULL,
      NULL,
      NULL,
      self::clip($detail),
      NULL,
    );
  }

  /**
   * Flattens this to the scalars a queue item may hold.
   *
   * Scalars only, and short keys, because the array is serialised into a database
   * column. Nothing here needs an object to survive a deployment, which also means an
   * item written by this release can still be read by the next one.
   *
   * The leading version stamp is what makes that claim checkable rather than hoped
   * for. See self::PAYLOAD_VERSION.
   *
   * @return array<string, int|string|null>
   *   The queue payload.
   */
  public function toQueueItem(): array {
    return [
      'v' => self::PAYLOAD_VERSION,
      'event' => $this->event->value,
      'resource' => $this->resourceId,
      'timezone' => $this->timezone,
      'occurred_at' => $this->occurredAt,
      'reservation' => $this->dispatchReservationId,
      'superseded' => $this->supersededReservationId,
      'submission' => $this->submissionId,
      'webform' => $this->webformId,
      'arrival' => $this->pickupArrival,
      'window_start' => $this->windowStart,
      'window_end' => $this->windowEnd,
      'detail' => $this->detail,
      'actor' => $this->actorUid,
    ];
  }

  /**
   * The stable name of the message this payload asks for.
   *
   * Derived from the payload, never generated, which is the whole point: a job that
   * is retried, released after a crashed worker, or deliberately replayed by an
   * operator rebuilds the same payload and therefore computes the same key. The
   * notification log claims that key before anything is composed, so the second
   * attempt finds the first one's claim and sends nothing.
   *
   * Every field participates, including the version stamp. Two genuinely different
   * announcements differ somewhere in this array — a Blackout withdrawn twice in the
   * same second on the same Resource still differs in its detail code — so
   * distinguishing them does not rest on the one-second resolution of occurredAt.
   *
   * Not a secret, and nothing authenticates with it. It is a function of identifiers
   * that are already stored in the row it keys, so it discloses nothing that row did
   * not already hold. sha256 hex is 64 characters, which is exactly the column.
   */
  public function idempotencyKey(): string {
    return hash('sha256', serialize($this->toQueueItem()));
  }

  /**
   * Rebuilds one payload from a queue item, or NULL when it cannot be trusted.
   *
   * A queue item is read back in a later request, possibly after a deployment, so it
   * is treated as input and not as memory. An event this release does not recognise,
   * or an item naming no Resource, answers NULL: the worker deletes it and records the
   * fact rather than composing a message about a vehicle it cannot identify.
   *
   * The event is not rebuilt through the named constructors on purpose. Those enforce
   * what a caller must supply when the fact is fresh; an item already written must be
   * read exactly as it was stored, or a payload would change meaning between the
   * enqueue and the send.
   *
   * @param array<string, mixed> $item
   *   A payload previously produced by self::toQueueItem().
   */
  public static function fromQueueItem(array $item): ?self {
    // A payload from a future release is refused rather than guessed at. Absence of
    // the stamp means version 1 — items enqueued before the stamp existed carry the
    // same field set — so an in-flight legacy item still reads correctly.
    if ((int) ($item['v'] ?? self::PAYLOAD_VERSION) > self::PAYLOAD_VERSION) {
      return NULL;
    }

    $event = DispatchEvent::tryFrom((string) ($item['event'] ?? ''));

    if ($event === NULL) {
      return NULL;
    }

    $resourceId = trim((string) ($item['resource'] ?? ''));
    if ($resourceId === '') {
      return NULL;
    }

    return new self(
      $event,
      $resourceId,
      trim((string) ($item['timezone'] ?? '')),
      (int) ($item['occurred_at'] ?? 0),
      self::positive(self::intOrNull($item['reservation'] ?? NULL)),
      self::positive(self::intOrNull($item['superseded'] ?? NULL)),
      self::positive(self::intOrNull($item['submission'] ?? NULL)),
      self::identifier(isset($item['webform']) ? (string) $item['webform'] : NULL),
      self::positive(self::intOrNull($item['arrival'] ?? NULL)),
      self::positive(self::intOrNull($item['window_start'] ?? NULL)),
      self::positive(self::intOrNull($item['window_end'] ?? NULL)),
      isset($item['detail']) ? self::clip((string) $item['detail']) : NULL,
      self::positive(self::intOrNull($item['actor'] ?? NULL)),
    );
  }

  /**
   * Tells whether a submission can be looked up for contact data at send time.
   */
  public function hasSubmissionReference(): bool {
    return $this->submissionId !== NULL && $this->webformId !== NULL;
  }

  /**
   * Keeps a positive identifier or instant, and answers NULL for anything else.
   */
  private static function positive(?int $value): ?int {
    return ($value !== NULL && $value > 0) ? $value : NULL;
  }

  /**
   * Reads a queue value as an integer without inventing one from a non-scalar.
   */
  private static function intOrNull(mixed $value): ?int {
    return is_int($value) || (is_string($value) && $value !== '') ? (int) $value : NULL;
  }

  /**
   * Keeps a machine name that looks like one, and answers NULL otherwise.
   *
   * The same shape DispatchRequestFactory requires before it will load a Webform. A
   * malformed identifier is dropped here rather than carried to a send-time lookup
   * that would then have to decide what to do with it.
   */
  private static function identifier(?string $value): ?string {
    $value = trim((string) $value);
    if ($value === '' || strlen($value) > 128 || preg_match('/^[a-z0-9_]+$/', $value) !== 1) {
      return NULL;
    }

    return $value;
  }

  /**
   * Shortens one operator-safe detail to what the history column holds.
   */
  private static function clip(string $value): string {
    $value = trim($value);

    return mb_strlen($value) > self::DETAIL_MAX_LENGTH
      ? mb_substr($value, 0, self::DETAIL_MAX_LENGTH)
      : $value;
  }

}
