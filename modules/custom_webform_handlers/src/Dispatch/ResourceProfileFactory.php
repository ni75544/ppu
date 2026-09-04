<?php

namespace Drupal\custom_webform_handlers\Dispatch;

use Drupal\Core\Config\ConfigFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Builds the Resource Profile the server plans against, from configuration only.
 *
 * This exists so that no caller can supply a Resource. offerSlots() is handed a
 * profile because a read-only availability answer may legitimately be asked about
 * a named Resource, but a Hold and a confirmation must not be: accepting a
 * client-supplied capacity, timezone, working interval, buffer, or depot would let
 * a request describe the very calendar it is trying to fit into. So the claim and
 * confirmation paths build their profile here, from
 * custom_webform_handlers.dispatch, and nowhere else.
 *
 * Every reading is fail-closed. A missing, non-numeric, or out-of-range figure
 * yields NULL — no profile, therefore no Slots, therefore no Hold — rather than a
 * substituted default. A default would be a business rule invented in code, which
 * is exactly what the configuration object exists to prevent.
 *
 * One exception is worth naming: an empty working_hours map is not an error. A
 * Resource with no worked weekdays is a Resource that is closed, and the schedule
 * seam already expresses that as "no day", so it is passed through rather than
 * refused.
 */
final class ResourceProfileFactory {

  /**
   * The operating profile.
   */
  const SETTINGS = 'custom_webform_handlers.dispatch';

  /**
   * Resource identifiers are machine names, and are compared as exact strings.
   *
   * Bounded and restricted because the identifier reaches a varchar_ascii column
   * and a lock key. A value that cannot round-trip through both is not a Resource
   * this planner can serialize work against.
   */
  const RESOURCE_ID_PATTERN = '/^[a-z0-9][a-z0-9_\-]{0,127}$/';

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Builds the configured Resource Profile, or NULL when it is unusable.
   *
   * @return \Drupal\custom_webform_handlers\Dispatch\ResourceProfile|null
   *   The profile, or NULL when configuration cannot yield one that can be
   *   planned against. NULL is a refusal, not an absence of opinion.
   */
  public function create(): ?ResourceProfile {
    $resource = $this->configFactory->get(self::SETTINGS)->get('resource');
    if (!is_array($resource)) {
      $this->logger->error('Dispatch configuration has no resource profile, so no Slots can be planned.');
      return NULL;
    }

    $resourceId = trim((string) ($resource['id'] ?? ''));
    if (preg_match(self::RESOURCE_ID_PATTERN, $resourceId) !== 1) {
      $this->logger->error('Dispatch resource identifier is missing or not a usable machine name, so no Slots can be planned.');
      return NULL;
    }

    $capacity = $this->boundedInt($resource['capacity'] ?? NULL, 1);
    $gridMinutes = $this->boundedInt($resource['grid_minutes'] ?? NULL, 1);
    $repositioning = $this->boundedInt($resource['repositioning_buffer_minutes'] ?? NULL, 0);
    $operational = $this->boundedInt($resource['operational_buffer_minutes'] ?? NULL, 0);
    $horizon = $this->boundedInt($resource['booking_horizon_days'] ?? NULL, 0);
    if ($capacity === NULL || $gridMinutes === NULL || $repositioning === NULL || $operational === NULL || $horizon === NULL) {
      // Named without values. Which figure is wrong is useful to an operator; the
      // figures themselves are operational settings and are already visible to
      // anyone who can read the configuration, but there is no reason to copy
      // them into the log to say one of them does not parse.
      $this->logger->error('Dispatch resource @resource has a missing or out-of-range capacity, grid, buffer, or horizon, so no Slots can be planned.', [
        '@resource' => $resourceId,
      ]);
      return NULL;
    }

    $blackoutDates = $this->localDates($resource['blackout_dates'] ?? []);
    if ($blackoutDates === NULL) {
      // A blackout that cannot be read is worse than no blackout at all: the
      // comparison downstream is a strict string match, so an unreadable entry
      // would simply never match and the closed day would be offered.
      $this->logger->error('Dispatch resource @resource has an unreadable blackout date, so no Slots can be planned.', [
        '@resource' => $resourceId,
      ]);
      return NULL;
    }

    $timezone = trim((string) ($resource['timezone'] ?? ''));

    try {
      return new ResourceProfile(
        $resourceId,
        $capacity,
        $timezone,
        trim((string) ($resource['depot_address'] ?? '')),
        $gridMinutes,
        $this->workingHours($resource['working_hours'] ?? []),
        $this->intervals($resource['breaks'] ?? []),
        $blackoutDates,
        $repositioning,
        $operational,
        $horizon,
        (bool) ($resource['return_to_base'] ?? FALSE),
      );
    }
    catch (\InvalidArgumentException $e) {
      // Today this is only the timezone check, which refuses rather than falling
      // back to UTC. Reported without the exception message so a configuration
      // value is not copied into the log by an unrelated future validation.
      $this->logger->error('Dispatch resource @resource could not be constructed from configuration, so no Slots can be planned.', [
        '@resource' => $resourceId,
      ]);
      return NULL;
    }
  }

