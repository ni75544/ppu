# Task 10 baseline

What is true **before** any booking-profile work begins. Recorded 2026-08-31 on
branch `codex-experiments` for `modules/custom_webform_handlers`.

Every value below was read out of the active configuration, the live schema, or a
recorded QA run. Nothing here is inferred, and nothing here was changed to make it
true. Where a fact comes from outside Graphify's coverage (YAML, Markdown, config,
schema) it is labelled as read from source or from Drush, per the project rule.

## 1. Active Dispatch allowlist

```
custom_webform_handlers.dispatch : workflow.dispatch_webforms = [hg]
```

`hg` is the only Webform the Dispatch workflow will answer for. The four public
POST endpoints (`dispatch_offer`, `dispatch_hold`, `dispatch_renew`,
`dispatch_release`) all carry `_custom_webform_handlers_booking_workflow: 'dispatch'`
and refuse any Webform outside this list. Read from Drush
(`drush php:eval` on the active config object), not from the graph — config YAML is
outside the AST-only graph's coverage.

## 2. Schema version

```
system.schema : custom_webform_handlers = 10035
```

Read from Drush key-value. Hooks `update_10029`–`update_10035` are present in the
working tree and **uncommitted**; the installed schema number is the authority for
what has actually run against the active database.

## 3. Resource schedule, capacity and buffers

Site timezone is `America/New_York` (confirmed via `date_default_timezone_get()`),
and the resource carries the same value explicitly rather than inheriting it.

| Setting | Value |
| --- | --- |
| `resource.id` | `driver-01` |
| `resource.capacity` | `1` |
| `resource.timezone` | `America/New_York` |
| `resource.depot_address` | `''` (empty) |
| `resource.grid_minutes` | `30` |
| `resource.working_hours` | Mon `08:00–18:00`, Tue `08:00–18:00`, Wed `08:00–18:00` |
| `resource.breaks` | `{}` (none) |
| `resource.blackout_dates` | `{}` (none in config; staff blackouts live in their own table) |
| `resource.repositioning_buffer_minutes` | `4` |
| `resource.operational_buffer_minutes` | `4` |
| `resource.booking_horizon_days` | `30` |
| `resource.return_to_base` | `false` |

**Consequence to carry into Task 10:** `working_hours` names only days 1–3. A fully
valid, fully eligible request on a Thursday, Friday, Saturday or Sunday renders
**zero Slots** — not an error, just an empty grid. Every acceptance run must pick a
Monday, Tuesday or Wednesday date or it proves nothing.

Courier-scoped durations override the generic ones and are the only class that
currently resolves; see `service-booking-profile-matrix.md` for the full table.

## 4. Authoritative reservation table

**`custom_webform_handlers_dispatch_reservation` is the authoritative ledger** for
everything the Dispatch workflow books. 24 physical columns (`SHOW COLUMNS`, read
from the live schema):

```
dispatch_reservation_id  int PRI auto_increment
resource_id              varchar(128) MUL
webform_id               varchar(128) MUL
submission_id            int MUL NULL
state                    varchar(16) MUL
pickup_arrival           int
timezone                 varchar(64)
window_start             int
window_end               int
inbound_reposition_seconds   int DEFAULT 0
outbound_reposition_seconds  int DEFAULT 0
pickup_location          text NULL
delivery_location        text NULL
route_seconds            int NULL
route_meters             int NULL
price_snapshot_id        varchar(128) NULL
price_snapshot_cents     int NULL
price_snapshot_currency  varchar(3) NULL
hold_token_hash          varchar(64) MUL NULL
owner_hash               varchar(64) NULL
expires_at               int NULL
created                  int
changed                  int
order_id                 int MUL NULL
```

`state` takes exactly five values, declared on `ReservationStoreInterface`: `held`,
`confirmed`, `released`, `expired`, `cancelled`. Only `held` and `confirmed` occupy
time on the grid. The live `ppu` table currently holds a single row, in state
`held`.

Two tables are **not** the Dispatch ledger and must not be confused with it:

- `custom_webform_handlers_reservation` — the legacy fixed-slot reservation table,
  served by the `reservation_*` routes and the `/admin/content/webform-reservations`
  screens. Still installed, separate lifecycle, not part of Dispatch.
- `custom_webform_handlers_dispatch_blackout`, `_dispatch_guard`,
  `_dispatch_notification`, `_dispatch_notify_claim`, `_dispatch_status`,
  `_checkout_attempt`, `_review_request` — supporting tables around the ledger, not
  substitutes for it.

Secrets never cross the store seam in stored form: raw hold tokens and raw
anonymous owner identifiers are passed in and SHA-256 hashed inside the
implementation; the interface neither returns nor accepts a hash.

## 5. Courier acceptance — command and result

The permanent gate. Runs six walks (three scenarios × two widths) from a fresh
anonymous session, through the real Google Places widget, with no DOM injection and
no fabricated offer payload.

```bash
ddev exec bash -c 'COURIER_FORM_URL=https://ppu-qa.ddev.site/form/hg QA_DRUSH_URI=https://ppu-qa.ddev.site node qa/browser/courier-final-acceptance.mjs 2026-09-01'
```

