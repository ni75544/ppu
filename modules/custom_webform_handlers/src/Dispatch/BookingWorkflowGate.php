<?php

namespace Drupal\custom_webform_handlers\Dispatch;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\custom_webform_handlers\Reservation\ReservationDefinitionProvider;
use Drupal\webform\WebformInterface;

/**
 * The one authority on which booking workflow may act for a Webform.
 *
 * Before this class existed the answer was spread across three places that each
 * knew part of it: DispatchRequestFactory::dispatchOwns() knew when the planner
 * may enforce, ReservationDefinitionProvider::isEnabled() knew when the
 * fixed-slot workflow may enforce, and the public controllers knew neither —
 * each simply served its own workflow and trusted configuration to keep them
 * apart. That is enough while only one workflow is live. It is not enough during
 * a cutover, because the two Hold families live in different tables and cannot
 * see each other, so nothing structural stopped both from selling the same
 * minute of the same driver's day.
 *
 * This class does not re-derive either of those two predicates. It calls them,
 * so "dispatch is configured here" and "reservations are switched on here" keep
 * exactly one definition each, and adds the one decision that was missing: which
 * of them is allowed to answer. Callers get a single value from
 * workflowFor(), and the if/else in decide() is what makes it single — a Webform
 * cannot come back as both, whatever the underlying flags say.
 *
 * It decides nothing about price, eligibility, capacity, or payment, and it
 * cannot open a workflow that is not configured. Its only powers are to say no
 * and to say which of two yesses applies.
 */
final class BookingWorkflowGate {

  /**
   * The operating profile that records which Webforms have been transferred.
   */
  const SETTINGS = 'custom_webform_handlers.dispatch';

  /**
   * The one handler that owns booking on a Webform, in either workflow.
   */
  const HANDLER_ID = 'address_distance_handler';

  /**
   * The configuration path listing Webforms an operator moved to the planner.
   *
   * Shipped empty, and empty is the whole point: a Webform cannot become
   * Dispatch-owned by a handler checkbox alone. Task 5 adds the homepage form to
   * this list once its offer-only interface exists, and until something is
   * written here every Webform on the site is Legacy or Disabled.
   */
  const TRANSFER_KEY = 'workflow.dispatch_webforms';

  /**
   * Answers already reached on this request, keyed by Webform ID.
   *
   * A route access checker and the controller behind it ask the same question
   * about the same Webform on one request, and answering it twice would load the
   * same configuration entity twice. Scoped to the request, like every other
   * service in this module, so a configuration change is never masked beyond the
   * request that was already in flight when it happened.
   *
   * @var array<string, \Drupal\custom_webform_handlers\Dispatch\BookingWorkflow>
   */
  private array $decided = [];

