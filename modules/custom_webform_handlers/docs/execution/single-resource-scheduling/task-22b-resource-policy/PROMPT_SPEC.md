# Task 22B — Configure one-driver operating policy

You are Claude Code CLI working after Task 22A has identified approved
configuration-only gaps. Do not start if Task 22A is absent or requires planner
or storage changes first.

## Objective

Make the initial operating policy explicit for exactly one active Resource. Do
not create a fleet-management system or alter confirmed Reservations.

## Required policy

Configure one Resource, such as `driver-01-vehicle-01`, with capacity `1`,
`America/New_York` timezone, depot address, working days/hours, blackouts,
booking horizon, pickup grid, optional return-to-base rule, and explicit
pickup-handling, delivery-handling, operational-buffer, and rest policies by
service class. Start with a 30-minute grid only if existing business policy does
not already specify a different cadence.

Do not silently supply values for service facts the form cannot provide. Those
cases must route to Manual Review.

## Expected files

Confirm actual files from 22A. Likely candidates are:

```text
config/install/custom_webform_handlers.dispatch.yml
config/schema/custom_webform_handlers.schema.yml
src/Dispatch/ResourceProfileFactory.php
src/Dispatch/Policy/ConfigServiceDurationPolicy.php
src/Form/DispatchPlannerSettingsForm.php
```

Do not modify Task 21A routing code, DDEV, Composer, credentials, or unrelated
Webforms.

## Constraints and verification

Keep policy in typed Drupal configuration, not browser state or PHP literals.
Read active configuration before proposing any update path; `config/install` does
not update an existing site. Preserve existing overrides, historical
Reservations, orders, price snapshots, and Holds. A Resource with no working
hours must offer no Slots. Manual Review consumes no capacity until staff creates
a scheduled Reservation.

Use Graphify/GitNexus for source impact, direct YAML inspection, and DBHub
read-only discovery when schema facts are involved. After approved module
source/config edits run `rtk proxy ddev exec vendor/bin/drush cr`, YAML validation,
PHP lint for changed files, non-mutating Drush config checks, and `rtk git diff
--check`. Do not run tests, browser automation, live routing, payment, or
reservation side effects. Report effective old/new values without secrets, then
stop without committing.

