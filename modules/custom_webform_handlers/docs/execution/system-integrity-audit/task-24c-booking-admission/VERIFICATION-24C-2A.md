# Task 24C-2A — Contract-evidence gap closure (verify() precondition + fresh-vs-reuse)

Status: **Two contract-evidence gaps closed; admission enforcement inactive.**
Scope: narrowly-necessary admission-foundation fixes, module evidence
corrections, and one temporary sequential QA rollback probe. **No** schema
change (QA stays at `update_10048`; 10048 was **not** rerun), no committed
fixture, no caller activation, no concurrency/activation work, no commit.

All commands ran against the already-running DDEV container via `ddev drush`
(`docker` CLI is not on this host's PATH; `ddev exec` is avoided — it recreates
web+db). QA is addressed explicitly with `--uri=https://ppu-qa.ddev.site` on
every invocation; the default site is never substituted. The QA table was
**never dropped**.

## 1. Preconditions (verified fresh, not assumed)

| Fact | Value | How established |
| --- | --- | --- |
| Probe DB | `qa` (`SELECT DATABASE()` == `qa`) | Probe P0, runtime. |
| Installed schema | **10048** | `keyValue('system.schema')`, runtime. |
| Admission table | **present** | `Connection::schema()->tableExists(...)`, runtime. |
| Synthetic rows before probe | **0** | fresh-process baseline check. |

The probe used an **unused synthetic-ID ledger** (`924200001`–`924200006`), a
single outer `startTransaction()` undone in a `finally` `rollBack()`, a
nonzero `exit(1)` on any failed assertion (success falls through → drush EC 0,
per the `php:script` exit-swallowing rule), and a **separate fresh process**
for the baseline/cleanup check.

## 2. Code changes (narrowly necessary, additive, no caller)

Three source edits under `modules/custom_webform_handlers/`, all inert (no
service tag, no caller, no schema, no migration):

1. **`BookingAdmissionStore::verify()` — new `assertInTransaction()` precondition
   (Gap 1 fix).** `verify()` now calls `assertInTransaction()` immediately after
   `assertSchema()` and **before any read**. The new private method throws
   `\LogicException` when `Connection::inTransaction()` is false. `verify()` is a
   locking read (`SELECT … FOR UPDATE`) whose lock is only meaningful inside the
   binding writer's own transaction; standalone it would auto-commit and hand back
   a lock the caller no longer holds. The guard makes that a refused programming
   error instead of a silent unlocked answer. The method opens **no** transaction
   of its own.
2. **`AdmissionResult::isFreshAdmission()` — new predicate (Gap 2 contract).**
   Returns `outcome === AdmissionOutcome::Admitted`. `isAdmitted()` is left
   unchanged (still true for `Reused`); the class docblock now states the two are
   not interchangeable and that a new hold/order/payment must gate on
   `isFreshAdmission()`.
3. **Documentation corrections** (Gap 3): FOUNDATION-24C-1.md §6/§7 and
   VERIFICATION-24C-2.md §2/§3 — see §C below.

## A. Gap 1 — verify() transaction precondition (proven both ways)

Requirement: prove `verify()` *requires* a transaction, not merely that it works
inside one. The prior stage (24C-2) only exercised the positive inside-txn path,
which proves nothing about the precondition.

**A.1 Standalone refusal (no ambient transaction).** In a fresh process, with
`inTransaction()` asserted **false**, `verify($id, $secret, 1)` was called with
otherwise-valid inputs:

| # | Assertion | Result |
| --- | --- | --- |
| A0 | `inTransaction()` is false before the call | PASS |
| A1 | the call **throws** (does not return an `AdmissionResult`) | PASS |
| A2 | thrown type is `\LogicException` | PASS |
| A3 | message names the precondition (contains "transaction") | PASS |
| A4 | no `NotFound` (or any) result was returned — it refused **before** the read | PASS |
| A5 | `inTransaction()` still false afterwards — it opened **no** transaction | PASS |

