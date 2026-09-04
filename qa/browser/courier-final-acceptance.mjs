// FINAL INDEPENDENT ACCEPTANCE — a fresh anonymous Express Courier walk driven
// only through real WebDriver user actions.
//
// This deliberately shares nothing with the gate's input path. courier-flow.mjs
// fills the form by assigning `element.value` and dispatching synthetic input and
// change events, and asks the offer endpoint by assembling a body in page script.
// Both are legitimate instruments, and both are exactly what an independent
// acceptance must not use: a value assigned from script proves the widget reacts
// to an event, not that a customer can produce that state with a mouse and a
// keyboard. So every answer below is a WebDriver element click or a WebDriver
// keystroke against a control the browser reports as displayed, and no offer
// request is ever composed here — the only offers that exist are the ones the
// page decided to send.
//
// What is imported from courier-flow.mjs is read-only: the DOM inventory, the
// sidebar reader, the resource-name list, and the two observers (the widget's own
// booking-state event, and a fetch wrapper that records and redacts what the page
// sent). Observing traffic is not fabricating it.
//
// The user actions themselves now live in user-actions.mjs. They were lifted out
// of this file unchanged when the On-Demand pilot needed the same instruments:
// two harnesses each carrying their own Places shadow-root traversal is how one
// of them quietly stops driving the real control.
//
// Usage: ddev exec node qa/browser/courier-final-acceptance.mjs [YYYY-MM-DD]

import { sleep } from './webdriver.mjs';
import {
  FORM_URL, SAMPLE, inventory, sidebarWording, requestNames, messages,
  armBookingState, armNetworkCapture, capturedCalls, onPage2,
} from './courier-flow.mjs';
import {
  clickControl, typeInto, typeAddress, typeDate, pressNext,
  freshSession, severeConsole, waitFor,
} from './user-actions.mjs';
import { openDays } from './open-days.mjs';
import { clearThrottles } from './throttles.mjs';

// The day under test. It used to default to a hardcoded '2026-09-01', which is a
// correct default for as long as that day is in the future and a silent source of
// false reds afterwards — a request for a past day is answered "unavailable" no
// matter how well the form works. The date now comes from the resource's own
// working hours when the command line does not name one.
const OPEN = openDays(1);
const DATE = OPEN.dates[0];

const STAMP = new Date().toISOString().replace(/[:.]/g, '-').slice(0, 19);
const WIDTHS = [
  { label: 'desktop', width: 1440, height: 900 },
  { label: 'mobile', width: 390, height: 844 },
];

// The Slot count a valid Courier Send must offer on the day under test.
//
// Per-site, not universal: the working hours behind it are operator-set
// configuration and the two sites carry different ones — ppu-qa's resource works
// Mon-Wed 08:00-18:00 and offers 19, the active ppu resource works Mondays
// 08:00-17:00 and offers 17. Scoring an active-site walk against the wrong
// constant would report a red that is really a schedule difference, and changing
// the active schedule to suit a harness is forbidden. So the count is an input
// with the ppu-qa value as its default, which leaves every existing invocation
// scored exactly as before.
const EXPECTED_SLOTS = Number.parseInt(process.env.EXPECTED_SLOTS || '', 10) > 0
  ? Number.parseInt(process.env.EXPECTED_SLOTS, 10)
  : 19;

// One scenario at a time, when asked. The mutation sentinel has to bracket a
// single walk with a database snapshot on either side: a whole run's aggregate
// says a write happened somewhere, which is not enough to name the action that
// wrote. ACCEPTANCE_ONLY takes `<width>:<check>`, e.g. `desktop:A`, and an empty
// value keeps the full six-walk run. The tally then covers only what ran, so a
// filtered run reports its own denominator rather than pretending to be complete.
const ONLY = String(process.env.ACCEPTANCE_ONLY || '').trim();
const wanted = (label, check) => ONLY === ''
  || ONLY.toLowerCase() === `${label}:${check}`.toLowerCase();

// ---------------------------------------------------------------------------
// The walk
// ---------------------------------------------------------------------------

/**
 * Page 1, answered only through visible controls.
 *
 * `total_weight_unit` is a hidden element carrying its own default and a customer
 * never touches it, so this does not either; its value is read and reported so
 * the pound assumption is evidence rather than something the harness supplied.
 * `single_driver_handling` and `prohibited_items_ack` are never touched at all —
 * both are retired, and a filler that answered them would be re-creating the
 * fixture dependency this acceptance exists to disprove.
 */
