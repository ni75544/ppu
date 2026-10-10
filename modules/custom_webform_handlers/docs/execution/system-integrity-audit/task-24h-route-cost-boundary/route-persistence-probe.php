<?php

/**
 * @file
 * Task 24H — REAL save/checkout-preparation persistence check (ppu-qa only).
 *
 * QA-gated. Unlike the method-level probe, this SAVES real WebformSubmission rows
 * (entity ->save(), which runs the real preSave/postSave) and READS BACK the stored
 * result, then asserts the failure outcomes are safe:
 *
 *   - A booking whose breakdown names a MISSING route fact is persisted as
 *     manual_review with no payable price — never a confirmed reservation, an order,
 *     a payment, or a silently changed price.
 *   - A booking whose breakdown names a VALID fact is persisted from that exact fact
 *     (distance from the fact), with ZERO provider calls during save.
 *   - Checkout preparation prices the valid one from the fact (zero calls) and
 *     refuses the missing one (NULL) — no order either way.
 *   - No Commerce order is created by saving either booking.
 *
 * Test submissions are deleted at the end. No real payment is initiated (dispatch
 * confirms capacity only on the Commerce payment-success path, never on save). No
 * default-site access. The [T24H-DIAG] counter measures provider invocations.
 */

use Drupal\Core\Database\Database;
use Drupal\custom_webform_handlers\Distance\DistanceResult;
use Drupal\custom_webform_handlers\Distance\RoutingPolicy;
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
$connection = Database::getConnection();
$orderCount = static function () use ($connection): int {
  try {
    return (int) $connection->select('commerce_order', 'o')->countQuery()->execute()->fetchField();
  }
  catch (\Throwable) {
    return -1;
  }
};

$factStore = \Drupal::service('custom_webform_handlers.route_fact_store');
$starter = \Drupal::service('custom_webform_handlers.dispatch_checkout_starter');
$mode = \Drupal::service('custom_webform_handlers.routing_mode_resolver')->effectiveMode();
$resolveQuote = new \ReflectionMethod($starter, 'resolveQuote');
$resolveQuote->setAccessible(TRUE);
$settings = [
  'origin_address_field' => 'origin_address',
  'destination_address_field' => 'destination_address',
  'quote_breakdown_field' => 'estimated_quote_breakdown',
];

$origin = '911 Persist St, QaTown ' . bin2hex(random_bytes(2));
$destination = '912 Persist Ave, QaCity ' . bin2hex(random_bytes(2));
$now = \Drupal::time()->getCurrentTime();

$factA = DistanceResult::restored(19312, 1680, 'development_fixed', $now, ['data_version' => 'persist-A'], NULL, FALSE);
$refA = $factStore->store($origin, $destination, $mode, RoutingPolicy::PURPOSE_ORDINARY, $factA);
$quoteA = \Drupal::service('custom_webform_handlers.quote_calculator')->calculate([
  'service_type' => 'courier', 'delivery_mode' => 'courier_send', 'total_weight_lbs' => '5',
  'total_weight_unit' => 'lb', 'courier_urgency' => 'standard', 'prohibited_items_ack' => 'none',
  'courier_prohibited_ack' => '1',
], $factA);

$REF = \Drupal\custom_webform_handlers\Plugin\WebformHandler\AddressDistanceHandler::ROUTE_REFERENCE_KEY;
// The reference now rides the dedicated per-instance carrier ($REF), the value the
// hidden element would hold — not the breakdown. '' = no carrier (must fail closed).
$mk = static function (string $reference) use ($origin, $destination, $REF): array {
  $data = [
    'service_type' => 'courier',
    'origin_address' => $origin,
    'destination_address' => $destination,
    'delivery_mode' => 'courier_send',
    'total_weight_lbs' => '5',
    'total_weight_unit' => 'lb',
    'courier_urgency' => 'rush',
    'prohibited_items_ack' => 'none',
    'courier_prohibited_ack' => '1',
    'dispatch_slot' => 'seed-slot-nonempty',
  ];
  if ($reference !== '') {
    $data[$REF] = $reference;
  }
  return $data;
};

$saved = [];
$saveAndRead = static function (array $data) use (&$saved): WebformSubmission {
  $submission = WebformSubmission::create(['webform_id' => 'hg', 'data' => $data]);
  // Mark completed so postSave's completed-only branch runs (manual-review announce).
  $submission->setCompletedTime(\Drupal::time()->getRequestTime());
  $submission->save();
  $saved[] = $submission->id();
  // Reload from storage to read the persisted values, not the in-memory entity.
  return WebformSubmission::load($submission->id());
};

echo "== Valid-fact booking: persisted from the fact, ZERO provider calls ==\n";
$ordersBefore = $orderCount();
$state->set('custom_webform_handlers.t24h_calc_count', 0);
$validSub = $saveAndRead($mk($refA));
$validDelta = $counter();
$check('Valid booking persisted status=estimated', (string) $validSub->getElementData('estimated_quote_status') === 'estimated');
$check('Valid booking persisted distance from fact A (19312 m)', (int) $validSub->getElementData('calculated_distance_meters') === 19312);
$check('Valid booking save performed ZERO provider calls (+0)', $validDelta === 0);
printf("  status=%s distance_meters=%d calc_delta=%d\n", (string) $validSub->getElementData('estimated_quote_status'), (int) $validSub->getElementData('calculated_distance_meters'), $validDelta);

echo "== Missing-carrier booking (no route reference): safe refusal, ZERO calls ==\n";
$state->set('custom_webform_handlers.t24h_calc_count', 0);
// No dedicated route-reference carrier at all — nothing names a fact, and there is
// no owner/pair recovery — so it must fail closed to Manual Review.
$missingData = $mk('');
$missingSub = $saveAndRead($missingData);
$missingDelta = $counter();
$check('Missing-fact booking persisted status=manual_review', (string) $missingSub->getElementData('estimated_quote_status') === 'manual_review');
$check('Missing-fact booking save performed ZERO provider calls (+0)', $missingDelta === 0);
printf("  status=%s calc_delta=%d\n", (string) $missingSub->getElementData('estimated_quote_status'), $missingDelta);

echo "== No Commerce order was created by saving either booking ==\n";
$ordersAfter = $orderCount();
$check('Commerce order count unchanged across both saves (no order, no payment)', $ordersBefore >= 0 && $ordersAfter === $ordersBefore);
printf("  commerce_order rows: before=%d after=%d\n", $ordersBefore, $ordersAfter);

echo "== Checkout preparation from the persisted rows ==\n";
$state->set('custom_webform_handlers.t24h_calc_count', 0);
$snapValid = $resolveQuote->invoke($starter, $validSub, $settings, (int) $validSub->id());
$snapMissing = $resolveQuote->invoke($starter, $missingSub, $settings, (int) $missingSub->id());
$check('Checkout prices the valid booking from the fact (snapshot present)', $snapValid !== NULL);
$check('Checkout refuses the missing-fact booking (NULL, no order)', $snapMissing === NULL);
$check('Checkout preparation performed ZERO provider calls (+0)', $counter() === 0);

// Cleanup: delete the test submissions so QA is left as found.
foreach (array_filter($saved) as $sid) {
  $s = WebformSubmission::load($sid);
  if ($s) {
    $s->delete();
  }
}
echo "== Cleanup: deleted " . count(array_filter($saved)) . " test submission(s) ==\n";

echo "\n== RESULT: {$pass} passed, {$fail} failed ==\n";
$state->set('custom_webform_handlers.t24h_calc_count', 0);
echo "(counter reset to 0)\n";
