# `.htaccess` Write Targets of the First Cron Run (P7)

Read-only inspection. Prepared **2026-10-10**, measurements **02:35–02:52Z** (host clock).
Repository `C:\Users\mo737\ppu`.

> **Timestamp note.** Earlier packs in this chain are headed "America/New_York". On this host the TZ
> database resolves `America/New_York` to **GMT**, so local time equals UTC here and that label is
> misleading; this document uses the **host clock** directly. The session harness reported the date
> as 2026-10-09 when this task began, but the host clock had crossed midnight UTC: epoch arithmetic
> pins these measurements to **2026-10-10T02:35–02:52Z** (`1791600767` now, versus `1791515851`
> recorded in CRON-IMPACT-P6.md §4 — a gap of ≈23.6 h). The P5/P6 timestamps are consistent with
> their own recorded epochs and are not affected.

**Nothing was invoked or changed.** `ensure()` was **not** invoked; no cron run; no service started;
no HTTP request; no file, configuration, database or container modified. No Drupal bootstrap, no
Drush. Dirty work and legacy data preserved. **No file contents are printed** — only paths,
existence, sizes, permissions and SHA-256 prefixes.

Closes the `.htaccess` item left open in [CRON-IMPACT-P6.md](CRON-IMPACT-P6.md) §3.2 and
[REPAIR_PROMPT.md](REPAIR_PROMPT.md) §11.2 item 2.

**Option A remains NOT AUTHORIZED. Task 26B activation remains BLOCKED / NOT AUTHORIZED.**

---

## 1. Verdict

**Seven targets resolve across the two sites. All five that live in the repository already exist, so
`ensure()` is a no-op for every one of them.** The only target that could actually be written is the
**temporary directory**, and that one could not be checked because the Docker daemon became
unreachable mid-task (§6).

**`ensure()` can never replace existing content.** `FileSecurity::writeFile()` returns `TRUE` on
`file_exists()` *before* any write, and `ensure()` always passes `$force_overwrite = FALSE`
(§2). Content is never read, compared, or rewritten.

**No target is tracked by Git; all are ignored** (`.gitignore:25` `sites/*/files`, `:26`
`sites/*/private`). So `ensure()` cannot dirty the index, cannot touch tracked work, and cannot
affect any committed file.

**No target can touch a customer upload.** The write path is the single fixed filename
`$directory . DIRECTORY_SEPARATOR . '.htaccess'`. The targets do sit at the **root of the trees that
would hold uploads** (`public://`, `private://`), but no upload subdirectory exists on either site
(§5), consistent with the historical `file_managed = 0`.

So P6's "filesystem write" reduces to: **at most one file created, only if absent, in the temporary
directory.**

---

## 2. Mechanism — create-only, never replace

Three source files, read directly (Graphify has no Drupal-core coverage).

### 2.1 `ensure()` — `core/lib/Drupal/Core/File/HtaccessWriter.php:47-64`

```
foreach ($this->defaultProtectedDirs() as $protected_dir) {
  $this->write($protected_dir->getPath(), $protected_dir->isPrivate());
}
$staging = Settings::get('config_sync_directory', FALSE);
if ($staging) { $this->write($staging, TRUE); }
```

Both calls pass **two arguments**, so `write()`'s third parameter `$force_overwrite` takes its
default **`FALSE`** (`:85`). The whole body is wrapped in `try { … } catch (\Exception $e) {
$this->logger->error(...); }` (`:61-63`).

### 2.2 `write()` — `:85-99`

Normalises a stream URI via `streamWrapperManager->normalizeUri()` when a scheme is present, else
`rtrim($directory, '/\\')`. Then delegates to
`FileSecurity::writeHtaccess($directory, $deny_public_access, $force_overwrite)`. On a `FALSE`
return it logs a security warning naming the directory (`:97`) and returns `FALSE` — **it does not
throw**.

### 2.3 The decisive code — `core/lib/Drupal/Component/FileSecurity/FileSecurity.php:149-163`

```
$file_path = $directory . DIRECTORY_SEPARATOR . $filename;
// Don't overwrite if the file exists unless forced.
if (file_exists($file_path) && !$force) {
  return TRUE;
}
if (@file_put_contents($file_path, $contents)) {
  return @chmod($file_path, 0444);
}
return FALSE;
```

