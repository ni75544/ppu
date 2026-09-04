// Express Courier page-1 → page-2 walk and read-only DOM inventory.
//
// Deliberately does NOT invent answers: every control it touches is reported in
// `filled`, and a control that is not visible is recorded as absent rather than
// forced. A filler that types into a field the brief says must not be there is
// how a scope violation passes a gate, so absence is data here, not an obstacle.
//
// The page-1 answer sequences live here rather than in each runner because a
// second copy of them is a second definition of what the customer did: the write
// probe's private copy typed into `total_weight`, a field the form does not have,
// so its walk never reached the Slot page and its verdict was "inconclusive"
// rather than "wrong". One copy, imported, cannot disagree with itself.
import {
  clickControl, formQuiet, typeAddress, typeInto, waitFor,
} from './user-actions.mjs';

export const FORM_URL = process.env.COURIER_FORM_URL || 'https://ppu.ddev.site/form/hg';

/** The non-Courier pilot service, named once for every runner that switches to it. */
export const PILOT_SERVICE = 'on_demand_delivery';

export const SAMPLE = {
  service_type: 'courier',
  delivery_mode: 'courier_send',
  origin_address: '20 W 34th St, New York, NY 10001',
  destination_address: '30 Rockefeller Plaza, New York, NY 10112',
  total_weight_lbs: '10',
  // Only asked when delivery_mode is courier_store_pickup, which is why they sit
  // here rather than in the default page-1 sequence: the form gates all three on
  // that answer, so a walk that filled them unconditionally would be typing into
  // controls the customer cannot see.
  store_pickup_merchant: 'Midtown Camera Exchange',
  store_pickup_order_reference: 'QA-10D-0001',
};

/** Shared browser-side helpers, prepended to every evaluated snippet. */
const HELPERS = `
  const shown = (el) => {
    if (!el) { return false; }
    const cs = getComputedStyle(el);
    if (cs.visibility === 'hidden' || cs.display === 'none') { return false; }
    if (el.type === 'hidden') { return false; }
    const r = el.getBoundingClientRect();
    if (r.width === 0 && r.height === 0) { return false; }
    return el.offsetParent !== null || cs.position === 'fixed';
  };
  const base = (name) => String(name || '').split('[')[0];
  const all = (sel) => Array.from(document.querySelectorAll(sel));
  const named = (name) => all('[name]').filter((el) => base(el.name) === name);
  const fire = (el) => {
    el.dispatchEvent(new Event('input', { bubbles: true }));
    el.dispatchEvent(new Event('change', { bubbles: true }));
  };
  const countText = (needle) => {
    const text = document.body ? document.body.innerText : '';
    if (!needle) { return 0; }
    let n = 0;
    let i = text.indexOf(needle);
    while (i !== -1) { n += 1; i = text.indexOf(needle, i + needle.length); }
    return n;
  };
`;

const evaluate = (browser, body, args = []) => browser.js(HELPERS + body, args);

/** Sets a text input by value, then fires the events Drupal behaviors listen for. */
export function setText(browser, name, value) {
  return evaluate(browser, `
    const [name, value] = arguments;
    const el = named(name).find((c) => shown(c)) || named(name)[0];
    if (!el) { return { name, ok: false, reason: 'absent' }; }
    el.focus();
    el.value = value;
    fire(el);
    return { name, ok: true, visible: shown(el), value: el.value };
  `, [name, value]);
}

/** Clicks the radio/checkbox carrying a value, so #states and behaviors run. */
export function setChoice(browser, name, value) {
  return evaluate(browser, `
    const [name, value] = arguments;
    const options = named(name);
    if (!options.length) { return { name, ok: false, reason: 'absent' }; }
    const visible = options.some((el) => shown(el));
    const target = value === null
      ? options.find((el) => el.type === 'checkbox')
      : options.find((el) => String(el.value) === String(value));
    if (!target) { return { name, ok: false, reason: 'no-such-option', visible }; }
    if (target.type === 'checkbox' || target.type === 'radio') {
      if (!target.checked) { target.click(); }
      if (!target.checked) { target.checked = true; fire(target); }
    }
    else { target.value = value; fire(target); }
    return { name, ok: true, visible, value: String(target.value), checked: !!target.checked };
  `, [name, value]);
}

/**
 * Fills page 1 with a complete, eligible Courier Send request.
 *
 * `single_driver_handling` is NOT answered. It used to be pre-filled with '1' here,
 * and that one line was the whole difference between a green gate and the customer's
 * screenshot: the fixture answered a question the real form should never ask, so the
 * fixture got 19 Slots and the visitor got 'Manual review required'. The filler now
 * types only what a customer can actually see, and `inventory()` scores the control's
 * absence instead. A scenario that wants a value for it supplies one explicitly,
 * which is how the stale-payload case is built.
 *
 * `overrides` replaces individual answers — `{total_weight_lbs: '45'}` builds a
 * complete request that is genuinely ineligible rather than an incomplete one,
 * which is the only honest way to score the Manual Review state. An override of
 * `null` skips that control entirely and records it as skipped.
 */
