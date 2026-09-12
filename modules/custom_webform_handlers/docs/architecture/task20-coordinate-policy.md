# Task 20D — coordinate resolution and cache policy

## Decision

Committed route endpoints resolve through the `ppu_nominatim` Drupal Geocoder
provider and no other source. The provider reaches the private Nominatim service
over the project network and is pinned to
`nycmetro-20260904T202121Z+md5.77b0c1c4`. Provisioning, import, measured coverage
and database hardening are recorded in `task20-geocoder-deployment.md`.

The browser's Google place ID and coordinates are presentation hints only. They
do not enter the authoritative cache key and are never forwarded to OSRM or
Mapbox. `AddressCoordinateResolver::resolve()` always obtains a server-validated
result. A later server-issued reference may optimize that path only if it is
bound to the normalized submitted address and the same provider/version.

## Contrib reuse and cache ownership

The resolver reuses the configured Drupal Geocoder plugin, its HTTP adapter,
throttle and Nominatim provider package. It does not implement a second geocoding
HTTP client.

Contrib's `cache.geocoder` cannot own this layer: it stores results permanently,
does not include the dataset version, and cannot express provider storage rights.
The resolver therefore reconstructs the approved configured plugin with Drupal's
in-memory cache backend. This preserves contrib's request construction and
throttle while ensuring that `CoordinateCache` is the only persistent owner.

`cache.custom_webform_handlers_coordinates` is a dedicated Drupal cache bin.
`CoordinateCache` also memoizes hits, misses and negative results for the request
cycle, so an unavailable cache backend does not turn two fallback adapters into
two geocoding requests. Cache read/write failures are logged without an address,
coordinate, cache key or upstream message.

## Identity, provenance and permissions

The positive key is a SHA-256 digest over all of the following values:

- normalized full submitted address;
- country and full postal context;
- configured provider entity and pinned data version; and
- persistence, cross-provider and external-map permissions.

Whitespace and comma spacing are normalized and case is folded. House numbers,
directionals, units, entrances and every other address token remain in identity.
The provider query may omit only an NY/NJ/CT state abbreviation because this
extract lacks the state boundary Nominatim needs to interpret that token; the
unmodified token remains in cache identity. A ZIP code partitions identity but
never replaces the full address. A→B ordering belongs to the route cache in Task
20F; this coordinate cache resolves A and B independently.

Every `ResolvedLocation` retains the normalized input identity, canonical
provider address, coordinates at provider precision, country/postal context,
provider and OSM source IDs, data version, original fetch time, expiry, and the
three reuse permissions. A cache hit restores those original values; it never
reports the read time as the fetch time.

Because the source is self-hosted, persistence, cross-provider routing and
non-origin-map rendering are permitted. Those permissions are true only for the
approved `ppu_nominatim` entity. The resolver refuses public Nominatim and refuses
any different plugin or provider selection instead of inheriting permissions.
The opaque hash is an identity mechanism, not anonymization; positive payloads
still contain address data and remain private application storage.

## Freshness and refusal policy

- Positive coordinates expire after 30 days. A changed dataset version produces
  a different key immediately, independently of that TTL.
- Invalid or ambiguous addresses are cached for 15 minutes.
- Clearly out-of-coverage addresses are cached for one hour.
- Provider outages, timeouts, malformed responses, throttling and configuration
  failures are never negative-cached as facts about an address.

Only building/address precision is accepted for courier endpoints. PO boxes,
missing coordinates, non-finite/range-invalid values, missing house/street data,
country/postal mismatches and candidates outside the extract bounds are refused.
Multiple otherwise valid candidates separated by more than 250 meters are
ambiguous and require correction. OSRM owns the later road-snap/unrouteable-access
check; no failure here fabricates zero distance.

Explicit states outside NY/NJ/CT, postal prefixes outside the metro envelope and
named foreign countries produce `outside_coverage`, which a provider chain may
advance past. An unresolved in-area address produces `address_unusable`, which is
customer-correctable and terminal. The bounded query and coordinate containment
check remain the final coverage authority.

## Budget and rollout boundary

The selected private provider carries a conservative 100,000-request monthly
operational ceiling. A zero ceiling disables resolution immediately. Durable,
atomic reservation of metered geocoder allowances belongs to Task 20F; this
service is not connected to a public route caller before that gate. Request-local
memoization remains active even when the persistent cache is down.

Task 20D registers the resolver and cache only. It does not add OSRM/Mapbox
adapters and does not change `custom_webform_handlers.distance_provider`, which
still aliases the stabilized Google implementation.

## Acceptance matrix

| Case | Required result |
| --- | --- |
| Two streets in the same ZIP | different coordinate cache identities |
| Same address resolved twice in one request | one provider lookup at most |
| Reverse route direction | same endpoint coordinates, ordered separately by the later route cache |
| Browser coordinate nearer than submitted address | ignored; server result remains authoritative |
| Dataset version changed | old persistent entry cannot be read by the new key |
| Provider result past expiry | miss; original timestamp is never refreshed |
| PO box or missing house/street precision | `address_unusable` |
| Explicit address outside the metro/country | `outside_coverage` |
| Cache backend unavailable | one request-local provider attempt, no unrestricted repeated geocoding |
| Provider transport outage | unavailable classification, never a negative address entry |

