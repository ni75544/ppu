# Task 24H — A4 diagnosis of the browser-acceptance failure (ppu-qa only)

Reported failure: on `https://ppu-qa.ddev.site/form/hg`, `custom_webform_handlers.t24h_calc_count`
stayed `0` while a submission showed "Your estimate has been updated to USD 29.50.
Review the revised estimate and submit again to accept it."

`ppu` (default) was left read-only. No cache was rebuilt before evidence was
captured. Full sanitized evidence: `failure-evidence-20260919T144600Z.txt`.

## What is proven (server-side, on ppu-qa)

1. **Instrumentation is ACTIVE — the zero is not inactive instrumentation.** The
   `[T24H-DIAG]` counter increments on every genuine calculation, in every SAPI:
   - direct `FixedDevelopmentRouteProvider::calculate()` → delta 1;
   - wired `customer_route_entry_provider->calculate()` (dev mode) → delta 1;
   - a real HTTP `route_preview` POST (web SAPI) with a fresh pair → 0 → 1.
   `opcache.validate_timestamps=On`, `revalidate_freq=0`, and php-fpm `clear_env=no`,
   so no stale opcode and web `effectiveMode` = `development_fixed` (same as CLI).

2. **No live/external provider path in development.** All customer seams — the
   preview controller, `AddressDistanceHandler` (validate + preSave), and the
   checkout starter — inject `custom_webform_handlers.customer_route_entry_provider`
   (`RoutingModeEntryProvider`), which in `development_fixed` delegates to the offline
   `FixedDevelopmentRouteProvider` (opens no socket). Every such calc counts.

3. **USD 29.50 reproduced and explained.** With `service_type=courier`, the courier
   branch prices the 19312 m fixed-dev distance at `total_cents=2950` (USD 29.50),
   `rate_version=2026-08-production-v1`. Deterministic across repeated calcs.

4. **The acceptance gate does not loop server-side; it converges.** `assertAcceptedDispatchQuote()`
   compares the browser-posted breakdown (`total_cents`, `currency`, `rate_version`)
   against a fresh recompute. Those keys exactly match `QuoteResult::toArray()`. A
   repeated calc yields identical `total_cents`/`currency`/`rate_version`, so once the
   browser carries back the gate's corrected breakdown the next submit MATCHES and
   accepts. Each submit calc increments the counter (2 calcs → counter 2).

## CONFIRMED root cause: two courier price contracts (preview vs submit)

The **preview** endpoint prices `sanitizeQuoteInput($data['quote_input'])` and does
**not** canonicalize; the **submit** gate prices the submission **after**
`canonicalizeCourierInputs()`, which forces `courier_urgency = 'standard'` (and
`total_weight_unit = 'lb'`). The browser (`distance_calculator.js:750`) writes the
*preview* quote into `quote_breakdown` and **re-writes it on every re-render**
(`applyPreview`). So the accepted breakdown the browser posts is always the
preview-path price, and the gate always recomputes the submit-path price.

**Live-reachable trigger (confirmed on ppu-qa):** on `/form/hg`, `courier_urgency`
is a **visible** radios element (`#access=true`, no `#states`) with options
`standard` / `rush` / `expedited`, default `standard`. QuoteCalculator's courier case
surcharges by `courier_urgency`. So a customer who selects **Rush** or **Expedited**:

- preview prices the selected tier → e.g. `total_cents = 3700`, written to `quote_breakdown`;
- submit canonicalizes urgency → `standard` → recompute `total_cents = 2950` (USD 29.50);
- gate: accepted `3700` ≠ recompute `2950` → *"estimate updated to USD 29.50, submit
  again to accept it."*;
- customer resubmits unchanged → the JS has re-written `3700` into `quote_breakdown`
  → mismatch again → **the warning repeats forever.** USD 29.50 is precisely the
  canonicalized standard price the gate keeps offering and the browser keeps rejecting.

Reproduced server-side: same input priced `3700` un-canonicalized vs `2950`
canonicalized (`currency`/`rate_version` identical, so only `total_cents` diverges).
`total_weight_unit` is a hidden `lb` element so it is not the live trigger; a stale
`kg` would diverge the same way. **The offer endpoint reads raw browser values too
(per `canonicalizeCourierInputs` own comment), so it shares this latent asymmetry.**

