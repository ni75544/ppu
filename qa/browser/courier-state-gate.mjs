// Express Courier public-state gate.
//
// Scores the states the brief names as separate outcomes, at both required
// widths, from the real anonymous form and with no answer the customer cannot
// see:
//
//   1. unfinished  — a date is chosen but a required quote answer is missing.
//      No pickup times, no review wording, and no offer request sent early.
//   2. eligible    — a complete in-limit request on a working day, with the
//      one-driver question never asked. An instant estimate and only bookable
//      times, all on the day that was asked for.
//   3. date change — the same request moved to another working day.
//   4. refused     — a complete request the rules genuinely refuse, here by
//      weight. Review wording, no times, and no disclosed threshold.
//   5. layout      — one date input, one Slot widget, no driver control.
//   6. stale       — a crafted `single_driver_handling=0` payload. The server
//      derives the fact from the service policy, so the same request stays
//      eligible; the retired control cannot be answered in the browser at all.
//
// The gate deliberately does NOT prefill `single_driver_handling`. Pre-filling it
// with '1' was the whole difference between a green gate and the customer's
// screenshot: the fixture answered a question the real form must never ask, so the
// fixture got its Slots and the visitor got 'Manual review required'. The control's
// absence is now scored on page 1, where it would render, and the crafted-payload
// scenario proves the server ignores a value sent anyway.
//
// State 1 is not reachable by an ordinary Courier walk: every quote answer is
// #states-required on page 1, so the wizard will not carry an unfinished request
// to the widget page. It is therefore proven the two honest ways — the server is
// asked directly with the answer omitted, and the browser is put in a page-2
// state that carries no such default, which is what a resumed draft or an
// expired wizard context actually looks like. Both are labelled as such. The
// unattested-contents refusal is proven the same way, because the attestation is
// #states-required too and the wizard cannot be walked past it unchecked.
//
// Read-only with respect to the business: no final submit, no Hold, no
// reservation, no checkout, no payment. Selecting a time takes nothing.
//
// Usage: ddev exec node qa/browser/courier-state-gate.mjs [YYYY-MM-DD] [YYYY-MM-DD]
import { Browser } from './webdriver.mjs';
import {
  FORM_URL, SAMPLE, fillPage1, nextPage, messages, inventory, setDateField, requestNames,
  armBookingState, armNetworkCapture, capturedCalls, resetCapture, onPage2,
  postOffer, stripFact, sidebarWording, clickSlot,
} from './courier-flow.mjs';
import { openDays } from './open-days.mjs';
import { clearThrottles } from './throttles.mjs';

// Two open days, and neither is hardcoded any more: the pair used to default to
// '2026-09-01' and '2026-09-02', which stopped being open days the moment they
// passed and would have made this gate report a stale-arrival failure that was
// really a stale gate. `openDays()` reads the resource's own schedule.
const OPEN = openDays(2);
const DATE = OPEN.dates[0];
const NEXT = OPEN.dates[1];
const ARTIFACTS = process.env.COURIER_ARTIFACTS || 'qa/artifacts';
const STAMP = new Date().toISOString().replace(/[:.]/g, '-').slice(0, 19);
const TERMINAL = ['available', 'unavailable', 'manual_review', 'waiting'];
const DATE_HELP = 'Select a date to see available pickup times.';
// The sentence an undeclared refusal falls back to. Over-weight is deliberately
// published as the generic `staff_review` code, so this is what a 45 lb request
// must read — and the two-person sentence is what it must NOT read, because no
// customer answered any such question any more.
const GENERIC_REVIEW = 'These details need a person to confirm the schedule.';
const TWO_PERSON = 'You told us this needs two people to carry';
// The configured self-service ceiling must never be disclosed to a visitor, so no
// on-screen sentence may name it.
const THRESHOLD = /\b30\s*(?:lb|lbs|pounds?)\b/i;
// The Slot count the brief names for a valid Courier Send on the working day
// under test. Scored as its own assertion so a capacity or hours drift is legible
// as a drift rather than hiding inside "some times were offered".
//
// Per-site, not universal: the working hours behind this number are operator-set
// configuration, and the two sites carry different ones — ppu-qa's resource works
// Mon-Wed 08:00-18:00 and offers 19, the active ppu resource works Mondays
// 08:00-17:00 and offers 17. Scoring an active-site walk against the wrong
// constant would report a red that is really a schedule difference, and changing
// the active schedule to suit a harness is forbidden. So the count is an input
// with the ppu-qa value as its default, which leaves every existing invocation
// scored exactly as before.
const EXPECTED_SLOTS = Number.parseInt(process.env.EXPECTED_SLOTS || '', 10) > 0
  ? Number.parseInt(process.env.EXPECTED_SLOTS, 10)
  : 19;
const WIDTHS = [
  { label: 'desktop', width: 1440, height: 900 },
  { label: 'mobile', width: 390, height: 844 },
];
// Anything here appearing in a resource name would mean the widget took capacity
// or started money, which this flow must not do.
const SIDE_EFFECTS = /hold|reserve|reservation|checkout|payment|renew|release/i;

const results = [];

/** Records one scored assertion. `detail` is what was actually measured. */
function check(scenario, width, name, pass, detail) {
  results.push({ scenario, width, name, pass: !!pass, detail });
  return !!pass;
}

