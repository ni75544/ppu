# Task 20B — Stop routing during uncommitted address typing

Recorded 2026-09-05. Companion to [the baseline](task20-baseline.md) and
[the provider policy](task20-provider-policy.md), and executed against
[the execution spec](../execution/routing-cost-reduction/task-20b-request-suppression/PROMPT_SPEC.md).

This change ships **before** the provider migration and does not depend on it.
The alias `custom_webform_handlers.distance_provider` is untouched: every request
this document talks about still reaches
`GoogleRoutesDistanceProvider`. The point is that far fewer of them are made.

## The defect, per input path

The form has three ways an address can arrive, and only one of them was already
safe. All three used to funnel `input` and `change` into `scheduleRefresh()`,
which fired a preview request 400 ms
([`distance_calculator.js:24`](../../js/distance_calculator.js:24)) after typing
settled — so a paused typist bought a route, then bought another one, for an
address that was never finished.

| Path | Condition | Before | After |
| --- | --- | --- | --- |
| Modern `PlaceAutocompleteElement` | API key present, `importLibrary('places')` available | Already suppressed while typing | Unchanged in intent; now suppresses through the shared dirty state |
| Legacy `google.maps.places.Autocomplete` | API key present, legacy library | **One request per 400 ms typing settle** | Requests only on a committed pair |
| Plain text | `apiKey` empty | **One request per 400 ms typing settle** | Requests only on a committed pair |

Nothing about the paid request is retroactive. Aborting a `fetch` is UI cleanup —
if the request reached the provider, it is billed. The saving comes from *not
starting* it, which is why the suppression is on the dispatch decision and not on
the response handling.

## The state machine

One dirty/commit machine now serves all three paths, in `setupForm()`:

| Concern | Identifier | Location |
| --- | --- | --- |
| Edit in progress | `addressesDirty` | [`:418`](../../js/distance_calculator.js:418) |
| Commit window | `commitTimer`, `commitDelayMs` | [`:400`](../../js/distance_calculator.js:400) |
| Requested pair identity | `lastRequestedPair` | [`:705`](../../js/distance_calculator.js:705) |
| In-flight guard | `inFlightKey` | `refreshPreview()` |
| Replayable success | `lastSuccessfulKey`, `lastSuccessfulData` | `applyPreview()` |
| Suppress our own writes | `writingMappedFields` | [`:529`](../../js/distance_calculator.js:529) |
| Suppress synthetic selection events | `selectingAddress` | [`:1021`](../../js/distance_calculator.js:1021) |

Invalidation runs on the first keystroke.
[`markAddressesDirty()`](../../js/distance_calculator.js:822) sets the flag,
clears both timers, calls `invalidateActivePreview()`, forgets `inFlightKey` and
`lastRequestKey`, emits `custom-webform-address-dirty`
([`:838`](../../js/distance_calculator.js:838)) and clears the displayed estimate.
It sends nothing. The emit is guarded on the *transition* into dirty, so a
20-character burst produces one event, not twenty.

Commit runs on a settled fact.
[`scheduleCommit()`](../../js/distance_calculator.js:854) opens the window;
[`commitAddresses()`](../../js/distance_calculator.js:865) refuses an incomplete
pair, clears the flag and calls `refreshPreview()`. The commit triggers are a
valid autocomplete selection, delegated `focusout`
([`:920`](../../js/distance_calculator.js:920) — `blur` does not bubble, so
delegation on the form requires `focusout`), `Enter` that nothing else cancelled
([`:928`](../../js/distance_calculator.js:928)), `change`
([`:898`](../../js/distance_calculator.js:898)), and an explicit
`[data-custom-webform-quote-commit]` click
([`:945`](../../js/distance_calculator.js:945)) for a widget that exposes no
reliable blur. That last one is a **seam, not a feature**: no markup carries the
attribute today, and the handler is inert until some does.

