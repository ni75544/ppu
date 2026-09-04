<?php

/**
 * @file
 * Removes exactly what qa/browser/probe-submit-writes.mjs created, and nothing else.
 *
 * QA site only, and it says so twice: the manifest records which host the probe
 * drove, and this refuses to run against any database but `qa`. A teardown that
 * guesses its target is how a fixture cleanup deletes production rows.
 *
 * It deletes by the rule the manifest states, which is the same invariant the
 * mutation sentinel uses to notice the write in the first place: every primary key
 * here is auto-increment, so every row above the maximum recorded before the probe
 * opened its browser was created by the probe. That is not a pattern match and not
 * a date guess — no "delete everything that looks like QA data" sweep, which would
 * also take the rows earlier tasks left behind on purpose. The Dispatch guard rows
 * and the review request on this site are exactly that, and they survive this.
 *
 * Submissions, orders, order items and payments go through the entity API so their
 * field rows, revisions and file usage go with them. The module's own ledger rows
 * are deleted directly, because the Dispatch ledger has no delete: it is an
 * append-and-state ledger where `release()` moves a row to released rather than
 * removing it, and adding a delete method to the booking authority so a QA fixture
 * could be tidied would be a change to the product. Scoped to named primary keys
 * on the QA site, a DELETE is the smaller and more honest of the two.
 *
 * Two things it deliberately leaves. The `sessions` row, which expires on its own
 * and names no person on a fixture run, and `watchdog`, which is the evidence
 * trail of the run that just happened — a cleanup that erases its own log is how a
 * QA site stops being able to explain itself.
 *
 * One extra, reported apart because the manifest does not name it: order field
 * rows whose parent order no longer exists. `commerce_order` is truncated by
 * qa-reset.php, which resets its auto-increment, so an orphaned
 * `commerce_order__order_items` row makes the next order on this site collide on
 * "Duplicate entry '1-0-0-und' for key 'PRIMARY'" and the module reports it as
 * "A dispatch order could not be opened" — a QA-database artifact wearing the
 * shape of a product failure. They are provably unreferenced: the condition is
 * "no row in commerce_order has this order_id".
 *
 * Prints counts before and after, and a verdict. Exit status is not used; read the
 * verdict line.
 *
 * Usage:
 *   ddev exec vendor/bin/drush --uri=https://ppu-qa.ddev.site \
 *     php:script qa/support/task10d-write-control-teardown.php
 */

$connection = \Drupal::database();
$name = (string) ($connection->getConnectionOptions()['database'] ?? '');
$site = \Drupal::service('kernel')->getSitePath();

if ($name !== 'qa' || $site !== 'sites/qa') {
  print "REFUSED: QA site only. database='$name' site='$site'\n";
  print "VERDICT: RED\n";
  return;
}

$manifest_file = 'qa/tmp/task10d-write-control.json';
if (!is_file($manifest_file)) {
  print "REFUSED: no manifest at $manifest_file, so there is nothing this can prove the probe created\n";
  print "VERDICT: RED\n";
  return;
}
$manifest = json_decode((string) file_get_contents($manifest_file), TRUE);
if (!is_array($manifest) || ($manifest['webform_id'] ?? '') !== 'hg') {
  print "REFUSED: $manifest_file does not name Webform 'hg'\n";
  print "VERDICT: RED\n";
  return;
}
if (str_contains((string) ($manifest['site'] ?? ''), 'ppu.ddev.site')) {
  print "REFUSED: the manifest was written against the live host\n";
  print "VERDICT: RED\n";
  return;
}
$baseline = is_array($manifest['baseline'] ?? NULL) ? $manifest['baseline'] : [];
if ($baseline === []) {
  print "REFUSED: $manifest_file records no baseline maxima\n";
  print "VERDICT: RED\n";
  return;
}

print sprintf(
  "site=%s db=%s  manifest: host=%s phase=%s outcome=%s\n\n",
  $site,
  $name,
  (string) ($manifest['site'] ?? '?'),
  (string) ($manifest['phase'] ?? '?'),
  var_export($manifest['outcome'] ?? NULL, TRUE)
);

