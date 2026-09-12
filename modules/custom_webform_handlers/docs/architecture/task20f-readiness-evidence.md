# Task 20F readiness evidence

Recorded 2026-09-08 and resolved 2026-09-09. The original readiness findings
below are retained as history. Task 20F is now implemented and registered but
not connected to a public consumer. No public provider alias was changed.

## Database gate: cleared for read-only discovery

DBHub is configured locally but is not exposed in this session's native tool
list. The configured stdio server was called directly using MCP initialization,
tool discovery, and `execute_sql` containing SELECT statements only.

The saved connection used an obsolete local DDEV port. Its startup failed with
a connection-refused diagnostic. The current port was read from Docker's port
mapping. A session-scoped child-process environment reused the configured
`dbhub_ro` credentials against that verified loopback port. Neither `.mcp.json`
nor `.claude/dbhub.toml` was edited. No DSN, password, or raw server error log
was printed. This restores inspection for this execution, not the persistent
tool configuration for a future session.

DBHub returned:

| Check | Observed result |
| --- | --- |
| Database principal | `dbhub_ro@%` |
| Database / server | `db` / `11.8.8-MariaDB-ubu2404-log` |
| Global privileges | `USAGE`, not grantable |
| Database privileges | `SELECT`, `SHOW VIEW`, neither grantable |
| Active role / table-specific privileges | None / none returned |
| Default storage engine | `InnoDB` |
| Existing dispatch tables inspected | All returned `InnoDB` |
| Installed module schema version | `10041` (`system.schema` record) |
| Existing module tables matching `*budget*` | None returned |

Only privilege metadata, table metadata, server identity, and this module's
installed update number were read. No customer/order records were queried.
DBHub was not used for DDL, writes, migrations, or simulated reservations.

Source inspection found 41 update hooks, with `10041` the highest and no `10042`.
`rtk proxy ddev exec vendor/bin/drush updatedb:status` returned:
`[success] No database updates required.`

At that snapshot `10042` was the next candidate. It was rechecked immediately
before migration on 2026-09-09; Drush listed `10042` as the only pending update.
The update then created `custom_webform_handlers_routing_budget` as an InnoDB
table and the follow-up status reported no pending updates.

## Documentation gate: historical blocker, resolved

Context7's MariaDB lookup returned relevant primary-source documentation for
conditional updates/upserts and `SELECT ... FOR UPDATE NOWAIT`.

Context7's versioned Drupal lookup did not establish the requested database
interfaces: the snippets were attributed to a spelling dictionary and a search
fixture, and included unrelated legacy entity interfaces. Those snippets were
rejected as evidence. A second lookup against `/drupal/core` returned
`No documentation ... matched this query`.

The official Drupal 10 Transaction page was retrieved separately. On 2026-09-09
the user directed execution to continue after the Context7 limitation had been
reported. The exact Drupal 10.6.16 database, transaction, lock, state, and cache
interfaces were then verified against the installed core source before code was
written. Context7's unusable Drupal result was not cited as supporting evidence.

Primary references retrieved:

- [MariaDB WAIT and NOWAIT](https://github.com/mariadb-corporation/mariadb-docs/blob/main/server/reference/sql-statements/transactions/wait-and-nowait.md).
- [MariaDB INSERT ON DUPLICATE KEY UPDATE](https://github.com/mariadb-corporation/mariadb-docs/blob/main/server/reference/sql-statements/data-manipulation/inserting-loading-data/insert-on-duplicate-key-update.md).
- [Drupal 10 Transaction](https://api.drupal.org/api/drupal/core!lib!Drupal!Core!Database!Transaction.php/class/Transaction/10).

## Capability ledger and scope

- Graphify was queried for `RoutingPolicy` before source dependency inspection.
  The query explicitly truncated its neighborhood. No missing edge was treated
  as proof of no dependency. YAML and Markdown were read directly because they
  are outside Graphify's extraction coverage.
- The incomplete GitNexus index was replaced on 2026-09-09 with a forced full
  rebuild using pinned `gitnexus@1.6.11`. The successful index contains 5,608
  nodes, 14,701 edges, 261 clusters, and 478 flows, with `incomplete=false`.
  Impact queries found no resolved source callers for the Google adapter or
  coordinate resolver. That is a lower bound: service-container YAML was read
  directly and remained authoritative for dependency wiring.
- The `codebase-design` skill was read. Its small-interface and injected
  dependency guidance informs the intended implementation; no interface changed.
- Existing Drupal transaction/schema and coordination facilities remain the
  intended reuse points. No alternative database abstraction or dependency was
  installed, and no cache/state counter was substituted for a durable ledger.
- All prior worktree changes were preserved. Task 20F then added its chain,
  cache, circuit, budget services, schema, and geocoder pre-dispatch guard.
  The local MCP launcher/configuration was also repaired without exposing its
  saved read-only database credential.
- No tests, browser probes, route calls, provider load, or commits were run.
  Update `10042` was the sole pending update, was applied successfully, and a
  cache rebuild completed. The resulting allowance table contained zero rows,
  confirming that verification reserved no spending allowance.
- `rtk git diff --check -- modules/custom_webform_handlers` exited 0. This does
  not validate runtime behavior or the new, untracked document's contents.

## Completion boundary

Task 20F is implemented and its services compile. The live
`custom_webform_handlers.distance_provider` alias still resolves to the original
Google adapter, so the new chain is not active on public traffic. Task 20G owns
that integration change; Task 20H owns bounded live-provider acceptance and
activation. The request ledger is durable now, but it begins enforcing route
spend only when a chain service is used. Geocoder misses are protected now at
their approved resolver seam.
