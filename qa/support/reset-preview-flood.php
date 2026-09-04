<?php

/**
 * @file
 * Clears the transient distance-preview throttle for EVERY client identifier.
 *
 * The throttle is Redis-backed here, and `\Drupal::flood()->clear()` defaults
 * the identifier to the *current* request's client IP — which, run from Drush,
 * is the CLI, not the browser under test. Clearing that way looks successful and
 * leaves the browser's own bucket full, which is how three fresh page loads in a
 * row still answered 429.
 *
 * Deletes only Redis throttle counters for this one event. Operating hours,
 * capacity, buffers, the Dispatch allowlist and every configuration value are
 * untouched.
 */

$event = 'custom_webform_handlers.distance_preview';
$client = \Drupal\redis\ClientFactory::getClient();
$prefix = (string) $client->getOption(\Redis::OPT_PREFIX);
$client->setOption(\Redis::OPT_PREFIX, '');
$keys = $client->keys('*flood*' . $event . '*');
$deleted = $keys ? (int) $client->del($keys) : 0;
$client->setOption(\Redis::OPT_PREFIX, $prefix);

print "throttle event=$event buckets_found=" . count($keys) . " deleted=$deleted\n";
print 'preview_rate_limit=' . var_export(
  \Drupal::config('custom_webform_handlers.settings')->get('preview_rate_limit'),
  TRUE
) . " per 3600s per client IP\n";
print 'dblog watermark wid=' . (int) \Drupal::database()->query(
  'SELECT MAX(wid) FROM {watchdog}'
)->fetchField() . "\n";
