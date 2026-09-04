<?php

namespace Drupal\custom_webform_handlers\Dispatch\Status;

/**
 * One recorded observation of where a Resource is in its day.
 *
 * A read model, and only that. It carries no method that changes a schedule and no
 * property a planner reads: the calendar is proved from Reservations, Holds,
 * Blackouts and the operating profile, and adding a status to that list would give
 * the system a second, unproved idea of what the vehicle is doing.
 *
 * What it deliberately does not carry: no address, no customer name, no phone
 * number, no price, no Hold token and no owner hash. A status names a Reservation
 * by identifier when it is about one, and the board already shows that identifier,
 * so nothing here discloses anything the operator could not already see.
 *
 * The audit fields are the point of the record as much as the state is. Somebody
 * asserted this, at a known instant, through a known route — actorUid and actorName
 * are stored together because an account can be renamed or removed and the audit
 * trail has to keep meaning something afterwards.
 */
final readonly class ResourceStatus {

  /**
   * Recorded by a member of staff on the status form.
   *
   * The only source this release writes. The column exists so that a later
   * integration — a driver application, a telematics feed — is distinguishable in
   * storage from the moment it is added, and can never be mistaken for a human
   * assertion made under a permission check.
   */
  const SOURCE_STAFF = 'staff_form';

  /**
   * @param int $id
   *   The log row, ascending with time. 0 for a record not yet written.
   * @param string $resourceId
   *   The Resource this is about.
   * @param \Drupal\custom_webform_handlers\Dispatch\Status\ResourceStatusState $state
   *   What was reported.
   * @param int|null $dispatchReservationId
   *   The Reservation the state is about, or NULL when it is about the Resource
   *   itself. Stored as an identifier and never resolved into customer detail.
   * @param string $note
   *   Free text an operator typed, for other operators. Length-capped on the way
   *   in and rendered as plain text.
   * @param int $actorUid
   *   The account that asserted this.
   * @param string $actorName
   *   That account's name at the instant it asserted it.
   * @param int $recordedAt
   *   When it was asserted, as a UNIX timestamp.
   * @param string $source
   *   How it arrived. See self::SOURCE_STAFF.
   */
  public function __construct(
    public int $id,
    public string $resourceId,
    public ResourceStatusState $state,
    public ?int $dispatchReservationId,
    public string $note,
    public int $actorUid,
    public string $actorName,
    public int $recordedAt,
    public string $source,
  ) {}

  /**
   * Rebuilds one record from its stored row, or NULL when the row is unusable.
   *
   * An unrecognised state value answers NULL rather than a guess. A state this
   * release does not know about was written by one that did, and rendering it as
   * something else would report the wrong thing about a vehicle.
   */
  public static function fromRow(object $row): ?self {
    $state = ResourceStatusState::tryFrom((string) ($row->state ?? ''));
    if ($state === NULL) {
      return NULL;
    }

    $reservationId = $row->dispatch_reservation_id ?? NULL;

    return new self(
      (int) ($row->id ?? 0),
      (string) ($row->resource_id ?? ''),
      $state,
      $reservationId === NULL ? NULL : (int) $reservationId,
      (string) ($row->note ?? ''),
      (int) ($row->actor_uid ?? 0),
      (string) ($row->actor_name ?? ''),
      (int) ($row->recorded_at ?? 0),
      (string) ($row->source ?? self::SOURCE_STAFF),
    );
  }

  /**
   * Tells whether a human asserted this under a permission check.
   */
  public function isStaffAsserted(): bool {
    return $this->source === self::SOURCE_STAFF;
  }

}