/** Maps each offered Slot's absolute instant to its New York calendar date. */
const localDates = (ats) => {
  const fmt = new Intl.DateTimeFormat('en-CA',
    { timeZone: 'America/New_York', year: 'numeric', month: '2-digit', day: '2-digit' });
  return Array.from(new Set(ats.map((at) => fmt.format(new Date(at * 1000)))));
};

/**
 * Waits for the widget's own next verdict, not for a guessed interval.
 *
 * The widget announces every transition, so a snapshot is only trustworthy once
 * the change counter has moved past the baseline and it is no longer busy.
 */
async function settle(browser, baseline, label) {
  try {
    return await browser.until(label, async () => {
      const snap = await inventory(browser);
      if (snap.dispatchBusy || snap.bookingStateChanges <= baseline) {
        return null;
      }
      return TERMINAL.includes(snap.bookingState) ? snap : null;
    }, { timeout: 25000 });
  }
  catch {
    return inventory(browser);
  }
}

/** The offer calls captured so far, newest last. */
const offerCalls = (calls) => calls.filter((c) => /dispatch\/offer/.test(c.path));

/** Real script errors only; a missing favicon is not a defect in this flow. */
const severeConsole = async (browser) => (await browser.consoleLog())
  .filter((e) => e.level === 'SEVERE' && !/favicon|net::ERR_/i.test(e.message || ''))
  .map((e) => String(e.message).slice(0, 160));

/**
 * Loads the form fresh, answers page 1, and advances to the widget page.
 *
 * Page 1 is inventoried after it is answered and before it is left, because that
 * is the only page the retired driver question would ever have rendered on.
 * Measuring its absence from page 2 would prove nothing: page 2 carries none of
 * the page-1 controls, so every count there is zero whatever the form asks.
 */
async function toPage2(browser, overrides) {
  // Both buckets, and on the site under test. The preview-only reset this used to
  // call left the offer bucket full, and passed no `--uri`, so a ppu-qa run cleared
  // the default site and reported success.
  const throttle = clearThrottles();
  await browser.get(`${FORM_URL}?cb=${Date.now()}`);
  await browser.consoleLog();
  const filled = await fillPage1(browser, overrides);
  const page1 = await inventory(browser);
  const nav = await nextPage(browser);
  const msgs = await messages(browser);
  const arrived = nav.ok && (await onPage2(browser));
  if (arrived) {
    await armBookingState(browser);
    await armNetworkCapture(browser);
    await resetCapture(browser);
  }
  return { throttle, filled, page1, nav, messages: msgs, arrived };
}

/**
 * State 1 — a date is chosen while a required quote answer is missing.
 *
 * The walk itself is a normal, complete page-1 answer set, because the wizard
 * will not carry an incomplete one forward. The required weight answer is then
 * removed from the page-2 value sources, which is exactly the page-2 state a
 * resumed draft or an expired wizard context renders. The server is asked the
 * same question directly, with the answer omitted, so the fail-safe is proven
 * where it actually lives rather than inferred from the sentence on screen.
 */
async function scenarioUnfinished(browser, width) {
  const scenario = '1-unfinished';
  const walk = await toPage2(browser, {});
  if (!walk.arrived) {
    check(scenario, width.label, 'reached the widget page', false,
      { nav: walk.nav, messages: walk.messages });
    return { walk };
  }

  const strip = await stripFact(browser, 'total_weight_lbs');
  const before = await inventory(browser);
  const chosen = await setDateField(browser, 'preferred_service_date', DATE);
  const after = await settle(browser, before.bookingStateChanges, `a verdict for ${DATE}`);
  await new Promise((resolve) => setTimeout(resolve, 600));
  const calls = await capturedCalls(browser);
  const offers = offerCalls(calls);
  const names = Array.from(new Set(await requestNames(browser)));
  const sidebar = await sidebarWording(browser);
  const shot = `${ARTIFACTS}/${STAMP}-${width.label}-1-unfinished.png`;
  await browser.screenshot(shot);
  const probe = await postOffer(browser, DATE, { omit: ['total_weight_lbs'] });
  const body = (probe && probe.body) || {};

  check(scenario, width.label, 'no offer request sent for an unfinished request',
    offers.length === 0, { offerRequests: offers.length });
  check(scenario, width.label, 'shows the neutral incomplete instruction',
    after.textCompleteRequiredDetails > 0, { status: (after.status || '').trim() });
  check(scenario, width.label, 'says nothing about manual review or staff pricing',
    after.textManualReviewRequired === 0 && after.textStaffWillPrice === 0,
    { manualReviewRequired: after.textManualReviewRequired,
      staffWillPrice: after.textStaffWillPrice, price: (after.priceText || '').trim() });
  check(scenario, width.label, 'offers no pickup times',
    after.slotButtonsVisible === 0, { slots: after.slotButtonsVisible });
  check(scenario, width.label, 'server answers incomplete, not manual review',
    probe.status === 200 && body.incomplete === true && body.manual_review === false
      && body.slots && body.slots.count === 0,
    { status: probe.status, incomplete: body.incomplete,
      manual_review: body.manual_review, review_code: body.review_code || null,
      slots: body.slots, sentKeys: probe.sentKeys });
  check(scenario, width.label, 'took no capacity and started no payment',
    !names.some((n) => SIDE_EFFECTS.test(n)), { requests: names });
  // The summary region is the second place either verdict can be published. A
  // visitor who has not said what the package weighs must not read anywhere on
  // the page that their job is pending review or cannot be priced — and must not
  // read a figure either, since the calculator still prices the base charge.
  check(scenario, width.label, 'the summary claims no review either',
    sidebar.estimateNeedsReview === 0 && sidebar.pendingManualReview === 0
      && sidebar.cannotBePricedNote === 0 && sidebar.needsAPersonNote === 0,
    sidebar);
  check(scenario, width.label, 'the summary asks for the missing details instead',
    sidebar.completeRemainingNote > 0 && sidebar.noEstimateYet > 0,
    { note: sidebar.completeRemainingNote, disclaimer: sidebar.noEstimateYet,
      status: sidebar.statusText });
  check(scenario, width.label, 'the summary shows no figure for an unfinished request',
    sidebar.totalText === '' && sidebar.lineItemCount === 0,
    { total: sidebar.totalText, lineItems: sidebar.lineItemCount });
  // The published precheck contract, scored in the one state that actually uses
  // it. requestUnfinished() compares required_facts against the live values before
  // it will send anything, so a driver key here would make the browser withhold
  // every offer request for a fact the customer can no longer answer — the
  // neutral instruction above would then be permanent rather than a prompt.
  check(scenario, width.label, 'the browser required-facts list names no driver fact',
    Array.isArray(after.dispatchRequiredFacts)
      && !after.dispatchRequiredFacts.includes('single_driver_handling'),
    { requiredFacts: after.dispatchRequiredFacts });

  return { walk, strip, chosen, after, calls, names, sidebar, probe, shot,
    console: await severeConsole(browser) };
}

