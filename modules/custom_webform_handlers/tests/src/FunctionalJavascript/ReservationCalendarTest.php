<?php

namespace Drupal\Tests\custom_webform_handlers\FunctionalJavascript;

use Drupal\Core\Serialization\Yaml;
use Drupal\Core\Url;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\webform\Entity\Webform;

/**
 * Renders the visitor calendar and the administrative schedule summary.
 *
 * Everything is seeded against an isolated probe Webform created by this test
 * and torn down with its database, so no real reservation is read or written.
 *
 * @group custom_webform_handlers
 */
class ReservationCalendarTest extends WebDriverTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['webform', 'custom_webform_handlers'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The probe Webform id.
   */
  protected string $probeId;

  /**
   * Seeded local dates: one partly booked, one full, one closed weekday.
   */
  protected string $partialDate;
  protected string $fullDate;
  protected string $closedDate;

  /**
   * A date with every configured hour still free, used by the E2E flow.
   */
  protected string $freeDate;

  /**
   * The configured hours of the probe schedule.
   */
  protected array $hours = ['09:00', '14:00', '16:00'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->probeId = 'cwh_probe_' . substr(hash('sha256', microtime()), 0, 8);
    $timezone = new \DateTimeZone('America/New_York');

    $elements = [
      // Mirrors the essential shape of live hg: the reservation elements sit on
      // a wizard page that is NOT the page carrying Submit, and the hidden token
      // lives at the root so Webform renders it on every page. That combination
      // is what makes the rebuild lose the DOM token.
      'page_1' => [
        '#type' => 'webform_wizard_page',
        '#title' => '1. Service details',
        'probe_date' => ['#type' => 'date', '#title' => 'Preferred service date'],
        'probe_time' => [
          '#type' => 'select',
          '#title' => 'Preferred service time',
          '#options' => ['09:00' => '9:00 AM', '14:00' => '2:00 PM', '16:00' => '4:00 PM'],
        ],
      ],
      'page_2' => [
        '#type' => 'webform_wizard_page',
        '#title' => '2. Final details',
        'probe_notes' => ['#type' => 'textfield', '#title' => 'Notes'],
      ],
      'probe_token' => ['#type' => 'hidden', '#title' => 'Hold token'],
    ];

    $webform = Webform::create([
      'id' => $this->probeId,
      'title' => 'CWH calendar probe',
      'elements' => Yaml::encode($elements),
    ]);
    // Webform Ajax on, so advancing the wizard rebuilds the form in place —
    // the exact rebuild that drops the hidden token from the DOM.
    $webform->setSetting('ajax', TRUE);
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
        'reservation_hold_seconds' => 600,
        'reservation_booking_window_days' => 90,
        'reservation_allowed_weekdays' => [1, 2, 3, 4, 5, 6],
      ],
    ]);
    $webform->addWebformHandler($handler);
    $webform->save();

    // Three day shapes, all inside the horizon and inside one month window.
    $base = (new \DateTimeImmutable('now', $timezone))->setTime(0, 0, 0)->modify('+7 days');
    $this->partialDate = $this->nextWeekday($base, [1, 2, 3, 4, 5, 6]);
    $this->fullDate = $this->nextWeekday(
      (new \DateTimeImmutable($this->partialDate, $timezone))->modify('+1 day'),
      [1, 2, 3, 4, 5, 6]
    );
    $this->closedDate = $this->nextWeekday($base, [7]);

    // A day left completely free, for the hold/submit lifecycle.
    $this->freeDate = $this->nextWeekday(
      (new \DateTimeImmutable($this->fullDate, $timezone))->modify('+1 day'),
      [1, 2, 3, 4, 5, 6]
    );

    $manager = \Drupal::service('custom_webform_handlers.reservation_manager');

    // Partly booked: one hour confirmed, two left free.
    $held = $manager->hold($this->slot($this->partialDate, '09:00'), 'probe-owner');
    $manager->confirm($held->token, 999000101);

    // Fully booked: every configured hour is unavailable.
    foreach ($this->hours as $hour) {
      $manager->block($this->slot($this->fullDate, $hour));
    }
  }

  /**
   * Removes only this test's own probe artefacts.
   *
   * Scoped by the probe Webform id in every case: its submissions, its
   * reservation rows, and the Webform itself. Nothing is dropped, truncated, or
   * matched by pattern, so no other test's data and no real reservation can be
   * caught by it. Safe to call more than once.
   */
  protected function cleanupProbe(): array {
    $report = [];
    if (empty($this->probeId)) {
      return $report;
    }

    try {
      $storage = \Drupal::entityTypeManager()->getStorage('webform_submission');
      $sids = \Drupal::entityQuery('webform_submission')
        ->accessCheck(FALSE)
        ->condition('webform_id', $this->probeId)
        ->execute();
      if ($sids) {
        $storage->delete($storage->loadMultiple($sids));
      }
      $report[] = 'submissions deleted=' . count($sids);

      $deleted = (int) \Drupal::database()->delete('custom_webform_handlers_reservation')
        ->condition('webform_id', $this->probeId)
        ->execute();
      $report[] = 'reservation rows deleted=' . $deleted;

      $webform = Webform::load($this->probeId);
      if ($webform) {
        $webform->delete();
      }
      $report[] = 'webform deleted=' . (Webform::load($this->probeId) ? 'NO' : 'yes');

      $left = (int) \Drupal::database()->select('custom_webform_handlers_reservation', 'r')
        ->condition('r.webform_id', $this->probeId)
        ->countQuery()->execute()->fetchField();
      $report[] = 'probe rows remaining=' . $left;
    }
    catch (\Throwable $exception) {
      $report[] = 'cleanup error: ' . get_class($exception) . ': ' . $exception->getMessage();
    }

    return $report;
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    // Runs whatever happened in the test body, including a fatal assertion, and
    // before the test site itself is torn down.
    $report = $this->cleanupProbe();
    if ($report) {
      // Never write to STDOUT/STDERR from a test: PHPUnit treats output during a
      // test as an error and it masks the real result.
      $this->note('CLEANUP (' . $this->probeId . '): ' . implode('; ', $report));
    }
    parent::tearDown();
  }

  /**
   * Records a diagnostic line for the runner to read after the run.
   */
  protected function note(string $line): void {
    file_put_contents('/tmp/cwh_probe_report.txt', $line . "\n", FILE_APPEND);
  }

  /**
   * Returns the next date whose ISO weekday is in the allowed set.
   */
  protected function nextWeekday(\DateTimeImmutable $from, array $allowed): string {
    $day = $from;
    for ($i = 0; $i < 14; $i++) {
      if (in_array((int) $day->format('N'), $allowed, TRUE)) {
        return $day->format('Y-m-d');
      }
      $day = $day->modify('+1 day');
    }
    return $day->format('Y-m-d');
  }

  /**
   * Builds a canonical probe Slot.
   */
  protected function slot(string $date, string $time) {
    $timezone = new \DateTimeZone('America/New_York');
    $start = new \DateTimeImmutable($date . ' ' . $time, $timezone);
    return new \Drupal\custom_webform_handlers\Reservation\ReservationSlot(
      $this->probeId,
      'default',
      $start->getTimestamp(),
      $start->getTimestamp() + 3600,
      'America/New_York',
      600
    );
  }

  /**
   * Brings the grid to the month containing one date, and lets it settle.
   *
   * Settling matters: renderCalendar() empties and rebuilds every day button
   * when a fetch resolves, so an element located before that lands is detached
   * by the time it is clicked, and WebDriver rejects the click with HTTP 400.
   */
  protected function showMonthOf(string $date): void {
    $session = $this->getSession();

    for ($i = 0; $i < 3; $i++) {
      $found = $session->wait(
        8000,
        "document.querySelector('[data-reservation-date=\"" . $date . "\"]') !== null"
      );
      if ($found) {
        $this->waitForDayReady($date);
        return;
      }

      $next = $session->getPage()->find(
        'css',
        '.custom-reservation-calendar__nav[aria-label="Next month"]'
      );
      if (!$next || $next->getAttribute('disabled') !== NULL) {
        return;
      }
      // Driven through the DOM for the same interactability reason as the day
      // buttons; it runs the product's own click listener either way.
      $session->executeScript(
        "document.querySelector('.custom-reservation-calendar__nav[aria-label=\"Next month\"]').click();"
      );
      $session->wait(3000);
    }
  }

  /**
   * Presses a submit control by its visible label, via a real DOM click.
   *
   * Same reason as selectTime(): WebDriver refuses natively-driven clicks on
   * controls it considers outside the viewport in this headless window. A DOM
   * click on a submit input still submits the form through Drupal exactly as a
   * user's click does.
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
   * Fills a text field and fires its input/change events.
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
   * Selects one time option and fires the event the behavior listens for.
   *
   * Mink implements selectOption() by clicking the <option>, which Chrome
   * rejects inside a closed native select (HTTP 400, not interactable). Setting
   * the value and dispatching change drives the same listener requestHold() is
   * bound to.
   */
  protected function selectTime(string $value): void {
    $session = $this->getSession();
    $session->executeScript(
      "(function () { var s = document.querySelector('[name=\"probe_time\"]');"
      . " s.value = '" . $value . "';"
      . " s.dispatchEvent(new Event('change', {bubbles: true})); })()"
    );
    $session->wait(1500);
  }

  /**
   * Waits until the server has reported a definite state for one day.
   */
  protected function waitForDayReady(string $date): void {
    $settled = $this->getSession()->wait(
      10000,
      "(function () { var b = document.querySelector('[data-reservation-date=\"" . $date . "\"]');"
      . " return !!b && b.getAttribute('data-reservation-day-state') !== 'unknown'; })()"
    );
    $this->assertTrue($settled, 'The calendar reported a definite state for ' . $date);
  }

  /**
   * Activates one calendar day and records what the button looked like.
   *
   * The click is dispatched as a real MouseEvent on the button rather than
   * driven through WebDriver: WebDriver rejected the native click with HTTP 400
   * (not interactable) in this headless viewport, and the geometry is recorded
   * below so that rejection is evidenced rather than assumed. The product's own
   * click listener is what runs either way.
   */
  protected function activateDay(string $date): void {
    $session = $this->getSession();
    $this->note('DAY ' . $date . ': ' . (string) $session->evaluateScript(
      "(function () { var b = document.querySelector('[data-reservation-date=\"" . $date . "\"]');"
      . " if (!b) { return 'absent'; }"
      . " var r = b.getBoundingClientRect(); var s = getComputedStyle(b);"
      . " return JSON.stringify({state: b.getAttribute('data-reservation-day-state'),"
      . " w: Math.round(r.width), h: Math.round(r.height), top: Math.round(r.top),"
      . " disabled: b.disabled === true, ariaDisabled: b.getAttribute('aria-disabled'),"
      . " display: s.display, visibility: s.visibility, pointerEvents: s.pointerEvents}); })()"
    ));

    $session->executeScript(
      "document.querySelector('[data-reservation-date=\"" . $date . "\"]')"
      . ".dispatchEvent(new MouseEvent('click', {bubbles: true, cancelable: true}));"
    );
    $session->wait(2500);
  }

  /**
   * The full visitor lifecycle, with the hidden token lost before submission.
   *
   * This is the reported failure reproduced end to end: a real Hold is taken in
   * a real browser session, the hidden token is then removed from the DOM the way
   * an AJAX rebuild removes it, and the completed form is submitted with no token
   * in the POST at all. The submission must still succeed, because the server
   * kept its own receipt.
   */
  public function testHoldSurvivesTokenLossAndConfirms(): void {
    try {
      $this->runHoldLifecycle();
    }
    finally {
      $this->cleanupProbe();
    }
  }

  /**
   * The hold/token-loss/confirm body, wrapped by the cleanup path above.
   */
  protected function runHoldLifecycle(): void {
    $assert = $this->assertSession();
    $session = $this->getSession();
    $database = \Drupal::database();
    $table = 'custom_webform_handlers_reservation';

    $this->drupalGet(Url::fromRoute('entity.webform.canonical', ['webform' => $this->probeId]));

    // Hypothesis 1: library and settings attached, no exception on attach.
    $this->assertTrue(
      (bool) $session->evaluateScript(
        "!!document.querySelector('script[src*=\"reservation_slots.js\"]')"
      ),
      'reservation_slots.js is loaded on the form.'
    );
    $settings = $session->evaluateScript('drupalSettings.custom_webform_handlers');
    $this->assertNotEmpty($settings['reservation'] ?? [], 'Reservation settings are published.');
    $this->assertNotEmpty($settings['reservation']['calendar_url'] ?? '', 'calendar_url is published.');
    $this->assertNotEmpty($settings['reservation']['hold_url'] ?? '', 'hold_url is published.');
    $this->assertSame($this->probeId, $settings['webform_id'] ?? '');
    // The behavior only attaches when it found the date and time elements; the
    // status region it creates is the observable proof that it did.
    $this->assertNotNull(
      $assert->waitForElement('css', '[data-reservation-calendar]'),
      'The behavior attached and built its calendar.'
    );

    // Hypothesis 2: the Hold request is actually sent and succeeds. The hidden
    // token is only ever populated from a 200 response carrying a token.
    $this->showMonthOf($this->freeDate);
    $this->activateDay($this->freeDate);
    $this->assertSame(
      $this->freeDate,
      $session->getPage()->findField('probe_date')->getValue()
    );

    $this->selectTime('14:00');
    $tokenPresent = $session->wait(
      10000,
      "document.querySelector('[name=\"probe_token\"]') && document.querySelector('[name=\"probe_token\"]').value.length === 64"
    );
    $this->assertTrue($tokenPresent, 'The hold returned a token into the hidden element.');

    $heldRows = $database->select($table, 'r')
      ->fields('r', ['state'])
      ->condition('r.webform_id', $this->probeId)
      ->condition('r.state', 'held')
      ->execute()
      ->fetchAll();
    $this->assertCount(1, $heldRows, 'Exactly one held reservation exists after the hold.');

    // Hypothesis 4: advance the wizard. This is the real Webform Ajax rebuild —
    // it replaces the form in place, and the page it renders does not contain
    // the date or time elements at all.
    $tokenBeforeRebuild = $session->evaluateScript(
      "document.querySelector('[name=\"probe_token\"]').value"
    );
    $this->pressByLabel('Next Step');
    // The wizard advances by full page POST here: assertWaitOnAjaxRequest()
    // reported "called unnecessarily", so no XHR is involved. Wait for the DOM
    // the next page is expected to have instead.
    $session->wait(8000, "document.querySelector('[name=\"probe_notes\"]') !== null");

    $this->assertNotNull(
      $session->getPage()->find('css', '[name="probe_notes"]'),
      'The wizard advanced to the page carrying Submit.'
    );
    $this->assertNull(
      $session->getPage()->find('css', '[name="probe_date"]'),
      'The rebuilt page no longer contains the date element.'
    );
    $this->assertNull(
      $session->getPage()->find('css', '[name="probe_time"]'),
      'The rebuilt page no longer contains the time element.'
    );

    // Diagnostic, reported rather than asserted: whether Webform restored the
    // root-level hidden token into the rebuilt DOM at all.
    $tokenAfterRebuild = $session->evaluateScript(
      "document.querySelector('[name=\"probe_token\"]') ? document.querySelector('[name=\"probe_token\"]').value : '__ELEMENT_ABSENT__'"
    );
    $this->note(sprintf(
      'REBUILD TOKEN STATE: before=%s after=%s',
      $tokenBeforeRebuild === '' ? 'empty' : 'len' . strlen((string) $tokenBeforeRebuild),
      $tokenAfterRebuild === '__ELEMENT_ABSENT__'
        ? 'element absent'
        : ($tokenAfterRebuild === '' ? 'empty' : 'len' . strlen((string) $tokenAfterRebuild))
    ));

    // Force the reported condition regardless of what Webform restored: no token
    // reaches the server in the final POST.
    $session->executeScript(
      "var t = document.querySelector('[name=\"probe_token\"]'); if (t) { t.remove(); }"
    );
    $this->assertNull(
      $session->getPage()->find('css', '[name="probe_token"]'),
      'No hidden token element remains in the submitted DOM.'
    );

    // Submit the completed form from the page that has no reservation elements.
    $this->fillByName('probe_notes', 'probe');
    $this->pressByLabel('Submit');
    // Settled when either the form is gone (accepted) or a message appears
    // (refused). Both are terminal, and waiting on readyState alone proves
    // nothing because it is already 'complete'.
    $session->wait(
      15000,
      "document.querySelector('[name=\"probe_notes\"]') === null"
      . " || document.body.innerText.indexOf('no longer held') !== -1"
    );
    $this->note('AFTER SUBMIT: ' . substr(
      (string) preg_replace('/\s+/', ' ', (string) $session->getPage()->getText()),
      0,
      700
    ));

    // Exactly one reservation, confirmed, linked to the created submission.
    //
    // Scoped to the chosen slot, not just to the Webform: setUp() deliberately
    // seeds a confirmed row on another day, and matching on webform + state
    // alone silently passed against that seed instead of against this booking.
    $expectedStart = (new \DateTimeImmutable(
      $this->freeDate . ' 14:00',
      new \DateTimeZone('America/New_York')
    ))->getTimestamp();

    $rows = $database->select($table, 'r')
      ->fields('r', ['reservation_id', 'state', 'submission_id'])
      ->condition('r.webform_id', $this->probeId)
      ->condition('r.slot_start', $expectedStart)
      ->condition('r.state', 'confirmed')
      ->execute()
      ->fetchAll();
    $this->assertCount(1, $rows, 'Exactly one reservation became confirmed for the chosen slot.');

    // Read the submission straight from storage. An entity query here returned
    // nothing while the confirmed row plainly referenced a submission, so the
    // table is the authority for "did the submission get created".
    $sids = $database->select('webform_submission', 's')
      ->fields('s', ['sid'])
      ->condition('s.webform_id', $this->probeId)
      ->execute()
      ->fetchCol();
    $this->note('SUBMISSIONS: ' . implode(',', $sids)
      . ' | confirmed.submission_id=' . ($rows[0]->submission_id ?? 'none'));
    $this->assertCount(1, $sids, 'The submission was created.');
    $this->assertSame(
      (int) reset($sids),
      (int) $rows[0]->submission_id,
      'The confirmed reservation links to the real submission.'
    );

    // A genuinely different visitor: every cookie dropped, so the next request
    // carries no session and no owner identity from the booking above.
    // session->restart() was rejected by this driver (HTTP 400), so the cookie
    // jar is cleared instead, which is what makes it a separate session.
    $session->getDriver()->getWebDriverSession()->deleteAllCookies();
    $this->drupalGet('/custom-webform-handlers/reservation/availability/' . $this->probeId, [
      'query' => ['date' => $this->freeDate],
    ]);
    $raw = (string) $session->evaluateScript('document.body.innerText');
    $payload = json_decode($raw, TRUE);
    $this->note('SECOND SESSION AVAILABILITY: ' . substr($raw, 0, 200));
    $this->assertSame('success', $payload['status'] ?? '', 'The availability read succeeded.');
    $this->assertContains(
      '14:00',
      $payload['booked'] ?? [],
      'A fresh second session sees the booked hour as unavailable.'
    );
  }

  /**
   * The visitor calendar marks days correctly and drives the real date field.
   */
  public function testVisitorCalendarAndAdminSchedule(): void {
    try {
      $this->runCalendarAndSchedule();
    }
    finally {
      $this->cleanupProbe();
    }
  }

  /**
   * The calendar and schedule body, wrapped by the cleanup path above.
   */
  protected function runCalendarAndSchedule(): void {
    $assert = $this->assertSession();
    $session = $this->getSession();

    $this->drupalGet(Url::fromRoute('entity.webform.canonical', ['webform' => $this->probeId]));

    // The grid renders and finishes its first read.
    $this->assertNotNull(
      $assert->waitForElement('css', '[data-reservation-calendar]'),
      'The month grid was rendered beside the native date input.'
    );
    $this->assertNotNull(
      $assert->waitForElement('css', '.custom-reservation-calendar__day--open'),
      'At least one day was reported open.'
    );

    // The native input survives as the no-JavaScript fallback.
    $assert->elementExists('css', '[name="probe_date"]');

    $this->showMonthOf($this->partialDate);

    // 1. Partly booked day: selectable, and says how many hours are open.
    $partial = $assert->elementExists('css', '[data-reservation-date="' . $this->partialDate . '"]');
    $this->assertSame('open', $partial->getAttribute('data-reservation-day-state'));
    $this->assertNull($partial->getAttribute('aria-disabled'), 'A partly booked day is not disabled.');
    $this->assertStringContainsString('open', strtolower($partial->getText()));
    $this->assertStringContainsString(
      'hours available',
      strtolower((string) $partial->getAttribute('aria-label'))
    );

    // 2. Fully booked day: marked Full, aria-disabled, and refuses activation.
    $this->showMonthOf($this->fullDate);
    $full = $assert->elementExists('css', '[data-reservation-date="' . $this->fullDate . '"]');
    $this->assertSame('full', $full->getAttribute('data-reservation-day-state'));
    $this->assertSame('true', $full->getAttribute('aria-disabled'));
    $this->assertStringContainsString('full', strtolower($full->getText()));

    $before = $session->getPage()->findField('probe_date')->getValue();
    $this->activateDay($this->fullDate);
    $this->assertSame(
      $before,
      $session->getPage()->findField('probe_date')->getValue(),
      'Activating a fully booked day did not change the date field.'
    );

    // 3. Closed weekday: unavailable, but never reported as booked.
    $this->showMonthOf($this->closedDate);
    $closed = $assert->elementExists('css', '[data-reservation-date="' . $this->closedDate . '"]');
    $this->assertSame('closed', $closed->getAttribute('data-reservation-day-state'));
    $this->assertStringContainsString('closed', strtolower($closed->getText()));
    $this->assertStringNotContainsString('full', strtolower($closed->getText()));

    // 4. Selecting an open day writes the real field and fires its events.
    $this->showMonthOf($this->partialDate);
    $session->executeScript(
      "window.__cwhEvents = [];"
      . "var el = document.querySelector('[name=\"probe_date\"]');"
      . "['input','change'].forEach(function (name) {"
      . "  el.addEventListener(name, function () { window.__cwhEvents.push(name); });"
      . "});"
    );
    $this->activateDay($this->partialDate);

    $this->assertSame(
      $this->partialDate,
      $session->getPage()->findField('probe_date')->getValue(),
      'The chosen calendar day was written into the Webform date field.'
    );
    $this->assertSame(
      ['input', 'change'],
      $session->evaluateScript('window.__cwhEvents'),
      'The date field fired input then change, in that order.'
    );

    // 5. The booked hour is disabled and labelled; free hours stay selectable.
    $this->assertNotNull(
      $assert->waitForElement('css', 'option[data-reservation-booked="true"]'),
      'The taken hour was marked booked.'
    );
    $booked = $assert->elementExists('css', '[name="probe_time"] option[value="09:00"]');
    $this->assertSame('true', $booked->getAttribute('aria-disabled'));
    $this->assertStringContainsString('Booked', $booked->getText());
    $this->assertTrue(
      (bool) $session->evaluateScript(
        "document.querySelector('[name=\"probe_time\"] option[value=\"09:00\"]').disabled"
      )
    );

    $free = $assert->elementExists('css', '[name="probe_time"] option[value="14:00"]');
    $this->assertNull($free->getAttribute('aria-disabled'), 'A free hour stays selectable.');
    $this->assertStringNotContainsString('Booked', $free->getText());
    $this->assertFalse(
      (bool) $session->evaluateScript(
        "document.querySelector('[name=\"probe_time\"] option[value=\"14:00\"]').disabled"
      )
    );

    // 6. Keyboard navigation and horizon-bounded month controls.
    $moved = $session->evaluateScript(
      "(function () {"
      . "  var day = document.querySelector('[data-reservation-date=\"" . $this->partialDate . "\"]');"
      . "  day.focus();"
      . "  var before = document.activeElement.getAttribute('data-reservation-date');"
      . "  day.dispatchEvent(new KeyboardEvent('keydown', {key: 'ArrowRight', bubbles: true}));"
      . "  return [before, document.activeElement.getAttribute('data-reservation-date')];"
      . "})()"
    );
    $this->assertSame($this->partialDate, $moved[0]);
    $this->assertNotSame($moved[0], $moved[1], 'ArrowRight moved focus to another day.');

    // The current month is the start of the horizon, so Previous is disabled.
    $this->drupalGet(Url::fromRoute('entity.webform.canonical', ['webform' => $this->probeId]));
    $assert->waitForElement('css', '.custom-reservation-calendar__day--open');
    $this->assertTrue(
      (bool) $session->evaluateScript(
        "document.querySelector('.custom-reservation-calendar__nav[aria-label=\"Previous month\"]').disabled"
      ),
      'Previous month is disabled at the start of the booking horizon.'
    );

    // ---- Administrative schedule summary.
    $admin = $this->drupalCreateUser(['administer custom webform reservations']);
    $this->drupalLogin($admin);
    $this->drupalGet('/admin/content/webform-reservations', [
      'query' => [
        'webform_id' => $this->probeId,
        'from' => $this->partialDate,
        'to' => $this->fullDate,
      ],
    ]);

    $assert->pageTextContains('Schedule capacity');
    $assert->pageTextContains('Partially booked');
    $assert->pageTextContains('Fully booked');

    $partialRow = $assert->elementExists(
      'xpath',
      '//table//tr[td//a[text()="' . $this->partialDate . '"]]'
    );
    $partialCells = array_map(
      static fn ($cell) => trim($cell->getText()),
      $partialRow->findAll('css', 'td')
    );
    // Date, hours, held, confirmed, blocked, free, status.
    $this->assertSame('3', $partialCells[1], 'Total configured hours.');
    $this->assertSame('0', $partialCells[2], 'Held hours.');
    $this->assertSame('1', $partialCells[3], 'Confirmed hours.');
    $this->assertSame('0', $partialCells[4], 'Blocked hours.');
    $this->assertSame('2', $partialCells[5], 'Free hours.');
    $this->assertStringContainsString('Partially booked', $partialCells[6]);

    $fullRow = $assert->elementExists(
      'xpath',
      '//table//tr[td//a[text()="' . $this->fullDate . '"]]'
    );
    $fullCells = array_map(
      static fn ($cell) => trim($cell->getText()),
      $fullRow->findAll('css', 'td')
    );
    $this->assertSame('3', $fullCells[1]);
    $this->assertSame('0', $fullCells[3], 'No confirmed hours on the blocked day.');
    $this->assertSame('3', $fullCells[4], 'All three hours are blocked.');
    $this->assertSame('0', $fullCells[5], 'No free hours.');
    $this->assertStringContainsString('Fully booked', $fullCells[6]);
  }

}