/**
 * Everything one submit can create, in the order it is safe to remove.
 *
 * Payments before orders and orders before order items, so nothing is deleted
 * while a row that points at it is still there; the module's ledgers next; the
 * submission last, because the ledger rows reference it. `entity` names the
 * entity type when the entity API owns the row, and is NULL for this module's own
 * ledgers, which have no entity type and no delete method.
 */
$spec = [
  ['key' => 'payment_max', 'table' => 'commerce_payment', 'id' => 'payment_id', 'entity' => 'commerce_payment'],
  ['key' => 'order_max', 'table' => 'commerce_order', 'id' => 'order_id', 'entity' => 'commerce_order'],
  ['key' => 'order_item_max', 'table' => 'commerce_order_item', 'id' => 'order_item_id', 'entity' => 'commerce_order_item'],
  ['key' => 'attempt_max', 'table' => 'custom_webform_handlers_checkout_attempt', 'id' => 'attempt_id', 'entity' => NULL],
  ['key' => 'review_max', 'table' => 'custom_webform_handlers_review_request', 'id' => 'review_id', 'entity' => NULL],
  ['key' => 'reservation_max', 'table' => 'custom_webform_handlers_dispatch_reservation', 'id' => 'dispatch_reservation_id', 'entity' => NULL],
  ['key' => 'guard_max', 'table' => 'custom_webform_handlers_dispatch_guard', 'id' => 'guard_id', 'entity' => NULL],
  ['key' => 'status_max', 'table' => 'custom_webform_handlers_dispatch_status', 'id' => 'id', 'entity' => NULL],
  ['key' => 'notification_max', 'table' => 'custom_webform_handlers_dispatch_notification', 'id' => 'id', 'entity' => NULL],
  ['key' => 'notify_claim_max', 'table' => 'custom_webform_handlers_dispatch_notify_claim', 'id' => 'id', 'entity' => NULL],
  ['key' => 'submission_max', 'table' => 'webform_submission', 'id' => 'sid', 'entity' => 'webform_submission', 'webform' => 'hg'],
];

// Watched, never touched. If one of these moves, this script deleted something it
// does not own. `webform_submission_data` is the exception that cannot be an exact
// number — one submission carries one row per answered element, which is a
// property of the form — so it is checked by direction instead.
$watch = ['custom_webform_handlers_dispatch_blackout', 'webform_submission_data'];

/** Rows in one table, or NULL when the table is not there. */
$count = static function (string $table) use ($connection): ?int {
  return $connection->schema()->tableExists($table)
    ? (int) $connection->query('SELECT COUNT(*) FROM {' . $table . '}')->fetchField()
    : NULL;
};

$before = [];
foreach ([...array_column($spec, 'table'), ...$watch, 'commerce_order__order_items'] as $table) {
  $before[$table] = $count($table);
}

$entity_types = \Drupal::entityTypeManager();
$named = [];
$missing_baseline = [];

foreach ($spec as $row) {
  $table = $row['table'];
  if ($before[$table] === NULL) {
    print sprintf("%-46s no such table\n", $table);
    continue;
  }
  if (!array_key_exists($row['key'], $baseline)) {
    // Reported, not guessed. A manifest written by an older probe knows fewer
    // ledgers than this script does, and treating a missing baseline as 0 would
    // delete every row in the table.
    $missing_baseline[] = $row['key'] . ' (' . $table . ')';
    continue;
  }
  $floor = (int) $baseline[$row['key']];
  $query = $connection->select($table, 't')->fields('t', [$row['id']])
    ->condition($row['id'], $floor, '>')->orderBy($row['id']);
  if (isset($row['webform'])) {
    $query->condition('webform_id', $row['webform']);
  }
  $ids = array_map('intval', $query->execute()->fetchCol());
  $named[$table] = $ids;
  print sprintf("%-46s baseline %-8d above it: %s\n", $table, $floor, $ids === [] ? '(none)' : implode(',', $ids));

  if ($ids === []) {
    continue;
  }
  if ($row['entity'] !== NULL && $entity_types->hasDefinition($row['entity'])) {
    $storage = $entity_types->getStorage($row['entity']);
    $entities = $storage->loadMultiple($ids);
    $storage->delete($entities);
    print sprintf("  deleted %d %s entit%s via the entity API\n", count($entities), $row['entity'], count($entities) === 1 ? 'y' : 'ies');
    continue;
  }
  $deleted = $connection->delete($table)->condition($row['id'], $ids, 'IN')->execute();
  print sprintf("  deleted %d row(s) by primary key\n", (int) $deleted);
}

