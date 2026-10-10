# Pre-Repair Checks P3 — Completion of the Read-Only Prerequisites

Read-only inspection. Prepared 2026-10-09 (America/New_York), measurements at **02:35–02:50Z**.
Repository `C:\Users\mo737\ppu`.

**No repair was performed.** `/start.sh`, supervisord, nginx, PHP-FPM and Mailpit were **not**
started. No container was started, stopped, restarted, recreated, reloaded or signalled. No Drush,
no DDEV, no Drupal bootstrap, no request to any Drupal route. No application configuration edited,
no cache key deleted, no scratch artifact removed, no Option A or B performed, nothing committed.

Completes the pre-repair checks in [REPAIR_PROMPT.md](REPAIR_PROMPT.md) §3. Prior evidence:
[DIAGNOSIS.md](DIAGNOSIS.md) (26B-P1), [PRECHECK-P2.md](PRECHECK-P2.md) (26B-P2).

**Task 26B activation remains BLOCKED / NOT AUTHORIZED.**

---

## 1. Verdict

| Check | Result |
| --- | --- |
| **P1** — cached container still valid | **VALID, re-measured.** All five `expandEntry()` checks pass; the entry is the same physical key, unchanged, still **15 arguments**. |
| **P2** — can `docker exec -d ddev-ppu-web /start.sh` run as written? | **RESOLVED — YES.** It runs as uid 1000, which is what the script expects; every privilege, path, variable and binary it needs is present. No `-u root` required. |
| **P4** — active automated-cron interval | **RESOLVED — and the proposal's premise was wrong.** `automated_cron` is **enabled on both sites** with interval **10800 s (3 h)**, **not 0**, and is overdue by **19.4+ days**, so cron **will fire on the first HTTP request**. Quantified data exposure is **zero** on the two paths that could be measured; other modules' `hook_cron` is **not enumerated**. |
| **Item 4** — nothing changed since P2 | **CONFIRMED.** Same container generation, same Redis process, cache neither invalidated nor replaced, all three source fingerprints identical on host **and** in the container. |

**Conclusion: Option A is technically prepared for a separate explicit authorization** — P1 is not
stale, P2 and P4 are resolved, and the command's requirements are established. It is **not
authorized**, and §6 records one newly discovered effect (**P5**) the authorizer must accept or
mitigate first, because the proposal's own effects analysis assumed the opposite.

---

## 2. P1 re-measured — valid

Gate and method exactly as documented in [PRECHECK-P2.md](PRECHECK-P2.md) §3. A physically present
hash was **not** treated as proof.

| `expandEntry()` check | Measured 02:35Z | vs P2 | Result |
| --- | --- | --- | --- |
| 1 — entry present, `cid` non-empty | `type=hash`, `data` **565,625 B**, `cid` 202 B, `serialized=1`, `gz` empty | identical | **PASS** |
| 2 — `delayedDeletions` | per-request; empty on a cold read | identical | **PASS** |
| 3a — expiry | `expire = -1` = `Cache::PERMANENT` | identical | **PASS** |
| 3b — `valid` flag | `valid = 1` | identical | **PASS** |
| 4 — tag checksum | stored `checksum = 0`, `tags = [x-redis-bin:container]`; `EXISTS <prefix>:cachetags:x-redis-bin:container` → **0**, value empty ⇒ computed checksum **0** = stored **0** | identical | **PASS** |
| 5 — last-delete-all | marker **1789532352.349** (2026-09-16T04:19:12Z) vs `created` **1790877299.243** (2026-10-01T17:54:59Z) → `created < marker` is **False** | **both byte-identical to P2** | **PASS** |
| Payload argument count | **`value";a:15:`** | identical | **15 arguments** |

**Proof it is the same physical key, not a replacement:** Redis TTL moved **30,901,376 → 30,899,953**
(−1,423 s), matching the ~24 min of elapsed wall-clock between the P2 and P3 reads. A rewritten entry
would have reset the TTL and changed `created`; neither moved.