async function answerPage1(browser, { weight = SAMPLE.total_weight_lbs, attest = true } = {}) {
  const trace = {};
  const waits = [];

  trace.service_type = await clickControl(browser,
    `input[name="service_type"][value="${SAMPLE.service_type}"]`);
  waits.push(await waitFor(browser, 'the courier branch to render', async () =>
    (await browser.js(`return document.querySelector('[name="delivery_mode"]') !== null;`)) === true));

  trace.delivery_mode = await clickControl(browser,
    `input[name="delivery_mode"][value="${SAMPLE.delivery_mode}"]`);
  trace.origin_address = await typeAddress(browser, 'origin_address',
    SAMPLE.origin_address);
  trace.destination_address = await typeAddress(browser, 'destination_address',
    SAMPLE.destination_address);

  // Page 1 must still be the page. An Enter keystroke that lands outside an open
  // suggestion list submits the wizard, and every later reading would then be of
  // the wrong page while looking like a rendering failure.
  trace.stillOnPage1 = await browser.js(`
    return {
      onPage1: document.querySelector('[name="service_type"]') !== null,
      onPage2: document.querySelector('[name="dispatch_slot"]') !== null,
      url: String(location.pathname),
    };
  `);

  waits.push(await waitFor(browser, 'the weight question to render', async () =>
    (await browser.js(`
      const el = document.querySelector('[name="total_weight_lbs"]');
      return !!el && el.offsetParent !== null;
    `)) === true));
  trace.total_weight_lbs = await typeInto(browser, 'input[name="total_weight_lbs"]', String(weight));

  if (attest) {
    waits.push(await waitFor(browser, 'the contents attestation to render', async () =>
      (await browser.js(`
        const el = document.querySelector('[name="courier_prohibited_ack"]');
        return !!el && el.offsetParent !== null;
      `)) === true));
    trace.courier_prohibited_ack = await clickControl(browser,
      'input[name="courier_prohibited_ack"]');
  }
  else {
    trace.courier_prohibited_ack = { ok: false, reason: 'left-unanswered-by-scenario' };
  }
  trace.waits = waits.filter((wait) => !wait.ok);

  // Read back what page 1 now holds, plus the state of the two retired questions.
  trace.page1 = await browser.js(`
    const val = (name) => {
      const els = Array.from(document.querySelectorAll('[name="' + name + '"]'));
      const checkable = els.filter((el) => el.type === 'checkbox' || el.type === 'radio');
      if (checkable.length) {
        const on = checkable.find((el) => el.checked);
        return on ? String(on.value) : '';
      }
      return els.length ? String(els[0].value || '') : '';
    };
    const shown = (el) => {
      if (!el) { return false; }
      const cs = getComputedStyle(el);
      if (cs.visibility === 'hidden' || cs.display === 'none' || el.type === 'hidden') { return false; }
      const r = el.getBoundingClientRect();
      if (r.width === 0 && r.height === 0) { return false; }
      return el.offsetParent !== null || cs.position === 'fixed';
    };
    const driver = Array.from(document.querySelectorAll('[name="single_driver_handling"]'));
    const text = document.body ? document.body.innerText : '';
    return {
      service_type: val('service_type'),
      delivery_mode: val('delivery_mode'),
      origin_address: val('origin_address'),
      destination_address: val('destination_address'),
      total_weight_lbs: val('total_weight_lbs'),
      total_weight_unit: val('total_weight_unit'),
      courier_prohibited_ack: val('courier_prohibited_ack'),
      placesWidgets: document.querySelectorAll('gmp-place-autocomplete').length,
      addressStatuses: Array.from(
        document.querySelectorAll('.custom-address-autocomplete-status'),
      ).map((el) => String(el.textContent || '').trim()),
      driverControls: driver.length,
      driverVisible: driver.filter(shown).length,
      driverTitleText: text.indexOf('Can one person carry this without help') === -1 ? 0 : 1,
      twoPeopleText: text.indexOf('No, it needs two people') === -1 ? 0 : 1,
      nativeInvalid: Array.from(document.querySelectorAll('input, select, textarea'))
        .filter((el) => shown(el) && el.willValidate && !el.checkValidity())
        .map((el) => String(el.name || el.id || el.type)),
    };
  `);

  return trace;
}

/**
 * Everything this brief scores that the shared inventory does not already read.
 */
