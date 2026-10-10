# Task 24A recovery — corrected candidate manifest

**STATUS: APPLIED & VERIFIED (2026-09-18, authorized).** The 16-file patch has
been applied to the working tree (uncommitted) and the recovered baseline verified
live — see `VERIFICATION.md` legs 5–9. Task 24H remains paused. This manifest
describes the corrected recovery candidate under `corrected/candidate/`, the patch
generated from it, and the provenance of every changed or new file.

## What this candidate restores

Two accepted-but-uncommitted bodies of work that are absent from the working tree
at baseline `b49c259e`:

- **Task 21A — zero-cost development routing guard.** A routing-mode resolver, a
  deterministic no-HTTP development route provider, and a customer-route entry
  facade, wired so every customer entry point resolves distances through the
  facade; both Google adapters additionally fail closed in `development_fixed`.
- **Task 22D — versioned price snapshot** (the work the current numbering calls
  "24A"). A first-class `price_snapshot_version` persisted on the Dispatch
  Reservation alongside the existing id/cents/currency snapshot peers, plus its
  install schema column and the `update_10046` migration hook.

The two are recovered together because 21A introduces `update_10045`
(`routing_mode`) and 22D introduces `update_10046` (`price_snapshot_version`),
and the baseline install file defines hooks only through `update_10044`.

A third, previously missed body of work is now also included: the **price-snapshot
forwarding** through the checkout planner seam — the code path that carries the
accepted quote's identity, amount, currency and pricing-policy version from the
Commerce checkout into the store-facing `DispatchClaim`. This was documented as
present in the Sept 14 audit tree (`README.md:10`) but is absent from the working
tree at `b49c259e`. Its recovery is what makes the `price_snapshot_version`
column actually populated on the checkout path rather than always NULL. This
supersedes the earlier (incorrect) Check #5 conclusion that forwarding never
existed — see `PROVENANCE.md`.

## Authoritative sources

| Work | Authoritative session log | What it contains |
| --- | --- | --- |
| Task 21A | `a02d06f0-751e-48f8-938a-7df2bb9b99cf.jsonl` (dup: `ce0c4f4d-…`) | The 3 new files (Write), the Google-adapter ctor + `route()` guard edits, the facade rewiring, the Maps gate, `services.yml`, `update_10045`, and the `routing_mode` schema/settings. |
| Task 22D | `09006154-8718-4dfa-847e-aa11641e5129.jsonl` (dup: `b0bcb8c7-…`, `e28b8d7f-…`) | The value-object property + docblock edits, the 5 `DatabaseReservationStore` seams, the install schema column, and `update_10046`. |
| Price-snapshot forwarding | Codex working tree read verbatim in the Sept 14 audit session `~/.codex/sessions/2026/09/14/rollout-2026-09-14T00-52-34-01a09e42-….jsonl` | The `DispatchPlanner::createCheckoutHold()` body + `createHoldFor()` signature/docblock + `new DispatchClaim(` price args, the `DispatchCheckoutManager` line-129 call, and the planner's `QuoteSnapshot` import. The `DispatchCheckoutPlannerInterface` declaration is a labeled minimal reconstruction (see below). |

The Task 22D **design intent** is additionally captured verbatim in the compaction
summary at `a02d06f0…jsonl:2101`, which states the planner is out of scope and
`confirm()` writes no price "by deliberate symmetry" — see check #5 below.

## File-by-file completeness and provenance

Every row was reconciled byte-for-byte against the authoritative session edit/write
payloads. "Recovered" means the candidate content is identical to the content the
authoring session wrote; no row is a reconstruction.

### Task 21A — routing guard

