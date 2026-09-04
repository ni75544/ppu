/**
 * @file
 * Browser gate for the Reservation Dashboard (Task 10C).
 *
 * QA site only. The in-process HTTP matrix already proves the permission model
 * against rendered markup; this proves it against the DOM a real browser built,
 * at both widths, through real sessions and the real exposed-filter controls.
 * Both are worth having: markup a browser assembles is not the markup a
 * sub-request returns, and a browser check that invents its own expectations can
 * pass while agreeing with nothing. So the expectations here are the ones the
 * matrix computed from the ledger — qa/tmp/task10c-expect.json — and no value in
 * this file is restated by hand.
 *
 * Every session is a fresh browser, because one shared cookie jar would let one
 * persona's page answer for another. Every login link is minted immediately
 * before it is used, because a one-time link dies with the login it performs and
 * two minted in the same second are the same link. No link is ever printed.
 *
 * Read-only. It visits pages and drives the exposed filters, which are GET, and
 * it posts nothing. It also never requests a route it expects to be refused: a
 * 403 costs a `watchdog` row, that row is a mutation of a real table, and the
 * authenticated-dashboard sentinel is not allowed to accept one. Route-level
 * authorisation — anonymous and the read-only personas being refused the dashboard
 * and the three mutation routes — is proven instead by
 * qa/support/task10c-access-matrix.php, which asks Drupal's access manager
 * directly, builds no controller, raises no exception and writes no log row. What
 * is left here is what only a browser can answer: what the rendered DOM contains,
 * and what it does not.
 *
 * The fixture it asserts against is created by
 * qa/support/task10c-seed.php, and the expectations come from
 * qa/support/task10c-rbac.php: run those two, in that order, before this.
 *
 * Usage: ddev exec node qa/browser/task10c-dashboard-gate.mjs
 */
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { Browser } from './webdriver.mjs';
import { PERSONAS, WIDTHS } from './task10c-matrix.mjs';
import {
  CSS_escape,
  elClear,
  elClick,
  elKeys,
  findAll,
  severeConsole,
} from './user-actions.mjs';

const SITE = process.env.QA_SITE_URL || 'https://ppu-qa.ddev.site';
const EXPECT_FILE = 'qa/tmp/task10c-expect.json';
const ARTIFACTS = 'qa/artifacts/task10c';
const EXPORT_MARKERS = ['views-data-export', 'data_export', 'views_bulk_operations', 'bulk_form', 'Apply to selected', '.csv'];
const SECRET_WORDS = ['hold_token', 'owner_hash', 'hmac', 'authorization'];

/**
 * What this gate did that a database can see, counted as it happens.
 *
 * This gate cannot be run without signing in, and Drupal records every login.
 * Counting them here means the authenticated-dashboard sentinel can compare the
 * rows it finds against what the walk says it actually did, instead of against a
 * number somebody typed. Nothing else in this file has a database cost: the walk
 * requests no route it expects to be refused, so there is no second counter.
 */
const effects = { logins: 0 };


const EXPECT = JSON.parse(readFileSync(EXPECT_FILE, 'utf8'));

/**
 * The paths that change capacity, hunted for in the page rather than requested.
 *
 * "No mutation control is available" is a statement about the dashboard's own
 * markup: no link and no form on it may lead to a route that writes. Asking the
 * page is both stricter than navigating away to check and free of side effects —
 * a link that should not be there is a defect whether or not the route behind it
 * would have refused the click.
 */
const MUTATION_PATHS = Object.values(EXPECT.mutations || {});

const results = [];
const check = (scope, label, ok, detail = '') => {
  results.push(Boolean(ok));
  console.log(`[${ok ? 'PASS' : 'FAIL'}] ${scope.padEnd(22)} ${label.padEnd(44)} ${detail}`);
};

/** Views writes a field's class with dashes where its identifier has underscores. */
const dashed = (field) => field.replace(/_/g, '-');

/**
 * Everything one page can be asked, in a single round trip.
 *
 * Reading the whole document over the wire per persona per width would be slower
 * and would put withheld values in this process; the hunt happens in the page and
 * only the needles that were found come back.
 */
