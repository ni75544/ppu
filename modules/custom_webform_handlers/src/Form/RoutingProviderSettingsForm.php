<?php

namespace Drupal\custom_webform_handlers\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\custom_webform_handlers\Distance\RoutingPolicy;
use Drupal\custom_webform_handlers\Distance\RoutingUsage;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Administers which routing provider answers which question, and what it may spend.
 *
 * This form owns the `routing` subtree of custom_webform_handlers.settings and nothing
 * else. It cannot change a tariff, cannot change what the calendar offers, and — until
 * Task 20G switches the container alias — cannot change which provider actually answers
 * a live quote. What it can do is record a policy and then say, in plain words, whether
 * that policy could run today.
 *
 * Three things are deliberately kept apart here, because collapsing them is what makes
 * routing configuration silently wrong:
 *
 * - Refusals. RoutingPolicy::validate() returns the structural mistakes that must not be
 *   saved: an unknown provider, a duplicate in a chain, Google outside final operations,
 *   a deadline shorter than a member's own timeout, an allowance wider than the provider
 *   it divides. Every message it returns is keyed by the element that caused it.
 * - Readiness. RoutingPolicy::readiness() explains what each purpose could do *today*.
 *   A saveable configuration is routinely not a runnable one — the shipped defaults name
 *   providers that do not exist yet — so this is reported rather than refused.
 * - Credentials. Reported as present or absent and never read. The form names the
 *   settings.php key an operator has to add; it never renders a value, and there is no
 *   element here that could store one, so no export of this configuration can carry a
 *   secret.
 *
 * @see \Drupal\custom_webform_handlers\Distance\RoutingPolicy
 */
final class RoutingProviderSettingsForm extends ConfigFormBase {

  /**
   * The config object whose `routing` subtree this form owns.
   */
  const SETTINGS = 'custom_webform_handlers.settings';

