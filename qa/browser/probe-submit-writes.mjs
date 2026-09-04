/**
 * @file
 * Deliberately creates one submission, to prove the sentinel can go red.
 *
 * A sentinel that has only ever printed GREEN is not evidence. This is the
 * control: a browser action that really does save a `hg` row, so
 * `mutation-sentinel.mjs` can be shown detecting it. It is also the reproduction
 * the incident asked for — it separates the wizard's Next click, which creates
 * nothing because `hg` runs with draft='none', from the final Submit click, which
 * is what writes.
 *
 * The two addresses are assigned without firing an input event, because the
 * Places widget's own listener clears the field on input. That is the one place
 * this probe is not a real-user path, and it is deliberate: the acceptance walk
 * exists to prove the real path, while this one exists only to make a write
 * happen so the detector can be checked against it.
 *
 * GUARDRAIL: refuses unless COURIER_FORM_URL points somewhere other than the live
 * host, and unless `--i-know-this-writes` is passed. Both are required. The live
 * `ppu` site is read-only for browser checks; this belongs on the isolated QA
 * site only.
 *
 * CLEANUP: writes `qa/tmp/task10d-write-control.json` before it opens the browser,
 * recording the two auto-increment maxima it is about to move.
 * `qa/support/task10d-write-control-teardown.php` reads that file and removes the
 * rows above them — the only rows this probe can have created — and nothing else.
 *
 * Usage:
 *   ddev exec bash -c 'COURIER_FORM_URL=https://ppu-qa.ddev.site/form/hg \
 *     QA_DRUSH_URI=https://ppu-qa.ddev.site \
 *     node qa/browser/mutation-sentinel.mjs \
 *     --runner=qa/browser/probe-submit-writes.mjs --i-know-this-writes'
 */

import { execFileSync } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';
import { Browser, sleep } from './webdriver.mjs';
import { FORM_URL, SAMPLE, fillPage1, nextPage, onPage2, setDateField, clickSlot } from './courier-flow.mjs';
import { openDays } from './open-days.mjs';

const LIVE_HOSTS = ['ppu.ddev.site'];
const MANIFEST = 'qa/tmp/task10d-write-control.json';

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

// A day the resource actually works, read after the guardrails and not before: a
// refused invocation should touch nothing at all, not even a read-only script.
// Hardcoding a day made this probe stop writing the moment that day passed, which
// would have looked like a sentinel that no longer detects writes — the opposite
// of what this control is for.
const DATE = openDays(1).dates[0];

/**
 * The auto-increment maxima this probe is about to move.
 *
 * Read from the same snapshot the sentinel uses, so the manifest and the verdict
 * cannot disagree about where the ledger stood. Every row above these maxima was
 * created after this line ran, which is the whole rule the teardown deletes by —
 * no pattern, no date guess, no "looks like QA data" sweep.
 *
 * Every ledger one submit can reach is recorded, not just the two that moved the
 * last time this ran. A submit that manages to open a Commerce order writes rows
 * the teardown can only remove if it was told where the table stood beforehand,
 * and a manifest that is silent about a table makes the teardown leave it alone
 * rather than guess a floor of zero and empty it.
 */
function baseline() {
  const uri = String(process.env.QA_DRUSH_URI || '').trim();
  const args = uri === '' ? [] : [`--uri=${uri}`];
  const out = execFileSync('vendor/bin/drush', [...args, 'php:script', 'qa/support/mutation-state.php'], {
    encoding: 'utf8', maxBuffer: 8 * 1024 * 1024,
  });
  const line = out.trim().split('\n').filter((l) => l.startsWith('{')).pop();
  if (!line) { throw new Error('qa/support/mutation-state.php printed no JSON line'); }
  const state = JSON.parse(line);
  const max = (key) => Number((state[key] || {}).max || 0);

  return {
    submission_max: max('webform_submission_hg'),
    reservation_max: max('dispatch_reservation'),
    guard_max: max('dispatch_guard'),
    status_max: max('dispatch_status'),
    notification_max: max('dispatch_notification'),
    notify_claim_max: max('dispatch_notify_claim'),
    attempt_max: max('checkout_attempt'),
    review_max: max('review_request'),
    order_max: max('commerce_order'),
    order_item_max: max('commerce_order_item'),
    payment_max: max('commerce_payment'),
  };
}

/** Records what a teardown may remove, before anything has been created. */
function writeManifest(before, extra) {
  mkdirSync('qa/tmp', { recursive: true });
  writeFileSync(MANIFEST, `${JSON.stringify({
    site: new URL(FORM_URL).hostname,
    written_by: 'qa/browser/probe-submit-writes.mjs',
    webform_id: 'hg',
    date_under_test: DATE,
    baseline: before,
    ...extra,
  }, null, 2)}\n`);
}

const BEFORE = baseline();

console.log(`WRITE PROBE — ${FORM_URL} (date ${DATE})`);
console.log(`baseline: hg max ${BEFORE.submission_max}, reservation max ${BEFORE.reservation_max},`
  + ` order max ${BEFORE.order_max} (+8 more) -> manifest ${MANIFEST}`);

// Written before the browser opens, not after the walk. A probe that crashes
// halfway has still created whatever it created, and a teardown that can only
// clean up after a tidy exit is the one case where fixture rows get left behind.
writeManifest(BEFORE, { phase: 'started', outcome: null });

/** Puts an address in the field without waking the widget's input listener. */
const setAddressQuietly = (browser, name, value) => browser.js(`
  const [name, value] = arguments;
  const el = document.querySelector('[name="' + name + '"]');
  if (!el) { return { ok: false, reason: 'absent' }; }
  el.value = value;
  el.dispatchEvent(new Event('change', { bubbles: true }));
  return { ok: true, length: el.value.length };
`, [name, value]);