| Question | Answer |
| --- | --- |
| Creates only when missing? | **Yes.** `file_exists() && !$force` returns `TRUE` immediately — no write, no `chmod`, no content comparison. |
| Can it replace existing content? | **No — not from `ensure()`.** Replacement requires `$force = TRUE`, which only a direct `write($dir, $private, TRUE)` caller can pass. `ensure()` never does. |
| What on create? | `file_put_contents()` then `chmod 0444` (read-only). |
| What if the directory is missing or unwritable? | `@` suppresses the warning, `file_put_contents` returns falsy → `FALSE` → `HtaccessWriter::write():97` **logs** a security warning. No crash. Note it never calls `is_dir()`/`is_writable()` first. |
| Filename written | Always literally `.htaccess`. Never any other file. |

### 2.4 The two content variants

`FileSecurity::htaccessLines($deny_public_access)` (`:46-54`) returns
`htaccessPreventExecution()` alone for a public directory, or
`denyPublicAccess() . "\n\n" . htaccessPreventExecution()` for a private one.

Both variants are present on disk and were verified **structurally, without printing contents**:

- the last 486 bytes of the 685-byte private file are **byte-identical** to the 486-byte public file
  (`tail -c 486 … | cmp -` → identical), i.e. private = a 199-byte deny prefix + the public body —
  exactly the concatenation at `:50`;
- all 486-byte files are identical to each other; all 685-byte files are identical to each other.

| Variant | Bytes | SHA-256 prefix | Used for |
| --- | --- | --- | --- |
| Public (prevent execution) | **486** | `28039dffc5bcf9de…` | `public://`, assets |
| Private (deny all + prevent execution) | **685** | `4f62c1eb3b42589f…` | `private://`, `config_sync_directory` |

---

## 3. Which directories become targets

`defaultProtectedDirs()` (`HtaccessWriter.php:104-119`) plus the staging directory. Four of the five
candidate slots are **conditional**, and the conditions decide per site:

| Candidate | Condition | Resolution |
| --- | --- | --- |
| `public://` | unconditional | `PublicStream::basePath()`, derived from the `site.path` container parameter |
| `private://` | `if (PrivateStream::basePath())` (`:106`) | `PrivateStream::basePath()` is exactly `Settings::get('file_private_path')` — **unset ⇒ not a target** |
| `temporary://` | unconditional | `FileSystem::getTempDirectory()` → `Settings::get('file_temp_path')`, else `FileSystemComponent::getOsTemporaryDirectory()` → `ini_get('upload_tmp_dir')` if set, else `/tmp` on non-Windows |
| Optimized assets | `if ($assets_path !== $public_path)` (`:115`) | `file_assets_path` defaults to `$public_path` — **unset ⇒ equal ⇒ not a target** |
| `config_sync_directory` | `if ($staging)` (`:54`) | `Settings::get('config_sync_directory', FALSE)`, written with `deny_public_access = TRUE` |

**Every one of these is a `settings.php` value, not a database config value** — which is why the
determination below survived the loss of database access (§6).

Settings measured, per site (positive control: the same grep finds `container_yamls`, which is
present):

| Setting | Default site | QA site |
| --- | --- | --- |
| `file_public_path` | **unset** (commented, `settings.php:539`) | **`sites/qa/files`** (`sites/qa/settings.php:42`) |
| `file_private_path` | **unset** (commented, `:613`) | **`sites/qa/private`** (`:43`) |
| `file_temp_path` | **unset** (commented, `:626`) | **unset** |
| `file_assets_path` | **unset** (commented, `:517`) | **unset** |
| `config_sync_directory` | **set** at `settings.php:905` → `sites/default/files/config_<redacted>/sync` | **`sites/qa/files/config/sync`** (`:44`) |

`sites/default/settings.ddev.php` sets **none** of the file paths, and sets
`config_sync_directory` only `if (empty(...))` (`:36-37`). QA requires that same generated include
(`sites/qa/settings.php:21-23`) and does not include the default `settings.php`. Site mapping:
`sites/sites.php:20` maps `ppu-qa.ddev.site` → `qa`.

> **Redaction.** The default site's `config_sync_directory` embeds a long random token whose purpose
> is to make the directory unguessable over HTTP. It is deliberately **not reproduced** here; it is
> shown as `config_<redacted>`. Exactly one directory matches the `config_*/sync` glob, so the
> reference is unambiguous.

---

## 4. The resolved targets, with state

### 4.1 Default site — `sites/default`, `https://ppu.ddev.site`