export async function fillPage1(browser, overrides = {}) {
  const has = (key) => Object.prototype.hasOwnProperty.call(overrides, key);
  const want = (key, fallback) => (has(key) ? overrides[key] : fallback);
  const filled = {};
  const text = async (key, fallback) => {
    const value = want(key, fallback);
    filled[key] = value === null
      ? { name: key, ok: false, reason: 'skipped-by-scenario' }
      : await setText(browser, key, value);
  };
  const choice = async (key, fallback) => {
    const value = want(key, fallback);
    filled[key] = value === undefined
      ? { name: key, ok: false, reason: 'skipped-by-scenario' }
      : await setChoice(browser, key, value);
  };

  await choice('service_type', SAMPLE.service_type);
  await browser.until('service-dependent fields to settle', async () =>
    (await evaluate(browser, `return named('delivery_mode').length > 0;`)) === true);
  await choice('delivery_mode', SAMPLE.delivery_mode);
  await text('origin_address', SAMPLE.origin_address);
  await text('destination_address', SAMPLE.destination_address);
  await text('total_weight_lbs', SAMPLE.total_weight_lbs);
  await choice('total_weight_unit', 'lb');
  await choice('courier_prohibited_ack', null);
  // Deliberately not answered unless a scenario names it. The service derives the
  // one-driver fact server-side, so a customer never sees this control; typing into
  // it would restore the fixture dependency this gate exists to prove is gone.
  if (has('single_driver_handling')) {
    await choice('single_driver_handling', overrides.single_driver_handling);
  }
  // Only answered when a scenario asks for it. The form does not require it, so
  // a filler that always set it would be inventing an answer the customer never
  // gave — and this control is one of the candidate Manual Review causes.
  if (has('prohibited_items_ack')) {
    await choice('prohibited_items_ack', overrides.prohibited_items_ack);
  }
  return filled;
}

/** True when the wizard actually advanced to the page that carries the widget. */
export function onPage2(browser) {
  return evaluate(browser, `
    return document.querySelector('[name="dispatch_slot"]') !== null;
  `);
}

/**
 * Drops the short-lived wizard context the quote behavior stored on page 1.
 *
 * Page 2 holds none of the page-1 controls, so every planning fact in an offer
 * request comes from that context. Clearing it reproduces the state a real
 * visitor reaches whenever it is missing — a session that sat past the 30-minute
 * TTL, storage the browser refused, a draft reopened in a new tab — without
 * touching the form, the configuration, or the server. What the offer payload
 * then contains is measured, not assumed.
 */
export function clearWizardContext(browser) {
  return evaluate(browser, `
    const dropped = [];
    for (let i = window.sessionStorage.length - 1; i >= 0; i -= 1) {
      const key = window.sessionStorage.key(i);
      if (/quote|context|webform/i.test(key || '')) {
        dropped.push(key);
        window.sessionStorage.removeItem(key);
      }
    }
    return { dropped: dropped.length, keys: dropped.map((k) => String(k).slice(0, 60)) };
  `);
}

/** Presses the wizard's own Next control and waits for page 2 to render. */
export async function nextPage(browser) {
  const clicked = await evaluate(browser, `
    const btn = document.querySelector('[data-drupal-selector="edit-wizard-next"]')
      || Array.from(document.querySelectorAll('input[type="submit"], button[type="submit"]'))
        .find((el) => /next/i.test(el.value || el.textContent || ''));
    if (!btn) { return { ok: false, reason: 'no-next-button' }; }
    btn.click();
    return { ok: true, label: btn.value || btn.textContent };
  `);
  if (!clicked.ok) {
    return clicked;
  }
  await browser.until('page 2, a server message, or a blocked control', async () =>
    (await evaluate(browser, `
      if (document.querySelector('[name="dispatch_slot"]') !== null) { return true; }
      if (document.querySelector('.messages--error') !== null) { return true; }
      // The browser's own required-field enforcement can refuse the submit
      // outright, so the page never changes and no server message appears.
      // Treating that as a settled outcome is what lets a deliberately
      // incomplete scenario be measured instead of timing out.
      return all('input, select, textarea')
        .some((el) => shown(el) && el.willValidate && !el.checkValidity());
    `)) === true, { timeout: 20000 });
  return clicked;
}

/**
 * Records the widget's own published verdict so the gate can wait on it.
 *
 * The widget announces every transition on a bubbling
 * `custom-webform-booking-state` event — `pending` while it is re-asking the
 * server, then `available`, `unavailable` or `manual_review`. That is the only
 * non-guessy way to know an offer round finished: `aria-busy` covers just the
 * fetch and not the debounce ahead of it, and the status line is prose that
 * differs between the first request and a refresh.
 *
 * Install this after page 2 has rendered — the wizard's Next is a real POST, so
 * anything attached earlier is gone with the old document.
 */
export function armBookingState(browser) {
  return evaluate(browser, `
    window.__cwhBookingState = null;
    window.__cwhBookingStates = 0;
    document.addEventListener('custom-webform-booking-state', (event) => {
      window.__cwhBookingState = (event.detail || {}).state || null;
      window.__cwhBookingStates += 1;
    }, true);
    return true;
  `);
}

