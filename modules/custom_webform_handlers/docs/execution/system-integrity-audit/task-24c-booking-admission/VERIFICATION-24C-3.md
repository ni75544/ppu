# Task 24C-3 — Bounded Admission-Store Concurrency Verification

**Scope:** store-level concurrency behaviour of `custom_webform_handlers.booking_admission_store` only.
Booking-admission enforcement stays **inactive** throughout; no caller was activated.

**Target (all steps):** URI `https://ppu-qa.ddev.site`, `sites/qa`, database `qa`, `system.schema[custom_webform_handlers] = 10048`.
**Execution route:** `rtk ddev drush --uri=https://ppu-qa.ddev.site php:script -` against the already-running container (no start/recreate; `ddev exec` avoided).

## Evidence systems & coverage limits

- **Graphify:** refresh **BLOCKED** by the running watch-lock (WinError 32). Per the stage exception, direct-source + SHA-256 hash verification was used in its place. Watcher left running; this is recorded as a coverage limitation, **not** successful graph coverage.
- **DBHub:** **SUSPENDED** (least-privilege QA account saturation risk). Not used.
- **GitNexus / Context7:** not required this stage — the question is runtime concurrency behaviour, not static structure or external-API semantics.
- **DB privilege:** the QA account is least-privilege and lacks `PROCESS`, so `information_schema.innodb_trx` is not readable (expected). Absence of lingering probe transactions is instead evidenced by (i) every worker/case process exiting with a recorded exit code after writing its result, and (ii) the cleanup `DELETE`s acquiring each row lock and completing with **no wait** under a 10 s `innodb_lock_wait_timeout`.

## Source integrity (hash-verified final source; unchanged end-to-end)

All seven admission files PHP-lint clean (`php -l` rc=0) and hash-identical at closeout to the pre-verification record — no source was edited (no foundation defect was demonstrated; scope permits fixing only demonstrated defects).

| File (`src/Dispatch/Admission/`) | `php -l` | SHA-256 |
| --- | --- | --- |
| `BookingAdmissionStore.php` | rc=0 | `d82527f3e3093cd3f5cc3793d74fd0da1eed64fe643e9bf44f80a353c530f6c5` |
| `BookingAdmissionStoreInterface.php` | rc=0 | `ddd84357f954d4ac860a57b1b637b24361249101e2cc9bc102ab641dea4ae6c9` |
| `AdmissionOutcome.php` | rc=0 | `814eb47ccb9639bca2f6806b363c0f97646e808d6c6193d2a9d40f8eb39b7777` |
| `AdmissionState.php` | rc=0 | `664681a403cca55e1bf24750e47a9b05e01ae8249ff3146d217c83526bb7c3c4` |
| `AdmissionRecord.php` | rc=0 | `b8d3868ac6c5a726c42830f55a5c1a69ee7ad87381211660b058b13ea70bcaa0` |
| `AdmissionResult.php` | rc=0 | `56880faa58cb06230514d70396982b26382fdf0f7c431b3694c9f7950669d6b9` |
| `AdmissionSchemaMissingException.php` | rc=0 | `52b6ada7e85093ccdc7f52ed1315f4b848dd92ee26c72a58eb4af6530359e0c2` |

## Requirement 1 — Final-source sequential gate (corrected chronology) — **PASS 39/39**

Re-run against the hash-verified final files, with the chronology corrected: `isFreshAdmission()` exists in the final source **before** the assertions that call it (the earlier narrative placed its addition after the probe; that count is not reused as final-source proof). Direct calls to `isFreshAdmission()` / `isRefused()` / `isAdmitted()` were exercised. Standalone `verify()` **refuses outside a transaction** (`assertInTransaction()`), and **succeeds inside** an ambient transaction. Ledger IDs `924300001`–`924300003` were used and **rolled back** (no committed residue).

## Requirement 2 — Two-session cases — **PASS 19/19**

Distinct MySQL sessions (separate OS processes for A/B/C; two named connections in one process for D), explicit file-flag **barriers**, per-session `innodb_lock_wait_timeout = 10 s`, 30 s barriers, ≤60 s/case. Transient deadlock/timeout is retried **once** on `acquire()` only (a self-committing call — never a silent replay of a caller-owned transaction) so every recorded outcome is a genuine business result, not a lock error. Committed fixtures: a=`924300004`, b=`924300005`, c=`924300006`, d1=`924300007`, d2=`924300008`.

### Case A — competing owners/intents, one submission (worker EC 0/0)
- A → `admitted` (fresh); B → `refused_owner`; both synced; no retry, no error.
- DB after: **1 row**, generation 1, `pending`, owner = winner (A).
- ⇒ exactly one committed fresh admission; the other receives the appropriate refusal.

### Case B — same owner/intent (worker EC 0/0)
- B → `admitted` (fresh); A → `reused`; both synced; no retry, no error.
- DB after: **1 row**, generation 1 (**unchanged**), `pending`.
- ⇒ one fresh admission + one reuse; generation not advanced.

### Case C — hold admission lock through `verify()` while another session mutates (worker EC 0/0)
- Holder: `verify()` = `verified`; lock held ≈0.52 s (`t_lock` 697.805813 → `t_commit` 698.326550).
- Mutator: `saw_lock` = true; `blocked_ms` = **510** (not a timeout; ≪ 10 000 ms bound); `t_return` 698.333822 ≥ holder `t_commit` ⇒ `returned_after_commit` = **true**; outcome = `confirmed`.
- ⇒ lock **retained until the holder's transaction completed**; the mutation succeeded only post-release — a real business result, not a deadlock/timeout.

