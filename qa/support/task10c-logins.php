<?php

/**
 * @file
 * Mints one one-time login URL for one QA account.
 *
 * QA site only. The Task 10C personas' passwords are not known to this harness,
 * and inventing new ones would change accounts the earlier tasks left behind. A
 * one-time login link is the supported way in, and it is the reason this is a
 * script rather than a line in the gate: the URL is a credential, so it is
 * written to stdout for the calling process and to nothing else. The gate never
 * logs it, and no terminal ever sees it.
 *
 * A link is invalidated by the login it performs, because the hash covers the
 * account's last-login time. Two links minted in the same second are also the
 * same string. So the caller mints one immediately before each session rather
 * than a batch up front.
 *
 * QA site only. Called by qa/browser/task10c-dashboard-gate.mjs, once per session.
 */

$uid = (int) getenv('TASK10C_UID');
$account = $uid > 0
  ? \Drupal::entityTypeManager()->getStorage('user')->load($uid)
  : NULL;
if ($account === NULL) {
  // No URL on this path, so a failure cannot be mistaken for a credential.
  print "ERROR: no account for uid " . $uid . "\n";
  return;
}

\Drupal::moduleHandler()->loadInclude('user', 'module');
print user_pass_reset_url($account) . "/login\n";
