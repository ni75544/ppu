<?php

/**
 * @file
 * The branches of custom_webform_handlers_update_10039(), exercised on purpose.
 *
 * Task 10D requires an idempotent update that "reports missing element keys
 * honestly". Both halves of that are claims about branches a normal run never
 * reaches: the second run, and the run that does not find what it expects. So this
 * drives them deliberately.
 *
 * Three checks:
 *   1. Re-running the whole hook changes nothing. Proven by hashing the profile
 *      configuration and the hg element tree either side of the call, not by
 *      trusting the sentence it returns.
 *   2. The date guarantee reports an absent key instead of creating one.
 *   3. The date guarantee repairs a date question that has been retired, hidden,
 *      gated or made optional — the four ways the shared shell could be silently
 *      broken while every other part of the update looked correct. Gating counts
 *      once per #states arm, so the four faults report five repairs.
 *
 * Checks 2 and 3 run against synthetic element arrays, so they touch no entity.
 * Check 1 calls the real hook, which writes only if something is missing — that is
 * the property under test. It is refused outside ppu-qa all the same.
 *
 * Usage:
 *   ddev exec vendor/bin/drush --uri=https://ppu-qa.ddev.site \
 *     php:script qa/support/task10d-update-branches.php
 */

use Drupal\webform\Entity\Webform;

$site = \Drupal::service('kernel')->getSitePath();
if ($site !== 'sites/qa') {
  print "REFUSED: this script calls an update hook; run it on ppu-qa only (site={$site})\n";
  return;
}

require_once \Drupal::service('extension.list.module')->getPath('custom_webform_handlers')
  . '/custom_webform_handlers.install';

$pass = 0;
$fail = 0;
$check = static function (string $name, bool $ok, string $detail = '') use (&$pass, &$fail): void {
  $ok ? $pass++ : $fail++;
  print sprintf("  [%s] %s%s\n", $ok ? 'PASS' : 'FAIL', $name, $detail === '' ? '' : ' — ' . $detail);
};

/** A fingerprint of everything this hook is allowed to write. */
$fingerprint = static function (): string {
  $profiles = \Drupal::configFactory()->get('custom_webform_handlers.dispatch')
    ->get('booking_profiles');
  $elements = (string) (Webform::load('hg')?->get('elements') ?? '');
  return hash('sha256', json_encode($profiles) . "\0" . $elements);
};

print "1. second run of custom_webform_handlers_update_10039()\n";
$before = $fingerprint();
$sentence = custom_webform_handlers_update_10039();
\Drupal::configFactory()->reset('custom_webform_handlers.dispatch');
\Drupal::entityTypeManager()->getStorage('webform')->resetCache(['hg']);
$after = $fingerprint();
$check('nothing was written', $before === $after,
  $before === $after ? 'profile config and hg elements byte-identical' : 'the second run changed state');
$check('it says so', str_contains($sentence, 'already share one pickup-date shell'),
  substr($sentence, 0, 96));

print "\n2. an absent date question is reported, not created\n";
$elements = ['page_2' => ['preferred_service_time' => ['#type' => 'select']]];
$missing = [];
$changed = custom_webform_handlers_shell_date_10039($elements, $missing);
$check('no change invented', $changed === [], implode('; ', $changed));
$check('the key is named in the report', count($missing) === 1
  && str_starts_with($missing[0], 'preferred_service_date'), implode(', ', $missing));
$check('no element was added', !isset($elements['page_2']['preferred_service_date']));

print "\n3. a broken date question is repaired\n";
$elements = [
  'page_2' => [
    'preferred_service_date' => [
      '#type' => 'date',
      '#access_create_roles' => [],
      '#access' => FALSE,
      '#required' => FALSE,
      '#states' => [
        'visible' => [':input[name="service_type"]' => ['value' => 'courier']],
        'required' => [':input[name="service_type"]' => ['value' => 'courier']],
      ],
    ],
  ],
];
$missing = [];
$changed = custom_webform_handlers_shell_date_10039($elements, $missing);
$repaired = $elements['page_2']['preferred_service_date'];
$check('all five repairs reported', count($changed) === 5, implode(' | ', $changed));
$check('nothing reported missing', $missing === [], implode(', ', $missing));
$check('asked of everyone again', !array_key_exists('#access_create_roles', $repaired)
  && !array_key_exists('#access', $repaired));
$check('ungated', !array_key_exists('#states', $repaired));
$check('required', ($repaired['#required'] ?? NULL) === TRUE);
$check('type untouched', ($repaired['#type'] ?? '') === 'date');

print sprintf("\n%d/%d branch assertions passed\n", $pass, $pass + $fail);
print $fail === 0 ? "BRANCHES GREEN\n" : "BRANCHES RED\n";