**Key identity re-verified** (all components of `getContainerCacheKey()` unchanged):

| Component | Current | Match |
| --- | --- | --- |
| `Drupal::VERSION` | `10.6.16` (`core/lib/Drupal.php:78`) | ✓ |
| `deployment_identifier` | not set — only a **commented** line, `sites/default/settings.php:299` | ✓ empty |
| `container_yamls` appends | `sites/default/settings.php:716`, then `settings.ddev.redis.php:16` and `:19` — same files, same lines, same order as the stored `a:3:{…}` | ✓ |

---

## 3. P2 resolved — the proposed command runs as written

Target command under review (**not executed**): `rtk docker exec -d ddev-ppu-web /start.sh`.

### 3.1 Identity

| Fact | Value |
| --- | --- |
| `Config.User` | `1000:1000` |
| What `docker exec` actually runs as | **uid 1000, gid 1000, `mo737`**, `HOME=/home/mo737` |
| Consequence | The command as written (no `-u`) runs as the **non-root** user — which is what `/start.sh` is built for: it calls `sudo` explicitly at `:20`, `:101`, `:103`, `:104`, and branches on `id -u` at `:73-77`. **No `-u root` is needed, and adding one would change behaviour.** |
| `sudo` | **present**, and **passwordless** (`sudo -n true` succeeds) |

### 3.2 Every hard requirement of `/start.sh`, checked

`/start.sh:3` sets `errexit nounset pipefail`, so **any** unmet requirement aborts the script before
`:151` ever reaches supervisord. Each was checked individually.

| Requirement | Line | Measured | Result |
| --- | --- | --- | --- |
| 12 env vars dereferenced **unguarded** under `set -u`: `PHP_DEFAULT_VERSION`, `DDEV_PHP_VERSION`, `TZ`, `DDEV_PROJECT_TYPE`, `DDEV_XDEBUG_ENABLED`, `TERMINUS_CACHE_DIR`, `HOSTNAME`, `CAROOT`, `VIRTUAL_HOST`, `DOCKER_IP`, `DDEV_WEBSERVER_TYPE`, `DDEV_PROJECT` | `:23,37,60,89,101,103,129,135,151` | **all 12 SET** — `docker exec` inherits the container's `Config.Env`. Presence only was read; **no values printed**. | **PASS** — `nounset` will not abort |
| `update-alternatives --set` (normally root-only) | `:31-32` | real root-owned binary, but `/var/lib/dpkg/alternatives` **writable** and `/etc/alternatives` **writable** by uid 1000 | **PASS** |
| Write `/etc/php/8.4/{fpm,cli}/conf.d` | `:46-47` | both **writable**; and `/mnt/ddev_config/php/*.ini` count is **0**, so the branch is skipped anyway | **PASS** |
| `rm -rf /etc/nginx/sites-enabled` + re-copy | `:52-53` | `/mnt/ddev_config/nginx_full` **exists**, `/etc/nginx` and `/etc/nginx/sites-enabled` **writable** | **PASS** — and see §5 (side effect) |
| Append to `/etc/apache2/envvars`, `a2enmod`/`a2enconf` | `:74-80` | `/etc/apache2` and `/etc/apache2/envvars` **writable** | **PASS** |
| `sudo cp /etc/skel` + `chown $HOME` | `:20` | gated on `[ ! -f ~/.gitconfig ]`; `~/.gitconfig` **present** ⇒ **block skipped** | **PASS** (not executed) |
| `ls /var/www/html` or exit 101 | `:98` | mount healthy — the healthcheck reports `/var/www/html:OK` every cycle | **PASS** |
| `sudo mkdir`/`chown` of global cache and `/var/lib/php` | `:101-104` | sudo NOPASSWD; `/var/lib/php` **writable** | **PASS** |
| `mkcert -install` and cert generation | `:129-135` | `mkcert` at `/usr/local/bin/mkcert`; `CAROOT` dir **exists + writable**; `rootCA.pem` **present**; `/etc/ssl/certs` **writable**; `master.crt` already present | **PASS** |
| `ddev_custom_init_scripts` | `:142` | `/functions.sh:3` sources only `${DDEV_WEB_ENTRYPOINT}/*.sh` = `/mnt/ddev_config/web-entrypoint.d/*.sh`; that directory contains **only `README.txt`** ⇒ **nothing sourced** | **PASS** (no project script re-run) |
| Logpipe setup | `:144-149` | `/var/tmp/logpipe` **already exists** (created by `/pre-start.sh`), so the block is **skipped** — see §3.3 | **PASS** |
| `exec supervisord -n -c /etc/supervisor/supervisord-${DDEV_WEBSERVER_TYPE}.conf` | `:151` | `supervisord` at `/usr/bin/supervisord`; the resolved conf file **exists** | **PASS** |
| nginx must bind **:80** as uid 1000 | runtime | **both** mechanisms present: `/usr/sbin/nginx` has `cap_net_bind_service=eip`, **and** `net.ipv4.ip_unprivileged_port_start=0` | **PASS** |
| Stale Oct-1 supervisor socket | `:12` | `rm -f /var/run/supervisor/supervisor.sock`; `/var/run/supervisor` **writable** ⇒ the stale socket is cleared by the script itself | **PASS** |
| Pre-existing supervisord | `:8-11` | `pkill -0 supervisord` sits in an `if` condition, exempt from `errexit`; no supervisord is running | **PASS** |

