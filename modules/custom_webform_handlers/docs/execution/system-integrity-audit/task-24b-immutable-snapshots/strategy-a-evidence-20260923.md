# Task 24B — Strategy A execution evidence, 2026-09-23

Status: **Strategy A COMPLETE. Task 24B remains PARTIAL.**

## Authority and scope

The human owner explicitly authorized immediate execution of the QA-only
attempt-persistence probe, including `open()`, fresh-instance `loadByOrder()`,
eight-column verification, and mandatory transaction rollback. This run used that
authorization directly; it did not infer permission from an earlier assistant
summary. No further application implementation, migration, or infrastructure
change was needed for this probe.

All Drupal commands ran through the existing native Windows Docker CLI into
`ddev-ppu-web`, with `--uri=https://ppu-qa.ddev.site`. No DDEV command ran.
Container ID before execution:
`df2027fc480b683f807a93486be670457e041e13e41f3c226aa27c061b79c189`.
The container was running, with an unhealthy HTTP healthcheck; Drupal CLI bootstrap
was successful. This probe does not establish browser availability.

## Execution result

Actual final probe output:

```text
LEDGER synthetic_order=899000240 synthetic_submission=899000241 tag=task24b_strategy_a_20260923
LEDGER inserted_attempt=3
ROLLBACK explicit Drupal transaction rollback executed
PASS no transaction remains after rollback
PASS post-rollback fresh loadByOrder() === NULL
PASS post-rollback no fixture row by any ledger identifier
RESULT 68 passed / 0 failed; Strategy A attempt round-trip + rollback only
```

**Outer command exit: 0**, captured by the execution tool, not inferred from text.
The script ended normally on success and rethrew on failure; no explicit `exit()`
was used inside Drush's `php:script` runner.

The temporary probe was run with:

```text
rtk proxy "C:/Users/mo737/AppData/Local/Programs/DockerDesktop/resources/bin/docker.exe" exec -w /var/www/html ddev-ppu-web sh -c 'php -l modules/custom_webform_handlers/docs/execution/system-integrity-audit/task-24b-immutable-snapshots/strategy-a-probe-temporary.php && vendor/bin/drush --uri=https://ppu-qa.ddev.site php:script modules/custom_webform_handlers/docs/execution/system-integrity-audit/task-24b-immutable-snapshots/strategy-a-probe-temporary.php'
```

Its executed SHA-256 was
`9341f9637b3d9ec466dddb7284057286f71d8d2c8fb1f3461269e432ad13c102`.
The temporary file is removed as part of cleanup; it is not a shipped handler or
a permanent automated test suite.

## Preconditions and what the 68 checks establish

- Runtime database `qa`, site path `sites/qa`, module schema **10047**.
- `IS_DDEV_PROJECT=true`; effective mode **development_fixed**.
- No ambient transaction. Attempt table **InnoDB**, with no table triggers.
- All eight snapshot columns exist. Synthetic order/submission IDs and derived
  attempt intent were unused before insertion.
- Public factories constructed `AcceptedJob`, `QuoteSnapshot`, `Slot`, and
  `CheckoutAttempt`; fixed in-memory route facts were used, not a provider call.
- A real `CheckoutAttemptStore` inserted one row through `open()`. A separate
  store instance, using the same connection, re-read it through `loadByOrder()`.
  Returned attempt and quote objects were distinct from the first read.
- All eight raw database column values matched the input snapshot exactly:

| Column | Verified persisted value |
| --- | --- |
| `quote_route_meters` | 8047 |
| `quote_route_seconds` | 1800 |
| `quote_line_items` | Canonical two-line breakdown, 2500 + 2000 cents |
| `quote_pair_fingerprint` | Exact input snapshot fingerprint |
| `quote_payload_schema_version` | 3 |
| `quote_payload_digest` | Exact input snapshot digest |
| `quote_job_facts_digest` | Exact accepted-job digest |
| `quote_job_facts` | Exact canonical accepted-facts JSON |

- All **16** public snapshot properties round-tripped, including USD 4500 cents,
  fixture rate version, provider provenance, line items, and integrity fields.
- Composite binding identity matched; persisted facts re-derived the job digest;
  line-item amounts summed to the snapshot total.
- Slot, `America/New_York` timezone, attempt identifiers, intent key, timestamps,
  pending state, and NULL reservation/settlement fields round-tripped.
- Explicit Drupal transaction rollback ran in `finally`, before releasing the
  transaction object. Afterwards there was no active transaction, a fresh store
  returned NULL, and direct reads found zero rows matching any fixture identifier.

