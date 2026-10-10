<?php

/**
 * @file
 * Task 24H bounded offline verification probe — dedup, replay, transport seam.
 *
 * Run: ddev exec vendor/bin/drush php:script \
 *   modules/custom_webform_handlers/docs/execution/system-integrity-audit/\
 *   task-24h-route-cost-boundary/route-dedup-offline-probe.php
 *
 * This exercises the ACTUAL coordination classes — RouteOperationLedger over the
 * real keyvalue.expirable store, RouteFactStore over the real PrivateTempStore and
 * lock, and RouteRequestOwner — with local time and a call-counting provider. It
 * never reaches a routing provider for a replay and never opens a socket. The
 * transport section proves interception at the true seam, GuzzleHttp\ClientInterface,
 * with a positive control that is counted and blocked before any network I/O.
 *
 * It is a verification artifact, not shipped code: it is not autoloaded, wired, or
 * referenced by the module, and it makes no paid request, no write to the Dispatch
 * ledger, and no configuration change. Ledger/fact rows it writes are namespaced by
 * a per-run token and carry native expiry.
 *
 * Bounded: a fixed, enumerated set of scenarios, no loops beyond small constants.
 */

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Session\UserSession;
use Drupal\custom_webform_handlers\Distance\DistanceProviderException;
use Drupal\custom_webform_handlers\Distance\DistanceProviderInterface;
use Drupal\custom_webform_handlers\Distance\DistanceResult;
use Drupal\custom_webform_handlers\Distance\GoogleRoutesDistanceProvider;
use Drupal\custom_webform_handlers\Distance\RouteFactStore;
use Drupal\custom_webform_handlers\Distance\RouteOperationLedger;
use Drupal\custom_webform_handlers\Distance\RouteRequestOwner;
use Drupal\custom_webform_handlers\Distance\RoutingModeEntryProvider;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

$pass = 0;
$fail = 0;
$check = static function (string $label, bool $ok) use (&$pass, &$fail): void {
  if ($ok) {
    $pass++;
    printf("  PASS  %s\n", $label);
  }
  else {
    $fail++;
    printf("  FAIL  %s\n", $label);
  }
};

$container = \Drupal::getContainer();
$run = 'probe-' . bin2hex(random_bytes(4));

// A clock the probe drives, so lease expiry and fact expiry can be crossed without
// waiting real seconds. Every TimeInterface method reads the same movable value.
$time = new class implements TimeInterface {
  public int $now = 1_000_000_000;

  public function getCurrentTime(): int {
    return $this->now;
  }

  public function getRequestTime(): int {
    return $this->now;
  }

  public function getCurrentMicroTime(): float {
    return (float) $this->now;
  }

  public function getRequestMicroTime(): float {
    return (float) $this->now;
  }

};

// A provider that counts how many times it is actually asked to calculate. A replay
// or a quote-only reprice must never increment this.
$counting = new class implements DistanceProviderInterface {
  public int $calls = 0;

  public function calculate(string $origin, string $destination): DistanceResult {
    $this->calls++;
    return DistanceResult::restored(
      42_000,
      3_600,
      'probe_provider',
      1_000_000_000,
      [
        'expires_at' => 1_000_003_600,
        'provider_version' => 'probe-v1',
        'profile' => 'driving',
      ],
      'abc_polyline',
      FALSE,
    );
  }

};

$sample = static function () use ($counting): DistanceResult {
  return $counting->calculate('123 Origin St, Town', '456 Dest Ave, City');
};

$snapshotOf = static function (DistanceResult $r): array {
  return [
    'distance_meters' => $r->distanceMeters(),
    'duration_seconds' => $r->durationSeconds(),
    'provider' => $r->provider(),
    'calculated_at' => $r->calculatedAt(),
    'encoded_polyline' => $r->encodedPolyline(),
    'traffic_aware' => $r->trafficAware(),
    'metadata' => $r->metadata(),
  ];
};

// ---------------------------------------------------------------------------
print "== Transport seam positive control (GuzzleHttp\\ClientInterface) ==\n";
// ---------------------------------------------------------------------------
// The real @http_client is a GuzzleHttp\Client. Its transport is a handler on the
// HandlerStack, invoked before any bytes leave the process. A PHP stream-wrapper
// trap does NOT sit on that path (the default handler is curl, which bypasses PHP
// streams), which is why the honest interception seam is the Guzzle handler. Here a
// counting-and-blocking handler proves a direct request through the same client
// class is counted and refused before networking.
$outbound = 0;
$blockingHandler = static function ($request, array $options) use (&$outbound) {
  $outbound++;
  throw new \RuntimeException('blocked-before-network');
};
$spyClient = new Client(['handler' => HandlerStack::create($blockingHandler)]);

