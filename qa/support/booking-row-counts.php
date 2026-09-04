<?php

/**
 * @file
 * Read-only booking-side row counts, for a before/after comparison.
 *
 * The acceptance walk must create no submission, order, Hold, or Reservation.
 * Asserting that from the browser can only prove no such request was sent; this
 * proves no such row appeared, which is the claim that actually matters. Every
 * statement here is a SELECT.
 *
 * Usage: ddev exec vendor/bin/drush php:script qa/support/booking-row-counts.php
 */

$database = \Drupal::database();
$count = static function (string $table, string $where = '', array $args = []) use ($database): string {
  if (!$database->schema()->tableExists($table)) {
    return 'no-such-table';
  }
  $sql = 'SELECT COUNT(*) FROM {' . $table . '}' . ($where === '' ? '' : ' WHERE ' . $where);

  return (string) (int) $database->query($sql, $args)->fetchField();
};

$rows = [
  'webform_submission (hg, completed)' => $count('webform_submission', 'webform_id = :id AND in_draft = 0', [':id' => 'hg']),
  'webform_submission (hg, draft)' => $count('webform_submission', 'webform_id = :id AND in_draft = 1', [':id' => 'hg']),
  'commerce_order (all)' => $count('commerce_order'),
  'custom_webform_handlers_dispatch_reservation' => $count('custom_webform_handlers_dispatch_reservation'),
  'custom_webform_handlers_dispatch_guard' => $count('custom_webform_handlers_dispatch_guard'),
  'custom_webform_handlers_reservation' => $count('custom_webform_handlers_reservation'),
  'custom_webform_handlers_review_request' => $count('custom_webform_handlers_review_request'),
];

foreach ($rows as $label => $value) {
  print str_pad($label, 46) . ' ' . $value . "\n";
}