  public function __construct(
    ConfigFactoryInterface $config_factory,
    private readonly RoutingUsage $routingUsage,
  ) {
    parent::__construct($config_factory);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('custom_webform_handlers.routing_usage'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'custom_webform_handlers_routing_provider_settings';
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
    $stored = $this->config(self::SETTINGS)->get('routing');
    $routing = RoutingPolicy::merge(is_array($stored) ? $stored : []);

    $form['intro'] = [
      '#markup' => '<p>' . $this->t('A purpose is asked, not a provider. Each purpose below names the chain it may try, in order, and the total time the chain gets; the first provider that answers wins. Nothing here changes a price — the same distance produces the same quote whichever provider measured it.') . '</p>',
    ];

    $form['readiness'] = $this->readinessSection($routing);
    $form['credentials'] = $this->credentialSection();
    $form['usage'] = $this->usageSection();

    $form['billing_period'] = [
      '#type' => 'select',
      '#title' => $this->t('Allowance period'),
      '#options' => [
        'day' => $this->t('Daily'),
        'month' => $this->t('Monthly'),
      ],
      '#default_value' => (string) $routing['billing_period'],
      '#description' => $this->t('The calendar every request allowance below resets on.'),
    ];
    $form['billing_timezone'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Timezone the period rolls over in'),
      '#default_value' => (string) $routing['billing_timezone'],
      '#maxlength' => 64,
      '#description' => $this->t('A timezone identifier such as UTC or America/Toronto. Fixed and operator-chosen: a visitor’s own timezone is never used, so nobody can reset a budget early by travelling.'),
    ];

    $form['geocoder'] = [
      '#type' => 'details',
      '#title' => $this->t('Coordinate resolution'),
      '#open' => FALSE,
      '#tree' => TRUE,
      '#description' => $this->t('Committed addresses are resolved through the approved self-hosted provider. Clearing the provider disables coordinate resolution; browser place coordinates never replace this server-side result.'),
    ];
    $form['geocoder']['provider'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Geocoder machine name'),
      '#default_value' => (string) $routing['geocoder']['provider'],
      '#maxlength' => 64,
      '#description' => $this->t('Lowercase letters, numbers and underscores, or empty for none.'),
    ];
    $form['geocoder']['version'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Geocoder data version'),
      '#default_value' => (string) $routing['geocoder']['version'],
      '#maxlength' => 64,
      '#description' => $this->t('Required before coordinates may be stored: a stored coordinate can only be attributed to a dataset if the dataset was named, and without one there is no way to expire only the results measured before it changed.'),
    ];
    $form['geocoder']['persist'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Coordinates may be stored beyond the request'),
      '#default_value' => !empty($routing['geocoder']['persist']),
    ];
    $form['geocoder']['request_allowance'] = [
      '#type' => 'number',
      '#title' => $this->t('Geocoding requests per period'),
      '#default_value' => (int) $routing['geocoder']['request_allowance'],
      '#min' => 0,
      '#description' => $this->t('Zero permits none. It does not mean unlimited.'),
    ];

    $form['purposes'] = $this->purposesSection($routing);
    $form['providers'] = $this->providersSection($routing);

    return parent::buildForm($form, $form_state);
  }

  /**
   * The three purposes, each with the chain it may ask and the time it gets.
   *
   * The order is a text field of provider ids rather than a set of per-provider weights,
   * for the same reason the schema declares it a sequence: failover order is the whole
   * point, and a weight map turns "which provider is asked second" into something the
   * operator has to work out instead of something they wrote down. Typing it also keeps
   * the two mistakes worth naming — an unknown id and a repeated one — answerable with
   * the message that describes them, instead of silently unrepresentable.
   *
   * The allowances go the other way: one number per metered provider, offered only for
   * the providers that bill per request. A free-text pair list would exist mainly so an
   * operator could name a provider that has no allowance to divide, and refusing that is
   * worth less than never inviting it. Both refusals still apply to a configuration
   * import, which is the path that can still express them.
   *
   * Empty and zero are different answers there, and the widget has to keep them apart:
   * empty means this purpose does not divide the provider-wide allowance, zero means it
   * may spend none of it.
   */
  private function purposesSection(array $routing): array {
    $labels = [
      'ordinary' => $this->t('Ordinary quotes'),
      'offer' => $this->t('Public offers and warming'),
      'final' => $this->t('Validated final operations'),
    ];
    $help = [
      'ordinary' => $this->t('The distance preview and the stored quote figure. The highest-volume purpose on the site, and the one that must never be billed per keystroke. Traffic is not consulted here at all: a price that moved with the hour of the day would depend on when the customer happened to fill the form in.'),
      'offer' => $this->t('Slot offers shown to customers, and the warming that fills their cache. Needs a departure-stamped route, so a provider with no live traffic feed cannot serve it however it is ordered.'),
      'final' => $this->t('The figure an operator commits to. The only purpose Google Routes may appear in, and the longest deadline, because what is waiting on it is a booking decision rather than a page repaint.'),
    ];

    $section = [
      '#type' => 'details',
      '#title' => $this->t('Purposes'),
      '#open' => TRUE,
      '#tree' => TRUE,
    ];

    foreach ($labels as $purpose => $label) {
      $settings = $routing['purposes'][$purpose];

      $section[$purpose] = [
        '#type' => 'details',
        '#title' => $label,
        '#open' => TRUE,
        '#description' => $help[$purpose],
      ];
      $section[$purpose]['order'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Providers to ask, in order'),
        '#default_value' => implode(', ', array_filter((array) $settings['order'], 'is_string')),
        '#maxlength' => 128,
        '#description' => $this->t('Comma-separated, from osrm, mapbox and google_routes. Name each one once: a repeated provider is still a single attempt.'),
      ];
      $section[$purpose]['deadline_ms'] = [
        '#type' => 'number',
        '#title' => $this->t('Deadline for the whole chain, in milliseconds'),
        '#default_value' => (int) $settings['deadline_ms'],
        '#min' => 0,
        '#description' => $this->t('Has to be at least as long as the slowest provider named above, or nothing after that provider could ever be reached.'),
      ];
      $section[$purpose]['request_allowance'] = [
        '#type' => 'container',
        '#tree' => TRUE,
      ];
      foreach (self::meteredProviders() as $id) {
        $share = $settings['request_allowance'][$id] ?? NULL;
        $section[$purpose]['request_allowance'][$id] = [
          '#type' => 'number',
          '#title' => $this->t('@provider requests this purpose may spend', ['@provider' => $id]),
          '#default_value' => $share === NULL ? NULL : (int) $share,
          '#min' => 0,
          '#description' => $this->t('Leave empty to let the provider-wide allowance be the only limit. Zero is not empty: it permits no calls at all. A share can only narrow the provider-wide figure, never widen it.'),
        ];
      }
    }

    return $section;
  }

  /**
   * The three providers, each with only the settings that provider actually has.
   *
   * Not one repeated fieldset. OSRM has an endpoint allowlist and no request allowance
   * at all, because a self-hosted extract bills nothing per request; Mapbox has a second
   * profile and a caching-terms acknowledgement; Google has neither and defers its
   * timings to the keys already in service. A shared fieldset would have to show every
   * field for every provider, which is how an allowance ends up typed against the one
   * provider that costs nothing to ask.
   */
  private function providersSection(array $routing): array {
    $section = [
      '#type' => 'details',
      '#title' => $this->t('Providers'),
      '#open' => TRUE,
      '#tree' => TRUE,
      '#description' => $this->t('A provider that is switched off is skipped wherever it is named, and a provider with an allowance of zero is not called at all. Neither is an error: a chain may legitimately be written before the provider behind it exists.'),
    ];

    $osrm = $routing['providers']['osrm'];
    $section['osrm'] = [
      '#type' => 'details',
      '#title' => $this->t('Self-hosted OSRM'),
      '#open' => FALSE,
      '#description' => $this->t('A private routing engine over an OpenStreetMap extract. It bills nothing per request, which is why it has no allowance — what limits it is its own host, expressed as a timeout and a breaker. It has no live traffic feed, so it can only serve ordinary quotes.'),
    ];
    $section['osrm']['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('OSRM may be asked'),
      '#default_value' => !empty($osrm['enabled']),
    ];
    $section['osrm']['allowed_schemes'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Schemes the endpoint may use'),
      '#options' => [
        'http' => $this->t('http — plaintext, for a deployment reachable only inside your own network'),
        'https' => $this->t('https'),
      ],
      '#default_value' => array_values(array_filter((array) $osrm['allowed_schemes'], 'is_string')),
    ];
    $section['osrm']['allowed_hosts'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Hosts the endpoint may name'),
      '#default_value' => implode("\n", array_filter((array) $osrm['allowed_hosts'], 'is_string')),
      '#rows' => 3,
      '#description' => $this->t('One host per line, without a scheme, port or path. The endpoint itself is not configuration — it is read from settings.php — and this is the list it is checked against, so an operator can narrow where this module is willing to send addresses without touching the filesystem. The public OSRM demonstration server can never be permitted: it offers no availability undertaking, and sending customer addresses to it is a disclosure this site has not agreed to.'),
    ];
    $section['osrm']['profile'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Profile'),
      '#default_value' => (string) $osrm['profile'],
      '#maxlength' => 64,
    ];
    $section['osrm']['timeout_ms'] = [
      '#type' => 'number',
      '#title' => $this->t('Timeout, in milliseconds'),
      '#default_value' => (int) $osrm['timeout_ms'],
      '#min' => 0,
    ];
    $section['osrm']['cache_results'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Results may be stored'),
      '#default_value' => !empty($osrm['cache_results']),
    ];
    $section['osrm']['cache_ttl'] = [
      '#type' => 'number',
      '#title' => $this->t('Stored result lifetime, in seconds'),
      '#default_value' => (int) $osrm['cache_ttl'],
      '#min' => 0,
      '#description' => $this->t('A private extract changes when its operator rebuilds it rather than continuously, and no per-request cost pushes this figure down.'),
    ];
    $section['osrm']['data_version'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Extract version'),
      '#default_value' => (string) $osrm['data_version'],
      '#maxlength' => 64,
      '#description' => $this->t('Part of cache identity. Changing it after a rebuild is what retires routes measured against the old road network.'),
    ];
    $section['osrm'] += $this->circuitElements($osrm);

    $mapbox = $routing['providers']['mapbox'];
    $section['mapbox'] = [
      '#type' => 'details',
      '#title' => $this->t('Mapbox Directions'),
      '#open' => FALSE,
      '#description' => $this->t('Metered per request, and able to answer both questions: it has one profile for a time-independent route and a separate traffic-aware profile for a departure-stamped one. Which of the two is asked for is decided by the purpose, not by the provider.'),
    ];
    $section['mapbox']['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Mapbox may be asked'),
      '#default_value' => !empty($mapbox['enabled']),
    ];
    $section['mapbox']['profile'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Profile for time-independent routes'),
      '#default_value' => (string) $mapbox['profile'],
      '#maxlength' => 64,
    ];
    $section['mapbox']['traffic_profile'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Profile for departure-stamped routes'),
      '#default_value' => (string) $mapbox['traffic_profile'],
      '#maxlength' => 64,
      '#description' => $this->t('A different product from the profile above. Naming a traffic-aware profile is a profile choice and not by itself a claim that a returned duration was priced against traffic.'),
    ];
    $section['mapbox']['timeout_ms'] = [
      '#type' => 'number',
      '#title' => $this->t('Timeout, in milliseconds'),
      '#default_value' => (int) $mapbox['timeout_ms'],
      '#min' => 0,
    ];
    $section['mapbox']['cache_results'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Results may be stored'),
      '#default_value' => !empty($mapbox['cache_results']),
    ];
    $section['mapbox']['cache_terms_recorded'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('My Mapbox terms permit storing route results'),
      '#default_value' => !empty($mapbox['cache_terms_recorded']),
      '#description' => $this->t('Caching is only in force when both this and the switch above are on. Task 20A read the published terms and could not establish that right, so it is asserted by the operator who has read their own contract rather than assumed by a default.'),
    ];
    $section['mapbox']['cache_ttl'] = [
      '#type' => 'number',
      '#title' => $this->t('Stored result lifetime, in seconds'),
      '#default_value' => (int) $mapbox['cache_ttl'],
      '#min' => 0,
    ];
    $section['mapbox']['data_version'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Dataset version'),
      '#default_value' => (string) $mapbox['data_version'],
      '#maxlength' => 64,
    ];
    $section['mapbox'] += $this->circuitElements($mapbox);
    $section['mapbox']['request_allowance'] = [
      '#type' => 'number',
      '#title' => $this->t('Requests per period, across all purposes'),
      '#default_value' => (int) $mapbox['request_allowance'],
      '#min' => 0,
      '#description' => $this->t('Zero permits none. It does not mean unlimited.'),
    ];

    $google = $routing['providers']['google_routes'];
    $section['google_routes'] = [
      '#type' => 'details',
      '#title' => $this->t('Google Routes'),
      '#open' => FALSE,
      '#description' => $this->t('The provider in service today, and the most expensive one. Permitted only for validated final operations: a configuration that names it for a preview, an ordinary price, a public offer or warming is refused rather than saved with a warning.'),
    ];
    $section['google_routes']['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Google Routes may be asked'),
      '#default_value' => !empty($google['enabled']),
    ];
    $section['google_routes']['timeout_ms'] = [
      '#type' => 'number',
      '#title' => $this->t('Timeout, in milliseconds, or 0 to keep using the existing setting'),
      '#default_value' => (int) $google['timeout_ms'],
      '#min' => 0,
      '#description' => $this->t('Zero here is a reference and not a timeout of nothing: it means keep reading the route request timeout on the quote settings form, which is already in service and already clamped inside the adapter. Leaving it at zero keeps a Google timing with exactly one owner.'),
    ];
    $section['google_routes']['cache_results'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Results may be stored'),
      '#default_value' => !empty($google['cache_results']),
    ];
    $section['google_routes']['cache_ttl'] = [
      '#type' => 'number',
      '#title' => $this->t('Stored result lifetime, in seconds, or 0 to keep using the existing settings'),
      '#default_value' => (int) $google['cache_ttl'],
      '#min' => 0,
      '#description' => $this->t('Zero defers to the two existing route cache lifetimes, which are already separate: a traffic-aware route for a departure an hour away stops describing that departure much sooner than a time-independent one does.'),
    ];
    $section['google_routes']['data_version'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Dataset version'),
      '#default_value' => (string) $google['data_version'],
      '#maxlength' => 64,
    ];
    $section['google_routes'] += $this->circuitElements($google);
    $section['google_routes']['request_allowance'] = [
      '#type' => 'number',
      '#title' => $this->t('Requests per period, across all purposes'),
      '#default_value' => (int) $google['request_allowance'],
      '#min' => 0,
      '#description' => $this->t('Ships at zero, which permits none. Raising it is the deliberate act that lets the provider chain bill anything, and it applies to validated final operations only. The quote path in service today does not read this figure.'),
    ];