function extraReads(browser) {
  return browser.js(`
    const shown = (el) => {
      if (!el) { return false; }
      const cs = getComputedStyle(el);
      if (cs.visibility === 'hidden' || cs.display === 'none' || el.type === 'hidden') { return false; }
      const r = el.getBoundingClientRect();
      if (r.width === 0 && r.height === 0) { return false; }
      return el.offsetParent !== null || cs.position === 'fixed';
    };
    const all = (sel) => Array.from(document.querySelectorAll(sel));
    const headingText = 'Choose your pickup time';
    // Per-control measurement for the questions this brief says must not be on
    // screen. A count alone cannot tell "removed" from "present but masked", and
    // masked-but-submittable is exactly the state that must not pass unnoticed,
    // so self and wrapper painting are both read.
    const detail = (name) => all('[name="' + name + '"]').map((el) => {
      const cs = getComputedStyle(el);
      const wrapper = el.closest('.js-form-item, .form-item, .js-form-wrapper, fieldset')
        || el.parentElement;
      const wcs = wrapper ? getComputedStyle(wrapper) : null;
      const rect = el.getBoundingClientRect();
      const label = el.id ? document.querySelector('label[for="' + el.id + '"]') : null;
      const legend = el.closest('fieldset')
        ? el.closest('fieldset').querySelector('legend')
        : null;
      // Which ancestor actually hides it, at whatever depth. Webform puts
      // js-webform-states-hidden on the element a #states rule targets: for a
      // select that is its own .js-form-item, but for radios it is the enclosing
      // fieldset, while each option keeps a painted .js-form-item of its own.
      // Reading only the immediate wrapper therefore reports a radio group as
      // unhidden even though nothing about it reaches the screen.
      const statesHidden = el.closest('.js-webform-states-hidden');
      const shcs = statesHidden ? getComputedStyle(statesHidden) : null;
      return {
        hiddenByTag: statesHidden ? statesHidden.tagName.toLowerCase() : null,
        hiddenByClass: statesHidden ? String(statesHidden.className || '') : null,
        hiddenByDisplay: shcs ? shcs.display : null,
        hiddenByInlineStyle: statesHidden
          ? String(statesHidden.getAttribute('style') || '') : null,
        hiddenByIsSelfWrapper: !!(statesHidden && statesHidden === wrapper),
        tag: el.tagName.toLowerCase(),
        type: String(el.type || ''),
        value: String(el.value || ''),
        checked: (el.type === 'checkbox' || el.type === 'radio') ? !!el.checked : null,
        disabled: !!el.disabled,
        selfDisplay: cs.display,
        selfVisibility: cs.visibility,
        selfClass: String(el.className || ''),
        wrapperClass: wrapper ? String(wrapper.className || '') : null,
        wrapperDisplay: wcs ? wcs.display : null,
        wrapperInlineStyle: wrapper ? String(wrapper.getAttribute('style') || '') : null,
        wrapperStates: wrapper ? String(wrapper.getAttribute('data-drupal-states') || '') : '',
        rect: [Math.round(rect.width), Math.round(rect.height)],
        visible: shown(el),
        labelText: label ? String(label.textContent || '').trim().slice(0, 70) : null,
        legendText: legend ? String(legend.textContent || '').trim().slice(0, 70) : null,
      };
    });
    const headings = all('h1, h2, h3, h4, h5, h6, legend, .custom-webform-dispatch__title')
      .filter((el) => shown(el) && String(el.textContent || '').trim().indexOf(headingText) !== -1);
    const widgets = all('.custom-webform-dispatch').filter(shown);
    const dateInputs = all('input[type="date"]').filter(shown);
    return {
      choosePickupTimeHeadings: headings.length,
      choosePickupTimeHeadingTags: headings.map((el) => el.tagName.toLowerCase()),
      slotWidgets: widgets.length,
      slotHiddenInputs: all('[name="dispatch_slot"]').length,
      visibleDateInputNames: dateInputs.map((el) => String(el.name || ('#' + el.id))),
      // Retired questions, counted whether painted or not: a CSS-hidden control
      // would still be here, which is how "removed" is told from "covered up".
      driverControlsAnywhere: all('[name="single_driver_handling"]').length,
      restrictedControlsAnywhere: all('[name="prohibited_items_ack"]').length,
      preferredServiceTimeAnywhere: all('[name="preferred_service_time"]').length,
      dateFlexibilityAnywhere: all('[name="date_flexibility"]').length,
      pickupDateFieldsAnywhere: all('[name*="pickup_date"]').length,
      preferredServiceTimeVisible: all('[name="preferred_service_time"]').filter(shown).length,
      dateFlexibilityVisible: all('[name="date_flexibility"]').filter(shown).length,
      preferredServiceTimeDetail: detail('preferred_service_time'),
      dateFlexibilityDetail: detail('date_flexibility'),
      // A witness that the two zeroes above mean "not in this document" and not
      // "the name selector stopped matching anything": the shell's own date
      // question, read by the same machinery, on the same page, in one breath.
      shellDateAnywhere: all('[name="preferred_service_date"]').length,
      // Where the estimate phrase is painted. A bare count cannot distinguish
      // "stated once in the widget and once in the order summary" from "the price
      // line rendered twice", and only the second would be a defect.
      instantEstimatePlaces: all('*')
        .filter((el) => shown(el)
          && String(el.textContent || '').indexOf('Instant estimate') !== -1
          && !Array.from(el.children).some((child) =>
            String(child.textContent || '').indexOf('Instant estimate') !== -1))
        .map((el) => ({
          tag: el.tagName.toLowerCase(),
          cls: String(el.className || '').slice(0, 60),
          text: String(el.textContent || '').trim().slice(0, 70),
        })),
      dispatchSlotValue: (all('[name="dispatch_slot"]')[0] || {}).value || '',
    };
  `);
}

/**
 * One scenario at one width, start to finish, in its own browser session.
 */
