# Task 25A - Configuration-only bridge compatibility decision

You are Claude Code CLI working in `C:\Users\mo737\ppu`.
Read [shared rules](../RULES.md) and applicable repository instructions fully.

## Objective and entry gate

Find whether maintained contrib, starting with Commerce Webform Order, can
connect the existing trusted Webform quote to existing Commerce checkout using
supported configuration alone. Preserve scheduling, driver locks, Commerce,
pricing, and historical data. Do not create a new custom integration layer.

This is the next task. Application/configuration/database inspection is
read-only; only the documentation deliverables below may be written. If a
matching Task 25A decision already exists, inspect and refresh its evidence;
do not assume completion from a chat summary or automatically start Task 25B.

## Target files

Allowed documentation outputs in this pack:

- `task-25a-bridge-compatibility/DECISION.md` - append the dated decision/evidence.
- `README.md` - update only the task status/evidence link after execution.

No application PHP/JS/YAML, Composer, schema, active configuration, or data edits.
No installs, enablement, migrations, submissions, fixtures, payments, browser
probes, paid route calls, cache rebuilds, container changes, or test suites.

## Step 1 - Establish the bounded baseline

1. Read `AGENTS.md` if present, `CLAUDE.md`, RTK instructions, `CONTEXT.md`, and
   applicable skills. Inspect Git status and relevant existing diffs.
2. Use the mandated evidence systems within read-only scope. Record tool failures,
   coverage limits, stale indexes, and the authority for any fallback.
3. Identify the exact site/URI/database, module path, package versions, schema
   level, active handler, and active checkout configuration. Do not start or repair
   services to get an answer; record a blocker if safe inspection is unavailable.
4. Determine whether QA and default share code, Composer/vendor, uploads, caches,
   queues, or workers. A separate database alone is not installation isolation.
5. Earlier audit observations (default schema 10046, QA 10049, pending attempts,
   and non-cart draft orders) are historical leads, not current values. Reverify
   only information necessary for this decision; do not repeat the full audit.

Completion: target identity, current evidence provenance, dirty-work baseline,
and isolation limitations are recorded without runtime changes.

## Step 2 - Trace the mandatory existing handoff

Inspect these paths under `modules/custom_webform_handlers/` and their callers:

```text
src/Plugin/WebformHandler/AddressDistanceHandler.php
src/Checkout/DispatchCheckoutStarter.php
src/Checkout/CheckoutAttemptStore.php
src/Commerce/DispatchCheckoutManager.php
src/Commerce/DispatchOrderFactory.php
src/Plugin/Commerce/CheckoutFlow/DispatchCheckoutFlow.php
src/Plugin/Commerce/CheckoutPane/DispatchPaymentProcess.php
custom_webform_handlers.services.yml
config/install/commerce_checkout.commerce_checkout_flow.dispatch.yml
```

Compare exported definitions with active configuration. Inspect scheduling
interfaces only to establish their current contract, not to redesign them.

Trace the actual ordering of:

```text
saved submission -> trusted accepted quote -> checkout entry point
-> capacity claim -> Commerce order/item -> CheckoutAttempt
-> private Hold receipt -> Commerce checkout/payment -> capacity confirmation
```

For every transition, name class/method and current source line, input,
record/receipt produced, next consumer, and whether it is mandatory. Document
where the database transaction and session publication occur; do not invoke them.

Completion: all prerequisites of payment and final capacity confirmation are
identified, including dependencies beyond merely possessing an order ID.

## Step 3 - Inspect the candidate release without installing

Use the official project, compatible stable release metadata, and its exact
source. Record version and source URL. View source remotely or inspect archive
bytes in memory only; do not download/extract files to disk, execute package code,
or change the application's dependencies. If those read-only methods are
unavailable, record the source-evidence gate as blocked.

Primary starting point: <https://www.drupal.org/project/commerce_webform_order>.

Determine:

1. Drupal, Commerce, Webform, PHP, and additional dependency compatibility.
2. Potential locked-package changes and installation/schema/config side effects.
3. Existing `dispatch` order and `dispatch_service` non-product item support.
4. The exact price source, currency override, and shipment-field mappings.
5. Order/item creation or reuse timing, handler order, and checkout redirect.
6. Cart combining, cart finalization/emptying, and same-customer multi-job effects.
7. Submission storage, duplicate submission, retry, and upload/reference behavior.
8. Whether stock configuration preserves every contract in Step 2, particularly
   CheckoutAttempt, private Hold receipt, and the existing payment pane.

Name real settings and documented mechanisms. A PHP hook, decorator, subscriber,
script, or custom ECA action is not a configuration-only solution. Do not confuse
package installation compatibility with application-contract compatibility.

Completion: a configuration-to-contract matrix cites the candidate and current
source for each supported, unsupported, or unverified transition.

## Step 4 - Enforce the price and single-writer gate

The proposed path must use the existing server-validated accepted quote, not an
authoritative browser-supplied amount. Identify how amount/currency, job facts,
and accepted identity are preserved and why another route call is unnecessary.

There must be exactly one order-creation path. Reject a proposal that creates an
independent order bypassing the manager, fabricates attempts/receipts afterward,
or disables required checks. Do not disable an entire existing Webform handler
if it still owns quote validation, scheduling integration, or price integrity.

If any needed transition requires changing protected code/configuration or adding
custom glue, return verdict B. Do not install the module to force progress.

## Step 5 - Define the protected data and future change manifest

Using approved bounded read-only queries, identify existing submissions/uploads,
orders/items/customers, attempts, snapshots, Reservations, and review references.
Record identities and privacy-safe value/relationship comparisons, not only counts.
No customer details or raw credentials belong in evidence documents.

Describe separately:

- What would remain untouched in a future setup.
- Exact proposed package versions, config entities, fields, and handler settings.
- Root-file or isolated-environment exceptions needing human approval.
- Any schema/environment blocker requiring a separate task, not an automatic fix.
- Backup, restoration, and shared-codebase considerations for Task 25B.

Do not clean stale attempts, convert draft orders, rewrite prices/references,
purge submissions, or release Reservations. Do not make a backup/clone merely to
inspect it when that would exceed this task's read-only scope.

## Step 6 - Decide and update the roadmap

Return exactly one verdict:

- **A - CONFIGURATION-ONLY CONNECTION SUPPORTED:** exact settings and execution
  sequence preserve trusted price, one order writer, attempts/receipts, payment,
  and driver locks according to complete source/configuration evidence. Include
  a bounded QA change proposal, not an installation. Mark dynamic runtime proof
  as pending for 25C; verdict A is not end-to-end acceptance.
- **B - CUSTOM INTEGRATION REQUIRED / OUTSIDE SCOPE:** identify the first
  unsupported transition and required forbidden change. Leave runtime untouched.
- **C - INSUFFICIENT EVIDENCE:** list the missing proof and smallest authorized
  action that could obtain it. Do not invent compatibility.

Update README status, retaining prior history. Verdict A permits requesting
approval for 25B; it does not grant it. B/C keep 25B-25D blocked. Schema repair,
legacy cleanup, gateway setup, and form redesign stay separate tasks.

## Verification and closeout

- Check cited paths/methods and local Markdown links.
- Run scoped `git diff --check`; include new documents in a separate whitespace
  check without staging user files. Compare final status with the starting state.
- Record actual commands/exit codes and untouched application/config boundaries.
- No `drush cr` or graph refresh for Markdown-only changes.

Report: verdict; current handoff; exact supported connection or first gap;
protected-data findings; files changed; evidence systems and limits; commands;
pending proof; next approval required. State that no Task 25B action was executed.
Stop after the report. Do not commit or proceed to the next task.
