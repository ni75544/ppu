// On-Demand Delivery pilot gate — the shared scheduling shell, scored on the
// service a person schedules rather than the one a customer books.
//
// The BookingProfile seam claims two things that only a browser can settle. That
// On-Demand Delivery renders the *same* public shell as Express Courier — one
// Preferred Service Date, one "Choose your pickup time" widget, one timezone
// note — and that the shell is nevertheless not bookable: no Slot buttons, no
// Hold, no token, no countdown, no Reservation, no checkout, no payment, and a
// sentence that says a person will confirm the times rather than pretending an
// estimate is instant. A server probe can prove the profile resolves; it cannot
// prove the visitor is not offered a button.
//
// Four scenarios, at both required widths:
//
//   3  pilot        — a fresh anonymous On-Demand request. The shell, the
//                     wording, the absent controls, and the fact that the widget
//                     sends no offer request at all for a manual_review profile.
//   4a courier→pilot — an eligible Courier request with a pickup time already
//                     selected, then switched to On-Demand through the wizard.
//                     The selected `dispatch_slot` must be gone and no Slot may
//                     remain on the screen.
//   4b pilot→courier — the reverse. The review sentence must clear and Courier
//                     availability must come back, but only once Courier's own
//                     required answers are complete.
//   4c crafted       — the offer endpoint asked directly, in the live session,
//                     with an On-Demand body carrying a stale Courier arrival.
//                     The server must offer nothing regardless.
//
// A service switch has to go through the wizard — Back, change the radio, Next —
// because `service_type` is a page-1 element and page 2 does not carry it. The
// widget reads the service from the quote behavior's stored context, so poking
// the radio from page 2 would prove nothing about what a customer can reach.
//
// Read-only with respect to the business: no final Submit, no Hold, no
// Reservation, no checkout, no payment. Selecting a Courier time in 4a takes
// nothing — capacity is claimed at checkout — and it is the only way to show
// that the switch discards it. The crafted-arrival *submission* half is a
// separate, deliberately write-capable probe (probe-ondemand-stale-slot.mjs) and
// is not run from here.
//
// Usage:
//   ddev exec bash -c 'COURIER_FORM_URL=https://ppu-qa.ddev.site/form/hg \
//     node qa/browser/ondemand-pilot-gate.mjs [YYYY-MM-DD]'
import { sleep } from './webdriver.mjs';
import {
  FORM_URL, SAMPLE, PILOT_SERVICE, answerCourierPage1, answerPilotPage1,
  inventory, sidebarWording, requestNames, messages,
  armBookingState, armNetworkCapture, capturedCalls, onPage2, clickSlot, postOffer,
} from './courier-flow.mjs';
import {
  clickControl, typeDate, pressNext,
  freshSession, severeConsole, waitFor, CSS_escape, formQuiet,
} from './user-actions.mjs';

const PILOT = PILOT_SERVICE;
const DATE = /^\d{4}-\d{2}-\d{2}$/.test(process.argv[2] || '') ? process.argv[2] : '2026-09-01';
// Slot count for the site under test, because the two sites run different
// Resource profiles: ppu-qa works Mon–Wed 08:00–18:00 and offers 19, the active
// ppu site works Mondays 08:00–17:00 and offers 17. Naming the number keeps the
// Courier control case a real count rather than "more than zero", without
// touching either schedule.
const EXPECTED_SLOTS = Number.parseInt(process.env.EXPECTED_SLOTS || '', 10) > 0
  ? Number.parseInt(process.env.EXPECTED_SLOTS, 10)
  : 19;
const ARTIFACTS = process.env.COURIER_ARTIFACTS || 'qa/artifacts';
const STAMP = new Date().toISOString().replace(/[:.]/g, '-').slice(0, 19);
const WIDTHS = [
  { label: 'desktop', width: 1440, height: 900 },
  { label: 'mobile', width: 390, height: 844 },
];
// The pilot's shipped sentence, read from the profile configuration rather than
// invented here: booking_profiles.on_demand_delivery.review_message.
const REVIEW_SENTENCE =
  'This delivery needs a quick review before pickup times can be confirmed.';
// The form's own help text for the date question, which the widget restores
// whenever the list is empty and replaces while times are on the screen.
const DATE_HELP_DEFAULT = 'Select a date to see available pickup times.';
// Anything here in a resource name would mean capacity was taken or money
// started, which no scenario in this gate may do.
const SIDE_EFFECTS = /hold|reserve|reservation|checkout|payment|renew|release|token/i;
const ONLY = String(process.env.PILOT_ONLY || '').trim();
// One width, for diagnosing a single failure without paying for the other. A
// verdict still requires both, so this narrows a run; it does not shorten the gate.
const ONE_WIDTH = String(process.env.PILOT_WIDTH || '').trim();

const results = [];

/**
 * Records one scored assertion. `detail` is what was actually measured.
 *
 * The width is stored as its label whether a scorer passes the label or the whole
 * width object, so the per-width tallies cannot silently count zero because one
 * scorer handed over `{label, width, height}`.
 */
function check(scenario, width, name, pass, detail) {
  const label = width && typeof width === 'object' ? width.label : String(width);
  results.push({ scenario, width: label, name, pass: !!pass, detail });

  return !!pass;
}