/**
 * States 2 and 5 — a complete in-limit request on a working day, and the layout
 * the brief requires at this width.
 *
 * This is the case the customer's screenshot failed. The walk answers only what is
 * visible: no driver question is answered, because none is asked. So the Slots
 * below are the proof that the fact is now derived from the service policy rather
 * than from the browser, and the payload assertions are the proof that the browser
 * is not quietly carrying it after all.
 *
 * State 3's date change continues from this same page on purpose: "the previous
 * Slot clears" is only a claim worth testing about a selection that existed.
 */
async function scenarioEligible(browser, width) {
  const scenario = '2-eligible';
  const walk = await toPage2(browser, {});
  if (!walk.arrived) {
    check(scenario, width.label, 'reached the widget page', false,
      { nav: walk.nav, messages: walk.messages });
    return { walk };
  }

  const before = await inventory(browser);
  const chosen = await setDateField(browser, 'preferred_service_date', DATE);
  const after = await settle(browser, before.bookingStateChanges, `a verdict for ${DATE}`);
  await new Promise((resolve) => setTimeout(resolve, 600));
  const calls = await capturedCalls(browser);
  const offer = offerCalls(calls).slice(-1)[0] || null;
  const names = Array.from(new Set(await requestNames(browser)));
  const dates = localDates(after.slotAts || []);
  const shot = `${ARTIFACTS}/${STAMP}-${width.label}-2-eligible.png`;
  await browser.screenshot(shot);
  const sidebar = await sidebarWording(browser);
  // The sentence the date question must be showing now that times are rendered,
  // built with the widget's own published timezone and formatter rather than from
  // the harness's clock or a hardcoded month abbreviation: this container runs in
  // UTC, so a late-evening New York date formatted here would name the wrong day.
  const expectedDayHelp = await browser.js(`
    const [ymd] = arguments;
    const dispatch = ((window.drupalSettings || {}).custom_webform_handlers || {}).dispatch || {};
    const timezone = (dispatch.constraints || {}).timezone || '';
    const parts = String(ymd).split('-').map(Number);
    const label = (() => {
      if (parts.length !== 3 || parts.some((v) => !Number.isFinite(v))) { return ymd; }
      const at = new Date(Date.UTC(parts[0], parts[1] - 1, parts[2], 12));
      const options = { weekday: 'long', month: 'short', day: 'numeric' };
      if (timezone) { options.timeZone = timezone; }
      try { return new Intl.DateTimeFormat(undefined, options).format(at); }
      catch (error) { return ymd; }
    })();
    return 'Available pickup times for ' + label + '.';
  `, [DATE]);
  const sentKeys = Object.keys((offer && offer.request && offer.request.values) || {});

  // The brief's first row, and the assertion the whole change exists for: a valid
  // Courier Send with no driver answer supplied must reach bookable times.
  check(scenario, width.label, 'offers pickup times without any driver answer',
    after.slotButtonsVisible > 0,
    { slots: after.slotButtonsVisible,
      driverAnswered: (walk.filled || {}).single_driver_handling || 'not attempted' });
  check(scenario, width.label, `offers the ${EXPECTED_SLOTS} bookable times the brief names`,
    after.slotButtonsVisible === EXPECTED_SLOTS,
    { slots: after.slotButtonsVisible, expected: EXPECTED_SLOTS });
  check(scenario, width.label, 'every offered time is bookable',
    after.slotButtonsVisible > 0 && after.slotButtonsUnbookable === 0,
    { unbookable: after.slotButtonsUnbookable });
  check(scenario, width.label, `every offered time is on ${DATE}`,
    dates.length === 1 && dates[0] === DATE, { slotDates: dates });
  check(scenario, width.label, 'shows the instant estimate',
    after.textInstantEstimate > 0, { price: (after.priceText || '').trim() });
  check(scenario, width.label, 'says nothing about manual review or staff pricing',
    after.textManualReviewRequired === 0 && after.textStaffWillPrice === 0,
    { manualReviewRequired: after.textManualReviewRequired,
      staffWillPrice: after.textStaffWillPrice });
  check(scenario, width.label, 'does not ask for details that are already complete',
    after.textCompleteRequiredDetails === 0,
    { status: (after.status || '').trim() });
  // The date question's help text, scored on both sides of the one wording polish
  // the profile work is allowed: the instruction while nothing is chosen, the named
  // day once times are on the screen. Two assertions rather than one because the
  // failure modes are opposite — a text frozen on the instruction leaves "select a
  // date" standing beside times that are already rendered, and one frozen on the
  // day name asks nobody to choose anything on arrival. This assertion used to
  // require the instruction *after* rendering, which is now the forbidden state.
  check(scenario, width.label, 'date help asks for a date before one is chosen',
    before.dateHelpText === DATE_HELP,
    { dateHelp: before.dateHelpText });
  check(scenario, width.label, 'date help names the chosen day once times render',
    after.dateHelpText === expectedDayHelp
      && after.textSelectDateToSeeTimes === 0
      && after.textConfirmedAfterStaffReview === 0,
    { dateHelp: after.dateHelpText,
      expected: expectedDayHelp,
      selectADateStillOnScreen: after.textSelectDateToSeeTimes,
      legacyPromise: after.textConfirmedAfterStaffReview });
  check(scenario, width.label, 'server answered eligible',
    offer !== null && offer.status === 200 && offer.response
      && offer.response.manual_review === false && offer.response.incomplete === false,
    offer ? { status: offer.status, response: offer.response } : { offerRequests: 0 });
  // Three separate places the retired fact could still be travelling: the page
  // where it used to render, the request the widget actually sent, and the
  // allowlist it built that request from. All three are scored, because a control
  // that is gone from the DOM proves nothing about a value published in
  // drupalSettings, and a value the browser never sends proves nothing about a
  // value the server would read if it arrived (that is scenario 6's job).
  check(scenario, width.label, 'page 1 asked no driver question at all',
    walk.page1.singleDriverControls === 0 && walk.page1.textOnePersonCarry === 0
      && walk.page1.textTwoPeopleOption === 0,
    { controls: walk.page1.singleDriverControls,
      questionText: walk.page1.textOnePersonCarry,
      optionText: walk.page1.textTwoPeopleOption,
      visible: walk.page1.singleDriverVisible });
  check(scenario, width.label, 'the offer payload carries no driver fact',
    offer !== null && !sentKeys.includes('single_driver_handling'),
    { sentKeys: sentKeys.sort() });
  check(scenario, width.label, 'the collect-and-send allowlist excludes the driver fact',
    Array.isArray(after.dispatchValueFields)
      && !after.dispatchValueFields.includes('single_driver_handling'),
    { valueFields: after.dispatchValueFields });
  check(scenario, width.label, 'took no capacity and started no payment',
    !names.some((n) => SIDE_EFFECTS.test(n)), { requests: names });
  // The mirror of state 1's summary assertions. Suppressing review wording for an
  // unfinished request must not have cost a complete one its estimate: this state
  // is the one that is allowed to print a figure, and it must.
  check(scenario, width.label, 'the summary claims no review',
    sidebar.estimateNeedsReview === 0 && sidebar.pendingManualReview === 0
      && sidebar.cannotBePricedNote === 0 && sidebar.needsAPersonNote === 0,
    sidebar);
  check(scenario, width.label, 'the summary does not ask for details already given',
    sidebar.completeRemainingNote === 0 && sidebar.noEstimateYet === 0,
    { note: sidebar.completeRemainingNote, disclaimer: sidebar.noEstimateYet,
      status: sidebar.statusText });
  check(scenario, width.label, 'the summary shows the itemised estimate',
    sidebar.totalText !== '' && sidebar.lineItemCount > 0,
    { total: sidebar.totalText, lineItems: sidebar.lineItemCount });

  layoutChecks(width, after, walk.page1);
  const change = await dateChange(browser, width);

  return { walk, chosen, after, calls, offer, names, dates, shot, change, sidebar,
    sentKeys: sentKeys.sort(),
    sidebarAfterChange: await sidebarWording(browser),
    console: await severeConsole(browser) };
}

