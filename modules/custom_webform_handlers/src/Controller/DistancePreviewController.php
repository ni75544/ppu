<?php

namespace Drupal\custom_webform_handlers\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\custom_webform_handlers\Distance\DistanceProviderException;
use Drupal\custom_webform_handlers\Distance\DistanceProviderInterface;
use Drupal\custom_webform_handlers\Distance\DistanceResult;
use Drupal\custom_webform_handlers\Distance\RouteFactStore;
use Drupal\custom_webform_handlers\Distance\RouteFactStoreException;
use Drupal\custom_webform_handlers\Distance\RouteOperationLedger;
use Drupal\custom_webform_handlers\Distance\RouteRequestOwner;
use Drupal\custom_webform_handlers\Distance\RouteRequestOwnerException;
use Drupal\custom_webform_handlers\Distance\RoutingModeResolver;
use Drupal\custom_webform_handlers\Distance\RoutingPolicy;
use Drupal\custom_webform_handlers\Plugin\WebformHandler\AddressDistanceHandler;
use Drupal\custom_webform_handlers\Quote\QuoteCalculator;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Returns a throttled, non-authoritative distance preview.
 */
final class DistancePreviewController implements ContainerInjectionInterface {

  /**
   * Calculate a route for a new address pair, then take it into custody.
   *
   * The only operation that may reach a routing provider.
   */
  private const OPERATION_ROUTE_PREVIEW = 'route_preview';

  /**
   * Re-price an unchanged pair from a fact the server already holds.
   *
   * Never reaches a provider: it prices from stored custody or refuses.
   */
  private const OPERATION_QUOTE_ONLY = 'quote_only';

  /**
   * The closed set of operations this endpoint answers.
   */
  private const OPERATIONS = [
    self::OPERATION_ROUTE_PREVIEW,
    self::OPERATION_QUOTE_ONLY,
  ];

  /**
   * The only purpose this endpoint measures a route for.
   *
   * A browser preview and the price that follows it both ask the time-independent
   * question, which RoutingPolicy collapses onto the ordinary purpose. It is fixed
   * here, never taken from the request: the request may not choose a costlier
   * purpose, and the fact identity is bound to this value so a fact can never be
   * repriced as a purpose it was not measured for.
   */
  private const PURPOSE = RoutingPolicy::PURPOSE_ORDINARY;

  /**
   * How long, and how many times, to wait for the single-flight lock.
   *
   * A follower waits for the leader's provider call plus its store write, which is
   * bounded by the provider deadline. These cover that with margin; a follower
   * that still cannot acquire the lock re-reads the ledger one last time and, if
   * the leader has not recorded an outcome yet, is told the endpoint is busy
   * rather than starting a second billed calculation.
   */
  private const LOCK_ATTEMPTS = 40;
  private const LOCK_WAIT_SECONDS = 1;

