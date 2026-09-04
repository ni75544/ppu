/**
 * @file
 * The one read-only visit Task 10C makes to the active site.
 *
 * The dashboard was proven on ppu-qa, against a fixture, through real sessions at
 * both widths. That does not prove the page exists on the site people use, and it
 * cannot: ppu-qa is a different database. So this runs on active ppu, and it is
 * built so that being wrong about it is impossible rather than unlikely.
 *
 * It never logs in. An authenticated browser session writes a row to `sessions`,
 * which the mutation sentinel gates on, so a login here would be indistinguishable
 * from the kind of write this whole exercise is meant to rule out. The browser half
 * is therefore anonymous, and asserts its own anonymity before it asserts anything
 * else. The authenticated half is an in-process render driven from Drush, inside
 * the same sentinel bracket, so it is covered by the same before/after snapshot.
 *
 * It prints no cell values. Active ppu holds real customers, and the columns this
 * page builds for an administrator are exactly the ones that must not appear in a
 * terminal: contact, exact addresses, price. Every assertion below is about
 * structure — a status code, a column name, a count, the absence of a shape.
 *
 * Read-only. GET only; nothing is posted, and no filter is submitted, because the
 * exposed form is a GET form on ppu-qa and does not need re-proving here.
 *
 * Usage, always through the sentinel:
 *   ddev exec node qa/browser/mutation-sentinel.mjs \
 *     --runner=qa/browser/task10c-live-readonly.mjs desktop
 */
import { execFileSync } from 'node:child_process';
import { Browser } from './webdriver.mjs';
import { severeConsole } from './user-actions.mjs';

const SITE = process.env.PPU_SITE_URL || 'https://ppu.ddev.site';
const DASHBOARD = '/admin/structure/webform/dispatch-planner/reservations';
const BOARD = '/admin/structure/webform/dispatch-planner';
const RENDER = 'qa/support/task10c-live-render.php';
const ARTIFACTS = 'qa/artifacts/task10c';
const WIDTHS = { desktop: [1440, 900], mobile: [390, 844] };
const MUTATIONS = [
  '/admin/structure/webform/dispatch-planner/reservation/18/replan',
  '/admin/structure/webform/dispatch-planner/claim/18/release',
  '/admin/structure/webform/dispatch-planner/blackouts/add',
];
const EXPORT_MARKERS = ['views-data-export', 'data_export', 'views_bulk_operations', 'bulk_form', 'Apply to selected', '.csv'];
const SECRET_WORDS = ['hold_token', 'owner_hash', 'hmac', 'authorization'];
const PROTECTED_CLASSES = [
  'customer-name', 'customer-phone', 'customer-email',
  'pickup-location', 'delivery-location',
  'price-snapshot-cents', 'order-id',
];

const results = [];
const check = (label, ok, detail = '') => {
  results.push(Boolean(ok));
  console.log(`[${ok ? 'PASS' : 'FAIL'}] ${label.padEnd(46)} ${detail}`);
};

/**
 * Everything one anonymous page can be asked, without carrying text back.
 *
 * The hunt happens in the page. What returns is how many of each shape was found,
 * never the thing that was found, because on this site the thing that was found
 * would be somebody's phone number.
 */
const FACTS = `
const [markers, words, classes] = arguments;
const html = document.documentElement.outerHTML;
// Views' own DOM id and core's permissions hash are 64-character hex and belong to
// Drupal, not to a reservation. Excluded by name, so a stored hash of the same
// shape is still a failure.
const scrubbed = html
  .replace(/js-view-dom-id-[0-9a-f]{64}/g, 'js-view-dom-id-EXPECTED')
  .replace(/"permissionsHash":"[0-9a-f]{64}"/g, '"permissionsHash":"EXPECTED"');
const lower = html.toLowerCase();
const text = String(document.body ? document.body.innerText : '').replace(/\\s+/g, ' ');
return {
  title: document.title,
  status: document.title,
  // Both table classes: Claro renders core's template, Olivero overrides it and
  // carries 'views-table' instead. Anonymous gets Olivero.
  hasTable: Boolean(document.querySelector('table.views-view-table, table.views-table')),
  cells: document.querySelectorAll('td[class*="views-field-"]').length,
  protectedNodes: classes.reduce((total, name) => total + document.querySelectorAll('.views-field-' + name).length, 0),
  hex: (scrubbed.match(/\\b[0-9a-f]{40,64}\\b/) || []).length,
  markers: markers.filter((marker) => lower.indexOf(marker.toLowerCase()) !== -1),
  words: words.filter((word) => lower.indexOf(word) !== -1),
  denied: /access denied|not authorized|log in/i.test(document.title + ' ' + text.slice(0, 400)),
  uid: (window.drupalSettings && drupalSettings.user && drupalSettings.user.uid !== undefined)
    ? Number(drupalSettings.user.uid)
    : null,
  loginForm: Boolean(document.querySelector('form#user-login-form, form.user-login-form')),
  logout: Boolean(document.querySelector('a[href*="/user/logout"]')),
};
`;

const facts = (browser) => browser.js(FACTS, [EXPORT_MARKERS, SECRET_WORDS, PROTECTED_CLASSES]);

