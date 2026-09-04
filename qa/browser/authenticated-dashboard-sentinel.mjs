/**
 * @file
 * The authenticated-dashboard mutation sentinel (Task 11A). QA site only.
 *
 * The generic sentinel, qa/browser/mutation-sentinel.mjs, hard-gates every store
 * it probes and has no allowance of any kind. That is correct for an anonymous
 * read-only walk and it is why it must not be touched. But the Task 10C dashboard
 * gate proves a permission model, and a permission model cannot be proven without
 * holding a permission: the gate signs in as four accounts at two widths, so it
 * moves `sessions` and the `user` log channel by construction. Run under the
 * generic sentinel it therefore scores functionally green and sentinel red for
 * exactly the two effects that prove it ran at all.
 *
 * The wrong fix is a flag on the generic sentinel. This file is the right one: a
 * separate policy that says what an authenticated read-only walk is allowed to
 * cost, in numbers derived from the runner's own matrix rather than typed in, and
 * that is stricter than the generic sentinel everywhere else — it gates the whole
 * Commerce money path, the `config` table as one hash, and account and role
 * identity as another, none of which the generic sentinel looks at.
 *
 * Two bounded effects are accepted, and nothing else:
 *
 *   1. `sessions` — exactly PERSONAS x WIDTHS new rows, each one identified by the
 *      SHA-256 of its session id and each owned by one of the matrix's own uids.
 *      No pre-existing session row may disappear.
 *   2. `watchdog`, `user` channel, severity info — exactly two rows per login,
 *      one per core template, and no third template.
 *
 * There is no third class. In particular an `access denied` row is a mutation like
 * any other and fails this sentinel: the runner no longer requests any route it
 * expects to be refused, so a refusal row can only mean something happened that
 * nobody asked for. Route-level authorisation is proven instead by
 * qa/support/task10c-access-matrix.php, which asks Drupal's access manager about
 * every account and every route directly, builds no controller, raises no
 * exception, and measures that it wrote no log row. The predicate that decides
 * which rows are authorised is exercised against synthetic rows — including a
 * refusal row wearing a login row's label — before this file reads the database at
 * all, so "an unexpected classification is rejected" is a demonstrated property of
 * the harness rather than a claim about it.
 *
 * Everything else is byte-for-byte: 31 booking, money and account stores by count
 * and maximum id, seven configuration and identity hashes, the error-severity log
 * slice, the dblog row limit and the last cron time.
 *
 * This sentinel takes no arguments. Its runner, its snapshot, its classifier and
 * its site are fixed in the four constants below, so there is no way to point it
 * at a different scenario or to widen what it accepts from a command line.
 *
 * Usage: ddev exec node qa/browser/authenticated-dashboard-sentinel.mjs
 */
import { spawnSync } from 'node:child_process';
import { readFileSync, writeFileSync, writeSync } from 'node:fs';
import { PERSONAS, WIDTHS } from './task10c-matrix.mjs';

/** Fixed, not configurable: the one scenario this policy was written for. */
const RUNNER = 'qa/browser/task10c-dashboard-gate.mjs';
const STATE_SCRIPT = 'qa/support/auth-dashboard-state.php';
const WATCHDOG_SCRIPT = 'qa/support/auth-dashboard-watchdog.php';
const EXPECT_FILE = 'qa/tmp/task10c-expect.json';
const MANIFEST = 'qa/tmp/task11a-auth-dashboard.json';
const JUDGEMENT = 'qa/tmp/task11a-auth-dashboard.txt';
const SITE = process.env.QA_SITE_URL || 'https://ppu-qa.ddev.site';

const findings = [];
const lines = [];
/**
 * One judgement, recorded and printed.
 *
 * `gate` marks the keys that must not move at all; `bound` marks the two that may,
 * within a number this file derived; `self` marks the harness proving its own
 * predicate before it is trusted with real rows. All three are failures when they
 * fail — the distinction is for the report, not for the verdict.
 */
const judge = (kind, key, ok, detail) => {
  if (!ok) {
    findings.push({ kind, key, detail });
  }
  lines.push(`[${ok ? 'OK  ' : 'FAIL'}] ${kind.padEnd(5)} ${key.padEnd(38)} ${detail}`);
};