Completeness is a local predicate, not a guess about what Google will accept:
[`isResolvableAddress()`](../../js/distance_calculator.js:78) requires a
normalized string of at least `MIN_ADDRESS_LENGTH` 6 characters,
`MIN_ADDRESS_TOKENS` 2 whitespace tokens, and either a digit or a comma. It is
deliberately permissive — its job is to exclude `123`, not to adjudicate
addresses. Both endpoints must pass before a request is made.

Duplicate suppression is by fingerprint at four levels, checked in this order in
`refreshPreview()`: dirty short-circuit; incomplete pair; replay of
`lastSuccessfulData` when the request key is unchanged; `inFlightKey`; then
`lastRequestKey`. Replay re-applies a **completed** response for the **current**
facts, which is why an invalidated old response can never restore results — a
superseded response is dropped by `invalidateActivePreview()` before it can be
recorded as the last success.

## Item-by-item conformance

| Spec item | Where it lives | State |
| --- | --- | --- |
| 1 — raw `input` clears and cancels, never dispatches | `markAddressesDirty()`, `onFormInput()` | Implemented; browser check pending |
| 2 — commit on selection or delegated `focusout`, one configurable debounce | `onFormChange`, `onFormFocusOut`, `onFormKeyDown`, `onCommitClick`, `commitDelayMs` | Implemented, 600 ms default, config-overridable |
| 3 — both addresses complete; one pair fingerprint; in-flight marker | `isCompletePair()`, `pairKey()`, `inFlightKey` | Implemented; browser check pending |
| 4 — retain the Places widget; do not claim control of its prediction requests | Widget untouched; see the Places section below | Implemented as *no change*, documented |
| 5 — `dispatch_slots.js` coordinates, suspends while dirty/detached/hidden | `addressesDirty`, `onAddressDirty`, poll guard | Implemented; browser check pending |
| 6 — package/service edits reuse the existing route and server cache | Non-address inputs still use `scheduleRefresh()`; `routeUnchanged` is published | Implemented |
| 7 — attach/detach, timers, listeners, `once()`, status text, no framework | `listeners` table, `teardown()`, `detach()` guard | Implemented |
| 8 — CSRF, size bounds, flood, `no-store`; server-side normalized fingerprint | `DistancePreviewController` | Implemented |

## Slot coordination without a second predicate

`dispatch_slots.js` guarded only `!origin || !destination`
([`:699`](../../js/dispatch_slots.js:699)), which catches an empty endpoint but not
a *partial* one — so a half-typed address could reach the offer endpoint, the
planner, and paid traffic-aware routing, bypassing the quote gate entirely.

The fix is coordination by event, not a copy of the completeness rule. The widget
listens for `custom-webform-address-dirty`
([`:1366`](../../js/dispatch_slots.js:1366)) and for
`custom-webform-route-validated` ([`:1339`](../../js/dispatch_slots.js:1339)), and
holds `addressesDirty` ([`:487`](../../js/dispatch_slots.js:487)) as the single
suspension flag, checked in `requestOffers()`
([`:640`](../../js/dispatch_slots.js:640)) and in the poll callback alongside
`document.hidden` ([`:1440`](../../js/dispatch_slots.js:1440)). On dirty it
cancels the reoffer timer, bumps `offerSequence`, aborts, clears the selected Slot
and renders an empty list — and sends nothing.

Duplicating `isResolvableAddress()` here was rejected deliberately. The Express
Courier `manual_review` regression had exactly that shape: a second copy of an
allowlist in `DistancePreviewController` drifted from the handler's, and one
request produced two contradictory verdicts. Two completeness thresholds could
diverge the same way, with the Slot widget and the quote disagreeing about whether
an address exists. One owner publishes the fact; the other consumes it.

`onRouteValidated` returns early when the flag is already clear, because that is a
pricing-only refresh whose reoffer is already scheduled — the guard is what keeps
item 6 from turning every package edit into a duplicate offer request.

## The server seam — where the cost control actually is

Client debouncing is per browser. It cannot stop two visitors, or one visitor in
two tabs, from buying the same journey twice. The durable control is that the
server collapses near-identical addresses onto one cache key.

