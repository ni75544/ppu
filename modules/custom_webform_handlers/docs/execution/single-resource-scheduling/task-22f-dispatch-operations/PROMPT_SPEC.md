# Task 22F — Give staff a simple one-driver operating view

You are Claude Code CLI working only after capacity, pricing, and confirmation
are authoritative.

## Objective

Improve dispatch operations without exposing customer data or turning the public
form into an internal dispatch console. Staff need one chronological driver
timeline and a few safe actions.

## Required admin experience

For a selected local New York day and Resource, show:

```text
available/depot → repositioning → pickup window → delivery/buffer
→ repositioning → next job
```

Reuse existing role checks. Permit explicit blackout/withdrawal, Resource
unavailability, staff-proposed replan, authorized reservation release,
operational-delay status, and provenance needed to explain a plan. Drag-and-drop
is never capacity authority: every replan returns through the existing planner
and calendar guard.

## Expected seams and verification

Confirm existing controllers, board reader, operations, status, blackout, and
replan forms before changing code. Do not introduce a frontend framework, public
schedule detail, map dependency, or address leakage. Verify chronological
ordering, permissions, New York-local display, replan refusal for overlap, and
redaction by role. Run only allowed source/YAML/lint/cache/Drush/diff checks. Do
not create real reservations, notify customers, run tests, call live providers,
or commit.

