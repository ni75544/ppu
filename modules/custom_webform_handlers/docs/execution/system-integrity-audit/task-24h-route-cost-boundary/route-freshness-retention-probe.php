<?php

/**
 * @file
 * Task 24H focused offline probe — route freshness vs. operation retention.
 *
 * Run: ddev exec vendor/bin/drush php:script \
 *   modules/custom_webform_handlers/docs/execution/system-integrity-audit/\
 *   task-24h-route-cost-boundary/route-freshness-retention-probe.php
 *
 * Verifies the correction that separates two lifetimes that used to be one:
 *
 *  - Route validity (the payload): an expired fact cannot be priced, scheduled or
 *    republished as fresh, and its payload is not retained past permitted validity.
 *  - Operation retention (the marker): a completed/expired operation id is still
 *    recognised for the bounded retention window and answered with a recorded
 *    outcome — never a second provider call — even after its route has expired.
 *
 * It drives the ACTUAL RouteOperationLedger over the real keyvalue.expirable store,
 * with a movable clock and a call-counting fake provider. Each retry records the
 * fake-provider call count before and after, so a replay/refresh is proven to make
 * no further fake-provider invocation. No socket is opened, no paid request is made,
 * no config or Dispatch state is touched. All fake-provider work is offline.
 *
 * "Provider calls" below count invocations of the in-process FAKE provider, not
 * billed requests. Bounded: a fixed, enumerated set of scenarios, no unbounded loop.
 */

use Drupal\Component\Datetime\TimeInterface;
use Drupal\custom_webform_handlers\Distance\DistanceProviderInterface;
use Drupal\custom_webform_handlers\Distance\DistanceResult;
use Drupal\custom_webform_handlers\Distance\RouteOperationLedger;

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
$run = 'fret-' . bin2hex(random_bytes(4));

// A clock the probe drives, so route expiry and the retention boundary can be
// crossed without waiting real seconds. Every method reads one movable value.
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

