<?php

/**
 * @file
 * Removes exactly the rows the authenticated dashboard walk created, and nothing
 * that resembles them.
 *
 * A cleanup tool is the most dangerous thing in a QA harness, because it is the
 * one that deletes, and because the shapes it deletes by are the shapes real data
 * has. "Sessions belonging to the persona accounts" would take out the eight rows
 * an earlier run left behind; "log rows from the last ten minutes" would take out
 * whatever else happened to be logged in those ten minutes. Neither is used here.
 *
 * The only keys accepted are the ones the sentinel published as it measured them:
 * the SHA-256 of each session id it saw appear, and the primary key of each log row
 * it read and classified. Sessions are matched by hashing every current row and
 * comparing — the raw session id never leaves the database and never enters the
 * manifest — and log rows are re-read and re-checked against the manifest's own
 * record of their channel and severity before they go. A row that has changed
 * underneath the manifest is left alone and reported.
 *
 * It refuses to run at all unless the site path is sites/qa, unless the manifest
 * says it was written for ppu-qa, and unless the sentinel's verdict was GREEN. The
 * last condition matters: a red run is evidence, and evidence is not to be tidied
 * away before it has been read.
 *
 * Then it re-reads the strict snapshot and prints a key-by-key comparison against
 * the baseline the sentinel recorded before the walk. That comparison, not the
 * deletion, is the deliverable — restoring the count is worth nothing unless the
 * booking and money stores were never touched in the first place.
 *
 * Usage — plan only, deletes nothing:
 *   ddev exec vendor/bin/drush --uri=https://ppu-qa.ddev.site \
 *     php:script qa/support/auth-dashboard-teardown.php
 *
 * Usage — apply:
 *   ddev exec bash -c 'AUTH_TEARDOWN_APPLY=1 vendor/bin/drush \
 *     --uri=https://ppu-qa.ddev.site php:script qa/support/auth-dashboard-teardown.php'
 */

$site = \Drupal::service('kernel')->getSitePath();
if ($site !== 'sites/qa') {
  print 'REFUSED: this teardown runs on the QA site only, not ' . $site . "\n";
  print "VERDICT: RED\n";
  return;
}

$manifest_file = getenv('AUTH_TEARDOWN_MANIFEST') ?: 'qa/tmp/task11a-auth-dashboard.json';
$manifest = is_readable($manifest_file) ? json_decode(file_get_contents($manifest_file), TRUE) : NULL;
if (!is_array($manifest)) {
  print 'REFUSED: no readable manifest at ' . $manifest_file . "\n";
  print "VERDICT: RED\n";
  return;
}
if (($manifest['site'] ?? '') !== 'ppu-qa' || ($manifest['site_path'] ?? '') !== 'sites/qa') {
  print "REFUSED: the manifest was not written for ppu-qa\n";
  print "VERDICT: RED\n";
  return;
}
if (($manifest['verdict'] ?? '') !== 'GREEN') {
  print 'REFUSED: the sentinel verdict was ' . ($manifest['verdict'] ?? '?')
    . "; a red run is evidence and is not cleaned up automatically\n";
  print "VERDICT: RED\n";
  return;
}

$apply = (bool) getenv('AUTH_TEARDOWN_APPLY');
$database = \Drupal::database();
$baseline = $manifest['baseline'] ?? [];
$wanted_sids = [];
foreach (($manifest['sessions_created'] ?? []) as $row) {
  $wanted_sids[$row['sha256']] = (int) $row['uid'];
}
$wanted_wids = [];
foreach (($manifest['watchdog_created'] ?? []) as $row) {
  $wanted_wids[(int) $row['wid']] = $row;
}
$baseline_max = (int) ($manifest['watchdog_baseline_max'] ?? 0);

print 'manifest ' . $manifest_file . ' verdict ' . $manifest['verdict']
  . ' sessions ' . count($wanted_sids) . ' log rows ' . count($wanted_wids) . "\n";
print 'mode ' . ($apply ? 'APPLY' : 'PLAN (set AUTH_TEARDOWN_APPLY=1 to delete)') . "\n";

