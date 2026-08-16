<?php

namespace Drupal\custom_webform_handlers\Plugin\WebformHandler;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\custom_webform_handlers\Distance\DistanceProviderException;
use Drupal\custom_webform_handlers\Distance\DistanceProviderInterface;
use Drupal\custom_webform_handlers\Quote\QuoteCalculator;
use Drupal\webform\Plugin\WebformHandlerBase;
use Drupal\webform\WebformSubmissionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Webform handler to calculate distance between two addresses.
 *
 * @WebformHandler(
 *   id = "address_distance_handler",
 *   label = @Translation("Address and Distance Calculator"),
 *   category = @Translation("Custom"),
 *   description = @Translation("Calculates distance between two addresses using Google Maps API."),
 *   cardinality = \Drupal\webform\Plugin\WebformHandlerInterface::CARDINALITY_SINGLE,
 *   results = \Drupal\webform\Plugin\WebformHandlerInterface::RESULTS_IGNORED,
 *   submission = \Drupal\webform\Plugin\WebformHandlerInterface::SUBMISSION_REQUIRED,
 * )
 */
class AddressDistanceHandler extends WebformHandlerBase {

  protected DistanceProviderInterface $distanceProvider;

  protected QuoteCalculator $quoteCalculator;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->distanceProvider = $container->get('custom_webform_handlers.distance_provider');
    $instance->quoteCalculator = $container->get('custom_webform_handlers.quote_calculator');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'origin_address_field' => '',
      'destination_address_field' => '',
      'distance_field' => '',
      'distance_meters_field' => '',
      'quote_total_field' => '',
      'quote_currency_field' => '',
      'quote_breakdown_field' => '',
      'quote_status_field' => '',
      'quote_rate_version_field' => '',
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $elements = $this->getWebform()->getElementsInitializedAndFlattened();
    $address_options = [];
    $storage_options = ['' => $this->t('- None -')];
    $breakdown_options = [];
    foreach ($elements as $key => $element) {
      $type = $element['#type'] ?? NULL;
      $title = $element['#title'] ?? $key;
      $label = $title . ' (' . $key . ')';

      if ($type === 'textfield') {
        $address_options[$key] = $label;
      }
      if (in_array($type, ['hidden', 'number', 'textfield'], TRUE)) {
        $storage_options[$key] = $label;
      }
      if (in_array($type, ['textarea', 'hidden', 'textfield'], TRUE)) {
        $breakdown_options[$key] = $label;
      }
    }

    $form['origin_address_field'] = [
      '#type' => 'select',
      '#title' => $this->t('Origin address field'),
      '#options' => $address_options,
      '#default_value' => $this->configuration['origin_address_field'],
      '#description' => $this->t('Select the field containing the origin address.'),
      '#required' => TRUE,
    ];

    $form['destination_address_field'] = [
      '#type' => 'select',
      '#title' => $this->t('Destination address field'),
      '#options' => $address_options,
      '#default_value' => $this->configuration['destination_address_field'],
      '#description' => $this->t('Select the field containing the destination address.'),
      '#required' => TRUE,
    ];

    $form['distance_field'] = [
      '#type' => 'select',
      '#title' => $this->t('Distance field'),
      '#options' => $storage_options,
      '#default_value' => $this->configuration['distance_field'],
      '#description' => $this->t('Select the field to store the calculated distance.'),
      '#required' => TRUE,
    ];

    $optional_fields = [
      'distance_meters_field' => $this->t('Canonical distance field (meters)'),
      'quote_total_field' => $this->t('Quote total field'),
      'quote_currency_field' => $this->t('Quote currency field'),
      'quote_status_field' => $this->t('Quote status field'),
      'quote_rate_version_field' => $this->t('Quote rate-version field'),
    ];
    foreach ($optional_fields as $key => $title) {
      $form[$key] = [
        '#type' => 'select',
        '#title' => $title,
        '#options' => $storage_options,
        '#default_value' => $this->configuration[$key],
        '#required' => FALSE,
      ];
    }