/**
 * The readings `inventory()` does not take, because the Courier gates never
 * needed them.
 *
 * Three groups. The pilot's own sentence and the wording it must never use, so a
 * manual_review service cannot be described as an instant estimate. The presence
 * of the retired scheduling questions *in the DOM at all* — not merely their
 * visibility — because a question hidden with CSS is still a question the page
 * asks, and hiding one was named as the wrong fix. And every control or word that
 * would mean a booking authority reached this page: a Hold, a token, a countdown,
 * a Reservation, a checkout or a payment action.
 *
 * The day label is computed here with the widget's own formatter and the widget's
 * own published timezone rather than hardcoded, so the date-help assertion is
 * exact without pinning the gate to one locale's month abbreviation.
 */
function pilotReads(browser, ymd) {
  return browser.js(`
    const [ymd, reviewSentence] = arguments;
    const all = (css) => Array.from(document.querySelectorAll(css));
    const named = (name) => all('[name="' + name + '"]')
      .concat(all('[name^="' + name + '["]'));
    const text = String(document.body.innerText || '');
    const countText = (needle) => needle === '' ? 0 : text.split(needle).length - 1;
    const dispatch = ((window.drupalSettings || {}).custom_webform_handlers || {}).dispatch || {};
    const timezone = (dispatch.constraints || {}).timezone || '';
    const profiles = dispatch.booking_profiles || {};
    const dayLabel = (() => {
      const parts = String(ymd).split('-').map(Number);
      if (parts.length !== 3 || parts.some((v) => !Number.isFinite(v))) { return ymd; }
      const at = new Date(Date.UTC(parts[0], parts[1] - 1, parts[2], 12));
      const options = { weekday: 'long', month: 'short', day: 'numeric' };
      if (timezone) { options.timeZone = timezone; }
      try { return new Intl.DateTimeFormat(undefined, options).format(at); }
      catch (error) { return ymd; }
    })();
    return {
      reviewSentence: countText(reviewSentence),
      instantEstimateWords: countText('Instant estimate') + countText('instant estimate'),
      bookingWords: ['Hold your time', 'Hold expires', 'expires in', 'Reserve this',
        'Reservation', 'Continue to checkout', 'Proceed to payment', 'Pay now',
        'Renew hold', 'Release hold']
        .reduce((n, needle) => n + countText(needle), 0),
      bookingControls: all('[data-dispatch-hold], [data-hold-token], [data-reservation],'
        + ' [data-checkout], [data-payment], .custom-webform-dispatch__countdown,'
        + ' .custom-webform-dispatch__hold, [name="dispatch_hold_token"]').length,
      preferredServiceTimeInDom: named('preferred_service_time').length,
      dateFlexibilityInDom: named('date_flexibility').length,
      preferredServiceDateInDom: named('preferred_service_date').length,
      dispatchSlotInDom: named('dispatch_slot').length,
      slotWidgets: all('.custom-webform-dispatch').length,
      expectedDayHelp: 'Available pickup times for ' + dayLabel + '.',
      publishedTimezone: timezone,
      publishedProfileModes: Object.keys(profiles)
        .sort().map((k) => k + '=' + String((profiles[k] || {}).mode || '')),
    };
  `, [ymd, REVIEW_SENTENCE]);
}

/**
 * Presses the wizard's own Back control and waits for page 1 to come back.
 *
 * The only route to a service change: `service_type` is a page-1 element, page 2
 * does not carry it, and the widget reads the service from the context the quote
 * behavior stored on page 1. Poking a radio that is not on the page — or writing
 * the stored context directly — would prove something about the harness rather
 * than about the form.
 *
 * The click is delivered by WebDriver, not by page script. `btn.click()` would
 * submit the wizard even if the control were covered, disabled by the theme, or
 * off-screen, so a harness that used it could not tell a reachable Back button
 * from an unreachable one.
 */
async function pressBack(browser) {
  const label = await browser.js(`
    const btn = document.querySelector('[data-drupal-selector="edit-wizard-prev"]');
    return btn ? String(btn.value || btn.textContent || '').trim() : null;
  `);
  let clicked = await clickControl(browser, '[data-drupal-selector="edit-wizard-prev"]');
  if (!clicked.ok) {
    // Some wizard skins label the control without that selector; the visible
    // submit whose text says Back is the same control to a customer.
    const alternates = await browser.js(`
      const hits = Array.from(document.querySelectorAll(
        'input[type="submit"], button[type="submit"]'))
        .filter((el) => /back|previous/i.test(el.value || el.textContent || ''))
        .map((el) => el.id).filter(Boolean);
      return hits;
    `);
    if (alternates.length) {
      clicked = await clickControl(browser, `#${CSS_escape(alternates[0])}`);
    }
  }
  if (!clicked.ok) {
    return { ...clicked, label };
  }
  const back = await waitFor(browser, 'page 1 to render again', async () =>
    (await browser.js(`
      return document.querySelector('[name="service_type"]') !== null
        && document.querySelector('[name="dispatch_slot"]') === null;
    `)) === true, 20000);
  // Page 1 being *present* is not page 1 being *finished*: Webform builds the
  // wizard step and then re-renders its conditional regions, and a control found
  // between the two is detached before it can be used. A person would wait for the
  // page to stop moving before answering it, so the walk does too.
  const quiet = back.ok ? await formQuiet(browser) : { ok: false, reason: 'never arrived' };

  return { ...clicked, label, onPage1: back.ok, wait: back, quiet };
}

/**
 * Changes the service through the wizard and returns to the widget page.
 *
 * Both observers are re-armed afterwards: the wizard's Next and Back are real
 * POSTs, so anything attached to the old document went with it. A capture that
 * survived would be a capture of the previous page.
 */
