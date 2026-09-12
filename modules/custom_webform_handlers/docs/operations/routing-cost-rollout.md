# Routing cost rollout

Task 20H operating record. Updated 2026-09-10 (America/New_York).

## Current deployment state

The bounded local activation is complete. Ordinary, time-independent quotes use
the private OSRM service. Traffic-aware public offers and final checks remain
fail-closed because no Mapbox allocation has been approved and Google remains
disabled with a zero allowance. This is not a production paid-provider
activation.

| Purpose | Effective eligible chain | State |
| --- | --- | --- |
| Ordinary preview and quote | OSRM | Ready and locally accepted |
| Public offers and warming | none | Unavailable; Mapbox disabled |
| Validated final traffic check | none | Unavailable; Mapbox and Google disabled |

Purpose is fixed by the service container. No public parameter, header, form
value, cookie, or query string may select a purpose or provider.

## Provider and credential inventory

| Item | Effective state |
| --- | --- |
| OSRM endpoint | Present through environment-backed Drupal Settings; the value is not rendered by Drupal |
| Mapbox token | Absent; provider disabled; site allowance 0 |
| Google server key | Present for legacy compatibility; routing provider disabled; site allowance 0 |
| Geocoder | `ppu_nominatim`, private and keyless |
| Browser Places key | Separate browser credential; unchanged by Task 20 |

The credential panel reports presence only. It never reads or renders a value.
Local counters and allowance reservations shown on the same permissioned page
are not vendor invoice totals and do not include other applications sharing an
account.

## Dataset and runtime pin

Nominatim and OSRM use the same source bytes:

| Property | Value |
| --- | --- |
| Extract | `nyc-metro-260904.osm.pbf` |
| Requested bounds | `-74.35,40.45` to `-72.75,41.20`, `complete_ways` |
| Source size | 269,504,387 bytes |
| Source MD5 | `77b0c1c4f92b37ccd0fb32881dc04b31` |
| Data version | `nycmetro-20260904T202121Z+md5.77b0c1c4` |
| OSRM image | `ghcr.io/project-osrm/osrm-backend:26.8.0-debian` |
| Linux/AMD64 image digest | `sha256:05d11e09c919d32996c3ae0784fda841c688943cf4ae4ec67f38d222082d04af` |
| Car profile SHA-256 | `1b03f79784b94cb7d71b89c2eb62410a17e96d92a8e5f407f3b2dd244a179818` |
| Prepared graph volume | `ppu-osrm-nycmetro-20260904-v2680` |
| Prepared volume contents | 1,125,912,511 bytes |

The initial runbook named an unavailable `v26.9.0` image. Activation stopped at
that gate. The official 26.8.0 release and registry metadata were then checked,
the Linux/AMD64 digest was pulled explicitly, and the same immutable image was
used for extraction, partitioning, customization, and serving.

### Measured preparation

| Stage | Exit/OOM | Wall time | Peak memory reported by OSRM |
| --- | --- | --- | --- |
| `osrm-extract` | 0 / false | 1 m 52.5 s | 2,203,635,712 bytes |
| `osrm-partition` | 0 / false | 39.2 s | 577,347,584 bytes |
| `osrm-customize` | 0 / false | 12.5 s | 716,546,048 bytes |

One generated `.osrm.fileIndex` arrived with mode `700`. The first unprivileged
launch refused it. Its mode was corrected to `0644`; no data was regenerated.
The next launch succeeded.

## Runtime security and health

`.ddev/docker-compose.osrm.yaml` starts the router with:

- user and group `65534:65534`;
- a read-only root filesystem and read-only graph mount;
- all Linux capabilities dropped and `no-new-privileges` enabled;
- 2 CPUs, 2 GiB memory, 128 processes, and two routing threads;
- port 5000 exposed only to the DDEV project network; no host port binding.

The public-coordinate readiness route returned HTTP 200, `code=Ok`, a positive
2,403.3 m route, a positive 304.9 s duration, and two waypoints inside the 100 m
snap bound. Nominatim `/status` also returned HTTP 200 after the same restart.
The OSRM runtime image ID matched the pinned Linux/AMD64 digest.

