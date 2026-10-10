<?php

namespace Drupal\custom_webform_handlers\Dispatch\Admission;

/**
 * Thrown when the booking-admission table does not exist, or cannot be proven to.
 *
 * This is the fail-closed marker for Task 24C-1. The admission store creates no
 * schema and is wired to nothing, so the only way it runs is an explicit,
 * authorized invocation. If that invocation reaches a site whose migration has
 * not been applied, the correct answer is not "admitted" and not "no admission
 * exists" — both of those would let a caller proceed as though job exclusivity had
 * been decided when it has not. The correct answer is to stop.
 *
 * A RuntimeException rather than a domain result on purpose: a missing table is an
 * operational precondition failure, not one of the lifecycle outcomes a caller is
 * expected to branch on. Every entry point on the store asserts the schema before
 * it does anything else, so this cannot be swallowed as a benign NULL.
 */
final class AdmissionSchemaMissingException extends \RuntimeException {

  /**
   * Builds the exception for a named table.
   */
  public static function forTable(string $table): self {
    return new self(sprintf(
      'Booking admission table "%s" is absent or could not be inspected; admission enforcement is inactive and fails closed. Apply the Task 24C migration before invoking the admission store.',
      $table,
    ));
  }

}