// Reported apart from the manifest's rows, and deleted only where the parent order
// is genuinely absent.
$orphans = 0;
if ($before['commerce_order__order_items'] !== NULL) {
  $ids = array_map('intval', $connection->query(
    'SELECT DISTINCT f.entity_id FROM {commerce_order__order_items} f
      LEFT JOIN {commerce_order} o ON o.order_id = f.entity_id
      WHERE o.order_id IS NULL'
  )->fetchCol());
  if ($ids !== []) {
    $orphans = (int) $connection->delete('commerce_order__order_items')
      ->condition('entity_id', $ids, 'IN')->execute();
    print sprintf("\norphan order field rows removed: %d (parentless order_id %s)\n", $orphans, implode(',', $ids));
  }
  else {
    print "\norphan order field rows: none\n";
  }
}

$after = [];
foreach (array_keys($before) as $table) {
  $after[$table] = $count($table);
}

print "\ntable                                          before  after  delta\n";
$unexpected = [];
$blackout = 'custom_webform_handlers_dispatch_blackout';
foreach ($before as $table => $was) {
  $is = $after[$table];
  $delta = ($was === NULL || $is === NULL) ? 0 : $is - $was;
  print sprintf("%-46s %6s %6s %6d\n", $table, $was === NULL ? 'none' : $was, $is === NULL ? 'none' : $is, $delta);

  // Direction, not an exact number, for everything the entity API can cascade.
  // Deleting an order takes its order items and its field rows with it, and one
  // submission carries one row per answered element — expectations phrased as
  // exact deltas would go red on a correct cascade, which is the false-red shape
  // this suite has been getting rid of. What must hold exactly is checked below,
  // against the baseline: nothing above it may survive.
  if ($table === $blackout) {
    if ($delta !== 0) {
      $unexpected[] = sprintf('%s moved by %d; this script does not own that table', $table, $delta);
    }
    continue;
  }
  if ($delta > 0) {
    $unexpected[] = sprintf('%s grew by %d during a teardown', $table, $delta);
  }
  if ($table === 'webform_submission_data' && $delta === 0 && ($named['webform_submission'] ?? []) !== []) {
    $unexpected[] = 'webform_submission_data held still while a submission was deleted';
  }
}

// The exact check. Every ledger the manifest gave a floor for must have nothing
// above that floor left, which is the same statement the sentinel makes when it
// says a maximum moved.
print "\nabove the baseline, after:\n";
$left = 0;
foreach ($spec as $row) {
  if ($before[$row['table']] === NULL || !array_key_exists($row['key'], $baseline)) {
    continue;
  }
  $query = $connection->select($row['table'], 't')->condition($row['id'], (int) $baseline[$row['key']], '>');
  if (isset($row['webform'])) {
    $query->condition('webform_id', $row['webform']);
  }
  $remaining = (int) $query->countQuery()->execute()->fetchField();
  $left += $remaining;
  print sprintf("  %-46s %d\n", $row['table'], $remaining);
}

$orphans_left = $before['commerce_order__order_items'] === NULL ? 0 : (int) $connection->query(
  'SELECT COUNT(*) FROM {commerce_order__order_items} f
    LEFT JOIN {commerce_order} o ON o.order_id = f.entity_id
    WHERE o.order_id IS NULL'
)->fetchField();
print sprintf("  %-46s %d\n", 'orphan order field rows', $orphans_left);

print "\nleft in place on purpose: sessions (expires on its own) and watchdog"
  . " (the evidence trail of the run)\n";

foreach ($missing_baseline as $key) {
  print 'NOT CLEANED: the manifest records no ' . $key . ", so its rows were left rather than guessed at\n";
}
if ($left === 0 && array_filter($named) !== []) {
  unlink($manifest_file);
  print "manifest consumed: $manifest_file removed\n";
}
foreach ($unexpected as $line) {
  print 'UNEXPECTED: ' . $line . "\n";
}
print 'VERDICT: ' . ($left === 0 && $orphans_left === 0 && $unexpected === [] && $missing_baseline === [] ? 'GREEN' : 'RED') . "\n";
