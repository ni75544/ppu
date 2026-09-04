<?php

/**
 * @file
 * Route-level authorisation matrix for the Reservation Dashboard, asked of Drupal
 * directly instead of through a browser.
 *
 * The browser gate used to prove authorisation the obvious way: navigate to a
 * forbidden route and check that the page says no. That works, and it costs a
 * `watchdog` row per refusal, because core's ExceptionLoggingSubscriber records
 * every 403 it converts into a response. Those rows are real mutations of a real
 * table, and the authenticated-dashboard sentinel is not allowed to accept them.
 * Proving the same thing without provoking an HTTP exception is therefore not a
 * weakening of the check — it is the same question asked one layer lower, where
 * the answer is authoritative and silent.
 *
 * What this asks, per account and per route, is exactly what the router asks
 * before it dispatches: AccessManager::checkNamedRoute(). The controller is never
 * built, the form is never built, no exception is constructed, no response is
 * rendered and nothing is logged. Access is denied unless it is explicitly
 * allowed, so both a forbidden and a neutral result are refusals here; the
 * concrete result class is printed either way, because "neutral because no check
 * matched" and "forbidden because a check said so" are different facts about the
 * route even though they are the same fact about the account.
 *
 * The routes are resolved from the same paths the browser gate reads out of
 * qa/tmp/task10c-expect.json, through the router that does not check access, so
 * this file names no route and no path of its own. If the dashboard moves, this
 * follows it. Each route's declared access requirement is published alongside the
 * verdict, so a requirement quietly changing is visible rather than merely
 * surviving.
 *
 * It fences itself against the very side effect it exists to avoid: `watchdog` is
 * counted before and after, and a single new row fails the run.
 *
 * Read-only. Every statement is a SELECT or an access evaluation.
 *
 * Usage:
 *   ddev exec vendor/bin/drush --uri=https://ppu-qa.ddev.site \
 *     php:script qa/support/task10c-access-matrix.php
 */

$site = \Drupal::service('kernel')->getSitePath();
if ($site !== 'sites/qa') {
  print 'REFUSED: this matrix asserts QA persona accounts by uid and runs on sites/qa only, not ' . $site . "\n";
  print "VERDICT: RED\n";
  return;
}

$expect_file = getenv('TASK10C_EXPECT') ?: 'qa/tmp/task10c-expect.json';
$expect = is_readable($expect_file) ? json_decode(file_get_contents($expect_file), TRUE) : NULL;
if (!is_array($expect) || empty($expect['dashboard']) || empty($expect['personas'])) {
  print 'REFUSED: cannot read the route set and personas from ' . $expect_file . "\n";
  print "VERDICT: RED\n";
  return;
}

$database = \Drupal::database();
$log_before = $database->query('SELECT COUNT(*) AS n, MAX(wid) AS m FROM {watchdog}')->fetchAssoc();

$results = [];
$rows = [];
$check = static function (string $scope, string $label, bool $ok, string $detail = '') use (&$results): void {
  $results[] = $ok;
  print '[' . ($ok ? 'PASS' : 'FAIL') . '] ' . str_pad($scope, 24) . ' ' . str_pad($label, 46) . ' ' . $detail . "\n";
};

/**
 * Every route under test, resolved from its path rather than named here.
 *
 * `router.no_access_checks` is the plain router; the default `router` service is
 * AccessAwareRouter, which evaluates access during match() and throws
 * AccessDeniedHttpException when it fails. Using that one would create the HTTP
 * exception this file exists to avoid, and would abort on the first denial instead
 * of measuring it.
 */
$router = \Drupal::service('router.no_access_checks');
$provider = \Drupal::service('router.route_provider');
$paths = ['dashboard' => $expect['dashboard'], 'board' => $expect['board']];
foreach (($expect['mutations'] ?? []) as $name => $path) {
  $paths['mutation:' . $name] = $path;
}

$routes = [];
foreach ($paths as $key => $path) {
  try {
    $match = $router->match($path);
  }
  catch (\Throwable $e) {
    $check('routes', 'path resolves: ' . $key, FALSE, get_class($e));
    continue;
  }
  $parameters = [];
  foreach ($match as $parameter => $value) {
    if ($parameter[0] !== '_' && is_scalar($value)) {
      $parameters[$parameter] = $value;
    }
  }
  $route = $provider->getRouteByName($match['_route']);
  $requirements = array_filter(
    $route->getRequirements(),
    static fn (string $requirement): bool => str_starts_with($requirement, '_'),
    ARRAY_FILTER_USE_KEY
  );
  $routes[$key] = [
    'path' => $path,
    'route' => $match['_route'],
    'parameters' => $parameters,
    'requirements' => $requirements,
  ];
  $check('routes', 'path resolves: ' . $key, TRUE, $match['_route']);
  $check('routes', 'route declares an access requirement: ' . $key, $requirements !== [], implode(' ', array_keys($requirements)));
}

/**
 * The accounts, real objects loaded from storage.
 *
 * Anonymous is a session object rather than a loaded user because uid 0 has no
 * `users` row to load; AnonymousUserSession is what core hands to an access check
 * for a signed-out request, so it is what an access check should be given here.
 */
$accounts = ['anonymous' => new \Drupal\Core\Session\AnonymousUserSession()];
foreach ($expect['personas'] as $name => $persona) {
  $account = \Drupal\user\Entity\User::load((int) $persona['uid']);
  if (!$account) {
    $check('accounts', 'persona account exists: ' . $name, FALSE, 'uid ' . (int) $persona['uid'] . ' is not in {users}');
    continue;
  }
  $check('accounts', 'persona account exists: ' . $name, TRUE, 'uid ' . $account->id() . ', ' . count($account->getRoles()) . ' roles');
  $check('accounts', 'persona account is active: ' . $name, (bool) $account->isActive(), $account->isActive() ? 'active' : 'blocked');
  $accounts[$name] = $account;
}