**No unmet requirement was found.** The command can run as written.

### 3.3 One mechanical consequence, and a correction to a worry

supervisord would be the **`docker exec` session's** process, not PID 1. I initially expected that to
break the fallback diagnostic in REPAIR_PROMPT §7 (read `docker logs` after a failed start), because
`/start.sh:144-149` is skipped — the FIFO already exists — so `/start.sh` never installs its
`cat < logpipe >/proc/1/fd/1` relay, and a detached exec discards its own stdout.

**That worry is wrong, and the supervisor configuration settles it:** `[program:nginx]`
(`supervisord-nginx-fpm.conf:4`) and `[program:php-fpm]` (`php-fpm.conf:1`) both set
`stdout_logfile=/var/tmp/logpipe` with `stdout_logfile_maxbytes=0` and `redirect_stderr=true`. PID 1
(`/pre-start.sh`) is already `cat`-ing that same FIFO to its own stdout, which **is** the docker log
stream. So nginx and PHP-FPM output reaches `docker logs` normally. **REPAIR_PROMPT §7's fallback
remains usable.**

Two narrower points stay **REQUIRES_VERIFICATION**: supervisord's *own* console output (as opposed to
its programs') may be lost to the detached session, and `conf.d/mailpit.conf` does not declare a
`stdout_logfile` in the lines inspected, so Mailpit's log destination is not established.

---

## 4. P4 resolved — and it contradicts the proposal's premise

REPAIR_PROMPT §3 P4 recorded: *"Task 19 evidence records interval `0` on the active site, which would
mean no HTTP-triggered cron — but that must be re-confirmed, not assumed."* It was right to flag it.

### 4.1 Measured active configuration

Method: no Drupal bootstrap, no Drush. First the file layer, then the active layer read directly from
each site's database with targeted single-row queries. Credentials were never typed or printed — the
`db` container authenticated from its own `~/.my.cnf`.

| Layer | Default site | QA site |
| --- | --- | --- |
| `$config['automated_cron.settings']` override in `settings.php` / `settings.ddev.php` / `settings.ddev.redis.php` / `sites/qa/settings.php` | **none** (0 mentions; grep positive-controlled against a known-present token) | **none** |
| `automated_cron` present in active `core.extension` | **YES — enabled** | **YES — enabled** |
| Active `automated_cron.settings` | `interval` = **10800** | `interval` = **10800** |
| `state` → `system.cron_last` | 1789828157 → **19.5 days ago** | 1789840293 → **19.4 days ago** |
| Overdue by | **19.4 days** past the 3 h interval | **19.2 days** |