  /**
   * Whether any Webform still belongs to the fixed-slot workflow, once asked.
   */
  private ?bool $legacyReachable = NULL;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly DispatchRequestFactory $requestFactory,
    private readonly ReservationDefinitionProvider $definitionProvider,
  ) {}

  /**
   * Returns the one workflow that may act for a Webform.
   */
  public function workflowFor(string $webformId): BookingWorkflow {
    $webformId = trim($webformId);
    if (isset($this->decided[$webformId])) {
      return $this->decided[$webformId];
    }

    return $this->decided[$webformId] = $this->decide($webformId);
  }

  /**
   * Tells a caller whether the workflow it serves owns this Webform.
   *
   * The caller names the workflow it is, rather than asking what the Webform is
   * and comparing: a controller that compares can forget a case, and a
   * controller that declares itself cannot.
   */
  public function allows(string $webformId, BookingWorkflow $workflow): bool {
    return $this->workflowFor($webformId) === $workflow;
  }

  /**
   * Tells whether the fixed-slot workflow still owns any Webform on this site.
   *
   * This exists for one caller: the legacy release endpoint, which is the only
   * public booking operation that names no Webform. It carries a Hold token and a
   * session identity and nothing else, so there is no Webform to ask about — and
   * accepting one from the request body would not help, because a caller wanting
   * past the gate would simply name a Webform that passes it.
   *
   * A site-wide answer is the honest substitute. Releasing narrows a claim rather
   * than making one, it can only ever reach the fixed-slot table, and while any
   * Webform is still Legacy there are live Holds that must stay releasable — the
   * alternative is stranding a visitor's capacity until it lapses. Once the last
   * Webform has left the workflow the endpoint closes, so the family can be
   * retired completely rather than left as a permanently open mutation.
   *
   * Access is deliberately not checked on the listing: this is a server-side
   * question about configuration, asked on behalf of an anonymous visitor who has
   * no permission to enumerate Webforms and is never shown the result.
   */
  public function legacyReachable(): bool {
    if ($this->legacyReachable !== NULL) {
      return $this->legacyReachable;
    }

    $ids = $this->entityTypeManager->getStorage('webform')->getQuery()
      ->accessCheck(FALSE)
      ->execute();

    foreach ($ids as $id) {
      if ($this->workflowFor((string) $id) === BookingWorkflow::Legacy) {
        return $this->legacyReachable = TRUE;
      }
    }

    return $this->legacyReachable = FALSE;
  }

  /**
   * Reaches the one answer for a Webform, in one pass with one return each way.
   *
   * The order matters. An explicit transfer is read first, and a transferred
   * Webform never falls back to the fixed-slot workflow: if the transfer is only
   * half-applied — dispatch listed here but its handler settings incomplete, or
   * the older reservation switch still on — the honest answer is that no workflow
   * may act, not that the workflow the operator was leaving quietly resumes. A
   * fallback there is how a form ends up taking bookings into the table its
   * operator believed it had stopped using.
   *
   * A Webform that was never transferred cannot become Dispatch-owned here at
   * all, whatever its handler says. That is what makes the safe default
   * structural rather than a matter of configuration hygiene.
   */
  private function decide(string $webformId): BookingWorkflow {
    $webform = $this->loadWebform($webformId);
    if ($webform === NULL) {
      return BookingWorkflow::Disabled;
    }

    if (isset($this->transferred()[$webformId])) {
      return $this->requestFactory->dispatchOwns($webformId)
        ? BookingWorkflow::Dispatch
        : BookingWorkflow::Disabled;
    }

    return $this->legacyOwns($webform)
      ? BookingWorkflow::Legacy
      : BookingWorkflow::Disabled;
  }

  /**
   * Reads the transferred Webforms as a set, discarding anything unusable.
   *
   * Returned keyed rather than as a list so a lookup is an isset() and not a
   * search. Anything that is not a non-empty string is dropped rather than
   * coerced: this list decides which workflow holds capacity, so a malformed
   * entry must fail to match rather than match something.
   *
   * @return array<string, true>
   */
  private function transferred(): array {
    $configured = $this->configFactory->get(self::SETTINGS)->get(self::TRANSFER_KEY);
    if (!is_array($configured)) {
      return [];
    }

    $ids = [];
    foreach ($configured as $id) {
      if (!is_scalar($id)) {
        continue;
      }
      $id = trim((string) $id);
      if ($id !== '') {
        $ids[$id] = TRUE;
      }
    }

    return $ids;
  }

  /**
   * Tells whether the fixed-slot workflow is switched on for a Webform.
   *
   * The enable test is ReservationDefinitionProvider's own, called rather than
   * copied, so an administrator's explicit choice and the legacy
   * mapping-as-opt-in rule keep one definition.
   *
   * What is deliberately not tested here is whether the mapping is complete
   * enough to serve a booking. That check already exists downstream and already
   * refuses with 'reservation_not_configured'. Repeating it here would move a
   * half-configured live form from Legacy to Disabled, which changes when a
   * workflow that is currently taking real bookings refuses — and this gate's
   * job is to keep the two families apart, not to re-adjudicate a form the
   * fixed-slot workflow already handles.
   */
  private function legacyOwns(WebformInterface $webform): bool {
    foreach ($webform->getHandlers() as $handler) {
      if ($handler->getPluginId() !== self::HANDLER_ID || !$handler->isEnabled()) {
        continue;
      }
      $settings = $handler->getConfiguration()['settings'] ?? [];
      if (!is_array($settings)) {
        continue;
      }
      if ($this->definitionProvider->isEnabled($webform, (string) $handler->getHandlerId(), $settings)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Loads a Webform by machine name, refusing anything that is not one.
   *
   * The same guard DispatchRequestFactory applies, applied here because this
   * class needs the entity itself: the fixed-slot enable test takes a Webform,
   * while the dispatch test takes an ID. The alternative — widening another
   * class's surface so this one can borrow its loader — would couple two seams
   * for four lines of validation.
   */
  private function loadWebform(string $webformId): ?WebformInterface {
    if ($webformId === '' || strlen($webformId) > 128 || preg_match('/^[a-z0-9_]+$/', $webformId) !== 1) {
      return NULL;
    }

    $webform = $this->entityTypeManager->getStorage('webform')->load($webformId);

    return $webform instanceof WebformInterface ? $webform : NULL;
  }

}