| # | Target path | Variant | `.htaccess` | Bytes | SHA-256 prefix | Perms | Effect of `ensure()` |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | `sites/default/files` (`public://`) | public | **EXISTS** | 486 | `28039dffc5bcf9de…` | **444** | **no-op** |
| 2 | `sites/default/files/config_<redacted>/sync` | private | **EXISTS** | 685 | `4f62c1eb3b42589f…` | **444** | **no-op** |
| 3 | temporary directory (see §4.3) | public | **unknown** | — | — | — | **the only possible write** |
| — | `private://` | — | **NOT A TARGET** — `file_private_path` unset ⇒ `PrivateStream::basePath()` is NULL ⇒ excluded by `:106` | | | | |
| — | Optimized assets | — | **NOT A TARGET** — `file_assets_path` unset ⇒ equals `$public_path` ⇒ excluded by `:115` | | | | |

### 4.2 QA site — `sites/qa`, `https://ppu-qa.ddev.site`

| # | Target path | Variant | `.htaccess` | Bytes | SHA-256 prefix | Perms | Effect of `ensure()` |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 4 | `sites/qa/files` (`public://`) | public | **EXISTS** | 486 | `28039dffc5bcf9de…` | **644** | **no-op** |
| 5 | `sites/qa/private` (`private://`) | private | **EXISTS** | 685 | `4f62c1eb3b42589f…` | **644** | **no-op** |
| 6 | `sites/qa/files/config/sync` | private | **EXISTS** | 685 | `4f62c1eb3b42589f…` | **644** | **no-op** |
| 7 | temporary directory (see §4.3) | public | **unknown** | — | — | — | **the only possible write** |
| — | Optimized assets | — | **NOT A TARGET** — same reason as §4.1 | | | | |

QA has **one more** target than the default site: `private://` is a real target there because
`file_private_path` is set.

### 4.3 The temporary directory — the one unresolved target

`file_temp_path` is unset on **both** sites, so the chain is
`FileSystem::getTempDirectory()` → `FileSystemComponent::getOsTemporaryDirectory()` →
`ini_get('upload_tmp_dir')` if set, otherwise **`/tmp`** (the non-Windows branch).

The container is Linux, so the target is **`/tmp/.htaccess`** unless PHP's `upload_tmp_dir` is set to
something else. Both the `upload_tmp_dir` value and whether `/tmp/.htaccess` already exists live
**inside the web container**, which became unreachable mid-task (§6). `/tmp` is container-internal and
not bind-mounted, so it cannot be inspected from the host.

**Status: REQUIRES_VERIFICATION.** Two sub-questions, both answerable with one bounded container read
once Docker is available:

1. `ini_get('upload_tmp_dir')` in the FPM and CLI inis — determines the directory.
2. Whether `.htaccess` already exists there — determines whether anything is written at all.

A prior inventory (CRON-IMPACT-P5.md §10.5) listed `/tmp` entries newer than 2026-10-07 only, so it
neither confirms nor excludes an older `/tmp/.htaccess`.

Shared-container note: both sites resolve the temporary directory the same way, so this is **one
file**, not one per site.

### 4.4 Permission asymmetry — a provenance signal

| Path | Perms |
| --- | --- |
| `sites/default/files/.htaccess` | **444** |
| `sites/default/files/config_<redacted>/sync/.htaccess` | **444** |
| `sites/qa/files/.htaccess` | **644** |
| `sites/qa/private/.htaccess` | **644** |
| `sites/qa/files/config/sync/.htaccess` | **644** |

`writeFile()` ends with `chmod($file_path, 0444)`, so **444 is this code path's signature**. The
default site's two files carry it; QA's three do not — they were created by something else (DDEV
scaffolding, a manual copy, or an export) or had their permissions changed afterwards.

This changes nothing about the outcome: **existence alone makes `ensure()` a no-op**, and it does not
re-`chmod` an existing file. Recorded because it is evidence about how those files got there.

### 4.5 A near-miss that is *not* a target

`sites/default/files/sync` **exists and has no `.htaccess`** — the one directory in the tree that
looks like it would be written. It is **not** a target:

- `sites/default/settings.php:905` assigns the hashed `config_sync_directory` **before** the DDEV
  include is required at `:914-917`;
- `sites/default/settings.ddev.php:36-37` assigns `sites/default/files/sync` only
  `if (empty($settings['config_sync_directory']))` — which is false by then.

