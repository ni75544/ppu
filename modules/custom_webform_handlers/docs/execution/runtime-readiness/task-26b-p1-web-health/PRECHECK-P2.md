# Pre-check P2 — Default-Site Compiled-Container Cache Validity

Read-only inspection. Prepared 2026-10-09 (America/New_York). Repository `C:\Users\mo737\ppu`.

**No repair was performed.** `/start.sh`, supervisord, nginx, PHP-FPM and Mailpit were **not**
started. No container was restarted or recreated. No Drupal bootstrap, no Drush, no database access,
no DDEV invocation, no Drupal request, no configuration change, no cache key deleted, no install,
migration or cache rebuild, no code edit, no commit, no memory change.

Answers pre-check **P1** in [REPAIR_PROMPT.md](REPAIR_PROMPT.md) §3. Context:
[DIAGNOSIS.md](DIAGNOSIS.md) (Task 26B-P1),
[../task-26a-readiness/READINESS.md](../task-26a-readiness/READINESS.md) §2.2 and §10.3.

**Task 26B activation remains BLOCKED / NOT AUTHORIZED.**

---

## 1. Conclusion

**The default site's cached 15-argument service container is VALID and will be reused.** The
question is **resolved — not UNKNOWN.**

Restoring PHP-FPM would cause the first HTTP request to **load the cached 15-argument definition**
and fail with `ArgumentCountError` when the `hg` handler instantiates
`custom_webform_handlers.dispatch_checkout_manager` — the Task 25A symptom. It would **not** compile
a 16-argument container from the uncommitted checkout.

**REPAIR_PROMPT.md §2's dangerous branch is therefore REFUTED for the current state:** repairing B5
would **not** trigger the B2/B3-gated activation. §7 below records what would flip this, and §6
records what remains unverified.

Two things were **freshly measured, not inherited** from Task 26A: the stored argument counts
(default **15**, QA **16**) and every validity input below.

---

## 2. Timeline correction — the stack restarted again since Task 26B-P1

Task 26B-P1 recorded a whole-stack start at 02:14:30–02:14:31Z on 2026-10-08. **That is no longer the
current generation.**

| Container | StartedAt (fresh) | RestartCount | State |
| --- | --- | --- | --- |
| `ddev-ppu-web` | **2026-10-08T13:23:00.617Z** | 0 | running, **unhealthy**, streak **31445**, `Path=/pre-start.sh`, PID 690 |
| `ddev-ppu-db` | 2026-10-08T13:23:01.433Z | 0 | running, healthy |
| `ddev-ppu-redis` | **2026-10-08T13:23:02.072Z** | 0 | running |

Observation time: **2026-10-09T02:12Z**. Redis `uptime_in_seconds` independently corroborates the
13:23:02Z start.

Two consequences:

1. The survival question in this pre-check concerns the **13:23:02Z** Redis restart. The entry
   survived **both** restarts (§4).
2. The web container is in the **identical** broken state P1 diagnosed — same `/pre-start.sh` PID 1,
   same two failing checks. **P1's root cause reproduces across two independent whole-stack
   restarts**, which strengthens that diagnosis rather than changing it.

---

## 3. The exact lookup and validity path for this installation

A physically present hash is **not** proof of use. The path below is what actually decides, read from
the installed source.

### 3.1 How `cache.container` is bound here

`sites/default/settings.ddev.redis.php` (54 lines) sets, when `IS_DDEV_PROJECT` and the `redis`
extension are present:

| Setting | Value | Line |
| --- | --- | --- |
| `cache.container` class | **`\Drupal\redis\Cache\PhpRedis`**, via factory `@cache.backend.redis::get` | `:40-43` |
| `cache_tags_provider.container` | `Drupal\redis\Cache\RedisCacheTagsChecksum` | `:45-47` |
| `cache.backend.redis` | `Drupal\redis\Cache\CacheBackendFactory` with `@serialization.phpserialize` | `:36-38` |
| default backend for all bins | `cache.backend.redis` | `:7` |