/**
 * Everything this file prints goes through one synchronous writer.
 *
 * Mixing console.log with fs.writeSync on the same descriptor loses output when
 * stdout is a file or a pipe: the stream buffers, writeSync writes past it at the
 * descriptor's own offset, and the stream then drains over the top. The judgement
 * table below is the whole point of the tool, so it is not written by a mechanism
 * that can be overwritten by the runner's 946 lines arriving late. One writer also
 * means process.exit() can never truncate a refusal.
 */
const out = (text) => writeSync(1, text);

/** Stops, loudly, with the reason. */
const refuse = (why) => {
  out(`REFUSED: ${why}\nAUTHENTICATED DASHBOARD SENTINEL RED\n`);
  process.exit(2);
};

if (process.argv.length > 2) {
  refuse('this sentinel takes no arguments; its runner and its policy are fixed in the file');
}
if (!SITE.includes('ppu-qa')) {
  refuse(`the authenticated walk runs on the isolated QA site only, not ${SITE}`);
}

/** Runs one Drush php:script and parses its single JSON line. */
function drushJson(script, env = {}) {
  const result = spawnSync('vendor/bin/drush', [`--uri=${SITE}`, 'php:script', script], {
    encoding: 'utf8',
    env: { ...process.env, ...env },
    maxBuffer: 32 * 1024 * 1024,
  });
  if (result.status !== 0) {
    refuse(`${script} exited ${result.status}: ${String(result.stderr).trim().slice(0, 400)}`);
  }
  const line = String(result.stdout).split('\n').map((text) => text.trim()).filter((text) => text.startsWith('{')).pop();
  if (!line) {
    refuse(`${script} printed no JSON object`);
  }
  const parsed = JSON.parse(line);
  if (parsed.error) {
    refuse(`${script}: ${parsed.error}`);
  }
  return parsed;
}

const EXPECT = JSON.parse(readFileSync(EXPECT_FILE, 'utf8'));

/**
 * What this walk is allowed to cost, derived rather than declared.
 *
 * Every number below comes from the matrix the runner imports and the route set
 * the runner reads. Nothing is restated: if a fifth persona or a third width is
 * added, or a fourth mutation route appears in the expectation file, these figures
 * move with it and the sentinel stays honest without being edited. A policy typed
 * in by hand is the failure mode this avoids — it would agree with the runner on
 * the day it was written and silently disagree forever after.
 *
 * Two rows per login is core's, not a guess: user.module logs "Session opened for
 * %name." from user_login_finalize(), and UserController::resetPassLogin() logs
 * "User %name used one-time login link at time %timestamp." after it. The runner
 * signs in exclusively by one-time link, so both fire every time.
 */
function derivePolicy() {
  const logins = PERSONAS.length * WIDTHS.length;

  return {
    logins,
    sessions: logins,
    user_rows: 2 * logins,
    user_rows_per_template: logins,
    uids: PERSONAS.map((name) => EXPECT.personas[name].uid),
  };
}

const POLICY = derivePolicy();

/**
 * The two classes of log row this walk is allowed to produce.
 *
 * Named once, here, and consulted by both the self-test below and the judgement of
 * the real rows further down, so the property the self-test demonstrates is the
 * property the run enforces. Adding a third entry is the only way to widen what is
 * accepted, and it would have to be done in the open, in this list.
 */
const ACCEPTED_CLASSES = ['login_session_opened', 'login_one_time_link'];

/**
 * Whether one classified log row is an authorised effect of this walk.
 *
 * The class alone is not enough. The classifier derives it from channel, severity
 * and template, but this file is the thing that must not be fooled, so it re-checks
 * all three of the facts that matter — the channel is `user`, the severity is info,
 * and the account is one of the matrix's own — instead of trusting a label. A
 * refusal row carrying the string `login_session_opened` is rejected here on its
 * channel, which is the case the retired ceiling could not have caught.
 */
const rowAccepted = (row) => ACCEPTED_CLASSES.includes(row.class)
  && row.type === 'user'
  && row.severity === 6
  && POLICY.uids.includes(row.uid);

/**
 * The harness proving its own predicate, on rows it invents, before it looks at the
 * database.
 *
 * None of these rows exists. Provoking a real `access denied` row to prove that
 * `access denied` is rejected would be writing the mutation this sentinel exists to
 * forbid, so the proof is done on synthetic input where it costs nothing and can
 * cover cases the site would not produce on demand. A failure here refuses the run
 * before the first query: a sentinel whose accept/reject boundary is wrong cannot be
 * allowed to declare anything green.
 */
