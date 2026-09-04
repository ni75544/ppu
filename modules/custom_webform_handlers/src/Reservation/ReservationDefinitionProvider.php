<?php

namespace Drupal\custom_webform_handlers\Reservation;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\webform\WebformInterface;

/**
 * Derives canonical Slots from a Webform's own configuration.
 *
 * Nothing a visitor sends is trusted as schedule. The browser supplies at most
 * three strings — a date, an HH:MM key, and an optional resource key — and each
 * is checked against what the Webform's address_distance_handler actually
 * configures before a Slot exists. A request cannot widen the horizon, invent a
 * time option, open a closed weekday, or shift the timezone.
 *
 * Local time is resolved through the configured IANA zone, and the two DST edge
 * cases are rejected by name rather than silently resolved: a skipped local time
 * has no instant to book, and a repeated one has two.
 */
final class ReservationDefinitionProvider {

  /**
   * The handler plugin that owns reservation configuration.
   */
  const HANDLER_ID = 'address_distance_handler';

  /**
   * The Resource used when a handler configures no resource element.
   */
  const DEFAULT_RESOURCE = 'default';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Builds the server's schedule rules for one Webform and Resource.
   *
   * @throws \Drupal\custom_webform_handlers\Reservation\ReservationException
   */
  public function definition(string $webformId, string $resourceId = ''): ReservationDefinition {
    return $this->buildDefinition($webformId, $resourceId, FALSE);
  }

  /**
   * Builds the schedule definition needed to attach a reservation form.
   *
   * A resource-partitioned form has no selected resource during render. Its
   * public constraints are still safe to publish, and the browser must receive
   * them so it can request Availability after the visitor chooses a resource.
   * The placeholder Resource selected here is never used to Hold, confirm, or
   * validate a Slot; those paths call definition() and require a configured
   * resource value.
   *
   * @throws \Drupal\custom_webform_handlers\Reservation\ReservationException
   */
  public function formDefinition(string $webformId): ReservationDefinition {
    return $this->buildDefinition($webformId, '', TRUE);
  }

  /**
   * Builds a Definition for a selected Resource or for form attachment.
   *
   * @throws \Drupal\custom_webform_handlers\Reservation\ReservationException
   */
  private function buildDefinition(string $webformId, string $resourceId, bool $forForm): ReservationDefinition {
    $settings = $this->handlerSettings($webformId);
    $webform = $this->loadWebform($webformId);

    $timeOptions = $this->timeOptions($webform, (string) ($settings['reservation_time_field'] ?? ''));
    if ($timeOptions === []) {
      throw new ReservationException(ReservationException::NOT_CONFIGURED);
    }

    $resourceOptions = $this->resourceOptions($webform, (string) ($settings['reservation_resource_field'] ?? ''));
    $resource = $forForm
      ? ($resourceOptions[0] ?? self::DEFAULT_RESOURCE)
      : $this->resolveResource($resourceId, $resourceOptions);

    return new ReservationDefinition(
      $webformId,
      $resource,
      $this->timezone($settings),
      $this->positiveInt($settings['reservation_duration_minutes'] ?? NULL, 60),
      $this->positiveInt($settings['reservation_hold_seconds'] ?? NULL, 600),
      $this->positiveInt($settings['reservation_booking_window_days'] ?? NULL, 90),
      $this->weekdays($settings['reservation_allowed_weekdays'] ?? NULL),
      $timeOptions,
      $resourceOptions,
    );
  }

  /**
   * Turns one validated date and time into a canonical UTC Slot.
   *
   * @throws \Drupal\custom_webform_handlers\Reservation\ReservationException
   */
  public function slot(ReservationDefinition $definition, string $date, string $time): ReservationSlot {
    $date = trim($date);
    $time = trim($time);

    if (!in_array($time, $definition->timeOptions, TRUE)) {
      throw new ReservationException(ReservationException::INVALID_TIME);
    }

    $timezone = $definition->timezoneObject();
    $this->day($definition, $date);

    $start = $this->resolveLocalTime($date, $time, $timezone);
    $end = $start->modify('+' . $definition->durationMinutes . ' minutes');

    return new ReservationSlot(
      $definition->webformId,
      $definition->resourceId,
      $start->getTimestamp(),
      $end->getTimestamp(),
      $definition->timezone,
      $definition->holdSeconds,
    );
  }

  /**
   * Validates a requested availability day against the server schedule.
   *
   * @throws \Drupal\custom_webform_handlers\Reservation\ReservationException
   */
  public function day(ReservationDefinition $definition, string $date): \DateTimeImmutable {
    $timezone = $definition->timezoneObject();
    $day = $this->parseDate(trim($date), $timezone);
    $this->assertWithinHorizon($day, $definition, $timezone);
    $this->assertOpenWeekday($day, $definition);
    return $day;
  }

