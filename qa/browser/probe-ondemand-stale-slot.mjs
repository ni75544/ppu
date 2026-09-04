/**
 * @file
 * Submits an On-Demand Delivery request carrying a crafted Courier arrival.
 *
 * The read-only gate (ondemand-pilot-gate.mjs) can prove the offer endpoint
 * refuses a stale arrival, because that is one request. It cannot prove the other
 * half of the refusal: that a submission which reaches the database carries no
 * arrival on it. `discardArrivalInFormState()` acts during validation and
 * `discardArrivalOutsideSelfBooking()` again at preSave, and neither is observable
 * without a row to look at. So this probe deliberately creates one.
 *
 * The craft is a real one rather than an invented integer. The walk books a
 * genuine Courier pickup time first, so `dispatch_slot` holds an arrival the
 * planner really did offer, then goes back through the wizard, switches the
 * service to On-Demand Delivery, and puts that arrival back into the hidden field
 * before pressing Next. That is exactly the state a service change leaves behind
 * when the clearing script never runs — a page restored from cache, a hand-edited
 * request, a browser that dropped the JavaScript.
 *
 * Then it reads the stored submission back through Drush and reports what the
 * database actually holds. The arrival must be empty and the service must be the
 * pilot; anything else is the finding.
 *
 * The hidden field is written from page script, which is the one place this probe
 * is not a real-user path. That is the point of it: no customer can produce this
 * state with a mouse, which is why the server is the thing being tested.
 *
 * GUARDRAIL: refuses unless COURIER_FORM_URL points somewhere other than the live
 * host, and unless `--i-know-this-writes` is passed. Both are required. The active
 * `ppu` site is read-only for browser checks; this belongs on the isolated QA site
 * only.
 *
 * Usage:
 *   ddev exec bash -c 'COURIER_FORM_URL=https://ppu-qa.ddev.site/form/hg \
 *     QA_DRUSH_URI=https://ppu-qa.ddev.site \
 *     node qa/browser/mutation-sentinel.mjs \
 *     --runner=qa/browser/probe-ondemand-stale-slot.mjs --i-know-this-writes'
 */

import { execFileSync } from 'node:child_process';
import { mkdirSync, writeFileSync, unlinkSync } from 'node:fs';
import { sleep } from './webdriver.mjs';
import { FORM_URL, PILOT_SERVICE, answerCourierPage1, answerPilotPage1, onPage2, clickSlot }
  from './courier-flow.mjs';
import {
  clickControl, typeDate, pressNext, freshSession, waitFor, formQuiet, CSS_escape,
} from './user-actions.mjs';

const LIVE_HOSTS = ['ppu.ddev.site'];
const PILOT = PILOT_SERVICE;
const DATE = process.argv.find((a) => /^\d{4}-\d{2}-\d{2}$/.test(a)) || '2026-09-01';
const DRUSH_URI = process.env.QA_DRUSH_URI || new URL(FORM_URL).origin;
const READBACK = 'qa/tmp/ondemand-stale-readback.php';

if (!process.argv.includes('--i-know-this-writes')) {
  console.log('refused: this probe creates a submission. Pass --i-know-this-writes.');
  process.exit(2);
}
const host = new URL(FORM_URL).hostname;
if (LIVE_HOSTS.includes(host)) {
  console.log(`refused: ${host} holds real submissions and is read-only for browser checks.`);
  console.log('set COURIER_FORM_URL to the isolated QA site (https://ppu-qa.ddev.site/form/hg).');
  process.exit(2);
}
// A mismatch here would submit to one database and read the other, which reads as
// "the arrival was discarded" no matter what the code did.
if (new URL(DRUSH_URI).hostname !== host) {
  console.log(`refused: QA_DRUSH_URI (${DRUSH_URI}) and COURIER_FORM_URL (${FORM_URL})`
    + ' name different sites, so the read-back would not be of the row just written.');
  process.exit(2);
}

console.log(`STALE-ARRIVAL WRITE PROBE — ${FORM_URL} (date ${DATE}, read back via ${DRUSH_URI})`);

/** Puts the stale arrival back into the hidden field, as a crafted request would. */
const craftArrival = (browser, value) => browser.js(`
  const [value] = arguments;
  const el = document.querySelector('[name="dispatch_slot"]');
  if (!el) { return { ok: false, reason: 'no-dispatch-slot-element' }; }
  el.value = value;
  el.dispatchEvent(new Event('change', { bubbles: true }));
  return { ok: true, value: String(el.value || '') };
`, [value]);

/** Reads the hidden field back, so "crafted" is a measured fact. */
const arrivalNow = (browser) => browser.js(`
  const el = document.querySelector('[name="dispatch_slot"]');
  return el ? String(el.value || '') : null;
`);