/**
 * State 5 — scored against the eligible state, because that is the only state in
 * which all of the date and time UI is on screen at once. A width that renders
 * one date input while showing no times has not been shown to be free of a
 * duplicate.
 *
 * `page1` is the answered page-1 snapshot from the same walk, and it is what makes
 * the driver assertion mean anything: the question only ever rendered there.
 */
function layoutChecks(width, snap, page1) {
  const scenario = '5-layout';
  check(scenario, width.label, 'exactly one visible date input, the public one',
    snap.visibleEnabledDateInputs === 1 && snap.preferredServiceDateVisible === 1
      && snap.dispatchWidgetDateVisible === 0,
    { visibleDateInputs: snap.visibleEnabledDateInputs,
      names: snap.visibleDateInputNames,
      widgetDateInputs: snap.dispatchWidgetDateInputs,
      widgetDateVisible: snap.dispatchWidgetDateVisible });
  check(scenario, width.label, 'no duplicate date or time UI',
    snap.textPickupDate === 0 && snap.preferredServiceTimeVisible === 0
      && snap.textPreferredServiceTime === 0 && snap.legacyReservationWidgets === 0,
    { pickupDateText: snap.textPickupDate,
      preferredServiceTimeVisible: snap.preferredServiceTimeVisible,
      preferredServiceTimeText: snap.textPreferredServiceTime,
      legacyWidgets: snap.legacyReservationWidgets });
  check(scenario, width.label, 'exactly one Slot widget',
    snap.dispatchSlotValue !== null && snap.slotButtonsVisible > 0,
    { slotInput: snap.dispatchSlotValue === null ? 'absent' : 'present',
      slots: snap.slotButtonsVisible });
  // Retired, not hidden. The control count includes controls CSS would have hidden,
  // and the copy counts read innerText, so a question painted over rather than
  // removed fails the first clause while passing the other two.
  check(scenario, width.label, 'no driver control anywhere on the Courier form',
    page1.singleDriverControls === 0 && page1.singleDriverVisible === 0
      && page1.textOnePersonCarry === 0
      && snap.singleDriverControls === 0 && snap.singleDriverVisible === 0,
    { page1Controls: page1.singleDriverControls,
      page1Visible: page1.singleDriverVisible,
      page1QuestionText: page1.textOnePersonCarry,
      page2Controls: snap.singleDriverControls,
      page2Visible: snap.singleDriverVisible });
  check(scenario, width.label, 'no horizontal overflow',
    snap.documentScrollWidth <= snap.innerWidth + 1,
    { scrollWidth: snap.documentScrollWidth, innerWidth: snap.innerWidth });
}

