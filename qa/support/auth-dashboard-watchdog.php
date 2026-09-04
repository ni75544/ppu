<?php

/**
 * @file
 * Classifies the log rows an authenticated browser walk added, without printing
 * anything a log row is not allowed to disclose.
 *
 * The authenticated-dashboard sentinel cannot accept `watchdog` movement on a
 * count alone. "Sixteen new rows" is true of sixteen logins and equally true of
 * one login plus fifteen PHP fatals, and the second is the defect the whole gate
 * exists to catch. So every row above the baseline id is read and classified here,
 * and the sentinel decides on the classification.
 *
 * Exactly two classes exist, both of them core's own login records. Everything
 * else — `access denied`, `php`, `security`, `webform`, `commerce`,
 * `custom_webform_handlers`, an unknown template on the `user` channel, or a
 * `user` row at any severity but info — is `unexpected`, which the sentinel treats
 * as a mutation and fails on. There is deliberately no third class: the browser
 * runner no longer requests any route it expects to be refused, so a refusal row
 * appearing at all is now a finding rather than an expected cost. Route-level
 * authorisation is proven instead by qa/support/task10c-access-matrix.php, which
 * asks the access manager directly and writes no log row.
 *
 * What is deliberately not read: `watchdog.variables`, at all. A log row's
 * variables carry the account name of whoever signed in, the file and line of
 * core's exception handler, and — on a refused one-time login link — the login
 * token itself. None of it is needed to decide whether a row is an expected
 * authentication record, so none of it is unserialized and none of it can leak
 * into an artifact. The `message` column is the developer's static template,
 * which is why it is safe to publish and useful to gate on: a row whose template
 * is not one of the two this site produces for authentication is a finding
 * whatever its channel says.
 *
 * Usage:
 *   AUTH_WATCHDOG_SINCE=636 ddev exec vendor/bin/drush \
 *     --uri=https://ppu-qa.ddev.site php:script qa/support/auth-dashboard-watchdog.php
 *
 * Prints one JSON object. Exits with an `error` key rather than throwing, so the
 * sentinel reports "the classifier refused" instead of an empty parse failure.
 */

$since = (int) (getenv('AUTH_WATCHDOG_SINCE') ?: -1);
if ($since < 0) {
  print json_encode(['error' => 'AUTH_WATCHDOG_SINCE is required and must be the baseline MAX(wid)']) . "\n";
  return;
}

/**
 * One row's class, from its channel, severity and template alone.
 *
 * The two accepted templates are core's, quoted from
 * core/modules/user/user.module and
 * core/modules/user/src/Controller/UserController.php. Matching on the template
 * rather than on the channel is what stops a `user`-channel error from being waved
 * through as a login: the channel says who wrote the row, the template says what
 * the row is.
 */
$classify = static function (string $type, int $severity, string $message): string {
  if ($type === 'user' && $severity === 6 && $message === 'Session opened for %name.') {
    return 'login_session_opened';
  }
  if ($type === 'user' && $severity === 6 && $message === 'User %name used one-time login link at time %timestamp.') {
    return 'login_one_time_link';
  }

  return 'unexpected';
};

$database = \Drupal::database();
$rows = [];
$by_class = [];
$templates = [];

$result = $database->query(
  'SELECT wid, type, severity, uid, message FROM {watchdog} WHERE wid > :since ORDER BY wid',
  [':since' => $since]
);

foreach ($result as $row) {
  $type = (string) $row->type;
  $severity = (int) $row->severity;
  $message = (string) $row->message;
  $class = $classify($type, $severity, $message);
  $rows[] = [
    'wid' => (int) $row->wid,
    'type' => $type,
    'severity' => $severity,
    'uid' => (int) $row->uid,
    'template' => $message,
    'class' => $class,
  ];
  $by_class[$class] = ($by_class[$class] ?? 0) + 1;
  $key = $type . '|' . $severity . '|' . $message;
  $templates[$key] = ($templates[$key] ?? 0) + 1;
}

ksort($by_class);

print json_encode([
  'since' => $since,
  'site' => \Drupal::service('kernel')->getSitePath(),
  'total' => count($rows),
  'by_class' => $by_class,
  'templates' => $templates,
  'rows' => $rows,
], JSON_UNESCAPED_SLASHES) . "\n";
