<?php

/**
 * @file
 * Task 24H controller-path probe, run against the FINAL implementation.
 *
 * Run: ddev exec vendor/bin/drush php:script \
 *   modules/custom_webform_handlers/docs/execution/system-integrity-audit/\
 *   task-24h-route-cost-boundary/route-controller-offline-probe.php
 *
 * This drives the REAL DistancePreviewController::preview() end to end over the
 * real RouteOperationLedger (keyvalue.expirable), the real RouteFactStore
 * (PrivateTempStore + lock) and RouteRequestOwner, with a call-counting provider,
 * a movable clock and a permissive flood. It exercises the whole operation matrix
 * and, specifically, the two seams the prior report left unverified:
 *
 *  - fail-closed fact-store lock: a lock backend that cannot be acquired yields a
 *    typed busy result and NO unlocked write to the shared map;
 *  - persist-before-publish: a store() failure AFTER the provider was billed and
 *    the ledger completed still leaves the completion durable, so a retry replays
 *    it with no second provider call.
 *
 * It also proves outbound interception through the ACTUAL injected adapter client
 * — the real GoogleRoutesDistanceProvider driven in live_cached mode with a
 * synthetic key and a counting-and-blocking Guzzle handler — with a positive
 * control counted and blocked before any network I/O. No real key, no billed
 * request, no config change, no Dispatch write. Rows carry a per-run token and
 * native expiry.
 *
 * Bounded: a fixed, enumerated set of scenarios, no unbounded loops.
 */

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Site\Settings;
use Drupal\custom_webform_handlers\Controller\DistancePreviewController;
use Drupal\custom_webform_handlers\Distance\DistanceProviderException;
use Drupal\custom_webform_handlers\Distance\DistanceProviderInterface;
use Drupal\custom_webform_handlers\Distance\DistanceResult;
use Drupal\custom_webform_handlers\Distance\GoogleRoutesDistanceProvider;
use Drupal\custom_webform_handlers\Distance\RouteFactStore;
use Drupal\custom_webform_handlers\Distance\RouteFactStoreException;
use Drupal\custom_webform_handlers\Distance\RouteOperationLedger;
use Drupal\custom_webform_handlers\Distance\RoutingModeResolver;
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
$run = 'ctl-' . bin2hex(random_bytes(4));

$time = new class implements TimeInterface {
  public int $now = 1_000_000_000;
  public function getCurrentTime(): int { return $this->now; }
  public function getRequestTime(): int { return $this->now; }
  public function getCurrentMicroTime(): float { return (float) $this->now; }
  public function getRequestMicroTime(): float { return (float) $this->now; }
};

// A provider that counts genuine calculations. Any replay or quote-only reprice
// that increments this is a defect.
$counting = new class implements DistanceProviderInterface {
  public int $calls = 0;
  public bool $throwNext = FALSE;
  public function calculate(string $origin, string $destination): DistanceResult {
    $this->calls++;
    if ($this->throwNext) {
      $this->throwNext = FALSE;
      throw DistanceProviderException::classified(DistanceProviderException::NO_ROUTE, 'probe_provider');
    }
    return DistanceResult::restored(
      42_000, 3_600, 'probe_provider', 1_000_000_000,
      ['expires_at' => 1_000_003_600, 'provider_version' => 'probe-v1', 'profile' => 'driving'],
      'abc_polyline', FALSE,
    );
  }
};

// A flood that always allows, so the matrix is not perturbed by rate limiting;
// the rate-limit branch is exercised explicitly with its own strict flood below.
$permissiveFlood = new class implements \Drupal\Core\Flood\FloodInterface {
  public function register($name, $window = 3600, $identifier = NULL): void {}
  public function clear($name, $identifier = NULL): void {}
  public function isAllowed($name, $threshold, $window = 3600, $identifier = NULL): bool { return TRUE; }
  public function garbageCollection(): void {}
};

// A lock that always grants (the controller's single-flight lock in the happy path).
$grantingLock = new class implements LockBackendInterface {
  public function acquire($name, $timeout = 30.0): bool { return TRUE; }
  public function lockMayBeAvailable($name): bool { return TRUE; }
  public function wait($name, $delay = 30): bool { return FALSE; }
  public function release($name): void {}
  public function releaseAll($lock_id = NULL): void {}
  public function getLockId(): string { return 'probe-lock'; }
};