async function switchService(browser, service, answer) {
  const step = { service };
  step.back = await pressBack(browser);
  if (!step.back.onPage1) {
    return step;
  }
  step.answered = await answer(browser);
  step.next = await pressNext(browser);
  step.onPage2 = await onPage2(browser);
  if (step.onPage2) {
    await armBookingState(browser);
    await armNetworkCapture(browser);
  }

  return step;
}

/** Waits until the widget has published a settled verdict, or records that it did not. */
const settled = (browser) => waitFor(browser, 'the widget to publish a settled state',
  async () => ['available', 'unavailable', 'manual_review']
    .includes(await browser.js(`return window.__cwhBookingState || null;`)), 25000);

/**
 * Waits for a verdict only as far as one is owed.
 *
 * Before a date is chosen the widget has nothing to answer about, and the observer
 * is armed after page 2 has already rendered, so an initialisation-time state is
 * unobservable. Waiting the full settle timeout there would spend 25 seconds
 * proving the shell behaves exactly as Courier's does. This gives the event a
 * short chance and then reports what it found, so "no verdict yet" is recorded as
 * a reading rather than as a timeout.
 */
const settledOrQuiet = (browser) => waitFor(browser, 'a verdict, if one is owed yet',
  async () => ['available', 'unavailable', 'manual_review']
    .includes(await browser.js(`return window.__cwhBookingState || null;`)), 3000);

/** Waits until at least one bookable pickup time is on the screen. */
const slotsRendered = (browser) => waitFor(browser, 'pickup times to render', async () =>
  (await browser.js(
    `return document.querySelectorAll('.custom-webform-dispatch__slot').length > 0;`)) === true,
25000);

/**
 * Re-picks the same service date after a service switch, and reports what happened.
 *
 * Not a workaround for a slow page: the widget publishes its verdict only when the
 * verdict *changes*, and a switch re-renders page two with the date already
 * answered, so the first verdict of the new service is decided while the harness is
 * still re-attaching its listener to a document that did not exist a moment ago.
 * From then on the widget is right and silent, and a check that reads the event
 * would score the instrument rather than the product.
 *
 * A customer re-opening the picker and choosing the same day is the ordinary action
 * that asks the question again, and it is the one modelled here: the keystrokes fire
 * the form's own input listener, which schedules a re-offer, which publishes
 * `pending` and then the new service's real verdict. Nothing about the request
 * changes — the same date goes back into the same field — so the answer that
 * arrives is the answer the customer already had, now observable.
 */
async function reassertDate(browser) {
  const before = await browser.js(`
    const el = document.querySelector('[name="preferred_service_date"]');
    return el ? String(el.value || '') : null;
  `);
  const typed = await typeDate(browser, 'input[name="preferred_service_date"]', DATE);

  return { before, carried: before === DATE, typed };
}

/** Every reading a scenario is scored from, taken together so they agree. */
async function readAll(browser, label) {
  const shot = await browser.screenshot(`${ARTIFACTS}/${STAMP}-pilot-${label}.png`)
    .catch((error) => `screenshot-failed:${String(error.message || error).slice(0, 60)}`);

  return {
    inventory: await inventory(browser),
    pilot: await pilotReads(browser, DATE),
    sidebar: await sidebarWording(browser),
    requests: await requestNames(browser),
    calls: await capturedCalls(browser),
    messages: await messages(browser),
    console: await severeConsole(browser),
    screenshot: shot,
  };
}

/**
 * Scenario 3 — a fresh anonymous On-Demand request.
 *
 * Read twice: once as the widget first settles, and again after a date is chosen,
 * because "no Slot buttons" is only interesting if it survives the act that
 * produces them for Courier.
 */
async function walkPilot(width) {
  const { browser, reset } = await freshSession(width.width, width.height, FORM_URL);
  const run = { reset };
  try {
    run.page1 = await answerPilotPage1(browser);
    run.next = await pressNext(browser);
    run.onPage2 = await onPage2(browser);
    if (!run.onPage2) {
      run.stoppedAt = 'page-1';

      return run;
    }
    await armBookingState(browser);
    await armNetworkCapture(browser);
    run.settledBeforeDate = await settledOrQuiet(browser);
    run.beforeDate = await readAll(browser, `${width.label}-3-before-date`);

    run.date = await typeDate(browser, 'input[name="preferred_service_date"]', DATE);
    run.settledAfterDate = await settled(browser);
    await sleep(1500);
    run.afterDate = await readAll(browser, `${width.label}-3-after-date`);

    return run;
  }
  catch (error) {
    error.partial = run;
    throw error;
  }
  finally {
    await browser.quit().catch(() => {});
  }
}

/**
 * Scenario 4a — an eligible Courier request with a time selected, then switched.
 *
 * The selection is the point. A gate that switched services without selecting
 * anything would prove only that On-Demand renders no Slots, which scenario 3
 * already says; the claim under test is that a `dispatch_slot` the customer really
 * did choose does not survive into a service that cannot honour it.
 *
 * The crafted-arrival probe rides along here because this is where a *real* stale
 * Courier arrival exists. It is posted from the live On-Demand session to the
 * offer endpoint, which is the same route and body shape the widget uses.
 */