`sites/default/settings.php:920-921` includes that file; `sites/qa/settings.php:30-31` includes the
**same** file and additionally sets `$settings['cache_prefix'] = 'qa_'` (`:39`). So the two sites
share one Redis connection and are separated **only by key prefix**.

### 3.2 The lookup

`core/lib/Drupal/Core/DrupalKernel.php:576` —
`$this->bootstrapContainer->get('cache.container')->get($this->getContainerCacheKey())`, with
`$allow_invalid` left at its default **FALSE**.

`initializeContainer()` reaches that only when the cache is permitted:
`if (empty($this->moduleList) && !$this->containerNeedsRebuild)` (`:39-40` of the method body). On a
fresh HTTP request both hold, so the cached definition **is** consulted.

### 3.3 The validity gate — `CacheBase::expandEntry()`

`modules/contrib/redis/src/Cache/CacheBase.php`. Five independent rejections; **all** must be
avoided:

1. `empty($values['cid'])` → return FALSE.
2. `in_array($values['cid'], $this->delayedDeletions)` → return FALSE (per-request only).
3. `$cache->valid = $cache->expire == Cache::PERMANENT || $cache->expire >= requestTime()`.
4. `if ($cache->valid && !$this->checksumProvider->isValid($cache->checksum, $cache->tags))` →
   `valid = FALSE`. The bin tag is stripped **after** this check, so it is included in it.
5. **`$last_delete_timestamp = $this->getLastDeleteAll(); if ($last_delete_timestamp &&
   ((float)$values['created']) < $last_delete_timestamp) return FALSE;`** — the timestamp
   invalidation the governing instruction warned about. `deleteAll()` (`:511-519`) only writes the
   `_redis_last_delete_all` marker; it deletes no keys.

Then `if (!$allow_invalid && !$cache->valid) return FALSE;`.

---

## 4. Measured inputs, and the gate evaluated

### 4.1 Namespace — default versus QA

Seven container-bin keys exist. The **default** site's prefix embeds the core version
(`drupal.redis.<version>..539018e…`); **QA's** prefix is the fixed `qa_`:

| Site | Kind | Prefix version | cid version | Type | Redis TTL |
| --- | --- | --- | --- | --- | --- |
| DEFAULT | marker | 10.6.15 | n/a | string | −1 |
| DEFAULT | entry | 10.6.15 | 10.6.15 | hash | 28,524,083 s |
| **DEFAULT** | **marker** | **10.6.16** | n/a | string | −1 |
| **DEFAULT** | **entry** | **10.6.16** | **10.6.16** | **hash** | **30,901,376 s** |
| QA | marker | fixed `qa_` | n/a | string | −1 |
| QA | entry | fixed `qa_` | 10.6.15 | hash | 28,519,517 s |
| QA | entry | fixed `qa_` | 10.6.16 | hash | 31,277,528 s |

Live core is **10.6.16** (`core/lib/Drupal.php:78`), so the bolded pair is the live default-site
namespace. The 10.6.15 keys are leftovers and are unreachable from the current version. Key names
were enumerated and classified **inside the container** to avoid transcription error; no key name is
reproduced here in full.

### 4.2 The live default-site entry, and the gate