// A lock that NEVER grants — for the fail-closed fact-store test.
$denyingLock = new class implements LockBackendInterface {
  public int $acquireCalls = 0;
  public function acquire($name, $timeout = 30.0): bool { $this->acquireCalls++; return FALSE; }
  public function lockMayBeAvailable($name): bool { return FALSE; }
  public function wait($name, $delay = 30): bool { return TRUE; }
  public function release($name): void {}
  public function releaseAll($lock_id = NULL): void {}
  public function getLockId(): string { return 'deny-lock'; }
};

$resolver = $container->get('custom_webform_handlers.routing_mode_resolver');
$quote = $container->get('custom_webform_handlers.quote_calculator');
$logger = $container->get('logger.channel.custom_webform_handlers');

// A real session so PrivateTempStore and RouteRequestOwner derive a real owner.
$session = new Session(new MockArraySessionStorage());
$session->setId('ctl-session-' . $run);
$session->start();
$request0 = Request::create('/probe');
$request0->setSession($session);
$container->get('request_stack')->push($request0);
$ownerService = $container->get('custom_webform_handlers.route_request_owner');

$factStore = new RouteFactStore(
  $container->get('tempstore.private'),
  $container->get('config.factory'),
  $time,
  $logger,
  $grantingLock,
  $ownerService,
);
$ledger = new RouteOperationLedger($container->get('keyvalue.expirable'), $time, $logger);

$makeController = static function (
  DistanceProviderInterface $provider,
  RouteFactStore $store,
  LockBackendInterface $lock,
) use ($container, $permissiveFlood, $quote, $time, $resolver, $ledger, $ownerService, $logger): DistancePreviewController {
  return new DistancePreviewController(
    $provider,
    $permissiveFlood,
    $container->get('config.factory'),
    $logger,
    $quote,
    $time,
    $store,
    $resolver,
    $ledger,
    $ownerService,
    $lock,
  );
};

$controller = $makeController($counting, $factStore, $grantingLock);

// Helper to build a JSON POST request through the same session.
$post = static function (array $body) use ($session): Request {
  $request = Request::create('/probe', 'POST', [], [], [], [], json_encode($body));
  $request->setSession($session);
  return $request;
};
$decode = static function ($response): array {
  return json_decode($response->getContent(), TRUE) ?: [];
};

$origin = '123 Origin St, Town';
$destination = '456 Dest Ave, City';
$validQuote = ['delivery_mode' => 'standard'];

// ---------------------------------------------------------------------------
print "== Operation gate: nothing routes without an explicit, valid operation ==\n";
// ---------------------------------------------------------------------------
$c0 = $counting->calls;
$r = $controller->preview($post(['origin' => $origin, 'destination' => $destination]));
$check('missing operation -> 400 invalid_operation', $r->getStatusCode() === 400 && ($decode($r)['code'] ?? '') === 'invalid_operation');
$r = $controller->preview($post(['origin' => $origin, 'destination' => $destination, 'operation' => 'route_previeW']));
$check('unknown operation string -> 400 invalid_operation', $r->getStatusCode() === 400 && ($decode($r)['code'] ?? '') === 'invalid_operation');
$r = $controller->preview($post(['origin' => $origin, 'destination' => $destination, 'operation' => ['route_preview']]));
$check('non-string operation -> 400 invalid_operation', $r->getStatusCode() === 400);
$check('operation gate reached no provider', $counting->calls === $c0);

// ---------------------------------------------------------------------------
print "== route_preview requires a valid operation_id ==\n";
// ---------------------------------------------------------------------------
$c0 = $counting->calls;
$r = $controller->preview($post(['origin' => $origin, 'destination' => $destination, 'operation' => 'route_preview']));
$check('route_preview with no operation_id -> 400 invalid_operation', $r->getStatusCode() === 400 && ($decode($r)['code'] ?? '') === 'invalid_operation');
$r = $controller->preview($post(['origin' => $origin, 'destination' => $destination, 'operation' => 'route_preview', 'operation_id' => 'short']));
$check('route_preview with a too-short operation_id -> 400', $r->getStatusCode() === 400);
$r = $controller->preview($post(['origin' => $origin, 'destination' => $destination, 'operation' => 'route_preview', 'operation_id' => 'bad id!!']));
$check('route_preview with a bad-charset operation_id -> 400', $r->getStatusCode() === 400);
$check('operation_id gate reached no provider', $counting->calls === $c0);