async function runScenario({ label, file, width, height, weight, attest }) {
  const lines = [];
  const say = (text) => { lines.push(text); };
  // Before the session, not after: every walk in this run arrives from the same
  // Selenium container, so the per-IP preview and offer buckets fill as the run
  // goes on. Without this the fourth walk read 429 and scored it as a refusal
  // stated in no words, which is a finding about a throttle, not about Courier.
  const throttle = clearThrottles();
  const { browser, reset } = await freshSession(width, height, FORM_URL);
  const errors = [];
  let record = null;

  try {
    say(`  preview/offer throttle: ${throttle}`);
    say(`  storage/cookie reset: cookies=${reset.cookiesAfterClear}`
      + ` sessionStorage=${reset.sessionStorageAfterClear}`
      + ` localStorage=${reset.localStorageAfterClear}`);

    const page1 = await answerPage1(browser, { weight, attest });
    say('  page-1 actions: ' + Object.entries(page1)
      .filter(([key]) => ['page1', 'waits', 'stillOnPage1'].indexOf(key) === -1)
      .map(([key, value]) => `${key}=${value.ok ? (value.via || 'typed') : value.reason}`)
      .join(' '));
    if ((page1.waits || []).length) {
      say('  page-1 waits that expired: ' + JSON.stringify(page1.waits));
    }
    if (page1.stillOnPage1 && page1.stillOnPage1.onPage1 !== true) {
      say('  WARNING the walk left page 1 while answering it: '
        + JSON.stringify(page1.stillOnPage1));
    }
    say('  page-1 answers: ' + JSON.stringify({
      service_type: page1.page1.service_type,
      delivery_mode: page1.page1.delivery_mode,
      total_weight_lbs: page1.page1.total_weight_lbs,
      total_weight_unit: page1.page1.total_weight_unit,
      courier_prohibited_ack: page1.page1.courier_prohibited_ack,
    }));
    say(`  addresses accepted through the Places widget`
      + ` (${page1.page1.placesWidgets} widgets on the page): `
      + JSON.stringify({
        origin_address: page1.page1.origin_address,
        destination_address: page1.page1.destination_address,
        status: page1.page1.addressStatuses,
      }));
    say('  suggestion selection: ' + JSON.stringify({
      origin: {
        via: page1.origin_address.via,
        predictions: page1.origin_address.predictions,
        attempts: page1.origin_address.attempts,
        tried: page1.origin_address.tried,
      },
      destination: {
        via: page1.destination_address.via,
        predictions: page1.destination_address.predictions,
        attempts: page1.destination_address.attempts,
        tried: page1.destination_address.tried,
      },
    }));
    say(`  page-1 retired questions: single_driver_handling ${page1.page1.driverControls} controls,`
      + ` ${page1.page1.driverVisible} visible, title x${page1.page1.driverTitleText},`
      + ` two-person option x${page1.page1.twoPeopleText}`);
    if (page1.page1.nativeInvalid.length) {
      say('  page-1 controls the browser considers invalid: '
        + JSON.stringify(page1.page1.nativeInvalid));
    }

    errors.push(...await severeConsole(browser));

    const next = await pressNext(browser);
    say(`  Next -> ${next.ok ? next.outcome : next.reason}`);

    const reachedPage2 = (await onPage2(browser)) === true;
    if (!reachedPage2) {
      const shown = await messages(browser);
      const invalid = await browser.js(`
        return Array.from(document.querySelectorAll('input, select, textarea'))
          .filter((el) => el.willValidate && !el.checkValidity() && el.offsetParent !== null)
          .map((el) => ({ name: String(el.name || el.id), message: String(el.validationMessage || '') }));
      `);
      const slots = await browser.js(`
        return {
          slotButtons: document.querySelectorAll('.custom-webform-dispatch__slot').length,
          slotWidgets: document.querySelectorAll('.custom-webform-dispatch').length,
          dateInputs: document.querySelectorAll('input[name="preferred_service_date"]').length,
        };
      `);
      const names = await requestNames(browser);
      say('  server messages: ' + JSON.stringify(shown));
      say('  blocked controls: ' + JSON.stringify(invalid));
      say(`  slots on the page: ${slots.slotButtons} buttons,`
        + ` ${slots.slotWidgets} widget(s), ${slots.dateInputs} date input(s)`);
      say('  request names: ' + JSON.stringify(names));
      errors.push(...await severeConsole(browser));
      say(`  console errors: ${JSON.stringify(errors)}`);
      const shot = await browser.screenshot(`qa/artifacts/${STAMP}-acceptance-${file}.png`);
      say(`  screenshot: ${shot}`);
      record = {
        reachedPage2: false, blockedAt: 'page-1', blocked: invalid, messages: shown,
        page1: page1.page1, names, slots, errors, screenshot: shot,
      };
      return { lines, record };
    }

    await armBookingState(browser);
    await armNetworkCapture(browser);

    const typed = await typeDate(browser, 'input[name="preferred_service_date"]', DATE);
    say(`  Preferred Service Date typed: ${typed.ok
      ? `"${typed.typed}" -> ${typed.value}`
      : `${typed.reason} ${JSON.stringify(typed.tried || [])}`}`);

    if (typed.ok) {
      await browser.until('the widget to publish a verdict', async () =>
        ['available', 'unavailable', 'manual_review'].indexOf(
          await browser.js(`return window.__cwhBookingState;`)) !== -1,
        { timeout: 25000 });
      // The widget drops aria-busy in its finally; give the render that follows
      // the response one turn of the event loop before counting buttons.
      await browser.until('the widget to finish rendering', async () =>
        (await browser.js(`
          const el = document.querySelector('.custom-webform-dispatch');
          return !!el && el.getAttribute('aria-busy') !== 'true';
        `)) === true, { timeout: 15000 });
      await sleep(300);
    }

    const inv = await inventory(browser);
    const extra = await extraReads(browser);
    const sidebar = await sidebarWording(browser);
    const calls = await capturedCalls(browser);
    const names = await requestNames(browser);
    errors.push(...await severeConsole(browser));
    // Bring the date field and the pickup-time buttons into one frame. A
    // screenshot captures the viewport, and at 900px tall the buttons sit below
    // the fold, so an un-scrolled capture would show the heading but not the
    // thing the brief asks to see: bookable times.
    const shotFrame = await browser.js(`
      var widget = document.querySelector('.custom-webform-dispatch');
      var date = document.querySelector('input[type="date"]');
      var anchor = date || widget;
      if (anchor) { anchor.scrollIntoView({block: 'start'}); window.scrollBy(0, -24); }
      return {scrolledTo: anchor ? anchor.name || anchor.className : null,
        scrollY: Math.round(window.scrollY)};`);
    await sleep(400);
    const shot = await browser.screenshot(`qa/artifacts/${STAMP}-acceptance-${file}.png`);

    const offers = calls.filter((call) => /dispatch\/offer/.test(call.path));
    const lastOffer = offers.length ? offers[offers.length - 1] : null;

    say(`  booking state: ${inv.bookingState} (${inv.bookingStateChanges} transitions)`);
    say(`  status text: "${String(inv.status).trim()}"`);
    say(`  price text:  "${String(inv.priceText).trim()}"`);
    say(`  date help:   "${inv.dateHelpText}"`);
    say(`  slots: ${inv.slotButtonsVisible} visible, ${inv.slotButtonsUnbookable} unbookable,`
      + ` selected ${inv.slotSelected}, dispatch_slot="${extra.dispatchSlotValue}"`);
    say(`  headings: "Choose your pickup time" x${extra.choosePickupTimeHeadings}`
      + ` ${JSON.stringify(extra.choosePickupTimeHeadingTags)},`
      + ` timezone note x${inv.textTimezoneNote}`);
    say(`  date inputs: ${extra.visibleDateInputNames.length} visible`
      + ` ${JSON.stringify(extra.visibleDateInputNames)}`);
    say(`  slot widgets: ${extra.slotWidgets} visible container(s),`
      + ` ${extra.slotHiddenInputs} hidden input(s),`
      + ` legacy reservation widgets ${inv.legacyReservationWidgets}`);
    say('  forbidden wording counts: ' + JSON.stringify({
      manualReviewRequired: inv.textManualReviewRequired,
      staffWillPrice: inv.textStaffWillPrice,
      confirmedAfterStaffReview: inv.textConfirmedAfterStaffReview,
      preferredServiceTime: inv.textPreferredServiceTime,
      pickupDate: inv.textPickupDate,
    }));
    say('  retired controls anywhere on page 2: ' + JSON.stringify({
      single_driver_handling: extra.driverControlsAnywhere,
      prohibited_items_ack: extra.restrictedControlsAnywhere,
      preferred_service_time: extra.preferredServiceTimeAnywhere,
      date_flexibility: extra.dateFlexibilityAnywhere,
      pickup_date: extra.pickupDateFieldsAnywhere,
    }));
    say(`  of those, visible to a customer: preferred_service_time`
      + ` ${extra.preferredServiceTimeVisible}, date_flexibility ${extra.dateFlexibilityVisible}`);
    if (extra.preferredServiceTimeAnywhere || extra.dateFlexibilityAnywhere) {
      say('  preferred_service_time detail: '
        + JSON.stringify(extra.preferredServiceTimeDetail));
      say('  date_flexibility detail: ' + JSON.stringify(extra.dateFlexibilityDetail));
    }
    say(`  "Instant estimate" occurrences in visible text: ${inv.textInstantEstimate}`);
    say('  where the estimate phrase is painted: '
      + JSON.stringify(extra.instantEstimatePlaces));
    say('  published contract: required_facts '
      + JSON.stringify(inv.dispatchRequiredFacts)
      + `, value_fields carries single_driver_handling: `
      + JSON.stringify((inv.dispatchValueFields || []).includes('single_driver_handling')));
    if (lastOffer) {
      say(`  the page's own offer: POST ${lastOffer.path} -> ${lastOffer.status}`);
      say('    request.values keys: '
        + JSON.stringify(Object.keys((lastOffer.request || {}).values || {}).sort()));
      say('    single_driver_handling in that body: '
        + JSON.stringify(Object.prototype.hasOwnProperty.call(
          (lastOffer.request || {}).values || {}, 'single_driver_handling')));
      say('    response: ' + JSON.stringify(lastOffer.response));
    }
    else {
      say('  the page sent no offer request');
    }
    say('  sidebar: ' + JSON.stringify(sidebar));
    say('  request names: ' + JSON.stringify(names));
    say(`  console errors: ${JSON.stringify(errors)}`);
    say(`  screenshot: ${shot} (framed at ${JSON.stringify(shotFrame)})`);

    record = {
      reachedPage2: true, inv, extra, sidebar, offer: lastOffer, names, errors,
      typed, page1: page1.page1, screenshot: shot,
    };
  }
  finally {
    await browser.quit();
  }

  return { lines, record };
}

