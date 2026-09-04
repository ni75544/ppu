<?php

/**
 * @file
 * The shared pickup-date shell, per service, as the running site publishes it.
 *
 * Read-only verification for Task 10D. Prints two things side by side for each of
 * the seven services: what the resolved BookingProfile publishes to the browser,
 * and what the hg Webform actually asks about dates and times. Those are the two
 * halves of the shell, and the task's claim is that all seven now agree — so the
 * honest way to say so is to print both halves for every service before and after
 * the change and diff the runs.
 *
 * Prints no submitted value, resolves no address, requests no offer and touches no
 * entity. Safe on any site.
 *
 * Usage:
 *   ddev exec vendor/bin/drush php:script qa/support/task10d-shell-state.php
 *   ddev exec vendor/bin/drush --uri=https://ppu-qa.ddev.site \
 *     php:script qa/support/task10d-shell-state.php
 */

use Drupal\webform\Entity\Webform;

$site = \Drupal::service('kernel')->getSitePath();
$webform = Webform::load('hg');
if ($webform === NULL) {
  print "REFUSED: the hg webform is not on this site\n";
  return;
}

$flat = $webform->getElementsDecodedAndFlattened();
$services = array_keys((array) ($flat['service_type']['#options'] ?? []));

print sprintf("site=%s  services=%d\n\n", $site, count($services));

// Half one: the published profile map. This is the entire browser contract —
// BookingProfile::toPublishedArray() withholds the policy id, resource id,
// capacity and price-book key, and the widget decides from what is left.
$resolver = \Drupal::service('custom_webform_handlers.booking_profile_resolver');
print "published profile map (what the browser is allowed to decide from):\n";
foreach ($services as $service) {
  $published = $resolver->resolve($service)->toPublishedArray();
  print sprintf(
    "  %-24s mode=%-14s always_review=%-5s facts=%-28s message=%s\n",
    $service,
    (string) ($published['mode'] ?? '?'),
    !empty($published['always_review']) ? 'yes' : 'no',
    implode(',', array_values((array) ($published['required_facts'] ?? []))) ?: '(none)',
    (string) ($published['review_message'] ?? '') ?: '(none)'
  );
}

// Half two: the date and time questions themselves. An element with no `name=`
// in the rendered form is not a question, whatever its title still says.
$scheduling = ['preferred_service_date', 'preferred_service_time', 'date_flexibility', 'dispatch_slot'];
print "\nscheduling elements (asked = a create role may answer it):\n";
foreach ($scheduling as $key) {
  if (!isset($flat[$key])) {
    print sprintf("  %-24s ABSENT\n", $key);
    continue;
  }
  $element = $flat[$key];
  $roles = array_key_exists('#access_create_roles', $element)
    ? (array) $element['#access_create_roles'] : NULL;
  $asked = $roles !== [] && ($element['#access'] ?? TRUE) !== FALSE;
  print sprintf(
    "  %-24s asked=%-3s required=%-3s create_roles=%-22s states=%s\n",
    $key,
    $asked ? 'yes' : 'no',
    !empty($element['#required']) ? 'yes' : 'no',
    $roles === NULL ? '(unset: everyone)' : '[' . implode(',', $roles) . ']',
    ($element['#states'] ?? []) === []
      ? '(none)'
      : json_encode($element['#states'], JSON_UNESCAPED_SLASHES)
  );
}

// The two retired questions must stay readable where they were already answered.
print "\nhistoric readability of the retired questions:\n";
foreach (['preferred_service_time', 'date_flexibility'] as $key) {
  if (!isset($flat[$key])) {
    continue;
  }
  print sprintf(
    "  %-24s view_roles=%-22s update_roles=%-22s title=%s\n",
    $key,
    array_key_exists('#access_view_roles', $flat[$key])
      ? '[' . implode(',', (array) $flat[$key]['#access_view_roles']) . ']' : '(unset: kept)',
    array_key_exists('#access_update_roles', $flat[$key])
      ? '[' . implode(',', (array) $flat[$key]['#access_update_roles']) . ']' : '(unset: kept)',
    (string) ($flat[$key]['#title'] ?? '')
  );
}
