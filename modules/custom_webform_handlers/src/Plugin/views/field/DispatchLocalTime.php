<?php

namespace Drupal\custom_webform_handlers\Plugin\views\field;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\views\Attribute\ViewsField;
use Drupal\views\Plugin\views\display\DisplayPluginBase;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;
use Drupal\views\ViewExecutable;
use Drupal\views\Views;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders a stored UTC instant in the timezone its row was booked in.
 *
 * The one custom time renderer on this dashboard, and it exists because core's
 * date field formats in the *viewer's* timezone. Personal timezones are
 * configurable on this site, so two operators reading one Reservation would be
 * shown two different pickup times and at most one of them would match what the
 * customer was told. Every ledger row carries the zone it was booked in; this
 * handler formats in that zone and names it, which is the rule the Dispatch
 * Planner's own detail table already follows.
 *
 * Three shapes come out of it, chosen per field in the View rather than by
 * adding a handler per column:
 * - an instant — "Tue, Sep 1 2026 8:00 AM EDT";
 * - a date alone, for a column whose time of day is noise;
 * - a window — "Tue, Sep 1 2026 8:00 AM – 9:02 AM EDT" — when the field is told
 *   which column closes it.
 *
 * Nothing is mutated and nothing is recalculated. A missing, zero, or
 * non-numeric instant renders as the field's own empty text rather than as
 * midnight in 1970: expires_at is NULL on every confirmed Reservation, and a row
 * left malformed by an older write is exactly the row an operator must not be
 * shown a fabricated time for.
 *
 * @see custom_webform_handlers_views_data()
 */
#[ViewsField("dispatch_local_time")]
class DispatchLocalTime extends FieldPluginBase {

  /**
   * Timezone identifiers already proven loadable, keyed by identifier.
   *
   * A ledger of 24 columns can hold thousands of rows in one page request and
   * they nearly all carry the same zone, so the validity of an identifier is
   * decided once per request rather than once per cell.
   *
   * @var array<string, bool>
   */
  private static array $validatedZones = [];

  /**
   * Constructs the handler.
   *
   * @param array $configuration
   *   Plugin configuration.
   * @param string $plugin_id
   *   Plugin identifier.
   * @param mixed $plugin_definition
   *   Plugin definition.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $dateFormatter
   *   Formats an instant in an explicitly named zone.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   Reads the site's default zone, used only as a fallback.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly ConfigFactoryInterface $configFactory,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('date.formatter'),
      $container->get('config.factory'),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function defineOptions() {
    $options = parent::defineOptions();

    $options['dispatch_time_format'] = ['default' => 'datetime'];
    $options['dispatch_window_end'] = ['default' => ''];
    $options['dispatch_show_zone'] = ['default' => TRUE];

    return $options;
  }

  /**
   * {@inheritdoc}
   *
   * The zone is read off the row, so it has to be in the SELECT; so does the
   * closing boundary when this field renders a window. Both are declared here
   * rather than in the Views data, which keeps the handler self-sufficient: it
   * works on any instant column in the ledger without that column's definition
   * having to remember to hand it a timezone.
   */
  public function init(ViewExecutable $view, DisplayPluginBase $display, ?array &$options = NULL) {
    parent::init($view, $display, $options);

    $this->additional_fields['timezone'] = 'timezone';

    $end = $this->windowEndColumn();
    if ($end !== '') {
      $this->additional_fields['window_end'] = ['field' => $end];
    }
  }

  /**
   * The column this field closes its window with, if it renders a window.
   *
   * @return string
   *   A column name, or '' when this field renders a single instant.
   */
  private function windowEndColumn(): string {
    $end = $this->options['dispatch_window_end'] ?? '';

    return is_string($end) ? $end : '';
  }