// ---------------------------------------------------------------------------
print "== quote_only never reaches a provider ==\n";
// ---------------------------------------------------------------------------
$c0 = $counting->calls;
$r = $controller->preview($post(['origin' => $origin, 'destination' => $destination, 'operation' => 'quote_only']));
$check('quote_only with no reference -> 409 refresh_required', $r->getStatusCode() === 409 && ($decode($r)['code'] ?? '') === 'refresh_required');
$r = $controller->preview($post(['origin' => $origin, 'destination' => $destination, 'operation' => 'quote_only', 'route_reference' => 'nope-not-a-real-ref']));
$check('quote_only with an unknown reference -> 409 refresh_required', $r->getStatusCode() === 409);
$check('quote_only refusals reached no provider', $counting->calls === $c0);

// ---------------------------------------------------------------------------
print "== route_preview: first calc bills once, mints a reference ==\n";
// ---------------------------------------------------------------------------
$op = 'op-first-' . $run;
$c0 = $counting->calls;
$r = $controller->preview($post(['origin' => $origin, 'destination' => $destination, 'operation' => 'route_preview', 'operation_id' => $op, 'quote_input' => $validQuote]));
$body = $decode($r);
$check('first route_preview -> 200 success', $r->getStatusCode() === 200 && ($body['status'] ?? '') === 'success');
$check('first route_preview billed exactly one provider call', $counting->calls === $c0 + 1);
$check('first route_preview minted a route_reference', is_string($body['route_reference'] ?? NULL) && $body['route_reference'] !== '');
$check('first route_preview reports non-authoritative', ($body['authoritative'] ?? TRUE) === FALSE);
$firstRef = $body['route_reference'] ?? '';

// quote_only against that minted reference: prices, zero provider calls.
$c0 = $counting->calls;
$r = $controller->preview($post(['origin' => $origin, 'destination' => $destination, 'operation' => 'quote_only', 'route_reference' => $firstRef, 'quote_input' => $validQuote]));
$check('quote_only against the minted reference -> 200', $r->getStatusCode() === 200 && ($decode($r)['status'] ?? '') === 'success');
$check('quote_only reprice made zero provider calls', $counting->calls === $c0);

// ---------------------------------------------------------------------------
print "== Replay: same op id replays, no second billed call ==\n";
// ---------------------------------------------------------------------------
$c0 = $counting->calls;
$r = $controller->preview($post(['origin' => $origin, 'destination' => $destination, 'operation' => 'route_preview', 'operation_id' => $op, 'quote_input' => $validQuote]));
$check('replay of the same operation_id -> 200 success', $r->getStatusCode() === 200 && ($decode($r)['status'] ?? '') === 'success');
$check('replay made no additional provider call', $counting->calls === $c0);

// ---------------------------------------------------------------------------
print "== Dedup by identity: a second op id for the same journey reuses the calc ==\n";
// ---------------------------------------------------------------------------
$op2 = 'op-second-' . $run;
$c0 = $counting->calls;
$r = $controller->preview($post(['origin' => $origin, 'destination' => $destination, 'operation' => 'route_preview', 'operation_id' => $op2, 'quote_input' => $validQuote]));
$check('a different op id for the same journey -> 200 success', $r->getStatusCode() === 200 && ($decode($r)['status'] ?? '') === 'success');
$check('same-journey second op id reused the calc (no new provider call)', $counting->calls === $c0);

// ---------------------------------------------------------------------------
print "== Conflict: the same op id for a different journey is refused ==\n";
// ---------------------------------------------------------------------------
$c0 = $counting->calls;
$r = $controller->preview($post(['origin' => 'DIFFERENT ORIGIN', 'destination' => $destination, 'operation' => 'route_preview', 'operation_id' => $op, 'quote_input' => $validQuote]));
$check('reused op id, different journey -> 409 operation_conflict', $r->getStatusCode() === 409 && ($decode($r)['code'] ?? '') === 'operation_conflict');
$check('conflict reached no provider (answered from ledger)', $counting->calls === $c0);

