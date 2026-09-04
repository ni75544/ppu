<?php

namespace Drupal\Tests\custom_webform_handlers\Kernel\Reservation;

use Drupal\Core\Config\Schema\SchemaCheckTrait;
use Drupal\Core\Serialization\Yaml;
use Drupal\KernelTests\KernelTestBase;
use Drupal\custom_webform_handlers\Reservation\ReservationDefinition;
use Drupal\custom_webform_handlers\Reservation\ReservationException;
use Drupal\custom_webform_handlers\Reservation\ReservationDefinitionProvider;
use Drupal\custom_webform_handlers\Reservation\ReservationSlot;
use Drupal\webform\Entity\Webform;

/**
 * Tests that only server-configured input can become a canonical Slot.
 *
 * Everything goes through the provider's public methods. The point of these
 * tests is that a client-supplied schedule is never trusted: the date, time,
 * resource, horizon, weekday, and DST rules all come from the Webform's own
 * handler configuration, and every invalid input fails closed.
 *
 * @group custom_webform_handlers
 *
 * @coversDefaultClass \Drupal\custom_webform_handlers\Reservation\ReservationDefinitionProvider
 */
class ReservationDefinitionProviderTest extends KernelTestBase {

  use SchemaCheckTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'path_alias',
    'webform',
    'custom_webform_handlers',
  ];

  /**
   * The seam under test.
   */
  protected ReservationDefinitionProvider $provider;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('webform_submission');
    // Webform keeps its per-form submission serial in its own table, and
    // installing Webform's configuration writes to that table immediately, so
    // the schema has to exist first.
    $this->installSchema('webform', ['webform']);
    $this->installSchema('custom_webform_handlers', ['custom_webform_handlers_reservation']);
    $this->installConfig(['system', 'webform']);
    $this->provider = $this->container->get('custom_webform_handlers.reservation_definition_provider');
  }

  /**
   * A configured Webform yields a definition built from the server's own rules.
   */
  public function testDefinitionComesFromHandlerConfiguration(): void {
    $this->createReservationWebform();

    $definition = $this->provider->definition('reservation_form');

    $this->assertInstanceOf(ReservationDefinition::class, $definition);
    $this->assertSame('reservation_form', $definition->webformId);
    $this->assertSame('default', $definition->resourceId);
    $this->assertSame('America/New_York', $definition->timezone);
    $this->assertSame(60, $definition->durationMinutes);
    $this->assertSame(600, $definition->holdSeconds);
    $this->assertSame(90, $definition->bookingWindowDays);
    $this->assertSame([1, 2, 3, 4, 5], $definition->allowedWeekdays);
    $this->assertSame(['09:00', '10:00', '14:00'], $definition->timeOptions);
  }

  /**
   * A Webform without the handler enabled has no reservation definition.
   */
  public function testDisabledReservationFailsClosed(): void {
    $this->createReservationWebform(['reservation_enabled' => FALSE]);

    $this->expectException(ReservationException::class);
    $this->expectExceptionMessage(ReservationException::NOT_CONFIGURED);
    $this->provider->definition('reservation_form');
  }

  /**
   * An unknown Webform never produces a definition.
   */
  public function testUnknownWebformFailsClosed(): void {
    $this->expectException(ReservationException::class);
    $this->expectExceptionMessage(ReservationException::NOT_CONFIGURED);
    $this->provider->definition('no_such_form');
  }

  /**
   * A configured date and time become one canonical UTC Slot.
   *
   * 2026-09-03 10:00 in America/New_York is EDT (UTC-4), so the canonical UTC
   * start is 14:00.
   */
  public function testValidInputBecomesACanonicalUtcSlot(): void {
    $this->createReservationWebform();
    $definition = $this->provider->definition('reservation_form');

    $slot = $this->provider->slot($definition, '2026-09-03', '10:00');

    $this->assertInstanceOf(ReservationSlot::class, $slot);
    $this->assertSame(strtotime('2026-09-03 14:00:00 UTC'), $slot->startTimestamp);
    $this->assertSame(strtotime('2026-09-03 15:00:00 UTC'), $slot->endTimestamp);
    $this->assertSame('10:00', $slot->localTime());
    $this->assertSame('2026-09-03', $slot->localDate());
  }

  /**
   * The same wall-clock time in winter resolves to a different UTC instant.
   *
   * 2026-12-03 10:00 in America/New_York is EST (UTC-5), so the canonical UTC
   * start is 15:00. A naive implementation that stored local time, or applied a
   * fixed offset, would silently book the wrong hour half the year.
   */
  public function testStandardTimeResolvesToADifferentUtcInstant(): void {
    $this->createReservationWebform(['reservation_booking_window_days' => 400]);
    $definition = $this->provider->definition('reservation_form');

    $slot = $this->provider->slot($definition, '2026-12-03', '10:00');

    $this->assertSame(strtotime('2026-12-03 15:00:00 UTC'), $slot->startTimestamp);
    $this->assertSame('10:00', $slot->localTime());
  }

  /**
   * A malformed date is refused before any Slot exists.
   *
   * @dataProvider malformedDateProvider
   */
  public function testMalformedDateFailsClosed(string $date): void {
    $this->createReservationWebform();
    $definition = $this->provider->definition('reservation_form');

    $this->expectException(ReservationException::class);
    $this->expectExceptionMessage(ReservationException::INVALID_DATE);
    $this->provider->slot($definition, $date, '10:00');
  }

  /**
   * Dates that must never parse.
   */
  public static function malformedDateProvider(): array {
    return [
      'empty' => [''],
      'wrong separator' => ['2026/09/03'],
      'day first' => ['03-09-2026'],
      'impossible day' => ['2026-02-30'],
      'impossible month' => ['2026-13-01'],
      'partial' => ['2026-09'],
      'trailing text' => ['2026-09-03 or later'],
      'sql fragment' => ["2026-09-03' OR '1'='1"],
    ];
  }

  /**
   * A time outside the Webform's own option keys is refused.
   *
   * @dataProvider malformedTimeProvider
   */
  public function testUnconfiguredTimeFailsClosed(string $time): void {
    $this->createReservationWebform();
    $definition = $this->provider->definition('reservation_form');

    $this->expectException(ReservationException::class);
    $this->provider->slot($definition, '2026-09-03', $time);
  }

  /**
   * Times that must never be accepted for this Webform.
   */
  public static function malformedTimeProvider(): array {
    return [
      'not an option' => ['11:00'],
      'seconds' => ['10:00:00'],
      'unpadded' => ['9:00'],
      'twelve hour' => ['10:00 AM'],
      'empty' => [''],
      'out of range' => ['25:00'],
      'nonsense' => ['ten'],
    ];
  }

  /**
   * A date beyond the booking horizon is refused.
   */
  public function testDateBeyondHorizonFailsClosed(): void {
    $this->createReservationWebform(['reservation_booking_window_days' => 7]);
    $definition = $this->provider->definition('reservation_form');
    $beyond = (new \DateTimeImmutable('+30 days', new \DateTimeZone('America/New_York')))->format('Y-m-d');

    $this->expectException(ReservationException::class);
    $this->expectExceptionMessage(ReservationException::OUTSIDE_HORIZON);
    $this->provider->slot($definition, $beyond, '10:00');
  }

  /**
   * A date in the past is refused.
   */
  public function testPastDateFailsClosed(): void {
    $this->createReservationWebform();
    $definition = $this->provider->definition('reservation_form');
    $past = (new \DateTimeImmutable('-1 day', new \DateTimeZone('America/New_York')))->format('Y-m-d');

    $this->expectException(ReservationException::class);
    $this->expectExceptionMessage(ReservationException::OUTSIDE_HORIZON);
    $this->provider->slot($definition, $past, '10:00');
  }

  /**
   * A weekday the handler does not open for booking is refused.
   *
   * 2026-09-05 is a Saturday, and the fixture allows Monday to Friday only.
   */
  public function testClosedWeekdayFailsClosed(): void {
    $this->createReservationWebform();
    $definition = $this->provider->definition('reservation_form');

    $this->expectException(ReservationException::class);
    $this->expectExceptionMessage(ReservationException::WEEKDAY_CLOSED);
    $this->provider->slot($definition, '2026-09-05', '10:00');
  }

  /**
   * A local time that the spring-forward jump skipped is refused explicitly.
   *
   * America/New_York jumps 02:00 to 03:00 on 2027-03-14, so 02:30 never
   * happens. Accepting it would silently book 03:30 or 01:30.
   */
  public function testNonexistentLocalTimeFailsClosed(): void {
    $this->createReservationWebform([
      'reservation_time_field' => 'appointment_time_dst',
      'reservation_booking_window_days' => 800,
      'reservation_allowed_weekdays' => [1, 2, 3, 4, 5, 6, 7],
    ]);
    $definition = $this->provider->definition('reservation_form');

    $this->expectException(ReservationException::class);
    $this->expectExceptionMessage(ReservationException::TIME_NONEXISTENT);
    $this->provider->slot($definition, '2027-03-14', '02:30');
  }

  /**
   * A local time the fall-back repeats is refused explicitly.
   *
   * America/New_York repeats 01:00 to 02:00 on 2026-11-01, so 01:30 happens
   * twice. Choosing one silently would book an hour the visitor did not pick.
   */
  public function testAmbiguousLocalTimeFailsClosed(): void {
    $this->createReservationWebform([
      'reservation_time_field' => 'appointment_time_dst',
      'reservation_booking_window_days' => 800,
      'reservation_allowed_weekdays' => [1, 2, 3, 4, 5, 6, 7],
    ]);
    $definition = $this->provider->definition('reservation_form');

    $this->expectException(ReservationException::class);
    $this->expectExceptionMessage(ReservationException::TIME_AMBIGUOUS);
    $this->provider->slot($definition, '2026-11-01', '01:30');
  }

  /**
   * A resource outside the configured options is refused.
   */
  public function testUnknownResourceFailsClosed(): void {
    $this->createReservationWebform();

    $this->expectException(ReservationException::class);
    $this->expectExceptionMessage(ReservationException::INVALID_RESOURCE);
    $this->provider->definition('reservation_form', 'not-a-crew');
  }

  /**
   * A configured resource partitions the definition.
   */
  public function testConfiguredResourceIsAccepted(): void {
    $this->createReservationWebform();

    $definition = $this->provider->definition('reservation_form', 'crew_two');

    $this->assertSame('crew_two', $definition->resourceId);
  }

  /**
   * A select element with canonical HH:MM keys is the only usable time field.
   */
  public function testSelectWithCanonicalKeysIsAcceptedAsTimeField(): void {
    $webform = $this->createReservationWebform();

    $this->assertSame('', $this->provider->timeElementProblem($webform, 'appointment_time'));
  }

  /**
   * Radios are refused, so the disabled-option behavior stays honest.
   *
   * The browser labels booked slots by disabling <option> elements. An element
   * that renders no options cannot show that state, so allowing it here would
   * leave the visitor with no way to see what is taken.
   */
  public function testRadiosAreRefusedAsTimeField(): void {
    $webform = $this->createReservationWebform();

    $this->assertSame('not_select', $this->provider->timeElementProblem($webform, 'appointment_time_radios'));
  }

  /**
   * A textfield can never enumerate slots and is refused.
   */
  public function testTextfieldIsRefusedAsTimeField(): void {
    $webform = $this->createReservationWebform();

    $this->assertSame('not_select', $this->provider->timeElementProblem($webform, 'origin_address'));
  }

  /**
   * A select whose keys are not canonical HH:MM is refused.
   */
  public function testNonCanonicalOptionKeysAreRefused(): void {
    $webform = $this->createReservationWebform();

    $this->assertSame('non_canonical_options', $this->provider->timeElementProblem($webform, 'appointment_time_loose'));
  }

  /**
   * A select with no options at all is refused.
   */
  public function testEmptyOptionsAreRefused(): void {
    $webform = $this->createReservationWebform();

    $this->assertSame('no_options', $this->provider->timeElementProblem($webform, 'appointment_time_empty'));
  }

  /**
   * A missing element is refused rather than assumed usable.
   */
  public function testMissingTimeElementIsRefused(): void {
    $webform = $this->createReservationWebform();

    $this->assertSame('missing', $this->provider->timeElementProblem($webform, 'no_such_element'));
    $this->assertSame('missing', $this->provider->timeElementProblem($webform, ''));
  }

  /**
   * An incompatible time field yields no definition at runtime either.
   *
   * Configuration validation and the runtime path must agree: if the element
   * cannot enumerate slots, the whole workflow stays off rather than half on.
   */
  public function testIncompatibleTimeFieldDisablesTheDefinition(): void {
    $this->createReservationWebform(['reservation_time_field' => 'appointment_time_radios']);

    $this->expectException(ReservationException::class);
    $this->expectExceptionMessage(ReservationException::NOT_CONFIGURED);
    $this->provider->definition('reservation_form');
  }

  /**
   * The handler's settings are declared in typed configuration schema.
   *
   * Webform supplies only a `webform.handler.*` fallback that types no keys at
   * all, so without an explicit declaration every setting this handler writes —
   * reservation settings included — is stored as "missing schema". Any site with
   * strict schema checking then refuses to save the Webform, which takes the
   * whole reservation workflow down with it.
   */
  public function testHandlerSettingsAreDeclaredInConfigSchema(): void {
    $definition = $this->container->get('config.typed')
      ->getDefinition('webform.handler.address_distance_handler');

    $this->assertNotSame(
      'undefined',
      $definition['type'] ?? 'undefined',
      'The handler declares its own typed configuration schema.',
    );

    $mapping = $definition['mapping'] ?? [];
    foreach ([
      'reservation_enabled',
      'reservation_date_field',
      'reservation_time_field',
      'reservation_hold_token_field',
      'reservation_resource_field',
      'reservation_timezone',
      'reservation_duration_minutes',
      'reservation_hold_seconds',
      'reservation_booking_window_days',
      'reservation_allowed_weekdays',
    ] as $key) {
      $this->assertArrayHasKey($key, $mapping, "Setting $key is typed.");
    }
  }

  /**
   * The module's own shipped settings validate against its own schema.
   *
   * Installing this module is the first thing every reservation test does, and a
   * default value the schema does not declare makes that installation throw on
   * any site with strict schema checking. Validating the shipped file keeps the
   * two from drifting apart.
   */
  public function testShippedSettingsMatchConfigSchema(): void {
    $path = $this->container->get('extension.list.module')
      ->getPath('custom_webform_handlers');
    $data = Yaml::decode(file_get_contents($path . '/config/install/custom_webform_handlers.settings.yml'));

    $result = $this->checkConfigSchema(
      $this->container->get('config.typed'),
      'custom_webform_handlers.settings',
      $data,
    );

    $this->assertTrue(
      $result,
      is_array($result) ? implode('; ', array_keys($result)) : 'No schema found.',
    );
  }

  /**
   * Creates a Webform whose handler configures the reservation workflow.
   */
  private function createReservationWebform(array $overrides = []): Webform {
    $webform = Webform::create([
      'id' => 'reservation_form',
      'title' => 'Reservation form',
      'elements' => Yaml::encode([
        'origin_address' => ['#type' => 'textfield', '#title' => 'Origin'],
        'destination_address' => ['#type' => 'textfield', '#title' => 'Destination'],
        'calculated_distance' => ['#type' => 'hidden'],
        'appointment_date' => ['#type' => 'date', '#title' => 'Date'],
        'appointment_time' => [
          '#type' => 'select',
          '#title' => 'Time',
          '#options' => [
            '09:00' => '9:00 am',
            '10:00' => '10:00 am',
            '14:00' => '2:00 pm',
          ],
        ],
        'appointment_crew' => [
          '#type' => 'select',
          '#title' => 'Crew',
          '#options' => [
            'crew_one' => 'Crew one',
            'crew_two' => 'Crew two',
          ],
        ],
        // Deliberately unusable time fields, kept on the same Webform so the
        // element-compatibility rule can be exercised without new fixtures.
        'appointment_time_radios' => [
          '#type' => 'radios',
          '#title' => 'Time (radios)',
          '#options' => ['09:00' => '9:00 am', '10:00' => '10:00 am'],
        ],
        'appointment_time_loose' => [
          '#type' => 'select',
          '#title' => 'Time (loose keys)',
          '#options' => ['morning' => 'Morning', '9am' => '9 am'],
        ],
        'appointment_time_empty' => [
          '#type' => 'select',
          '#title' => 'Time (no options)',
          '#options' => [],
        ],
        // The only element that offers the two daylight-saving wall clocks, so
        // the DST tests reach the timezone logic instead of stopping at the
        // option-membership check that guards every other request.
        'appointment_time_dst' => [
          '#type' => 'select',
          '#title' => 'Time (daylight-saving edges)',
          '#options' => [
            '01:30' => '1:30 am',
            '02:30' => '2:30 am',
            '10:00' => '10:00 am',
          ],
        ],
        'reservation_hold_token' => ['#type' => 'hidden'],
      ]),
    ]);
    $webform->save();

    $webform->addWebformHandler($this->container
      ->get('plugin.manager.webform.handler')
      ->createInstance('address_distance_handler', [
        'id' => 'address_distance_handler',
        'handler_id' => 'address_distance_handler',
        'label' => 'Address and Distance Calculator',
        'status' => TRUE,
        'weight' => 0,
        'settings' => $overrides + [
          'origin_address_field' => 'origin_address',
          'destination_address_field' => 'destination_address',
          'distance_field' => 'calculated_distance',
          'reservation_enabled' => TRUE,
          'reservation_date_field' => 'appointment_date',
          'reservation_time_field' => 'appointment_time',
          'reservation_hold_token_field' => 'reservation_hold_token',
          'reservation_resource_field' => 'appointment_crew',
          'reservation_timezone' => 'America/New_York',
          'reservation_duration_minutes' => 60,
          'reservation_hold_seconds' => 600,
          'reservation_booking_window_days' => 90,
          'reservation_allowed_weekdays' => [1, 2, 3, 4, 5],
        ],
      ]));
    $webform->save();

    return $webform;
  }

}