Last recorded result: **66/66 at 1440×900 and 390×844**, `ACCEPTANCE GREEN`, on
2026-08-30. Log `qa/artifacts/acceptance-run-10.log`; screenshots
`qa/artifacts/2026-08-30T03-57-39-acceptance-{desktop,mobile}-{A,B,C}-*.png`. For
Tuesday 2026-09-01 the eligible scenario yields **19 bookable Slots** (8:00 AM –
5:00 PM, 30-minute grid), an offer with `manual_review: false` at `$19.00`, and no
Hold, token, Reservation, checkout or payment request anywhere in the walk. Console
empty.

Two behaviours the gate pins down that are easy to misread as defects:

- Negative scenario B (unticked prohibited-contents attestation) is blocked at
  **page 1**, not by server-side Manual Review. `courier_prohibited_ack` is
  `#states`-required, so the browser refuses to advance. Zero Slots holds by a
  stronger mechanism than the original brief expected. Only the 45 lb path exercises
  the server's `ManualReview` / `staff_review` refusal on page 2.
- `preferred_service_time` and `date_flexibility` remain in page-2 markup. They are
  the **other** services' questions, hidden by Webform's own `#states` on
  `service_type != courier`: unanswered, un-required, `display:none` through a
  states-hidden ancestor, and absent from the offer body. Present-but-inert by
  design. Not Courier duplicates — do not "fix" them.

Courier is the reference behaviour for Task 10 and must not regress.

## 6. Mutation sentinel — command and red-control proof

The sentinel is the required QA guardrail. It brackets **one** walk at a time:
snapshot, run, snapshot, compare.

```bash
ddev exec bash -c 'COURIER_FORM_URL=https://ppu-qa.ddev.site/form/hg QA_DRUSH_URI=https://ppu-qa.ddev.site node qa/browser/mutation-sentinel.mjs desktop:A 2026-09-01'
```

Scenarios are `desktop|mobile:A|B|C`, or any runner via `--runner=<file.mjs>`. It
snapshots 20 probes and gates on all of them except the `info_`-prefixed ones, which
move legitimately whenever the module logs a Manual Review routing.

Each probe records **count and maximum id**, not count alone — an insert followed by
a delete leaves the count unchanged and moves only the max. This is not theoretical
tidiness: a whole-run before/after row count is what left `hg` submissions 78 and 79
unexplained (`docs/incidents/2026-08-30-hg-78-79-submissions.md`). A whole-run
aggregate cannot exclude a write *inside* the run and says nothing about earlier
runs.

**Red-capability is proven, not assumed.** `qa/browser/probe-submit-writes.mjs`
deliberately completes a submission and moves **9 gated probes**, and the sentinel
exits `1` on it. The probe is guarded twice: it refuses to run against a live host,
and it requires the explicit `--i-know-this-writes` flag.

```bash
ddev exec bash -c 'COURIER_FORM_URL=https://ppu-qa.ddev.site/form/hg QA_DRUSH_URI=https://ppu-qa.ddev.site node qa/browser/mutation-sentinel.mjs --runner=probe-submit-writes.mjs --i-know-this-writes 2026-09-01'
```

Writing `hg` requires reaching **page 3** and clicking the wizard's own Submit. The
acceptance walk stops on page 2, so it has no reachable action that writes — which
is why a green sentinel around it is a meaningful result rather than a tautology.

Two environment traps that have each cost real time:

- `ddev exec` does **not** forward host environment variables. Every targeted run
  must be wrapped as `ddev exec bash -c '…'`.
- If `QA_DRUSH_URI` names a different site than `COURIER_FORM_URL`, the sentinel
  watches the wrong database and prints a meaningless GREEN. The two must agree.

## 7. `ppu` versus `ppu-qa` — the database safety model

One codebase, one DDEV project, two databases.

| | `ppu` (active) | `ppu-qa` (isolated) |
| --- | --- | --- |
| URL | `https://ppu.ddev.site` | `https://ppu-qa.ddev.site` |
| Database | `db` | `qa` |
| Redis key space | default | `$settings['cache_prefix'] = 'qa_'` |
| Wired by | default site | `sites/sites.php` map + `additional_hostnames: ["ppu-qa"]` |
| Transactional data | real, must be preserved | cloned then emptied |
| Writable by QA | **no** | yes, via `qa/support/qa-reset.php` |

Build or rebuild the isolated site with:

```bash
bash qa/support/qa-env-setup.sh
```

It clones the live schema and configuration, sanitizes, then empties every
transactional table. `qa/support/qa-reset.php` is the only script that writes, and it
**refuses unless the connected database is literally `qa`** — the guard is in the
script, not in the operator's memory.

A cloned database arrives **without** `$settings['custom_webform_handlers_server_api_key']`,
because that is a settings value and not config. Without it every distance preview
answers `provider_not_configured`, the quote comes back empty and zero Slots render —
which looks exactly like a broken feature. `qa/support/qa-site-settings.php` reads the
key out of `sites/default/settings.php` at runtime rather than copying the secret into
a second file. No key material is written into Git or echoed to the terminal.