const FACTS = `
const [needles, fields, markers, words, emptyText, mutationPaths] = arguments;
const norm = (value) => String(value).replace(/\\u00a0/g, ' ').replace(/\\s+/g, ' ').trim();
const html = document.documentElement.outerHTML;
// Views' own DOM id and core's permissions hash are 64-character hex and belong
// to Drupal rather than to a reservation. They are excluded by name, so a stored
// hash of the same shape is still a failure.
const scrubbed = html
  .replace(/js-view-dom-id-[0-9a-f]{64}/g, 'js-view-dom-id-EXPECTED')
  .replace(/"permissionsHash":"[0-9a-f]{64}"/g, '"permissionsHash":"EXPECTED"');
const lower = html.toLowerCase();
const counts = {};
fields.forEach((field) => {
  counts[field] = document.querySelectorAll('.views-field-' + field).length;
});
const idCell = document.querySelector('td[class*="views-field-dispatch-reservation-id"]');
// Rows are found by what a Views row is rather than by one theme's class. Claro
// renders core's template, which carries 'views-view-table'; Olivero overrides it
// and carries 'views-table' instead, and the three dispatch roles cannot use the
// admin theme, so they see the Olivero markup. A selector tied to either class
// reports "no table" for half the personas and would read as a permission fault.
const rows = Array.prototype.slice
  .call(document.querySelectorAll('table tr'))
  .filter((tr) => tr.querySelector('td[class*="views-field-"]'))
  .map((tr) => {
    const cells = {};
    Array.prototype.forEach.call(tr.querySelectorAll('td'), (td) => {
      const named = Array.prototype.slice.call(td.classList)
        .filter((name) => name.indexOf('views-field-') === 0)[0];
      if (named) {
        cells[named.slice(12)] = norm(td.textContent);
      }
    });
    return cells;
  });
// Mutation controls, looked for where a person would click one. The view's own
// container is the scope: the site's search block and the toolbar carry submit
// buttons of their own on every page, and counting those would make the check fail
// for a reason that has nothing to do with the dashboard. Inside the container the
// only form the dashboard is allowed to render is its exposed filter, which is GET.
const viewRoot = document.querySelector('.views-element-container, .view');
const inView = (selector) => viewRoot ? viewRoot.querySelectorAll(selector).length : -1;
const targets = Array.prototype.slice.call(document.querySelectorAll('a[href], form[action]'))
  .map((el) => el.getAttribute('href') || el.getAttribute('action') || '');
return {
  title: document.title,
  url: location.href,
  hasTable: Boolean(document.querySelector('table.views-view-table, table.views-table')),
  rows: rows,
  needles: needles.filter((needle) => html.indexOf(needle) !== -1),
  hex: (scrubbed.match(/\\b[0-9a-f]{40,64}\\b/) || []).length,
  markers: markers.filter((marker) => lower.indexOf(marker.toLowerCase()) !== -1),
  words: words.filter((word) => lower.indexOf(word) !== -1),
  fieldCounts: counts,
  idCellVisible: Boolean(idCell && idCell.getClientRects().length),
  emptyText: emptyText.filter((text) => html.indexOf(text) !== -1),
  denied: /access denied|not authorized/i.test(document.title + ' ' + norm(document.body.innerText).slice(0, 400)),
  submits: document.querySelectorAll('form input[type="submit"], form button[type="submit"]').length,
  uid: (window.drupalSettings && drupalSettings.user && drupalSettings.user.uid !== undefined)
    ? Number(drupalSettings.user.uid)
    : null,
  logout: Boolean(document.querySelector('a[href*="/user/logout"]')),
  viewFound: Boolean(viewRoot),
  viewSubmits: inView('input[type="submit"], button[type="submit"]'),
  viewExposedSubmits: inView('.views-exposed-form input[type="submit"], .views-exposed-form button[type="submit"]'),
  viewPostForms: inView('form[method="post"], form[method="POST"]'),
  mutationTargets: mutationPaths.filter((path) => targets.some((target) => target.indexOf(path) !== -1)),
};
`;

