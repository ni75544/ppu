<?php

declare(strict_types=1);

namespace Drupal\custom_webform_handlers\Prototype\R4;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Transaction;
use Drupal\Core\Database\Transaction\ClientConnectionTransactionState;
use Drupal\Core\Database\Transaction\StackItem;
use Drupal\Core\Database\Transaction\StackItemType;
use Drupal\mysql\Driver\Database\mysql\TransactionManager as MysqlTransactionManager;

/**
 * Task 24C-8C-R4 prototype — an inherited transaction manager that owns root outcomes.
 *
 * NOT application code. Extends the installed MySQL manager and inherits its behaviour; it adds
 * ONLY the outcome bookkeeping the installed manager does not expose. It never reads or writes the
 * parent's private state ($rootId, $stack, $voidedItems, $postTransactionCallbacks) and uses no
 * reflection, no bound-closure state capture of parent internals, and no copied core internals.
 *
 * Ownership is established in exactly ONE place: beginRoot() records an outcome entry for the id it
 * just pushed. Every other recording site checks isOwned() first, so a root pushed through the
 * inherited push() is never adopted mid-flight and keeps exact installed behaviour.
 *
 * What it overrides and why (every one calls parent to preserve installed behaviour):
 *  - addStackItem()        : observe the id of each new ROOT (the parent still stores it).
 *  - beginRoot()           : public entry that PUSHES a root and returns an opaque RootHandle.
 *  - voidStackItem()       : OBSERVE a voided id, so an owned root terminated by voidClientTransaction()
 *                            (or by an implicit server-side end) is classified Voided rather than left
 *                            NotCompleted. No behaviour change; the parent still moves the item.
 *  - unpile()              : record which id is resolving while the parent processes it.
 *  - processRootCommit()   : SUPPRESS the commit when a rollback for this root failed, and
 *                            classify Committed vs Voided vs Uncertain from the parent's state.
 *  - rollback()            : classify the rollback outcome and arm commit-suppression on failure.
 *  - addPostTransactionCallback(): wrap the callback so an owned root's void/uncertain outcome is
 *                            not delivered as success, and a callback throw is contained.
 */
final class TransactionManager extends MysqlTransactionManager {

  /** @var array<string,RootOutcome> outcome by owned root id. */
  private array $outcomes = [];

  /** @var array<string,bool> root ids whose client commit must be suppressed. */
  private array $suppressCommit = [];

  /** The id currently being resolved inside unpile()/rollback(). */
  private ?string $resolvingId = NULL;

  /** @var string[] class names of callbacks that threw for an owned root. */
  private array $callbackErrors = [];

  /**
   * Pushes an OWNED root transaction and returns it with an opaque handle.
   *
   * @param string $name
   *   Optional transaction name.
   *
   * @return array{0: \Drupal\Core\Database\Transaction, 1: \Drupal\custom_webform_handlers\Prototype\R4\RootHandle}
   *   The Transaction object (destroy it to resolve the root) and the handle. The handle does NOT
   *   hold the Transaction, so unset()-ing the Transaction still resolves the root.
   */
  public function beginRoot(string $name = ''): array {
    $this->pendingRootCapture = NULL;
    $transaction = parent::push($name);
    $id = $this->pendingRootCapture;
    $this->pendingRootCapture = NULL;
    if ($id !== NULL) {
      $this->outcomes[$id] = RootOutcome::NotCompleted;
    }
    return [$transaction, new RootHandle($this, (string) $id)];
  }

  /**
   * The outcome for one specific root id. Unknown/old ids are Uncertain, never another root's.
   */
  public function outcomeFor(string $id): RootOutcome {
    return $this->outcomes[$id] ?? RootOutcome::Uncertain;
  }

  /**
   * TRUE only for an id that beginRoot() put on record; an inherited push() root is never owned.
   */
  private function isOwned(?string $id): bool {
    return $id !== NULL && array_key_exists($id, $this->outcomes);
  }

  /** @return string[] */
  public function callbackErrors(): array {
    return $this->callbackErrors;
  }

  /**
   * {@inheritdoc}
   *
   * Only OBSERVES the root id; the parent still performs the storage.
   */
  protected function addStackItem(string $id, StackItem $item): void {
    if ($item->type === StackItemType::Root) {
      $this->pendingRootCapture = $id;
    }
    parent::addStackItem($id, $item);
  }

  /**
   * {@inheritdoc}
   *
   * OBSERVES a voided id so an OWNED root terminated by voidClientTransaction() — or by an implicit
   * server-side transaction end, which routes through the same call — is classified Voided instead
   * of remaining NotCompleted. The parent still moves the item; nothing here changes behaviour.
   *
   * A savepoint void is ignored: releasing a savepoint must never count as a root outcome. An unowned
   * root is ignored too, so the installed path is untouched.
   */
  protected function voidStackItem(string $id): void {
    if ($this->isOwned($id)) {
      // A savepoint id is never in $outcomes, so this only fires for a voided ROOT: releasing a
      // savepoint can never be mistaken for a root outcome.
      $this->outcomes[$id] = RootOutcome::Voided;
    }
    parent::voidStackItem($id);
  }

