<?php

declare(strict_types=1);

namespace Drupal\custom_webform_handlers\Prototype\R4;

/**
 * Task 24C-8C-R4 prototype — an opaque handle to ONE owned root transaction.
 *
 * NOT application code. Loaded only by the standalone demonstration in this directory.
 *
 * Opaque on purpose: the caller holds an id it never inspects and asks the manager for that
 * root's outcome. A handle for an old, already-resolved, or unknown root can therefore never be
 * confused with another root's success — lookups are keyed by the id this handle owns.
 */
final class RootHandle {

  public function __construct(
    private readonly TransactionManager $manager,
    public readonly string $id,
  ) {}

  /**
   * Returns the outcome recorded for THIS root, or Uncertain for an unknown id.
   */
  public function outcome(): RootOutcome {
    return $this->manager->outcomeFor($this->id);
  }

}
