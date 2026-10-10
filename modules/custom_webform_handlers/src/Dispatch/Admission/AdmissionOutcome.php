<?php

namespace Drupal\custom_webform_handlers\Dispatch\Admission;

/**
 * The single outcome word a booking-admission operation resolves to.
 *
 * Every store method returns one of these wrapped in an AdmissionResult. They fall
 * into two groups. The admissions and transitions (Admitted, Reused, Confirmed,
 * ReconciliationRequired, Released, Verified) each report a decision the caller may
 * act on. The refusals (RefusedOwner, RefusedIntent, RefusedState,
 * RefusedGeneration, NotFound) each report why the store declined, and a refusal
 * never carries permission to proceed. isRefusal() is the fail-closed test: a
 * caller that cannot positively read an admitting outcome must treat the operation
 * as denied.
 */
enum AdmissionOutcome: string {

  /**
   * A fresh admission was granted (first acquire, or re-arm from Released).
   */
  case Admitted = 'admitted';

  /**
   * A live admission already existed for the same owner and same intent.
   *
   * Idempotent success: the caller holds the admission it asked for. Distinct from
   * Admitted so a caller can tell a new grant from a replayed one.
   */
  case Reused = 'reused';

  /**
   * Confirmation was recorded (or was already present for this generation).
   */
  case Confirmed = 'confirmed';

  /**
   * The admission was moved to, or already sat in, reconciliation-required.
   */
  case ReconciliationRequired = 'reconciliation_required';

  /**
   * The admission was released (or was already released) with recorded evidence.
   */
  case Released = 'released';

  /**
   * A non-mutating check confirmed live ownership at the expected generation.
   */
  case Verified = 'verified';

  /**
   * The caller's secret did not match the recorded owner.
   */
  case RefusedOwner = 'refused_owner';

  /**
   * Ownership matched but the deterministic intent fingerprint did not.
   *
   * The job is already being booked under a different intent by the same owner; the
   * store refuses rather than silently retargeting the live admission.
   */
  case RefusedIntent = 'refused_intent';

  /**
   * The recorded state does not permit the requested transition.
   *
   * Also the fail-closed answer for a stored row whose state cannot be read.
   */
  case RefusedState = 'refused_state';

  /**
   * Ownership matched but the caller named a different generation.
   *
   * A stale request from an earlier generation cannot confirm, reconcile, or release
   * a later generation's admission.
   */
  case RefusedGeneration = 'refused_generation';

  /**
   * No admission row exists for the submission.
   *
   * A refusal, not a benign absence: a mutation cannot be applied to a row that is
   * not there, and the caller must not read this as "safe to proceed".
   */
  case NotFound = 'not_found';

  /**
   * Whether this outcome denies the caller.
   */
  public function isRefusal(): bool {
    return match ($this) {
      self::RefusedOwner,
      self::RefusedIntent,
      self::RefusedState,
      self::RefusedGeneration,
      self::NotFound => TRUE,
      default => FALSE,
    };
  }

  /**
   * Whether this outcome grants or reuses a live admission.
   */
  public function isAdmission(): bool {
    return $this === self::Admitted || $this === self::Reused;
  }

}
