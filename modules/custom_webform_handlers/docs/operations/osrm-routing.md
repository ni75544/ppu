# Private OSRM routing: preparation and cutover runbook

Prepared in Task 20E and deployed locally in Task 20H. This remains the operator
runbook; the measured deployment and acceptance record live in
[Routing cost rollout](routing-cost-rollout.md) and
[Task 20 routing acceptance](../releases/task20-routing-acceptance.md).
Do not execute an import from a Drupal request or enable a provider merely because
its class can be constructed.

## Verified local inputs

Read from the existing Nominatim deployment record and compose source:

| Input | Value |
| --- | --- |
| Source volume | External Docker volume `ppu-osm-data` |
| Source filename | `nyc-metro-260904.osm.pbf` |
| Source size | 269,504,387 bytes |
| Source MD5 | `77b0c1c4f92b37ccd0fb32881dc04b31` |
| Dataset identity | `nycmetro-20260904T202121Z+md5.77b0c1c4` |
| Requested extract bounds | West -74.35, south 40.45, east -72.75, north 41.20 |
| Extraction method | `osmium extract --strategy=complete_ways --set-bounds` |
| Snapshot provenance | Parent timestamp `2026-09-04T20:21:21Z`; not a timestamp present in the child header |

Use the exact same PBF bytes as Nominatim. A matching dataset does not guarantee
that the two engines choose the same road entrance. OSRM still snaps geocoded
coordinates; excessive snaps and unroutable endpoints must fail visibly.

The source covers the NYC metro area across state lines. The requested rectangle
is not an exact polygon of routable coverage: complete ways can extend beyond
it, and two included endpoints can require roads outside it. Check cross-Hudson
and edge routes before offering service there. OSRM's car profile is not a
truck-clearance or commercial-vehicle legality guarantee; cargo dispatch must
retain its existing eligibility/manual-review restrictions.

Docker inspection on 2026-09-08 confirmed that `ddev-ppu-web` belongs to
`ddev-ppu_default` and `ddev_default`. Use the project-specific
`ddev-ppu_default`, not the shared network. Re-inspect after a DDEV recreation.
No Nominatim mounts, authentication rules, passwords, or database volumes need to
change for OSRM.

## Image gate

The deployed local generation uses the official OSRM `26.8.0-debian` image and
records both the registry manifest and the selected Linux/AMD64 image digest:

| Item | Verified value |
| --- | --- |
| Release | `v26.8.0` |
| Manifest digest | `sha256:3ac496ff8fd7e1af53846179d73d06a97f719c8ad2217d008ed868942398665c` |
| Linux/AMD64 image digest | `sha256:05d11e09c919d32996c3ae0784fda841c688943cf4ae4ec67f38d222082d04af` |
| Engine | `v26.8.0` |
| `/opt/car.lua` SHA-256 | `1b03f79784b94cb7d71b89c2eb62410a17e96d92a8e5f407f3b2dd244a179818` |

The serving definition pins the architecture-specific digest in
`.ddev/docker-compose.osrm.yaml`. Use that identical image for extraction,
partitioning, customization, and serving. A release tag alone is not an
immutable deployment pin. Do not substitute `latest`, a public routing demo, or
an invented digest.

