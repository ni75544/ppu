# Task 20D — Resolve committed addresses and build the coordinate cache

You are Claude Code CLI executing Task 20D in `C:\Users\mo737\ppu` after 20C.
Read root `CLAUDE.md`, RTK, applicable rules, the routing pack README, and Task
20A's provider-policy record. The selected geocoder's usage rights must be known.

## Objective

Supply authoritative coordinates to OSRM/Mapbox through a shared Drupal Geocoder
adapter, with exact address identity and permitted reuse across fallback attempts.

## Target files

```text
modules/custom_webform_handlers/src/Distance/AddressCoordinateResolver.php
modules/custom_webform_handlers/src/Distance/ResolvedLocation.php
modules/custom_webform_handlers/src/Distance/CoordinateCache.php
modules/custom_webform_handlers/custom_webform_handlers.services.yml
modules/custom_webform_handlers/config/install/custom_webform_handlers.settings.yml
modules/custom_webform_handlers/config/schema/custom_webform_handlers.schema.yml
modules/custom_webform_handlers/docs/architecture/task20-coordinate-policy.md
```

## Required implementation

1. Inspect installed Geocoder plugins and their configured dependencies. Wrap the
   approved provider instead of implementing another geocoding HTTP client.
   Missing Composer packages or infrastructure are explicit prerequisites; do not
   make unrelated Composer/DDEV edits within this module task.
2. Return an immutable `ResolvedLocation` with coordinates, canonical address
   identity, country/postal context, precision, provider/source ID, original fetch
   time, expiry, and storage/use permissions. Validate finite longitude/latitude
   bounds, supported geography, address precision, and ambiguous candidate results.
3. Build layer 1 keys from normalized FULL address + country/postal context +
   geocoder/version. A postal code alone never identifies an endpoint. Preserve
   house numbers, directional suffixes, and distinct entrances. Do not globally
   strip address information as a string optimization.
4. Resolve each unchanged endpoint at most once per request. Use request-local
   memoization and a dedicated Drupal cache bin where persistence is permitted.
   Cache temporary-provider results only to the extent their terms permit;
   disable persistent writes when rights are unresolved. Bound negative entries
   for invalid/ambiguous addresses separately from provider outages.
5. Treat browser coordinates/place IDs as hints. Use a server-validated resolver
   result or a server-issued reference bound to the normalized submitted address;
   range checks alone do not prevent submitting a nearer false coordinate.
6. Resolve provenance explicitly. Do not blindly convert Google autocomplete
   output into a permanent shared OSRM cache. Use an independently approved
   coordinate source, or the permitted provenance/retention mode recorded in 20A.
   Keep existing autocomplete presentation separate from route-data authority.
7. Handle one endpoint missing, same postal code, different countries, PO boxes,
   apartment/building ambiguity, unrouteable access, and coverage borders. Return
   a correction/unavailable result, never a fabricated zero-distance route.
8. Use Drupal cache services with DI and the shared injected clock. If the cache
   backend fails, respect geocoding/routing budgets; do not turn a cache outage
   into unrestricted paid geocoding. Hash keys do not anonymize stored payloads.

## Verification

Use read-only plugin/container inspection and a manual acceptance matrix. No tests
or broad API probes. Document examples for same ZIP/different streets, reverse
direction, unverified browser coordinates, expired provenance, and duplicate
fallback resolution. Any permitted live geocoder check must have a bounded request
count and no real customer data or keys in output.

```bash
rtk proxy ddev exec vendor/bin/drush cr
```

Completion: the chosen provider and permissions are recorded, unsupported input
is refused, and cache identity cannot equate different full addresses. If the
geocoder is not provisioned, mark runtime checks blocked and do not activate it.
