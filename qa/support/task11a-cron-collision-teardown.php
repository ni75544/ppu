<?php

/**
 * @file
 * Reverses one accident, once: the automated cron run that shared its window with
 * the first authenticated-dashboard walk of Task 11A.
 *
 * That walk is preserved as a red manifest, and the ordinary teardown refuses a red
 * manifest on purpose — a red run is evidence, and evidence is not tidied away
 * before it has been read. It has now been read. What it says is that the browser
 * gate itself passed 912/912, that no booking, money, review, reservation, guard,
 * configuration or identity probe moved, that `access denied` did not move, and that
 * the sentinel went red for one reason only: `automated_cron` fired between the two
 * snapshots and wrote 51 rows of its own.
 *
 * So the residue is four things, and this file knows all four by heart rather than
 * by argument: eight session rows named by SHA-256, sixteen login rows and
 * fifty-one cron rows named by primary key, and one state key to put back. There is
 * no generic mode. There is no table name, id, pattern, channel, severity, time
 * range or manifest path to pass in — the manifest is a constant, and it is pinned
 * by the digest of its bytes, so this tool can only ever act on that one archived
 * run. Point it at a different accident and it refuses.
 *
 * Before it deletes anything it proves, in order: the site is sites/qa and the
 * database is qa; the manifest is byte-for-byte the archived one; the archived run
 * moved nothing but the four known things; every one of the 75 records still matches
 * the fingerprint the manifest recorded for it; and the whole site still matches the
 * snapshot the archived run ended on, so nothing has happened in between that this
 * cleanup would be walking over. Any single mismatch refuses the run with nothing
 * deleted.
 *
 * Sessions are matched by hashing every current row, so no raw session id is read
 * into a variable that is printed and none enters an artifact. `watchdog.variables`
 * is never selected: the account name, the exception's file and line and, on a
 * one-time login link, the token itself all live there, and none of it is needed to
 * identify a row by primary key. Cron templates are counted, not printed.
 *
 * Usage — plan only, deletes nothing:
 *   ddev exec vendor/bin/drush --uri=https://ppu-qa.ddev.site \
 *     php:script qa/support/task11a-cron-collision-teardown.php
 *
 * Usage — apply:
 *   ddev exec bash -c 'TASK11A_COLLISION_APPLY=1 vendor/bin/drush \
 *     --uri=https://ppu-qa.ddev.site php:script qa/support/task11a-cron-collision-teardown.php'
 */

/**
 * The one run this tool may act on, pinned by content rather than by name.
 *
 * The digest is the whole safety story: a manifest that has been edited, extended,
 * regenerated or swapped for another run's no longer hashes to this, and the tool
 * stops. Everything below is quoted from that file so that a mismatch is a refusal
 * rather than a silent adaptation.
 */
const TASK11A_MANIFEST = 'qa/tmp/task11a-auth-dashboard-cron-collision.json';
const TASK11A_TRANSCRIPT = 'qa/tmp/task11a-auth-dashboard-cron-collision.txt';
const TASK11A_MANIFEST_SHA256 = '5cc75d7bc062c1774c6b2d45a7817520292eb440ccdc0715c57e665d334fe89c';
const TASK11A_TRANSCRIPT_SHA256 = 'ab423f8fd7f08f9bd0f4049a623d1243525e5571a6d8f17797798432ab74b2b1';

$site = \Drupal::service('kernel')->getSitePath();
if ($site !== 'sites/qa') {
  print 'REFUSED: this teardown deletes rows and runs on the QA site only, not ' . $site . "\n";
  print "VERDICT: RED\n";
  return;
}

$database = \Drupal::database();
$database_name = (string) ($database->getConnectionOptions()['database'] ?? '?');
if ($database_name !== 'qa') {
  print 'REFUSED: the connected database is ' . $database_name . ', not qa' . "\n";
  print "VERDICT: RED\n";
  return;
}