const SELF_TEST = [
  ['accepts a real session-opened row', true, 'user', 6, POLICY.uids[0], 'login_session_opened'],
  ['accepts a real one-time-link row', true, 'user', 6, POLICY.uids[POLICY.uids.length - 1], 'login_one_time_link'],
  ['rejects an access denied row', false, 'access denied', 4, 0, 'unexpected'],
  ['rejects access denied labelled as a login', false, 'access denied', 4, POLICY.uids[0], 'login_session_opened'],
  ['rejects an access denied row for a real persona', false, 'access denied', 4, POLICY.uids[0], 'unexpected'],
  ['rejects the retired denied_known class', false, 'access denied', 4, 0, 'denied_known'],
  ['rejects the retired denied_unknown class', false, 'access denied', 4, 0, 'denied_unknown'],
  ['rejects a php row', false, 'php', 3, 0, 'unexpected'],
  ['rejects a security row', false, 'security', 4, 0, 'unexpected'],
  ['rejects a webform row', false, 'webform', 5, POLICY.uids[0], 'unexpected'],
  ['rejects a commerce row', false, 'commerce', 5, POLICY.uids[0], 'unexpected'],
  ['rejects a payment row', false, 'commerce_payment', 5, POLICY.uids[0], 'unexpected'],
  ['rejects a dispatch row', false, 'custom_webform_handlers', 7, POLICY.uids[0], 'unexpected'],
  ['rejects a login row at the wrong severity', false, 'user', 3, POLICY.uids[0], 'login_session_opened'],
  ['rejects a login row for a stranger account', false, 'user', 6, 99991, 'login_session_opened'],
  ['rejects a login row with no account', false, 'user', 6, 0, 'login_one_time_link'],
  ['rejects an unknown user-channel template', false, 'user', 6, POLICY.uids[0], 'unexpected'],
];

judge('self', 'exactly two classes are accepted', ACCEPTED_CLASSES.length === 2, ACCEPTED_CLASSES.join(' '));
judge('self', 'no accepted class names a refusal',
  !ACCEPTED_CLASSES.some((name) => /deni|forbid|403/i.test(name)), ACCEPTED_CLASSES.length + ' checked');
for (const [label, want, type, severity, uid, klass] of SELF_TEST) {
  const got = rowAccepted({ type, severity, uid, class: klass });
  judge('self', label, got === want, `${type}/${severity} as ${klass} -> ${got ? 'accepted' : 'rejected'}`);
}
if (findings.length) {
  refuse(`${findings.length} harness self-test(s) failed; the accept/reject boundary is wrong: `
    + findings.map((finding) => finding.key).join(', '));
}

/** Reads the strict snapshot. */
const snapshot = () => drushJson(STATE_SCRIPT);

/** Reads and classifies every log row above a baseline id. */
const classify = (since) => drushJson(WATCHDOG_SCRIPT, {
  AUTH_WATCHDOG_SINCE: String(since),
});

out(`site=${SITE}  runner=${RUNNER}\n`);
out(`policy: sessions=${POLICY.sessions}  user rows=${POLICY.user_rows} (${POLICY.user_rows_per_template} per template)`
  + `  every other log row is a failure\n`);

const before = snapshot();
if (before.site !== 'sites/qa') {
  refuse(`the snapshot reports site path ${before.site}; this sentinel runs against sites/qa only`);
}
const absentBefore = Object.entries(before.probes).filter(([, probe]) => probe.absent);
if (absentBefore.length) {
  refuse(`${absentBefore.length} store(s) could not be probed and would pass as unchanged: `
    + absentBefore.map(([key, probe]) => `${key}:${probe.absent}`).join(', '));
}
out(`baseline: ${Object.keys(before.probes).length} stores probed, sessions=${before.sessions.count},`
  + ` watchdog=${before.watchdog.total}/${before.dblog_row_limit} (max ${before.watchdog.max})\n`);

// Scoring log counts rather than maxima is only sound while the ring buffer has
// room: dblog trims to its limit on every insert, so a full table would evict old
// rows during the walk and a count gate would fail for a reason that is not a
// mutation. The generic sentinel scores watchdog on the maximum alone precisely
// because the active site's log is full. Here the headroom is measured, and the
// stricter gate is used only when it is earned.
const headroom = before.dblog_row_limit - before.watchdog.total;
if (headroom < POLICY.user_rows + 50) {
  refuse(`the log has only ${headroom} rows of headroom under its ${before.dblog_row_limit}-row limit;`
    + ' eviction during the walk would be indistinguishable from a mutation. Trim the log and re-run.');
}

