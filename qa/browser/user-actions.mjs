// Real user actions over W3C WebDriver — the input path the acceptance walks
// share, extracted so a second harness cannot drift from the first.
//
// Everything here delivers a click or a keystroke to a control the browser
// reports as displayed. Nothing assigns a DOM property and nothing composes a
// network request: a value written from page script proves the widget reacts to
// an event, not that a customer can produce that state with a mouse and a
// keyboard. This module was lifted verbatim out of courier-final-acceptance.mjs
// when the On-Demand pilot needed the same instruments — in particular the
// Places shadow-root traversal, which is the one part of this form that punishes
// a second implementation.

import { Browser, sleep } from './webdriver.mjs';

// ---------------------------------------------------------------------------
// Raw W3C element commands. webdriver.mjs exposes `send`, so the element
// endpoints are reachable without touching the shared client.
// ---------------------------------------------------------------------------

export const W3C_ELEMENT = 'element-6066-11e4-a52e-4f615166544b';
export const W3C_SHADOW = 'shadow-6066-11e4-a52e-4f615166544b';
export const KEY_ESCAPE = '\uE00C';
export const KEY_ENTER = '\uE007';
export const KEY_ARROW_DOWN = '\uE015';
export const KEY_CONTROL = '\uE009';

/**
 * The handle out of a W3C element or shadow-root reference.
 *
 * The standard key is read by name where present and by position otherwise: it is
 * what Chromedriver sends today, but a reference object carries exactly one
 * property either way, and a `/element/undefined/\u2026` request is a confusing way
 * to learn that a remote end used a different name. This one did: its shadow
 * references came back under `shadow-6066-11e4-a52e-4f735466cecf`.
 */
export function handleOf(reference, key) {
  if (reference && reference[key] !== undefined) {
    return reference[key];
  }
  if (reference && typeof reference === 'object') {
    return Object.values(reference)[0];
  }
  return reference;
}

export const usable = (id) => typeof id === 'string' && id !== '';

/** Element handles for a selector. */
export async function findAll(browser, css) {
  const found = await browser.send('POST', '/elements', {
    using: 'css selector',
    value: css,
  });
  return (found || []).map((entry) => handleOf(entry, W3C_ELEMENT)).filter(usable);
}

/** Element handles for a selector inside a shadow root. */
export async function findAllInShadow(browser, shadowId, css) {
  const found = await browser.send('POST', `/shadow/${shadowId}/elements`, {
    using: 'css selector',
    value: css,
  });
  return (found || []).map((entry) => handleOf(entry, W3C_ELEMENT)).filter(usable);
}

// Chromedriver rejects a bodyless POST on these two with "missing command
// parameters", so the empty object is required rather than decorative.
export const elClick = (browser, id) => browser.send('POST', `/element/${id}/click`, {});
export const elKeys = (browser, id, text) => browser.send('POST', `/element/${id}/value`, { text });
export const elClear = (browser, id) => browser.send('POST', `/element/${id}/clear`, {});
export const elShown = (browser, id) => browser.send('GET', `/element/${id}/displayed`);
export const elProp = (browser, id, name) => browser.send('GET', `/element/${id}/property/${name}`);