/**
 * State 3 — the same complete request moved to another working day.
 *
 * A time is selected first so that clearing it is observable. Selecting takes
 * nothing: the widget creates no Hold and the server claims capacity only when
 * checkout starts.
 */
async function dateChange(browser, width) {
  const scenario = '3-date-change';
  const picked = await clickSlot(browser);
  const held = await inventory(browser);
  check(scenario, width.label, 'a time could be selected on the first date',
    picked.ok === true && String(held.dispatchSlotValue || '') !== '',
    { picked, selected: held.slotSelected, value: held.dispatchSlotValue });

  await resetCapture(browser);
  const before = await inventory(browser);
  const changed = await setDateField(browser, 'preferred_service_date', NEXT);
  const after = await settle(browser, before.bookingStateChanges, `a verdict for ${NEXT}`);
  await new Promise((resolve) => setTimeout(resolve, 600));
  const calls = await capturedCalls(browser);
  const offer = offerCalls(calls).slice(-1)[0] || null;
  const dates = localDates(after.slotAts || []);
  const shot = `${ARTIFACTS}/${STAMP}-${width.label}-3-date-change.png`;
  await browser.screenshot(shot);

  check(scenario, width.label, 'the previous selection was cleared',
    String(after.dispatchSlotValue || '') === '' && after.slotSelected === 0,
    { value: after.dispatchSlotValue, selected: after.slotSelected });
  check(scenario, width.label, 'fresh availability was requested for the new date',
    offer !== null && offer.request && offer.request.date === NEXT,
    offer ? { date: offer.request && offer.request.date, status: offer.status }
      : { offerRequests: 0 });
  check(scenario, width.label, `only the new date's bookable times render`,
    after.slotButtonsVisible > 0 && after.slotButtonsUnbookable === 0
      && dates.length === 1 && dates[0] === NEXT,
    { slots: after.slotButtonsVisible, unbookable: after.slotButtonsUnbookable,
      slotDates: dates });
  check(scenario, width.label, 'still says nothing about manual review',
    after.textManualReviewRequired === 0 && after.textStaffWillPrice === 0
      && after.textCompleteRequiredDetails === 0,
    { manualReviewRequired: after.textManualReviewRequired,
      staffWillPrice: after.textStaffWillPrice,
      incompleteInstruction: after.textCompleteRequiredDetails });

  return { picked, held, changed, after, offer, dates, shot };
}

/**
 * State 4 — a complete request the rules genuinely refuse, here because the
 * package is 45 lb against the 30 lb self-service ceiling.
 *
 * Complete, on the same working day, differing from the eligible walk in exactly
 * one answer, so a review verdict here is a judgement about the job and not about
 * a blank field. Weight is the right cause to walk now that the driver question is
 * gone: it is the one genuine refusal a customer can reach through the visible form
 * without declaring anything about contents or helpers.
 *
 * QuoteCalculator's courier branch does not gatekeep weight, so the quote still
 * prices and only the offer endpoint refuses. That is the harder shape to get
 * right, and it is why the sidebar assertions matter: the price line has to pick
 * the refusal up from the booking-state event rather than from its own quote.
 *
 * Two further questions are put to the server in the same session. An over-weight
 * refusal must stay generic — the customer never declared it, so publishing the
 * cause would hand back a configured threshold — and an unattested-contents refusal
 * must still name itself, which has to be a wire probe because the attestation is
 * #states-required and the wizard cannot be walked past it unchecked.
 */