  /**
   * {@inheritdoc}
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    parent::buildOptionsForm($form, $form_state);

    $form['dispatch_time_format'] = [
      '#type' => 'select',
      '#title' => $this->t('What to show'),
      '#options' => [
        'datetime' => $this->t('Local date and time'),
        'date' => $this->t('Local date only'),
        'time' => $this->t('Local time only'),
      ],
      '#default_value' => $this->options['dispatch_time_format'] ?? 'datetime',
      '#description' => $this->t('Formatted in the timezone recorded on the row, never in the timezone of whoever is reading the page.'),
    ];

    $form['dispatch_window_end'] = [
      '#type' => 'select',
      '#title' => $this->t('Close a window with'),
      '#options' => ['' => $this->t('- Show one instant -')] + $this->instantColumns(),
      '#default_value' => $this->windowEndColumn(),
      '#description' => $this->t('Renders this field and the chosen column as one window, for example 8:00 AM – 9:02 AM. Only the ledger&#039;s other local time columns are offered, because anything else would put a column that is not an instant into the query.'),
    ];

    $form['dispatch_show_zone'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Name the timezone'),
      '#default_value' => !empty($this->options['dispatch_show_zone']),
      '#description' => $this->t('Appends the zone abbreviation, for example EDT. A date on its own never carries one.'),
    ];
  }

  /**
   * The ledger's other instant columns, offered as window boundaries.
   *
   * @return array
   *   Column names keyed to their Views titles.
   */
  private function instantColumns(): array {
    $columns = [];
    foreach (Views::viewsData()->get($this->table) as $column => $definition) {
      if ($column === $this->realField || !is_array($definition)) {
        continue;
      }
      if (($definition['field']['id'] ?? '') === 'dispatch_local_time') {
        $columns[$column] = $definition['title'] ?? $column;
      }
    }

    return $columns;
  }

  /**
   * {@inheritdoc}
   *
   * A row with no usable opening instant renders as the empty string, which is
   * what advancedRender() replaces with the field's configured empty text. A
   * window whose closing instant is unusable falls back to its opening instant
   * alone rather than inventing a boundary; a window that is merely inverted or
   * zero-length is printed as stored, because that is a data defect an operator
   * should see rather than one this handler should tidy away.
   */
  public function render(ResultRow $values) {
    $start = $this->instant($this->getValue($values));
    if ($start === NULL) {
      return '';
    }

    $zone = $this->rowTimeZone($values);
    $mode = (string) ($this->options['dispatch_time_format'] ?? 'datetime');

    $end = $this->windowEndColumn() === ''
      ? NULL
      : $this->instant($this->getValue($values, 'window_end'));
    if ($end === NULL) {
      return $this->formatted($start, $zone, $mode, $this->namesZone($mode));
    }

    // A window running past local midnight has to spell its closing date out.
    // One that closes on the day it opened does not, and repeating the date
    // there would make the cell harder to scan rather than more exact.
    $closing = $this->sameLocalDay($start, $end, $zone) ? 'time' : 'datetime';

    return new TranslatableMarkup('@start – @end', [
      '@start' => $this->formatted($start, $zone, $mode, FALSE),
      '@end' => $this->formatted($end, $zone, $closing, $this->namesZone($closing)),
    ]);
  }

  /**
   * Whether a cell rendered in this shape ends with its zone abbreviation.
   *
   * @param string $mode
   *   'datetime', 'date', or 'time'.
   *
   * @return bool
   *   TRUE to append the abbreviation. A date carries no time of day, so no
   *   zone can change what it means and naming one would only add noise.
   */
  private function namesZone(string $mode): bool {
    return $mode !== 'date' && !empty($this->options['dispatch_show_zone']);
  }

