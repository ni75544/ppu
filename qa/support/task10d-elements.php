<?php

/**
 * @file
 * What the active hg Webform asks about dates, times and slots, per service.
 *
 * Read-only reconnaissance for Task 10D. Prints one line per element that is
 * part of the public scheduling surface — the date questions, the legacy time and
 * flexibility questions, the Dispatch widget and the Slot store — plus the
 * per-service #states that decide who sees them. It is a script rather than a
 * one-liner because the answer has to be re-checkable after each phase: the whole
 * point of the task is that seven services end up with the same shell, and the
 * only honest way to say so is to print the shell for each of them twice.
 *
 * Prints no submitted value and touches no entity. Safe on any site.
 *
 * Usage:
 *   ddev exec vendor/bin/drush php:script qa/support/task10d-elements.php
 *   ddev exec vendor/bin/drush --uri=https://ppu-qa.ddev.site \
 *     php:script qa/support/task10d-elements.php
 */

use Drupal\webform\Entity\Webform;

$webform = Webform::load('hg');
if ($webform === NULL) {
  print "REFUSED: the hg webform is not on this site\n";
  return;
}

$site = \Drupal::service('kernel')->getSitePath();
$flat = $webform->getElementsDecodedAndFlattened();
$decoded = $webform->getElementsDecoded();

// The scheduling surface: everything a visitor could read as "when".
$watch = [
  'preferred_service_date',
  'preferred_service_time',
  'date_flexibility',
  'dispatch_slot',
  'dispatch_slots',
  'pickup_date',
  'service_date',
];
$found = [];
foreach ($flat as $key => $element) {
  $type = (string) ($element['#type'] ?? '');
  $title = (string) ($element['#title'] ?? '');
  $date_like = $type === 'date' || $type === 'datetime' || $type === 'webform_time';
  if (in_array($key, $watch, TRUE) || $date_like || stripos($title, 'date') !== FALSE
    || stripos($title, 'time') !== FALSE || stripos($key, 'dispatch') !== FALSE) {
    $found[$key] = $element;
  }
}

print sprintf("site=%s  hg elements=%d  scheduling-surface=%d\n\n", $site, count($flat), count($found));

/** Which page an element sits on, by walking the decoded tree one level. */
$page_of = static function (string $needle) use ($decoded): string {
  foreach ($decoded as $page => $children) {
    if (is_array($children) && isset($children[$needle])) {
      return (string) $page;
    }
  }
  return '(nested or root)';
};

foreach ($found as $key => $element) {
  print sprintf(
    "%-28s page=%-8s type=%-16s required=%s access_create_roles=%s\n",
    $key,
    $page_of($key),
    (string) ($element['#type'] ?? '?'),
    !empty($element['#required']) ? 'yes' : 'no',
    array_key_exists('#access_create_roles', $element)
      ? '[' . implode(',', (array) $element['#access_create_roles']) . ']'
      : '(unset)'
  );
  print sprintf("%-28s title=%s\n", '', (string) ($element['#title'] ?? ''));
  foreach ((array) ($element['#states'] ?? []) as $arm => $conditions) {
    print sprintf("%-28s states.%-9s %s\n", '', $arm, json_encode($conditions, JSON_UNESCAPED_SLASHES));
  }
  if (isset($element['#options']) && is_array($element['#options'])) {
    print sprintf("%-28s options=%s\n", '', implode(',', array_keys($element['#options'])));
  }
  print "\n";
}

// The service list itself, and which handler serves the form.
$service = $flat['service_type'] ?? [];
print "service_type options: " . implode(', ', array_keys((array) ($service['#options'] ?? []))) . "\n";
print "service_type required: " . (!empty($service['#required']) ? 'yes' : 'no') . "\n\n";

print "handlers:\n";
foreach ($webform->getHandlers() as $handler) {
  print sprintf("  %-28s %s status=%s\n", $handler->getHandlerId(), $handler->getPluginId(), $handler->isEnabled() ? 'on' : 'off');
}
