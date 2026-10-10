<?php

namespace Drupal\custom_webform_handlers\Plugin\WebformHandler;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\custom_webform_handlers\Checkout\DispatchCheckoutStarter;
use Drupal\custom_webform_handlers\Distance\DistanceProviderException;
use Drupal\custom_webform_handlers\Distance\DistanceProviderInterface;
use Drupal\custom_webform_handlers\Distance\RoutingModeResolver;
use Drupal\custom_webform_handlers\Dispatch\DispatchManualReviewEntry;
use Drupal\custom_webform_handlers\Dispatch\Notify\DispatchNotification;
use Drupal\custom_webform_handlers\Dispatch\Notify\DispatchNotifierInterface;
use Drupal\custom_webform_handlers\Dispatch\Profile\BookingProfile;
use Drupal\custom_webform_handlers\Dispatch\Profile\BookingProfileResolverInterface;
use Drupal\custom_webform_handlers\Dispatch\ResourceProfileFactory;
use Drupal\custom_webform_handlers\Quote\QuoteCalculator;
use Drupal\custom_webform_handlers\Ranking\ServicePopularityInterface;
use Drupal\custom_webform_handlers\Ranking\StateServicePopularityStore;
use Drupal\custom_webform_handlers\Reservation\ReservationDefinitionProvider;
use Drupal\custom_webform_handlers\Reservation\ReservationException;
use Drupal\custom_webform_handlers\Reservation\ReservationManagerInterface;
use Drupal\custom_webform_handlers\Reservation\ReservationOwner;
use Drupal\custom_webform_handlers\Reservation\ReservationSlot;
use Drupal\custom_webform_handlers\Review\ReviewManagerInterface;
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

  /**
   * Fallback address-commit debounce published to the browser, milliseconds.
   *
   * Used when `custom_webform_handlers.settings:preview_debounce_ms` is absent,
   * which is the case on a site whose active configuration predates that key.
   * The behavior carries the same fallback, so a stale active configuration
   * cannot leave the browser committing on the first event of a burst.
   */
  const PREVIEW_DEBOUNCE_MS = 600;

  /**
   * Bounds for a configured commit debounce, milliseconds.
   *
   * Clamped server-side as well as in the browser: a value out of range must not
   * be able to remove the coalescing or strand a visitor waiting for an
   * estimate. The browser applies the same bounds to the value it is given.
   */
  const PREVIEW_DEBOUNCE_MIN_MS = 100;
  const PREVIEW_DEBOUNCE_MAX_MS = 5000;

  /**
   * The pricing answers that belong in a quote calculation.
   *
   * This is the single source of truth for the allowlist. alterForm() reuses it
   * for both quote_defaults and the quote_input_fields it publishes to the
   * browser, so the client never carries its own copy of the list.
   */
  const QUOTE_INPUT_FIELDS = [
    'service_type',
    // Express Courier uses these package facts for an eligible, priced quote.
    // They are intentionally part of the same client/server allowlist as every
    // other pricing input, and never contain contact or payment information.
    'delivery_mode',
    'total_weight_lbs',
    'total_weight_unit',
    'courier_urgency',
    'prohibited_items_ack',
    // The compact Courier prohibited-items attestation. It is a pricing input
    // only in the narrow sense that a checked attestation is what lets a Courier
    // quote be an estimate rather than manual_review; QuoteCalculator's courier
    // branch reads it alongside prohibited_items_ack so the live preview honours
    // the same acknowledgement the preSave canonicalization writes.
    'courier_prohibited_ack',
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
  ];

  /**
   * The customer-route entry facade used for customer quote calculations.
   *
   * The routing-mode facade rather than the Google adapter directly: in DDEV it
   * serves a deterministic recorded route and makes no outbound request.
   */
  protected DistanceProviderInterface $distanceProvider;

  /**
   * Decides the routing mode, and gates browser Google Maps loading.
   */
  protected RoutingModeResolver $routingModeResolver;

  /**
   * The authoritative quote calculator.
   */
  protected QuoteCalculator $quoteCalculator;

  /**
   * The fixed-slot reservation manager.
   */
  protected ReservationManagerInterface $reservationManager;

  /**
   * The fixed-slot reservation definition provider.
   */
  protected ReservationDefinitionProvider $reservationDefinitionProvider;

  /**
   * The fixed-slot reservation owner resolver.
   */
  protected ReservationOwner $reservationOwner;

  /**
   * The dispatch resource profile factory.
   */
  protected ResourceProfileFactory $dispatchProfileFactory;

  /**
   * Where a committed booking is announced from, and never sent from.
   *
   * Held on the port rather than on any mail service on purpose. This handler runs
   * inside a Webform submission save, so anything slow reached from here would be
   * slow inside a customer's checkout; the notifier enqueues and returns, and the
   * message is composed and sent later on cron. It is also called only after the
   * planner has confirmed, which is after that transaction committed and released
   * its Resource Calendar Guard rows, so no announcement is ever attempted while a
   * capacity lock is held.
   */
  protected DispatchNotifierInterface $dispatchNotifier;

  /**
   * The clock the dispatch manual-review announcement is stamped from.
   *
   * Its own dependency rather than a call to time(), so the moment recorded on a
   * queued notification is taken from the same request-time source the rest of the
   * save reads, rather than drifting to a separate wall-clock reading.
   */
  protected TimeInterface $dispatchTime;

  /**
   * The seam that turns a saved dispatch submission into a checkout redirect.
   *
   * Injected rather than reached statically so the one place a submission
   * becomes a payable order stays a single seam. It is consulted only from
   * confirmForm(), and it answers with a checkout URL or NULL — where NULL is an
   * ordinary refusal that leaves the form's own confirmation in place.
   */
  protected DispatchCheckoutStarter $checkoutStarter;

  /**
   * The one authority on Review Requests, injected on its interface.
   *
   * This handler is where a job that could not be priced becomes a request somebody
   * owes an answer on. It is asked to open that request and nothing else: it does not
   * decide states, does not read the queue, and does not mint or store the credential
   * — the manager does all three, so there is one place where a review's lifecycle
   * lives and this handler is not a second one.
   */
  protected ReviewManagerInterface $reviews;

  /**
   * Which order to show the service cards in, asked on its interface.
   *
   * Presentation only, and the seam is shaped so that it cannot be anything else: it
   * is handed the options this form already offers and returns the same ones in a
   * different sequence. It cannot add a service, remove one, rename one, or make one
   * bookable, so no answer it could give would change what this handler goes on to
   * validate, price, or refuse.
   *
   * Asked on every fresh render and never on a resave. Reordering a form that already
   * has a submission behind it would move the cards under a customer who is partway
   * through choosing, which is a worse outcome than showing them a stale order.
   */
  protected ServicePopularityInterface $servicePopularity;

  /**
   * The one seam that answers what a service type decides about being booked.
   *
   * This handler used to answer several of those questions itself, from several
   * different sources: which services are self-bookable (read from
   * self_bookable.service_types while building the browser precheck), which facts
   * a request must carry (assembled from three separate configuration keys), and
   * whether a submission is a Courier one (a `!== 'courier'` literal). Each of
   * those was a copy of a decision the eligibility policy also makes, and copies
   * of one decision are how a form comes to offer what the server will refuse.
   *
   * Everything the browser is told about a service now comes from the profile this
   * resolver returns, and the server assesses against the same profile. There is
   * one place to change to open a service, and the widget cannot be told something
   * the policy does not believe.
   */
  protected BookingProfileResolverInterface $bookingProfiles;

  /**
   * The raw tracking code minted by this request's save, held only until it is shown.
   *
   * A property rather than storage, because storing it would defeat it. The code is
   * the credential for the customer's own page, kept as an HMAC in the database, and
   * writing the raw value into a submission element or the session would put a bearer
   * token where every reader of that submission — and every later administrator of it
   * — could pick it up. This holds it in memory for the remainder of the one request
   * that created it: postSave() sets it, confirmForm() shows it to the browser that
   * submitted the form and clears it, and if anything goes wrong in between the value
   * dies with the request and the customer is told to ring the office instead.
   *
   * NULL is the normal case. It means no review was raised by this save, or the job
   * already had one — a resave never re-issues a credential, because open() mints the
   * code exactly once and returns it only to the call that created the row.
   */
  private ?string $issuedTrackingCode = NULL;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->distanceProvider = $container->get('custom_webform_handlers.customer_route_entry_provider');
    $instance->routingModeResolver = $container->get('custom_webform_handlers.routing_mode_resolver');
    $instance->quoteCalculator = $container->get('custom_webform_handlers.quote_calculator');
    $instance->reservationManager = $container->get('custom_webform_handlers.reservation_manager');
    $instance->reservationDefinitionProvider = $container->get('custom_webform_handlers.reservation_definition_provider');
    $instance->reservationOwner = $container->get('custom_webform_handlers.reservation_owner');
    $instance->dispatchProfileFactory = $container->get('custom_webform_handlers.dispatch_profile_factory');
    $instance->dispatchNotifier = $container->get('custom_webform_handlers.dispatch_notifier');
    $instance->dispatchTime = $container->get('datetime.time');
    $instance->checkoutStarter = $container->get('custom_webform_handlers.dispatch_checkout_starter');
    $instance->reviews = $container->get(ReviewManagerInterface::class);
    $instance->servicePopularity = $container->get(ServicePopularityInterface::class);
    $instance->bookingProfiles = $container->get(BookingProfileResolverInterface::class);
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
      'reservation_enabled' => FALSE,
      'reservation_date_field' => '',
      'reservation_time_field' => '',
      'reservation_hold_token_field' => '',
      'reservation_resource_field' => '',
      'reservation_timezone' => '',
      'reservation_duration_minutes' => 60,
      'reservation_hold_seconds' => 600,
      'reservation_booking_window_days' => 90,
      'reservation_allowed_weekdays' => [1, 2, 3, 4, 5, 6],
      // The Dispatch Planner. Off by default, and mutually exclusive with the
      // fixed-slot settings above: DispatchRequestFactory::dispatchOwns() gives
      // dispatch a Webform only while reservation_enabled is off, so no ordering of
      // a cutover leaves both guards enforcing the same submission.
      'dispatch_enabled' => FALSE,
      'dispatch_slot_field' => '',
      'dispatch_hold_token_field' => '',
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
    $date_options = ['' => $this->t('- None -')];
    $select_options = ['' => $this->t('- None -')];
    $time_field_options = ['' => $this->t('- None -')];
    $hidden_options = ['' => $this->t('- None -')];
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
      if (in_array($type, ['date', 'webform_date'], TRUE)) {
        $date_options[$key] = $label;
      }
      if ($type === 'select') {
        $select_options[$key] = $label;
      }
      // The time field must be able to render disabled "Booked" options, which
      // only a select can do, so its own list is deliberately narrower.
      if ($type === 'select') {
        $time_field_options[$key] = $label;
      }
      if ($type === 'hidden') {
        $hidden_options[$key] = $label;
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

    // The resolved state, not the raw merged flag: a handler configured before
    // the flag existed is already active at runtime, so the checkbox has to show
    // that. Saving the form then writes the flag explicitly and the legacy case
    // resolves itself.
    $reservation_enabled = $this->reservationEnabled();

    $form['reservation'] = [
      '#type' => 'details',
      '#title' => $this->t('Reservation and time-slot locking'),
      '#description' => $this->t('A visitor receives a short-lived Hold on one time slot, and a Reservation only when the submission completes. The database refuses a second claim on the same slot; the browser is presentation only.'),
      '#open' => $reservation_enabled,
      '#tree' => FALSE,
    ];

    $form['reservation']['reservation_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable time-slot reservations'),
      '#default_value' => $reservation_enabled,
      '#description' => $this->t('Leave this on to enforce time-slot reservations. A date, time, and hold-token element must all be selected below; incomplete configuration keeps the workflow off rather than offering a slot the server cannot enforce.'),
    ];

    $reservation_visible = [
      'visible' => [':input[name="settings[reservation_enabled]"]' => ['checked' => TRUE]],
    ];

    $form['reservation']['reservation_date_field'] = [
      '#type' => 'select',
      '#title' => $this->t('Reservation date field'),
      '#options' => $date_options,
      '#default_value' => $this->configuration['reservation_date_field'],
      '#description' => $this->t('A date element storing Y-m-d.'),
      '#states' => $reservation_visible,
    ];

    $form['reservation']['reservation_time_field'] = [
      '#type' => 'select',
      '#title' => $this->t('Reservation time field'),
      '#options' => $time_field_options,
      '#default_value' => $this->configuration['reservation_time_field'],
      '#description' => $this->t('A select element whose option keys are canonical zero-padded HH:MM values, such as 09:00. Only select elements are listed: booked slots are shown as disabled options, which radios and text fields cannot express.'),
      '#states' => $reservation_visible,
    ];

    $form['reservation']['reservation_hold_token_field'] = [
      '#type' => 'select',
      '#title' => $this->t('Reservation hold-token field'),
      '#options' => $hidden_options,
      '#default_value' => $this->configuration['reservation_hold_token_field'],
      '#description' => $this->t('A hidden element the browser writes its hold token into. The token is verified server-side on submission.'),
      '#states' => $reservation_visible,
    ];

    $form['reservation']['reservation_resource_field'] = [
      '#type' => 'select',
      '#title' => $this->t('Reservation resource field'),
      '#options' => $select_options,
      '#default_value' => $this->configuration['reservation_resource_field'],
      '#description' => $this->t('Optional. A select element partitioning capacity, such as a crew or vehicle. Without one, every slot belongs to a single default resource.'),
      '#states' => $reservation_visible,
    ];

    $form['reservation']['reservation_timezone'] = [
      '#type' => 'select',
      '#title' => $this->t('Reservation timezone'),
      '#options' => array_combine(\DateTimeZone::listIdentifiers(), \DateTimeZone::listIdentifiers()),
      '#empty_option' => $this->t('- Site default -'),
      '#default_value' => $this->configuration['reservation_timezone'],
      '#description' => $this->t('Local times are resolved through this zone. Times that a daylight-saving change skips or repeats are refused rather than guessed.'),
      '#states' => $reservation_visible,
    ];

    $form['reservation']['reservation_duration_minutes'] = [
      '#type' => 'number',
      '#title' => $this->t('Slot duration (minutes)'),
      '#min' => 1,
      '#default_value' => $this->configuration['reservation_duration_minutes'],
      '#states' => $reservation_visible,
    ];

    $form['reservation']['reservation_hold_seconds'] = [
      '#type' => 'number',
      '#title' => $this->t('Hold lifetime (seconds)'),
      '#min' => 1,
      '#default_value' => $this->configuration['reservation_hold_seconds'],
      '#description' => $this->t('How long a slot stays claimed while the visitor completes the form.'),
      '#states' => $reservation_visible,
    ];

    $form['reservation']['reservation_booking_window_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Booking horizon (days)'),
      '#min' => 1,
      '#default_value' => $this->configuration['reservation_booking_window_days'],
      '#states' => $reservation_visible,
    ];

    $form['reservation']['reservation_allowed_weekdays'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Bookable weekdays'),
      '#options' => [
        1 => $this->t('Monday'),
        2 => $this->t('Tuesday'),
        3 => $this->t('Wednesday'),
        4 => $this->t('Thursday'),
        5 => $this->t('Friday'),
        6 => $this->t('Saturday'),
        7 => $this->t('Sunday'),
      ],
      '#default_value' => $this->configuration['reservation_allowed_weekdays'],
      '#states' => $reservation_visible,
    ];

    // The Dispatch Planner. Presented after the fixed-slot settings and stated as
    // mutually exclusive with them, because that is exactly how it behaves:
    // DispatchRequestFactory::dispatchOwns() gives dispatch a Webform only while
    // reservation_enabled is off. The cutover is therefore one switch off and one
    // switch on, in either order, with no window in which both guards enforce.
    $form['dispatch'] = [
      '#type' => 'details',
      '#title' => $this->t('Dispatch Planner (route-aware scheduling)'),
      '#description' => $this->t('Derives arrival times from the real route, the job, and the driver’s day, instead of a fixed grid of slots. The server calculates every window; the browser only displays what it is offered.'),
      '#open' => !empty($this->configuration['dispatch_enabled']),
      '#tree' => FALSE,
    ];

    if ($this->reservationEnabled()) {
      $form['dispatch']['dispatch_conflict'] = [
        '#type' => 'webform_message',
        '#message_type' => 'warning',
        '#message_message' => $this->t('Time-slot reservations are currently enforcing on this form. Dispatch stands down while that is on, so exactly one workflow ever guards a submission. Turn reservations off to hand scheduling to the Dispatch Planner.'),
      ];
    }

    $form['dispatch']['dispatch_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable the Dispatch Planner'),
      '#default_value' => !empty($this->configuration['dispatch_enabled']),
      '#description' => $this->t('Both elements below must be selected. Incomplete configuration keeps dispatch off rather than offering an arrival the server cannot enforce, and dispatch never enforces while time-slot reservations are enabled above.'),
    ];

    $dispatch_visible = [
      'visible' => [':input[name="settings[dispatch_enabled]"]' => ['checked' => TRUE]],
    ];

    $form['dispatch']['dispatch_slot_field'] = [
      '#type' => 'select',
      '#title' => $this->t('Dispatch arrival field'),
      '#options' => $hidden_options,
      '#default_value' => $this->configuration['dispatch_slot_field'],
      '#description' => $this->t('A hidden element the browser writes the chosen pickup-arrival timestamp into. The server re-derives the arrival and the whole window from it; a window sent by a browser is ignored.'),
      '#states' => $dispatch_visible,
    ];

    $form['dispatch']['dispatch_hold_token_field'] = [
      '#type' => 'select',
      '#title' => $this->t('Dispatch hold marker field'),
      '#options' => $hidden_options,
      '#default_value' => $this->configuration['dispatch_hold_token_field'],
      '#description' => $this->t('A hidden element marking that an arrival was claimed. It is never given the hold token: the token stays in the visitor’s session, and the server reads it from there.'),
      '#states' => $dispatch_visible,
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

    $this->configuration['reservation_enabled'] = (bool) $form_state->getValue('reservation_enabled');
    $this->configuration['dispatch_enabled'] = (bool) $form_state->getValue('dispatch_enabled');
    foreach ([
      'dispatch_slot_field',
      'dispatch_hold_token_field',
    ] as $key) {
      $this->configuration[$key] = (string) ($form_state->getValue($key) ?: '');
    }
    foreach ([
      'reservation_date_field',
      'reservation_time_field',
      'reservation_hold_token_field',
      'reservation_resource_field',
      'reservation_timezone',
    ] as $key) {
      $this->configuration[$key] = (string) ($form_state->getValue($key) ?: '');
    }
    foreach ([
      'reservation_duration_minutes' => 60,
      'reservation_hold_seconds' => 600,
      'reservation_booking_window_days' => 90,
    ] as $key => $fallback) {
      $value = (int) $form_state->getValue($key);
      $this->configuration[$key] = $value > 0 ? $value : $fallback;
    }

    // Checkboxes hand back a keyed map including the unchecked days.
    $weekdays = array_values(array_filter(array_map(
      static fn ($day) => (int) $day,
      (array) ($form_state->getValue('reservation_allowed_weekdays') ?: []),
    )));
    sort($weekdays);
    $this->configuration['reservation_allowed_weekdays'] = $weekdays ?: [1, 2, 3, 4, 5, 6];
  }

  /**
   * Tells whether reservations are active for this handler instance.
   *
   * Resolved through the definition provider so form attachment, the availability
   * and hold endpoints, and final submission validation all answer this question
   * the same way. Asking $this->configuration directly cannot: the merged
   * configuration reports a legacy handler's absent flag as FALSE.
   */
  private function reservationEnabled(): bool {
    return $this->reservationDefinitionProvider->isEnabled(
      $this->getWebform(),
      (string) $this->getHandlerId(),
      $this->configuration,
    );
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

    if (!$form_state->getValue('reservation_enabled')) {
      return;
    }

    foreach ([
      'reservation_date_field' => $this->t('A reservation date field is required when reservations are enabled.'),
      'reservation_time_field' => $this->t('A reservation time field is required when reservations are enabled.'),
      'reservation_hold_token_field' => $this->t('A hidden hold-token field is required when reservations are enabled.'),
    ] as $key => $message) {
      if ((string) $form_state->getValue($key) === '') {
        $form_state->setErrorByName($key, $message);
      }
    }

    $webform = $this->getWebform();
    $date_field = (string) $form_state->getValue('reservation_date_field');
    $token_field = (string) $form_state->getValue('reservation_hold_token_field');
    $timezone = (string) $form_state->getValue('reservation_timezone');
    if ($timezone !== '' && !in_array($timezone, \DateTimeZone::listIdentifiers(), TRUE)) {
      $form_state->setErrorByName('reservation_timezone', $this->t('Select a valid IANA timezone.'));
    }

    $date_problem = $this->reservationDefinitionProvider->dateElementProblem($webform, $date_field);
    if ($date_problem === 'missing') {
      $form_state->setErrorByName('reservation_date_field', $this->t('The selected reservation date field does not exist on this webform.'));
    }
    elseif ($date_problem === 'not_date') {
      $form_state->setErrorByName('reservation_date_field', $this->t('The reservation date field must be a date element that stores Y-m-d values.'));
    }

    $token_problem = $this->reservationDefinitionProvider->hiddenElementProblem($webform, $token_field);
    if ($token_problem === 'missing') {
      $form_state->setErrorByName('reservation_hold_token_field', $this->t('The selected hold-token field does not exist on this webform.'));
    }
    elseif ($token_problem === 'not_hidden') {
      $form_state->setErrorByName('reservation_hold_token_field', $this->t('The hold-token field must be a hidden element.'));
    }

    // A time element whose keys are not canonical HH:MM can never produce a
    // Slot, so it is refused here rather than failing on every request. The rule
    // lives in the definition provider, so configuration and runtime agree.
    $time_field = (string) $form_state->getValue('reservation_time_field');
    if ($time_field !== '') {
      $problem = $this->reservationDefinitionProvider->timeElementProblem($webform, $time_field);
      $messages = [
        'missing' => $this->t('The selected reservation time field does not exist on this webform.'),
        'not_select' => $this->t('The reservation time field must be a select element. Booked slots are shown as disabled options, which radios and text fields cannot express.'),
        'no_options' => $this->t('The reservation time field must declare at least one time option.'),
        'non_canonical_options' => $this->t('The reservation time field must have canonical zero-padded HH:MM option keys, such as 09:00.'),
      ];
      if ($problem !== '' && isset($messages[$problem])) {
        $form_state->setErrorByName('reservation_time_field', $messages[$problem]);
      }
    }

    $resource_field = (string) $form_state->getValue('reservation_resource_field');
    $resource_problem = $this->reservationDefinitionProvider->resourceElementProblem($webform, $resource_field);
    if ($resource_problem === 'missing') {
      $form_state->setErrorByName('reservation_resource_field', $this->t('The selected reservation resource field does not exist on this webform.'));
    }
    elseif ($resource_problem === 'not_select') {
      $form_state->setErrorByName('reservation_resource_field', $this->t('The resource field must be a select element.'));
    }
    if ($resource_field !== '' && in_array($resource_field, [$date_field, $time_field, $token_field], TRUE)) {
      $form_state->setErrorByName('reservation_resource_field', $this->t('The resource field must be different from the date, time, and hold-token fields.'));
    }

    $allowed_weekdays = array_filter(array_map('intval', (array) ($form_state->getValue('reservation_allowed_weekdays') ?: [])));
    if ($allowed_weekdays === []) {
      $form_state->setErrorByName('reservation_allowed_weekdays', $this->t('Select at least one bookable weekday.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function alterForm(array &$form, FormStateInterface $form_state, ?WebformSubmissionInterface $webform_submission = NULL) {
    $webform_id = $this->getWebform()->id();
    $settings_config = \Drupal::config('custom_webform_handlers.settings');

    // What is attached below varies with the browser key and the generated
    // endpoint URLs, so their metadata travels with the form instead of being
    // dropped at render time.
    $cacheability = CacheableMetadata::createFromRenderArray($form);
    $cacheability->addCacheableDependency($settings_config);

    // Reservation wiring is independent of the route calculator. Marking the
    // form before the quote-specific guard lets a valid reservation workflow
    // operate even when live distance calculation is not configured.
    $form['#attributes']['data-custom-webform-quote'] = $webform_id;

    // The service selector renders as radio cards on every load, before the
    // address-dependent guards below, because its styling never depends on a
    // route being priceable. This is CSS only: the server stays the source of
    // service eligibility and the native radios keep working without it.
    $form['#attached']['library'][] = 'custom_webform_handlers/service_cards';

    // The Courier row spacing loads on the same terms and for the same reason: it
    // is CSS over the layout containers the module's own update built, it carries
    // no behaviour, and the form is complete and ordered without it.
    $form['#attached']['library'][] = 'custom_webform_handlers/courier_layout';

    // Order the cards by what people actually book, which is presentation and stops
    // there. Placed before the address guards below because it must happen on every
    // render of the form, including the renders where no route can be priced and this
    // method returns early — a page that shows its services in one order when a quote
    // is possible and another when it is not would be worse than either order alone.
    $this->orderServiceCards($form, $webform_submission, $cacheability);

    $reservation = $this->reservationSettings($webform_id, $cacheability);
    if ($reservation !== []) {
      $form['#attached']['library'][] = 'custom_webform_handlers/reservation_slots';
    }

    $origin_field = trim((string) $this->configuration['origin_address_field']);
    $destination_field = trim((string) $this->configuration['destination_address_field']);
    if ($origin_field === '' || $destination_field === '') {
      // Without both address fields there is no route to price, so the marker,
      // the settings, and the libraries are all withheld. The client behaviors
      // then have nothing to attach to and build no UI.
      $this->loggerFactory->get('custom_webform_handlers')->warning('The quote experience was not attached to webform @webform because its origin and destination address fields are not both configured.', [
        '@webform' => $webform_id,
      ]);
      if ($reservation !== []) {
        $form['#attached']['drupalSettings']['custom_webform_handlers'] = [
          'webform_id' => $webform_id,
          'reservation' => $reservation,
        ];
      }
      $cacheability->applyTo($form);
      return;
    }

    // development_fixed makes no outbound request, so the browser must not load
    // Google Maps or Places either. Even with the config flag on, dev forces the
    // key empty here, so the route_map_preview library and key are never attached
    // below and js/distance_calculator.js never injects the Maps script. The
    // self-hosted autocomplete endpoint is independent of this and keeps working.
    $maps_enabled = !$this->routingModeResolver->isDevelopmentFixed()
      && (bool) $settings_config->get('google_maps_browser_enabled');
    $api_key = $maps_enabled
      ? trim((string) $settings_config->get('google_maps_browser_api_key'))
      : '';

    // Address suggestions never reach Google. The self-hosted endpoint may run
    // while the visitor types; only the committed-pair event emitted by the
    // attached behavior is allowed to reach the paid Google Routes provider.
    // Roles are explicit so JavaScript never guesses from field names.
    $suggestions_url = Url::fromRoute(
      'custom_webform_handlers.address_suggestions',
    )->toString(TRUE);
    $cacheability->addCacheableDependency($suggestions_url);
    $autocomplete_attached = FALSE;
    foreach ([
      'origin' => $origin_field,
      'destination' => $destination_field,
    ] as $role => $address_field) {
      $element = &$this->findElement($form, $address_field);
      if ($element === NULL) {
        continue;
      }
      $element['#attributes']['data-custom-address-suggestions-url'] =
        $suggestions_url->getGeneratedUrl();
      $element['#attributes']['data-custom-address-role'] = $role;
      $element['#attributes']['data-custom-address-commit-delay'] =
        $this->previewDebounceMs($settings_config);
      $element['#attributes']['autocomplete'] = 'street-address';
      $autocomplete_attached = TRUE;
      unset($element);
    }
    if ($autocomplete_attached) {
      $form['#attached']['library'][] = 'custom_webform_handlers/address_autocomplete';
    }

    $quote_context_key = 'custom_webform_handlers:' . $webform_id;
    if ($webform_submission && $webform_submission->uuid()) {
      $quote_context_key .= ':' . $webform_submission->uuid();
    }

    $field_names = [
      'origin' => $origin_field,
      'destination' => $destination_field,
      'distance' => $this->configuration['distance_field'],
      'distance_meters' => $this->configuration['distance_meters_field'],
      'quote_total' => $this->configuration['quote_total_field'] ?: 'final_calculated_quote',
      'quote_currency' => $this->configuration['quote_currency_field'],
      'quote_breakdown' => $this->configuration['quote_breakdown_field'],
      'quote_status' => $this->configuration['quote_status_field'],
      'quote_rate_version' => $this->configuration['quote_rate_version_field'],
    ];

    // The Dispatch Planner is the other scheduling layer, and the two are
    // mutually exclusive at runtime: dispatchSettings() returns nothing whenever
    // reservations are enabled, so at most one of the two ever attaches a library
    // or a settings key. It is resolved here, ahead of the published defaults,
    // because the facts the offer endpoint needs are what those defaults have to
    // carry.
    $dispatch = $this->dispatchSettings($webform_id, $cacheability);

    // Every operational fact a later wizard page may have to re-send: the priced
    // inputs, both addresses, and — when Dispatch is attached — each field its
    // Request maps. No contact or payment field can appear here by construction,
    // because the list is built from the pricing allowlist and the Dispatch field
    // map and from nothing else.
    $context_fields = array_merge(self::QUOTE_INPUT_FIELDS, [$origin_field, $destination_field]);
    if ($dispatch !== []) {
      $context_fields = array_merge($context_fields, (array) ($dispatch['value_fields'] ?? []));
    }
    $context_fields = array_values(array_unique(array_filter(
      $context_fields,
      static fn (mixed $field): bool => is_string($field) && $field !== '',
    )));

    $quote_defaults = [];
    $submission_data = $webform_submission ? $webform_submission->getData() : [];
    // A Webform wizard can rebuild its next page before it persists a draft
    // entity. During that handoff the FormState is the server-owned record of
    // Route & Service, so use it after stored submission data without reading
    // any unallowlisted customer or payment fields.
    //
    // These defaults cover the whole context allowlist and not just the priced
    // subset, because a fact that decides eligibility cannot depend on the
    // browser's own carry: Drupal mints a fresh submission UUID on every wizard
    // rebuild, so the session-storage key moves underneath the visitor between
    // page one and page two. A page-one answer that lives nowhere else — the
    // contents attestation and the Store Pickup confirmation are the ones that
    // bite — would then reach the offer endpoint as unanswered, and an unanswered
    // affirmation is refused, which is read at the far end as "no times are
    // available today". The server holds those answers regardless, so the server
    // is what hands them back.
    //
    // The one-driver fact used to be the worst case here and is no longer carried
    // at all: DispatchRequestFactory derives it from
    // self_bookable.single_driver_service_types, so its request_fields key is empty
    // and it appears in neither value_fields nor QUOTE_INPUT_FIELDS. There is
    // nothing left for a lost wizard context to lose.
    //
    // Each key is published only when this particular draft or form state
    // actually has it. Nothing is invented, and no value is read from browser
    // storage; the addresses in this list obey the same rule, which is what lets
    // later pages preview a route they no longer render.
    $wizard_values = $form_state->getValues();
    foreach ($context_fields as $key) {
      if (array_key_exists($key, $submission_data)) {
        $quote_defaults[$key] = $submission_data[$key];
      }
      if (array_key_exists($key, $wizard_values)) {
        $quote_defaults[$key] = $wizard_values[$key];
      }
    }

    // The sidebar renders the stored breakdown and needs its identity, storage
    // key, and field map whether or not live estimates are available.
    $settings = [
      'webform_id' => $webform_id,
      'quote_context_key' => $quote_context_key,
      'field_names' => $field_names,
      'quote_input_fields' => self::QUOTE_INPUT_FIELDS,
      'quote_defaults' => $quote_defaults,
    ];

    // Reservations are an independent layer: they attach with or without a
    // browser key, and a reservation failure never touches a quote field.
    if ($reservation !== []) {
      $settings['reservation'] = $reservation;
    }

    // Dispatch was resolved above, next to the defaults it decides the shape of.
    if ($dispatch !== []) {
      $form['#attached']['library'][] = 'custom_webform_handlers/dispatch_slots';
      $settings['dispatch'] = $dispatch;
    }

    // The quote behavior owns the short-lived browser context used to carry
    // wizard answers across pages. It names every mapped fact and not only the
    // priced ones, because the Courier journey asks for the route, the service and
    // the package on page one while the offer widget that has to send them is not
    // rendered until page two. That carry is a convenience now rather than the
    // only path between the two pages: the same facts are published as
    // server-side defaults above, so a browser that loses the context — a fresh
    // submission UUID, a cleared session, a second tab — still reaches the offer
    // endpoint with a complete Request.
    $settings['wizard_context_fields'] = $context_fields;

    $preview_url = Url::fromRoute('custom_webform_handlers.distance_preview')->toString(TRUE);
    $csrf_token_url = Url::fromRoute('system.csrftoken')->toString(TRUE);
    $cacheability->addCacheableDependency($preview_url);
    $cacheability->addCacheableDependency($csrf_token_url);

    $form['#attached']['library'][] = 'custom_webform_handlers/distance_calculator';
    $form['#attached']['library'][] = 'custom_webform_handlers/quote_summary_sidebar';
    $form['#attached']['drupalSettings']['custom_webform_handlers'] = $settings + [
      'preview_url' => $preview_url->getGeneratedUrl(),
      'csrf_token_url' => $csrf_token_url->getGeneratedUrl(),
      'preview_debounce_ms' => $this->previewDebounceMs($settings_config),
    ];
    if ($api_key !== '') {
      $form['#attached']['library'][] = 'custom_webform_handlers/route_map_preview';
      $form['#attached']['drupalSettings']['custom_webform_handlers']['google_maps_browser_api_key'] = $api_key;
    }
    $cacheability->applyTo($form);
  }

  /**
   * The address-commit debounce this site publishes to the browser.
   *
   * A committed address pair is what buys a route request, so this delay is a
   * cost control and not a cosmetic one: it decides how many commits a burst of
   * focus changes can produce. It is read from configuration so a site can widen
   * it without a code change, and clamped so it cannot be set to zero.
   */
  private function previewDebounceMs(ImmutableConfig $settings_config): int {
    $configured = $settings_config->get('preview_debounce_ms');
    $debounce = is_numeric($configured) ? (int) $configured : 0;
    if ($debounce <= 0) {
      $debounce = self::PREVIEW_DEBOUNCE_MS;
    }

    return max(
      self::PREVIEW_DEBOUNCE_MIN_MS,
      min(self::PREVIEW_DEBOUNCE_MAX_MS, $debounce),
    );
  }

  /**
   * Reorders the service selector's options by measured popularity.
   *
   * A permutation of #options and nothing else. Every option that was offered is still
   * offered, with the same key and the same label, so this cannot hide a service, make
   * an ineligible one selectable, or change what the server will accept — the reader's
   * own interface makes that structural, and this method adds no way around it.
   *
   * Skipped entirely once a submission exists. A resave, a preview, a multi-step form
   * returning to page one, or an administrator editing a saved job all arrive here with
   * a submission in hand, and in every one of those cases the visitor has already made
   * or been shown a choice. Moving the cards under them would be the one behaviour this
   * feature is not allowed to have, and the check is a single condition because that is
   * cheaper than any attempt to be clever about which of those cases is safe.
   *
   * The cache metadata is not optional. The order changes when the nightly snapshot is
   * written, and a form whose render cache did not know that would keep serving the
   * previous order until something unrelated invalidated it.
   */
  private function orderServiceCards(array &$form, ?WebformSubmissionInterface $webform_submission, CacheableMetadata $cacheability): void {
    // Whatever the answer turns out to be, this form's rendering now depends on the
    // snapshot and on the settings that decide whether the snapshot may be used.
    // Recorded before the early returns, because "the feature is off" is itself a state
    // that has to stop being cached when an operator turns it back on.
    $cacheability->addCacheTags([StateServicePopularityStore::CACHE_TAG]);
    $cacheability->addCacheableDependency(\Drupal::config('custom_webform_handlers.ranking'));

    if ($webform_submission !== NULL && !$webform_submission->isNew()) {
      return;
    }

    $element_key = $this->serviceTypeElement();

    if ($element_key === '') {
      return;
    }

    $element = &$this->findElement($form, $element_key);

    if ($element === NULL || !is_array($element['#options'] ?? NULL)) {
      return;
    }

    $options = $element['#options'];
    $ordered = $this->servicePopularity->order(array_map('strval', array_keys($options)));

    // Rebuilt by reading the ordering, then anything the ordering did not mention. The
    // second loop is the safety net: the reader is contracted to return a permutation,
    // and if a future implementation ever broke that contract this preserves the
    // unmentioned options at the end instead of dropping them from the form.
    $reordered = [];
    foreach ($ordered as $key) {
      if (array_key_exists($key, $options)) {
        $reordered[$key] = $options[$key];
      }
    }
    foreach ($options as $key => $label) {
      $reordered[$key] ??= $label;
    }

    $element['#options'] = $reordered;
  }

  /**
   * Finds a Webform element anywhere in a form array, by reference.
   *
   * By reference because the caller needs to modify the element in place, and by
   * recursion because a Webform element's depth is a property of how the form was
   * built: wizard pages, containers, fieldsets and flexbox layouts all nest, and a
   * shallow lookup would work on the current form and silently stop working the first
   * time someone dragged the selector into a container.
   *
   * Matched on #webform_key rather than on the array key. They are usually the same,
   * but #webform_key is the element's identity as Webform itself understands it, and it
   * is what the stored configuration and the submission data both use.
   *
   * @return array|null
   *   A reference to the element, or NULL. Returned by reference, so the NULL case is
   *   a real assignable variable rather than a literal.
   */
  private function &findElement(array &$form, string $key): ?array {
    $missing = NULL;

    foreach ($form as $name => &$child) {
      if (!is_array($child) || (is_string($name) && str_starts_with($name, '#'))) {
        continue;
      }

      if ((string) ($child['#webform_key'] ?? '') === $key) {
        return $child;
      }

      $found = &$this->findElement($child, $key);
      if ($found !== NULL) {
        return $found;
      }
      unset($found);
    }

    return $missing;
  }

  /**
   * Builds the reservation settings that are safe to publish to the browser.
   *
   * Only field names, public schedule constraints, and endpoint URLs travel to
   * the client. No hold token, database identifier, owner value, or availability
   * snapshot is published, so this can sit in a cached render array without
   * leaking state or going stale.
   *
   * @return array
   *   The settings, or an empty array when reservations are not usable.
   */
  private function reservationSettings(string $webform_id, CacheableMetadata $cacheability): array {
    if (!$this->reservationEnabled()) {
      return [];
    }

    // Whether the reservation layer attaches at all is decided by this handler's
    // configuration, which lives on the Webform. Without this dependency a form
    // rendered before reservations were configured keeps serving a cached copy
    // with no reservation behavior attached, and every taken time then looks
    // free to the visitor.
    $cacheability->addCacheableDependency($this->getWebform());

    try {
      $definition = $this->reservationDefinitionProvider->formDefinition($webform_id);
    }
    catch (ReservationException $exception) {
      $this->loggerFactory->get('custom_webform_handlers')->warning('Reservations are enabled on webform @webform but not usable: @code.', [
        '@webform' => $webform_id,
        '@code' => $exception->getReservationCode(),
      ]);
      return [];
    }

    $availability_url = Url::fromRoute('custom_webform_handlers.reservation_availability', ['webform' => $webform_id])->toString(TRUE);
    $calendar_url = Url::fromRoute('custom_webform_handlers.reservation_calendar', ['webform' => $webform_id])->toString(TRUE);
    $hold_url = Url::fromRoute('custom_webform_handlers.reservation_hold')->toString(TRUE);
    $renew_url = Url::fromRoute('custom_webform_handlers.reservation_renew')->toString(TRUE);
    $release_url = Url::fromRoute('custom_webform_handlers.reservation_release')->toString(TRUE);
    $csrf_url = Url::fromRoute('system.csrftoken')->toString(TRUE);
    foreach ([$availability_url, $calendar_url, $hold_url, $renew_url, $release_url, $csrf_url] as $generated) {
      $cacheability->addCacheableDependency($generated);
    }

    return [
      'field_names' => [
        'date' => $this->configuration['reservation_date_field'],
        'time' => $this->configuration['reservation_time_field'],
        'hold_token' => $this->configuration['reservation_hold_token_field'],
        'resource' => $this->configuration['reservation_resource_field'],
      ],
      'constraints' => $definition->publicConstraints(),
      'availability_url' => $availability_url->getGeneratedUrl(),
      'calendar_url' => $calendar_url->getGeneratedUrl(),
      'hold_url' => $hold_url->getGeneratedUrl(),
      'renew_url' => $renew_url->getGeneratedUrl(),
      'release_url' => $release_url->getGeneratedUrl(),
      'csrf_token_url' => $csrf_url->getGeneratedUrl(),
    ];
  }

  /**
   * Builds the dispatch settings that are safe to publish to the browser.
   *
   * The dispatch counterpart of reservationSettings(), and it observes the same
   * discipline: only element keys, non-secret public profile constraints, and
   * endpoint URLs travel to the client. No Hold token, database identifier,
   * owner value, Dispatch Window, or availability snapshot is published — the
   * token stays in the server-side session receipt, and every window the widget
   * can show is derived by the server per request. Publishing only this lets the
   * block sit in a cached render array without leaking state or going stale.
   *
   * It returns nothing unless dispatchEnabled() is true, which is false whenever
   * reservations are enabled, so this and reservationSettings() can never both
   * publish to the same form. A half-configured dispatch — missing the arrival,
   * marker, or either address element — is treated as not configured, exactly as
   * DispatchRequestFactory::settingsFor() treats it, because the booking
   * endpoints would refuse the Webform and a widget that can only fail is worse
   * than no widget.
   *
   * @return array
   *   The settings, or an empty array when dispatch is not usable here.
   */
  private function dispatchSettings(string $webform_id, CacheableMetadata $cacheability): array {
    if (!$this->dispatchEnabled()) {
      return [];
    }

    $slot_field = trim((string) $this->configuration['dispatch_slot_field']);
    $hold_marker_field = trim((string) $this->configuration['dispatch_hold_token_field']);
    $origin_field = trim((string) $this->configuration['origin_address_field']);
    $destination_field = trim((string) $this->configuration['destination_address_field']);
    if ($slot_field === '' || $hold_marker_field === '' || $origin_field === '' || $destination_field === '') {
      return [];
    }

    // Whether dispatch attaches, and against which constraints, is decided by
    // this handler's configuration and by the operating profile — both of which
    // can change after this form was first rendered. Without these dependencies a
    // form cached before dispatch was configured keeps serving a copy with no
    // widget, and one cached before the profile changed bounds its date control
    // and times its renewals against a stale policy.
    $cacheability->addCacheableDependency($this->getWebform());
    // The operating profile. Named by its literal config id rather than through a
    // Dispatch class constant, so publishing the browser settings adds no new
    // dependency on the booking internals; the server remains the only authority.
    $dispatch_config = \Drupal::config('custom_webform_handlers.dispatch');
    $cacheability->addCacheableDependency($dispatch_config);

    // The offer widget attaches only where an operator has actually transferred
    // this Webform to the Dispatch Planner. dispatchEnabled() and the complete
    // fields above establish that this handler *can* plan a route; presence in
    // the transfer list is what establishes that this form *has been moved* to do
    // so. BookingWorkflowGate is the authority on that decision and the offer
    // endpoint enforces it — a form absent from this list is refused with
    // 'not_configured', so attaching here would render a widget that can only
    // fail, the very case the half-configured guard above exists to avoid. Read
    // from the operating profile this method already loaded, by the same literal
    // key the gate uses, so the check adds no dependency on the booking internals
    // and the server stays the one authority; the endpoint, not this, enforces.
    $transferred = $dispatch_config->get('workflow.dispatch_webforms');
    $owns = FALSE;
    foreach (is_array($transferred) ? $transferred : [] as $listed) {
      if (is_scalar($listed) && trim((string) $listed) === $webform_id) {
        $owns = TRUE;
        break;
      }
    }
    if (!$owns) {
      return [];
    }

    // The public, non-secret operating constraints. Every value here already
    // describes the business as a whole, never the Resource's day or anybody's
    // booking: they only bound the date control the widget shows. The server
    // still decides what is actually offerable.
    $resource = (array) ($dispatch_config->get('resource') ?? []);
    $working_hours = is_array($resource['working_hours'] ?? NULL) ? $resource['working_hours'] : [];
    $working_weekdays = array_values(array_map('intval', array_keys($working_hours)));
    sort($working_weekdays);

    $constraints = [
      'timezone' => trim((string) ($resource['timezone'] ?? '')),
      'grid_minutes' => (int) ($resource['grid_minutes'] ?? 0),
      'booking_horizon_days' => (int) ($resource['booking_horizon_days'] ?? 0),
      'working_weekdays' => $working_weekdays,
      'pickup_promise_minutes' => (int) ($dispatch_config->get('durations.pickup_promise_minutes') ?? 0),
    ];

    $offer_url = Url::fromRoute('custom_webform_handlers.dispatch_offer', ['webform' => $webform_id])->toString(TRUE);
    $csrf_url = Url::fromRoute('system.csrftoken')->toString(TRUE);
    foreach ([$offer_url, $csrf_url] as $generated) {
      $cacheability->addCacheableDependency($generated);
    }

    // The whole per-service contract, resolved server-side from the one resolver
    // the offer endpoint assesses against. This is what lets a single widget serve
    // both a self-bookable service and a Manual Review one without the browser
    // holding a service list of its own.
    $profiles = $this->dispatchBookingProfiles($dispatch_config);

    // The element the operating profile nominates as the requested service date,
    // and the element it nominates for the selected arrival. Both come from the
    // resolved shell profile rather than from configuration read here a second
    // time, so the widget reads the same two controls the server does — the
    // duplication that once let the offered day and the submitted day drift apart.
    // An empty date means no element was nominated, and the widget then has no
    // date to read.
    $shell = $this->bookingProfiles->resolve('');
    $date_field = $shell->requestedDateField;

    // Fail closed on a disagreement about where the arrival is stored. Every
    // server-side read stays on this handler's own dispatch_slot_field, so there
    // is no split authority; but if the profile nominates a different element then
    // one of the two is wrong, and a widget writing to an element the submission
    // handler never reads is a booking the customer believes they made. No widget
    // is the safe answer, and the manual-review path still works.
    if ($shell->selectedSlotField !== $slot_field) {
      $this->loggerFactory->get('custom_webform_handlers')->error('Dispatch did not attach to Webform @webform: the booking profile nominates arrival element @profile but the handler stores @handler.', [
        '@webform' => $webform_id,
        '@profile' => $shell->selectedSlotField,
        '@handler' => $slot_field,
      ]);
      return [];
    }

    return [
      'field_names' => [
        // The browser writes only the canonical pickup-arrival instant here. The
        // server re-derives and re-proves the whole window from it and ignores
        // anything else the element might contain.
        'slot' => $slot_field,
        // A marker that an arrival is claimed, set to '1' while a Hold is live and
        // cleared otherwise. Never the token: the token lives in the session, and
        // the server reads it from there. It is deliberately reusing the
        // hold-token element key, which the server never reads for dispatch.
        'hold_marker' => $hold_marker_field,
        // The one public date field, read and never written by the widget. The
        // offer endpoint reads the same element from the submitted values and
        // refuses a request that names a different day, so this is a convenience
        // for the browser rather than a source of truth.
        'date' => $date_field,
        // The two address elements the offer and hold requests must carry, so the
        // widget can require them before asking and the server can rebuild the
        // route. Their values are collected with the rest, never trusted as facts.
        'origin' => $origin_field,
        'destination' => $destination_field,
      ],
      // Every element key whose live value the Offer and Hold requests must send,
      // so the server can rebuild the Dispatch Request exactly as it will rebuild
      // the saved submission. Keys only — never values, and nothing secret.
      'value_fields' => $this->dispatchValueFields($origin_field, $destination_field, $dispatch_config->get('request_fields')),
      // What each selectable service does, keyed by service type: whether Slots
      // are possible at all, which answers that service still needs, and the fixed
      // sentence to show when a person will schedule it. The widget renders one
      // shell for every service and reads this to know which of its two states it
      // is in, so no service name and no review wording is written in JavaScript.
      'booking_profiles' => $profiles,
      // Which element to read the chosen service from, so the widget can pick the
      // profile above without spelling the key itself.
      'service_type_field' => $this->serviceTypeElement(),
      // The element that already holds the authoritative quote. The widget only
      // displays it beside the choice; it never recomputes or writes a price.
      'price_field' => $this->configuration['quote_total_field'] ?: 'final_calculated_quote',
      'constraints' => $constraints,
      'offer_url' => $offer_url->getGeneratedUrl(),
      'csrf_token_url' => $csrf_url->getGeneratedUrl(),
    ];
  }

  /**
   * Lists the element keys whose live values a dispatch request must carry.
   *
   * Three sets, unioned. The two address elements, because the server rebuilds the
   * route from them. Every element the operating profile maps a planning fact to:
   * the request_fields map holds both single element keys and nested lists (an
   * add-ons group maps to several elements at once), so it is flattened, and empty
   * mappings — facts the Webform does not ask yet — contribute nothing. And every
   * pricing answer in QUOTE_INPUT_FIELDS, because an arrival is only ever offered
   * against the quote it was priced beside: when urgency or a package dimension
   * moves the price, the arrival the visitor picked is stale, and the widget both
   * watches these keys to drop that choice and sends their live values so the
   * server never plans against a figure the form has already changed.
   *
   * The profile maps only a subset of the pricing answers, and
   * DispatchRequestFactory reads only what the profile maps, so the unmapped keys
   * travel as facts the server ignores. Keys this Webform does not ask cost
   * nothing either — the browser's collector skips a name with no elements.
   * QUOTE_INPUT_FIELDS holds no slot, hold, or marker key, which is what keeps the
   * widget from ever collecting or storing arrival state of its own.
   */
  private function dispatchValueFields(string $origin_field, string $destination_field, mixed $request_fields): array {
    $fields = [$origin_field, $destination_field];
    if (is_array($request_fields)) {
      array_walk_recursive($request_fields, static function ($element) use (&$fields) {
        $element = trim((string) $element);
        if ($element !== '') {
          $fields[] = $element;
        }
      });
    }
    $fields = array_merge($fields, self::QUOTE_INPUT_FIELDS);

    return array_values(array_unique($fields));
  }

  /**
   * The element key that carries the chosen service type.
   *
   * One reader for a key three places used to spell for themselves. The operating
   * profile names the element, and 'service_type' is the fallback the card-ordering
   * seam already applied, kept so a profile that has not been installed yet still
   * finds the element every existing submission is stored under.
   */
  private function serviceTypeElement(): string {
    $key = trim((string) (\Drupal::config('custom_webform_handlers.dispatch')
      ->get('request_fields.service_type') ?? ''));

    return $key !== '' ? $key : 'service_type';
  }

  /**
   * Every service type this form can select, in no particular order.
   *
   * The union of what the form offers and what the operating profile declares. The
   * form's own options are the set the visitor can actually choose, so a service
   * present there and absent from the profile still gets a published answer — the
   * fail-closed one — rather than no answer at all, which is what would let the
   * widget treat an undeclared service as if it were bookable.
   *
   * @return array<int, string>
   *   The selectable and configured service machine names.
   */
  private function dispatchServiceTypes(ImmutableConfig $dispatch_config): array {
    $services = [];
    $add = static function (mixed $service) use (&$services): void {
      if (is_scalar($service) && trim((string) $service) !== '') {
        $services[trim((string) $service)] = TRUE;
      }
    };

    $elements = $this->getWebform()->getElementsDecodedAndFlattened();
    $options = $elements[$this->serviceTypeElement()]['#options'] ?? NULL;
    foreach (is_array($options) ? array_keys($options) : [] as $option) {
      $add($option);
    }
    foreach (['self_bookable.service_types', 'manual_review.service_types'] as $list) {
      $declared = $dispatch_config->get($list);
      foreach (is_array($declared) ? $declared : [] as $service) {
        $add($service);
      }
    }
    $profiles = $dispatch_config->get('booking_profiles.services');
    foreach (is_array($profiles) ? array_keys($profiles) : [] as $service) {
      $add($service);
    }

    return array_keys($services);
  }

  /**
   * What the browser is told about each service type, straight off its profile.
   *
   * The whole per-service contract the widget needs, in one published map, from the
   * one resolver the server assesses against. Before this the browser was told only
   * which services were self-bookable, as a bare list assembled here from
   * configuration keys this handler read for itself — so a Manual Review service
   * looked to the widget exactly like a service whose facts were merely unfinished,
   * and the widget's only way to find out was to post an offer request whose answer
   * was already decided.
   *
   * BookingProfile::toPublishedArray() decides what may cross to the browser, and
   * it withholds the eligibility policy id, the resource, the capacity limit and
   * the price-book key. Nothing here is an authority: the offer endpoint assesses
   * every request it receives against the same profile, so the worst a tampered
   * copy of this map can do is make the widget ask a question the server refuses.
   */
  private function dispatchBookingProfiles(ImmutableConfig $dispatch_config): array {
    $published = [];
    foreach ($this->dispatchServiceTypes($dispatch_config) as $service) {
      $published[$service] = $this->bookingProfiles->resolve($service)->toPublishedArray();
    }

    return $published;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state, WebformSubmissionInterface $webform_submission) {
    parent::validateForm($form, $form_state, $webform_submission);

    if ($webform_submission->isDraft()) {
      // A draft keeps only its Hold. It never becomes a Reservation, so there
      // is nothing to prove yet. True of both scheduling workflows.
      return;
    }

    // Dispatch and fixed-slot reservation are mutually exclusive workflows, and
    // they part ways here. A dispatch arrival is advisory checkout intent, so the
    // dispatch branch claims nothing at form time: the internal Hold is created
    // when checkout starts and the Reservation is confirmed by the payment-success
    // path. The fixed-slot guard below still proves its Hold before the submission
    // is accepted.
    if ($this->dispatchEnabled()) {
      // Order matters here. An arrival that the resolved profile forbids is
      // removed before anything that depends on that profile can read it, so no
      // profile-dependent check ever sees a value that is not allowed to exist.
      // Doing it the other way round — validate, then discard at preSave — left a
      // window in which a later check could compare a forbidden arrival against a
      // date and refuse the page over it.
      //
      // Advisory does not mean unchecked. For a service that may be booked, the
      // one thing that must be true of an advisory arrival is that it is an
      // arrival on the day the customer asked for, because that pair is what
      // checkout will later try to Hold.
      if (!$this->discardArrivalInFormState($form_state, $webform_submission)) {
        $this->assertDispatchArrivalMatchesRequestedDate($form_state, $webform_submission);
        if ($form_state->getErrors() === []) {
          $this->assertAcceptedDispatchQuote($form_state, $webform_submission);
        }
      }

      return;
    }

    if (!$this->reservationEnabled()) {
      return;
    }

    $token_field = (string) $this->configuration['reservation_hold_token_field'];
    $date_field = (string) $this->configuration['reservation_date_field'];
    $time_field = (string) $this->configuration['reservation_time_field'];
    $message = $this->t('That time slot is no longer held for you. Choose a time again to continue.');

    // Incomplete configuration cannot prove a Hold, so the submission is
    // refused rather than accepted unchecked. Reservations are enabled here, so
    // silently letting it through would sell a slot the server never claimed.
    if ($token_field === '' || $date_field === '' || $time_field === '') {
      $form_state->setErrorByName('', $message);
      return;
    }

    $date = trim((string) $form_state->getValue($date_field));
    $time = trim((string) $form_state->getValue($time_field));
    $submitted_token = trim((string) $form_state->getValue($token_field));
    $owner = $this->reservationOwnerId();

    // Nothing has been selected yet, so there is no Slot to prove. This is not
    // an edge case: validateForm() runs on EVERY page of a multi-page Webform,
    // so on a wizard it fires on each page before the one that carries the date
    // and time. Demanding a Hold there refused the page the visitor was on and
    // stopped them ever reaching the controls that would create one — the error
    // named a time slot on a page that offers no times.
    //
    // preSave() and postSave() apply the identical "both empty means nothing was
    // selected" rule, so a submission that really does choose no slot is still
    // accepted and still confirms nothing.
    if ($date === '' && $time === '') {
      return;
    }

    // Half a selection cannot describe a Slot, and without a session identity
    // nothing can be verified against one.
    if ($date === '' || $time === '' || $owner === '') {
      $form_state->setErrorByName($time_field, $message);
      return;
    }

    try {
      $definition = $this->reservationDefinitionProvider->definition(
        $this->getWebform()->id(),
        $this->reservationResourceValue($form_state),
      );
      $slot = $this->reservationDefinitionProvider->slot($definition, $date, $time);
    }
    catch (ReservationException) {
      $form_state->setErrorByName($time_field, $message);
      return;
    }

    $token = $this->resolvedReservationHoldToken($slot, $submitted_token);
    if ($token === '') {
      $form_state->setErrorByName($time_field, $message);
      return;
    }

    // The browser's hidden token proves nothing on its own: the Hold must still
    // exist, still be unexpired, and still belong to this session. The same is
    // true of a token recovered from the session receipt, which is checked here
    // identically.
    //
    // Nothing is written back into the submitted values. A receipt is this
    // server's own record, and copying it into FormState would turn a private
    // recovery into submission data — visible in the saved submission, exported
    // with it, and indistinguishable from a token the browser actually sent.
    // Every path that needs the token resolves it again from the same receipt.
    if (!$this->reservationManager->verifyHold($slot, $token, $owner)) {
      $form_state->setErrorByName($time_field, $message);
    }
  }

  /**
   * Requires the customer to accept a server-recomputed payable amount.
   *
   * The hidden preview is not price authority. It is used only as evidence of
   * which server-produced figure the customer saw before submitting. The route
   * and quote are recomputed here, before a Hold, Commerce order, or payment
   * authorization exists. A changed amount is written back into the normal quote
   * fields and the form is stopped once, so the customer can review and submit
   * the revised figure. A forged preview cannot lower the price: only equality
   * with the fresh server result permits the save to continue.
   */
  private function assertAcceptedDispatchQuote(FormStateInterface $form_state, WebformSubmissionInterface $submission): void {
    $slotField = trim((string) $this->configuration['dispatch_slot_field']);
    if ($slotField === '' || trim((string) $this->submittedValue($form_state, $submission, $slotField)) === '') {
      return;
    }

    $originField = trim((string) $this->configuration['origin_address_field']);
    $destinationField = trim((string) $this->configuration['destination_address_field']);
    $breakdownField = trim((string) $this->configuration['quote_breakdown_field']);
    if ($originField === '' || $destinationField === '' || $breakdownField === '') {
      $form_state->setErrorByName('', $this->t('The current price could not be verified. Please contact us before continuing.'));
      return;
    }

    $origin = trim((string) $this->submittedValue($form_state, $submission, $originField));
    $destination = trim((string) $this->submittedValue($form_state, $submission, $destinationField));
    if ($origin === '' || $destination === '') {
      return;
    }

    // Apply the same server-owned Courier defaults preSave() applies, so the
    // accepted figure and the figure persisted moments later cannot disagree.
    $this->canonicalizeCourierInputs($submission);

    $quoteInput = $submission->getData();

    try {
      $distance = $this->distanceProvider->calculate($origin, $destination);
      $quote = $this->quoteCalculator->calculate($quoteInput, $distance);
    }
    catch (\Throwable $exception) {
      // The normal preSave path records this as Manual Review. Do not invent a
      // payable quote or expose provider detail from validation.
      return;
    }

    if ($quote->status() === 'manual_review' || $quote->totalCents() < 1) {
      return;
    }

    $acceptedRaw = $this->submittedValue($form_state, $submission, $breakdownField);
    $accepted = NULL;
    if (is_string($acceptedRaw) && $acceptedRaw !== '') {
      try {
        $decoded = json_decode($acceptedRaw, TRUE, 16, JSON_THROW_ON_ERROR);
        $accepted = is_array($decoded) ? $decoded : NULL;
      }
      catch (\JsonException) {
        $accepted = NULL;
      }
    }

    $matches = $accepted !== NULL
      && (int) ($accepted['total_cents'] ?? -1) === $quote->totalCents()
      && strtoupper(trim((string) ($accepted['currency'] ?? ''))) === $quote->currency()
      && trim((string) ($accepted['rate_version'] ?? '')) === $quote->rateVersion();
    if ($matches) {
      return;
    }

    $quoteData = $quote->toArray();
    $encoded = json_encode($quoteData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (is_string($encoded)) {
      $form_state->setValue($breakdownField, $encoded);
      $this->setSubmissionValue($submission, $breakdownField, $encoded);
    }
    $this->setAcceptedQuoteFields($form_state, $submission, $quoteData, $distance->distanceMeters());

    $form_state->setErrorByName(
      $breakdownField,
      $this->t('Your estimate has been updated to @currency @amount. Review the revised estimate and submit again to accept it.', [
        '@currency' => $quote->currency(),
        '@amount' => $quote->totalDecimal(),
      ]),
    );
  }

  /**
   * Writes one recomputed quote back through the configured Webform fields.
   */
  private function setAcceptedQuoteFields(FormStateInterface $form_state, WebformSubmissionInterface $submission, array $quote, int $distanceMeters): void {
    $values = [
      $this->configuration['distance_meters_field'] => $distanceMeters,
      ($this->configuration['quote_total_field'] ?: 'final_calculated_quote') => (string) ($quote['total'] ?? ''),
      $this->configuration['quote_currency_field'] => (string) ($quote['currency'] ?? ''),
      $this->configuration['quote_status_field'] => (string) ($quote['status'] ?? ''),
      $this->configuration['quote_rate_version_field'] => (string) ($quote['rate_version'] ?? ''),
    ];
    foreach ($values as $field => $value) {
      $field = trim((string) $field);
      if ($field === '') {
        continue;
      }
      $form_state->setValue($field, $value);
      $this->setSubmissionValue($submission, $field, $value);
    }
  }

  /**
   * Reads the current page first and then the submission's wizard state.
   */
  private function submittedValue(FormStateInterface $form_state, WebformSubmissionInterface $submission, string $field): mixed {
    $value = $form_state->getValue($field);

    return $value ?? $submission->getElementData($field);
  }

  /**
   * {@inheritdoc}
   */
  public function postSave(WebformSubmissionInterface $webform_submission, $update = TRUE) {
    parent::postSave($webform_submission, $update);

    if (!$webform_submission->isCompleted()) {
      // Drafts and incomplete submissions retain only a Hold. True of both
      // scheduling workflows.
      return;
    }

    // Only the save that creates the submission confirms a Hold. A later update
    // or resave must never be asked for a token again: the Hold it presented was
    // legitimately consumed when it was first confirmed, so demanding it a second
    // time would refuse every ordinary administrative edit of a booking that is
    // already reserved. This is checked before either workflow's branch because
    // it is equally true of both.
    if ($update) {
      return;
    }

    // For the fixed-slot workflow this is the one place a Reservation is created:
    // not in a request a browser can make, because confirming needs the
    // submission's own identifier — which exists only once the initial completed
    // save has written the row. Dispatch no longer confirms here; its capacity is
    // committed by the Commerce payment-success path, and a completed submission
    // records the arrival only as checkout intent.
    //
    // The Manual Review announcement still belongs here rather than where the
    // review flag is written: preSave() writes that flag before the row exists, and
    // an announcement naming a submission that may yet be rolled back would be an
    // announcement about nothing.
    if ($this->dispatchEnabled()) {
      // Dispatch capacity is confirmed only by the Commerce payment-success path.
      // A selected arrival is stored as checkout intent, not as a Reservation.
      $this->announceDispatchManualReview($webform_submission);
      return;
    }

    if (!$this->reservationEnabled()) {
      return;
    }

    $date_field = (string) $this->configuration['reservation_date_field'];
    $time_field = (string) $this->configuration['reservation_time_field'];
    $token_field = (string) $this->configuration['reservation_hold_token_field'];
    $resource_field = (string) $this->configuration['reservation_resource_field'];

    $date = $date_field === '' ? '' : trim((string) $webform_submission->getElementData($date_field));
    $time = $time_field === '' ? '' : trim((string) $webform_submission->getElementData($time_field));
    $submitted_token = $token_field === '' ? '' : trim((string) $webform_submission->getElementData($token_field));
    $resource = $resource_field === '' ? '' : trim((string) $webform_submission->getElementData($resource_field));

    if ($date === '' && $time === '') {
      // No slot was selected, so there is nothing to reserve and nothing to
      // fail. This is the only legitimate way a completed submission reaches
      // here without a token.
      return;
    }

    if ($date === '' || $time === '') {
      // Half a selection cannot describe a Slot. Returning quietly here is what
      // let a completed submission claim a date and time while reserving
      // nothing, leaving the slot bookable by the next visitor.
      throw new ReservationException(ReservationException::HOLD_NOT_FOUND);
    }

    // The same canonical Slot the guard derived, derived the same way, so the
    // receipt is looked up under the identical active slot key.
    $definition = $this->reservationDefinitionProvider->definition(
      $this->getWebform()->id(),
      $resource,
    );
    $slot = $this->reservationDefinitionProvider->slot($definition, $date, $time);

    $token = $this->resolvedReservationHoldToken($slot, $submitted_token);
    if ($token === '') {
      // A slot was selected but no Hold token came with it and no receipt covers
      // it. validateForm() blocks this on the submission form; this path also
      // closes it for saves that never run form validation — an administrator
      // adding a submission, a programmatic save, or an import.
      throw new ReservationException(ReservationException::HOLD_NOT_FOUND);
    }

    // Confirmation failure is not swallowed. A completed submission whose Hold
    // could not be confirmed is not a reservation, and logging that quietly
    // would sell the same slot twice while both visitors saw a confirmation.
    // confirm() is idempotent for this submission, so a repeated postSave() is
    // not mistaken for a failure.
    $this->reservationManager->confirm(
      $token,
      (int) $webform_submission->id(),
    );

    // The Hold is now a Reservation, so its receipt has nothing left to
    // reproduce. Dropping it keeps a consumed token out of the session.
    $this->reservationOwner->forgetHold($token);
  }

  /**
   * Sends a freshly saved dispatch submission to payment, or to its own tracker.
   *
   * This is the redirect seam, and it is confirmForm() rather than postSave()
   * for one structural reason: postSave() runs inside save() and is handed no
   * FormState, so it cannot change where the submission form sends the visitor.
   * confirmForm() runs after save() has written the row — so the submission has
   * an id and its stored quote and distance — and after the form set its own
   * default confirmation redirect, so a setRedirectUrl() here overrides that
   * default. postSave() keeps its work (recording checkout intent, announcing
   * Manual Review); this adds the redirect when there is a payable order to redirect
   * to, and when there is not, hands over the tracking link that postSave() minted.
   * Both are outputs of the same fact — whether this job has a price — so they belong
   * to one decision and not to two screens.
   */
  public function confirmForm(array &$form, FormStateInterface $form_state, WebformSubmissionInterface $webform_submission) {
    parent::confirmForm($form, $form_state, $webform_submission);

    // Only a freshly completed submission starts checkout. A draft, an unsaved
    // rebuild, or a later administrative edit (STATE_UPDATED, where completed and
    // changed diverge) must never be redirected to payment — the same scope
    // postSave() enforces with isCompleted() and !$update, expressed here where
    // no $update flag is passed.
    if ($webform_submission->getState() !== WebformSubmissionInterface::STATE_COMPLETED) {
      return;
    }

    // Dispatch is the only workflow that pays. dispatchEnabled() is already false
    // whenever the fixed-slot reservation workflow owns this Webform, so this one
    // check both scopes the redirect to dispatch and leaves reservations
    // untouched.
    if (!$this->dispatchEnabled()) {
      return;
    }

    // The starter is the sole authority on whether this submission becomes a
    // payable order. It answers with a checkout URL, or NULL — and NULL is an
    // ordinary refusal (no arrival chosen, a manual-review quote, an address that
    // no longer routes), for which the default confirmation the form already set
    // is the right outcome. Only a URL overrides it.
    $url = $this->checkoutStarter->start($webform_submission);
    if ($url !== NULL) {
      $form_state->setRedirectUrl($url);

      return;
    }

    // No payable order, which for a manual-review job is the expected refusal: there
    // is no price yet, so there is nothing to pay. What that customer is owed instead
    // is the way back to their own job, and this is the only moment it can be given.
    $this->showTrackingCode();
  }

  /**
   * Hands the customer the one link to their own request, once.
   *
   * This is specification item 4's "shown only to its customer" at its narrowest. The
   * raw code exists in exactly one request — the one whose save minted it — and this
   * is the end of that request. It goes out as a status message rather than into the
   * confirmation template because a message survives whichever confirmation the
   * Webform is configured for: an inline confirmation, a separate confirmation page, a
   * redirect to another URL. All of them show session messages, and the alternative
   * would be a credential that appears on some sites and silently does not on others.
   *
   * Nothing is stored and nothing is logged. The property is cleared as it is read, so
   * a second call in the same request — which no ordinary flow makes, but a resave or
   * an embedded form could — cannot show the code a second time.
   *
   * The link is deliberately the whole of the message. No price, because there is not
   * one yet; no address, no order, and no internal identifier, which is item 9: this
   * text is composed while nobody has been authenticated, so it may say only what the
   * person in front of the screen has just this moment typed in themselves.
   */
  private function showTrackingCode(): void {
    $code = $this->issuedTrackingCode;
    $this->issuedTrackingCode = NULL;

    if ($code === NULL || $code === '') {
      return;
    }

    // Absolute, because this is the link a customer is expected to keep: pasted into a
    // note, mailed to themselves, opened tomorrow on a different device. A relative
    // path is none of those things.
    $tracker = Url::fromRoute('custom_webform_handlers.review_track', ['code' => $code])
      ->setAbsolute()
      ->toString();

    $this->messenger()->addStatus($this->t('Your request needs a member of staff to price it, so no payment has been taken. Keep this link — it is the only way back to this job, it is yours alone, and it will show you the price as soon as it has been set: @link', [
      '@link' => $tracker,
    ]));
  }

  /**
   * Refuses an advisory dispatch arrival that is not on the requested date.
   *
   * The date the customer chose and the arrival instant they clicked are two
   * separate stored answers, produced by two different controls, and until this
   * existed nothing on the server compared them. The widget reads the date element
   * and the offer endpoint refuses a day that element does not carry, but neither
   * is a guarantee at submit time: the arrival element is a plain hidden field, and
   * a stale value can survive a date change that the browser did not process.
   *
   * So the pair is proved here, from the values the submission will actually store,
   * in the Resource's own timezone — the same zone the planner used when it offered
   * the instant. Comparing calendar dates in any other zone would fail a legitimate
   * late-evening or early-morning arrival.
   *
   * Deliberately not a Hold check, and deliberately silent when either half is
   * absent. An arrival is advisory: choosing none is allowed, and a wizard page
   * that carries neither element has nothing to disagree about. This refuses one
   * thing only — a contradiction between two answers the customer did give.
   *
   * A service whose profile is not self-bookable never reaches this check: its
   * arrival is removed first, by discardArrivalInFormState() on the form path and
   * by discardArrivalOutsideSelfBooking() on every other save path. That is
   * deliberate rather than incidental. Such an arrival is not a contradiction to
   * correct but a value that must not exist at all, and an error telling the
   * visitor to pick a different time would name a control that is not on the page
   * and trap them on it. The early return below is the second lock on the same
   * door, for a caller that reaches here another way.
   */
  private function assertDispatchArrivalMatchesRequestedDate(FormStateInterface $form_state, WebformSubmissionInterface $submission): void {
    $slot_field = trim((string) $this->configuration['dispatch_slot_field']);
    if ($slot_field === '') {
      return;
    }

    // The current page first, then what earlier pages already stored: a wizard
    // validates one page at a time, and on the last page neither element renders
    // while both answers exist.
    $read = static function (string $key) use ($form_state, $submission): string {
      $value = $form_state->getValue($key);
      if ($value === NULL) {
        $value = $submission->getElementData($key);
      }

      return is_scalar($value) ? trim((string) $value) : '';
    };

    // Which date element to compare against is the profile's answer, not a
    // configuration key read here for a second time. Resolved from the service the
    // submission actually carries, so a service that nominates no date element has
    // nothing to compare and says so once.
    $profile = $this->bookingProfiles->resolve($read($this->serviceTypeElement()));
    if (!$profile->isSelfBookable()) {
      return;
    }

    $date_field = $profile->requestedDateField;
    if ($date_field === '') {
      return;
    }

    $arrival = $read($slot_field);
    if ($arrival === '' || !ctype_digit($arrival)) {
      // No arrival chosen, or nothing that can be an instant. Advisory means the
      // first is allowed; the second cannot be compared, and the checkout path
      // re-derives the whole window from this value before it claims anything.
      return;
    }

    $profile = $this->dispatchProfileFactory->create();
    $timezone = $profile !== NULL ? trim((string) $profile->timezone) : '';
    if ($timezone === '') {
      // Without the operating zone there is no calendar day to compare against.
      // The offer endpoint already refused every request under this condition, so
      // there is nothing here that a stricter reading would protect.
      return;
    }

    $requested = $read($date_field);
    if ($requested === '') {
      $form_state->setErrorByName($date_field, $this->t('Choose your preferred service date, then pick one of the pickup times offered for it.'));

      return;
    }

    try {
      $arrival_date = (new \DateTimeImmutable('@' . $arrival))
        ->setTimezone(new \DateTimeZone($timezone))
        ->format('Y-m-d');
    }
    catch (\Exception) {
      return;
    }

    if ($arrival_date !== $requested) {
      $form_state->setErrorByName($date_field, $this->t('The pickup time you chose is not on @requested. Pick one of the times offered for your preferred service date.', [
        '@requested' => $requested,
      ]));
    }
  }

  /**
   * Drops a forbidden arrival at validation time, before anything reads it.
   *
   * The form-time half of discardArrivalOutsideSelfBooking(). Both exist, and both
   * are needed: this one runs first and keeps the value out of every
   * profile-dependent check on the way through the form, while the preSave one
   * covers the save paths that never run a form callback — a programmatic save, an
   * import, a resave.
   *
   * Nothing is reported to the visitor, for the same reason as at preSave: a
   * Manual Review service renders no Slot buttons, so an error telling somebody to
   * choose a different time would name a control that is not on their page.
   *
   * @return bool
   *   TRUE when this service forbids an arrival — whether or not one was found to
   *   remove — so the caller can skip the checks that only apply to a service
   *   whose arrivals are real.
   */
  private function discardArrivalInFormState(FormStateInterface $form_state, WebformSubmissionInterface $submission): bool {
    $slot_field = trim((string) $this->configuration['dispatch_slot_field']);
    if ($slot_field === '') {
      return FALSE;
    }

    // The current page first, then what earlier pages already stored: a wizard
    // validates one page at a time, and the page carrying the service type is not
    // the page carrying the arrival.
    $read = static function (string $key) use ($form_state, $submission): string {
      $value = $form_state->getValue($key);
      if ($value === NULL) {
        $value = $submission->getElementData($key);
      }

      return is_scalar($value) ? trim((string) $value) : '';
    };

    $service = $read($this->serviceTypeElement());
    if ($this->bookingProfiles->resolve($service)->isSelfBookable()) {
      return FALSE;
    }

    $fields = [$slot_field, trim((string) $this->configuration['dispatch_hold_token_field'])];
    $cleared = [];
    foreach ($fields as $field) {
      if ($field === '') {
        continue;
      }
      // Form state is only touched where the element is actually part of this
      // page's values. Writing a value for an off-page element would add data the
      // page never carried, which is a different bug from the one being fixed; the
      // stored answer below is the authoritative copy either way.
      if ($form_state->getValue($field) !== NULL && trim((string) $form_state->getValue($field)) !== '') {
        $form_state->setValue($field, '');
        $cleared[] = $field . ':form-state';
      }
      if ($this->elementExists($submission, $field)) {
        $stored = $submission->getElementData($field);
        if (is_scalar($stored) && trim((string) $stored) !== '') {
          $submission->setElementData($field, '');
          $cleared[] = $field . ':stored';
        }
      }
    }

    if ($cleared !== []) {
      $this->loggerFactory->get('custom_webform_handlers')->warning('Discarded a selected pickup time submitted for @service at validation, which is not self-bookable: @cleared.', [
        '@service' => $service !== '' ? $service : '(none chosen)',
        '@cleared' => implode(', ', $cleared),
      ]);
    }

    return TRUE;
  }

  /**
   * Drops a stored arrival for a service whose profile forbids one.
   *
   * The arrival element is a plain hidden field, so its value is whatever the last
   * successful write left in it — and a service change is exactly the case where
   * that write belongs to a different service. The widget clears it in the browser;
   * this is the server saying the same thing about a value that arrived anyway,
   * whether because a request was crafted, a page was restored from cache, or the
   * clearing script never ran.
   *
   * Not a validation error, because there is nothing for the visitor to fix: a
   * Manual Review service renders no Slots, so the honest outcome is a submission
   * with no arrival on it, which is what a request awaiting staff scheduling is.
   * Refusal proper lives at the booking endpoints, which now assess eligibility
   * before creating a Hold; this only keeps the stored answer truthful, so no
   * later reader — the review board, the checkout starter — can mistake a leftover
   * value for a time the customer was offered.
   *
   * Runs on every save path rather than only the form one, because a programmatic
   * save, an import, or a resave never runs a form callback.
   */
  private function discardArrivalOutsideSelfBooking(WebformSubmissionInterface $submission): void {
    if (!$this->dispatchEnabled()) {
      return;
    }

    $slot_field = trim((string) $this->configuration['dispatch_slot_field']);
    if ($slot_field === '' || !$this->elementExists($submission, $slot_field)) {
      return;
    }

    $service_element = $this->serviceTypeElement();
    $service = $service_element !== ''
      ? trim((string) $submission->getElementData($service_element))
      : '';
    if ($this->bookingProfiles->resolve($service)->isSelfBookable()) {
      return;
    }

    $arrival = $submission->getElementData($slot_field);
    if (is_scalar($arrival) && trim((string) $arrival) !== '') {
      $submission->setElementData($slot_field, '');
      // The marker says "an arrival is claimed". With no arrival there is nothing
      // to claim, and leaving it set would tell the checkout path a Hold exists.
      $marker_field = trim((string) $this->configuration['dispatch_hold_token_field']);
      if ($marker_field !== '' && $this->elementExists($submission, $marker_field)) {
        $submission->setElementData($marker_field, '');
      }

      $this->loggerFactory->get('custom_webform_handlers')->warning('Discarded a selected pickup time submitted for @service, which is not self-bookable.', [
        '@service' => $service !== '' ? $service : '(none chosen)',
      ]);
    }
  }

  /**
   * Refuses to persist a selected Slot that this session does not already hold.
   *
   * The validateForm() method covers the submission form, but it is a form callback: an
   * administrator adding a submission, a programmatic save, an import, or a
   * resave never runs it. postSave() catches those too, but only after Drupal
   * has already written the row — the submission exists while the Slot does not.
   * This guard runs before persistence, so an unreserved selected Slot never
   * becomes a stored submission in the first place.
   *
   * Nothing is re-implemented here: canonicalisation stays in
   * ReservationDefinitionProvider, identity in ReservationOwner, and the hold
   * check in the reservation manager. Their own exceptions are allowed to
   * propagate, which is what makes every invalid, expired, foreign-owner,
   * unavailable, or malformed condition fail closed.
   *
   * @throws \Drupal\custom_webform_handlers\Reservation\ReservationException
   */
  private function assertReservationHoldBeforeSave(WebformSubmissionInterface $submission): void {
    // New, non-draft submissions only. isDraft() is used rather than
    // isCompleted() because the completed timestamp is stamped during save, so
    // it cannot be relied on at this point; a draft keeps only its Hold, and a
    // resave of an existing submission must not be re-verified against a Hold
    // that was legitimately consumed when it was first confirmed.
    if (!$this->reservationEnabled() || !$submission->isNew() || $submission->isDraft()) {
      return;
    }

    $date_field = (string) $this->configuration['reservation_date_field'];
    $time_field = (string) $this->configuration['reservation_time_field'];
    $token_field = (string) $this->configuration['reservation_hold_token_field'];
    $resource_field = (string) $this->configuration['reservation_resource_field'];

    $date = $date_field === '' ? '' : trim((string) $submission->getElementData($date_field));
    $time = $time_field === '' ? '' : trim((string) $submission->getElementData($time_field));
    $submitted_token = $token_field === '' ? '' : trim((string) $submission->getElementData($token_field));
    $resource = $resource_field === '' ? '' : trim((string) $submission->getElementData($resource_field));

    if ($date === '' && $time === '') {
      // Nothing was selected, so there is nothing to reserve. This is the only
      // legitimate way a completed submission passes here without a Hold.
      return;
    }

    // Half a selection cannot describe a Slot. The token is checked after the
    // Slot exists, because the server's receipt is keyed by the Slot.
    if ($date === '' || $time === '') {
      throw new ReservationException(ReservationException::HOLD_NOT_FOUND);
    }

    $owner = $this->reservationOwnerId();
    if ($owner === '') {
      throw new ReservationException(ReservationException::HOLD_NOT_FOUND);
    }

    $definition = $this->reservationDefinitionProvider->definition(
      $this->getWebform()->id(),
      $resource,
    );
    $slot = $this->reservationDefinitionProvider->slot($definition, $date, $time);

    // A selection with no token anywhere — not submitted, and no receipt for
    // this Slot — describes a Slot nobody holds.
    $token = $this->resolvedReservationHoldToken($slot, $submitted_token);
    if ($token === '') {
      throw new ReservationException(ReservationException::HOLD_NOT_FOUND);
    }

    if (!$this->reservationManager->verifyHold($slot, $token, $owner)) {
      throw new ReservationException(ReservationException::HOLD_NOT_FOUND);
    }
  }

  /**
   * Returns the session identity a Hold is bound to.
   *
   * Resolved through the shared owner service so the identity a Hold was taken
   * with over AJAX is the same one this submission is checked against.
   */
  private function reservationOwnerId(): string {
    return $this->reservationOwner->ownerId();
  }

  /**
   * Resolves the Hold token to check, preferring what the browser submitted.
   *
   * The submitted value wins whenever there is one, so the ordinary path is
   * unchanged. It is only when the browser has nothing that the server's own
   * receipt for this exact Slot is used — the case where a Webform AJAX rebuild
   * replaced the hidden element before the Hold response reached it, leaving the
   * Hold alive and owned but unrepresented in the submitted values.
   *
   * This resolves a token; it never authorises anything. The token still has to
   * survive verifyHold(), which requires the Hold to exist, to be unexpired, to
   * be on this exact Slot, and to belong to this session. A receipt for a Hold
   * that lapsed, was released, or was already confirmed is refused exactly as an
   * absent token is.
   */
  private function resolvedReservationHoldToken(ReservationSlot $slot, string $submittedToken): string {
    return $submittedToken !== ''
      ? $submittedToken
      : $this->reservationOwner->holdToken($slot);
  }

  /**
   * Returns the submitted resource value, or an empty string.
   */
  private function reservationResourceValue(FormStateInterface $form_state): string {
    $field = (string) $this->configuration['reservation_resource_field'];
    if ($field === '') {
      return '';
    }
    return trim((string) $form_state->getValue($field));
  }

  /**
   * Tells whether the Dispatch Planner is the guard for this Webform.
   *
   * Deliberately not DispatchRequestFactory::dispatchOwns(). That method answers
   * a different question — "can dispatch plan here" — and requires complete
   * configuration to say yes, which is right for planning and wrong for
   * enforcing: a half-configured form would report "dispatch does not own this"
   * and switch the guard off, which is precisely when a guard is needed. So the
   * gate is the local switch plus the same one-directional interlock, and
   * incomplete configuration fails closed below rather than opting out.
   *
   * It cannot disagree with the shared definition about which workflow enforces,
   * because both express the interlock the same way: dispatch enforces only while
   * the fixed-slot guard does not. Where they differ, this one is the stricter of
   * the two, and being stricter is safe in a guard.
   */
  private function dispatchEnabled(): bool {
    return !empty($this->configuration['dispatch_enabled']) && !$this->reservationEnabled();
  }

  /**
   * Opens the review for a job that finished unplanned, and announces it.
   *
   * Two things in one place because they are one event. The same test the board's
   * Manual Review queue applies, read from the element the same handler configuration
   * names, decides both: a request is opened exactly when a queue entry appears, and
   * a message is enqueued exactly then too. Anything looser and staff would be told
   * about work that is not on their list, or find work on it nobody was told about —
   * or, worse for the customer, find a queue entry with no request behind it and so
   * nothing they can be answered through.
   *
   * Reached only when confirmation reported nothing to confirm, which is the shape a
   * job needing staff arrives in: eligibility declines it, so no arrival is ever
   * offered, so the submission completes holding none. A Hold that existed and was
   * refused never reaches here — that throws, and the submission does not become a
   * booking at all.
   *
   * The reason is fixed text rather than the recorded quote reason, deliberately.
   * Those reasons can carry a distance provider's own message, which is a third
   * party's text about a customer's addresses, and the safe list of what may be
   * shown is private to the board. Restating that list here would put a second copy
   * of one rule in the module for the two to drift apart. Saying only that the
   * request awaits approval cannot leak anything, and an operator still sees the
   * recorded detail when they open the queue.
   */
  private function announceDispatchManualReview(WebformSubmissionInterface $submission): void {
    $statusField = trim((string) $this->configuration['quote_status_field']);
    if ($statusField === '') {
      // This Webform stores no quote status, so there is no Manual Review state to
      // read — and nothing that would reach the board either.
      return;
    }

    $status = $submission->getElementData($statusField);
    if (!is_string($status) || $status !== DispatchManualReviewEntry::STATUS_MANUAL_REVIEW) {
      // A completed submission with no arrival and no review flag is a Webform
      // that simply carries no dispatch selection. Nothing happened to announce.
      return;
    }

    // The request itself, before anybody is told about it, and before the operating
    // profile is looked at. This is specification item 6 taken literally: the board's
    // Manual Review queue and the Review Request are the same work, so the moment that
    // produces a queue entry is the moment that opens the request, and there is never
    // a second queue to reconcile. Ordered first for two reasons — a notification that
    // names work no operator can open is worse than one that arrives a moment later,
    // and a review is about a submission rather than about a Resource, so a site with
    // no operating profile configured must still owe this customer an answer.
    //
    // open() is idempotent on the submission, so a resave adds nothing and re-issues
    // nothing. The credential comes back only from the call that created the row, and
    // is held for confirmForm() to show to the browser that submitted — the only
    // party it may ever be shown to.
    try {
      $issue = $this->reviews->open((string) $this->getWebform()->id(), (int) $submission->id());
      if ($issue !== NULL && $issue->isNew()) {
        $this->issuedTrackingCode = $issue->rawCode;
      }
    }
    catch (\Throwable $exception) {
      // Not fatal, and deliberately not rethrown. The submission is already saved and
      // its quote already carries the manual-review status the board reads, so staff
      // still see this job with or without a review row; what is lost is the
      // customer's own page, and the customer is better served by a confirmation that
      // omits a tracking link than by a save that fails after their job was recorded.
      // The type is logged and the message is not, because a storage exception can
      // quote the values it was given.
      $this->loggerFactory->get('custom_webform_handlers')->error('A Review Request could not be opened for submission @sid: @type.', [
        '@sid' => (int) $submission->id(),
        '@type' => get_class($exception),
      ]);
    }

    $profile = $this->dispatchProfileFactory->create();
    if ($profile === NULL) {
      // No operating profile means no Resource this announcement can be about. The
      // board already reports that misconfiguration; an announcement naming an
      // invented Resource would report it wrongly. The request above is already open,
      // so the work is not lost — only the message about it is.
      return;
    }

    // Enqueued, not sent, and outside every lock: this runs after the submission's
    // own save and touches no planner, so there is no capacity transaction open and
    // no guard row held. The submission is named so an operator can open the request
    // from the board, and nothing else about the customer travels with it.
    $this->dispatchNotifier->notify(DispatchNotification::manualReview(
      $profile->resourceId,
      $profile->timezone,
      $this->dispatchTime->getRequestTime(),
      (int) $submission->id(),
      (string) $this->getWebform()->id(),
      'awaiting staff approval',
    ));
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(WebformSubmissionInterface $submission) {
    // First, before anything is written and before the quote path can return
    // early: a fixed-slot reservation selection must already be held by this
    // session. Placing this after the address check below would let a submission
    // with no route addresses skip the reservation guard entirely.
    //
    // Only the fixed-slot guard runs here. A dispatch arrival is advisory checkout
    // intent, not a Hold to prove at save: the internal Hold is created when
    // checkout starts and the Reservation is confirmed by the payment-success path,
    // so there is nothing for dispatch to assert before persistence. The guard
    // returns immediately unless the fixed-slot workflow owns this Webform.
    $this->assertReservationHoldBeforeSave($submission);

    $origin = $submission->getElementData($this->configuration['origin_address_field']);
    $destination = $submission->getElementData($this->configuration['destination_address_field']);
    $this->clearComputedValues($submission);

    // Canonicalise the simplified Courier inputs before the quote is calculated,
    // so the figure priced below, the status stored, and everything the checkout
    // and Manual Review paths later read all see the server's own values rather
    // than whatever the browser submitted for fields the Courier UI no longer
    // shows. This is a no-op for non-courier requests and for a form still on the
    // legacy Courier layout.
    $this->canonicalizeCourierInputs($submission);

    // An arrival only means something for a service the profile says may be
    // booked. Discarded before the quote is priced and before the Manual Review
    // entry is written, so nothing downstream — the stored submission, the review
    // board, the checkout starter — ever sees an instant for a service that cannot
    // have one. A no-op for a self-bookable service and for a submission with no
    // arrival, which is almost all of them.
    $this->discardArrivalOutsideSelfBooking($submission);

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

  /**
   * Clears every previously computed distance and quote field.
   */
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

  /**
   * Forces the simplified Courier inputs to their canonical server-side values.
   *
   * The simplified Courier journey removes the weight-unit selector, the urgency
   * tiers, and the legacy prohibited-items radio from the visible form, and adds
   * a single compact attestation checkbox and a Send-only same-as-sender
   * checkbox. Once those UI fields are gone, their stored values are no longer
   * live customer input: they may be stale from an earlier service_type, cleared
   * by a #states toggle, or crafted by a client. This seam replaces them with the
   * values the server is authoritative for, before the quote is priced and before
   * any required-field or checkout consumer reads them.
   *
   * The compact attestation element is used as the marker for "this form is on
   * the simplified Courier layout". Where it is absent — a non-courier request,
   * or a form still on the legacy Courier layout — nothing is touched, so every
   * value the visitor entered (including a genuine kg weight or a rush upsell on
   * the legacy form) is preserved and this method can be deployed before the
   * element migration safely.
   */
  private function canonicalizeCourierInputs(WebformSubmissionInterface $submission): void {
    $service_element = $this->serviceTypeElement();
    if ($service_element === '') {
      return;
    }
    // "Is this the Courier service?" asked of the service's own profile, by the
    // same test ConfigDispatchEligibility applies, instead of comparing the stored
    // value to a `courier` literal. A second service that is later given the
    // courier duration resolver is canonicalised here without an edit, and a
    // renamed machine value cannot leave this seam silently doing nothing.
    $profile = $this->bookingProfiles->resolve(
      trim((string) $submission->getElementData($service_element)),
    );
    if ($profile->durationResolver !== BookingProfile::DURATION_COURIER) {
      return;
    }
    if (!$this->elementExists($submission, 'courier_prohibited_ack')) {
      return;
    }

    // Weight is always pounds for Courier now that the unit selector is gone.
    // total_weight_unit also survives as a hidden 'lb'-defaulting element for the
    // Dispatch offer endpoint, which reads raw browser values and never runs this
    // seam; forcing it here makes the stored and requoted figure unambiguous and
    // stops a stale or crafted 'kg' from halving or doubling the priced weight.
    $this->setSubmissionValue($submission, 'total_weight_unit', 'lb');

    // Courier is a single standard service; the rush/expedited tiers are not
    // offered on the Courier UI. 'standard' adds no surcharge in QuoteCalculator,
    // so it is the safe canonical value for any stale or crafted urgency.
    $this->setSubmissionValue($submission, 'courier_urgency', 'standard');

    // One-driver handling is a property of Express Courier, not a question. The
    // service is one driver and one vehicle under a weight ceiling chosen so one
    // person can carry the package unaided, so the retired element's stored value
    // is written canonically here rather than being asked for and possibly answered
    // wrong. DispatchRequestFactory already derives the same fact from the
    // service's BookingProfile for the live quote, the offer endpoint, and the checkout requote — none of which run this seam — and this
    // keeps the saved submission and the staff view saying the same thing as the
    // verdict the visitor was given. Historic answers on non-Courier submissions
    // are untouched, and setSubmissionValue() is a no-op on a form without the
    // element at all.
    $this->setSubmissionValue($submission, 'single_driver_handling', '1');

    // A checked attestation maps to the existing safe acknowledgement value;
    // absent, unchecked, or crafted maps to 'unsure', which QuoteCalculator turns
    // into a manual_review quote and which therefore yields no checkout snapshot.
    // A missing attestation is never silently treated as 'none'.
    $acknowledged = $this->attestationChecked($submission->getElementData('courier_prohibited_ack'));
    $this->setSubmissionValue($submission, 'prohibited_items_ack', $acknowledged ? 'none' : 'unsure');

    // The three universal move add-ons are not offered on the Courier UI, and
    // ConfigDispatchEligibility refuses Slots for any add-on this module cannot
    // time. A value here can only be wizard state left over from a service the
    // visitor started with and then changed, so clearing it keeps the stored
    // request, the requote at checkout, and what staff read in Manual Review
    // describing the courier run that was actually asked for. QuoteCalculator
    // independently declines to price these for courier, so the two agree whether
    // or not this seam ran.
    foreach (['packing_services', 'cleaning_services', 'disposal_donation_interest'] as $move_add_on) {
      if (!empty($submission->getElementData($move_add_on))) {
        $this->setSubmissionValue($submission, $move_add_on, '');
      }
    }

    // Same-as-sender is UI intent; the server resolves the pickup contact. Only
    // the Send mode offers it — Receive keeps its third-party pickup contact and
    // Store Pickup uses merchant fields. Copying here, before the pickup-contact
    // fields are read for validation or checkout, means browser autofill or a
    // cleared hidden block can neither leak a stale contact nor blank a required
    // one.
    if ((string) $submission->getElementData('delivery_mode') === 'courier_send'
      && $this->elementExists($submission, 'pickup_same_as_sender')
      && $this->attestationChecked($submission->getElementData('pickup_same_as_sender'))) {
      $this->setSubmissionValue($submission, 'pickup_contact_name', (string) $submission->getElementData('full_name'));
      $this->setSubmissionValue($submission, 'pickup_contact_phone', (string) $submission->getElementData('phone_number'));
    }
  }

  /**
   * Whether a checkbox-style value is affirmatively checked.
   *
   * A Webform checkbox submits '1' when checked and '', 0, or nothing otherwise.
   * Only the affirmative states count; a crafted string is not acknowledgement.
   *
   * A one-element array is unwrapped before the string cast, because a REST, ECA,
   * or queue writer — and a multi-value element — can deliver a checked box as
   * ['1']. Without that step the array was cast to the literal string 'Array',
   * which emitted a PHP warning and read an affirmation as unchecked. Anything
   * that is not a single scalar after unwrapping — an empty array, a two-element
   * array, a nested array, an object — still reads as unchecked, so a shape can
   * only widen a value into itself and never into an acknowledgement it did not
   * carry.
   *
   * QuoteCalculator::attestationChecked() is the same reader on the pricing side
   * and must keep the same accept set: change both or neither.
   */
  private function attestationChecked(mixed $value): bool {
    if (is_array($value)) {
      $value = count($value) === 1 ? reset($value) : NULL;
    }
    if (is_bool($value)) {
      return $value;
    }
    if (!is_scalar($value)) {
      return FALSE;
    }
    return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], TRUE);
  }

  /**
   * Whether the Webform actually defines an element with this key.
   *
   * Used as a read-side gate: setSubmissionValue() guards writes the same way,
   * but the Courier canonicalisation needs to know an element's presence before
   * deciding whether to treat the form as the simplified layout at all.
   */
  private function elementExists(WebformSubmissionInterface $submission, string $field): bool {
    if ($field === '') {
      return FALSE;
    }
    $webform = $submission->getWebform();
    if ($webform === NULL) {
      return FALSE;
    }
    return isset($webform->getElementsInitializedAndFlattened()[$field]);
  }

  /**
   * Marks the submission as requiring a manual quote review.
   */
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

  /**
   * Writes a value only when the configured Webform element exists.
   */
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
