// Cause matrix: walks the public Express Courier form once per candidate answer
// set on the same disputed date and records which single answer flips the widget
// into the screenshot's state — `Manual review required — staff will price this
// request.` with no pickup times — while every other answer stays constant.
//
// Each variant is a COMPLETE request except where the variant's whole point is
// incompleteness, so a Manual Review verdict here means the request was judged
// ineligible, never that a field was still blank.
//
// The distance-preview throttle is cleared between variants: it is keyed per
// client IP per hour, and one poisoned variant would otherwise report an empty
// price line as if it were the widget's verdict.
//
// Read-only with respect to the business: no final submit, no Hold, no checkout.
//
// Usage: ddev exec node qa/browser/courier-cause-matrix.mjs [YYYY-MM-DD]
import { execSync } from 'node:child_process';
import { Browser } from './webdriver.mjs';
import {
  FORM_URL, fillPage1, nextPage, messages, inventory, setDateField,
  requestNames, armBookingState, armNetworkCapture, capturedCalls, resetCapture,
  onPage2,
} from './courier-flow.mjs';

const DATE = process.argv[2] && /^\d{4}-\d{2}-\d{2}$/.test(process.argv[2])
  ? process.argv[2]
  : '2026-09-01';
const ARTIFACTS = process.env.COURIER_ARTIFACTS || 'qa/artifacts';
const STAMP = new Date().toISOString().replace(/[:.]/g, '-').slice(0, 19);
const TERMINAL = ['available', 'unavailable', 'manual_review', 'waiting'];

// Every variant differs from `eligible` in exactly one answer. `undefined`
// skips a choice control and `null` skips a text control, and both are recorded
// as skipped rather than silently filled.
const VARIANTS = [
  { name: 'incomplete-details',
    why: 'date chosen, quote inputs never supplied',
    overrides: { total_weight_lbs: null, courier_prohibited_ack: undefined,
      single_driver_handling: undefined } },
  { name: 'eligible',
    why: 'complete, 10 lb, one-person carry, contents attested',
    overrides: {} },
  { name: 'single-driver-no',
    why: 'complete, but customer answered "it needs two people"',
    overrides: { single_driver_handling: '0' } },
  { name: 'contents-restricted',
    why: 'complete, but contents declared restricted',
    overrides: { prohibited_items_ack: 'restricted' } },
  { name: 'contents-unattested',
    why: 'complete, but the attestation checkbox is left unchecked',
    overrides: { courier_prohibited_ack: undefined } },
  { name: 'overweight',
    why: 'complete, but 45 lb against a 30 lb self-service limit',
    overrides: { total_weight_lbs: '45' } },
];

const settledAfter = (browser, baseline) => async () => {
  const snap = await inventory(browser);
  if (snap.dispatchBusy || snap.bookingStateChanges <= baseline) {
    return null;
  }
  return TERMINAL.includes(snap.bookingState) ? snap : null;
};

/** Clears the per-IP preview throttle so each variant sees a real quote. */
function clearPreviewThrottle() {
  try {
    execSync('vendor/bin/drush php:script qa/support/reset-preview-flood.php',
      { stdio: 'pipe' });
    return 'cleared';
  }
  catch (error) {
    return `failed:${String(error.message || '').slice(0, 80)}`;
  }
}

/** Maps each offered Slot's absolute instant to its New York calendar date. */
const localDates = (ats) => {
  const fmt = new Intl.DateTimeFormat('en-CA',
    { timeZone: 'America/New_York', year: 'numeric', month: '2-digit', day: '2-digit' });
  return Array.from(new Set(ats.map((at) => fmt.format(new Date(at * 1000)))));
};