if (!is_readable(TASK11A_MANIFEST) || !is_readable(TASK11A_TRANSCRIPT)) {
  print 'REFUSED: the archived cron-collision run is not readable at ' . TASK11A_MANIFEST . "\n";
  print "VERDICT: RED\n";
  return;
}
$manifest_digest = hash_file('sha256', TASK11A_MANIFEST);
$transcript_digest = hash_file('sha256', TASK11A_TRANSCRIPT);
if (!hash_equals(TASK11A_MANIFEST_SHA256, $manifest_digest)) {
  print "REFUSED: the manifest is not the archived cron-collision run\n";
  print '  expected sha256 ' . substr(TASK11A_MANIFEST_SHA256, 0, 16) . ', read ' . substr($manifest_digest, 0, 16) . "\n";
  print "VERDICT: RED\n";
  return;
}
$manifest = json_decode(file_get_contents(TASK11A_MANIFEST), TRUE);
if (!is_array($manifest)) {
  print "REFUSED: the manifest did not parse\n";
  print "VERDICT: RED\n";
  return;
}

$apply = (bool) getenv('TASK11A_COLLISION_APPLY');
$failures = [];
$results = [];
$check = static function (string $scope, string $label, bool $ok, string $detail = '') use (&$results, &$failures): void {
  $results[] = $ok;
  if (!$ok) {
    $failures[] = $scope . ' / ' . $label . ($detail === '' ? '' : ' — ' . $detail);
  }
  print '[' . ($ok ? 'PASS' : 'FAIL') . '] ' . str_pad($scope, 12) . ' ' . str_pad($label, 48) . ' ' . $detail . "\n";
};

print 'manifest ' . TASK11A_MANIFEST . '  sha256 ' . substr($manifest_digest, 0, 16) . "\n";
print 'mode ' . ($apply ? 'APPLY' : 'PLAN (set TASK11A_COLLISION_APPLY=1 to delete)') . "\n\n";

/**
 * The archived run's identity, field by field.
 *
 * A cleanup that trusts a manifest's contents has to first prove the manifest is the
 * one it was written against. These are not sanity checks — each is a statement that
 * would have to be false for this tool to be pointed at the wrong accident.
 */
$identity = [
  'tool' => 'authenticated-dashboard-sentinel',
  'task' => '11A',
  'site' => 'ppu-qa',
  'site_path' => 'sites/qa',
  'database' => 'qa',
  'runner' => 'qa/browser/task10c-dashboard-gate.mjs',
  'verdict' => 'RED',
];
foreach ($identity as $key => $want) {
  $check('manifest', 'identity ' . $key, (string) ($manifest[$key] ?? '') === $want, (string) ($manifest[$key] ?? '<missing>'));
}
foreach (['started' => 1788497464, 'ended' => 1788497510, 'watchdog_baseline_max' => 758, 'self_test' => 17] as $key => $want) {
  $check('manifest', 'identity ' . $key, (int) ($manifest[$key] ?? -1) === $want, (string) (int) ($manifest[$key] ?? -1));
}
$check('manifest', 'accepted classes are the two known ones',
  ($manifest['accepted_classes'] ?? []) === ['login_session_opened', 'login_one_time_link'],
  implode(' ', $manifest['accepted_classes'] ?? []));
$check('manifest', 'the browser gate itself passed',
  ($manifest['gate']['verdict'] ?? '') === 'GREEN' && (int) ($manifest['gate']['passed'] ?? 0) === 912
  && (int) ($manifest['gate']['total'] ?? 0) === 912,
  ($manifest['gate']['passed'] ?? '?') . '/' . ($manifest['gate']['total'] ?? '?') . ' ' . ($manifest['gate']['verdict'] ?? '?'));
$check('manifest', 'deltas are the four known ones',
  ($manifest['deltas'] ?? []) === ['sessions' => 8, 'watchdog_total' => 67, 'watchdog_user' => 16],
  json_encode($manifest['deltas'] ?? []));

/**
 * Why the archived run went red, asserted rather than assumed.
 *
 * The seven findings are quoted in full. If the archived run had failed for any
 * other reason — an `access denied` row, an unexpected channel, a moved probe — this
 * set would not match and the residue would not be cleanable by a tool that only
 * knows how to remove logins and cron rows.
 */
$expected_findings = [
  'no cron run shared the window',
  'no other log channel moved',
  'every new log row is a login row',
  'classifier saw the same rows',
  'every new log row is authorised',
  'no unexpected type, severity or template',
  'exactly the two login templates',
];
$found_findings = array_map(static fn (array $finding): string => (string) $finding['key'], $manifest['findings'] ?? []);
$check('archived run', 'failed for exactly the seven cron reasons', $found_findings === $expected_findings,
  count($found_findings) . ' finding(s)');