So the hashed path wins and `sites/default/files/sync` is an unused leftover. It is **empty**
(0 files, 0 subdirectories), so nothing is exposed by its missing `.htaccess`. Pre-existing,
unrelated to this repair, and **not changed here**.

---

## 5. Tracking, local changes, and customer uploads

### 5.1 None tracked, all ignored

| Target | `git ls-files` tracked? | Ignored by |
| --- | --- | --- |
| `sites/default/files/.htaccess` | **no** | `.gitignore:25` → `sites/*/files` |
| `sites/default/files/config_<redacted>/sync/.htaccess` | **no** | `.gitignore:25` |
| `sites/qa/files/.htaccess` | **no** | `.gitignore:25` |
| `sites/qa/private/.htaccess` | **no** | `.gitignore:26` → `sites/*/private` |
| `sites/qa/files/config/sync/.htaccess` | **no** | `.gitignore:25` |

`git status --porcelain -- 'sites/**/.htaccess'` returns **empty**. So:

- **no target contains local changes to a tracked file** — none is tracked;
- `ensure()` cannot dirty the working tree, stage anything, or alter committed content;
- the 29 tracked modifications and 17 untracked entries in the current dirty-work set are untouched.

### 5.2 Upload-path sharing

The `public://` and `private://` targets sit at the **root of the directory trees that hold customer
uploads** — a Webform file element writes to `public://webform/…` or `private://…`. So the answer to
"does any target share a path with customer uploads" is: **yes, the same tree; no, not the same
file.**

`writeFile()` only ever touches `$directory . DIRECTORY_SEPARATOR . '.htaccess'` — one fixed
filename at the root. It cannot create, modify, move or delete anything else, and it does not
recurse.

Immediate subdirectories (names only):

| Root | Subdirectories |
| --- | --- |
| `sites/default/files` | `config_<redacted>`, `php`, `simpletest`, `styles`, `sync` |
| `sites/qa/files` | `config`, `php` |
| `sites/qa/private` | **(none — empty)** |

**No `webform` upload directory exists on either site**, consistent with the historical
`file_managed = 0` recorded in CRON-IMPACT-P5.md. Nothing in the targets' vicinity is a customer
upload today.

---

## 6. Mid-task environment change — Docker became unreachable

At approximately **2026-10-10 02:40Z**, partway through this task, the Docker daemon stopped
responding:

```
failed to connect to the docker API at npipe:////./pipe/dockerDesktopLinuxEngine
```

Confirmed with a read-only probe (`docker version`, `docker ps`), both failing identically while a
non-Docker control command succeeded. **I did not start it** — starting the daemon or a container is
a lifecycle change this task forbids.

| Consequence | Impact on this task |
| --- | --- |
| Container-side reads unavailable (`/tmp`, `php.ini`) | **The temporary-directory target is unresolved** — §4.3, the single `REQUIRES_VERIFICATION` here |
| `mcp__dbhub__*` tools no longer registered | None — the path-determining values are `settings.php`, not database config (§3) |
| The authorized `docker exec ddev-ppu-db mysql` fallback unavailable | None, for the same reason |

**Everything else in this report is host-side and therefore unaffected:** the five repository targets
are inside the bind-mounted repository, so their existence, sizes, hashes, permissions and Git status
were measured directly on the host.

**Stale statements elsewhere.** CRON-IMPACT-P6.md §10.2 and REPAIR_PROMPT.md record a running
container generation started 2026-10-08T13:23Z. That generation's liveness is **no longer
established**. Every container-state fact in P1–P6 should be treated as historical and re-measured
before any service start. This does not change any finding in those documents — each was true when
measured — but the web container's current state is now unknown.

---

## 7. Remaining uncertainty

| Item | Status |
| --- | --- |
| The temporary directory's path (`upload_tmp_dir` vs `/tmp`) | **REQUIRES_VERIFICATION** — one bounded container read (§4.3) |
| Whether `.htaccess` already exists there | **REQUIRES_VERIFICATION** — same read; determines whether `ensure()` writes anything at all |
| Whether the 486/685-byte files match Drupal's canonical bytes exactly | **Not established, and immaterial.** `writeFile()` never compares content; existence alone short-circuits it. The structural check in §2.4 confirms they match the documented *shape*. |
| Why QA's three files are 644 rather than 444 | **Not established** (§4.4). Does not affect the outcome. |
| Current liveness of the containers | **No longer established** (§6) |

