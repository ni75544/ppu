<?php

/**
 * Task 24C-8C-R4 — standalone comparative demonstration: installed MySQL driver vs the R4 candidate.
 *
 * Loads the labelled prototype under prototype-r4/ EXPLICITLY (they are never registered with Drupal
 * and are not referenced by normal execution). Runs the REAL installed manager and the candidate over
 * a recording client — no real database, no Commerce fixture, no provider call. Each scenario runs in
 * its own child process so a deliberately-dirty scenario cannot leak into, or crash, the parent.
 * Assertions stay enabled; nothing is suppressed; no fake success replaces installed logic.
 *
 * Run:  php demo.php            (parent: drives both variants, validates, prints RESULT)
 *       php demo.php <scn> <variant>   (child: variant = installed|candidate)
 */

$root = '/var/www/html/modules/custom_webform_handlers/docs/execution/system-integrity-audit/task-24c-booking-admission/prototype-r4';
$loader = require '/var/www/html/autoload.php';
if ($loader instanceof \Composer\Autoload\ClassLoader) {
  $loader->addPsr4('Drupal\\mysql\\Driver\\Database\\mysql\\', '/var/www/html/core/modules/mysql/src/Driver/Database/mysql/');
  $loader->addPsr4('Drupal\\custom_webform_handlers\\Prototype\\R4\\', $root . '/');
}

use Drupal\Core\Database\Connection as BaseConnection;
use Drupal\custom_webform_handlers\Prototype\R4\RootOutcome;
use Drupal\mysql\Driver\Database\mysql\TransactionManager as InstalledManager;

$arg = $argv[1] ?? 'driver';
$variant = $argv[2] ?? 'installed';

// ---------------------------------------------------------------- recording client
$makeClient = static function (array $cfg): object {
  return new class($cfg) {
    public int $beginCalls = 0, $commitCalls = 0, $rollBackCalls = 0;
    public bool $inTransaction;
    public function __construct(public array $cfg) { $this->inTransaction = $cfg['start_in_tx'] ?? FALSE; }
    public function beginTransaction(): bool { $this->beginCalls++; $this->inTransaction = TRUE; return TRUE; }
    public function commit(): bool {
      $this->commitCalls++;
      if (($this->cfg['commit_throws'] ?? FALSE)) { throw new \RuntimeException('client commit throw'); }
      $ok = $this->cfg['commit_ok'] ?? TRUE;
      if ($ok) { $this->inTransaction = FALSE; }
      return $ok;
    }
    public function rollBack(): bool {
      $this->rollBackCalls++;
      if (($this->cfg['rollback_throws'] ?? FALSE)) { throw new \RuntimeException('client rollback throw'); }
      $ok = $this->cfg['rollback_ok'] ?? TRUE;
      if ($ok) { $this->inTransaction = FALSE; }
      return $ok;
    }
    public function inTransaction(): bool { return $this->inTransaction; }
  };
};

// A connection whose manager factory is overridable per-variant. For 'installed' it behaves like
// the MySQL connection; for 'candidate' it returns the prototype manager. It is NOT Drupal-registered.
$makeConn = static function (object $client, string $variant, string $root): object {
  return new class($client, $variant, $root) extends BaseConnection {
    public ?object $probeManager = NULL;
    /** @var string[] every SQL string the manager issued through the connection. */
    public array $probeSql = [];
    public function __construct(public object $probeClient, public string $probeVariant, public string $probeRoot) {}
    public function __destruct() {}
    public function getClientConnection(): object { return $this->probeClient; }
    public function inTransaction() { return $this->probeManager ? $this->probeManager->inTransaction() : FALSE; }
    public function transactionManager(): \Drupal\Core\Database\Transaction\TransactionManagerInterface|FALSE { return $this->probeManager; }
    // The savepoint path issues real SQL (SAVEPOINT / RELEASE SAVEPOINT) through Connection::query().
    // Record it instead of preparing it, so those statements execute and are visible as evidence.
    public function query($query, array $args = [], $options = []) {
      $this->probeSql[] = (string) $query;
      return new class {
        public function execute($args = [], $options = []) { return TRUE; }
        public function fetchObject() { return FALSE; }
        public function fetchField() { return FALSE; }
      };
    }
    public static function open(array &$connection_options = []) { throw new \LogicException('probe'); }
    public function queryRange($query, $from, $count, array $args = [], array $options = []) { throw new \LogicException('probe'); }
    public function driver() { throw new \LogicException('probe'); }
    public function databaseType() { throw new \LogicException('probe'); }
    public function createDatabase($database) { throw new \LogicException('probe'); }
    public function mapConditionOperator($operator) { throw new \LogicException('probe'); }
    public function nextId($existing_id = 0) { throw new \LogicException('probe'); }
  };
};

