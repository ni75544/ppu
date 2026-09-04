// Diagnosis harness: names the field(s) that put a valid Express Courier request
// on a valid Tuesday into the Manual Review state, from captured evidence.
//
// Not a test suite and not a gate. It walks the same public form twice on the
// same date and records, for each walk, the redacted visible answers, the exact
// `/dispatch/offer/hg` request body, the server's response, and the widget's
// rendered price/status/help text. The two walks differ in exactly one thing —
// whether the short-lived wizard context the quote behavior stores on page 1 is
// present when page 2 asks for offers — so the payload diff attributes the
// difference to fields rather than to a hunch.
//
// Read-only with respect to the business: no final submit, no Hold, no checkout.
//
// Usage: ddev exec node qa/browser/courier-state-diagnosis.mjs [YYYY-MM-DD]
import { Browser } from './webdriver.mjs';
import {
  FORM_URL, fillPage1, nextPage, messages, inventory, setDateField,
  requestNames, armBookingState, armNetworkCapture, capturedCalls, resetCapture,
  clearWizardContext,
} from './courier-flow.mjs';

const DATE = process.argv[2] && /^\d{4}-\d{2}-\d{2}$/.test(process.argv[2])
  ? process.argv[2]
  : '2026-09-01';
const ARTIFACTS = process.env.COURIER_ARTIFACTS || 'qa/artifacts';
const STAMP = new Date().toISOString().replace(/[:.]/g, '-').slice(0, 19);
const TERMINAL = ['available', 'unavailable', 'manual_review', 'waiting'];

/** Every answer the form currently carries, machine names kept, values redacted. */
function visibleAnswers(browser) {
  return browser.js(`
    const SECRETISH = /address|street|city|postal|zip|name|email|mail|phone|tel|token/i;
    const shown = (el) => {
      if (!el) { return false; }
      const cs = getComputedStyle(el);
      if (cs.visibility === 'hidden' || cs.display === 'none') { return false; }
      if (el.type === 'hidden') { return false; }
      const r = el.getBoundingClientRect();
      if (r.width === 0 && r.height === 0) { return false; }
      return el.offsetParent !== null || cs.position === 'fixed';
    };
    const out = {};
    Array.from(document.querySelectorAll('[name]')).forEach((el) => {
      const key = String(el.name || '').split('[')[0];
      if (!key) { return; }
      if ((el.type === 'checkbox' || el.type === 'radio') && !el.checked) {
        if (!(key in out)) { out[key] = { visible: shown(el), value: null }; }
        return;
      }
      const raw = el.value == null ? '' : String(el.value);
      out[key] = {
        visible: shown(el),
        type: el.type || el.tagName.toLowerCase(),
        value: SECRETISH.test(key) && raw !== '' ? ('<redacted:' + raw.length + '>') : raw,
      };
    });
    return out;
  `);
}

const settledAfter = (browser, baseline) => async () => {
  const snap = await inventory(browser);
  if (snap.dispatchBusy || snap.bookingStateChanges <= baseline) {
    return null;
  }
  return TERMINAL.includes(snap.bookingState) ? snap : null;
};

/**
 * One walk. `dropContext` is the single deliberate difference between the two.
 */
async function walk(browser, label, { dropContext, overrides = {} }) {
  await browser.get(`${FORM_URL}?cb=${Date.now()}`);
  await browser.consoleLog();
  const filled = await fillPage1(browser, overrides);
  const nav = await nextPage(browser);
  const msgs = await messages(browser);
  if (!nav.ok) {
    return { label, blocked: true, nav, messages: msgs };
  }
  await armBookingState(browser);
  await armNetworkCapture(browser);
  await resetCapture(browser);

  const droppedContext = dropContext ? await clearWizardContext(browser) : null;
  const before = await inventory(browser);
  const baseline = before.bookingStateChanges;
  const chosen = await setDateField(browser, 'preferred_service_date', DATE);

  let after = before;
  try {
    after = await browser.until(`a verdict for ${DATE}`, settledAfter(browser, baseline),
      { timeout: 25000 });
  }
  catch {
    after = await inventory(browser);
  }
  // The response body is filled in by a then() one microtask after the fetch
  // resolves, so give the capture a beat before reading it.
  await new Promise((resolve) => setTimeout(resolve, 400));

  const shot = `${ARTIFACTS}/${STAMP}-diagnosis-${label}.png`;
  await browser.screenshot(shot);

  return {
    label,
    blocked: false,
    filled,
    messages: msgs,
    droppedContext,
    chosen,
    answers: await visibleAnswers(browser),
    calls: await capturedCalls(browser),
    requests: Array.from(new Set(await requestNames(browser))),
    dom: {
      bookingState: after.bookingState,
      status: (after.status || '').trim(),
      price: (after.priceText || '').trim(),
      dateHelp: (after.dateHelpText || '').trim(),
      slotButtonsVisible: after.slotButtonsVisible,
      textManualReviewRequired: after.textManualReviewRequired,
      textStaffWillPrice: after.textStaffWillPrice,
      textConfirmedAfterStaffReview: after.textConfirmedAfterStaffReview,
      textInstantEstimate: after.textInstantEstimate,
      courierAddOnControlsVisible: after.courierAddOnControlsVisible,
      courierAddOnControlsChecked: after.courierAddOnControlsChecked,
    },
    console: (await browser.consoleLog())
      .filter((e) => e.level === 'SEVERE' && !/favicon|net::ERR_/i.test(e.message || ''))
      .map((e) => String(e.message).slice(0, 200)),
    shot,
  };
}