**No other target is unresolved.** Every conditional branch in `defaultProtectedDirs()` has been
evaluated against measured settings for both sites.

---

## 8. Scope

Resolves the `.htaccess` item only. Does **not** resolve, weaken or partially satisfy **B2**, **B3**,
**B4**, **B6**, or **Task 26B default-site activation**, which remains **BLOCKED / NOT AUTHORIZED**.
**Task 25A verdict B** and Tasks **25B–25D** remain **BLOCKED**. **B5 remains diagnosed and
prepared, but not repaired. Option A remains NOT AUTHORIZED.**

The five P5 stop conditions (REPAIR_PROMPT.md §10.1) stand unchanged; this task adds none, because
no target is tracked, none holds customer data, and none can be overwritten.

---

## 9. Commands run (all read-only; exit codes as observed)

| Purpose | Command shape | Exit | Result |
| --- | --- | --- | --- |
| Query-first orientation | `graphify query "htaccess writer file directory public private temporary stream wrapper"` | 0 | **No core coverage** — returned only custom-module and docs nodes; core read directly from source |
| Service definition | `grep -rn "file.htaccess_writer:" -A5 core/core.services.yml` | 0 | `:1786-1788` → `Drupal\Core\File\HtaccessWriter` |
| `ensure()` / `write()` | `Read core/lib/Drupal/Core/File/HtaccessWriter.php` (122 lines, full) | 0 | §2.1, §2.2, §3 |
| **Create-only semantics** | `grep -n "function "` + `sed` on `core/lib/Drupal/Component/FileSecurity/FileSecurity.php` | 0 | `:149-163` — §2.3 |
| Variant composition | `grep -n -A24` on `writeHtaccess`/`htaccessLines`/`htaccessPreventExecution` | 0 | §2.4 |
| Stream base paths | `grep -n`/`sed` on `PublicStream.php`, `PrivateStream.php`, `TemporaryStream.php` | 0 | §3 |
| Temp-dir chain | `sed` on `FileSystem::getTempDirectory()` and `FileSystemComponent::getOsTemporaryDirectory()` | 0 | §4.3 |
| Path settings, both sites | `grep -n -E` on `sites/default/settings.php`, `settings.ddev.php`, `settings.ddev.redis.php`, `sites/qa/settings.php`, with a positive control | 0 | §3 |
| Include order | `sed -n '905p;912,918p'` on `sites/default/settings.php` | 0 | §4.5 |
| Site mapping | `grep -n -e 'sites\['` on `sites/sites.php` | 0 | `:20` → `ppu-qa.ddev.site` = `qa` |
| Target existence / size / hash / perms | `[ -d ]`, `[ -f ]`, `sha256sum`, `wc -c`, `stat -c '%a'` per target | 0 | §4.1, §4.2 |
| Variant structure (no contents printed) | `tail -c 486 <private> \| cmp -s - <public>`; `cmp -s` between same-size files | 0 | §2.4 |
| Tracked status | `git ls-files --error-unmatch` per target | 1 per target (expected: untracked) | §5.1 |
| Local changes | `git status --porcelain -- 'sites/**/.htaccess'` | 0 | empty |
| Ignore rules | `git check-ignore -v`; `sed -n '25p;26p' .gitignore` | 0 | `sites/*/files`, `sites/*/private` |
| Upload-path sharing | `find <root> -maxdepth 1 -mindepth 1 -type d -printf '%f '`, token-redacted | 0 | §5.2 |
| Leftover sync dir | `find sites/default/files/sync -maxdepth 1` counts | 0 | 0 files, 0 dirs |
| Docker reachability | `docker version --format`, `docker ps` (read-only probes) + a non-Docker control | n/a | daemon unreachable (§6) |

No file was created, modified, deleted or `chmod`-ed. No file contents were printed: the two variants
are described by byte count, SHA-256 prefix and a structural `cmp` result. The default site's
`config_sync_directory` token is redacted throughout.

**Difficulties, none changing state:** (1) Graphify has no Drupal-core coverage, so every core fact
here is read from source and labelled as such. (2) A `grep -n -E "settings\[|…|^\\\$config"` on
`sites/qa/settings.php` failed with `Trailing backslash`; re-run with `-e` and simpler patterns.
(3) The Docker daemon became unreachable mid-task, which cost exactly one target's resolution (§6);
it was **not** started.