// ---------------------------------------------------------------------------
// Assertions
// ---------------------------------------------------------------------------

class Score {

  constructor() {
    this.rows = [];
  }

  ok(label, condition, detail = '') {
    this.rows.push({ label, pass: condition === true, detail });
    return condition === true;
  }

  get passed() {
    return this.rows.filter((row) => row.pass).length;
  }

  get failed() {
    return this.rows.filter((row) => !row.pass);
  }

  print(prefix) {
    this.rows.forEach((row) => {
      console.log(`  [${row.pass ? 'PASS' : 'FAIL'}] ${prefix}${row.label}`
        + (row.pass || !row.detail ? '' : ` — ${row.detail}`));
    });
  }

}

/** The primary case: a valid Courier Send must reach bookable times. */
function scoreEligible(score, prefix, r) {
  score.ok(`${prefix}reached the pickup-time page`, r.reachedPage2 === true,
    `blocked at ${r.blockedAt || '?'} ${JSON.stringify(r.blocked || [])}`);
  if (!r.reachedPage2) {
    return;
  }
  const { inv, extra } = r;
  // The addresses are the one answer a script cannot fake here: the widget's own
  // `input` listener wipes the submitted value, so a non-empty value plus
  // "Address selected." on both fields is proof a real suggestion was chosen.
  score.ok(`${prefix}both addresses came from a real Places selection`,
    r.page1.placesWidgets === 2
    && r.page1.origin_address !== '' && r.page1.destination_address !== ''
    && r.page1.addressStatuses.length === 2
    && r.page1.addressStatuses.every((text) => text === 'Address selected.'),
    JSON.stringify([r.page1.placesWidgets, r.page1.addressStatuses,
      r.page1.origin_address, r.page1.destination_address]));
  score.ok(`${prefix}the date was typed and accepted as ${DATE}`,
    r.typed.ok === true && r.typed.value === DATE, JSON.stringify(r.typed));
  score.ok(`${prefix}exactly one visible date input, preferred_service_date`,
    extra.visibleDateInputNames.length === 1
    && extra.visibleDateInputNames[0] === 'preferred_service_date',
    JSON.stringify(extra.visibleDateInputNames));
  score.ok(`${prefix}exactly one "Choose your pickup time" heading`,
    extra.choosePickupTimeHeadings === 1, String(extra.choosePickupTimeHeadings));
  score.ok(`${prefix}the timezone note is shown`, inv.textTimezoneNote === 1,
    String(inv.textTimezoneNote));
  score.ok(`${prefix}exactly one Slot widget`,
    extra.slotWidgets === 1 && extra.slotHiddenInputs === 1,
    `containers=${extra.slotWidgets} inputs=${extra.slotHiddenInputs}`);
  score.ok(`${prefix}${EXPECTED_SLOTS} bookable Slots for ${DATE}`,
    inv.slotButtonsVisible === EXPECTED_SLOTS && inv.slotButtonsUnbookable === 0,
    `${inv.slotButtonsVisible} visible / ${inv.slotButtonsUnbookable} unbookable`);
  score.ok(`${prefix}every Slot is on ${DATE}`,
    inv.slotAts.length === EXPECTED_SLOTS
    && inv.slotAts.every((at) => new Date(at * 1000).toISOString().slice(0, 10) === DATE
      || dateInNewYork(at) === DATE),
    JSON.stringify(inv.slotAts.slice(0, 3)));
  score.ok(`${prefix}no Manual Review wording`,
    inv.textManualReviewRequired === 0 && inv.textStaffWillPrice === 0
    && inv.textConfirmedAfterStaffReview === 0,
    JSON.stringify([inv.textManualReviewRequired, inv.textStaffWillPrice,
      inv.textConfirmedAfterStaffReview]));
  score.ok(`${prefix}the sidebar agrees: an estimate, not a review`,
    inv.textInstantEstimate >= 1 && r.sidebar.estimateNeedsReview === 0
    && r.sidebar.pendingManualReview === 0 && r.sidebar.cannotBePricedNote === 0
    && r.sidebar.needsAPersonNote === 0,
    `textInstantEstimate=${inv.textInstantEstimate}`
    + ` places=${JSON.stringify(extra.instantEstimatePlaces)}`
    + ` sidebar=${JSON.stringify(r.sidebar)}`);
  score.ok(`${prefix}no second Pickup date, and no Preferred Service Time on screen`,
    inv.textPickupDate === 0 && inv.textPreferredServiceTime === 0
    && extra.preferredServiceTimeVisible === 0 && extra.dateFlexibilityVisible === 0
    && extra.pickupDateFieldsAnywhere === 0,
    JSON.stringify({
      textPickupDate: inv.textPickupDate,
      textPreferredServiceTime: inv.textPreferredServiceTime,
      preferredServiceTimeVisible: extra.preferredServiceTimeVisible,
      dateFlexibilityVisible: extra.dateFlexibilityVisible,
      pickupDateFieldsAnywhere: extra.pickupDateFieldsAnywhere,
    }));
  // The other services' date questions used to live in this shared form's markup,
  // gated by Webform's own #states on service_type != courier, and what this
  // asserted was that they were inert: unanswered, un-required, hidden by a
  // #states rule rather than by a stylesheet this module wrote, and absent from
  // the request the page sends.
  //
  // update_10039 went further than inert. '#access_create_roles: []' makes Webform
  // resolve both to '#access: FALSE' while the create form is built, so neither
  // renders a name= attribute at all — which is why this check reported
  // "controls: 0" once that update was installed: the present-but-inert
  // precondition it required had been deliberately removed. Absent is strictly
  // stronger than inert, because there is no control left to answer, mask,
  // require or submit. So it now reads the way its single_driver_handling sibling
  // below already does — gone, painted or not — and a masked-but-present control
  // fails it, since masked is the state 10039 replaced. The offer-body half is
  // unchanged and still independent evidence: whatever the document holds, the
  // request the page actually sent carries neither key.
  const stillRendered = [...(extra.preferredServiceTimeDetail || []),
    ...(extra.dateFlexibilityDetail || [])];
  const offerKeys = Object.keys((((r.offer || {}).request) || {}).values || {});
  score.ok(`${prefix}the non-courier date questions are gone, not merely masked`,
    extra.preferredServiceTimeAnywhere === 0 && extra.dateFlexibilityAnywhere === 0
    && extra.shellDateAnywhere >= 1
    && !offerKeys.includes('preferred_service_time')
    && !offerKeys.includes('date_flexibility'),
    JSON.stringify({
      preferred_service_time: extra.preferredServiceTimeAnywhere,
      date_flexibility: extra.dateFlexibilityAnywhere,
      shell_date_present: extra.shellDateAnywhere,
      stillRendered: stillRendered.map((el) => ({
        name: el.labelText || el.legendText, tag: el.tag, value: el.value,
        checked: el.checked,
        visible: el.visible, disabled: el.disabled, rect: el.rect,
        hiddenBy: el.hiddenByTag, hiddenByDisplay: el.hiddenByDisplay,
        hiddenByIsSelfWrapper: el.hiddenByIsSelfWrapper,
      })),
      offerCarriesThem: [
        offerKeys.includes('preferred_service_time'),
        offerKeys.includes('date_flexibility'),
      ],
    }));
  score.ok(`${prefix}no legacy reservation widget`, inv.legacyReservationWidgets === 0,
    String(inv.legacyReservationWidgets));
  score.ok(`${prefix}no driver control anywhere, painted or not`,
    extra.driverControlsAnywhere === 0 && inv.singleDriverControls === 0
    && inv.textOnePersonCarry === 0 && inv.textTwoPeopleOption === 0,
    JSON.stringify([extra.driverControlsAnywhere, inv.textOnePersonCarry]));
  score.ok(`${prefix}the page's own offer omitted the retired fact`,
    !!r.offer && !Object.prototype.hasOwnProperty.call(
      (r.offer.request || {}).values || {}, 'single_driver_handling'),
    r.offer ? 'present in body' : 'no offer captured');
  score.ok(`${prefix}the server called it schedulable`,
    !!r.offer && r.offer.status === 200 && r.offer.response
    && r.offer.response.manual_review === false && r.offer.response.incomplete === false
    && r.offer.response.slots && r.offer.response.slots.count === EXPECTED_SLOTS,
    JSON.stringify(r.offer && r.offer.response));
  score.ok(`${prefix}required_facts still names no driver fact`,
    Array.isArray(inv.dispatchRequiredFacts)
    && !inv.dispatchRequiredFacts.includes('single_driver_handling'),
    JSON.stringify(inv.dispatchRequiredFacts));
  score.ok(`${prefix}no console errors`, r.errors.length === 0, JSON.stringify(r.errors));
  score.ok(`${prefix}no Hold, Reservation, renew, release, checkout or payment request`,
    r.names.every((name) => !/hold|reserv|renew|release|checkout|payment/i.test(name)),
    JSON.stringify(r.names));
  score.ok(`${prefix}nothing was selected, so no capacity was claimed`,
    extra.dispatchSlotValue === '' && inv.slotSelected === 0,
    `value="${extra.dispatchSlotValue}" selected=${inv.slotSelected}`);
}