  /**
   * Builds the configured profile only when it is the Resource being asked about.
   *
   * The comparison is the whole point. A caller naming a Resource must not be
   * able to have its request planned against a different one, and while only one
   * Resource exists today, silently ignoring the name would make the eventual
   * second Resource a security change rather than a configuration change.
   *
   * @return \Drupal\custom_webform_handlers\Dispatch\ResourceProfile|null
   *   The profile, or NULL when it is unusable or names a different Resource.
   */
  public function createForResource(string $resourceId): ?ResourceProfile {
    $profile = $this->create();
    if ($profile === NULL) {
      return NULL;
    }

    return $profile->resourceId === trim($resourceId) ? $profile : NULL;
  }

  /**
   * Normalises the working-hours map, keeping only readable weekday entries.
   *
   * Weekday keys are kept as strings because ResourceProfile looks them up that
   * way. A malformed interval is dropped rather than refused, which closes that
   * weekday: the schedule seam already refuses a date whose interval will not
   * parse, and dropping it here means the same thing — the day is not worked.
   *
   * @return array<string, array{start: string, end: string}>
   *   Local HH:MM bounds by ISO-8601 weekday number.
   */
  private function workingHours(mixed $hours): array {
    if (!is_array($hours)) {
      return [];
    }

    $normalised = [];
    foreach ($hours as $weekday => $interval) {
      $weekday = (int) $weekday;
      if ($weekday < 1 || $weekday > 7 || !is_array($interval)) {
        continue;
      }
      $normalised[(string) $weekday] = [
        'start' => trim((string) ($interval['start'] ?? '')),
        'end' => trim((string) ($interval['end'] ?? '')),
      ];
    }

    return $normalised;
  }

  /**
   * Normalises a list of local intervals, such as breaks.
   *
   * Entries are passed through as strings without being validated here, because
   * the schedule seam validates them against a specific date and refuses the whole
   * date when one will not parse. Dropping a malformed break here instead would
   * schedule straight through it.
   *
   * @return array<int, array{start: string, end: string}>
   *   Local HH:MM bounds, in configured order.
   */
  private function intervals(mixed $intervals): array {
    if (!is_array($intervals)) {
      return [];
    }

    $normalised = [];
    foreach ($intervals as $interval) {
      if (!is_array($interval)) {
        // Deliberately preserved as an unreadable entry rather than skipped, so
        // the schedule seam refuses every date instead of quietly losing a break.
        $normalised[] = ['start' => '', 'end' => ''];
        continue;
      }
      $normalised[] = [
        'start' => trim((string) ($interval['start'] ?? '')),
        'end' => trim((string) ($interval['end'] ?? '')),
      ];
    }

    return $normalised;
  }

  /**
   * Normalises a list of local Y-m-d dates, or refuses the list.
   *
   * @return string[]|null
   *   Normalised dates, or NULL when an entry cannot be read as one.
   */
  private function localDates(mixed $dates): ?array {
    if (!is_array($dates)) {
      return NULL;
    }

    $normalised = [];
    foreach ($dates as $date) {
      if (!is_string($date) && !is_int($date)) {
        return NULL;
      }
      $date = trim((string) $date);
      if ($date === '') {
        continue;
      }
      // Strict: PHP would roll 2026-02-30 into March, and a closure on a date
      // nobody configured is as wrong as a missing one.
      $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('UTC'));
      if (!$parsed instanceof \DateTimeImmutable || $parsed->format('Y-m-d') !== $date) {
        return NULL;
      }
      $normalised[] = $date;
    }

    return array_values(array_unique($normalised));
  }

  /**
   * Reads a configured integer that must be present and at least a minimum.
   */
  private function boundedInt(mixed $value, int $minimum): ?int {
    if (!is_numeric($value)) {
      return NULL;
    }
    $value = (int) $value;

    return $value >= $minimum ? $value : NULL;
  }

}
