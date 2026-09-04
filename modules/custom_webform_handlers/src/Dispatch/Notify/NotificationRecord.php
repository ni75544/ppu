<?php

namespace Drupal\custom_webform_handlers\Dispatch\Notify;

/**
 * One line of the notification history, as the board reads it.
 *
 * What this record deliberately does not contain is the recipient. Not a hashed
 * address, not a truncated one, not a user reference: an operator looking at the board
 * needs to know that a confirmation for booking 412 was handed to mail at 09:14, and
 * knowing which address it went to adds nothing to that decision while putting a
 * customer's email in a table that a wider set of accounts can read than the
 * submission itself. The submission is named by identifier, and anybody entitled to
 * the address can already open it there.
 *
 * Nor does it contain the message. The composed subject and body are built from
 * customer data at send time and discarded; storing them would quietly turn this log
 * into a second copy of every address and arrival promise the system has ever sent,
 * with none of the access control that guards the first copy.
 *
 * What is left is a fact about the machinery: which event, which audience, over which
 * channel, with what result, at what instant. That is the whole intended use.
 */
final readonly class NotificationRecord {

  /**
   * Delivered as site mail.
   *
   * The only channel this release writes. The column exists so a later integration —
   * SMS, a webhook — is distinguishable in storage from the moment it is added rather
   * than after somebody has to guess which rows were mail.
   */
  const CHANNEL_MAIL = 'mail';

  /**
   * @param int $id
   *   The history row.
   * @param \Drupal\custom_webform_handlers\Dispatch\Notify\DispatchEvent $event
   *   What was being told.
   * @param \Drupal\custom_webform_handlers\Dispatch\Notify\DispatchAudience $audience
   *   Who it was written for. Not who it was addressed to.
   * @param string $channel
   *   How it was carried. See self::CHANNEL_MAIL.
   * @param \Drupal\custom_webform_handlers\Dispatch\Notify\NotificationOutcome $outcome
   *   What became of it.
   * @param string $resourceId
   *   The Resource the event happened on.
   * @param int|null $dispatchReservationId
   *   The Reservation it was about, or NULL.
   * @param int|null $submissionId
   *   The submission that owns the booking, or NULL. An identifier only.
   * @param int $occurredAt
   *   When the planner fact committed.
   * @param int $recordedAt
   *   When the send was attempted. Later than $occurredAt by however long the queue
   *   waited, which is the number an operator wants when mail is running behind.
   * @param string $detail
   *   Operator-safe reason or failure code. Never a customer's data.
   */
  public function __construct(
    public int $id,
    public DispatchEvent $event,
    public DispatchAudience $audience,
    public string $channel,
    public NotificationOutcome $outcome,
    public string $resourceId,
    public ?int $dispatchReservationId,
    public ?int $submissionId,
    public int $occurredAt,
    public int $recordedAt,
    public string $detail,
  ) {}

  /**
   * Rebuilds one row, or NULL when it cannot be read as one.
   *
   * An unrecognised event, audience or outcome answers NULL rather than a guess. Such
   * a row was written by a release that knew what it meant, and rendering it as
   * something this release does happen to recognise would report the wrong thing about
   * a customer's confirmation.
   */
  public static function fromRow(object $row): ?self {
    $event = DispatchEvent::tryFrom((string) ($row->event ?? ''));
    $audience = DispatchAudience::tryFrom((string) ($row->audience ?? ''));
    $outcome = NotificationOutcome::tryFrom((string) ($row->outcome ?? ''));

    if ($event === NULL || $audience === NULL || $outcome === NULL) {
      return NULL;
    }

    $reservationId = $row->dispatch_reservation_id ?? NULL;
    $submissionId = $row->submission_id ?? NULL;

    return new self(
      (int) ($row->id ?? 0),
      $event,
      $audience,
      (string) ($row->channel ?? self::CHANNEL_MAIL),
      $outcome,
      (string) ($row->resource_id ?? ''),
      $reservationId === NULL ? NULL : (int) $reservationId,
      $submissionId === NULL ? NULL : (int) $submissionId,
      (int) ($row->occurred_at ?? 0),
      (int) ($row->recorded_at ?? 0),
      (string) ($row->detail ?? ''),
    );
  }

  /**
   * How long the event waited between committing and being sent, in seconds.
   *
   * Never negative: a clock that appears to run backwards reads as no delay rather
   * than as a negative one.
   */
  public function queueDelay(): int {
    return max(0, $this->recordedAt - $this->occurredAt);
  }

}
