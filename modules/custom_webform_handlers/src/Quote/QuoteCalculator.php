<?php

namespace Drupal\custom_webform_handlers\Quote;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\custom_webform_handlers\Distance\DistanceResult;

/**
 * Calculates preliminary quotes from normalized server-side data.
 */
final class QuoteCalculator {

  private array $lineItems = [];
  private array $manualReviewReasons = [];

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  public function calculate(array $data, DistanceResult $distance): QuoteResult {
    $this->lineItems = [];
    $this->manualReviewReasons = [];

    $config = $this->configFactory->get('custom_webform_handlers.settings');
    $service = (string) ($data['service_type'] ?? '');
    $total_cents = 0;
    $weight_lbs = NULL;
    $weight_source = 'not_applicable';
    $commercial_packing_included = FALSE;

    switch ($service) {
      case 'long_distance_move':
        [$weight_lbs, $weight_source] = $this->resolveWeight(
          $data,
          (array) $config->get('quote.long_distance.fallback_weight_lbs'),
          $this->positiveFloat($config->get('quote.long_distance.dimensional_weight_divisor'), 139),
        );
        $base_cents = $this->positiveInt($config->get('quote.long_distance.base_cents'), 50000);
        $this->addLine('long_distance_base', 'Long-distance base charge', $base_cents);
        $total_cents += $base_cents;
        if ($weight_lbs > 0) {
          $factor = $this->positiveFloat($config->get('quote.long_distance.weight_distance_factor'), 0.00065);
          $distance_charge = max(0, (int) round($weight_lbs * $distance->distanceMiles() * $factor * 100));
          $this->addLine('weight_distance', 'Weight and distance charge', $distance_charge, [
            'weight_lbs' => round($weight_lbs, 2),
            'distance_miles' => round($distance->distanceMiles(), 2),
            'rate_factor' => $factor,
          ]);
          $total_cents += $distance_charge;
        }
        else {
          $this->manualReviewReasons[] = 'missing_shipment_weight';
        }
        $total_cents += $this->calculateAccessFees($data, $config);
        if (trim((string) ($data['specialty_items_long_distance'] ?? '')) !== '') {
          $this->manualReviewReasons[] = 'long_distance_specialty_items_require_review';
        }
        break;

      case 'local_residential_move':
        $size = (string) ($data['move_size_residential'] ?? '');
        $base_cents = $this->positiveInt($config->get("quote.local_residential.base_cents.$size"), 0);
        if ($base_cents > 0) {
          $this->addLine('local_residential_base', 'Local residential base charge', $base_cents, ['move_size' => $size]);
          $total_cents += $base_cents;
        }
        else {
          $this->manualReviewReasons[] = 'missing_or_unsupported_move_size';
        }
        $speed = $this->positiveFloat($config->get('quote.local_residential.travel_speed_mph'), 30);
        $multiplier = $this->positiveFloat($config->get('quote.local_residential.round_trip_multiplier'), 2);
        $hourly_cents = $this->positiveInt($config->get('quote.local_residential.travel_hourly_cents'), 11000);
        $travel_hours = ceil((($distance->distanceMiles() / $speed) * $multiplier) * 10) / 10;
        $travel_cents = (int) round($travel_hours * $hourly_cents);
        $this->addLine('travel', 'Travel charge', $travel_cents, ['hours' => $travel_hours]);
        $total_cents += $travel_cents + $this->calculateAccessFees($data, $config);
        foreach (['origin', 'destination'] as $location) {
          $condition = (string) ($data[$location . '_access_conditions'] ?? '');
          if ($condition === '') {
            $this->manualReviewReasons[] = 'missing_' . $location . '_access_conditions';
          }
          elseif (!in_array($condition, ['ground_floor', 'stairs', 'passenger_elevator', 'freight_elevator'], TRUE)) {
            $this->manualReviewReasons[] = 'unsupported_' . $location . '_access_conditions';
          }
          elseif ($condition === 'stairs' && (int) ($data[$location . '_stairs_flights'] ?? 0) < 1) {
            $this->manualReviewReasons[] = 'missing_' . $location . '_stairs_flights';
          }
        }
        $parking = (string) ($data['parking_situation'] ?? '');
        if ($parking === '') {
          $this->manualReviewReasons[] = 'missing_parking_situation';
        }
        elseif (!in_array($parking, ['close', 'long_carry', 'shuttle'], TRUE)) {
          $this->manualReviewReasons[] = 'unsupported_parking_situation';
        }
        $specialty_declaration = (string) ($data['specialty_items_residential'] ?? '');
        if ($specialty_declaration === 'Yes') {
          $this->manualReviewReasons[] = 'residential_specialty_items_require_review';
        }
        elseif ($specialty_declaration !== 'No') {
          $this->manualReviewReasons[] = 'missing_residential_specialty_items_declaration';
        }
        break;

      case 'local_commercial_move':
        $base_cents = $this->positiveInt($config->get('quote.commercial.base_cents'), 300000);
        $this->addLine('commercial_base', 'Commercial move base charge', $base_cents);
        $total_cents += $base_cents;
        $services = $this->selectedValues($data['additional_services_commercial'] ?? []);
        $commercial_fees = [
          'packing_unpacking' => ['commercial_packing', 'Commercial packing/unpacking', 'quote.commercial.packing_unpacking_cents', 32500],
          'it_services' => ['commercial_it', 'IT services', 'quote.commercial.it_services_cents', 20000],
          'furniture_assembly' => ['commercial_furniture', 'Furniture assembly', 'quote.commercial.furniture_assembly_cents', 15000],
        ];
        foreach ($commercial_fees as $selection => [$code, $label, $path, $fallback]) {
          if (in_array($selection, $services, TRUE)) {
            $amount = $this->positiveInt($config->get($path), $fallback);
            $this->addLine($code, $label, $amount);
            $total_cents += $amount;
            if ($selection === 'packing_unpacking') {
              $commercial_packing_included = TRUE;
            }
          }
        }
        $threshold = $this->positiveFloat($config->get('quote.commercial.distance_threshold_miles'), 50);
        if ($distance->distanceMiles() > $threshold) {
          $rate = $this->positiveInt($config->get('quote.commercial.excess_distance_cents_per_mile'), 200);
          $amount = (int) round(($distance->distanceMiles() - $threshold) * $rate);
          $this->addLine('commercial_distance', 'Commercial excess-distance charge', $amount);
          $total_cents += $amount;
        }
        $timing = (string) ($data['timing_of_move'] ?? '');
        $timing_rates = [
          'after_hours' => ['Commercial after-hours premium', 'quote.commercial.after_hours_percent', 20],
          'weekend' => ['Commercial weekend premium', 'quote.commercial.weekend_percent', 15],
        ];
        if (isset($timing_rates[$timing])) {
          [$label, $path, $fallback] = $timing_rates[$timing];
          $percent = $this->positiveInt($config->get($path), $fallback);
          $amount = (int) round($base_cents * ($percent / 100));
          $this->addLine('commercial_timing', $label, $amount, ['percent' => $percent]);
          $total_cents += $amount;
        }
        elseif ($timing !== 'business_hours') {
          $this->manualReviewReasons[] = 'missing_or_unsupported_commercial_timing';
        }
        if (trim((string) ($data['scope_of_move'] ?? '')) !== '') {
          $this->manualReviewReasons[] = 'commercial_scope_requires_review';
        }
        else {
          $this->manualReviewReasons[] = 'missing_commercial_scope';
        }
        if ($this->selectedValues($data['building_requirements'] ?? [])) {
          $this->manualReviewReasons[] = 'commercial_building_requirements_require_review';
        }
        break;

      case 'specialty_item_move':
        $item = (string) ($data['item_to_be_moved'] ?? '');
        $items = [
          'piano' => ['Specialty item: piano', 'quote.specialty.piano_cents', 42500],
          'gun_safe' => ['Specialty item: gun safe', 'quote.specialty.gun_safe_cents', 27500],
          'fine_art' => ['Specialty item: fine art', 'quote.specialty.fine_art_cents', 35000],
          'gym_equipment' => ['Specialty item: gym equipment', 'quote.specialty.gym_equipment_cents', 22500],
          'other' => ['Specialty item: other', 'quote.specialty.other_cents', 10000],
        ];
        if (isset($items[$item])) {
          [$label, $path, $fallback] = $items[$item];
          $amount = $this->positiveInt($config->get($path), $fallback);
          $this->addLine('specialty_item', $label, $amount);
          $total_cents += $amount;
        }
        else {
          $this->manualReviewReasons[] = 'missing_specialty_item_type';
        }
        $this->manualReviewReasons[] = 'specialty_item_requires_review';
        break;

      case 'on_demand_delivery':
        $vehicle = (string) ($data['vehicle_helper_needs'] ?? '');
        $vehicles = [
          'van_1_helper' => ['Van with one helper', 'quote.on_demand.van_1_helper_cents', 20000],
          'truck_1_helper' => ['Truck with one helper', 'quote.on_demand.truck_1_helper_cents', 30000],
          'truck_2_helpers' => ['Truck with two helpers', 'quote.on_demand.truck_2_helpers_cents', 47500],
        ];
        if (isset($vehicles[$vehicle])) {
          [$label, $path, $fallback] = $vehicles[$vehicle];
          $amount = $this->positiveInt($config->get($path), $fallback);
          $this->addLine('on_demand_vehicle', $label, $amount);
          $total_cents += $amount;
        }
        else {
          $this->manualReviewReasons[] = 'missing_vehicle_selection';
        }
        $speed = (string) ($data['service_speed'] ?? 'standard');
        if (in_array($speed, ['rush', 'expedited'], TRUE)) {
          $path = $speed === 'rush' ? 'quote.on_demand.rush_cents' : 'quote.on_demand.expedited_cents';
          $fallback = $speed === 'rush' ? 7500 : 15000;
          $amount = $this->positiveInt($config->get($path), $fallback);
          $this->addLine('on_demand_speed', ucfirst($speed) . ' service', $amount);
          $total_cents += $amount;
        }
        $included = $this->positiveFloat($config->get('quote.on_demand.included_miles'), 10);
        if ($distance->distanceMiles() > $included) {
          $rate = $this->positiveInt($config->get('quote.on_demand.excess_distance_cents_per_mile'), 100);
          $amount = (int) round(($distance->distanceMiles() - $included) * $rate);
          $this->addLine('on_demand_distance', 'On-demand excess-distance charge', $amount);
          $total_cents += $amount;
        }
        break;

      case 'diy_truck_rental':
        $this->manualReviewReasons[] = 'diy_fuel_taxes_require_review';
        $truck_size = (string) ($data['truck_size'] ?? '');
        $duration = max(0, (int) ($data['rental_duration'] ?? 0));
        $daily_rates = [
          '10_ft' => ['10-foot truck rental', 'quote.diy_truck.daily_base_cents.10_ft', 9900],
          '15_ft' => ['15-foot truck rental', 'quote.diy_truck.daily_base_cents.15_ft', 12900],
          '20_ft' => ['20-foot truck rental', 'quote.diy_truck.daily_base_cents.20_ft', 15900],
          '26_ft' => ['26-foot truck rental', 'quote.diy_truck.daily_base_cents.26_ft', 19900],
        ];
        if (!isset($daily_rates[$truck_size])) {
          $this->manualReviewReasons[] = 'missing_truck_size';
        }
        if ($duration < 1) {
          $this->manualReviewReasons[] = 'missing_rental_duration';
        }
        if (isset($daily_rates[$truck_size]) && $duration > 0) {
          [$label, $path, $fallback] = $daily_rates[$truck_size];
          $daily_rate = $this->positiveInt($config->get($path), $fallback);
          $amount = $daily_rate * $duration;
          $this->addLine('diy_truck_base', $label, $amount, ['days' => $duration]);
          $total_cents += $amount;

          $entered_mileage = $this->numeric($data['estimated_mileage'] ?? NULL);
          $billable_mileage = max($distance->distanceMiles(), $entered_mileage ?? 0.0);
          $included_per_day = $this->positiveInt($config->get('quote.diy_truck.included_miles_per_day'), 50);
          $included_miles = $included_per_day * $duration;
          if ($billable_mileage > $included_miles) {
            $rate = $this->positiveInt($config->get('quote.diy_truck.excess_mileage_cents_per_mile'), 129);
            $amount = (int) round(($billable_mileage - $included_miles) * $rate);
            $this->addLine('diy_excess_mileage', 'DIY truck excess mileage', $amount, [
              'estimated_miles' => round($billable_mileage, 2),
              'included_miles' => $included_miles,
              'rate_cents_per_mile' => $rate,
            ]);
            $total_cents += $amount;
          }

          $addons = $this->selectedValues($data['diy_addons'] ?? []);
          $daily_addons = [
            'insurance' => ['diy_insurance', 'Damage waiver', 'quote.diy_truck.insurance_cents_per_day', 3500],
            'dolly' => ['diy_dolly', 'Appliance/utility dolly', 'quote.diy_truck.dolly_cents_per_day', 2000],
            'pads' => ['diy_pads', 'Furniture pads', 'quote.diy_truck.pads_cents_per_day', 2500],
          ];
          foreach ($daily_addons as $selection => [$code, $label, $path, $fallback]) {
            if (in_array($selection, $addons, TRUE)) {
              $amount = $this->positiveInt($config->get($path), $fallback) * $duration;
              $this->addLine($code, $label, $amount, ['days' => $duration]);
              $total_cents += $amount;
            }
          }
          if (in_array('labor_help', $addons, TRUE)) {
            $amount = $this->positiveInt($config->get('quote.diy_truck.labor_help_minimum_cents'), 19000);
            $this->addLine('diy_labor_help', 'Labor help (two-hour minimum)', $amount);
            $total_cents += $amount;
            $this->manualReviewReasons[] = 'diy_labor_hours_require_review';
          }
        }
        break;

      default:
        $this->manualReviewReasons[] = 'missing_or_unsupported_service_type';
    }

    if (!empty($data['packing_services']) && !$commercial_packing_included) {
      $amount = $this->positiveInt($config->get('quote.fees.packing_cents'), 32500);
      $this->addLine('packing', 'Professional packing', $amount);
      $total_cents += $amount;
    }
    if (!empty($data['cleaning_services'])) {
      $amount = $this->positiveInt($config->get('quote.fees.cleaning_cents'), 21000);
      $this->addLine('cleaning', 'Move-in/move-out cleaning', $amount);
      $total_cents += $amount;
    }
    if (!empty($data['disposal_donation_interest'])) {
      $this->manualReviewReasons[] = 'disposal_or_donation_requires_review';
    }

    $status = $this->manualReviewReasons ? 'manual_review' : 'estimated';
    return new QuoteResult(
      strtoupper((string) ($config->get('quote.currency') ?: 'USD')),
      max(0, $total_cents),
      $this->lineItems,
      $status,
      array_values(array_unique($this->manualReviewReasons)),
      (string) ($config->get('quote.rate_version') ?: 'unversioned'),
      $distance,
      $weight_lbs,
      $weight_source,
    );
  }