async function walkCourierThenPilot(width) {
  const { browser, reset } = await freshSession(width.width, width.height, FORM_URL);
  const run = { reset };
  try {
    run.page1 = await answerCourierPage1(browser);
    run.next = await pressNext(browser);
    run.onPage2 = await onPage2(browser);
    if (!run.onPage2) {
      run.stoppedAt = 'page-1';

      return run;
    }
    await armBookingState(browser);
    await armNetworkCapture(browser);
    run.date = await typeDate(browser, 'input[name="preferred_service_date"]', DATE);
    run.slotsRendered = await slotsRendered(browser);
    await settled(browser);
    run.selected = await clickSlot(browser);
    await sleep(1200);
    run.courier = await readAll(browser, `${width.label}-4a-courier-selected`);

    run.switch = await switchService(browser, PILOT, answerPilotPage1);
    if (!run.switch.onPage2) {
      run.stoppedAt = 'switch';

      return run;
    }
    run.dateReasserted = await reassertDate(browser);
    run.settledAfterSwitch = await settled(browser);
    await sleep(1500);
    run.pilot = await readAll(browser, `${width.label}-4a-pilot-after-switch`);

    // Scenario 4c, in the session that owns a genuine Courier arrival: the server
    // is asked for On-Demand times with that arrival attached. It must offer none.
    const stale = String((run.selected && run.selected.value) || '');
    run.crafted = stale === ''
      ? { skipped: 'no Courier arrival was selected, so there is nothing stale to craft' }
      : await postOffer(browser, DATE, { set: { dispatch_slot: stale } });
    run.craftedArrival = stale;

    return run;
  }
  catch (error) {
    error.partial = run;
    throw error;
  }
  finally {
    await browser.quit().catch(() => {});
  }
}

/**
 * Scenario 4b — On-Demand first, then switched to an eligible Courier request.
 *
 * The reverse direction has its own failure mode: review wording that outlives the
 * service it described. So the pilot is taken all the way to its settled review
 * state before the switch, and afterwards both the sentence and the Slots are
 * scored — Courier must offer times again, and no part of the page may still say a
 * person is going to confirm them.
 *
 * Courier's own required answers are supplied during the switch, which is the
 * honest sequence: availability is reoffered only once the request is complete.
 */
async function walkPilotThenCourier(width) {
  const { browser, reset } = await freshSession(width.width, width.height, FORM_URL);
  const run = { reset };
  try {
    run.page1 = await answerPilotPage1(browser);
    run.next = await pressNext(browser);
    run.onPage2 = await onPage2(browser);
    if (!run.onPage2) {
      run.stoppedAt = 'page-1';

      return run;
    }
    await armBookingState(browser);
    await armNetworkCapture(browser);
    run.date = await typeDate(browser, 'input[name="preferred_service_date"]', DATE);
    run.settledAsPilot = await settled(browser);
    await sleep(1500);
    run.pilot = await readAll(browser, `${width.label}-4b-pilot-first`);

    run.switch = await switchService(browser, SAMPLE.service_type, answerCourierPage1);
    if (!run.switch.onPage2) {
      run.stoppedAt = 'switch';

      return run;
    }
    // The date survives the wizard as a stored answer, so it is re-read rather than
    // assumed; if it did not come back it is typed again, and which happened is
    // recorded.
    run.dateAfterSwitch = await browser.js(`
      const el = document.querySelector('[name="preferred_service_date"]');
      return el ? String(el.value || '') : null;
    `);
    run.dateReasserted = await reassertDate(browser);
    run.slotsRendered = await slotsRendered(browser);
    run.settledAsCourier = await settled(browser);
    await sleep(1500);
    run.courier = await readAll(browser, `${width.label}-4b-courier-after-switch`);
    return run;
  }
  catch (error) {
    error.partial = run;
    throw error;
  }
  finally {
    await browser.quit().catch(() => {});
  }
}

// ---------------------------------------------------------------------------
// Scoring
// ---------------------------------------------------------------------------

/**
 * Resource names that would mean capacity was taken or money started.
 *
 * Drupal core's CSRF endpoint is named as an exception rather than dropped from
 * the pattern. Every page in this theme fetches `/session/token`, and it starts no
 * booking — but the pattern has to keep matching the bare word, because a Hold
 * token is the one artefact of a booking authority that need not carry the word
 * "hold" in its path. Excluding the one known-good route keeps both true.
 */
const CORE_CSRF = '/session/token';
const sideEffects = (read) => (read.requests || [])
  .filter((n) => n !== CORE_CSRF && SIDE_EFFECTS.test(n));

/** The offer requests this document made, from resource timing rather than the wrapper. */
const offerRequests = (read) => (read.requests || []).filter((n) => /dispatch\/offer/.test(n));

/**
 * The public shell every profile shares, scored on a manual_review reading.
 *
 * One of each thing, no Slot buttons, no arrival value, the pilot's own sentence,
 * and none of the wording or controls that would make it look bookable. The
 * retired scheduling questions are scored on what is *painted*: their elements
 * still exist in the document because `#states` hides them in the browser, so the
 * count is reported and the assertion is that nothing renders and no visitor can
 * read their words.
 *
 * `verdict` says whether the widget owes a published state yet. Before any date is
 * chosen it does not: the observer is installed after page 2 has rendered, so a
 * state the widget published during its own initialisation is unobservable by
 * construction, and Courier's shell behaves identically — it publishes nothing
 * until a date gives it something to answer about. What must hold at every moment
 * is the weaker, real invariant: this profile never publishes a bookable verdict.
 * The review sentence being on screen is scored separately and does not depend on
 * the event.
 */