/** Minimal attribute-selector escaping; element ids here are plain. */
export const CSS_escape = (value) => String(value).replace(/(["\\])/g, '\\$1');

/**
 * True for the one WebDriver error a re-render legitimately produces.
 *
 * A handle names a node, not a selector, so it dies the moment that node is
 * detached. Webform detaches plenty: the wizard's Back returns a freshly built
 * page 1, and a conditional branch re-renders its region again once the browser
 * settles. Anything else that throws is a real fault and must keep propagating.
 */
export const staleReference = (error) =>
  /stale element/i.test(String((error && error.message) || error));

/**
 * A *read* that survives one re-render, by looking the control up again.
 *
 * Only reads are retried, never a click or a keystroke: repeating a click on a
 * checkbox would untick it, and repeating keystrokes would double the typed text.
 * So the caller passes the selector it found the handle with, and a detached node
 * costs one extra lookup instead of ending the walk.
 */
export async function readAgain(browser, css, id, read) {
  try {
    return await read(id);
  }
  catch (error) {
    if (!staleReference(error)) {
      throw error;
    }
    const again = await findAll(browser, css);
    if (!again.length) {
      throw error;
    }
    return read(again[0]);
  }
}

/**
 * A read whose answer is genuinely unknowable if the document went away.
 *
 * Used for the state a control was left in by a click that turned out to be a
 * navigation — the wizard's Back and Next are submits, so the node just clicked
 * belongs to a document that no longer exists. Its `checked` property is not
 * false, it is unanswerable, and reporting null says so. Treating it as an error
 * is what ended a walk at the exact moment its click had worked.
 */
export async function readOptional(browser, css, id, read) {
  try {
    return await read(id);
  }
  catch (error) {
    if (!staleReference(error)) {
      throw error;
    }
    const again = await findAll(browser, css);
    if (!again.length) {
      return null;
    }
    try {
      return await read(again[0]);
    }
    catch (retry) {
      if (staleReference(retry)) {
        return null;
      }
      throw retry;
    }
  }
}

/**
 * Waits for the form to stop being rebuilt underneath the harness.
 *
 * This is the faithful wait, not a workaround for one: a person looks at the page
 * and acts on what has finished rendering, whereas a script can find a control in
 * the document Webform is halfway through replacing and lose it a moment later.
 * The reading is deliberately crude — two consecutive identical control counts
 * with no jQuery request in flight and the document complete — because the precise
 * mechanism differs between a wizard POST and an AJAX region rebuild, and both end
 * the same way: nothing is changing any more.
 *
 * Expiry is returned, not thrown, for the same reason waitFor() returns it.
 */
export async function formQuiet(browser, timeout = 20000) {
  const sample = () => browser.js(`
    const jq = window.jQuery;
    return {
      controls: document.querySelectorAll('input, select, textarea, button').length,
      ajax: (jq && typeof jq.active === 'number') ? jq.active : 0,
      ready: String(document.readyState || ''),
    };
  `);
  const deadline = Date.now() + timeout;
  let previous = await sample();
  while (Date.now() < deadline) {
    await sleep(350);
    const current = await sample();
    if (current.ajax === 0 && current.ready === 'complete'
      && current.controls === previous.controls) {
      return { ok: true, controls: current.controls };
    }
    previous = current;
  }
  return { ok: false, reason: 'the form was still changing', last: previous };
}

/**
 * Clicks a control the way a customer reaches it.
 *
 * Radios and checkboxes in this theme are frequently painted as cards with the
 * real input clipped out of the layout, so the input itself is not clickable and
 * WebDriver correctly refuses. A person clicks the card, which is the label, and
 * the browser forwards the activation. Falling back to the label is therefore
 * more faithful than forcing the input, not a workaround — and if neither is
 * reachable that is reported rather than papered over with a scripted assignment.
 *
 * `checked` is null when the click turned out to be a navigation: the wizard's
 * Back and Next are submits, so the node just clicked belongs to a document that
 * no longer exists and its state is unanswerable rather than false. A caller that
 * cares about a tick reads it; a caller pressing a submit ignores it. Only a
 * disappearance before the click is a failure, because then nothing was delivered.
 */
export async function clickControl(browser, css) {
  const ids = await findAll(browser, css);
  if (!ids.length) {
    return { ok: false, reason: 'absent', css };
  }
  const id = ids[0];
  const shown = await readOptional(browser, css, id, (h) => elShown(browser, h));
  if (shown === null) {
    return { ok: false, reason: 'vanished-mid-lookup', css };
  }
  if (shown) {
    try {
      await elClick(browser, id);
      return {
        ok: true,
        via: 'input',
        checked: await readOptional(browser, css, id, (h) => elProp(browser, h, 'checked')),
      };
    }
    catch (error) {
      if (staleReference(error)) {
        // The node went away between the lookup and the click, so nothing was
        // delivered to anything; finding it again and clicking once is still one
        // click.
        const again = await findAll(browser, css);
        if (again.length && (await elShown(browser, again[0]))) {
          await elClick(browser, again[0]);
          return {
            ok: true,
            via: 'input-refound',
            checked: await readOptional(browser, css, again[0], (h) => elProp(browser, h, 'checked')),
          };
        }
        return { ok: false, reason: 'vanished-mid-click', css };
      }
      // Displayed but obscured; the label is the reachable surface.
    }
  }
  const domId = await readOptional(browser, css, id, (h) => elProp(browser, h, 'id'));
  if (domId) {
    const labels = await findAll(browser, `label[for="${CSS_escape(domId)}"]`);
    if (labels.length && (await elShown(browser, labels[0]))) {
      await elClick(browser, labels[0]);
      const inputs = await findAll(browser, css);
      return {
        ok: true,
        via: 'label',
        checked: inputs.length
          ? await readOptional(browser, css, inputs[0], (h) => elProp(browser, h, 'checked'))
          : null,
      };
    }
  }
  return { ok: false, reason: 'not-interactable', css };
}

/** Clicks into a text field and types, as a person would. */
export async function typeInto(browser, css, text, { dismiss = false } = {}) {
  const ids = await findAll(browser, css);
  if (!ids.length) {
    return { ok: false, reason: 'absent', css };
  }
  const id = ids[0];
  if (!(await readAgain(browser, css, id, (h) => elShown(browser, h)))) {
    return { ok: false, reason: 'not-visible', css };
  }
  await elClick(browser, id);
  try {
    await elClear(browser, id);
  }
  catch {
    // An empty field refuses clear on some builds; typing is still correct.
  }
  await elKeys(browser, id, text);
  if (dismiss) {
    // Google Places renders a suggestion list over the following field, and a
    // list left open swallows the next click. Escape closes it; a customer either
    // presses it or clicks elsewhere, and the typed text stays either way.
    await elKeys(browser, id, KEY_ESCAPE);
  }
  return { ok: true, value: await readAgain(browser, css, id, (h) => elProp(browser, h, 'value')) };
}

/**
 * Session-level keystrokes, delivered to whatever currently has focus.
 *
 * The W3C Actions API is the only way in to the address control: its real input
 * lives in a closed shadow root, and an element-scoped `POST /element/{id}/value`
 * against it answers "element not interactable". A person's keyboard is not
 * element-scoped either, so this is the faithful instrument rather than a
 * workaround for one.
 */
export async function typeKeys(browser, text) {
  const actions = [];
  for (const ch of text) {
    actions.push({ type: 'keyDown', value: ch }, { type: 'keyUp', value: ch });
  }
  await browser.send('POST', '/actions', {
    actions: [{ type: 'key', id: 'keyboard', actions }],
  });
}

/** Ctrl+A on the focused control, then the modifier is released. */
export async function selectAllKeys(browser) {
  await browser.send('POST', '/actions', {
    actions: [{
      type: 'key',
      id: 'keyboard',
      actions: [
        { type: 'keyDown', value: KEY_CONTROL },
        { type: 'keyDown', value: 'a' },
        { type: 'keyUp', value: 'a' },
        { type: 'keyUp', value: KEY_CONTROL },
      ],
    }],
  });
  // Releases the remote end's input state so a held modifier cannot leak into
  // the next field's keystrokes.
  await browser.send('DELETE', '/actions').catch(() => {});
}

/**
 * Counts prediction rows inside the widget's shadow root.
 *
 * This matters more than it looks. Enter with no highlighted suggestion is not a
 * no-op: inside a form it triggers implicit submission, which is how a blind
 * ArrowDown+Enter walked one scenario off page 1 and made the weight question
 * "fail to render". So the list is looked at before Enter is ever pressed. Page
 * script cannot do this — the root is closed — but WebDriver can, and it is the
 * same list a person is looking at when they press the key.
 */
export async function predictionRows(browser, shadowId) {
  for (const css of ['[role="option"]', '.place-autocomplete-element-row', 'li']) {
    const ids = await findAllInShadow(browser, shadowId, css).catch(() => []);
    const displayed = [];
    for (const id of ids) {
      if (await elShown(browser, id).catch(() => false)) {
        displayed.push(id);
      }
    }
    if (displayed.length) {
      return { count: displayed.length, via: css };
    }
  }
  return { count: 0, via: null };
}

/**
 * Fills an address the way a customer does: through the Places widget.
 *
 * The Webform textfield is not the address control. distance_calculator.js
 * inserts a <gmp-place-autocomplete> after it, repoints the label at the widget,
 * and marks the textfield visually-hidden, aria-hidden and tabindex="-1" — and
 * its own `input` listener *clears* the submitted value and asks for a complete
 * address whenever the text changes without a selection. So the submitted value
 * exists only after the widget's `gmp-select` event, which only a real suggestion
 * choice produces. Typing into the textfield would set a value the module then
 * discards, and would prove nothing about what a person can do.
 *
 * The route in is therefore: WebDriver shadow-root traversal to the widget's own
 * combobox input, a click to focus it, Actions-API keystrokes, then ArrowDown and
 * Enter to accept a suggestion. What lands is Google's canonical formatting of the
 * address, not the string typed, so the typed text and the accepted value are both
 * returned and the accepted one is what gets reported.
 */
export async function typeAddress(browser, name, text) {
  const css = `input[name="${name}"]`;
  const fields = await findAll(browser, css);
  if (!fields.length) {
    return { ok: false, reason: 'absent', name };
  }
  const field = fields[0];
  const domId = await readAgain(browser, css, field, (h) => elProp(browser, h, 'id'));

  // If the widget never attached, the textfield is still the visible control and
  // typing into it is what a customer would do. Which path ran is reported.
  const widgets = domId
    ? await findAll(browser, `gmp-place-autocomplete#${CSS_escape(domId)}--place-autocomplete`)
    : [];
  if (!widgets.length) {
    const fallback = await typeInto(browser, `input[name="${name}"]`, text, { dismiss: true });
    return { ...fallback, via: 'textfield-no-widget' };
  }

  const shadowId = handleOf(
    await browser.send('GET', `/element/${widgets[0]}/shadow`),
    W3C_SHADOW,
  );
  if (!usable(shadowId)) {
    return { ok: false, reason: 'no-shadow-root', name };
  }
  const inners = await findAllInShadow(browser, shadowId, 'input');
  if (!inners.length) {
    return { ok: false, reason: 'no-widget-input', name };
  }
  // The first *displayed* input, chosen with a real await per candidate: an async
  // predicate inside .filter() returns a promise, which is always truthy, so that
  // spelling would silently accept a hidden one.
  let inner = inners[0];
  for (const candidate of inners) {
    if (await elShown(browser, candidate)) {
      inner = candidate;
      break;
    }
  }

  const submitted = () => browser.js(
    `const el = document.querySelector('[name="${name}"]'); return el ? String(el.value || '') : null;`,
  );
  const attempts = [];
  for (let attempt = 1; attempt <= 3; attempt += 1) {
    await elClick(browser, inner);
    if (attempt > 1) {
      await selectAllKeys(browser);
    }
    await typeKeys(browser, text);

    // Wait for the list Places renders, rather than guessing at a delay and then
    // pressing Enter into whatever is there.
    let rows = { count: 0, via: null };
    for (let tick = 0; tick < 12; tick += 1) {
      rows = await predictionRows(browser, shadowId);
      if (rows.count) {
        break;
      }
      await sleep(400);
    }
    if (!rows.count) {
      // No suggestions: pressing Enter here would submit the form, so close the
      // field instead and let the next attempt retype.
      await typeKeys(browser, KEY_ESCAPE);
      attempts.push({ attempt, predictions: 0, value: '' });
      continue;
    }

    await typeKeys(browser, KEY_ARROW_DOWN);
    await sleep(400);
    await typeKeys(browser, KEY_ENTER);

    let value = '';
    for (let tick = 0; tick < 20; tick += 1) {
      value = await submitted();
      if (value) {
        break;
      }
      await sleep(400);
    }
    attempts.push({ attempt, predictions: rows.count, via: rows.via, value });
    if (value) {
      return {
        ok: true, via: 'places-widget', typed: text, value,
        attempts: attempt, predictions: rows.count,
      };
    }
  }
  return { ok: false, reason: 'no-suggestion-accepted', name, tried: attempts };
}

/**
 * Types a date into the native date input and insists on the ISO result.

 *
 * A date input takes locale-ordered digits, not the ISO string, so the format is
 * tried in order and the control's own value property is the acceptance test. No
 * attempt writes to `value` directly: if none of the three keystroke orders
 * lands, the walk reports that instead of falling back to injection.
 */
export async function typeDate(browser, css, iso) {
  const ids = await findAll(browser, css);
  if (!ids.length) {
    return { ok: false, reason: 'absent', css };
  }
  const id = ids[0];
  if (!(await readAgain(browser, css, id, (h) => elShown(browser, h)))) {
    return { ok: false, reason: 'not-visible', css };
  }
  const [year, month, day] = iso.split('-');
  const attempts = [`${month}/${day}/${year}`, `${month}${day}${year}`, iso];
  const tried = [];
  for (const text of attempts) {
    await elClick(browser, id);
    try {
      await elClear(browser, id);
    }
    catch {
      // Already empty.
    }
    await elKeys(browser, id, text);
    const value = await readAgain(browser, css, id, (h) => elProp(browser, h, 'value'));
    tried.push({ text, value });
    if (value === iso) {
      return { ok: true, typed: text, value, attempts: tried.length };
    }
  }
  return { ok: false, reason: 'date-not-accepted', tried };
}

/**
 * Presses the wizard's own Next and waits for whatever it produces.
 *
 * A timeout is reported rather than thrown. Three things can keep page 2 away —
 * the browser refused to submit an incomplete answer, the server sent the page
 * back with a message, or the submit was accepted and the answer was rejected
 * silently — and they are not the same defect. Throwing names a line of harness
 * code; returning the page's own account of itself names which of the three it
 * was, and the caller still sees `ok: false`.
 *
 * A fourth outcome is none of those and is not a defect at all: the click never
 * reached the button. It is told apart by the page's own report — nothing pending,
 * nothing invalid, nothing said — and is retried once rather than scored, in the
 * same way `ensureRadio()` retries a lost radio click.
 */
export async function pressNext(browser) {
  const first = await pressNextOnce(browser);
  if (first.ok || first.reason !== 'nothing-happened') {
    return first;
  }

  // Nothing happened at all: no page 2, no server message, no control the browser
  // refused, and jQuery reports no request in flight on a document that finished
  // loading. That is a click that was delivered to the button and lost, which
  // happened once in a 32-session run and scored a form that answers correctly as a
  // form that will not advance. Pressing again is safe precisely because nothing is
  // pending — a second Next cannot skip a page, and if a submit were still running
  // this branch would not have been reached. The retry is recorded either way.
  const idle = (first.page || {}).ajaxActive === 0
    && (first.page || {}).onPage2 === false;
  if (!idle) {
    return first;
  }
  const retry = await pressNextOnce(browser);

  return { ...retry, via: 'retried', firstAttempt: first };
}

async function pressNextOnce(browser) {
  const next = await findAll(browser, '[data-drupal-selector="edit-wizard-next"]');
  if (!next.length) {
    return { ok: false, reason: 'no-next-button' };
  }
  await elClick(browser, next[0]);
  try {
    const outcome = await browser.until('page 2, a server message, or a blocked control',
      async () => (await browser.js(`
        if (document.querySelector('[name="dispatch_slot"]') !== null) { return 'page-2'; }
        if (document.querySelector('.messages--error') !== null) { return 'server-error'; }
        const blocked = Array.from(document.querySelectorAll('input, select, textarea'))
          .some((el) => el.willValidate && !el.checkValidity() && el.offsetParent !== null);
        return blocked ? 'browser-blocked' : null;
      `)), { timeout: 25000 });

    return { ok: true, outcome };
  }
  catch (error) {
    return {
      ok: false,
      reason: 'nothing-happened',
      error: String((error && error.message) || error).slice(0, 200),
      page: await browser.js(`
        const invalid = Array.from(document.querySelectorAll('input, select, textarea'))
          .filter((el) => el.willValidate && !el.checkValidity())
          .map((el) => (el.name || el.id || el.type) + (el.offsetParent === null ? ' (hidden)' : ''));
        const messages = Array.from(document.querySelectorAll('.messages'))
          .map((el) => String(el.textContent || '').replace(/\\s+/g, ' ').trim().slice(0, 160));
        return {
          invalid: invalid.slice(0, 12),
          messages: messages.slice(0, 6),
          onPage2: document.querySelector('[name="dispatch_slot"]') !== null,
          ajaxActive: (window.jQuery && typeof window.jQuery.active === 'number')
            ? window.jQuery.active : null,
          readyState: String(document.readyState || ''),
        };
      `).catch(() => null),
    };
  }
}

// ---------------------------------------------------------------------------
// Session hygiene
// ---------------------------------------------------------------------------

/**
 * A genuinely fresh anonymous session.
 *
 * Browser.open() already gives a throwaway Chrome profile, but the brief asks for
 * the clearing to be done and proven, so it is done explicitly and the emptiness
 * is measured afterwards and printed. Storage is origin-scoped, so a document on
 * the origin has to be loaded before it can be cleared; the form itself is that
 * document, and it is then requested again so the walk starts on a first paint
 * with no cookies and empty storage.
 *
 * The clearing load used to be the front page, which is the same origin and looks
 * like the cheaper choice. It is not: rendering `/` runs Commerce's outbound
 * Centarro message fetch, the container has no TLS egress, and the resulting
 * ConnectException lands in watchdog as an error-severity row. The mutation
 * sentinel gates on error rows moving, so a harness step that visits the front
 * page makes every wrapped scenario red for a reason that has nothing to do with
 * the scenario. Loading the form twice costs one request and keeps that gate
 * meaningful. Nothing about the visitor's experience changes — they still arrive
 * at the form with no cookies and no stored context.
 *
 * The form URL is a parameter rather than a module constant because two sites are
 * in play — the active one for a read-only sentinel-wrapped walk, the isolated QA
 * one for anything that may write — and a harness that hardcoded one of them
 * would silently watch the wrong database.
 */
export async function freshSession(width, height, formUrl) {
  const browser = await Browser.open();
  await browser.viewport(width, height);
  await browser.get(formUrl);
  await browser.send('DELETE', '/cookie');
  await browser.js(`
    try { window.sessionStorage.clear(); } catch (e) {}
    try { window.localStorage.clear(); } catch (e) {}
    return true;
  `);
  const cookies = (await browser.send('GET', '/cookie')) || [];
  const cleared = await browser.js(`
    return {
      sessionStorage: window.sessionStorage.length,
      localStorage: window.localStorage.length,
    };
  `);
  await browser.get(formUrl);
  await browser.until('the form and its settings to render', async () =>
    (await browser.js(`
      return document.querySelector('[name="service_type"]') !== null
        && !!(window.drupalSettings || {}).custom_webform_handlers;
    `)) === true, { timeout: 20000 });
  return {
    browser,
    reset: {
      cookiesAfterClear: cookies.length,
      sessionStorageAfterClear: cleared.sessionStorage,
      localStorageAfterClear: cleared.localStorage,
    },
  };
}

/** Severe browser console entries only, drained and accumulated by the caller. */
export async function severeConsole(browser) {
  const entries = await browser.consoleLog();
  return entries
    .filter((entry) => String(entry.level || '').toUpperCase() === 'SEVERE')
    .map((entry) => String(entry.message || '').slice(0, 200));
}

/**
 * A wait whose expiry is a recorded fact rather than a thrown run-ender.
 *
 * A filler that dies on the first slow control takes the evidence with it: the
 * run that timed out on the weight question printed nothing at all about the
 * scenario it was in, so there was nothing to diagnose. Timing out is data.
 */
export async function waitFor(browser, label, probe, timeout = 15000) {
  try {
    await browser.until(label, probe, { timeout });
    return { ok: true, label };
  }
  catch (error) {
    return { ok: false, label, reason: String(error.message || error) };
  }
}
