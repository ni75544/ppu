/**
 * @file
 * Empties this module's per-IP throttle buckets before a browser walk.
 *
 * Both the distance preview and the Dispatch offer endpoint throttle on
 * sha256(client IP), and every session in every gate here arrives from the same
 * Selenium container. A run of several walks that each ask for a quote and several
 * offers spends its later ones reading 429 and scoring that as "no times offered" —
 * a red gate that says nothing about the form. That is not hypothetical: the
 * Courier acceptance gate's fourth walk failed exactly this way on 2026-09-03,
 * reporting an unstated refusal and three 429s on `/distance-preview`, because it
 * was the one gate with no reset at all.
 *
 * The buckets are transient Redis counters. Clearing them touches no schedule,
 * capacity, buffer, allowlist, reservation or configuration value, which is why the
 * mutation sentinel sees nothing move across it.
 *
 * `reset-throttles.php` covers both events. The preview-only script some gates used
 * to call left the offer bucket full, and called it without `--uri`, so on `ppu-qa`
 * it cleared the default site's buckets and reported success.
 *
 * `QA_DRUSH_URI` picks the site, exactly as it does for the sentinel. The two sites
 * share one Redis server and differ only by `cache_prefix`, so the script's
 * prefix-blind glob would find both sets of keys either way; passing the uri keeps
 * the bootstrap honest about which site is under test rather than relying on that.
 */

import { execSync } from 'node:child_process';

/**
 * @return string
 *   'cleared', or 'failed:<reason>'. Never throws: a gate that cannot clear a
 *   throttle should still run and report what it saw, and the returned word is
 *   printed with the walk so a 429 later on has a named cause.
 */
export function clearThrottles() {
  const uri = String(process.env.QA_DRUSH_URI || '').trim();
  try {
    execSync(`vendor/bin/drush ${uri === '' ? '' : `--uri=${uri} `}`
      + 'php:script qa/support/reset-throttles.php', { stdio: 'pipe' });

    return 'cleared';
  }
  catch (error) {
    return `failed:${String(error.message || '').slice(0, 80)}`;
  }
}