/**
 * Runs the dashboard gate once, whole, and reads back what it says it did.
 *
 * Its output is echoed rather than persisted. The gate prints stored field values
 * in the detail column of a failing assertion, and an artifact holding those is an
 * artifact holding ledger data; the manifest therefore keeps the score, the
 * verdict and the labels of any failures, and no detail text.
 */
function runGate() {
  const started = Math.floor(Date.now() / 1000);
  const result = spawnSync('node', [RUNNER], {
    encoding: 'utf8',
    env: { ...process.env, QA_SITE_URL: SITE },
    maxBuffer: 64 * 1024 * 1024,
  });
  const stdout = String(result.stdout || '');
  out(stdout);
  if (result.stderr) {
    writeSync(2, String(result.stderr));
  }
  const score = stdout.match(/^RESULT: (\d+)\/(\d+) checks passed/m);
  const effects = stdout.match(/^AUTH-EFFECTS (\{.*\})$/m);
  const verdict = stdout.match(/^VERDICT: (GREEN|RED)$/m);

  return {
    status: result.status,
    started,
    ended: Math.floor(Date.now() / 1000),
    passed: score ? Number(score[1]) : null,
    total: score ? Number(score[2]) : null,
    verdict: verdict ? verdict[1] : null,
    effects: effects ? JSON.parse(effects[1]) : null,
    failures: stdout.split('\n').filter((line) => line.startsWith('[FAIL]'))
      .map((line) => line.slice(0, 75).trim()),
  };
}

const gate = runGate();
const after = snapshot();
// Classified from the baseline maximum, so the rows examined are exactly the rows
// the walk added and nothing that was already there is re-judged.
const added = classify(before.watchdog.max);

out('\n== authenticated dashboard sentinel ==\n');

judge('gate', 'runner exited cleanly', gate.status === 0, `exit ${gate.status}`);
judge('gate', 'runner functional verdict', gate.verdict === 'GREEN', String(gate.verdict));
judge('gate', 'runner score', gate.passed !== null && gate.passed === gate.total,
  `${gate.passed}/${gate.total}${gate.failures.length ? ' first failure: ' + gate.failures[0] : ''}`);
judge('gate', 'runner reported its effects', gate.effects !== null, gate.effects ? 'AUTH-EFFECTS present' : 'absent');

// The runner counts what it did; this file derives what it should have done from
// the same matrix. Comparing the two is the only reason the derived numbers can be
// called a measurement: if the runner is changed without this policy following,
// the two disagree here and the walk is refused rather than silently re-scoped.
const effects = gate.effects || {};
judge('gate', 'logins matched the matrix', effects.logins === POLICY.logins, `${effects.logins} of ${POLICY.logins}`);
judge('gate', 'runner reported no denied navigation', !('denied_navigations' in effects),
  'denied_navigations' in effects ? `still reports ${effects.denied_navigations}` : 'key absent');
judge('gate', 'matrix shape matched', effects.personas === PERSONAS.length && effects.widths === WIDTHS.length,
  `${effects.personas} personas x ${effects.widths} widths`);
judge('gate', 'account set matched', String(effects.uids) === String(POLICY.uids), String(effects.uids));

judge('gate', 'every store still probeable',
  Object.values(after.probes).every((probe) => !probe.absent),
  `${Object.keys(after.probes).length} stores`);

// The hard gate. Booking authority, money authority, accounts and role grants:
// count and maximum id, both directions, no allowance.
let moved = 0;
for (const [key, probe] of Object.entries(before.probes)) {
  const now = after.probes[key] || {};
  const same = now.count === probe.count && now.max === probe.max;
  if (!same) {
    moved += 1;
    judge('gate', key, false, `${probe.count}/${probe.max} -> ${now.count}/${now.max}`);
  }
}
judge('gate', 'all 31 booking and money stores', moved === 0, `${Object.keys(before.probes).length - moved} unchanged`);

for (const [key, hash] of Object.entries(before.hashes)) {
  const now = after.hashes[key] || {};
  judge('gate', `hash ${key}`, now.sha256 === hash.sha256 && now.objects === hash.objects && now.accounts === hash.accounts
    && now.grants === hash.grants, `${hash.sha256.slice(0, 8)} -> ${String(now.sha256).slice(0, 8)}`);
}