**So automated cron is enabled, set to 3 hours, and overdue by nearly three weeks on both sites. It
will fire on the first HTTP request after PHP-FPM is restored**, on whichever site receives it.

### 4.2 Where the "interval 0" came from

`vendor/drush/drush/src/Boot/DrupalBoot8.php:203` sets
`$GLOBALS['config']['automated_cron.settings']['interval'] = 0;` during **Drush's own bootstrap**.
Any reading of this interval taken *through Drush* therefore reports **0** regardless of the stored
value. That is almost certainly the origin of the earlier "interval is 0" note, and it applies only
to Drush processes — **not** to the HTTP path, which is the one a repair re-opens. This is precisely
why the governing instruction required a method that does not bootstrap Drupal.

### 4.3 Quantified exposure — measured, not assumed

An immediate cron run matters only if it writes or sends. Both reachable paths were measured:

| Path | Finding | Exposure |
| --- | --- | --- |
| `custom_webform_handlers_cron()` (`custom_webform_handlers.module:85`) → `ReviewManager::expireLapsed()` (`ReviewManager.php:251-274`) | **It does write**: `$this->store->move($request->reviewId, $request->state, ReviewState::Expired)`, up to `$limit = 50` rows per run. Selection (`:307-319`) requires `state IN (expirable)` **AND** `expires_at IS NOT NULL` **AND** `expires_at <= now`; `mayExpire()` (`ReviewState.php:132-134`) is Submitted, UnderReview, Approved. | **0 rows.** Exact replica of the selection, run per site: default **0**, QA **0**. All expirable rows (default 2, QA 8) have **`expires_at IS NULL`**, and none has a past deadline. |
| `DispatchNotificationWorker` queue (`src/Plugin/QueueWorker/DispatchNotificationWorker.php`), drained by core cron | Dispatch notifications — a mail path, which matters because **Mailpit is down** (the other failing healthcheck) | **0 items queued** on both sites, so nothing would be drained and no mail sent from this queue |
| Popularity-recount enqueue in the same hook | Gated to a ~20 h window by a state timestamp | Would enqueue at most one item; no external effect |

So the *custom module's* immediate cron exposure measures to **zero writes and zero mail**. I record
this plainly because my first reading of the 10800 figure treated it as decisive; quantifying it
showed the custom-module impact is currently nil.

### 4.4 What is still not known about the cron run

Core cron invokes **every** enabled module's `hook_cron`, not only the custom module's. That set was
**not enumerated**, so the full blast radius of the first cron run is **REQUIRES_VERIFICATION**. Known
possibilities not assessed: other modules' queue workers, `update` status fetches (outbound HTTP),
`search` indexing, log pruning, and flood-table maintenance. On the **default** site any cron task
that instantiates `custom_webform_handlers.dispatch_checkout_manager` would additionally hit
`ArgumentCountError` from the 15-argument container; whether any does was not traced.

---

## 5. Item 4 — nothing has changed since P2

| Subject | P2 (2026-10-09 ~02:12Z) | P3 (2026-10-09 02:35Z) | Verdict |
| --- | --- | --- | --- |
| `ddev-ppu-web` | started 13:23:00.617Z, restarts 0, PID 690, `Path=/pre-start.sh`, unhealthy | **identical**; streak 32429 → 32429+ (still climbing) | unchanged |
| `ddev-ppu-db` | started 13:23:01.433Z, healthy | **identical**, PID 984 | unchanged |
| `ddev-ppu-redis` | started 13:23:02.072Z | **identical**, PID 1175 | unchanged |
| Redis process generation | `run_id bf4dbf315fd35cd3351d7af5a3abbd6c0a1a8b7a`, uptime 45,988 s | **same `run_id`**, uptime 47,543 s (+1,555 s ≈ the elapsed 26 min) | **same process — no restart** |
| Redis dataset | DBSIZE 19,433; `rdb_changes_since_last_save:0`; `evicted_keys:0`; `expired_keys:0` | **DBSIZE 19,433; changes 0; evicted 0; expired 0** | **no write, no eviction, no expiry** |
| `_redis_last_delete_all` (default 10.6.16) | 1789532352.349 | **1789532352.349** | **not bumped — cache not invalidated** |
| Entry `created` / size | 1790877299.243 / 565,625 B | **1790877299.243 / 565,625 B** | **not replaced** |
| `services.yml` | `920581e3…5263e5f` | **match, host *and* container** | unchanged |
| `DispatchCheckoutManager.php` | `b7251503…0a399e` | **match, host *and* container** | unchanged |
| `custom_webform_handlers.install` | `172d25b0…2669e4a236` | **match, host *and* container** | unchanged |