/**
 * Records the widget's own quote and offer traffic, redacted at capture time.
 *
 * The widget posts JSON through `window.fetch`, so wrapping fetch is the only
 * place both the request body and the server's verdict are visible together —
 * `performance.getEntriesByType('resource')` gives names and nothing else, and
 * the response is consumed by the widget before any later reader could see it.
 * The body is cloned rather than read, so the widget still gets its own copy.
 *
 * Addresses, names, phone numbers and e-mail are replaced with a length marker
 * here, in the browser, before the payload ever reaches a log: the diff this
 * gate has to produce is about which planning facts were present, and no part of
 * that needs a real street address.
 *
 * Install after page 2 has rendered, for the same reason as armBookingState().
 */
export function armNetworkCapture(browser) {
  return evaluate(browser, `
    if (window.__cwhFetchWrapped) { return { ok: true, already: true }; }
    const SECRETISH = /address|street|city|postal|zip|name|email|mail|phone|tel|token/i;
    const redact = (value) => {
      if (Array.isArray(value)) { return value.map(redact); }
      if (value && typeof value === 'object') {
        const out = {};
        Object.keys(value).forEach((key) => {
          out[key] = SECRETISH.test(key) && value[key] !== null && value[key] !== ''
            ? '<redacted:' + String(JSON.stringify(value[key])).length + '>'
            : redact(value[key]);
        });
        return out;
      }
      return value;
    };
    window.__cwhCalls = [];
    window.__cwhFetchWrapped = true;
    const native = window.fetch;
    window.fetch = function (input, init) {
      const url = String((input && input.url) || input || '');
      const path = url.split('?')[0].replace(location.origin, '');
      const method = String(((init || {}).method) || 'GET').toUpperCase();
      const watched = /distance-preview|dispatch\\/offer/.test(path);
      let body = null;
      if (watched && init && typeof init.body === 'string') {
        try { body = redact(JSON.parse(init.body)); }
        catch (error) { body = '<unparsed:' + init.body.length + '>'; }
      }
      const started = Date.now();
      return native.apply(this, arguments).then((response) => {
        if (!watched) { return response; }
        const entry = { path, method, request: body, status: response.status, response: null,
          ms: Date.now() - started };
        window.__cwhCalls.push(entry);
        response.clone().text().then((text) => {
          try { entry.response = redact(JSON.parse(text)); }
          catch (error) { entry.response = String(text).slice(0, 300); }
        }, () => { entry.response = '<unreadable>'; });
        return response;
      }, (error) => {
        if (watched) {
          window.__cwhCalls.push({ path, method, request: body, status: null,
            response: 'network-error:' + ((error && error.name) || 'unknown'),
            ms: Date.now() - started });
        }
        throw error;
      });
    };
    return { ok: true, already: false };
  `);
}

/**
 * The captured calls so far. Slot lists are summarised, never dumped whole.
 */
export function capturedCalls(browser) {
  return evaluate(browser, `
    return (window.__cwhCalls || []).map((call) => {
      const out = { path: call.path, method: call.method, status: call.status,
        request: call.request, response: call.response };
      const body = out.response;
      if (body && typeof body === 'object' && Array.isArray(body.slots)) {
        out.response = Object.assign({}, body, {
          slots: { count: body.slots.length,
            firstAt: body.slots.length ? body.slots[0].at : null },
        });
      }
      return out;
    });
  `);
}

/** Clears the capture buffer so one scenario's calls are not read as another's. */
export function resetCapture(browser) {
  return evaluate(browser, `window.__cwhCalls = []; return true;`);
}

/** Validation messages Drupal rendered, so a blocked walk is never silent. */
export function messages(browser) {
  return evaluate(browser, `
    return Array.from(document.querySelectorAll('.messages li, .messages'))
      .map((el) => el.textContent.trim().slice(0, 200))
      .filter((t) => t.length > 0)
      .slice(0, 12);
  `);
}