A4 + A5 together satisfy "does not silently open a transaction or perform a
locking read": the refusal precedes any DB access, so `verify()` can never
degrade to an unlocked point-in-time answer.

**A.2 Positive inside-transaction path.** Then, inside the outer
`startTransaction()`:

| # | Assertion | Result |
| --- | --- | --- |
| B0 | `inTransaction()` true inside the txn | PASS |
| B1 | fresh `acquire()` → `Admitted` (gen 1) | PASS |
| B2 | `verify()` of that row at gen 1, right owner → `Verified` | PASS |
| B3 | `verify()` wrong owner → `RefusedOwner` | PASS |
| B4 | `verify()` wrong generation → `RefusedGeneration` | PASS |

`Verified` is neither `isAdmitted()` nor `isRefused()` — it is the non-mutating
binding primitive, to be called inside the writer's transaction so its
`FOR UPDATE` lock is held to that writer's commit.

## G. Gap 2 — fresh admission vs. reuse (actual results recorded)

Requirement: call `acquire()` with the rightful owner and unchanged intent
against `pending`, `confirmed` and `reconciliation_required` rows, and record the
**actual** outcome/state/generation/predicates — because asserting against
`Confirmed`/`ReconciliationRequired` outcomes does not describe what `acquire()`
returns (it returns `Reused`).

All inside the outer transaction, on the synthetic ledger:

| # | Setup | Call | outcome | state after | gen | isAdmitted() | isFreshAdmission() |
| --- | --- | --- | --- | --- | --- | --- | --- |
| G0 | no row | acquire | **Admitted** | pending | **1** | true | **true** |
| G1 | pending | acquire (same owner+intent) | **Reused** | pending (unchanged) | 1 (unchanged) | true | **false** |
| G2 | confirmed | acquire (same owner+intent) | **Reused** | confirmed (unchanged) | 1 (unchanged) | true | **false** |
| G3 | reconciliation_required | acquire (same owner+intent) | **Reused** | reconciliation_required (unchanged) | 1 (unchanged) | true | **false** |

Findings, recorded from runtime:

- `acquire()` returns `Admitted` **only** for a fresh grant (G0: insert at gen 1;
  a re-arm from `released` would be gen + 1). On every live row it returns
  `Reused` with **state and generation unchanged** (G1–G3) — it never mutates or
  re-grants a live admission.
- `isAdmitted()` is **true for all four** (G0–G3), because both `Admitted` and
  `Reused` are `isAdmission()`. So `isAdmitted()` alone answers only "does this
  owner hold a live admission for this intent" — it is **not** authorization to
  create another hold/order/payment.
- `isFreshAdmission()` (new, `outcome === Admitted`) is **true only for G0** and
  is the predicate a caller must gate a new downstream effect on. A `Reused`
  result means "already held — do not act again".
- **Integration gate, not proven here:** actually *suppressing* a duplicate
  downstream side effect (a second Hold/Order/payment on `Reused`) is the binding
  caller's responsibility. This store-only probe proves the store **distinguishes**
  the two outcomes; it does **not** and cannot prove downstream duplicate
  prevention. That remains an explicit activation gate (FOUNDATION §11).

## C. Gap 3 — evidence-precision corrections (earlier results preserved)

Earlier results are **kept**; only their overbroad interpretations are corrected.

- **Missing-schema behavior → labelled source-established.** VERIFICATION-24C-2 §2
  said this was "established in the foundation stage" without distinguishing source
  from runtime. It is established by **reading `assertSchema()`**, not by runtime
  evidence: the QA table is present (10048) and was **never dropped** to recreate
  the absent-table condition. §2 now says exactly that.
- **verify()-requires-a-transaction → scoped to what was exercised.**
  VERIFICATION-24C-2 §3 was headed "`verify()` requires an active transaction",
  but 24C-2 only ran the inside-txn (positive) path, which cannot prove the
  requirement. §3 now records that scope limit and points to §A here, where the
  requirement is code-enforced and demonstrated by standalone refusal.