  /**
   * Constructs the distance preview controller.
   */
  public function __construct(
    private readonly DistanceProviderInterface $distanceProvider,
    private readonly FloodInterface $flood,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
    private readonly QuoteCalculator $quoteCalculator,
    private readonly TimeInterface $time,
    private readonly RouteFactStore $routeFactStore,
    private readonly RoutingModeResolver $routingModeResolver,
    private readonly RouteOperationLedger $operationLedger,
    private readonly RouteRequestOwner $routeRequestOwner,
    private readonly LockBackendInterface $lock,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('custom_webform_handlers.customer_route_entry_provider'),
      $container->get('flood'),
      $container->get('config.factory'),
      $container->get('logger.channel.custom_webform_handlers'),
      $container->get('custom_webform_handlers.quote_calculator'),
      $container->get('datetime.time'),
      $container->get('custom_webform_handlers.route_fact_store'),
      $container->get('custom_webform_handlers.routing_mode_resolver'),
      $container->get('custom_webform_handlers.route_operation_ledger'),
      $container->get('custom_webform_handlers.route_request_owner'),
      $container->get('lock'),
    );
  }

  /**
   * Returns a throttled preliminary quote for one address pair.
   */
  public function preview(Request $request): JsonResponse {
    if (strlen((string) $request->getContent()) > 4096) {
      return $this->response(['status' => 'error', 'code' => 'invalid_request'], 413);
    }

    try {
      $data = json_decode((string) $request->getContent(), TRUE, 16, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      return $this->response(['status' => 'error', 'code' => 'invalid_request'], 400);
    }

    $origin = $this->normalizeAddressText((string) ($data['origin'] ?? ''));
    $destination = $this->normalizeAddressText((string) ($data['destination'] ?? ''));
    if ($origin === '' || $destination === '' || mb_strlen($origin) > 500 || mb_strlen($destination) > 500) {
      return $this->response(['status' => 'error', 'code' => 'invalid_address'], 400);
    }

    $config = $this->configFactory->get('custom_webform_handlers.settings');
    $limit = max(5, min(120, (int) ($config->get('preview_rate_limit') ?: 30)));
    $identifier = hash('sha256', (string) $request->getClientIp());
    $event = 'custom_webform_handlers.distance_preview';
    if (!$this->flood->isAllowed($event, $limit, 3600, $identifier)) {
      return $this->response(['status' => 'error', 'code' => 'rate_limited'], 429);
    }
    $this->flood->register($event, 3600, $identifier);

    // Which operation the browser is asking for is an explicit, named property
    // of the request, not something inferred from which fields happen to be
    // present. There are exactly two, and anything else — a missing value, an
    // unknown string, a mode nobody implemented — is refused here, before any
    // provider could be reached. The default is refusal, never calculation, so a
    // request that fails to say what it wants can never be treated as a licence
    // to buy a route.
    $operation = $this->operation($data);
    if ($operation === NULL) {
      return $this->response(['status' => 'error', 'code' => 'invalid_operation'], 400);
    }

    $mode = $this->routingModeResolver->effectiveMode();

    try {
      if ($operation === self::OPERATION_QUOTE_ONLY) {
        // A quote-only reprice must be priced against a fact the server itself
        // produced and still holds for this session, named by an opaque
        // reference. It never reaches a routing provider: a reference that is
        // missing, unknown, expired, for a different pair, purpose, or produced
        // under a different routing mode or a policy that has since moved yields a
        // typed refresh_required with no fallback. This is the server-side
        // once-only boundary — the browser's own deduplication is an optimisation
        // on top of it, not a substitute.
        $result = $this->routeFactStore->retrieve(
          $data['route_reference'] ?? NULL,
          $origin,
          $destination,
          $mode,
          self::PURPOSE,
        );
        if ($result === NULL) {
          return $this->response(['status' => 'error', 'code' => 'refresh_required'], 409);
        }
        $reference = '';
      }
      else {
        // A route_preview is the only operation permitted to ask a provider for a
        // route, and only for a genuinely new pair. It is idempotent by operation
        // id: concurrent requests for the same journey share one calculation, a
        // replay after a lost response returns the recorded result without a
        // second billed call, and the id may not be reused for a different
        // journey. resolvePreview() returns either a stored/newly-calculated fact
        // with its reference, or a typed refusal to return as-is.
        $resolved = $this->resolvePreview($data, $origin, $destination, $mode);
        if ($resolved instanceof JsonResponse) {
          return $resolved;
        }
        [$result, $reference] = $resolved;
      }

      $quote_input = $this->sanitizeQuoteInput($data['quote_input'] ?? []);
      $quote = $this->quoteCalculator->calculate($quote_input, $result);
      $payload = [
        'status' => 'success',
        'operation' => $operation,
        'distance' => $result->toArray(),
        'quote' => $quote->toArray(),
        'authoritative' => FALSE,
        'fingerprint' => $this->pairFingerprint($origin, $destination),
      ];
      // Only a route_preview mints a reference; a quote-only reprice reuses the
      // one the browser already holds, so it is not re-issued here.
      if ($reference !== '') {
        $payload['route_reference'] = $reference;
      }
      return $this->response($payload);
    }
    catch (RouteRequestOwnerException) {
      // No isolating owner could be established for this request (no session to
      // carry an anonymous identity). Refuse before any provider call rather than
      // serve under a shared owner; a real browser request always has a session,
      // so this is the safe boundary for the pathological contexts that do not.
      $this->logger->warning('Distance preview refused: no owner could be established for the request.');
      return $this->response(['status' => 'error', 'code' => 'routing_busy'], 503);
    }
    catch (DistanceProviderException $exception) {
      $this->logger->notice('Distance preview failed with classified code @code.', ['@code' => $exception->getMessage()]);
      return $this->response(['status' => 'error', 'code' => $exception->getMessage()], 422);
    }
  }

  /**
   * Resolves a route_preview idempotently, or returns a typed refusal.
   *
   * This is the deduplication-and-replay boundary. Every route_preview carries a
   * caller-minted operation id, and the ledger binds that id to the owner and the
   * canonical journey identity. The method never calls the provider more than
   * once for one recorded operation:
   *
   * - A recorded completion is replayed from the ledger's own copy of the fact,
   *   with no provider call, even if the route fact store has since evicted it.
   * - A recorded failure is returned as the same classified refusal, with no
   *   second billed attempt.
   * - The same id presented for a different journey is a conflict, never answered
   *   from the first journey's result.
   * - An abandoned pending record (its worker gone, the lock free) is refused as
   *   unknown, so a timed-out or lost operation cannot auto-authorize a new call.
   *
   * A first, genuinely new operation is serialised by a single-flight lock keyed
   * on owner and identity: a concurrent duplicate waits for the leader and then
   * replays its result rather than starting a second calculation. The provider is
   * called strictly between lock acquire and release, with no database
   * transaction and no reservation lock in scope, and the completion is persisted
   * to the ledger before the lock is released, so a replay that arrives after a
   * lost response finds the outcome already durable.
   *
   * @return array{0: \Drupal\custom_webform_handlers\Distance\DistanceResult, 1: string}|\Symfony\Component\HttpFoundation\JsonResponse
   *   A [result, reference] pair to price and return, or a JsonResponse refusal.
   *
   * @throws \Drupal\custom_webform_handlers\Distance\DistanceProviderException
   *   Rethrown from a first calculation so the caller renders the classified 422.
   */
  private function resolvePreview(array $data, string $origin, string $destination, string $mode): array|JsonResponse {
    // A route_preview that cannot be named cannot be made idempotent, so a
    // missing or malformed operation id is refused before a lock or a provider is
    // reached. There is no server-minted default: an unnameable buy-a-route
    // request is not one this endpoint answers.
    $operationId = $data['operation_id'] ?? NULL;
    if (!$this->operationLedger->isValidId($operationId)) {
      return $this->response(['status' => 'error', 'code' => 'invalid_operation'], 400);
    }

    $owner = $this->routeRequestOwner->token();
    $identity = $this->routeIdentity($owner, $origin, $destination, $mode);

    // Fast path: answer a repeat without taking the lock at all.
    $replay = $this->replay($this->operationLedger->lookup($owner, $operationId, $identity), $origin, $destination, $mode);
    if ($replay !== NULL) {
      return $replay;
    }

    // Single-flight. The lock name folds owner and identity, so only requests for
    // the very same journey by the same owner serialise; everything else runs
    // freely. It is routing-specific and unrelated to any reservation lock.
    $lockName = 'custom_webform_handlers.route_preview:' . hash('sha256', $identity);
    $acquired = FALSE;
    for ($attempt = 0; $attempt < self::LOCK_ATTEMPTS; $attempt++) {
      if ($this->lock->acquire($lockName)) {
        $acquired = TRUE;
        break;
      }
      // While waiting for the leader, the leader may finish. Re-read the ledger
      // each cycle so a follower replays the moment the result is durable rather
      // than waiting out the whole timeout.
      $replay = $this->replay($this->operationLedger->lookup($owner, $operationId, $identity), $origin, $destination, $mode);
      if ($replay !== NULL) {
        return $replay;
      }
      $this->lock->wait($lockName, self::LOCK_WAIT_SECONDS);
    }

    if (!$acquired) {
      // The leader is still working after the whole wait. One last ledger read;
      // if it has recorded nothing, tell the client the endpoint is busy rather
      // than starting a second billed calculation for the same journey.
      $replay = $this->replay($this->operationLedger->lookup($owner, $operationId, $identity), $origin, $destination, $mode);
      if ($replay !== NULL) {
        return $replay;
      }
      return $this->response(['status' => 'error', 'code' => 'routing_busy'], 503);
    }

    try {
      // A peer may have completed between the fast-path read and the acquire.
      $replay = $this->replay($this->operationLedger->lookup($owner, $operationId, $identity), $origin, $destination, $mode);
      if ($replay !== NULL) {
        return $replay;
      }

      // Same journey, different operation id. A peer already computed this exact
      // identity under another id (a duplicate submit, a second tab). Reuse that
      // result and bind this id to it, so two operation ids for one journey share a
      // single provider call rather than each buying the route. This is the check
      // that makes single-flight collapse by identity, not merely by operation id;
      // it fires only for a completed result, so a fresh id can still retry a
      // journey whose earlier attempt failed.
      $identityOutcome = $this->operationLedger->lookupIdentity($owner, $identity);
      if ($identityOutcome !== NULL) {
        $reused = $this->rebuildFromSnapshot($identityOutcome['snapshot'] ?? []);
        if ($reused !== NULL) {
          $this->operationLedger->complete($owner, $operationId, $identity, $identityOutcome['snapshot'], $reused->expiresAt());
          try {
            $reference = $this->routeFactStore->store($origin, $destination, $mode, self::PURPOSE, $reused);
          }
          catch (RouteFactStoreException) {
            return $this->response(['status' => 'error', 'code' => 'routing_busy'], 503);
          }
          return [$reused, $reference];
        }
      }

      // Claim the id as pending. A failure to claim means a record now exists
      // that the read above did not resolve — a pending one whose worker is gone.
      // Refuse it as unknown rather than compute: the prior attempt may have been
      // billed, and only a fresh operation id may try again.
      if (!$this->operationLedger->claimPending($owner, $operationId, $identity)) {
        return $this->response(['status' => 'error', 'code' => 'operation_unknown'], 409);
      }

      try {
        $result = $this->distanceProvider->calculate($origin, $destination);
      }
      catch (DistanceProviderException $exception) {
        // A billed attempt that the provider refused is recorded so its retries
        // return the same refusal, then rethrown for the caller's 422.
        $this->operationLedger->fail($owner, $operationId, $identity, $exception->getMessage());
        throw $exception;
      }

      // A provider result whose declared validity has already passed is stale on
      // arrival: it must not be priced, published or made reusable. Record a
      // terminal expired outcome (no payload retained) so a repeat of this id
      // returns refresh_required with no second provider call, and require a fresh,
      // separately authorized operation id to try the journey again.
      $resultExpiresAt = $result->expiresAt();
      if ($resultExpiresAt !== NULL && $resultExpiresAt <= $this->time->getCurrentTime()) {
        $this->operationLedger->recordExpired($owner, $operationId, $identity);
        return $this->response(['status' => 'error', 'code' => 'refresh_required'], 409);
      }

      // Persist completion BEFORE publishing the reference and before releasing the
      // lock. The provider has already been billed, so the outcome must be durable
      // even if the reference cannot be minted: a retry then replays this record
      // rather than calling the provider a second time. The ledger carries its own
      // copy of the fact, so replay also survives the fact store evicting it.
      $this->operationLedger->complete(
        $owner,
        $operationId,
        $identity,
        $this->snapshot($result),
        $result->expiresAt(),
      );

      try {
        $reference = $this->routeFactStore->store($origin, $destination, $mode, self::PURPOSE, $result);
      }
      catch (RouteFactStoreException) {
        // The fact map lock was unavailable, so custody could not be published. The
        // completion above is already durable, so a retry with this operation id
        // replays the recorded result without another provider call. Report busy.
        return $this->response(['status' => 'error', 'code' => 'routing_busy'], 503);
      }

      return [$result, $reference];
    }
    finally {
      $this->lock->release($lockName);
    }
  }

  /**
   * Turns a ledger outcome into a replay response, or NULL to compute.
   *
   * NULL means the ledger has nothing to answer with and the caller should claim
   * and compute. Every other outcome is answered without a provider call.
   *
   * @param array{state: string, snapshot?: array, code?: string}|null $outcome
   *   The ledger lookup result.
   *
   * @return array{0: \Drupal\custom_webform_handlers\Distance\DistanceResult, 1: string}|\Symfony\Component\HttpFoundation\JsonResponse|null
   *   A [result, reference] pair rebuilt from the recorded fact, a JsonResponse
   *   refusal, or NULL when there is nothing recorded yet.
   */
  private function replay(?array $outcome, string $origin, string $destination, string $mode): array|JsonResponse|null {
    if ($outcome === NULL) {
      return NULL;
    }
    switch ($outcome['state']) {
      case RouteOperationLedger::STATE_COMPLETED:
        $result = $this->rebuildFromSnapshot($outcome['snapshot'] ?? []);
        if ($result === NULL) {
          // A completion we cannot rebuild is not a licence to recompute under an
          // id already marked done: refuse and require a fresh commit.
          return $this->response(['status' => 'error', 'code' => 'refresh_required'], 409);
        }
        // Re-mint a reference from the replayed fact so the browser gets a live
        // handle for its next quote-only reprice. This is a local store write, not
        // a provider call — and if the fact map lock is unavailable it fails closed
        // to a busy result, still never calling the provider.
        try {
          $reference = $this->routeFactStore->store($origin, $destination, $mode, self::PURPOSE, $result);
        }
        catch (RouteFactStoreException) {
          return $this->response(['status' => 'error', 'code' => 'routing_busy'], 503);
        }
        return [$result, $reference];

      case RouteOperationLedger::STATE_FAILED:
        return $this->response(['status' => 'error', 'code' => $outcome['code'] ?? DistanceProviderException::CODE_REQUEST_FAILED], 422);

      case RouteOperationLedger::STATE_EXPIRED:
        // The operation completed (or arrived stale) but its route is no longer
        // valid and its payload is retired. Replay protection holds — no provider
        // call — but the fact cannot be repriced as fresh: tell the client to
        // re-commit, which mints a new operation id and buys a genuinely new route.
        return $this->response(['status' => 'error', 'code' => 'refresh_required'], 409);

      case 'conflict':
        // The id was already bound to a different journey. Answering from the
        // first journey's result would price the wrong route.
        return $this->response(['status' => 'error', 'code' => 'operation_conflict'], 409);

      case 'unknown':
        // An abandoned pending record. A timed-out or lost operation must not
        // auto-authorize another potentially billed request.
        return $this->response(['status' => 'error', 'code' => 'operation_unknown'], 409);

      case RouteOperationLedger::STATE_PENDING:
      default:
        // A live claim is in flight, or a state with no replay. The caller waits
        // on the lock rather than computing.
        return NULL;
    }
  }

  /**
   * The canonical identity a route_preview operation is bound to.
   *
   * The dimensions are exactly those that make two requests the same buy: the
   * owner, the ordered address pair, the routing mode, the fixed purpose, and the
   * resolved policy version (which folds in profile, dataset version and the
   * answering endpoint). A change to any of them is a different route and gets its
   * own operation. The policy version is '' under development-fixed mode, where no
   * live policy resolves; mode is part of the identity, so the two modes never
   * share an identity even when the policy version collapses.
   */
  private function routeIdentity(string $owner, string $origin, string $destination, string $mode): string {
    return hash('sha256', implode("\0", [
      $owner,
      DistanceResult::pairFingerprint($origin, $destination),
      $mode,
      self::PURPOSE,
      $this->policyVersion(),
    ]));
  }

  /**
   * The resolved policy version for this endpoint's purpose, or '' when none.
   *
   * Guarded exactly as RouteFactStore's is, and for the same reason: fromConfig()
   * raises NOT_CONFIGURED in development-fixed mode, which is not an error but the
   * absence of a live policy. Both places compute it the same way, so a stored
   * fact and the identity that guards its operation always agree.
   */
  private function policyVersion(): string {
    try {
      return RoutingPolicy::fromConfig(
        $this->configFactory->get('custom_webform_handlers.settings'),
        self::PURPOSE,
      )->version();
    }
    catch (DistanceProviderException) {
      return '';
    }
  }

  /**
   * The full fact fields the ledger keeps so a replay needs no provider.
   *
   * A superset of what a reference alone could recover, because the ledger is the
   * replay authority and must not depend on the route fact store still holding the
   * fact. These are the same fields RouteFactStore records, so rebuildFromSnapshot
   * is the exact inverse.
   */
  private function snapshot(DistanceResult $result): array {
    return [
      'distance_meters' => $result->distanceMeters(),
      'duration_seconds' => $result->durationSeconds(),
      'provider' => $result->provider(),
      'calculated_at' => $result->calculatedAt(),
      'encoded_polyline' => $result->encodedPolyline(),
      'traffic_aware' => $result->trafficAware(),
      'metadata' => $result->metadata(),
    ];
  }

  /**
   * Rebuilds a result from a ledger snapshot, or NULL when it is unusable.
   *
   * The inverse of snapshot(). Every field is coerced and the invariants
   * DistanceResult enforces are honoured, so a corrupt or truncated record yields
   * NULL — which the caller turns into a refusal, never a silent recompute.
   */
  private function rebuildFromSnapshot(array $snapshot): ?DistanceResult {
    $meters = (int) ($snapshot['distance_meters'] ?? 0);
    $seconds = (int) ($snapshot['duration_seconds'] ?? 0);
    $calculatedAt = (int) ($snapshot['calculated_at'] ?? 0);
    if ($meters <= 0 || $seconds < 0 || $calculatedAt <= 0) {
      return NULL;
    }
    try {
      return DistanceResult::restored(
        $meters,
        $seconds,
        (string) ($snapshot['provider'] ?? ''),
        $calculatedAt,
        is_array($snapshot['metadata'] ?? NULL) ? $snapshot['metadata'] : [],
        is_string($snapshot['encoded_polyline'] ?? NULL) ? $snapshot['encoded_polyline'] : NULL,
        (bool) ($snapshot['traffic_aware'] ?? FALSE),
      );
    }
    catch (\InvalidArgumentException) {
      return NULL;
    }
  }

  /**
   * Resolves the request's declared operation, or NULL when it is not valid.
   *
   * The operation is a named string the caller must send, drawn from a closed
   * set. It is deliberately not inferred from which other fields are present:
   * intent that can be read off the incidental shape of a payload is intent an
   * attacker can forge by adding or omitting a key. A missing operation and an
   * unknown one are treated identically — both return NULL, and the caller turns
   * NULL into a typed refusal without ever reaching a provider. There is no
   * default operation, because the only safe default for "buy a route or not" is
   * "not".
   */
  private function operation(array $data): ?string {
    $operation = $data['operation'] ?? NULL;

    return is_string($operation) && in_array($operation, self::OPERATIONS, TRUE)
      ? $operation
      : NULL;
  }

  /**
   * Collapses an address to the text the route cache is keyed on.
   *
   * The route provider's own cache identity already lowercases both endpoints,
   * but it does not collapse whitespace or comma spacing — so "123 Main St,
   * Springfield" and "123 Main St,Springfield" miss each other and buy the same
   * journey twice, from any number of browsers. Normalizing here feeds the
   * existing cache rather than adding a second one.
   *
   * This is semantically neutral for address resolution and invisible to the
   * response: DistanceResult carries distances, not the addresses it was asked
   * about. Case is deliberately left alone for the provider request; the shared
   * fingerprint helper folds it when identity, rather than display, is needed.
   */
  private function normalizeAddressText(string $value): string {
    return DistanceResult::normalizeAddressText($value);
  }

  /**
   * The identity of one ordered address pair.
   *
   * Echoed so a client, a log line, or an operator reading a response can say
   * which journey was priced without repeating the address. A route is
   * directional, so the pair is ordered and A to B never collides with B to A.
   *
   * A hash organizes identity. It is not anonymization and confers no licensing
   * right over the addresses it was computed from, and it is returned only to
   * the client that supplied them.
   */
  private function pairFingerprint(string $origin, string $destination): string {
    return DistanceResult::pairFingerprint($origin, $destination);
  }

  /**
   * Builds a private, non-cacheable JSON response.
   */
  private function response(array $data, int $status = 200): JsonResponse {
    $response = new JsonResponse($data, $status);
    $response->headers->set('Cache-Control', 'no-store, private');
    $response->headers->set('Pragma', 'no-cache');
    return $response;
  }

  /**
   * Reduces a browser-supplied quote payload to the pricing allowlist.
   *
   * The allowlist is AddressDistanceHandler::QUOTE_INPUT_FIELDS and never a copy
   * of it. A second list here silently starved QuoteCalculator's Express Courier
   * branch of delivery_mode, total_weight_lbs, total_weight_unit, courier_urgency
   * and prohibited_items_ack, so every courier preview came back
   * "manual_review" — missing_package_weight, missing_delivery_mode and
   * package_contents_require_review — while the Dispatch offer endpoint, which
   * receives the full value set, correctly reported the same request as
   * self-bookable and served Slots. One quote status cannot have two input
   * contracts.
   *
   * The caps stay here, because they are this endpoint's concern rather than the
   * calculator's: the offer path is fed by validated Webform values, whereas this
   * one is fed by an unauthenticated POST body.
   */
  private function sanitizeQuoteInput(mixed $input): array {
    if (!is_array($input)) {
      return [];
    }

    $clean = [];
    foreach (AddressDistanceHandler::QUOTE_INPUT_FIELDS as $key) {
      if (!array_key_exists($key, $input)) {
        continue;
      }
      $value = $input[$key];
      if (is_array($value)) {
        $clean[$key] = array_slice(array_values(array_filter(array_map(
          static fn ($item) => is_scalar($item) ? mb_substr(trim((string) $item), 0, 100) : '',
          $value,
        ))), 0, 20);
      }
      elseif (is_scalar($value)) {
        $clean[$key] = mb_substr(trim((string) $value), 0, 500);
      }
    }
    return $clean;
  }

}
