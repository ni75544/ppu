// Task 10D — one pickup-date shell, scored on all seven services.
//
// The claim is a comparison, so it can only be settled by measuring every service
// with the same instrument: one `Preferred Service Date`, one "Choose your pickup
// time" widget, one New York timezone note, and no second date, legacy time,
// flexibility, fixed-slot, Hold, token or countdown control anywhere. Then the
// halves that must differ — Express Courier offers real times, and each of the six
// Manual Review services offers none and says, in its own words, that a person will
// confirm the window.
//
// A server probe can prove the profiles resolve; `qa/support/task10d-shell-state.php`
// already does. It cannot prove a visitor is not offered a button, which is the
// whole of the truthfulness requirement, so that is what this gate reads.
//
// Scenarios, each at 1440x900 and 390x844:
//
//   shell:<service>   ×7  a fresh anonymous request per service: page 1, the
//                         service's own page-2 facts, the date, then the shell.
//   courier-store     ×1  the same walk with delivery_mode=courier_store_pickup
//                         and its three gated answers. Times must still be real.
//   courier-refused   ×1  a complete but ineligible Courier request (overweight).
//                         Zero times, and no sentence claiming otherwise.
//   courier-clears    ×1  a selected time must not survive a date change, a weight
//                         change or a handoff-mode change.
//   switch:<service>  ×6  Courier with a time selected, switched to the service
//                         (arrival must be gone), then switched back (times must
//                         return only once Courier's own answers are complete).
//
// A service change goes through the wizard — Back, change the radio, Next —
// because `service_type` lives on page 1 and page 2 does not carry it. The widget
// reads the service from the quote behavior's stored context, so poking a radio
// that is not on the page would prove something about the harness instead.
//
// Read-only with respect to the business: no final Submit, no Hold, no Reservation,
// no checkout, no payment. Selecting a Courier time takes nothing — capacity is
// claimed when checkout starts — and it is the only way to show a switch discards
// it. Run wrapped in the mutation sentinel; write-capable setup belongs on ppu-qa.
//
// Usage:
//   ddev exec bash -c 'COURIER_FORM_URL=https://ppu-qa.ddev.site/form/hg \
//     node qa/browser/mutation-sentinel.mjs --runner=qa/browser/task10d-shell-gate.mjs'
//   SHELL_ONLY=shell:courier,switch:diy_truck_rental narrows a diagnosing run.
import { sleep } from './webdriver.mjs';
import {
  FORM_URL, answerCourierPage1, answerPilotPage1, inventory, sidebarWording,
  requestNames, messages, armBookingState, armNetworkCapture, capturedCalls, onPage2,
  clickSlot, resetCapture, ensureRadio,
} from './courier-flow.mjs';
import {
  clickControl, typeDate, pressNext, freshSession, severeConsole, waitFor, CSS_escape,
  formQuiet,
} from './user-actions.mjs';
import { openDays } from './open-days.mjs';
import { clearThrottles } from './throttles.mjs';

const SELF_BOOKABLE = 'courier';

/**
 * The next days the resource under test actually works.
 *
 * Two hardcoded dates were the first thing to break here: a run on 2026-09-03 asked
 * for 2026-09-01 and read `unavailable`, which is the correct answer to a request for
 * a day already gone. Scoring that as "the shell offers no times" would have been a
 * false finding, so the dates are asked of the site instead. That resolution now
 * lives in `qa/browser/open-days.mjs`, shared with the two older Courier gates that
 * carried the same rotted default.
 */
const OPEN = openDays(2);
const DATE = OPEN.dates[0];
// A second open day, for the one claim that needs two: a time chosen for one day
// must not survive being asked about another. Only that it is open is assumed, so
// the exact-count assertion stays on DATE.
const DATE_2 = OPEN.dates[1];
// Slot count for the site under test: ppu-qa works Mon-Wed 08:00-18:00 on a 30-minute
// grid, which is 20 grid points, and offers 19 — the last start cannot fit the job
// inside the window once travel, work and both buffers are counted. The active ppu
// site offers 17. Naming it keeps the Courier control a real count rather than "more
// than zero", and keeps it an expectation the gate holds rather than one it reads
// back from the widget it is testing.
const EXPECTED_SLOTS = Number.parseInt(process.env.EXPECTED_SLOTS || '', 10) > 0
  ? Number.parseInt(process.env.EXPECTED_SLOTS, 10)
  : 19;
// A weight that is complete and genuinely ineligible, rather than missing. Only a
// complete request can be refused; an incomplete one is a different state, and
// telling the two apart is the point of the refusal scenario. 45 lb is the value the
// existing state gate walks, chosen because it clears the self-service ceiling by a
// margin no customer could read a threshold off.
const REFUSED_WEIGHT = process.env.REFUSED_WEIGHT || '45';
const ARTIFACTS = process.env.COURIER_ARTIFACTS || 'qa/artifacts';
const STAMP = new Date().toISOString().replace(/[:.]/g, '-').slice(0, 19);
const WIDTHS = [
  { label: 'desktop', width: 1440, height: 900 },
  { label: 'mobile', width: 390, height: 844 },
];
const ONLY = String(process.env.SHELL_ONLY || '').trim();
// One width, for diagnosing a single failure without paying for the other. A
// verdict still requires both, so this narrows a run; it does not shorten the gate.
const ONE_WIDTH = String(process.env.SHELL_WIDTH || '').trim();

/**
 * Each Manual Review service's own sentence, as shipped.
 *
 * Written out here rather than read from the published map, deliberately: the map
 * is what the browser decides from, so a gate that took its expectation from there
 * could not tell a correct sentence from a configuration that lost one. These are
 * `booking_profiles.services.<service>.review_message` in
 * config/install/custom_webform_handlers.dispatch.yml, and the gate asserts the
 * published map still matches them as a separate check.
 */
const REVIEW = {
  on_demand_delivery:
    'This delivery needs a quick review before pickup times can be confirmed.',
  local_residential_move:
    'A move coordinator reviews your home move and confirms the pickup window with you.',
  local_commercial_move:
    'A move coordinator reviews your commercial move and confirms the pickup window with you.',
  long_distance_move:
    'A move coordinator reviews your long-distance shipment and confirms the pickup window with you.',
  specialty_item_move:
    'A specialty-item coordinator reviews the item and access details, then confirms the pickup window with you.',
  diy_truck_rental:
    'A rental coordinator confirms truck availability and your pickup window before anything is scheduled.',
};

const MANUAL_SERVICES = Object.keys(REVIEW);
const ALL_SERVICES = [SELF_BOOKABLE, ...MANUAL_SERVICES];

/**
 * The page-2 answers each service actually requires, in the order the form asks.
 *
 * Not a guess and not a wish list. Two authorities decide what "required" means here
 * and both are consulted:
 *
 *   - the form's own `#states`, which mark an element required for a service. Most
 *     keys below come from there; `qa/support/task10d-service-facts.php` prints that
 *     list, so it can be re-derived rather than trusted.
 *   - the price calculator, which refuses an estimate and names the field in a
 *     `missing_*` review reason. `vehicle_helper_needs` and `origin_stairs_flights`
 *     came from there, read off the stored quote: on-demand reported
 *     `missing_vehicle_selection` and a residential move `missing_origin_stairs_flights`
 *     while every other service reached a genuine review verdict. Both are existing
 *     questions answered with their own existing options — nothing about duration,
 *     capacity or price is inferred, and answering them is what the brief means by
 *     completing a service's actual required facts.
 *
 * Order matters twice: `shipment_home_size` is gated on `shipment_size`, and
 * `origin_stairs_flights` only exists once origin access is `stairs`. The gating answer
 * is given first and the dependent control is waited for.
 *
 * Courier has no entry because Courier asks for nothing on page 2 but the date —
 * which is the shell, not a service-specific fact.
 */
