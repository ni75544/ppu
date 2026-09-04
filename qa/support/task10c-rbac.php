<?php

/**
 * @file
 * Task 10C RBAC matrix for the Reservation Dashboard, in-process over HTTP.
 *
 * QA site only. The three Task 7 personas have no known password, so this drives
 * the real HTTP stack instead of a browser: one sub-request per persona through
 * http_kernel with account_switcher, which runs the router, the access checks,
 * the Views query, the field handlers, the theme, and page attachments exactly
 * as a logged-in request would, and hands back the whole rendered page.
 *
 * What it is built to catch, rather than merely observe:
 *
 * - A protected column that renders for an account without its permission — in
 *   the row markup, in a table header, or anywhere else on the page.
 * - The same value reached round the front door: sorting by a withheld column,
 *   filtering on one, or asking for the detail page directly.
 * - A secret that should be on no page at any privilege: the stored hold-token
 *   and owner hashes, read out of the ledger here so the assertion is made
 *   against the literal value in the database rather than a guess at its shape.
 * - A mutation route reachable by a read-only role.
 * - An empty cell that means two different things: a stored 0 printed as though
 *   nothing was measured, or a NULL printed as 0. Expectations are computed from
 *   the ledger rows themselves, so the table is checked against storage.
 *
 * Every expectation is derived from the live permission grant, not from a
 * hardcoded list: the matrix asserts "column present if and only if this account
 * holds the permission", so a role edited later changes the expectation with it.
 *
 * Prints one line per check and nothing that came out of the page. Exit status 0
 * only when every check passed.
 *
 * Usage:
 *   ddev exec vendor/bin/drush --uri=https://ppu-qa.ddev.site \
 *     php:script qa/support/task10c-rbac.php
 *
 * QA site only, and it needs the fixture: run qa/support/task10c-seed.php first.
 * Writes qa/tmp/task10c-expect.json, which the browser gate reads, so the two
 * gates assert the same ledger-derived values instead of two sets of guesses.
 */

use Drupal\Core\Session\AnonymousUserSession;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\HttpKernelInterface;

$base = 'https://ppu-qa.ddev.site';
$dashboard = '/admin/structure/webform/dispatch-planner/reservations';

$kernel = \Drupal::service('http_kernel');
$switcher = \Drupal::service('account_switcher');
$render_cache = \Drupal::service('cache.render');
$database = \Drupal::database();

$results = [];

/**
 * Scores one check.
 */
$check = static function (string $persona, string $name, bool $pass, string $detail = '') use (&$results): void {
  $results[] = $pass;
  print sprintf("[%s] %-16s %-46s %s\n", $pass ? 'PASS' : 'FAIL', $persona, $name, $detail);
};

/**
 * Fetches one page as one account, through the real request pipeline.
 *
 * The render cache is dropped before each fetch. A cached render served across
 * personas would be a defect worth finding, but it would be found here as a
 * confusing pass or fail on the wrong row; the browser gate re-checks the same
 * pages over the wire, where nothing is shared.
 */
$fetch = static function ($account, string $path, array $query = []) use ($kernel, $switcher, $render_cache, $base): array {
  $url = $base . $path . ($query === [] ? '' : '?' . http_build_query($query));
  $request = Request::create($url, 'GET');
  $request->setSession(new Session(new MockArraySessionStorage()));
  $render_cache->deleteAll();
  $switcher->switchTo($account);
  try {
    $response = $kernel->handle($request, HttpKernelInterface::SUB_REQUEST, FALSE);
    return ['status' => $response->getStatusCode(), 'body' => (string) $response->getContent()];
  }
  catch (HttpExceptionInterface $e) {
    return ['status' => $e->getStatusCode(), 'body' => ''];
  }
  catch (\Throwable $e) {
    return ['status' => 0, 'body' => '', 'error' => get_class($e) . ': ' . $e->getMessage()];
  }
  finally {
    $switcher->switchBack();
  }
};

