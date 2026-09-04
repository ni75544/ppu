<?php

/**
 * @file
 * Strict read-only state snapshot for the authenticated dashboard gate.
 *
 * Every statement here is a SELECT. This is a second, narrower snapshot than
 * qa/support/mutation-state.php, written because that one is deliberately generic:
 * it brackets anonymous browser walks, scores `watchdog` on the maximum id alone
 * because the active site's log is a full ring buffer, and does not look at
 * configuration or at who holds which role. An authenticated walk needs more than
 * that. It signs in as four accounts, so it moves `sessions` and the `user` log by
 * construction, and the only way to accept those two movements without accepting
 * everything is to know exactly which rows appeared and what they say.
 *
 * So this snapshot adds, over the generic one:
 *   - the rest of the Commerce money path — payment methods, order field tables,
 *     the order-number sequence, stores, profiles;
 *   - the identity of every session row, as a hash, so the sentinel can name the
 *     rows the walk created instead of subtracting two counts;
 *   - `watchdog` broken out per channel with counts as well as maxima, plus the
 *     row limit and the last cron time, which together prove no eviction and no
 *     cron run could have moved those counts during the walk;
 *   - a hash over the whole `config` table, and over role and account identity,
 *     because a read-only dashboard that rewrites a view, a permission or a role
 *     grant is exactly the defect a row count cannot see.
 *
 * It never prints a session id, an account name, a mail address or a log
 * variable. Session ids are credentials, so only their SHA-256 appears; identity
 * is published as a hash of the whole set, which changes if any member changes and
 * discloses no member.
 *
 * Usage:
 *   ddev exec vendor/bin/drush --uri=https://ppu-qa.ddev.site \
 *     php:script qa/support/auth-dashboard-state.php
 *
 * Or, from another script in this directory, to get the array instead of the line:
 *   $GLOBALS['auth_dashboard_state_return'] = TRUE;
 *   require __DIR__ . '/auth-dashboard-state.php';
 *   $state = $GLOBALS['auth_dashboard_state'];
 */

$database = \Drupal::database();

/**
 * Count and maximum id for one table, or NULLs when table or column is absent.
 *
 * The column is checked rather than assumed: a probe that throws on a renamed
 * column would take the whole snapshot down, and a probe that silently guessed
 * would report a hard-gated store as unchanged when it had never been read. An
 * absent probe is printed as absent and the sentinel counts them.
 */
$probe = static function (string $table, string $key, string $where = '', array $args = []) use ($database): array {
  $schema = $database->schema();
  if (!$schema->tableExists($table)) {
    return ['count' => NULL, 'max' => NULL, 'absent' => 'table'];
  }
  if (!$schema->fieldExists($table, $key)) {
    return ['count' => NULL, 'max' => NULL, 'absent' => 'column:' . $key];
  }
  $sql = 'SELECT COUNT(*) AS n, MAX(' . $key . ') AS m FROM {' . $table . '}'
    . ($where === '' ? '' : ' WHERE ' . $where);
  $row = $database->query($sql, $args)->fetchAssoc();

  return ['count' => (int) $row['n'], 'max' => $row['m'] === NULL ? 0 : (int) $row['m']];
};

/**
 * Every store whose movement is a finding, whatever the walk claims it did.
 *
 * The first block is the booking authority, key for key as the generic sentinel
 * probes it, so the two snapshots can be read side by side. The second block is
 * the rest of the money path, which the generic snapshot does not carry: an
 * authenticated dashboard reader must not be able to open a payment method, number
 * an order, or write an address profile, and "no new orders" alone does not say so.
 */
