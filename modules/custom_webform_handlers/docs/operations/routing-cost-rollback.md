# Routing cost rollback

Task 20H rollback procedure. This preserves reservations, paid orders, request
suppression, and financial limits.

## Preferred rollback order

1. Open **Configuration > Web services > Routing Providers** with the
   `administer routing providers` permission.
2. Switch off **OSRM may be asked**. Leave Mapbox and Google disabled and leave
   both allowances at 0 unless an operator has separately approved a capped
   paid-provider rollout.
3. Save and rebuild Drupal caches once.
4. Confirm ordinary readiness reports unavailable and the public quote returns a
   recoverable unavailable result. Do not restore Google previews as an automatic
   response to an OSRM fault.
5. If the router itself must be stopped, stop only `ddev-ppu-osrm`. Keep
   `ppu-osrm-nycmetro-20260904-v2680`, `ppu-osm-data`, the Nominatim database,
   Drupal database, Redis, and all reservation/order storage intact.

This is the safest rollback: temporary quote unavailability costs nothing and
does not invent route facts. Task 20B’s dirty/commit suppression remains in
place, as do the durable paid-call ledger and all zero allowances.

## Restore a known-good OSRM generation

The service image, graph volume, Drupal data version, and endpoint allowlist are
one deployable unit. Restore all of them together:

| Item | Current known-good value |
| --- | --- |
| Image | `ghcr.io/project-osrm/osrm-backend:26.8.0-debian@sha256:05d11e09c919d32996c3ae0784fda841c688943cf4ae4ec67f38d222082d04af` |
| Graph volume | `ppu-osrm-nycmetro-20260904-v2680` |
| Endpoint host | `ppu-osrm` |
| Data version | `nycmetro-20260904T202121Z+md5.77b0c1c4` |

Never relabel an old graph with a new data version. A graph prepared with a new
PBF or car profile must use a new volume and route-cache identity. Keep the prior
volume until the replacement passes the full bounded readiness matrix.

After restoring a generation:

1. Start the private router without a host port binding.
2. Verify the runtime image digest, unprivileged user, read-only root and graph
   mount, dropped capabilities, and `no-new-privileges` setting.
3. Require the public-coordinate health route to return HTTP 200, `code=Ok`, a
   positive finite distance/duration, and snaps within 100 m.
4. Verify Nominatim is healthy and its configured data version matches the
   router’s version.
5. Re-enable OSRM only after those checks, rebuild Drupal caches, and repeat the
   bounded Manhattan, reverse, Jersey City, Stamford, and outside-coverage
   checks.

## Paid-provider emergency choices

An OSRM or Mapbox outage must never promote ordinary previews, public offers,
polling, or warming to Google. If a separately approved emergency configuration
uses Mapbox or Google:

- set a non-zero provider-wide allowance and, where appropriate, a narrower
  purpose allowance before enabling the provider;
- keep Google only in the validated final chain;
- verify the account-level quota outside Drupal, because the site ledger cannot
  see other applications using the same account;
- leave route-result caching off unless the operator has recorded the applicable
  provider rights; and
- return every allowance to 0 when the emergency window ends.

Returning all public previews to Google is an explicit cost-bearing decision,
not rollback automation.

## Data preservation

Rollback does not delete, rewrite, or reprice:

- Commerce orders, payments, or Checkout Attempts;
- Holds, Reservations, Resource Calendar Guard rows, or audit events;
- Webform submissions or Manual Review records;
- route provenance already stored with a quote; or
- routing usage and budget rows from earlier periods.

Do not prune Docker volumes. Delete a failed OSRM generation only after its exact
name is verified and no running container mounts it. Never treat the Nominatim,
source, Drupal, database, or Redis volumes as OSRM cleanup.

## Rollback verification

- Drupal cache rebuild succeeds.
- The effective provider policy matches the intended disabled or restored state.
- Mapbox and Google allowance consumption did not increase during rollback.
- The Routing Providers page still distinguishes local counters from vendor
  billing totals.
- Existing paid orders and confirmed Reservations remain unchanged.
- Dirty address typing still issues no route or offer request before commit.

Document whether rollback disabled routing or restored a known-good generation.
Do not claim a cost saving until equivalent billing observation periods exist.
