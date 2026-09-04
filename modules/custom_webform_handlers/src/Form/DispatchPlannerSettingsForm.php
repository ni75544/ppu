<?php

namespace Drupal\custom_webform_handlers\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\custom_webform_handlers\Dispatch\DeliveryMode;

/**
 * Administers the Dispatch Planner's operating profile.
 *
 * This form owns the operating profile and nothing else. It holds no Google key
 * — that stays on the existing API settings form — and it does not touch quote
 * rates, Webform submissions, or reservation records.
 *
 * Validation here is about coherence, not taste. A profile that passes must be
 * one the planner can actually derive Slots from: a real timezone, a grid that
 * divides an hour, work-hour ranges that run forwards, breaks that fall inside
 * those hours, buffers that are not negative, and a courier limit above zero. A
 * profile that fails is refused rather than saved and left to produce silently
 * empty days.
 */
final class DispatchPlannerSettingsForm extends ConfigFormBase {

  /**
   * The config object holding the operating profile.
   */
  const SETTINGS = 'custom_webform_handlers.dispatch';

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'custom_webform_handlers_dispatch_planner_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [self::SETTINGS];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config(self::SETTINGS);

    $form['intro'] = [
      '#markup' => '<p>' . $this->t('The Dispatch Planner offers pickup-arrival Slots on a fixed grid in one timezone. A Slot is when the Resource arrives at the customer, not how long the job takes; the occupied Dispatch Window is always derived on the server.') . '</p>',
    ];

    // Persistent guidance, not a transient message: while this holds, the planner
    // offers nothing at all, and an operator arriving at this form should be told
    // why before they look for the reason elsewhere.
    if ((bool) $config->get('resource.return_to_base') && trim((string) $config->get('resource.depot_address')) === '') {
      $form['depot_required'] = [
        '#markup' => '<div class="messages messages--warning">'
        . $this->t('Self-booking is currently switched off by configuration. Return to base is required but no depot address is set, so the planner cannot prove a Resource could get home and therefore offers no Slots. Set the depot address below to enable self-booking; do not disable return to base to work around it.')
        . '</div>',
        '#allowed_tags' => ['div'],
        '#weight' => -10,
      ];
    }

