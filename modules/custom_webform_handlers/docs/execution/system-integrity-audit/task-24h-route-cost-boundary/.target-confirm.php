<?php

/**
 * @file
 * Read-only Task 24H target confirmation: which class/site/mode a request reaches.
 * Prints no secrets. Not autoloaded, not wired; a bounded inspection artifact.
 */

$c = \Drupal::getContainer();
$ref = new \ReflectionClass('Drupal\custom_webform_handlers\Controller\DistancePreviewController');
printf("loaded controller file: %s\n", str_replace('\\', '/', $ref->getFileName()));
printf("create() start line: %d\n", $ref->getMethod('create')->getStartLine());
printf("constructor param count: %d\n", $ref->getConstructor()->getNumberOfParameters());

$services = [
  'custom_webform_handlers.route_operation_ledger',
  'custom_webform_handlers.route_request_owner',
  'custom_webform_handlers.route_fact_store',
  'custom_webform_handlers.routing_mode_resolver',
  'custom_webform_handlers.customer_route_entry_provider',
];
foreach ($services as $s) {
  printf("service %s: %s\n", $s, $c->has($s) ? 'present' : 'MISSING');
}

printf("effective routing mode: %s\n", \Drupal::service('custom_webform_handlers.routing_mode_resolver')->effectiveMode());
$db = \Drupal::database();
printf("db driver=%s database=%s\n", $db->driver(), $db->getConnectionOptions()['database'] ?? '?');