/**
 * The 75 records, counted and shaped before a single one is looked for.
 */
$sessions_wanted = [];
foreach (($manifest['sessions_created'] ?? []) as $row) {
  $sessions_wanted[(string) $row['sha256']] = (int) $row['uid'];
}
$rows_wanted = [];
$composition = [];
foreach (($manifest['watchdog_created'] ?? []) as $row) {
  $rows_wanted[(int) $row['wid']] = $row;
  $key = (string) $row['type'] . '|' . (int) $row['severity'];
  $composition[$key] = ($composition[$key] ?? 0) + 1;
}
ksort($rows_wanted);
ksort($composition);
$baseline_max = (int) $manifest['watchdog_baseline_max'];
$wids = array_keys($rows_wanted);

$check('records', 'eight session rows, all distinct', count($sessions_wanted) === 8,
  count($sessions_wanted) . ' distinct hash(es)');
$session_uids = array_values(array_unique(array_values($sessions_wanted)));
sort($session_uids);
$check('records', 'session uids are the four matrix accounts',
  $session_uids === [1, 2, 3, 4]
  && count(array_filter(array_count_values(array_values($sessions_wanted)), static fn (int $n): bool => $n === 2)) === 4,
  'uids ' . implode(',', $session_uids) . ', two sessions each');
$check('records', 'sixty-seven log rows, all distinct', count($rows_wanted) === 67, count($rows_wanted) . ' distinct wid(s)');
$check('records', 'composition is 16 logins and 51 cron rows',
  $composition === ['cron|4' => 34, 'cron|6' => 17, 'user|6' => 16],
  json_encode($composition));
$check('records', 'every wid is above the archived baseline maximum',
  $wids !== [] && min($wids) > $baseline_max, 'lowest ' . min($wids) . ' > ' . $baseline_max);
$check('records', 'the wid range is contiguous 759 to 825',
  min($wids) === 759 && max($wids) === 825 && count($wids) === 825 - 759 + 1,
  min($wids) . '..' . max($wids));

/**
 * One comparison, used three times.
 *
 * The same key-by-key diff answers all three questions this file has to ask: what
 * moved during the archived run, what has moved since it ended, and what is left
 * over once the residue is gone. Naming every difference with a stable key is what
 * makes the first question answerable as an equality — the set of things that moved
 * during that run must be exactly the four known ones, not merely include them.
 */
$compare = static function (array $was, array $now): array {
  $moved = [];
  foreach (($was['probes'] ?? []) as $key => $probe) {
    $here = $now['probes'][$key] ?? [];
    if ((int) ($probe['count'] ?? -1) !== (int) ($here['count'] ?? -2) || (int) ($probe['max'] ?? -1) !== (int) ($here['max'] ?? -2)) {
      $moved['probe.' . $key] = json_encode($probe) . ' -> ' . json_encode($here);
    }
  }
  foreach (($was['hashes'] ?? []) as $key => $hash) {
    if ((string) ($hash['sha256'] ?? '') !== (string) ($now['hashes'][$key]['sha256'] ?? '')) {
      $moved['hash.' . $key] = substr((string) ($hash['sha256'] ?? ''), 0, 8) . ' -> '
        . substr((string) ($now['hashes'][$key]['sha256'] ?? ''), 0, 8);
    }
  }
  foreach (['total', 'max'] as $key) {
    if ((int) ($was['watchdog'][$key] ?? -1) !== (int) ($now['watchdog'][$key] ?? -2)) {
      $moved['watchdog.' . $key] = (int) $was['watchdog'][$key] . ' -> ' . (int) ($now['watchdog'][$key] ?? -2);
    }
  }
  $error_was = $was['watchdog']['errors'] ?? ['count' => 0, 'max' => 0];
  $error_now = $now['watchdog']['errors'] ?? ['count' => 0, 'max' => 0];
  if ((int) $error_was['count'] !== (int) $error_now['count'] || (int) $error_was['max'] !== (int) $error_now['max']) {
    $moved['watchdog.errors'] = (int) $error_was['count'] . '/' . (int) $error_was['max']
      . ' -> ' . (int) $error_now['count'] . '/' . (int) $error_now['max'];
  }
  $channels = array_unique(array_merge(
    array_keys($was['watchdog']['by_type'] ?? []),
    array_keys($now['watchdog']['by_type'] ?? [])
  ));
  sort($channels);
  foreach ($channels as $channel) {
    $channel_was = $was['watchdog']['by_type'][$channel] ?? ['count' => 0, 'max' => 0];
    $channel_now = $now['watchdog']['by_type'][$channel] ?? ['count' => 0, 'max' => 0];
    if ((int) $channel_was['count'] !== (int) $channel_now['count'] || (int) $channel_was['max'] !== (int) $channel_now['max']) {
      $moved['watchdog.by_type.' . $channel] = (int) $channel_was['count'] . '/' . (int) $channel_was['max']
        . ' -> ' . (int) $channel_now['count'] . '/' . (int) $channel_now['max'];
    }
  }
  if ((int) ($was['sessions']['count'] ?? -1) !== (int) ($now['sessions']['count'] ?? -2)) {
    $moved['sessions.count'] = (int) $was['sessions']['count'] . ' -> ' . (int) ($now['sessions']['count'] ?? -2);
  }
  $sids_was = $was['sessions']['sids'] ?? [];
  $sids_now = $now['sessions']['sids'] ?? [];
  ksort($sids_was);
  ksort($sids_now);
  if ($sids_was !== $sids_now) {
    $moved['sessions.sids'] = count(array_diff_key($sids_now, $sids_was)) . ' added, '
      . count(array_diff_key($sids_was, $sids_now)) . ' vanished';
  }
  foreach (['cron_last', 'cron_interval', 'dblog_row_limit'] as $key) {
    if ((int) ($was[$key] ?? -1) !== (int) ($now[$key] ?? -2)) {
      $moved[$key] = (int) ($was[$key] ?? -1) . ' -> ' . (int) ($now[$key] ?? -2);
    }
  }

  return $moved;
};

