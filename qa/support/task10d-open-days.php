<?php

/**
 * @file
 * The next few days the Dispatch resource actually works, for a browser gate.
 *
 * A gate that hardcodes a calendar date is correct until the date passes, and then
 * it reports "zero pickup times offered" as a failure of the shell when the real
 * finding is that the gate is out of date. That already happened once here: a run
 * on 2026-09-03 asked for 2026-09-01 and read `unavailable`, which is the honest
 * answer to a request for a day gone by.
 *
 * So the dates come from the resource instead. This prints the next open days from
 * the configured working hours, skipping blackout dates and stopping at the booking
 * horizon, with the grid points each day would offer before travel, duration and
 * buffers are subtracted — an upper bound the gate can sanity-check its expected
 * count against, not a substitute for it.
 *
 * Read-only: no entity, submission, reservation or configuration write, and nothing
 * about capacity, duration, pricing or eligibility is changed or inferred. Safe on
 * any site.
 *
 * Usage:
 *   ddev exec vendor/bin/drush php:script qa/support/task10d-open-days.php
 *   ddev exec vendor/bin/drush --uri=https://ppu-qa.ddev.site \
 *     php:script qa/support/task10d-open-days.php
 *
 * Output: one JSON line, then a human summary. The gate reads the JSON line.
 */

$resource = (array) \Drupal::config('custom_webform_handlers.dispatch')->get('resource');
$zone = new DateTimeZone((string) ($resource['timezone'] ?? 'America/New_York'));
$hours = (array) ($resource['working_hours'] ?? []);
$blackout = array_map('strval', array_values((array) ($resource['blackout_dates'] ?? [])));
$grid = max(1, (int) ($resource['grid_minutes'] ?? 30));
$horizon = max(1, (int) ($resource['booking_horizon_days'] ?? 30));

// Tomorrow, not today: a same-day request is cut by lead time in a way that
// depends on the hour the gate happens to run, and a gate whose expected count
// changes over lunch is not a gate.
$day = new DateTimeImmutable('tomorrow', $zone);
$open = [];
for ($i = 0; $i < $horizon && count($open) < 5; $i++, $day = $day->modify('+1 day')) {
  $ymd = $day->format('Y-m-d');
  $window = $hours[(int) $day->format('N')] ?? $hours[$day->format('N')] ?? NULL;
  if (!is_array($window) || in_array($ymd, $blackout, TRUE)) {
    continue;
  }
  $start = new DateTimeImmutable($ymd . ' ' . (string) ($window['start'] ?? '00:00'), $zone);
  $end = new DateTimeImmutable($ymd . ' ' . (string) ($window['end'] ?? '00:00'), $zone);
  $minutes = max(0, (int) round(($end->getTimestamp() - $start->getTimestamp()) / 60));
  $open[] = [
    'date' => $ymd,
    'weekday' => $day->format('D'),
    'start' => (string) ($window['start'] ?? ''),
    'end' => (string) ($window['end'] ?? ''),
    'grid_points' => (int) ceil($minutes / $grid),
  ];
}

print json_encode([
  'site' => \Drupal::service('kernel')->getSitePath(),
  'timezone' => $zone->getName(),
  'grid_minutes' => $grid,
  'today' => (new DateTimeImmutable('now', $zone))->format('Y-m-d'),
  'open_days' => $open,
], JSON_UNESCAPED_SLASHES) . "\n";

print sprintf("\nresource works %s, grid %d min, horizon %d days\n",
  implode(',', array_map(static fn ($n) => (string) $n, array_keys($hours))) ?: '(no days)',
  $grid, $horizon);
foreach ($open as $row) {
  print sprintf("  %s %s  %s-%s  grid points=%d\n",
    $row['date'], $row['weekday'], $row['start'], $row['end'], $row['grid_points']);
}
if ($open === []) {
  print "  no open day inside the horizon\n";
}