Source: [OSRM releases](https://github.com/Project-OSRM/osrm-backend/releases).

## Measured local generation

The dedicated graph volume is `ppu-osrm-nycmetro-20260904-v2680`; its complete
contents measured 1,125,912,511 bytes after preparation. The three stages
completed successfully with the following observed peaks:

| Stage | Elapsed | Peak memory |
| --- | --- | --- |
| Extract | 1 min 53 sec | 2,203,635,712 bytes |
| Partition | 39 sec | 577,347,584 bytes |
| Customize | 12 sec | 716,546,048 bytes |

These are measurements for this exact PBF, image, profile, and host—not sizing
promises for future generations. The live graph is mounted read-only and the
router runs without a host port as unprivileged user `65534:65534` with a
read-only root filesystem, dropped capabilities, and `no-new-privileges`.

## Isolated preparation

The companion [compose template](../../ops/osrm/compose.yml) deliberately requires
an image, an existing application network, and an existing output volume. It
does not create source datasets or silently reuse a Nominatim database volume.

Set these process-local variables in the operator's shell before running it:

- `OSRM_IMAGE`: the verified immutable image reference above.
- `OSRM_APP_NETWORK`: the inspected project network, currently
  `ddev-ppu_default`.
- `OSRM_DATA_VOLUME`: a newly created, dedicated OSRM build volume. Never use
  `ppu-osm-data`, `ddev-ppu_nominatim-db`, or a Drupal/MariaDB volume here.
- `OSM_SOURCE_VOLUME`: defaults to the existing `ppu-osm-data`.

Create and inspect that dedicated output volume under the infrastructure
authorization for provisioning. Keep the PBF and every generated `.osrm*` file
out of Git. The source volume is mounted read-only; the build volume is writable
only in the explicit preparation service.

Run these stages individually. Each must exit successfully before the next:

1. Validate the source checksum and size inside the preparation container.
   Require an empty build directory, then copy only the named NYC PBF into it.
   Recheck the copied bytes. Do not download a moving `-latest` extract.
2. `osrm-extract -p /opt/car.lua /data/nyc-metro-260904.osm.pbf`
3. `osrm-partition /data/nyc-metro-260904.osrm`
4. `osrm-customize /data/nyc-metro-260904.osrm`
5. Record the PBF identity, image digest, car-profile digest, stage exit codes,
   output size, elapsed time, and observed peak memory with the build.

Invoke each engine stage through `docker compose --profile prepare run --rm
prepare <command>` with `-f modules/custom_webform_handlers/ops/osrm/compose.yml`.
The preparation service has no network connection. No long-running preparation
stage is authorized merely by installing this template.

The `.osrm` argument identifies a family of files, not necessarily one standalone
file. Preserve the complete output family. MLD preparation and serving follow
the [official OSRM deployment sequence](https://github.com/Project-OSRM/osrm-backend).

## Resource limits and private serving

The template's default limits are initial safety limits, **not measured sizing**:
2 CPUs and 4 GiB for preparation, 2 CPUs and 2 GiB for serving. Nominatim and
Drupal also need memory. Measure host headroom before starting; reduce
concurrency or explicitly adjust limits if required. An OOM-killed build is not
complete. Never convert its partial files into the serving volume.

Disk needs include the copied 269.5 MB source, the whole MLD output family,
temporary build files, and a separate rollback generation. No reliable regional
RAM/disk multiplier has been measured for this deployment; record real values
on the first build instead of estimating them from PBF size.

Start only the serving service after preparation succeeds. It uses read-only
data, a read-only root filesystem, an unprivileged user, dropped capabilities,
and no host-published ports. The application can reach `http://ppu-osrm:5000`
on the selected project network. Other containers on that same network can also
reach it; this is network isolation, not per-client authentication.

Use TLS/authenticated private ingress if routing ever crosses that trusted
network. Do not add host-wide exposure or send real addresses to a demo server.

## Bounded readiness checks before activation

From the web container, request this public-coordinate health route with a short
timeout:

```text
http://ppu-osrm:5000/route/v1/driving/-73.9857,40.7484;-73.9772,40.7527?alternatives=false&steps=false&overview=false&radiuses=100;100
```

Require HTTP success, `code=Ok`, one usable route, positive finite distance and
duration, and two plausible snapped waypoints. An HTTP listener alone does not
prove that the graph loaded. Confirm the runtime image digest and build manifest
match the prepared generation. See the [OSRM HTTP contract](https://project-osrm.org/docs/v5.24.0/api/).

Then perform separately authorized, bounded checks for:

- Manhattan routing in both directions, since routes are directional.
- Manhattan to Jersey City and a route near the metro extract edge.
- An endpoint beyond the extract: no fabricated zero-distance success.
- An unreachable private endpoint and an excessive snap: classified failure,
  without an ordinary-preview fallback to Google.
- A required-traffic scheduling request: OSRM must not claim traffic support.

Record checks as pending until actually run. No paid Mapbox or Google request is
part of this infrastructure readiness check.

## Configuration and guarded cutover

During the later authorized activation, supply the private endpoint through the
environment-backed Drupal Settings key
`custom_webform_handlers_osrm_base_url`, not a public form field.

Record the exact hostname/scheme allowlist and dataset identity in
`custom_webform_handlers.settings:routing.providers.osrm`. An HTTP scheme is
permissible only inside the chosen trusted Docker network. Keep OSRM disabled
and keep the existing distance-provider alias until the chain's cache, financial
limits, failure handling, and consumer integration have passed their gates.

## Refresh, swap, and rollback

Build a new generation in a different output volume. Never prepare data in a
volume mounted by the live router. A monthly refresh is an operator procedure,
not a scheduler installed by this runbook.

Refresh Nominatim and OSRM from the same new PBF. Record the new dataset identity
and both manifests; matching filenames alone are insufficient. Validate the new
router on an isolated endpoint before swapping it into service. If the engine or
car profile changes without changing the PBF, also change the route-cache
generation identity or invalidate the affected route cache explicitly; retain
the geocoder's true PBF identity.

Coordinate the service endpoint, dataset configuration, and cache invalidation
so requests never use old coordinate provenance with a newly labelled graph.
Rebuild Drupal caches after authorized configuration changes. Preserve the last
known-good image, output volume, endpoint settings, and data-version values.

Rollback restores those matching values and the prior router together; do not
relabel old data as new. Remove a failed generation only after verifying its
exact volume name and that no live container mounts it. Never prune Docker
volumes broadly, and never delete source/Nominatim/Drupal volumes as OSRM cleanup.