| Gate input | Measured value | Check | Result |
| --- | --- | --- | --- |
| Entry presence | `type=hash`, `data` **565,625 B**, `serialized=1`, `gz` empty | 1 | **PASS** |
| `delayedDeletions` | per-request; empty on a cold request | 2 | **PASS** |
| `expire` | **−1** = `Cache::PERMANENT` | 3 | **PASS** — passes unconditionally, no clock dependence |
| `valid` | **1** | 3 | **PASS** |
| `tags` | `x-redis-bin:container` | 4 | see below |
| `checksum` | **0** | 4 | **PASS** — no `cachetags:x-redis-bin:container` key exists for the default prefix (the only tag keys present are three `…:cachetags:x-redis-bin:menu`). `RedisCacheTagsChecksum::getTagInvalidationCounts()` (`:88-94`) casts a missing key with `(int)` → **0**, and `CacheTagsChecksumTrait::calculateChecksum()` starts at `$checksum = 0`. Computed **0** equals stored **0**, so `isValid()` returns TRUE. Established from source, not assumed. |
| `created` | **1790877299.243** = **2026-10-01T17:54:59Z** | 5 | **PASS** |
| `_redis_last_delete_all` (default, 10.6.16) | **1789532352.349** = **2026-09-16T04:19:12Z** | 5 | **PASS** — `created < last_delete` is **False**; the entry is **1,344,947 s ≈ 15.6 days NEWER** than the marker, so it is *not* rejected |
| Redis-level TTL | 30,901,376 s ≈ **357 days** | — | the key will not lapse on its own |

**Continuity:** `created` = 2026-10-01T17:54:59Z matches Task 26A §2.3's recorded default-container
creation **to the second**, and the QA entry's `created` = 1791253451.811 = **2026-10-06T02:24:11Z**
matches 26A's QA figure to the second. These are the same two entries 26A examined, unchanged.

### 4.3 Key identity — the computed key will match the stored cid

`getContainerCacheKey()` (`core/lib/Drupal/Core/DrupalKernel.php:906-909`) is
`service_container : environment : VERSION : deployment_identifier : PHP_OS : serialize(container_yamls)`.

| Component | Stored in the cid | Current value | Match |
| --- | --- | --- | --- |
| literal | `service_container` | — | ✓ |
| environment | `prod` | `prod` for a normal HTTP request | ✓ |
| `Drupal::VERSION` | `10.6.16` | **10.6.16** | ✓ |
| `deployment_identifier` | **empty** | not set — the only occurrence is a **commented** line, `sites/default/settings.php:299` | ✓ |
| `PHP_OS` | `Linux` | Linux container | ✓ |
| `serialize(container_yamls)` | `a:3:{i:0;s:40:"/var/www/html/sites/default/services.yml";i:1;s:42:"modules/contrib/redis/example.services.yml";i:2;s:40:"modules/contrib/redis/redis.services.yml";}` | `settings.php:716` appends the site `services.yml` **unconditionally** (`$settings['container_yamls'][] = $app_root . '/' . $site_path . '/services.yml';`) and precedes the redis include at `:920-921`, which appends the two redis files at `settings.ddev.redis.php:16` and `:19` | ✓ same three entries, same order |

Recorded for completeness: **`/var/www/html/sites/default/services.yml` does not exist** (verified
inside the container). This is **harmless to the key**, because `getContainerCacheKey()` serializes
the *setting value*, not the filesystem — index 0 simply points at a missing file. It does not cause
a miss or a rebuild.

### 4.4 Argument counts — measured now, directly from the cached payloads

Extracted in-container; only the matched fragment was printed, never the 565 KB payload:

| Site | Fragment | Arguments |
| --- | --- | --- |
| **DEFAULT** (10.6.16) | `…DispatchCheckoutManager";s:9:"arguments";O:8:"stdClass":2:{…"value";a:15:{…` | **15** |
| QA (10.6.16) | `…DispatchCheckoutManager";s:9:"arguments";O:8:"stdClass":2:{…"value";a:16:{…` | **16** |

This independently reproduces Task 26A §2.1 without relying on it.

---

## 5. Redis restarted — why the data survived

