<?php

namespace Drupal\Tests\custom_webform_handlers\Functional;

use Drupal\Core\Serialization\Yaml;
use Drupal\Tests\BrowserTestBase;
use Drupal\webform\Entity\Webform;
use GuzzleHttp\Cookie\CookieJar;

/**
 * Tests the reservation routes over HTTP only.
 *
 * These tests speak to the endpoints the way the browser does, including the
 * CSRF header, so they cover the contract the JavaScript depends on: booked
 * values are published, a losing Hold gets HTTP 409, and a release cannot reach
 * another session's Hold or a confirmed Reservation.
 *
 * @group custom_webform_handlers
 */
class ReservationControllerTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['webform', 'custom_webform_handlers'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The base path of the reservation routes.
   */
  const BASE = '/custom-webform-handlers/reservation';

  /**
   * Cookies shared by every raw request this visitor makes.
   */
  private ?CookieJar $cookieJar = NULL;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->createReservationWebform();
  }

  /**
   * An anonymous visitor keeps one Hold identity across separate requests.
   *
   * Drupal keeps an anonymous session lazy: with no session cookie it never
   * starts one, so there is no session id to bind a Hold to and no cookie to
   * carry that identity into the next request. Unless the endpoint establishes
   * an identity of its own, an anonymous visitor can neither obtain a Hold nor
   * be recognised as its owner afterwards — which is every visitor this form has.
   */
  public function testAnAnonymousVisitorKeepsOneIdentityAcrossRequests(): void {
    $date = $this->bookableDate();

    $hold = $this->hold($date, '10:00');
    $this->assertSame(200, $hold['status'], $this->describe($hold));
    $this->assertNotEmpty($hold['body']['hold_token'] ?? '');

    // A later, separate request must be recognised as the same owner.
    $release = $this->release($hold['body']['hold_token']);
    $this->assertSame(200, $release['status'], $this->describe($release));
  }

  /**
   * Availability starts empty and is never cached.
   */
  public function testAvailabilityStartsEmptyAndIsPrivate(): void {
    $response = $this->availability($this->bookableDate());

    $this->assertSame(200, $response['status'], $this->describe($response));
    $this->assertSame('success', $response['body']['status']);
    $this->assertSame([], $response['body']['booked']);
    $this->assertStringContainsString('no-store', $response['headers']['cache-control'][0] ?? '');
  }

  /**
   * A held Slot is published as a booked canonical HH:MM value.
   */
  public function testAHeldSlotIsPublishedAsBooked(): void {
    $date = $this->bookableDate();
    $hold = $this->hold($date, '10:00');
    $this->assertSame(200, $hold['status'], $this->describe($hold));
    $this->assertNotEmpty($hold['body']['hold_token']);

    $response = $this->availability($date);
    $this->assertSame(['10:00'], $response['body']['booked']);
  }

  /**
   * A competing Hold receives HTTP 409 and no token.
   */
  public function testACompetingHoldReceives409(): void {
    $date = $this->bookableDate();
    $this->hold($date, '10:00');

    // A second, independent visitor.
    $this->newVisitor();
    $competing = $this->hold($date, '10:00');

    $this->assertSame(409, $competing['status']);
    $this->assertSame('error', $competing['body']['status']);
    $this->assertSame('slot_unavailable', $competing['body']['code']);
    $this->assertArrayNotHasKey('hold_token', $competing['body']);
  }

  /**
   * The hold route refuses a request without the CSRF header.
   *
   * Drupal's header check engages for an authenticated session, which is the
   * case that can be forged from another origin; a session-less request carries
   * no ambient authority to abuse.
   */
  public function testHoldRequiresCsrfHeader(): void {
    $this->drupalLogin($this->drupalCreateUser(['access content']));

    $response = $this->request('POST', self::BASE . '/hold', [
      'webform' => 'reservation_form',
      'date' => $this->bookableDate(),
      'time' => '10:00',
    ], FALSE);

    $this->assertSame(403, $response['status'], $this->describe($response));
  }

  /**
   * The release route refuses a request without the CSRF header.
   */
  public function testReleaseRequiresCsrfHeader(): void {
    $this->drupalLogin($this->drupalCreateUser(['access content']));

    $response = $this->request('POST', self::BASE . '/release', [
      'hold_token' => str_repeat('a', 64),
    ], FALSE);

    $this->assertSame(403, $response['status'], $this->describe($response));
  }

  /**
   * A time the Webform does not offer is refused before any Slot exists.
   */
  public function testUnconfiguredTimeIsRefused(): void {
    $response = $this->hold($this->bookableDate(), '03:15');

    $this->assertSame(422, $response['status']);
    $this->assertSame('error', $response['body']['status']);
  }

  /**
   * A date beyond the booking horizon is refused.
   */
  public function testDateBeyondHorizonIsRefused(): void {
    $beyond = (new \DateTimeImmutable('+400 days'))->format('Y-m-d');
    $response = $this->hold($beyond, '10:00');

    $this->assertSame(422, $response['status']);
  }

  /**
   * An oversized payload is refused before it is parsed.
   */
  public function testOversizedPayloadIsRefused(): void {
    $response = $this->request('POST', self::BASE . '/hold', [
      'webform' => 'reservation_form',
      'date' => $this->bookableDate(),
      'time' => '10:00',
      'padding' => str_repeat('x', 5000),
    ]);

    $this->assertSame(413, $response['status']);
  }

  /**
   * A visitor can release the Hold it owns, which frees the Slot.
   */
  public function testAnOwnerCanReleaseItsOwnHold(): void {
    $date = $this->bookableDate();
    $hold = $this->hold($date, '10:00');

    $release = $this->release($hold['body']['hold_token']);
    $this->assertSame(200, $release['status']);
    $this->assertSame('success', $release['body']['status']);

    $this->assertSame([], $this->availability($date)['body']['booked']);
  }

  /**
   * Another session cannot release a Hold it does not own.
   */
  public function testAnotherSessionCannotRelease(): void {
    $date = $this->bookableDate();
    $hold = $this->hold($date, '10:00');
    $token = $hold['body']['hold_token'];

    $this->newVisitor();
    $release = $this->release($token);

    // The endpoint never confirms whether the token exists, but the Slot must
    // still be booked afterwards.
    $this->assertNotSame(200, $release['status']);
    $this->assertSame(['10:00'], $this->availability($date)['body']['booked']);
  }

  /**
   * The release endpoint never returns owner, submission, or row data.
   */
  public function testResponsesNeverLeakInternalData(): void {
    $date = $this->bookableDate();
    $hold = $this->hold($date, '10:00');

    foreach (['owner_hash', 'owner_id', 'submission_id', 'reservation_id', 'active_slot_key', 'hold_token_hash'] as $leak) {
      $this->assertArrayNotHasKey($leak, $hold['body']);
    }
  }

  /**
   * Availability rejects an unbounded date parameter.
   */
  public function testAvailabilityRejectsMalformedDate(): void {
    $response = $this->availability(str_repeat('9', 200));

    $this->assertSame(400, $response['status']);
  }

  /**
   * Requests an Availability read.
   */
  private function availability(string $date, string $resource = ''): array {
    $query = ['date' => $date];
    if ($resource !== '') {
      $query['resource'] = $resource;
    }
    return $this->request('GET', self::BASE . '/availability/reservation_form', NULL, TRUE, $query);
  }

  /**
   * Requests a Hold.
   */
  private function hold(string $date, string $time, string $resource = ''): array {
    $payload = [
      'webform' => 'reservation_form',
      'date' => $date,
      'time' => $time,
    ];
    if ($resource !== '') {
      $payload['resource'] = $resource;
    }
    return $this->request('POST', self::BASE . '/hold', $payload);
  }

  /**
   * Requests a release.
   */
  private function release(string $token): array {
    return $this->request('POST', self::BASE . '/release', ['hold_token' => $token]);
  }

  /**
   * Describes a response so a failure says what the endpoint actually returned.
   */
  private function describe(array $response): string {
    preg_match('/<title>(.*?)<\/title>/s', (string) ($response['raw'] ?? ''), $title);
    return 'HTTP ' . $response['status'] . ' url=' . ($response['url'] ?? '?')
      . ' title=' . ($title[1] ?? '-') . ' ' . json_encode($response['body']);
  }

  /**
   * Issues a request, optionally with the Drupal CSRF header.
   *
   * The query stays out of $path on purpose: buildUrl() treats its whole
   * argument as a path and percent-encodes the '?', which silently turns a
   * valid request into a 404.
   */
  private function request(string $method, string $path, ?array $payload = NULL, bool $withCsrf = TRUE, array $query = []): array {
    $options = ['http_errors' => FALSE];
    if ($withCsrf) {
      $options['headers']['X-CSRF-Token'] = $this->drupalGet('session/token');
    }
    // One visitor keeps one cookie jar, so the session the hold endpoint starts
    // is still present when the release endpoint has to recognise its owner.
    $options['cookies'] = $this->cookieJar();
    if ($payload !== NULL) {
      $options['headers']['Content-Type'] = 'application/json';
      $options['body'] = json_encode($payload);
    }

    $url = $this->buildUrl($path, $query === [] ? [] : ['query' => $query]);
    $response = $this->getHttpClient()->request($method, $url, $options);
    $raw = (string) $response->getBody();
    $body = json_decode($raw, TRUE);

    // Header names are case-insensitive on the wire; lower-casing them keeps a
    // lookup from silently missing a header the server did send.
    $headers = [];
    foreach ($response->getHeaders() as $name => $values) {
      $headers[strtolower($name)] = $values;
    }

    return [
      'status' => $response->getStatusCode(),
      'headers' => $headers,
      'body' => is_array($body) ? $body : [],
      'raw' => $raw,
      'url' => $url,
    ];
  }

  /**
   * Returns the cookie jar shared by this visitor's requests.
   */
  private function cookieJar(): CookieJar {
    if ($this->cookieJar === NULL) {
      $this->cookieJar = $this->getSessionCookies();
    }
    return $this->cookieJar;
  }

  /**
   * Becomes a second, independent visitor with an empty session.
   */
  private function newVisitor(): void {
    $this->mink->resetSessions();
    $this->cookieJar = new CookieJar();
  }

  /**
   * Returns the next weekday inside the booking horizon.
   */
  private function bookableDate(): string {
    $day = new \DateTimeImmutable('+3 days');
    while ((int) $day->format('N') > 5) {
      $day = $day->modify('+1 day');
    }
    return $day->format('Y-m-d');
  }

  /**
   * Creates a Webform whose handler configures the reservation workflow.
   */
  private function createReservationWebform(): Webform {
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
          '#options' => ['09:00' => '9:00 am', '10:00' => '10:00 am'],
        ],
        'reservation_hold_token' => ['#type' => 'hidden'],
      ]),
    ]);
    $webform->save();

    $webform->addWebformHandler(\Drupal::service('plugin.manager.webform.handler')
      ->createInstance('address_distance_handler', [
        'id' => 'address_distance_handler',
        'handler_id' => 'address_distance_handler',
        'label' => 'Address and Distance Calculator',
        'status' => TRUE,
        'weight' => 0,
        'settings' => [
          'origin_address_field' => 'origin_address',
          'destination_address_field' => 'destination_address',
          'distance_field' => 'calculated_distance',
          'reservation_enabled' => TRUE,
          'reservation_date_field' => 'appointment_date',
          'reservation_time_field' => 'appointment_time',
          'reservation_hold_token_field' => 'reservation_hold_token',
          'reservation_resource_field' => '',
          'reservation_timezone' => 'UTC',
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