$probes = [
  // Submission ledger, split by draft state: `hg` runs with draft='none', so a
  // draft row appearing at all is a finding.
  'webform_submission_hg' => $probe('webform_submission', 'sid', 'webform_id = :id AND in_draft = 0', [':id' => 'hg']),
  'webform_submission_hg_draft' => $probe('webform_submission', 'sid', 'webform_id = :id AND in_draft = 1', [':id' => 'hg']),
  'webform_submission_any' => $probe('webform_submission', 'sid'),
  'webform_submission_data' => $probe('webform_submission_data', 'sid'),
  // Booking authority.
  'dispatch_reservation' => $probe('custom_webform_handlers_dispatch_reservation', 'dispatch_reservation_id'),
  'dispatch_guard' => $probe('custom_webform_handlers_dispatch_guard', 'guard_id'),
  'dispatch_status' => $probe('custom_webform_handlers_dispatch_status', 'id'),
  'dispatch_notification' => $probe('custom_webform_handlers_dispatch_notification', 'id'),
  'dispatch_notify_claim' => $probe('custom_webform_handlers_dispatch_notify_claim', 'id'),
  'dispatch_blackout' => $probe('custom_webform_handlers_dispatch_blackout', 'blackout_id'),
  'legacy_reservation' => $probe('custom_webform_handlers_reservation', 'reservation_id'),
  'review_request' => $probe('custom_webform_handlers_review_request', 'review_id'),
  'checkout_attempt' => $probe('custom_webform_handlers_checkout_attempt', 'attempt_id'),
  // Money authority, including the field tables an order drags with it — an
  // orphan row in one of these is what made a later QA order collide once.
  'commerce_order' => $probe('commerce_order', 'order_id'),
  'commerce_order_item' => $probe('commerce_order_item', 'order_item_id'),
  'commerce_payment' => $probe('commerce_payment', 'payment_id'),
  // Commerce names this key `method_id`, not `payment_method_id`: the entity type
  // is `commerce_payment_method` but its id column is not the entity type plus
  // `_id` the way the others are. The `fieldExists` guard caught the guess and
  // reported the store absent, which is the point of the guard — an ungated money
  // store must be visible as ungated rather than pass as unchanged.
  'commerce_payment_method' => $probe('commerce_payment_method', 'method_id'),
  'commerce_order__order_items' => $probe('commerce_order__order_items', 'entity_id'),
  'commerce_order__adjustments' => $probe('commerce_order__adjustments', 'entity_id'),
  'commerce_order_item__adjustments' => $probe('commerce_order_item__adjustments', 'entity_id'),
  'commerce_order__contact_name' => $probe('commerce_order__field_dispatch_contact_name', 'entity_id'),
  'commerce_order__contact_phone' => $probe('commerce_order__field_dispatch_contact_phone', 'entity_id'),
  'commerce_number_sequence' => $probe('commerce_number_pattern_sequence', 'number'),
  'commerce_store' => $probe('commerce_store', 'store_id'),
  'commerce_store_field_data' => $probe('commerce_store_field_data', 'store_id'),
  'commerce_inbox_message' => $probe('commerce_inbox_message', 'send_date'),
  'profile' => $probe('profile', 'profile_id'),
  'profile_revision' => $probe('profile_revision', 'revision_id'),
  'profile__address' => $probe('profile__address', 'entity_id'),
  // Accounts and role grants. No new account, no new grant, whoever signs in.
  'users' => $probe('users', 'uid'),
  'user__roles' => $probe('user__roles', 'entity_id'),
];

/**
 * A hash over every configuration object under one name prefix.
 *
 * Read from the `config` table rather than through the config factory, so the
 * hash covers the stored bytes and cannot be smoothed over by an override or a
 * warm cache. `config_all` is the one that matters most: a browser walk that is
 * read-only has no business changing any configuration at all, and one hash over
 * the whole table says so in a way no list of table names could.
 */
$config_hash = static function (string $prefix) use ($database): array {
  $rows = $database->query(
    'SELECT name, data FROM {config} WHERE name LIKE :p ORDER BY name',
    [':p' => $database->escapeLike($prefix) . '%']
  )->fetchAllKeyed();

  return ['objects' => count($rows), 'sha256' => hash('sha256', serialize($rows))];
};

/**
 * Who exists and what they may do, as one hash.
 *
 * Deliberately excludes `login`, `access`, `init` and the password hash. Signing
 * in updates the first two — that is what signing in is — so gating on the whole
 * row would make every authenticated gate red for the one movement that is not
 * optional. What must not change is the set of accounts, their status, their names
 * and their role grants, and that is what this covers. The password hash is left
 * out because it is a credential and because `sql:sanitize` already scrambled it.
 */
$identity_hash = static function () use ($database): array {
  $accounts = $database->query(
    'SELECT uid, name, status, langcode FROM {users_field_data} ORDER BY uid, langcode'
  )->fetchAll(\PDO::FETCH_ASSOC);
  $grants = $database->query(
    'SELECT entity_id, delta, roles_target_id FROM {user__roles} ORDER BY entity_id, delta'
  )->fetchAll(\PDO::FETCH_ASSOC);

  return [
    'accounts' => count($accounts),
    'grants' => count($grants),
    'sha256' => hash('sha256', serialize([$accounts, $grants])),
  ];
};

