# PPU — Mandatory Claude Code Policy

These rules are the project baseline and may be changed only by an explicit user instruction.

## Scope and change control

- All application-code, Drupal configuration, PHP, JavaScript, YAML, and documentation changes must be limited to `modules/custom_webform_handlers/`.
- Ignore the partial duplicate at the repository root, `custom_webform_handlers/`, and do not edit it.
- The only exception is explicit tooling/setup work requested by the user, such as files in `.claude/`, `.mcp.json`, `.gitignore`, or this `CLAUDE.md`.
- Do not make unrelated cleanup, formatting, dependency, DDEV, database-schema, or configuration changes.
- Preserve unrelated uncommitted changes. Do not commit, reset, stash, or discard work unless explicitly instructed.

## Mandatory evidence workflow

Before analyzing or changing `modules/custom_webform_handlers/`, use the applicable systems below. Do not bypass them merely to proceed faster.

1. `/graphify`
   - Query the graph first for PHP/JavaScript structure, call paths, dependencies, and blast radius.
   - Run `graphify update .` after relevant source changes.
   - Treat Graphify as authoritative only for its extracted coverage. YAML, Markdown, and incompletely parsed SQL are outside its current coverage; inspect those directly after recording that the graph does not establish the answer.

2. GitNexus MCP
   - Use GitNexus for architecture and impact analysis before changes.
   - If uncommitted changes affect the analysis, re-index or re-analyze before relying on graph results.

3. Context7 MCP
   - Retrieve current official documentation before relying on external APIs or framework behavior, including Drupal 10.6, Webform, Google Maps, Drupal Behaviors, PHP, JavaScript, and MariaDB APIs.

4. Matt Pocock engineering skills
   - Read and apply the relevant skill in `.agents/skills/` before planning or changing work.
   - Use TDD only when tests are explicitly requested. Use the relevant diagnosis, design, refactoring, review, or git-guardrail skill when applicable.

5. DBHub MCP
   - Use DBHub for read-only schema discovery and query-plan analysis when database tables, SQL, entity storage, or schema assumptions are relevant.
   - DBHub must use the dedicated least-privilege read-only account. Never use it for writes, DDL, migrations, or destructive SQL.

6. Drupal YAML and routing analysis
   - No MCP covers Drupal YAML or routing. The official fallback is direct inspection of the active module's own YAML files under `modules/custom_webform_handlers/` — `*.routing.yml`, `*.services.yml`, `*.libraries.yml`, `*.permissions.yml`, `*.info.yml`, `*.links.menu.yml`, and `config/schema/*.yml`.
   - Supplement that with DDEV/Drush route and cache operations, for example `ddev drush route --name=<route>` and `ddev exec vendor/bin/drush cr`.
   - Read-only Drush inspection is expected here; do not use export tools or commands that alter Drupal state unless explicitly ordered.
   - Label such findings as read from source or from Drush, not as graph-established evidence.

A required MCP or skill being unavailable, failing, or returning unusable results is a blocker for the part of the task that requires it: stop and report the failure. Do not silently substitute guesswork. A known coverage limitation is not an MCP failure; use the documented fallback source and clearly label the limitation.

## Drupal execution policy

- Run all Drupal CLI commands through DDEV from the project root.
- After every Drupal module source or configuration edit, run:
  `ddev exec vendor/bin/drush cr`
- Do not run PHPUnit, Kernel, Functional, Functional-JavaScript, browser probes, or other tests unless explicitly requested.
- Do not run long-running loops, deep verification, or security scans unless explicitly requested.

## Security and reporting

- Keep credentials, API keys, generated database passwords, and private local configuration out of Git and terminal output.
- Use least privilege for local services.
- Before reporting a tool as active, verify its configured status. If Claude Code approval or OAuth is required, report that exact status instead of claiming activation.
- State which evidence systems were used, relevant coverage limits, files changed, commands run, and any blocker in the final report.