`GoogleRoutesDistanceProvider::cacheIdentity()` already lowercases both
endpoints, but it does not collapse whitespace or comma spacing — so
`123 Main St, Springfield` and `123 Main St,Springfield` miss each other and each
buys the journey.
[`normalizeAddressText()`](../../src/Controller/DistancePreviewController.php:99)
now collapses runs of whitespace, normalizes comma spacing, folds repeated
commas, and trims stray commas before the address reaches the provider. It feeds
the **existing** route cache rather than adding a second layer.

This is safe to do silently because `DistanceResult::toArray()` carries distances,
not the address text it was asked about, so normalization cannot change anything
the visitor sees. Case is deliberately left alone: the provider's key already
folds it, and there is no reason to send an oddly cased address upstream.

[`pairFingerprint()`](../../src/Controller/DistancePreviewController.php:118)
echoes the ordered pair's identity on the success response, so a client, a log
line or an operator can say *which* journey was priced without repeating the
address. The pair is ordered, so A→B never collides with B→A. It is a hash used
for identity — not anonymization, and it confers no licensing right over the
addresses it was computed from. It is returned only to the client that supplied
them.

The client and server fingerprints are computed independently and neither is
derived from the other. A divergence therefore costs at most one duplicate
request and can never admit an unnormalized address, which is why the client's
`normalizeAddress()` is allowed to be a looser approximation of the server's.

Unchanged and re-verified by reading the file: the 4096-byte body bound, the
`json_decode(..., 16, JSON_THROW_ON_ERROR)` depth limit, the flood clamp
`max(5, min(120, … ?: 30))` keyed on `hash('sha256', clientIp)`,
`sanitizeQuoteInput()` over `AddressDistanceHandler::QUOTE_INPUT_FIELDS` as the
single allowlist, and `Cache-Control: no-store, private` with `Pragma: no-cache`
on every response including errors. No constructor dependency was added, so the
existing five-argument construction in `DistancePreviewControllerTest` still
holds.

## The Places widget — retained, and not what this change controls

Item 4 requires the widget to stay and requires this to be said plainly: **the new
debounce does not throttle Google's own prediction requests.** Those are issued by
the widget inside the Maps JS bundle, browser-to-Google, on a channel this module
never sees. Suppressing our preview requests changes the Routes bill, not the
Places bill.

Baseline rows 2 and 3 ([`task20-baseline.md`](task20-baseline.md)) established the
three separately billed families that are live at once: **Routes** `computeRoutes`
(rows 1, 4–9), **Places autocomplete sessions** (row 2), and **Maps JS map loads**
(rows 2, 3). Task 20B addresses the first family only.

- **Session behaviour.** Autocomplete is billed per typing session, and per page
  load for the bundle. Row 2 records that replacing the routing provider removes
  none of it. This task does not change when the widget is constructed or when a
  session starts, so its Places exposure is unchanged by design.
- **Selected-place field mask.** Already minimal, and verified rather than
  assumed: the modern path requests exactly
  `place.fetchFields({ fields: ['formattedAddress'] })`
  ([`:1106`](../../js/distance_calculator.js:1106)) and the legacy path is
  constructed with `fields: ['formatted_address']`
  ([`:1051`](../../js/distance_calculator.js:1051)). One field each. The spec's
  "fetch only required place fields where the installed API supports that change"
  is therefore satisfied with **no edit** — there is nothing to trim, and widening
  the mask is what would cost money.
- **Map loads.** Row 3: `route-map-preview.js` draws a `google.maps.Polyline`
  from the server's `encoded_polyline` and calls no routing service, so it is one
  map load per page and no request per interaction. Untouched here.

The widget's own `input` handler now calls
`markAddressesDirty(Drupal.t('Select a complete address from the suggestions.'))`,
which is the one Places-adjacent behavioural change: typing into the widget
invalidates our estimate immediately instead of leaving a stale one on screen.

A selection dispatches synthetic `input` then `change` on the hidden Webform
field. Left alone, `onFormInput` would have read that as typing and cleared the
estimate on every successful selection. Both Places paths therefore route through
[`applySelectedAddress()`](../../js/distance_calculator.js:1020), which brackets
the synthetic dispatch with `selectingAddress` so the burst commits instead of
dirtying.

