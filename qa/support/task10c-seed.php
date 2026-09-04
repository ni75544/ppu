<?php

/**
 * @file
 * Seeds QA-only Dispatch ledger rows for the Reservation Dashboard checks.
 *
 * QA site only, and only through the store's own write API: claim() to take a
 * Hold, confirm() to turn one into a Reservation, release() to give one back.
 * Nothing here writes the ledger table directly, so every row it produces was
 * accepted by the same guards a real booking passes.
 *
 * The four rows are chosen to make the dashboard's empty-cell and access rules
 * falsifiable rather than merely plausible: one row carrying every optional
 * value, one carrying none of them, one live Hold with no submission behind it,
 * and one released row. Two stored zeros are included deliberately, because a
 * repositioning of 0 must print as 0 and not as "not measured".
 *
 * Writes qa/tmp/task10c-seed.json so the teardown removes exactly what this
 * created and nothing else.
 *
 * QA site only. The fixture the two Task 10C gates read; re-run it, then
 * qa/support/task10c-rbac.php, to give the browser gate a fixture again after the
 * QA data has been cleaned up.
 */

use Drupal\custom_webform_handlers\Dispatch\DispatchClaim;
use Drupal\custom_webform_handlers\Dispatch\DispatchWindow;
use Drupal\custom_webform_handlers\Dispatch\Slot;
use Drupal\webform\Entity\WebformSubmission;

$store = \Drupal::service('custom_webform_handlers.dispatch_reservation_store');
$database = \Drupal::database();
$zone = new \DateTimeZone('America/New_York');
$resource = 'driver-01';
$webform = 'hg';

$at = static fn (string $local): int => (new \DateTimeImmutable($local, $zone))->getTimestamp();

// Two submissions, saved as drafts so the form's own handler leaves them alone:
// an incomplete submission confirms no Hold and announces nothing. Neither
// carries an address, so no route lookup is attempted either.
$submissions = [];
foreach ([
  'contact' => [
    'full_name' => 'QA Tencee Persona',
    'phone_number' => '+1 555 0100',
    'email' => 'qa-10c@example.invalid',
  ],
  'blank' => [],
] as $key => $data) {
  $submission = WebformSubmission::create([
    'webform_id' => $webform,
    'in_draft' => TRUE,
    'data' => $data,
  ]);
  $submission->save();
  $submissions[$key] = (int) $submission->id();
}
$plan = [
  [
    'label' => 'confirmed-full',
    'local' => '2026-09-08 09:00',
    'minutes' => 120,
    'reposition' => [1800, 1800],
    'pickup' => '1 QA Pickup Way, Bronx, NY 10451',
    'delivery' => '2 QA Dropoff Road, Yonkers, NY 10701',
    'route' => [2700, 24140],
    'price' => ['qa-10c-snapshot-1', 148500, 'USD'],
    'confirm' => 'contact',
  ],
  [
    'label' => 'confirmed-sparse',
    'local' => '2026-09-09 14:30',
    'minutes' => 90,
    // Both zero on purpose: a stored 0 must print as 0.
    'reposition' => [0, 0],
    'pickup' => '',
    'delivery' => '',
    'route' => [NULL, NULL],
    'price' => [NULL, NULL, NULL],
    'confirm' => 'blank',
  ],
  [
    'label' => 'held-live',
    'local' => '2026-09-07 10:00',
    'minutes' => 120,
    'reposition' => [900, 900],
    'pickup' => '3 QA Hold Street, Mount Vernon, NY 10550',
    'delivery' => '4 QA Hold Lane, New Rochelle, NY 10801',
    'route' => [1500, 11265],
    'price' => ['qa-10c-snapshot-3', 96000, 'USD'],
    'confirm' => NULL,
  ],
  [
    'label' => 'released',
    'local' => '2026-09-07 15:00',
    'minutes' => 60,
    'reposition' => [900, 900],
    'pickup' => '5 QA Released Court, Pelham, NY 10803',
    'delivery' => '6 QA Released Way, Rye, NY 10580',
    'route' => [900, 7240],
    'price' => [NULL, NULL, NULL],
    'confirm' => 'release',
  ],
];
$reservations = [];
foreach ($plan as $index => $row) {
  $start = $at($row['local']);
  $slot = new Slot($webform, $resource, $start, 'America/New_York');
  $window = new DispatchWindow(
    $start,
    $start + ($row['minutes'] * 60),
    30,
    (int) round($row['minutes'] / 4),
    30,
    15,
  );
  $owner = 'qa-10c-owner-' . $index;

  $held = $store->claim(new DispatchClaim(
    $slot,
    $window,
    $row['reposition'][0],
    $row['reposition'][1],
    $owner,
    3600,
    $row['pickup'],
    $row['delivery'],
    $row['route'][0],
    $row['route'][1],
    $row['price'][0],
    $row['price'][1],
    $row['price'][2],
  ));

  if (!$held->isHeld()) {
    print sprintf("%-18s REFUSED %s\n", $row['label'], $held->reason->value);
    continue;
  }

  $id = (int) $database->select('custom_webform_handlers_dispatch_reservation', 'r')
    ->fields('r', ['dispatch_reservation_id'])
    ->condition('resource_id', $resource)
    ->condition('window_start', $start)
    ->orderBy('dispatch_reservation_id', 'DESC')
    ->range(0, 1)
    ->execute()
    ->fetchField();
  $reservations[] = $id;
  $outcome = 'held';
  if ($row['confirm'] === 'release') {
    $outcome = $store->release($held->hold->token, $owner) ? 'released' : 'release refused';
  }
  elseif ($row['confirm'] !== NULL) {
    $result = $store->confirm($held->hold->token, $owner, $submissions[$row['confirm']]);
    $outcome = $result->isConfirmed()
      ? 'confirmed against submission ' . $submissions[$row['confirm']]
      : 'confirm refused: ' . $result->reason->value;
  }

  print sprintf("%-18s id=%-4d %s\n", $row['label'], $id, $outcome);
}

$manifest = [
  'created' => date('c'),
  'site' => 'ppu-qa',
  'reservations' => $reservations,
  'submissions' => array_values($submissions),
];
file_put_contents('qa/tmp/task10c-seed.json', json_encode($manifest, JSON_PRETTY_PRINT) . "\n");

print "\nledger now:\n";
$counts = $database->query('SELECT state, COUNT(*) AS n FROM {custom_webform_handlers_dispatch_reservation} GROUP BY state ORDER BY state')->fetchAllKeyed();
foreach ($counts as $state => $n) {
  print sprintf("  %-12s %d\n", $state, $n);
}
print 'manifest: ' . implode(', ', $reservations) . ' | submissions ' . implode(', ', $submissions) . "\n";