  /**
   * Returns the field names the browser needs, or an empty array.
   */
  public function fieldNames(string $webformId): array {
    try {
      $settings = $this->handlerSettings($webformId);
    }
    catch (ReservationException) {
      return [];
    }

    return [
      'date' => (string) ($settings['reservation_date_field'] ?? ''),
      'time' => (string) ($settings['reservation_time_field'] ?? ''),
      'hold_token' => (string) ($settings['reservation_hold_token_field'] ?? ''),
      'resource' => (string) ($settings['reservation_resource_field'] ?? ''),
    ];
  }

  /**
   * Tells whether reservations are active for one handler's configuration.
   *
   * The enable flag has to be read from the Webform's *stored* handler settings,
   * not from the plugin's merged configuration. WebformHandlerBase merges
   * defaultConfiguration() over what was saved, and this handler defaults the
   * flag to FALSE, so a handler configured before the flag existed arrives here
   * looking exactly like an administrator who deliberately switched reservations
   * off. Treating those two the same is what leaves a fully configured form
   * silently unbookable.
   *
   * So: an explicit stored value always wins, in either direction. Only when the
   * key is absent is the field mapping itself read as the opt-in — a date, a
   * time, and a token element is not something a Webform acquires by accident.
   *
   * @param \Drupal\webform\WebformInterface $webform
   *   The Webform that owns the handler.
   * @param string $handlerId
   *   The handler instance ID, used to find its stored settings.
   * @param array $settings
   *   The handler's effective (merged) settings.
   */
  public function isEnabled(WebformInterface $webform, string $handlerId, array $settings): bool {
    $handlers = $webform->get('handlers');
    $stored = is_array($handlers) ? ($handlers[$handlerId]['settings'] ?? NULL) : NULL;

    if (is_array($stored) && array_key_exists('reservation_enabled', $stored)) {
      // An administrator's explicit choice, kept in both directions.
      return (bool) $stored['reservation_enabled'];
    }

    // Legacy configuration predating the flag: the mapping is the intent.
    return trim((string) ($settings['reservation_date_field'] ?? '')) !== ''
      && trim((string) ($settings['reservation_time_field'] ?? '')) !== ''
      && trim((string) ($settings['reservation_hold_token_field'] ?? '')) !== '';
  }

  /**
   * Reads the enabled handler's reservation settings.
   *
   * @throws \Drupal\custom_webform_handlers\Reservation\ReservationException
   */
  private function handlerSettings(string $webformId): array {
    $webform = $this->loadWebform($webformId);

    foreach ($webform->getHandlers() as $handler) {
      if ($handler->getPluginId() !== self::HANDLER_ID || !$handler->isEnabled()) {
        continue;
      }
      $settings = $handler->getConfiguration()['settings'] ?? [];
      if (!$this->isEnabled($webform, (string) $handler->getHandlerId(), $settings)) {
        continue;
      }
      // A reservation cannot be recorded without somewhere to keep its token,
      // so an incomplete mapping is treated as "not configured" rather than
      // half-enabled.
      if (($settings['reservation_date_field'] ?? '') === ''
        || ($settings['reservation_time_field'] ?? '') === ''
        || ($settings['reservation_hold_token_field'] ?? '') === '') {
        continue;
      }
      if ($this->dateElementProblem($webform, (string) $settings['reservation_date_field']) !== ''
        || $this->timeElementProblem($webform, (string) $settings['reservation_time_field']) !== ''
        || $this->hiddenElementProblem($webform, (string) $settings['reservation_hold_token_field']) !== ''
        || $this->resourceElementProblem($webform, (string) ($settings['reservation_resource_field'] ?? '')) !== '') {
        continue;
      }
      return $settings;
    }

    throw new ReservationException(ReservationException::NOT_CONFIGURED);
  }

  /**
   * @throws \Drupal\custom_webform_handlers\Reservation\ReservationException
   */
  private function loadWebform(string $webformId): WebformInterface {
    if ($webformId === '' || strlen($webformId) > 128 || !preg_match('/^[a-z0-9_]+$/', $webformId)) {
      throw new ReservationException(ReservationException::NOT_CONFIGURED);
    }

    $webform = $this->entityTypeManager->getStorage('webform')->load($webformId);
    if (!$webform instanceof WebformInterface) {
      throw new ReservationException(ReservationException::NOT_CONFIGURED);
    }
    return $webform;
  }

