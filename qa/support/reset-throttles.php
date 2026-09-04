<?php

/**
 * @file
 * Clears this module's transient Redis throttle buckets for every client.
 *
 * Two separate throttles gate a Courier walk: the distance-preview event in
 * DistancePreviewController and 'custom_webform_handlers.dispatch_<operation>'
 * in DispatchController::allow(). Both key on sha256(client IP), so
 * \Drupal::flood()->clear() run from Drush clears the CLI's bucket and leaves
 * the browser's full — which is how a fresh page load still answers 429. This
 * deletes the counters directly, for this module's events only.
 *
 * Operating hours, capacity, buffers, the Dispatch allowlist, payment and every
 * configuration value are untouched. Nothing here is persistent state.
 *
 * Usage: ddev exec vendor/bin/drush php:script qa/support/reset-throttles.php
 */

$client = \Drupal\redis\ClientFactory::getClient();
$prefix = (string) $client->getOption(\Redis::OPT_PREFIX);
$client->setOption(\Redis::OPT_PREFIX, '');
$keys = $client->keys('*flood*custom_webform_handlers*');
$deleted = $keys ? (int) $client->del($keys) : 0;
$client->setOption(\Redis::OPT_PREFIX, $prefix);

print 'throttle buckets_found=' . count($keys) . " deleted=$deleted\n";
print 'dblog watermark wid=' . (int) \Drupal::database()->query(
  'SELECT MAX(wid) FROM {watchdog}'
)->fetchField() . "\n";