## Lifecycle and teardown

Every listener is registered from a table
([`distance_calculator.js:966`](../../js/distance_calculator.js:966),
[`dispatch_slots.js:1412`](../../js/dispatch_slots.js:1412)) with a named handler,
so `teardown()` ([`:985`](../../js/distance_calculator.js:985)) and `stopAll()`
([`:1296`](../../js/dispatch_slots.js:1296)) remove exactly what was added —
including each `custom-webform-*` handler with its matching capture flag, and the
date input's `onDateChanged` via the stored `dateListener`
([`:481`](../../js/dispatch_slots.js:481)). Anonymous arrow-function listeners
cannot be removed and were replaced for that reason.

`detach()` is guarded on `trigger !== 'unload'` in both files
([`:1180`](../../js/distance_calculator.js:1180),
[`:1479`](../../js/dispatch_slots.js:1479)). This is not a stylistic choice.
Drupal also detaches with `'serialize'` before an AJAX submit, and the `once()`
mark survives that detach — so tearing down on `'serialize'` would leave a form
that is still in the DOM, still marked as processed, and permanently unwired. The
`WeakMap` keyed on the form element is what lets the guarded detach find the right
teardown for a form that is genuinely going away.

An AJAX wizard rebuild therefore re-attaches to *new* form markup, and
`once(ONCE_ID, …)` ([`:105`](../../js/distance_calculator.js:105)) keeps the
existing markup from being wired twice. No framework was added; this is
`Drupal.behaviors`, `once()`, `AbortController` and `setTimeout`.

Accessible status text is unchanged in mechanism: the dirty state writes through
the same `clearPreview()`/`setStatus()` seams the previous code used, so the
existing live-region wording still announces. `quote-summary-sidebar.js` needed
**no change** — it already listens for `custom-webform-quote-pending` (L525),
`-updated` (L529), `-cleared` (L535) and `custom-webform-booking-state` (L548), so
`clearPreview()` on dirty is already reflected in the sidebar.

Server validation is untouched. With JavaScript off, nothing described here runs
and `AddressDistanceHandler` still resolves and prices the submission on
`preSave`, exactly as baseline row 4 records.

## Configuration, and one pending divergence

`preview_debounce_ms: 600` is shipped in
[`config/install`](../../config/install/custom_webform_handlers.settings.yml:29)
with a `type: integer` entry in
[`config/schema`](../../config/schema/custom_webform_handlers.schema.yml:32). Both
files were verified by an actual Symfony YAML parse, not by eye: the shipped value
reads back as integer `600`, and the schema type reads back as `integer`.

The handler clamps it —
`AddressDistanceHandler::previewDebounceMs()` at
[`:909`](../../src/Plugin/WebformHandler/AddressDistanceHandler.php:909), bounded by
`PREVIEW_DEBOUNCE_MIN_MS` 100 and `PREVIEW_DEBOUNCE_MAX_MS` 5000, falling back to
`PREVIEW_DEBOUNCE_MS` 600 for absent or non-numeric input — and publishes it at
[`:895`](../../src/Plugin/WebformHandler/AddressDistanceHandler.php:895) in the
calculator branch only, which is the branch that attaches `distance_calculator`.
The sidebar-only branch does not attach that library and was left alone. The client
clamps again from `config.preview_debounce_ms`
([`distance_calculator.js:400`](../../js/distance_calculator.js:400)), so a value
that somehow bypasses the server clamp still cannot disable the delay.

**Pending, and deliberately so:** active config on this site has no such key —
`drush config:get custom_webform_handlers.settings preview_debounce_ms` returns
`null`. The shipped file is one key **ahead** of the active database. No update
hook was written, because mutating active configuration is Task 20F's authority,
not this task's.

That divergence is inert rather than merely tolerable: the fallback equals the
shipped default, so the running site debounces at 600 ms today and will still
debounce at 600 ms after the key is imported. Nothing observable changes when the
gap closes — which is exactly why it is safe to leave open, and why it must be
written down rather than assumed. A fresh install gets the key from
`config/install`; an existing site keeps the fallback until Task 20F imports it.

