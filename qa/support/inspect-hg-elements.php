<?php

/**
 * @file
 * Read-only: prints the hg element flags the acceptance walk depends on.
 *
 * The acceptance run uses only visible customer controls, so whether a control
 * is required decides which negative check is even reachable in a browser. This
 * reads the active Webform and changes nothing.
 *
 * Usage: ddev exec vendor/bin/drush php:script qa/support/inspect-hg-elements.php
 */

$webform = \Drupal\webform\Entity\Webform::load('hg');
if ($webform === NULL) {
  print "hg: not found\n";
  return;
}

$flat = $webform->getElementsDecodedAndFlattened();
$keys = [
  'service_type',
  'delivery_mode',
  'origin_address',
  'destination_address',
  'total_weight_lbs',
  'total_weight_unit',
  'courier_prohibited_ack',
  'prohibited_items_ack',
  'single_driver_handling',
  'preferred_service_date',
  'preferred_service_time',
  'date_flexibility',
  'dispatch_slot',
];

foreach ($keys as $key) {
  if (!isset($flat[$key])) {
    print $key . ": ABSENT\n";
    continue;
  }
  $element = $flat[$key];
  print $key . ': type=' . ($element['#type'] ?? '?')
    . ' required=' . (empty($element['#required']) ? '0' : '1')
    . ' states=' . (isset($element['#states']) ? implode(',', array_keys($element['#states'])) : '-')
    . ' access_create_roles=' . (array_key_exists('#access_create_roles', $element)
      ? json_encode($element['#access_create_roles']) : '-')
    . ' title=' . json_encode($element['#title'] ?? '')
    . "\n";
}

print 'draft=' . var_export($webform->getSetting('draft'), TRUE) . "\n";
print 'wizard_progress_pages=' . var_export($webform->getSetting('wizard_progress_pages'), TRUE) . "\n";

// The two questions the acceptance found still present in the page-2 DOM. A
// count cannot say whether they are asked, masked, or merely stored, so their
// full definitions are printed verbatim.
foreach (['preferred_service_time', 'date_flexibility'] as $key) {
  print "\n--- $key (full definition) ---\n";
  if (!isset($flat[$key])) {
    print "ABSENT from the flattened elements\n";
    continue;
  }
  print json_encode($flat[$key], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
}
