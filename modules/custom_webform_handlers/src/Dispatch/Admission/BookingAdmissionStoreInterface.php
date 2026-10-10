<?php

namespace Drupal\custom_webform_handlers\Dispatch\Admission;

/**
 * The one per-submission job-exclusivity record for a booking.
 *
 * This is the contract for Task 24C's booking admission. Admission answers "is this
 * saved submission already being booked, and by whom" — a question about the job,
 * which is why it lives apart from the two driver-capacity families
 * (custom_webform_handlers_dispatch_reservation and custom_webform_handlers_reservation).
 * Those have different writers and different unique keys, so neither can gate the
 * other; a single per-submission admission record can. A binding — a Hold, a
 * checkout Attempt, a Reservation — must carry the generation the admission was at
 * when the binding was minted, so a later re-arm invalidates it.
 *
 * Ownership rests on a server-minted secret; the store keeps only its sha256 hash
 * and compares in constant time. The intent fingerprint is a deterministic hash of
 * the booking intent used to recognise same-intent retries — it is not an
 * authenticator, and a matching fingerprint with a mismatched owner is still
 * refused. Every mutation checks submission, generation, owner, and permitted prior
 * state before it writes.
 *
 * Fail-closed is the governing rule. The store creates no schema; if its table is
 * absent, every method throws AdmissionSchemaMissingException rather than returning
 * a benign answer. No operation is time-based: neither age, nor a lapsed Hold, nor
 * a missing recorded authorization releases an admission — only an explicit,
 * evidence-backed release does.
 */
interface BookingAdmissionStoreInterface {

  /**
   * The durable admission table. One row per submission; PRIMARY KEY submission_id.
   */
  public const TABLE = 'custom_webform_handlers_dispatch_booking_admission';

  /**
   * Owner-kind for the public customer checkout path.
   */
  public const PATH_PUBLIC = 'public';

  /**
   * Owner-kind for a staff-driven booking that bypasses the public checkout.
   */
  public const PATH_STAFF = 'staff';

  /**
   * Owner-kind for a manual or replan operation that bypasses the public checkout.
   */
  public const PATH_MANUAL = 'manual';

  /**
   * The owner-kinds the store accepts. Staff, manual, and replan bypass the
   * checkout attempt entirely, which is why admission cannot live on that table.
   */
  public const OWNER_KINDS = [self::PATH_PUBLIC, self::PATH_STAFF, self::PATH_MANUAL];

  /**
   * Acquires, reuses, or re-arms admission for a submission.
   *
   * No row yet grants a fresh admission at generation 1. A live row owned by the
   * same secret with the same intent is reused idempotently; a live row with a
   * different owner or a different intent is refused. A released row is re-armed on
   * an incremented generation. Fails closed if the schema is absent.
   */
  public function acquire(int $submissionId, string $ownerSecret, string $intentFingerprint, string $ownerKind): AdmissionResult;

  /**
   * Records confirmation against a pending admission, preserving ownership.
   *
   * Idempotent when already confirmed at the expected generation. Refuses on an
   * owner or generation mismatch, or from any state other than pending/confirmed.
   */
  public function confirm(int $submissionId, string $ownerSecret, int $expectedGeneration): AdmissionResult;

  /**
   * Flags a still-owned admission as needing operator reconciliation.
   *
   * A live transition, not a release: exclusivity is retained. Reachable from
   * pending or confirmed, idempotent once set, and requires a non-empty reason.
   */
  public function requireReconciliation(int $submissionId, string $ownerSecret, int $expectedGeneration, string $reason): AdmissionResult;

  /**
   * Releases a live admission with recorded evidence, retaining the row.
   *
   * The generation is not incremented (only a later re-arm does that), the row is
   * kept, and a non-empty evidence string is required. Refuses on an owner or
   * generation mismatch; idempotent when already released.
   */
  public function release(int $submissionId, string $ownerSecret, int $expectedGeneration, string $releaseEvidence): AdmissionResult;

  /**
   * Non-mutating ownership-and-liveness check at an expected generation.
   *
   * The primitive a future binding writer uses: call it under the writer's own
   * transaction so the admission row lock is held across the binding write.
   */
  public function verify(int $submissionId, string $ownerSecret, int $expectedGeneration): AdmissionResult;

  /**
   * Reads the current admission snapshot, or NULL when no row exists.
   *
   * NULL means "the table exists and holds no row for this submission", never
   * "could not check" — an absent table throws AdmissionSchemaMissingException.
   */
  public function find(int $submissionId): ?AdmissionRecord;

}
