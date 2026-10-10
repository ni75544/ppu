<?php

namespace Drupal\custom_webform_handlers\Dispatch\Admission;

/**
 * An immutable read of one booking-admission row.
 *
 * A value object, not a live handle: it is a snapshot taken under the row lock at
 * the moment the store read it, and it never writes back. Callers that need to bind
 * a Hold, Attempt, or Reservation to this admission must carry the generation
 * recorded here, so a later re-arm (which increments the generation) invalidates a
 * binding minted against an earlier one.
 *
 * owner_token_hash is a sha256 of a server-minted secret; the secret itself is
 * never stored and never appears here. intentFingerprint is a deterministic hash of
 * the job's booking intent — it detects same-intent retries and is explicitly not
 * an authenticator.
 */
final readonly class AdmissionRecord {

  private function __construct(
    public int $submissionId,
    public int $generation,
    public AdmissionState $state,
    public string $ownerTokenHash,
    public string $intentFingerprint,
    public string $ownerKind,
    public ?string $note,
    public int $createdAt,
    public int $updatedAt,
    public ?int $confirmedAt,
    public ?int $releasedAt,
  ) {}

  /**
   * Hydrates a record from a fetched row, or NULL when the row cannot be read.
   *
   * NULL is returned for an unreadable state or a non-positive submission id rather
   * than a coerced default. The store logs the NULL and turns it into a refusal, so
   * a corrupt row can never be mistaken for a live or a released admission.
   */
  public static function fromRow(object $row): ?self {
    $state = AdmissionState::fromStored(isset($row->state) ? (string) $row->state : NULL);
    $submissionId = (int) ($row->submission_id ?? 0);
    if ($state === NULL || $submissionId < 1) {
      return NULL;
    }

    return new self(
      $submissionId,
      (int) ($row->generation ?? 0),
      $state,
      (string) ($row->owner_token_hash ?? ''),
      (string) ($row->intent_fingerprint ?? ''),
      (string) ($row->owner_kind ?? ''),
      isset($row->note) ? (string) $row->note : NULL,
      (int) ($row->created_at ?? 0),
      (int) ($row->updated_at ?? 0),
      isset($row->confirmed_at) ? (int) $row->confirmed_at : NULL,
      isset($row->released_at) ? (int) $row->released_at : NULL,
    );
  }

  /**
   * Whether this snapshot still holds job exclusivity.
   */
  public function isLive(): bool {
    return $this->state->isLive();
  }

}
