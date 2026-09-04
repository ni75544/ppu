<?php

/**
 * @file
 * Resets the isolated QA site to controlled, non-PII fixtures.
 *
 * This is the only script in `qa/` that writes. It exists because the permanent
 * browser gate must not run against the database that holds real and historical
 * submissions: the QA site gets a clone of this project's configuration and
 * schema, and then every row that could contain a real person is deleted here.
 *
 * The first statement is a guard, not a formality. A `--uri` typo or a missing
 * `sites/qa/settings.php` would otherwise silently aim this at the live database
 * and delete the submissions the incident record depends on. So it refuses to run
 * unless the connection it was handed is literally the `qa` schema, and refusing
 * is an error exit, not a warning.
 *
 * Usage:
 *   ddev exec vendor/bin/drush --uri=https://ppu-qa.ddev.site php:script \
 *     qa/support/qa-reset.php
 */

$connection = \Drupal::database();
$name = (string) ($connection->getConnectionOptions()['database'] ?? '');

if ($name !== 'qa') {
  print "REFUSED: this script only runs against the 'qa' database, not '$name'.\n";
  print "Nothing was deleted. Check --uri and sites/qa/settings.php.\n";
  // Drush reports a non-zero exit for an uncaught exception, which is what a
  // caller checking $? needs to see.
  throw new \RuntimeException("qa-reset.php refused to run against database '$name'");
}

$deleted = [];

/**
 * Truncates one table when it exists, recording what it held.
 */
$purge = static function (string $table) use ($connection, &$deleted): void {
  if (!$connection->schema()->tableExists($table)) {
    $deleted[$table] = 'no-such-table';
    return;
  }
  $before = (int) $connection->query('SELECT COUNT(*) FROM {' . $table . '}')->fetchField();
  $connection->truncate($table)->execute();
  $deleted[$table] = $before . ' -> 0';
};

// Submissions first: the entity tables and every table keyed on a submission id.
foreach ([
  'webform_submission',
  'webform_submission_data',
  'webform_submission_log',
] as $table) {
  $purge($table);
}

// The booking-side ledgers. These are this module's own authorities, and a QA
// run must start from an empty book so a Slot count is deterministic.
foreach ([
  'custom_webform_handlers_dispatch_reservation',
  'custom_webform_handlers_dispatch_guard',
  'custom_webform_handlers_dispatch_status',
  'custom_webform_handlers_dispatch_notification',
  'custom_webform_handlers_dispatch_notify_claim',
  'custom_webform_handlers_dispatch_blackout',
  'custom_webform_handlers_reservation',
  'custom_webform_handlers_review_request',
  'custom_webform_handlers_checkout_attempt',
] as $table) {
  $purge($table);
}

// Commerce carries names, addresses and payment references.
//
// Discovered by pattern rather than listed, because a hand-written list of the
// base tables is what broke this. Truncating `commerce_order` alone resets its
// auto-increment while leaving the field tables populated, so the next order
// opened on the QA site is order 1 again and collides with the field row the
// previous order left behind. The write-control probe hit exactly that on
// 2026-09-03: "Duplicate entry '1-0-0-und' for key 'PRIMARY'" inserting into
// `commerce_order__order_items`, logged as an integrity violation on the
// `commerce_order` channel and again as "A dispatch order could not be opened"
// by this module — a QA-database artifact reported in the shape of a product
// failure. A pattern also covers field tables added later, which a list cannot.
//
// `commerce_payment_method%` is in here for the other half of the reason this
// script exists: it held a stored card, with its number, type and expiry in
// four field tables, that every previous "reset" left untouched.
$order_tables = [];
foreach (['commerce_order%', 'commerce_payment%', 'profile%'] as $pattern) {
  $order_tables = array_merge($order_tables, $connection->schema()->findTables($pattern));
}
sort($order_tables);
foreach (array_unique($order_tables) as $table) {
  $purge($table);
}

// Log rows quote request URIs, referers and user names.
foreach (['watchdog', 'sessions'] as $table) {
  $purge($table);
}

foreach ($deleted as $table => $result) {
  print str_pad($table, 52) . ' ' . $result . "\n";
}

print "\nqa fixtures reset on database '$name'\n";