Library versions were bumped so browsers fetch the changed files:
`distance_calculator` 1.1.12 → **1.1.13** and `dispatch_slots` 1.1.1 → **1.1.2**.
`quote_summary_sidebar` stays at 1.1.11 because its file did not change. Both new
values were read back from a parse of the libraries file.

## Evidence systems, coverage limits and blockers

| System | Status | What it did or why it could not |
| --- | --- | --- |
| Graphify | **Used** | Queried before the source edits for the calculator's call path and for what reaches `refreshPreview()`/`postPreview()`. AST-only, so PHP and JS structure only. |
| GitNexus MCP | **FAILED — blocker** | `impact` on `DistancePreviewController` returned `LadybugDB unavailable for C:\Users\mo737\ppu\.gitnexus\lbug … Cannot open database in read-only mode while checkpoint is in progress.` Same failure as the 20A baseline recorded. Re-indexing is deep verification and was not requested, so it was not attempted. Blast-radius analysis for the changed PHP therefore rests on direct reads, not on graph evidence. |
| DBHub MCP | **ABSENT — blocker** | No DBHub tool exists in this session's tool list. Nothing in 20B depends on schema facts, so the blocker is recorded rather than worked around. |
| Context7 MCP | **Not required, not queried** | 20B adds no external API call and does not change how either Places path is constructed. The two field masks were read from installed source, which is stronger evidence than documentation for what this code actually asks for. |
| Direct YAML inspection | **Used — the documented primary source** | Graphify classifies `.yml` as a document, which only the semantic pass extracts, so library attachment, config install and config schema are outside its coverage. All three were read and then parsed. |
| DDEV read-only Drush | **Used** | `config:get` for the two preview keys. No export, no import, no state mutation. |

Absence of a YAML edge in the graph proves nothing here, and no claim in this
document rests on one.

## Verification performed

- `node --check` on `js/distance_calculator.js`, `js/dispatch_slots.js` and
  `js/quote-summary-sidebar.js` — all three parse.
- `php -l` on `src/Controller/DistancePreviewController.php` and
  `src/Plugin/WebformHandler/AddressDistanceHandler.php` — no syntax errors.
- Symfony YAML parse of the config install file, the config schema file and the
  libraries file, reading back the new key, its declared type and both bumped
  versions.
- `drush config:get` for `preview_debounce_ms` (null) and `preview_rate_limit`
  (30), establishing the divergence above as fact rather than inference.
- Read-back of the changed seams by name and line, cited throughout this document.
- `graphify update .` — converged: 5713 nodes, 12329 edges, exit 0. Its warning
  lists 52 zero-node files, all of them JSON editor/report files
  (`launch.json`, `settings.json`, `report.json`); no module PHP or JS is among
  them.
- `ddev exec vendor/bin/drush cr` — `[success] Cache rebuild complete.` This is
  also the parse gate for the libraries file and the config schema, both of which
  the rebuild reads.

### Structural confirmation of item 1, from the refreshed graph

`graphify explain` on the module's `onFormInput()`
(`modules_custom_webform_handlers_js_distance_calculator_setupform_onforminput`)
returns exactly six outgoing `[EXTRACTED]` edges: `isAddressInput()`,
`persistInput()`, `scheduleCommit()`, `markAddressesDirty()`, `isTrackedInput()`
and `scheduleRefresh()`. There is **no edge to `refreshPreview()` or
`postPreview()`**, and `graphify path "onFormInput" "postPreview"` finds no
directed path — while the positive control
`graphify path "commitAddresses" "postPreview"` finds one in two hops:
`commitAddresses() → refreshPreview() → postPreview()`.

The negative alone would have been worthless, because a `setTimeout` callback is
an indirect edge the AST pass may not record — an absence could just mean the
extractor cannot see it. Paired with a positive control that *is* recorded, it
establishes what item 1 asks for: no synchronous route from a keystroke to a
request. Every remaining route runs through a timer that the next keystroke
clears. `scheduleRefresh()` survives on that list on purpose — it serves tracked
**non-address** inputs, which is item 6 reusing the existing route rather than
buying a new one.