const facts = (browser, needles = [], fields = [], emptyText = []) =>
  browser.js(FACTS, [needles, fields, EXPORT_MARKERS, SECRET_WORDS, emptyText, MUTATION_PATHS]);

/**
 * One one-time login URL, minted now and never logged.
 *
 * The passwords of the personas the earlier tasks left behind are not known here,
 * and inventing new ones would change those accounts. The URL is a credential: it
 * is returned into this process and goes nowhere else.
 */
function loginUrl(uid) {
  const output = execFileSync('vendor/bin/drush', [`--uri=${SITE}`, 'php:script', 'qa/support/task10c-logins.php'], {
    encoding: 'utf8',
    env: { ...process.env, TASK10C_UID: String(uid) },
    maxBuffer: 1024 * 1024,
  });
  const url = output.split('\n').map((line) => line.trim()).filter((line) => line.startsWith('https://')).pop();
  if (!url) {
    throw new Error(`no login link was minted for uid ${uid}`);
  }
  return url;
}

async function session(width, height) {
  const browser = await Browser.open();
  await browser.viewport(width, height);
  return browser;
}

/**
 * Severe console entries, unfiltered.
 *
 * There used to be an exclusion here for failed 401/403/404 resource loads, which
 * Chrome reports at severe level and which this gate produced on purpose by
 * navigating to routes it expected to be refused. It no longer navigates to any of
 * them, so the exclusion would now hide a real finding: a refused request the walk
 * did not ask for is exactly the surprise worth failing on.
 */
async function realSevere(browser) {
  return severeConsole(browser);
}

/** Presses the exposed form's own Apply and waits for the filtered page. */
async function submitExposed(browser, expectedParameter) {
  const submits = await findAll(
    browser,
    '.views-exposed-form input[type="submit"], .views-exposed-form button[type="submit"]'
  );
  if (!submits.length) {
    return { ok: false, reason: 'no Apply control' };
  }
  await elClick(browser, submits[0]);
  await browser.until(`filtered on ${expectedParameter}`, () => browser.js(
    'const [name] = arguments; return document.readyState === "complete" && location.search.indexOf(name) !== -1;',
    [expectedParameter]
  ), { timeout: 20000 });
  return { ok: true };
}

/**
 * Selects a state in the real control.
 *
 * The filter takes several states at once, so its parameter is an array. Driving
 * it by hand-built query string is how a check ends up passing on a page that
 * never filtered: ?state=released is simply ignored.
 */
async function applyState(browser, value) {
  const options = await findAll(browser, `select[name="state[]"] option[value="${CSS_escape(value)}"]`);
  if (!options.length) {
    return { ok: false, reason: `no option for ${value}` };
  }
  await elClick(browser, options[0]);
  return submitExposed(browser, 'state');
}

/** Types both ends of the pickup range into the real inputs. */
async function applyRange(browser, min, max) {
  for (const [name, value] of [['pickup_arrival[min]', min], ['pickup_arrival[max]', max]]) {
    const inputs = await findAll(browser, `input[name="${name}"]`);
    if (!inputs.length) {
      return { ok: false, reason: `no input named ${name}` };
    }
    await elClear(browser, inputs[0]);
    await elKeys(browser, inputs[0], value);
  }
  return submitExposed(browser, 'pickup_arrival');
}

const ids = (rows) => rows.map((row) => Number(row['dispatch-reservation-id']));
const allProtectedFields = () => Object.values(EXPECT.protected).flat();