  /**
   * {@inheritdoc}
   *
   * Records the resolving id so processRootCommit()/callbacks can attribute the outcome to the
   * exact root being resolved.
   */
  public function unpile(string $name, string $id): void {
    $this->resolvingId = $id;
    try {
      parent::unpile($name, $id);
    }
    finally {
      $this->resolvingId = NULL;
    }
  }

  /**
   * {@inheritdoc}
   *
   * Suppresses the client commit when this root's rollback already failed, then classifies the
   * parent's post-commit state. This is the destructor-commit guard: the base manager would call
   * commitClientTransaction() (a real client commit) for a root left Active by a thrown rollback.
   */
  protected function processRootCommit(): void {
    $id = $this->resolvingId;
    if (!$this->isOwned($id)) {
      // Unowned root (an inherited push()): exact installed behaviour, no bookkeeping.
      parent::processRootCommit();
      return;
    }
    if ($this->suppressCommit[$id] ?? FALSE) {
      // A rollback for this root failed/uncertain earlier. Do not commit; record the failure.
      $this->setConnectionTransactionState(ClientConnectionTransactionState::RollbackFailed);
      $this->outcomes[$id] = RootOutcome::Failed;
      return;
    }
    parent::processRootCommit();
    $this->outcomes[$id] = match ($this->safeState()) {
      ClientConnectionTransactionState::Committed => RootOutcome::Committed,
      ClientConnectionTransactionState::Voided => RootOutcome::Voided,
      default => RootOutcome::Uncertain,
    };
  }

  /**
   * {@inheritdoc}
   *
   * Classifies the rollback outcome, and arms commit-suppression whenever the rollback is not a
   * confirmed success — so a later destruction cannot commit a root whose rollback failed.
   */
  public function rollback(string $name, string $id): void {
    $this->resolvingId = $id;
    try {
      parent::rollback($name, $id);
    }
    catch (\Throwable $e) {
      // The client rollback threw before the parent updated its state: the item may remain on the
      // stack and the parent state may still be Active. Arm suppression and record failure — but
      // only for an OWNED root; an unowned one keeps exact installed behaviour.
      if ($this->isOwned($id)) {
        $this->outcomes[$id] = RootOutcome::Failed;
        $this->suppressCommit[$id] = TRUE;
      }
      $this->resolvingId = NULL;
      throw $e;
    }

    if (!$this->isOwned($id)) {
      $this->resolvingId = NULL;
      return;
    }

    $this->outcomes[$id] = match ($this->safeState()) {
      ClientConnectionTransactionState::RolledBack => RootOutcome::RolledBack,
      ClientConnectionTransactionState::Voided => RootOutcome::Voided,
      ClientConnectionTransactionState::RollbackFailed => RootOutcome::Failed,
      default => RootOutcome::Uncertain,
    };
    if ($this->outcomes[$id] !== RootOutcome::RolledBack) {
      // Not a confirmed rollback (returns-FALSE, already-voided, or indeterminate): a later
      // destruction must not commit this root.
      $this->suppressCommit[$id] = TRUE;
    }
    $this->resolvingId = NULL;
  }

  /**
   * {@inheritdoc}
   *
   * Wraps the callback so an OWNED root's void/uncertain outcome is delivered as NOT success, and
   * a callback throw is contained (recorded) for owned roots. Registration failure is unchanged:
   * the parent still throws \LogicException when no transaction is active. An UNOWNED root (no
   * outcome entry) is passed straight through with the parent's value — the control.
   */
  public function addPostTransactionCallback(callable $callback): void {
    parent::addPostTransactionCallback(function (bool $success) use ($callback): void {
      $id = $this->resolvingId;
      if ($id !== NULL && array_key_exists($id, $this->outcomes)) {
        // Owned root: success is granted ONLY for a confirmed commit, never for void/uncertain.
        $mapped = $this->outcomes[$id] === RootOutcome::Committed;
        try {
          $callback($mapped);
        }
        catch (\Throwable $e) {
          // A callback throwing after a known commit does not undo it; record separately.
          $this->callbackErrors[] = get_class($e);
        }
        return;
      }
      // Unowned root: exact parent behaviour (value passed through, exceptions propagate).
      $callback($success);
    });
  }

  /**
   * Reads the parent's protected connection state, treating any failure as Uncertain.
   */
  private function safeState(): ClientConnectionTransactionState|RootOutcome {
    try {
      return $this->getConnectionTransactionState();
    }
    catch (\Throwable) {
      return RootOutcome::Uncertain;
    }
  }

}
