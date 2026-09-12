# Task 20H — Verify the cost policy and prepare controlled activation

You are Claude Code CLI executing Task 20H in `C:\Users\mo737\ppu` after 20G.
Read root `CLAUDE.md`, RTK, applicable rules, the routing pack README, and all Task
20 evidence. OSRM and the selected geocoder must be operational before activation.

## Objective

Deliver bounded acceptance evidence, operator configuration, usage visibility, and
a rollback procedure. Report measured savings only when comparable observations
exist. Preparing this prompt pack does not itself authorize production changes.

## Target files

```text
modules/custom_webform_handlers/docs/operations/routing-cost-rollout.md
modules/custom_webform_handlers/docs/operations/routing-cost-rollback.md
modules/custom_webform_handlers/docs/releases/task20-routing-acceptance.md
modules/custom_webform_handlers/README.md
modules/custom_webform_handlers/src/Form/RoutingProviderSettingsForm.php
modules/custom_webform_handlers/config/install/custom_webform_handlers.settings.yml
modules/custom_webform_handlers/config/schema/custom_webform_handlers.schema.yml
```

## Required implementation

1. Record effective provider policy, credential-presence status, geocoder rights,
   route-cache rights, OSRM data version/coverage, service health, account/site
   allowances, and installed module config schema. List blocked prerequisites.
2. Add a small operator usage summary through the existing settings/admin area:
   provider/purpose attempts, cache hits, fallback reasons, circuit state, and site
   budget remaining. Distinguish local counters from vendor billing totals. Keep
   it permissioned and free of route/customer/credential payloads.
3. Prepare a bounded manual acceptance matrix with expected external call counts:

   | Scenario | Expected |
   | --- | --- |
   | Raw address typing, neither address committed | Zero route/offer calls |
   | Selection followed by blur, unchanged pair | At most one preview request |
   | Repeated valid route cache hit | Zero upstream routing calls |
   | OSRM healthy, ordinary quote | OSRM only; no Mapbox/Google |
   | OSRM unavailable, Mapbox permitted | One bounded Mapbox attempt; no Google |
   | Both preview providers unavailable | Recoverable unavailable; no Google |
   | Required-traffic offer, Mapbox unavailable | No fabricated Slot; no Google |
   | Eligible final traffic check, Mapbox unavailable | Bounded Google attempt if budget permits |
   | Public caller requests final/provider override | Override refused; no Google |
   | Budget exhausted or durable accounting unavailable | Paid call refused |
   | Two distinct addresses in one ZIP / reversed route | Distinct authoritative cache identities |
   | Dirty input or slow stale response | Quote/Slot invalidated; stale result ignored |
   | Cache outage or concurrent identical requests | Bounded work; financial limit preserved |
   | Route map displayed | No extra Google routing call |
   | Final provider changes price/time | Explicit revised quote or Slot refusal before charge |

4. Record request budgets BEFORE any authorized live acceptance. Use public sample
   addresses and sandbox payments; keep deliberate faults local. Do not launch an
   automated suite or stop production providers to exercise fallback.
5. Prepare activation by explicit provider/config settings after acceptance.
   Preserve existing site overrides; `config/install` alone does not update an
   existing site. Document the narrow application of approved settings, then
   `rtk proxy ddev exec vendor/bin/drush cr`. Do not bulk import configuration or
   execute unrelated pending updates.
6. Rollback retains 20B request suppression and paid-call limits. Prefer OSRM-only
   or temporary quote unavailability if fallback is unsafe. Returning all previews
   to Google is an explicit operator choice with a cost cap, never an automatic
   response to OSRM/Mapbox failure. Do not alter existing orders/reservations.
7. Compare equivalent observation periods: Routes versus Places/map SKUs,
   provider calls per committed address pair and completed booking, cache hits,
   fallback rate, latency, and scheduling refusals. Account for OSRM hosting and
   geocoder costs. No assertion of a percentage saving without data.

## Verification

Only the bounded, explicitly authorized manual session and relevant read-only
Drush/metadata inspection. No automated tests, long-running loops, broad scans,
or real payment capture. Rebuild cache only if Drupal source/config was changed.

Completion: each matrix row is passed with evidence or explicitly pending; the
operating runbook and rollback can be followed without guessing; no secrets/data
artifacts entered Git. Report production activation separately as performed or
pending. Stop at the agreed deployment boundary.