judge('gate', 'no cron run shared the window', after.cron_last === before.cron_last, `${before.cron_last} -> ${after.cron_last}`);
judge('gate', 'log row limit unchanged', after.dblog_row_limit === before.dblog_row_limit, String(after.dblog_row_limit));
judge('gate', 'no error-severity log row', after.watchdog.errors.count === before.watchdog.errors.count
  && after.watchdog.errors.max === before.watchdog.errors.max,
  `${before.watchdog.errors.count}/${before.watchdog.errors.max} -> ${after.watchdog.errors.count}/${after.watchdog.errors.max}`);

// Bounded effect 1: sessions, named rather than counted. A count would be
// satisfied by eight rows appearing and eight unrelated rows vanishing; the set
// difference over the session-id hashes is what makes "these are the walk's rows"
// a statement the teardown can act on.
const sidsBefore = before.sessions.sids || {};
const sidsAfter = after.sessions.sids || {};
const sidsCreated = Object.keys(sidsAfter).filter((hash) => !(hash in sidsBefore));
const sidsGone = Object.keys(sidsBefore).filter((hash) => !(hash in sidsAfter));
const perAccount = {};
for (const hash of sidsCreated) {
  perAccount[sidsAfter[hash]] = (perAccount[sidsAfter[hash]] || 0) + 1;
}

judge('bound', 'session rows created', sidsCreated.length === POLICY.sessions, `${sidsCreated.length} of ${POLICY.sessions}`);
judge('gate', 'no session row destroyed', sidsGone.length === 0, `${sidsGone.length} vanished`);
judge('gate', 'sessions count agrees with the set', after.sessions.count - before.sessions.count === sidsCreated.length,
  `${before.sessions.count} -> ${after.sessions.count}`);
judge('gate', 'one new session per matrix login',
  POLICY.uids.every((uid) => perAccount[uid] === WIDTHS.length)
  && Object.keys(perAccount).every((uid) => POLICY.uids.includes(Number(uid))),
  Object.entries(perAccount).map(([uid, n]) => `uid${uid}x${n}`).join(' ') || 'none');

// Bounded effect 2: the log, one channel at a time. Every channel but the login
// channel is held to count and maximum, which is the strict form the measured
// headroom above licenses. `access denied` is one of those channels now — it is
// held like `php` and `commerce` are, with no ceiling and no exception, because the
// runner no longer asks for a page it expects to be refused.
const zero = { count: 0, max: 0 };
const channels = new Set([...Object.keys(before.watchdog.by_type), ...Object.keys(after.watchdog.by_type)]);
const otherMoved = [];
let userDelta = 0;
for (const channel of channels) {
  const was = before.watchdog.by_type[channel] || zero;
  const now = after.watchdog.by_type[channel] || zero;
  if (channel === 'user') {
    userDelta = now.count - was.count;
    continue;
  }
  if (now.count !== was.count || now.max !== was.max) {
    otherMoved.push(`${channel} ${was.count}/${was.max} -> ${now.count}/${now.max}`);
  }
}

// Named separately as well as caught by the loop above. The loop would fail on a
// refusal row anyway, under a key that says "no other log channel moved"; this says
// which channel, so a regression reads as the thing it is instead of as a generic
// channel drift.
const deniedWas = before.watchdog.by_type['access denied'] || zero;
const deniedNow = after.watchdog.by_type['access denied'] || zero;

judge('gate', 'no other log channel moved', otherMoved.length === 0, otherMoved.join('; ') || `${channels.size - 1} channels held`);
judge('gate', 'no access denied row was written',
  deniedNow.count === deniedWas.count && deniedNow.max === deniedWas.max,
  `${deniedWas.count}/${deniedWas.max} -> ${deniedNow.count}/${deniedNow.max}`);
judge('bound', 'user channel rows', userDelta === POLICY.user_rows, `${userDelta} of ${POLICY.user_rows}`);
judge('gate', 'every new log row is a login row',
  after.watchdog.total - before.watchdog.total === userDelta,
  `total +${after.watchdog.total - before.watchdog.total} = user +${userDelta}`);

// The rows themselves. Counts say how much moved; only the classifier says what
// moved, and "what" is the whole question: sixteen new rows is equally true of
// sixteen logins and of one login plus fifteen fatals.
const classCount = (name) => added.by_class[name] || 0;
const loginRows = added.rows.filter((row) => rowAccepted(row));
const rejectedRows = added.rows.filter((row) => !rowAccepted(row));

judge('gate', 'classifier saw the same rows', added.total === userDelta,
  `${added.total} classified, ${userDelta} counted`);