## Active Drupal settings

The existing configuration was merged before these narrow values were saved;
unrelated site overrides were preserved.

| Setting | Active value |
| --- | --- |
| `routing.billing_period` | `month` |
| `routing.billing_timezone` | `America/New_York` |
| `routing.providers.osrm.enabled` | `true` |
| `routing.providers.osrm.allowed_schemes` | `http` |
| `routing.providers.osrm.allowed_hosts` | `ppu-osrm` |
| `routing.providers.osrm.data_version` | pinned value above |
| `routing.providers.mapbox.enabled` / allowance | `false` / `0` |
| `routing.providers.google_routes.enabled` / allowance | `false` / `0` |

HTTP is permitted only because the endpoint is a private service on this one
Docker network. A remote or host-published deployment must use authenticated TLS
and a correspondingly narrow scheme/host allowlist.

The endpoint itself is supplied as
`CUSTOM_WEBFORM_HANDLERS_OSRM_BASE_URL=http://ppu-osrm:5000` to the web container,
then read into `custom_webform_handlers_osrm_base_url` in `settings.php`. It is
not accepted from active configuration or a public form.

## Operator usage summary

The permissioned page at **Configuration > Web services > Routing Providers**
now reports:

- provider/purpose attempts, cache hits, failures, throttles, allowance refusals,
  circuit-open events, and fallback reasons;
- durable current-period reservations and remaining configured allowance; and
- current circuit state and consecutive provider faults.

The counters have fixed dimensions and store no address, coordinate, endpoint,
route geometry, customer value, credential, or provider payload. Update `10044`
created `custom_webform_handlers_routing_usage`; the existing durable financial
ledger remains `custom_webform_handlers_routing_budget`. Both tables exist and
the installed configuration validated with zero schema violations.

The acceptance session and its isolated cache proof ended with four OSRM
attempts, nine cache hits, one earlier transient failure, and a closed OSRM
circuit with zero consecutive faults. Seven private Nominatim reservations were
used, leaving 99,993 of the 100,000 local operational allowance. Mapbox and
Google recorded zero Directions reservations. These are local counters, not
vendor totals.

## Controlled activation procedure

1. Confirm the exact source and graph volumes exist. Never substitute a moving
   `latest` extract or prepare into the volume mounted by the running router.
2. Confirm `.ddev/docker-compose.osrm.yaml` names the pinned image digest and
   graph volume, then start DDEV.
3. From the web container, call the public-coordinate readiness route recorded
   above. Require HTTP 200, `code=Ok`, positive finite distance/duration, and both
   snaps within 100 m. Also require Nominatim `/status` HTTP 200.
4. Confirm the Routing Providers page reports the OSRM endpoint as present and
   the ordinary purpose as ready. Do not disclose the endpoint setting or any
   credential while diagnosing readiness.
5. Apply only the approved provider fields. For this deployment that means OSRM
   enabled with `http`, host `ppu-osrm`, the pinned data version, Mapbox disabled
   with allowance 0, and Google disabled with allowance 0.
6. Rebuild Drupal caches once. Do not bulk-import configuration and do not run an
   unrelated pending database update.
7. Run the bounded public-address matrix in
   `../releases/task20-routing-acceptance.md`. Stop if a paid reservation changes
   or a route leaves the approved provider chain.
8. Record the activation as local, staging, or production. Paid-provider
   activation is a separate operator decision with its own account cap.

## Blocked prerequisites

- Mapbox remains blocked on a token, an operator-approved account allocation,
  and an explicit route-result storage-rights decision.
- Google remains blocked on account restriction evidence, an approved final-only
  cap, and a sandbox acceptance window.
- Browser interaction rows, stale-response behavior, concurrent-cache failure,
  map display, and revised-price/payment behavior remain pending.
- No comparable pre/post production observation periods exist, so no savings
  percentage is claimed.

When production observations exist, compare Routes, Places, and map SKUs
separately; provider calls per committed address pair and completed booking;
cache hits; fallback rate; latency; scheduling refusals; and OSRM/Nominatim
hosting cost. Do not attribute Places or Maps JavaScript spend to routing.
