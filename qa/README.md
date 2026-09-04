# Browser QA

Permanent browser checks for the Express Courier flow, and the isolated site they
run against.

Everything here drives a real Chrome through WebDriver against a real Drupal. No
PHPUnit, Kernel, or FunctionalJavascript suite is involved.

## Where the gate runs — and why not on `ppu`

The permanent gate runs against **`https://ppu-qa.ddev.site`**, a second Drupal
site on this same codebase backed by a separate `qa` database seeded with
fixtures.

The active `ppu` database holds real and historical submissions. Two of them
(`hg` 78 and 79) were created by an interactive administrator session while QA
runs were happening, and a whole-run row count could not tell the two apart —
see [`docs/incidents/2026-08-30-hg-78-79-submissions.md`](../docs/incidents/2026-08-30-hg-78-79-submissions.md).
The conclusion stands: browser acceptance must not need that database at all.

`ppu` stays usable for a one-off diagnostic run, and every check here is
read-only, but **`ppu` is read-only for browser acceptance** and the only script
in this tree that writes refuses to run against it.

## Build the QA site

```bash
bash qa/support/qa-env-setup.sh
```

Idempotent, and it leaves the default site's database untouched apart from the
read the export performs. It:

1. writes `sites/qa/settings.php` from the versioned, credential-free
   `qa/support/qa-site-settings.php` (the generated file is git-ignored, like
   every other site settings file);
2. checks `ppu-qa` is in `additional_hostnames` and starts DDEV;
3. clones the live schema and configuration into a `qa` database, then deletes
   the dump — a clone rather than a fresh install, because a QA site built from
   different configuration would prove something about that configuration;
4. runs `drush sql:sanitize`, scrambling every user email and password hash;
5. deletes every transactional row (`qa/support/qa-reset.php`);
6. verifies with `qa/support/mutation-state.php` that all 20 gated probes read
   zero.

Reset to fixtures at any time:

```bash
ddev exec vendor/bin/drush --uri=https://ppu-qa.ddev.site php:script qa/support/qa-reset.php
```

### The guardrail

`qa/support/qa-reset.php` reads the connection it was handed and **refuses unless
the database is literally `qa`**, exiting non-zero without deleting anything. A
`--uri` typo or a missing `sites/qa/settings.php` therefore cannot point it at the
live database. `qa/browser/probe-submit-writes.mjs` — the only browser script that
creates a submission — refuses unless `COURIER_FORM_URL` points somewhere other
than `ppu.ddev.site` *and* `--i-know-this-writes` is passed.

The QA site also gets its own Redis key space (`cache_prefix = 'qa_'`), so a cache
or throttle key from one site is never read by the other.

## Run the gate

The whole gate, six walks — desktop and mobile × three scenarios:

```bash
ddev exec bash -c 'COURIER_FORM_URL=https://ppu-qa.ddev.site/form/hg node qa/browser/courier-final-acceptance.mjs 2026-09-01'
```

Expect `66/66 assertions passed` and `ACCEPTANCE GREEN`. The date argument
defaults to `2026-09-01`; the dispatch resource works Monday to Wednesday only, so
a date outside that window legitimately offers no Slots and the run goes red.

Clear the throttles first if a previous run tripped them — the flood counters are
Redis-backed and keyed on the *browser's* address, so `flood()->clear()` from
Drush clears the wrong bucket:

```bash
ddev exec vendor/bin/drush --uri=https://ppu-qa.ddev.site php:script qa/support/reset-throttles.php
```

### One scenario at a time, with a write detector

```bash
ddev exec bash -c 'COURIER_FORM_URL=https://ppu-qa.ddev.site/form/hg QA_DRUSH_URI=https://ppu-qa.ddev.site node qa/browser/mutation-sentinel.mjs desktop:A 2026-09-01'
```

The sentinel snapshots 20 database probes — counts *and* maximum ids — immediately
before and immediately after exactly one walk, and fails if any of them moved.
Scenario names are `desktop:A|B|C` and `mobile:A|B|C`. `QA_DRUSH_URI` must name
the same site as `COURIER_FORM_URL`, or the detector watches the wrong database.

Maximum ids are compared as well as counts because an insert followed by a delete
leaves the count alone. Probes prefixed `info_` are printed but never gate: the
module logs an advisory row every time it routes an offer to Manual Review, which
is what scenarios B and C exist to trigger.

## What is in here

`qa/browser/` — permanent, all driven through WebDriver:

| file | purpose |
| --- | --- |
| `courier-final-acceptance.mjs` | the gate: 66 assertions over six walks |
| `mutation-sentinel.mjs` | brackets one walk with a database snapshot |
| `courier-flow.mjs` | shared page driving — fill, navigate, offer, Slot, capture |
| `webdriver.mjs` | the WebDriver client, including shadow-root traversal |
| `courier-state-gate.mjs` | the three public states, 78 assertions, both widths |
| `courier-date-gate.mjs` | date-canonicalization checks |
| `courier-cause-matrix.mjs` | which missing fact causes which refusal |
| `courier-state-diagnosis.mjs` | one-off diagnosis of a single offer response |
| `probe-address-widget.mjs` | Places-widget probe, for when address entry breaks |
| `probe-submit-writes.mjs` | **writes.** Guarded; QA site only; proves the sentinel can go red |

`qa/support/` — Drush-side helpers the browser scripts shell out to. All are
read-only `SELECT`s except `qa-reset.php`, which only deletes and only on `qa`:

| file | purpose |
| --- | --- |
| `mutation-state.php` | the sentinel's snapshot: 20 gated probes plus 2 advisory |
| `booking-row-counts.php` | a shorter before/after count, human-readable |
| `reset-throttles.php` | clears this module's Redis flood buckets for every client |
| `reset-preview-flood.php` | clears the distance-preview bucket only |
| `inspect-hg-elements.php` | element flags the walk depends on |
| `inspect-hg-pages.php` | the wizard's pages and required elements per page |
| `qa-site-settings.php` | versioned source for `sites/qa/settings.php` |
| `qa-env-setup.sh` | builds the QA site |
| `qa-reset.php` | resets the QA site to fixtures |

`qa/artifacts/` holds run logs and screenshots and is git-ignored. `qa/tmp/` is for
disposable probes and is git-ignored; nothing permanent belongs there.

## Fixtures

The walk uses two real public addresses and a 10 lb Courier Send request, because
the distance and the quote have to be real for the Slot offer to mean anything. It
never selects a Slot, never starts a checkout, and never submits: with `hg` running
`draft='none'` and the Submit control living on page 3, a walk that stops on page 2
has no reachable action that writes.