// ---------------------------------------------------------------------------
print "== Failure replay: a billed refusal replays the classified code ==\n";
// ---------------------------------------------------------------------------
$opFail = 'op-fail-' . $run;
$counting->throwNext = TRUE;
$c0 = $counting->calls;
$r = $controller->preview($post(['origin' => 'FAIL ORIGIN', 'destination' => 'FAIL DEST', 'operation' => 'route_preview', 'operation_id' => $opFail, 'quote_input' => $validQuote]));
$check('a provider refusal -> 422 with the classified public code', $r->getStatusCode() === 422 && ($decode($r)['code'] ?? '') === DistanceProviderException::CODE_NOT_FOUND);
$check('the refusal billed exactly one attempt', $counting->calls === $c0 + 1);
$c0 = $counting->calls;
$r = $controller->preview($post(['origin' => 'FAIL ORIGIN', 'destination' => 'FAIL DEST', 'operation' => 'route_preview', 'operation_id' => $opFail, 'quote_input' => $validQuote]));
$check('replay of the failed op -> 422 same code, no second billed attempt', $r->getStatusCode() === 422 && $counting->calls === $c0);

// ---------------------------------------------------------------------------
print "== Body guards ==\n";
// ---------------------------------------------------------------------------
$oversized = Request::create('/probe', 'POST', [], [], [], [], str_repeat('x', 5000));
$oversized->setSession($session);
$r = $controller->preview($oversized);
$check('oversized body -> 413 invalid_request', $r->getStatusCode() === 413);

// Rate-limit branch, with a strict flood that denies.
$strictFlood = new class implements \Drupal\Core\Flood\FloodInterface {
  public function register($name, $window = 3600, $identifier = NULL): void {}
  public function clear($name, $identifier = NULL): void {}
  public function isAllowed($name, $threshold, $window = 3600, $identifier = NULL): bool { return FALSE; }
  public function garbageCollection(): void {}
};
$rlController = new DistancePreviewController(
  $counting, $strictFlood, $container->get('config.factory'), $logger, $quote, $time,
  $factStore, $resolver, $ledger, $ownerService, $grantingLock,
);
$c0 = $counting->calls;
$r = $rlController->preview($post(['origin' => $origin, 'destination' => $destination, 'operation' => 'route_preview', 'operation_id' => 'op-rl-' . $run]));
$check('flood-denied request -> 429 rate_limited, no provider call', $r->getStatusCode() === 429 && $counting->calls === $c0);

// ---------------------------------------------------------------------------
print "== Fail-closed fact-store lock: a busy result, never an unlocked write ==\n";
// ---------------------------------------------------------------------------
// A fact store whose per-owner write lock can never be acquired. store() must
// throw RouteFactStoreException and leave the shared map untouched.
$denyStore = new RouteFactStore(
  $container->get('tempstore.private'),
  $container->get('config.factory'),
  $time,
  $logger,
  $denyingLock,
  $ownerService,
);
$tempBefore = $container->get('tempstore.private')->get('custom_webform_handlers.route_facts')->get('facts');
$threw = FALSE;
try {
  $denyStore->store($origin, $destination, $resolver->effectiveMode(), 'ordinary', $counting->calculate('L', 'R'));
}
catch (RouteFactStoreException) {
  $threw = TRUE;
}
$tempAfter = $container->get('tempstore.private')->get('custom_webform_handlers.route_facts')->get('facts');
$check('store() under an unavailable lock throws RouteFactStoreException', $threw);
$check('the fact-store lock was actually attempted (LOCK_ATTEMPTS times)', $denyingLock->acquireCalls >= 5);
$check('the shared fact map was left untouched (no unlocked write)', $tempBefore === $tempAfter);