/** One persona, one width, one browser: the rows, the columns, and the filters. */
async function persona(name, width, height, label) {
  const expect = EXPECT.personas[name];
  const scope = `${label}/${name}`;
  const browser = await session(width, height);
  try {
    await browser.get(loginUrl(expect.uid));
    effects.logins += 1;
    await browser.get(SITE + EXPECT.dashboard);
    const page = await facts(browser, expect.withheld, allProtectedFields().map(dashed), EXPECT.empty_text);

    check(scope, `session is uid ${expect.uid}`, page.uid === expect.uid || (page.uid === null && page.logout), `drupalSettings.user.uid=${page.uid}`);
    check(scope, 'dashboard title', /Reservation Dashboard/.test(page.title), page.title);
    check(scope, 'the view rendered a table', page.hasTable);
    check(scope, 'row count', page.rows.length === EXPECT.row_count, `${page.rows.length} of ${EXPECT.row_count}`);
    check(scope, 'identifier column is visible', page.idCellVisible);
    check(scope, 'default sort: next pickup first', ids(page.rows).join(',') === expect.ids_in_order.join(','), ids(page.rows).join(','));

    for (const row of page.rows) {
      const id = String(Number(row['dispatch-reservation-id']));
      const want = EXPECT.expected[id];
      if (!want) {
        check(scope, 'row identifier resolves', false, `unknown id ${id}`);
        continue;
      }
      // The schedule columns every reader gets, including the two stored zeros,
      // which must print as 0 rather than as an absence.
      for (const field of EXPECT.open_fields) {
        const got = row[dashed(field)];
        check(scope, `row ${id} ${field}`, got === want.open[field], got === want.open[field] ? got : `got "${got}" want "${want.open[field]}"`);
      }
      // The protected columns, both ways round: the stored value when the
      // permission is held, and no cell at all when it is not.
      for (const [permission, list] of Object.entries(EXPECT.protected)) {
        for (const field of list) {
          const got = row[dashed(field)];
          if (!expect.grants[permission]) {
            check(scope, `row ${id} withheld ${field}`, got === undefined, got === undefined ? '' : `got "${got}"`);
            continue;
          }
          check(scope, `row ${id} ${field}`, got === want[permission][field], got === want[permission][field] ? '' : `got "${got}" want "${want[permission][field]}"`);
        }
      }
    }

    for (const field of expect.present) {
      check(scope, `column present: ${field}`, (page.fieldCounts[dashed(field)] || 0) > 0, `${page.fieldCounts[dashed(field)]} nodes`);
    }
    for (const field of expect.absent) {
      check(scope, `column withheld: ${field}`, page.fieldCounts[dashed(field)] === 0, `${page.fieldCounts[dashed(field)]} nodes`);
    }
    check(scope, 'no withheld value in the DOM', page.needles.length === 0, `${expect.withheld.length} hunted${page.needles.length ? ': ' + page.needles.length + ' found' : ''}`);
    check(scope, 'no unexplained 40/64-char hash', page.hex === 0);
    check(scope, 'no export or bulk marker', page.markers.length === 0, page.markers.join(','));
    check(scope, 'no hold-token or authorization word', page.words.length === 0, page.words.join(','));

    // No mutation control is available. Three ways of asking it, because a
    // dashboard can offer a write in three ways: a link to a route that writes, a
    // form that posts, or a submit button that is not the filter's own.
    check(scope, 'the view container was found', page.viewFound, `submits ${page.viewSubmits}`);
    check(scope, 'no link or form targets a mutation route', page.mutationTargets.length === 0,
      `${MUTATION_PATHS.length} hunted${page.mutationTargets.length ? ': ' + page.mutationTargets.length + ' found' : ''}`);
    check(scope, 'no POST form inside the view', page.viewPostForms === 0, `${page.viewPostForms} forms`);
    check(scope, 'every control in the view is the exposed filter',
      page.viewSubmits === page.viewExposedSubmits && page.viewExposedSubmits > 0,
      `${page.viewSubmits} submit(s), ${page.viewExposedSubmits} in the exposed form`);
    await browser.screenshot(`${ARTIFACTS}/${label}-${name}-dashboard.png`);

    // The exposed filters, through the controls a person would use.
    const released = await applyState(browser, EXPECT.filters.released.value);
    check(scope, 'state control accepted a value', released.ok, released.reason || '');
    let filtered = await facts(browser, expect.withheld, []);
    check(scope, `filter state=${EXPECT.filters.released.value}`, ids(filtered.rows).join(',') === EXPECT.filters.released.ids.join(','), `ids ${ids(filtered.rows).join(',')}`);
    check(scope, 'filtered page withholds too', filtered.needles.length === 0);

    await browser.get(SITE + EXPECT.dashboard);
    const confirmed = await applyState(browser, EXPECT.filters.confirmed.value);
    check(scope, 'state control accepted confirmed', confirmed.ok, confirmed.reason || '');
    filtered = await facts(browser, expect.withheld, []);
    check(scope, `filter state=${EXPECT.filters.confirmed.value}`, filtered.rows.length === EXPECT.filters.confirmed.count, `ids ${ids(filtered.rows).join(',')}`);

    await browser.get(SITE + EXPECT.dashboard);
    const range = await applyRange(browser, EXPECT.filters.day.min, EXPECT.filters.day.max);
    check(scope, 'pickup range control accepted values', range.ok, range.reason || '');
    filtered = await facts(browser, expect.withheld, []);
    check(scope, 'filter pickup date range', ids(filtered.rows).join(',') === EXPECT.filters.day.ids.join(','), `ids ${ids(filtered.rows).join(',')}`);

    await browser.get(SITE + EXPECT.dashboard);
    const emptied = await applyState(browser, EXPECT.filters.empty.value);
    check(scope, 'state control accepted expired', emptied.ok, emptied.reason || '');
    const empty = await facts(browser, expect.withheld, [], EXPECT.empty_text);
    check(scope, 'empty state has no rows', empty.rows.length === 0, `${empty.rows.length} rows`);
    check(scope, 'empty state is truthful', empty.emptyText.length === EXPECT.empty_text.length, `${empty.emptyText.length} of ${EXPECT.empty_text.length} sentences`);
    await browser.screenshot(`${ARTIFACTS}/${label}-${name}-empty.png`);

    // The board this page sits beside is pre-existing and must still be there.
    // Every persona in the matrix is expected to reach it, so this is a render
    // check and not a refusal check; who may reach it is settled by the access
    // matrix instead.
    await browser.get(SITE + EXPECT.board);
    const board = await facts(browser, expect.withheld, []);
    check(scope, 'planner board still renders', !board.denied && board.title !== '', board.title);
    check(scope, 'board withholds too', board.needles.length === 0, `${expect.withheld.length} hunted`);

    const severe = await realSevere(browser);
    check(scope, 'no severe console entry', severe.length === 0, severe.slice(0, 1).join(' '));
  }
  finally {
    await browser.quit();
  }
}