### Two rules, stated explicitly

1. **Active `ppu` browser checks must be sentinel-wrapped and write-free.** Any browser
   check pointed at `https://ppu.ddev.site` runs inside
   `qa/browser/mutation-sentinel.mjs`, and a moved gated probe is a failure of the
   check — not a finding to be explained away afterwards. The active `ppu` database is
   read-only for browser acceptance.
2. **The write-capable probe runs only against `ppu-qa`.**
   `qa/browser/probe-submit-writes.mjs`, and anything else that intentionally submits,
   is restricted to `https://ppu-qa.ddev.site`. Its host guard plus the mandatory
   `--i-know-this-writes` flag enforce this in code; the rule is written here so the
   intent survives the code.

The sentinel is the required QA guardrail. It is not to be removed, weakened or
bypassed.

## 8. Known Git-index debt, and no-commit status

**Nothing has been committed, reset, unstaged or discarded.** The debt below is
pre-existing: it was created by the two baseline commits before this line of work and
is recorded here rather than repaired, because `git reset`, `git restore --staged`,
`git rm --cached`, mass staging and committing are all out of scope.

### 37 already-indexed `qa/` paths

`git diff --cached --name-only -- qa/` returns 37 entries, all added:

| Count | Path | Note |
| --- | --- | --- |
| 32 | `qa/artifacts/*.png` | generated screenshots, now git-ignored *going forward* |
| 4 | `qa/browser/{courier-date-gate,courier-flow,courier-state-diagnosis,webdriver}.mjs` | permanent tools; correctly tracked, no action needed |
| 1 | `qa/tmp/inspect-eligibility-state.php` | staged `AD` — added in the index, deleted in the working tree |

A `.gitignore` entry never un-stages an already-indexed path. The 32 PNGs and the
disposable `qa/tmp/` script therefore stay in the index until someone is explicitly
authorised to run `git rm --cached`. That authorisation has not been given, so they
remain. The new ignore rules do prevent *further* generated output from being added.

The four staged `qa/browser/*.mjs` files are not debt — they are source and belong in
the repository. `qa/browser/courier-flow.mjs` reads `AM`: staged, with later
working-tree edits.

Untracked and deliberately left untracked-but-not-ignored, i.e. ready to be added by
whoever commits: `qa/README.md`, `qa/support/` (9 files), and
`qa/browser/{courier-cause-matrix,courier-final-acceptance,courier-state-gate,mutation-sentinel,probe-address-widget,probe-submit-writes}.mjs`.

### Whole-tree staged baseline

`git diff --cached --name-only | wc -l` returns **52,682** entries. The overwhelming
majority is the Drupal core and `vendor`-adjacent tree, staged wholesale by the two
baseline commits (`6b2597f7`, `c365148f`) before this work started. Consequences worth
knowing before anyone runs a Git command here:

- `git status --short` unscoped produces ~3.7 MB of output. Always scope it:
  `git status --short -- modules/custom_webform_handlers/ qa/ docs/ .gitignore`.
- `git diff --cached --check` produces ~4.8 MB, because staged binary PNGs
  (`.claude/tmp-quote.png`, `.claude/tmp-rush.png`) are diffed as text. The
  meaningful, clean check is the working-tree one: `git diff --check`, which exits `0`
  apart from CRLF advisories on `.ddev/config.yaml`, `.gitignore` and
  `qa/browser/courier-flow.mjs`.

### `.gitignore` additions, and what they deliberately do not cover

Three narrowly scoped patterns were appended, for generated output only:

```
/qa/artifacts/
/qa/tmp/
/.claude/settings.local.json.tmp.*
```

`qa/browser/`, `qa/support/`, `docs/`, module source and configuration are **not**
ignored, and these two `qa/` patterns must never be widened to the whole `qa/` tree.
Verification as actually run is in §9 below, reproducible with `git check-ignore -v`.

## 9. Ignore-rule verification, as run

```
qa/artifacts/example.png                     IGNORED   .gitignore:59  /qa/artifacts/
qa/tmp/example.php                           IGNORED   .gitignore:60  /qa/tmp/
.claude/settings.local.json.tmp.99999.abc    IGNORED   .gitignore:63  /.claude/settings.local.json.tmp.*
qa/browser/courier-final-acceptance.mjs      TRACKABLE
qa/support/mutation-state.php                TRACKABLE
docs/architecture/task10-baseline.md         TRACKABLE
modules/custom_webform_handlers/…info.yml    TRACKABLE
```

`git diff --check` exits `0` (three CRLF advisories only, listed in §8).

## Evidence systems used

Graphify for PHP call structure and the "does any code save a submission" question;
direct reads of the module's own `*.routing.yml`, `*.permissions.yml` and
`config/install/*.yml` for routing, permissions and roles, because YAML is outside
this graph's AST-only coverage and the absence of a YAML edge proves nothing there;
read-only Drush (`php:eval`, `sql:query`, `role:list`) for active config, schema
version, live table shape and role grants; `git` for index state. Every fact in §1–§4
is labelled with where it came from; nothing was taken from the two duplicate copies
of the module on disk.






