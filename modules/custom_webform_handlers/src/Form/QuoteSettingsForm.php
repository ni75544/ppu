<?php

namespace Drupal\custom_webform_handlers\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configures server route and preliminary quote rates.
 */
final class QuoteSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['custom_webform_handlers.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'custom_webform_handlers_quote_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('custom_webform_handlers.settings');

    $form['notice'] = [
      '#type' => 'markup',
      '#markup' => '<p><strong>' . $this->t('All prices are preliminary, non-binding estimate inputs. Review changes before production use. Amount fields are stored in cents.') . '</strong></p>',
    ];

    $form['general'] = [
      '#type' => 'details',
      '#title' => $this->t('General and route settings'),
      '#open' => TRUE,
    ];
    $form['general']['currency'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Currency'),
      '#default_value' => $config->get('quote.currency') ?: 'USD',
      '#maxlength' => 3,
      '#required' => TRUE,
      '#description' => $this->t('Three-letter ISO 4217 currency code, for example USD.'),
    ];
    $form['general']['rate_version'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Rate version'),
      '#default_value' => $config->get('quote.rate_version') ?: '2026-08-production-v1',
      '#maxlength' => 64,
      '#required' => TRUE,
      '#description' => $this->t('Change this value whenever production rates change.'),
    ];
    $form['general']['route_request_timeout'] = $this->numberField(
      $this->t('Route request timeout in seconds'),
      $config->get('route_request_timeout') ?: 8,
      2,
      20,
    );
    $form['general']['route_cache_ttl'] = $this->numberField(
      $this->t('Route cache lifetime in seconds'),
      $config->get('route_cache_ttl') ?: 3600,
      60,
      86400,
    );
    $form['general']['preview_rate_limit'] = $this->numberField(
      $this->t('Preview requests per IP address per hour'),
      $config->get('preview_rate_limit') ?: 30,
      5,
      120,
    );

    $form['long_distance'] = [
      '#type' => 'details',
      '#title' => $this->t('Long-distance rates'),
      '#open' => TRUE,
    ];
    $form['long_distance']['long_distance_base_cents'] = $this->numberField($this->t('Base charge in cents'), $config->get('quote.long_distance.base_cents') ?? 50000);
    $form['long_distance']['weight_distance_factor'] = $this->numberField($this->t('Dollar factor per pound-mile'), $config->get('quote.long_distance.weight_distance_factor') ?? 0.00065, 0, NULL, 0.00001);
    $form['long_distance']['dimensional_weight_divisor'] = $this->numberField($this->t('Dimensional-weight divisor (inches)'), $config->get('quote.long_distance.dimensional_weight_divisor') ?? 139, 1);
    foreach ([
      'studio' => $this->t('Studio fallback weight (lb)'),
      '1_br' => $this->t('One-bedroom fallback weight (lb)'),
      '2_br' => $this->t('Two-bedroom fallback weight (lb)'),
      '3_br' => $this->t('Three-bedroom fallback weight (lb)'),
      '4_br_plus' => $this->t('Four-plus-bedroom fallback weight (lb)'),
    ] as $key => $label) {
      $form['long_distance']['fallback_' . $key] = $this->numberField($label, $config->get('quote.long_distance.fallback_weight_lbs.' . $key) ?? 0);
    }

    $form['local'] = [
      '#type' => 'details',
      '#title' => $this->t('Local residential rates'),
    ];
    foreach ([
      'studio' => $this->t('Studio base charge in cents'),
      '1_br' => $this->t('One-bedroom base charge in cents'),
      '2_br' => $this->t('Two-bedroom base charge in cents'),
      '3_br' => $this->t('Three-bedroom base charge in cents'),
      '4_br_plus' => $this->t('Four-plus-bedroom base charge in cents'),
      'few_items' => $this->t('Few-items base charge in cents'),
    ] as $key => $label) {
      $form['local']['local_' . $key] = $this->numberField($label, $config->get('quote.local_residential.base_cents.' . $key) ?? 0);
    }
    $form['local']['travel_speed_mph'] = $this->numberField($this->t('Assumed travel speed (mph)'), $config->get('quote.local_residential.travel_speed_mph') ?? 30, 1);
    $form['local']['travel_hourly_cents'] = $this->numberField($this->t('Travel hourly charge in cents'), $config->get('quote.local_residential.travel_hourly_cents') ?? 11000);
    $form['local']['round_trip_multiplier'] = $this->numberField($this->t('Round-trip multiplier'), $config->get('quote.local_residential.round_trip_multiplier') ?? 2, 1, 4, 0.1);

    $form['fees'] = [
      '#type' => 'details',
      '#title' => $this->t('Access and add-on fees'),
    ];
    $fee_fields = [
      'stairs_per_flight_cents' => $this->t('Stair fee per flight in cents'),
      'passenger_elevator_cents' => $this->t('Passenger elevator fee in cents'),
      'freight_elevator_cents' => $this->t('Freight elevator fee in cents'),
      'long_carry_cents' => $this->t('Long-carry fee in cents'),
      'shuttle_cents' => $this->t('Shuttle fee in cents'),
      'packing_cents' => $this->t('Packing fee in cents'),
      'cleaning_cents' => $this->t('Cleaning fee in cents'),
    ];
    foreach ($fee_fields as $key => $label) {
      $form['fees'][$key] = $this->numberField($label, $config->get('quote.fees.' . $key) ?? 0);
    }

    $form['commercial'] = [
      '#type' => 'details',
      '#title' => $this->t('Commercial rates'),
    ];
    foreach ([
      'base_cents' => $this->t('Commercial base charge in cents'),
      'distance_threshold_miles' => $this->t('Included distance in miles'),
      'excess_distance_cents_per_mile' => $this->t('Excess-distance charge per mile in cents'),
      'packing_unpacking_cents' => $this->t('Packing/unpacking fee in cents'),
      'it_services_cents' => $this->t('IT services fee in cents'),
      'furniture_assembly_cents' => $this->t('Furniture assembly fee in cents'),
      'after_hours_percent' => $this->t('After-hours premium percentage'),
      'weekend_percent' => $this->t('Weekend premium percentage'),
    ] as $key => $label) {
      $form['commercial']['commercial_' . $key] = $this->numberField($label, $config->get('quote.commercial.' . $key) ?? 0);
    }

    $form['specialty'] = [
      '#type' => 'details',
      '#title' => $this->t('Specialty-item rates'),
    ];
    foreach ([
      'piano_cents' => $this->t('Piano charge in cents'),
      'gun_safe_cents' => $this->t('Gun-safe charge in cents'),
      'fine_art_cents' => $this->t('Fine-art charge in cents'),
      'gym_equipment_cents' => $this->t('Gym-equipment charge in cents'),
      'other_cents' => $this->t('Other specialty-item charge in cents'),
    ] as $key => $label) {
      $form['specialty']['specialty_' . $key] = $this->numberField($label, $config->get('quote.specialty.' . $key) ?? 0);
    }

    $form['on_demand'] = [
      '#type' => 'details',
      '#title' => $this->t('On-demand delivery rates'),
    ];
    foreach ([
      'van_1_helper_cents' => $this->t('Van with one helper in cents'),
      'truck_1_helper_cents' => $this->t('Truck with one helper in cents'),
      'truck_2_helpers_cents' => $this->t('Truck with two helpers in cents'),
      'rush_cents' => $this->t('Rush fee in cents'),
      'expedited_cents' => $this->t('Expedited fee in cents'),
      'included_miles' => $this->t('Included miles'),
      'excess_distance_cents_per_mile' => $this->t('Excess-distance charge per mile in cents'),
    ] as $key => $label) {
      $form['on_demand']['on_demand_' . $key] = $this->numberField($label, $config->get('quote.on_demand.' . $key) ?? 0);
    }

    $form['diy_truck'] = [
      '#type' => 'details',
      '#title' => $this->t('DIY truck-rental rates'),
    ];
    foreach ([
      '10_ft' => $this->t('10-foot truck daily charge in cents'),
      '15_ft' => $this->t('15-foot truck daily charge in cents'),
      '20_ft' => $this->t('20-foot truck daily charge in cents'),
      '26_ft' => $this->t('26-foot truck daily charge in cents'),
    ] as $key => $label) {
      $form['diy_truck']['diy_daily_' . $key] = $this->numberField($label, $config->get('quote.diy_truck.daily_base_cents.' . $key) ?? 0);
    }
    foreach ([
      'included_miles_per_day' => $this->t('Included miles per rental day'),
      'excess_mileage_cents_per_mile' => $this->t('Excess-mileage charge per mile in cents'),
      'insurance_cents_per_day' => $this->t('Damage-waiver charge per day in cents'),
      'dolly_cents_per_day' => $this->t('Dolly charge per day in cents'),
      'pads_cents_per_day' => $this->t('Furniture-pads charge per day in cents'),
      'labor_help_minimum_cents' => $this->t('Labor-help two-hour minimum in cents'),
    ] as $key => $label) {
      $form['diy_truck']['diy_' . $key] = $this->numberField($label, $config->get('quote.diy_truck.' . $key) ?? 0);
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);
    $currency = strtoupper(trim((string) $form_state->getValue('currency')));
    if (!preg_match('/^[A-Z]{3}$/', $currency)) {
      $form_state->setErrorByName('currency', $this->t('Currency must be a three-letter ISO code.'));
    }
    if (trim((string) $form_state->getValue('rate_version')) === '') {
      $form_state->setErrorByName('rate_version', $this->t('Rate version is required.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $fallbacks = [];
    $local_bases = [];
    foreach (['studio', '1_br', '2_br', '3_br', '4_br_plus'] as $key) {
      $fallbacks[$key] = (int) $form_state->getValue('fallback_' . $key);
      $local_bases[$key] = (int) $form_state->getValue('local_' . $key);
    }
    $local_bases['few_items'] = (int) $form_state->getValue('local_few_items');

    $fees = [];
    foreach (['stairs_per_flight_cents', 'passenger_elevator_cents', 'freight_elevator_cents', 'long_carry_cents', 'shuttle_cents', 'packing_cents', 'cleaning_cents'] as $key) {
      $fees[$key] = (int) $form_state->getValue($key);
    }
    $commercial = [];
    foreach (['base_cents', 'distance_threshold_miles', 'excess_distance_cents_per_mile', 'packing_unpacking_cents', 'it_services_cents', 'furniture_assembly_cents', 'after_hours_percent', 'weekend_percent'] as $key) {
      $commercial[$key] = (int) $form_state->getValue('commercial_' . $key);
    }
    $specialty = [];
    foreach (['piano_cents', 'gun_safe_cents', 'fine_art_cents', 'gym_equipment_cents', 'other_cents'] as $key) {
      $specialty[$key] = (int) $form_state->getValue('specialty_' . $key);
    }
    $on_demand = [];
    foreach (['van_1_helper_cents', 'truck_1_helper_cents', 'truck_2_helpers_cents', 'rush_cents', 'expedited_cents', 'included_miles', 'excess_distance_cents_per_mile'] as $key) {
      $on_demand[$key] = (int) $form_state->getValue('on_demand_' . $key);
    }
    $diy_daily_bases = [];
    foreach (['10_ft', '15_ft', '20_ft', '26_ft'] as $key) {
      $diy_daily_bases[$key] = (int) $form_state->getValue('diy_daily_' . $key);
    }
    $diy_truck = ['daily_base_cents' => $diy_daily_bases];
    foreach (['included_miles_per_day', 'excess_mileage_cents_per_mile', 'insurance_cents_per_day', 'dolly_cents_per_day', 'pads_cents_per_day', 'labor_help_minimum_cents'] as $key) {
      $diy_truck[$key] = (int) $form_state->getValue('diy_' . $key);
    }

    $this->config('custom_webform_handlers.settings')
      ->set('route_request_timeout', (int) $form_state->getValue('route_request_timeout'))
      ->set('route_cache_ttl', (int) $form_state->getValue('route_cache_ttl'))
      ->set('preview_rate_limit', (int) $form_state->getValue('preview_rate_limit'))
      ->set('quote', [
        'currency' => strtoupper(trim((string) $form_state->getValue('currency'))),
        'rate_version' => trim((string) $form_state->getValue('rate_version')),
        'long_distance' => [
          'base_cents' => (int) $form_state->getValue('long_distance_base_cents'),
          'weight_distance_factor' => (float) $form_state->getValue('weight_distance_factor'),
          'dimensional_weight_divisor' => (int) $form_state->getValue('dimensional_weight_divisor'),
          'fallback_weight_lbs' => $fallbacks,
        ],
        'local_residential' => [
          'base_cents' => $local_bases,
          'travel_speed_mph' => (int) $form_state->getValue('travel_speed_mph'),
          'travel_hourly_cents' => (int) $form_state->getValue('travel_hourly_cents'),
          'round_trip_multiplier' => (float) $form_state->getValue('round_trip_multiplier'),
        ],
        'fees' => $fees,
        'commercial' => $commercial,
        'specialty' => $specialty,
        'on_demand' => $on_demand,
        'diy_truck' => $diy_truck,
      ])
      ->save();

    parent::submitForm($form, $form_state);
  }

  private function numberField(string $title, int|float $default, int|float $min = 0, int|float|null $max = NULL, int|float $step = 1): array {
    $element = [
      '#type' => 'number',
      '#title' => $title,
      '#default_value' => $default,
      '#min' => $min,
      '#step' => $step,
      '#required' => TRUE,
    ];
    if ($max !== NULL) {
      $element['#max'] = $max;
    }
    return $element;
  }

}
