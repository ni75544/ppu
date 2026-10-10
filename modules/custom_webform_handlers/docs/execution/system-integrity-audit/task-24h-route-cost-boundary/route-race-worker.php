<?php

/**
 * @file
 * Task 24H multi-process race worker (one independent process per invocation).
 *
 * Driven by route-race-orchestrator.php, never run by hand. Each worker is a
 * separate `drush php:script` OS process, so the ledger (DB-backed
 * keyvalue.expirable) and the single-flight lock (Redis) are genuinely shared
 * across processes — this is a real race, not a modelled one.
 *
 * A worker drives the REAL DistancePreviewController::preview() with a
 * COUNTING provider: every genuine calculate() writes ONE UNIQUE key into a
 * shared DB-backed keyvalue.expirable collection, so the orchestrator can count
 * the true cross-process provider-call total after all workers exit. Unique keys
 * mean no lost update; the DB store (not the Mutagen-synced filesystem) is what
 * makes the count visible across processes and on the host. A replay or a reused
 * calculation writes nothing.
 *
 * Env in:
 *   RACE_OP        - operation_id to send (may be shared or unique per worker)
 *   RACE_ORIGIN    - origin address
 *   RACE_DEST      - destination address
 *   RACE_COUNTER   - keyvalue.expirable collection used as the shared call counter
 *   RACE_OWNERKEY  - shared session id, so every worker resolves the same owner
 *   RACE_QUOTE     - optional 'weight' to vary quote input (does not change identity)
 *
 * Prints one line: WORKER <pid> status=<http> code=<code> ref=<0|1>
 *
 * No paid request (development_fixed), no config change, no Dispatch write.
 */

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\custom_webform_handlers\Controller\DistancePreviewController;
use Drupal\custom_webform_handlers\Distance\DistanceProviderException;
use Drupal\custom_webform_handlers\Distance\DistanceProviderInterface;
use Drupal\custom_webform_handlers\Distance\DistanceResult;
use Drupal\custom_webform_handlers\Distance\RouteFactStore;
use Drupal\custom_webform_handlers\Distance\RouteOperationLedger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

$container = \Drupal::getContainer();

$op = (string) getenv('RACE_OP');
$origin = (string) getenv('RACE_ORIGIN');
$destination = (string) getenv('RACE_DEST');
$counterCollection = (string) getenv('RACE_COUNTER');
$ownerKey = (string) getenv('RACE_OWNERKEY');
$quoteWeight = (string) getenv('RACE_QUOTE');

// A provider that records each genuine calculation by writing ONE UNIQUE key to a
// shared DB-backed keyvalue.expirable collection. Unique keys never collide, so
// there is no lost update, and the DB store is visible across processes and on the
// host (unlike a Mutagen-synced file). The key count is the true call total.
$counterStore = $container->get('keyvalue.expirable')->get($counterCollection);
$counting = new class($counterStore) implements DistanceProviderInterface {
  public function __construct(private readonly object $counterStore) {}
  public function calculate(string $origin, string $destination): DistanceResult {
    $this->counterStore->setWithExpire(getmypid() . '-' . uniqid('', TRUE), 1, 900);
    // A short spin so overlapping workers actually contend on the lock rather
    // than the leader finishing before the followers start. Bounded, no I/O.
    usleep(200_000);
    // Live-relative timestamps. The ledger caps a completed record's TTL at
    // (expires_at - now), so a past expires_at would collapse the dedup window to
    // one second — long enough for a single in-process replay, but not for workers
    // that `ddev exec` cold-starts several seconds apart. A future expiry keeps the
    // completion durable across the whole spawn stagger, so what the race measures
    // is the single-flight guarantee, not fixture timestamp decay.
    $now = time();
    return DistanceResult::restored(
      42_000, 3_600, 'race_provider', $now,
      ['expires_at' => $now + 900, 'provider_version' => 'race-v1', 'profile' => 'driving'],
      'race_polyline', FALSE,
    );
  }
};

$permissiveFlood = new class implements \Drupal\Core\Flood\FloodInterface {
  public function register($name, $window = 3600, $identifier = NULL): void {}
  public function clear($name, $identifier = NULL): void {}
  public function isAllowed($name, $threshold, $window = 3600, $identifier = NULL): bool { return TRUE; }
  public function garbageCollection(): void {}
};

// The REAL Redis lock — shared across processes. This is the single-flight seam.
$lock = $container->get('lock');

// A real, shared session id so every worker resolves the SAME owner token and
// therefore the same ledger identity for the same journey.
$session = new Session(new MockArraySessionStorage());
$session->setId($ownerKey);
$session->start();
$request0 = Request::create('/probe');
$request0->setSession($session);
$container->get('request_stack')->push($request0);

$controller = new DistancePreviewController(
  $counting,
  $permissiveFlood,
  $container->get('config.factory'),
  $container->get('logger.channel.custom_webform_handlers'),
  $container->get('custom_webform_handlers.quote_calculator'),
  $container->get('datetime.time'),
  new RouteFactStore(
    $container->get('tempstore.private'),
    $container->get('config.factory'),
    $container->get('datetime.time'),
    $container->get('logger.channel.custom_webform_handlers'),
    $lock,
    $container->get('custom_webform_handlers.route_request_owner'),
  ),
  $container->get('custom_webform_handlers.routing_mode_resolver'),
  new RouteOperationLedger(
    $container->get('keyvalue.expirable'),
    $container->get('datetime.time'),
    $container->get('logger.channel.custom_webform_handlers'),
  ),
  $container->get('custom_webform_handlers.route_request_owner'),
  $lock,
);

$body = [
  'origin' => $origin,
  'destination' => $destination,
  'operation' => 'route_preview',
  'operation_id' => $op,
  'quote_input' => ['delivery_mode' => 'standard', 'total_weight_lbs' => $quoteWeight ?: '5'],
];
$request = Request::create('/probe', 'POST', [], [], [], [], json_encode($body));
$request->setSession($session);

// Overlap evidence: record when this worker entered and left preview() into a
// shared DB-backed collection. The orchestrator reads all workers' spans and checks
// that max(start) < min(end) — a real instant when every worker was inside preview()
// at once. Without this, a provider-call count of one is equally consistent with
// workers running sequentially and reusing a cached fact; with it, the single call
// is proven to be single-flight collapsing genuinely concurrent work. Wall-clock
// milliseconds; the leader's 200ms in-provider spin makes the window comfortably
// measurable against process spawn jitter.
$spanStore = $container->get('keyvalue.expirable')->get($counterCollection . ':spans');
$startMs = (int) round(microtime(TRUE) * 1000);

$response = $controller->preview($request);

$endMs = (int) round(microtime(TRUE) * 1000);
$spanStore->setWithExpire(getmypid() . '-' . uniqid('', TRUE), ['start' => $startMs, 'end' => $endMs], 900);

$decoded = json_decode($response->getContent(), TRUE) ?: [];
printf(
  "WORKER %d status=%d code=%s ref=%d start=%d end=%d\n",
  getmypid(),
  $response->getStatusCode(),
  $decoded['code'] ?? $decoded['status'] ?? '?',
  isset($decoded['route_reference']) ? 1 : 0,
  $startMs,
  $endMs,
);
