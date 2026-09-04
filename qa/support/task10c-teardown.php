<?php

/**
 * @file
 * Removes exactly what qa/support/task10c-seed.php created, and nothing else.
 *
 * QA site only, and it says so twice: the manifest records which site it was
 * written on, and this refuses to run anywhere but sites/qa. A teardown that
 * guesses its target is how a fixture cleanup deletes production rows.
 *
 * It deletes by identifier from the manifest, never by pattern and never by date.
 * A "delete everything that looks like QA data" sweep would also delete whatever
 * an earlier task left behind on purpose — the t7_* accounts and review 6 are
 * meant to still be there.
 *
 * The two submissions go through the entity API, so their data rows, their
 * revisions and any file usage go with them. The four ledger rows are deleted
 * directly, because the Dispatch ledger has no delete: it is an append-and-state
 * ledger, `release()` moves a row to released rather than removing it, and adding
 * a delete method to the product so that a QA fixture could be tidied would be a
 * change to the booking authority. Scoped to four primary keys on the QA site,
 * a DELETE is the smaller and more honest of the two.
 *
 * Prints counts before and after, and a verdict. Exit status is not used; read the
 * verdict line.
 *
 * Usage:
 *   ddev exec vendor/bin/drush --uri=https://ppu-qa.ddev.site \
 *     php:script qa/support/task10c-teardown.php
 */

use Drupal\webform\Entity\WebformSubmission;

$site = \Drupal::service('kernel')->getSitePath();
if ($site !== 'sites/qa') {
  print "REFUSED: this teardown runs on the QA site only, not " . $site . "\n";
  print "VERDICT: RED\n";
  return;
}

$manifest_file = 'qa/tmp/task10c-seed.json';
if (!is_file($manifest_file)) {
  print "REFUSED: no manifest at " . $manifest_file . ", so there is nothing this can prove it created\n";
  print "VERDICT: RED\n";
  return;
}
$manifest = json_decode((string) file_get_contents($manifest_file), TRUE);
if (($manifest['site'] ?? '') !== 'ppu-qa') {
  print "REFUSED: the manifest was written on '" . ($manifest['site'] ?? '?') . "'\n";
  print "VERDICT: RED\n";
  return;
}

$database = \Drupal::database();
$reservations = array_map('intval', $manifest['reservations'] ?? []);
$submissions = array_map('intval', $manifest['submissions'] ?? []);

/** Rows in one table, or NULL when the table is not there. */
$count = static function (string $table) use ($database): ?int {
  return $database->schema()->tableExists($table)
    ? (int) $database->query('SELECT COUNT(*) FROM {' . $table . '}')->fetchField()
    : NULL;
};

$tables = [
  'custom_webform_handlers_dispatch_reservation',
  'custom_webform_handlers_dispatch_guard',
  'custom_webform_handlers_dispatch_status',
  'custom_webform_handlers_checkout_attempt',
  'custom_webform_handlers_review_request',
  'commerce_order',
  'webform_submission',
];
$before = [];
foreach ($tables as $table) {
  $before[$table] = $count($table);
}

print sprintf("site=%s  removing reservations %s and submissions %s\n", $site, implode(',', $reservations), implode(',', $submissions));

foreach ($submissions as $sid) {
  $submission = WebformSubmission::load($sid);
  if ($submission === NULL) {
    print sprintf("  submission %-3d already gone\n", $sid);
    continue;
  }
  // Only ever a draft this fixture created. A saved submission on this form would
  // belong to somebody's walk-through, so the state is checked before deleting.
  if (!$submission->isDraft()) {
    print sprintf("  submission %-3d KEPT: not a draft, so not this fixture's\n", $sid);
    continue;
  }
  $submission->delete();
  print sprintf("  submission %-3d deleted\n", $sid);
}

foreach ($reservations as $id) {
  $deleted = $database->delete('custom_webform_handlers_dispatch_reservation')
    ->condition('dispatch_reservation_id', $id)
    ->execute();
  print sprintf("  reservation %-3d %s\n", $id, $deleted === 1 ? 'deleted' : 'already gone');
}

$after = [];
foreach ($tables as $table) {
  $after[$table] = $count($table);
}

print "\ntable                                          before  after  delta\n";
$unexpected = [];
foreach ($tables as $table) {
  $delta = ($before[$table] === NULL || $after[$table] === NULL) ? 0 : $after[$table] - $before[$table];
  print sprintf(
    "%-46s %6s %6s %6d\n",
    $table,
    $before[$table] === NULL ? 'none' : $before[$table],
    $after[$table] === NULL ? 'none' : $after[$table],
    $delta
  );
  // The two tables this is allowed to shrink, by exactly what was named.
  $allowed = match ($table) {
    'custom_webform_handlers_dispatch_reservation' => -count($reservations),
    'webform_submission' => -count($submissions),
    default => 0,
  };
  if ($delta !== $allowed) {
    $unexpected[] = sprintf('%s moved by %d, expected %d', $table, $delta, $allowed);
  }
}

$left = $reservations === []
  ? 0
  : (int) $database->select('custom_webform_handlers_dispatch_reservation', 'r')
    ->condition('dispatch_reservation_id', $reservations, 'IN')
    ->countQuery()->execute()->fetchField();

print "\n";
print sprintf("fixture rows left in the ledger: %d\n", $left);
foreach ($unexpected as $line) {
  print 'UNEXPECTED: ' . $line . "\n";
}
print 'VERDICT: ' . ($left === 0 && $unexpected === [] ? 'GREEN' : 'RED') . "\n";