All three fingerprints equal the Task 26A §2.4 values, on the host **and** inside the container, so
there is no host/container divergence either.

**One side effect of Option A to disclose** (newly established, not previously recorded):
`/mnt/ddev_config/nginx_full` **exists**, so `/start.sh:52-53` will `rm -rf /etc/nginx/sites-enabled`
and re-copy the project's nginx config into the container. That is intended DDEV behaviour and
rewrites **container-internal** files only — no host file and no application configuration is
touched — but it is a write, not a pure service start.

---

## 6. P5 — the new prerequisite this check created

Not a defect in the command, and not a blocker under the governing criteria. It is a **correction to
the proposal's effects analysis**, which the authorizer must accept or mitigate before Option A runs:

> **REPAIR_PROMPT §4 Option A's effects table does not mention cron.** It was written on the
> assumption that `automated_cron` was effectively off. In fact restoring PHP-FPM causes a **full,
> 19-day-overdue core cron run on the first HTTP request** to whichever site is hit — on a shared
> container serving **both** the default and QA sites.

Measured consequences: **0** Review Request writes, **0** queued notifications drained, **0** mail
sent from the custom module's queue. Unmeasured: every other enabled module's `hook_cron` (§4.4).

Options for the authorizer, none of which this task performs or recommends as implemented:

1. **Accept** the cron run on the stated measurements, acknowledging §4.4's unenumerated remainder.
2. **Enumerate first** — a further read-only task listing every enabled module's `hook_cron` and
   queue worker, so the run's full effect is known before it happens.
3. **Mitigate** by setting the interval to 0 before restoring the service — but that is an
   **application-configuration edit**, which neither this task nor Option A is authorized to make,
   and it would need its own approval and its own preservation evidence.

---

## 7. Scope

These checks resolve prerequisites for **B5 only**. They do **not** resolve, weaken, or partially
satisfy **B2** (activated wiring set), **B3** (runtime atomicity and safe disposal after a failed
rollback), **B4** (backup/restore), **B6** (isolation target manifest), or **Task 26B default-site
activation**, which remains **BLOCKED / NOT AUTHORIZED**. **Task 25A verdict B** and Tasks
**25B–25D** remain unchanged and **BLOCKED**. B5 remains **diagnosed and prepared, but not
repaired**.