/** Severe console entries that are not the refusals this check went looking for. */
async function realSevere(browser) {
  const entries = await severeConsole(browser);
  return entries.filter((entry) => !/status of (401|403|404)/.test(entry));
}

/**
 * The authenticated half: an administrator's render of the page, in process.
 *
 * Not a browser, deliberately. Signing in as uid 1 in a browser writes a session
 * row, and the sentinel bracketing this run gates on that table — so the only way
 * to check the administrator's view of the live page without making the write
 * detector lie is to render it inside Drupal. The script it calls prints structure
 * and counts; it is written not to print a cell.
 */
function adminRender() {
  const output = execFileSync('vendor/bin/drush', ['php:script', RENDER], {
    encoding: 'utf8',
    maxBuffer: 4 * 1024 * 1024,
  });
  console.log(output.trimEnd());
  const line = output.split('\n').map((l) => l.trim()).filter((l) => l.startsWith('{')).pop();
  if (!line) {
    throw new Error('no JSON line from the render script');
  }

  return JSON.parse(line);
}

async function main() {
  const width = (process.argv[2] || 'desktop').toLowerCase();
  if (!WIDTHS[width]) {
    console.log(`usage: node qa/browser/task10c-live-readonly.mjs <${Object.keys(WIDTHS).join('|')}>`);
    console.log('VERDICT: RED');
    process.exitCode = 2;
    return;
  }
  // This check exists to say something about the active site. Pointed at ppu-qa it
  // would pass and mean nothing, which is worse than failing.
  if (SITE.includes('ppu-qa')) {
    console.log(`REFUSED: this check is the active-site visit and cannot run against ${SITE}`);
    console.log('VERDICT: RED');
    process.exitCode = 1;
    return;
  }
  const [w, h] = WIDTHS[width];
  console.log(`site=${SITE}  width=${width} ${w}x${h}  signed in: never`);

  const browser = await Browser.open();
  try {
    await browser.viewport(w, h);
    await browser.get(SITE + DASHBOARD);
    const page = await facts(browser);

    // Anonymity first: every assertion after this one is only worth making if the
    // browser really is nobody.
    check('browser is anonymous', !page.logout && (page.uid === 0 || page.uid === null), `uid=${page.uid} logout-link=${page.logout}`);
    check('dashboard is refused', page.denied || page.loginForm, page.title);
    check('no view table rendered', !page.hasTable);
    check('no Views row cell at all', page.cells === 0, `${page.cells} cells`);
    check('no protected column node', page.protectedNodes === 0, `${page.protectedNodes} nodes`);
    check('no export or bulk marker', page.markers.length === 0, page.markers.join(','));
    check('no hold-token or authorization word', page.words.length === 0, page.words.join(','));
    check('no unexplained 40/64-char hash', page.hex === 0, `${page.hex} runs`);
    await browser.screenshot(`${ARTIFACTS}/live-${width}-anonymous.png`);

    await browser.get(SITE + BOARD);
    const board = await facts(browser);
    check('pre-existing board also refused', board.denied || board.loginForm, board.title);
    check('board leaks no row cell', board.cells === 0, `${board.cells} cells`);

    for (const path of MUTATIONS) {
      await browser.get(SITE + path);
      const probe = await facts(browser);
      check(`mutation refused: ${path.split('/').pop()}`, probe.denied || probe.loginForm, probe.title);
    }

    const severe = await realSevere(browser);
    check('no severe console entry', severe.length === 0, severe.slice(0, 1).join(' '));
  }
  finally {
    await browser.quit();
  }

  // The administrator's render, in process, inside the same sentinel bracket.
  console.log('\n--- administrator render (in process, no session) ---');
  const admin = adminRender();
  check('administrator gets 200', admin.status === 200, `status ${admin.status}`);
  check('page is the Reservation Dashboard', admin.titled, admin.title);
  check('view rendered a table', admin.has_table);
  check('exposed filters present', admin.filters >= 4, `${admin.filters} controls`);
  check('rows match the live ledger', admin.rows === admin.ledger_rows, `${admin.rows} rows, ledger holds ${admin.ledger_rows}`);
  check('identifier column present', admin.columns.includes('dispatch-reservation-id'), `${admin.columns.length} columns`);
  check('administrator gets every protected column', admin.missing_protected.length === 0, admin.missing_protected.join(','));
  check('anonymous is refused in process too', admin.anonymous_status === 403, `status ${admin.anonymous_status}`);
  check('no export or bulk marker', admin.markers.length === 0, admin.markers.join(','));
  check('no hold-token or authorization word', admin.words.length === 0, admin.words.join(','));
  check('no unexplained 40/64-char hash', admin.hex === 0, `${admin.hex} runs`);
  check('no write announced while rendering', admin.watchdog_delta === 0, `watchdog moved by ${admin.watchdog_delta}`);

  const passed = results.filter(Boolean).length;
  console.log(`\nRESULT: ${passed}/${results.length} checks passed`);
  console.log(`VERDICT: ${passed === results.length ? 'GREEN' : 'RED'}`);
  if (passed !== results.length) {
    process.exitCode = 1;
  }
}

await main();