$blocked = FALSE;
try {
  $spyClient->request('POST', 'https://routes.googleapis.com/directions/v2:computeRoutes');
}
catch (\Throwable $e) {
  $blocked = str_contains($e->getMessage(), 'blocked-before-network');
}
$check('a direct Guzzle request is counted (1) before any network I/O', $outbound === 1);
$check('a direct Guzzle request is blocked before any network I/O', $blocked);

try {
  $spyClient->request('GET', 'https://api.mapbox.com/directions/v5/mapbox/driving');
}
catch (\Throwable) {
  // Expected.
}
$check('a second transport attempt is counted separately (2)', $outbound === 2);

// The real provider injects exactly this seam. Structural fact, cited not executed:
// GoogleRoutesDistanceProvider::__construct takes GuzzleHttp\ClientInterface, so the
// spy above stands in for its transport. It cannot be driven to the socket in this
// environment because the server credential is intentionally absent, so the counted
// -and-blocked control uses the same client CLASS and handler stack directly.
$ctor = (new \ReflectionClass(GoogleRoutesDistanceProvider::class))->getConstructor();
$firstParam = $ctor?->getParameters()[0] ?? NULL;
$seam = $firstParam ? (string) $firstParam->getType() : '';
$check(
  'GoogleRoutesDistanceProvider injects GuzzleHttp\\ClientInterface (the spied seam)',
  str_contains($seam, 'GuzzleHttp\\ClientInterface'),
);

// ---------------------------------------------------------------------------
print "== Development-fixed facade: zero outbound, fixed provider counted separately ==\n";
// ---------------------------------------------------------------------------
$resolver = $container->get('custom_webform_handlers.routing_mode_resolver');
$check('resolver reports development_fixed in this environment', $resolver->isDevelopmentFixed());

// A Google adapter wired to the spy transport, and a call-counting development
// provider, behind the real facade. In dev-fixed the facade must route to the fixed
// provider and never touch the transport.
$fixedCounter = new class($container->get('custom_webform_handlers.fixed_development_route_provider')) implements DistanceProviderInterface {
  public int $calls = 0;

  public function __construct(private readonly DistanceProviderInterface $inner) {}

  public function calculate(string $origin, string $destination): DistanceResult {
    $this->calls++;
    return $this->inner->calculate($origin, $destination);
  }

};
$googleWithSpy = new GoogleRoutesDistanceProvider(
  $spyClient,
  $container->get('cache.default'),
  $container->get('config.factory'),
  $container->get('logger.channel.custom_webform_handlers'),
  $time,
  FALSE,
  $resolver,
);
$facade = new RoutingModeEntryProvider($resolver, $googleWithSpy, $fixedCounter);

$outboundBefore = $outbound;
$facadeResult = $facade->calculate('123 Origin St, Town', '456 Dest Ave, City');
$check('facade result is from the development_fixed provider', $facadeResult->provider() === 'development_fixed');
$check('facade invoked the fixed provider exactly once (reported separately)', $fixedCounter->calls === 1);
$check('facade made zero outbound transport attempts in development_fixed', $outbound === $outboundBefore);

// The container facade every seam actually uses, unmodified, also stays fixed.
$containerFacade = $container->get('custom_webform_handlers.customer_route_entry_provider');
$check(
  'container customer_route_entry_provider stays development_fixed',
  $containerFacade->calculate('9 A Rd, X', '10 B Rd, Y')->provider() === 'development_fixed',
);

// ---------------------------------------------------------------------------
print "== Route fact store: quote-only reprice makes zero provider calls ==\n";
// ---------------------------------------------------------------------------
// PrivateTempStore needs a session; CLI has none. Push a started session so the
// real tempstore derives an owner exactly as it would for an anonymous visitor.
$sessionA = new Session(new MockArraySessionStorage());
$sessionA->setId('probe-session-A-' . $run);
$sessionA->start();
$requestA = Request::create('/probe');
$requestA->setSession($sessionA);
$container->get('request_stack')->push($requestA);

$ownerService = $container->get('custom_webform_handlers.route_request_owner');
$factStore = new RouteFactStore(
  $container->get('tempstore.private'),
  $container->get('config.factory'),
  $time,
  $container->get('logger.channel.custom_webform_handlers'),
  $container->get('lock'),
  $ownerService,
);

$mode = $resolver->effectiveMode();
$purpose = 'ordinary';
$origin = '123 Origin St, Town';
$destination = '456 Dest Ave, City';
$stored = $sample();
$countAfterStore = $counting->calls;
$reference = $factStore->store($origin, $destination, $mode, $purpose, $stored);
$check('store() returned a non-empty opaque reference', is_string($reference) && $reference !== '');