function scorePilotShell(scenario, width, prefix, read, { verdict = true } = {}) {
  const inv = read.inventory;
  const pilot = read.pilot;
  check(scenario, width, `${prefix}exactly one visible date input, and it is preferred_service_date`,
    inv.visibleEnabledDateInputs === 1 && inv.preferredServiceDateVisible === 1
    && JSON.stringify(inv.visibleDateInputNames) === JSON.stringify(['preferred_service_date']),
    { visible: inv.visibleEnabledDateInputs, names: inv.visibleDateInputNames,
      preferred: inv.preferredServiceDateVisible });
  check(scenario, width, `${prefix}exactly one "Preferred Service Date" label`,
    inv.textPreferredServiceDate === 1, { count: inv.textPreferredServiceDate });
  check(scenario, width, `${prefix}exactly one "Choose your pickup time" widget heading`,
    inv.textChoosePickupTime === 1 && pilot.slotWidgets === 1,
    { heading: inv.textChoosePickupTime, widgets: pilot.slotWidgets });
  check(scenario, width, `${prefix}exactly one "Times shown in New York local time" note`,
    inv.textTimezoneNote === 1,
    { count: inv.textTimezoneNote, publishedTimezone: pilot.publishedTimezone });
  check(scenario, width, `${prefix}no second date question from the widget itself`,
    inv.dispatchWidgetDateInputs === 0 && inv.textPickupDate === 0,
    { widgetDateInputs: inv.dispatchWidgetDateInputs, pickupDate: inv.textPickupDate });
  check(scenario, width, `${prefix}zero Slot buttons`,
    inv.slotButtonsVisible === 0,
    { visible: inv.slotButtonsVisible, unbookable: inv.slotButtonsUnbookable });
  check(scenario, width, `${prefix}no arrival is carried in dispatch_slot`,
    inv.dispatchSlotValue === '', { dispatch_slot: inv.dispatchSlotValue });
  check(scenario, width, `${prefix}the Manual Review sentence is published`,
    pilot.reviewSentence >= 1, { count: pilot.reviewSentence, status: inv.status });
  check(scenario, width, `${prefix}nothing calls it an instant estimate`,
    pilot.instantEstimateWords === 0 && inv.textInstantEstimate === 0,
    { words: pilot.instantEstimateWords, sidebarTotal: read.sidebar.totalText });
  check(scenario, width, `${prefix}neither legacy scheduling question is painted`,
    inv.preferredServiceTimeVisible === 0 && inv.dateFlexibilityVisible === 0
    && inv.textPreferredServiceTime === 0,
    { timeVisible: inv.preferredServiceTimeVisible, flexVisible: inv.dateFlexibilityVisible,
      timeWords: inv.textPreferredServiceTime,
      inDomHiddenByStates: [pilot.preferredServiceTimeInDom, pilot.dateFlexibilityInDom] });
  check(scenario, width, `${prefix}no legacy fixed-slot widget and no driver control`,
    inv.legacyReservationWidgets === 0 && inv.singleDriverControls === 0
    && inv.textOnePersonCarry === 0,
    { legacy: inv.legacyReservationWidgets, driver: inv.singleDriverControls,
      driverWords: inv.textOnePersonCarry });
  check(scenario, width, `${prefix}no Hold, token, countdown, Reservation, checkout or payment control`,
    pilot.bookingControls === 0 && pilot.bookingWords === 0,
    { controls: pilot.bookingControls, words: pilot.bookingWords });
  check(scenario, width, `${prefix}the widget publishes ${verdict ? 'manual_review' : 'no bookable verdict'}`,
    verdict
      ? inv.bookingState === 'manual_review'
      : (inv.bookingState === null || inv.bookingState === 'manual_review'),
    { state: inv.bookingState, changes: inv.bookingStateChanges });
  check(scenario, width, `${prefix}the "select a date" instruction stands beside no times`,
    inv.dateHelpText === DATE_HELP_DEFAULT && inv.slotButtonsVisible === 0,
    { help: inv.dateHelpText, slots: inv.slotButtonsVisible });
  check(scenario, width, `${prefix}no severe console entry`,
    read.console.length === 0, { console: read.console });
  check(scenario, width, `${prefix}no Hold, Reservation, checkout or payment request`,
    sideEffects(read).length === 0, { names: sideEffects(read) });
}

/** Scenario 3 — the pilot on its own, before and after a date is chosen. */
function scorePilot(width, run) {
  const s = '3-pilot';
  if (!run.onPage2) {
    check(s, width, '3 the On-Demand request reaches the scheduling page',
      false, { stoppedAt: run.stoppedAt, page1: run.page1 && run.page1.page1,
        next: run.next });

    return;
  }
  check(s, width, '3 the On-Demand request reaches the scheduling page', true,
    { blockedOnPage1: run.page1.page1.blockedControls });
  check(s, width, '3 page 1 asked only the service and the two addresses',
    run.page1.page1.preferredServiceTimeVisible === 0
    && run.page1.page1.dateFlexibilityVisible === 0
    && run.page1.page1.singleDriverVisible === 0
    && run.page1.page1.deliveryModeVisible === 0
    && run.page1.page1.weightVisible === 0
    && run.page1.page1.attestationVisible === 0,
    run.page1.page1);
  check(s, width, '3 the session started empty',
    run.reset.cookiesAfterClear === 0 && run.reset.sessionStorageAfterClear === 0
    && run.reset.localStorageAfterClear === 0, run.reset);

  scorePilotShell(s, width, '3 (before a date) ', run.beforeDate, { verdict: false });
  scorePilotShell(s, width, '3 (after a date) ', run.afterDate);

  check(s, width, '3 the chosen date is the one that was typed',
    run.date.ok === true && run.date.value === DATE, run.date);
  check(s, width, '3 the widget asked the server for no offer at all',
    offerRequests(run.afterDate).length === 0
    && (run.afterDate.calls || []).filter((c) => /dispatch\/offer/.test(c.path)).length === 0,
    { resourceTiming: offerRequests(run.afterDate),
      wrapped: (run.afterDate.calls || []).map((c) => `${c.path}=${c.status}`) });
  check(s, width, '3 the profile map published to the browser says manual_review',
    (run.afterDate.pilot.publishedProfileModes || []).includes(`${PILOT}=manual_review`)
    && (run.afterDate.pilot.publishedProfileModes || []).includes('courier=self_bookable'),
    { modes: run.afterDate.pilot.publishedProfileModes });
}