/** Everything the assertions are scored against. Counts and names only. */
export function inventory(browser) {
  return evaluate(browser, `
    const dateInputs = all('input[type="date"]').concat(all('.custom-webform-dispatch__date-input'));
    const uniqueDates = Array.from(new Set(dateInputs));
    const visibleDates = uniqueDates.filter((el) => shown(el));
    const slotButtons = all('.custom-webform-dispatch__slot');
    const visibleSlots = slotButtons.filter((el) => shown(el));
    const unbookable = (el) => el.disabled === true
      || el.getAttribute('aria-disabled') === 'true'
      || /disabled|unavailable|unbookable|full|past|blocked|closed/i.test(el.className);
    const slotInput = named('dispatch_slot')[0] || null;
    const legacy = all('.custom-reservation-calendar, .custom-webform-reservation, [data-custom-reservation], .webform-reservation-slots')
      .filter((el) => shown(el));
    return {
      visibleEnabledDateInputs: visibleDates.filter((el) => !el.disabled).length,
      visibleDateInputNames: visibleDates.map((el) => base(el.name) || ('#' + (el.id || 'unnamed'))),
      dispatchWidgetDateInputs: all('.custom-webform-dispatch__date-input').length,
      dispatchWidgetDateVisible: all('.custom-webform-dispatch__date-input').filter((el) => shown(el)).length,
      preferredServiceDateVisible: named('preferred_service_date').filter((el) => shown(el)).length,
      // The answer the widget is actually reading, not the one the walk believes it
      // typed. A wizard round trip can return the page with the question empty, and
      // an empty date is one of the reasons the widget declines to ask for times —
      // indistinguishable, from the outside, from a widget that ignored a date it had.
      preferredServiceDateValue: (() => {
        const el = named('preferred_service_date')[0];

        return el ? String(el.value || '') : null;
      })(),
      preferredServiceTimeVisible: named('preferred_service_time').filter((el) => shown(el)).length,
      dateFlexibilityVisible: named('date_flexibility').filter((el) => shown(el)).length,
      singleDriverVisible: named('single_driver_handling').filter((el) => shown(el)).length,
      legacyReservationWidgets: legacy.length,
      textPreferredServiceDate: countText('Preferred Service Date'),
      textPreferredServiceTime: countText('Preferred Service Time'),
      textPickupDate: countText('Pickup date'),
      textChoosePickupTime: countText('Choose your pickup time'),
      textTimezoneNote: countText('Times shown in New York local time'),
      slotButtonsVisible: visibleSlots.length,
      slotButtonsUnbookable: visibleSlots.filter(unbookable).length,
      slotAts: visibleSlots.map((el) => Number(el.getAttribute('data-at'))).filter((n) => Number.isFinite(n) && n > 0),
      slotSelected: visibleSlots.filter((el) => el.getAttribute('aria-pressed') === 'true'
        || /--selected/.test(el.className)).length,
      dispatchSlotValue: slotInput ? String(slotInput.value || '') : null,
      status: (document.querySelector('.custom-webform-dispatch__status') || {}).textContent || '',
      // The widget's own in-flight signal, and the only trustworthy one: it
      // raises aria-busy before the offer request and drops it in the finally.
      // Reading the status line instead means reading whichever sentence the
      // request happened to be showing, so a snapshot taken mid-fetch scores an
      // empty slot list as the answer rather than as "not answered yet".
      dispatchBusy: (document.querySelector('.custom-webform-dispatch') || {})
        .getAttribute ? document.querySelector('.custom-webform-dispatch')
        .getAttribute('aria-busy') === 'true' : false,
      bookingState: window.__cwhBookingState || null,
      bookingStateChanges: window.__cwhBookingStates || 0,
      // The price line is a separate verdict from the status line and carries its
      // own review wording, so a gate that reads only the status cannot tell an
      // unpriced request from an unschedulable one.
      priceText: (document.querySelector('.custom-webform-dispatch__price') || {}).textContent || '',
      dateHelpText: (() => {
        const input = named('preferred_service_date')[0];
        const wrapper = input ? input.closest('.js-form-item, .form-item') : null;
        const help = wrapper
          ? wrapper.querySelector('.description, .form-item__description, .webform-element-description')
          : null;
        return help ? help.textContent.trim() : '';
      })(),
      textManualReviewRequired: countText('Manual review required'),
      textStaffWillPrice: countText('staff will price this request'),
      textConfirmedAfterStaffReview: countText('This request is confirmed after staff review'),
      textCompleteRequiredDetails: countText('Complete the required courier details to see available pickup times'),
      textSelectDateToSeeTimes: countText('Select a date to see available pickup times'),
      textInstantEstimate: countText('Instant estimate'),
      courierAddOnControlsVisible: ['packing_services', 'cleaning_services', 'disposal_donation_interest']
        .reduce((n, key) => n + named(key).filter((el) => shown(el)).length, 0),
      courierAddOnControlsChecked: ['packing_services', 'cleaning_services', 'disposal_donation_interest']
        .reduce((n, key) => n + named(key).filter((el) => el.checked === true).length, 0),
      documentScrollWidth: document.documentElement.scrollWidth,
      innerWidth: window.innerWidth,
      nativeInvalidVisible: all('input, select, textarea')
        .filter((el) => shown(el) && el.willValidate && !el.checkValidity()).length,
      // Every control with this name, visible or not, so "retired" is distinguished
      // from "merely hidden": a CSS-hidden question would still be counted here.
      singleDriverControls: named('single_driver_handling').length,
      // The retired question's own words, which catch a re-added control under some
      // other element key. countText reads innerText, so it counts only what is
      // actually painted — which is why it is scored together with the count above
      // rather than instead of it.
      textOnePersonCarry: countText('Can one person carry this without help'),
      textTwoPeopleOption: countText('No, it needs two people'),
      // The published contract the widget plans against. value_fields is the
      // browser's collect-and-send allowlist and each profile's required_facts is
      // the list it compares against live values before it will ask for an offer at
      // all, so these are where "not collected by JavaScript" and "not in the
      // browser required-facts list" are actually decided.
      dispatchValueFields: (() => {
        const d = ((window.drupalSettings || {}).custom_webform_handlers || {}).dispatch;
        return d && Array.isArray(d.value_fields) ? d.value_fields.slice().sort() : null;
      })(),
      // The element names every published profile still demands, gathered from
      // booking_profiles — the one contract the widget plans against and the same
      // resolver the offer endpoint assesses with.
      //
      // Read as a union across services rather than for the service in hand,
      // deliberately: this reading happens on page 2, where service_type is not in
      // the DOM, and a union answers the stronger question anyway. If no profile
      // anywhere names single_driver_handling, then no service can demand it, so
      // the driver-fact assertion cannot pass by resolving the wrong service.
      //
      // The values are flattened rather than the keys reported, because a list of
      // keys would report the same shape for every configuration ever published and
      // could never fail.
      dispatchRequiredFacts: (() => {
        const d = ((window.drupalSettings || {}).custom_webform_handlers || {}).dispatch;
        const profiles = d && d.booking_profiles;
        if (!profiles || typeof profiles !== 'object') { return null; }
        const out = [];
        const walk = (value) => {
          if (Array.isArray(value)) { value.forEach(walk); }
          else if (value && typeof value === 'object') { Object.values(value).forEach(walk); }
          else if (value !== null && value !== '' && value !== undefined) {
            out.push(String(value));
          }
        };
        Object.values(profiles).forEach((profile) => {
          walk((profile && profile.required_facts) || []);
        });
        return Array.from(new Set(out)).sort();
      })(),
    };
  `);
}

