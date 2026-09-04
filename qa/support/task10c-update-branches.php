<?php

/**
 * @file
 * All three outcomes of custom_webform_handlers_update_10038(), on demand.
 *
 * QA site only, and write-capable: it deletes and recreates the dashboard view to
 * put the update hook in front of each situation it claims to handle. That is why
 * it refuses to run anywhere but sites/qa — on the active site the same steps
 * would take an operator's page away and give it back, which is not something a
 * check gets to do.
 *
 * The three outcomes, in the order they are exercised:
 *   already present — this module's own page is on the name; leave it untouched
 *   conflicting     — somebody else's page is on the name; refuse and say so
 *   created         — the name is free; install and rebuild the router and menu
 *
 * Restores the starting state before it finishes, and proves the restore by
 * comparing the stored configuration against a hash taken at the start. The
 * conflict branch is the one that matters most: the assertion is not that the hook
 * printed a sentence, but that the foreign view is bit-for-bit unchanged after the
 * hook has seen it.
 *
 * Usage:
 *   ddev exec vendor/bin/drush --uri=https://ppu-qa.ddev.site \
 *     php:script qa/support/task10c-update-branches.php
 */

$site = \Drupal::service('kernel')->getSitePath();
if ($site !== 'sites/qa') {
  print "REFUSED: this probe deletes and recreates a view, so it runs on the QA site only, not " . $site . "\n";
  print "VERDICT: RED\n";
  return;
}

$id = 'dispatch_reservations';
$storage = \Drupal::entityTypeManager()->getStorage('view');
\Drupal::moduleHandler()->loadInclude('custom_webform_handlers', 'install');

$results = [];
$check = static function (string $label, bool $ok, string $detail = '') use (&$results): void {
  $results[] = $ok;
  print sprintf("[%s] %-46s %s\n", $ok ? 'PASS' : 'FAIL', $label, $detail);
};

/** A stable fingerprint of one view's stored configuration. */
$fingerprint = static function (?object $view): string {
  return $view === NULL ? 'absent' : hash('sha256', serialize($view->toArray()));
};

$original = $storage->load($id);
if ($original === NULL) {
  print "REFUSED: the dashboard is not installed here, so the already-present branch cannot be exercised\n";
  print "VERDICT: RED\n";
  return;
}
$original_array = $original->toArray();
$original_hash = $fingerprint($original);
print sprintf("start: %s present, fingerprint %s…\n\n", $id, substr($original_hash, 0, 12));

// 1. Already present.
$message = custom_webform_handlers_update_10038();
print '  ' . $message . "\n";
$check('already-present branch says so', str_contains($message, 'was already installed at'), '');
$check('already-present branch changed nothing', $fingerprint($storage->load($id)) === $original_hash);

// 2. Conflicting: a namesake that is not this page.
$storage->load($id)->delete();
$foreign = $storage->create([
  'id' => $id,
  'label' => 'QA namesake, not the dashboard',
  'base_table' => 'users_field_data',
  'base_field' => 'uid',
  'display' => [
    'default' => [
      'display_plugin' => 'default',
      'id' => 'default',
      'display_title' => 'Default',
      'position' => 0,
      'display_options' => ['title' => 'QA namesake'],
    ],
    'page_1' => [
      'display_plugin' => 'page',
      'id' => 'page_1',
      'display_title' => 'Page',
      'position' => 1,
      'display_options' => ['path' => 'qa-namesake-not-the-dashboard'],
    ],
  ],
]);
$foreign->save();
$foreign_hash = $fingerprint($storage->load($id));

print "\n";
$message = custom_webform_handlers_update_10038();
print '  ' . $message . "\n";
$check('conflict branch refuses to install', str_contains($message, 'was not installed and that view was not touched'));
$check('conflict branch names the namesake', str_contains($message, 'QA namesake, not the dashboard'));
$check('conflict branch names both tables', str_contains($message, 'users_field_data')
  && str_contains($message, 'custom_webform_handlers_dispatch_reservation'));
$check('conflict branch names the shipped file', str_contains($message, 'config/install/views.view.' . $id . '.yml'));
$check('the namesake is bit-for-bit unchanged', $fingerprint($storage->load($id)) === $foreign_hash);

// 3. Created, on a free name.
$storage->load($id)->delete();
$check('name is free before the created branch', $storage->load($id) === NULL);
print "\n";
$message = custom_webform_handlers_update_10038();
print '  ' . $message . "\n";
$check('created branch reports the install', str_contains($message, 'Installed the Reservation Dashboard at'));
$check('created branch names the path', str_contains($message, 'admin/structure/webform/dispatch-planner/reservations'));

$restored = $storage->load($id);
$check('the dashboard is back', $restored !== NULL);
// A fresh install writes a new uuid, so the restore is the shipped definition
// carrying the uuid the site started with — anything else would leave every
// config export on this site reporting a change that is only this probe.
if ($restored !== NULL) {
  $rebuilt = $restored->toArray();
  $rebuilt['uuid'] = $original_array['uuid'];
  if (isset($original_array['_core'])) {
    $rebuilt['_core'] = $original_array['_core'];
  }
  $restored->delete();
  $storage->create($rebuilt)->save();
  \Drupal::service('router.builder')->rebuild();
  \Drupal::service('plugin.manager.menu.link')->rebuild();
}
$check('restored to the starting fingerprint', $fingerprint($storage->load($id)) === $original_hash, substr($fingerprint($storage->load($id)), 0, 12) . '…');

$passed = count(array_filter($results));
print sprintf("\nRESULT: %d/%d checks passed\n", $passed, count($results));
print 'VERDICT: ' . ($passed === count($results) ? 'GREEN' : 'RED') . "\n";