async function walk(browser, variant) {
  const throttle = clearPreviewThrottle();
  await browser.get(`${FORM_URL}?cb=${Date.now()}`);
  await browser.consoleLog();
  const filled = await fillPage1(browser, variant.overrides);
  const nav = await nextPage(browser);
  const msgs = await messages(browser);
  // The wizard's Next always "clicks"; whether it advanced is a separate fact.
  // A variant the form itself refuses to carry forward is evidence about which
  // states a real customer can reach, so it is reported, not retried.
  if (!nav.ok || !(await onPage2(browser))) {
    return { variant, throttle, blocked: true, nav, filled, messages: msgs };
  }
  await armBookingState(browser);
  await armNetworkCapture(browser);
  await resetCapture(browser);

  const before = await inventory(browser);
  const chosen = await setDateField(browser, 'preferred_service_date', DATE);
  let after = before;
  try {
    after = await browser.until(`a verdict for ${DATE}`,
      settledAfter(browser, before.bookingStateChanges), { timeout: 25000 });
  }
  catch {
    after = await inventory(browser);
  }
  // The captured response body is filled in one microtask after the fetch
  // resolves, so give the capture a beat before reading it.
  await new Promise((resolve) => setTimeout(resolve, 600));

  const shot = `${ARTIFACTS}/${STAMP}-cause-${variant.name}.png`;
  await browser.screenshot(shot);
  const calls = await capturedCalls(browser);

  return {
    variant,
    throttle,
    blocked: false,
    filled,
    messages: msgs,
    chosen,
    calls,
    requests: Array.from(new Set(await requestNames(browser))),
    dom: {
      bookingState: after.bookingState,
      status: (after.status || '').trim(),
      price: (after.priceText || '').trim(),
      dateHelp: (after.dateHelpText || '').trim(),
      slots: after.slotButtonsVisible,
      slotsUnbookable: after.slotButtonsUnbookable,
      slotDates: localDates(after.slotAts || []),
      manualReviewWording: after.textManualReviewRequired,
      staffWillPriceWording: after.textStaffWillPrice,
      confirmedAfterStaffReview: after.textConfirmedAfterStaffReview,
      completeRequiredDetails: after.textCompleteRequiredDetails,
      selectDateToSeeTimes: after.textSelectDateToSeeTimes,
      instantEstimate: after.textInstantEstimate,
    },
    console: (await browser.consoleLog())
      .filter((e) => e.level === 'SEVERE' && !/favicon|net::ERR_/i.test(e.message || ''))
      .map((e) => String(e.message).slice(0, 160)),
    shot,
  };
}

async function main() {
  const browser = await Browser.open();
  const runs = [];
  try {
    await browser.viewport(1440, 900);
    for (const variant of VARIANTS) {
      runs.push(await walk(browser, variant));
    }
  }
  finally {
    await browser.quit();
  }

  console.log(`Express Courier cause matrix — ${FORM_URL}`);
  console.log(`Date under test: ${DATE}\n`);

  runs.forEach((run) => {
    console.log(`=== ${run.variant.name} — ${run.variant.why} ===`);
    console.log(`  preview throttle: ${run.throttle}`);
    const answered = Object.entries(run.filled || {})
      .map(([k, v]) => `${k}=${v.ok ? JSON.stringify(v.value) : (v.reason || 'no')}`
        + (v.ok && v.visible === false ? '(hidden)' : ''));
    console.log(`  page-1 answers: ${answered.join(' ')}`);
    if (run.blocked) {
      console.log('  BLOCKED: the wizard did not advance to the widget page');
      console.log(`  messages: ${JSON.stringify(run.messages)}`);
      console.log('');
      return;
    }
    Object.entries(run.dom).forEach(([k, v]) => console.log(`  ${k}: ${JSON.stringify(v)}`));
    run.calls.forEach((call) => {
      console.log(`  ${call.method} ${call.path} → ${call.status}`);
      if (/dispatch\/offer/.test(call.path)) {
        console.log(`    request.values: ${JSON.stringify(call.request && call.request.values)}`);
        console.log(`    request.date:   ${JSON.stringify(call.request && call.request.date)}`);
      }
      console.log(`    response: ${JSON.stringify(call.response)}`);
    });
    if (!run.calls.some((c) => /dispatch\/offer/.test(c.path))) {
      console.log('  NO offer request was sent');
    }
    console.log(`  requests: ${JSON.stringify(run.requests)}`);
    console.log(`  console errors: ${JSON.stringify(run.console)}`);
    console.log(`  screenshot: ${run.shot}\n`);
  });

  console.log('=== which variants reproduce the screenshot ===');
  runs.forEach((run) => {
    if (run.blocked) {
      console.log(`  ${run.variant.name}: blocked before page 2`);
      return;
    }
    const screenshotState = run.dom.staffWillPriceWording > 0 && run.dom.slots === 0;
    console.log(`  ${run.variant.name}: ${screenshotState ? 'REPRODUCES' : 'does not reproduce'}`
      + ` (state=${run.dom.bookingState}, slots=${run.dom.slots},`
      + ` price=${JSON.stringify(run.dom.price)})`);
  });
}

await main();
