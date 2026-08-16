<?php

/**
 * @file
 * Applies Step 15 validation rules to the project-specific hg Webform.
 *
 * Run from the Drupal root:
 * vendor/bin/drush scr modules/custom_webform_handlers/scripts/update_hg_validation.php
 */

use Drupal\webform\Entity\Webform;

$webform = Webform::load('hg');
if (!$webform) {
  throw new RuntimeException('Webform hg was not found. No changes were made.');
}

$backup = '/root/hg-elements-before-step15.yml';
if (!file_exists($backup) && file_put_contents($backup, $webform->getElementsRaw()) === FALSE) {
  throw new RuntimeException("Could not create backup at {$backup}. No changes were made.");
}

$elements = $webform->getElementsDecoded();
$update = static function (array &$tree, string $target, callable $callback) use (&$update): bool {
  foreach ($tree as $key => &$value) {
    if ($key === $target && is_array($value)) {
      $callback($value);
      return TRUE;
    }
    if (is_array($value) && $update($value, $target, $callback)) {
      return TRUE;
    }
  }
  return FALSE;
};

$required_for_service = static function (string $service): array {
  return [':input[name="service_type"]' => ['value' => $service]];
};
$required_when_value = static function (string $field, string $value): array {
  return [':input[name="' . $field . '"]' => ['value' => $value]];
};

$changes = [
  'phone_number' => static function (array &$element): void {
    $element['#description'] = 'Enter a 10-digit US phone number. Spaces, parentheses, periods, and hyphens are allowed.';
    $element['#pattern'] = '(?:\\+?1[ .-]?)?(?:\\([0-9]{3}\\)|[0-9]{3})[ .-]?[0-9]{3}[ .-]?[0-9]{4}';
    $element['#pattern_error'] = 'Enter a valid 10-digit US phone number.';
    $element['#required'] = TRUE;
  },
  'origin_access_conditions' => static function (array &$element) use ($required_for_service): void {
    $element['#states']['required'] = $required_for_service('local_residential_move');
  },
  'origin_stairs_flights' => static function (array &$element) use ($required_when_value): void {
    $element['#states']['required'] = $required_when_value('origin_access_conditions', 'stairs');
  },
  'destination_access_conditions' => static function (array &$element) use ($required_for_service): void {
    $element['#states']['required'] = $required_for_service('local_residential_move');
  },
  'destination_stairs_flights' => static function (array &$element) use ($required_when_value): void {
    $element['#states']['required'] = $required_when_value('destination_access_conditions', 'stairs');
  },
  'parking_situation' => static function (array &$element) use ($required_for_service): void {
    $element['#states']['required'] = $required_for_service('local_residential_move');
  },
  'specialty_items_residential' => static function (array &$element) use ($required_for_service): void {
    $element['#states']['required'] = $required_for_service('local_residential_move');
  },
  'specialty_items_residential_description' => static function (array &$element) use ($required_when_value): void {
    $element['#states']['required'] = $required_when_value('specialty_items_residential', 'Yes');
  },
  'timing_of_move' => static function (array &$element) use ($required_for_service): void {
    $element['#states']['required'] = $required_for_service('local_commercial_move');
  },
  'items_for_disposal_donation' => static function (array &$element): void {
    unset($element['#required']);
    $element['#states']['required'] = [':input[name="disposal_donation_interest"]' => ['checked' => TRUE]];
  },
];

$missing = [];
foreach ($changes as $key => $callback) {
  if (!$update($elements, $key, $callback)) {
    $missing[] = $key;
  }
}

$webform->setElements($elements);
$webform->save();

print "STEP15 VALIDATION UPDATED\n";
print "BACKUP: {$backup}\n";
if ($missing) {
  print 'FIELDS NOT FOUND: ' . implode(', ', $missing) . "\n";
}
