<?php

/**
 * @file
 * Read-only write-detector snapshot, printed as one JSON line.
 *
 * Every statement here is a SELECT. A browser acceptance check is supposed to
 * observe the site, not change it, and a count taken once around a whole run
 * cannot say which scenario wrote: two scenarios that write and delete in the
 * same run cancel out, and a run that writes once looks identical to a run that
 * writes once per width. So this is taken immediately before and immediately
 * after exactly one scenario, and it reports the maximum id as well as the count
 * — an insert followed by a delete moves the maximum and leaves the count alone.
 *
 * Keys prefixed `info_` are context, not evidence: this module logs an advisory
 * watchdog row every time it routes an offer to Manual Review, which is what a
 * refusal scenario is for. Gating on those would make a correct refusal red.
 *
 * One caveat about the counts, for `watchdog` only. That table is a ring buffer —
 * `dblog.settings:row_limit` is 1000 and Drupal evicts the oldest rows as new ones
 * arrive — so its counts are not conserved and can fall during a scenario that
 * wrote nothing. The sentinel therefore scores every `watchdog_*` probe on the
 * maximum alone, which still catches every row written because `wid` is
 * auto-increment. Every other probe here is scored on both.
 *
 * Usage: ddev exec vendor/bin/drush php:script qa/support/mutation-state.php
 */

$database = \Drupal::database();

/**
 * Count and maximum id for one table, or NULLs when the table is absent.
 */
$probe = static function (string $table, string $key, string $where = '', array $args = []) use ($database): array {
  if (!$database->schema()->tableExists($table)) {
    return ['count' => NULL, 'max' => NULL, 'missing' => TRUE];
  }
  $sql = 'SELECT COUNT(*) AS n, MAX(' . $key . ') AS m FROM {' . $table . '}'
    . ($where === '' ? '' : ' WHERE ' . $where);
  $row = $database->query($sql, $args)->fetchAssoc();

  return ['count' => (int) $row['n'], 'max' => $row['m'] === NULL ? 0 : (int) $row['m']];
};

$state = [
  // The submission ledger, split by draft state: `hg` runs with draft='none',
  // so a draft row appearing at all is itself a finding.
  'webform_submission_hg' => $probe('webform_submission', 'sid', 'webform_id = :id AND in_draft = 0', [':id' => 'hg']),
  'webform_submission_hg_draft' => $probe('webform_submission', 'sid', 'webform_id = :id AND in_draft = 1', [':id' => 'hg']),
  'webform_submission_any' => $probe('webform_submission', 'sid'),
  // Booking-side authorities.
  'dispatch_reservation' => $probe('custom_webform_handlers_dispatch_reservation', 'dispatch_reservation_id'),
  'dispatch_guard' => $probe('custom_webform_handlers_dispatch_guard', 'guard_id'),
  'dispatch_status' => $probe('custom_webform_handlers_dispatch_status', 'id'),
  'dispatch_notification' => $probe('custom_webform_handlers_dispatch_notification', 'id'),
  'dispatch_notify_claim' => $probe('custom_webform_handlers_dispatch_notify_claim', 'id'),
  'dispatch_blackout' => $probe('custom_webform_handlers_dispatch_blackout', 'blackout_id'),
  'legacy_reservation' => $probe('custom_webform_handlers_reservation', 'reservation_id'),
  'review_request' => $probe('custom_webform_handlers_review_request', 'review_id'),
  'checkout_attempt' => $probe('custom_webform_handlers_checkout_attempt', 'attempt_id'),
  'commerce_order' => $probe('commerce_order', 'order_id'),
  'commerce_order_item' => $probe('commerce_order_item', 'order_item_id'),
  'commerce_payment' => $probe('commerce_payment', 'payment_id'),
  // An authenticated session is how submissions 78 and 79 were made. A browser
  // acceptance check logs in nowhere, so any authenticated session touched
  // during one scenario is a finding about that scenario.
  'sessions' => $probe('sessions', 'timestamp'),
  // Writes Drupal itself announces: 'webform' covers submission creation.
  'watchdog_webform' => $probe('watchdog', 'wid', 'type = :t', [':t' => 'webform']),
  'watchdog_commerce_order' => $probe('watchdog', 'wid', 'type = :t', [':t' => 'commerce_order']),
  'watchdog_user' => $probe('watchdog', 'wid', 'type = :t', [':t' => 'user']),
  'watchdog_errors' => $probe('watchdog', 'wid', 'severity <= 3'),
  // Context only — see the note above.
  'info_watchdog_module' => $probe('watchdog', 'wid', 'type = :t', [':t' => 'custom_webform_handlers']),
  'info_watchdog_all' => $probe('watchdog', 'wid'),
];

print json_encode($state, JSON_UNESCAPED_SLASHES) . "\n";
