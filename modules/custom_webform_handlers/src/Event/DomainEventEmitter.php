<?php

namespace Drupal\custom_webform_handlers\Event;

use Drupal\Core\Database\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Dispatches a domain event after the transaction that justifies it has committed.
 *
 * Requirement 4 asks for one thing that is easy to state and easy to get wrong: a
 * domain event must be emitted only after its authoritative transaction commits,
 * never during it. A listener that reacts to "Reservation confirmed" by warming a
 * route must not run inside the guard transaction that is still deciding whether
 * the Reservation exists — if that transaction rolled back, the reaction would have
 * fired for something that never happened.
 *
 * Both of this module's emission sites already sit past their commit: decide()'s
 * compare-and-set has returned, and settlePaid() runs after the calendar guard's
 * transaction has closed. So in the ordinary case there is no open transaction and
 * this dispatches immediately. The post-commit callback is the defensive path: if a
 * future caller ever emits from inside a transaction, the event is deferred to the
 * commit and dropped on a rollback, rather than fired early.
 *
 * A listener that throws is contained here. Because a reaction is a consequence of
 * an authoritative fact and never a condition of it, a failed listener must not
 * turn a paid, committed booking into an error a customer sees — the failure is
 * logged by class and the fact stands. The class only, never the exception message:
 * something reacting to a booking can quote the data it was reacting to.
 */
final class DomainEventEmitter {

  public function __construct(
    private readonly EventDispatcherInterface $eventDispatcher,
    private readonly Connection $database,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Emits one event: now when no transaction is open, otherwise after it commits.
   *
   * @param object $event
   *   The event object to dispatch.
   * @param string $eventName
   *   The name to dispatch it under; the ECA event plugin subscribes to this.
   */
  public function emit(object $event, string $eventName): void {
    if (!$this->database->inTransaction()) {
      // The common path. Both real callers reach here, because both have committed
      // the write the event describes before they emit.
      $this->dispatch($event, $eventName);

      return;
    }

    try {
      // A transaction is open after all. Defer to its outcome — dispatch on commit,
      // stay silent on rollback — so the event can never describe a write that was
      // undone. The callback is handed the commit result.
      $this->database->transactionManager()->addPostTransactionCallback(function (bool $success) use ($event, $eventName): void {
        if ($success) {
          $this->dispatch($event, $eventName);
        }
      });
    }
    catch (\Throwable $exception) {
      // Registering the callback failed — no transaction manager, or the transaction
      // closed between the check and here. The caller only reaches emit() after its
      // own authoritative write returned, so dispatching now is still after the fact;
      // losing the event would be the worse outcome.
      $this->logger->warning('A domain event for @name could not be deferred and was dispatched immediately: @class.', [
        '@name' => $eventName,
        '@class' => get_class($exception),
      ]);
      $this->dispatch($event, $eventName);
    }
  }

  /**
   * Dispatches the event and contains any listener failure.
   */
  private function dispatch(object $event, string $eventName): void {
    try {
      $this->eventDispatcher->dispatch($event, $eventName);
    }
    catch (\Throwable $exception) {
      // A reaction failed. The authoritative fact it reacted to has already
      // committed and is not disturbed; only the reaction is lost, and it is logged
      // by class because a listener's message can carry what it was handling.
      $this->logger->error('A listener for domain event @name failed: @class.', [
        '@name' => $eventName,
        '@class' => get_class($exception),
      ]);
    }
  }

}
