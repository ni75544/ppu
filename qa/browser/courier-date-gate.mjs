// Red-capable browser gate for the Express Courier date/pickup-time defect.
//
// Scored against the required Courier UI: ONE public date field
// (`preferred_service_date`), one "Choose your pickup time" list beneath it, and
// no second date selector anywhere. It is red on a site that still ships the
// Dispatch widget's own `Pickup date`, which is the point — an assertion set that
// cannot fail proves nothing.
//
// Read-only with respect to the business: it walks page 1 to page 2, selects a
// date, may select an arrival (advisory), and never presses a final submit,
// never starts checkout, and never asks for a Hold.
//
// Usage: ddev exec node qa/browser/courier-date-gate.mjs [--strict-driver]
import { Browser } from './webdriver.mjs';
import {
  FORM_URL, SAMPLE, fillPage1, nextPage, messages, inventory, setDateField,
  requestNames, armBookingState,
} from './courier-flow.mjs';

const CANONICAL = 'preferred_service_date';
const NY = 'America/New_York';
const WIDTHS = [
  { label: 'desktop', width: 1440, height: 900 },
  { label: 'mobile', width: 390, height: 844 },
];
const STRICT_DRIVER = process.argv.includes('--strict-driver');
const ARTIFACTS = process.env.COURIER_ARTIFACTS || 'qa/artifacts';
const STAMP = new Date().toISOString().replace(/[:.]/g, '-').slice(0, 19);

const dateFmt = new Intl.DateTimeFormat('en-CA', {
  timeZone: NY, year: 'numeric', month: '2-digit', day: '2-digit',
});
const dayFmt = new Intl.DateTimeFormat('en-US', { timeZone: NY, weekday: 'short' });
const timeFmt = new Intl.DateTimeFormat('en-GB', {
  timeZone: NY, hour: '2-digit', minute: '2-digit',
});

const nyDate = (ms) => dateFmt.format(new Date(ms));
const nyTime = (ms) => timeFmt.format(new Date(ms));
const nyDay = (ms) => dayFmt.format(new Date(ms));

/**
 * The next operating dates: Monday–Wednesday only, inside the 30-day horizon,
 * starting tomorrow. The active profile is the only source of truth used here;
 * nothing about capacity, buffers, or blackouts is assumed.
 */
function operatingDates(count) {
  const [y, m, d] = nyDate(Date.now()).split('-').map(Number);
  const found = [];
  for (let offset = 1; offset <= 30 && found.length < count; offset += 1) {
    const ms = Date.UTC(y, m - 1, d + offset, 12);
    if (['Mon', 'Tue', 'Wed'].includes(nyDay(ms))) {
      found.push(nyDate(ms));
    }
  }
  return found;
}

function scoreboard() {
  const rows = [];
  return {
    rows,
    check(name, pass, detail) {
      rows.push({ name, pass: pass === true, detail });
      return pass === true;
    },
    record(name, detail) {
      rows.push({ name, pass: null, detail });
    },
    get failed() {
      return rows.filter((r) => r.pass === false);
    },
  };
}

/** Console entries that are genuine page errors, not network noise from probes. */
const isPageError = (entry) => entry.level === 'SEVERE'
  && !/favicon|net::ERR_|Failed to load resource/i.test(entry.message || '');