async function scenarioRefused(browser, width) {
  const scenario = '4-refused';
  const walk = await toPage2(browser, { total_weight_lbs: '45' });
  if (!walk.arrived) {
    check(scenario, width.label, 'reached the widget page', false,
      { nav: walk.nav, messages: walk.messages });
    return { walk };
  }

  const before = await inventory(browser);
  const chosen = await setDateField(browser, 'preferred_service_date', DATE);
  const after = await settle(browser, before.bookingStateChanges, `a verdict for ${DATE}`);
  await new Promise((resolve) => setTimeout(resolve, 600));
  const calls = await capturedCalls(browser);
  const offer = offerCalls(calls).slice(-1)[0] || null;
  const names = Array.from(new Set(await requestNames(browser)));
  const shot = `${ARTIFACTS}/${STAMP}-${width.label}-4-refused.png`;
  await browser.screenshot(shot);
  const sidebar = await sidebarWording(browser);
  const status = String(after.status || '').trim();
  // Unattested contents, proven on a request that is otherwise perfectly valid.
  // The weight is put back inside the limit for this one body on purpose:
  // ConfigDispatchEligibility::assessCourier() reaches the weight ceiling before
  // the attestation, so an unattested 45 lb request is refused for being heavy
  // and publishes the generic code — which would prove the ceiling again and the
  // attestation not at all. Riding this walk rather than the eligible one keeps
  // the two refusals in one scenario; the body is the only thing that differs.
  const unattested = await postOffer(browser, DATE, {
    omit: ['courier_prohibited_ack', 'prohibited_items_ack'],
    set: { total_weight_lbs: SAMPLE.total_weight_lbs },
  });
  const unBody = (unattested && unattested.body) || {};

  check(scenario, width.label, 'shows manual review wording',
    after.textManualReviewRequired > 0,
    { manualReviewRequired: after.textManualReviewRequired,
      price: (after.priceText || '').trim() });
  check(scenario, width.label, 'explains it without naming a cause the customer never gave',
    status.indexOf(GENERIC_REVIEW) !== -1 && status.indexOf(TWO_PERSON) === -1,
    { status, expected: GENERIC_REVIEW });
  // The reason the code is generic in the first place. A sentence that named the
  // ceiling would let anyone read the configured limit off the public form.
  check(scenario, width.label, 'discloses no weight threshold anywhere on the page',
    !THRESHOLD.test(status) && !THRESHOLD.test(String(after.priceText || ''))
      && !THRESHOLD.test(String(sidebar.statusText || '')),
    { status, price: (after.priceText || '').trim(), summary: sidebar.statusText });
  check(scenario, width.label, 'offers no pickup times',
    after.slotButtonsVisible === 0, { slots: after.slotButtonsVisible });
  check(scenario, width.label, 'does not ask for details that were supplied',
    after.textCompleteRequiredDetails === 0,
    { incompleteInstruction: after.textCompleteRequiredDetails });
  check(scenario, width.label, 'server refused an undeclared cause generically',
    offer !== null && offer.status === 200 && offer.response
      && offer.response.manual_review === true
      && offer.response.incomplete === false
      && offer.response.review_code === 'staff_review',
    offer ? { status: offer.status, response: offer.response } : { offerRequests: 0 });
  check(scenario, width.label, 'an unattested request is refused and names itself',
    unattested.status === 200 && unBody.manual_review === true
      && unBody.incomplete === false
      && unBody.review_code === 'contents_or_add_ons'
      && unBody.slots && unBody.slots.count === 0,
    { status: unattested.status, manual_review: unBody.manual_review,
      incomplete: unBody.incomplete, review_code: unBody.review_code,
      slots: unBody.slots, sentKeys: unattested.sentKeys });
  check(scenario, width.label, 'took no capacity and started no payment',
    !names.some((n) => SIDE_EFFECTS.test(n)), { requests: names });
  // The state that is supposed to say this. Suppressing the wording for an
  // unfinished request is only correct if a genuine refusal still carries it, so
  // this asserts the wording is present, that it is not confused with the
  // unfinished note, and that no bookable figure is printed beside it.
  check(scenario, width.label, 'the summary carries the review wording',
    sidebar.pendingManualReview > 0 && sidebar.estimateNeedsReview > 0,
    { badge: sidebar.pendingManualReview, status: sidebar.statusText });
  check(scenario, width.label, 'the summary does not call a refusal unfinished',
    sidebar.completeRemainingNote === 0 && sidebar.noEstimateYet === 0,
    { note: sidebar.completeRemainingNote, disclaimer: sidebar.noEstimateYet });
  check(scenario, width.label, 'the summary prints no figure for a refused request',
    sidebar.totalText === '', { total: sidebar.totalText });

  return { walk, chosen, after, calls, offer, names, unattested, shot, sidebar,
    console: await severeConsole(browser) };
}

/**
 * State 6 — a crafted, stale `single_driver_handling=0`.
 *
 * This is the exact payload that produced the customer's screenshot, and it is now
 * proven harmless in both halves.
 *
 * The browser half: a scenario that explicitly asks for the answer cannot give it,
 * because there is no control to bind it to. `fillPage1` reports `absent`, which is
 * the difference between a retired question and a hidden one — CSS would leave a
 * control that still accepts a value.
 *
 * The wire half: the same value is put straight into the offer body, past the form,
 * past value_fields, past drupalSettings. The server derives the fact from
 * self_bookable.single_driver_service_types before any element is read, so the
 * request stays eligible and keeps its times. The key is asserted to have actually
 * been sent, because a probe that quietly failed to inject would pass for the wrong
 * reason.
 */
