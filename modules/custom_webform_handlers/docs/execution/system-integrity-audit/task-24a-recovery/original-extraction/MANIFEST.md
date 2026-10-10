# Task 24A-R2 — Original Extraction Recovery Manifest

**Date**: 2026-09-17
**Repository**: `C:/Users/mo737/ppu`
**Branch**: `codex-experiments`
**Base Commit**: `b49c259e3e3d5452286ffb45d1ec6bf1451c0fcc`
**Provenance Scope**: Claude JSONL Session Log Extraction (`.claude/projects/C--Users-mo737-ppu/e28b8d7f-c321-4de0-82bb-818043993504.jsonl`, etc.)

---

## Artifact Inventory & Original Provenance

| Target File / Component | Classification | Original Extraction Source | Status |
|---|---|---|---|
| `custom_webform_handlers.install` (hooks 10045, 10046, schema) | Verbatim Extraction | Log `e28b8d7f` / `09006154` | Extracted, candidate saved, patch formatted |
| `config/install/custom_webform_handlers.settings.yml` (`routing_mode: live_cached`) | Verbatim Extraction | Log `e28b8d7f` / `09006154` | Extracted, candidate saved, patch formatted |
| `config/schema/custom_webform_handlers.schema.yml` (`routing_mode` string mapping) | Verbatim Extraction | Log `e28b8d7f` / `09006154` | Extracted, candidate saved, patch formatted |
| `custom_webform_handlers.services.yml` (routing services & starter rewiring) | Verbatim Extraction | Log `e28b8d7f` / `09006154` | Extracted, candidate saved, patch formatted |
| `src/Dispatch/Storage/DatabaseReservationStore.php` (`price_snapshot_version`) | Verbatim Extraction | Log `e28b8d7f` / `09006154` | Extracted, candidate saved, patch formatted |
| `src/Dispatch/DispatchClaim.php` (`$priceSnapshotVersion` property) | Verbatim Extraction | Log `e28b8d7f` / `09006154` | Extracted, candidate saved, patch formatted |
| `src/Dispatch/DispatchReservationDetail.php` (`$priceSnapshotVersion` property) | Verbatim Extraction | Log `e28b8d7f` / `09006154` | Extracted, candidate saved, patch formatted |
| `src/Distance/RoutingModeResolver.php` | 100% Verbatim Code | Log `e28b8d7f-c321-4de0-82bb-818043993504.jsonl` | Extracted, candidate saved, patch formatted |
| `src/Distance/FixedDevelopmentRouteProvider.php` | 100% Verbatim Code | Log `e28b8d7f-c321-4de0-82bb-818043993504.jsonl` | Extracted, candidate saved, patch formatted |
| `src/Distance/RoutingModeEntryProvider.php` | 100% Verbatim Code | Log `e28b8d7f-c321-4de0-82bb-818043993504.jsonl` | Extracted, candidate saved, patch formatted |
| `src/Distance/GoogleRoutesDistanceProvider.php` | Verbatim Extraction (Constructor & Fail-Closed Guard) | Log `06854e8b-f3ed-4fa1-bd57-c4946166df01.jsonl` | Optional constructor param & guard extracted |

---

## Package Contents

1. `MANIFEST.md` — This manifest document.
2. `PROVENANCE.md` — Itemized JSONL session evidence, exact line counts, checksums, and discrepancy resolution between R1 reconstruction and R2 original extraction.
3. `VERIFICATION.md` — Complete validation report, including `git apply --check` clean exit code 0 against HEAD (`b49c259`), PHP syntax, and type compatibility.
4. `task-24a-recovery-r2.patch` — Unified diff generated mechanically against HEAD (`b49c259e3e3d5452286ffb45d1ec6bf1451c0fcc`).
5. `candidate/` — Candidate files stored separately outside application source.