const PAGE2_FACTS = {
  on_demand_delivery: [
    { name: 'items_to_move_sameday',
      value: 'One sealed document envelope and one small parcel.' },
    { name: 'vehicle_helper_needs', value: 'van_1_helper' },
  ],
  local_residential_move: [
    { name: 'move_size_residential', value: '1_br' },
    { name: 'origin_access_conditions', value: 'stairs' },
    { name: 'origin_stairs_flights', value: '2' },
    { name: 'destination_access_conditions', value: 'passenger_elevator' },
    { name: 'parking_situation', value: 'close' },
    { name: 'specialty_items_residential', value: 'No' },
  ],
  local_commercial_move: [
    { name: 'scope_of_move',
      value: 'Twelve desks, twelve chairs and four filing cabinets, one floor to another.' },
    { name: 'timing_of_move', value: 'business_hours' },
  ],
  long_distance_move: [
    { name: 'shipment_size', value: 'home_size' },
    { name: 'shipment_home_size', value: '1_br' },
  ],
  specialty_item_move: [
    { name: 'item_to_be_moved', value: 'piano' },
    { name: 'item_specifications', value: 'Upright piano, about 500 lb, 58 inches wide.' },
    { name: 'access_obstacles', value: 'Four exterior steps at the pickup address, no elevator.' },
  ],
  diy_truck_rental: [
    { name: 'truck_size', value: '15_ft' },
    { name: 'rental_duration', value: '2' },
  ],
};

// The form's own help text for the date question, which the widget restores
// whenever the list is empty and replaces while times are on the screen.
const DATE_HELP_DEFAULT = 'Select a date to see available pickup times.';
// Anything here in a resource name would mean capacity was taken or money started,
// which no scenario in this gate may do. Core's CSRF endpoint is excluded by name
// rather than dropped from the pattern: every page in this theme fetches
// /session/token and it starts no booking, but a Hold token is the one artefact of a
// booking authority that need not carry the word "hold" in its path.
const SIDE_EFFECTS = /hold|reserve|reservation|checkout|payment|renew|release|token/i;
const CORE_CSRF = '/session/token';
/**
 * Claims that would be false on a service that cannot be booked from the form.
 *
 * Phrases, not words. "Confirm" cannot be banned: every shipped review sentence
 * says a person confirms the window, which is the true statement. What must never
 * appear is a claim that the confirmation has already happened, that times are on
 * offer, or that the price is instant.
 */
const FALSE_AVAILABILITY = [
  'Available pickup times for ',
  'Instant estimate',
  'instant estimate',
  'Booking confirmed',
  'Reservation confirmed',
  'Your pickup time is confirmed',
  'Pickup time confirmed',
  'confirmed instantly',
  'Confirmed instantly',
];

const results = [];

/** Records one scored assertion. `detail` is what was actually measured. */
function check(scenario, width, name, pass, detail) {
  const label = width && typeof width === 'object' ? width.label : String(width);
  results.push({ scenario, width: label, name, pass: !!pass, detail });

  return !!pass;
}

/**
 * The readings `inventory()` does not take, taken per service.
 *
 * Four groups. This service's own review sentence and every *other* service's, so
 * a shared or leftover sentence is caught rather than counted as a pass. The
 * phrases that would be false here. The retired scheduling questions counted in the
 * document at all — not merely their visibility — because a question hidden with
 * CSS is still a question the page asks, and hiding one was named as the wrong fix.
 * And the published profile map itself, so the browser's contract can be compared
 * with the configuration that is supposed to have produced it.
 *
 * The day label is computed with the widget's own formatter and published timezone
 * rather than hardcoded, so the date-help assertion is exact without pinning the
 * gate to one locale's month abbreviation.
 */
function shellReads(browser, ymd, service) {
  const own = REVIEW[service] || '';
  const others = MANUAL_SERVICES.filter((s) => s !== service).map((s) => REVIEW[s]);

  return browser.js(`
    const [ymd, own, others, falsePhrases] = arguments;
    const all = (css) => Array.from(document.querySelectorAll(css));
    const named = (name) => all('[name]')
      .filter((el) => String(el.name || '').split('[')[0] === name);
    const body = String(document.body.innerText || '');
    const countText = (needle) => needle === '' ? 0 : body.split(needle).length - 1;
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
    const published = {};
    Object.keys(profiles).sort().forEach((key) => {
      const p = profiles[key] || {};
      published[key] = {
        mode: String(p.mode || ''),
        always_review: p.always_review === true,
        facts: Array.isArray(p.required_facts) ? p.required_facts.length : null,
        message: String(p.review_message || ''),
      };
    });
    return {
      reviewSentence: countText(own),
      foreignSentences: others.map((s, i) => [i, countText(s)]).filter((pair) => pair[1] > 0),
      falseClaims: falsePhrases
        .map((p) => [p, countText(p)]).filter((pair) => pair[1] > 0),
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
      timeInputsInDom: all('input[type="time"]').length,
      slotWidgets: all('.custom-webform-dispatch').length,
      expectedDayHelp: 'Available pickup times for ' + dayLabel + '.',
      publishedTimezone: timezone,
      published,
    };
  `, [ymd, own, others, FALSE_AVAILABILITY]);
}

/**
 * Answers one page-2 control, whatever kind of control the form made it.
 *
 * The kind is read from the document rather than declared in the fact table, so a
 * question that changes from radios to a select is answered rather than silently
 * skipped — and an option that does not exist is reported with the options that do,
 * instead of being invented. A control that is absent or unpainted is reported as
 * such: this walk answers what the form asks and nothing else.
 */
function answerFact(browser, name, value) {
  return browser.js(`
    const [name, value] = arguments;
    const shown = (el) => {
      if (!el) { return false; }
      const cs = getComputedStyle(el);
      if (cs.visibility === 'hidden' || cs.display === 'none') { return false; }
      if (el.type === 'hidden') { return false; }
      return el.offsetParent !== null || cs.position === 'fixed';
    };
    const fire = (el) => {
      el.dispatchEvent(new Event('input', { bubbles: true }));
      el.dispatchEvent(new Event('change', { bubbles: true }));
    };
    const controls = Array.from(document.querySelectorAll('[name]'))
      .filter((el) => String(el.name || '').split('[')[0] === name);
    if (!controls.length) { return { name, ok: false, reason: 'absent' }; }
    const visible = controls.filter(shown);
    if (!visible.length) {
      return { name, ok: false, reason: 'not-painted', inDom: controls.length };
    }
    const el = visible[0];
    if (el.tagName === 'SELECT') {
      const option = Array.from(el.options).find((o) => String(o.value) === String(value));
      if (!option) {
        return { name, ok: false, reason: 'no-such-option',
          options: Array.from(el.options).map((o) => String(o.value)).slice(0, 12) };
      }
      el.focus();
      el.value = option.value;
      fire(el);
      return { name, ok: el.value === option.value, kind: 'select', value: el.value };
    }
    if (el.type === 'radio' || el.type === 'checkbox') {
      const target = visible.find((c) => String(c.value) === String(value));
      if (!target) {
        return { name, ok: false, reason: 'no-such-option',
          values: visible.map((c) => String(c.value)).slice(0, 12) };
      }
      if (!target.checked) { target.click(); }
      if (!target.checked) { target.checked = true; fire(target); }
      return { name, ok: target.checked === true, kind: el.type, value: String(target.value) };
    }
    el.focus();
    el.value = value;
    fire(el);
    return { name, ok: String(el.value) === String(value),
      kind: String(el.type || el.tagName).toLowerCase(), length: String(el.value).length };
  `, [name, value]);
}

/**
 * Answers every page-2 fact the service requires, and reports each one.
 *
 * Each control is waited for before it is answered, because one of them is gated on
 * another answer and Webform re-renders the region between the two. The whole trace
 * is returned so an unanswered question is a reading rather than a mystery: a Manual
 * Review verdict on an incomplete request would be true for the wrong reason, and
 * the brief asks for the service's real facts to be complete.
 */
async function answerPage2Facts(browser, service) {
  const facts = PAGE2_FACTS[service] || [];
  const trace = { service, asked: facts.length, answers: [] };
  for (const fact of facts) {
    const appeared = await waitFor(browser, `${fact.name} to render`, async () =>
      (await browser.js(`
        const [name] = arguments;
        const el = document.querySelector('[name="' + name + '"]');
        return !!el && el.offsetParent !== null;
      `, [fact.name])) === true, 12000);
    const answer = appeared.ok
      ? await answerFact(browser, fact.name, fact.value)
      : { name: fact.name, ok: false, reason: 'never-rendered' };
    trace.answers.push(answer);
    await formQuiet(browser).catch(() => {});
  }
  trace.complete = trace.answers.every((a) => a.ok === true);

  return trace;
}