/**
 * What the archived run moved — an equality, not an inclusion.
 *
 * This is the check that licenses the deletion. If the cron run had also touched a
 * reservation, an order, a review, a role, a configuration object or the error log,
 * that would show up here as an eighth moved key and the tool would stop: rows can be
 * deleted, but a changed reservation cannot be un-changed by deleting anything.
 */
$archived_movement = $compare($manifest['baseline'] ?? [], $manifest['after'] ?? []);
$archived_expected = [
  'watchdog.total',
  'watchdog.max',
  'watchdog.by_type.cron',
  'watchdog.by_type.user',
  'sessions.count',
  'sessions.sids',
  'cron_last',
];
$archived_keys = array_keys($archived_movement);
sort($archived_keys);
$archived_wanted = $archived_expected;
sort($archived_wanted);
$check('archived run', 'moved exactly the four known things', $archived_keys === $archived_wanted,
  count($archived_keys) . ' key(s): ' . implode(' ', $archived_keys));
foreach (['probe', 'hash'] as $family) {
  $touched = array_filter($archived_keys, static fn (string $key): bool => str_starts_with($key, $family . '.'));
  $check('archived run', 'no ' . $family . ' moved during it', $touched === [], count($touched) . ' moved');
}
$check('archived run', 'access denied did not move during it',
  !isset($archived_movement['watchdog.by_type.access denied']), 'channel held');
$check('archived run', 'no error-severity row was written during it',
  !isset($archived_movement['watchdog.errors']),
  (int) ($manifest['baseline']['watchdog']['errors']['count'] ?? -1) . ' errors, unchanged');
$check('archived run', 'the row limit and cron interval held',
  !isset($archived_movement['dblog_row_limit']) && !isset($archived_movement['cron_interval']),
  (int) ($manifest['baseline']['dblog_row_limit'] ?? 0) . ' rows, ' . (int) ($manifest['baseline']['cron_interval'] ?? 0) . 's');
$check('archived run', 'no session row vanished during it',
  count(array_diff_key($manifest['baseline']['sessions']['sids'] ?? [], $manifest['after']['sessions']['sids'] ?? [])) === 0,
  '0 vanished');
$check('archived run', 'the eight created sessions are exactly the added set',
  array_keys(array_diff_key($manifest['after']['sessions']['sids'] ?? [], $manifest['baseline']['sessions']['sids'] ?? []))
  === array_keys($sessions_wanted),
  '8 added, 8 named');

