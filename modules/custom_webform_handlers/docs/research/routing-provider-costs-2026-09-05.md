# Routing provider cost and capability notes

Checked 2026-09-05 against primary sources. Public list pricing is not evidence of
this site's actual account allowance or the source of its reported $52+ charge.

## OSRM

OSRM is an open-source routing engine over OpenStreetMap data. Its route service
accepts ordered longitude/latitude coordinates, not postal addresses. Its nearest
service snaps coordinates to roads; it is not an address geocoder. The documented
route result uses meters and seconds. Self-hosting avoids a vendor per-request
fee but requires compute, memory, storage, data preparation, and maintenance.
Capacity is bounded by the host. The project documents Docker and an MLD data
preparation pipeline. Use a private deployment, not the public demonstration
endpoint, as the production provider. Standard OSRM operation does not supply a
live traffic feed. [OSRM project and deployment](https://github.com/Project-OSRM/osrm-backend),
[HTTP API](https://project-osrm.org/docs/v5.24.0/api/).

## Mapbox

The published Directions API schedule shows the first 100,000 requests per month
as free, then $2 per 1,000 in the next band at the time checked. Directions is a
different product from geocoding, search, map loads, and matrix elements. Confirm
the account's plan, shared usage, and remaining allowance before assigning a site
budget. [Mapbox pricing](https://www.mapbox.com/pricing).

Directions takes coordinate pairs. `mapbox/driving` is suitable for ordinary
distance routing; `mapbox/driving-traffic` supports traffic-informed routing and
`depart_at`. Future departures use prediction, with live traffic mixed in near
the present. Coverage and fallback matter: the documentation notes fallback to
driving results where traffic coverage is absent. Do not set `trafficAware=true`
solely because the requested profile has that name. Normalize route distance and
duration, enforce result validity, and implement the documented coverage/fallback
policy. [Directions API](https://docs.mapbox.com/api/navigation/directions/),
[traffic behavior](https://docs.mapbox.com/help/dive-deeper/directions/).

Temporary Geocoding results are for immediate/session use and cannot be retained
for future reuse. Permanent Geocoding permits persistent results and has separate
pricing. Search Box is a distinct product with temporary-use restrictions. A
Directions free allowance does not confer free persistent address geocoding.
[Temporary versus permanent geocoding](https://docs.mapbox.com/help/dive-deeper/understand-temporary-vs-permanent-geocoding/),
[Search Box restrictions](https://docs.mapbox.com/api/search/search-box/).

The legal portal links its current Product Terms as a separate document; the full
Directions caching permission was not established in this research pass. Keep
Mapbox persistent route caching disabled until the applicable terms/contract are
recorded in Task 20A. [Mapbox product terms portal](https://www.mapbox.com/legal/product-terms).

## Google

Routes, Places/autocomplete, Geocoding, and map display are separate activities.
Replacing Routes does not by itself remove Places or map-load charges. Obtain a
redacted SKU/time-window breakdown before attributing the entire bill to typing.
[Maps billing overview](https://developers.google.com/maps/billing-and-pricing/overview).

The current service-specific terms distinguish use without a map, use with
non-Google maps, and caching permissions. The Routes provision permits temporary
latitude/longitude caching; do not interpret that as blanket permission to retain
all route responses indefinitely. Places/Geocoding have their own provisions.
The terms inspected apply to non-EEA billing addresses; an EEA billing account has
different terms. Before passing Google-derived coordinates into another provider,
sharing cached results between customers, or changing the map renderer, document
the applicable permissions and provenance. This is a deployment constraint, not a
claim that every mixed-provider integration is prohibited.
[Current service-specific terms](https://cloud.google.com/maps-platform/terms/maps-service-terms),
[Geocoding policies](https://developers.google.com/maps/documentation/geocoding/policies).

## Geocoding infrastructure

Do not replace paid autocomplete with the public Nominatim endpoint. Its published
policy prohibits client autocomplete and heavy use and imposes an application-wide
maximum of one request per second. These limits do not apply to separately hosted
instances under your own operation. A commercial/self-hosted geocoder and its
usage rights must be selected separately from OSRM.
[Public Nominatim policy](https://operations.osmfoundation.org/policies/nominatim/).

## Planning implications

Use persistent caches only for data with established permission; default uncertain
provider cache policies to disabled. Hashes organize cache identity, not licensing
rights or irreversible anonymization. Preserve provenance and expiry metadata.

Use ZIP codes only as context/partitions or clearly approximate estimates. Neither
route API can turn a ZIP-pair distance into an exact driveway-to-door quote.

Context7 provided primary OSRM/Mapbox excerpts. Its Drupal result was from 11.x;
it must not be copied as proof of Drupal 10.6 compatibility. No provider account
or application runtime was queried, and no billable route request was made.