    $form['quote_breakdown_field'] = [
      '#type' => 'select',
      '#title' => $this->t('Quote breakdown field'),
      '#options' => ['' => $this->t('- None -')] + $breakdown_options,
      '#default_value' => $this->configuration['quote_breakdown_field'],
      '#required' => FALSE,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);
    $this->configuration['origin_address_field'] = $form_state->getValue('origin_address_field');
    $this->configuration['destination_address_field'] = $form_state->getValue('destination_address_field');
    $this->configuration['distance_field'] = $form_state->getValue('distance_field');
    foreach ([
      'distance_meters_field',
      'quote_total_field',
      'quote_currency_field',
      'quote_breakdown_field',
      'quote_status_field',
      'quote_rate_version_field',
    ] as $key) {
      $this->configuration[$key] = $form_state->getValue($key) ?: '';
    }
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::validateConfigurationForm($form, $form_state);

    $origin = $form_state->getValue('origin_address_field');
    $destination = $form_state->getValue('destination_address_field');
    $distance = $form_state->getValue('distance_field');

    if ($origin && $origin === $destination) {
      $form_state->setErrorByName('destination_address_field', $this->t('Origin and destination must use different fields.'));
    }
    if ($distance && in_array($distance, [$origin, $destination], TRUE)) {
      $form_state->setErrorByName('distance_field', $this->t('The distance field must be different from both address fields.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function alterForm(array &$form, FormStateInterface $form_state, ?WebformSubmissionInterface $webform_submission = NULL) {
    $api_key = \Drupal::config('custom_webform_handlers.settings')->get('google_maps_browser_api_key');
    if (empty($api_key)) {
      $form['#attached']['library'][] = 'custom_webform_handlers/quote_summary_sidebar';
      return;
    }

    $quote_defaults = [];
    $submission_data = $webform_submission ? $webform_submission->getData() : [];
    foreach ([
      'service_type',
      'shipment_weight',
      'shipment_weight_unit',
      'shipment_size',
      'shipment_home_size',
      'item_quantity',
      'item_length',
      'item_width',
      'item_height',
      'dimension_unit',
      'move_size_residential',
      'origin_access_conditions',
      'origin_stairs_flights',
      'destination_access_conditions',
      'destination_stairs_flights',
      'parking_situation',
      'specialty_items_residential',
      'additional_services_commercial',
      'building_requirements',
      'timing_of_move',
      'scope_of_move',
      'specialty_items_long_distance',
      'item_to_be_moved',
      'truck_size',
      'rental_duration',
      'estimated_mileage',
      'diy_addons',
      'vehicle_helper_needs',
      'service_speed',
      'packing_services',
      'cleaning_services',
      'disposal_donation_interest',
    ] as $key) {
      if (array_key_exists($key, $submission_data)) {
        $quote_defaults[$key] = $submission_data[$key];
      }
    }

    $form['#attached']['library'][] = 'custom_webform_handlers/distance_calculator';
    $form['#attached']['library'][] = 'custom_webform_handlers/quote_summary_sidebar';
    $form['#attached']['library'][] = 'custom_webform_handlers/route_map_preview';
    $quote_context_key = 'custom_webform_handlers:' . $this->getWebform()->id();
    if ($webform_submission && $webform_submission->uuid()) {
      $quote_context_key .= ':' . $webform_submission->uuid();
    }
    $form['#attached']['drupalSettings']['custom_webform_handlers'] = [
      'google_maps_browser_api_key' => $api_key,
      'preview_url' => Url::fromRoute('custom_webform_handlers.distance_preview')->toString(),
      'csrf_token_url' => Url::fromRoute('system.csrftoken')->toString(),
      'quote_defaults' => $quote_defaults,
      'quote_context_key' => $quote_context_key,
      'field_names' => [
        'origin' => $this->configuration['origin_address_field'],
        'destination' => $this->configuration['destination_address_field'],
        'distance' => $this->configuration['distance_field'],
        'distance_meters' => $this->configuration['distance_meters_field'],
        'quote_total' => $this->configuration['quote_total_field'] ?: 'final_calculated_quote',
        'quote_currency' => $this->configuration['quote_currency_field'],
        'quote_breakdown' => $this->configuration['quote_breakdown_field'],
        'quote_status' => $this->configuration['quote_status_field'],
        'quote_rate_version' => $this->configuration['quote_rate_version_field'],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(WebformSubmissionInterface $submission) {
    $origin = $submission->getElementData($this->configuration['origin_address_field']);
    $destination = $submission->getElementData($this->configuration['destination_address_field']);
    $this->clearComputedValues($submission);

    if (trim((string) $origin) === '' || trim((string) $destination) === '') {
      $this->markManualReview($submission, 'missing_route_addresses');
      return;
    }

    try {
      $distance = $this->distanceProvider->calculate((string) $origin, (string) $destination);
      $this->setSubmissionValue($submission, $this->configuration['distance_field'], number_format($distance->distanceMiles(), 2, '.', '') . ' mi');
      $this->setSubmissionValue($submission, $this->configuration['distance_meters_field'], $distance->distanceMeters());

      $quote = $this->quoteCalculator->calculate($submission->getData(), $distance);
      $quote_data = $quote->toArray();
      $quote_total_field = $this->configuration['quote_total_field'] ?: 'final_calculated_quote';
      $this->setSubmissionValue($submission, $quote_total_field, $quote->totalDecimal());
      $this->setSubmissionValue($submission, $this->configuration['quote_currency_field'], $quote->currency());
      $this->setSubmissionValue($submission, $this->configuration['quote_status_field'], $quote->status());
      $this->setSubmissionValue($submission, $this->configuration['quote_rate_version_field'], $quote->rateVersion());
      $this->setSubmissionValue(
        $submission,
        $this->configuration['quote_breakdown_field'],
        json_encode($quote_data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
      );
    }
    catch (DistanceProviderException $exception) {
      $this->markManualReview($submission, $exception->getMessage());
      $this->loggerFactory->get('custom_webform_handlers')->warning('Authoritative route calculation failed with code @code.', [
        '@code' => $exception->getMessage(),
      ]);
    }
    catch (\Throwable $exception) {
      $this->markManualReview($submission, 'quote_calculation_failed');
      $this->loggerFactory->get('custom_webform_handlers')->error('Authoritative quote calculation failed with exception type @type.', [
        '@type' => get_class($exception),
      ]);
    }
  }

  private function clearComputedValues(WebformSubmissionInterface $submission): void {
    $fields = [
      $this->configuration['distance_field'],
      $this->configuration['distance_meters_field'],
      $this->configuration['quote_total_field'] ?: 'final_calculated_quote',
      $this->configuration['quote_currency_field'],
      $this->configuration['quote_breakdown_field'],
      $this->configuration['quote_status_field'],
      $this->configuration['quote_rate_version_field'],
    ];
    foreach (array_unique(array_filter($fields)) as $field) {
      $this->setSubmissionValue($submission, $field, '');
    }
  }

  private function markManualReview(WebformSubmissionInterface $submission, string $reason): void {
    $config = \Drupal::config('custom_webform_handlers.settings');
    $currency = strtoupper((string) ($config->get('quote.currency') ?: 'USD'));
    $rate_version = (string) ($config->get('quote.rate_version') ?: 'unversioned');
    $breakdown = [
      'currency' => $currency,
      'total_cents' => NULL,
      'total' => NULL,
      'line_items' => [],
      'status' => 'manual_review',
      'manual_review_reasons' => [$reason],
      'rate_version' => $rate_version,
      'distance' => NULL,
      'weight_lbs' => NULL,
      'weight_source' => 'not_calculated',
      'preliminary_non_binding' => TRUE,
      'calculated_at' => time(),
    ];
    $this->setSubmissionValue($submission, $this->configuration['quote_currency_field'], $currency);
    $this->setSubmissionValue($submission, $this->configuration['quote_status_field'], 'manual_review');
    $this->setSubmissionValue($submission, $this->configuration['quote_rate_version_field'], $rate_version);
    $this->setSubmissionValue(
      $submission,
      $this->configuration['quote_breakdown_field'],
      json_encode($breakdown, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    );
  }

  private function setSubmissionValue(WebformSubmissionInterface $submission, string $field, mixed $value): void {
    if ($field === '') {
      return;
    }
    $elements = $submission->getWebform()->getElementsInitializedAndFlattened();
    if (isset($elements[$field])) {
      $submission->setElementData($field, $value);
    }
  }

}