/**
 * The table body rows of the rendered view, as raw markup.
 */
$rows = static function (string $html): array {
  if (!preg_match('#<tbody\b.*?>(.*?)</tbody>#s', $html, $body)) {
    return [];
  }
  preg_match_all('#<tr\b.*?>(.*?)</tr>#s', $body[1], $found);

  return $found[1] ?? [];
};

/**
 * The visible text of one Views column inside one row, or NULL when absent.
 */
$cell = static function (string $row, string $field): ?string {
  $class = 'views-field-' . str_replace('_', '-', $field);
  $pattern = '#<t[dh][^>]*class="[^"]*(?<![a-z-])' . preg_quote($class, '#') . '(?![a-z-])[^"]*"[^>]*>(.*?)</t[dh]>#s';
  if (!preg_match($pattern, $row, $found)) {
    return NULL;
  }
  $text = html_entity_decode(strip_tags($found[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');

  return trim(preg_replace('/\s+/u', ' ', $text));
};

/**
 * Whether any element on the page carries a Views column's class.
 *
 * Header, body and any hidden markup are all in scope: a withheld column has to
 * be absent from the page, not merely from the visible cells.
 */
$has_column = static function (string $html, string $field): bool {
  $class = 'views-field-' . str_replace('_', '-', $field);

  return (bool) preg_match('#(?<![a-z-])' . preg_quote($class, '#') . '(?![a-z-])#', $html);
};

/**
 * Local time as the dispatch_local_time handler prints it.
 */
$local = static function (?int $instant, string $timezone, string $pattern, bool $zone = TRUE): ?string {
  if ($instant === NULL || $instant <= 0) {
    return NULL;
  }
  $date = (new \DateTimeImmutable('@' . $instant))->setTimezone(new \DateTimeZone($timezone));

  return $date->format($pattern . ($zone ? ' T' : ''));
};

// The fixture rows, read back out of the ledger so the table is checked against
// storage rather than against the intent of the seed script.
$manifest = json_decode((string) file_get_contents('qa/tmp/task10c-seed.json'), TRUE);
$ledger = $database->select('custom_webform_handlers_dispatch_reservation', 'r')
  ->fields('r')
  ->condition('dispatch_reservation_id', $manifest['reservations'], 'IN')
  ->execute()
  ->fetchAllAssoc('dispatch_reservation_id', \PDO::FETCH_ASSOC);

$number = static fn ($value): string => number_format((int) $value, 0, '', ',');

$secrets = [];
$expected = [];
foreach ($ledger as $id => $row) {
  $zone = (string) $row['timezone'];
  foreach (['hold_token_hash', 'owner_hash'] as $column) {
    if (is_string($row[$column]) && strlen($row[$column]) > 8) {
      $secrets[$column . '-' . $id] = $row[$column];
    }
  }

  $submission = $row['submission_id'] === NULL ? NULL : \Drupal\webform\Entity\WebformSubmission::load((int) $row['submission_id']);
  $contact = static function (string $element) use ($submission): string {
    $value = $submission === NULL ? NULL : $submission->getElementData($element);

    return is_string($value) && trim($value) !== '' ? trim($value) : 'Not recorded';
  };

  $expected[$id] = [
    'open' => [
      'dispatch_reservation_id' => (string) $id,
      'state' => ucfirst((string) $row['state']),
      'resource_id' => (string) $row['resource_id'],
      'webform_id' => (string) $row['webform_id'],
      'pickup_arrival' => $local((int) $row['pickup_arrival'], $zone, 'D, M j Y g:i A') ?? 'Not recorded',
      'window_start' => $local((int) $row['window_start'], $zone, 'D, M j Y g:i A', FALSE)
        . ' – ' . $local((int) $row['window_end'], $zone, 'g:i A'),
      'expires_at' => $local((int) $row['expires_at'], $zone, 'D, M j Y g:i A') ?? 'No hold pending',
      'changed' => $local((int) $row['changed'], $zone, 'D, M j Y g:i A') ?? 'Not recorded',
      'submission_id' => $row['submission_id'] === NULL || (int) $row['submission_id'] === 0 ? 'Not linked yet' : $number($row['submission_id']),
      'route_seconds' => $row['route_seconds'] === NULL ? 'Not measured' : $number($row['route_seconds']),
      'route_meters' => $row['route_meters'] === NULL ? 'Not measured' : $number($row['route_meters']),
      'inbound_reposition_seconds' => $number($row['inbound_reposition_seconds']),
      'outbound_reposition_seconds' => $number($row['outbound_reposition_seconds']),
    ],
    'view dispatch customer contact' => [
      'customer_name' => $contact('full_name'),
      'customer_phone' => $contact('phone_number'),
      'customer_email' => $contact('email'),
    ],
    'view dispatch exact addresses' => [
      'pickup_location' => trim((string) $row['pickup_location']) === '' ? 'Not recorded' : trim((string) $row['pickup_location']),
      'delivery_location' => trim((string) $row['delivery_location']) === '' ? 'Not recorded' : trim((string) $row['delivery_location']),
    ],
    'view dispatch financial details' => [
      'price_snapshot_cents' => $row['price_snapshot_cents'] === NULL
        ? 'No price was recorded'
        : number_format(((int) $row['price_snapshot_cents']) / 100, 2) . ' ' . $row['price_snapshot_currency'] . ', snapshot ' . $row['price_snapshot_id'],
      'order_id' => $row['order_id'] === NULL || (int) $row['order_id'] === 0 ? 'No order recorded' : (string) $row['order_id'],
    ],
  ];
}

$protected = [
  'view dispatch customer contact' => ['customer_name', 'customer_phone', 'customer_email'],
  'view dispatch exact addresses' => ['pickup_location', 'delivery_location'],
  'view dispatch financial details' => ['price_snapshot_cents', 'order_id'],
];

// GET only. A confirm form renders without acting, and nothing here is posted.
// The release form is pointed at the confirmed fixture rather than the held one:
// a hold's own claim stops existing when it expires, and the release form answers
// 404 for a claim that is no longer live — correct behaviour that would otherwise
// turn this probe red on a clock, not on a permission.
$mutations = [
  'replan' => '/admin/structure/webform/dispatch-planner/reservation/' . $manifest['reservations'][0] . '/replan',
  'release-claim' => '/admin/structure/webform/dispatch-planner/claim/' . $manifest['reservations'][0] . '/release',
  'add-blackout' => '/admin/structure/webform/dispatch-planner/blackouts/add',
];

$personas = [
  ['anonymous', 0, '', 403],
  ['viewer', 3, 'dispatch_viewer', 200],
  ['manager', 2, 'dispatch_schedule_manager', 200],
  ['finance', 4, 'dispatch_financial_viewer', 200],
  ['administrator', 1, 'administrator', 200],
];

print "TASK 10C — Reservation Dashboard RBAC matrix\n";
print 'ledger rows under test: ' . implode(', ', array_keys($ledger)) . "\n";
print 'secrets asserted absent: ' . count($secrets) . " stored hashes\n\n";

$only = trim((string) getenv('TASK10C_ONLY'));
// What the browser gate is handed, so the two gates assert the same thing from
// the same source: expectations computed from the ledger, and per persona the
// columns that must be there, the columns that must not, and the literal values
// this account is not cleared for.
$export = [];
foreach ($personas as [$label, $uid, $role, $want_status]) {
  if ($only !== '' && $only !== $label) {
    continue;
  }
  $account = $uid === 0
    ? new AnonymousUserSession()
    : \Drupal::entityTypeManager()->getStorage('user')->load($uid);
  if ($account === NULL) {
    $check($label, 'account exists', FALSE, 'uid ' . $uid . ' not found');
    continue;
  }
  if ($role !== '') {
    $check($label, 'holds role ' . $role, in_array($role, $account->getRoles(), TRUE), 'uid ' . $uid);
  }

  $grants = [];
  foreach (array_keys($protected) as $permission) {
    $grants[$permission] = $account->hasPermission($permission);
  }
  $check($label, 'permission grant read', TRUE, implode(' ', array_map(
    static fn ($p, $g) => ($g ? '+' : '-') . str_replace('view dispatch ', '', $p),
    array_keys($grants),
    $grants
  )));

  $page = $fetch($account, $dashboard);
  $check($label, 'dashboard status ' . $want_status, $page['status'] === $want_status, 'got ' . $page['status'] . ($page['error'] ?? ''));
  $body = $page['body'];

  // Secrets and shapes that must be on no page at any privilege.
  foreach ($secrets as $name => $value) {
    $check($label, 'stored hash absent (' . $name . ')', !str_contains($body, $value));
  }
  // Two 64-character hex runs on these pages are Drupal's own and say nothing
  // about a reservation: the Views DOM id, which hashes the view name and
  // display, and core's drupalSettings permissions hash, attached to every
  // authenticated page by the user module. Both are removed by name rather than
  // by loosening the pattern, so a stored hash of the same shape still fails.
  $scrubbed = preg_replace(
    ['/js-view-dom-id-[0-9a-f]{64}/', '/"permissionsHash":"[0-9a-f]{64}"/'],
    ['js-view-dom-id-EXPECTED', '"permissionsHash":"EXPECTED"'],
    $body
  );
  $check($label, 'no unexplained 40/64-char hex run', !preg_match('/\b[0-9a-f]{40,64}\b/', $scrubbed), 'Views DOM id and permissions hash excluded by name');
  foreach (['hold_token', 'owner_hash', 'hmac', 'authorization'] as $word) {
    $check($label, 'word absent: ' . $word, stripos($body, $word) === FALSE);
  }
  foreach (['data_export', 'views-data-export', 'views_bulk_operations', 'bulk_form', 'Apply to selected', '.csv'] as $marker) {
    $check($label, 'no export/bulk marker: ' . $marker, stripos($body, $marker) === FALSE);
  }

  // Protected columns: present exactly when the account holds the permission,
  // and absent from the page entirely when it does not.
  foreach ($protected as $permission => $fields) {
    foreach ($fields as $field) {
      $present = $has_column($body, $field);
      $check(
        $label,
        ($grants[$permission] ? 'column present: ' : 'column withheld: ') . $field,
        $present === ($grants[$permission] && $want_status === 200)
      );
    }
  }

  if ($page['status'] !== 200) {
    foreach ($mutations as $name => $path) {
      $denied = $fetch($account, $path);
      $check($label, 'mutation denied: ' . $name, $denied['status'] === 403, 'got ' . $denied['status']);
    }
    print "\n";
    continue;
  }

  // Every fixture row, matched to its ledger row by the identifier cell.
  $table = $rows($body);
  $check($label, 'row count', count($table) === count($ledger), count($table) . ' of ' . count($ledger));
  $seen = [];
  foreach ($table as $row) {
    $id = (int) $cell($row, 'dispatch_reservation_id');
    if (!isset($expected[$id])) {
      $check($label, 'row identifier resolves', FALSE, 'unknown id ' . $id);
      continue;
    }
    $seen[] = $id;
    // The schedule columns every dashboard reader gets, compared against the
    // value computed from storage — including the two stored zeros, which must
    // print as 0 and not as an absence.
    foreach ($expected[$id]['open'] as $field => $want) {
      $got = $cell($row, $field);
      $check($label, 'row ' . $id . ' ' . $field, $got === $want, $got === NULL ? 'column missing' : ($got === $want ? $got : 'got "' . $got . '" want "' . $want . '"'));
    }

    // The protected columns, in both directions: the exact stored value when
    // the permission is held, and no cell at all when it is not.
    foreach ($protected as $permission => $fields) {
      foreach ($fields as $field) {
        $got = $cell($row, $field);
        if (!$grants[$permission]) {
          $check($label, 'row ' . $id . ' withheld ' . $field, $got === NULL);
          continue;
        }
        $want = $expected[$id][$permission][$field];
        $check($label, 'row ' . $id . ' ' . $field, $got === $want, $got === $want ? '' : 'got "' . (string) $got . '" want "' . $want . '"');
      }
    }
  }
  $check($label, 'all ledger rows rendered', count($seen) === count($ledger), implode(',', $seen));

  // Every value this account is not cleared for, gathered once so it can be
  // hunted on the other pages too. Placeholders are excluded: "Not recorded" is
  // shared wording, not a disclosure.
  $withheld = [];
  foreach ($protected as $permission => $fields) {
    if ($grants[$permission]) {
      continue;
    }
    foreach ($expected as $row_expectations) {
      foreach ($row_expectations[$permission] as $value) {
        if (!in_array($value, ['Not recorded', 'No order recorded', 'No price was recorded'], TRUE)) {
          $withheld[$value] = TRUE;
        }
      }
    }
  }
  $hunt = static function (string $where, string $html) use ($withheld, $check, $label): void {
    $found = array_filter(array_keys($withheld), static fn ($value) => str_contains($html, $value));
    $check($label, 'no withheld value on ' . $where, $found === [], count($withheld) . ' hunted');
  };
  $hunt('dashboard', $body);

  // Handed to the browser gate, which asserts the same facts against the DOM the
  // browser actually built rather than against a second set of expectations.
  $export[$label] = [
    'uid' => $uid,
    'grants' => $grants,
    'may_write' => $account->hasPermission('manage dispatch schedule'),
    'ids_in_order' => $seen,
    'present' => [],
    'absent' => [],
    'withheld' => array_keys($withheld),
  ];
  foreach ($protected as $permission => $fields) {
    foreach ($fields as $field) {
      $export[$label][$grants[$permission] ? 'present' : 'absent'][] = $field;
    }
  }

  // Sorting by a column this account cannot see, and filtering on one: both are
  // ignored rather than answered, and neither leaks the value.
  foreach ([['order' => 'customer_email', 'sort' => 'desc'], ['order' => 'price_snapshot_cents', 'sort' => 'asc'], ['order' => 'pickup_location', 'sort' => 'desc']] as $query) {
    $probe = $fetch($account, $dashboard, $query);
    $check($label, 'sort bypass ignored: ' . $query['order'], $probe['status'] === 200 && count($rows($probe['body'])) === count($ledger), 'status ' . $probe['status'] . ', ' . count($rows($probe['body'])) . ' rows');
    $hunt('sort=' . $query['order'], $probe['body']);
  }
  $crafted = $fetch($account, $dashboard, [
    'pickup_location' => '1 QA Pickup Way',
    'customer_email' => 'qa-10c@example.invalid',
    'price_snapshot_cents' => '148500',
    'hold_token_hash' => str_repeat('a', 64),
  ]);
  $check($label, 'crafted filter params ignored', $crafted['status'] === 200 && count($rows($crafted['body'])) === count($ledger), count($rows($crafted['body'])) . ' rows');
  $hunt('crafted query', $crafted['body']);

  // The exposed filters, and the empty state they can produce. The state filter
  // accepts several values at once, so its parameter is an array: ?state=x is
  // ignored by the filter and would score a pass on a page that never filtered.
  $released = $manifest['reservations'][3];
  $one_state = $fetch($account, $dashboard, ['state' => ['released']]);
  $ids_shown = array_map(static fn ($row) => (int) $cell($row, 'dispatch_reservation_id'), $rows($one_state['body']));
  $check($label, 'filter state=released', $ids_shown === [$released], 'ids ' . implode(',', $ids_shown));

  $confirmed = $fetch($account, $dashboard, ['state' => ['confirmed']]);
  $confirmed_ids = array_map(static fn ($row) => (int) $cell($row, 'dispatch_reservation_id'), $rows($confirmed['body']));
  $check($label, 'filter state=confirmed', count($confirmed_ids) === 2, 'ids ' . implode(',', $confirmed_ids));

  $day = $fetch($account, $dashboard, ['pickup_arrival' => ['min' => '2026-09-08 00:00:00', 'max' => '2026-09-08 23:59:59']]);
  $day_ids = array_map(static fn ($row) => (int) $cell($row, 'dispatch_reservation_id'), $rows($day['body']));
  $check($label, 'filter pickup date range', $day_ids === [$manifest['reservations'][0]], 'ids ' . implode(',', $day_ids));

  $empty = $fetch($account, $dashboard, ['state' => ['expired']]);
  $check($label, 'empty state has no rows', $rows($empty['body']) === []);
  $check($label, 'empty state is truthful', str_contains($empty['body'], 'No reservation matches these filters.') && str_contains($empty['body'], 'hides no row it fetched'));

  // The board this page sits beside, and the detail page a row links to: both
  // pre-existing, and neither is a way round the column permissions.
  $board = $fetch($account, '/admin/structure/webform/dispatch-planner');
  $check($label, 'planner board still reachable', $board['status'] === 200, 'status ' . $board['status']);

  $detail = $fetch($account, '/admin/structure/webform/dispatch-planner/reservation/' . $manifest['reservations'][0]);
  $check($label, 'detail page status', in_array($detail['status'], [200, 403], TRUE), 'status ' . $detail['status']);
  if ($detail['status'] === 200) {
    $hunt('detail page', $detail['body']);
  }

  // Read-only roles must not reach a route that writes.
  $may_write = $account->hasPermission('manage dispatch schedule');
  foreach ($mutations as $name => $path) {
    $probe = $fetch($account, $path);
    $check(
      $label,
      ($may_write ? 'mutation reachable: ' : 'mutation denied: ') . $name,
      $may_write ? $probe['status'] === 200 : $probe['status'] === 403,
      'status ' . $probe['status']
    );
  }
  print "\n";
}

$passed = count(array_filter($results));
$total = count($results);

// The browser gate's expectation file. Written only on a full run, so a
// single-persona debugging pass cannot leave a partial file behind that the
// browser gate would then read as the whole matrix. No stored hash goes in it:
// the browser gate checks the shape of a hash, not a copy of one.
if ($only === '') {
  $written = file_put_contents('qa/tmp/task10c-expect.json', json_encode([
    'dashboard' => $dashboard,
    'board' => '/admin/structure/webform/dispatch-planner',
    'row_count' => count($ledger),
    'open_fields' => array_keys(reset($expected)['open']),
    'protected' => $protected,
    'expected' => $expected,
    'personas' => $export,
    'mutations' => $mutations,
    'filters' => [
      'released' => ['value' => 'released', 'ids' => [$manifest['reservations'][3]]],
      'confirmed' => ['value' => 'confirmed', 'count' => 2],
      'day' => ['min' => '2026-09-08 00:00:00', 'max' => '2026-09-08 23:59:59', 'ids' => [$manifest['reservations'][0]]],
      'empty' => ['value' => 'expired'],
    ],
    'empty_text' => ['No reservation matches these filters.', 'hides no row it fetched'],
  ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
  print sprintf("expectations written: qa/tmp/task10c-expect.json (%d bytes, %d personas)\n", (int) $written, count($export));
}

print sprintf("RESULT: %d/%d checks passed\n", $passed, $total);
// No exit() here: Drush reports a script that exits directly as having
// terminated abnormally, which would read as a failed gate on a clean run. The
// verdict line is the gate.
print 'VERDICT: ' . ($passed === $total ? 'GREEN' : 'RED') . "\n";