/**
 * The same shell read on a self-bookable service — the control case.
 *
 * Scored here rather than deferred to the acceptance gate because the claim is a
 * comparison: the pilot must render the *same* shell, so the shell has to be
 * measured on both services with the same instrument. The date-help polish is
 * asserted against the label the widget's own formatter produces, so the
 * assertion is exact without hardcoding one locale's month abbreviation.
 */
function scoreCourierControl(scenario, width, prefix, read) {
  const inv = read.inventory;
  const pilot = read.pilot;
  check(scenario, width, `${prefix}exactly one visible date input, and it is preferred_service_date`,
    inv.visibleEnabledDateInputs === 1 && inv.preferredServiceDateVisible === 1,
    { visible: inv.visibleEnabledDateInputs, names: inv.visibleDateInputNames });
  check(scenario, width, `${prefix}one date label, one widget heading, one timezone note`,
    inv.textPreferredServiceDate === 1 && inv.textChoosePickupTime === 1
    && inv.textTimezoneNote === 1,
    { date: inv.textPreferredServiceDate, heading: inv.textChoosePickupTime,
      timezone: inv.textTimezoneNote });
  check(scenario, width, `${prefix}${EXPECTED_SLOTS} bookable pickup times, none unbookable`,
    inv.slotButtonsVisible === EXPECTED_SLOTS && inv.slotButtonsUnbookable === 0,
    { visible: inv.slotButtonsVisible, unbookable: inv.slotButtonsUnbookable,
      expected: EXPECTED_SLOTS, status: inv.status, busy: inv.dispatchBusy,
      dateInField: inv.preferredServiceDateValue });
  check(scenario, width, `${prefix}an instant estimate, not a review sentence`,
    inv.textInstantEstimate >= 1 && pilot.reviewSentence === 0
    && inv.textManualReviewRequired === 0,
    { instant: inv.textInstantEstimate, review: pilot.reviewSentence,
      manualReview: inv.textManualReviewRequired, price: inv.priceText });
  check(scenario, width, `${prefix}the widget publishes available`,
    inv.bookingState === 'available',
    { state: inv.bookingState, changes: inv.bookingStateChanges, status: inv.status,
      dateInField: inv.preferredServiceDateValue });
  check(scenario, width, `${prefix}the date help names the day and the instruction is gone`,
    inv.dateHelpText === pilot.expectedDayHelp && inv.textSelectDateToSeeTimes === 0,
    { help: inv.dateHelpText, expected: pilot.expectedDayHelp,
      instruction: inv.textSelectDateToSeeTimes });
  check(scenario, width, `${prefix}no legacy scheduling question, driver control or fixed-slot widget`,
    inv.preferredServiceTimeVisible === 0 && inv.dateFlexibilityVisible === 0
    && inv.singleDriverControls === 0 && inv.legacyReservationWidgets === 0,
    { time: inv.preferredServiceTimeVisible, flex: inv.dateFlexibilityVisible,
      driver: inv.singleDriverControls, legacy: inv.legacyReservationWidgets });
  check(scenario, width, `${prefix}no Hold, token, countdown or checkout control`,
    pilot.bookingControls === 0 && pilot.bookingWords === 0,
    { controls: pilot.bookingControls, words: pilot.bookingWords });
  check(scenario, width, `${prefix}no severe console entry`,
    read.console.length === 0, { console: read.console });
  check(scenario, width, `${prefix}no Hold, Reservation, checkout or payment request`,
    sideEffects(read).length === 0, { names: sideEffects(read) });
}

/**
 * Scenario 4a — the selected Courier time must not survive the switch.
 *
 * Both halves are scored, because "cleared" is only meaningful against a state
 * that really held a selection: the Courier reading has to show one selected Slot
 * and a stored arrival before the On-Demand reading is allowed to prove there is
 * none.
 */
