<?php

namespace Drupal\Tests\custom_webform_handlers\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\custom_webform_handlers\Dispatch\DispatchReason;
use Drupal\custom_webform_handlers\Dispatch\Profile\BookingMode;
use Drupal\custom_webform_handlers\Dispatch\Profile\BookingProfile;
use Drupal\custom_webform_handlers\Dispatch\Profile\BookingProfileResolverInterface;
use Drupal\custom_webform_handlers\Distance\DistanceResult;
use Drupal\custom_webform_handlers\Quote\QuoteCalculator;
use Drupal\Tests\UnitTestCase;

/**
 * Tests authoritative quote arithmetic.
 *
 * @group custom_webform_handlers
 */
final class QuoteCalculatorTest extends UnitTestCase {

  public function testLongDistanceWeightConversionAndRate(): void {
    $calculator = new QuoteCalculator($this->configFactory([
      'quote.currency' => 'USD',
      'quote.rate_version' => 'test-v1',
      'quote.long_distance.base_cents' => 50000,
      'quote.long_distance.weight_distance_factor' => 0.00065,
      'quote.long_distance.fallback_weight_lbs' => [],
    ]), $this->bookingProfiles());
    $distance = new DistanceResult(160934, 3600, 'test', 1234567890);

    $quote = $calculator->calculate([
      'service_type' => 'long_distance_move',
      'shipment_weight' => 1000,
    ], $distance);

    $expected_distance_charge = (int) round(1000 * 2.2046226218 * $distance->distanceMiles() * 0.00065 * 100);
    $this->assertSame(50000 + $expected_distance_charge, $quote->totalCents());
    $this->assertSame('estimated', $quote->status());
    $this->assertSame('test-v1', $quote->rateVersion());
  }

  public function testStairFeeUsesMultiplication(): void {
    $calculator = new QuoteCalculator($this->configFactory([
      'quote.currency' => 'USD',
      'quote.rate_version' => 'test-v1',
      'quote.local_residential.base_cents.studio' => 41500,
      'quote.local_residential.travel_speed_mph' => 30,
      'quote.local_residential.round_trip_multiplier' => 2,
      'quote.local_residential.travel_hourly_cents' => 11000,
      'quote.fees.stairs_per_flight_cents' => 1750,
    ]), $this->bookingProfiles());
    $distance = new DistanceResult(16093, 600, 'test', 1234567890);

    $quote = $calculator->calculate([
      'service_type' => 'local_residential_move',
      'move_size_residential' => 'studio',
      'origin_access_conditions' => 'stairs',
      'origin_stairs_flights' => 3,
    ], $distance);

    $travel_hours = ceil((($distance->distanceMiles() / 30) * 2) * 10) / 10;
    $expected = 41500 + (int) round($travel_hours * 11000) + (3 * 1750);
    $this->assertSame($expected, $quote->totalCents());
  }

  public function testDimensionalWeightCanExceedActualWeight(): void {
    $calculator = new QuoteCalculator($this->configFactory([
      'quote.currency' => 'USD',
      'quote.rate_version' => 'test-v1',
      'quote.long_distance.base_cents' => 50000,
      'quote.long_distance.weight_distance_factor' => 0.00065,
      'quote.long_distance.dimensional_weight_divisor' => 139,
      'quote.long_distance.fallback_weight_lbs' => [],
    ]), $this->bookingProfiles());
    $distance = new DistanceResult(160934, 3600, 'test', 1234567890);

    $quote = $calculator->calculate([
      'service_type' => 'long_distance_move',
      'shipment_weight' => 10,
      'shipment_weight_unit' => 'lb',
      'item_quantity' => 2,
      'item_length' => 40,
      'item_width' => 30,
      'item_height' => 20,
      'dimension_unit' => 'in',
    ], $distance);

    $data = $quote->toArray();
    $expected_weight = ((40 * 30 * 20) / 139) * 2;
    $this->assertSame('dimensional_weight', $data['weight_source']);
    $this->assertEqualsWithDelta($expected_weight, $data['weight_lbs'], 0.01);
  }

  public function testHomeSizeFallbackCannotBeUndercutByLowEnteredWeight(): void {
    $calculator = new QuoteCalculator($this->configFactory([
      'quote.currency' => 'USD',
      'quote.rate_version' => 'test-v1',
      'quote.long_distance.base_cents' => 50000,
      'quote.long_distance.weight_distance_factor' => 0.00065,
      'quote.long_distance.dimensional_weight_divisor' => 139,
      'quote.long_distance.fallback_weight_lbs' => ['1_br' => 4000],
    ]), $this->bookingProfiles());
    $distance = new DistanceResult(160934, 3600, 'test', 1234567890);

    $quote = $calculator->calculate([
      'service_type' => 'long_distance_move',
      'shipment_weight' => 97,
      'shipment_weight_unit' => 'lb',
      'shipment_size' => 'home_size',
      'shipment_home_size' => '1_br',
    ], $distance);

    $data = $quote->toArray();
    $this->assertSame('home_size_fallback', $data['weight_source']);
    $this->assertSame(4000.0, $data['weight_lbs']);
  }