Two caveats on that evidence. The graph also indexes Drupal's aggregated asset
copies under `sites/qa/files/js/`, which are build output and are not cited here.
And `graphify path` reported an ambiguous match for both endpoints, so the module
node was addressed by its full id rather than by name.

No PHPUnit, Kernel, Functional or Functional-JavaScript tests were run, and no
browser probe was run. The spec forbids both.

## Manual acceptance — all seven checks PENDING

None of these were performed. Each is a browser check, and browser probes are
outside this task's authority. They are listed with the seam each one exercises so
they can be run without re-deriving the design.

| # | Check | Expected seam | State |
| --- | --- | --- | --- |
| 1 | Typing 20 characters produces zero new preview *and* zero new offer requests before commit | `markAddressesDirty()` returns without dispatching; `requestOffers()` returns on `addressesDirty` | **PENDING** |
| 2 | A selection followed by blur produces exactly one preview | `applySelectedAddress()` → `scheduleCommit()`; the second commit is absorbed by `lastRequestKey`/`inFlightKey` | **PENDING** |
| 3 | A repeat blur with unchanged facts produces none | replay of `lastSuccessfulData` for an unchanged request key | **PENDING** |
| 4 | Changing either endpoint clears the selected Slot | `onAddressDirty()` → `clearSelection()` + `renderOffers([], null)` | **PENDING** |
| 5 | A slow earlier response is ignored | `invalidateActivePreview()` and `offerSequence` | **PENDING** |
| 6 | An AJAX step rebuild does not duplicate listeners | `once(ONCE_ID, …)` plus the `trigger !== 'unload'` detach guard | **PENDING** |
| 7 | Complete manually typed addresses still work | `focusout`/`Enter`/`change` commit on the plain-text path | **PENDING** |

Check 1 is the one that decides whether this task achieved anything, and it is the
one no static evidence can settle. Nothing in this document should be read as a
claim that request volume has been measured. What has been established is that the
dispatch decision is now gated on a committed pair; what has not been established
is the resulting bill.

## Deliberately not done

- **The provider alias is untouched.** `custom_webform_handlers.distance_provider`
  still resolves to the Google Routes provider. The spec says to stop before
  changing it, and 20B's saving is orthogonal to which provider answers.
- **No update hook.** The active-config gap above is left open for Task 20F.
- **The Places widget is unchanged**, per item 4 — including its construction, its
  session boundaries and its already-minimal field masks.
- **`quote-summary-sidebar.js` unchanged**, because it already consumes the events
  the dirty path emits. Editing it to "support" this change would have added a
  second consumer of the same fact.
- **No frontend framework, no new dependency, no reformatting** of untouched code,
  and no edit outside `modules/custom_webform_handlers/`. The partial duplicate at
  the repository root was not touched.

## Files changed

| File | Change |
| --- | --- |
| `js/distance_calculator.js` | Dirty/commit state machine, completeness predicate, pair fingerprints, replay cache, named listeners, `teardown()`, guarded `detach()` |
| `js/dispatch_slots.js` | `addressesDirty` suspension driven by the calculator's events, named listeners, date-listener removal, hidden-tab poll guard, guarded `detach()` |
| `src/Plugin/WebformHandler/AddressDistanceHandler.php` | Three debounce constants, `previewDebounceMs()`, `preview_debounce_ms` published to the calculator branch |
| `src/Controller/DistancePreviewController.php` | `normalizeAddressText()` applied to both endpoints, `pairFingerprint()` echoed on success |
| `config/install/custom_webform_handlers.settings.yml` | `preview_debounce_ms: 600` |
| `config/schema/custom_webform_handlers.schema.yml` | `preview_debounce_ms` as `type: integer` |
| `custom_webform_handlers.libraries.yml` | `distance_calculator` → 1.1.13, `dispatch_slots` → 1.1.2 |
| `docs/architecture/task20b-evidence.md` | This document |