/**
 * Who is expected to reach what, derived from the same field the browser gate
 * reads.
 *
 * `may_write` is the dashboard's own split between a reader and a member of staff
 * who may change capacity, and it is declared once, in the expectation file the
 * RBAC pass computed. Restating it here as a second table is how a matrix ends up
 * asserting yesterday's permission model: a persona whose grants change would keep
 * passing against the copy.
 */
$expected = static function (string $account_key, string $route_key) use ($expect): bool {
  if ($account_key === 'anonymous') {
    return FALSE;
  }
  if (!str_starts_with($route_key, 'mutation:')) {
    return TRUE;
  }

  return (bool) ($expect['personas'][$account_key]['may_write'] ?? FALSE);
};

$manager = \Drupal::service('access_manager');
foreach ($accounts as $account_key => $account) {
  foreach ($routes as $route_key => $route) {
    $result = $manager->checkNamedRoute($route['route'], $route['parameters'], $account, TRUE);
    $allowed = $result->isAllowed();
    $want = $expected($account_key, $route_key);
    // A neutral result is a denial: Drupal grants access only on an explicit
    // allow. Both are reported by class so the two are never conflated.
    $shape = (new \ReflectionClass($result))->getShortName();
    $check(
      $account_key,
      ($want ? 'allowed: ' : 'denied: ') . $route_key,
      $allowed === $want,
      $shape . ($allowed === $want ? '' : ' — got ' . ($allowed ? 'allowed' : 'denied'))
    );
    $rows[] = [
      'account' => $account_key,
      'uid' => (int) $account->id(),
      'route_key' => $route_key,
      'route' => $route['route'],
      'expected' => $want ? 'allowed' : 'denied',
      'actual' => $allowed ? 'allowed' : 'denied',
      'result' => $shape,
    ];
  }
}

/**
 * The permission behind the write split, asserted on the accounts themselves.
 *
 * The route check above proves the outcome; this proves the reason. All three
 * mutation routes must be gated on one and the same permission — if they ever
 * diverge, `may_write` stops being a single fact about a persona and the matrix
 * above would be asserting something narrower than it claims.
 */
$mutation_requirements = [];
foreach ($routes as $route_key => $route) {
  if (str_starts_with($route_key, 'mutation:')) {
    $mutation_requirements[$route['requirements']['_permission'] ?? '<none>'] = TRUE;
  }
}
$write_permission = count($mutation_requirements) === 1 ? array_key_first($mutation_requirements) : '';
$check('permissions', 'all mutation routes share one permission', $write_permission !== '', implode(' | ', array_keys($mutation_requirements)));

foreach ($expect['personas'] as $name => $persona) {
  if (!isset($accounts[$name])) {
    continue;
  }
  $account = $accounts[$name];
  if ($write_permission !== '') {
    $check(
      'permissions',
      ($persona['may_write'] ? 'holds ' : 'lacks ') . $write_permission . ': ' . $name,
      $account->hasPermission($write_permission) === (bool) $persona['may_write'],
      $account->hasPermission($write_permission) ? 'holds it' : 'does not hold it'
    );
  }
  // The three field-level permissions the dashboard splits its columns on. The
  // browser gate proves the columns; this proves the grants those columns follow
  // from, for the same account object, without rendering anything.
  foreach (($expect['protected'] ?? []) as $permission => $fields) {
    $want = (bool) ($persona['grants'][$permission] ?? FALSE);
    $check(
      'permissions',
      ($want ? 'holds ' : 'lacks ') . $permission . ': ' . $name,
      $account->hasPermission($permission) === $want,
      count($fields) . ' field(s)'
    );
  }
}
foreach (($expect['protected'] ?? []) as $permission => $fields) {
  $check('permissions', 'lacks ' . $permission . ': anonymous',
    $accounts['anonymous']->hasPermission($permission) === FALSE, count($fields) . ' field(s)');
}

/**
 * The fence. An access evaluation must cost nothing, and this is how that is a
 * measurement rather than a claim.
 */
$log_after = $database->query('SELECT COUNT(*) AS n, MAX(wid) AS m FROM {watchdog}')->fetchAssoc();
$check(
  'side effects',
  'no watchdog row was written',
  (int) $log_before['n'] === (int) $log_after['n'] && (int) $log_before['m'] === (int) $log_after['m'],
  (int) $log_before['n'] . '/' . (int) $log_before['m'] . ' -> ' . (int) $log_after['n'] . '/' . (int) $log_after['m']
);

$passed = count(array_filter($results));
$total = count($results);
$verdict = $passed === $total && $total > 0 ? 'GREEN' : 'RED';

print "\nRESULT: " . $passed . '/' . $total . " access assertions passed\n";
print json_encode([
  'tool' => 'task10c-access-matrix',
  'site' => $site,
  'database' => $database->getConnectionOptions()['database'] ?? '?',
  'passed' => $passed,
  'total' => $total,
  'routes' => $routes,
  'accounts' => array_map(static fn ($account): int => (int) $account->id(), $accounts),
  'write_permission' => $write_permission,
  'matrix' => $rows,
  'watchdog' => [
    'before' => ['count' => (int) $log_before['n'], 'max' => (int) $log_before['m']],
    'after' => ['count' => (int) $log_after['n'], 'max' => (int) $log_after['m']],
  ],
  'verdict' => $verdict,
], JSON_UNESCAPED_SLASHES) . "\n";
print 'VERDICT: ' . $verdict . "\n";
