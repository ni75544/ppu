# Incident — `hg` submissions 78 and 79

**Status:** closed, cause attributed outside the browser harness.
**Opened:** 2026-08-30, when a routine row count moved from 33 to 35 completed
`hg` submissions between acceptance run 10 and run 11.
**Both rows are preserved.** Nothing in this investigation deleted, altered, or
hid them, and no fix touches them.

Every value below is either metadata or a field *name*. No address, phone number,
email address, token, or secret is reproduced; where the content of a field
matters, only its length is given.

## 1. What the two rows are

| | sid 78 | sid 79 |
| --- | --- | --- |
| webform | `hg` | `hg` |
| uid | **1** (administrator) | **1** (administrator) |
| `in_draft` | 0 — completed | 0 — completed |
| `current_page` | `webform_confirmation` | `webform_confirmation` |
| created | 1788062986 | 1788063061 |
| completed / changed | 1788063038 | 1788063125 |
| elapsed | 52 s | 64 s |
| `remote_addr` | 172.18.0.4 | 172.18.0.4 |
| request uri | `/form/hg` | `/form/hg` |
| service requested | Express Courier, Send | **Local residential move** |
| `dispatch_slot` | set — one Slot was chosen | empty |
| `reservation_hold_token` | empty | empty |

`remote_addr` is the DDEV router, not a client: browser traffic reaches nginx
through it, so every local request records the router's address and it changes as
containers restart. It cannot distinguish a scripted browser from a human one.

The elapsed times are the strongest single fact here. A person filling three
wizard pages takes about a minute. The acceptance walk cannot finish a page-1 to
page-2 transition and a Slot render in 52 seconds *and* also answer a contact
page it never loads.

## 2. Submitted data — field names and value lengths only

sid 78 (29 answered fields):
`calculated_distance(7) calculated_distance_meters(4) courier_prohibited_ack(1)`
`courier_urgency(8) data_consent(1) delivery_contact_name(10)`
`delivery_contact_phone(10) delivery_mode(12) destination_address(37)`
`dispatch_slot(10) email(14) estimated_quote_breakdown(733)`
`estimated_quote_currency(3) estimated_quote_rate_version(21)`
`estimated_quote_status(9) final_calculated_quote(5) full_name(5)`
`handoff_preference(9) origin_address(37) phone_number(10)`
`pickup_contact_name(5) pickup_contact_phone(10) pickup_same_as_sender(1)`
`preferred_service_date(10) prohibited_items_ack(4) service_type(7)`
`single_driver_handling(1) total_weight_lbs(2) total_weight_unit(2)`

sid 79 (28 answered fields):
`calculated_distance(7) calculated_distance_meters(4) cleaning_services(1)`
`data_consent(1) date_flexibility(12) destination_access_conditions(16)`
`destination_address(38) dimension_unit(2) disposal_donation_interest(1)`
`email(24) estimated_quote_breakdown(1329) estimated_quote_currency(3)`
`estimated_quote_rate_version(21) estimated_quote_status(9)`
`final_calculated_quote(6) full_name(10) how_did_you_hear(12) item_quantity(1)`
`move_size_residential(9) origin_access_conditions(18) origin_address(37)`
`packing_services(1) parking_situation(5) phone_number(10)`
`preferred_service_date(10) preferred_service_time(5) service_type(22)`
`specialty_items_residential(2)`

Both carry a full contact set — name, phone, email, consent — which only page 3
of the wizard collects.

## 3. What they did not create

No surviving row in any booking-side ledger references sid 78 or sid 79:

| table | rows referencing 78 or 79 |
| --- | --- |
| `custom_webform_handlers_dispatch_reservation` | none |
| `custom_webform_handlers_reservation` | none |
| `custom_webform_handlers_review_request` | none |
| `custom_webform_handlers_checkout_attempt` | none |
| `custom_webform_handlers_dispatch_notification` | none |

So both are saved submissions and nothing further: no Hold, no Reservation, no
checkout attempt, no order, no payment.

## 4. Log and session metadata

| wid | type | uid | timestamp | location | referer |
| --- | --- | --- | --- | --- | --- |
| 3837 | `webform` | 1 | 1788063040 | `/form/hg` | `/form/hg` |
| 3845 | `webform` | 1 | 1788063126 | `/form/hg` | `/form/hg` |

