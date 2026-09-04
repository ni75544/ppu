<?php

namespace Drupal\Tests\custom_webform_handlers\Kernel\Reservation;

use Drupal\KernelTests\KernelTestBase;
use Drupal\custom_webform_handlers\Reservation\HeldReservation;
use Drupal\custom_webform_handlers\Reservation\ReservationDefinition;
use Drupal\custom_webform_handlers\Reservation\ReservationException;
use Drupal\custom_webform_handlers\Reservation\ReservationManagerInterface;
use Drupal\custom_webform_handlers\Reservation\ReservationSlot;

/**
 * Tests the reservation seam through its interface only.
 *
 * Every assertion goes through ReservationManagerInterface. Nothing here reads
 * a column, a state string, or an owner hash, so the implementation stays free
 * to change its storage without changing this test.
 *
 * @group custom_webform_handlers
 *
 * @coversDefaultClass \Drupal\custom_webform_handlers\Reservation\DatabaseReservationManager
 */
class DatabaseReservationManagerTest extends KernelTestBase {

  /**
   * Every key adminCounts() must report, whatever the table happens to hold.
   *
   * These are state machine names, which the interface names as part of the
   * returned shape, so asserting them here does not reach past the seam.
   */
  private const COUNT_KEYS = [
    'held',
    'confirmed',
    'blocked',
    'released',
    'expired',
    'total',
  ];

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['custom_webform_handlers'];