    return $section;
  }

  /**
   * The three breaker fields, which are the same question for every provider.
   *
   * Shared because a breaker is not provider-specific policy: it is how many faults in a
   * row are taken as "this provider is down rather than unlucky", how long to leave it
   * alone afterwards, and how many requests to risk on finding out whether it came back.
   * Only the numbers differ, and those come from the caller's own settings.
   */
  private function circuitElements(array $provider): array {
    return [
      'circuit_failure_threshold' => [
        '#type' => 'number',
        '#title' => $this->t('Faults in a row that open the breaker'),
        '#default_value' => (int) $provider['circuit_failure_threshold'],
        '#min' => 1,
      ],
      'circuit_cooldown_seconds' => [
        '#type' => 'number',
        '#title' => $this->t('Seconds an open breaker stays open'),
        '#default_value' => (int) $provider['circuit_cooldown_seconds'],
        '#min' => 1,
        '#description' => $this->t('While it is open this provider is skipped and the next one in the order is asked, so a chain of two is what keeps a cooldown from becoming an outage.'),
      ],
      'circuit_half_open_probes' => [
        '#type' => 'number',
        '#title' => $this->t('Requests risked on testing whether it recovered'),
        '#default_value' => (int) $provider['circuit_half_open_probes'],
        '#min' => 1,
      ],
    ];
  }

  /**
   * What each purpose could actually do today, and what is stopping it.
   *
   * Reported rather than refused, because a saveable configuration is routinely not a
   * runnable one: this module ships naming providers that do not exist yet, so refusing
   * that at save time would make the shipped defaults unsaveable and force an operator to
   * enable a provider before they were allowed to write down an order for it.
   *
   * Every line here comes from RoutingPolicy, which is also what the runtime consults, so
   * this page cannot say a purpose is ready while the chain refuses to run it.
   */
  private function readinessSection(array $routing): array {
    $section = [
      '#type' => 'details',
      '#title' => $this->t('What each purpose can do now'),
      '#open' => TRUE,
    ];

    foreach (RoutingPolicy::readiness($routing) as $purpose => $report) {
      $lines = [];
      // Only when there is one. An empty chain already arrives as a blocker in the
      // operator's own terms, and saying it twice reads as two different problems.
      if ($report['chain'] !== []) {
        $lines[] = $this->t('Chain: @chain', ['@chain' => implode(' → ', $report['chain'])]);
      }
      foreach ($report['blockers'] as $blocker) {
        $lines[] = $this->t('Blocked: @reason', ['@reason' => $blocker]);
      }
      foreach ($report['notes'] as $note) {
        $lines[] = $note;
      }

      $section[$purpose] = [
        '#theme' => 'item_list',
        '#title' => $report['ready']
          ? $this->t('@purpose — ready', ['@purpose' => $purpose])
          : $this->t('@purpose — not ready', ['@purpose' => $purpose]),
        '#items' => $lines,
      ];
    }

    return $section;
  }

  /**
   * Shows local counters, durable reservations, and circuit state.
   */
  private function usageSection(): array {
    $summary = $this->routingUsage->snapshot();
    $section = [
      '#type' => 'details',
      '#title' => $this->t('Local routing usage'),
      '#open' => TRUE,
      '#description' => $this->t('These are this Drupal site’s local operational counters and allowance reservations. They are not vendor billing totals and do not include another application that shares a provider account.'),
    ];
    if (!$summary['available']) {
      $section['unavailable'] = [
        '#markup' => '<p>' . $this->t('The local routing usage summary is unavailable. Paid routing remains fail-closed when its durable ledger cannot be read.') . '</p>',
      ];
      return $section;
    }

    $window = $summary['window'];
    $timezone = new \DateTimeZone($window['timezone']);
    $start = (new \DateTimeImmutable('@' . $window['start']))
      ->setTimezone($timezone)
      ->format('Y-m-d H:i T');
    $end = (new \DateTimeImmutable('@' . $window['end']))
      ->setTimezone($timezone)
      ->format('Y-m-d H:i T');
    $section['period'] = [
      '#markup' => '<p>' . $this->t(
        'Current @period period: @start through @end.',
        [
          '@period' => $window['period'],
          '@start' => $start,
          '@end' => $end,
        ]
      ) . '</p>',
    ];

    $counterRows = [];
    foreach ($summary['counters'] as $row) {
      $counterRows[] = [
        $row['provider'],
        $row['purpose'],
        str_replace('_', ' ', $row['event']),
        $row['reason'] === '' ? '—' : str_replace('_', ' ', $row['reason']),
        (int) $row['occurrences'],
      ];
    }
    $section['counters'] = [
      '#type' => 'table',
      '#caption' => $this->t('Observed chain events'),
      '#header' => [
        $this->t('Provider'),
        $this->t('Purpose'),
        $this->t('Event'),
        $this->t('Fallback reason'),
        $this->t('Count'),
      ],
      '#rows' => $counterRows,
      '#empty' => $this->t(
        'No routing chain event has been recorded in this period.'
      ),
    ];

    $budgetRows = [];
    foreach ($summary['budgets'] as $row) {
      $limit = $row['limit'];
      $budgetRows[] = [
        $row['provider'],
        $row['product'],
        $row['purpose'] === '__all__'
          ? $this->t('all purposes')
          : $row['purpose'],
        (int) $row['used'],
        $limit === NULL ? $this->t('provider cap only') : (int) $limit,
        $row['remaining'] === NULL ? '—' : (int) $row['remaining'],
      ];
    }
    $section['budgets'] = [
      '#type' => 'table',
      '#caption' => $this->t('Durable request allowances'),
      '#header' => [
        $this->t('Provider'),
        $this->t('Product'),
        $this->t('Purpose'),
        $this->t('Reserved'),
        $this->t('Limit'),
        $this->t('Remaining'),
      ],
      '#rows' => $budgetRows,
      '#empty' => $this->t('No metered provider allowance is configured.'),
    ];

    $circuitRows = [];
    foreach ($summary['circuits'] as $row) {
      $reopen = (int) $row['open_until'];
      $openUntil = '—';
      if ($reopen > 0) {
        $openUntil = (new \DateTimeImmutable('@' . $reopen))
          ->setTimezone($timezone)
          ->format('Y-m-d H:i:s T');
      }
      $circuitRows[] = [
        $row['provider'],
        $row['purpose'],
        str_replace('_', ' ', $row['state']),
        (int) $row['failures'],
        $openUntil,
      ];
    }
    $section['circuits'] = [
      '#type' => 'table',
      '#caption' => $this->t('Provider circuit state'),
      '#header' => [
        $this->t('Provider'),
        $this->t('Purpose'),
        $this->t('State'),
        $this->t('Consecutive faults'),
        $this->t('Open until'),
      ],
      '#rows' => $circuitRows,
      '#empty' => $this->t(
        'No provider is currently eligible for a routing purpose.'
      ),
    ];

    return $section;
  }

  /**
   * Whether each provider's credential is installed — never what it is.
   *
   * Presence is the only fact this page reports, and the only fact it can report: the
   * values live in environment-backed Settings, there is no element on this form that
   * could hold one, and nothing here reads one. That is what makes it safe to answer
   * "why is this provider not being called" from a browser rather than from a shell.
   *
   * The keys are named so an operator knows what to add. A key name is not a secret; the
   * OSRM endpoint's own host is treated as one, which is why it is set in settings.php
   * rather than here and why no exported configuration file names it.
   */
  private function credentialSection(): array {
    $keys = [
      'osrm' => RoutingPolicy::SETTING_OSRM_BASE_URL,
      'mapbox' => RoutingPolicy::SETTING_MAPBOX_TOKEN,
      'google_routes' => RoutingPolicy::SETTING_GOOGLE_KEY,
    ];
    $items = [];
    foreach ($keys as $provider => $key) {
      $items[] = RoutingPolicy::credentialPresent($provider)
        ? $this->t('@provider: installed as $settings[\'@key\'].', [
          '@provider' => $provider,
          '@key' => $key,
        ])
        : $this->t('@provider: not installed. Add $settings[\'@key\'] to settings.php, reading it from the environment.', [
          '@provider' => $provider,
          '@key' => $key,
        ]);
    }

    return [
      '#type' => 'details',
      '#title' => $this->t('Credentials'),
      '#open' => FALSE,
      '#description' => $this->t('Reported as present or absent. No value is read, rendered, or storable in this configuration, and the browser key used by the address autocomplete is a separate credential that is never used for a server request.'),
      'list' => [
        '#theme' => 'item_list',
        '#items' => $items,
      ],
    ];
  }

  /**
   * The providers that bill per request, read from the defaults rather than listed.
   *
   * Whether a provider is metered is not a fact about this form: it is whether that
   * provider's own settings have an allowance at all. Asking the defaults means a
   * provider that gains one later gains its allowance fields here too, and OSRM cannot
   * acquire one by someone adding it to a list in a form.
   */
  private static function meteredProviders(): array {
    $metered = [];
    foreach (RoutingPolicy::PROVIDERS as $id) {
      if (array_key_exists('request_allowance', RoutingPolicy::DEFAULTS['providers'][$id])) {
        $metered[] = $id;
      }
    }

    return $metered;
  }

  /**
   * The submitted values, assembled into one routing subtree.
   *
   * Used by validateForm() and by submitForm(), so that what was checked is exactly what
   * is stored. Two separate readings of the same form would let a value be accepted in
   * one shape and written in another, which is the defect this whole task is about.
   *
   * The assembly is permissive on purpose. It normalises what the widgets encode — a
   * comma-separated chain into a list, a checkbox set into the schemes it checked, a
   * textarea into the hosts it named — and then stops. It does not drop an unknown
   * provider id, clamp a timeout, or reorder anything, because every one of those is a
   * refusal RoutingPolicy owns, and quietly repairing a value here would mean saving
   * something the operator did not write and never being told.
   */
  private function subtree(FormStateInterface $form_state): array {
    $routing = [
      'billing_period' => (string) $form_state->getValue('billing_period'),
      'billing_timezone' => trim((string) $form_state->getValue('billing_timezone')),
      'geocoder' => [],
      'purposes' => [],
      'providers' => [],
    ];

    $geocoder = (array) $form_state->getValue('geocoder');
    $routing['geocoder'] = [
      'provider' => trim((string) ($geocoder['provider'] ?? '')),
      'version' => trim((string) ($geocoder['version'] ?? '')),
      'persist' => !empty($geocoder['persist']),
      'request_allowance' => (int) ($geocoder['request_allowance'] ?? 0),
    ];

    $purposes = (array) $form_state->getValue('purposes');
    foreach (RoutingPolicy::PURPOSES as $purpose) {
      $submitted = (array) ($purposes[$purpose] ?? []);
      $routing['purposes'][$purpose] = [
        'order' => self::parseOrder((string) ($submitted['order'] ?? '')),
        'deadline_ms' => (int) ($submitted['deadline_ms'] ?? 0),
        'request_allowance' => self::parseAllowances((array) ($submitted['request_allowance'] ?? [])),
      ];
    }

    $providers = (array) $form_state->getValue('providers');
    foreach (RoutingPolicy::PROVIDERS as $id) {
      $routing['providers'][$id] = self::provider($id, (array) ($providers[$id] ?? []));
    }

    return $routing;
  }

  /**
   * One provider's submitted values, typed the way its defaults are typed.
   *
   * The shape is taken from that provider's own defaults rather than from a list written
   * here, which is what keeps this method from being a fourth place that has an opinion
   * about which settings a provider has. A key the defaults do not define is not read at
   * all; a key the form did not render keeps its stored value, because RoutingPolicy::
   * merge() fills it back in.
   *
   * Typing matters more than it looks: an unchecked checkbox submits the integer 0 and a
   * number field submits a string, and configuration exported with '0' where FALSE
   * belongs is a diff on every site that saves this form.
   */
  private static function provider(string $id, array $submitted): array {
    $provider = [];
    foreach (RoutingPolicy::DEFAULTS['providers'][$id] as $key => $default) {
      if (!array_key_exists($key, $submitted)) {
        continue;
      }
      $value = $submitted[$key];
      if ($key === 'allowed_schemes') {
        // A checkbox set submits the option's own key when checked and 0 when not.
        $provider[$key] = array_values(array_filter((array) $value, 'is_string'));
      }
      elseif ($key === 'allowed_hosts') {
        $provider[$key] = self::parseHosts((string) $value);
      }
      elseif (is_bool($default)) {
        $provider[$key] = !empty($value);
      }
      elseif (is_int($default)) {
        $provider[$key] = (int) $value;
      }
      else {
        // Left as typed apart from surrounding space. A profile name belongs to the
        // provider's own product, not to this module, so its case is not ours to
        // normalise — if it is wrong, the refusal says so.
        $provider[$key] = trim((string) $value);
      }
    }

    return $provider;
  }

  /**
   * A typed chain into the list of ids it named.
   *
   * Case is folded because the case of an identifier is not a distinction this module
   * makes: "OSRM" and "osrm" are the same provider, and answering the first with
   * "unknown provider" would be a refusal about typography. Everything else is kept —
   * an id that is genuinely not a provider stays in the list so that the message naming
   * it can be shown.
   */
  private static function parseOrder(string $value): array {
    $order = [];
    foreach (explode(',', $value) as $id) {
      $id = strtolower(trim($id));
      if ($id !== '') {
        $order[] = $id;
      }
    }

    return $order;
  }

  /**
   * One host per line, emptied of blanks.
   *
   * Lower-casing and the bare-host check both belong to RoutingPolicy, which does them
   * for a configuration import as well as for this form.
   */
  private static function parseHosts(string $value): array {
    $hosts = [];
    foreach (preg_split('/\R/', $value) ?: [] as $host) {
      $host = trim($host);
      if ($host !== '') {
        $hosts[] = $host;
      }
    }

    return $hosts;
  }

  /**
   * The per-purpose shares that were actually entered.
   *
   * An empty field is an absent key, not a zero: absent means this purpose does not
   * divide the provider-wide allowance, and zero means it may spend none of it. Only
   * metered providers are read, so nothing this form renders can write an allowance
   * against a provider that has none to divide.
   */
  private static function parseAllowances(array $submitted): array {
    $shares = [];
    foreach (self::meteredProviders() as $id) {
      $value = $submitted[$id] ?? '';
      if ($value === '' || $value === NULL) {
        continue;
      }
      $shares[$id] = (int) $value;
    }

    return $shares;
  }

  /**
   * {@inheritdoc}
   *
   * Every refusal comes from RoutingPolicy::validate(), which is also what a
   * configuration import and the runtime consult. There is no second rule set here: a
   * check written in this form would apply to an operator using the browser and not to
   * the same value arriving through config import, and the two would diverge the first
   * time either was edited.
   *
   * The messages are keyed by the element that caused them, so a refusal about Mapbox's
   * cache terms lands on Mapbox's own checkbox rather than at the top of a page with
   * forty fields on it.
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);
    foreach (RoutingPolicy::validate($this->subtree($form_state)) as $element => $message) {
      $form_state->setErrorByName($element, $message);
    }
  }

  /**
   * {@inheritdoc}
   *
   * Stored through merge(), so the saved subtree is complete and contains only keys the
   * defaults define. That matters for a site that predates this subtree: until Task 20F's
   * migration runs, active configuration has no `routing` key at all, and saving this
   * form is one of the two ways it comes into existence — so it has to arrive whole
   * rather than as the handful of keys someone happened to change.
   *
   * Only `routing` is written. Every other key in this configuration object belongs to
   * the quote settings form or to the handler, and this form has no business touching one
   * of them by saving a partial object over it.
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config(self::SETTINGS)
      ->set('routing', RoutingPolicy::merge($this->subtree($form_state)))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