// Build [client, conn, manager] for a variant. For 'installed' use the shipped MySQL manager; for
// 'candidate' use the installed mysql Connection subclass (prototype Connection extends it) so the
// factory mechanism itself is exercised. The manager is set explicitly to keep both variants on the
// SAME BaseConnection shell, isolating the manager difference under test.
$mk = static function (array $cfg, string $variant, string $root) use ($makeClient, $makeConn): array {
  $client = $makeClient($cfg);
  $conn = $makeConn($client, $variant, $root);
  if ($variant === 'candidate') {
    require_once $root . '/RootOutcome.php';
    require_once $root . '/RootHandle.php';
    require_once $root . '/TransactionManager.php';
    require_once $root . '/Connection.php';
    $cls = 'Drupal\\custom_webform_handlers\\Prototype\\R4\\TransactionManager';
    $conn->probeManager = new $cls($conn);
  }
  else {
    $conn->probeManager = new InstalledManager($conn);
  }
  return [$client, $conn, $conn->probeManager];
};

// Push a root: candidate uses beginRoot() (owned handle); installed uses push() (no handle).
// $handleId is untyped/by-ref because callers pass an as-yet-undefined variable.
$openRoot = static function (object $m, string $variant, &$handleId): object {
  if ($variant === 'candidate') {
    [$tx, $handle] = $m->beginRoot();
    $handleId = $handle->id;
    return $tx;
  }
  $handleId = '';
  return $m->push();
};

$emit = static function (string $line): void { echo 'OBS:' . $line . PHP_EOL; };
$stateOf = static function (?string $id, object $m): string {
  if ($m instanceof InstalledManager && method_exists($m, 'outcomeFor') && $id !== NULL && $id !== '') {
    return $m->outcomeFor($id)->value;
  }
  return 'n/a';
};
$counters = static function (object $c, array $p, string $outcome): string {
  return sprintf('commit=%d rollback=%d cbCalls=%d cbVal=%s outcome=%s client_in_tx=%s',
    $c->commitCalls, $c->rollBackCalls, ($p['calls'] ?? 0), var_export($p['val'] ?? NULL, TRUE), $outcome, var_export($c->inTransaction, TRUE));
};
$mkCb = static function (array &$props): callable {
  return static function (bool $s) use (&$props): void { $props['calls'] = ($props['calls'] ?? 0) + 1; $props['val'] = $s; };
};