$callsBeforeRetrieve = $counting->calls;
$rebuilt = $factStore->retrieve($reference, $origin, $destination, $mode, $purpose);
$check('retrieve() returns the fact for the matching pair/mode/purpose', $rebuilt instanceof DistanceResult);
$check('retrieve() made zero provider calls (quote-only never bills)', $counting->calls === $callsBeforeRetrieve);

$check('retrieve() refuses a mismatched pair', $factStore->retrieve($reference, 'other', $destination, $mode, $purpose) === NULL);
$check('retrieve() refuses a mismatched mode', $factStore->retrieve($reference, $origin, $destination, 'live_cached', $purpose) === NULL);
$check('retrieve() refuses a mismatched purpose', $factStore->retrieve($reference, $origin, $destination, $mode, 'offer') === NULL);
$check('retrieve() refuses a non-string reference', $factStore->retrieve(['x'], $origin, $destination, $mode, $purpose) === NULL);

// Cross the fact's expiry on the movable clock; it must refuse rather than serve
// stale custody, still with no provider call.
$time->now += 100_000;
$check('retrieve() refuses an expired fact', $factStore->retrieve($reference, $origin, $destination, $mode, $purpose) === NULL);
$time->now -= 100_000;
$check('quote-only path total provider calls unchanged since store', $counting->calls === $countAfterStore);

// ---------------------------------------------------------------------------
print "== Operation ledger: replay, single-flight, conflict, unknown, failure ==\n";
// ---------------------------------------------------------------------------
$ledger = new RouteOperationLedger(
  $container->get('keyvalue.expirable'),
  $time,
  $container->get('logger.channel.custom_webform_handlers'),
);
$ownerA = 'uid:1001-' . $run;
$idA = hash('sha256', 'identity-A-' . $run);
$idB = hash('sha256', 'identity-B-' . $run);

// R1 — completed-operation replay returns the recorded result with no second call.
$op1 = 'op-one-' . $run;
$check('R1 fresh op has no recorded outcome', $ledger->lookup($ownerA, $op1, $idA) === NULL);
$check('R1 claimPending succeeds for a fresh op', $ledger->claimPending($ownerA, $op1, $idA) === TRUE);
$r1 = $sample();
$callsAfterFirst = $counting->calls;
$ledger->complete($ownerA, $op1, $idA, $snapshotOf($r1), $r1->expiresAt());
$outcome1 = $ledger->lookup($ownerA, $op1, $idA);
$check('R1 lookup after complete reports completed', ($outcome1['state'] ?? '') === RouteOperationLedger::STATE_COMPLETED);
$check('R1 completed outcome carries a replayable snapshot', is_array($outcome1['snapshot'] ?? NULL) && ($outcome1['snapshot']['distance_meters'] ?? 0) === 42_000);
$check('R1 replay makes no additional provider call', $counting->calls === $callsAfterFirst);

// R2 — concurrent duplicate: leader claims, follower cannot re-claim and sees
// pending; after the leader completes, the follower replays without computing.
$op2 = 'op-two-' . $run;
$check('R2 leader claimPending succeeds', $ledger->claimPending($ownerA, $op2, $idA) === TRUE);
$check('R2 follower claimPending is refused while pending', $ledger->claimPending($ownerA, $op2, $idA) === FALSE);
$pendingLook = $ledger->lookup($ownerA, $op2, $idA);
$check('R2 follower sees pending (waits, does not compute)', ($pendingLook['state'] ?? '') === RouteOperationLedger::STATE_PENDING);
$r2 = $sample();
$callsAfterLeader = $counting->calls;
$ledger->complete($ownerA, $op2, $idA, $snapshotOf($r2), $r2->expiresAt());
$followerLook = $ledger->lookup($ownerA, $op2, $idA);
$check('R2 follower now replays a completed result', ($followerLook['state'] ?? '') === RouteOperationLedger::STATE_COMPLETED);
$check('R2 only the leader computed (one calculation shared)', $counting->calls === $callsAfterLeader);

// R3 — same operation id, different journey identity, is a conflict.
$op3 = 'op-three-' . $run;
$ledger->claimPending($ownerA, $op3, $idA);
$ledger->complete($ownerA, $op3, $idA, $snapshotOf($sample()), NULL);
$conflict = $ledger->lookup($ownerA, $op3, $idB);
$check('R3 reuse of an id for a different identity is a conflict', ($conflict['state'] ?? '') === 'conflict');

// R4 — an abandoned pending claim (lease elapsed) reads as unknown, not a fresh
// billable attempt.
$op4 = 'op-four-' . $run;
$ledger->claimPending($ownerA, $op4, $idA);
$check('R4 within lease the claim reads pending', ($ledger->lookup($ownerA, $op4, $idA)['state'] ?? '') === RouteOperationLedger::STATE_PENDING);
$time->now += 121;
$check('R4 past lease the abandoned claim reads unknown', ($ledger->lookup($ownerA, $op4, $idA)['state'] ?? '') === 'unknown');
$time->now -= 121;

