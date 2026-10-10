# Task 24H — human-operated browser acceptance checklist

**Scope:** the ONLY outstanding item for Task 24H. The anonymous-owner isolation
defect is fixed and its six ownership checks pass (see `COMPLETION.md`, section
"2026-09-19 (later)"). This checklist covers the browser outbound-spy sweep that no
permitted automation can run this session (Selenium forbidden; the preview browser
tool is unavailable). A human operator drives a real browser; the server-side counter
proves whether any route calculation actually happened.

**Target (QA only):** `https://ppu-qa.ddev.site/form/hg`
**Never** run this against `https://ppu.ddev.site` (the default site).

---

## Why a server-side counter, not just DevTools Network

Browser Network inspection shows what the *browser* fetched. It cannot prove the
*server* did not call the routing provider (a cached preview, a background warm, or a
duplicate handler could calculate without a new browser request). So absence-of-call
is measured on the server: the QA-gated `[T24H-DIAG]` counter in
`FixedDevelopmentRouteProvider::calculate()` increments once per genuine provider
calculation, and only on the `qa` database. Read its delta around each step.

`development_fixed` mode is confirmed active on QA, so the provider is the offline
`FixedDevelopmentRouteProvider` — no socket, no external request, no billing. Keep it
that way for the whole window; do not switch routing mode.

---

## Operator commands (run from the repo root, in a second terminal)

Reset the counter before the window:

```bash
ddev exec 'drush --uri=https://ppu-qa.ddev.site state:set custom_webform_handlers.t24h_calc_count 0'
```

Read the counter at any checkpoint (record the number shown):

```bash
ddev exec 'drush --uri=https://ppu-qa.ddev.site state:get custom_webform_handlers.t24h_calc_count'
```

The **delta** between two reads is the number of real server-side route
calculations that occurred between them. "Expected calc" below is that delta.

At the very end, confirm zero external attempts by the offline provider (it opens no
socket by construction — this is a source-verified property, recorded for the log):

```bash
ddev exec 'drush --uri=https://ppu-qa.ddev.site watchdog:show --count=50 --severity=Error' # expect no provider/network errors from this window
```

Do **not** clear the watchdog. Do **not** run any `state:set`/`state:delete` other
than resetting the counter key above.

---

## Steps (read the counter before step 1, then after each step)

Use **one previously-unseen address pair** for the whole window — a From/To that has
not been priced on this QA site before, so its first commit is a genuine cache miss
that must calculate exactly once; every repeated interaction on that same pair must
then add zero.

Record, per step, BOTH: (a) counter-before → counter-after and the delta, and (b) the
**visible form behavior** you observed (what rendered, what stayed unchanged, any
spinner/price/slot update). Do not record only the final total — the per-step deltas
and the on-screen behavior together are the evidence. A committed *new* pair is the
only thing that should ever add a calculation; everything else must add zero.

| # | Interaction | What to do in the browser | Expected calc (delta) |
|---|---|---|---|
| 0 | **Page load** | Open `https://ppu-qa.ddev.site/form/hg` fresh (no reload loop). | **0** — a GET is not a routing op. |
| 1 | **Typing** | Type into the From and To address fields WITHOUT selecting a prediction; pause between keystrokes. | **0** — typing must not buy a route. |
| 2 | **Commit a pair** | Select a real prediction for From and for To (a committed, geocoded pair). Let the preview/price render. | **1** — the one new journey calculates once. |
| 3 | **Re-price only** | Change a pricing input that does NOT change the addresses (e.g. weight, delivery mode, urgency). | **0** — quote-only reprices from the held fact. |
| 4 | **Duplicate selection / blur** | Re-select the SAME predictions, blur and refocus the fields, without changing the text. | **0** — the same committed pair reuses its fact. |
| 5 | **Date / slot selection** | Pick a service date, then a slot/time offer. | **0** — offers read warmed/held route facts, not a new preview calc. |
| 6 | **Polling** | Wait through any booking-state / availability polling cycle (watch the widget update). | **0** — polling samples state, it does not recompute. |
| 7 | **Wizard rebuild** | Trigger a Webform AJAX rebuild (advance a wizard page and return, or any action that re-renders the form) with the pair unchanged. | **0** — a rebuild must not re-buy the route. |
| 8 | **Second unchanged submit/next** | Move forward again without editing addresses. | **0**. |

**Total expected calculations for the whole window: exactly 1** (step 2 only).

A committed *change* of address in any step legitimately adds one calculation for the
new pair — note it explicitly if you deliberately change the pair; otherwise any
non-zero delta outside step 2 is a **FAIL** to report.

---

## Pass criteria

- Step 2 delta = 1; every other step delta = 0 (barring a deliberately changed pair).
- Total window delta = 1.
- No external/provider error in the watchdog for the window (offline provider only).
- No reload storm, no console errors tied to the preview/quote endpoints.

Record the per-step numbers back into `COMPLETION.md` (a small table), then:

1. Remove the `[T24H-DIAG]` block from
   `modules/custom_webform_handlers/src/Distance/FixedDevelopmentRouteProvider.php`.
2. Delete the QA state key:
   `ddev exec 'drush --uri=https://ppu-qa.ddev.site state:delete custom_webform_handlers.t24h_calc_count'`.
3. `ddev exec vendor/bin/drush cr` and `graphify update .`.
4. Close Task 24H and proceed to Task 24B.

Until these browser numbers are recorded and pass, Task 24H stays open and Task 24B
stays gated.