async function scenarioStalePayload(browser, width) {
  const scenario = '6-stale-payload';
  const walk = await toPage2(browser, { single_driver_handling: '0' });
  if (!walk.arrived) {
    check(scenario, width.label, 'reached the widget page', false,
      { nav: walk.nav, messages: walk.messages });
    return { walk };
  }

  const attempted = (walk.filled || {}).single_driver_handling || null;
  const before = await inventory(browser);
  const chosen = await setDateField(browser, 'preferred_service_date', DATE);
  const after = await settle(browser, before.bookingStateChanges, `a verdict for ${DATE}`);
  await new Promise((resolve) => setTimeout(resolve, 600));
  const calls = await capturedCalls(browser);
  const offer = offerCalls(calls).slice(-1)[0] || null;
  const names = Array.from(new Set(await requestNames(browser)));
  const dates = localDates(after.slotAts || []);
  const shot = `${ARTIFACTS}/${STAMP}-${width.label}-6-stale-payload.png`;
  await browser.screenshot(shot);
  const sidebar = await sidebarWording(browser);
  const crafted = await postOffer(browser, DATE, { set: { single_driver_handling: '0' } });
  const body = (crafted && crafted.body) || {};

  check(scenario, width.label, 'the retired question cannot be answered in the browser',
    attempted !== null && attempted.ok === false && attempted.reason === 'absent',
    { attempted, page1Controls: walk.page1.singleDriverControls });
  check(scenario, width.label, 'the walk is still the eligible one on screen',
    after.slotButtonsVisible > 0 && after.slotButtonsUnbookable === 0
      && after.textInstantEstimate > 0 && after.textManualReviewRequired === 0,
    { slots: after.slotButtonsVisible, unbookable: after.slotButtonsUnbookable,
      price: (after.priceText || '').trim(),
      manualReviewRequired: after.textManualReviewRequired });
  check(scenario, width.label, `its times are still on ${DATE}`,
    dates.length === 1 && dates[0] === DATE, { slotDates: dates });
  check(scenario, width.label, 'the crafted value really was sent',
    Array.isArray(crafted.sentKeys) && crafted.sentKeys.includes('single_driver_handling'),
    { sentKeys: crafted.sentKeys, error: crafted.error || null });
  check(scenario, width.label, 'server canonicalization wins: still eligible with times',
    crafted.status === 200 && body.manual_review === false && body.incomplete === false
      && body.slots && body.slots.count > 0,
    { status: crafted.status, manual_review: body.manual_review,
      incomplete: body.incomplete, review_code: body.review_code || null,
      slots: body.slots });
  check(scenario, width.label, 'the crafted request is never called two-person',
    String(after.status || '').indexOf(TWO_PERSON) === -1
      && (body.review_code || null) !== 'two_person_handling',
    { status: (after.status || '').trim(), review_code: body.review_code || null });
  check(scenario, width.label, 'the summary still prints the estimate',
    sidebar.totalText !== '' && sidebar.pendingManualReview === 0,
    { total: sidebar.totalText, badge: sidebar.pendingManualReview });
  check(scenario, width.label, 'took no capacity and started no payment',
    !names.some((n) => SIDE_EFFECTS.test(n)), { requests: names });

  return { walk, attempted, chosen, after, calls, offer, names, dates, crafted,
    shot, sidebar, console: await severeConsole(browser) };
}