  private function calculateAccessFees(array $data, $config): int {
    $total = 0;
    foreach (['origin', 'destination'] as $location) {
      $condition = (string) ($data[$location . '_access_conditions'] ?? '');
      if ($condition === 'stairs') {
        $flights = max(0, (int) ($data[$location . '_stairs_flights'] ?? 0));
        $rate = $this->positiveInt($config->get('quote.fees.stairs_per_flight_cents'), 1750);
        $amount = $flights * $rate;
        if ($amount > 0) {
          $this->addLine($location . '_stairs', ucfirst($location) . ' stairs', $amount, ['flights' => $flights]);
          $total += $amount;
        }
      }
      elseif ($condition === 'passenger_elevator') {
        $amount = $this->positiveInt($config->get('quote.fees.passenger_elevator_cents'), 3500);
        $this->addLine($location . '_passenger_elevator', ucfirst($location) . ' passenger elevator', $amount);
        $total += $amount;
      }
      elseif ($condition === 'freight_elevator') {
        $amount = $this->positiveInt($config->get('quote.fees.freight_elevator_cents'), 2000);
        $this->addLine($location . '_freight_elevator', ucfirst($location) . ' freight elevator', $amount);
        $total += $amount;
      }
    }

    $parking = (string) ($data['parking_situation'] ?? '');
    if ($parking === 'long_carry') {
      $amount = $this->positiveInt($config->get('quote.fees.long_carry_cents'), 7500);
      $this->addLine('long_carry', 'Long carry', $amount);
      $total += $amount;
    }
    elseif ($parking === 'shuttle') {
      $amount = $this->positiveInt($config->get('quote.fees.shuttle_cents'), 25000);
      $this->addLine('shuttle', 'Shuttle service', $amount);
      $total += $amount;
    }
    return $total;
  }