/**
 * Sessions, matched by hashing what is there rather than by searching for a shape.
 *
 * Every current row is hashed and looked up in the manifest. A row the manifest
 * does not name is not touched and not reported in detail — there are eight such
 * rows from an earlier run of this gate, and leaving them is deliberate: they are
 * not this run's, so this run has no exact key for them.
 */
$session_hits = [];
$session_misses = array_keys($wanted_sids);
foreach ($database->query('SELECT sid, uid FROM {sessions}') as $row) {
  $hash = hash('sha256', (string) $row->sid);
  if (isset($wanted_sids[$hash])) {
    if ((int) $row->uid !== $wanted_sids[$hash]) {
      print '  skip session ' . substr($hash, 0, 12) . ': uid is ' . (int) $row->uid
        . ', manifest says ' . $wanted_sids[$hash] . "\n";
      continue;
    }
    $session_hits[$hash] = (string) $row->sid;
    $session_misses = array_values(array_diff($session_misses, [$hash]));
  }
}

$sessions_deleted = 0;
foreach ($session_hits as $hash => $sid) {
  if ($apply) {
    $sessions_deleted += (int) $database->delete('sessions')->condition('sid', $sid)->execute();
  }
  print '  ' . ($apply ? 'deleted' : 'would delete') . ' session ' . substr($hash, 0, 12)
    . ' uid ' . $wanted_sids[$hash] . "\n";
}
foreach ($session_misses as $hash) {
  print '  absent already: session ' . substr($hash, 0, 12) . "\n";
}

/**
 * Log rows, by primary key, re-verified against the manifest before deletion.
 *
 * Three conditions, all required: the id must be above the baseline maximum, so a
 * pre-existing row can never be reached even if the manifest were wrong; the
 * channel and severity must still be what the sentinel classified; and the id must
 * be named in the manifest. A row that fails any of them is left in place and
 * printed, because a cleanup that corrects the manifest is a cleanup that has
 * stopped being a measurement.
 */
$log_deleted = 0;
$log_skipped = [];
$log_absent = 0;
ksort($wanted_wids);
foreach ($wanted_wids as $wid => $expected) {
  if ($wid <= $baseline_max) {
    $log_skipped[] = $wid . ': at or below the baseline maximum ' . $baseline_max;
    continue;
  }
  $row = $database->query('SELECT wid, type, severity FROM {watchdog} WHERE wid = :wid', [':wid' => $wid])->fetchAssoc();
  if (!$row) {
    $log_absent++;
    continue;
  }
  if ((string) $row['type'] !== (string) $expected['type'] || (int) $row['severity'] !== (int) $expected['severity']) {
    $log_skipped[] = $wid . ': is ' . $row['type'] . '/' . (int) $row['severity']
      . ', manifest says ' . $expected['type'] . '/' . (int) $expected['severity'];
    continue;
  }
  if ($apply) {
    $log_deleted += (int) $database->delete('watchdog')->condition('wid', $wid)->execute();
  }
}
print '  ' . ($apply ? 'deleted' : 'would delete') . ' ' . ($apply ? $log_deleted : count($wanted_wids) - count($log_skipped) - $log_absent)
  . ' log rows above wid ' . $baseline_max . "\n";
if ($log_absent) {
  print '  absent already: ' . $log_absent . " log rows\n";
}
foreach ($log_skipped as $reason) {
  print '  SKIPPED log row ' . $reason . "\n";
}

// Any row above the baseline that the manifest does not name is left alone and
// reported: it was written by something other than the walk the sentinel measured,
// so this tool has no key for it and no business guessing one.
$unnamed = [];
foreach ($database->query('SELECT wid, type, severity FROM {watchdog} WHERE wid > :max ORDER BY wid', [':max' => $baseline_max]) as $row) {
  if (!isset($wanted_wids[(int) $row->wid])) {
    $unnamed[] = (int) $row->wid . ':' . $row->type . '/' . (int) $row->severity;
  }
}
if ($unnamed) {
  print '  LEFT IN PLACE, not named by the manifest: ' . implode(' ', $unnamed) . "\n";
}

// Re-read the strict snapshot in include mode and compare it, key by key, with the
// baseline the sentinel recorded before the walk. The deletion above restores two
// counts; this is the part that proves nothing else ever moved.
$GLOBALS['auth_dashboard_state_return'] = TRUE;
require __DIR__ . '/auth-dashboard-state.php';
$final = $GLOBALS['auth_dashboard_state'];