/** Field-by-field difference between two captured offer payloads. */
function diffPayloads(a, b) {
  const va = (a && a.request && a.request.values) || {};
  const vb = (b && b.request && b.request.values) || {};
  const keys = Array.from(new Set(Object.keys(va).concat(Object.keys(vb)))).sort();
  const rows = [];
  keys.forEach((key) => {
    const inA = Object.prototype.hasOwnProperty.call(va, key);
    const inB = Object.prototype.hasOwnProperty.call(vb, key);
    const sa = inA ? JSON.stringify(va[key]) : '<absent>';
    const sb = inB ? JSON.stringify(vb[key]) : '<absent>';
    if (sa !== sb) {
      rows.push({ key, screenshotPath: sa, fixture: sb });
    }
  });
  return rows;
}

async function main() {
  const browser = await Browser.open();
  let reproduction;
  let fixture;
  try {
    await browser.viewport(1440, 900);
    reproduction = await walk(browser, 'screenshot-path', { dropContext: true });
    fixture = await walk(browser, 'fixture-path', { dropContext: false });
  }
  finally {
    await browser.quit();
  }

  const print = (run) => {
    console.log(`\n=== ${run.label} ===`);
    if (run.blocked) {
      console.log(`  BLOCKED at page 1 → 2: ${JSON.stringify(run.nav)}`);
      console.log(`  messages: ${JSON.stringify(run.messages)}`);
      return;
    }
    console.log(`  wizard context dropped: ${JSON.stringify(run.droppedContext)}`);
    console.log(`  date set: ${JSON.stringify(run.chosen)}`);
    console.log('  DOM verdict:');
    Object.entries(run.dom).forEach(([k, v]) => console.log(`    ${k}: ${JSON.stringify(v)}`));
    console.log('  captured calls:');
    run.calls.forEach((call) => {
      console.log(`    ${call.method} ${call.path} → ${call.status}`);
      console.log(`      request:  ${JSON.stringify(call.request)}`);
      console.log(`      response: ${JSON.stringify(call.response)}`);
    });
    console.log(`  request names: ${JSON.stringify(run.requests)}`);
    console.log(`  console errors: ${JSON.stringify(run.console)}`);
    console.log(`  screenshot: ${run.shot}`);
  };

  console.log(`Express Courier Manual Review diagnosis — ${FORM_URL}`);
  console.log(`Date under test: ${DATE}`);
  print(reproduction);
  print(fixture);

  const offerOf = (run) => (run.blocked ? null
    : run.calls.find((c) => /dispatch\/offer/.test(c.path)) || null);
  const offerA = offerOf(reproduction);
  const offerB = offerOf(fixture);

  console.log('\n=== offer payload diff (screenshot path vs fixture) ===');
  if (!offerA && !offerB) {
    console.log('  neither walk sent an offer request');
  }
  else if (!offerA) {
    console.log('  screenshot path sent NO offer request; fixture did');
  }
  else if (!offerB) {
    console.log('  fixture sent NO offer request; screenshot path did');
  }
  else {
    const rows = diffPayloads(offerA, offerB);
    if (!rows.length) {
      console.log('  payloads identical field-for-field');
    }
    rows.forEach((row) =>
      console.log(`  ${row.key}: screenshot=${row.screenshotPath}  fixture=${row.fixture}`));
    console.log(`  date sent:  screenshot=${JSON.stringify(offerA.request && offerA.request.date)}`
      + `  fixture=${JSON.stringify(offerB.request && offerB.request.date)}`);
    console.log(`  response:   screenshot=${JSON.stringify(offerA.response)}`);
    console.log(`              fixture=${JSON.stringify(offerB.response)}`);
  }

  const quoteOf = (run) => (run.blocked ? null
    : run.calls.filter((c) => /distance-preview/.test(c.path)).pop() || null);
  console.log('\n=== quote (distance-preview) verdicts ===');
  [reproduction, fixture].forEach((run) => {
    const call = quoteOf(run);
    console.log(`  ${run.label}: ${call ? JSON.stringify(call.response) : 'no quote call captured'}`);
  });
}

await main();