async function runWidth(browser, profile, dates) {
  const board = scoreboard();
  const artifacts = [];
  const consoleEntries = [];
  const viewport = await browser.viewport(profile.width, profile.height);

  await browser.get(`${FORM_URL}?cb=${Date.now()}`);
  await browser.consoleLog();
  const filled = await fillPage1(browser);
  // Measured on page 1, where the element lives. Page 2 reports zero for it
  // whether or not a Courier customer was made to answer it, so scoring it there
  // would have been an assertion that cannot fail.
  const page1 = await inventory(browser);
  const nav = await nextPage(browser);
  const msgs = await messages(browser);
  board.record('page-1-walk', {
    viewport, next: nav, messages: msgs,
    single_driver_handling: filled.single_driver_handling,
  });
  if (!nav.ok) {
    board.check('reached-page-2', false, nav);
    return { profile, board, artifacts, consoleEntries };
  }
  board.check('reached-page-2', true, { messages: msgs });
  await armBookingState(browser);

  // ---- State A: page 2 rendered, no date chosen yet. ----
  const before = await inventory(browser);
  artifacts.push(await browser.screenshot(`${ARTIFACTS}/${STAMP}-${profile.label}-1-before-date.png`));

  board.check('visible-enabled-date-inputs-is-1',
    before.visibleEnabledDateInputs === 1,
    { count: before.visibleEnabledDateInputs, names: before.visibleDateInputNames });
  board.check(`canonical-date-input-is-${CANONICAL}`,
    before.visibleDateInputNames.length === 1 && before.visibleDateInputNames[0] === CANONICAL,
    { names: before.visibleDateInputNames });
  board.check('text-preferred-service-date-is-1',
    before.textPreferredServiceDate === 1, { count: before.textPreferredServiceDate });
  board.check('text-choose-your-pickup-time-is-1',
    before.textChoosePickupTime === 1, { count: before.textChoosePickupTime });
  board.check('text-times-shown-in-new-york-local-time-is-1',
    before.textTimezoneNote === 1, { count: before.textTimezoneNote });
  board.check('visible-preferred-service-time-controls-is-0',
    before.preferredServiceTimeVisible === 0 && before.textPreferredServiceTime === 0,
    { controls: before.preferredServiceTimeVisible, text: before.textPreferredServiceTime });
  board.check('visible-dispatch-pickup-date-controls-is-0',
    before.dispatchWidgetDateVisible === 0 && before.textPickupDate === 0,
    { controls: before.dispatchWidgetDateVisible, text: before.textPickupDate,
      inDom: before.dispatchWidgetDateInputs });
  board.check('visible-legacy-reservation-widgets-is-0',
    before.legacyReservationWidgets === 0, { count: before.legacyReservationWidgets });
  board.check('slot-buttons-before-date-is-0',
    before.slotButtonsVisible === 0,
    { count: before.slotButtonsVisible, status: before.status.trim() });
  board.record('single-driver-handling-visible', {
    page: 1, count: page1.singleDriverVisible,
    answered: filled.single_driver_handling,
  });
  if (STRICT_DRIVER) {
    board.check('visible-single-driver-handling-controls-is-0',
      page1.singleDriverVisible === 0, { page: 1, count: page1.singleDriverVisible });
  }

  // ---- State B: an eligible Monday–Wednesday date is chosen. ----
  // "Settled" is the widget's own published verdict, not a status sentence the
  // gate recognises. Matching on wording was a defect in this harness: the
  // first-fetch line ("Finding available arrival times…") and the refresh line
  // ("Updating the arrival times we can offer…") are different strings, and the
  // debounce ahead of a refresh shows the second one while aria-busy is still
  // down — so both a wording check and a bare busy check accept a snapshot taken
  // before the server was asked, and score the empty list as the answer.
  //
  // Waiting for a state change past a baseline is what makes it an answer to
  // *this* date rather than whatever verdict was already on screen.
  const TERMINAL = ['available', 'unavailable', 'manual_review'];
  const settledAfter = (baseline) => async () => {
    const snap = await inventory(browser);
    if (snap.dispatchBusy || snap.bookingStateChanges <= baseline) {
      return null;
    }
    return TERMINAL.includes(snap.bookingState) ? snap : null;
  };
  const baselineA = before.bookingStateChanges;
  const chosen = await setDateField(browser, CANONICAL, dates[0]);
  board.check('canonical-date-input-accepts-a-date', chosen.ok === true,
    { requested: dates[0], result: chosen });
  let after = before;
  if (chosen.ok) {
    try {
      after = await browser.until(`offers for ${dates[0]}`, settledAfter(baselineA), { timeout: 25000 });
    }
    catch {
      after = await inventory(browser);
    }
  }
  artifacts.push(await browser.screenshot(`${ARTIFACTS}/${STAMP}-${profile.label}-2-after-date.png`));

  const onDate = (ats, day) => ats.every((at) => nyDate(at * 1000) === day);
  board.check('slot-buttons-after-eligible-date-is-positive',
    after.slotButtonsVisible > 0,
    { date: dates[0], weekday: nyDay(Date.parse(`${dates[0]}T12:00:00Z`)),
      count: after.slotButtonsVisible, status: (after.status || '').trim(),
      bookingState: after.bookingState });
  board.check('every-rendered-slot-is-bookable',
    after.slotButtonsVisible > 0 && after.slotButtonsUnbookable === 0,
    { rendered: after.slotButtonsVisible, unbookable: after.slotButtonsUnbookable });
  board.check('rendered-slots-all-fall-on-the-selected-date',
    after.slotAts.length > 0 && onDate(after.slotAts, dates[0]),
    { date: dates[0], sample: after.slotAts.slice(0, 3).map((at) => `${nyDate(at * 1000)} ${nyTime(at * 1000)}`),
      distinctDates: Array.from(new Set(after.slotAts.map((at) => nyDate(at * 1000)))) });
  board.check('still-exactly-one-visible-date-input-after-offers',
    after.visibleEnabledDateInputs === 1 && after.dispatchWidgetDateVisible === 0,
    { count: after.visibleEnabledDateInputs, widget: after.dispatchWidgetDateVisible });

  // ---- State C: an arrival is selected, then the date is changed. ----
  const picked = await browser.js(`
    const btn = Array.from(document.querySelectorAll('.custom-webform-dispatch__slot'))
      .find((el) => el.offsetParent !== null);
    if (!btn) { return { ok: false, reason: 'no-slot-rendered' }; }
    btn.click();
    return { ok: true, at: Number(btn.getAttribute('data-at')) };
  `);
  let held = after;
  if (picked.ok) {
    try {
      held = await browser.until('the arrival to be recorded', async () => {
        const snap = await inventory(browser);
        return snap.dispatchSlotValue ? snap : null;
      }, { timeout: 6000 });
    }
    catch {
      held = await inventory(browser);
    }
  }
  board.check('selecting-an-arrival-records-it-in-dispatch_slot',
    picked.ok === true && held.dispatchSlotValue === String(picked.at),
    { clicked: picked, stored: held.dispatchSlotValue });

  const baselineB = held.bookingStateChanges;
  const rechosen = await setDateField(browser, CANONICAL, dates[1]);
  let cleared = held;
  try {
    cleared = await browser.until('the previous arrival to be dropped', async () => {
      const snap = await inventory(browser);
      return (snap.dispatchSlotValue === '' && snap.slotSelected === 0) ? snap : null;
    }, { timeout: 8000 });
  }
  catch {
    cleared = await inventory(browser);
  }
  board.check('changing-the-date-clears-the-selected-arrival',
    rechosen.ok === true && cleared.dispatchSlotValue === '' && cleared.slotSelected === 0,
    { date: dates[1], stored: cleared.dispatchSlotValue, selected: cleared.slotSelected });

  let rebound = cleared;
  try {
    rebound = await browser.until(`offers for ${dates[1]}`, settledAfter(baselineB), { timeout: 25000 });
  }
  catch {
    rebound = await inventory(browser);
  }
  artifacts.push(await browser.screenshot(`${ARTIFACTS}/${STAMP}-${profile.label}-3-after-date-change.png`));
  board.check('after-a-date-change-only-the-new-dates-times-render',
    rebound.slotButtonsVisible > 0 && onDate(rebound.slotAts, dates[1])
      && rebound.slotButtonsUnbookable === 0,
    { date: dates[1], count: rebound.slotButtonsVisible,
      distinctDates: Array.from(new Set(rebound.slotAts.map((at) => nyDate(at * 1000)))),
      unbookable: rebound.slotButtonsUnbookable });

  // ---- Environment: nothing broken, nothing bought. ----
  consoleEntries.push(...(await browser.consoleLog()));
  const errors = consoleEntries.filter(isPageError)
    .map((e) => String(e.message).slice(0, 200));
  board.check('no-console-errors', errors.length === 0, { errors });
  board.check('no-native-validation-bubbles',
    rebound.nativeInvalidVisible === 0, { count: rebound.nativeInvalidVisible });
  board.check('no-horizontal-overflow',
    rebound.documentScrollWidth <= rebound.innerWidth + 1,
    { scrollWidth: rebound.documentScrollWidth, innerWidth: rebound.innerWidth });

  const names = await requestNames(browser);
  const forbidden = names.filter((n) => /hold|reserve|reservation|checkout|payment|renew|release/i.test(n));
  const countdown = await browser.js(`
    return /(expires in|time remaining|held for you|minutes left|seconds left)/i
      .test(document.body.innerText);
  `);
  board.check('no-hold-token-countdown-reservation-checkout-or-payment',
    forbidden.length === 0 && countdown === false,
    { forbiddenRequests: forbidden, countdownVisible: countdown });
  board.record('dispatch-requests', { names: Array.from(new Set(names)) });

  return { profile, board, artifacts, consoleEntries };
}