  /**
   * Returns why a Webform element cannot serve as the time field, or ''.
   *
   * This is the single rule both configuration validation and the runtime path
   * consult, so an element the administrator was allowed to choose is always an
   * element the browser can render booked options for.
   *
   * @return string
   *   One of 'missing', 'not_select', 'no_options', 'non_canonical_options', or
   *   an empty string when the element is usable.
   */
  public function timeElementProblem(WebformInterface $webform, string $elementKey): string {
    if ($elementKey === '') {
      return 'missing';
    }

    $element = $webform->getElement($elementKey);
    if (!is_array($element)) {
      return 'missing';
    }

    // Only a select can carry the disabled "Booked" options the visitor relies
    // on to see what is taken. Radios and free-text cannot express that state.
    if (($element['#type'] ?? '') !== 'select') {
      return 'not_select';
    }

    $options = is_array($element['#options'] ?? NULL) ? array_keys($element['#options']) : [];
    if ($options === []) {
      return 'no_options';
    }

    // A select commonly carries an empty-keyed placeholder option — the "choose
    // one" row a required select needs when nothing is selected yet. That key is
    // the absence of a time, not a malformed one, so it is skipped rather than
    // condemning the whole element. Every remaining key must still be canonical.
    $times = array_values(array_filter(
      array_map('strval', $options),
      static fn (string $key): bool => $key !== '',
    ));
    if ($times === []) {
      return 'no_options';
    }

    foreach ($times as $key) {
      if (!$this->isCanonicalTime($key)) {
        return 'non_canonical_options';
      }
    }

    return '';
  }

  /**
   * Returns why an element cannot store the canonical date value, or ''.
   */
  public function dateElementProblem(WebformInterface $webform, string $elementKey): string {
    if ($elementKey === '') {
      return 'missing';
    }
    $element = $webform->getElement($elementKey);
    if (!is_array($element)) {
      return 'missing';
    }
    return in_array($element['#type'] ?? '', ['date', 'webform_date'], TRUE) ? '' : 'not_date';
  }

  /**
   * Returns why an element cannot safely retain the bearer token, or ''.
   */
  public function hiddenElementProblem(WebformInterface $webform, string $elementKey): string {
    if ($elementKey === '') {
      return 'missing';
    }
    $element = $webform->getElement($elementKey);
    if (!is_array($element)) {
      return 'missing';
    }
    return ($element['#type'] ?? '') === 'hidden' ? '' : 'not_hidden';
  }

  /**
   * Returns why an element cannot partition reservation capacity, or ''.
   */
  public function resourceElementProblem(WebformInterface $webform, string $elementKey): string {
    if ($elementKey === '') {
      return '';
    }
    $element = $webform->getElement($elementKey);
    if (!is_array($element)) {
      return 'missing';
    }
    return ($element['#type'] ?? '') === 'select' ? '' : 'not_select';
  }

  /**
   * Returns the canonical HH:MM keys the Webform's time element declares.
   */
  private function timeOptions(WebformInterface $webform, string $elementKey): array {
    // An element the compatibility rule rejects yields no options at all, so an
    // incompatible configuration leaves the workflow off rather than half on.
    if ($this->timeElementProblem($webform, $elementKey) !== '') {
      return [];
    }

    $element = $webform->getElement($elementKey);
    $options = is_array($element['#options'] ?? NULL) ? $element['#options'] : [];

    $times = [];
    foreach (array_keys($options) as $key) {
      $key = (string) $key;
      // The placeholder option is not a bookable time: leaving it in would make
      // "no time chosen" look like a Slot the server could hold.
      if ($key === '') {
        continue;
      }
      $times[] = $key;
    }
    return array_values(array_unique($times));
  }

  /**
   * Returns the canonical resource keys, or an empty array when unpartitioned.
   */
  private function resourceOptions(WebformInterface $webform, string $elementKey): array {
    if ($elementKey === '') {
      return [];
    }
    if ($this->resourceElementProblem($webform, $elementKey) !== '') {
      return [];
    }
    $element = $webform->getElement($elementKey);
    $options = is_array($element['#options'] ?? NULL) ? $element['#options'] : [];

    $resources = [];
    foreach (array_keys($options) as $key) {
      $key = (string) $key;
      if ($key !== '' && strlen($key) <= 128 && preg_match('/^[A-Za-z0-9_\-]+$/', $key)) {
        $resources[] = $key;
      }
    }
    return array_values(array_unique($resources));
  }

  /**
   * @throws \Drupal\custom_webform_handlers\Reservation\ReservationException
   */
  private function resolveResource(string $requested, array $options): string {
    $requested = trim($requested);
    // An unpartitioned schedule has exactly one Resource, and an explicit
    // "default" always names it. Once the Webform declares a resource field,
    // however, an empty or invented value must not create a hidden default
    // capacity pool outside the configured options.
    if ($options === [] && ($requested === '' || $requested === self::DEFAULT_RESOURCE)) {
      return self::DEFAULT_RESOURCE;
    }

    if (!in_array($requested, $options, TRUE)) {
      throw new ReservationException(ReservationException::INVALID_RESOURCE);
    }
    return $requested;
  }