// Session rows, identified rather than counted. The sid is the cookie value: it
// is a live credential, so it is hashed here and never leaves this script in
// clear. The hash is stable, which is the only property the sentinel needs to say
// "these four rows are new and these twenty-seven were already here".
$sessions = ['count' => 0, 'max_timestamp' => 0, 'sids' => []];
if ($database->schema()->tableExists('sessions')) {
  foreach ($database->query('SELECT sid, uid, timestamp FROM {sessions}') as $row) {
    $sessions['sids'][hash('sha256', (string) $row->sid)] = (int) $row->uid;
    $sessions['max_timestamp'] = max($sessions['max_timestamp'], (int) $row->timestamp);
  }
  $sessions['count'] = count($sessions['sids']);
  ksort($sessions['sids']);
}

// The log, per channel, with counts as well as maxima. The generic sentinel scores
// `watchdog` on the maximum alone because the active site's log sits at its row
// limit and evicts rows during a read-only visit. That reasoning does not apply
// here: this snapshot also reports the limit and the current total, so the
// sentinel can prove there is headroom and then hold the counts to account.
$watchdog = ['total' => 0, 'max' => 0, 'errors' => ['count' => 0, 'max' => 0], 'by_type' => []];
if ($database->schema()->tableExists('watchdog')) {
  foreach ($database->query('SELECT type, COUNT(*) AS n, MAX(wid) AS m FROM {watchdog} GROUP BY type ORDER BY type') as $row) {
    $watchdog['by_type'][$row->type] = ['count' => (int) $row->n, 'max' => (int) $row->m];
  }
  $totals = $database->query('SELECT COUNT(*) AS n, MAX(wid) AS m FROM {watchdog}')->fetchAssoc();
  $watchdog['total'] = (int) $totals['n'];
  $watchdog['max'] = $totals['m'] === NULL ? 0 : (int) $totals['m'];
  $errors = $database->query('SELECT COUNT(*) AS n, MAX(wid) AS m FROM {watchdog} WHERE severity <= 3')->fetchAssoc();
  $watchdog['errors'] = ['count' => (int) $errors['n'], 'max' => $errors['m'] === NULL ? 0 : (int) $errors['m']];
}

$state = [
  'site' => \Drupal::service('kernel')->getSitePath(),
  'database' => $database->getConnectionOptions()['database'] ?? '?',
  'probes' => $probes,
  'hashes' => [
    // Everything, then the four that would be named in an incident report.
    'config_all' => $config_hash(''),
    'config_views' => $config_hash('views.view.'),
    'config_user_roles' => $config_hash('user.role.'),
    'config_dispatch' => $config_hash('custom_webform_handlers.'),
    'config_webform_hg' => $config_hash('webform.webform.hg'),
    'config_commerce_payment' => $config_hash('commerce_payment.'),
    'identity' => $identity_hash(),
  ],
  'sessions' => $sessions,
  'watchdog' => $watchdog,
  // Automated cron is enabled on this site. If it fires mid-walk it writes log
  // rows and expirable state of its own, and the honest way to handle that is to
  // notice rather than to allow for it: this key moving means the walk shared its
  // window with a cron run and the run should be repeated.
  'cron_last' => (int) \Drupal::state()->get('system.cron_last', 0),
  // Read from stored configuration rather than through the config factory. Drush
  // sets $GLOBALS['config']['automated_cron.settings']['interval'] = 0 during its
  // own bootstrap — vendor/drush/drush/src/Boot/DrupalBoot8.php:203, "Disable
  // automated cron if the module is enabled" — so a snapshot taken by php:script
  // reads 0 through the factory and would conclude that cron cannot fire. The
  // browser's requests are not Drush's, and the value that governs them is the
  // stored one.
  'cron_interval' => (int) (\Drupal::service('config.storage')->read('automated_cron.settings')['interval'] ?? 0),
  'dblog_row_limit' => (int) \Drupal::config('dblog.settings')->get('row_limit'),
  'read_at' => \Drupal::time()->getRequestTime(),
];

if (!empty($GLOBALS['auth_dashboard_state_return'])) {
  $GLOBALS['auth_dashboard_state'] = $state;
  return;
}

print json_encode($state, JSON_UNESCAPED_SLASHES) . "\n";