/**
 * There is deliberately no anonymous browser pass.
 *
 * A signed-out visitor has nothing to look at: every route this gate covers
 * refuses them, so the only thing a browser could do here is request a page it
 * expects to be refused and collect the `watchdog` row that comes with it. The
 * assertion itself is not lost — anonymous is refused the dashboard, the board and
 * all three mutation routes in qa/support/task10c-access-matrix.php, evaluated
 * against a real AnonymousUserSession by the same access manager the router
 * consults, and proven there to write no log row at all.
 */

async function main() {
  // This gate signs in as real accounts and drives forms. The active site is
  // read-only for browser work, and a typo in a URL is not an acceptable way to
  // find that out.
  if (!SITE.includes('ppu-qa')) {
    console.log(`REFUSED: this gate runs on the isolated QA site only, not ${SITE}`);
    console.log('VERDICT: RED');
    process.exitCode = 1;
    return;
  }
  console.log(`site=${SITE}  dashboard=${EXPECT.dashboard}  fixture rows=${EXPECT.row_count}`);
  for (const [width, height, label] of WIDTHS) {
    console.log(`\n== ${label} ${width}x${height} ==`);
    for (const name of PERSONAS) {
      await persona(name, width, height, label);
    }
  }
  const passed = results.filter(Boolean).length;
  console.log(`\nRESULT: ${passed}/${results.length} checks passed`);
  // One machine-readable line, for the authenticated-dashboard sentinel. It is
  // what this walk did, not what it was supposed to do: the sentinel derives the
  // same numbers independently from the matrix and refuses if they disagree.
  console.log(`AUTH-EFFECTS ${JSON.stringify({
    logins: effects.logins,
    personas: PERSONAS.length,
    widths: WIDTHS.length,
    uids: PERSONAS.map((name) => EXPECT.personas[name].uid),
  })}`);
  console.log(`VERDICT: ${passed === results.length ? 'GREEN' : 'RED'}`);
  if (passed !== results.length) {
    process.exitCode = 1;
  }
}

await main();