Both are Webform's own `@title created.` message, two seconds after each
submission completed, under **uid 1**.

The same window contains this module's offer routings with two other referers —
`https://ppu.ddev.site/node` under uid 0, and `https://ppu.ddev.site/` under
uid 1. A script that navigates straight to `/form/hg` cannot produce either.

The `sessions` table holds exactly one uid-1 session, last seen eight minutes
after sid 79 completed.

nginx's access log had already rotated to the current day, so no HTTP-level
record of the window survives locally. That is the one piece of evidence this
investigation could not obtain.

## 5. Whether any harness could have written them

Audit of every file under `qa/` for the ability to create a submission — a submit
control, a draft save, a form build id or token, a triggering-element override:

| file | submission-creating capability |
| --- | --- |
| `qa/browser/courier-final-acceptance.mjs` | none |
| `qa/browser/courier-flow.mjs` | none |
| `qa/browser/courier-state-gate.mjs` | none |
| `qa/browser/courier-cause-matrix.mjs` | none |
| `qa/browser/courier-date-gate.mjs` | none |
| `qa/browser/courier-state-diagnosis.mjs` | none |
| `qa/browser/probe-address-widget.mjs` | none |
| `qa/browser/webdriver.mjs` | none |
| `qa/browser/mutation-sentinel.mjs` | none |
| `qa/browser/probe-submit-writes.mjs` | **yes — added by this investigation, guarded, QA site only** |
| `qa/support/*.php` (8 files) | none except `qa-reset.php`, which only deletes, only on the `qa` database |

Every route the harness touches:

| request | issued by | writes? |
| --- | --- | --- |
| `GET /form/hg` | every walk | no |
| `POST /form/hg` with `op=Next Page >` | the wizard's own Next button | no — `hg` runs with `draft='none'`, so a page change has nothing to persist to |
| `GET` the CSRF token url from `drupalSettings` | `postOffer()` | no |
| `POST` the Dispatch offer url | `postOffer()`, `courier-state-diagnosis.mjs` | no — proven below, 12 sentinel runs |
| WebDriver's own endpoints (`/session`, `/actions`, `/execute/*`, `/screenshot`) | `webdriver.mjs` | not Drupal requests |

Four further facts rule the harness out:

1. No file under `qa/` performs a login of any kind, yet both rows are uid 1.
2. No file under `qa/` names a non-courier service type, yet sid 79 is a local
   residential move.
3. The acceptance walk asserts `dispatch_slot` is empty and never selects a Slot;
   sid 78 has one selected.
4. The acceptance fixture is 10 lb over 1.76 mi; sid 78 is 20 lb over 3.83 mi.

## 6. What does write — reproduced

`qa/browser/probe-submit-writes.mjs` was written to answer this by reproduction
rather than inference. On the isolated QA site it walks all three pages and
clicks the wizard's real controls:

```
1. page 1 filled; addresses set quietly
2. Next clicked ("Next >")  -> on the Slot page: true
3. date "2026-09-01"        -> 19 Slot(s) offered
4. Slot clicked: dispatch_slot=1788264000
5. Next clicked ("Next >")  -> on the contact page: true
6. contact page answered: full_name, phone_number, email, delivery_contact_name,
   delivery_contact_phone, handoff_preference, data_consent
7. Submit clicked: "Submit"
```

The write happens at step 7 and nowhere earlier. Steps 1–6 move nothing. The
wizard has **three** pages, the Submit control exists only on page 3, and the
acceptance walk stops on page 2 — so it has no reachable action that writes.

## 7. Verdict

The writes came from an interactive, authenticated (uid 1) browser session using
the site's own front page and `/form/hg`, over roughly a minute each, answering
the contact page and — for sid 78 — choosing a Slot. That is a person, or a tool
driven by one, signed in as the administrator. It is not any file under `qa/`:
no harness logs in, none reaches page 3, none clicks Submit, and none knows the
service type sid 79 requested.

The earlier QA claim was therefore true about the harness and unproven as stated.
"Row counts were identical before and after run 11" was a whole-run aggregate,
which cannot exclude a write inside the run and says nothing about run 10. That
is the gap the sentinel closes.

## 8. Remediation

No production code or configuration was changed. Nothing in the module was proven
to save a submission on a quote, date, or Slot action — the reproduction in §6
shows the save requires the wizard's own Submit control on page 3 — so the fix is
entirely in the QA harness:

| change | file |
| --- | --- |
| per-scenario write detector | `qa/browser/mutation-sentinel.mjs` (new) |
| the state it reads | `qa/support/mutation-state.php` (new) |
| single-scenario addressing, so one walk can be bracketed | `ACCEPTANCE_ONLY` in `qa/browser/courier-final-acceptance.mjs` |
| red-capability control, guarded to the QA site | `qa/browser/probe-submit-writes.mjs` (new) |
| isolated QA site, so the permanent gate stops reading real data | `sites/sites.php`, `qa/support/qa-site-settings.php`, `qa/support/qa-env-setup.sh`, `qa/support/qa-reset.php`, `additional_hostnames` in `.ddev/config.yaml` |
| wizard-page inspection used for the audit | `qa/support/inspect-hg-pages.php` (new) |

## 9. Verification

Twelve per-scenario sentinel runs, each one browser walk bracketed by a snapshot
of 20 gated probes. `count` and `max` are both compared, because an insert
followed by a delete moves the maximum and leaves the count alone.

Active `ppu` database — six walks, all six with every gated probe unmoved:

| scenario | assertions | `hg` count/max before → after | verdict |
| --- | --- | --- | --- |
| desktop:A | 21/21 | 35/79 → 35/79 | SENTINEL GREEN |
| desktop:B | 4/4 | 35/79 → 35/79 | SENTINEL GREEN |
| desktop:C | 8/8 | 35/79 → 35/79 | SENTINEL GREEN |
| mobile:A | 21/21 | 35/79 → 35/79 | SENTINEL GREEN |
| mobile:B | 4/4 | 35/79 → 35/79 | SENTINEL GREEN |
| mobile:C | 8/8 | 35/79 → 35/79 | SENTINEL GREEN |

Isolated QA database — the same six walks, plus the whole gate in one run:

| scenario | assertions | `hg` count/max before → after | verdict |
| --- | --- | --- | --- |
| desktop:A | 21/21 | 0/0 → 0/0 | SENTINEL GREEN |
| desktop:B | 4/4 | 0/0 → 0/0 | SENTINEL GREEN |
| desktop:C | 8/8 | 0/0 → 0/0 | SENTINEL GREEN |
| mobile:A | 21/21 | 0/0 → 0/0 | SENTINEL GREEN |
| mobile:B | 4/4 | 0/0 → 0/0 | SENTINEL GREEN |
| mobile:C | 8/8 | 0/0 → 0/0 | SENTINEL GREEN |
| full gate, six walks | 66/66 | 0/0 → 0/0 | ACCEPTANCE GREEN |

Scenarios B and C move `info_watchdog_module` by one row each. That is the module
logging its own Manual Review routing, which is exactly what those two scenarios
exist to trigger, so `info_`-prefixed probes are reported and never gate. Gating
on them would make a correct refusal red.

The detector is red-capable, shown against the deliberate write in §6 on the QA
database. Nine gated probes moved and the command exited 1:

```
9 gating probe(s) MOVED — this scenario wrote:
  webform_submission_hg        before: count=0 max=0   after: count=1 max=1
  webform_submission_any       before: count=0 max=0   after: count=1 max=1
  dispatch_reservation         before: count=0 max=0   after: count=1 max=1
  dispatch_guard               before: count=0 max=0   after: count=1 max=1
  checkout_attempt             before: count=0 max=0   after: count=1 max=1
  commerce_order               before: count=0 max=0   after: count=1 max=1
  commerce_order_item          before: count=0 max=0   after: count=1 max=1
  sessions                     before: count=0 max=0   after: count=1 max=…
  watchdog_webform             before: count=0 max=0   after: count=1 max=4

SENTINEL RED — qa/browser/probe-submit-writes.mjs
```

That row was then removed by `qa/support/qa-reset.php`, on the `qa` database only.

## 10. Preservation

Submissions 78 and 79 are unchanged: still `sid 78` and `sid 79`, still uid 1,
still `in_draft=0`, timestamps and data as recorded above, re-read after all
twelve verification runs. Every statement in `qa/support/mutation-state.php` and
`qa/support/booking-row-counts.php` is a `SELECT`, and the only writing script in
`qa/` refuses to run against any database other than `qa`.