  /**
   * Parses a strict Y-m-d date, rejecting anything PHP would coerce.
   *
   * @throws \Drupal\custom_webform_handlers\Reservation\ReservationException
   */
  private function parseDate(string $date, \DateTimeZone $timezone): \DateTimeImmutable {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
      throw new ReservationException(ReservationException::INVALID_DATE);
    }

    $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, $timezone);
    if (!$parsed || $parsed->format('Y-m-d') !== $date) {
      // Rejects 2026-02-30 and 2026-13-01, which createFromFormat would
      // otherwise roll forward into a different, valid-looking day.
      throw new ReservationException(ReservationException::INVALID_DATE);
    }
    return $parsed;
  }

  /**
   * @throws \Drupal\custom_webform_handlers\Reservation\ReservationException
   */
  private function assertWithinHorizon(\DateTimeImmutable $day, ReservationDefinition $definition, \DateTimeZone $timezone): void {
    $today = (new \DateTimeImmutable('@' . $this->time->getRequestTime()))
      ->setTimezone($timezone)
      ->setTime(0, 0, 0);
    $last = $today->modify('+' . $definition->bookingWindowDays . ' days');

    if ($day < $today || $day > $last) {
      throw new ReservationException(ReservationException::OUTSIDE_HORIZON);
    }
  }

  /**
   * @throws \Drupal\custom_webform_handlers\Reservation\ReservationException
   */
  private function assertOpenWeekday(\DateTimeImmutable $day, ReservationDefinition $definition): void {
    if (!in_array((int) $day->format('N'), $definition->allowedWeekdays, TRUE)) {
      throw new ReservationException(ReservationException::WEEKDAY_CLOSED);
    }
  }

  /**
   * Resolves a local wall-clock time to exactly one UTC instant.
   *
   * @throws \Drupal\custom_webform_handlers\Reservation\ReservationException
   */
  private function resolveLocalTime(string $date, string $time, \DateTimeZone $timezone): \DateTimeImmutable {
    $wall = $date . ' ' . $time;
    $local = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $wall, $timezone);
    if (!$local) {
      throw new ReservationException(ReservationException::INVALID_TIME);
    }

    // A spring-forward jump skips this wall clock entirely: PHP silently lands
    // on a different one, which is how the hour gets booked wrong.
    if ($local->format('Y-m-d H:i') !== $wall) {
      throw new ReservationException(ReservationException::TIME_NONEXISTENT);
    }

    // A fall-back repeats this wall clock, so the same string names two
    // instants an hour apart. Refuse rather than guess which one was meant.
    foreach ([-3600, 3600] as $shift) {
      $alternative = (new \DateTimeImmutable('@' . ($local->getTimestamp() + $shift)))
        ->setTimezone($timezone);
      if ($alternative->format('Y-m-d H:i') === $wall) {
        throw new ReservationException(ReservationException::TIME_AMBIGUOUS);
      }
    }

    return $local->setTimezone(new \DateTimeZone('UTC'));
  }

  /**
   * Returns the configured IANA timezone, falling back to the site's own.
   */
  private function timezone(array $settings): string {
    $configured = trim((string) ($settings['reservation_timezone'] ?? ''));
    if ($configured !== '' && $this->isValidTimezone($configured)) {
      return $configured;
    }

    $site = (string) $this->configFactory->get('system.date')->get('timezone.default');
    return $site !== '' && $this->isValidTimezone($site) ? $site : 'UTC';
  }

  /**
   * Tells whether a string is an IANA timezone this server knows.
   */
  public function isValidTimezone(string $timezone): bool {
    return in_array($timezone, \DateTimeZone::listIdentifiers(), TRUE);
  }

  /**
   * Tells whether a string is a canonical zero-padded HH:MM value.
   */
  public function isCanonicalTime(string $value): bool {
    return (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value);
  }

  private function positiveInt(mixed $value, int $fallback): int {
    $value = (int) $value;
    return $value > 0 ? $value : $fallback;
  }

  /**
   * Normalises the configured weekdays to sorted ISO-8601 integers.
   */
  private function weekdays(mixed $value): array {
    $days = [];
    foreach (is_array($value) ? $value : [] as $day) {
      $day = (int) $day;
      if ($day >= 1 && $day <= 7) {
        $days[] = $day;
      }
    }
    $days = array_values(array_unique($days));
    sort($days);

    // Monday to Saturday is the documented product default.
    return $days === [] ? [1, 2, 3, 4, 5, 6] : $days;
  }

}