/**
 * Why the wizard did not advance — printed instead of a bare "inconclusive".
 *
 * A probe that stalls and says only that it stalled cannot be told apart from the
 * server refusing the crafted request, which is one of the outcomes being tested.
 * So the page names itself: which page it is on, what the site said out loud, and
 * which visible control the browser itself considers unanswered.
 */
const whyStuck = (browser) => browser.js(`
  const shown = (el) => !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length);
  const text = (sel) => Array.from(document.querySelectorAll(sel))
    .filter(shown).map((el) => String(el.textContent || '').replace(/\\s+/g, ' ').trim())
    .filter((s) => s !== '');
  const form = document.querySelector('form[data-drupal-selector^="webform-submission"]')
    || document.querySelector('form.webform-submission-form');
  return {
    page: document.querySelector('[name="service_type"]') ? 1
      : (document.querySelector('[name="dispatch_slot"]') ? 2
        : (document.querySelector('[name="full_name"], [name="data_consent"]') ? 3 : 0)),
    errors: text('.messages--error, .messages--warning'),
    fieldErrors: text('.form-item--error-message, .webform-message--error'),
    unanswered: form ? Array.from(form.querySelectorAll('input, select, textarea'))
      .filter((el) => shown(el) && el.willValidate && !el.checkValidity())
      .map((el) => String(el.name || el.id || 'unnamed') + ': ' + String(el.validationMessage || ''))
      : ['no-webform-form'],
    dispatchSlot: (() => {
      const el = document.querySelector('[name="dispatch_slot"]');
      return el ? String(el.value || '') : null;
    })(),
  };
`);

/**
 * Clicks the wizard's real final submit control, inside the webform only.
 *
 * The control is discovered rather than named: Drupal uniquifies
 * `data-drupal-selector` values when a page carries more than one form, so a
 * hardcoded `edit-actions-submit` can be absent on a page that plainly has a
 * Submit button — which is what an earlier run reported. Every candidate it
 * considered is returned either way, so "absent" stays a readable finding instead
 * of a dead end. Restricted to the submission form, and Back/Previous is excluded,
 * so the probe cannot walk backwards or press some other form's button.
 */
async function clickSubmit(browser) {
  const found = await browser.js(`
    const shown = (el) => !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length);
    const form = document.querySelector('form[data-drupal-selector^="webform-submission"]')
      || document.querySelector('form.webform-submission-form');
    if (!form) { return { ok: false, reason: 'no-webform-form' }; }
    const controls = Array.from(form.querySelectorAll(
      'input[type="submit"], button[type="submit"], button:not([type])'))
      .filter(shown)
      .map((el) => ({
        id: String(el.id || ''),
        selector: String(el.getAttribute('data-drupal-selector') || ''),
        name: String(el.name || ''),
        label: String(el.value || el.textContent || '').replace(/\\s+/g, ' ').trim(),
      }));
    return { ok: true, controls };
  `);
  if (!found.ok) {
    return found;
  }
  const back = /back|previous|prev/i;
  const forward = /submit|send|request|confirm|finish|next/i;
  const target = found.controls.find((c) => !back.test(c.label + c.selector + c.name)
    && (forward.test(c.label) || /actions-submit/.test(c.selector) || c.name === 'op'));
  if (!target) {
    return { ok: false, reason: 'no-submit-control', candidates: found.controls };
  }
  const css = target.selector !== ''
    ? `form.webform-submission-form [data-drupal-selector="${CSS_escape(target.selector)}"],`
      + ` form[data-drupal-selector^="webform-submission"]`
      + ` [data-drupal-selector="${CSS_escape(target.selector)}"]`
    : `#${CSS_escape(target.id)}`;
  const clicked = await clickControl(browser, css);

  return Object.assign({ pressed: target.label || target.selector || target.id }, clicked);
}

/** Answers whatever the contact page asks, with obvious QA-database fixtures. */
const fillRequiredVisible = (browser) => browser.js(`
  const shown = (el) => !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length);
  const form = document.querySelector('form[data-drupal-selector^="webform-submission"]')
    || document.querySelector('form.webform-submission-form');
  if (!form) { return { ok: false, reason: 'no-webform-form' }; }
  const answered = [];
  Array.from(form.querySelectorAll('[required]')).forEach((el) => {
    if (!shown(el)) { return; }
    const name = String(el.name || '');
    if (el.type === 'checkbox' || el.type === 'radio') {
      if (!el.checked) { el.click(); }
      answered.push(name + '=' + (el.checked ? 'checked' : 'refused'));
      return;
    }
    if (el.tagName === 'SELECT') {
      const option = Array.from(el.options).find((o) => o.value !== '');
      if (option) { el.value = option.value; }
    }
    else if (el.type === 'email') { el.value = 'qa-fixture@example.invalid'; }
    else if (el.type === 'tel') { el.value = '555-555-0100'; }
    else if (el.type === 'number') { el.value = el.value || '1'; }
    else if (el.value === '') { el.value = 'QA Fixture'; }
    el.dispatchEvent(new Event('input', { bubbles: true }));
    el.dispatchEvent(new Event('change', { bubbles: true }));
    answered.push(name);
  });
  return { ok: true, answered };
`);

