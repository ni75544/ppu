<?php

/**
 * @file
 * Updates the project-specific hg Webform copy for Step 14.
 *
 * Run from the Drupal root:
 * vendor/bin/drush scr modules/custom_webform_handlers/scripts/update_hg_pricing_copy.php
 */

use Drupal\webform\Entity\Webform;

$webform = Webform::load('hg');
if (!$webform) {
  throw new RuntimeException('Webform hg was not found. No changes were made.');
}

$backup = '/root/hg-elements-before-step14.yml';
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

$changes = [
  'local_residential_move_details' => static function (array &$element): void {
    $element['#description'] = 'The preliminary estimate uses the selected move size, route distance, access conditions, and add-ons. Current configured rates appear in the estimate breakdown.';
  },
  'origin_access_conditions' => static function (array &$element): void {
    $element['#description'] = 'Select the actual access condition so any configured access charge can be included.';
    $element['#options'] = [
      'ground_floor' => 'Ground floor / No stairs',
      'stairs' => 'Stairs (Walk-up)',
      'passenger_elevator' => 'Passenger Elevator',
      'freight_elevator' => 'Freight Elevator',
    ];
  },
  'destination_access_conditions' => static function (array &$element): void {
    $element['#description'] = 'Select the actual access condition so any configured access charge can be included.';
    $element['#options'] = [
      'ground_floor' => 'Ground floor / No stairs',
      'stairs' => 'Stairs (Walk-up)',
      'passenger_elevator' => 'Passenger Elevator',
      'freight_elevator' => 'Freight Elevator',
    ];
  },
  'parking_situation' => static function (array &$element): void {
    $element['#description'] = 'Long-carry or shuttle charges may apply when the truck cannot park near the entrance.';
    $element['#options'] = [
      'close' => 'Truck can park close to the entrance',
      'long_carry' => 'Long carry required (> 50 feet)',
      'shuttle' => 'Shuttle service may be needed',
    ];
  },
  'local_commercial_move_details' => static function (array &$element): void {
    $element['#description'] = 'The preliminary estimate uses the configured commercial base, timing, distance, and selected services. Detailed scope and building requirements are reviewed by a moving specialist.';
  },
  'additional_services_commercial' => static function (array &$element): void {
    $element['#options'] = [
      'packing_unpacking' => 'Full packing/unpacking',
      'it_services' => 'IT services (computer/server disconnect and reconnect)',
      'furniture_assembly' => 'Modular furniture disassembly/reassembly',
    ];
  },
  'pricing_info_long_distance' => static function (array &$element): void {
    $element['#helptext'] = '<strong>Pricing note:</strong> The preliminary estimate uses shipment weight, home-size weight floors, dimensions, route distance, access conditions, and add-ons. Current configured charges appear in the estimate breakdown.';
  },
  'pricing_info_specialty' => static function (array &$element): void {
    $element['#helptext'] = 'A configured starting amount is shown for the selected item. Specialty moves always require review of item specifications, access, equipment, and route conditions.';
  },
  'item_to_be_moved' => static function (array &$element): void {
    $element['#options'] = [
      'piano' => 'Upright or grand piano',
      'gun_safe' => 'Gun safe (heavy or secure item)',
      'fine_art' => 'Fine art or sculpture (fragile or crated)',
      'gym_equipment' => 'Heavy gym equipment',
      'other' => 'Other large single item',
    ];
  },
  'truck_size' => static function (array &$element): void {
    unset($element['#required']);
    $element['#states']['required'][':input[name="service_type"]'] = ['value' => 'diy_truck_rental'];
  },
  'rental_duration' => static function (array &$element): void {
    unset($element['#required']);
    $element['#states']['required'][':input[name="service_type"]'] = ['value' => 'diy_truck_rental'];
  },
  'labor_help_description' => static function (array &$element): void {
    $element['#helptext'] = 'The preliminary estimate includes the configured two-hour labor-help minimum. Additional labor time requires manual review.';
  },
  'service_speed' => static function (array &$element): void {
    $element['#options'] = [
      'standard' => 'Standard (same-day)',
      'rush' => 'Rush (within 2–4 hours)',
      'expedited' => 'Expedited (within 1 hour)',
    ];
  },
  'vehicle_helper_needs' => static function (array &$element): void {
    $element['#options'] = [
      'van_1_helper' => 'Van with 1 helper',
      'truck_1_helper' => 'Truck with 1 helper',
      'truck_2_helpers' => 'Truck with 2 helpers',
    ];
  },
  'packing_services' => static function (array &$element): void {
    $element['#description'] = 'Adds the currently configured preliminary packing charge. Materials and unusual packing requirements may be reviewed separately.';
  },
  'cleaning_services' => static function (array &$element): void {
    $element['#description'] = 'Adds the currently configured preliminary cleaning charge. Final pricing depends on the space and condition.';
  },
  'insurance_options_fieldset' => static function (array &$element): void {
    $element['#description'] = 'Available protection and valuation options depend on the service and applicable terms. A moving specialist will confirm included protection, exclusions, and any optional coverage before booking.';
  },
  'insurance_interest' => static function (array &$element): void {
    if (isset($element['#options']['basic_liability'])) {
      $element['#options']['basic_liability'] = 'No, the standard included protection is sufficient.';
    }
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

print "STEP14 WEBFORM COPY UPDATED\n";
print "BACKUP: {$backup}\n";
if ($missing) {
  print 'FIELDS NOT FOUND: ' . implode(', ', $missing) . "\n";
}