$cron_from = (int) ($manifest['after']['cron_last'] ?? 0);
$cron_to = (int) ($manifest['baseline']['cron_last'] ?? 0);
$check('archived run', 'one cron time to restore', $cron_from === 1788497466 && $cron_to === 1788486007,
  $cron_from . ' -> ' . $cron_to);

/**
 * The site as it stands now, against the snapshot the archived run ended on.
 *
 * Between that run and this one a second walk was measured and cleaned up, and its
 * teardown proved it had restored the state this run left behind. That claim is
 * re-proved here from the database rather than trusted, because a cleanup keyed to
 * an archived snapshot is only safe if the site is still standing where that snapshot
 * left it. Nothing may have moved — not one probe, not one channel, not one session.
 */
$GLOBALS['auth_dashboard_state_return'] = TRUE;
require __DIR__ . '/auth-dashboard-state.php';
$live = $GLOBALS['auth_dashboard_state'];

$since_movement = $compare($manifest['after'] ?? [], $live);
$check('since', 'nothing has moved since the archived run ended', $since_movement === [],
  $since_movement === [] ? count($live['probes']) . ' probes, ' . (int) $live['sessions']['count'] . ' sessions, watchdog '
    . (int) $live['watchdog']['total'] . '/' . (int) $live['watchdog']['max']
    : count($since_movement) . ' key(s): ' . implode(' ', array_keys($since_movement)));
foreach ($since_movement as $key => $detail) {
  print '        moved ' . $key . ' ' . $detail . "\n";
}
$check('since', 'the site and database are the archived ones',
  (string) $live['site'] === 'sites/qa' && (string) $live['database'] === 'qa',
  $live['site'] . ' / ' . $live['database']);
$check('since', 'cron has not run again',
  (int) $live['cron_last'] === $cron_from, (string) (int) $live['cron_last']);

/**
 * Each record, matched against its own recorded fingerprint.
 *
 * Sessions are found by hashing what is in the table, so the raw id is compared
 * without ever being printed. Log rows are re-read by primary key and must still
 * agree with the manifest on channel, severity and account, and their template must
 * still classify to the class the sentinel recorded — the two login templates are the
 * ones the classifier accepts, quoted from core, and everything else is `unexpected`,
 * which is what all 51 cron rows were recorded as.
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

$session_hits = [];
$session_wrong = [];
foreach ($database->query('SELECT sid, uid FROM {sessions}') as $row) {
  $hash = hash('sha256', (string) $row->sid);
  if (!isset($sessions_wanted[$hash])) {
    continue;
  }
  if ((int) $row->uid !== $sessions_wanted[$hash]) {
    $session_wrong[] = substr($hash, 0, 12) . ': uid ' . (int) $row->uid . ', manifest says ' . $sessions_wanted[$hash];
    continue;
  }
  $session_hits[$hash] = (string) $row->sid;
}
$check('fingerprint', 'all eight session rows are present', count($session_hits) === 8,
  count($session_hits) . ' of 8 matched by hash and uid');
$check('fingerprint', 'no session row disagrees with the manifest', $session_wrong === [],
  $session_wrong === [] ? 'none' : implode('; ', $session_wrong));

$row_wrong = [];
$row_absent = [];
$row_hits = [];
$class_counts = [];
foreach ($rows_wanted as $wid => $expected) {
  $row = $database->query('SELECT wid, type, severity, uid, message FROM {watchdog} WHERE wid = :wid', [':wid' => $wid])
    ->fetchAssoc();
  if (!$row) {
    $row_absent[] = $wid;
    continue;
  }
  $class = $classify((string) $row['type'], (int) $row['severity'], (string) $row['message']);
  if ((string) $row['type'] !== (string) $expected['type']
    || (int) $row['severity'] !== (int) $expected['severity']
    || (int) $row['uid'] !== (int) $expected['uid']
    || $class !== (string) $expected['class']) {
    $row_wrong[] = $wid . ': ' . $row['type'] . '/' . (int) $row['severity'] . ' as ' . $class
      . ', manifest says ' . $expected['type'] . '/' . (int) $expected['severity'] . ' as ' . $expected['class'];
    continue;
  }
  $row_hits[$wid] = TRUE;
  $class_counts[$class] = ($class_counts[$class] ?? 0) + 1;
}
ksort($class_counts);
$check('fingerprint', 'all sixty-seven log rows are present', count($row_hits) === 67,
  count($row_hits) . ' of 67 matched by wid, channel, severity, account and template');
$check('fingerprint', 'no log row is already gone', $row_absent === [],
  $row_absent === [] ? 'none' : count($row_absent) . ' absent: ' . implode(',', array_slice($row_absent, 0, 8)));
$check('fingerprint', 'no log row disagrees with the manifest', $row_wrong === [],
  $row_wrong === [] ? 'none' : implode('; ', array_slice($row_wrong, 0, 3)));
$check('fingerprint', 'sixteen still classify as logins',
  ($class_counts['login_session_opened'] ?? 0) === 8 && ($class_counts['login_one_time_link'] ?? 0) === 8,
  json_encode($class_counts));
$check('fingerprint', 'fifty-one are still the unexpected cron rows',
  ($class_counts['unexpected'] ?? 0) === 51, (string) ($class_counts['unexpected'] ?? 0));

/**
 * Nothing inside the range that the manifest does not name, and nothing outside it
 * that this tool would touch.
 *
 * The 67 ids are contiguous, so the range they span is a closed question: it must
 * contain exactly them. A row that appeared inside it later would be someone else's,
 * and no key here would fit it. Rows above the range belong to later runs and are
 * counted and left alone.
 */
