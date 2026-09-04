<?php

namespace Drupal\custom_webform_handlers\Dispatch\Schedule;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\custom_webform_handlers\Dispatch\ResourceDay;
use Drupal\custom_webform_handlers\Dispatch\ResourceProfile;

/**
 * Resolves a Resource Profile's local calendar rules for one date.
 *
 * Every rule read here arrives on the ResourceProfile, which is built from
 * custom_webform_handlers.dispatch. There is no hour, weekday, grid, horizon, or
 * blackout literal in this class, so an operator changes the operating profile
 * without a code change.
 *
 * The single job is to turn local wall-clock rules into UTC integers, once, so no
 * scheduling arithmetic downstream ever touches a timezone. The only clock this
 * class needs of its own is "now", for the booking horizon.
 *
 * Every refusal is total. A date is either a usable working day or it is nothing:
 * there is no half-open day, because a partially trusted day is what produces a
 * Slot the business cannot actually serve. In particular a malformed break is a
 * refusal of the whole date rather than a break that quietly disappears — an
 * ignored lunch break would be scheduled straight over.
 */
final class ProfileResourceSchedule implements ResourceScheduleInterface {

  /**
   * Local times are accepted only as a 24-hour HH:MM wall-clock reading.
   *
   * Strict on purpose. A loose parse would read '8' or '8:0' or '25:00' as
   * something, and a working day that starts at an hour nobody configured is
   * worse than a day that is refused.
   */
  const TIME_PATTERN = '/^([01][0-9]|2[0-3]):([0-5][0-9])$/';

  public function __construct(
    private readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function dayFor(ResourceProfile $profile, string $localDate): ?ResourceDay {
    // A grid of zero or less has no candidate times at all, and a negative
    // horizon cannot be compared against. Both mean the profile is not usable
    // rather than that this particular date is closed, but the answer a caller
    // needs is the same: nothing can be offered.
    if ($profile->gridMinutes <= 0 || $profile->bookingHorizonDays < 0) {
      return NULL;
    }

    $zone = $profile->timezoneObject();
    $date = $this->parseLocalDate($localDate, $zone);
    if ($date === NULL) {
      return NULL;
    }

    // The horizon is measured in local days, not in elapsed hours, because that
    // is how an operator means it: "thirty days out" is a date on the calendar
    // the business keeps, not a moment 720 hours from now.
    $today = (new \DateTimeImmutable('@' . $this->time->getRequestTime()))
      ->setTimezone($zone)
      ->setTime(0, 0, 0);
    if ($date < $today) {
      return NULL;
    }
    if ($date > $today->modify('+' . $profile->bookingHorizonDays . ' days')) {
      return NULL;
    }

    // Compare the normalised date, so an equivalent-but-differently-written
    // input cannot slip past a configured closure.
    $normalisedDate = $date->format('Y-m-d');
    if ($profile->isBlackout($normalisedDate)) {
      return NULL;
    }

    $hours = $profile->workingHoursFor((int) $date->format('N'));
    if ($hours === NULL) {
      // A weekday absent from the profile is not worked. That is how a closed
      // day is expressed, and it is not an error.
      return NULL;
    }

    $workStart = $this->atLocalTime($date, $hours['start'] ?? '');
    $workEnd = $this->atLocalTime($date, $hours['end'] ?? '');
    if ($workStart === NULL || $workEnd === NULL || $workEnd <= $workStart) {
      return NULL;
    }

    $breaks = $this->breaksFor($profile, $date, $workStart, $workEnd);
    if ($breaks === NULL) {
      return NULL;
    }

    return new ResourceDay(
      $normalisedDate,
      $workStart->getTimestamp(),
      $workEnd->getTimestamp(),
      $breaks,
      $profile->gridMinutes,
    );
  }

  /**
   * Resolves the profile's breaks against one date, or refuses the date.
   *
   * @return array<int, array{start: int, end: int}>|null
   *   Ascending UTC intervals that overlap the working interval, or NULL when a
   *   configured break could not be read and the date must be refused.
   */
  private function breaksFor(ResourceProfile $profile, \DateTimeImmutable $date, \DateTimeImmutable $workStart, \DateTimeImmutable $workEnd): ?array {
    $breaks = [];

    foreach ($profile->breaks as $break) {
      if (!is_array($break)) {
        return NULL;
      }
      $start = $this->atLocalTime($date, $break['start'] ?? '');
      $end = $this->atLocalTime($date, $break['end'] ?? '');
      if ($start === NULL || $end === NULL || $end <= $start) {
        // Refusing the day is the conservative reading. Dropping the break would
        // offer Slots straight through it.
        return NULL;
      }

      // A break entirely outside the working interval constrains nothing, so it
      // is dropped rather than carried as noise the planner must re-check.
      if ($end <= $workStart || $start >= $workEnd) {
        continue;
      }

      $breaks[] = [
        'start' => $start->getTimestamp(),
        'end' => $end->getTimestamp(),
      ];
    }

    usort($breaks, static fn(array $a, array $b): int => $a['start'] <=> $b['start']);

    return $breaks;
  }

  /**
   * Parses a Y-m-d date strictly, in the Resource's zone.
   *
   * The re-format comparison is what makes it strict: PHP will happily roll
   * 2026-02-30 forward into March, and a date the caller did not ask for must not
   * be planned against.
   */
  private function parseLocalDate(string $localDate, \DateTimeZone $zone): ?\DateTimeImmutable {
    $localDate = trim($localDate);
    if ($localDate === '') {
      return NULL;
    }

    // The leading '!' resets every unspecified field, so the result is local
    // midnight rather than midnight-with-today's-clock-time.
    $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $localDate, $zone);
    if (!$date instanceof \DateTimeImmutable || $date->format('Y-m-d') !== $localDate) {
      return NULL;
    }

    return $date;
  }

  /**
   * Places a local HH:MM reading on one date, or refuses it.
   *
   * The post-set comparison catches a spring-forward gap: a wall-clock time that
   * does not exist on that date silently rolls forward, and a working day that
   * begins at an hour the clock skipped is not a day the business keeps.
   */
  private function atLocalTime(\DateTimeImmutable $date, mixed $time): ?\DateTimeImmutable {
    $time = trim((string) $time);
    if (preg_match(self::TIME_PATTERN, $time) !== 1) {
      return NULL;
    }

    [$hours, $minutes] = explode(':', $time);
    $resolved = $date->setTime((int) $hours, (int) $minutes, 0);

    return $resolved->format('H:i') === $time ? $resolved : NULL;
  }

}