  /**
   * The seam under test.
   */
  protected ReservationManagerInterface $manager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('custom_webform_handlers', ['custom_webform_handlers_reservation']);
    $this->manager = $this->container->get('custom_webform_handlers.reservation_manager');
  }

  /**
   * One Slot can be held, and the raw token is returned exactly once.
   */
  public function testASlotCanBeHeld(): void {
    $held = $this->manager->hold($this->slot(), 'session-a');

    $this->assertInstanceOf(HeldReservation::class, $held);
    $this->assertNotSame('', $held->token);
    $this->assertGreaterThan($this->now(), $held->expiresAt);
  }

  /**
   * A competing Hold on the same Slot fails closed with slot_unavailable.
   *
   * This is the double-booking guarantee. The second call must lose on the
   * database unique key, not on anything the caller checked first.
   */
  public function testACompetingHoldIsRefused(): void {
    $slot = $this->slot();
    $this->manager->hold($slot, 'session-a');

    $this->expectException(ReservationException::class);
    $this->expectExceptionMessage(ReservationException::SLOT_UNAVAILABLE);
    $this->manager->hold($slot, 'session-b');
  }

  /**
   * A different Slot on the same day is unaffected by an existing Hold.
   */
  public function testADifferentSlotIsStillAvailable(): void {
    $this->manager->hold($this->slot(), 'session-a');
    $later = $this->slot(strtotime('2026-09-01 15:00:00 UTC'));

    $held = $this->manager->hold($later, 'session-b');
    $this->assertNotSame('', $held->token);
  }

  /**
   * The same Slot under another Resource is a separate capacity.
   */
  public function testResourcesPartitionCapacity(): void {
    $this->manager->hold($this->slot(), 'session-a');
    $other = $this->slot(NULL, 'crew-two');

    $held = $this->manager->hold($other, 'session-b');
    $this->assertNotSame('', $held->token);
  }

  /**
   * An expired Hold releases its active key, so the Slot can be held again.
   */
  public function testAnExpiredHoldFreesTheSlot(): void {
    $slot = $this->slot();
    $this->manager->hold($slot, 'session-a');

    $expired = $this->manager->releaseExpired($this->now() + 601);
    $this->assertSame(1, $expired);

    $held = $this->manager->hold($slot, 'session-b');
    $this->assertNotSame('', $held->token);
  }

  /**
   * Confirmation keeps the Slot unavailable while linking the submission.
   */
  public function testConfirmationRetainsTheSlot(): void {
    $slot = $this->slot();
    $held = $this->manager->hold($slot, 'session-a');

    $this->manager->confirm($held->token, 42);

    $this->expectException(ReservationException::class);
    $this->expectExceptionMessage(ReservationException::SLOT_UNAVAILABLE);
    $this->manager->hold($slot, 'session-b');
  }

  /**
   * An expiry sweep never disturbs a confirmed Reservation.
   */
  public function testExpirySweepSparesConfirmedReservations(): void {
    $slot = $this->slot();
    $held = $this->manager->hold($slot, 'session-a');
    $this->manager->confirm($held->token, 42);

    $this->assertSame(0, $this->manager->releaseExpired($this->now() + 100000));

    $this->expectException(ReservationException::class);
    $this->expectExceptionMessage(ReservationException::SLOT_UNAVAILABLE);
    $this->manager->hold($slot, 'session-b');
  }

  /**
   * An unknown or expired token cannot be confirmed.
   */
  public function testConfirmingAnUnknownHoldFails(): void {
    $this->expectException(ReservationException::class);
    $this->expectExceptionMessage(ReservationException::HOLD_NOT_FOUND);
    $this->manager->confirm(str_repeat('a', 64), 42);
  }

  /**
   * A Hold cannot be confirmed twice.
   */
  public function testAHoldCannotBeConfirmedTwice(): void {
    $held = $this->manager->hold($this->slot(), 'session-a');
    $this->manager->confirm($held->token, 42);

    $this->expectException(ReservationException::class);
    $this->expectExceptionMessage(ReservationException::HOLD_NOT_FOUND);
    $this->manager->confirm($held->token, 43);
  }

  /**
   * Only the owning session can release its own Hold.
   */
  public function testAnotherSessionCannotReleaseAHold(): void {
    $slot = $this->slot();
    $held = $this->manager->hold($slot, 'session-a');

    $this->assertFalse($this->manager->release($held->token, 'session-b'));
    $this->assertTrue($this->manager->verifyHold($slot, $held->token, 'session-a'));
  }

  /**
   * Releasing a Hold frees the Slot for the next visitor.
   */
  public function testReleasingAHoldFreesTheSlot(): void {
    $slot = $this->slot();
    $held = $this->manager->hold($slot, 'session-a');

    $this->assertTrue($this->manager->release($held->token, 'session-a'));

    $next = $this->manager->hold($slot, 'session-b');
    $this->assertNotSame('', $next->token);
  }

  /**
   * A confirmed Reservation is never released by the Hold release path.
   */
  public function testAConfirmedReservationCannotBeReleased(): void {
    $slot = $this->slot();
    $held = $this->manager->hold($slot, 'session-a');
    $this->manager->confirm($held->token, 42);

    $this->assertFalse($this->manager->release($held->token, 'session-a'));

    $this->expectException(ReservationException::class);
    $this->expectExceptionMessage(ReservationException::SLOT_UNAVAILABLE);
    $this->manager->hold($slot, 'session-b');
  }

  /**
   * Administrative list, count, and total queries use identical filters.
   */
  public function testAdministrativeQueriesAgreeOnTheirFilters(): void {
    $this->manager->hold($this->slot(), 'session-a');
    $confirmed = $this->manager->hold($this->slot(strtotime('2026-09-01 15:00:00 UTC')), 'session-b');
    $this->manager->confirm($confirmed->token, 42);
    $this->manager->hold($this->slot(NULL, 'crew-two'), 'session-c');

    $filters = [
      'state' => 'held',
      'webform_id' => 'quote_form',
      'resource_id' => 'default',
      'date' => '2026-09-01',
    ];
    $records = $this->manager->adminList($filters);
    $counts = $this->manager->adminCounts($filters);

    $this->assertCount(1, $records);
    $this->assertSame('held', $records[0]->state);
    $this->assertSame(1, $counts['held']);
    $this->assertSame(1, $counts['total']);
    $this->assertSame(count($records), $this->manager->adminTotal($filters));

    $invalid = ['state' => str_repeat('x', 17)];
    $this->assertSame([], $this->manager->adminList($invalid));
    $this->assertSame(0, $this->manager->adminCounts($invalid)['total']);
    $this->assertSame(0, $this->manager->adminTotal($invalid));
  }

  /**
   * Counts always report every state, so a dashboard can render zeros.
   *
   * The interface promises that a state with no rows is present with a zero
   * count rather than absent. Both counting branches are checked, because the
   * undated branch groups in SQL and can only ever see states that exist, while
   * the dated branch counts in PHP.
   */
  public function testAdminCountsAlwaysReportEveryState(): void {
    $empty = $this->manager->adminCounts([]);
    $this->assertEqualsCanonicalizing(self::COUNT_KEYS, array_keys($empty));
    foreach (self::COUNT_KEYS as $key) {
      $this->assertSame(0, $empty[$key], $key);
    }
    $this->assertSame(0, $this->manager->adminTotal([]));
    $this->assertSame([], $this->manager->adminList([]));

    $this->manager->hold($this->slot(), 'session-a');

    foreach ([[], ['date' => '2026-09-01']] as $filters) {
      $counts = $this->manager->adminCounts($filters);
      $this->assertEqualsCanonicalizing(self::COUNT_KEYS, array_keys($counts));
      $this->assertSame(1, $counts['held']);
      $this->assertSame(1, $counts['total']);
      // The four states with no rows are still reported.
      $this->assertSame(0, $counts['confirmed']);
      $this->assertSame(0, $counts['blocked']);
      $this->assertSame(0, $counts['released']);
      $this->assertSame(0, $counts['expired']);
    }
  }

  /**
   * Every state a Slot can reach is counted under its own key.
   *
   * One row is driven into each of the five states through the interface, so a
   * miscounted or collapsed state shows up here rather than on the admin screen.
   */
  public function testAdminCountsCoverEveryReservationState(): void {
    // The expired row comes first: the sweep that lapses its Hold would lapse
    // every other standing Hold too, so nothing else may exist yet.
    $this->manager->hold($this->slot(strtotime('2026-09-01 09:00:00 UTC')), 'session-e');
    $this->assertSame(1, $this->manager->releaseExpired($this->now() + 601));

    $released = $this->manager->hold($this->slot(strtotime('2026-09-01 10:00:00 UTC')), 'session-r');
    $this->assertTrue($this->manager->release($released->token, 'session-r'));

    $confirmed = $this->manager->hold($this->slot(strtotime('2026-09-01 11:00:00 UTC')), 'session-c');
    $this->manager->confirm($confirmed->token, 42);

    $this->manager->block($this->slot(strtotime('2026-09-01 12:00:00 UTC')));
    $this->manager->hold($this->slot(strtotime('2026-09-01 13:00:00 UTC')), 'session-h');

    $counts = $this->manager->adminCounts([]);
    $this->assertEqualsCanonicalizing(self::COUNT_KEYS, array_keys($counts));
    $this->assertSame(1, $counts['held']);
    $this->assertSame(1, $counts['confirmed']);
    $this->assertSame(1, $counts['blocked']);
    $this->assertSame(1, $counts['released']);
    $this->assertSame(1, $counts['expired']);
    $this->assertSame(5, $counts['total']);

    // The total a pager would trust must match the rows a page actually shows.
    $this->assertSame(5, $this->manager->adminTotal([]));
    $this->assertCount(5, $this->manager->adminList([]));

    // Narrowing to one state narrows the total with it.
    $this->assertSame(1, $this->manager->adminTotal(['state' => 'confirmed']));
    $this->assertSame(1, $this->manager->adminCounts(['state' => 'confirmed'])['confirmed']);
    $this->assertSame(0, $this->manager->adminCounts(['state' => 'confirmed'])['held']);
  }

  /**
   * Counting and totalling honour the day the visitor saw, not the UTC day.
   *
   * Two rows share one instant under different stored timezones, so any query
   * that bounded the day in UTC alone would put both on the same calendar day.
   */
  public function testAdminCountsAndTotalUseTheVisitorsLocalDate(): void {
    // 02:00 on 2 September in UTC is 22:00 on 1 September in New York.
    $instant = strtotime('2026-09-02 02:00:00 UTC');
    $this->manager->hold($this->zonedSlot('America/New_York', $instant), 'session-ny');
    $this->manager->hold($this->slot($instant, 'crew-two'), 'session-utc');
    $this->assertSame(2, $this->manager->adminTotal([]));

    $newYorkDay = ['date' => '2026-09-01'];
    $records = $this->manager->adminList($newYorkDay);
    $this->assertCount(1, $records);
    $this->assertSame('America/New_York', $records[0]->timezone);
    $this->assertSame('2026-09-01', $records[0]->localDate());
    $this->assertSame(1, $this->manager->adminCounts($newYorkDay)['held']);
    $this->assertSame(1, $this->manager->adminCounts($newYorkDay)['total']);
    $this->assertSame(count($records), $this->manager->adminTotal($newYorkDay));

    $utcDay = ['date' => '2026-09-02'];
    $utcRecords = $this->manager->adminList($utcDay);
    $this->assertCount(1, $utcRecords);
    $this->assertSame('UTC', $utcRecords[0]->timezone);
    $this->assertSame('2026-09-02', $utcRecords[0]->localDate());
    $this->assertSame(1, $this->manager->adminCounts($utcDay)['total']);
    $this->assertSame(count($utcRecords), $this->manager->adminTotal($utcDay));

    // A day inside the UTC window the query scans, but on neither row's own
    // calendar, must come back empty rather than borrowing a neighbour's row.
    $emptyDay = ['date' => '2026-09-03'];
    $this->assertSame([], $this->manager->adminList($emptyDay));
    $this->assertSame(0, $this->manager->adminCounts($emptyDay)['total']);
    $this->assertSame(0, $this->manager->adminTotal($emptyDay));
  }

  /**
   * A malformed filter matches nothing instead of widening to everything.
   *
   * A value longer than its column, or a date that is not a date, cannot
   * describe any stored row. Dropping such a filter would turn a bad request
   * into a listing of every reservation on the site, so each one must be
   * refused by all three administrative queries alike.
   */
  public function testMalformedAdminFiltersMatchNothing(): void {
    $this->manager->hold($this->slot(), 'session-a');
    $this->assertSame(1, $this->manager->adminTotal([]));

    $malformed = [
      'day-first date' => ['date' => '01-09-2026'],
      'unpadded date' => ['date' => '2026-9-1'],
      'nonsense date' => ['date' => 'tomorrow'],
      'over-long state' => ['state' => str_repeat('x', 17)],
      'over-long webform id' => ['webform_id' => str_repeat('w', 129)],
      'over-long resource id' => ['resource_id' => str_repeat('r', 129)],
      'valid date, over-long state' => [
        'date' => '2026-09-01',
        'state' => str_repeat('x', 17),
      ],
    ];

    foreach ($malformed as $label => $filters) {
      $this->assertSame([], $this->manager->adminList($filters), $label);
      $this->assertSame(0, $this->manager->adminTotal($filters), $label);

      $counts = $this->manager->adminCounts($filters);
      $this->assertSame(0, $counts['total'], $label);
      // A refused filter set still reports the whole state list, so the screen
      // shows zeros rather than a half-built table.
      $this->assertEqualsCanonicalizing(self::COUNT_KEYS, array_keys($counts), $label);
      foreach (self::COUNT_KEYS as $key) {
        $this->assertSame(0, $counts[$key], $label . ': ' . $key);
      }
    }
  }

  /**
   * Hold verification is bound to the Slot, the token, and the owner.
   */
  public function testHoldVerificationIsBoundToOwnerAndSlot(): void {
    $slot = $this->slot();
    $held = $this->manager->hold($slot, 'session-a');

    $this->assertTrue($this->manager->verifyHold($slot, $held->token, 'session-a'));
    $this->assertFalse($this->manager->verifyHold($slot, $held->token, 'session-b'));
    $this->assertFalse($this->manager->verifyHold($slot, str_repeat('b', 64), 'session-a'));
    $this->assertFalse($this->manager->verifyHold($this->slot(NULL, 'crew-two'), $held->token, 'session-a'));
  }

  /**
   * Availability reports held and confirmed Slots as booked HH:MM values.
   */
  public function testAvailabilityReportsBookedValues(): void {
    $this->manager->hold($this->slot(), 'session-a');
    $confirmed = $this->manager->hold($this->slot(strtotime('2026-09-01 15:00:00 UTC')), 'session-b');
    $this->manager->confirm($confirmed->token, 42);

    $booked = $this->manager->availability(
      $this->definition(),
      new \DateTimeImmutable('2026-09-01', new \DateTimeZone('UTC')),
    );

    $this->assertContains('14:00', $booked);
    $this->assertContains('15:00', $booked);
  }

  /**
   * Availability never reports another day, Resource, or expired Hold.
   */
  public function testAvailabilityIsScopedAndExcludesExpiredHolds(): void {
    $this->manager->hold($this->slot(), 'session-a');
    $this->manager->hold($this->slot(NULL, 'crew-two'), 'session-b');
    $this->manager->releaseExpired($this->now() + 601);

    $this->assertSame([], $this->manager->availability(
      $this->definition(),
      new \DateTimeImmutable('2026-09-01', new \DateTimeZone('UTC')),
    ));

    $held = $this->manager->hold($this->slot(), 'session-c');
    $this->assertSame(['14:00'], $this->manager->availability(
      $this->definition(),
      new \DateTimeImmutable('2026-09-01', new \DateTimeZone('UTC')),
    ));
    $this->assertSame([], $this->manager->availability(
      $this->definition(),
      new \DateTimeImmutable('2026-09-02', new \DateTimeZone('UTC')),
    ));
    $this->assertSame([], $this->manager->availability(
      $this->definition()->withResource('crew-two'),
      new \DateTimeImmutable('2026-09-01', new \DateTimeZone('UTC')),
    ));
    $this->assertNotSame('', $held->token);
  }

  /**
   * Builds a Slot the way the definition provider would.
   */
  private function slot(?int $start = NULL, string $resource = 'default'): ReservationSlot {
    $start = $start ?? strtotime('2026-09-01 14:00:00 UTC');
    return new ReservationSlot('quote_form', $resource, $start, $start + 3600, 'UTC', 600);
  }

  /**
   * Builds a Slot stored under a specific timezone.
   *
   * The instant is the same one every other helper would produce; only the
   * timezone the row remembers differs, which is what decides the local day.
   */
  private function zonedSlot(string $timezone, int $start, string $resource = 'default'): ReservationSlot {
    return new ReservationSlot('quote_form', $resource, $start, $start + 3600, $timezone, 600);
  }

  /**
   * Builds the matching definition for availability queries.
   */
  private function definition(): ReservationDefinition {
    return new ReservationDefinition(
      'quote_form',
      'default',
      'UTC',
      60,
      600,
      90,
      [1, 2, 3, 4, 5, 6],
      ['14:00', '15:00'],
    );
  }

  private function now(): int {
    return (int) $this->container->get('datetime.time')->getRequestTime();
  }

}