    $form['resource'] = [
      '#type' => 'details',
      '#title' => $this->t('Resource Profile'),
      '#open' => TRUE,
      '#tree' => TRUE,
    ];
    $form['resource']['id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Resource identifier'),
      '#default_value' => $config->get('resource.id'),
      '#required' => TRUE,
      '#maxlength' => 128,
      '#description' => $this->t('Canonical identifier that partitions Slot capacity, for example driver-01-vehicle-01. Lowercase letters, numbers, hyphens, and underscores.'),
    ];
    $form['resource']['capacity'] = [
      '#type' => 'number',
      '#title' => $this->t('Capacity per Slot'),
      '#default_value' => $config->get('resource.capacity'),
      '#min' => 1,
      '#required' => TRUE,
      '#description' => $this->t('Concurrent Dispatch Windows this Resource can serve. The initial model is one.'),
    ];
    $form['resource']['timezone'] = [
      '#type' => 'select',
      '#title' => $this->t('Scheduling timezone'),
      '#options' => array_combine(\DateTimeZone::listIdentifiers(), \DateTimeZone::listIdentifiers()),
      '#default_value' => $config->get('resource.timezone'),
      '#required' => TRUE,
      '#description' => $this->t('Every local time in the planner is read in this zone.'),
    ];
    $form['resource']['depot_address'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Depot address'),
      '#default_value' => $config->get('resource.depot_address'),
      '#maxlength' => 255,
      '#description' => $this->t('Where the Resource starts, and returns to when return-to-base is required. While this is empty and return to base is enabled, the planner offers no Slots and self-booking cannot operate.'),
    ];
    $form['resource']['grid_minutes'] = [
      '#type' => 'number',
      '#title' => $this->t('Pickup-arrival grid in minutes'),
      '#default_value' => $config->get('resource.grid_minutes'),
      '#min' => 1,
      '#max' => 240,
      '#required' => TRUE,
      '#description' => $this->t('Must divide 60 evenly, so the grid repeats identically every hour. The production grid is 30.'),
    ];
    $form['resource']['working_hours'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Working hours'),
      '#default_value' => $this->encodeWorkingHours($config->get('resource.working_hours') ?: []),
      '#rows' => 7,
      '#required' => TRUE,
      '#description' => $this->t('One line per worked weekday, as <em>ISO weekday|HH:MM|HH:MM</em>, where 1 is Monday. Omit a weekday to close it entirely.'),
    ];
    $form['resource']['breaks'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Breaks'),
      '#default_value' => $this->encodeIntervals($config->get('resource.breaks') ?: []),
      '#rows' => 3,
      '#description' => $this->t('One local interval per line, as <em>HH:MM|HH:MM</em>. No Dispatch Window may overlap these.'),
    ];
    $form['resource']['blackout_dates'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Blackout dates'),
      '#default_value' => implode("\n", $config->get('resource.blackout_dates') ?: []),
      '#rows' => 3,
      '#description' => $this->t('One local date per line, as YYYY-MM-DD. Nothing is offered on these days.'),
    ];
    $form['resource']['repositioning_buffer_minutes'] = [
      '#type' => 'number',
      '#title' => $this->t('Repositioning buffer in minutes'),
      '#default_value' => $config->get('resource.repositioning_buffer_minutes'),
      '#min' => 0,
      '#required' => TRUE,
      '#description' => $this->t('Padding added to travel between one Dispatch Window and the next Reservation. Not part of the customer-requested service.'),
    ];
    $form['resource']['operational_buffer_minutes'] = [
      '#type' => 'number',
      '#title' => $this->t('Operational buffer in minutes'),
      '#default_value' => $config->get('resource.operational_buffer_minutes'),
      '#min' => 0,
      '#required' => TRUE,
      '#description' => $this->t('Padding at the end of every Dispatch Window before the Resource is free again.'),
    ];
    $form['resource']['booking_horizon_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Booking horizon in days'),
      '#default_value' => $config->get('resource.booking_horizon_days'),
      '#min' => 1,
      '#max' => 365,
      '#required' => TRUE,
    ];
    $form['resource']['return_to_base'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Require return to base within working hours'),
      '#default_value' => (bool) $config->get('resource.return_to_base'),
    ];

    $form['self_bookable'] = [
      '#type' => 'details',
      '#title' => $this->t('Self-service eligibility'),
      '#open' => TRUE,
      '#tree' => TRUE,
      '#description' => $this->t('These are the limits on what a customer may book without speaking to staff. Every one of them is an operating policy you set here, not a guarantee to a customer or a statement about what can be carried — raising a limit opens more work to self-service, and lowering it sends more to Manual Review. A request that fails any of them is still quoted and still reaches staff; it simply creates no Hold and consumes no capacity.'),
    ];
    $form['self_bookable']['service_types'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Self-bookable service machine values'),
      '#default_value' => implode("\n", $config->get('self_bookable.service_types') ?: []),
      '#rows' => 3,
      '#required' => TRUE,
      '#description' => $this->t('One service machine value per line. Courier is the self-bookable service. Values already stored on submissions must not be renamed here.'),
    ];
    $form['self_bookable']['delivery_modes'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Self-bookable Courier delivery modes'),
      '#options' => $this->deliveryModeOptions(),
      '#default_value' => $config->get('self_bookable.delivery_modes') ?: [],
      '#description' => $this->t('Only the modes checked here may be self-booked. A mode left unchecked is Manual Review even when Courier itself is bookable.'),
    ];
    $form['self_bookable']['max_package_lbs'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum self-bookable package weight in pounds'),
      '#default_value' => $config->get('self_bookable.max_package_lbs'),
      '#min' => 1,
      '#required' => TRUE,
      '#description' => $this->t('A declared package heavier than this is Manual Review. Express Courier ships configured at 30 lb, which reflects what one driver can carry unaided rather than any fixed rule; set it to whatever your own single-driver limit actually is.'),
    ];
    $form['self_bookable']['require_declared_weight'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Require a declared weight'),
      '#default_value' => (bool) $config->get('self_bookable.require_declared_weight'),
      '#description' => $this->t('An undeclared weight is unknown, not light. Leaving this off lets unweighed packages through.'),
    ];
    $form['self_bookable']['require_single_driver_handling'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Require single-driver handling'),
      '#default_value' => (bool) $config->get('self_bookable.require_single_driver_handling'),
      '#description' => $this->t('The customer must confirm one person can carry the package unaided. A checkbox has no third state, so an unconfirmed package reads as needing two people and goes to Manual Review. Turn this off only if you are willing to dispatch one driver against an unanswered question.'),
    ];

    $form['manual_review'] = [
      '#type' => 'details',
      '#title' => $this->t('Manual Review'),
      '#open' => TRUE,
      '#tree' => TRUE,
      '#description' => $this->t('Manual Review creates neither a Hold nor a Reservation and consumes no Resource capacity.'),
    ];
    $form['manual_review']['service_types'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Service machine values always needing staff approval'),
      '#default_value' => implode("\n", $config->get('manual_review.service_types') ?: []),
      '#rows' => 5,
    ];
    $form['manual_review']['vehicle_helper_needs'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Helper machine values implying multi-person work'),
      '#default_value' => implode("\n", $config->get('manual_review.vehicle_helper_needs') ?: []),
      '#rows' => 3,
    ];
    $form['manual_review']['handling_flags'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Handling markers forcing Manual Review'),
      '#default_value' => implode("\n", $config->get('manual_review.handling_flags') ?: []),
      '#rows' => 5,
    ];

    $form['durations'] = [
      '#type' => 'details',
      '#title' => $this->t('Work durations'),
      '#open' => TRUE,
      '#tree' => TRUE,
    ];
    $form['durations']['pickup_work_minutes'] = [
      '#type' => 'number',
      '#title' => $this->t('Pickup work in minutes'),
      '#default_value' => $config->get('durations.pickup_work_minutes'),
      '#min' => 1,
      '#required' => TRUE,
    ];
    $form['durations']['delivery_work_minutes'] = [
      '#type' => 'number',
      '#title' => $this->t('Delivery work in minutes'),
      '#default_value' => $config->get('durations.delivery_work_minutes'),
      '#min' => 1,
      '#required' => TRUE,
    ];

    $form['durations']['courier'] = [
      '#type' => 'details',
      '#title' => $this->t('Express Courier durations'),
      '#open' => TRUE,
      '#tree' => TRUE,
      '#description' => $this->t('Express Courier keeps its own dwell times because a package handoff is not a move. Road time is never entered here: it comes from the scheduled route service at the actual departure time, per leg. Where a figure below is left at 0 the planner falls back to the general figure above or on the Resource Profile, so leaving this section alone changes nothing.'),
    ];
    $form['durations']['courier']['pickup_handling_minutes'] = [
      '#type' => 'number',
      '#title' => $this->t('Pickup dwell in minutes'),
      '#default_value' => $config->get('durations.courier.pickup_handling_minutes'),
      '#min' => 1,
      '#required' => TRUE,
      '#description' => $this->t('Time on site collecting the package.'),
    ];
    $form['durations']['courier']['delivery_handling_minutes'] = [
      '#type' => 'number',
      '#title' => $this->t('Delivery dwell in minutes'),
      '#default_value' => $config->get('durations.courier.delivery_handling_minutes'),
      '#min' => 1,
      '#required' => TRUE,
      '#description' => $this->t('Time on site handing the package over.'),
    ];
    $form['durations']['courier']['store_pickup_dwell_minutes'] = [
      '#type' => 'number',
      '#title' => $this->t('Store Pickup dwell in minutes'),
      '#default_value' => $config->get('durations.courier.store_pickup_dwell_minutes'),
      '#min' => 0,
      '#description' => $this->t('Replaces the pickup dwell for Store Pickup rather than adding to it, because queueing at a counter for a paid order is a different job from taking a package from a customer. 0 uses the pickup dwell above.'),
    ];
    $form['durations']['courier']['operational_buffer_minutes'] = [
      '#type' => 'number',
      '#title' => $this->t('Operational buffer in minutes'),
      '#default_value' => $config->get('durations.courier.operational_buffer_minutes'),
      '#min' => 0,
      '#description' => $this->t('End-of-window padding for Express Courier only. 0 uses the Resource Profile buffer, which is sized for longer jobs.'),
    ];
    $form['durations']['courier']['promise_minutes'] = [
      '#type' => 'number',
      '#title' => $this->t('Pickup arrival promise width in minutes'),
      '#default_value' => $config->get('durations.courier.promise_minutes'),
      '#min' => 0,
      '#description' => $this->t('How wide an arrival promise an Express Courier customer is shown. 0 uses the general pickup promise. These are operating figures an operator sets, not a guarantee to a customer.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    $resource = (array) $form_state->getValue('resource');

    if (!preg_match('/^[a-z0-9_\-]+$/', trim((string) ($resource['id'] ?? '')))) {
      $form_state->setErrorByName('resource][id', $this->t('The Resource identifier may contain only lowercase letters, numbers, hyphens, and underscores.'));
    }

    $timezone = (string) ($resource['timezone'] ?? '');
    if (!in_array($timezone, \DateTimeZone::listIdentifiers(), TRUE)) {
      $form_state->setErrorByName('resource][timezone', $this->t('Select a timezone this server recognises.'));
    }

    // A grid that does not divide an hour would drift: 08:00 and 09:00 would
    // offer different minutes, and a stored Slot could stop being on-grid.
    $grid = (int) ($resource['grid_minutes'] ?? 0);
    if ($grid < 1 || 60 % $grid !== 0) {
      $form_state->setErrorByName('resource][grid_minutes', $this->t('The grid must divide 60 evenly, so every hour offers the same minutes. Valid values include 5, 10, 15, 20, 30, and 60.'));
    }

    foreach (['repositioning_buffer_minutes', 'operational_buffer_minutes'] as $key) {
      if ((int) ($resource[$key] ?? -1) < 0) {
        $form_state->setErrorByName('resource][' . $key, $this->t('Buffers cannot be negative.'));
      }
    }

    if ((int) ($resource['capacity'] ?? 0) < 1) {
      $form_state->setErrorByName('resource][capacity', $this->t('Capacity must be at least one.'));
    }
    if ((int) ($resource['booking_horizon_days'] ?? 0) < 1) {
      $form_state->setErrorByName('resource][booking_horizon_days', $this->t('The booking horizon must be at least one day.'));
    }

    // Return-to-base without a depot cannot be evaluated, so the planner must
    // refuse to offer anything. That is a warning here rather than a save-time
    // error on purpose: blocking the save would force an operator to invent a
    // depot address in order to change anything else on this form, and an
    // invented depot is worse than a configured refusal.
    if (!empty($resource['return_to_base']) && trim((string) ($resource['depot_address'] ?? '')) === '') {
      $this->messenger()->addWarning($this->t('Return to base is enabled but no depot address is set, so the Dispatch Planner will offer no Slots and self-booking cannot operate. Set the depot address to enable it.'));
    }

    $hours = $this->parseWorkingHours((string) ($resource['working_hours'] ?? ''));
    if ($hours === NULL) {
      $form_state->setErrorByName('resource][working_hours', $this->t('Use one line per weekday, as ISO weekday|HH:MM|HH:MM, with the weekday between 1 and 7 and the end after the start.'));
    }
    elseif ($hours === []) {
      $form_state->setErrorByName('resource][working_hours', $this->t('At least one weekday must be worked, or the planner can never offer a Slot.'));
    }

    $breaks = $this->parseIntervals((string) ($resource['breaks'] ?? ''));
    if ($breaks === NULL) {
      $form_state->setErrorByName('resource][breaks', $this->t('Use one interval per line, as HH:MM|HH:MM, with the end after the start.'));
    }
    elseif (is_array($hours) && $hours !== []) {
      // A break outside every working day is dead configuration that reads as if
      // it were protecting time.
      foreach ($breaks as $index => $break) {
        $inside = FALSE;
        foreach ($hours as $window) {
          if ($break['start'] >= $window['start'] && $break['end'] <= $window['end']) {
            $inside = TRUE;
            break;
          }
        }
        if (!$inside) {
          $form_state->setErrorByName('resource][breaks', $this->t('Break @n (@start to @end) does not fall inside any configured working day.', [
            '@n' => $index + 1,
            '@start' => $break['start'],
            '@end' => $break['end'],
          ]));
        }
      }
    }

    foreach ($this->lines((string) ($resource['blackout_dates'] ?? '')) as $date) {
      $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('UTC'));
      if (!$parsed || $parsed->format('Y-m-d') !== $date) {
        $form_state->setErrorByName('resource][blackout_dates', $this->t('@date is not a valid YYYY-MM-DD date.', ['@date' => $date]));
      }
    }

    $selfBookable = (array) $form_state->getValue('self_bookable');
    if ((int) ($selfBookable['max_package_lbs'] ?? 0) < 1) {
      $form_state->setErrorByName('self_bookable][max_package_lbs', $this->t('The self-service weight limit must be at least one pound.'));
    }
    if ($this->lines((string) ($selfBookable['service_types'] ?? '')) === []) {
      $form_state->setErrorByName('self_bookable][service_types', $this->t('List at least one self-bookable service machine value, or nothing can ever be booked without staff.'));
    }

    // Checkboxes return every option keyed by value, unchecked ones as 0.
    $modes = array_values(array_filter((array) ($selfBookable['delivery_modes'] ?? [])));
    $known = array_keys($this->deliveryModeOptions());
    $unknown = array_diff($modes, $known);
    if ($unknown !== []) {
      $form_state->setErrorByName('self_bookable][delivery_modes', $this->t('Unknown delivery mode values: @values', [
        '@values' => implode(', ', $unknown),
      ]));
    }
    if ($modes === []) {
      $this->messenger()->addWarning($this->t('No Courier delivery mode is self-bookable, so every Courier request will go to Manual Review.'));
    }

    // A value cannot be both self-bookable and always-Manual-Review; the planner
    // would have to pick one, and either choice would be a surprise.
    $overlap = array_intersect(
      $this->lines((string) ($selfBookable['service_types'] ?? '')),
      $this->lines((string) (((array) $form_state->getValue('manual_review'))['service_types'] ?? ''))
    );
    if ($overlap !== []) {
      $form_state->setErrorByName('manual_review][service_types', $this->t('These service values are listed as both self-bookable and Manual Review: @values', [
        '@values' => implode(', ', $overlap),
      ]));
    }

    $durations = (array) $form_state->getValue('durations');
    foreach (['pickup_work_minutes', 'delivery_work_minutes'] as $key) {
      if ((int) ($durations[$key] ?? 0) < 1) {
        $form_state->setErrorByName('durations][' . $key, $this->t('Work durations must be at least one minute.'));
      }
    }

    $courier = (array) ($durations['courier'] ?? []);
    // Dwell at each end is required work, so zero is not an available answer: it
    // would schedule a driver to arrive and leave in the same instant.
    foreach (['pickup_handling_minutes', 'delivery_handling_minutes'] as $key) {
      if ((int) ($courier[$key] ?? 0) < 1) {
        $form_state->setErrorByName('durations][courier][' . $key, $this->t('Express Courier dwell times must be at least one minute.'));
      }
    }
    // The remaining three are overrides, where 0 means "use the general figure".
    foreach (['store_pickup_dwell_minutes', 'operational_buffer_minutes', 'promise_minutes'] as $key) {
      if ((int) ($courier[$key] ?? 0) < 0) {
        $form_state->setErrorByName('durations][courier][' . $key, $this->t('Express Courier overrides cannot be negative. Use 0 to fall back to the general figure.'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $resource = (array) $form_state->getValue('resource');
    $selfBookable = (array) $form_state->getValue('self_bookable');
    $manualReview = (array) $form_state->getValue('manual_review');
    $durations = (array) $form_state->getValue('durations');
    $courier = (array) ($durations['courier'] ?? []);

    $this->config(self::SETTINGS)
      ->set('resource.id', trim((string) $resource['id']))
      ->set('resource.capacity', (int) $resource['capacity'])
      ->set('resource.timezone', (string) $resource['timezone'])
      ->set('resource.depot_address', trim((string) $resource['depot_address']))
      ->set('resource.grid_minutes', (int) $resource['grid_minutes'])
      ->set('resource.working_hours', $this->parseWorkingHours((string) $resource['working_hours']) ?: [])
      ->set('resource.breaks', $this->parseIntervals((string) $resource['breaks']) ?: [])
      ->set('resource.blackout_dates', $this->lines((string) $resource['blackout_dates']))
      ->set('resource.repositioning_buffer_minutes', (int) $resource['repositioning_buffer_minutes'])
      ->set('resource.operational_buffer_minutes', (int) $resource['operational_buffer_minutes'])
      ->set('resource.booking_horizon_days', (int) $resource['booking_horizon_days'])
      ->set('resource.return_to_base', (bool) $resource['return_to_base'])
      ->set('self_bookable.service_types', $this->lines((string) $selfBookable['service_types']))
      ->set('self_bookable.delivery_modes', array_values(array_filter((array) ($selfBookable['delivery_modes'] ?? []))))
      ->set('self_bookable.max_package_lbs', (int) $selfBookable['max_package_lbs'])
      ->set('self_bookable.require_declared_weight', (bool) $selfBookable['require_declared_weight'])
      ->set('self_bookable.require_single_driver_handling', (bool) $selfBookable['require_single_driver_handling'])
      ->set('manual_review.service_types', $this->lines((string) $manualReview['service_types']))
      ->set('manual_review.vehicle_helper_needs', $this->lines((string) $manualReview['vehicle_helper_needs']))
      ->set('manual_review.handling_flags', $this->lines((string) $manualReview['handling_flags']))
      ->set('durations.pickup_work_minutes', (int) $durations['pickup_work_minutes'])
      ->set('durations.delivery_work_minutes', (int) $durations['delivery_work_minutes'])
      // Written key by key, like everything above, so sibling duration settings
      // this form does not expose — durations.pickup_promise_minutes and the whole
      // durations.move tree — survive a save instead of being replaced by whatever
      // this form happens to know about.
      ->set('durations.courier.pickup_handling_minutes', (int) $courier['pickup_handling_minutes'])
      ->set('durations.courier.delivery_handling_minutes', (int) $courier['delivery_handling_minutes'])
      ->set('durations.courier.store_pickup_dwell_minutes', (int) ($courier['store_pickup_dwell_minutes'] ?? 0))
      ->set('durations.courier.operational_buffer_minutes', (int) ($courier['operational_buffer_minutes'] ?? 0))
      ->set('durations.courier.promise_minutes', (int) ($courier['promise_minutes'] ?? 0))
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Returns the Courier delivery modes an operator may allow.
   *
   * Taken from the DeliveryMode enum rather than restated, so the form can never
   * offer a mode the planner does not recognise.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Machine value => human label.
   */
  private function deliveryModeOptions(): array {
    return [
      DeliveryMode::Send->value => $this->t('Send — the customer hands the package over'),
      DeliveryMode::Receive->value => $this->t('Receive — collected from a third party'),
      DeliveryMode::StorePickup->value => $this->t('Store Pickup — collected from a nominated counter'),
    ];
  }

  /**
   * Splits a textarea into trimmed, non-empty lines.
   *
   * @return string[]
   *   The lines, reindexed.
   */
  private function lines(string $value): array {
    $lines = preg_split('/\R/', $value) ?: [];
    return array_values(array_filter(array_map('trim', $lines), static fn (string $line): bool => $line !== ''));
  }

  /**
   * Parses working hours, or reports the text as unusable.
   *
   * @return array<string, array{start: string, end: string}>|null
   *   Weekday number => local bounds, or NULL when any line is malformed.
   */
  private function parseWorkingHours(string $value): ?array {
    $hours = [];
    foreach ($this->lines($value) as $line) {
      $parts = array_map('trim', explode('|', $line));
      if (count($parts) !== 3) {
        return NULL;
      }
      [$weekday, $start, $end] = $parts;
      if (!preg_match('/^[1-7]$/', $weekday)
        || !$this->isLocalTime($start)
        || !$this->isLocalTime($end)
        || $end <= $start) {
        return NULL;
      }
      $hours[$weekday] = ['start' => $start, 'end' => $end];
    }
    ksort($hours);
    return $hours;
  }

  /**
   * Parses HH:MM|HH:MM intervals, or reports the text as unusable.
   *
   * @return array<int, array{start: string, end: string}>|null
   *   The intervals, or NULL when any line is malformed.
   */
  private function parseIntervals(string $value): ?array {
    $intervals = [];
    foreach ($this->lines($value) as $line) {
      $parts = array_map('trim', explode('|', $line));
      if (count($parts) !== 2) {
        return NULL;
      }
      [$start, $end] = $parts;
      if (!$this->isLocalTime($start) || !$this->isLocalTime($end) || $end <= $start) {
        return NULL;
      }
      $intervals[] = ['start' => $start, 'end' => $end];
    }
    return $intervals;
  }

  /**
   * Tells whether a string is a zero-padded 24-hour HH:MM value.
   *
   * Zero padding matters because these values are compared as strings.
   */
  private function isLocalTime(string $value): bool {
    return (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value);
  }

  /**
   * Renders stored working hours back into the textarea format.
   */
  private function encodeWorkingHours(array $hours): string {
    $lines = [];
    foreach ($hours as $weekday => $window) {
      $lines[] = $weekday . '|' . ($window['start'] ?? '') . '|' . ($window['end'] ?? '');
    }
    return implode("\n", $lines);
  }

  /**
   * Renders stored intervals back into the textarea format.
   */
  private function encodeIntervals(array $intervals): string {
    $lines = [];
    foreach ($intervals as $interval) {
      $lines[] = ($interval['start'] ?? '') . '|' . ($interval['end'] ?? '');
    }
    return implode("\n", $lines);
  }

}