/** Clicks the wizard's real final submit control, inside the webform only. */
const clickSubmit = (browser) => browser.js(`
  const form = document.querySelector('form[data-drupal-selector^="webform-submission"]')
    || document.querySelector('form.webform-submission-form');
  if (!form) { return { ok: false, reason: 'no-webform-form' }; }
  const btn = form.querySelector('[data-drupal-selector="edit-actions-submit"]')
    || Array.from(form.querySelectorAll('input[type="submit"], button[type="submit"]'))
      .find((el) => !/previous|next|draft|search/i.test(el.value || el.textContent || ''));
  if (!btn) { return { ok: false, reason: 'no-submit-button' }; }
  btn.click();
  return { ok: true, label: String(btn.value || btn.textContent || '').trim() };
`);

/**
 * Answers every visible required control with a fixture value.
 *
 * Page 3 asks for a name, a phone number and an email, and which of the handoff
 * controls it asks for depends on `#states`. Enumerating them here would bake in
 * today's configuration; answering whatever is visible and required keeps the
 * probe working when that configuration changes. The values are deliberately
 * obvious fixtures — this runs on the QA database only.
 */
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

const browser = await Browser.open();
try {
  await browser.get(FORM_URL);
  await sleep(1500);

  await fillPage1(browser);
  const origin = await setAddressQuietly(browser, 'origin_address', SAMPLE.origin_address);
  const destination = await setAddressQuietly(browser, 'destination_address', SAMPLE.destination_address);
  console.log(`\n1. page 1 filled; addresses set quietly`
    + ` (origin ${origin.length} chars, destination ${destination.length} chars)`);

  const next = await nextPage(browser);
  const page2 = await onPage2(browser);
  console.log(`2. Next clicked (${JSON.stringify(next.label || next.reason)}) -> on the Slot page: ${page2}`);
  if (!page2) {
    console.log('   the wizard did not advance; nothing to submit');
    console.log('\nWRITE PROBE INCONCLUSIVE');
    await browser.quit();
    process.exit(1);
  }

  const date = await setDateField(browser, 'preferred_service_date', DATE);
  const slots = await browser.until('Slots to render', async () =>
    (await browser.js(`return document.querySelectorAll('.custom-webform-dispatch__slot').length;`)) > 0,
    { timeout: 25000 }).then(() => browser.js(
      `return document.querySelectorAll('.custom-webform-dispatch__slot').length;`))
    .catch(() => 0);
  console.log(`3. date ${JSON.stringify(date.value || date.reason)} -> ${slots} Slot(s) offered`);

  const slot = await clickSlot(browser);
  console.log(`4. Slot clicked: ${slot.ok ? `dispatch_slot=${slot.value}` : slot.reason}`);
  await sleep(1200);

  // Page 2 to page 3. The Slot page is not the last page: the contact page holds
  // the only Submit control, so a walk that stops here can never write.
  const toPage3 = await nextPage(browser);
  const onPage3 = await browser.js(`
    return document.querySelector('[name="full_name"], [name="data_consent"]') !== null;
  `);
  console.log(`5. Next clicked (${JSON.stringify(toPage3.label || toPage3.reason)}) -> on the contact page: ${onPage3}`);
  if (!onPage3) {
    console.log('   the wizard did not reach the contact page; nothing to submit');
    console.log('\nWRITE PROBE INCONCLUSIVE');
    await browser.quit();
    process.exit(1);
  }

  const filled = await fillRequiredVisible(browser);
  console.log(`6. contact page answered: ${filled.ok ? filled.answered.join(', ') : filled.reason}`);

  const submitted = await clickSubmit(browser);
  console.log(`7. Submit clicked: ${submitted.ok ? JSON.stringify(submitted.label) : submitted.reason}`);
  // Where it landed, not whether it matched one of three shapes. The first version
  // of this looked only for a confirmation element, an error message or the word
  // "confirmation" in the path, and reported `timed-out` when a submit that had in
  // fact worked redirected into Commerce checkout instead — a successful write
  // described as a hang. Anything that leaves the form page is an outcome; the
  // sentinel around this run is what decides whether it was the right one.
  const outcome = await browser.until('the form page to resolve into something', async () =>
    (await browser.js(`
      if (document.querySelector('.webform-confirmation') !== null) { return 'confirmation'; }
      if (document.querySelector('.messages--error') !== null) { return 'server-error'; }
      const path = window.location.pathname;
      if (/confirmation/.test(path)) { return 'confirmation-url'; }
      if (/checkout/.test(path)) { return 'checkout:' + path; }
      if (!/\\/form\\//.test(path)) { return 'left-the-form:' + path; }
      return null;
    `)), { timeout: 30000 }).catch(() => 'timed-out-on-the-form-page');
  console.log(`8. outcome: ${outcome}`);
  writeManifest(BEFORE, { phase: 'complete', outcome });

  console.log('\nWRITE PROBE DONE — the sentinel around this run decides the verdict.');
  console.log(`clean up with: ddev exec vendor/bin/drush --uri=${process.env.QA_DRUSH_URI || 'https://ppu-qa.ddev.site'}`
    + ' php:script qa/support/task10d-write-control-teardown.php');
  await browser.quit();
}
catch (error) {
  console.log(`write probe failed: ${error && error.message ? error.message : error}`);
  await browser.quit().catch(() => {});
  process.exit(1);
}