A `NullLogger` was injected into the real store to prevent a synthetic refusal
from producing unrelated logging writes. No persistence method was mocked.

## Independent post-rollback check

A separate Drush process, after the probe exited, queried `DATABASE()` plus counts
for the fixture attempt (attempt/order/submission/tag identifiers), Commerce order,
and Webform submission. Actual result:

```text
qa    0    0    0
```

**Outer command exit: 0.** No Commerce order or Webform submission was created.
The synthetic IDs are references in a store fixture, not proof of real entity
relationships. Only attempt ID **3** was generated, and that row was rolled back.
An auto-increment gap may remain; it is not a retained fixture row.

Final cleanup verification: the temporary PHP file was absent on both host and
container; the container absence check exited 0. The same seven application-file
SHA-256 hashes were unchanged after the run, and the web container ID was
unchanged. `rtk proxy git diff --check -- modules/custom_webform_handlers` exited
0 with no output. Only this evidence document and the historical evidence
document's current-status header remain changed by this execution.

## Source identity, safety, and tool limitations

Before insertion, host/container SHA-256 parity was verified for these seven
application files: `CheckoutAttemptStore.php`, `CheckoutAttempt.php`,
`QuoteSnapshot.php`, `AcceptedJob.php`, `DistanceResult.php`, `Slot.php`, and
`QuoteResult.php`. Parity was also checked for the initial temporary probe.

Direct Mutagen inspection returned `ppu-courier` **[Paused]**, both endpoints
disconnected; `ppu` was **Watching for changes**, both endpoints connected.
No daemon/session/container was started, stopped, resumed, or recreated.

No routing/geocoding provider, payment, notification, checkout manager,
reservation writer, or capacity lock was invoked by the probe. No credentials
were printed. No default-site Drupal command, migration, source/config edit,
commit, reset, or log deletion was performed. Existing application changes were
preserved. A whole-stack network trace was not collected; no-call evidence is the
bounded exercised code path and runtime zero-cost guard, not a network monitor.

Tool limitations were not concealed or treated as runtime evidence:

- GitNexus query bound to `ppu`, but the index was dated 2026-09-14 and reported
  missing FTS indexes; its empty result was not used as absence-of-call proof.
- Graphify CLI query failed with `uv trampoline failed to canonicalize script
  path`; no index repair or deletion was attempted.
- DBHub was not exposed in the available tool inventory. The explicitly
  authorized direct-container QA connection supplied schema/runtime evidence.
- Context7 returned no matching transaction documentation; installed Drupal core
  source was read to verify `startTransaction()`, `rollBack()`, and destructor
  behavior. Independent read-only source review confirmed the same transaction
  boundary and the store's lack of entity/payment/provider calls.

## Diagnostic correction during this run

The first probe invocation stopped **before starting a transaction or INSERT**:
`7 passed / 1 failed`, outer exit **1**, at its InnoDB precondition. This was a
temporary diagnostic bug, not a module defect: `prefixTables()` produces a quoted
SQL identifier, which was incorrectly supplied as a table-name value to
`information_schema`. A read-only query confirmed the table was InnoDB. The
temporary probe was corrected to `getPrefix() . $table`; the subsequent complete
execution produced the 68/0 result above. No failed write or cleanup was hidden.

## Clear task audit

| Check / task | Status |
| --- | --- |
| QA schema 10047 and eight snapshot columns | COMPLETE; re-confirmed this run, migration not rerun |
| Strategy A real INSERT + fresh store hydration | COMPLETE; 68/0 with exit 0 |
| Exact eight-column payload and all 16 snapshot properties | COMPLETE |
| Mandatory rollback and independent fixture-absence check | COMPLETE |
| Existing A-I value-object harness | Previously recorded 55/0, exit 0 in round2-evidence.md §10; not rerun or included in this run's 68 |
| Locked reservation confirmation and edited-job rejection | PENDING; not exercised by Strategy A |
| Real reservation / Commerce order / checkout display persistence | PENDING; synthetic store fixture cannot establish this |
| Concurrent payment callbacks / booking confirmation idempotency | PENDING; no concurrency or payment probe was run |
| Full browser acceptance and unhealthy web service | PENDING; not repaired or exercised |
| Default-site migration / full runtime acceptance | Outside this QA-only run; not performed |
| Whole Task 24B | PARTIAL, not complete |

Strategy A proves a transactional INSERT/reload/rollback round-trip. It does not
prove a committed booking, crash durability, payment confirmation, or whole-site
database integrity. Those claims remain separate acceptance work.