/** Waits until the widget has published a settled verdict, or records that it did not. */
const settled = (browser) => waitFor(browser, 'the widget to publish a settled state',
  async () => ['available', 'unavailable', 'manual_review']
    .includes(await browser.js(`return window.__cwhBookingState || null;`)), 25000);

/**
 * Waits for a verdict only as far as one is owed.
 *
 * Before a date is chosen a self-bookable service has nothing to answer about, and
 * the observer is installed after page 2 has rendered, so an initialisation-time
 * state is unobservable by construction. This gives the event a short chance and
 * then reports what it found, so "no verdict yet" is a reading, not a timeout.
 */
const settledOrQuiet = (browser) => waitFor(browser, 'a verdict, if one is owed yet',
  async () => ['available', 'unavailable', 'manual_review']
    .includes(await browser.js(`return window.__cwhBookingState || null;`)), 4000);

/** Waits until at least one bookable pickup time is on the screen. */
const slotsRendered = (browser) => waitFor(browser, 'pickup times to render', async () =>
  (await browser.js(
    `return document.querySelectorAll('.custom-webform-dispatch__slot').length > 0;`)) === true,
25000);

/**
 * Presses the wizard's own Back control and waits for page 1 to come back.
 *
 * The only route to a service change: `service_type` is a page-1 element and the
 * widget reads the service from the context the quote behavior stored there. The
 * click is delivered by WebDriver rather than by page script, so an unreachable Back
 * control is reported instead of being clicked anyway.
 */