/** Presses the wizard's Back control and waits for page 1. */
async function pressBack(browser) {
  const clicked = await clickControl(browser, '[data-drupal-selector="edit-wizard-prev"]');
  if (!clicked.ok) {
    return clicked;
  }
  const back = await waitFor(browser, 'page 1 to render again', async () =>
    (await browser.js(`
      return document.querySelector('[name="service_type"]') !== null
        && document.querySelector('[name="dispatch_slot"]') === null;
    `)) === true, 20000);

  return { ...clicked, onPage1: back.ok };
}

/**
 * The newest `hg` row, read from the database rather than from the screen.
 *
 * Written as a script file and run through Drush because the values wanted are
 * element data on a Webform submission, which no SQL-shaped read returns tidily.
 * Read-only: it loads, prints, and saves nothing.
 */
function readBackNewestSubmission() {
  mkdirSync('qa/tmp', { recursive: true });
  writeFileSync(READBACK, `<?php

$storage = \\Drupal::entityTypeManager()->getStorage('webform_submission');
$ids = $storage->getQuery()
  ->accessCheck(FALSE)
  ->condition('webform_id', 'hg')
  ->sort('sid', 'DESC')
  ->range(0, 1)
  ->execute();
if ($ids === []) {
  print json_encode(['found' => FALSE]) . "\\n";
  return;
}
$submission = $storage->load(reset($ids));
$data = $submission->getData();
$read = static fn (string $key): string => isset($data[$key]) && is_scalar($data[$key])
  ? trim((string) $data[$key]) : '';
print json_encode([
  'found' => TRUE,
  'sid' => (int) $submission->id(),
  'service_type' => $read('service_type'),
  'preferred_service_date' => $read('preferred_service_date'),
  'dispatch_slot' => $read('dispatch_slot'),
  'dispatch_hold_token' => $read('dispatch_hold_token'),
  'is_draft' => (bool) $submission->isDraft(),
]) . "\\n";
`);
  try {
    const out = execFileSync('vendor/bin/drush', [`--uri=${DRUSH_URI}`, 'php:script', READBACK],
      { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] });
    const line = out.split('\n').map((l) => l.trim()).filter((l) => l.startsWith('{')).pop();

    return line ? JSON.parse(line) : { found: false, raw: out.slice(0, 300) };
  }
  catch (error) {
    return { found: false, error: String((error && error.message) || error).slice(0, 400) };
  }
  finally {
    try {
      unlinkSync(READBACK);
    }
    catch {
      // Nothing to clean up.
    }
  }
}

const findings = [];
const note = (name, pass, detail) => {
  findings.push({ name, pass: !!pass, detail });
  console.log(`   ${pass ? 'PASS' : 'FAIL'}  ${name}`);
  if (!pass) {
    console.log(`         ${JSON.stringify(detail)}`);
  }
};