$diffs = [];
foreach (($baseline['probes'] ?? []) as $key => $was) {
  $now = $final['probes'][$key] ?? [];
  if (($now['count'] ?? NULL) !== ($was['count'] ?? NULL) || ($now['max'] ?? NULL) !== ($was['max'] ?? NULL)) {
    $diffs[] = 'probe ' . $key . ' ' . json_encode($was) . ' -> ' . json_encode($now);
  }
}
foreach (($baseline['hashes'] ?? []) as $key => $was) {
  if (($final['hashes'][$key]['sha256'] ?? '') !== ($was['sha256'] ?? '')) {
    $diffs[] = 'hash ' . $key . ' changed';
  }
}
foreach (['total', 'max'] as $key) {
  if ((int) ($final['watchdog'][$key] ?? -1) !== (int) ($baseline['watchdog'][$key] ?? -2)) {
    $diffs[] = 'watchdog ' . $key . ' ' . (int) $baseline['watchdog'][$key] . ' -> ' . (int) $final['watchdog'][$key];
  }
}
foreach (['count', 'max'] as $key) {
  if ((int) ($final['watchdog']['errors'][$key] ?? -1) !== (int) ($baseline['watchdog']['errors'][$key] ?? -2)) {
    $diffs[] = 'watchdog errors ' . $key . ' moved';
  }
}
$channels = array_unique(array_merge(
  array_keys($baseline['watchdog']['by_type'] ?? []),
  array_keys($final['watchdog']['by_type'] ?? [])
));
sort($channels);
foreach ($channels as $channel) {
  $was = $baseline['watchdog']['by_type'][$channel] ?? ['count' => 0, 'max' => 0];
  $now = $final['watchdog']['by_type'][$channel] ?? ['count' => 0, 'max' => 0];
  if ((int) $was['count'] !== (int) $now['count'] || (int) $was['max'] !== (int) $now['max']) {
    $diffs[] = 'watchdog ' . $channel . ' ' . (int) $was['count'] . '/' . (int) $was['max']
      . ' -> ' . (int) $now['count'] . '/' . (int) $now['max'];
  }
}
if ((int) ($final['sessions']['count'] ?? -1) !== (int) ($baseline['sessions']['count'] ?? -2)) {
  $diffs[] = 'sessions count ' . (int) $baseline['sessions']['count'] . ' -> ' . (int) $final['sessions']['count'];
}
$sid_diff = array_merge(
  array_diff(array_keys($final['sessions']['sids'] ?? []), array_keys($baseline['sessions']['sids'] ?? [])),
  array_diff(array_keys($baseline['sessions']['sids'] ?? []), array_keys($final['sessions']['sids'] ?? []))
);
if ($sid_diff) {
  $diffs[] = 'session set differs by ' . count($sid_diff) . ' row(s)';
}
foreach (['cron_last', 'dblog_row_limit'] as $key) {
  if ((int) ($final[$key] ?? -1) !== (int) ($baseline[$key] ?? -2)) {
    $diffs[] = $key . ' ' . (int) $baseline[$key] . ' -> ' . (int) $final[$key];
  }
}

print "\n== post-cleanup comparison with the pre-walk baseline ==\n";
print 'probes ' . count($final['probes']) . '  sessions ' . (int) $final['sessions']['count']
  . '  watchdog ' . (int) $final['watchdog']['total'] . '/' . (int) $final['watchdog']['max'] . "\n";
if ($diffs) {
  foreach ($diffs as $diff) {
    print '  DIFF ' . $diff . "\n";
  }
}
else {
  print "  every probed store, hash, log channel, session row, cron time and row limit matches the baseline exactly\n";
}

if (!$apply) {
  print "\nPLAN ONLY: nothing was deleted, so the differences above are the walk's own rows still in place.\n";
  print "VERDICT: PLAN\n";
  return;
}
print "\nsessions deleted " . $sessions_deleted . '  log rows deleted ' . $log_deleted . "\n";
print 'VERDICT: ' . ($diffs ? 'RED' : 'GREEN') . "\n";



