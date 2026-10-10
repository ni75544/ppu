# Task 24H — A3 HTTP replay diagnosis (ppu-qa only)

Read-only target: `https://ppu-qa.ddev.site`, DDEV project `ppu`, DB-isolated
(`database=qa`), routing mode `development_fixed` (offline fixed provider, no
socket). `ppu` (default site) was left read-only throughout. Historical HTTP 500
remains **unconfirmed after Drupal service-container cache rebuild** and was not
reopened.

## Question

The initial HTTP acceptance run had A3 (a `route_preview` replayed under the same
operation id in the same cookie jar) return `success` with a *new*
`calculated_at`, i.e. an apparent recompute. Cause unknown; timestamp change is a
symptom, not proof of which code ran.

## Method — measure provider calls directly, not via timestamp/rows

Temporary, additive, log-only QA instrumentation (no business logic changed):
- `DistancePreviewController::resolvePreview()` — one line recording the derived
  owner hash, operation hash, and the ledger fast-path **state**; one line
  recording each real `distanceProvider->calculate()` invocation.
- `RouteRequestOwner::token()` — one line recording session `isStarted`, a hash
  of the session id, and session-cookie presence.
All values logged are hashes/booleans; no owner value, address, reference,
cookie, or token was logged or printed.

Reproductions used the same cookie jar, host, operation id, and canonical inputs;
a unique address pair per run eliminated cross-run identity reuse.

## Evidence

Unprimed (cold cookie jar), unique pair — two distinct owners, two calculations:

```
owner-derive started=0 sidhash=e3b0c44298fc cookie=absent   # sha256('')
fastpath owner=9b3ee4541c9c op=524ead0b3867 state=NULL       # A1 (empty-session owner)
CALCULATE owner=9b3ee4541c9c op=524ead0b3867
owner-derive started=1 sidhash=d737749a39f1 cookie=set
fastpath owner=b5b86c163631 op=524ead0b3867 state=NULL       # A3 (real-session owner) -> MISS
CALCULATE owner=b5b86c163631 op=524ead0b3867
```

Primed (session established by a throwaway POST first) — one stable owner, replay:

```
fastpath owner=9465c803afd4 op=d8ed9e4a705b state=NULL       # A1 -> calc once
CALCULATE owner=9465c803afd4 op=d8ed9e4a705b
owner-derive started=1 sidhash=d044777c791b cookie=set
fastpath owner=9465c803afd4 op=d8ed9e4a705b state=completed  # A3 -> NO calc (replay)
```
A1 `calc=1789800842`, A3 `calc=1789800842` (identical; replay served the record).

## Measured counts

| Request | provider calculate() | outbound network attempts |
| --- | --- | --- |
| A1 (first route_preview) | 1 (offline fixed provider) | 0 |
| A3 (same-op replay), primed session | 0 | 0 |
| A3 (same-op replay), cold jar | 1 (offline fixed provider) | 0 |

Owner/identity stable across A1/A3 **only when the session pre-exists**. Operation
id identical in all cases. Outbound attempts zero throughout — `development_fixed`
serves `FixedDevelopmentRouteProvider`, which opens no socket (source-verified).
"Provider calls" above are in-process fixed-provider invocations, not billed calls.

## Correction (supersedes an earlier "harness artifact" reading)

An earlier version of this document concluded A3 was purely a harness cold-start
artifact and that priming the session was the fix. **That was wrong.** Priming
made the acceptance pass (10/10) but only hid the symptom. The underlying
behaviour is a real **first-request owner-initialization defect**, and cold-request
ownership remains **defective until fixed**. Primed replay works; that does not
absolve the cold path.

## Confirmed cause

On the first POST from a cookieless anonymous client there is no session cookie
yet, so `RouteRequestOwner::token()` — called in `resolvePreview()` before any
session write — derives the owner from an **unstarted session whose id is `''`**,
yielding owner `sess:sha256('')` (`sidhash=e3b0c44298fc` == sha256 of the empty
string). That request nonetheless returns `success` and writes a ledger
completion (and a route fact) **under that placeholder owner**.

This violates the owner-scoped design: **every cookieless client derives the same
`sess:sha256('')` owner**, so the record lands in a shared bucket rather than a
per-session one. This is an **isolation risk** — it has not been demonstrated that
one client read another's data, and no such claim is made here.

The A1→A3 recompute is the visible consequence: A1 stores under the empty-session
owner; A1's response sets the real cookie; A3 (same op id, same jar) derives the
**real** owner, looks up a different ledger key, misses, and recomputes offline.

Likely mechanism (to confirm from installed core before editing): Drupal 10.6.16
lazy anonymous sessions — `SessionManager::start()` is suppressed for cookieless
anonymous requests, so `getId()` stays empty until something is written to the
session (the PrivateTempStore write happens later, after `token()` ran).

## Required fix (narrow; not yet applied)

Before any ledger lookup/claim, fact write, or provider calculation, ensure a
unique, session-backed anonymous owner exists and stays consistent across this
request and later requests carrying its cookie; if one cannot be established,
return a typed failure **before** calculation. Must not use an empty session id
or its hash, a shared anonymous fallback, an unvalidated client-supplied owner,
or a fresh unpersisted random owner per request. Authenticated (`uid:<n>`)
ownership and the existing replay/retention design are preserved.

## Measurement note

The priming POST used earlier is itself an extra `route_preview` calculation;
moving it outside the measured window does not resolve the defect. The acceptance
harness must instead exercise the cold path directly (see the task memory for the
check list) and count all setup + measured requests.
