<?php

namespace Drupal\Tests\custom_webform_handlers\FunctionalJavascript;

use Drupal\Core\Serialization\Yaml;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\webform\Entity\Webform;

/**
 * Tests that a live Hold survives a Webform AJAX reattach.
 *
 * A Webform rebuild re-runs every behavior against fresh DOM. The Hold itself
 * lives on the server for its whole window, so discarding the browser's token on
 * reattach would strand the Slot until it expired and force the visitor to pick
 * a time again. These tests pin that boundary: a rebuild preserves the token, a
 * slot change replaces it, and clearing the slot gives it up.
 *
 * @group custom_webform_handlers
 */
class ReservationSlotsTokenTest extends WebDriverTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['webform', 'custom_webform_handlers'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->createReservationWebform();
    $this->drupalLogin($this->drupalCreateUser(['access content']));
  }

  /**
   * A token earned by selecting a slot survives an AJAX reattach.
   */
  public function testTokenSurvivesAjaxReattach(): void {
    $this->drupalGet('/webform/reservation_form');
    $page = $this->getSession()->getPage();

    $page->fillField('appointment_date', $this->bookableDate());
    $page->selectFieldOption('appointment_time', '10:00');
    $this->assertSession()->waitForText('This time is held while you finish the form.');

    $token = $page->findField('reservation_hold_token')->getValue();
    $this->assertNotEmpty($token, 'A Hold was obtained.');

    // Re-run every behavior the way a Webform AJAX rebuild does.
    $this->reattachBehaviors();

    $this->assertSame(
      $token,
      $page->findField('reservation_hold_token')->getValue(),
      'The reattach preserved the existing hold token.',
    );

    // The reattach also re-reads availability, which reports this browser's own
    // Hold as booked. Its own option must stay selectable.
    $this->assertSame('10:00', $page->findField('appointment_time')->getValue());
    $this->assertFalse(
      $page->find('css', 'select[name="appointment_time"] option[value="10:00"]')->hasAttribute('disabled'),
      'The visitor\'s own held slot was not disabled against them.',
    );
  }

  /**
   * Choosing another time replaces the token rather than keeping the old one.
   */
  public function testChangingTheSlotReplacesTheToken(): void {
    $this->drupalGet('/webform/reservation_form');
    $page = $this->getSession()->getPage();

    $page->fillField('appointment_date', $this->bookableDate());
    $page->selectFieldOption('appointment_time', '10:00');
    $this->assertSession()->waitForText('This time is held while you finish the form.');
    $first = $page->findField('reservation_hold_token')->getValue();

    $page->selectFieldOption('appointment_time', '09:00');
    $this->assertSession()->assertWaitOnAjaxRequest();

    $second = $page->findField('reservation_hold_token')->getValue();
    $this->assertNotEmpty($second);
    $this->assertNotSame($first, $second, 'A new slot produced a new token.');
  }

  /**
   * Clearing the time gives the Hold up instead of keeping a stale token.
   */
  public function testClearingTheSlotClearsTheToken(): void {
    $this->drupalGet('/webform/reservation_form');
    $page = $this->getSession()->getPage();

    $page->fillField('appointment_date', $this->bookableDate());
    $page->selectFieldOption('appointment_time', '10:00');
    $this->assertSession()->waitForText('This time is held while you finish the form.');
    $this->assertNotEmpty($page->findField('reservation_hold_token')->getValue());

    $page->selectFieldOption('appointment_time', '');
    $this->assertSession()->assertWaitOnAjaxRequest();

    $this->assertSame('', $page->findField('reservation_hold_token')->getValue());
  }

  /**
   * Re-runs Drupal behaviors against the form, as an AJAX rebuild would.
   */
  private function reattachBehaviors(): void {
    $this->getSession()->executeScript(<<<'JS'
      var form = document.querySelector('form[data-custom-webform-quote]');
      // once() marks processed elements; clearing the mark is what a rebuild
      // achieves by replacing the element itself.
      form.removeAttribute('data-once');
      Drupal.attachBehaviors(document.body, drupalSettings);
JS);
    $this->assertSession()->assertWaitOnAjaxRequest();
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
          '#empty_option' => '- Select -',
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
