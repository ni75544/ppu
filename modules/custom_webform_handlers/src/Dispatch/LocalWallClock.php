<?php

namespace Drupal\custom_webform_handlers\Dispatch;

/**
 * Reads an operator's wall-clock entry as one exact instant, or refuses it.
 *
 * Staff type a local date and a local time. Every scheduling decision downstream
 * is arithmetic on UTC instants, so something has to bridge the two — and that
 * bridge is where a whole class of quiet errors lives, because PHP's date parser
 * is helpful by default. It accepts '25:70' and rolls it forward into the next
 * day, it fills unnamed fields from the current time, and it moves a local time
 * that daylight saving skipped over. Each of those turns "unreadable" into "some
 * other moment", which is exactly the substitution a schedule must never make.
 *
 * So this is deliberately strict, and it is one implementation rather than one
 * per caller: a Blackout boundary, a Replan arrival and a staff confirmation all
 * have to read a typed time the same way, or the same keystrokes would mean
 * different instants on different screens.
 *
 * Nothing here decides whether the instant is any good. Working hours, breaks,
 * the booking horizon and occupancy are the planner's judgement; this only says
 * whether the operator's keystrokes name a real moment in a real zone.
 */
final class LocalWallClock {

  /**
   * Resolves one local date and wall-clock time into a UTC instant.
   *
   * @param string $localDate
   *   A Y-m-d date as the operator typed it.
   * @param string $time
   *   An H:i time as the operator typed it.
   * @param \DateTimeZone $zone
   *   The zone to read both in, which is always a Resource's own zone.
   *
   * @return int|null
   *   The UTC instant, or NULL when the entry does not name a real local moment.
   */
  public static function instant(string $localDate, string $time, \DateTimeZone $zone): ?int {
    $localDate = trim($localDate);
    $time = trim($time);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $localDate) || !preg_match('/^\d{2}:\d{2}$/', $time)) {
      return NULL;
    }

    // The '!' zeroes every field the format does not name. Without it the instant
    // would carry whatever second the request happened on, and a boundary an
    // operator did not choose is not a boundary they can reason about.
    $wall = $localDate . ' ' . $time;
    $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $wall, $zone);

    // The round trip is the actual check. A rolled-over time comes back as a
    // different string, and so does a local time this zone skips for daylight
    // saving — both are refused here rather than silently moved.
    if (!$parsed instanceof \DateTimeImmutable || $parsed->format('Y-m-d H:i') !== $wall) {
      return NULL;
    }

    return $parsed->getTimestamp();
  }

  /**
   * Tells whether a string is a calendar date that survives being reformatted.
   *
   * A shape check alone accepts 2026-02-31, which PHP rolls into March. Callers
   * that only need to validate a date — a listing range, a form element — use
   * this rather than inventing a second rule about what a date is.
   */
  public static function isDate(string $localDate): bool {
    $localDate = trim($localDate);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $localDate)) {
      return FALSE;
    }

    $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $localDate, new \DateTimeZone('UTC'));

    return $parsed instanceof \DateTimeImmutable && $parsed->format('Y-m-d') === $localDate;
  }

  /**
   * Gives a typed time the two-digit shape instant() insists on, or refuses it.
   *
   * Operators type 9:30, and refusing that would be pedantry: it names exactly one
   * moment and nothing has to be guessed to know which. Everything else is refused
   * rather than interpreted — 9, 0930, half nine, 09:30:00 — because each of those
   * would need a minute or a second chosen on the operator's behalf.
   *
   * Whether the padded result is a real local moment is still instant()'s business.
   * This only settles the shape, in one place, so the Blackout form, the Replan form
   * and the staff confirmation cannot come to different conclusions about it.
   *
   * @param string $raw
   *   The time exactly as it was typed.
   *
   * @return string|null
   *   The same time as HH:MM, or NULL when it was not a 24-hour time at all.
   */
  public static function normaliseTime(string $raw): ?string {
    if (!preg_match('/^(\d{1,2}):(\d{2})$/', trim($raw), $matches)) {
      return NULL;
    }

    return sprintf('%02d:%02d', (int) $matches[1], (int) $matches[2]);
  }

}