- **FOUNDATION-24C-1.md** §7 now states the `assertInTransaction()` precondition is
  enforced in code (not merely a caller convention) and cites §A; §6 gains the
  fresh-vs-reuse authorization gate (`isFreshAdmission()`) and cites §G.

The 55/55 (P1) and 9/9 (P2) results reported in VERIFICATION-24C-2 are preserved
unchanged; the migration is preserved (10048 not rerun).

## 3. Final verification (exit codes recorded)

| Check | Command (abridged) | Result | Exit |
| --- | --- | --- | --- |
| Sequential QA rollback probe | `ddev drush --uri=…ppu-qa… php:script` (fresh process) | `RESULT: PASS 47 of 47` | 0 |
| Fresh-process baseline/cleanup | second independent `php:script` | `TABLE_PRESENT=yes SCHEMA=10048 SYNTH_ROWS=0`, `PASS 3 of 3` | 0 |
| PHP lint — all admission files | in-container `php -l` loop | `LINT: 7/7 ok, 0 bad` | 0 |
| YAML parse + service present | `Yaml::parseFile(services.yml)` + isset | `booking_admission_store=present` | 0 |
| QA cache rebuild | `drush --uri=…ppu-qa… cr` | `[success] Cache rebuild complete.` | 0 |
| Whitespace / conflict markers | `git diff --check` | clean (benign LF→CRLF advisory on unrelated `.gitignore`) | 0 |
| Graphify AST update | `graphify update .` | AST 131/131 re-extracted; **rebuild blocked by watch-lock** (`WinError 32`) — known condition, touched file confirmed by direct read | 1 |

No automated suites (PHPUnit/Kernel/Functional) were run, per policy. The
`graphify update` AST pass completed; only the final graph.json write was blocked
by a running `graphify watch` holding the file — the documented lock, not a
new failure. Admission source is confirmed by direct read regardless (YAML/new
files are outside AST coverage anyway).

## 4. Cleanup

- Container `/tmp/adm_probe_24c2a.php` removed (`CONTAINER_REMAINING=0`).
- Host temp `adm_probe_24c2a.php` removed (`HOST_REMAINING=0`).
- No repository artifacts were created for the probe; the only tracked additions
  from this stage are the two source edits (`BookingAdmissionStore.php`,
  `AdmissionResult.php`) and documentation.
- QA backup `/tmp/qa-pre-24c2.sql` (container `/tmp`, ~34.5 MB) **retained**
  untouched as a rollback net while Task 24C is incomplete. Outside the repo,
  never committed.

## 5. Remaining gates before any activation (unchanged)

This stage closes two contract-evidence gaps and adds a caller-facing predicate.
It does **not** satisfy the activation prerequisites (FOUNDATION §11), all still
open: both capacity families under real routing; safe anonymous-job identity;
pre-existing in-flight records; conflict reporting to operators/UX; failure
compensation after a binding write fails post-`verify()`; release-policy
enforcement and renewal-without-cron end to end; **and downstream
duplicate-side-effect prevention on `Reused`** (§G). The service remains inert:
no tag, no caller, no subscriber, no hook wiring, no constructor query.

## 6. Evidence systems used + coverage limits

- **Direct source inspection** (authoritative for the new/edited admission files,
  YAML, and Markdown — all outside Graphify AST coverage): `BookingAdmissionStore.php`,
  `AdmissionResult.php`, `AdmissionOutcome.php`, `services.yml`, the FOUNDATION and
  VERIFICATION docs.
- **`ddev drush`** against the running container (php lint, `php:eval`,
  `php:script`, `cr`) — `docker` CLI unavailable, `ddev exec` avoided.
- **Graphify** AST pass ran; graph.json rebuild blocked by the watch-lock. A
  negative there proves nothing for these files (uncommitted PHP + YAML).
- **DBHub** remained **suspended** (policy); no MCP SQL was issued. The runtime
  facts (`DATABASE()`, schema, `tableExists`) were read through the Drupal DB
  connection inside the probe, not DBHub.

---

**Two contract-evidence gaps closed; admission enforcement inactive. Task 24C
incomplete; Task 24B PARTIAL.**
