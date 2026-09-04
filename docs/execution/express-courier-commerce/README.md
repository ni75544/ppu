# Express Courier and Commerce Execution Pack

This is the canonical architecture and execution sequence for moving the existing
Drupal 10 dispatch implementation to the Express Courier homepage and Commerce
checkout defined in the current PRD. Each task directory contains a standalone
`PROMPT_SPEC.md` that can be sent directly to Claude Code CLI.

## Revised architect directive

Act as the implementation architect and terminal executor for the existing Drupal
10 logistics platform. Reuse and deepen the code already present in
`modules/custom_webform_handlers`; do not build a parallel scheduler, quote engine,
reservation store, or duplicate Drupal module.

The canonical project is `C:\Users\mo737\ppu`. DDEV's docroot is `.`, therefore the
only live custom-module path is:

```text
modules/custom_webform_handlers/
```

Do not edit the duplicate copies under `web/modules/`, `web/custom_webform_handlers/`,
`custom_webform_handlers/`, or archived ZIP/export files.

Preserve unrelated uncommitted work. Inspect before changing a file, make the
smallest coherent change for the current task, and stop if a prerequisite from an
earlier task is absent.

### Mandatory capability and reuse assessment

At the start of every task, perform **capability discovery** before choosing an
implementation. Inventory the native skills, plugins, MCP/tool integrations, slash
commands, extensions, CLI programs, Git facilities, and sub-agents exposed to that
Claude Code session. Evaluation is mandatory; activation is relevance-gated. Use
every capability that materially improves the task, and record why any apparently
relevant capability was unavailable or intentionally not activated.

Keep a compact Capability Ledger in the task report with: capability, availability,
selected use, and outcome. At minimum, use the environment's native code search,
file-editing, Git-diff, and relevant Drupal/DDEV/Composer/Drush capabilities. When a
bounded investigation or independent verification can run safely in parallel, use
a sub-agent with a narrow read-only assignment and integrate its evidence before
editing. A sub-agent must not duplicate the primary edit or mutate overlapping
files.

Apply **contrib-first discovery** before writing custom Drupal code. Inspect enabled
and available Drupal core/contrib modules, plugin managers, sub-modules, handlers,
checkout flows, queue backends/job types, ECA events/conditions/actions, services,
hooks, and existing custom Interfaces. For every proposed custom responsibility,
record a Reuse Decision: required behavior, core/contrib candidate, existing custom
candidate, selected plugin/seam, and the smallest glue still required. Custom code
is justified only when no installed/approved Drupal plugin owns the invariant or
when the existing Dispatch Planner must remain the authority.

### Product invariants

- Express Courier is pinned first and supports Send, Receive, and Store Pickup.
- Self-booking is limited by configured weight, route, handling, and one-Resource
  capacity. Ineligible work becomes Manual Review and consumes no capacity.
- A Slot is a pickup-arrival option. The server derives its variable Dispatch
  Window, repositioning, buffers, and traffic-aware route.
- Availability is advisory. Capacity contention is resolved only inside the
  existing Resource Calendar Guard transaction.
- Public pages have no timezone selectors and no Hold countdowns. Display New York
  local time and store canonical instants in UTC.
- Selecting a Slot does not reserve it. Starting payment creates a private Checkout
  Attempt and internal Hold. Only successful payment creates a Reservation.
- Payment-driven confirmation is synchronous and idempotent. It is never delegated
  to cron, Advanced Queue, Redis, ECA, JavaScript, or a payment-return page.
- Public schedule responses contain no customer data, route neighbours, internal
  windows, tokens, hashes, Hold expiry, order identifiers, or Reservation IDs.
- Queues and ECA react after authoritative state changes; they never decide price,
  eligibility, payment success, or capacity.

### Engineering policy changes from the draft

1. `ddev drush cr` is not sufficient verification for Composer, schema, payment,
   or concurrency changes. Each task names the smallest relevant verification.
2. Do not generate or execute automated test suites, including PHPUnit, Unit,
   Kernel, FunctionalJavascript, Selenium, or broad end-to-end coverage. Preserve
   existing tests untouched. Resolve and verify through direct code inspection,
   PHP/JavaScript syntax checks, Drush commands, safe metadata inspection, and
   focused manual checks.
3. Database changes require an idempotent update hook and `ddev drush updb -y` in
   the task that introduces them. Other tasks must not run unrelated updates.
4. Advanced Queue does not provide a configuration-only Redis backend. Use its
   supported SQL backend for durable job lifecycle at launch; use Redis for Drupal
   cache/lock and route-cache workloads. A custom Advanced Queue Redis backend is a
   separate infrastructure project, not incidental glue code.
5. Because this project remains on Drupal 10.6, use the Drupal-10-compatible ECA
   2.1 release line. ECA 3.x is not a Drupal 10 target.

## Verified environment snapshot — 2026-08-22

| Capability | Result |
|---|---|
| DDEV | Available, v1.25.3 |
| Web container | Running, Drupal 10, nginx-fpm |
| Drupal | 10.6.15, bootstrap successful |
| PHP | 8.4.22 inside DDEV |
| Composer | 2.10.2 inside DDEV |
| Drush | 13.6.2 |
| Git | 2.55.0 |
| Claude Code CLI | 2.1.240 |
| Database | MariaDB 11.8, connected |
| Module/composer write access | Available |
| Google Routes server key | Configured |
| Google browser Maps key | Configured |
| Redis DDEV service | Missing; Task 2 provisions it |
| Commerce/Geocoder/Advanced Queue/ECA | Not installed; Task 2 installs them |

The working tree is heavily modified and contains an existing variable-duration
planner, transactional store, Resource Calendar Guard, public Hold workflow,
administrative board, Replan operations, traffic-aware route support, and queued
notifications. Every executor must treat those files as prior work to migrate and
reuse.

## Task order

| Task | Outcome | Depends on |
|---|---|---|
| 1 | Baseline inventory and architecture checkpoint | None |
| 2 | Coherent Drupal 10 contrib stack and DDEV Redis | 1 |
| 3 | Commerce order model and Checkout Attempt seam | 2 |
| 4 | Express Courier fields, eligibility, duration, and price | 3 |
| 5 | Homepage Steps 1–2: route, service, quote, and Slots | 4 |
| 6 | Homepage Steps 3–4: contact, payment, atomic confirmation | 5 |
| 7 | Manual Review tracker and granular RBAC | 6 |
| 8 | Advanced Queue jobs, Redis integration, ECA reactions, ranking | 7 |
| 9 | Legacy cutover, security review, and launch runbook | 8 |

Execute one prompt at a time. Do not combine tasks, because later prompts assume
the earlier task's schema, configuration, and Interfaces are already present.