/** Drives whichever date control the gate was told is canonical. */
export function setDateField(browser, name, value) {
  return evaluate(browser, `
    const [name, value] = arguments;
    const el = named(name).find((c) => shown(c));
    if (!el) { return { ok: false, reason: 'not-visible' }; }
    el.focus();
    el.value = value;
    fire(el);
    return { ok: true, value: el.value };
  `, [name, value]);
}

/** Resource request names only — no query strings, headers, or bodies. */
export function requestNames(browser) {
  return evaluate(browser, `
    return performance.getEntriesByType('resource')
      .map((e) => String(e.name).split('?')[0].replace(location.origin, ''))
      .filter((n) => /dispatch|hold|reserve|reservation|checkout|payment|renew|release|offer|token|distance/i.test(n));
  `);
}

/**
 * Posts one offer request from the page, exactly as the widget does.
 *
 * The authoritative half of the incomplete-vs-refused question is the server's,
 * and this is the only way to ask it without inventing a client: same session,
 * same CSRF token endpoint, same route, same body shape. `omit` drops named
 * facts from the collected values, which is how a request that does not describe
 * a job yet is produced without touching the form, the configuration, or the
 * eligibility rules.
 *
 * The body is assembled by the widget's own rule — dispatch_slots.js
 * currentValues(), which is collectValues() over dispatch.value_fields on top of
 * quote_defaults merged with the stored wizard context. Mirroring it is load
 * bearing rather than tidy. An earlier version read `element.value` for every
 * named control it found, which is the checkbox's value attribute and says
 * nothing about whether the box is ticked: page 2 renders the move-services
 * add-on boxes unticked, so that sweep sent packing_services=1,
 * cleaning_services=1 and disposal_donation_interest=1 on a Courier walk that
 * selected none of them. The server then refused a request the harness had
 * invented — correctly, since an add-on with no configured duration is not
 * schedulable — and the refusal read as a canonicalization failure. The widget's
 * collector skips an unticked box, drops the published default for any field the
 * current page actually renders, and collects bracketed or repeated names into a
 * list; all three behaviours are reproduced below.
 *
 * Returns the HTTP status and the response body with slot lists summarised.
 */
