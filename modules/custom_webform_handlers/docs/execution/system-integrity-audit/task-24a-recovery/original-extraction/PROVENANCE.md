# Task 24A-R2 — Original Provenance & Audit Records

**Target Repository**: `C:/Users/mo737/ppu`
**Provenance Source**: Claude CLI Session Logs under `C:\Users\mo737\.claude\projects\C--Users-mo737-ppu\`
**Base Commit**: `b49c259e3e3d5452286ffb45d1ec6bf1451c0fcc`
**Date**: 2026-09-17

---

## 1. Primary Session Log Sources

The recovery payload was extracted directly from session logs containing actual tool calls (`write_file`, `replace_in_file`, `view_file`):

- **Session `e28b8d7f-c321-4de0-82bb-818043993504.jsonl`**:
  - Full verbatim implementations for `RoutingModeResolver.php`, `FixedDevelopmentRouteProvider.php`, `RoutingModeEntryProvider.php`.
  - Service definitions in `custom_webform_handlers.services.yml`.
  - Settings schema in `custom_webform_handlers.schema.yml` and default settings in `custom_webform_handlers.settings.yml`.
  - Database hooks 10045 & 10046 in `custom_webform_handlers.install`.
- **Session `09006154-8718-4dfa-847e-aa11641e5129.jsonl`**:
  - Full file write operations for `RoutingModeResolver.php` (97 lines), `FixedDevelopmentRouteProvider.php` (119 lines), `RoutingModeEntryProvider.php` (53 lines).
- **Session `06854e8b-f3ed-4fa1-bd57-c4946166df01.jsonl`**:
  - Implementation of `GoogleRoutesDistanceProvider.php` optional 7th parameter (`?RoutingModeResolver $routingModeResolver = NULL`) and fail-closed check (`$this->routingModeResolver?->isDevelopmentFixed()`).
- **Sessions `a02d06f0-751e-48f8-938a-7df2bb9b99cf.jsonl`, `b0bcb8c7-a494-49aa-8a13-6e476983bfcb.jsonl`, `ce0c4f4d-d4aa-43e4-bde4-927c038d37cb.jsonl`**:
  - `DatabaseReservationStore.php`, `DispatchClaim.php`, `DispatchReservationDetail.php` field additions (`price_snapshot_version`).

---

## 2. R1 vs R2 Discrepancy Resolution

| Component | Task 24A-R (R1 Spec Reconstruction) | Task 24A-R2 (R2 Log Extraction) | Resolution & Impact |
|---|---|---|---|
| `FixedDevelopmentRouteProvider.php` | Guessed fallback constants: 0m, 0s, empty class stub | Original constants: `FALLBACK_DISTANCE_METERS = 19312` (12.0 mi), `FALLBACK_DURATION_SECONDS = 1680` (28 min), `DATA_VERSION = 'ddev-fixed-v1'`, fixture fingerprinting | **Extracted Original Verbatim**: Provides realistic dev route calculations without invoking external APIs. |
| `GoogleRoutesDistanceProvider.php` | Omitted guard parameter in R1 patch | Extracted constructor optional parameter `?RoutingModeResolver` and fail-closed guard | **Extracted Original Guard**: Throws `NOT_CONFIGURED` if `isDevelopmentFixed()` is active, preventing accidental Google API calls. |
| Patch Formatting | `task-24a-recovery.patch` failed `git apply --check` (`corrupt patch at line 13`) | `task-24a-recovery-r2.patch` generated mechanically against HEAD | **Passed Offline Check**: Exit code 0, 100% applicable without reject hunks. |

---

## 3. SHA256 & MD5 Checksums

### Candidate Files (`original-extraction/candidate/modules/custom_webform_handlers/`)

| Relative Path | SHA256 Checksum | MD5 Checksum |
|---|---|---|
| `custom_webform_handlers.install` | `bde9b8a79b2b0242c4354adcb97e6d1ed60503d13a2c52fc145e2c2ec45b6744` | `705694b7075d8ccd1d4170f4c7c71931` |
| `custom_webform_handlers.services.yml` | `4d1563e57a2b25f7b83c16e1004533d901c85dd1cfa2d9d04f6d736ebf1c8d27` | `2bdd657de8203f1da876d51577ddbfa9` |
| `config/install/custom_webform_handlers.settings.yml` | `194a14a10ca1cb39c6004075218101ea6cc3c41d6e8f1f8a517173d4cd3de6e4` | `5eb14c1a520828c14e438ef69de68e41` |
| `config/schema/custom_webform_handlers.schema.yml` | `7c15b0675457a95c4290fa84b2118e39e39ca9bb4f21959347634e7917d812ce` | `0e1b79bbca706f1c46bfa8ae59bd855a` |
| `src/Dispatch/DispatchClaim.php` | `c189cbe48577e1ac3e8fa9fcb84751ee138ca002f4c126f294da7c25e6a5f5c6` | `57dffbf861704d37f448126f325f9da9` |
| `src/Dispatch/DispatchReservationDetail.php` | `0b28711b54dc78dac56b5ed8e380ba82b2b28340484ae8df79997900ff9efd94` | `9ea4489944445e8537546d0bf46f8ffe` |
| `src/Dispatch/Storage/DatabaseReservationStore.php` | `8ed6576bb75b5c2b2b674095a68044f092497285add4f32e7dd64f20d4973a44` | `24f1b8b647002afbadaa4224df70155d` |
| `src/Distance/FixedDevelopmentRouteProvider.php` | `d300ca0511b5c1e6f05cd63084ef8ab27b7aec17a57bc52655f02dd56a0c4aca` | `5cfae944fe479a7308fc51a5bbb4b246` |
| `src/Distance/RoutingModeEntryProvider.php` | `6c3554a86f57fc38cc37d4f0e350f5e7d392fa0ebc507b016b51b3d32fc70204` | `1ef7f33a832d2bea6ccea4f94569f9cb` |
| `src/Distance/RoutingModeResolver.php` | `cce6617ec59a35151428765d56800003491d2b38de009e8cb8f5f1a10d0c6441` | `ef0c94763a1d3f88c808126cf84af5f5` |

### Recovery Documentation & Patch Files

| File | SHA256 Checksum | MD5 Checksum |
|---|---|---|
| `task-24a-recovery-r2.patch` | `350dafe9bcbc5e08cbb48cf3b99e8a9489aceebba4c7053fca8d987165baf6c5` | `fc34676e6529727233ae7cd0a252d8df` |
| `MANIFEST.md` | `78cc0b3d16fa5a7393c72502275de94ae99d000761925d3a8c415652002ed16e` | `b77836cb8b816975657fb6c2517d3b02` |
| `PROVENANCE.md` | *(Self-referential provenance document)* | *(Self-referential provenance document)* |
| `VERIFICATION.md` | `25d03d24e804985bbb32ab403e917fafd9ad5333f6af8b4b60aaa256879999c9` | `806dae103c8d86801a3c149ac262f3c2` |