| Fact | Value | Source |
| --- | --- | --- |
| Data directory | `/data` on a **named Docker volume** (`redis:/data`) | `.ddev/docker-compose.redis.yaml:18`, `CONFIG GET dir` |
| RDB snapshots | **`save 3600 1 300 100 60 10000`** — Redis's built-in default is in force because the project config file sets no `save` line | `CONFIG GET save`; `.ddev/redis/redis.conf` (13 lines) |
| AOF | **`appendonly no`** (`#appendonly no` is commented in the project config; the default applies) | `CONFIG GET appendonly`, `INFO persistence` `aof_enabled:0` |
| **Keys loaded from disk at startup** | **`rdb_last_load_keys_loaded:19433`**, `rdb_last_load_keys_expired:0`, `loading:0` | `INFO persistence` |
| Current key count | **`DBSIZE 19433`** — identical to the number loaded | `DBSIZE` |
| Writes since last save | **`rdb_changes_since_last_save:0`**, `rdb_last_bgsave_status:ok` | `INFO persistence` |
| Eviction policy / usage | `allkeys-lfu`, `maxmemory 2.00G`, **used 78.25 M = 3.8 %**, **`evicted_keys:0`**, `expired_keys:0` | `CONFIG GET`, `INFO memory`, `INFO stats` |

Reading: the dataset was **restored from the RDB on disk** at the 13:23:02Z start, and **nothing has
written to Redis since** — consistent with the web container serving no HTTP and no Drush having run.
The entry predates (2026-10-01) every recent save, so no unsaved-write window could have lost it.
`allkeys-lfu` *permits* evicting any key, but at 3.8 % of `maxmemory` with zero evictions recorded,
eviction has not occurred and is not currently pressing.

---

## 6. What is NOT established

| Item | Status |
| --- | --- |
| That the first request actually raises `ArgumentCountError` rather than some other failure | **REQUIRES_VERIFICATION** — source-reasoned from the 15-argument definition and Task 25A's report; no request was made, and making one requires PHP-FPM, which this task must not start |
| Whether that error path could itself invalidate the container cache | **REQUIRES_VERIFICATION** — nothing in the read path (`DrupalKernel:576` → `expandEntry`) writes or flushes, but the full error-handling path was not exhaustively traced |
| What started the stack at 13:23:00Z (host boot, Docker Desktop restore, or manual) | **NOT ESTABLISHED** — outside read-only container inspection, as in Task 26B-P1 §4.3. Does not affect this conclusion |
| Whether a future start of `/start.sh` succeeds | **REQUIRES_VERIFICATION** — unchanged from Task 26B-P1 |
| Whether the 10.6.15 leftover keys serve any purpose | Not investigated; they are unreachable from core 10.6.16 and were not touched |

No Drupal bootstrap was used to resolve any of these, as instructed.

---

## 7. Validity is current, not durable — re-run this pre-check immediately before any repair

Each of the following flips the answer from "reuses 15 arguments" to "rebuilds 16 arguments from the
uncommitted checkout", which would make a B5 repair an **activation event** gated by **B2** and
**B3**:

| Trigger | Effect on the gate |
| --- | --- |
| Any `drush cr`, or any `drupal_flush_all_caches()` — including the one `updatedb` runs by default (see `../task-26a-readiness/READINESS.md` §10.2) | Bumps `_redis_last_delete_all` past `created` → check 5 rejects → rebuild |
| A Redis `FLUSHALL`/`FLUSHDB`, or a restart after the RDB is replaced | Entry gone → miss → rebuild |
| Eviction under memory pressure (`allkeys-lfu` permits any key) | Entry gone → miss → rebuild |
| A core version change | Both the prefix and the cid embed `10.6.16` → different key → miss → rebuild |
| Setting `deployment_identifier`, or changing `container_yamls` | Different cid → miss → rebuild |
| A cache-tag invalidation creating `cachetags:x-redis-bin:container` for the default prefix | Checksum mismatch → check 4 fails → rebuild |

**Operational consequence:** treat this result as valid **as of 2026-10-09T02:12Z for the current
container generation only**, and re-measure §4.2 and §4.4 immediately before executing any repair
option.

---

## 8. Scope