$in_range = [];
$above_range = 0;
foreach ($database->query('SELECT wid FROM {watchdog} WHERE wid > :max ORDER BY wid', [':max' => $baseline_max]) as $row) {
  if ((int) $row->wid <= max($wids)) {
    $in_range[] = (int) $row->wid;
    continue;
  }
  $above_range++;
}
$check('fingerprint', 'the range holds exactly the named rows',
  count(array_diff($in_range, $wids)) === 0 && count($in_range) === 67,
  count($in_range) . ' row(s) in ' . min($wids) . '..' . max($wids));
$check('fingerprint', 'rows from later runs are left alone', TRUE, $above_range . ' row(s) above ' . max($wids));

$login_rows = ($class_counts['login_session_opened'] ?? 0) + ($class_counts['login_one_time_link'] ?? 0);
print "\nresidue: " . count($session_hits) . ' session rows, '
  . $login_rows . ' watchdog:user:severity-6 login rows, '
  . ($class_counts['unexpected'] ?? 0) . ' cron rows, 1 system.cron_last restoration' . "\n";
foreach (array_keys($session_hits) as $hash) {
  print '  session ' . substr($hash, 0, 12) . '  uid ' . $sessions_wanted[$hash] . "\n";
}
print '  log rows ' . min($wids) . '..' . max($wids) . '  user/6 x16  cron/6 x17  cron/4 x34' . "\n";
print '  system.cron_last ' . $cron_from . ' -> ' . $cron_to . "\n";
print '  preserved: ' . TASK11A_MANIFEST . ' and its transcript, untouched' . "\n\n";

$passed = count(array_filter($results));
$total = count($results);
if ($failures) {
  print 'REFUSED: ' . count($failures) . ' of ' . $total . " invariants do not hold; nothing was deleted\n";
  foreach ($failures as $failure) {
    print '  - ' . $failure . "\n";
  }
  print "VERDICT: RED\n";
  return;
}
print 'RESULT: ' . $passed . '/' . $total . " invariants hold\n";

if (!$apply) {
  print "\nPLAN ONLY: nothing was deleted. Re-run with TASK11A_COLLISION_APPLY=1 to remove exactly the records above.\n";
  print "VERDICT: PLAN\n";
  return;
}

/**
 * The deletion. One row at a time, by the key the manifest named, and nothing else.
 *
 * Every session goes by its exact `sid`, every log row by its exact `wid`. There is no
 * range, no `IN` list built from a shape, no pattern, no truncation and no reliance on
 * the auto-increment. `system.cron_last` is set to the one integer the pre-walk
 * snapshot recorded. Drush disables `automated_cron` inside its own process — see the
 * note in auth-dashboard-state.php — so writing that value here cannot provoke the very
 * cron run it is restoring the time of.
 */
$sessions_deleted = 0;
foreach ($session_hits as $hash => $sid) {
  $sessions_deleted += (int) $database->delete('sessions')->condition('sid', $sid)->execute();
}
$rows_deleted = 0;
foreach (array_keys($row_hits) as $wid) {
  $rows_deleted += (int) $database->delete('watchdog')->condition('wid', $wid)->execute();
}
\Drupal::state()->set('system.cron_last', $cron_to);
$cron_written = (int) \Drupal::state()->get('system.cron_last', 0);

