<?php

namespace Drupal\custom_webform_handlers\Dispatch\Admission;

/**
 * The result every booking-admission mutation and check returns.
 *
 * It pairs an outcome word with the admission snapshot it applies to and a short
 * machine-stable reason. The record is nullable because some outcomes have no row
 * to describe (NotFound, or a race that resolved to no readable row); callers must
 * not assume a record is present without checking the outcome first.
 *
 * The conveniences are deliberately asymmetric with a fail-closed bias.
 * isAdmitted() is true for a live admission that was either freshly granted or
 * reused; isRefused() is true for every denial. Neither is the negation of the
 * other — Confirmed, Released, Verified, and the transition outcomes are neither
 * an admission grant nor a refusal — so a caller deciding "may I proceed to book"
 * must test a positive admitting predicate, never !isRefused().
 *
 * isAdmitted() and isFreshAdmission() are NOT interchangeable. isAdmitted() is
 * true for Reused as well as Admitted, so it answers only "does this owner hold a
 * live admission for this intent". Authorizing a NEW downstream effect — another
 * hold, order, or payment — must gate on isFreshAdmission() (Admitted only): a
 * Reused outcome means the caller is replaying an admission it already holds and
 * must not spawn a second effect. Preventing that duplicate downstream side
 * effect remains an explicit integration responsibility of the binding caller;
 * this store distinguishes the two outcomes but neither executes nor suppresses
 * the downstream effect itself.
 */
final readonly class AdmissionResult {

  private function __construct(
    public AdmissionOutcome $outcome,
    public ?AdmissionRecord $record,
    public string $reason,
  ) {}

  /**
   * Builds a result.
   */
  public static function of(AdmissionOutcome $outcome, ?AdmissionRecord $record = NULL, string $reason = ''): self {
    return new self($outcome, $record, $reason);
  }

  /**
   * Whether a live admission was granted or reused.
   */
  public function isAdmitted(): bool {
    return $this->outcome->isAdmission();
  }

  /**
   * Whether the store denied the operation.
   */
  public function isRefused(): bool {
    return $this->outcome->isRefusal();
  }

  /**
   * Whether a brand-new admission was just granted (not a reuse of an existing one).
   *
   * True only for AdmissionOutcome::Admitted — a first acquire, or a re-arm from
   * Released. A caller that is about to create a NEW downstream effect (a hold, an
   * order, a payment) must gate on this, not isAdmitted(): isAdmitted() is also
   * true for Reused, which means the owner is replaying an admission it already
   * holds and must not trigger a second effect. This predicate distinguishes the
   * two; suppressing the duplicate effect itself is the binding caller's job.
   */
  public function isFreshAdmission(): bool {
    return $this->outcome === AdmissionOutcome::Admitted;
  }

}
