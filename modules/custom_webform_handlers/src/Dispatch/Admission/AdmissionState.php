<?php

namespace Drupal\custom_webform_handlers\Dispatch\Admission;

/**
 * Where one submission's booking admission has got to.
 *
 * Admission is job exclusivity, not driver capacity. It answers "is this saved
 * submission already being booked, and by whom", which is a question about the job;
 * the two capacity families answer "is this driver's day sold", which is a question
 * about the calendar. Keeping the two in separate stores is deliberate: a single
 * capacity table cannot gate both writers, and a job-exclusivity record must
 * outlive any one Hold, Attempt, or Reservation the job passes through.
 *
 * Four states, and the important asymmetry is Released. A released admission is not
 * deleted — the row is retained so its generation can be read back — but it is the
 * only state a fresh acquisition may re-arm from, and re-arming increments the
 * generation rather than resetting it. Every other state is live: it names a
 * current owner whose ownership survives pending, confirmation, and reconciliation
 * untouched. Nothing here expires. Age, a lapsed Hold, or a missing recorded
 * authorization never move a row to Released; only an explicit, evidence-backed
 * release does.
 */
enum AdmissionState: string {

  /**
   * Admission has been acquired; no confirmation has been recorded yet.
   *
   * The starting state, reachable from nothing (a first acquire) or from Released
   * (a reacquire on a new generation).
   */
  case Pending = 'pending';

  /**
   * The paid or authoritative flow has confirmed against this admission.
   *
   * Ownership and generation are unchanged from Pending; only the recorded fact of
   * confirmation is added. Reachable back to reconciliation, never to Pending.
   */
  case Confirmed = 'confirmed';

  /**
   * Confirmation happened but something downstream needs an operator.
   *
   * A live state, distinct from Released: the job is still owned and still
   * exclusive. It exists so a failure after confirmation is visible without
   * surrendering the exclusivity that confirmation established.
   */
  case ReconciliationRequired = 'reconciliation_required';

  /**
   * Admission was explicitly released with recorded evidence.
   *
   * The one non-live state, and the only one a new acquisition may re-arm from. The
   * row is retained; the generation it holds is the floor the next acquisition
   * increments past, so a stale request can never release a later generation's
   * admission by mistake.
   */
  case Released = 'released';

  /**
   * Whether this state still holds job exclusivity.
   */
  public function isLive(): bool {
    return $this !== self::Released;
  }

  /**
   * Whether a fresh acquisition may re-arm from this state.
   */
  public function allowsReacquire(): bool {
    return $this === self::Released;
  }

  /**
   * Returns the state a stored value names, or NULL when it names none.
   *
   * NULL rather than a default: an unreadable state is never treated as live, and
   * never treated as safely released. The store turns a NULL here into a refusal,
   * not into an admission.
   */
  public static function fromStored(?string $stored): ?self {
    return $stored === NULL ? NULL : self::tryFrom(trim($stored));
  }

}