// ---------------------------------------------------------------- scenarios (child body)
$runScenario = static function (string $scn, string $variant) use ($mk, $openRoot, $emit, $counters, $mkCb, $stateOf): void {
  $root = '/var/www/html/modules/custom_webform_handlers/docs/execution/system-integrity-audit/task-24c-booking-admission/prototype-r4';
  $p = [];
  switch ($scn) {
    case 'ordinary-commit':
      [$c, , $m] = $mk([], $variant, $root);
      $tx = $openRoot($m, $variant, $hid);
      $m->addPostTransactionCallback($mkCb($p));
      unset($tx);
      $emit($counters($c, $p, $stateOf($hid, $m)));
      break;

    case 'successful-rollback':
      [$c, , $m] = $mk([], $variant, $root);
      $tx = $openRoot($m, $variant, $hid);
      $m->addPostTransactionCallback($mkCb($p));
      $tx->rollBack(); unset($tx);
      $emit($counters($c, $p, $stateOf($hid, $m)));
      break;

    case 'rollback-false':
      [$c, , $m] = $mk(['rollback_ok' => FALSE], $variant, $root);
      $tx = $openRoot($m, $variant, $hid);
      $m->addPostTransactionCallback($mkCb($p));
      $rbT = 'no'; try { $tx->rollBack(); } catch (\Throwable $e) { $rbT = get_class($e); }
      $unT = 'no'; try { unset($tx); } catch (\Throwable $e) { $unT = get_class($e); }
      $emit($counters($c, $p, $stateOf($hid, $m)) . " rollback-threw=$rbT unset-threw=$unT");
      break;

    case 'rollback-throw':
      [$c, , $m] = $mk(['rollback_throws' => TRUE], $variant, $root);
      $tx = $openRoot($m, $variant, $hid);
      $m->addPostTransactionCallback($mkCb($p));
      $rbT = 'no'; try { $tx->rollBack(); } catch (\Throwable $e) { $rbT = get_class($e); }
      $unT = 'no'; try { unset($tx); } catch (\Throwable $e) { $unT = get_class($e); }
      $emit($counters($c, $p, $stateOf($hid, $m)) . " rollback-threw=$rbT unset-threw=$unT");
      break;

    case 'voided':
      [$c, , $m] = $mk([], $variant, $root);
      $tx = $openRoot($m, $variant, $hid);
      $m->addPostTransactionCallback($mkCb($p));
      $m->voidClientTransaction(); unset($tx);
      $emit($counters($c, $p, $stateOf($hid, $m)));
      break;

    case 'already-ended':
      [$c, , $m] = $mk([], $variant, $root);
      $tx = $openRoot($m, $variant, $hid);
      $c->inTransaction = FALSE; // server implicitly ended the transaction
      $m->addPostTransactionCallback($mkCb($p));
      $rbT = 'no'; try { $tx->rollBack(); } catch (\Throwable $e) { $rbT = get_class($e); }
      try { unset($tx); } catch (\Throwable $e) {}
      $emit($counters($c, $p, $stateOf($hid, $m)) . " rollback-threw=$rbT");
      break;

    case 'observer-registration-failure':
      [$c, , $m] = $mk([], $variant, $root);
      $regT = 'no';
      try { $m->addPostTransactionCallback($mkCb($p)); } catch (\Throwable $e) { $regT = get_class($e); }
      $emit(sprintf('commit=%d rollback=%d register-threw=%s', $c->commitCalls, $c->rollBackCalls, $regT));
      break;

    case 'callback-failure-after-commit':
      [$c, , $m] = $mk([], $variant, $root);
      $tx = $openRoot($m, $variant, $hid);
      $m->addPostTransactionCallback(static function (bool $s): void { throw new \RuntimeException('callback boom'); });
      $pick = 'no'; try { unset($tx); } catch (\Throwable $e) { $pick = get_class($e); }
      $errs = ($variant === 'candidate' && method_exists($m, 'callbackErrors')) ? implode(',', $m->callbackErrors()) : 'n/a';
      $emit($counters($c, $p, $stateOf($hid, $m)) . " unset-threw=$pick callbackErrors=$errs");
      break;

    case 'nested-savepoint':
      [$c, $conn, $m] = $mk([], $variant, $root);
      $tx = $openRoot($m, $variant, $hid);
      $sp = $m->push();           // savepoint (nested)
      $afterPushOutcome = $stateOf($hid, $m);
      $sp->rollBack();            // rollback + release the savepoint
      unset($sp);
      $m->addPostTransactionCallback($mkCb($p));
      $afterSavepointOutcome = $stateOf($hid, $m);
      unset($tx);
      $emit($counters($c, $p, $stateOf($hid, $m))
        . " after_push=$afterPushOutcome after_savepoint_outcome=$afterSavepointOutcome sql=" . implode(',', $conn->probeSql));
      break;

    case 'consecutive-roots':
      [$c, , $m] = $mk([], $variant, $root);
      $txA = $openRoot($m, $variant, $hidA);
      unset($txA); // resolve A (commit)
      $outA = $stateOf($hidA, $m);
      $txB = $openRoot($m, $variant, $hidB);
      $pB = []; $m->addPostTransactionCallback($mkCb($pB));
      $txB->rollBack(); unset($txB); // resolve B (rollback)
      $outB = $stateOf($hidB, $m);
      $oldB = $stateOf('unknown-old-id', $m); // unknown handle must never get B's success
      $emit(sprintf('A=%s B=%s unknown=%s distinctHandles=%s commit=%d rollback=%d',
        $outA, $outB, $oldB, var_export($hidA !== $hidB && $hidA !== '' && $hidB !== '', TRUE), $c->commitCalls, $c->rollBackCalls));
      break;

    case 'retained-connection-reference':
      // After a suppressed commit, does another reference to the SAME client still see an open
      // transaction? (Suppression does not end the client transaction.)
      [$c, , $m] = $mk(['rollback_throws' => TRUE], $variant, $root);
      $tx = $openRoot($m, $variant, $hid);
      try { $tx->rollBack(); } catch (\Throwable $e) {}
      try { unset($tx); } catch (\Throwable $e) {}
      // "Another reference": the recording client itself, still the same object.
      $stillOpen = $c->inTransaction;
      $emit(sprintf('commit=%d rollback=%d outcome=%s client_still_open_after_suppression=%s',
        $c->commitCalls, $c->rollBackCalls, $stateOf($hid, $m), var_export($stillOpen, TRUE)));
      break;

    case 'unowned-root-control':
      // A root pushed WITHOUT beginRoot(): the candidate must deliver the parent's value unchanged.
      [$c, , $m] = $mk([], $variant, $root);
      $tx = $m->push(); // unowned
      $m->addPostTransactionCallback($mkCb($p));
      unset($tx);
      $emit($counters($c, $p, 'n/a'));
      break;

    default:
      fwrite(STDERR, "unknown scenario: $scn\n");
      exit(2);
  }
};

