/**
 * @file
 * Brackets exactly one browser scenario with a database snapshot on either side.
 *
 * The acceptance run used to report row counts once, around all six walks. That
 * cannot name the action that wrote: two scenarios that insert and delete cancel
 * out, one write looks the same as one write per width, and a count that happens
 * to match tells you nothing about the run before it. So this runs one scenario,
 * reads the same state immediately before and immediately after, and fails that
 * scenario if any gating count or maximum id moved. Maximum ids matter as much as
 * counts: an insert followed by a delete leaves the count alone and moves the id.
 *
 * Keys prefixed `info_` are reported and never gate — the module logs an advisory
 * watchdog row every time it routes an offer to Manual Review, which is exactly
 * what scenarios B and C are for, so gating on that would make a correct refusal
 * red. Every other key is a hard gate.
 *
 * Usage:
 *   ddev exec node qa/browser/mutation-sentinel.mjs <scenario> [YYYY-MM-DD]
 *   ddev exec node qa/browser/mutation-sentinel.mjs --runner=<file.mjs> [args...]
 *
 *   <scenario> is `<width>:<check>` as understood by courier-final-acceptance.mjs
 *   — desktop:A, desktop:B, desktop:C, mobile:A, mobile:B, mobile:C.
 *
 *   --runner points the sentinel at a different script, so a probe that is meant
 *   to write can be used to prove this command is capable of going red.
 *
 * Exit status: 0 only when the scenario passed AND no gating key moved.
 */

import { execFileSync } from 'node:child_process';

const SNAPSHOT = 'qa/support/mutation-state.php';
const DEFAULT_RUNNER = 'qa/browser/courier-final-acceptance.mjs';

// Which Drupal site to snapshot. The sentinel is useless if it watches a
// different database from the one the browser is driving, so this has to track
// COURIER_FORM_URL: set both, or neither.
const DRUSH_URI = String(process.env.QA_DRUSH_URI || '').trim();

/** Reads the one-line JSON snapshot through Drush. */
function snapshot() {
  const args = DRUSH_URI === '' ? [] : [`--uri=${DRUSH_URI}`];
  const out = execFileSync('vendor/bin/drush', [...args, 'php:script', SNAPSHOT], {
    encoding: 'utf8', maxBuffer: 8 * 1024 * 1024,
  });
  const line = out.trim().split('\n').filter((l) => l.startsWith('{')).pop();
  if (!line) {
    throw new Error(`no JSON line from ${SNAPSHOT}; got: ${out.trim().slice(0, 400)}`);
  }

  return JSON.parse(line);
}

/** Every key whose movement is a finding, in a stable printing order. */
function gatingKeys(state) {
  return Object.keys(state).filter((key) => !key.startsWith('info_'));
}

/**
 * Probes whose row count is not conserved, and so is scored on the maximum alone.
 *
 * `watchdog` is a ring buffer: `dblog.settings:row_limit` is 1000, and Drupal
 * evicts the oldest rows as new ones arrive. On `ppu-qa`, which holds ~500 rows,
 * every count only ever rises and the distinction never shows. On active `ppu`,
 * which sits at its limit, a read-only Courier visit reported `watchdog_webform`
 * falling 31 → 26, `watchdog_user` 69 → 55 and `watchdog_errors` 19 → 18 while
 * every maximum stood still — three "this scenario wrote" rows for rows the
 * scenario did not write and could not have deleted.
 *
 * Scoring these on the maximum hides nothing. `wid` is auto-increment, so any row
 * this module or Drupal writes moves it; what is given up is only the ability to
 * notice rows leaving, which for a capped log is Drupal's own housekeeping and not
 * a fact about the scenario. Every other probe keeps the count-and-maximum rule,
 * where a row disappearing is a real finding — see the note in mutation-state.php
 * about an insert followed by a delete.
 */
const MAX_ONLY = /^watchdog_/;

/** Compares two snapshots, returning one row per key that moved. */
function diff(before, after) {
  const moved = [];
  for (const key of gatingKeys(before)) {
    const a = before[key] || {};
    const b = after[key] || {};
    const countMoved = MAX_ONLY.test(key) ? false : a.count !== b.count;
    if (countMoved || a.max !== b.max) {
      moved.push({ key, before: a, after: b });
    }
  }

  return moved;
}