  public function testCommercialPackingIsNotChargedTwice(): void {
    $calculator = new QuoteCalculator($this->configFactory([
      'quote.currency' => 'USD',
      'quote.rate_version' => 'test-v1',
      'quote.commercial.base_cents' => 300000,
      'quote.commercial.distance_threshold_miles' => 50,
      'quote.commercial.packing_unpacking_cents' => 32500,
      'quote.fees.packing_cents' => 32500,
    ]), $this->bookingProfiles());
    $distance = new DistanceResult(16093, 600, 'test', 1234567890);

    $quote = $calculator->calculate([
      'service_type' => 'local_commercial_move',
      'additional_services_commercial' => ['packing_unpacking'],
      'packing_services' => 1,
    ], $distance);

    $this->assertSame(332500, $quote->totalCents());
    $packing_lines = array_values(array_filter(
      $quote->toArray()['line_items'],
      static fn (array $item): bool => in_array($item['code'], ['commercial_packing', 'packing'], TRUE),
    ));
    $this->assertCount(1, $packing_lines);
  }

  public function testFewItemsAndFourPlusBedroomHaveDistinctBaseRates(): void {
    $calculator = new QuoteCalculator($this->configFactory([
      'quote.currency' => 'USD',
      'quote.rate_version' => 'test-v1',
      'quote.local_residential.base_cents.few_items' => 29500,
      'quote.local_residential.base_cents.4_br_plus' => 300000,
      'quote.local_residential.travel_speed_mph' => 30,
      'quote.local_residential.round_trip_multiplier' => 2,
      'quote.local_residential.travel_hourly_cents' => 11000,
    ]), $this->bookingProfiles());
    $distance = new DistanceResult(1, 0, 'test', 1234567890);

    $few_items = $calculator->calculate([
      'service_type' => 'local_residential_move',
      'move_size_residential' => 'few_items',
      'origin_access_conditions' => 'ground_floor',
      'destination_access_conditions' => 'ground_floor',
      'parking_situation' => 'close',
      'specialty_items_residential' => 'No',
    ], $distance);
    $large_home = $calculator->calculate([
      'service_type' => 'local_residential_move',
      'move_size_residential' => '4_br_plus',
      'origin_access_conditions' => 'ground_floor',
      'destination_access_conditions' => 'ground_floor',
      'parking_situation' => 'close',
      'specialty_items_residential' => 'No',
    ], $distance);

    $this->assertSame(30600, $few_items->totalCents());
    $this->assertSame('estimated', $few_items->status());
    $this->assertSame(301100, $large_home->totalCents());
  }

  public function testCommercialTimingPremiumAndReviewReasons(): void {
    $calculator = new QuoteCalculator($this->configFactory([
      'quote.currency' => 'USD',
      'quote.rate_version' => 'test-v1',
      'quote.commercial.base_cents' => 300000,
      'quote.commercial.distance_threshold_miles' => 50,
      'quote.commercial.after_hours_percent' => 20,
    ]), $this->bookingProfiles());
    $distance = new DistanceResult(16093, 600, 'test', 1234567890);

    $quote = $calculator->calculate([
      'service_type' => 'local_commercial_move',
      'timing_of_move' => 'after_hours',
      'scope_of_move' => 'Twenty workstations and two copiers.',
      'building_requirements' => ['coi', 'elevator_rules'],
    ], $distance);
    $data = $quote->toArray();

    $this->assertSame(360000, $quote->totalCents());
    $this->assertSame('manual_review', $quote->status());
    $this->assertContains('commercial_scope_requires_review', $data['manual_review_reasons']);
    $this->assertContains('commercial_building_requirements_require_review', $data['manual_review_reasons']);
  }

  public function testDiyTruckRentalUsesDaysMileageAndAddons(): void {
    $calculator = new QuoteCalculator($this->configFactory([
      'quote.currency' => 'USD',
      'quote.rate_version' => 'test-v1',
      'quote.diy_truck.daily_base_cents.15_ft' => 12900,
      'quote.diy_truck.included_miles_per_day' => 50,
      'quote.diy_truck.excess_mileage_cents_per_mile' => 129,
      'quote.diy_truck.insurance_cents_per_day' => 3500,
      'quote.diy_truck.dolly_cents_per_day' => 2000,
    ]), $this->bookingProfiles());
    $distance = new DistanceResult(160934, 3600, 'test', 1234567890);

    $quote = $calculator->calculate([
      'service_type' => 'diy_truck_rental',
      'truck_size' => '15_ft',
      'rental_duration' => 2,
      'estimated_mileage' => 150,
      'diy_addons' => ['insurance', 'dolly'],
    ], $distance);

    $expected = (12900 * 2) + ((150 - 100) * 129) + (3500 * 2) + (2000 * 2);
    $this->assertSame($expected, $quote->totalCents());
    $this->assertSame('manual_review', $quote->status());
    $this->assertContains('diy_fuel_taxes_require_review', $quote->toArray()['manual_review_reasons']);
  }