This pricing defect is separate from the counter reading. **A counter of `0` alongside
a displayed price is not by itself contradictory:** this session's own acceptance
results show quote-only reprices and completed-operation replays legitimately return a
priced response with **zero** provider calls (the fact is served from custody or the
ledger). So a `0` during the operator's run is consistent with valid cached reuse and
must be **traced**, not assumed to be a fault or "browser-side" — the trace is which
operation the browser sent and whether it was a cold calculation or a cache/replay hit
(see the observation steps below). Only a *cold, first-seen* pair that returns a price
with `0` calls would indicate the preview never reached the provider.

## Why the counter can legitimately read 0

`distance_calculator.js` keeps a **client-side localStorage route ledger** and reuses
a per-pair `operation_id`. A pair already in that browser's ledger sends `quote_only`
/ replay (served from the held fact, **0 provider calls** — valid cache reuse) rather
than a fresh `route_preview`. A genuinely first-seen pair should send `route_preview`
(→ counter 1). Distinguishing "valid cache reuse" from "the preview never fired"
requires seeing the browser's actual POST (operation type + response code) and its
localStorage — which is browser-side observation.

## Fix applied (confirmed defect only) and verification

Decision (product): Express Courier is a single standard-priced service; rush/expedited
are not honoured. Fix: price courier as standard for **every** caller, so the preview,
the Dispatch offer endpoint, the submission gate and the checkout requote all agree on
the one figure the booking honours.

- `src/Quote/QuoteCalculator.php`, courier case: `courier_urgency` is **not read** and
  **no urgency surcharge** is applied — the service level is canonicalised to standard
  at the one calculator every caller shares, with no "rush"/"expedited" line, label or
  snapshot left behind. This is the single pricing authority, so callers that do not
  canonicalise on their own (preview, offer) now match the submission's figure. The
  acceptance gate still recomputes server-side and never trusts the browser-posted price.

Verified on ppu-qa (`development_fixed`, offline provider, actual calc counter):
- urgency standard / rush / expedited all price `total_cents=2950`, `status=estimated`,
  `rate_version` identical — the divergence is gone.
- Preview(rush)=2950 vs Submit(canonicalised standard)=2950 → **MATCH=YES**.
- **Gate-level verification (not full-form):** `route-acceptance-gate-probe.php` drives
  `assertAcceptedDispatchQuote()` directly with a real submission + FormState carrying a
  selected slot, a **rush** journey, and the browser-accepted breakdown. First submit
  sets **no** estimate-update error (the gate accepts); a second unchanged resubmit also
  accepts with no repeated warning. This is the gate that raised the loop — it does NOT
  prove the multi-page webform advances end to end; that is the remaining human browser
  pass.
