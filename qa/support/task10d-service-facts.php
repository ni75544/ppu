<?php

/**
 * @file
 * What each hg service actually requires, read from the form's own #states.
 *
 * Read-only reconnaissance for Task 10D. Each Manual Review walk has to complete
 * "its actual service-specific required facts", and the only non-invented source
 * for that list is the conditional logic the form itself carries. So this
 * evaluates every element's `visible` and `required` arms against one service
 * value at a time and prints what that service is asked.
 *
 * Only the `service_type` dimension is evaluated. Conditions keyed on any other
 * selector are reported as dependencies rather than guessed at: whether the
 * destination-access follow-up is required depends on an answer the visitor has
 * not given yet, and inventing one here is exactly the fixture dependency this
 * repository's QA notes warn about. An element gated on another answer is listed
 * with that selector beside it, so the walk can decide honestly.
 *
 * Prints no submitted value and touches no entity. Safe on any site.
 *
 * Usage:
 *   ddev exec vendor/bin/drush php:script qa/support/task10d-service-facts.php
 *   ddev exec vendor/bin/drush --uri=https://ppu-qa.ddev.site \
 *     php:script qa/support/task10d-service-facts.php local_residential_move
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

$service_element = $flat['service_type'] ?? [];
$all_services = array_keys((array) ($service_element['#options'] ?? []));
$only = array_slice($extra ?? [], 0);
$services = $only === [] ? $all_services : array_values(array_intersect($all_services, $only));

/** The page an element sits on, from the initialized tree's own parent chain. */
$initialized = $webform->getElementsInitializedAndFlattened();
$page_of = static function (string $needle) use ($decoded, $initialized): string {
  $parents = (array) ($initialized[$needle]['#webform_parents'] ?? []);
  foreach ($parents as $parent) {
    if (array_key_exists((string) $parent, $decoded)) {
      return (string) $parent;
    }
  }
  foreach ($decoded as $page => $children) {
    if (is_array($children) && array_key_exists($needle, $children)) {
      return (string) $page;
    }
  }
  return '(root)';
};

const SERVICE_SELECTOR = ':input[name="service_type"]';

/**
 * Whether one selector's comparison admits this service value.
 *
 * Returns NULL when the comparison is keyed on some other answer, which is a
 * dependency rather than a verdict. The caller collects those and prints them.
 */
$admits = static function (string $selector, $comparison, string $service): ?bool {
  if ($selector !== SERVICE_SELECTOR) {
    return NULL;
  }
  if (!is_array($comparison)) {
    return NULL;
  }
  foreach ($comparison as $trigger => $expected) {
    if ($trigger === 'value' && is_array($expected)) {
      if (array_key_exists('!pattern', $expected)) {
        return preg_match('/' . (string) $expected['!pattern'] . '/', $service) !== 1;
      }
      if (array_key_exists('pattern', $expected)) {
        return preg_match('/' . (string) $expected['pattern'] . '/', $service) === 1;
      }
      // A list of accepted values.
      return in_array($service, array_map('strval', $expected), TRUE);
    }
    if ($trigger === 'value') {
      return (string) $expected === $service;
    }
    if ($trigger === '!value') {
      return is_array($expected)
        ? !in_array($service, array_map('strval', $expected), TRUE)
        : (string) $expected !== $service;
    }
  }

  return NULL;
};

/**
 * Evaluates one #states arm for one service.
 *
 * A list of condition maps is OR and a single map is AND, which is how Drupal
 * reads them. Conditions on another answer contribute no verdict; they are
 * returned as dependencies so an element the service *can* reach is reported as
 * reachable-but-dependent rather than silently included or silently dropped.
 */
$arm = static function ($conditions, string $service) use ($admits): array {
  $deps = [];
  if (!is_array($conditions) || $conditions === []) {
    return ['verdict' => NULL, 'deps' => $deps];
  }
  $is_list = array_keys($conditions) === range(0, count($conditions) - 1);
  $maps = $is_list ? $conditions : [$conditions];
  $any = FALSE;
  $all = TRUE;
  $decided = FALSE;
  foreach ($maps as $map) {
    if (!is_array($map)) {
      continue;
    }
    $map_all = TRUE;
    $map_decided = FALSE;
    foreach ($map as $selector => $comparison) {
      if (!is_string($selector)) {
        continue;
      }
      $verdict = $admits($selector, $comparison, $service);
      if ($verdict === NULL) {
        $deps[$selector] = TRUE;
        continue;
      }
      $map_decided = TRUE;
      $map_all = $map_all && $verdict;
    }
    if ($map_decided) {
      $decided = TRUE;
      $any = $any || $map_all;
      $all = $all && $map_all;
    }
  }
  if (!$decided) {
    return ['verdict' => NULL, 'deps' => array_keys($deps)];
  }

  return ['verdict' => $is_list ? $any : $all, 'deps' => array_keys($deps)];
};

print sprintf("site=%s  hg elements=%d  services=%d\n", $site, count($flat), count($services));
print "legend: REQ=always required  REQ*=required by #states  dep=<selector> gated on another answer\n";

$containers = ['webform_wizard_page', 'webform_section', 'container', 'fieldset',
  'webform_flexbox', 'details', 'markup', 'webform_markup', 'processed_text',
  'webform_actions', 'webform_horizontal_rule'];

foreach ($services as $service) {
  print sprintf("\n=== %s ===\n", $service);
  $rows = [];
  foreach ($flat as $key => $element) {
    $type = (string) ($element['#type'] ?? '');
    if (in_array($type, $containers, TRUE) || $type === '') {
      continue;
    }
    if (array_key_exists('#access_create_roles', $element)
      && (array) $element['#access_create_roles'] === []) {
      // Retired: the form builder does not ask it of anyone creating a booking.
      continue;
    }
    if (($element['#access'] ?? TRUE) === FALSE) {
      continue;
    }
    $states = (array) ($element['#states'] ?? []);
    $visible = $arm($states['visible'] ?? NULL, $service);
    if ($visible['verdict'] === FALSE) {
      continue;
    }
    $required_states = $arm($states['required'] ?? NULL, $service);
    $always = !empty($element['#required']);
    $required = $always || $required_states['verdict'] === TRUE;
    if (!$required) {
      continue;
    }
    $deps = array_unique(array_merge($visible['deps'], $required_states['deps']));
    $rows[] = [
      'key' => $key,
      'page' => $page_of($key),
      'type' => $type,
      'flag' => $always ? 'REQ ' : 'REQ*',
      'options' => isset($element['#options']) && is_array($element['#options'])
        ? implode('|', array_slice(array_keys($element['#options']), 0, 6)) : '',
      'deps' => $deps,
    ];
  }
  usort($rows, static fn (array $a, array $b): int => [$a['page'], $a['key']] <=> [$b['page'], $b['key']]);
  foreach ($rows as $row) {
    print sprintf("  %s %-9s %-32s %-18s %s%s\n", $row['flag'], $row['page'], $row['key'], $row['type'],
      $row['options'] === '' ? '' : 'options=' . $row['options'],
      $row['deps'] === [] ? '' : '  dep=' . implode(',', array_map(
        static fn (string $s): string => preg_replace('/^:input\[name="(.*)"\]$/', '$1', $s), $row['deps'])));
  }
  print sprintf("  (%d required elements reachable for this service)\n", count($rows));
}