// Now the controller behaviour: a fresh route_preview whose fact store fails
// closed must return busy AND keep the completion durable for replay.
$denyController = $makeController($counting, $denyStore, $grantingLock);
$opBusy = 'op-busy-' . $run;
$c0 = $counting->calls;
$r = $denyController->preview($post(['origin' => 'BUSY ORIGIN', 'destination' => 'BUSY DEST', 'operation' => 'route_preview', 'operation_id' => $opBusy, 'quote_input' => $validQuote]));
$check('route_preview whose custody publish fails -> 503 routing_busy', $r->getStatusCode() === 503 && ($decode($r)['code'] ?? '') === 'routing_busy');
$check('the busy publish still billed exactly one provider call', $counting->calls === $c0 + 1);

// The completion is durable: a retry with a WORKING fact store replays it, no
// second provider call. This is persist-before-publish proven at the controller.
$c0 = $counting->calls;
$r = $controller->preview($post(['origin' => 'BUSY ORIGIN', 'destination' => 'BUSY DEST', 'operation' => 'route_preview', 'operation_id' => $opBusy, 'quote_input' => $validQuote]));
$body = $decode($r);
$check('retry after a publish failure -> 200 success (completion was durable)', $r->getStatusCode() === 200 && ($body['status'] ?? '') === 'success');
$check('retry after a publish failure made NO second provider call', $counting->calls === $c0);
$check('retry after a publish failure minted a fresh reference', is_string($body['route_reference'] ?? NULL) && $body['route_reference'] !== '');

// ---------------------------------------------------------------------------
print "== Transport interception through the REAL injected adapter client ==\n";
// ---------------------------------------------------------------------------
// The honest live-mode seam is GoogleRoutesDistanceProvider's injected Guzzle
// client. Drive the REAL adapter in live_cached mode with a synthetic key and a
// counting-and-blocking handler; the request must be counted and blocked before
// any byte leaves the process. No real key, no billed call.
$outbound = 0;
$blockingHandler = static function ($request, array $options) use (&$outbound) {
  $outbound++;
  throw new \RuntimeException('blocked-before-network');
};
$spyClient = new Client(['handler' => HandlerStack::create($blockingHandler)]);
// Positive control: a bare request through the same client class is counted+blocked.
$blocked = FALSE;
try { $spyClient->request('POST', GoogleRoutesDistanceProvider::class); }
catch (\Throwable $e) { $blocked = str_contains($e->getMessage(), 'blocked-before-network'); }
$check('positive control: a direct request is counted (1) before network', $outbound === 1);
$check('positive control: a direct request is blocked before network', $blocked);

// A synthetic server key so the adapter proceeds PAST the missing-credential
// guard to the transport seam. Settings is re-created in-process only; nothing is
// written to disk and no real credential is touched.
$existing = Settings::getAll();
new Settings(['custom_webform_handlers_server_api_key' => 'SYNTHETIC-PROBE-KEY-NOT-REAL'] + $existing);
// A resolver forced to live_cached, WITHOUT touching config or the environment.
$liveResolver = new RoutingModeResolver($container->get('config.factory'), 'false');
$liveAdapter = new GoogleRoutesDistanceProvider(
  $spyClient,
  $container->get('cache.default'),
  $container->get('config.factory'),
  $logger,
  $time,
  FALSE,
  $liveResolver,
);
$outboundBefore = $outbound;
$blockedAdapter = FALSE;
$classified = FALSE;
try {
  $liveAdapter->calculate('100 Adapter St, Town', '200 Adapter Ave, City');
}
catch (DistanceProviderException) {
  // The adapter classifies the blocked transport as an UNCLASSIFIED provider
  // failure — a non-billing refusal, not a route.
  $classified = TRUE;
}
catch (\Throwable) {
}
$check('the REAL adapter attempted its injected transport exactly once', $outbound === $outboundBefore + 1);
$check('the REAL adapter transport was blocked before network (classified refusal)', $classified);
// Restore the prior Settings so nothing leaks to later scripts in this process.
new Settings($existing);

// ---------------------------------------------------------------------------
$container->get('request_stack')->pop();
printf("\n== RESULT: %d passed, %d failed ==\n", $pass, $fail);
printf("counting provider calculate() calls total: %d\n", $counting->calls);
printf("real-adapter + control outbound attempts (all blocked before network): %d\n", $outbound);
printf("effective routing mode (container resolver): %s\n", $resolver->effectiveMode());
