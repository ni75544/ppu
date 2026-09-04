<?php

/**
 * @file
 * Read-only: prints the hg wizard pages and every required element per page.
 *
 * Needed because a browser walk can only reach the final Submit control by
 * answering each page before it, and "which page holds a required control" is a
 * Webform configuration question that the code graph does not cover. Read from
 * the active Webform; changes nothing.
 *
 * Usage: ddev exec vendor/bin/drush [--uri=...] php:script \
 *   qa/support/inspect-hg-pages.php
 */

$webform = \Drupal\webform\Entity\Webform::load('hg');
if ($webform === NULL) {
  print "hg: not found\n";
  return;
}

$page = '(before the first page)';
$pages = 0;
foreach ($webform->getElementsDecodedAndFlattened() as $key => $element) {
  $type = $element['#type'] ?? '?';
  if ($type === 'webform_wizard_page') {
    $page = $key;
    $pages++;
    print "== page $pages: $key — " . json_encode($element['#title'] ?? '') . "\n";
    continue;
  }
  $required = !empty($element['#required']);
  $states = isset($element['#states']['required']) ? ' (conditionally required)' : '';
  if ($required || $states !== '') {
    print "   required: " . str_pad($key, 30) . " type=$type$states  page=$page\n";
  }
}

print "\npages=$pages\n";
print 'confirmation_type=' . var_export($webform->getSetting('confirmation_type'), TRUE) . "\n";
print 'draft=' . var_export($webform->getSetting('draft'), TRUE) . "\n";