P1's validity is **generation-scoped**: it holds for the Redis process `run_id
bf4dbf31…` and the container generation started 2026-10-08T13:23Z. Every trigger listed in
[PRECHECK-P2.md](PRECHECK-P2.md) §7 still applies, so P1 must be re-measured again if that generation
changes before a repair.

---

## 8. Commands run (all read-only; exit codes as observed)

| Purpose | Command shape | Exit | Result |
| --- | --- | --- | --- |
| Query-first orientation | `graphify query …` | 0 | Established nothing; container runtime, supervisor config and Redis state are outside the graph's coverage |
| Container state ×3 | `rtk docker inspect … --format` | 0 | §5 |
| Redis generation / dataset | `rtk docker exec ddev-ppu-redis redis-cli INFO server\|persistence\|stats`, `DBSIZE` | 0 | §5 |
| **P1 re-measurement** | `redis-cli --scan --pattern '*:container:*'`, then `TYPE`, `TTL`, `HSTRLEN`, `HGET` of `expire`/`valid`/`tags`/`checksum`/`created`/`gz`/`serialized`, `EXISTS`/`GET` on the tag key, `GET` on `_redis_last_delete_all` | 0 | §2 — no full value printed |
| Argument count | `redis-cli HGET … data \| grep -aoE 'DispatchCheckoutManager.{0,95}' \| grep -aoE 'value";a:[0-9]+:'` | 0 | `value";a:15:` |
| Key identity | `grep` on `core/lib/Drupal.php`, `sites/default/settings.php`, `settings.ddev.redis.php` | 0 | §2 |
| Source fingerprints | `sha256sum` on host; `rtk docker exec ddev-ppu-web … sha256sum` | 0 | §5 |
| **P2** — exec identity | `rtk docker inspect --format 'Config.User'`; `rtk docker exec … id -u/-g/-un` | 0 | uid 1000 |
| **P2** — `/start.sh` | `rtk docker exec … cat -n /start.sh` (secret patterns redacted) | 0 | §3.2 |
| **P2** — privileges/paths | `rtk docker exec … bash -c` tests: `sudo -n true`, `-w` on 8 paths, `ls -l update-alternatives`, `getcap /usr/sbin/nginx`, `/proc/sys/net/ipv4/ip_unprivileged_port_start` | 0 | §3.2 |
| **P2** — env presence | `rtk docker exec … bash -c` loop over 12 names testing `${!v+x}` — **presence only, values never printed** | 0 | all SET |
| **P2** — branches | existence of `/mnt/ddev_config/{nginx_full,apache,php,.homeadditions,web-entrypoint.d}`, `ls` of `web-entrypoint.d`, `grep` of `ddev_custom_init_scripts` in `/functions.sh`, mkcert/`CAROOT` checks, supervisord conf + binary | 0 | §3.2 |
| **P2** — logging | `grep` on `supervisord-nginx-fpm.conf`, `php-fpm.conf`, `conf.d/mailpit.conf` | 0 | §3.3 |
| **P4** — file layer | `grep -c automated_cron` on four settings files, with a positive control | 0 | 0 mentions |
| **P4** — active layer | `rtk docker exec ddev-ppu-db bash -c 'mysql -N -s -D <db> -e "SELECT … WHERE collection=\"\" AND name=\"core.extension\"/\"automated_cron.settings\""'` and `key_value` `system.cron_last`; targeted single-row reads only | 0 | §4.1 |
| **P4** — exposure | `mysql` replica of the `lapsed()` criteria per site; `COUNT(*)` on `queue`; `GROUP BY state` on `custom_webform_handlers_review_request` | 0 | §4.3 |
| **P4** — code paths | `sed`/`grep` on `custom_webform_handlers.module:85`, `ReviewManager.php:251-319`, `ReviewState.php:132`, `DispatchNotificationWorker.php` | 0 | §4.3 |

Only `inspect`, `logs` (not needed this round) and read-only `exec` Docker verbs were used. Database
access was limited to the records needed for P4 — `config` rows `core.extension` and
`automated_cron.settings`, `key_value` row `system.cron_last`, and bounded aggregates over
`custom_webform_handlers_review_request` and `queue`. No credential was typed or printed; the `db`
container authenticated from its own `~/.my.cnf`. No environment-variable **values** were printed,
only SET/UNSET.

**Difficulties, with no state change:** (1) a `sh`-level `${!v+x}` indirection failed with
`Bad substitution`; re-run under `bash`. (2) The first db-auth probe assumed
`MYSQL_ROOT_PASSWORD`/`MARIADB_ROOT_PASSWORD` would be set — both are **UNSET**; the container
authenticates from `~/.my.cnf`, which also meant no secret had to be handled. (3) My first reading of
`interval = 10800` treated it as decisive against Option A; quantifying the exposure (§4.3) showed
the custom module's immediate impact is zero, and the finding is recorded at that calibration.