judge('gate', 'every new log row is authorised', rejectedRows.length === 0,
  rejectedRows.length
    ? rejectedRows.slice(0, 3).map((row) => `${row.type}/${row.severity} as ${row.class}`).join('; ')
    : `${added.total} rows, all accepted`);
judge('gate', 'no unexpected type, severity or template', classCount('unexpected') === 0,
  `${classCount('unexpected')} unexpected`);
judge('gate', 'exactly the two login templates', Object.keys(added.templates).length === 2,
  Object.keys(added.templates).length + ' distinct');
judge('bound', 'login rows: session opened', classCount('login_session_opened') === POLICY.user_rows_per_template,
  `${classCount('login_session_opened')} of ${POLICY.user_rows_per_template}`);
judge('bound', 'login rows: one-time link used', classCount('login_one_time_link') === POLICY.user_rows_per_template,
  `${classCount('login_one_time_link')} of ${POLICY.user_rows_per_template}`);
judge('gate', 'login rows carry a matrix account', loginRows.length === POLICY.user_rows
  && loginRows.every((row) => POLICY.uids.includes(row.uid) && row.severity === 6),
  `${loginRows.length} rows, uids ${[...new Set(loginRows.map((row) => row.uid))].sort((a, b) => a - b).join(',')}`);

const verdict = findings.length === 0 ? 'GREEN' : 'RED';
const manifest = {
  tool: 'authenticated-dashboard-sentinel',
  task: '11A',
  site: 'ppu-qa',
  site_path: before.site,
  database: before.database,
  runner: RUNNER,
  started: gate.started,
  ended: gate.ended,
  policy: POLICY,
  accepted_classes: ACCEPTED_CLASSES,
  self_test: SELF_TEST.length,
  gate: { passed: gate.passed, total: gate.total, verdict: gate.verdict, effects: gate.effects, failures: gate.failures },
  deltas: {
    sessions: sidsCreated.length,
    watchdog_total: after.watchdog.total - before.watchdog.total,
    watchdog_user: userDelta,
  },
  watchdog_baseline_max: before.watchdog.max,
  // The exact deletion keys the teardown is allowed to use, and nothing broader.
  sessions_created: sidsCreated.map((hash) => ({ sha256: hash, uid: sidsAfter[hash] })),
  watchdog_created: added.rows.map((row) => ({
    wid: row.wid, type: row.type, severity: row.severity, uid: row.uid, class: row.class,
  })),
  baseline: before,
  after,
  findings,
  verdict,
};
writeFileSync(MANIFEST, JSON.stringify(manifest, null, 2) + '\n');

/**
 * The judgement table, to the terminal and to a file beside the manifest.
 *
 * The file is not a convenience. The table is the evidence this tool exists to
 * produce, and a report that cites it should cite something that survived the run
 * rather than something scraped out of a redirected stream. Every line is a key,
 * a verdict and a measured number — the details judge() records are counts,
 * hashes and channel names, never a stored field value — so persisting it adds no
 * disclosure over printing it.
 */
const table = [
  `site=${SITE}  runner=${RUNNER}  site_path=${before.site}  database=${before.database}`,
  `policy: sessions=${POLICY.sessions}  user rows=${POLICY.user_rows} (${POLICY.user_rows_per_template} per template)`
    + `  accepted classes=${ACCEPTED_CLASSES.length}  every other log row is a failure`,
  `baseline: ${Object.keys(before.probes).length} stores probed, sessions=${before.sessions.count},`
    + ` watchdog=${before.watchdog.total}/${before.dblog_row_limit} (max ${before.watchdog.max})`,
  '',
  ...lines,
  '',
  `manifest: ${MANIFEST}`,
  `sessions +${sidsCreated.length}  watchdog user +${userDelta}  access denied +${deniedNow.count - deniedWas.count}`
    + `  everything else unchanged: ${moved === 0 && otherMoved.length === 0 ? 'yes' : 'no'}`,
  ...(findings.length
    ? [`${findings.length} finding(s):`, ...findings.map((finding) => `  - [${finding.kind}] ${finding.key}: ${finding.detail}`)]
    : []),
  '',
  `AUTHENTICATED DASHBOARD SENTINEL ${verdict}`,
];
writeFileSync(JUDGEMENT, table.join('\n') + '\n');

out('\n' + table.slice(4).join('\n') + '\n');
out(`judgement: ${JUDGEMENT}\n`);
process.exitCode = verdict === 'GREEN' ? 0 : 1;






