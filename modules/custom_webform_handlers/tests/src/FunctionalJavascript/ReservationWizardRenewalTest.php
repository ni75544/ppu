<?php

namespace Drupal\Tests\custom_webform_handlers\FunctionalJavascript;

use Drupal\Core\Serialization\Yaml;
use Drupal\Core\Url;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\webform\Entity\Webform;

/**
 * A Hold must survive the wizard pages that follow the one it was taken on.
 *
 * This is the live hg shape: the date and time controls live on page_2, the
 * hidden token lives at the root so Webform renders it on every page, and
 * Submit is on page_3. On page_3 the reservation behavior finds no date or time
 * element, so it stops before arming anything — and the Hold quietly runs out
 * underneath a visitor who is still filling the form.
 *
 * The Hold lifetime is deliberately short so that expiry is reachable inside a
 * test rather than after ten minutes.
 *
 * @group custom_webform_handlers
 */
class ReservationWizardRenewalTest extends WebDriverTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['webform', 'custom_webform_handlers'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Seconds a probe Hold lasts before it lapses.
   */
  const HOLD_SECONDS = 20;

  /**
   * The probe Webform id.
   */
  protected string $probeId;

  /**
   * The local date used for the booking.
   */
  protected string $bookingDate;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->probeId = 'cwh_probe_wiz_' . substr(hash('sha256', microtime()), 0, 8);
    $timezone = new \DateTimeZone('America/New_York');

    $elements = [
      'page_1' => [
        '#type' => 'webform_wizard_page',
        '#title' => '1. Contact information',
        'probe_name' => ['#type' => 'textfield', '#title' => 'Name'],
      ],
      'page_2' => [
        '#type' => 'webform_wizard_page',
        '#title' => '2. Service details',
        'probe_date' => ['#type' => 'date', '#title' => 'Preferred service date'],
        'probe_time' => [
          '#type' => 'select',
          '#title' => 'Preferred service time',
          '#options' => ['09:00' => '9:00 AM', '14:00' => '2:00 PM', '16:00' => '4:00 PM'],
        ],
      ],
      'page_3' => [
        '#type' => 'webform_wizard_page',
        '#title' => '3. Final details',
        'probe_notes' => ['#type' => 'textfield', '#title' => 'Notes'],
      ],
      // Root level, exactly as live hg carries it, so Webform renders it on
      // every page including the one that submits.
      'probe_token' => ['#type' => 'hidden', '#title' => 'Hold token'],
    ];

    $webform = Webform::create([
      'id' => $this->probeId,
      'title' => 'CWH wizard probe',
      'elements' => Yaml::encode($elements),
    ]);
    $webform->setSetting('wizard_next_button_label', 'Next Step');
    $webform->setSetting('wizard_progress_bar', FALSE);
    $webform->save();

    $handler = \Drupal::service('plugin.manager.webform.handler')
      ->createInstance('address_distance_handler');
    $handler->setWebform($webform);
    $handler->setHandlerId('probe_reservation');
    $handler->setConfiguration([
      'id' => 'address_distance_handler',
      'handler_id' => 'probe_reservation',
      'label' => 'Probe reservation',
      'status' => TRUE,
      'conditions' => [],
      'weight' => 0,
      'settings' => [
        'reservation_enabled' => TRUE,
        'reservation_date_field' => 'probe_date',
        'reservation_time_field' => 'probe_time',
        'reservation_hold_token_field' => 'probe_token',
        'reservation_resource_field' => '',
        'reservation_timezone' => 'America/New_York',
        'reservation_duration_minutes' => 60,
        'reservation_hold_seconds' => self::HOLD_SECONDS,
        'reservation_booking_window_days' => 90,
        'reservation_allowed_weekdays' => [1, 2, 3, 4, 5, 6],
      ],
    ]);
    $webform->addWebformHandler($handler);
    $webform->save();

    $day = (new \DateTimeImmutable('now', $timezone))->setTime(0, 0, 0)->modify('+8 days');
    for ($i = 0; $i < 10; $i++) {
      if (in_array((int) $day->format('N'), [1, 2, 3, 4, 5, 6], TRUE)) {
        break;
      }
      $day = $day->modify('+1 day');
    }
    $this->bookingDate = $day->format('Y-m-d');
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->cleanupProbe();
    parent::tearDown();
  }

  /**
   * Deletes only this test's own probe artefacts.
   */
  protected function cleanupProbe(): void {
    if (empty($this->probeId)) {
      return;
    }
    try {
      $storage = \Drupal::entityTypeManager()->getStorage('webform_submission');
      $sids = \Drupal::database()->select('webform_submission', 's')
        ->fields('s', ['sid'])
        ->condition('s.webform_id', $this->probeId)
        ->execute()
        ->fetchCol();
      if ($sids) {
        $storage->delete($storage->loadMultiple($sids));
      }
      \Drupal::database()->delete('custom_webform_handlers_reservation')
        ->condition('webform_id', $this->probeId)
        ->execute();
      $webform = Webform::load($this->probeId);
      if ($webform) {
        $webform->delete();
      }
    }
    catch (\Throwable $exception) {
      $this->note('cleanup error: ' . $exception->getMessage());
    }
  }

  /**
   * Records a diagnostic line; test output would be a PHPUnit error.
   */
  protected function note(string $line): void {
    file_put_contents('/tmp/cwh_wizard_report.txt', $line . "\n", FILE_APPEND);
  }

  /**
   * Presses a submit control by visible label, through a real DOM click.
   */
  protected function pressByLabel(string $label): void {
    $found = $this->getSession()->evaluateScript(
      "(function () { var all = Array.prototype.slice.call("
      . "document.querySelectorAll('input[type=submit], button[type=submit], button'));"
      . " var b = all.filter(function (n) {"
      . "   return ((n.value || '') + ' ' + (n.textContent || '')).indexOf('" . $label . "') !== -1; })[0];"
      . " if (!b) { return false; } b.click(); return true; })()"
    );
    $this->assertTrue((bool) $found, 'Pressed the control labelled ' . $label);
  }

  /**
   * Fills a text field and fires its events.
   */
  protected function fillByName(string $name, string $value): void {
    $this->getSession()->executeScript(
      "(function () { var f = document.querySelector('[name=\"" . $name . "\"]');"
      . " f.value = '" . $value . "';"
      . " f.dispatchEvent(new Event('input', {bubbles: true}));"
      . " f.dispatchEvent(new Event('change', {bubbles: true})); })()"
    );
  }

  /**
   * Selects a time and fires the change the behavior binds to.
   */
  protected function selectTime(string $value): void {
    $this->getSession()->executeScript(
      "(function () { var s = document.querySelector('[name=\"probe_time\"]');"
      . " s.value = '" . $value . "';"
      . " s.dispatchEvent(new Event('change', {bubbles: true})); })()"
    );
    $this->getSession()->wait(1500);
  }

  /**
   * Returns the reservation row for the probe slot, or NULL.
   */
  protected function slotRow(): ?object {
    $start = (new \DateTimeImmutable(
      $this->bookingDate . ' 14:00',
      new \DateTimeZone('America/New_York')
    ))->getTimestamp();

    $row = \Drupal::database()->select('custom_webform_handlers_reservation', 'r')
      ->fields('r', ['reservation_id', 'state', 'expires_at', 'submission_id', 'changed'])
      ->condition('r.webform_id', $this->probeId)
      ->condition('r.slot_start', $start)
      ->execute()
      ->fetchObject();

    return $row ?: NULL;
  }

  /**
   * A Hold taken on page_2 must still be valid when page_3 submits.
   */
  public function testHoldSurvivesLaterWizardPages(): void {
    $assert = $this->assertSession();
    $session = $this->getSession();

    $this->drupalGet(Url::fromRoute('entity.webform.canonical', ['webform' => $this->probeId]));

    // ---- page_1: no reservation controls here at all.
    $this->assertNotNull(
      $session->getPage()->find('css', '[name="probe_name"]'),
      'Page 1 rendered.'
    );
    $this->fillByName('probe_name', 'Probe');
    $this->pressByLabel('Next Step');
    $session->wait(8000, "document.querySelector('[name=\"probe_date\"]') !== null");

    $this->note('AFTER NEXT fields: ' . (string) $session->evaluateScript(
      "Array.prototype.slice.call(document.querySelectorAll('[name]'))"
      . ".map(function (n) { return n.getAttribute('name'); }).join(',')"
    ));
    $this->note('AFTER NEXT buttons: ' . (string) $session->evaluateScript(
      "Array.prototype.slice.call(document.querySelectorAll('input[type=submit], button'))"
      . ".map(function (n) { return (n.value || n.textContent || '').trim(); }).join(' | ')"
    ));
    $this->note('AFTER NEXT text: ' . substr((string) preg_replace('/\s+/', ' ',
      (string) $session->getPage()->getText()), 0, 400));

    // ---- page_2: the reservation surface.
    $this->assertNotNull(
      $session->getPage()->find('css', '[name="probe_date"]'),
      'Page 2 carries the date control.'
    );
    $this->assertNotNull(
      $assert->waitForElement('css', '[data-reservation-calendar]'),
      'The calendar attached on page 2.'
    );

    // Set the date through the real element, then hold the slot.
    $this->fillByName('probe_date', $this->bookingDate);
    $session->wait(2500);
    $this->selectTime('14:00');

    $held = $session->wait(
      12000,
      "document.querySelector('[name=\"probe_token\"]')"
      . " && document.querySelector('[name=\"probe_token\"]').value.length === 64"
    );
    $this->assertTrue($held, 'The hold returned a token on page 2.');

    $row = $this->slotRow();
    $this->assertNotNull($row, 'A reservation row exists after the hold.');
    $this->assertSame('held', $row->state, 'The row is a live hold.');
    $originalExpiry = (int) $row->expires_at;
    $this->note('ORIGINAL EXPIRY: ' . $originalExpiry . ' (hold seconds ' . self::HOLD_SECONDS . ')');

    // ---- advance to page_3, the page that submits.
    $this->pressByLabel('Next Step');
    $session->wait(8000, "document.querySelector('[name=\"probe_notes\"]') !== null");

    $this->assertNull(
      $session->getPage()->find('css', '[name="probe_date"]'),
      'Page 3 has no date control.'
    );
    $this->assertNull(
      $session->getPage()->find('css', '[name="probe_time"]'),
      'Page 3 has no time control.'
    );
    $rootToken = $session->evaluateScript(
      "document.querySelector('[name=\"probe_token\"]') ? document.querySelector('[name=\"probe_token\"]').value : '__ABSENT__'"
    );
    $this->note('PAGE 3 ROOT TOKEN: ' . ($rootToken === '__ABSENT__' ? 'absent' : 'len' . strlen((string) $rootToken)));
    $this->assertSame(
      64,
      strlen((string) $rootToken),
      'Page 3 still carries the root hold token.'
    );

    // What did page_2 leave behind for this page to work from?
    $storedMemo = (string) $session->evaluateScript(
      "sessionStorage.getItem('cwh_reservation_hold_" . $this->probeId . "') || 'NONE'"
    );
    $storageKeys = (string) $session->evaluateScript(
      "JSON.stringify(Object.keys(sessionStorage))"
    );
    $this->note('PAGE 3 STORAGE KEYS: ' . $storageKeys);
    $this->note('PAGE 3 MEMO: ' . $storedMemo);
    $this->assertStringContainsString(
      '"token"',
      $storedMemo,
      'sessionStorage memo present on page 3. keys=' . $storageKeys . ' memo=' . $storedMemo
    );

    // Record every renewal request this page makes.
    $session->executeScript(
      "window.__cwhCalls = [];"
      . "var of = window.fetch;"
      . "window.fetch = function (u) { try { window.__cwhCalls.push(String(u)); } catch (e) {} "
      . "return of.apply(this, arguments); };"
    );

    // ---- wait past the original expiry while page_3 sits open.
    $session->wait(30000, 'false');

    $renewCalls = (int) $session->evaluateScript(
      "(window.__cwhCalls || []).filter(function (u) { return u.indexOf('/reservation/renew') !== -1; }).length"
    );
    $afterWait = $this->slotRow();
    $this->note('RENEW CALLS ON PAGE 3: ' . $renewCalls);
    $this->note('EXPIRY AFTER WAIT: ' . ($afterWait ? $afterWait->expires_at : 'row gone')
      . ' state=' . ($afterWait ? $afterWait->state : '-'));

    // ---- submit.
    $this->fillByName('probe_notes', 'probe');
    $this->pressByLabel('Submit');
    $session->wait(
      15000,
      "document.querySelector('[name=\"probe_notes\"]') === null"
      . " || document.body.innerText.indexOf('no longer held') !== -1"
    );
    $bodyText = (string) preg_replace('/\s+/', ' ', (string) $session->getPage()->getText());
    $this->note('AFTER SUBMIT: ' . substr($bodyText, 0, 400));

    // ---- the four things this must prove.
    $this->assertGreaterThanOrEqual(
      1,
      $renewCalls,
      'A renewal request was made while page 3 was open.'
    );
    $this->assertNotNull($afterWait, 'The reservation row still exists.');
    $this->assertGreaterThan(
      $originalExpiry,
      (int) $afterWait->expires_at,
      'The hold outlived its original expiry.'
    );
    $this->assertStringNotContainsString(
      'no longer held',
      $bodyText,
      'The submission was not refused for a lost hold.'
    );

    $sids = \Drupal::database()->select('webform_submission', 's')
      ->fields('s', ['sid'])
      ->condition('s.webform_id', $this->probeId)
      ->execute()
      ->fetchCol();
    $this->assertCount(1, $sids, 'Exactly one submission was created.');

    $final = $this->slotRow();
    $this->assertNotNull($final, 'The reservation row survived submission.');
    $this->assertSame('confirmed', $final->state, 'Exactly one reservation confirmed.');
    $this->assertSame(
      (int) reset($sids),
      (int) $final->submission_id,
      'The confirmed reservation links to the submission.'
    );

    // ---- a new visitor must not be offered that hour.
    $session->getDriver()->getWebDriverSession()->deleteAllCookies();
    $this->drupalGet('/custom-webform-handlers/reservation/availability/' . $this->probeId, [
      'query' => ['date' => $this->bookingDate],
    ]);
    $raw = (string) $session->evaluateScript('document.body.innerText');
    $payload = json_decode($raw, TRUE);
    $this->note('NEW VISITOR AVAILABILITY: ' . substr($raw, 0, 200));
    $this->assertSame('success', $payload['status'] ?? '');
    $this->assertContains(
      '14:00',
      $payload['booked'] ?? [],
      'A new visitor sees the booked hour as unavailable.'
    );
  }

}