/**
 * A genuinely ineligible request must not produce bookable times.
 *
 * There are two honest ways for that to be true, and which one applies is a
 * property of the form rather than something to assume: either the wizard refuses
 * to advance at all, or it advances and the planner refuses to schedule. Both are
 * scored here on their own terms, and `blockedBy` names the control that has to be
 * the reason when the first path is taken — so "it was blocked" cannot pass by
 * being blocked on something unrelated.
 */
function scoreRefused(score, prefix, r, { blockedBy = null } = {}) {
  if (!r.reachedPage2) {
    score.ok(`${prefix}the wizard refused to advance, and on the right control`,
      blockedBy !== null
      && (r.blocked || []).some((entry) => String(entry.name) === blockedBy),
      `blocked=${JSON.stringify(r.blocked)} expected=${blockedBy}`);
    score.ok(`${prefix}zero Slots: no Slot widget and no date input exist`,
      r.slots.slotButtons === 0 && r.slots.slotWidgets === 0 && r.slots.dateInputs === 0,
      JSON.stringify(r.slots));
    score.ok(`${prefix}no Hold, Reservation, checkout or payment request`,
      r.names.every((name) => !/hold|reserv|renew|release|checkout|payment/i.test(name)),
      JSON.stringify(r.names));
    score.ok(`${prefix}no console errors`, r.errors.length === 0, JSON.stringify(r.errors));
    return;
  }
  const { inv } = r;
  score.ok(`${prefix}the widget reports manual review`, inv.bookingState === 'manual_review',
    String(inv.bookingState));
  score.ok(`${prefix}zero Slots`, inv.slotButtonsVisible === 0,
    String(inv.slotButtonsVisible));
  score.ok(`${prefix}the refusal is stated in words`,
    inv.textManualReviewRequired >= 1 || inv.textStaffWillPrice >= 1,
    JSON.stringify([inv.textManualReviewRequired, inv.textStaffWillPrice]));
  score.ok(`${prefix}the server refused it too`,
    !!r.offer && r.offer.response && r.offer.response.manual_review === true
    && r.offer.response.slots && r.offer.response.slots.count === 0,
    JSON.stringify(r.offer && r.offer.response));
  score.ok(`${prefix}the refusal is not blamed on two-person handling`,
    !!r.offer && r.offer.response
    && r.offer.response.review_code !== 'two_person_handling',
    JSON.stringify(r.offer && r.offer.response && r.offer.response.review_code));
  score.ok(`${prefix}no incomplete-details wording, this is a real refusal`,
    inv.textCompleteRequiredDetails === 0, String(inv.textCompleteRequiredDetails));
  score.ok(`${prefix}no Hold, Reservation, checkout or payment request`,
    r.names.every((name) => !/hold|reserv|renew|release|checkout|payment/i.test(name)),
    JSON.stringify(r.names));
  score.ok(`${prefix}no console errors`, r.errors.length === 0, JSON.stringify(r.errors));
}

