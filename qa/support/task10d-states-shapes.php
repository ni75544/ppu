<?php

/**
 * @file
 * The distinct shapes of #states conditions the active hg Webform uses.
 *
 * Read-only reconnaissance for Task 10D. The task has to answer "what does this
 * service actually require?" for seven services, and the honest source for that
 * is the form's own conditional logic rather than a guess. Emulating Drupal's
 * #states engine is only safe once the shapes in play are known, so this prints
 * the vocabulary first: which selectors gate elements, which comparison
 * operators appear, and whether an arm is a list (OR) or a map (AND).
 *
 * Prints no submitted value and touches no entity. Safe on any site.
 *
 * Usage:
 *   ddev exec vendor/bin/drush php:script qa/support/task10d-states-shapes.php
 */

use Drupal\webform\Entity\Webform;

$webform = Webform::load('hg');
if ($webform === NULL) {
  print "REFUSED: the hg webform is not on this site\n";
  return;
}

$flat = $webform->getElementsDecodedAndFlattened();

$selectors = [];
$operators = [];
$arms = [];
$shapes = [];

/** Records one condition map: selector => comparison. */
$record = static function (array $conditions) use (&$selectors, &$operators, &$shapes): void {
  foreach ($conditions as $selector => $comparison) {
    if (!is_string($selector)) {
      // A nested 'and'/'or' marker rather than a selector.
      $shapes['nested-marker:' . json_encode($comparison)] = TRUE;
      continue;
    }
    $selectors[$selector] = ($selectors[$selector] ?? 0) + 1;
    if (!is_array($comparison)) {
      $operators['(bare ' . gettype($comparison) . ')'] = ($operators['(bare ' . gettype($comparison) . ')'] ?? 0) + 1;
      continue;
    }
    foreach ($comparison as $trigger => $expected) {
      $operators[(string) $trigger] = ($operators[(string) $trigger] ?? 0) + 1;
      if (is_array($expected)) {
        foreach (array_keys($expected) as $inner) {
          $operators[$trigger . '.' . $inner] = ($operators[$trigger . '.' . $inner] ?? 0) + 1;
        }
      }
    }
  }
};

foreach ($flat as $key => $element) {
  foreach ((array) ($element['#states'] ?? []) as $arm => $conditions) {
    $arms[(string) $arm] = ($arms[(string) $arm] ?? 0) + 1;
    if (!is_array($conditions)) {
      $shapes['arm-not-array'] = TRUE;
      continue;
    }
    // A list of condition maps is OR; a single map is AND.
    $is_list = array_keys($conditions) === range(0, count($conditions) - 1);
    $shapes[$is_list ? 'list-of-maps (OR)' : 'single-map (AND)'] = ($shapes[$is_list ? 'list-of-maps (OR)' : 'single-map (AND)'] ?? 0) + 1;
    if ($is_list) {
      foreach ($conditions as $entry) {
        if (is_array($entry)) {
          $record($entry);
        }
        else {
          $shapes['list-entry-not-array:' . gettype($entry)] = TRUE;
        }
      }
    }
    else {
      $record($conditions);
    }
  }
}

print "arms:\n";
ksort($arms);
foreach ($arms as $arm => $n) {
  print sprintf("  %-12s %d\n", $arm, $n);
}

print "\nshapes:\n";
ksort($shapes);
foreach ($shapes as $shape => $n) {
  print sprintf("  %-24s %s\n", $shape, is_bool($n) ? 'seen' : $n);
}

print "\ncomparison operators:\n";
ksort($operators);
foreach ($operators as $op => $n) {
  print sprintf("  %-16s %d\n", $op, $n);
}

print "\nselectors:\n";
arsort($selectors);
foreach ($selectors as $selector => $n) {
  print sprintf("  %-52s %d\n", $selector, $n);
}
