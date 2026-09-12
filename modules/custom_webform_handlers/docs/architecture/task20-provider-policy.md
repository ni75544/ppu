# Task 20 — Provider policy

Recorded 2026-09-05 alongside [the baseline](task20-baseline.md). This is a
**policy decided before implementation**, not a description of live behaviour.
Nothing here is activated. The alias
`custom_webform_handlers.distance_provider` still resolves to
`custom_webform_handlers.google_routes_distance_provider` today.

Purpose is selected by **trusted server wiring only**. No public request
parameter, header, cookie, form value or query string may select a provider, a
purpose, or an allowance. A separately configured container instance per purpose
is the mechanism; reassigning one alias is not.

## Purpose matrix — confirmed

The matrix in
[the execution README](../execution/routing-cost-reduction/README.md) is confirmed
as written, with the Google eligibility column made explicit per purpose:

| Server purpose | Eligible sequence after activation | Google eligible | Why |
| --- | --- | --- | --- |
| Ordinary preview (baseline row 1) | OSRM → Mapbox `driving` | **false** | An uncommitted address is not worth a paid request |
| Final distance-based price (rows 4, 5) | OSRM → Mapbox `driving` | **false** | Distance, not traffic, sets the price; the formula is unchanged |
| Slot offers, public (row 6) | Mapbox `driving-traffic` | **false** | Reachable anonymously; must never be a paid-request faucet |
| Background warming (row 9) | Mapbox `driving-traffic` | **false** | Nobody is waiting on it, so nothing justifies the premium |
| Final booking validation requiring traffic (row 7 confirm, row 8 Replan) | Mapbox `driving-traffic` → Google scheduled routing | **allowed, capped, and only under the conditions below** | A committed booking is the one place traffic changes a real decision |
| Scheduling where existing policy permits non-traffic estimates | OSRM → Mapbox, keeping the existing conservative margin | **false** unless traffic becomes an explicit requirement | The existing margin already covers this case |

Three rules survive from the README unchanged, and are restated because each one
is a way the policy could quietly fail:

- OSRM is never tried for a capability it does not have. It has no traffic feed
  and it is not a geocoder.
- An OSRM outage must not turn ordinary previews into Google requests. The
  ordinary chain ends at Mapbox `driving`; past that it fails visibly.
- A failed required-traffic check cannot be answered with an unlabelled static
  duration. `routing.require_traffic_aware` and the existing conservative-duration
  policy stay authoritative, enforced where they already are, at
  `DispatchPlanner::routeLeg()`.

Traffic decides schedule feasibility. It does not silently change the approved
distance-price formula. If final route facts move the price, the revised server
quote is shown before authorization and a new accepted snapshot is created. No
existing paid order is changed and no amount is raised during a payment callback.

## Allocations

| Allocation | Value set now | Reason |
| --- | --- | --- |
| Mapbox site allocation | **0** | No Mapbox account, token, plan or remaining-allowance fact exists, and no owner has approved a share of an account that other applications may also be drawing on |
| Google final-check cap | **0** | No account-side restriction or usage figure is available, so no cap can be set responsibly |
| Google allowance for preview, quote, public offer, warming | **0**, permanently by policy, not pending approval | The four purposes above are `false` by design |

**Zero means no calls.** An optional provider stays disabled until its
credentials, its permissible use, and a site allocation are all configured. A
zero allocation is not a soft limit to be rounded up on the first cache miss.

The honest consequence: with the Mapbox allocation at 0, no OSRM deployment in
existence, and no configured Geocoder provider, **this policy cannot be activated
today.** Until Tasks 20D, 20E and 20F land and 20H confirms runtime readiness, the
existing Google alias remains in place and Task 20B's request suppression is the
only cost reduction available. Publishing this matrix does not reduce a single
call by itself, and no percentage is claimed for it.

Every paid HTTP attempt, **including a retry**, reserves allowance before
transmission — never after the response, and never after a retry has already been
sent. Account-level quotas remain necessary regardless: this site's ledger cannot
see other applications sharing the same account.

Cache or Redis loss must not reset a financial limit or open an unrestricted paid
fallback. The baseline records that `cache.default` is Redis-backed and volatile,
which is precisely why the ledger cannot live there.

## Final-check eligibility

Google may be reached **only** by one of two operations, decided in server code
before any request is formed:

1. **A server-validated checkout operation.** The order exists, the server
   computed the quote, the submission passed validation, and the operation is the
   confirmation step that a real booking depends on — baseline row 7's
   `confirmReservation()` path via `DispatchCheckoutManager.php:362`.
2. **A permissioned staff scheduling action that genuinely requires traffic.**
   Today that is Replan, already gated by `_permission: 'manage dispatch schedule'`
   at `custom_webform_handlers.routing.yml:379`.

Everything else is ineligible. In particular, and this is the live condition the
baseline found:

- **`dispatch/hold/{webform}` must not reach the final-check instance.** It is
  anonymous today (`routing.yml:79`, `_access: 'TRUE'` plus CSRF) and it reaches
  the traffic-aware chain through `DispatchBooking::hold()` L240 →
  `createHold()` → `planFor()`. Under this policy a Hold resolves the
  **public-offer** instance — Mapbox `driving-traffic`, Google forbidden. Task 20G
  owns that rewiring, and it is a correctness requirement, not a tuning choice.
- **`dispatch/renew` and `dispatch/release` must stay routeless.** They reach no
  route lookup today (`DispatchBooking::renew()` L256, `release()` L295 touch only
  the hold store and the session owner). Neither may acquire one.
- **`distance-preview` is never eligible.** It is anonymous and fires while an
  address is being typed.
- A retry, a poll, a re-offer, a warm job, or a queue replay is never promoted to
  final-check eligibility by virtue of having failed once.

CSRF protection is not authorization. All four dispatch endpoints already carry a
CSRF header requirement and three of them are still anonymous; the token proves
the request came from the site's own page, not that a paid operation was
authorized.

## Coordinate source, and what may be done with a coordinate

**Selected source:** a single `AddressCoordinateResolver` adapter over **one
approved Drupal Geocoder provider**, shared by OSRM and Mapbox so a fallback never
re-geocodes. No second geocoding HTTP client is written while an installed provider
owns that operation.

**Selected provider (Task 20D): `ppu_nominatim`.** It is a Drupal Geocoder
`nominatim` provider backed by this project's self-hosted service and the pinned
NYC metro extract `nycmetro-20260904T202121Z+md5.77b0c1c4`. Its package,
configuration entity, import measurements, coverage probes and hardening evidence
are recorded in `task20-geocoder-deployment.md`. The source is under this
project's operation, so all three self-hosted permissions in the table below
apply; public Nominatim remains prohibited.

**Public Nominatim is not a candidate.** Its published policy forbids client
autocomplete and heavy use and imposes an application-wide maximum of one request
per second. It is never selected as an autocomplete substitute. A separately hosted
instance under this project's own operation is a different question, and would be a
Task 20E deployment decision with its own capacity facts, not a free swap.

Permissible use, per coordinate provenance:

| Provenance | Persist? | Pass to another provider? | Render on a non-origin map? |
| --- | --- | --- | --- |
| Google Geocoding / Places | **No** — the shared persistent cache is **disabled** for Google-derived coordinates until the applicable service-specific terms and this account's billing region are recorded | **No** | **No** |
| Mapbox Temporary Geocoding / Search Box | **No** — temporary use only, by product definition | **No** | **No** |
| Mapbox Permanent Geocoding | Permitted in principle, once purchased and recorded | Subject to the same recorded terms | Subject to the same recorded terms |
| Self-hosted geocoder under this project's operation | Yes | Yes | Yes |

Two constraints behind that table. A Directions free allowance confers **no**
right to persistent address geocoding — they are different products. And the
Google Routes provision permitting *temporary* latitude/longitude caching is not
blanket permission to retain route responses indefinitely; the terms inspected
apply to non-EEA billing addresses, and this account's billing region has not been
established.

Where a permission is unresolved, the prescribed consequence applies rather than a
judgement call: **the affected shared cache or cross-provider path is disabled**
until the terms are recorded. That is why the first two rows read "No" — not
because mixed-provider integration is prohibited in general, but because the
permission has not been established for this account.

## Cache policy consequences

Two layers, as designed in the README, with the rights dimension made binding:

1. **Address resolution** — keyed on canonical full-address hash, country, postal
   code, geocoder identity and version, and the applicable use restrictions →
   validated coordinates, precision, provenance, and original fetch and expiry
   times. The restriction belongs *in the key*: a coordinate cached under
   permissive terms must not be served to a request governed by stricter ones.
2. **Route calculation** — keyed on ordered endpoint coordinates, provider, routing
   profile, relevant vehicle and avoidance parameters, data version, traffic and
   departure bucket, geometry representation, and policy version → normalized route
   result.

Binding consequences:

- **Mapbox persistent route caching is disabled.** Context7 could not resolve the
  Directions caching permission from Mapbox's own documentation in this pass, so
  the research note's instruction stands unchanged until the applicable Product
  Terms or contract is recorded.
- A cache hit keeps its **original** fetched time and provenance. It must never
  present itself as newly calculated.
- A route is **directional**. A→B is not B→A, and no key may conflate them.
- Coordinate identity uses the resolver's canonical precision. No extra coarse
  rounding is applied to widen hit rates — that trades a real address for a nearby
  one.
- A hash organizes cache identity. It confers no licensing right and is not
  irreversible anonymization.
- ZIP codes may **partition** the address cache; they are never sufficient keys.
  Two addresses sharing a ZIP are different jobs. Any ZIP-centroid estimate lives
  in an explicitly approximate namespace, ships disabled, and can never produce a
  payable quote, a confirmable Slot, or a reservation fact.
- No provider error yields a zero-mile route. `DistanceResult` already rejects a
  non-positive distance in its constructor; that guarantee is preserved, not
  reimplemented. Public messages distinguish "this address needs correcting" from
  "the estimate is temporarily unavailable".
