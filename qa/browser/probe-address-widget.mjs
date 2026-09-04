// Probe: how a real customer fills the address fields.
//
// The Webform textfield is visually-hidden and aria-hidden; the control a person
// actually types into is a <gmp-place-autocomplete> web component, and
// distance_calculator.js only writes the submitted value on the widget's
// 'gmp-select' event. So an acceptance that types into the textfield is not
// reproducing a customer at all. This prints the widget's structure and whether
// typing plus keyboard selection populates the submitted value, so the acceptance
// walk can drive the real control.
//
// Usage: ddev exec node qa/browser/probe-address-widget.mjs
import { Browser, sleep } from './webdriver.mjs';
import { FORM_URL, SAMPLE } from './courier-flow.mjs';

const W3C_ELEMENT = 'element-6066-11e4-a52e-4f615166544b';
const W3C_SHADOW = 'shadow-6066-11e4-a52e-4f615166544b';
const KEY_DOWN = '';
const KEY_ENTER = '';

const handle = (entry) => (entry && entry[W3C_ELEMENT] !== undefined
  ? entry[W3C_ELEMENT]
  : (entry && typeof entry === 'object' ? Object.values(entry)[0] : entry));

const browser = await Browser.open();
try {
  await browser.viewport(1440, 900);
  await browser.get(FORM_URL);
  await browser.until('the form', async () =>
    (await browser.js(`return document.querySelector('[name="service_type"]') !== null;`)) === true);

  // Courier, so the address questions are the courier ones.
  const label = handle((await browser.send('POST', '/elements', {
    using: 'css selector', value: 'label[for="edit-service-type-courier"]',
  }))[0]);
  await browser.send('POST', `/element/${label}/click`, {});
  await sleep(1500);

  console.log('structure:', JSON.stringify(await browser.js(`
    const widgets = Array.from(document.querySelectorAll('gmp-place-autocomplete'));
    return {
      widgets: widgets.length,
      ids: widgets.map((w) => w.id),
      shadowOpen: widgets.map((w) => !!w.shadowRoot),
      shadowChildren: widgets.map((w) => w.shadowRoot
        ? Array.from(w.shadowRoot.querySelectorAll('*')).slice(0, 12)
          .map((el) => el.tagName.toLowerCase() + (el.className ? '.' + String(el.className).split(' ')[0] : ''))
        : null),
      hiddenInputs: ['origin_address', 'destination_address'].map((name) => {
        const el = document.querySelector('[name="' + name + '"]');
        return el ? { name, classes: el.className, ariaHidden: el.getAttribute('aria-hidden'), value: el.value } : null;
      }),
      mapsLoaded: typeof google !== 'undefined' && !!(google.maps),
    };
  `), null, 2));

  // Shadow-root traversal through WebDriver, which is how a click reaches the
  // real input without any script touching the page.
  const widgets = (await browser.send('POST', '/elements', {
    using: 'css selector', value: 'gmp-place-autocomplete',
  })).map(handle);
  console.log('widget handles:', widgets.length);

  const shadow = await browser.send('GET', `/element/${widgets[0]}/shadow`).catch((e) => String(e.message));
  console.log('shadow root:', typeof shadow === 'string' ? shadow : Object.keys(shadow));
  if (typeof shadow === 'string') {
    process.exit(0);
  }
  const shadowId = shadow[W3C_SHADOW] !== undefined ? shadow[W3C_SHADOW] : Object.values(shadow)[0];
  const inners = (await browser.send('POST', `/shadow/${shadowId}/elements`, {
    using: 'css selector', value: 'input',
  })).map(handle);
  console.log('inner inputs:', inners.length);
  if (!inners.length) {
    process.exit(0);
  }

  const everything = (await browser.send('POST', `/shadow/${shadowId}/elements`, {
    using: 'css selector', value: '*',
  })).map(handle);
  const described = [];
  for (const id of everything.slice(0, 14)) {
    described.push({
      tag: await browser.send('GET', `/element/${id}/name`),
      displayed: await browser.send('GET', `/element/${id}/displayed`).catch(() => 'err'),
      type: await browser.send('GET', `/element/${id}/attribute/type`),
      role: await browser.send('GET', `/element/${id}/attribute/role`),
      readonly: await browser.send('GET', `/element/${id}/property/readOnly`).catch(() => null),
    });
  }
  console.log('shadow contents:', JSON.stringify(described, null, 1));

  // Typing through the Actions API: click to focus, then dispatch keystrokes at
  // the session level rather than at an element. That is what a person does, and
  // it does not care whether the remote end considers a shadow input "interactable".
  const type = async (text) => {
    const actions = [];
    for (const ch of text) {
      actions.push({ type: 'keyDown', value: ch }, { type: 'keyUp', value: ch });
    }
    await browser.send('POST', '/actions', {
      actions: [{ type: 'key', id: 'keyboard', actions }],
    });
  };

  await browser.send('POST', `/element/${inners[0]}/click`, {});
  console.log('active element after click:', await browser.js(`
    const a = document.activeElement;
    return a ? (a.tagName.toLowerCase() + (a.id ? '#' + a.id : '')) : null;
  `));
  await type('20 W 34th St, New York');
  await sleep(2500);
  console.log('predictions visible:', JSON.stringify(await browser.js(`
    const w = document.querySelector('gmp-place-autocomplete');
    const root = w && w.shadowRoot;
    if (!root) { return null; }
    const items = Array.from(root.querySelectorAll('[role="option"], li, .place-autocomplete-element-row'));
    return { count: items.length, first: items.length ? String(items[0].textContent || '').slice(0, 60) : null };
  `)));

  await type(KEY_DOWN);
  await sleep(400);
  await type(KEY_ENTER);
  await sleep(3000);
  console.log('after keyboard select:', JSON.stringify(await browser.js(`
    const el = document.querySelector('[name="origin_address"]');
    const status = document.querySelector('.custom-address-autocomplete-status');
    return {
      submittedValue: el ? el.value : null,
      status: status ? String(status.textContent || '').trim() : null,
    };
  `)));

  const logs = await browser.consoleLog();
  console.log('console:', JSON.stringify(logs.filter((l) => /SEVERE|WARNING/.test(l.level || ''))
    .map((l) => String(l.message).slice(0, 160)).slice(0, 8), null, 2));
  console.log('sample for reference:', SAMPLE.origin_address);
}
finally {
  await browser.quit();
}