function scoreCourierToPilot(width, run) {
  const s = '4a-courier-to-pilot';
  if (!run.onPage2 || !run.slotsRendered.ok) {
    check(s, width, '4a the Courier request reaches its pickup times',
      false, { stoppedAt: run.stoppedAt, next: run.next, wait: run.slotsRendered });

    return;
  }
  scoreCourierControl(s, width, '4a (Courier, before the switch) ', run.courier);
  check(s, width, '4a a pickup time really was selected',
    run.selected.ok === true && String(run.selected.value || '') !== ''
    && run.courier.inventory.slotSelected === 1
    && run.courier.inventory.dispatchSlotValue === String(run.selected.value),
    { clicked: run.selected, selectedButtons: run.courier.inventory.slotSelected,
      stored: run.courier.inventory.dispatchSlotValue });

  if (!run.switch.onPage2) {
    check(s, width, '4a the switch to On-Demand reaches the scheduling page',
      false, { stoppedAt: run.stoppedAt, switch: run.switch });

    return;
  }
  check(s, width, '4a the switch to On-Demand reaches the scheduling page', true,
    { back: run.switch.back.label, next: run.switch.next });
  scorePilotShell(s, width, '4a (On-Demand, after the switch) ', run.pilot);
  check(s, width, '4a the selected arrival is gone, not merely unpainted',
    run.pilot.inventory.dispatchSlotValue === ''
    && run.pilot.inventory.slotSelected === 0
    && run.pilot.inventory.slotButtonsVisible === 0,
    { was: String(run.selected.value || ''), now: run.pilot.inventory.dispatchSlotValue,
      selected: run.pilot.inventory.slotSelected,
      slots: run.pilot.inventory.slotButtonsVisible });
  check(s, width, '4a no bookable Courier price or availability state remains',
    run.pilot.inventory.textInstantEstimate === 0
    && run.pilot.inventory.bookingState === 'manual_review'
    && run.pilot.pilot.reviewSentence >= 1,
    { instant: run.pilot.inventory.textInstantEstimate,
      state: run.pilot.inventory.bookingState, price: run.pilot.inventory.priceText,
      sidebarTotal: run.pilot.sidebar.totalText });
}

/**
 * Scenario 4b — review state must not outlive the service that produced it.
 *
 * Scored in the same order the customer experiences: the pilot's review state is
 * confirmed present first, so "cleared" afterwards is a change rather than an
 * absence that was always there. Then Courier's own control case runs in full,
 * which is also where the date-help polish is checked in the reverse direction.
 */
function scorePilotToCourier(width, run) {
  const s = '4b-pilot-to-courier';
  if (!run.onPage2) {
    check(s, width, '4b the On-Demand request reaches the scheduling page',
      false, { stoppedAt: run.stoppedAt, next: run.next });

    return;
  }
  check(s, width, '4b the On-Demand request really was in its review state first',
    run.pilot.pilot.reviewSentence >= 1
    && run.pilot.inventory.bookingState === 'manual_review'
    && run.pilot.inventory.slotButtonsVisible === 0,
    { sentence: run.pilot.pilot.reviewSentence, state: run.pilot.inventory.bookingState,
      slots: run.pilot.inventory.slotButtonsVisible });

  if (!run.switch.onPage2) {
    check(s, width, '4b the switch to Courier reaches the scheduling page',
      false, { stoppedAt: run.stoppedAt, switch: run.switch });

    return;
  }
  check(s, width, '4b the switch to Courier reaches the scheduling page', true,
    { back: run.switch.back.label, next: run.switch.next,
      dateSurvived: run.dateAfterSwitch === DATE,
      dateRetyped: run.dateRetyped ? run.dateRetyped.ok : false });
  check(s, width, '4b the stale review wording is gone',
    run.courier.pilot.reviewSentence === 0
    && run.courier.inventory.textManualReviewRequired === 0
    && run.courier.sidebar.pendingManualReview === 0
    && run.courier.sidebar.needsAPersonNote === 0,
    { sentence: run.courier.pilot.reviewSentence,
      manualReview: run.courier.inventory.textManualReviewRequired,
      sidebarPending: run.courier.sidebar.pendingManualReview,
      sidebarNeedsPerson: run.courier.sidebar.needsAPersonNote });
  scoreCourierControl(s, width, '4b (Courier, after the switch) ', run.courier);
}

/**
 * Scenario 4c — the offer endpoint, asked for the pilot with a real stale arrival.
 *
 * The refusal has to be read off the response rather than the screen: the widget
 * short-circuits a manual_review profile before it sends anything, so the only
 * way to ask the server this question is to ask it directly. The body is the
 * widget's own body, built by the same collector rules, with one field replaced by
 * the arrival the customer genuinely selected for Courier moments earlier.
 *
 * `discardArrivalOutsideSelfBooking()` is the other half of this refusal and acts
 * at preSave, so it needs a submission to exist; that is the separate write-capable
 * probe on ppu-qa and is not scored here.
 */
function scoreCrafted(width, run) {
  const s = '4c-crafted-stale-slot';
  const crafted = run.crafted || {};
  if (crafted.skipped) {
    check(s, width, '4c a real Courier arrival was available to craft with',
      false, { reason: crafted.skipped });

    return;
  }
  if (crafted.error || typeof crafted.status !== 'number') {
    check(s, width, '4c the offer endpoint answered',
      false, { crafted });

    return;
  }
  const body = crafted.body || {};
  const slots = body.slots && typeof body.slots === 'object' ? body.slots : { count: null };
  check(s, width, '4c the offer endpoint answered',
    crafted.status === 200, { status: crafted.status });
  check(s, width, '4c the crafted body carried the stale Courier arrival',
    (crafted.sentKeys || []).includes('dispatch_slot') && run.craftedArrival !== '',
    { arrival: run.craftedArrival,
      sentDispatchSlot: (crafted.sentKeys || []).includes('dispatch_slot') });
  check(s, width, '4c the crafted body asked as On-Demand Delivery',
    (crafted.sentKeys || []).includes('service_type'),
    { sentServiceType: (crafted.sentKeys || []).includes('service_type') });
  check(s, width, '4c the server offers no pickup time for a manual_review profile',
    slots.count === 0, { slots });
  check(s, width, '4c the server names the outcome manual review, not an incomplete form',
    body.manual_review === true && body.incomplete === false,
    { manualReview: body.manual_review, incomplete: body.incomplete,
      reviewCode: body.review_code });
  check(s, width, '4c the refusal echoes no arrival back as claimable',
    slots.firstAt === null && !String(JSON.stringify(body)).includes(run.craftedArrival),
    { firstAt: slots.firstAt, arrival: run.craftedArrival });
  check(s, width, '4c the refusal claims no capacity: no Hold, token or Reservation',
    !SIDE_EFFECTS.test(JSON.stringify(body)),
    { body: JSON.stringify(body).slice(0, 300) });
}