  public function testUnpricedConditionsRequireManualReview(): void {
    $calculator = new QuoteCalculator($this->configFactory([
      'quote.currency' => 'USD',
      'quote.rate_version' => 'test-v1',
      'quote.local_residential.base_cents.studio' => 41500,
      'quote.local_residential.travel_speed_mph' => 30,
      'quote.local_residential.round_trip_multiplier' => 2,
      'quote.local_residential.travel_hourly_cents' => 11000,
    ]), $this->bookingProfiles());
    $distance = new DistanceResult(1, 0, 'test', 1234567890);

    $quote = $calculator->calculate([
      'service_type' => 'local_residential_move',
      'move_size_residential' => 'studio',
      'specialty_items_residential' => 'Yes',
      'disposal_donation_interest' => 1,
    ], $distance);
    $reasons = $quote->toArray()['manual_review_reasons'];

    $this->assertSame('manual_review', $quote->status());
    $this->assertContains('residential_specialty_items_require_review', $reasons);
    $this->assertContains('disposal_or_donation_requires_review', $reasons);
  }

  public function testIncompleteResidentialInputsRequireManualReview(): void {
    $calculator = new QuoteCalculator($this->configFactory([
      'quote.currency' => 'USD',
      'quote.rate_version' => 'test-v1',
      'quote.local_residential.base_cents.1_br' => 56900,
      'quote.local_residential.travel_speed_mph' => 30,
      'quote.local_residential.round_trip_multiplier' => 2,
      'quote.local_residential.travel_hourly_cents' => 11000,
    ]), $this->bookingProfiles());
    $distance = new DistanceResult(1609, 300, 'test', 1234567890);

    $quote = $calculator->calculate([
      'service_type' => 'local_residential_move',
      'move_size_residential' => '1_br',
      'origin_access_conditions' => 'stairs',
    ], $distance);
    $reasons = $quote->toArray()['manual_review_reasons'];

    $this->assertSame('manual_review', $quote->status());
    $this->assertContains('missing_origin_stairs_flights', $reasons);
    $this->assertContains('missing_destination_access_conditions', $reasons);
    $this->assertContains('missing_parking_situation', $reasons);
    $this->assertContains('missing_residential_specialty_items_declaration', $reasons);
  }

  public function testMissingCommercialTimingRequiresManualReview(): void {
    $calculator = new QuoteCalculator($this->configFactory([
      'quote.currency' => 'USD',
      'quote.rate_version' => 'test-v1',
      'quote.commercial.base_cents' => 300000,
      'quote.commercial.distance_threshold_miles' => 50,
    ]), $this->bookingProfiles());
    $distance = new DistanceResult(1609, 300, 'test', 1234567890);

    $quote = $calculator->calculate([
      'service_type' => 'local_commercial_move',
      'scope_of_move' => 'Ten workstations.',
    ], $distance);

    $this->assertContains('missing_or_unsupported_commercial_timing', $quote->toArray()['manual_review_reasons']);
  }

  private function configFactory(array $values): ConfigFactoryInterface {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(
      static fn (string $key) => $values[$key] ?? NULL,
    );

    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')
      ->with('custom_webform_handlers.settings')
      ->willReturn($config);
    return $factory;
  }

  /**
   * A resolver that prices every service under its own name.
   *
   * QuoteCalculator now asks the service's BookingProfile which price book to
   * apply instead of switching on the raw service_type. This stub answers with the
   * resolver's documented default — the strategy *is* the service type — so every
   * assertion below still exercises the same case label and the same arithmetic.
   * Only quoteStrategy is meaningful here; the rest of the contract is filled with
   * the fail-closed values, because the quote path reads nothing else.
   */
  private function bookingProfiles(): BookingProfileResolverInterface {
    return new class() implements BookingProfileResolverInterface {

      public function resolve(string $serviceType): BookingProfile {
        $service = trim($serviceType);

        return new BookingProfile(
          $service,
          BookingMode::ManualReview,
          '',
          'dispatch_slot',
          [],
          ['policy' => '', 'single_driver_service' => FALSE],
          BookingProfile::DURATION_NONE,
          ['resource_id' => '', 'capacity' => 0, 'claims_capacity' => FALSE],
          $service,
          [
            'policy' => BookingProfile::REVIEW_OFFER_ASSESSED,
            'message' => '',
            'reason' => DispatchReason::ManualReview,
          ],
        );
      }

    };
  }

}