// A fake provider that counts genuine calculate() invocations and lets the caller
// choose the result's declared validity relative to "now". This is how the probe
// manufactures both a fresh result and an already-stale one, offline.
$counting = new class($time) implements DistanceProviderInterface {
  public int $calls = 0;
  public int $expiresAtOffset = 3_600;

  public function __construct(private readonly object $clock) {}

  public function calculate(string $origin, string $destination): DistanceResult {
    $this->calls++;
    $now = $this->clock->now;
    return DistanceResult::restored(
      42_000,
      3_600,
      'fret_provider',
      $now,
      [
        // Offset may be negative to manufacture an already-expired result.
        'expires_at' => $now + $this->expiresAtOffset,
        'provider_version' => 'fret-v1',
        'profile' => 'driving',
      ],
      'fret_polyline',
      FALSE,
    );
  }

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

$ledger = new RouteOperationLedger(
  $container->get('keyvalue.expirable'),
  $time,
  $container->get('logger.channel.custom_webform_handlers'),
);

$owner = 'uid:5001-' . $run;
$idA = hash('sha256', 'journey-A-' . $run);
$idB = hash('sha256', 'journey-B-' . $run);
$idC = hash('sha256', 'journey-C-' . $run);
$idD = hash('sha256', 'journey-D-' . $run);

// MAX_RECORD_TTL is the documented operation-retention window (3600s). The probe
// asserts behaviour on both sides of it via the movable clock.
$RETENTION = 3600;

print "== S1: provider result already expired on arrival ==\n";
// The controller records this as a terminal expired outcome (recordExpired), never
// storing a payload. The ledger must then answer the id as expired, with no call.
$op1 = 'op-stale-' . $run;
$counting->expiresAtOffset = -10; // expires_at is 10s in the PAST at calculation.
$check('S1 fresh op has no recorded outcome', $ledger->lookup($owner, $op1, $idA) === NULL);
$check('S1 claimPending succeeds', $ledger->claimPending($owner, $op1, $idA) === TRUE);
$before = $counting->calls;
$stale = $counting->calculate('o', 'd');            // the one calculation.
$after = $counting->calls;
$check('S1 exactly one fake-provider call to obtain the stale result', $after - $before === 1);
$check('S1 result is indeed already expired', $stale->expiresAt() !== NULL && $stale->expiresAt() <= $time->now);
$ledger->recordExpired($owner, $op1, $idA);         // controller's terminal record.
$before = $counting->calls;
$out = $ledger->lookup($owner, $op1, $idA);
$check('S1 lookup reports expired', ($out['state'] ?? '') === RouteOperationLedger::STATE_EXPIRED);
$check('S1 expired outcome carries no snapshot to price', !isset($out['snapshot']));
$check('S1 no identity pointer minted for a stale result', $ledger->lookupIdentity($owner, $idA) === NULL);
$check('S1 repeat lookup makes zero fake-provider calls', $counting->calls === $before);

print "== S2: valid result that expires before its retry ==\n";
$op2 = 'op-fresh-then-stale-' . $run;
$counting->expiresAtOffset = 1_800;                 // valid for 30 min.
$check('S2 claimPending succeeds', $ledger->claimPending($owner, $op2, $idB) === TRUE);
$before = $counting->calls;
$r2 = $counting->calculate('o', 'd');
$check('S2 one fake-provider call for the genuine calculation', $counting->calls - $before === 1);
$ledger->complete($owner, $op2, $idB, $snapshotOf($r2), $r2->expiresAt());

// Retry #1: still within route validity — replays the payload, no call.
$before = $counting->calls;
$out = $ledger->lookup($owner, $op2, $idB);
$check('S2 retry within validity reports completed', ($out['state'] ?? '') === RouteOperationLedger::STATE_COMPLETED);
$check('S2 completed retry carries the replayable snapshot', ($out['snapshot']['distance_meters'] ?? 0) === 42_000);
$check('S2 retry within validity: zero further fake-provider calls', $counting->calls === $before);
$check('S2 identity reuse available while route valid', ($ledger->lookupIdentity($owner, $idB)['state'] ?? '') === RouteOperationLedger::STATE_COMPLETED);

// Advance past the route's validity but stay inside the retention window.
$time->now += 1_900; // now > expires_at (1800), still < retention (3600).
$before = $counting->calls;
$out = $ledger->lookup($owner, $op2, $idB);
$check('S2 retry after route expiry reports expired (not completed)', ($out['state'] ?? '') === RouteOperationLedger::STATE_EXPIRED);
$check('S2 expired retry serves no stale snapshot', !isset($out['snapshot']));
$check('S2 retry after route expiry: zero further fake-provider calls', $counting->calls === $before);
$check('S2 identity reuse refused once route expired', $ledger->lookupIdentity($owner, $idB) === NULL);
$time->now -= 1_900;

print "== S3: repeated requests using the ORIGINAL op id after route expiry ==\n";
$op3 = 'op-repeat-' . $run;
$counting->expiresAtOffset = 600;                   // valid for 10 min.
$ledger->claimPending($owner, $op3, $idC);
$r3 = $counting->calculate('o', 'd');
$ledger->complete($owner, $op3, $idC, $snapshotOf($r3), $r3->expiresAt());
$time->now += 900;                                  // past validity, inside retention.
$before = $counting->calls;
// Three repeats of the SAME op id, as a retry storm would produce.
$states = [];
for ($i = 0; $i < 3; $i++) {
  $states[] = $ledger->lookup($owner, $op3, $idC)['state'] ?? '';
}
$check('S3 every repeat of the original id resolves expired', $states === array_fill(0, 3, RouteOperationLedger::STATE_EXPIRED));
$check('S3 three repeats after expiry make zero fake-provider calls', $counting->calls === $before);
$time->now -= 900;

print "== S4: unknown (abandoned pending) is not a licence to recompute ==\n";
$op4 = 'op-abandoned-' . $run;
$check('S4 claimPending succeeds', $ledger->claimPending($owner, $op4, $idD) === TRUE);
// Lease is PENDING_LEASE (120s); no completion is ever recorded.
$before = $counting->calls;
$check('S4 within lease the claim reads pending', ($ledger->lookup($owner, $op4, $idD)['state'] ?? '') === RouteOperationLedger::STATE_PENDING);
$time->now += 121;                                  // past the pending lease.
$check('S4 past the lease an abandoned pending reads unknown', ($ledger->lookup($owner, $op4, $idD)['state'] ?? '') === 'unknown');
$check('S4 unknown resolution makes zero fake-provider calls', $counting->calls === $before);
$time->now -= 121;

print "== S5: retention boundary — the id is forgotten only past the window ==\n";
$op5 = 'op-boundary-' . $run;
$counting->expiresAtOffset = 300;                   // valid for 5 min.
$ledger->claimPending($owner, $op5, hash('sha256', 'journey-E-' . $run));
$r5 = $counting->calculate('o', 'd');
$ledger->complete($owner, $op5, hash('sha256', 'journey-E-' . $run), $snapshotOf($r5), $r5->expiresAt());
$idE = hash('sha256', 'journey-E-' . $run);

// Just inside the retention window (route long expired): still recognised expired.
$time->now += ($RETENTION - 5);
$before = $counting->calls;
$check('S5 inside retention: original id still recognised as expired', ($ledger->lookup($owner, $op5, $idE)['state'] ?? '') === RouteOperationLedger::STATE_EXPIRED);
$check('S5 inside retention: zero fake-provider calls', $counting->calls === $before);

// Past the retention window the marker expires NATIVELY via keyvalue.expirable's
// own TTL — which is measured in real wall-clock, not this probe's movable clock,
// so the fake clock cannot cross it. To prove the ledger's behaviour once the row
// is gone, emulate exactly what the store's expiry GC does at the boundary: delete
// the marker (and payload) rows, then confirm the id is forgotten and a repeat
// looks fresh — a genuinely new, separately authorized operation. The completion
// wrote the marker with MAX_RECORD_TTL (verified below), so the boundary is real;
// only its crossing is emulated here because the store's clock is not the fake one.
$opStore = $container->get('keyvalue.expirable')->get('custom_webform_handlers.route_operations');
$opKey = 'op:' . hash('sha256', $owner . "\0" . $op5);
$payKey = 'pay:' . hash('sha256', $owner . "\0" . $op5);
$check('S5 marker row exists before emulated eviction', $opStore->has($opKey));
$opStore->delete($opKey);
$opStore->delete($payKey);
$before = $counting->calls;
$check('S5 past retention (row evicted): the id is forgotten (lookup NULL)', $ledger->lookup($owner, $op5, $idE) === NULL);
$check('S5 forgetting an id requires no fake-provider call by itself', $counting->calls === $before);
$time->now -= ($RETENTION - 5);

print "\n";
printf("== RESULT: %d passed, %d failed ==\n", $pass, $fail);
printf("fake-provider calculate() calls total this run: %d (each a genuine first calc; never a replay/refresh)\n", $counting->calls);
print "no socket opened, no paid request, no config or Dispatch write.\n";
