# Task 1 — Baseline inventory and architecture checkpoint

You are Claude Code CLI executing Task 1 for the Drupal 10 Express Courier and
Commerce roadmap in `C:\Users\mo737\ppu`.

## Objective

Produce a factual checkpoint of the current implementation before any product code
is changed. Identify which parts of the existing Dispatch Planner are authoritative,
which legacy fixed-slot paths remain active, which native execution capabilities can
accelerate later tasks, which Drupal plugins can replace proposed custom work, and
which prerequisites are missing.

This task is read-only except for creating or updating:

```text
docs/architecture/express-courier-current-state.md
```

## Non-negotiable rules

- DDEV docroot is `.`, so inspect only `modules/custom_webform_handlers/` as the
  live custom module. Treat similarly named directories as duplicate artifacts.
- Preserve every existing uncommitted change. Do not format, normalize, revert, or
  repair product code in this task.
- Do not install packages, start database updates, export configuration, enable
  modules, or run a test suite.
- Do not print API-key values, environment secrets, Hold tokens, or database rows
  containing customer data.

## Step 1 — Discover native execution capabilities

Before inspecting architecture, use Claude Code's native discovery mechanisms to
inventory what this session actually exposes:

- skills and their trigger scopes;
- installed plugins/extensions and MCP/tool integrations;
- slash commands and Git integrations;
- filesystem search/edit facilities;
- DDEV, Composer, Drush, Git, PHP, and other relevant CLI programs;
- available sub-agent/task facilities.

Run these version-safe host probes when supported, with a bounded timeout for any
connector probe:

```bash
claude --version
claude --help
claude plugin list
claude mcp list
```

Inspect project/user skill, command, agent, plugin, MCP, and extension configuration
only where it exists and can be read without exposing secrets. Record an unavailable
command and its exact probe result; do not install a capability during this task.

Use the relevant capabilities during this task rather than merely listing them. At
minimum:

1. Use native code search to locate module definitions, plugin annotations/
   attributes, routes, services, handlers, and schema.
2. Use Git status/diff facilities to separate existing work from Task 1's report.
3. Use DDEV/Drush/Composer for the Drupal and dependency inventory.
4. If an Agent facility is available, delegate exactly one bounded read-only audit
   of Drupal plugin/contrib reuse opportunities in the live module. Require plugin
   IDs or class/source paths plus reuse recommendations. The primary executor must
   verify every incorporated finding directly before citing it. The sub-agent must
   not edit files or overlap the primary inventory. If the facility is unavailable,
   record the exact failed discovery/probe; do not simulate a sub-agent.

Add a `Native Capability Ledger` to the checkpoint with these columns:

```text
Category | Capability | Available | Evidence/probe | Used for |
Outcome / task-specific reason not used
```

Include at least one row for skills, plugins/MCP, slash commands/extensions,
search/edit tools, Git integration, CLI tools, and sub-agents. Evaluation is
exhaustive across those categories. Activation is based on task relevance; do not
invoke an unrelated capability merely to claim usage. “Not relevant” without a
task-specific reason does not satisfy the ledger.

## Step 2 — Inventory Drupal plugin and contrib reuse

Before proposing any future custom class, inspect Drupal core, enabled contrib,
Composer-available contrib, and the live custom module for reusable architecture.
Specifically inventory:

- Webform Handlers, elements, wizard behavior, access, and submission hooks;
- Commerce order types, order-item types, Checkout Flows/panes, payment gateways,
  payment events, and state-machine plugins;
- Address fields and Geocoder providers/widgets;
- Advanced Queue backends, queues, and JobType plugins;
- ECA events, conditions, actions, queue integrations, and Webform/Commerce support;
- Drupal cache, lock, state, workflow, mail, permissions, Views, and plugin managers;
- existing quote, route, planner, booking, reservation-store, board, Replan,
  notification, and Webform-handler Interfaces in `custom_webform_handlers`.