/** New York calendar day for a unix second, without pulling in a date library. */
function dateInNewYork(at) {
  return new Intl.DateTimeFormat('en-CA', {
    timeZone: 'America/New_York', year: 'numeric', month: '2-digit', day: '2-digit',
  }).format(new Date(at * 1000));
}

// ---------------------------------------------------------------------------

/**
 * Runs one scenario and prints it, turning a crash into a scored failure.
 *
 * A thrown error used to end the whole run, so the widths and scenarios after it
 * were never measured and the report said nothing about them. A scenario that
 * cannot complete is a failed assertion, not a reason to stop looking.
 */
async function attempt(heading, options, onRecord) {
  console.log(`\n--- ${heading} ---`);
  try {
    const result = await runScenario(options);
    result.lines.forEach((line) => console.log(line));
    onRecord(result.record);
  }
  catch (error) {
    const detail = String(error && error.message ? error.message : error);
    console.log(`  the scenario could not be completed: ${detail}`);
    onRecord(null, detail);
  }
}

const score = new Score();
console.log(`FINAL INDEPENDENT ACCEPTANCE — real user actions only, date ${DATE}`);
console.log(`form: ${FORM_URL}`);
if (ONLY !== '') {
  console.log(`scenario filter: ACCEPTANCE_ONLY=${ONLY} (a partial run — the tally below counts only this scenario)`);
}