Answering P1 resolves **one input** to the B5 repair decision. It does **not** resolve, weaken, or
partially satisfy **B2** (activated wiring set), **B3** (runtime atomicity and safe disposal after a
failed rollback), **B4** (backup/restore), **B6** (isolation target manifest), or **Task 26B
default-site activation**, which remains **BLOCKED / NOT AUTHORIZED**. Task **25A verdict B** and
Tasks **25B–25D** remain unchanged and **BLOCKED**. B5 itself remains **diagnosed but not
repaired**.

---

## 9. Commands run (all read-only; exit codes as observed)

| Purpose | Command shape | Exit | Result |
| --- | --- | --- | --- |
| Query-first orientation | `graphify query …` | 0 | Established nothing; Redis runtime state and `modules/contrib` are outside the graph's useful coverage here |
| Redis persistence config | `rtk read .ddev/docker-compose.redis.yaml` (filtered); `grep` on `.ddev/redis/redis.conf` | 0 | named volume `redis:/data`; no `save` line; `#appendonly no`; `maxmemory 2048mb`; `allkeys-lfu` |
| Live persistence / memory / stats | `rtk docker exec ddev-ppu-redis redis-cli CONFIG GET …`, `INFO persistence`, `INFO server`, `INFO memory`, `INFO stats`, `DBSIZE` | 0 | §5 |
| Fresh container state | `rtk docker inspect` ×3 (format-filtered) | 0 | §2 |
| Cache wiring | `grep` on `sites/default/settings.php`, `sites/qa/settings.php`, `sites/default/settings.ddev.redis.php` (credential patterns redacted) | 0 | §3.1 |
| Lookup path | `grep`/`sed` on `core/lib/Drupal/Core/DrupalKernel.php` | 0 | §3.2 |
| Validity gate | `sed` on `modules/contrib/redis/src/Cache/CacheBase.php` | 0 | §3.3 |
| Checksum semantics | `grep`/`sed` on `modules/contrib/redis/src/Cache/RedisCacheTagsChecksum.php`, `core/lib/Drupal/Core/Cache/CacheTagsChecksumTrait.php` | 0 | §4.2 |
| Key enumeration + metadata | `redis-cli --scan --pattern '*:container:*'`, then per key `TYPE`, `TTL`, `HKEYS`, `HGET` of `cid`/`created`/`expire`/`valid`/`tags`/`checksum`/`gz`, `HSTRLEN data` | 0 | §4.1, §4.2 |
| Invalidation markers | `redis-cli GET` on the `_redis_last_delete_all` keys; `--scan --pattern '*x-redis-bin*'` | 0 | §4.2 |
| cid components | in-container `grep -o` on the key name for `*.yml` paths and ordered `i:N;s:L:"…"` entries | 0 | §4.3 |
| Argument counts | in-container `redis-cli HGET … data \| grep -aoE 'DispatchCheckoutManager.{0,90}'` — only the matched fragment printed | 0 | §4.4 |
| cid path existence | `rtk docker exec ddev-ppu-web bash -c 'for p in …; do [ -f "$p" ]; done'` | 0 | §4.3 |

Only `inspect` and read-only `exec` Docker verbs were used. **No** `GET`/`HGET` output was printed in
full; values were reduced to field metadata, sizes, timestamps, or a single matched fragment. No
unrelated key's contents were read. No Drush, no Drupal bootstrap, no database, no DDEV, and nothing
that starts or recreates the project.

**Difficulties, with no state change:** (1) an early prefix was reconstructed by hand from truncated
scan output and resolved to a non-existent key (`TYPE=none`, `TTL=-2`); corrected by letting the
container enumerate and classify keys itself. (2) An `awk strftime` conversion printed **local** time
while a sibling `date -u` printed UTC, producing an apparent four-hour discrepancy; resolved by
computing both in UTC. (3) The first container-path existence check tested a container-absolute path
against the host root and reported a false MISSING; re-run inside the container, where the path is
genuinely absent — see §4.3. (4) A nested-quoting error mangled the first argument-count pattern,
returning no match; retried binary-safe with `grep -aoE`.