### Case D — commit release/reacquisition, then mutate with retained old credentials/generation (outer EC 0)
Single process; connections `c3a`/`c3b`/default (distinct sessions).
- **D1 (stale credentials, owner rotated):** replacement acquired under a **new** owner secret (`sNew`), then `verify(id,sOld,1)` and `confirm(id,sOld,1)` with the retired secret → `refused_owner` / `refused_owner` (owner is checked before generation, so a non-matching owner refuses on owner). Replacement row: generation 2, `pending`, `owner_is_new` = true — **replacement unaltered**.
- **D2 (stale generation, same owner):** release + **same-owner** reacquire (`sOld`, generation bumped to 2), then `verify(id,sOld,1)`/`confirm(id,sOld,1)` on generation 1 → `refused_generation` / `refused_generation` (owner matches, so refusal falls through to the stale generation). Replacement row: generation 2, `pending`, `owner_is_same` = true — **replacement unaltered**.

## Requirement 3 — Identity-checked cleanup — **CLEANUP-OK**

Baseline (recorded pre-write): admission table `row_count = 0`; ledger IDs absent. Post-Req-2 **FINAL-IDENTITIES** were captured (stored `owner_token_hash`, `generation`, `state`) and each row's identity re-verified before deletion; expected hashes were recomputed from the minted secrets (`hash('sha256', secret)`). No worker failed — every worker/case process exited with a recorded exit code after writing its result, so no probe-owned worker or transaction was outstanding at cleanup (the `DELETE`s below each acquired their row lock and completed with no wait under the 10 s bound, confirming this).

| Ledger ID | Expected owner hash | gen | state | Identity match | `DELETE` |
| --- | --- | --- | --- | --- | --- |
| 924300004 (a) | `47c72163…4bc294` | 1 | pending | ✓ | rows=1 |
| 924300005 (b) | `b6e2cf94…80e0543`* | 1 | pending | ✓ | rows=1 |
| 924300006 (c) | `437e3f61…208e64` | 1 | confirmed | ✓ | rows=1 |
| 924300007 (d1) | `a5af15d3…136959a` | 2 | pending | ✓ | rows=1 |
| 924300008 (d2) | `06b611ac…1bd2f900`* | 2 | pending | ✓ | rows=1 |

<sub>* hashes truncated in the table for brevity; full values were compared with `hash_equals()`.</sub>

**Independent baseline-restoration check:** `deleted=5`, admission `total_rows=0`, ledger IDs all absent, `system.schema[custom_webform_handlers]=10048`, table exists — `RESULT: CLEANUP-OK`.

**Artifact removal:** container `/tmp/adm_c3` and `/tmp/adm_c3_barrier` removed (`exists_after=0`); host `C:\Users\mo737\adm_c3_probe\` (worker scripts + `out_*.txt`) removed. **QA backup retained:** `/tmp/qa-pre-24c2.sql` (34,553,480 bytes). No secrets were written to the terminal (only hashes); the secret store (`params.json`) was inside the removed container temp dir.

## Requirement 4 — Closeout

- **PHP lint:** 7/7 admission files `php -l` rc=0 (table above).
- **Source hashes:** 7/7 identical to the pre-verification record (table above) — source untouched.
- **YAML:** `custom_webform_handlers.services.yml` parses (Symfony YAML), and service `custom_webform_handlers.booking_admission_store` is present.
- **QA cache rebuild:** `drush cr --uri=https://ppu-qa.ddev.site` → `[success] Cache rebuild complete.` (EC 0).
- **`git diff --check`:** clean (EC 0; only a benign LF→CRLF advisory on `.gitignore`, not a whitespace error). Repo change from this stage is limited to this document under `modules/custom_webform_handlers/docs/…`; unrelated pre-existing dirty work preserved.

## Exit codes

| Step | Outcome |
| --- | --- |
| Req 1 gate | `PASS 39/39`, DRUSH_EC 0 |
| Case A / B / C | worker EC 0/0 each; DRUSH_EC 0 |
| Case D | outer DRUSH_EC 0 (conns c3a/c3b/default) |
| Assessment | `RESULT: PASS 19 of 19`, DRUSH_EC 0 |
| Cleanup delete + verify | `RESULT: CLEANUP-OK`, DRUSH_EC 0 |
| Container artifact removal | `RESULT: CONTAINER-CLEAN`, DRUSH_EC 0 |
| Closeout (lint/hash/YAML/service) | `RESULT: CLOSEOUT-CONTAINER-OK`, DRUSH_EC 0 |
| `drush cr` (QA) / `git diff --check` | EC 0 / EC 0 |

## Conclusion

Under bounded two-session concurrency the admission store enforces per-submission job exclusivity correctly: one committed fresh admission per submission with the appropriate refusal (`refused_owner`) for a competing owner; reuse without generation change for the same owner/intent; row-lock retention through `verify()` until the holder's transaction completes; and refusal of mutations presented with retired credentials (`refused_owner`) or a stale generation (`refused_generation`) without altering the replacement. No foundation defect was demonstrated, so no source was modified. All synthetic rows (≤8 ledgered) were identity-checked and removed; baseline and schema restored.

Admission enforcement remains inactive; Task 24C incomplete and Task 24B PARTIAL.
