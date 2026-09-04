# Task 7 — Add the Manual Review tracker and granular RBAC

You are Claude Code CLI executing Task 7 for the Drupal 10 Express Courier and
Commerce roadmap in `C:\Users\mo737\ppu`. Tasks 1–6 must be complete.

## Objective

Give ineligible/custom Dispatch Requests a safe customer-visible review lifecycle
and divide administrative access by responsibility. Manual Review never consumes
capacity. Only payment after approval can create a Reservation.

## Target files

```text
modules/custom_webform_handlers/src/Review/
modules/custom_webform_handlers/src/Controller/
modules/custom_webform_handlers/src/Form/
modules/custom_webform_handlers/src/Dispatch/
modules/custom_webform_handlers/config/install/
modules/custom_webform_handlers/config/schema/
modules/custom_webform_handlers/custom_webform_handlers.install
modules/custom_webform_handlers/custom_webform_handlers.permissions.yml
modules/custom_webform_handlers/custom_webform_handlers.routing.yml
modules/custom_webform_handlers/custom_webform_handlers.links.menu.yml
modules/custom_webform_handlers/custom_webform_handlers.services.yml
modules/custom_webform_handlers/templates/ and css/ as needed
```

## Required implementation

1. Add one authoritative Review Request model linked to the Webform submission and,
   when approved, to a Commerce order. Store its lifecycle as:

   ```text
   submitted -> under_review -> approved -> scheduled
   ```

   Also support terminal `declined`, `cancelled`, and `expired` outcomes. Enforce
   allowed transitions in one manager Interface, not independently in forms/ECA.
2. `approved` means staff prepared an offer/order; it does not consume capacity.
   `scheduled` is reached only when Task 6's paid Commerce flow confirms a
   Reservation.
3. Add an idempotent schema/update hook for Review Request state, references,
   timestamps, audit actor, and a lookup credential hash. Store no raw public token.
4. Provide a customer tracker through a cryptographically random signed link/code.
   The raw credential is shown/sent only to its customer. Responses are no-store and
   reveal only that request's safe status, service summary, staff-safe message, and
   approved payment action. Enumeration must not reveal whether another code exists.
5. Add staff forms for begin review, approve with authoritative quote, decline, and
   cancel. Every mutation is POST/Form API protected and audited.
6. Integrate existing Manual Review entries on the dispatch board with the Review
   Request manager rather than creating a second queue.
7. Define narrow permissions, then create/configure roles:

   - `Schedule Manager`: view board, manage blackouts, release/Replan, approve and
     schedule Review Requests; no payment administration unless separately granted.
   - `Dispatch Viewer`: read-only board and operational details needed for dispatch;
     no mutations and no financial details.
   - `Financial / Customer Info Viewer`: Commerce order/payment status and customer
     contact access; no schedule mutation.

8. Split existing broad `administer dispatch planner` and `manage dispatch schedule`
   usage where needed so route access reflects these roles. Treat customer contact,
   exact addresses, financial data, and schedule mutation as separate permissions.
9. Public availability and tracking pages must never expose another customer's
   identity, exact route, payment state details, internal IDs, or the driver's full
   schedule.

## ECA constraint

Do not make ECA the Review Request state machine. Task 8 may configure ECA to react
to manager-emitted events. This task's PHP manager is the authority and must work
with ECA disabled.

## Verification

- Do not create or run automated tests. PHP-lint touched files and inspect the
  legal/illegal transition, signed-link access, approval-without-capacity, and
  scheduled-only-after-paid paths directly.
- Run:

```bash
ddev drush updb -y
ddev drush cr
```

Manually log in as each new role and verify allowed/denied routes. Open a tracker in
an anonymous session, verify no-cache headers, then try an invalid code and confirm
the response does not disclose existence. Stop before queue/ranking work.
