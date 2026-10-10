# Task 23B — Build the route-first Express Courier flow

You are Claude Code CLI working after Task 23A and explicit approval of its
field/data map.

## Objective

Implement the smallest useful public booking flow without replacing server
authority:

1. addresses, service, and required Courier mode/item fact;
2. server quote plus earliest safe pickup, with optional additional times; and
3. contact plus the existing Commerce payment and confirmation flow.

The interface should feel like a simple local courier request, not an internal
dispatch form. Keep Webform, quote, scheduling, review, and Commerce contracts
intact wherever possible.

## Implementation rules

- Reuse Drupal Webform conditional logic, existing handlers, Commerce checkout
  panes/flow, and the current dispatch-slot endpoint. Do not build a second
  booking application or introduce a frontend framework.
- Express Courier is visually first and may be preselected only when eligible.
- Step 1 sends no route request while a customer types. Route calculation remains
  subject to Task 21A's explicit two-address commit and cache rules.
- Step 2 shows the earliest feasible server offer first. Additional dates/times
  are progressive disclosure, not a large default calendar.
- A route change invalidates quote and slot offer. Date, slot, and package/service
  changes reuse the current route fact and do not invoke a distance provider.
- Preserve New York local display, UTC storage, private Holds, no public Hold
  countdown, and synchronous reservation confirmation after payment success.
- Heavy, oversized, multi-person, or unmapped requests route to the existing
  Manual Review path rather than exposing every moving-service field in the fast
  Courier flow.

## Target files

Task 23A determines the precise list. Expected candidates are limited to the
active module's Webform config, handler settings, existing checkout pane/flow,
and possibly:

```text
js/address_autocomplete.js
js/distance_calculator.js
js/dispatch_slots.js
custom_webform_handlers.libraries.yml
```

Do not modify Task 21A routing-mode code, DDEV, Composer, credentials, external
providers, or unrelated service forms without a separately approved task.

## Direct verification

Use non-billing direct verification. Demonstrate that:

1. a Courier customer reaches a quote from the minimum Step 1 facts;
2. incomplete addresses remain invalid without route-provider calls;
3. committed addresses use the current Task 21A development route fact only once;
4. date, slot, and package changes do not invoke a distance provider;
5. the earliest feasible offer is prominent and unavailable times are not
   selectable;
6. advanced details appear only for the relevant delivery mode/service;
7. Manual Review has no self-bookable Slot; and
8. contact/payment retains the existing server-authoritative confirmation path.

Follow root policy for Graphify, GitNexus, DBHub where applicable, YAML/source
inspection, lint, `rtk proxy ddev exec vendor/bin/drush cr` after module edits,
relevant read-only Drush checks, and `rtk git diff --check`. Do not create a
browser-automation suite, call live routing, process payments, or commit.

Report exact fields moved, hidden, or retained; preserved server consumers;
provider-call evidence; verification actually run; browser acceptance status; and
blockers.