export function postOffer(browser, date, { omit = [], set = {} } = {}) {
  // /execute/async, because this awaits two real network round trips: the CSRF
  // token and the offer itself. The sync executor would return before either.
  return browser.jsAsync(HELPERS + `
    const [date, omit, set] = arguments;
    const done = arguments[arguments.length - 1];
    (async () => {
      const bucket = window.drupalSettings.custom_webform_handlers || {};
      const dispatch = bucket.dispatch || {};
      const form = document.querySelector('[data-custom-webform-quote]');
      // readQuoteContext(): page one writes the Webform-wide key and Drupal mints
      // a draft UUID on Next, so page two inherits the root key before overlaying
      // the draft-scoped one. Read-only, and the same TTL the widget honours.
      const readContext = (key) => {
        if (!key) { return {}; }
        try {
          const raw = window.sessionStorage.getItem(key);
          if (!raw) { return {}; }
          const parsed = JSON.parse(raw);
          const savedAt = Number(parsed && parsed.at);
          if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)
            || !Number.isFinite(savedAt) || Date.now() - savedAt > 1800000) {
            return {};
          }
          return parsed.input && typeof parsed.input === 'object'
            && !Array.isArray(parsed.input) ? parsed.input : {};
        }
        catch (error) { return {}; }
      };
      const contextKey = bucket.quote_context_key || '';
      const rootKey = String(contextKey).replace(/:[^:]+$/, '');
      const context = rootKey && rootKey !== contextKey
        ? Object.assign({}, readContext(rootKey), readContext(contextKey))
        : readContext(contextKey);
      const values = Object.assign({}, bucket.quote_defaults || {}, context);
      // collectValues(), rule for rule.
      (dispatch.value_fields || []).forEach((name) => {
        if (!name || !form) { return; }
        const elements = form.querySelectorAll(
          '[name="' + name + '"], [name^="' + name + '["]');
        if (!elements.length) { return; }
        delete values[name];
        elements.forEach((element) => {
          if ((element.type === 'checkbox' || element.type === 'radio')
            && !element.checked) { return; }
          const many = element.name.indexOf('[') !== -1
            || (element.type === 'checkbox' && elements.length > 1);
          if (many) {
            if (!Array.isArray(values[name])) { values[name] = []; }
            values[name].push(element.value);
          }
          else { values[name] = element.value; }
        });
      });
      // The canonical date is forced to the day being asked about, because the
      // server refuses a body date that disagrees with the form's own date field
      // (DispatchBooking::offer). A caller that wants to test that disagreement
      // can still override it through the set option.
      values.preferred_service_date = date;
      omit.forEach((name) => { delete values[name]; });
      Object.keys(set).forEach((name) => { values[name] = set[name]; });
      const token = await (await fetch(dispatch.csrf_token_url,
        { credentials: 'same-origin' })).text();
      const response = await fetch(dispatch.offer_url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token },
        body: JSON.stringify({ date: date, values: values }),
      });
      const body = await response.json().catch(() => null);
      if (body && Array.isArray(body.slots)) {
        body.slots = { count: body.slots.length,
          firstAt: body.slots.length ? body.slots[0].at : null };
      }
      done({ status: response.status, sentKeys: Object.keys(values).sort(), body: body });
    })().catch((error) => done({ error: String((error && error.message) || error) }));
  `, [date, omit, set]);
}

/**
 * Removes a planning fact from every source the widget collects values from.
 *
 * Page 2 carries none of the page-1 controls, so a fact reaches an offer request
 * only through the server-published defaults or the wizard's stored context.
 * Dropping it from both reproduces a page-2 render that legitimately carries no
 * such default — a resumed draft, an element the profile maps but the form does
 * not ask yet, a form whose fact questions sit after the widget page — without
 * altering the form, the configuration, or any eligibility rule. The Express
 * Courier wizard itself requires these answers on page 1, so this is the honest
 * way to exercise the server's fail-safe rather than a customer's ordinary walk.
 */
export function stripFact(browser, name) {
  return evaluate(browser, `
    const [name] = arguments;
    const bucket = window.drupalSettings.custom_webform_handlers || {};
    const before = bucket.quote_defaults
      ? Object.prototype.hasOwnProperty.call(bucket.quote_defaults, name) : false;
    if (bucket.quote_defaults) { delete bucket.quote_defaults[name]; }
    const dropped = [];
    for (let i = window.sessionStorage.length - 1; i >= 0; i -= 1) {
      const key = window.sessionStorage.key(i);
      if (/quote|context|webform/i.test(key || '')) {
        dropped.push(key);
        window.sessionStorage.removeItem(key);
      }
    }
    const control = document.querySelector('[name="' + name + '"]');
    return { name, wasInDefaults: before, storageKeysDropped: dropped.length,
      controlOnThisPage: !!control };
  `, [name]);
}

/** Re-fires the date control so the widget re-asks with the current values. */
export function renotifyDate(browser, name) {
  return evaluate(browser, `
    const [name] = arguments;
    const el = named(name).find((c) => shown(c)) || named(name)[0];
    if (!el) { return { ok: false, reason: 'absent' }; }
    fire(el);
    return { ok: true, value: el.value };
  `, [name]);
}

/**
 * Sidebar wording and figures, so a suppressed price line cannot hide either
 * elsewhere on the page.
 *
 * The widget's status line is not the only place a verdict can be published: the
 * summary region renders the same two server authorities. So the review strings,
 * the review note, the neutral unfinished note and the printed total are all
 * counted here, and a state is only what it claims to be if this agrees.
 */
export function sidebarWording(browser) {
  return evaluate(browser, `
    const totalNode = document.querySelector(
      '.custom-quote-summary-sidebar__total-amount');
    const status = document.querySelector('[data-quote-summary-status]');
    return {
      estimateNeedsReview: countText('Estimate needs review'),
      pendingManualReview: countText('Pending manual review'),
      cannotBePricedNote: countText('cannot be priced automatically'),
      needsAPersonNote: countText('need a person to confirm them'),
      completeRemainingNote: countText('Complete the remaining required details'),
      noEstimateYet: countText('No estimate yet.'),
      lineItemCount: all('.custom-quote-summary-sidebar__item').length,
      totalText: totalNode ? String(totalNode.textContent || '').trim() : '',
      statusText: status ? String(status.textContent || '').trim() : '',
    };
  `);
}

/**
 * Selects the first offered pickup time, which is a local act only.
 *
 * Selecting takes nothing: the widget creates no Hold, renews nothing, releases
 * nothing, and capacity is claimed on the server when checkout starts. So this
 * is safe to do in a read-only gate, and it is the only way to prove the claim
 * the brief makes about a date change — that a selection made for the old day
 * does not survive into the new one.
 */