For installed extensions, inspect active configuration and concrete plugin
definitions—not just module names. Include Webform handler/element IDs, Commerce
Checkout Flow/pane/payment/order plugins and enabled sub-modules, Geocoder provider
IDs, Advanced Queue backend/queue/JobType IDs, and ECA Event/Condition/Action IDs.
Leave packages that are not installed as `unresolved`; do not infer their plugin
surface from memory.

Add a `Drupal Reuse Decision Matrix` with these columns:

```text
Required behavior | Core/contrib candidate | Existing custom candidate |
Plugin/submodule/handler ID | Enabled/available version | Existing duplicate |
Disposition (reuse/configure/wrap/retain/defer) | Selected owner/seam |
Smallest required glue | Evidence
```

The matrix must cover at least address capture, geocoding/autocomplete, quote input,
Commerce checkout/payment, Slot offering, capacity locking, Manual Review workflow,
notifications, background jobs, RBAC/admin Views, and popularity ranking. Mark a
decision `unresolved` when the required contrib module is not installed; do not
invent its plugin surface from memory.

## Step 3 — Inspect the current authority and environment

Record all of the following in the checkpoint document:

1. DDEV, Drupal, PHP, Composer, Drush, Git, and Claude Code versions.
2. DDEV services and whether Redis is present.
3. Drupal core constraint consistency across `composer.json` and `composer.lock`.
4. Installed/versioned status of Webform, Commerce, Address, Geocoder, Advanced
   Queue, Redis, and ECA.
5. Boolean-only readiness of browser Maps and server Routes keys.
6. Current schema/update-hook status and the existence of the dispatch reservation,
   guard, blackout, status, and notification tables. Report table names only.
7. Existing external seams: quote calculator, route provider, planner, booking,
   reservation store, board reader, staff operations, and notifier.
8. Every public route that offers, holds, renews, releases, or confirms capacity.
9. Where Webform submission lifecycle currently confirms a Reservation.
10. Existing Courier modes, eligibility limits, duration policies, traffic settings,
    and price support.
11. Existing permissions and which requested roles are still missing.
12. Exact overlap between the legacy fixed-slot reservation implementation and the
    variable-duration Dispatch Planner. Identify duplicate authority risks.

## Completion criteria

The checkpoint is complete only when it contains all of these sections:

1. Environment and dependency inventory.
2. Native Capability Ledger with every required capability category accounted for.
3. Drupal Reuse Decision Matrix covering every required behavior listed above.
4. Current authority map naming the single writer/decision owner for quote, route,
   eligibility, Slot offer, capacity, payment, Review Request, and notification.
5. Legacy-versus-Dispatch overlap and duplicate-authority risks.
6. Task 2 prerequisite checklist, unresolved decisions, and explicit blockers.

Every custom responsibility proposed for Tasks 2–9 must have a reuse decision or be
marked unresolved with the exact evidence still needed. Do not propose a second
planner or a new custom module.

Task 1 fails if the Capability Ledger only lists capabilities without showing their
use/outcome, or if any proposed custom responsibility lacks an evidence-backed reuse
disposition.

## Verification commands

Run these exact read-only commands when supported; document the exact substitute and
reason otherwise:

```bash
ddev describe
ddev drush status
ddev composer validate --no-check-publish
ddev drush pm:list --status=enabled
ddev drush plugin:list
git status --short
```

If the installed Drush version does not expose `plugin:list`, use Drupal service/
plugin-manager inspection and record the substitution. Do not install a command just
to satisfy this inventory.

Use Drupal bootstrap checks that return only `configured` or `missing` for API keys.
Do not reveal values.

Before stopping:

1. Run `git diff --check`.
2. Compare final `git status --short --untracked-files=all` with the initial snapshot
   and confirm Task 1 changed only the allowed checkpoint document.
3. Confirm every required report heading, Capability Ledger category, reuse-matrix
   behavior, and completion section exists.
4. Review the report for secrets, token material, and customer data and confirm none
   appears.

Then stop. Do not run `ddev drush cr`; no Drupal state changed.