/** Everything one scenario is scored on, in the order the brief asks for it. */
function describe(label, run) {
  console.log(`  --- ${label} ---`);
  if (!run || !run.walk) {
    console.log('    not run');
    return;
  }
  console.log(`    preview throttle: ${run.walk.throttle}`);
  const answered = Object.entries(run.walk.filled || {})
    .map(([k, v]) => `${k}=${v.ok ? JSON.stringify(v.value) : (v.reason || 'no')}`);
  console.log(`    page-1 answers: ${answered.join(' ')}`);
  if (run.walk.page1) {
    const p = run.walk.page1;
    console.log(`    page-1 driver question: ${p.singleDriverControls} controls,`
      + ` ${p.singleDriverVisible} visible,`
      + ` title text x${p.textOnePersonCarry}, two-person option x${p.textTwoPeopleOption}`);
  }
  if (!run.walk.arrived) {
    console.log(`    BLOCKED before the widget page: ${JSON.stringify(run.walk.nav)}`);
    console.log(`    messages: ${JSON.stringify(run.walk.messages)}`);
    return;
  }
  if (run.strip) {
    console.log(`    fact removed from page-2 sources: ${JSON.stringify(run.strip)}`);
  }
  const snap = run.after || {};
  console.log(`    status text: ${JSON.stringify((snap.status || '').trim())}`);
  console.log(`    price text:  ${JSON.stringify((snap.priceText || '').trim())}`);
  console.log(`    date help:   ${JSON.stringify((snap.dateHelpText || '').trim())}`);
  console.log(`    booking state: ${snap.bookingState}`);
  console.log(`    slots: ${snap.slotButtonsVisible} visible,`
    + ` ${snap.slotButtonsUnbookable} unbookable,`
    + ` dates ${JSON.stringify(localDates(snap.slotAts || []))}`);
  console.log(`    published contract: value_fields`
    + ` ${JSON.stringify(snap.dispatchValueFields)}`);
  console.log(`      required_facts ${JSON.stringify(snap.dispatchRequiredFacts)}`);
  (run.calls || []).forEach((call) => {
    console.log(`    ${call.method} ${call.path} -> ${call.status}`);
    if (/dispatch\/offer/.test(call.path)) {
      console.log(`      request.date: ${JSON.stringify(call.request && call.request.date)}`);
      console.log(`      request.value keys: ${JSON.stringify(
        Object.keys((call.request && call.request.values) || {}).sort())}`);
      console.log(`      response: ${JSON.stringify(call.response)}`);
    }
  });
  if (!(run.calls || []).some((c) => /dispatch\/offer/.test(c.path))) {
    console.log('    NO offer request was sent');
  }
  if (run.probe) {
    console.log(`    direct offer probe (weight omitted): status ${run.probe.status}`
      + ` body ${JSON.stringify(run.probe.body)}`);
    console.log(`      sent value keys: ${JSON.stringify(run.probe.sentKeys)}`);
  }
  if (run.unattested) {
    console.log(`    direct offer probe (attestation omitted): status`
      + ` ${run.unattested.status} body ${JSON.stringify(run.unattested.body)}`);
    console.log(`      sent value keys: ${JSON.stringify(run.unattested.sentKeys)}`);
  }
  if (run.crafted) {
    console.log(`    direct offer probe (crafted single_driver_handling=0): status`
      + ` ${run.crafted.status} body ${JSON.stringify(run.crafted.body)}`);
    console.log(`      sent value keys: ${JSON.stringify(run.crafted.sentKeys)}`);
  }
  if (run.attempted) {
    console.log(`    attempt to answer the retired question:`
      + ` ${JSON.stringify(run.attempted)}`);
  }
  console.log(`    sidebar wording: ${JSON.stringify(run.sidebar)}`);
  if (run.sidebarAfterChange) {
    console.log(`    sidebar after date change: ${JSON.stringify(run.sidebarAfterChange)}`);
  }
  console.log(`    request names: ${JSON.stringify(run.names)}`);
  console.log(`    console errors: ${JSON.stringify(run.console)}`);
  console.log(`    screenshot: ${run.shot}`);
  if (run.change) {
    const c = run.change;
    console.log(`    date change -> ${NEXT}: selection before`
      + ` ${JSON.stringify(c.held && c.held.dispatchSlotValue)}, after`
      + ` ${JSON.stringify(c.after && c.after.dispatchSlotValue)}`);
    console.log(`      slots: ${c.after && c.after.slotButtonsVisible} visible,`
      + ` ${c.after && c.after.slotButtonsUnbookable} unbookable,`
      + ` dates ${JSON.stringify(c.dates)}`);
    console.log(`      offer response: ${JSON.stringify(c.offer && c.offer.response)}`);
    console.log(`      screenshot: ${c.shot}`);
  }
}

async function main() {
  const browser = await Browser.open();
  const runs = [];
  try {
    for (const width of WIDTHS) {
      const viewport = await browser.viewport(width.width, width.height);
      runs.push({
        width,
        viewport,
        unfinished: await scenarioUnfinished(browser, width),
        eligible: await scenarioEligible(browser, width),
        refused: await scenarioRefused(browser, width),
        stale: await scenarioStalePayload(browser, width),
      });
    }
  }
  finally {
    await browser.quit();
  }

  console.log(`Express Courier public-state gate — ${FORM_URL}`);
  console.log(`Dates under test: ${DATE} then ${NEXT}\n`);

  runs.forEach((run) => {
    console.log(`=== ${run.width.label} (${run.width.width}x${run.width.height},`
      + ` measured viewport ${JSON.stringify(run.viewport)}) ===`);
    describe('1 — fresh Courier form + date only (unfinished)', run.unfinished);
    describe('2 — complete eligible Courier + ' + DATE
      + ' (no driver answer supplied)', run.eligible);
    describe('4 — genuine ineligible Courier (45 lb, over the self-service limit)',
      run.refused);
    describe('6 — crafted stale single_driver_handling=0', run.stale);
    console.log('');
  });

  console.log('=== scored assertions ===');
  const order = ['1-unfinished', '2-eligible', '3-date-change', '4-refused',
    '5-layout', '6-stale-payload'];
  order.forEach((scenario) => {
    results.filter((r) => r.scenario === scenario).forEach((r) => {
      console.log(`  [${r.pass ? 'PASS' : 'FAIL'}] ${r.scenario} ${r.width}: ${r.name}`);
      if (!r.pass) {
        console.log(`         measured: ${JSON.stringify(r.detail)}`);
      }
    });
  });

  const failed = results.filter((r) => !r.pass);
  console.log(`\n${results.length - failed.length}/${results.length} assertions passed`);
  WIDTHS.forEach((w) => {
    const mine = results.filter((r) => r.width === w.label);
    const bad = mine.filter((r) => !r.pass).length;
    console.log(`  ${w.label}: ${mine.length - bad}/${mine.length}`
      + ` — ${bad === 0 ? 'GREEN' : 'RED'}`);
  });
  console.log(failed.length === 0 ? '\nGATE GREEN' : '\nGATE RED');
  process.exitCode = failed.length === 0 ? 0 : 1;
}

await main();