  private function resolveWeight(array $data, array $fallbacks, float $dimensional_divisor): array {
    $entered_weight = $this->numeric($data['shipment_weight'] ?? NULL);
    $weight_unit = (string) ($data['shipment_weight_unit'] ?? 'kg');
    $actual_lbs = 0.0;
    if ($entered_weight !== NULL && $entered_weight > 0) {
      $actual_lbs = $weight_unit === 'lb' ? $entered_weight : $entered_weight * 2.2046226218;
    }

    $length = $this->numeric($data['item_length'] ?? NULL);
    $width = $this->numeric($data['item_width'] ?? NULL);
    $height = $this->numeric($data['item_height'] ?? NULL);
    $quantity = max(1, (int) ($data['item_quantity'] ?? 1));
    $dimension_unit = (string) ($data['dimension_unit'] ?? 'in');
    $dimensional_lbs = 0.0;
    if ($length > 0 && $width > 0 && $height > 0) {
      if ($dimension_unit === 'cm') {
        $length /= 2.54;
        $width /= 2.54;
        $height /= 2.54;
      }
      $dimensional_lbs = (($length * $width * $height) / $dimensional_divisor) * $quantity;
    }

    $size = (string) ($data['shipment_home_size'] ?? '');
    $use_home_size = (string) ($data['shipment_size'] ?? '') === 'home_size';
    $fallback = $use_home_size ? $this->numeric($fallbacks[$size] ?? NULL) : NULL;
    $fallback_lbs = $fallback !== NULL && $fallback > 0 ? $fallback : 0.0;

    $candidates = [
      'customer_entered' => $actual_lbs,
      'dimensional_weight' => $dimensional_lbs,
      'home_size_fallback' => $fallback_lbs,
    ];
    $weight = max($candidates);
    if ($weight <= 0) {
      return [0.0, 'missing'];
    }
    if ($weight === $fallback_lbs && $fallback_lbs > 0) {
      return [$weight, 'home_size_fallback'];
    }
    if ($weight === $dimensional_lbs && $dimensional_lbs > 0) {
      return [$weight, 'dimensional_weight'];
    }
    return [$weight, $weight_unit === 'lb' ? 'customer_entered_lb' : 'customer_entered_kg'];
  }

  private function selectedValues(mixed $value): array {
    if (!is_array($value)) {
      return [];
    }
    return array_values(array_filter($value, static fn ($item) => is_string($item) && $item !== '' && $item !== '0'));
  }

  private function addLine(string $code, string $label, int $amount_cents, array $metadata = []): void {
    if ($amount_cents <= 0) {
      return;
    }
    $this->lineItems[] = [
      'code' => $code,
      'label' => $label,
      'amount_cents' => $amount_cents,
      'amount' => number_format($amount_cents / 100, 2, '.', ''),
      'metadata' => $metadata,
    ];
  }

  private function numeric(mixed $value): ?float {
    return is_numeric($value) ? (float) $value : NULL;
  }

  private function positiveInt(mixed $value, int $fallback): int {
    return is_numeric($value) && (int) $value >= 0 ? (int) $value : $fallback;
  }

  private function positiveFloat(mixed $value, float $fallback): float {
    return is_numeric($value) && (float) $value > 0 ? (float) $value : $fallback;
  }

}