export function clickSlot(browser) {
  return evaluate(browser, `
    const button = all('.custom-webform-dispatch__slot').filter((el) => shown(el))[0];
    if (!button) { return { ok: false, reason: 'no-slot' }; }
    button.click();
    const input = named('dispatch_slot')[0] || null;
    return { ok: true, at: Number(button.getAttribute('data-at')),
      value: input ? String(input.value || '') : null };
  `);
}

/**
 * Answers one non-Courier service's page 1 — and nothing else.
 *
 * The browser reports exactly three controls it will refuse to submit unanswered
 * for every service except Courier: the service radio and the two addresses. That
 * is why the service is a parameter and the body is not — all six share this
 * page-1 shape, and a second copy of the sequence per service would be a second
 * definition of what the customer did. It defaults to the On-Demand pilot so the
 * gates written before the other five existed still read the same.
 *
 * Answering anything further would be the mistake the Courier walk already made
 * once: a filler that types into a question the brief says must not be asked turns
 * a scope violation into a green run. So the retired and other-service questions
 * are deliberately left alone here and their absence is scored instead. The
 * service's own page-2 facts are answered on page 2, by the caller, because that
 * is where the form asks them.
 */
export async function answerPilotPage1(browser, service = PILOT_SERVICE) {
  const trace = { service };
  trace.service_type = await ensureRadio(browser,
    `input[name="service_type"][value="${service}"]`);
  trace.branchSettled = await waitFor(browser, `the ${service} branch to render`,
    async () => (await browser.js(`
      const el = document.querySelector('[name="origin_address"]');
      return !!el && el.offsetParent !== null;
    `)) === true);
  trace.quiet = await formQuiet(browser);

  // Typed only when empty. A wizard Back carries the addresses as stored values,
  // and re-typing one wakes the Places widget's input listener, which clears the
  // submitted value until a suggestion is accepted again — so a walk that retyped
  // an address it already had could arrive with none. Which path ran is recorded.
  const addressLengths = await browser.js(`
    const len = (name) => {
      const el = document.querySelector('[name="' + name + '"]');
      return el ? String(el.value || '').length : -1;
    };
    return { origin: len('origin_address'), destination: len('destination_address') };
  `);
  trace.addressesCarried = addressLengths;
  if (addressLengths.origin < 5) {
    trace.origin_address = await typeAddress(browser, 'origin_address', SAMPLE.origin_address);
  }
  if (addressLengths.destination < 5) {
    trace.destination_address = await typeAddress(browser, 'destination_address',
      SAMPLE.destination_address);
  }

  // Page 1 must still be the page: an Enter keystroke landing outside an open
  // suggestion list submits the wizard, and every later reading would then be of
  // the wrong page while looking like a rendering failure.
  trace.page1 = await browser.js(`
    const shown = (el) => !!el && el.offsetParent !== null;
    const named = (name) => Array.from(document.querySelectorAll('[name="' + name + '"]'));
    const val = (name) => {
      const els = named(name);
      const checkable = els.filter((el) => el.type === 'checkbox' || el.type === 'radio');
      if (checkable.length) {
        const on = checkable.find((el) => el.checked);
        return on ? String(on.value) : '';
      }
      return els.length ? String(els[0].value || '') : '';
    };
    return {
      onPage1: document.querySelector('[name="service_type"]') !== null,
      onPage2: document.querySelector('[name="dispatch_slot"]') !== null,
      service_type: val('service_type'),
      origin_address: val('origin_address').length,
      destination_address: val('destination_address').length,
      // The questions this service must not be asked, counted where they would
      // render. Visible *and* present, so a CSS-hidden survivor is still seen.
      preferredServiceTimeVisible: named('preferred_service_time').filter(shown).length,
      dateFlexibilityVisible: named('date_flexibility').filter(shown).length,
      singleDriverVisible: named('single_driver_handling').filter(shown).length,
      deliveryModeVisible: named('delivery_mode').filter(shown).length,
      weightVisible: named('total_weight_lbs').filter(shown).length,
      attestationVisible: named('courier_prohibited_ack').filter(shown).length,
      blockedControls: Array.from(document.querySelectorAll('input, select, textarea'))
        .filter((el) => shown(el) && el.willValidate && !el.checkValidity())
        .map((el) => String(el.name || el.id || 'unnamed')),
    };
  `);

  return trace;
}

/**
 * Ticks a checkbox that may already be ticked.
 *
 * `clickControl()` delivers a real click, and a real click on a ticked box unticks
 * it. That is the right instrument for a first answer and the wrong one for a walk
 * that returns to page 1 through the wizard: re-answering the page would silently
 * withdraw the contents attestation and the paid-and-ready confirmation, and the
 * refusal that followed would be scored as the form's opinion of the change under
 * test rather than as the walk's own doing. Reading the box first makes answering it
 * idempotent, which is what a returning customer's page 1 actually is.
 */