async function pressBack(browser) {
  const label = await browser.js(`
    const btn = document.querySelector('[data-drupal-selector="edit-wizard-prev"]');
    return btn ? String(btn.value || btn.textContent || '').trim() : null;
  `);
  let clicked = await clickControl(browser, '[data-drupal-selector="edit-wizard-prev"]');
  if (!clicked.ok) {
    const alternates = await browser.js(`
      return Array.from(document.querySelectorAll('input[type="submit"], button[type="submit"]'))
        .filter((el) => /back|previous/i.test(el.value || el.textContent || ''))
        .map((el) => el.id).filter(Boolean);
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
  // Page 1 being present is not page 1 being finished: Webform builds the step and
  // then re-renders its conditional regions, and a control found between the two is
  // detached before it can be used.
  const quiet = back.ok ? await formQuiet(browser) : { ok: false, reason: 'never arrived' };

  return { ...clicked, label, onPage1: back.ok, wait: back, quiet };
}

/**
 * Changes the service through the wizard and returns to the widget page.
 *
 * Both observers are re-armed afterwards: Next and Back are real POSTs, so anything
 * attached to the old document went with it, and a capture that survived would be a
 * capture of the previous page. The new service's page-2 facts are answered on
 * arrival, because they are the request the customer is now making.
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
    await resetCapture(browser);
    step.facts = await answerPage2Facts(browser, service);
  }

  return step;
}

/**
 * Re-picks the service date, and reports what happened.
 *
 * Not a workaround for a slow page: the widget publishes its verdict only when the
 * verdict changes, and a wizard round trip re-renders page two with the date already
 * answered, so the first verdict of the new service is decided while the harness is
 * still re-attaching a listener to a document that did not exist a moment ago. A
 * customer re-opening the picker and choosing the same day is the ordinary action
 * that asks the question again, and it is the one modelled here. Nothing about the
 * request changes, so the answer that arrives is the answer they already had.
 */
async function reassertDate(browser, ymd = DATE) {
  const before = await browser.js(`
    const el = document.querySelector('[name="preferred_service_date"]');
    return el ? String(el.value || '') : null;
  `);
  const typed = await typeDate(browser, 'input[name="preferred_service_date"]', ymd);

  return { before, carried: before === ymd, typed };
}

/** Every reading a scenario is scored from, taken together so they agree. */
async function readAll(browser, label, service, ymd = DATE) {
  const shot = await browser.screenshot(`${ARTIFACTS}/${STAMP}-10d-${label}.png`)
    .catch((error) => `screenshot-failed:${String(error.message || error).slice(0, 60)}`);

  return {
    service,
    inventory: await inventory(browser),
    shell: await shellReads(browser, ymd, service),
    sidebar: await sidebarWording(browser),
    quote: await quoteBreakdown(browser),
    declared: await declaredFacts(browser),
    requests: await requestNames(browser),
    calls: await capturedCalls(browser),
    messages: await messages(browser),
    console: await severeConsole(browser),
    screenshot: shot,
  };
}

/** The page-1 answerer for one service: Courier's own, or the shared three-answer one. */
const page1For = (service, options = {}) => (browser) =>
  (service === SELF_BOOKABLE
    ? answerCourierPage1(browser, options)
    : answerPilotPage1(browser, service));

// ---------------------------------------------------------------------------
// Walks
// ---------------------------------------------------------------------------

/**
 * One service, walked from a fresh anonymous session to a settled verdict.
 *
 * Read twice for every service: once before a date is chosen, and again after. "No
 * Slot buttons" is only interesting if it survives the act that produces them for
 * Courier, and "one date question" has to hold before the widget has said anything
 * at all — that is where a second date control would be visible.
 */
async function walkShell(width, service, options = {}) {
  const throttle = clearThrottles();
  const { browser, reset } = await freshSession(width.width, width.height, FORM_URL);
  const run = { reset, service, throttle };
  try {
    run.page1 = await page1For(service, options)(browser);
    run.next = await pressNext(browser);
    run.onPage2 = await onPage2(browser);
    if (!run.onPage2) {
      run.stoppedAt = 'page-1';

      return run;
    }
    await armBookingState(browser);
    await armNetworkCapture(browser);
    run.facts = await answerPage2Facts(browser, service);
    run.settledBeforeDate = await settledOrQuiet(browser);
    run.beforeDate = await readAll(browser, `${width.label}-${service}-before-date`, service);

    run.date = await typeDate(browser, 'input[name="preferred_service_date"]', DATE);
    // Waiting for times is only right where times are expected. A refused Courier
    // request is a Courier request, and waiting 25 seconds for a list that must not
    // exist would spend the timeout to learn what the refusal already says.
    run.slotsRendered = service === SELF_BOOKABLE && options.expectSlots !== false
      ? await slotsRendered(browser)
      : null;
    run.settledAfterDate = await settled(browser);
    await sleep(1500);
    run.afterDate = await readAll(browser, `${width.label}-${service}-after-date`, service);

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
 * Waits for the widget's *next* verdict, counted rather than compared.
 *
 * A verdict is published only when it changes, and two different questions can get
 * the same answer: a complete Courier request moved from one open day to another
 * open day goes `available` → `available`. Waiting on the value would sample the
 * verdict the page was already showing and score it as the answer to the new
 * question. The change counter is the only instrument that separates them, so it is
 * the one used wherever a reading has to belong to a specific act.
 */
async function settleChanged(browser, baseline, label) {
  const waited = await waitFor(browser, label, async () => {
    const snap = await browser.js(`
      const region = document.querySelector('.custom-webform-dispatch');
      return {
        busy: !!region && region.getAttribute('aria-busy') === 'true',
        state: window.__cwhBookingState || null,
        changes: window.__cwhBookingStates || 0,
      };
    `);

    return snap.busy === false && snap.changes > baseline
      && ['available', 'unavailable', 'manual_review'].includes(snap.state);
  }, 25000);

  return { ...waited, baseline };
}

/**
 * The requests that would mean money or capacity moved, from resource timing.
 *
 * Resource timing rather than the fetch wrapper, because the wrapper only sees what
 * page script asked for: a form POST, an image beacon or an iframe would be invisible
 * to it and is exactly the shape a stray checkout call would take. The core CSRF
 * endpoint is excluded by name — the widget fetches a token to POST the offer request
 * at all, and matching on `token` alone would fail every honest run.
 */
const sideEffects = (read) => (read.requests || [])
  .filter((n) => n !== CORE_CSRF && SIDE_EFFECTS.test(n));

/** The availability requests this document made. */
const offerRequests = (read) => (read.requests || [])
  .filter((n) => /dispatch\/offer/.test(n));

/**
 * The Google Places transport, which is not this shell and cannot be fixed here.
 *
 * The address questions are a `<gmp-place-autocomplete>` element talking to
 * `places.googleapis.com`. When that call cannot complete — no outbound route from the
 * Selenium container, a referrer-restricted key, a rate limit — the element logs the
 * failure at SEVERE, and one of the three entries is attributed to the page URL rather
 * than to Google, so an origin filter would keep it.
 *
 * Those entries are recorded and printed, never gated on: the assertion Task 10D asks
 * for is that standardising the pickup-date shell introduced no script error, and a
 * gate that reddens on Google's reachability answers a different question on a
 * schedule nobody controls. Anything else severe still fails, including a Maps message
 * that names one of this module's own files.
 */
const THIRD_PARTY_CONSOLE =
  /places\.googleapis\.com|maps\.googleapis\.com|gmp-place-autocomplete|maps-api-v3/;

const ourConsole = (read) => (read.console || [])
  .filter((entry) => !(THIRD_PARTY_CONSOLE.test(entry)
    && !/custom_webform_handlers/.test(entry)));

const mapsConsole = (read) => (read.console || [])
  .filter((entry) => THIRD_PARTY_CONSOLE.test(entry)
    && !/custom_webform_handlers/.test(entry));

/**
 * The answers still in the document that Courier's eligibility policy refuses.
 *
 * A wizard carries every page's answers, and `#states` hides a control without
 * clearing it, so a service change leaves the previous service's declarations behind
 * where the offer request will still read them. `vehicle_helper_needs` is the one that
 * matters on the return leg: every value in that select is a declared helper need, and
 * `manual_review.vehicle_helper_needs` in the Dispatch configuration refuses a
 * self-booked Courier request that carries one — "Any helper requirement means more
 * people than the single driver this Resource represents".
 *
 * Read regardless of visibility, because invisible is exactly the state that matters:
 * the control the customer can no longer see is the one still speaking for them.
 *
 * This is a reading, not a judgement. What it enables is a return-leg assertion whose
 * expected outcome is decided by the request that was actually sent rather than by a
 * per-service exception list.
 */
function declaredFacts(browser) {
  return browser.js(`
    const value = (name) => {
      const el = document.querySelector('[name="' + name + '"]');
      return el ? String(el.value || '') : null;
    };

    return {
      vehicleHelperNeeds: value('vehicle_helper_needs'),
      totalWeightLbs: value('total_weight_lbs'),
      deliveryMode: value('delivery_mode'),
      serviceType: value('service_type'),
    };
  `);
}

/**
 * Whether the sidebar's panel is the truthful one for the verdict it was handed.
 *
 * Task 10D asks the gate to "distinguish unfinished form … from genuine Manual Review",
 * and the sidebar already draws that line: `render()` checks its unfinished branch
 * before the review badge, and the source says why — an unfinished request "claims
 * neither, because no estimate has been reached to qualify". So there are two honest
 * panels, and which one is correct is decided by the calculator's own words, not by a
 * preference of this gate:
 *
 *   - reasons that are all `missing_*` — a fact nobody has given yet. "Estimate not
 *     complete" and the note asking for the remaining details is the truthful panel,
 *     and the review badge would be a verdict the server has not reached.
 *   - any other reason, or a booking state of manual_review with no reasons at all — a
 *     real refusal, and the review badge is owed.
 *
 * Asserting the badge unconditionally is what the first version did, and it reddened on
 * two services whose panels were correct: the filler had left `vehicle_helper_needs` and
 * `origin_stairs_flights` unanswered, so the calculator was still asking. Naming the
 * outstanding reason is the point — it turns "a badge was absent" into the missing
 * field, which is how those two facts were found. Both panels are still held to saying
 * nothing bookable; that is scored separately and unconditionally.
 */
function sidebarHonest(read) {
  const quote = read.quote || {};
  const reasons = Array.isArray(quote.reasons) ? quote.reasons : [];
  const unfinished = quote.status === 'manual_review' && reasons.length > 0
    && reasons.every((r) => String(r).indexOf('missing_') === 0);
  const badge = read.sidebar.pendingManualReview >= 1
    || read.sidebar.needsAPersonNote >= 1;
  const detail = { badge, unfinished, outstanding: reasons,
    status: read.sidebar.statusText, quoteFrom: quote.from || null };

  // An unfinished request may show either panel: the badge is not wrong once the
  // widget has refused the service outright, and the incomplete note is not wrong
  // while a fact is outstanding. A finished one has no such latitude.
  return { ok: unfinished ? true : badge, detail };
}

/**
 * Why the price calculator sent this request to staff, in its own words.
 *
 * The sidebar draws one of two different truthful panels from this: reasons that are
 * all `missing_*` mean the customer has not finished describing the job, so it says
 * so and claims no verdict; anything else is a real refusal and gets the review
 * badge. Reading the reasons rather than the panel is what lets a failure name which
 * fact is still outstanding instead of reporting that a badge was absent.
 *
 * Read from the hidden breakdown field the sidebar itself parses, not from the
 * preview response: the response is an envelope whose own `status` is about the HTTP
 * call, and mistaking one for the other is how a reading of "success" ends up beside
 * a panel that says the estimate is not complete.
 *
 * The field is named by `field_names.quote_breakdown` in the handler's own published
 * settings, so it is looked up there rather than guessed from the element name. A
 * guess is what the first version did, and it reported `field: null` on a page whose
 * sidebar was rendering a quote — the honest answer to a search for a name nobody
 * uses, and a useless one for the assertion it was meant to explain.
 *
 * On `hg` that setting is empty: no element is configured to carry the breakdown, so
 * the sidebar never reads a field at all and its quote arrives on the
 * `custom-webform-quote-updated` event, persisted under `<quote_context_key>:preview`.
 * That store is the fallback here — the same object, read from the same place the
 * sidebar reloads it from, rather than a second copy of the calculator's rules.
 *
 * `drupalSettings.custom_webform_handlers` is that configuration object itself, not a
 * map keyed by form; walking its values looking for one with a `field_names` key finds
 * nothing, which is how a reader can report `key: null` on a page that publishes one.
 */
function quoteBreakdown(browser) {
  return browser.js(`
    const cfg = (window.drupalSettings || {}).custom_webform_handlers || {};
    const name = String((cfg.field_names || {}).quote_breakdown || '');
    const key = cfg.quote_context_key ? String(cfg.quote_context_key) + ':preview' : '';

    let raw = '';
    let from = null;
    const field = name === '' ? null
      : document.querySelector('[name="' + name.replace(/"/g, '\\\\"') + '"]');
    if (field) { raw = String(field.value || ''); from = 'field:' + name; }
    if (raw === '' && key !== '') {
      try {
        const stored = JSON.parse(String(window.sessionStorage.getItem(key) || ''));
        if (stored && stored.quote) { raw = JSON.stringify(stored.quote); from = 'store'; }
      }
      catch (e) { /* nothing stored yet */ }
    }
    if (raw === '') { return { from: null, field: name || null, key: key || null }; }

    let quote = null;
    try { quote = JSON.parse(raw); }
    catch (e) { quote = null; }
    if (!quote || typeof quote !== 'object') { return { from, unparsable: raw.slice(0, 80) }; }

    return {
      from,
      status: String(quote.status || ''),
      reasons: Array.isArray(quote.manual_review_reasons) ? quote.manual_review_reasons : [],
      total: quote.total === undefined ? null : quote.total,
    };
  `);
}

/**
 * A page-1 answer change, delivered the only way the form allows it.
 *
 * `total_weight_lbs` and `delivery_mode` are page-1 elements and the widget is a
 * page-2 element, so there is no keystroke that changes one while the other is on
 * screen. Back, re-answer, Next is the customer's route, and it is a real navigation:
 * both observers are re-armed on arrival because the document that carried them is
 * gone. The date answered earlier is carried by the wizard, so page 2 comes back with
 * a date already chosen and the widget asks for that day again on its own.
 */
async function wizardTrip(browser, options, label) {
  const trip = { label };
  trip.back = await pressBack(browser);
  if (!trip.back.onPage1) {
    return trip;
  }
  trip.page1 = await answerCourierPage1(browser, options);
  trip.next = await pressNext(browser);
  trip.onPage2 = await onPage2(browser);
  if (!trip.onPage2) {
    return trip;
  }
  await armBookingState(browser);
  await armNetworkCapture(browser);
  await resetCapture(browser);
  trip.settled = await settled(browser);
  // The widget re-offers on its own for a carried date, and the offer lands after
  // the state it published while asking. A short pause lets the list it renders be
  // read in the same snapshot as the verdict that produced it.
  await sleep(1200);

  return trip;
}

/** A second eligible weight: heavy enough to re-price, light enough to stay bookable. */
const HEAVIER_WEIGHT = process.env.HEAVIER_WEIGHT || '18';

/**
 * Courier, with a time selected, put through each change the brief names.
 *
 * One session rather than four, because the property under test is that a selection
 * does not outlive the request it belongs to, and re-selecting between changes is
 * what makes each change observable. A date change is answered in the page; a weight
 * change and a mode change are page-1 answers and go through the wizard. The service
 * change is the switch walks' subject and is not repeated here.
 */
async function walkCourierClears(width) {
  const throttle = clearThrottles();
  const { browser, reset } = await freshSession(width.width, width.height, FORM_URL);
  const run = { reset, service: SELF_BOOKABLE, throttle };
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
    run.settledFirst = await settled(browser);
    run.picked = await clickSlot(browser);
    run.held = await readAll(browser, `${width.label}-clears-1-selected`, SELF_BOOKABLE);

    await resetCapture(browser);
    run.dateChange = await typeDate(browser, 'input[name="preferred_service_date"]', DATE_2);
    run.settledSecond = await settleChanged(browser, run.held.inventory.bookingStateChanges,
      `a verdict for ${DATE_2}`);
    await sleep(800);
    run.afterDate = await readAll(browser, `${width.label}-clears-2-date`, SELF_BOOKABLE, DATE_2);

    run.reselectedForWeight = await clickSlot(browser);
    run.beforeWeight = await inventory(browser);
    run.weightTrip = await wizardTrip(browser, { weight: HEAVIER_WEIGHT }, 'weight');
    run.afterWeight = await readAll(browser, `${width.label}-clears-3-weight`,
      SELF_BOOKABLE, DATE_2);

    run.reselectedForMode = await clickSlot(browser);
    run.beforeMode = await inventory(browser);
    run.modeTrip = await wizardTrip(browser,
      { weight: HEAVIER_WEIGHT, mode: 'courier_store_pickup' }, 'mode');
    run.afterMode = await readAll(browser, `${width.label}-clears-4-mode`,
      SELF_BOOKABLE, DATE_2);

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
 * Courier with a time selected, switched to a Manual Review service and back.
 *
 * The return leg is deliberately attempted twice. First with the service radio and
 * nothing else, because "the times come back when you pick Courier again" would be a
 * false promise: the weight, handoff and attestation questions are blank after the
 * other service's branch replaced them, and a request missing them is not a request
 * the planner may answer. Only then are they restored, which is when times are owed.
 */
async function walkSwitch(width, service) {
  const throttle = clearThrottles();
  const { browser, reset } = await freshSession(width.width, width.height, FORM_URL);
  const run = { reset, service, throttle };
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
    run.settledCourier = await settled(browser);
    run.picked = await clickSlot(browser);
    run.held = await readAll(browser, `${width.label}-switch-${service}-1-courier`, SELF_BOOKABLE);

    run.switch = await switchService(browser, service, page1For(service));
    if (run.switch.onPage2) {
      run.reasserted = await reassertDate(browser, DATE);
      run.settledSwitched = await settled(browser);
      await sleep(1200);
      run.afterSwitch = await readAll(browser, `${width.label}-switch-${service}-2-review`,
        service, DATE);
    }

    // The bare return: the service radio alone, with the Courier branch's own
    // questions left as the other service's page 1 left them.
    run.bare = { back: await pressBack(browser) };
    if (run.bare.back.onPage1) {
      run.bare.radio = await ensureRadio(browser,
        `input[name="service_type"][value="${SELF_BOOKABLE}"]`);
      run.bare.quiet = await formQuiet(browser);
      run.bare.next = await pressNext(browser);
      run.bare.onPage2 = await onPage2(browser);
      run.bare.messages = await messages(browser);
    }

    run.restore = {};
    // Only answer page 1 if the bare attempt was in fact refused. If it was not, the
    // walk is already on page 2 and the assertion about that has already been taken;
    // re-answering a page that is not on screen would bury it under absent controls.
    if (run.bare.onPage2 !== true) {
      run.restore.page1 = await answerCourierPage1(browser);
      run.restore.next = await pressNext(browser);
    }
    run.restore.onPage2 = await onPage2(browser);
    if (run.restore.onPage2) {
      await armBookingState(browser);
      await armNetworkCapture(browser);
      await resetCapture(browser);
      run.restore.reasserted = await reassertDate(browser, DATE);
      run.restore.slotsRendered = await slotsRendered(browser);
      run.restore.settled = await settled(browser);
      await sleep(1200);
      run.restored = await readAll(browser, `${width.label}-switch-${service}-3-back`,
        SELF_BOOKABLE, DATE);
    }

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
// Scorers
// ---------------------------------------------------------------------------

/**
 * The shared shell itself, scored identically for all seven services.
 *
 * Every assertion here is a count, not a boolean, because the defect this task
 * exists to close was a *second* copy of a control rather than a missing one, and
 * "a date question is present" was true on the page that asked for the date twice.
 *
 * The retired questions are counted in the document and not merely in the visible
 * set. A question hidden with CSS is still a question the form asks and still posts
 * a value, and hiding them was named as the wrong fix; `#access_create_roles: []`
 * removes the control from the built form, so the honest measurement of it is zero
 * nodes, not zero visible nodes.
 */
function scoreSharedShell(s, width, read, label) {
  const inv = read.inventory;
  const shell = read.shell;
  check(s, width, `${label}: exactly one visible service-date question`,
    inv.preferredServiceDateVisible === 1 && shell.preferredServiceDateInDom === 1,
    { visible: inv.preferredServiceDateVisible, inDom: shell.preferredServiceDateInDom });
  check(s, width, `${label}: that is the only date control on the page`,
    inv.visibleEnabledDateInputs === 1
      && inv.visibleDateInputNames.join(',') === 'preferred_service_date'
      && inv.dispatchWidgetDateInputs === 0,
    { count: inv.visibleEnabledDateInputs, names: inv.visibleDateInputNames,
      widgetOwnDateInputs: inv.dispatchWidgetDateInputs });
  check(s, width, `${label}: exactly one pickup-time widget`,
    shell.slotWidgets === 1 && shell.dispatchSlotInDom === 1,
    { widgets: shell.slotWidgets, arrivalElements: shell.dispatchSlotInDom });
  check(s, width, `${label}: exactly one pickup-time heading`,
    inv.textChoosePickupTime === 1, { headings: inv.textChoosePickupTime });
  check(s, width, `${label}: exactly one New York time label`,
    inv.textTimezoneNote === 1, { labels: inv.textTimezoneNote });
  check(s, width, `${label}: the legacy time question is not in the document`,
    shell.preferredServiceTimeInDom === 0 && inv.preferredServiceTimeVisible === 0
      && inv.textPreferredServiceTime === 0 && shell.timeInputsInDom === 0,
    { inDom: shell.preferredServiceTimeInDom, visible: inv.preferredServiceTimeVisible,
      words: inv.textPreferredServiceTime, timeInputs: shell.timeInputsInDom });
  check(s, width, `${label}: the flexibility question is not in the document`,
    shell.dateFlexibilityInDom === 0 && inv.dateFlexibilityVisible === 0,
    { inDom: shell.dateFlexibilityInDom, visible: inv.dateFlexibilityVisible });
  check(s, width, `${label}: no legacy fixed-slot widget`,
    inv.legacyReservationWidgets === 0 && shell.bookingControls === 0,
    { legacy: inv.legacyReservationWidgets, holdControls: shell.bookingControls });
  check(s, width, `${label}: nothing was held, reserved, renewed or charged`,
    sideEffects(read).length === 0 && shell.bookingWords === 0,
    { requests: sideEffects(read), wording: shell.bookingWords });
  check(s, width, `${label}: no script error`,
    ourConsole(read).length === 0,
    { console: ourConsole(read), info_maps: mapsConsole(read).length });
  check(s, width, `${label}: no horizontal overflow`,
    inv.documentScrollWidth <= inv.innerWidth + 1,
    { scrollWidth: inv.documentScrollWidth, innerWidth: inv.innerWidth });
}

/**
 * A Manual Review service on a complete request with a date chosen.
 *
 * Zero times is the easy half. The half worth scoring is that the page says *why* in
 * this service's own words, and says nothing that a customer could read as a booking:
 * a shared sentence, a leftover Courier sentence, or an "Instant estimate" beside a
 * request a person has to look at are all ways of showing zero times dishonestly.
 *
 * No offer request at all is asserted deliberately. The profile answers this before a
 * request is made, so an offer call would mean the browser asked the planner about a
 * service the planner must refuse — harmless in effect and wrong in kind, and the
 * first symptom if the published map stopped reaching the widget.
 */
function scoreManualReview(s, width, read, service) {
  const inv = read.inventory;
  const shell = read.shell;
  const published = (shell.published || {})[service] || {};
  check(s, width, `${service}: zero pickup times are offered`,
    inv.slotButtonsVisible === 0 && inv.slotButtonsUnbookable === 0
      && (inv.slotAts || []).length === 0,
    { visible: inv.slotButtonsVisible, unbookable: inv.slotButtonsUnbookable,
      instants: (inv.slotAts || []).length });
  check(s, width, `${service}: no arrival is carried in dispatch_slot`,
    inv.dispatchSlotValue === '', { dispatch_slot: inv.dispatchSlotValue });
  check(s, width, `${service}: its own review sentence is on the page`,
    shell.reviewSentence >= 1,
    { count: shell.reviewSentence, status: String(inv.status || '').slice(0, 140) });
  check(s, width, `${service}: no other service's review sentence is on the page`,
    shell.foreignSentences.length === 0,
    { foreign: shell.foreignSentences.map(([i, n]) => `${MANUAL_SERVICES
      .filter((x) => x !== service)[i]}=${n}`) });
  check(s, width, `${service}: nothing claims availability, confirmation or instant pricing`,
    shell.falseClaims.length === 0 && inv.textInstantEstimate === 0,
    { claims: shell.falseClaims, instantEstimate: inv.textInstantEstimate,
      total: read.sidebar.totalText });
  check(s, width, `${service}: the calculator is not still asking for a fact`,
    (Array.isArray((read.quote || {}).reasons) ? read.quote.reasons : [])
      .filter((r) => String(r).indexOf('missing_') === 0).length === 0,
    { reasons: (read.quote || {}).reasons, from: (read.quote || {}).from });
  check(s, width, `${service}: the sidebar panel matches the reason it was given`,
    sidebarHonest(read).ok, sidebarHonest(read).detail);
  check(s, width, `${service}: the widget publishes manual_review`,
    inv.bookingState === 'manual_review',
    { state: inv.bookingState, changes: inv.bookingStateChanges });
  check(s, width, `${service}: the date question offers no times beside it`,
    inv.dateHelpText === DATE_HELP_DEFAULT,
    { help: inv.dateHelpText });
  check(s, width, `${service}: the planner was never asked for availability`,
    offerRequests(read).length === 0
      && (read.calls || []).filter((c) => /dispatch\/offer/.test(c.path)).length === 0,
    { resourceTiming: offerRequests(read),
      wrapped: (read.calls || []).map((c) => `${c.path}=${c.status}`) });
  check(s, width, `${service}: the published profile is the one configuration installed`,
    published.mode === 'manual_review' && published.always_review === true
      && published.message === REVIEW[service],
    { published, expected: REVIEW[service] });
  check(s, width, `${service}: courier is still published as self-bookable`,
    ((shell.published || {})[SELF_BOOKABLE] || {}).mode === 'self_bookable',
    { courier: (shell.published || {})[SELF_BOOKABLE] });
}

/** Each offered instant's New York calendar date, so a list can be tied to its day. */
const localDates = (ats, zone) => {
  const fmt = new Intl.DateTimeFormat('en-CA',
    { timeZone: zone || 'America/New_York', year: 'numeric', month: '2-digit', day: '2-digit' });

  return Array.from(new Set((ats || []).map((at) => fmt.format(new Date(at * 1000)))));
};

/**
 * Courier on a request the rules accept: the control the other six are measured by.
 *
 * The count is asserted exactly. "More than zero times" would pass on a widget that
 * had started offering a closed day's hours, and the whole claim being protected is
 * that only *active bookable* times render — so the unbookable count and the calendar
 * date of every instant are read as well, not just the total.
 */
function scoreCourierBookable(s, width, read, ymd) {
  const inv = read.inventory;
  const days = localDates(inv.slotAts, read.shell.publishedTimezone);
  check(s, width, `courier: exactly ${EXPECTED_SLOTS} bookable times, none unbookable`,
    inv.slotButtonsVisible === EXPECTED_SLOTS && inv.slotButtonsUnbookable === 0,
    { visible: inv.slotButtonsVisible, unbookable: inv.slotButtonsUnbookable,
      expected: EXPECTED_SLOTS });
  check(s, width, `courier: every offered time falls on ${ymd}`,
    days.length === 1 && days[0] === ymd, { days, instants: (inv.slotAts || []).length });
  check(s, width, 'courier: the widget publishes available',
    inv.bookingState === 'available',
    { state: inv.bookingState, changes: inv.bookingStateChanges,
      published: (read.shell.published || {})[SELF_BOOKABLE],
      calls: (read.calls || []).map((c) => `${c.path}=${c.status}`),
      offers: (read.calls || []).filter((c) => /dispatch\/offer/.test(c.path))
        .map((c) => JSON.stringify({ request: c.request, response: c.response }).slice(0, 400)),
      status: String(inv.status || '').slice(0, 200) });
  check(s, width, 'courier: the date question names the day it is offering',
    inv.dateHelpText === read.shell.expectedDayHelp,
    { help: inv.dateHelpText, expected: read.shell.expectedDayHelp });
  check(s, width, 'courier: it is priced as an instant estimate, not sent for review',
    inv.textInstantEstimate >= 1 && inv.textManualReviewRequired === 0
      && read.shell.foreignSentences.length === 0,
    { instantEstimate: inv.textInstantEstimate, review: inv.textManualReviewRequired,
      foreign: read.shell.foreignSentences, quote: read.quote });
  check(s, width, 'courier: no time is selected until one is chosen',
    inv.slotSelected === 0 && inv.dispatchSlotValue === '',
    { selected: inv.slotSelected, value: inv.dispatchSlotValue });
}

/**
 * Courier on a complete request the rules refuse.
 *
 * The distinction the brief asks for, from the other side: this request is finished,
 * so the page must not ask for details that were supplied, and it must not offer a
 * time it cannot honour. It must also not borrow one of the six review sentences —
 * courier's own `review_message` is empty by configuration, and the refusal wording
 * comes from the offer response.
 */
function scoreCourierRefused(s, width, read) {
  const inv = read.inventory;
  check(s, width, 'refused courier: zero pickup times are offered',
    inv.slotButtonsVisible === 0 && (inv.slotAts || []).length === 0,
    { visible: inv.slotButtonsVisible, instants: (inv.slotAts || []).length });
  check(s, width, 'refused courier: it says a person has to review it',
    inv.textManualReviewRequired >= 1 && inv.bookingState === 'manual_review',
    { wording: inv.textManualReviewRequired, state: inv.bookingState });
  check(s, width, 'refused courier: it does not ask again for details that were given',
    inv.textCompleteRequiredDetails === 0,
    { instruction: inv.textCompleteRequiredDetails });
  check(s, width, 'refused courier: nothing claims availability or instant pricing',
    read.shell.falseClaims.length === 0 && inv.textInstantEstimate === 0,
    { claims: read.shell.falseClaims, instantEstimate: inv.textInstantEstimate });
  check(s, width, `refused courier: it borrows no other service's review sentence`,
    read.shell.foreignSentences.length === 0, { foreign: read.shell.foreignSentences });
  check(s, width, 'refused courier: no arrival is carried in dispatch_slot',
    inv.dispatchSlotValue === '', { dispatch_slot: inv.dispatchSlotValue });
}

/**
 * One service's own walk: the shell twice, then the verdict that service is owed.
 *
 * The shell is scored before the date as well as after it, because the second date
 * control this task removes was visible from the moment page 2 rendered — a gate that
 * only looked after a verdict arrived would have been measuring the widget rather than
 * the page. `mode` says which verdict is owed: `bookable`, `review` or `refused`.
 */
function scoreShell(s, width, run, mode = 'review') {
  const service = run.service;
  if (!run.onPage2) {
    check(s, width, `${service}: the pickup-date page was reached`, false,
      { next: run.next, page1: run.page1 });

    return;
  }
  check(s, width, `${service}: every fact this service requires was answered`,
    (run.facts || { complete: true }).complete === true,
    { facts: run.facts });
  scoreSharedShell(s, width, run.beforeDate, `${service} before a date is chosen`);
  scoreSharedShell(s, width, run.afterDate, `${service} with a date chosen`);
  check(s, width, `${service}: the date the customer chose is the date being read`,
    run.afterDate.inventory.preferredServiceDateValue === DATE,
    { value: run.afterDate.inventory.preferredServiceDateValue, typed: run.date });
  check(s, width, `${service}: no pickup times before a date is chosen`,
    run.beforeDate.inventory.slotButtonsVisible === 0,
    { slots: run.beforeDate.inventory.slotButtonsVisible });
  if (mode === 'bookable') {
    scoreCourierBookable(s, width, run.afterDate, DATE);
  }
  else if (mode === 'refused') {
    scoreCourierRefused(s, width, run.afterDate);
  }
  else {
    scoreManualReview(s, width, run.afterDate, service);
  }
}

/**
 * Whether the arrival the page is carrying is one it can still honour.
 *
 * Cleared is the strongest answer and the one the in-page date change gives. Across a
 * wizard round trip the widget adopts an arrival the form carried and re-highlights it
 * once the new offer renders, by design — `adoptExistingSelection()` reads the element
 * and writes nothing — so the property that has to hold there is the one that protects
 * the customer: the arrival on the page is either gone or still on offer for the day
 * being shown. A value that survives while its time is not offered is the defect.
 */
const arrivalIsHonest = (read) => {
  const inv = read.inventory;
  const value = String(inv.dispatchSlotValue || '');
  const offered = (inv.slotAts || []).map(String);
  if (value === '') {
    return { ok: true, why: 'cleared', selected: inv.slotSelected, offered: offered.length };
  }

  return { ok: offered.includes(value), why: offered.includes(value) ? 'still-offered' : 'stale',
    value, selected: inv.slotSelected, offered: offered.length };
};

/** Scenario `courier-clears` — a selection must not outlive the request it belongs to. */
function scoreClears(width, run) {
  const s = 'courier-clears';
  if (!run.onPage2) {
    check(s, width, 'the pickup-date page was reached', false,
      { next: run.next, page1: run.page1 });

    return;
  }
  check(s, width, `a time could be selected on ${DATE}`,
    run.picked.ok === true && String(run.held.inventory.dispatchSlotValue || '') !== '',
    { picked: run.picked, value: run.held.inventory.dispatchSlotValue,
      selected: run.held.inventory.slotSelected });

  const afterDate = run.afterDate.inventory;
  const newDays = localDates(afterDate.slotAts, run.afterDate.shell.publishedTimezone);
  check(s, width, 'a date change clears the selected time outright',
    String(afterDate.dispatchSlotValue || '') === '' && afterDate.slotSelected === 0,
    { value: afterDate.dispatchSlotValue, selected: afterDate.slotSelected,
      settled: run.settledSecond });
  check(s, width, `a date change offers ${DATE_2}'s times and only those`,
    afterDate.slotButtonsVisible > 0 && afterDate.slotButtonsUnbookable === 0
      && newDays.length === 1 && newDays[0] === DATE_2,
    { slots: afterDate.slotButtonsVisible, unbookable: afterDate.slotButtonsUnbookable,
      days: newDays });
  check(s, width, `a date change asks the planner about ${DATE_2}`,
    (run.afterDate.calls || []).some((c) => /dispatch\/offer/.test(c.path)
      && c.request && c.request.date === DATE_2),
    { offers: (run.afterDate.calls || []).filter((c) => /dispatch\/offer/.test(c.path))
      .map((c) => `${(c.request || {}).date}=${c.status}`) });

  [['weight', run.weightTrip, run.afterWeight, run.reselectedForWeight],
    ['mode', run.modeTrip, run.afterMode, run.reselectedForMode]].forEach(
    ([what, trip, read, reselect]) => {
      check(s, width, `a ${what} change was made and the pickup-date page came back`,
        trip.onPage2 === true && reselect.ok === true,
        { back: trip.back, next: trip.next, onPage2: trip.onPage2, reselect });
      if (trip.onPage2 !== true) {
        return;
      }
      const honest = arrivalIsHonest(read);
      check(s, width, `after a ${what} change no arrival survives that is not on offer`,
        honest.ok === true, honest);
      check(s, width, `after a ${what} change the highlight and the stored arrival agree`,
        read.inventory.slotSelected === (honest.why === 'still-offered' ? 1 : 0),
        { selected: read.inventory.slotSelected, arrival: honest });
      check(s, width, `after a ${what} change availability was asked for again`,
        offerRequests(read).length >= 1,
        { offers: offerRequests(read).length, state: read.inventory.bookingState });
      scoreSharedShell(s, width, read, `courier after a ${what} change`);
    });
}

/**
 * Scenario `switch:<service>` — Courier's state must not follow the customer.
 *
 * Three claims, in the order a customer meets them. The Manual Review service they
 * switched to shows its own shell, its own sentence and no times, with no Courier
 * arrival left behind. Picking Courier again does not by itself bring times back,
 * because the answers Courier needs went with the other service's branch. Restoring
 * those answers does bring them back, in full.
 *
 * The third claim has two truthful endings and the request decides which. Five of the
 * six services leave nothing behind that Courier's eligibility policy objects to, so
 * the times come back. `on_demand_delivery` asks for `vehicle_helper_needs`, and every
 * value in that select is a declared helper need that
 * `manual_review.vehicle_helper_needs` refuses for a single-driver Courier job — the
 * answer is still in the document after the switch back, because `#states` hides a
 * control without clearing it, so the offer request still carries it and the planner
 * still refuses it. Zero times and the sentence naming the visitor's own two-person
 * answer is the correct behaviour there, and it is scored as such rather than excused:
 * the branch is chosen by reading the carried answer, not by naming the service.
 *
 * That carry is worth reporting on its own — a customer who once priced a same-day
 * delivery cannot self-book Courier again in that session, and the control that decides
 * it is no longer on their screen. Changing it would mean changing Courier eligibility,
 * which this task explicitly excludes, so it is recorded and not touched here.
 */
function scoreSwitch(width, run) {
  const service = run.service;
  const s = `switch:${service}`;
  if (!run.onPage2) {
    check(s, width, 'courier reached the pickup-date page', false,
      { next: run.next, page1: run.page1 });

    return;
  }
  check(s, width, 'a courier time was selected before the switch',
    run.picked.ok === true && String(run.held.inventory.dispatchSlotValue || '') !== ''
      && run.held.inventory.slotButtonsVisible === EXPECTED_SLOTS,
    { picked: run.picked, value: run.held.inventory.dispatchSlotValue,
      slots: run.held.inventory.slotButtonsVisible });
  check(s, width, `the switch to ${service} reached the pickup-date page again`,
    run.switch.onPage2 === true && (run.switch.facts || { complete: true }).complete === true,
    { back: run.switch.back, next: run.switch.next, facts: run.switch.facts });
  if (run.switch.onPage2 !== true) {
    return;
  }
  const after = run.afterSwitch.inventory;
  check(s, width, 'the courier arrival, its highlight and its price are all gone',
    String(after.dispatchSlotValue || '') === '' && after.slotSelected === 0
      && after.slotButtonsVisible === 0 && after.textInstantEstimate === 0,
    { value: after.dispatchSlotValue, selected: after.slotSelected,
      slots: after.slotButtonsVisible, instantEstimate: after.textInstantEstimate });
  scoreSharedShell(s, width, run.afterSwitch, `${service} after the switch`);
  scoreManualReview(s, width, run.afterSwitch, service);

  check(s, width, 'picking courier again is not enough on its own',
    run.bare.back.onPage1 === true && run.bare.radio.checked === true
      && run.bare.onPage2 !== true,
    { back: run.bare.back.onPage1, radio: run.bare.radio,
      next: run.bare.next, onPage2: run.bare.onPage2,
      messages: (run.bare.messages || {}).error || run.bare.messages });
  check(s, width, `courier's own answers were restored and the page came back`,
    run.restore.onPage2 === true,
    { page1: run.restore.page1, next: run.restore.next });
  if (run.restore.onPage2 !== true) {
    return;
  }
  const carried = String((run.restored.declared || {}).vehicleHelperNeeds || '');
  if (carried === '') {
    check(s, width, 'the times came back only once the request was valid again',
      run.restored.inventory.slotButtonsVisible === EXPECTED_SLOTS,
      { slots: run.restored.inventory.slotButtonsVisible, expected: EXPECTED_SLOTS,
        state: run.restored.inventory.bookingState });
    scoreSharedShell(s, width, run.restored, 'courier after switching back');
    scoreCourierBookable(s, width, run.restored, DATE);

    return;
  }

  // The other service's declared helper need is still in the document, so the request
  // that came back is not a valid Courier request and no time may be offered for it.
  // Scored as the refusal it is, in the visitor's own words, rather than as the
  // reappearance the other five services owe.
  check(s, width, 'the times stayed away while a helper need is still declared',
    run.restored.inventory.slotButtonsVisible === 0
      && run.restored.inventory.bookingState === 'manual_review',
    { slots: run.restored.inventory.slotButtonsVisible,
      state: run.restored.inventory.bookingState, declared: run.restored.declared });
  check(s, width, 'the refusal repeats the answer the visitor gave',
    /two people to carry/.test(String(run.restored.inventory.status || '')),
    { status: String(run.restored.inventory.status || '').slice(0, 200) });
  check(s, width, 'no arrival survived into the refused request',
    run.restored.inventory.dispatchSlotValue === '',
    { dispatch_slot: run.restored.inventory.dispatchSlotValue });
  scoreSharedShell(s, width, run.restored, 'courier after switching back');
}

// ---------------------------------------------------------------------------
// Scenarios
// ---------------------------------------------------------------------------

/**
 * Every scenario, at every width. The ids are the `SHELL_ONLY` vocabulary.
 *
 * One session per scenario per width and no sharing between them, because the claim
 * for six of the seven services is about what a *new* customer is shown, and a session
 * that had already been walked through Courier could show a cleared leftover as a pass.
 * The switch scenarios are the ones that deliberately carry state, and they say so.
 */
const SCENARIOS = [
  { id: `shell:${SELF_BOOKABLE}`, label: 'Express Courier, Send — the self-bookable control',
    walk: (w) => walkShell(w, SELF_BOOKABLE),
    score: (w, r) => scoreShell(`shell:${SELF_BOOKABLE}`, w, r, 'bookable') },
  ...MANUAL_SERVICES.map((service) => ({
    id: `shell:${service}`, label: `${service} — Manual Review`,
    walk: (w) => walkShell(w, service),
    score: (w, r) => scoreShell(`shell:${service}`, w, r, 'review'),
  })),
  { id: 'courier-store', label: 'Express Courier, Store Pickup',
    walk: (w) => walkShell(w, SELF_BOOKABLE, { mode: 'courier_store_pickup' }),
    score: (w, r) => scoreShell('courier-store', w, r, 'bookable') },
  { id: 'courier-refused', label: `Express Courier, complete but refused at ${REFUSED_WEIGHT} lb`,
    walk: (w) => walkShell(w, SELF_BOOKABLE, { weight: REFUSED_WEIGHT, expectSlots: false }),
    score: (w, r) => scoreShell('courier-refused', w, r, 'refused') },
  { id: 'courier-clears', label: 'Express Courier — date, weight and mode changes',
    walk: walkCourierClears, score: scoreClears },
  ...MANUAL_SERVICES.map((service) => ({
    id: `switch:${service}`, label: `Courier with a time selected → ${service} → Courier`,
    walk: (w) => walkSwitch(w, service),
    score: scoreSwitch,
  })),
];

function describe() {
  console.log('Task 10D — shared pickup-date shell gate');
  console.log(`  form              ${FORM_URL}`);
  console.log(`  services          ${ALL_SERVICES.length} (${SELF_BOOKABLE} self-bookable, `
    + `${MANUAL_SERVICES.length} manual review)`);
  console.log(`  dates             ${DATE}, then ${DATE_2} for the clearing walk`);
  console.log(`                    from ${OPEN.source}`);
  console.log(`  courier slots     ${EXPECTED_SLOTS} expected on an eligible request`);
  console.log(`  refused weight    ${REFUSED_WEIGHT} lb`);
  console.log(`  artifacts         ${ARTIFACTS}/${STAMP}-10d-*.png`);
  if (ONLY) {
    console.log(`  SHELL_ONLY        ${ONLY}`);
  }
  if (ONE_WIDTH) {
    console.log(`  SHELL_WIDTH       ${ONE_WIDTH}`);
  }
  console.log('');
}

async function main() {
  describe();
  const wanted = ONLY ? ONLY.split(',').map((v) => v.trim()).filter(Boolean) : [];
  const chosen = SCENARIOS.filter((s) => !wanted.length || wanted.includes(s.id));
  if (!chosen.length) {
    console.log(`SHELL_ONLY=${ONLY} selected no scenario; ids are `
      + SCENARIOS.map((s) => s.id).join(', '));
    process.exitCode = 2;

    return;
  }
  const widths = WIDTHS.filter((w) => !ONE_WIDTH || w.label === ONE_WIDTH);
  if (!widths.length) {
    console.log(`SHELL_WIDTH=${ONE_WIDTH} selected no width; labels are `
      + WIDTHS.map((w) => w.label).join(', '));
    process.exitCode = 2;

    return;
  }

  for (const width of widths) {
    console.log(`--- ${width.label} ${width.width}x${width.height} ---`);
    for (const scenario of chosen) {
      console.log(`  ${scenario.id}: ${scenario.label}`);
      // Where this scenario's rows begin, so the print covers every assertion it
      // recorded whatever id the scorer filed them under.
      const from = results.length;
      try {
        const run = await scenario.walk(width);
        scenario.score(width, run);
      }
      catch (error) {
        // A crash is a failing scenario, not a failing run: the other services and
        // the other width still have something to say.
        check(scenario.id, width, `${scenario.id}: the walk completed`, false,
          { error: String((error && error.stack) || error).slice(0, 400) });
        if (error && error.partial) {
          console.log(`    stopped after: ${Object.keys(error.partial).join(', ')}`);
          console.log(`    ${JSON.stringify(error.partial).slice(0, 1200)}`);
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
    const badAtWidth = atWidth.filter((r) => !r.pass).length;
    console.log(`  ${width.label}: ${badAtWidth === 0 ? 'GREEN' : 'RED'} `
      + `${atWidth.length - badAtWidth}/${atWidth.length}\n`);
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
