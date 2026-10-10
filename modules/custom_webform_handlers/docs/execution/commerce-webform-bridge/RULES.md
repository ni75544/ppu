# Task 25 shared execution rules

Read this file fully before the selected `PROMPT_SPEC.md`. Work in
`C:/Users/mo737/ppu` unless the user explicitly approves a named isolated copy.

## Authority and sequence

1. Read applicable `AGENTS.md`, `CLAUDE.md`, referenced RTK instructions, and
   `CONTEXT.md`. The actual custom module is `modules/custom_webform_handlers/`;
   do not edit the partial duplicate at the repository root.
2. Inspect Git status/diff. Preserve existing and concurrent work. No commit,
   stash, reset, broad formatting, or discard is authorized by this pack.
3. Execute only the selected task. 25A permits read-only inspection and its
   documentation. 25B-25D require their stated prerequisites and separate,
   explicit human approval; these documents do not grant that approval.
4. Keep documentation in this execution pack. Root Composer files, an isolated
   checkout/environment, private backups, and active configuration require exact
   target exceptions in the later task's approval. No silent scope expansion.
5. If a task identifier or evidence file already exists, read it and append a
   dated result for the matching task. Preserve unrelated tasks and prior evidence.

## Protected application contracts

- Leave the scheduling engine, resource calendars, driver/day guard, Hold and
  Reservation logic, concurrency checks, admission enforcement, and locking
  implementation/configuration unchanged. Normal approved synthetic QA operations
  may exercise them; that is not permission to rewrite them.
- Preserve the existing Commerce order/item types, checkout/payment implementation,
  workflow, customer association, and access protection. No core/contrib patches.
- Preserve server-trusted accepted quotes, immutable pricing evidence, routing
  controls, and the accepted development-fixed policy. No paid route calls or
  Google Maps/Places script activation to make a check pass.
- No new custom modules, PHP/JS glue, hooks, subscribers, service decorators,
  controllers, repositories, ECA scripts/actions, database drivers, or tables.
  A maintained contrib module's reviewed installation schema is a separate
  approval-gated dependency effect, not permission to design a custom schema.
- Exactly one order-creation path must remain. A contrib-created order cannot
  bypass required CheckoutAttempt records, private Hold receipts, payment checks,
  or the driver guard. A redirect to checkout does not prove these contracts.
- Use supported contrib configuration only. If that cannot satisfy the contracts,
  stop and report the unsupported transition rather than fabricate records or
  disable checks. Do not disable a whole handler that still owns required duties.
- Public time remains `America/New_York`, internal timestamps UTC, with existing
  DST behavior. No public timezone selector or Hold countdown.

## Legacy and financial data

- Preserve existing submissions, uploads, order/item IDs, customers, accepted
  amounts/currencies, snapshots, attempts, Reservations, review records, and their
  relationships. Do not recalculate historical prices or rewrite references.
- No stale-attempt cleanup, conversion of old drafts to carts, reservation
  expiry/release, purging, sync-delete enablement, backfill, table deletion, or
  legacy-code removal is included. Record suspected problems for separate work.
- Baselines must include record identity and relationship/value integrity, not
  merely counts. Keep sensitive raw material private; public evidence may contain
  aggregate/digest comparisons, not customer addresses, tokens, or credentials.
  An unkeyed address hash is not effective anonymization.
- Do not treat a database as disposable because it is named QA. In later tasks,
  fixture IDs must be explicitly identified and separated from protected records.
  Cleanup is limited to that approved set; no wildcard deletion or blanket restore.
- Record unexpected drift and stop the affected operation. Do not undo another
  actor's changes or infer that an unrelated difference was caused by this task.

## Evidence tools and skills

- Use applicable native skills, plugins, slash commands, and read-only subagents
  under the same task scope. Main executors must read applicable skill instructions.
  Use `writing-for-agents` for task documents and `codebase-design` for the existing
  interface/seam decision; never treat those skills as authority to add new code.
- Follow repository requirements for Graphify query-first, GitNexus, Context7,
  and least-privilege DBHub. Do not report unavailable tools as active or reactivate
  suspended services. Only an explicitly authorized fallback may replace a failed
  mandatory tool. Otherwise mark that evidence step blocked.
- Graphs do not establish active YAML/configuration, SQL runtime behavior, or
  Markdown content. Inspect these directly and label the evidence correctly.
  Stale indexes are not current proof. No index rebuild merely for Markdown edits.
- Use official module documentation and the exact candidate release source.
  Package version compatibility does not establish behavioral compatibility.

## Runtime and verification rules

- Prefix shell commands with RTK. Run Drupal CLI through the approved DDEV path
  with an explicit site/URI. Check health without starting services first; a DDEV
  invocation may start/recreate stopped containers. Stop on unexpected lifecycle
  changes, database saturation, or an unapproved target identity.
- No PHPUnit, Kernel, FunctionalJavascript, Selenium, browser-automation suites,
  or generated test suites. Direct probes/manual UI checks are allowed only when
  the selected task has explicit approval for their exact scope and writes.
- Prove zero-cost routing at the actual outgoing-call paths before dynamic work,
  including scheduling, checkout, staff actions, workers, and route warming.
  A mode label or absence of browser requests is not sufficient by itself.
- Isolate outbound email, webhooks, cron, workers, and payment behavior before
  exercising QA. No live charges, capture, void, refund, or external payment calls.
  A specific sandbox operation requires its own approval; missing setup is a
  blocker, not permission to install a new gateway or mock implementation.
- Short driver-lock transactions use prepared validated facts. Keep routing,
  geocoding, gateway I/O, email, queue delivery, and customer waiting outside them.
  Final local capacity decisions remain synchronous. Do not change this design.
- Review all pending hooks and installation effects before later authorized
  enablement. No blanket `updb`, unreviewed configuration import, or arbitrary
  execution of individual hooks to avoid the normal update lifecycle.
- After approved Drupal configuration changes, run the required site-specific
  DDEV cache rebuild once health is established. Cache rebuilding is not business
  acceptance. Markdown-only work needs neither `drush cr` nor graph refresh.
- Report actual commands, exit codes, assertion totals where applicable, and
  `git diff --check`. New/untracked documentation needs its own whitespace/link
  check because ordinary Git diff may not include it.

## Recovery and stop conditions

- Files, Composer, configuration, DDL, database state, and payment gateways do not
  share one transaction. Later implementation needs reviewed checkpoints and a
  restoration rehearsal in a genuinely isolated target.
- Stop on unapproved packages/hooks/config changes, missing legacy references,
  protected source/config changes, duplicate order writers, external effects, or
  any required custom integration. Do not expand into fixing these automatically.
- A rollback must preserve legitimate writes after cutover. Never restore an old
  whole database over newer customer orders or cancel unknown payments blindly.
- Mark results PASS, FAIL, BLOCKED, or NOT RUN. Use REQUIRES_VERIFICATION for a
  source inference not established dynamically. Evaluate missing evidence against
  the current task's gate: unresolved source/configuration contracts block 25B;
  dynamic acceptance deliberately reserved for 25C does not prevent a source-backed
  25A verdict A or approved 25B setup. It must remain explicitly pending and blocks
  rollout until verified in 25C. Preserve historical Task 21-24 acceptance distinctions.
