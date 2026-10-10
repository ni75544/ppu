<?php

/**
 * @file
 * Task 24H — METHOD-LEVEL verification of exact route-fact continuity through the
 * dedicated per-instance route-reference carrier (no owner/address-pair fallback).
 *
 * QA-gated. The reference reaches the gate/save/checkout via the dedicated hidden
 * element's value — form_state->getValue('custom_route_reference') at the gate, and
 * $submission->getElementData('custom_route_reference') at save/checkout. There is
 * NO recovery by owner or address pair: a form carries exactly the reference it was
 * given, or it is refused.
 *
 * Verifies (the user's checklist):
 *  - Explicit refA -> fact A (19312 m), refB -> fact B (88888 m): exact selection.
 *  - Two forms, same addresses, different references keep their respective facts.
 *  - A later preview for the same pair (fact C) cannot change a form still carrying
 *    refA — it still resolves A.
 *  - Missing carrier -> refusal everywhere, ZERO provider calls.
 *
 * Method-level (reflection); DB persistence is proven by route-persistence-probe.php,
 * full-form advance by the browser pass. The [T24H-DIAG] counter measures provider
 * invocations. No commit, no config/DB write beyond the counter key and this owner's
 * private tempstore.
 */

use Drupal\Core\Database\Database;
use Drupal\Core\Form\FormState;
use Drupal\custom_webform_handlers\Distance\DistanceResult;
use Drupal\custom_webform_handlers\Distance\RoutingPolicy;
use Drupal\custom_webform_handlers\Plugin\WebformHandler\AddressDistanceHandler;
use Drupal\webform\Entity\WebformSubmission;

$db = Database::getConnection()->getConnectionOptions()['database'] ?? '';
if ($db !== 'qa') {
  echo "REFUSED: not the QA database (found '{$db}').\n";
  return;
}

$pass = 0;
$fail = 0;
$check = function (string $label, bool $ok) use (&$pass, &$fail) {
  $ok ? $pass++ : $fail++;
  echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . "\n";
};

$state = \Drupal::state();
$counter = static fn () => (int) \Drupal::state()->get('custom_webform_handlers.t24h_calc_count', 0);
$REF = AddressDistanceHandler::ROUTE_REFERENCE_KEY;

$webform = \Drupal::entityTypeManager()->getStorage('webform')->load('hg');
$handler = NULL;
foreach ($webform->getHandlers() as $h) {
  if (is_a($h, 'Drupal\\custom_webform_handlers\\Plugin\\WebformHandler\\AddressDistanceHandler')) {
    $handler = $h;
    break;
  }
}
$factStore = \Drupal::service('custom_webform_handlers.route_fact_store');
$quoteCalc = \Drupal::service('custom_webform_handlers.quote_calculator');
$starter = \Drupal::service('custom_webform_handlers.dispatch_checkout_starter');
$mode = \Drupal::service('custom_webform_handlers.routing_mode_resolver')->effectiveMode();
$now = \Drupal::time()->getCurrentTime();

$origin = '909 Continuity St, QaTown';
$destination = '910 Continuity Ave, QaCity';

// Two valid facts for the SAME pair, DIFFERENT values (hand-built: fixed-dev values
// would be identical).
$factA = DistanceResult::restored(19312, 1680, 'development_fixed', $now, ['data_version' => 'A'], NULL, FALSE);
$factB = DistanceResult::restored(88888, 5400, 'development_fixed', $now, ['data_version' => 'B'], NULL, FALSE);
$refA = $factStore->store($origin, $destination, $mode, RoutingPolicy::PURPOSE_ORDINARY, $factA);
$refB = $factStore->store($origin, $destination, $mode, RoutingPolicy::PURPOSE_ORDINARY, $factB);

$courier = [
  'service_type' => 'courier', 'origin_address' => $origin, 'destination_address' => $destination,
  'delivery_mode' => 'courier_send', 'total_weight_lbs' => '5', 'total_weight_unit' => 'lb',
  'courier_urgency' => 'rush', 'prohibited_items_ack' => 'none', 'courier_prohibited_ack' => '1',
  'dispatch_slot' => 'seed-slot-nonempty',
];
$quoteA = $quoteCalc->calculate($courier, $factA);

$preSave = new \ReflectionMethod($handler, 'preSave');
$preSave->setAccessible(TRUE);
$gate = new \ReflectionMethod($handler, 'assertAcceptedDispatchQuote');
$gate->setAccessible(TRUE);
$resolveQuote = new \ReflectionMethod($starter, 'resolveQuote');
$resolveQuote->setAccessible(TRUE);
$settings = ['origin_address_field' => 'origin_address', 'destination_address_field' => 'destination_address', 'quote_breakdown_field' => 'estimated_quote_breakdown'];

