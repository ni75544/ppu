<?php

declare(strict_types=1);

namespace Drupal\custom_webform_handlers\Prototype\R4;

/**
 * Task 24C-8C-R4 prototype — outcome of one OWNED root transaction.
 *
 * NOT application code. Not referenced by Drupal execution; loaded only by the standalone
 * demonstration under this directory. It exists to answer one question: can an inherited
 * MySQL driver extension establish an owned-root outcome and prevent destructor-driven commit?
 */
enum RootOutcome: string {

  /** Opened but not yet resolved (no commit, rollback, or void observed). */
  case NotCompleted = 'not_completed';

  /** The client transaction was actually committed. */
  case Committed = 'committed';

  /** The client transaction was actually rolled back. */
  case RolledBack = 'rolled_back';

  /** The client transaction was voided (e.g. an implicit server-side commit). */
  case Voided = 'voided';

  /** A rollback was attempted and failed (returned FALSE, or threw before state update). */
  case Failed = 'failed';

  /** The outcome could not be established (e.g. state unreadable, or client already out). */
  case Uncertain = 'uncertain';

}