// ---------------------------------------------------------------------------
// Runner
// ---------------------------------------------------------------------------

const SCENARIOS = [
  { id: '3', label: 'On-Demand pilot shell', walk: walkPilot, score: scorePilot },
  { id: '4a', label: 'Courier with a time selected → On-Demand',
    walk: walkCourierThenPilot, score: (w, r) => { scoreCourierToPilot(w, r); scoreCrafted(w, r); } },
  { id: '4b', label: 'On-Demand → eligible Courier',
    walk: walkPilotThenCourier, score: scorePilotToCourier },
];

/** What this run is about to look at, in words, before it looks. */
function describe() {
  console.log('On-Demand Delivery pilot gate');
  console.log(`  form            ${FORM_URL}`);
  console.log(`  pilot service   ${PILOT}`);
  console.log(`  control service ${SAMPLE.service_type}`);
  console.log(`  date            ${DATE}`);
  console.log(`  Courier slots   ${EXPECTED_SLOTS} expected on the control case`);
  console.log(`  widths          ${WIDTHS.map((w) => `${w.width}x${w.height}`).join(', ')}`);
  console.log(`  artifacts       ${ARTIFACTS}/${STAMP}-pilot-*.png`);
  console.log('  writes          none: no Submit, no Hold, no Reservation, no checkout');
  if (ONLY) {
    console.log(`  PILOT_ONLY      ${ONLY}`);
  }
  console.log('');
}

async function main() {
  describe();
  const chosen = SCENARIOS.filter((s) => !ONLY || ONLY.split(',').includes(s.id));
  if (!chosen.length) {
    console.log(`PILOT_ONLY=${ONLY} selected no scenario; ids are `
      + SCENARIOS.map((s) => s.id).join(', '));
    process.exitCode = 2;

    return;
  }
  const widths = WIDTHS.filter((w) => !ONE_WIDTH || w.label === ONE_WIDTH);
  if (!widths.length) {
    console.log(`PILOT_WIDTH=${ONE_WIDTH} selected no width; labels are `
      + WIDTHS.map((w) => w.label).join(', '));
    process.exitCode = 2;

    return;
  }

  for (const width of widths) {
    console.log(`--- ${width.label} ${width.width}x${width.height} ---`);
    for (const scenario of chosen) {
      console.log(`  ${scenario.id}: ${scenario.label}`);
      // Where this scenario's results begin, so the print covers everything it
      // recorded. Filtering by the scenario's own id looked equivalent and was not:
      // scenario 4a also scores the crafted-arrival refusal, which files itself under
      // 4c, so seven assertions were being counted in the totals and never shown.
      const from = results.length;
      try {
        const run = await scenario.walk(width);
        scenario.score(width, run);
      }
      catch (error) {
        // A crash is a failing scenario, not a failing run: the other width and the
        // other scenarios still have something to say.
        check(scenario.id, width, `${scenario.id} the walk completed`, false,
          { error: String((error && error.stack) || error).slice(0, 400) });
        // The walk's own record of how far it got, attached by the walk before it
        // rethrew. Without it a crash names a line of harness code and nothing about
        // the customer's position, which is the part worth reading.
        if (error && error.partial) {
          console.log(`    stopped after: ${Object.keys(error.partial).join(', ')}`);
          console.log(`    ${JSON.stringify(error.partial).slice(0, 1400)}`);
        }
      }
      const mine = results.slice(from).filter((r) => r.width === width.label);
      mine.forEach((r) => {
        console.log(`    ${r.pass ? 'PASS' : 'FAIL'}  ${r.name}`);
        if (!r.pass) {
          console.log(`          ${JSON.stringify(r.detail)}`);
        }
      });
      const failed = mine.filter((r) => !r.pass).length;
      console.log(`    ${failed === 0 ? 'GREEN' : 'RED'} ${mine.length - failed}/${mine.length}`);
    }
    const atWidth = results.filter((r) => r.width === width.label);
    const failedAtWidth = atWidth.filter((r) => !r.pass).length;
    console.log(`  ${width.label}: ${failedAtWidth === 0 ? 'GREEN' : 'RED'} `
      + `${atWidth.length - failedAtWidth}/${atWidth.length}\n`);
  }

  const failed = results.filter((r) => !r.pass);
  console.log('=== summary ===');
  widths.forEach((width) => {
    const atWidth = results.filter((r) => r.width === width.label);
    const bad = atWidth.filter((r) => !r.pass).length;
    console.log(`  ${width.label.padEnd(8)} ${bad === 0 ? 'GREEN' : 'RED'} `
      + `${atWidth.length - bad}/${atWidth.length}`);
  });
  if (failed.length) {
    console.log('  failures:');
    failed.forEach((r) => console.log(`    ${r.width} ${r.scenario} — ${r.name}`));
  }
  console.log(`GATE ${failed.length === 0 ? 'GREEN' : 'RED'} `
    + `${results.length - failed.length}/${results.length}`);
  process.exitCode = failed.length === 0 ? 0 : 1;
}

await main();