function render(row) {
  const show = (v) => `count=${v.count === null ? 'no-table' : v.count} max=${v.max === null ? '-' : v.max}`;

  return `  ${row.key.padEnd(28)} before: ${show(row.before).padEnd(26)} after: ${show(row.after)}`
    + (MAX_ONLY.test(row.key) ? '  (scored on max only)' : '');
}

// ---------------------------------------------------------------------------

const argv = process.argv.slice(2);
const runnerArg = argv.find((a) => a.startsWith('--runner='));
const rest = argv.filter((a) => a !== runnerArg);

let runner = DEFAULT_RUNNER;
let runnerArgs = rest;
let scenario = '';
const env = { ...process.env };

if (runnerArg) {
  runner = runnerArg.slice('--runner='.length);
  scenario = `${runner} ${rest.join(' ')}`.trim();
}
else {
  scenario = rest[0] || '';
  runnerArgs = rest.slice(1);
  if (!/^(desktop|mobile):[ABC]$/i.test(scenario)) {
    console.log('usage: node qa/browser/mutation-sentinel.mjs <desktop|mobile>:<A|B|C> [YYYY-MM-DD]');
    console.log('       node qa/browser/mutation-sentinel.mjs --runner=<file.mjs> [args...]');
    process.exit(2);
  }
  env.ACCEPTANCE_ONLY = scenario;
}

console.log(`MUTATION SENTINEL — scenario ${scenario}`);
console.log(`snapshot: ${SNAPSHOT}${DRUSH_URI === '' ? ' (default site)' : ` (--uri=${DRUSH_URI})`}`);
console.log(`browser:  ${process.env.COURIER_FORM_URL || 'https://ppu.ddev.site/form/hg (default)'}`);

const before = snapshot();
console.log(`\nbefore: hg=${before.webform_submission_hg.count}/max ${before.webform_submission_hg.max}`
  + ` draft=${before.webform_submission_hg_draft.count}`
  + ` reservation=${before.dispatch_reservation.count}/max ${before.dispatch_reservation.max}`
  + ` order=${before.commerce_order.count}/max ${before.commerce_order.max}`
  + ` attempt=${before.checkout_attempt.count}/max ${before.checkout_attempt.max}`
  + ` review=${before.review_request.count}/max ${before.review_request.max}`);

let scenarioOk = true;
let scenarioDetail = '';
console.log(`\n--- ${runner} ---`);
try {
  execFileSync('node', [runner, ...runnerArgs], { encoding: 'utf8', stdio: 'inherit', env });
}
catch (error) {
  scenarioOk = false;
  scenarioDetail = `exit ${error.status === undefined ? '?' : error.status}`;
}

const after = snapshot();
console.log(`\nafter:  hg=${after.webform_submission_hg.count}/max ${after.webform_submission_hg.max}`
  + ` draft=${after.webform_submission_hg_draft.count}`
  + ` reservation=${after.dispatch_reservation.count}/max ${after.dispatch_reservation.max}`
  + ` order=${after.commerce_order.count}/max ${after.commerce_order.max}`
  + ` attempt=${after.checkout_attempt.count}/max ${after.checkout_attempt.max}`
  + ` review=${after.review_request.count}/max ${after.review_request.max}`);

const moved = diff(before, after);
const advisory = Object.keys(before).filter((k) => k.startsWith('info_'))
  .filter((k) => before[k].count !== after[k].count || before[k].max !== after[k].max);

console.log('\n--- mutation verdict ---');
if (advisory.length) {
  console.log('advisory, not gated:');
  advisory.forEach((key) => console.log(render({ key, before: before[key], after: after[key] })));
}
if (moved.length === 0) {
  console.log(`no gating count or maximum id moved across ${gatingKeys(before).length} probes`);
}
else {
  console.log(`${moved.length} gating probe(s) MOVED — this scenario wrote:`);
  moved.forEach((row) => console.log(render(row)));
}

const verdict = moved.length === 0 && scenarioOk;
if (!scenarioOk) {
  console.log(`\nscenario itself failed (${scenarioDetail})`);
}
console.log(`\nSENTINEL ${verdict ? 'GREEN' : 'RED'} — ${scenario}`);
process.exit(verdict ? 0 : 1);