print 'deleted ' . $sessions_deleted . ' session rows and ' . $rows_deleted . " watchdog rows\n";
print 'restored system.cron_last to ' . $cron_written . "\n";

/**
 * The post-cleanup snapshot, read fresh and compared with the pre-walk baseline.
 *
 * This is the deliverable. Two counts going back to where they were is arithmetic; a
 * key-by-key match across 31 stores, seven hashes, every log channel, the session set,
 * the cron time and the row limit is a restoration.
 */
unset($GLOBALS['auth_dashboard_state']);
$GLOBALS['auth_dashboard_state_return'] = TRUE;
require __DIR__ . '/auth-dashboard-state.php';
$final = $GLOBALS['auth_dashboard_state'];
$remaining = $compare($manifest['baseline'] ?? [], $final);

print "\n== post-cleanup snapshot against the pre-collision baseline ==\n";
print 'probes ' . count($final['probes']) . '  sessions ' . (int) $final['sessions']['count']
  . '  watchdog ' . (int) $final['watchdog']['total'] . '/' . (int) $final['watchdog']['max']
  . '  cron_last ' . (int) $final['cron_last'] . '  row limit ' . (int) $final['dblog_row_limit'] . "\n";
if ($remaining) {
  foreach ($remaining as $key => $detail) {
    print '  DIFF ' . $key . ' ' . $detail . "\n";
  }
}
else {
  print "  every probe, hash, log channel, session row, cron time and row limit matches the pre-collision baseline exactly\n";
}

// The evidence has to survive its own cleanup. Both artifacts are re-hashed after the
// deletion, because "preserved" is a property of the bytes, not an intention.
$manifest_after = hash_file('sha256', TASK11A_MANIFEST);
$transcript_after = hash_file('sha256', TASK11A_TRANSCRIPT);
$preserved = hash_equals(TASK11A_MANIFEST_SHA256, $manifest_after)
  && hash_equals(TASK11A_TRANSCRIPT_SHA256, $transcript_after);
print 'red-run evidence ' . ($preserved ? 'preserved unchanged' : 'ALTERED') . ': '
  . TASK11A_MANIFEST . ' ' . substr($manifest_after, 0, 12)
  . ', transcript ' . substr($transcript_after, 0, 12) . "\n";

$restored = $remaining === [] && $preserved
  && $sessions_deleted === 8 && $rows_deleted === 67 && $cron_written === $cron_to;

$report = 'qa/tmp/task11a-cron-collision-teardown.json';
file_put_contents($report, json_encode([
  'tool' => 'task11a-cron-collision-teardown',
  'manifest' => TASK11A_MANIFEST,
  'manifest_sha256' => $manifest_after,
  'transcript_sha256' => $transcript_after,
  'evidence_preserved' => $preserved,
  'site' => $final['site'],
  'database' => $final['database'],
  'invariants' => ['passed' => $passed, 'total' => $total],
  'deleted' => [
    'sessions' => $sessions_deleted,
    'watchdog_rows' => $rows_deleted,
    'watchdog_login_rows' => $login_rows,
    'watchdog_cron_rows' => $class_counts['unexpected'] ?? 0,
    'wid_range' => [min($wids), max($wids)],
    'session_hash_prefixes' => array_map(static fn (string $hash): string => substr($hash, 0, 12), array_keys($session_hits)),
  ],
  'cron_last' => ['was' => $cron_from, 'restored_to' => $cron_written],
  'post_cleanup' => [
    'probes' => count($final['probes']),
    'sessions' => (int) $final['sessions']['count'],
    'watchdog' => ['total' => (int) $final['watchdog']['total'], 'max' => (int) $final['watchdog']['max']],
    'cron_last' => (int) $final['cron_last'],
    'dblog_row_limit' => (int) $final['dblog_row_limit'],
  ],
  'differences_from_baseline' => $remaining,
  'verdict' => $restored ? 'GREEN' : 'RED',
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");

print 'report: ' . $report . "\n";
print 'VERDICT: ' . ($restored ? 'GREEN' : 'RED') . "\n";