  /**
   * Formats one stored instant in one named zone.
   *
   * @param int $instant
   *   UTC seconds, as stored.
   * @param string $zone
   *   The timezone identifier to render in.
   * @param string $mode
   *   'datetime', 'date', or 'time'.
   * @param bool $nameZone
   *   TRUE to append the zone abbreviation.
   *
   * @return string
   *   The formatted instant. Returned as a plain string, as core's own date
   *   field does: every character comes from a format pattern defined here or
   *   from the timezone database, so no row value reaches the markup.
   */
  private function formatted(int $instant, string $zone, string $mode, bool $nameZone): string {
    $pattern = match ($mode) {
      'date' => 'D, M j Y',
      'time' => 'g:i A',
      default => 'D, M j Y g:i A',
    };
    if ($nameZone) {
      $pattern .= ' T';
    }

    return $this->dateFormatter->format($instant, 'custom', $pattern, $zone);
  }

  /**
   * Reads a stored instant, refusing anything that is not one.
   *
   * @param mixed $value
   *   The raw column value off the row.
   *
   * @return int|null
   *   UTC seconds, or NULL when the column is empty, zero, or not a number.
   *   Zero is refused with the rest: the ledger writes it nowhere, so a zero is
   *   a defect, and rendering it would put January 1970 on a dispatch board.
   */
  private function instant(mixed $value): ?int {
    if (is_string($value)) {
      $value = trim($value);
    }
    if (!is_numeric($value)) {
      return NULL;
    }
    $instant = (int) $value;

    return $instant > 0 ? $instant : NULL;
  }

  /**
   * Whether two instants fall on one calendar day in the given zone.
   *
   * @param int $start
   *   The opening instant, in UTC seconds.
   * @param int $end
   *   The closing instant, in UTC seconds.
   * @param string $zone
   *   The timezone the comparison is made in.
   *
   * @return bool
   *   TRUE when both render as the same local date. Compared as formatted local
   *   dates rather than by arithmetic on the difference, because a day is not a
   *   fixed number of seconds across a daylight saving transition.
   */
  private function sameLocalDay(int $start, int $end, string $zone): bool {
    return $this->dateFormatter->format($start, 'custom', 'Y-m-d', $zone)
      === $this->dateFormatter->format($end, 'custom', 'Y-m-d', $zone);
  }

  /**
   * The zone one row is rendered in.
   *
   * @param \Drupal\views\ResultRow $values
   *   The row.
   *
   * @return string
   *   The timezone recorded on the row when it is loadable, and the site
   *   default when it is not. A row whose zone was never written, or was
   *   written before the column existed, is shown in the site's own zone and
   *   labelled with that zone's abbreviation, so the cell still says which zone
   *   it means instead of quietly implying the row's.
   */
  private function rowTimeZone(ResultRow $values): string {
    $stored = $this->getValue($values, 'timezone');
    if (is_string($stored) && $stored !== '' && $this->loadableZone($stored)) {
      return $stored;
    }

    return $this->siteTimeZone();
  }

  /**
   * Whether PHP can load a timezone identifier.
   *
   * @param string $zone
   *   The identifier to test.
   *
   * @return bool
   *   TRUE when it resolves. Decided once per identifier per request: a page of
   *   ledger rows nearly all carry the same zone, and an unknown identifier
   *   costs a thrown exception each time it is tried.
   */
  private function loadableZone(string $zone): bool {
    if (!isset(self::$validatedZones[$zone])) {
      try {
        new \DateTimeZone($zone);
        self::$validatedZones[$zone] = TRUE;
      }
      catch (\Exception) {
        self::$validatedZones[$zone] = FALSE;
      }
    }

    return self::$validatedZones[$zone];
  }

  /**
   * The site's configured zone, or PHP's if the site's is unusable.
   *
   * @return string
   *   A loadable timezone identifier. Only ever a fallback: a correct row is
   *   rendered in the zone it was booked in.
   */
  private function siteTimeZone(): string {
    $configured = $this->configFactory->get('system.date')->get('timezone.default');
    if (is_string($configured) && $configured !== '' && $this->loadableZone($configured)) {
      return $configured;
    }

    return date_default_timezone_get();
  }

}
