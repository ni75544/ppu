<?php

/**
 * @file
 * The administrator's render of the live dashboard, described and never quoted.
 *
 * Active site. This is the authenticated half of the Task 10C live check, and it
 * is a script rather than a browser session on purpose: signing in as uid 1 in a
 * browser writes a row to `sessions`, and the mutation sentinel bracketing this
 * run gates on that table. An in-process render answers the same question — does
 * the page exist, does it read the real ledger, does the administrator get every
 * column — without making the write detector report a write that happened for the
 * checking rather than for the site.
 *
 * Nothing here prints a cell. The rows on this site belong to real customers, and
 * the columns an administrator sees are the contact, address and price ones. What
 * is printed is a status code, a count, a list of column machine names, and the
 * absence of shapes that must not be there.
 *
 * Read-only: two GET sub-requests, one SELECT for the ledger count, and two reads
 * of the watchdog high-water mark. It writes nothing.
 *
 * Usage: ddev exec vendor/bin/drush php:script qa/support/task10c-live-render.php
 *
 * Called by qa/browser/task10c-live-readonly.mjs, which is itself run through the
 * mutation sentinel so this render is inside the bracketed snapshot.
 */

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\HttpKernelInterface;

$path = '/admin/structure/webform/dispatch-planner/reservations';
// The active site by default. TASK10C_BASE points the same render at ppu-qa, which
// is how the QA page is confirmed to still answer once its fixture has been
// removed; it must match the --uri Drush bootstrapped, or the sub-request would
// describe one site while the container belongs to another.
$base = getenv('TASK10C_BASE') ?: 'https://ppu.ddev.site';
$kernel = \Drupal::service('http_kernel');
$switcher = \Drupal::service('account_switcher');
$database = \Drupal::database();

/** Renders one GET as whoever is passed, or as nobody when that is NULL. */
$render = static function (?int $uid) use ($kernel, $switcher, $base, $path): array {
  $request = Request::create($base . $path, 'GET');
  $request->setSession(new Session(new MockArraySessionStorage()));
  if ($uid !== NULL) {
    $switcher->switchTo(\Drupal::entityTypeManager()->getStorage('user')->load($uid));
  }
  try {
    $response = $kernel->handle($request, HttpKernelInterface::SUB_REQUEST, FALSE);
  }
  catch (HttpExceptionInterface $refusal) {
    // A sub-request that is not allowed to run raises the refusal rather than
    // rendering an error page. That is the answer, so it is reported as the status
    // it carries and not as a crash — and no body comes back, which is the point.
    return [$refusal->getStatusCode(), ''];
  }
  finally {
    if ($uid !== NULL) {
      $switcher->switchBack();
    }
  }

  return [$response->getStatusCode(), (string) $response->getContent()];
};

$high_water = static fn (): int => (int) $database->query('SELECT COALESCE(MAX(wid), 0) FROM {watchdog}')->fetchField();

$before = $high_water();
[$status, $body] = $render(1);
[$anonymous_status] = $render(NULL);
$after = $high_water();

// Column machine names, from the header cells. A name is structure: it says which
// column was built, which is the permission question, and it carries no value.
preg_match_all('#<th[^>]*\bclass="[^"]*views-field-([a-z0-9-]+)#', $body, $headers);
$columns = array_values(array_unique($headers[1]));

$protected = [
  'customer-name', 'customer-phone', 'customer-email',
  'pickup-location', 'delivery-location',
  'price-snapshot-cents', 'order-id',
];

// The two 64-hex runs Drupal puts on every page: Views' DOM id and core's
// permissions hash. Excluded by name, so a stored hash of the same shape still
// counts.
$scrubbed = preg_replace(
  ['#js-view-dom-id-[0-9a-f]{64}#', '#"permissionsHash":"[0-9a-f]{64}"#'],
  ['js-view-dom-id-EXPECTED', '"permissionsHash":"EXPECTED"'],
  $body
);
preg_match_all('/\b[0-9a-f]{40,64}\b/', $scrubbed, $hex);

$markers = array_values(array_filter(
  ['views-data-export', 'data_export', 'views_bulk_operations', 'bulk_form', 'Apply to selected', '.csv'],
  static fn (string $marker): bool => stripos($body, $marker) !== FALSE
));
$words = array_values(array_filter(
  ['hold_token', 'owner_hash', 'hmac', 'authorization'],
  static fn (string $word): bool => stripos($body, $word) !== FALSE
));

// One data row is one <tr> carrying at least one Views field cell, counted the
// same way the browser counts it.
preg_match_all('#<tr[^>]*>(?:(?!</tr>).)*?<td[^>]*\bclass="[^"]*views-field-.*?</tr>#s', $body, $rows);

print json_encode([
  'status' => $status,
  'anonymous_status' => $anonymous_status,
  'title' => preg_match('#<title>(.*?)</title>#s', $body, $title) ? trim(strip_tags($title[1])) : '',
  'titled' => str_contains($body, 'Reservation Dashboard'),
  'has_table' => (bool) preg_match('#<table[^>]*class="[^"]*views-(view-)?table#', $body),
  'filters' => preg_match_all('#<(select|input)[^>]*\bname="(state\[\]|resource_id|webform_id|pickup_arrival\[(min|max)\])"#', $body),
  'rows' => count($rows[0]),
  'ledger_rows' => (int) $database->query('SELECT COUNT(*) FROM {custom_webform_handlers_dispatch_reservation}')->fetchField(),
  'columns' => $columns,
  'missing_protected' => array_values(array_diff($protected, $columns)),
  'hex' => count($hex[0]),
  'markers' => $markers,
  'words' => $words,
  'watchdog_delta' => $after - $before,
], JSON_UNESCAPED_SLASHES) . "\n";