async function main() {
  const dates = operatingDates(2);
  if (dates.length < 2) {
    throw new Error('The active profile offers fewer than two Mon–Wed dates inside its horizon.');
  }
  const browser = await Browser.open();
  const runs = [];
  try {
    for (const profile of WIDTHS) {
      runs.push(await runWidth(browser, profile, dates));
    }
  }
  finally {
    await browser.quit();
  }

  let failed = 0;
  console.log(`Express Courier date gate — ${FORM_URL}`);
  console.log(`Sample: ${SAMPLE.service_type}/${SAMPLE.delivery_mode}, ${SAMPLE.total_weight_lbs} lb`);
  console.log(`Dates: ${dates[0]} (${nyDay(Date.parse(dates[0] + 'T12:00:00Z'))}) then ${dates[1]} (${nyDay(Date.parse(dates[1] + 'T12:00:00Z'))}), ${NY}`);
  for (const run of runs) {
    console.log(`\n=== ${run.profile.label} ${run.profile.width}x${run.profile.height} ===`);
    for (const row of run.board.rows) {
      const mark = row.pass === null ? 'info' : (row.pass ? 'PASS' : 'FAIL');
      if (row.pass === false) {
        failed += 1;
      }
      console.log(`  [${mark}] ${row.name}  ${JSON.stringify(row.detail)}`);
    }
    for (const shot of run.artifacts) {
      console.log(`  [shot] ${shot}`);
    }
  }
  const asserted = runs.reduce((n, r) => n + r.board.rows.filter((x) => x.pass !== null).length, 0);
  console.log(`\n${asserted - failed}/${asserted} assertions green across ${runs.length} widths.`);
  if (failed > 0) {
    console.log('GATE: RED');
    process.exitCode = 1;
    return;
  }
  console.log('GATE: GREEN');
}

await main();