async function ensureChecked(browser, css) {
  const already = await browser.js(`
    const [css] = arguments;
    const el = document.querySelector(css);
    return el ? el.checked === true : null;
  `, [css]);
  if (already === true) {
    return { ok: true, via: 'already-checked', checked: true };
  }

  return clickControl(browser, css);
}

/**
 * Selects a radio and proves it took, re-clicking once if it did not.
 *
 * A click on a radio can be delivered and still leave nothing selected: the branch
 * these radios control is rebuilt as the page settles, and a click that lands during
 * the rebuild is spent on a node that is about to be replaced. It happened once in a
 * 32-session run — `service_type` reported `checked: false`, the courier branch never
 * rendered, and the walk was scored as a form that would not accept a valid request.
 * That reading would have been wrong about the form and right about nothing.
 *
 * Safe to repeat: a radio only ever goes from unselected to selected, so unlike a
 * checkbox a second click cannot withdraw the answer. The verified state is returned
 * either way, so a run that needed the retry says so instead of hiding it.
 */
export async function ensureRadio(browser, css) {
  const first = await clickControl(browser, css);
  if (first.checked === true) {
    return first;
  }
  const settled = await browser.js(`
    const [css] = arguments;
    const el = document.querySelector(css);
    return el ? el.checked === true : null;
  `, [css]);
  if (settled === true) {
    return { ...first, checked: true, via: `${first.via || 'unknown'}-settled` };
  }
  const retry = await clickControl(browser, css);

  return { ...retry, via: `${retry.via || retry.reason || 'unknown'}-retried`, firstAttempt: first };
}

/**
 * Answers Express Courier's page 1, the control case.
 *
 * Kept to the answers the acceptance walk uses, in the same order, because this
 * gate's Courier half exists to be compared against that walk rather than to
 * re-specify it. `single_driver_handling` is again never answered: the server
 * derives that fact from the service policy and the control is retired.
 *
 * `mode` selects the handoff the customer asked for. Store Pickup is not a variant
 * of the same answers: the form gates `store_pickup_merchant`,
 * `store_pickup_order_reference` and `order_is_paid_and_ready` on it, and all three
 * are required once it is chosen. They are answered here only for that mode, so the
 * Send walk still types into nothing the customer cannot see.
 */
export async function answerCourierPage1(browser, {
  weight = SAMPLE.total_weight_lbs,
  mode = SAMPLE.delivery_mode,
} = {}) {
  const trace = { mode };
  trace.service_type = await ensureRadio(browser,
    `input[name="service_type"][value="${SAMPLE.service_type}"]`);
  trace.branchSettled = await waitFor(browser, 'the courier branch to render',
    async () => (await browser.js(
      `return document.querySelector('[name="delivery_mode"]') !== null;`)) === true);
  trace.quiet = await formQuiet(browser);
  trace.delivery_mode = await ensureRadio(browser,
    `input[name="delivery_mode"][value="${mode}"]`);

  if (mode === 'courier_store_pickup') {
    trace.storePickupRendered = await waitFor(browser, 'the store-pickup questions to render',
      async () => (await browser.js(`
        const el = document.querySelector('[name="store_pickup_merchant"]');
        return !!el && el.offsetParent !== null;
      `)) === true);
    trace.store_pickup_merchant = await typeInto(browser,
      'input[name="store_pickup_merchant"]', SAMPLE.store_pickup_merchant);
    trace.store_pickup_order_reference = await typeInto(browser,
      'input[name="store_pickup_order_reference"]', SAMPLE.store_pickup_order_reference);
    trace.order_is_paid_and_ready = await ensureChecked(browser,
      'input[name="order_is_paid_and_ready"]');
  }

  // Addresses survive a wizard Back as stored values, and re-typing one would
  // wake the Places widget's input listener, which clears the submitted value
  // until a suggestion is accepted again. So they are only typed when empty, and
  // which path ran is recorded.
  const addressLengths = await browser.js(`
    const len = (name) => {
      const el = document.querySelector('[name="' + name + '"]');
      return el ? String(el.value || '').length : -1;
    };
    return { origin: len('origin_address'), destination: len('destination_address') };
  `);
  trace.addressesCarried = addressLengths;
  if (addressLengths.origin < 5) {
    trace.origin_address = await typeAddress(browser, 'origin_address', SAMPLE.origin_address);
  }
  if (addressLengths.destination < 5) {
    trace.destination_address = await typeAddress(browser, 'destination_address',
      SAMPLE.destination_address);
  }

  trace.weightRendered = await waitFor(browser, 'the weight question to render',
    async () => (await browser.js(`
      const el = document.querySelector('[name="total_weight_lbs"]');
      return !!el && el.offsetParent !== null;
    `)) === true);
  trace.total_weight_lbs = await typeInto(browser, 'input[name="total_weight_lbs"]',
    String(weight));
  trace.attestationRendered = await waitFor(browser, 'the contents attestation to render',
    async () => (await browser.js(`
      const el = document.querySelector('[name="courier_prohibited_ack"]');
      return !!el && el.offsetParent !== null;
    `)) === true);
  trace.courier_prohibited_ack = await ensureChecked(browser,
    'input[name="courier_prohibited_ack"]');

  return trace;
}