const { browser } = await freshSession(1440, 900, FORM_URL);
try {
  // 1. A genuine Courier arrival, so the craft is a real planner offer.
  // The page-1 answers come from the gate's own sequence rather than a copy of
  // it: this probe's private copy typed into `total_weight`, a name the form does
  // not have, and skipped the two render waits — so the weight went nowhere, the
  // attestation was clicked before it existed, and every run ended "inconclusive"
  // at the Slot page instead of testing the server.
  await answerCourierPage1(browser);
  await pressNext(browser);
  const courierPage2 = await onPage2(browser);
  console.log(`\n1. Courier page 1 answered -> on the Slot page: ${courierPage2}`);
  if (!courierPage2) {
    console.log('\nPROBE INCONCLUSIVE: the Courier walk never reached the Slot page.');
    await browser.quit();
    process.exit(1);
  }

  await typeDate(browser, 'input[name="preferred_service_date"]', DATE);
  await waitFor(browser, 'pickup times to render', async () => (await browser.js(
    `return document.querySelectorAll('.custom-webform-dispatch__slot').length > 0;`)) === true, 25000);
  const selected = await clickSlot(browser);
  await sleep(1200);
  console.log(`2. Courier pickup time selected: ${selected.ok ? `dispatch_slot=${selected.value}` : selected.reason}`);
  const stale = String((selected && selected.value) || '');
  if (stale === '') {
    console.log('\nPROBE INCONCLUSIVE: no real Courier arrival to craft with.');
    await browser.quit();
    process.exit(1);
  }

  // 2. Back, switch to the pilot, and put the Courier arrival back.
  const back = await pressBack(browser);
  console.log(`3. Back pressed -> on page 1: ${back.onPage1 === true}`);
  await answerPilotPage1(browser);
  await pressNext(browser);
  const pilotPage2 = await onPage2(browser);
  console.log(`4. switched to ${PILOT} -> on the Slot page: ${pilotPage2}`);
  if (!pilotPage2) {
    console.log('\nPROBE INCONCLUSIVE: the pilot walk never reached the Slot page.');
    await browser.quit();
    process.exit(1);
  }

  const clearedBySwitch = await arrivalNow(browser);
  console.log(`5. arrival after the switch, before crafting: ${JSON.stringify(clearedBySwitch)}`);

  // The pilot's own page-2 questions, answered before the craft and not after.
  // On-Demand Delivery asks something Courier does not (`items_to_move_sameday`),
  // and the browser refuses to advance while it is empty — an earlier run stalled
  // there and read as "the server refused", which is one of the outcomes under
  // test. Answering afterwards would be worse than useless: any keystroke on the
  // form wakes the widget's input listener, which re-offers and clears the arrival
  // for a Manual Review service, so the probe would erase its own craft and then
  // prove only that the browser is tidy.
  const pilotAnswers = await fillRequiredVisible(browser);
  console.log(`6. pilot page 2 answered: `
    + `${pilotAnswers.ok ? (pilotAnswers.answered.join(', ') || 'nothing was required') : pilotAnswers.reason}`);
  await formQuiet(browser);

  const crafted = await craftArrival(browser, stale);
  const craftedNow = await arrivalNow(browser);
  console.log(`7. crafted the stale arrival back in: ${JSON.stringify(craftedNow)}`
    + ` (${crafted.ok ? 'set' : crafted.reason})`);

  // 3. Carry it through the wizard and submit.
  await pressNext(browser);
  const onPage3 = await browser.js(
    `return document.querySelector('[name="full_name"], [name="data_consent"]') !== null;`);
  console.log(`8. Next pressed -> on the contact page: ${onPage3}`);
  if (!onPage3) {
    console.log(`   stalled: ${JSON.stringify(await whyStuck(browser))}`);
    console.log('\nPROBE INCONCLUSIVE: the wizard never reached the contact page.');
    await browser.quit();
    process.exit(1);
  }
  const filled = await fillRequiredVisible(browser);
  console.log(`9. contact page answered: ${filled.ok ? filled.answered.join(', ') : filled.reason}`);
  const submitted = await clickSubmit(browser);
  console.log(`10. Submit clicked: ${JSON.stringify(submitted)}`);
  const outcome = await waitFor(browser, 'a confirmation or a server message', async () =>
    (await browser.js(`
      if (document.querySelector('.webform-confirmation') !== null) { return true; }
      if (document.querySelector('.messages--error') !== null) { return true; }
      return /confirmation/.test(window.location.pathname);
    `)) === true, 30000);
  console.log(`11. outcome reached: ${outcome.ok}`);
  await sleep(1500);
  await browser.quit();

  // 4. What the database actually holds.
  const stored = readBackNewestSubmission();
  console.log(`\n12. newest hg row: ${JSON.stringify(stored)}\n`);
  note('the submission was created', stored.found === true, stored);
  note('it is the On-Demand Delivery request that was submitted',
    stored.service_type === PILOT, { service_type: stored.service_type });
  note('the crafted arrival was discarded, not stored',
    stored.dispatch_slot === '', { dispatch_slot: stored.dispatch_slot, crafted: stale });
  note('no Hold marker was left claiming that arrival',
    stored.dispatch_hold_token === '', { dispatch_hold_token: stored.dispatch_hold_token });

  const failed = findings.filter((f) => !f.pass);
  console.log(`\nSTALE-ARRIVAL PROBE ${failed.length === 0 ? 'GREEN' : 'RED'} `
    + `${findings.length - failed.length}/${findings.length}`);
  console.log('The sentinel around this run reports the write it was supposed to make.');
  process.exit(failed.length === 0 ? 0 : 1);
}
catch (error) {
  console.log(`probe failed: ${error && error.message ? error.message : error}`);
  await browser.quit().catch(() => {});
  process.exit(1);
}