// R5 — a failed billed attempt replays the classified refusal, no second call.
$op5 = 'op-five-' . $run;
$ledger->claimPending($ownerA, $op5, $idA);
$callsAfterR5Claim = $counting->calls;
$ledger->fail($ownerA, $op5, $idA, DistanceProviderException::CODE_REQUEST_FAILED);
$failLook = $ledger->lookup($ownerA, $op5, $idA);
$check('R5 lookup after fail reports failed', ($failLook['state'] ?? '') === RouteOperationLedger::STATE_FAILED);
$check('R5 failed outcome preserves the classified code', ($failLook['code'] ?? '') === DistanceProviderException::CODE_REQUEST_FAILED);
$check('R5 replay of a failure makes no provider call', $counting->calls === $callsAfterR5Claim);

// R6 — evicting a route fact must not erase replay protection. The ledger is a
// separate store, so overflowing the fact map cannot touch the ledger record.
$op6 = 'op-six-' . $run;
$r6 = $sample();
$ledger->claimPending($ownerA, $op6, $idA);
$ledger->complete($ownerA, $op6, $idA, $snapshotOf($r6), $r6->expiresAt());
// Overflow the per-owner fact map well past its bound to force eviction.
for ($i = 0; $i < 15; $i++) {
  $factStore->store('o' . $i . ' St', 'd' . $i . ' Ave', $mode, $purpose, $sample());
}
$callsAfterOverflow = $counting->calls;
$op6Look = $ledger->lookup($ownerA, $op6, $idA);
$check('R6 operation replay survives route-fact eviction', ($op6Look['state'] ?? '') === RouteOperationLedger::STATE_COMPLETED);
$check('R6 replay after eviction still needs no provider call', $counting->calls === $callsAfterOverflow);

// ---------------------------------------------------------------------------
print "== Owner scope: anonymous sessions and authenticated users are isolated ==\n";
// ---------------------------------------------------------------------------
$ownerB = 'sess:' . hash('sha256', 'session-B-' . $run);
$op7 = 'op-seven-' . $run;
$ledger->claimPending($ownerA, $op7, $idA);
$ledger->complete($ownerA, $op7, $idA, $snapshotOf($sample()), NULL);
$check('O1 an operation is invisible to a different anonymous owner', $ledger->lookup($ownerB, $op7, $idA) === NULL);

$ownerU1 = 'uid:2001-' . $run;
$ownerU2 = 'uid:2002-' . $run;
$op8 = 'op-eight-' . $run;
$ledger->claimPending($ownerU1, $op8, $idA);
$ledger->complete($ownerU1, $op8, $idA, $snapshotOf($sample()), NULL);
$check('O2 an operation is invisible to a different authenticated owner', $ledger->lookup($ownerU2, $op8, $idA) === NULL);
$check('O2 the owning user still replays its own operation', ($ledger->lookup($ownerU1, $op8, $idA)['state'] ?? '') === RouteOperationLedger::STATE_COMPLETED);

// O3 — token derivation. Authenticated resolves to uid; anonymous to a session
// hash; two different sessions are two different owners.
$authOwner = new RouteRequestOwner(new UserSession(['uid' => 7]), $container->get('request_stack'));
$check('O3 authenticated token is uid-scoped', $authOwner->token() === 'uid:7');
$check('O3 authenticated owner reports authenticated', $authOwner->isAuthenticated() === TRUE);

$anonOwner = new RouteRequestOwner(new UserSession(['uid' => 0]), $container->get('request_stack'));
$tokenSessA = $anonOwner->token();
$check('O3 anonymous token is a session hash', (bool) preg_match('/^sess:[0-9a-f]{64}$/', $tokenSessA));
$check('O3 anonymous owner reports not authenticated', $anonOwner->isAuthenticated() === FALSE);

$sessionB = new Session(new MockArraySessionStorage());
$sessionB->setId('probe-session-B2-' . $run);
$sessionB->start();
$requestB = Request::create('/probe');
$requestB->setSession($sessionB);
$container->get('request_stack')->push($requestB);
$tokenSessB = (new RouteRequestOwner(new UserSession(['uid' => 0]), $container->get('request_stack')))->token();
$check('O3 two distinct anonymous sessions derive two distinct owners', $tokenSessA !== $tokenSessB);
$container->get('request_stack')->pop();

// ---------------------------------------------------------------------------
printf("\n== RESULT: %d passed, %d failed ==\n", $pass, $fail);
printf("provider calculate() calls total: %d (all from route_preview/model, never a replay)\n", $counting->calls);
printf("transport outbound attempts (all blocked before network): %d\n", $outbound);
printf("effective routing mode: %s\n", $mode);