| File | Change | Provenance |
| --- | --- | --- |
| `src/Distance/RoutingModeResolver.php` | New (98 lines) | Recovered — Write, `a02d06f0…:1228–1230` region |
| `src/Distance/FixedDevelopmentRouteProvider.php` | New (118 lines; `19312` m, `1680` s, `development_fixed`, `ddev-fixed-v1`) | Recovered — Write |
| `src/Distance/RoutingModeEntryProvider.php` | New (52 lines) | Recovered — Write |
| `src/Distance/GoogleRoutesDistanceProvider.php` | ctor 7th param `?RoutingModeResolver`; `route()` fail-closed guard (`NOT_CONFIGURED`) | Recovered — Edits `…:1241` (ctor) and `…:1243` (guard) |
| `src/Controller/DistancePreviewController.php` | `create()` → `customer_route_entry_provider` | Recovered — Edit `…:1259` |
| `src/Plugin/WebformHandler/AddressDistanceHandler.php` | `use RoutingModeResolver`; property; `create()` wiring + resolver; browser-Maps gate | Recovered — Edits `…:1264–1296` |
| `custom_webform_handlers.services.yml` | resolver into both Google adapters; 3 new services; starter arg5 rewire | Recovered — Edits `…:1248,1250` |
| `config/schema/custom_webform_handlers.schema.yml` | `routing_mode` typed-config string | Recovered — Edit `…:1314` |
| `config/install/custom_webform_handlers.settings.yml` | `routing_mode: live_cached` + comment | Recovered — Edit `…:1305` |
| `custom_webform_handlers.install` | `update_10045` (`routing_mode` default) | Recovered — Edit `…:1316` |

### Task 22D — versioned price snapshot

| File | Change | Provenance |
| --- | --- | --- |
| `src/Dispatch/DispatchClaim.php` | `@param` + `public ?string $priceSnapshotVersion = NULL,` | Recovered — Edits `09006154…:97,105` |
| `src/Dispatch/DispatchReservationDetail.php` | `@param` + `public readonly ?string $priceSnapshotVersion,` | Recovered — Edits `…:108,110` |
| `src/Dispatch/Storage/DatabaseReservationStore.php` | 5 seams: `REPLAN_FIELDS`, claim INSERT, replan verbatim copy, `adminReservation()` SELECT, `adminReservation()` hydration | Recovered — Edits `…:119,121,123,125,127` |
| `custom_webform_handlers.install` | schema column `price_snapshot_version` (`varchar_ascii`/64/nullable) + `update_10046` | Recovered — Edits `…:132,134` |

### Price-snapshot forwarding (checkout planner seam)

| File | Change | Provenance |
| --- | --- | --- |
| `src/Commerce/DispatchCheckoutManager.php` | line-129 call → `createCheckoutHold($request, $resolved, $quote)` | Recovered verbatim — Sept 14 tree read (`:129`) |
| `src/Dispatch/DispatchPlanner.php` | `use …\Checkout\QuoteSnapshot`; `createCheckoutHold()` decomposes the quote into 4 scalars; `createHoldFor()` gains 4 nullable price params + docblock; `new DispatchClaim(` gains the 4 price args | Recovered verbatim — Sept 14 tree read (`:7,234–302,377–382`) |
| `src/Dispatch/DispatchCheckoutPlannerInterface.php` | `use …\Checkout\QuoteSnapshot`; `createCheckoutHold(..., QuoteSnapshot $quote)` | **Minimal reconstruction** — the typed 3-arg call requires this declaration, but no verbatim read of the interface's changed signature exists in any session log. Docblock and `use` line authored to match; signature is forced by the call site. |

Note: the forwarding's 4th `DispatchClaim` argument (`priceSnapshotVersion`) depends on the Task 22D value-object change above; the two compose into one coherent claim constructor.

## Patch

- `task-24a-recovery-corrected.patch` — 16 files, 3 new, 457 insertions / 9
  deletions. Baselines and candidates are mirrored under `corrected/baseline/`
  and `corrected/candidate/`; the patch is generated between those trees with the
  path prefixes rewritten to be module-relative. Apply from the repo root with an
  explicit `--directory`:

  ```bash
  git apply --directory=modules/custom_webform_handlers modules/custom_webform_handlers/docs/execution/system-integrity-audit/task-24a-recovery/task-24a-recovery-corrected.patch
  ```

  `git apply --check --verbose --directory=modules/custom_webform_handlers` passes
  (exit 0) against baseline `b49c259e`, checking all 16 files. See
  `VERIFICATION.md`.

## Corrections to earlier evidence wording

Recorded in full in `PROVENANCE.md`; in brief:

1. The reflog shows no reference rollback, but that cannot establish that
   uncommitted files were never discarded — the 21A/22D work was simply never
   committed, stashed, or branched, so Git never held it.
2. Matching dependency/constructor signatures does not prove the recovered code
   compiles. Offline `php -l` proves syntax only.
3. Manifest checksums (and this table) do not prove the candidate is the complete
   accepted implementation; they prove only that the candidate matches the content
   the authoring sessions recorded.
