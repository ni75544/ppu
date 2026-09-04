/**
 * @file
 * The next days the Dispatch resource under test actually works.
 *
 * A browser gate that hardcodes a calendar date is correct until the date passes,
 * and then it reports "zero pickup times offered" as a failure of the form when the
 * real finding is that the gate is out of date. That happened here: a run on
 * 2026-09-03 asked for 2026-09-01 and read `unavailable`, which is the honest answer
 * to a request for a day already gone. Two older gates still carried
 * `'2026-09-01'` as their default and would have reported the same false red.
 *
 * So the dates are asked of the site. `qa/support/task10d-open-days.php` reads the
 * resource's own working hours, blackout dates and booking horizon and answers
 * read-only, starting from tomorrow — never today, whose grid is cut by lead time
 * in a way that depends on the hour the gate happens to run.
 *
 * Explicit dates on the command line still win, which is how one specific day is
 * re-examined after a failure. A date already in the past is refused rather than
 * used, because that is the exact mistake this module exists to stop. And if the
 * probe cannot answer, the run stops: guessing a date would produce a gate that
 * fails for a reason it cannot name.
 *
 * `QA_DRUSH_URI` picks the site, exactly as it does for the mutation sentinel.
 */

import { execSync } from 'node:child_process';

const ISO = /^\d{4}-\d{2}-\d{2}$/;

/**
 * Reads the resource's open days.
 *
 * @return {{today: string, timezone: string, dates: string[]}}
 *   The site's own today, its timezone, and the open days it named.
 */
function fromResource() {
  const uri = String(process.env.QA_DRUSH_URI || '').trim();
  const out = execSync(`vendor/bin/drush ${uri === '' ? '' : `--uri=${uri} `}`
    + 'php:script qa/support/task10d-open-days.php', { encoding: 'utf8' });
  const line = out.trim().split('\n').filter((l) => l.startsWith('{')).pop();
  if (!line) {
    throw new Error('task10d-open-days.php printed no JSON line');
  }
  const read = JSON.parse(line);

  return {
    today: String(read.today || ''),
    timezone: String(read.timezone || ''),
    dates: (read.open_days || []).map((d) => String(d.date)),
  };
}

/**
 * Picks the open days a gate needs.
 *
 * @param int $need
 *   How many distinct open days the caller scores against.
 * @param string[] $argv
 *   Candidate dates from the command line, in order. Non-dates are ignored, so a
 *   gate may pass its whole argument list.
 *
 * @return {{dates: string[], source: string, today: string, timezone: string}}
 *   The chosen days, and where each came from.
 */
export function openDays(need = 1, argv = process.argv.slice(2)) {
  const explicit = argv.filter((a) => ISO.test(String(a || ''))).slice(0, need);
  const chosen = [...explicit];
  // Asked once and remembered. Both halves of this function need the site's answer
  // — the fill and the past-date refusal — and one read serves both.
  let memo = null;
  const read = () => (memo === null ? (memo = fromResource()) : memo);

  if (chosen.length < need) {
    for (const date of read().dates) {
      if (chosen.length >= need) {
        break;
      }
      if (!chosen.includes(date)) {
        chosen.push(date);
      }
    }
  }

  if (chosen.length < need) {
    throw new Error(`the resource named ${read().dates.length} open day(s)`
      + ` inside its booking horizon; this gate needs ${need}`);
  }

  // Only the caller's own dates are checked against the site's today. The ones read
  // from the resource start at tomorrow by construction, and re-deriving "past"
  // from the host clock would reintroduce the timezone guess this avoids.
  const past = explicit.filter((d) => d < read().today);
  if (past.length > 0) {
    throw new Error(`refusing to score against ${past.join(', ')}:`
      + ` the site's today is ${read().today}, so a request for that day is answered`
      + ' "unavailable" no matter how the form behaves');
  }

  return {
    dates: chosen,
    source: explicit.length === need ? 'command line'
      : (explicit.length === 0 ? 'qa/support/task10d-open-days.php'
        : 'command line + qa/support/task10d-open-days.php'),
    today: read().today,
    timezone: read().timezone,
  };
}