// preSave persists the distance of whichever fact the reference resolved — the
// cleanest witness of WHICH fact was selected.
$distanceForRef = function (string $ref) use ($handler, $preSave, $courier, $REF): int {
  $data = $courier;
  $data[$REF] = $ref;
  $sub = WebformSubmission::create(['webform_id' => 'hg', 'data' => $data]);
  $preSave->invoke($handler, $sub, TRUE);
  return (int) $sub->getElementData('calculated_distance_meters');
};

echo "== Exact selection by reference (never by pair) ==\n";
$state->set('custom_webform_handlers.t24h_calc_count', 0);
$b = $counter();
$dA = $distanceForRef($refA);
$dB = $distanceForRef($refB);
$check('refA resolves fact A (19312 m)', $dA === 19312);
$check('refB resolves fact B (88888 m)', $dB === 88888);
$check('exact-reference selection performs ZERO provider calls (+0)', ($counter() - $b) === 0);

echo "== Two forms, same addresses, different references keep their own facts ==\n";
$check('form1(refA) keeps A, form2(refB) keeps B', $distanceForRef($refA) === 19312 && $distanceForRef($refB) === 88888);

echo "== A later preview for the same pair cannot change a form carrying refA ==\n";
$factC = DistanceResult::restored(31111, 2000, 'development_fixed', $now, ['data_version' => 'C'], NULL, FALSE);
$refC = $factStore->store($origin, $destination, $mode, RoutingPolicy::PURPOSE_ORDINARY, $factC);
$check('after storing fact C, refA still resolves A (19312), not C', $distanceForRef($refA) === 19312);
$check('refC resolves C (31111)', $distanceForRef($refC) === 31111);

echo "== Gate accepts when the carried reference + accepted breakdown agree ==\n";
$state->set('custom_webform_handlers.t24h_calc_count', 0);
$b = $counter();
$acceptData = $courier;
$acceptData[$REF] = $refA;
$acceptData['estimated_quote_breakdown'] = json_encode($quoteA->toArray());
$fs = new FormState();
$fs->setValues($acceptData);
$gate->invoke($handler, $fs, WebformSubmission::create(['webform_id' => 'hg', 'data' => $acceptData]));
$check('Gate accepts (no estimate-update error) via the carried reference', $fs->getErrors() === []);
$check('Gate performs ZERO provider calls (+0)', ($counter() - $b) === 0);

echo "== Checkout prices from the carried reference, ZERO calls ==\n";
$state->set('custom_webform_handlers.t24h_calc_count', 0);
$b = $counter();
$coData = $courier; $coData[$REF] = $refA;
$snap = $resolveQuote->invoke($starter, WebformSubmission::create(['webform_id' => 'hg', 'data' => $coData]), $settings, 0);
$check('Checkout produced a snapshot from the carried reference', $snap !== NULL);
$check('Checkout performs ZERO provider calls (+0)', ($counter() - $b) === 0);

echo "== Missing carrier: refused everywhere, ZERO calls ==\n";
$state->set('custom_webform_handlers.t24h_calc_count', 0);
$b = $counter();
$noRef = $courier; // no custom_route_reference at all
$fs2 = new FormState();
$fs2->setValues($noRef);
$gate->invoke($handler, $fs2, WebformSubmission::create(['webform_id' => 'hg', 'data' => $noRef]));
$check('Gate refuses a missing carrier (refresh-required)', $fs2->getErrors() !== []);
$subNo = WebformSubmission::create(['webform_id' => 'hg', 'data' => $noRef]);
$preSave->invoke($handler, $subNo, TRUE);
$check('preSave marks manual_review for a missing carrier', (string) $subNo->getElementData('estimated_quote_status') === 'manual_review');
$snapNo = $resolveQuote->invoke($starter, WebformSubmission::create(['webform_id' => 'hg', 'data' => $noRef]), $settings, 0);
$check('Checkout refuses (NULL) for a missing carrier', $snapNo === NULL);
$check('Missing-carrier refusals perform ZERO provider calls (+0)', ($counter() - $b) === 0);

echo "\n== RESULT: {$pass} passed, {$fail} failed ==\n";
$state->set('custom_webform_handlers.t24h_calc_count', 0);
echo "(counter reset to 0)\n";