if ($arg !== 'driver') {
  $runScenario($arg, $variant);
  return;
}

// ---------------------------------------------------------------- parent: comparative driver
$scenarios = [
  'ordinary-commit', 'successful-rollback', 'rollback-false', 'rollback-throw', 'voided',
  'already-ended', 'observer-registration-failure', 'callback-failure-after-commit',
  'nested-savepoint', 'consecutive-roots', 'retained-connection-reference', 'unowned-root-control',
];
$pass = 0; $fail = 0; $fails = [];
echo "=== 24C-8C-R4 comparative demonstration (installed vs candidate, child-per-scenario) ===" . PHP_EOL;
foreach ($scenarios as $scn) {
  $row = [];
  foreach (['installed', 'candidate'] as $v) {
    $out = []; $code = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' ' . escapeshellarg($scn) . ' ' . escapeshellarg($v) . ' 2>&1', $out, $code);
    $obs = implode(' | ', array_values(array_filter($out, static fn($l) => str_starts_with($l, 'OBS:'))));
    $row[$v] = ['code' => $code, 'obs' => preg_replace('/^OBS:/', '', $obs)];
  }
  printf("%-30s installed[%3d] %s\n%-30s candidate[%3d] %s\n", $scn, $row['installed']['code'], $row['installed']['obs'], '', $row['candidate']['code'], $row['candidate']['obs']);
  // Compare the persistent-effect counters + outcome (the OBS line), not the harness exit codes
  // (dirty scenarios exit non-zero in both variants).
  $strip = static function (string $s): string { return preg_replace('/ (unset-threw|rollback-threw|client_in_tx|after_savepoint_outcome)=[^ ]*/', '', $s); };
  $checks = [];
  switch ($scn) {
    case 'ordinary-commit':
      $checks['candidate commits exactly once'] = str_contains($row['candidate']['obs'], 'commit=1 rollback=0');
      $checks['candidate callback TRUE'] = str_contains($row['candidate']['obs'], 'cbVal=true');
      $checks['candidate outcome committed'] = str_contains($row['candidate']['obs'], 'outcome=committed');
      break;
    case 'successful-rollback':
      $checks['candidate rollback once, commit 0'] = str_contains($row['candidate']['obs'], 'commit=0 rollback=1');
      $checks['candidate outcome rolled_back'] = str_contains($row['candidate']['obs'], 'outcome=rolled_back');
      $checks['candidate callback FALSE'] = str_contains($row['candidate']['obs'], 'cbVal=false');
      break;
    case 'rollback-false':
      $checks['candidate: no client commit'] = str_contains($row['candidate']['obs'], 'commit=0');
      $checks['candidate outcome failed'] = str_contains($row['candidate']['obs'], 'outcome=failed');
      // A returns-FALSE rollback leaves the item on the stack, so unset() is out of order in BOTH
      // variants. The candidate must NOT swallow that: same exit code, same exception.
      $checks['expected failure: both variants exit 255 on out-of-order unset'] = $row['installed']['code'] === 255 && $row['candidate']['code'] === 255;
      $checks['candidate raises the same exception as installed'] = str_contains($row['candidate']['obs'], 'TransactionOutOfOrderException') && str_contains($row['installed']['obs'], 'TransactionOutOfOrderException');
      break;
    case 'rollback-throw':
      $checks['candidate: NO destructor commit (installed does commit)'] = str_contains($row['candidate']['obs'], 'commit=0') && str_contains($row['installed']['obs'], 'commit=1');
      $checks['candidate outcome failed'] = str_contains($row['candidate']['obs'], 'outcome=failed');
      $checks['candidate survives teardown (exit 0) after a thrown rollback'] = $row['candidate']['code'] === 0;
      break;
    case 'voided':
      $checks['installed delivers callback TRUE for void'] = str_contains($row['installed']['obs'], 'cbVal=true');
      $checks['candidate delivers callback FALSE for void'] = str_contains($row['candidate']['obs'], 'cbVal=false');
      $checks['candidate outcome voided'] = str_contains($row['candidate']['obs'], 'outcome=voided');
      $checks['candidate: void commits nothing'] = str_contains($row['candidate']['obs'], 'commit=0');
      break;
    case 'already-ended':
      $checks['candidate: no client commit'] = str_contains($row['candidate']['obs'], 'commit=0');
      $checks['candidate outcome not committed'] = !str_contains($row['candidate']['obs'], 'outcome=committed');
      break;
    case 'observer-registration-failure':
      $checks['both throw LogicException on register'] = str_contains($row['installed']['obs'], 'register-threw=LogicException') && str_contains($row['candidate']['obs'], 'register-threw=LogicException');
      break;
    case 'callback-failure-after-commit':
      $checks['installed: unset throws (callback propagates)'] = str_contains($row['installed']['obs'], 'unset-threw=RuntimeException');
      $checks['expected failure: installed child exits 255 as the callback escapes'] = $row['installed']['code'] === 255;
      $checks['candidate: callback throw contained'] = str_contains($row['candidate']['obs'], 'unset-threw=no') && str_contains($row['candidate']['obs'], 'callbackErrors=RuntimeException');
      $checks['candidate: contained throw does not crash the child'] = $row['candidate']['code'] === 0;
      $checks['candidate still committed once'] = str_contains($row['candidate']['obs'], 'commit=1');
      break;
    case 'nested-savepoint':
      $checks['candidate: opening a savepoint is NOT a root outcome'] = str_contains($row['candidate']['obs'], 'after_push=not_completed');
      $checks['candidate: releasing a savepoint is NOT root commit'] = str_contains($row['candidate']['obs'], 'after_savepoint_outcome=not_completed');
      $checks['candidate final outcome committed'] = str_contains($row['candidate']['obs'], 'outcome=committed');
      $checks['candidate: the savepoint path issued real SQL (installed too)'] = str_contains($row['candidate']['obs'], 'SAVEPOINT') && str_contains($row['installed']['obs'], 'SAVEPOINT');
      break;
    case 'consecutive-roots':
      $checks['candidate: A committed, B rolled_back, distinct handles'] = str_contains($row['candidate']['obs'], 'A=committed') && str_contains($row['candidate']['obs'], 'B=rolled_back') && str_contains($row['candidate']['obs'], 'distinctHandles=true');
      $checks['candidate: unknown handle is uncertain, never another root success'] = str_contains($row['candidate']['obs'], 'unknown=uncertain');
      break;
    case 'retained-connection-reference':
      $checks['candidate suppresses commit'] = str_contains($row['candidate']['obs'], 'commit=0');
      $checks['installed commits after the thrown rollback (the defect)'] = str_contains($row['installed']['obs'], 'commit=1');
      $checks['client transaction remains open after suppression (documented)'] = str_contains($row['candidate']['obs'], 'client_still_open_after_suppression=true');
      break;
    case 'unowned-root-control':
      $checks['candidate: unowned root is unchanged (parent value TRUE)'] = str_contains($row['candidate']['obs'], 'cbVal=true');
      $checks['candidate unowned committed once'] = str_contains($row['candidate']['obs'], 'commit=1');
      break;
  }
  foreach ($checks as $cn => $ok) {
    if ($ok) { $pass++; } else { $fail++; $fails[] = "$scn: $cn"; }
  }
}
echo PHP_EOL;
foreach ($fails as $f) { echo 'FAIL: ' . $f . PHP_EOL; }
echo sprintf('RESULT: pass=%d fail=%d', $pass, $fail) . PHP_EOL;
exit($fail === 0 ? 0 : 1);