for (const width of WIDTHS) {
  if (!['A', 'B', 'C'].some((check) => wanted(width.label, check))) {
    continue;
  }
  console.log(`\n=== ${width.label} ${width.width}x${width.height} ===`);
  const prefix = `${width.label}: `;

  if (wanted(width.label, 'A')) {
    await attempt(`A: valid Express Courier Send, 10 lb, attested, ${DATE}`, {
      label: 'eligible', file: `${width.label}-A-eligible`,
      width: width.width, height: width.height, attest: true,
    }, (record, crash) => {
      if (!record) {
        score.ok(`${prefix}A the scenario ran to completion`, false, crash);
        return;
      }
      scoreEligible(score, prefix + 'A ', record);
    });
  }

  if (wanted(width.label, 'B')) {
    await attempt('B: negative — the contents attestation left unticked', {
      label: 'no-attestation', file: `${width.label}-B-no-attestation`,
      width: width.width, height: width.height, attest: false,
    }, (record, crash) => {
      if (!record) {
        score.ok(`${prefix}B the scenario ran to completion`, false, crash);
        return;
      }
      scoreRefused(score, prefix + 'B ', record, { blockedBy: 'courier_prohibited_ack' });
    });
  }

  if (wanted(width.label, 'C')) {
    await attempt('C: negative — 45 lb, over the Courier limit', {
      label: 'overweight', file: `${width.label}-C-overweight`,
      width: width.width, height: width.height, attest: true, weight: '45',
    }, (record, crash) => {
      if (!record) {
        score.ok(`${prefix}C the scenario ran to completion`, false, crash);
        return;
      }
      scoreRefused(score, prefix + 'C ', record, {});
    });
  }
}

console.log('\n--- assertions ---');
score.print('');
if (score.rows.length === 0) {
  console.log(`no scenario matched ACCEPTANCE_ONLY=${ONLY}`);
  console.log('\nACCEPTANCE RED');
  process.exit(1);
}
console.log(`\n${score.passed}/${score.rows.length} assertions passed`);
if (score.failed.length) {
  console.log('FAILED:');
  score.failed.forEach((row) => console.log(`  - ${row.label} — ${row.detail}`));
  console.log('\nACCEPTANCE RED');
  process.exit(1);
}
console.log('\nACCEPTANCE GREEN');