- **Fact-reuse correction with EXACT continuity by reference (requirements #1 & #2):**
  the gate, `preSave()`, and checkout preparation no longer call the distance provider
  to price. Each retrieves the server-owned route fact by the **immutable opaque
  reference** the estimate was priced under — `RouteFactStore::retrieve($reference, …)`
  — never by re-selecting a fact whose ordered pair merely matches. `retrieve()`
  validates ownership (the private-tempstore partition), the ordered-pair fingerprint,
  routing mode, purpose, resolved policy version and expiry, and never trusts a
  browser-supplied distance/duration. So an accepted/unchanged submission, its final
  save, and checkout each perform **zero provider invocations** (not merely zero
  outbound), and a second, newer fact for the same pair can never be substituted for
  the one the customer accepted.
  - **Reference continuity carrier (no migration):** the preview's opaque
    `route_reference` is written by `distance_calculator.js` **inside the quote
    breakdown** (an existing field), and the handler preserves it on every rewrite —
    the acceptance rewrite, the `preSave()` persist — so it survives to the stored
    submission and checkout reads it there. No new webform element, no update hook.
    `preSave()` captures the reference **before** `clearComputedValues()` wipes the
    breakdown.
  - **Failure is fail-closed on every path:** a missing, expired, mismatched, or
    reference-less fact yields refresh-required at the gate (with computed values
    cleared), Manual Review at save, and a NULL checkout snapshot — with no network
    fallback, no fabricated distance, no partial reservation, and no silent price
    change. A fresh route requires an explicit address re-commit that re-runs the
    preview and mints a new fact. `retrieveForOwnerPair()` (the interim by-pair helper)
    was removed so nothing can resolve a fact by pair alone.
  - **Method-level continuity probe** (`route-acceptance-gate-probe.php`, **13/13**):
    two valid facts for one pair with different values (A=19312 m, B=88888 m; hand-built
    because fixed-development values are identical); `refA`→A and `refB`→B; the gate,
    `preSave` and checkout all resolve **A by refA (19312 m), never B**, at **+0**
    provider calls; a reference-less breakdown is refused everywhere at **+0**. This is
    method-level (reflection) — it does not prove DB persistence or full-form advance.
  - **Real save/checkout persistence check** (`route-persistence-probe.php`, **9/9**):
    real `WebformSubmission->save()` then read-back. A valid-reference booking persists
    `status=estimated` with the fact's distance (19312 m) at **+0** calls; a
    missing-reference booking persists `status=manual_review` at **+0** with no payable
    price; **no Commerce order is created by either save** (0→0), so no payment is
    initiated and no reservation is confirmed on save (dispatch confirms capacity only
    on the Commerce payment-success path); checkout prices the valid row from the fact
    (+0) and refuses the missing one (NULL). Test submissions are deleted afterward.
- Full HTTP session acceptance re-run: **29/29**, total provider calculations across the
  run **exactly 2** (cache-miss A1 + independent cold C1), quote-only and same-session
  replay **0** — no regression to ownership/counter behaviour.

## Paired change — rush/expedited removed from new bookings (done, no migration)

`AddressDistanceHandler::alterForm()` already customises the live form at render time
(it orders the service cards there). The tiers are removed on that **same runtime path**
— no stored-config edit, no update hook. New method `restrictCourierUrgencyToStandard()`
prunes the rendered `courier_urgency` `#options` to `standard` only and forces the
default to `standard`, so a new booking can only carry `courier_urgency = 'standard'`.
It is a no-op when the control is absent or already reduced, and it only touches the
rendered options — historical submissions, stored snapshots, and every other service's
urgency handling are left exactly as they were.

Verified on ppu-qa: passing a `{standard, rush, expedited}` control with default `rush`
through the method yields `#options = {standard}` and `#default_value = standard`.

Service-level canonicalisation is consistent, not merely surcharge-level: the form no
longer offers the tiers; QuoteCalculator's courier branch reads no urgency at all and
applies no surcharge; the submission path writes `courier_urgency = 'standard'` onto the
stored submission; and no line, snapshot or audit label carries a "rush"/"expedited"
tag. Preview, offer, submission and checkout therefore identify courier urgency as
`standard` for new data.

## The counter reading — corrected

A counter of `0` with a displayed price is **not contradictory**: this session's
acceptance results show quote-only reprices and completed-operation replays return a
priced response with **zero** provider calls, by design. So the operator's `0` is
consistent with valid cached/replay reuse and must be **traced** (which operation the
browser sent, and whether it was a cold calc or a cache/replay hit) rather than assumed
to be a fault. Only a *cold, first-seen* pair returning a price with `0` calls would
indicate the preview never reached the provider. The browser step below captures the
operation type and cache/replay outcome so a verified cold calculation is distinguished
from valid cached reuse — without clearing caches or forcing a calculation to obtain a
`1`.

## The one browser observation that closes it (next pass)

On `https://ppu-qa.ddev.site/form/hg`, with DevTools open and a **first-seen** pair.
Reset the counter once at the start; do NOT clear caches or repeat a commit to force a
`1`. For each step record only: the request `operation`, the HTTP status, the
cache/replay outcome (a `route_reference` minted / a `refresh_required` / a replayed
result), and the counter delta.

1. Confirm the Courier urgency control now offers only **Standard** (no Rush/Expedited).
2. Commit the pair. Expect `operation=route_preview`, HTTP 200, a fresh `route_reference`,
   counter delta **1** — a verified cold calculation. (If the browser instead sends
   `quote_only`/replay for a pair already in its localStorage ledger, delta **0** is
   valid cached reuse, not a fault — record which it was; do not force a `1`.)
3. Change only a pricing input (weight/mode). Expect `operation=quote_only`, delta **0**.
4. Select a slot and submit. Expect the form to **advance with no "estimate updated"
   warning**, delta **0** — the gate now re-prices from the stored route fact, so
   advancement performs **no** provider call. **Another provider invocation on
   submission is NOT expected; a delta of 1 here is a failure to investigate, not the
   normal validation recompute.**
5. Submit again unchanged → accepts, no repeated warning, delta **0**.

A verified cold calc (step 2 delta 1 on a first-seen pair) versus valid cached reuse
(delta 0 with a `quote_only`/replay operation) is the distinction to record. Until this
passes, Task 24H stays open and Task 24B stays gated.
