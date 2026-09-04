# Task 9 — Cut over legacy booking paths and prepare launch

You are Claude Code CLI executing Task 9 for the Drupal 10 Express Courier and
Commerce roadmap in `C:\Users\mo737\ppu`. Tasks 1–8 must be complete and locally
verified.

## Objective

Make the Commerce-driven Dispatch Planner the sole authority for new bookings,
retire conflicting public fixed-slot/Hold behavior without destroying history, and
produce an operational launch runbook.

## Target files

```text
modules/custom_webform_handlers/src/Reservation/
modules/custom_webform_handlers/src/Dispatch/
modules/custom_webform_handlers/src/Controller/
modules/custom_webform_handlers/src/Plugin/WebformHandler/AddressDistanceHandler.php
modules/custom_webform_handlers/js/reservation_slots.js
modules/custom_webform_handlers/js/dispatch_slots.js
modules/custom_webform_handlers/custom_webform_handlers.routing.yml
modules/custom_webform_handlers/custom_webform_handlers.services.yml
modules/custom_webform_handlers/custom_webform_handlers.libraries.yml
modules/custom_webform_handlers/custom_webform_handlers.install
modules/custom_webform_handlers/README.md
docs/operations/express-courier-launch.md
docs/operations/express-courier-rollback.md
```

## Required cutover

1. Inventory every route, handler callback, JS request, service, and schema path that
   can create, renew, release, or confirm old fixed-slot/public Holds.
2. Disable/remove those paths for new Commerce-driven submissions only after proving
   the new homepage/payment flow owns the active Webform. Do not drop legacy tables
   or delete historical rows.
3. Preserve read-only historical administration and add explicit labels for legacy
   fixed-slot records. If a legacy active claim cannot be converted to a proven
   Dispatch Window, keep the existing conservative `legacy_unplanned` Resource-day
   guard and require staff reconciliation.
4. Ensure there is one variable-duration capacity authority: Dispatch Planner plus
   DatabaseReservationStore plus Resource Calendar Guard. No Webform handler,
   Commerce subscriber, ECA model, controller, JS behavior, queue job, or legacy
   manager may independently write active capacity.
5. Search all public markup, `drupalSettings`, JSON responses, and JavaScript for
   Hold countdown/expiry data and timezone selectors. Remove them. Retain only the
   fixed New York time label and canonical UTC storage.
6. Verify all state-changing routes use POST/Form API, CSRF protection, narrow
   permissions, bounded input, no-store where applicable, and stable non-secret
   errors. Verify public schedule/tracker responses contain no PII or internal IDs.
7. Confirm all price/order snapshots include currency and rate version; no client
   total can reach Commerce as authority.
8. Review cache tags, payment-event replay, stale Slot selection, route-provider
   failure, Redis failure, worker outage, notification failure, Manual Review, and
   Replan behavior.
9. Write launch and rollback runbooks covering database snapshot, Composer deploy,
   DDEV/production Redis, update hooks, configuration import, cache rebuild, queue
   workers, payment webhook configuration, smoke checks, monitoring, and rollback
   without deleting paid orders or Reservations.
10. Update module documentation and the project context vocabulary only when the
    final implementation proves a term has changed.

## Final verification

Run PHP/JavaScript static checks only. Do not create or run any automated test
suite, including Unit, Kernel, PHPUnit, Selenium, or FunctionalJavascript.

Then run the deployment sequence against the local DDEV snapshot:

```bash
ddev composer validate --no-check-publish
ddev composer audit
ddev drush updb -y
ddev drush cim -y
ddev drush cr
ddev drush status
```

If the project does not manage the active site's configuration through import, do
not run `cim`; document and use the repository's actual config workflow instead.

Perform a manual acceptance pass covering:

- Express Courier Send, Receive, and Store Pickup;
- an over-30-lb Manual Review request and tracker;
- successful, failed, cancelled, and replayed payments;
- two-session contention for overlapping windows;
- immediate admin-board/public-availability update after payment;
- permissions for all three requested roles;
- Redis/worker outage fallback;
- no countdown and no timezone selector.

Review the complete diff without modifying unrelated work. Report remaining risks,
exact commands run, schema/config changes, and rollback point, then stop.
