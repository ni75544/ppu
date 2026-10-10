# Task 26B-P2 — Proposed Web-Container Health Repair

> **⚠️ PROPOSED — NOT AUTHORIZED FOR EXECUTION.**
> No command in this file has been run. Each option below changes runtime state and requires the
> explicit, named authorization in §6 before it may be executed. Nothing here is authorized by the
> diagnosis that produced it.

Prepared: 2026-10-08 (America/New_York). Derived from [DIAGNOSIS.md](DIAGNOSIS.md) (Task 26B-P1).
Repository `C:\Users\mo737\ppu`.

**This proposal addresses blocker B5 only.** It does not resolve, weaken, or partially satisfy **B2**
(activated wiring set), **B3** (runtime atomicity and safe disposal after a failed rollback), **B4**
(backup/restore), **B6** (isolation target manifest), or Task **26B default-site activation**. Task
**25A verdict B** and Tasks **25B–25D** remain unchanged and **BLOCKED**.

---

## 1. The single established cause

| Field | Value |
| --- | --- |
| Affected container | **`ddev-ppu-web`** (`6cd266577f75…`), image `ddev/ddev-webserver:v1.25.3-ppu-built` |
| Affected services | **`nginx`**, **`php-fpm`**, **`mailpit`** — all three supervisord-managed |
| Cause | **supervisord is not running**, so no managed program started. Nothing listens on **:80** or **:8025**; both healthchecks fail to connect (curl exit 7). |
| Mechanism | The container was started at 2026-10-08T02:14:30Z **without DDEV's `/start.sh` phase**. PID 1 is `/pre-start.sh`, DDEV's log-pipe helper, which by design starts no services and blocks forever. |
| Configuration paths involved | `/start.sh:151` (`exec /usr/bin/supervisord -n -c /etc/supervisor/supervisord-${DDEV_WEBSERVER_TYPE}.conf`) — never executed. `/etc/supervisor/supervisord-nginx-fpm.conf:4` (`[program:nginx]`), `/etc/supervisor/php-fpm.conf:1` (`[program:php-fpm]`), `/etc/supervisor/conf.d/mailpit.conf:1` (`[program:mailpit]`). Container command from `.ddev/.ddev-docker-compose-base.yaml:96`. |
| **Nothing is misconfigured** | The healthcheck targets, nginx `monitoring.conf`, and the PHP-FPM pool all agree, and this same configuration was healthy on 2026-10-01. **No configuration file needs to change.** |

Because nothing is misconfigured, the repair is **"start the services that were never started"** —
not an edit. That is why there is one repair family here and no speculative list.

---

## 2. Why this repair is riskier than it looks

Starting PHP-FPM makes the sites answer HTTP again. Per
[task-26a-readiness/READINESS.md](../task-26a-readiness/READINESS.md) §10.2, **the first ordinary HTTP
request is what compiles the Drupal service container.** Two possibilities, with very different
consequences:

| If the default site's compiled container is… | First request after repair… | Gate status |
| --- | --- | --- |
| **still cached as 15 arguments** | loads the stale definition and throws `ArgumentCountError` on the `hg` handler (the 25A symptom). No activation. | Safe with respect to B2/B3 |
| **absent** (cache lost) | compiles a **fresh 16-argument container from the uncommitted working tree** — which **is** the activation that **B2** and **B3** gate | **Would breach B2/B3** |

`ddev-ppu-redis` restarted at **02:14:31Z**, in the same second as the web container
(`RestartCount=0`, policy `no`). Whether its cache survived was **deliberately not probed** by the
diagnosis and is **NOT ESTABLISHED**.

> **Therefore: do not start PHP-FPM before pre-check P1 in §3.** If P1 shows the cached container is
> gone, then repairing B5 is itself an activation event and must wait for **B2 and B3**, not for this
> proposal.

This is the one place where a "just make the container healthy" instinct would quietly cross an
activation gate.

> **RESOLVED 2026-10-09 (Task 26B-P2) — see [PRECHECK-P2.md](PRECHECK-P2.md) and §8 below.**
> P1 has been answered: the default site's cached **15-argument** entry is **VALID and will be
> reused**, so the **second row of the table above does not apply to the current state** and
> repairing B5 would **not** breach B2/B3. The first row applies: the first request would load the
> 15-argument definition and raise `ArgumentCountError`.
> Two corrections to the paragraph above: the relevant Redis restart is **13:23:02Z**, not
> 02:14:31Z (the stack restarted again after the diagnosis), and the cache **did** survive —
> 19,433 keys were reloaded from the RDB on a named volume, with zero writes since.
> **The gate above still governs, with its condition inverted:** the result is valid **only for the
> current container generation**, so re-measure PRECHECK-P2.md §4.2 and §4.4 immediately before
> executing any option in §4. PRECHECK-P2.md §7 lists every trigger that would flip it back to a
> 16-argument rebuild — a `drush cr`, the default flush inside `updatedb`, a Redis flush, eviction,
> a core version change, or a `container_yamls`/`deployment_identifier` change.

---

## 3. Pre-repair checks (read-only; must pass before any option in §4)

| # | Check | Why | Authorization it needs | Status |
| --- | --- | --- | --- | --- |
| **P1** | Does the default site's `cache_container` entry still hold a **15-argument** definition for `custom_webform_handlers.dispatch_checkout_manager`? Read the Redis key directly, as Task 26A §2.2 did — **without bootstrapping Drupal**. | Decides whether repairing B5 would trigger the B2/B3-gated activation (§2). **This is the discriminating check.** | A bounded read-only Redis read inside the already-running `ddev-ppu-redis` or `ddev-ppu-web` container. No Drupal bootstrap, no Drush. | **RE-MEASURED 2026-10-09 02:35Z — VALID, still 15 arguments** ([PRE-REPAIR-P3.md](PRE-REPAIR-P3.md) §2; first answered in [PRECHECK-P2.md](PRECHECK-P2.md)). Same physical key — TTL aged, `created` and the marker unmoved. Still generation-scoped: re-measure again if the container/Redis generation changes. |
| **P2** | Can `/start.sh` run under the container's configured user (`1000:1000`), or does it require root? | Determines whether Option A can work as written. `/start.sh:7-8` shows it is designed to be re-runnable (it kills a pre-existing supervisord), but its privilege requirements here are **REQUIRES_VERIFICATION**. | Read-only inspection of `/start.sh` and the container user. | **RESOLVED 2026-10-09 — YES, runs as written** ([PRE-REPAIR-P3.md](PRE-REPAIR-P3.md) §3). `docker exec` runs as uid 1000, which is what the script expects; sudo is NOPASSWD, all 12 unguarded `set -u` variables are inherited, every path it writes is writable, and nginx can bind :80 as non-root. **No `-u root`** — adding one would change behaviour. |
| **P3** | Would `ddev start` **recreate** `ddev-ppu-web` rather than start it? | Recreation discards the container's writable layer. This project has previously placed files via `docker cp` and has a recorded incident where a DDEV lifecycle command recreated `web`+`db` and re-broke a prior fix. | Cannot be established without running `ddev`; treat as **REQUIRES_VERIFICATION** and assume recreation is possible. | **STILL OPEN** — only reachable by running `ddev`, which remains unauthorized. Irrelevant unless Option A fails. |
| **P4** | Is Drupal's `automated_cron` interval still `0` on both sites? | Once HTTP serves again, an enabled automated cron could fire on the first request. Task 19 evidence records interval `0` on the active site, which would mean no HTTP-triggered cron — but that must be re-confirmed, not assumed. | Read-only config inspection (no bootstrap if read from exported config or the database directly). | **RESOLVED 2026-10-09 — and the premise above was WRONG** ([PRE-REPAIR-P3.md](PRE-REPAIR-P3.md) §4). `automated_cron` is **enabled on both sites**, interval **10800 s**, overdue **19.4+ days** ⇒ cron **fires on the first HTTP request**. The "interval 0" came from Drush overriding it in its own bootstrap (`DrupalBoot8.php:203`). Measured exposure: **0** review writes, **0** queued notifications. See the new **P5** in §9. |

If P1 cannot be answered, **stop**. Do not substitute a Drupal bootstrap for it — bootstrapping is
what the check exists to avoid.

> **Status 2026-10-09 (Task 26B-P3):** P1 re-measured valid, **P2 and P4 resolved**, and nothing has
> changed since P2 — same container generation, same Redis process (`run_id bf4dbf31…`), cache
> neither invalidated nor replaced, and all three source fingerprints identical on host **and** in
> the container. P3 stays open but is reachable only by running `ddev`. P4's answer added one new
> prerequisite, **P5** (§9).

---

## 4. Repair options, smallest first

### Option A — Start the services inside the existing container (smallest)

```bash
# PROPOSED — NOT AUTHORIZED FOR EXECUTION
rtk docker exec -d ddev-ppu-web /start.sh
```

| Aspect | Detail |
| --- | --- |
| Lifecycle impact | **No container restart, stop, or recreation.** The container keeps running; its writable layer and PID 1 are untouched. |
| What it changes | Launches supervisord, which starts `nginx`, `php-fpm`, `mailpit`. |
| **Added 2026-10-09 (P3):** it also rewrites container-internal nginx config | `/mnt/ddev_config/nginx_full` **exists**, so `/start.sh:52-53` runs `rm -rf /etc/nginx/sites-enabled` and re-copies the project's nginx config into the container. Intended DDEV behaviour; **container-internal only** — no host file and no application configuration is touched. But it is a write, not a pure service start. |
| **Added 2026-10-09 (P3):** it triggers an immediate, 19-day-overdue **core cron run** | This row was **missing** and the table was written assuming cron was effectively off. `automated_cron` is **enabled on both sites** with interval **10800 s**, last run **19.4+ days** ago, so cron fires on the **first HTTP request** to whichever site is hit. Measured exposure: **0** Review Request writes, **0** queued notifications, **0** mail from the custom module's queue. **Unmeasured:** every other enabled module's `hook_cron`. See [PRE-REPAIR-P3.md](PRE-REPAIR-P3.md) §4 and **P5** in §9. |
| Known unknowns | supervisord would run as a child of the exec session rather than as PID 1, so it would not reap orphans; PID 1's `trap … kill -- -1` should still clean up on container stop, but that is **REQUIRES_VERIFICATION**. Privilege requirements are P2. Whether `/start.sh` needs arguments or environment that DDEV supplies at invocation time is **REQUIRES_VERIFICATION**. **Narrowed 2026-10-09 (P3):** the environment question is **CLOSED** — all 12 variables `/start.sh` dereferences unguarded under `set -u` are inherited by `docker exec`, and it takes no arguments. Orphan reaping remains REQUIRES_VERIFICATION. Logging is **resolved and favourable**: nginx and php-fpm write `stdout_logfile=/var/tmp/logpipe` with `redirect_stderr=true`, and PID 1 relays that FIFO to the docker log stream, so §7's fallback diagnostic works under a detached exec. supervisord's *own* console output and Mailpit's log destination remain REQUIRES_VERIFICATION. |
| Effect on **default** site (`ppu`) | Becomes reachable over HTTP. **See §2 — this is the gated consequence.** |
| Effect on **QA** site (`ppu-qa`) | Also becomes reachable: both sites are a multisite sharing one codebase and this one web container. QA's own compiled container (16 arguments) would serve normally. |
| Effect on other shared services | `ddev-ppu-db` and `ddev-router` are currently **healthy** and are not restarted. The router's routes begin resolving once nginx listens, so both sites become reachable from the host browser. Mailpit resumes capturing local mail — an isolation improvement over the current state, where the sink is down. `ddev-ppu-redis`, `nominatim`, `osrm`, `selenium` are untouched. |
| Data risk | **None directly.** These are stateless daemons; no DDL, no schema change, no row write, no configuration edit. The risk is **exposure** (HTTP serving resumes), not corruption. **Corrected 2026-10-09 (P3): "no row write" was wrong as a blanket claim.** Starting the services is still write-free in itself, but the first HTTP request triggers an overdue core cron run, and `custom_webform_handlers_cron()` → `ReviewManager::expireLapsed()` **does** write (a compare-and-set to `Expired`, up to 50 rows). It would write **0 rows today** — every expirable Review Request has `expires_at IS NULL`, which the selection excludes — so the measured risk is nil, but it is nil **by current data**, not by design. Other modules' `hook_cron` is unmeasured. |
| Recovery | `rtk docker exec ddev-ppu-web supervisorctl shutdown` stops what was started and returns the container to the current, fully-understood broken state. Reversible with no data consequence. |

### Option B — DDEV's supported start (larger; use only if Option A fails)

```bash
# PROPOSED — NOT AUTHORIZED FOR EXECUTION — may RECREATE containers
ddev start
```

| Aspect | Detail |
| --- | --- |
| Lifecycle impact | **May recreate `ddev-ppu-web` and other project containers**, discarding writable layers (P3). Touches the whole project, not just web. |
| Why it is nonetheless the supported path | It runs DDEV's full start sequence, including the `/start.sh` phase, with the environment DDEV intends. |
| Effect on default / QA / shared | All project containers, including `db`, `router`, `redis`, `nominatim`, `osrm`, `selenium`. Both sites affected. Mutagen sync is re-established, which this project has previously seen break a `docker cp`-applied fix. |
| Data risk | No intentional database change, but recreation plus Mutagen re-sync is the project's recorded source of "stale container" and partial-sync incidents. Higher blast radius than Option A. |
| Recovery | No clean undo for a recreation. Requires the Task 26A §5.2 private backup to exist first. |

### Option C — `docker restart ddev-ppu-web` — **will NOT fix this**

Stated explicitly to pre-empt it: a restart re-runs the container's command, which is
`/pre-start.sh` (`.ddev-docker-compose-base.yaml:96`). That reproduces **exactly** the current state —
PID 1 blocking on `wait`, no supervisord, both checks failing. It would also reset the failing-streak
evidence. **Do not use it as a repair.**

---

## 5. Verification after repair (read-only)

Run in this order; stop on the first failure.

1. `rtk docker exec ddev-ppu-web bash -c 'supervisorctl status'` → `nginx`, `php-fpm`, `mailpit` all
   **RUNNING**.
2. `rtk docker exec ddev-ppu-web bash -c 'ss -lntp'` → listeners present on **:80** and **:8025**.
3. One bounded request per endpoint, exact healthcheck shape, 3 s timeout, max three attempts:
   `curl --fail -s --max-time 3 127.0.0.1/phpstatus` and `… 127.0.0.1:8025` → **exit 0** (was 7).
4. `rtk docker inspect ddev-ppu-web --format '{{.State.Health.Status}}|{{.State.Health.FailingStreak}}'`
   → `healthy|0`, and the last output becomes
   `/var/www/html:OK mailpit:OK phpstatus:OK` with exit 0. Secondary confirmation: `/tmp/healthy`
   exists and the check cadence drops from ≈1.5 s to ≈59 s (`/healthcheck.sh:18-21`).

**Do not verify by requesting a Drupal application route.** `/phpstatus` is served by PHP-FPM's
internal status handler (`pm.status_path`, pool `:258`) and bootstraps no Drupal code; a site route
would compile the service container and is exactly what §2 warns against. No test email may be sent.

---

## 6. Exact additional authorization required

Each line is separate. None is granted by this proposal or by the diagnosis.

1. **Pre-check P1:** authorization for a **bounded read-only Redis read** of the default site's
   compiled-container cache entry, with no Drupal bootstrap and no Drush.
   **— GRANTED and PERFORMED 2026-10-09 (Task 26B-P2); result in [PRECHECK-P2.md](PRECHECK-P2.md).
   A re-measurement immediately before any repair is still required under the same authorization.**
2. **Option A:** authorization to **start services inside the already-running `ddev-ppu-web`
   container** via one detached `docker exec /start.sh` — explicitly acknowledging that both the
   default and QA sites become HTTP-reachable as a result. **— NOT GRANTED.**
   **Technically prepared as of 2026-10-09 (Task 26B-P3):** the command runs **as written**, as uid
   1000, with no `-u root` and no argument. See [PRE-REPAIR-P3.md](PRE-REPAIR-P3.md) §3. Granting it
   now also means accepting **P5** below.
3. **Option B (only if A fails):** separate authorization for **`ddev start`, including possible
   container recreation** across the whole project, contingent on the Task 26A §5.2 private backup
   existing first. **— NOT GRANTED.**
4. **If P1 shows the cached container is absent:** authorization is **not** sufficient at the B5
   level. Restoring PHP-FPM would then be an **activation event**, and **B2** and **B3** must be
   satisfied first — per
   [task-26a-readiness/NEXT_EXECUTION_PROMPT.md](../task-26a-readiness/NEXT_EXECUTION_PROMPT.md) §6
   Gate 2. B3 requires **correctness evidence**, which a backup or a successful restore cannot
   supply.
   **— Condition NOT met as of 2026-10-09: the entry is present and valid, so this clause does not
   currently apply.** It remains live as a standing condition: if the re-measurement required by
   item 1 shows the entry absent or invalid, this clause takes effect and Option A must stop.
5. **P5 — accept or mitigate the immediate cron run (NEW, 2026-10-09).** Restoring PHP-FPM causes a
   **19-day-overdue core cron run on the first HTTP request**, on a container shared by both sites.
   This was **not** in the original effects analysis. Authorizing Option A requires one of:
   (a) **accepting** it on the measured exposure — 0 Review Request writes, 0 queued notifications;
   (b) a further **read-only enumeration** of every enabled module's `hook_cron` and queue worker
   first; or (c) **mitigation** by setting the interval to 0 beforehand, which is an
   **application-configuration edit** that neither this proposal nor Option A is authorized to make
   and would need its own approval and preservation evidence. **— NOT GRANTED; no option chosen.**
   **AUDITED 2026-10-09 (Tasks 26B-P5 and 26B-P6): option (b) is now COMPLETE** — the P5
   enumeration plus the P6 closure of its residual (§11). Effects are **understood**: **four**
   outbound calls and one `.htaccess` write, detail in §11.2. Original P5 text follows. —
   [CRON-IMPACT-P5.md](CRON-IMPACT-P5.md) enumerates all 68 enabled modules' cron surface. **Every
   protected-data path is clear, state-backed and control-verified** (no order, submission, review,
   reservation, payment, file or queued job is touched). **But the run is not effect-free:** three
   **outbound network calls** and a `watchdog` deletion will occur. The decision therefore stands and
   is now between (a) accepting the disclosed effects, (b-residual) closing the five unread core cron
   bodies in §5 of that audit, or (c) config mitigation. **— STILL NOT GRANTED; no option chosen.**

---

## 9. Correction record — Task 26B-P3, 2026-10-09 (America/New_York)

Scope of 26B-P3: **bounded read-only completion of the pre-repair checks**, plus this record and
[PRE-REPAIR-P3.md](PRE-REPAIR-P3.md). **No service started, no container lifecycle action, no Drush,
no DDEV, no Drupal bootstrap, no request to a Drupal route, no configuration edit, no scratch
artifact deleted, nothing committed.** §1–§7 are **preserved**; only check statuses and the effects
analysis are corrected.

| # | Original statement | Status | Correction |
| --- | --- | --- | --- |
| 1 | §3 P1 — "must be re-measured immediately before any repair" | **DONE** | Re-measured 02:35Z: all five `expandEntry()` checks pass, still **15 arguments**, and demonstrably the *same physical key* — TTL aged by the elapsed interval while `created` and `_redis_last_delete_all` did not move. |
| 2 | §3 P2 — **OPEN** | **RESOLVED** | The command runs **as written**. `docker exec` runs as uid 1000 (= `Config.User`), which is what `/start.sh` expects; sudo is NOPASSWD, all 12 unguarded `set -u` variables are inherited, every path it writes is writable, `update-alternatives` is usable, mkcert prerequisites are present, the supervisord conf exists, and nginx can bind :80 via **both** `cap_net_bind_service=eip` and `ip_unprivileged_port_start=0`. No unmet requirement found. |
| 3 | §3 P4 — "Task 19 evidence records interval `0` … must be re-confirmed, not assumed" | **RESOLVED — premise was WRONG** | `automated_cron` is **enabled on both sites**, interval **10800 s**, overdue **19.4+ days** ⇒ cron fires on the **first HTTP request**. The "0" is a **Drush artefact**: `DrupalBoot8.php:203` overrides the interval to 0 inside Drush's own bootstrap, so any reading taken through Drush reports 0 regardless of stored value. It does not apply to the HTTP path. |
| 4 | §4 Option A "Known unknowns" — environment/arguments unverified | **NARROWED** | Environment question **closed** (all variables inherited; no arguments taken). Logging resolved **favourably**: nginx and php-fpm log to `/var/tmp/logpipe`, which PID 1 relays to docker logs, so §7's fallback works under a detached exec — a concern I raised and then disproved. Orphan reaping, supervisord's own console output, and Mailpit's log destination remain REQUIRES_VERIFICATION. |
| 5 | §4 Option A "Data risk: **None directly** … no row write" | **CORRECTED** | Blanket claim withdrawn. The service start is write-free, but the cron it triggers reaches `ReviewManager::expireLapsed()`, which **does** write. It would write **0 rows today** because every expirable Review Request has `expires_at IS NULL` — nil **by current data**, not by design. |
| 6 | §4 Option A effects table — cron absent | **ROW ADDED** | Plus a second added row: `/start.sh:52-53` rewrites container-internal nginx config from `/mnt/ddev_config/nginx_full`, which exists. Container-internal only; no host or application config touched. |
| 7 | §3 P3 — **OPEN** | **STILL OPEN** | Reachable only by running `ddev`, which remains unauthorized. Irrelevant unless Option A fails. |

**Conclusion: Option A is technically prepared for a separate explicit authorization.** P1 is not
stale, P2 and P4 are resolved, and the command's requirements are established. **It is not
authorized**, and the authorizer must additionally settle **P5** (§6 item 5), because the proposal's
effects analysis was written on a premise that measurement has now disproved.

**Unchanged:** B5 remains **diagnosed and prepared, but not repaired**. **B2, B3, B4 and B6 are
untouched.** **Task 26B default-site activation remains BLOCKED / NOT AUTHORIZED.** **Task 25A
verdict B and Tasks 25B–25D remain BLOCKED.** P1's validity is scoped to Redis process `run_id
bf4dbf31…` and the container generation of 2026-10-08T13:23Z; [PRECHECK-P2.md](PRECHECK-P2.md) §7's
invalidation triggers all still apply.

## 7. If the cause were to change

The cause in §1 is established, so no alternative repair is proposed. If a future start attempt fails
for a different reason, the next discriminating observation is the **container log**: `/start.sh` runs
with its own output, so a failed startup would produce supervisord, nginx, or php-fpm lines where the
current log has only `/pre-start.sh`'s 10-line `set -x` trace. Read
`rtk docker logs --tail 40 ddev-ppu-web` (filtered, secrets redacted) before proposing anything else.

---

## 8. Correction record — Task 26B-P2, 2026-10-09 (America/New_York)

Scope of 26B-P2: **bounded read-only inspection** of the Redis service, its cache data and
invalidation metadata, and the installed Drupal Redis cache-backend source, plus this correction and
[PRECHECK-P2.md](PRECHECK-P2.md). **No service was started, no container restarted or recreated, no
cache key deleted, no Drupal bootstrap, no Drush, no database access, no DDEV invocation.** The
original proposal in §1–§7 is **preserved**; only the pre-check conclusion is corrected.

| # | Original statement | Status | Correction |
| --- | --- | --- | --- |
| 1 | §2: "Whether its cache survived was deliberately not probed and is **NOT ESTABLISHED**"; §3 P1 open | **RESOLVED** | The default site's cached **15-argument** entry is **present and VALID** against all five `CacheBase::expandEntry()` checks, and the computed cache key matches the stored cid. The first request after a repair would **reuse** it. |
| 2 | §2: the "absent → compiles a fresh 16-argument container → **would breach B2/B3**" row | **REFUTED for the current state** | Does not apply. The "still cached as 15 arguments" row applies: `ArgumentCountError`, no activation. Repairing B5 would **not** breach B2/B3. |
| 3 | §2: "`ddev-ppu-redis` restarted at **02:14:31Z**" | **SUPERSEDED** | The stack restarted **again** afterwards — web 13:23:00.617Z, db 13:23:01.433Z, redis 13:23:02.072Z on 2026-10-08, all `RestartCount=0`. The relevant restart is **13:23:02Z**. The entry survived both. |
| 4 | §6 item 4's conditional block on B2/B3 | **CONDITION NOT MET** | Retained as a standing condition, not deleted: it takes effect only if the required re-measurement finds the entry absent or invalid. |
| 5 | Implicit assumption that a present hash settles the question | **Explicitly rejected** | A present hash is not proof. Validity was established by tracing the gate (`expandEntry` checks 1–5), the tag checksum (`getTagInvalidationCounts()` casting a missing key to `0`), the key-identity components, and `initializeContainer()`'s cache-permitted branch. |

**Unchanged by this correction:** Task 26B-P1's root-cause diagnosis — which now **reproduces across
two independent whole-stack restarts** (web is still `/pre-start.sh`/unhealthy, streak 31445) — and
every blocker. **B5 remains diagnosed but not repaired. B2, B3, B4 and B6 are untouched. Task 26B
default-site activation remains BLOCKED / NOT AUTHORIZED. Task 25A verdict B and Tasks 25B–25D remain
BLOCKED.**

**The one operational change:** the §2 gate is no longer "find out whether the cache survived" but
"**re-measure that it still has, immediately before repairing**". PRECHECK-P2.md §7 enumerates every
trigger that would flip the answer back to a 16-argument rebuild.

---

## 10. Correction record — Task 26B-P5, 2026-10-09 (America/New_York)

Scope of 26B-P5: **bounded read-only cron audit** of both sites, plus this record and
[CRON-IMPACT-P5.md](CRON-IMPACT-P5.md). **No service started; no container lifecycle action; no
Drush; no DDEV; no Drupal bootstrap; no HTTP request to a Drupal route; no cron or queue worker run;
cron NOT disabled; no code or config edited; nothing committed.** §1–§9 are **preserved**; only the
P5 status and the effects disclosure change.

### 10.1 New stop condition (established by this audit)

> **STOP if, immediately before a service start, any of the following has changed from the audited
> state** — each would re-open a protected-data path that is currently closed, or change the
> disclosed effects:
>
> 1. **Any `commerce_order_type` gains a `commerce_cart.cart_expiration` third-party setting.** This
>    is the only thing standing between cron and `$order->delete()`
>    (`commerce/modules/cart/src/Cron.php::run()`). Currently **absent on both order types, both
>    sites**.
> 2. **Any webform's `purge` setting is no longer `none`.** Currently `none` on all three forms
>    (`contact`, `da`, `hg`) on both sites; any other value makes `WebformHooks::cron()` delete
>    submissions.
> 3. **Any row appears in the `queue` table.** Currently empty on both sites; a non-empty queue means
>    `DispatchNotificationWorker` (mail), `CartExpiration` or `OrphanPurger` would execute.
> 4. **Any Review Request gains a non-NULL `expires_at` in the past.** Currently every expirable row
>    has `expires_at IS NULL`, which is why `expireLapsed()` writes 0 rows.
> 5. **`file_managed` becomes non-empty** while `system.file.temporary_maximum_age` stays non-zero
>    (currently 21600), which would arm `file_cron` deletions.
>
> These are **live values**, not invariants. Re-measure all five before starting services.

### 10.2 Corrections to this proposal

| # | Original statement | Status | Correction |
| --- | --- | --- | --- |
| 1 | §6 item 5 — P5 open, three options offered | **AUDITED** | Option (b) performed. All 68 enabled modules' cron surface enumerated: 14 procedural `hook_cron` + 5 attribute-form (2 excluded as not enabled), 3 applicable cron-processed queue workers, 2 ECA models. |
| 2 | §4 Option A — "Measured exposure: **0** Review Request writes, **0** queued notifications… **Unmeasured:** every other enabled module's `hook_cron`" | **CLOSED, with a material addition** | The protected-data verdict **holds and is now broader**: no order, submission, review, reservation, guard, payment, file or queued job is touched — each clearance state-backed and control-verified. **But the run is not effect-free**, which the proposal implied: see 10.3. |
| 3 | Implicit premise that an overdue cron is only a *data* question | **CORRECTED** | It is also an **egress** question. Three outbound network calls fire; the isolation manifest ([../task-26a-readiness/READINESS.md](../task-26a-readiness/READINESS.md) §5.1) requires the external-call boundary to be **verified**, and it is not. |

### 10.3 Effects that WILL occur, newly disclosed

1. **Outbound HTTP ×3** — drupal.org update check (`update_cron`, last check **19.9 days** ago vs a
   1-day interval), drupal.org announcements feed (`announcements_feed_cron`, **19.5 days** vs a 6 h
   interval), and the Commerce inbox fetch (`CommerceHooks::cron()`; self-throttling
   **REQUIRES_VERIFICATION**).
2. **`watchdog` deletion** — `dblog_cron` prunes to `row_limit = 1000`; the default site holds
   **1045** rows ⇒ ~45 deleted. QA (231 rows) is a no-op.
3. **Search index writes** — `search_cron` re-indexes; **7,598** index rows present. Volume
   **REQUIRES_VERIFICATION**.
4. **Advanced Queue errors** — all three configured queues are `processor = cron`, but the
   **`advancedqueue_job` table does not exist** on either site, so `processQueue()` is expected to
   throw per queue and be logged. **Pre-existing and unrelated to this repair**; a cron run is simply
   what would surface it. Graceful degradation **REQUIRES_VERIFICATION**.
5. **Cron bookkeeping state writes** — `system.cron_last`, `update.last_check`,
   `announcements_feed.last_fetch`.

### 10.4 What stays open

The five core maintenance hooks `system_cron`, `node_cron`, `comment_cron`, `history_cron` and
`field_cron` were identified as applicable but **not read individually** — **REQUIRES_VERIFICATION**.
Also open: the Commerce fetcher's gating, the search re-index volume, Advanced Queue's failure mode,
and whether any cron path on the default site instantiates
`custom_webform_handlers.dispatch_checkout_manager` against the **15-argument** container.

> **CLOSED 2026-10-09 (Task 26B-P6) — see [CRON-IMPACT-P6.md](CRON-IMPACT-P6.md) and §11 below.**
> All six residual items are resolved. **The remaining cron risks are now understood**: no
> unexamined code path is left, only runtime confirmations that cannot be obtained without starting
> services. Two previously unlisted effects were found (a **fourth** outbound call and an
> `.htaccess` write), and one containment gap was found that strengthens stop condition #2.

**Unchanged:** **B5 remains diagnosed and prepared, but not repaired.** **B2, B3, B4 and B6 are
untouched.** **Task 26B default-site activation remains BLOCKED / NOT AUTHORIZED.** **Task 25A
verdict B and Tasks 25B–25D remain BLOCKED.** P1's validity remains generation-scoped per
[PRECHECK-P2.md](PRECHECK-P2.md) §7.

---

## 11. Correction record — Task 26B-P6, 2026-10-09 (America/New_York)

Scope of 26B-P6: **read-only source inspection plus narrowly scoped active-config and aggregate
reads**, closing the P5 residual, plus this record and [CRON-IMPACT-P6.md](CRON-IMPACT-P6.md).
**No Drupal bootstrap, no Drush, no cron, no queue worker, no HTTP request, no application
operation; no service started; no container changed; cron configuration unchanged; Advanced Queue
not repaired; nothing installed or migrated; no source, database or historical record altered; the
scheduling engine and driver lock untouched.** §1–§10 are **preserved**.

### 11.1 Are the remaining cron risks understood? — **YES**

All six P5 residual items are resolved. The residual list now contains **no unexamined code path** —
only runtime confirmations unobtainable without starting services, plus two bounded-volume questions
(CRON-IMPACT-P6.md §9.2).

| # | P5 residual | Outcome |
| --- | --- | --- |
| 1 | `system_cron`, `node_cron`, `comment_cron`, `history_cron`, `field_cron` unread | **RESOLVED.** `history_cron` deletes **0** (table empty); `field_cron` purges **0** (no deleted field storage, no `field_deleted_*` tables); `node_cron`/`comment_cron` are single state writes (0 nodes). `system_cron` adds two effects — see 11.2. |
| 2 | Commerce fetcher gating | **RESOLVED.** Self-throttled to **once per day** (`InboxMessageFetcher.php:24,58-65`); state is 19.9 d stale ⇒ fires **once**, via Guzzle. |
| 3 | Search re-index volume | **RESOLVED.** Hard-bounded at `cron_limit = 100` per page per run; **85** pending, **0 nodes**; writes confined to `search_index`/`search_dataset`/`search_total`. |
| 4 | Advanced Queue failure mode | **RESOLVED.** Throws at `Processor::processQueue()`'s first statement, `cleanupQueue()`, **before** any `claimJob()`; DB errors are `\Exception` descendants, so `Cron.php:336-342` logs and continues. **3 logged exceptions, no job processed.** |
| 5 | Any cron path instantiating `dispatch_checkout_manager` | **RESOLVED — NO.** Full closure: `services.yml:721` → `dispatch_checkout_starter` (consumed only by `ReviewPaymentForm.php:77` and `AddressDistanceHandler.php:309`) and `DispatchPaymentProcess.php:86` (checkout pane). None is a cron entry point; the notification worker's chain is clean. The one indirect path (webform purge → handler instantiation) is closed by **two independent gates**. |
| 6 | Any paid Google Routes / Maps / geocoding / external routing request | **RESOLVED — NO.** Blocked three independent ways, and the `development_fixed` safeguard is confirmed for **PHP-FPM**, not only the Drush CLI that measured it before — `clear_env = no` in the FPM pool, so `getenv('IS_DDEV_PROJECT')` resolves. `geocoder_field` remains disabled. |

### 11.2 Two effects added to §10.3

1. **A fourth outbound network call** — `system_cron` (`system.module:1037-1040`) fetches security
   advisories when `system.advisories.enabled`; measured **`b:1`** with `interval_hours: 6` on both
   sites, and no `state` row tracks a last fetch. Whether a cached response suppresses it is
   **REQUIRES_VERIFICATION**. §10.3's count of three outbound calls becomes **four**.
2. **A filesystem write** — `system.module:1035` calls `file.htaccess_writer->ensure()`, which writes
   protective `.htaccess` files into Drupal's standard directories when missing. §10.3 implied the
   run made no filesystem change; that is **corrected**. Target paths not enumerated —
   **REQUIRES_VERIFICATION**. It touches no managed file or user content.
   **ENUMERATED 2026-10-10 (Task 26B-P7) — [HTACCESS-WRITE-P7.md](HTACCESS-WRITE-P7.md); see §12.**
   Seven targets resolve; **the five in the repository all already exist, so `ensure()` is a no-op
   for them**, and it **can never replace existing content** (`FileSecurity::writeFile()` returns on
   `file_exists()` before writing; `ensure()` always passes `$force_overwrite = FALSE`). **None is
   tracked by Git; all are ignored.** The only possible write is **one file in the temporary
   directory**, still **REQUIRES_VERIFICATION**.

### 11.3 Stop condition #2 strengthened

`Core\Cron.php:341` catches **`\Exception`, not `\Throwable`**. `ArgumentCountError` is an `Error`,
so it would **escape** the per-module containment and be **fatal to the request**, aborting the rest
of the cron run rather than being logged and skipped.

That cannot happen today, because §11.1 item 5 closes every path. But it means §10.1 stop condition
**#2** (`purge` no longer `none`) is load-bearing for **availability**, not only data preservation:
re-enabling purge on any form would both delete submissions **and** open a path to a fatal
`ArgumentCountError` on the default site, where the valid compiled container holds **15** arguments.
**All five §10.1 stop conditions stand unchanged in substance and still rest on live values.**

### 11.4 Status

**No protected-data verdict changed.** No order, submission, review request, reservation, guard,
payment, managed file or queued job is created, modified or deleted; no mail is sent; no queue is
drained; neither ECA model triggers; no paid routing or geocoding call is made.

**Unchanged:** **B5 remains diagnosed and prepared, but not repaired.** **B2, B3, B4 and B6 are
untouched.** **Option A remains NOT AUTHORIZED** unless separately authorized. **Task 26B
default-site activation remains BLOCKED / NOT AUTHORIZED.** **Task 25A verdict B and Tasks 25B–25D
remain BLOCKED.** The **P5 decision in §6 item 5 is still open** — understanding the effects is not
the same as accepting them; option (b) is now complete, so the choice is between accepting the
four-call/one-write disclosure and mitigating by configuration.

---

## 12. Verified `.htaccess` write targets — Task 26B-P7, 2026-10-10 (host clock)

Scope of 26B-P7: **read-only inspection** of `file.htaccess_writer->ensure()`, plus this record and
[HTACCESS-WRITE-P7.md](HTACCESS-WRITE-P7.md). **`ensure()` was not invoked; no cron, no service
start, no HTTP request; no file, configuration, database or container modified; no Drupal bootstrap
and no Drush.** No file contents were printed. §1–§11 are **preserved**.

> **Timestamp note.** §§1–11 are headed "America/New_York". On this host the TZ database resolves
> that zone to **GMT**, so local equals UTC and the label is misleading; §12 uses the **host clock**.
> The harness reported 2026-10-09 when P7 began, but epoch arithmetic pins P7's measurements to
> **2026-10-10T02:35–02:52Z** (`1791600767` vs P6's `1791515851`, ≈23.6 h apart). P5/P6 dates match
> their own recorded epochs and are unaffected.

### 12.1 The seven targets, with current state

Resolution is driven entirely by `settings.php` values, not database config.

**Default site** (`sites/default`, `https://ppu.ddev.site`):

| Target | Variant | State | Effect |
| --- | --- | --- | --- |
| `sites/default/files` (`public://`) | public, 486 B | **EXISTS** `28039dff…`, perms 444 | **no-op** |
| `sites/default/files/config_<redacted>/sync` | private, 685 B | **EXISTS** `4f62c1eb…`, perms 444 | **no-op** |
| temporary directory | public | **unknown** | the only possible write |
| `private://` | — | **NOT A TARGET** — `file_private_path` unset ⇒ `PrivateStream::basePath()` NULL ⇒ excluded | none |
| optimized assets | — | **NOT A TARGET** — `file_assets_path` unset ⇒ equals public ⇒ excluded | none |

**QA site** (`sites/qa`, `https://ppu-qa.ddev.site`) — has **one more** target, because
`file_private_path` is set there:

| Target | Variant | State | Effect |
| --- | --- | --- | --- |
| `sites/qa/files` (`public://`) | public, 486 B | **EXISTS** `28039dff…`, perms 644 | **no-op** |
| `sites/qa/private` (`private://`) | private, 685 B | **EXISTS** `4f62c1eb…`, perms 644 | **no-op** |
| `sites/qa/files/config/sync` | private, 685 B | **EXISTS** `4f62c1eb…`, perms 644 | **no-op** |
| temporary directory | public | **unknown** (same single file as default — shared container) | the only possible write |
| optimized assets | — | **NOT A TARGET** | none |

The default site's `config_sync_directory` embeds an unguessable random token and is **deliberately
redacted**; exactly one directory matches the `config_*/sync` glob.

### 12.2 Four properties that bound the risk

1. **Create-only, never replace.** `FileSecurity::writeFile():149-163` returns `TRUE` on
   `file_exists() && !$force` **before any write** — content is never read, compared or rewritten —
   and `ensure()` calls `write()` with two arguments, so `$force_overwrite` is always **`FALSE`**.
2. **Not tracked, and ignored.** None of the five is tracked (`git ls-files` → untracked); all are
   ignored by `.gitignore:25` (`sites/*/files`) or `:26` (`sites/*/private`), and
   `git status --porcelain -- 'sites/**/.htaccess'` is **empty**. So `ensure()` cannot dirty the
   index or alter tracked work, and **no target contains local changes to a tracked file**.
3. **Cannot touch an upload.** The write path is the single fixed filename
   `$directory . DIRECTORY_SEPARATOR . '.htaccess'`, with no recursion. The targets do sit at the
   **root of the trees that would hold uploads** (`public://`, `private://`) — same tree, never the
   same file — and **no `webform` upload directory exists on either site**, consistent with the
   historical `file_managed = 0`.
4. **Failure is logged, not fatal.** A missing or unwritable directory makes `file_put_contents`
   fail under `@`, which `HtaccessWriter::write():97` turns into a logged security warning;
   `ensure():61-63` also catches and logs.

### 12.3 Remaining uncertainty

| Item | Status |
| --- | --- |
| The temporary directory's path, and whether `.htaccess` is already there | **REQUIRES_VERIFICATION.** `file_temp_path` is unset on both sites, so the chain is `getTempDirectory()` → `getOsTemporaryDirectory()` → `ini_get('upload_tmp_dir')` if set, else `/tmp`. Both answers live inside the web container, which became unreachable mid-task (12.4). One bounded container read settles it. |
| Why QA's three files are perms 644 while the default site's two are 444 | **Not established.** 444 is `writeFile()`'s `chmod` signature, so QA's were created by something else or later re-`chmod`-ed. Immaterial: existence alone makes `ensure()` a no-op, and it does not re-`chmod`. |
| Whether the existing files match Drupal's canonical bytes exactly | **Not established, and immaterial** — `writeFile()` never compares content. A structural check confirmed the private variant is a 199-byte deny prefix plus the byte-identical public body, matching the documented composition. |

**No other target is unresolved** — every conditional branch in `defaultProtectedDirs()` was
evaluated against measured settings for both sites.

A near-miss worth recording: `sites/default/files/sync` exists **without** an `.htaccess`, but it is
**not** a target — `settings.php:905` assigns the hashed path before the DDEV include at `:914`, and
`settings.ddev.php:36` only assigns `if (empty(...))`. The directory is **empty** (0 files, 0
subdirectories), so nothing is exposed. Pre-existing, unrelated, **not changed**.

### 12.4 Mid-task environment change — container state no longer established

At approximately **2026-10-10 02:40Z** the Docker daemon stopped responding
(`npipe:////./pipe/dockerDesktopLinuxEngine` not found), confirmed by read-only probes with a
non-Docker control. **It was not started** — that is a lifecycle change this task forbids. The
`mcp__dbhub__*` tools de-registered with it.

This cost exactly one target's resolution (12.3). Everything else here is host-side: the five
repository targets are inside the bind-mounted repository and were measured directly.

> **Consequence for this proposal:** the container generation recorded in §2, §5, §10 and §11 as
> started **2026-10-08T13:23Z** is **no longer verified as live**. Every container-state fact in
> P1–P7 is now **historical**. Each was true when measured, and no finding changes — but
> **re-measure container state, P1 cache validity, and all five §10.1 stop conditions before any
> service start.**

### 12.5 Status

**P7 adds no new stop condition** — no target is tracked, none holds customer data, and none can be
overwritten. §10.1's five stop conditions stand unchanged.

**Unchanged:** **B5 remains diagnosed and prepared, but not repaired.** **B2, B3, B4 and B6 are
untouched.** **Option A remains NOT AUTHORIZED** unless separately authorized. **Task 26B
default-site activation remains BLOCKED / NOT AUTHORIZED.** **Task 25A verdict B and Tasks 25B–25D
remain BLOCKED.** The §6 item 5 P5 decision is still open.

---

## 13. Temporary-directory check — Task 26B-P8, 2026-10-10 (host clock): **BLOCKED**

Inspection timestamp: **2026-10-10T03:07–03:09Z** (host clock; epoch `1791601621`). Scope: resolve
**only** the temporary-directory uncertainty left by P7. Read-only throughout.

**Outcome: the check was NOT completed.** Docker is unavailable, so the precondition for inspecting
the container was never met. This is recorded as **REQUIRES_VERIFICATION / blocked** and is
deliberately **not** converted into a finding.

### 13.1 Reference note

The governing instruction cites "REPAIR_PROMPT.md §12.6". **There is no §12.6** — §12 contains
§12.1–§12.5. The temporary-directory uncertainty is the first row of **§12.3**, and
[HTACCESS-WRITE-P7.md](HTACCESS-WRITE-P7.md) §4.3 holds its detail. Both were taken as the target.
No other P7 item was reopened.

### 13.2 Read-only evidence that Docker is unavailable

| # | Check | Result |
| --- | --- | --- |
| 1 | `docker ps --format …` (exit captured directly, not through a pipe) | **exit 1** — "failed to connect to the docker API at `npipe:////./pipe/dockerDesktopLinuxEngine` … The system cannot find the file specified" |
| 2 | `docker info --format '{{.ServerVersion}}'` | same connection failure |
| 3 | `docker context ls` (reads local config only) | current context is **`desktop-linux`** → `npipe:////./pipe/dockerDesktopLinuxEngine`; `default` → `npipe:////./pipe/docker_engine`, not current |
| 4 | Existence test on **both** endpoints | `//./pipe/docker_engine` **ABSENT**; `//./pipe/dockerDesktopLinuxEngine` **ABSENT** |
| 5 | Host process list, name-filtered | **no** `com.docker.backend.exe`; **no** `dockerd.exe` |
| 6 | **Controls** | `tasklist` returns `explorer.exe` (so #5's negatives are real, not a broken query); `git rev-parse` and shell echo succeed (so the failure is Docker-specific, not general tooling) |

**Conclusion:** no Docker engine is running on this host, therefore **no project container is
running**, therefore the web container's identity cannot be established and no command could be
directed at it. Procedure step 2's precondition ("proceed only if the existing project web container
is already running and its identity is unambiguous") is **not satisfied**; step 3 applies.

**Nothing was started.** No `ddev` command of any kind, no `docker compose up`, no `docker start`, no
Drush, no cron, no HTTP request, no interactive or `-i`/`-t` execution, no heredoc or stdin script.
Only `docker ps`, `docker info`, `docker context ls`, two filesystem existence tests, and a
name-filtered host process list were run — all read-only, none capable of starting the engine.

### 13.3 What remains unresolved

Unchanged from P7 §4.3 — both sub-questions still need **one** bounded container read, once a
container is legitimately running:

1. `ini_get('upload_tmp_dir')` in the FPM and CLI inis — determines the effective directory
   (`file_temp_path` is unset on both sites, so the chain is `getTempDirectory()` →
   `getOsTemporaryDirectory()` → `upload_tmp_dir` if set, else `/tmp`).
2. Whether `.htaccess` already exists there — determines whether `ensure()` writes anything at all.

The bound on the risk is unchanged and does not depend on this check: **at most one file, created
only if absent, never overwritten** (§12.2).

### 13.4 A consequence for the earlier evidence

P7 §12.4 recorded that the container generation of 2026-10-08T13:23Z was "no longer verified as
live". That is now **stronger**: it is positively established that **no container is running at
all**.

This matters beyond the present check. Pre-check **P1** (PRECHECK-P2.md, PRE-REPAIR-P3.md §2) rests
on the Redis process `run_id bf4dbf31…` holding the default site's valid 15-argument compiled
container. **That process no longer exists.** When Docker next starts, Redis starts as a new process
and reloads from its RDB — and per PRECHECK-P2.md §5 the project runs `appendonly no` with the
built-in `save` schedule, so whether the entry survives depends on whether a snapshot was written
before shutdown. Either outcome is possible:

- **entry survives** → P1 still valid, Option A would not activate new wiring;
- **entry lost** → the first HTTP request compiles a **16-argument** container from the uncommitted
  working tree, which is exactly the **B2/B3-gated activation** that §2 and §6 item 4 forbid.

**So P1 must be re-measured after Docker returns, before any service start** — this is already
required by §6 item 1 and PRECHECK-P2.md §7; P8 adds a concrete reason to expect it may have
flipped.

### 13.5 Status

**P8 adds no stop condition and changes no finding.** No source, Drupal configuration, compose file,
database, container, or runtime file was touched; no `.htaccess` was created, overwritten,
`chmod`-ed, deleted or repaired; the historical P7 evidence file is unmodified. All existing and
unrelated worktree changes are preserved (HEAD `b49c259e`, nothing staged, 29 tracked modifications,
17 untracked entries — unchanged).

**Unchanged:** **B5 remains diagnosed and prepared, but not repaired.** **B2, B3, B4 and B6 are
untouched.** **Option A remains NOT AUTHORIZED.** **Task 26B default-site activation remains BLOCKED
/ NOT AUTHORIZED.** **Task 25A verdict B and Tasks 25B–25D remain BLOCKED.** The §6 item 5 P5
decision is still open.

---

## 14. Post-Docker read-only preflight — Task 26B-P9, 2026-10-10 (host clock)

Inspection: **2026-10-10T03:19–03:23Z** (host clock; epoch `1791602343`). Read-only preflight only.
**No container or service was started; no Drupal bootstrap, Drush, cron or HTTP request occurred; no
runtime, configuration or database state was changed.** §§1–13 preserved verbatim.

**Result: P1 is VALID and all five §10.1 stop conditions are in their documented safe state on both
sites.** This is **not** permission to start services — see §14.6.

### 14.1 Current container state (not assumed from P1–P8)

Docker engine reachable (`docker ps` exit 0). Eight project containers running, started
**2026-10-10T03:12:48–03:12:49Z**, all `RestartCount=0` — a **new generation**, the third recorded in
this chain.

| Required container | ID | State | Started | Notes |
| --- | --- | --- | --- | --- |
| `ddev-ppu-web` | `6cd266577f75` | running, **unhealthy** | 03:12:49.393Z | **Same container ID as P1/P7** — restarted, *not* recreated. `Path=/pre-start.sh`. |
| `ddev-ppu-redis` | `98d42cf2e77d` | running (no healthcheck) | 03:12:48.990Z | — |
| `ddev-ppu-db` | `739bb26ec971` | running, healthy | 03:12:48.237Z | — |

Identity is **unambiguous**: `docker ps -a --filter name=^<name>$` returns **exactly one** match for
each of the three.

### 14.2 Redis generation — the P8 question, answered

P8 §13.4 predicted the Redis process would be replaced and the cache entry's survival would depend
on whether an RDB snapshot was written before shutdown. **The entry survived.**

| Fact | Value |
| --- | --- |
| `run_id` | **`a81ce7919ad90397fdaf6aba90657301fdcee84c`** — **differs** from P2/P3's `bf4dbf31…`, confirming a new process |
| `uptime_in_seconds` | 423 |
| `rdb_last_load_keys_loaded` | **19,433** — the dataset was reloaded from disk |
| `DBSIZE` | **19,433** — identical to the pre-shutdown count recorded in PRECHECK-P2.md §5 |
| `rdb_changes_since_last_save` / `aof_enabled` | 0 / 0 |

So P8's **"entry survives"** branch is the actual outcome: Option A would **not** activate new wiring
on that account. (The other B2/B3 grounds are untouched — §14.6.)

### 14.3 P1 remeasured — VALID

Procedure exactly as PRECHECK-P2.md §3.3. All seven container-bin keys survived; the live
default-site entry was re-enumerated and classified **in-container** (no transcription).

| `expandEntry()` gate | Measured 03:20Z | vs P2/P3 | Result |
| --- | --- | --- | --- |
| 1 — present, `cid` non-empty | `type=hash`, `cid` 202 B, `data` **565,625 B**, `serialized=1`, `gz` empty | identical | **PASS** |
| 2 — `delayedDeletions` | per-request; empty on a cold read | identical | **PASS** |
| 3 — expiry / valid | `expire = -1` (`Cache::PERMANENT`), `valid = 1` | identical | **PASS** |
| 4 — tag checksum | stored `0`, `tags = [x-redis-bin:container]`; `EXISTS <prefix>:cachetags:x-redis-bin:container` → **0** ⇒ computed `0` = stored `0` | identical | **PASS** |
| 5 — last-delete-all | marker **1789532352.349** vs `created` **1790877299.243** ⇒ `created < marker` is **False** | **both byte-identical** | **PASS** |
| Argument count | **`value";a:15:`** (default) / **`a:16:`** (QA) | identical | **still 15 on default** |

**Same physical key, not a replacement:** TTL moved **30,899,953 → 30,810,885** (−89,068 s ≈ 24.7 h),
matching elapsed wall time, while `created` and the marker did not move. Serialized contents were
never printed.

**Source fingerprints re-verified** (REPAIR_PROMPT §2) — unchanged and identical on host *and* inside
the restarted container: `services.yml` `920581e3…`, `DispatchCheckoutManager.php` `b7251503…`,
`custom_webform_handlers.install` `172d25b0…`. No host/container divergence after the restart.

### 14.4 The five §10.1 stop conditions — all SAFE, both sites

Read-only `SELECT`s against the already-running database container. No Drupal bootstrap, no Drush,
nothing able to invoke cron. Counts and flags only; **no customer data read or printed.**

| # | Condition | Safe value | Default (`db`) | QA (`qa`) | Verdict |
| --- | --- | --- | --- | --- | --- |
| 1 | order types with `commerce_cart.cart_expiration` | 0 | **0** | **0** | **SAFE** |
| 2 | webforms with `purge` ≠ `none` | 0 | **0** of 3 | **0** of 3 | **SAFE** |
| 3 | rows in `queue` | 0 | **0** | **0** | **SAFE** |
| 4 | Review Requests lapse-eligible (`state` expirable **AND** `expires_at IS NOT NULL` **AND** `expires_at <= now`) | 0 | **0** | **0** | **SAFE** |
| 5 | `file_managed` rows (while `temporary_maximum_age` ≠ 0) | 0 | **0** (age `21600`) | **0** (age `21600`) | **SAFE** |

**Controls, because two of these could read 0 for the wrong reason:**

- **#1** — `workflow` matched **2/2** order types on both sites, proving the `LIKE` matcher works, so
  `cart_expiration = 0` is a real negative. (This is the same control that exposed a dead matcher in
  CRON-IMPACT-P5.md §3.2.)
- **#2** — the condition is a **negated** `LIKE`, which can silently return 0 if malformed. Control:
  `purge`-key **present 3/3** and `purge == "none"` **exactly 3/3** on both sites ⇒ the negation is
  sound.
- **#4** — a 0 could mean "empty set" rather than "no deadline passed". Control: **2** expirable rows
  on default and **8** on QA, **all** with `expires_at IS NULL`, **0** non-NULL ⇒ the 0 comes from the
  deadline condition, as documented.

### 14.5 B5 is unchanged — it reproduces across a third restart

| Check | Value |
| --- | --- |
| Health / failing streak | **unhealthy** / 170 |
| Last healthcheck (03:22:15Z, exit 1) | `/var/www/html:OK mailpit:FAILED phpstatus:FAILED` |
| `supervisord` processes | **0** |
| Listeners on :80 / :8025 | **0 / 0** |

Identical to DIAGNOSIS.md. The root cause — the container started with `/pre-start.sh` as PID 1 and
DDEV's `/start.sh` phase never ran — now reproduces across **three** independent whole-stack
restarts, which strengthens that diagnosis rather than changing it.

### 14.6 Remaining blockers — a green preflight is not authorization

**Nothing here authorizes anything.** What a passing preflight establishes is narrow: P1 is still
valid and the five live values are still safe. It says nothing about the gates that actually hold
Option A.

| Blocker | Status |
| --- | --- |
| **§6 item 5 — the P5 decision** | **OPEN.** Effects are understood (four outbound calls + one `.htaccess` ensure), but no option has been chosen. |
| **B2** — approval of the activated wiring set | **NOT GRANTED** |
| **B3** — runtime atomicity / safe disposal after failed rollback | **NOT GRANTED.** Requires *correctness evidence*; P1's validity is not a substitute. |
| **B4** — backup completeness and restoration capability | **NOT GRANTED** |
| **B6** — verified isolation target manifest | **NOT GRANTED** |
| **Option A / Option B** | **NOT AUTHORIZED** |
| **Task 26B default-site activation** | **BLOCKED / NOT AUTHORIZED** |
| **Task 25A verdict B; Tasks 25B–25D** | **BLOCKED** |

**Still unverified (unchanged):** the temporary-directory target from P7 §4.3 / §12.3 — the effective
path (`upload_tmp_dir` vs `/tmp`) and whether `.htaccess` is already there. Docker's return has
**unblocked** it, but it is outside P9's scope and was **not** performed; it remains
**REQUIRES_VERIFICATION**, now reachable with one bounded container read.

**Freshness:** every value in §14.2–§14.4 is a **live** reading for the container generation started
2026-10-10T03:12:48Z. If that generation changes again, P1 and all five conditions must be
re-measured — §10.1's closing sentence and PRECHECK-P2.md §7 still govern.

---

## 15. Temporary-directory check — Task 26B-P8-R1, 2026-10-10 (host clock): **RESOLVED**

Inspection: **2026-10-10T03:33–03:35Z** (host clock; epoch `1791603213`). Read-only retry of the check
that P8 §13 recorded as blocked. §§1–14 preserved verbatim. **No container or service was started;
no `ddev`, no `/start.sh`, no PHP-FPM start, no Drush, no cron, no HTTP request, no Drupal bootstrap;
`ensure()` was not invoked; no `.htaccess` was created, written or `chmod`-ed; no runtime, source,
configuration, database or container state was changed.**

### 15.1 Answer

| Question | Result |
| --- | --- |
| Effective temporary directory | **`/tmp`** — for **both** sites (shared container) |
| Does `<temp>/.htaccess` exist? | **NO — ABSENT** |
| So what would `ensure()` do? | **Create exactly one file: `/tmp/.htaccess`**, the **public** variant (486 B, prevent-execution only), then `chmod 0444` |

This closes the last open item from §12.3 / §13.3 and resolves the P7 §4.3 uncertainty. It is the
**only** confirmed-actual write in the entire first-cron effect list: the other five `.htaccess`
targets already exist and are no-ops (§12.1).

### 15.2 Container identity (rechecked; P9's generation not assumed)

| Fact | Value |
| --- | --- |
| Docker engine | reachable, `docker ps` exit **0** |
| `ddev-ppu-web` name matches | **exactly 1** (`docker ps -a --filter 'name=^ddev-ppu-web$'`) |
| Full container ID | `6cd266577f75ca76f50ff47271a0fd83407987780da863a1618f7202a681ad19` |
| State / started / restarts | `running` / **2026-10-10T03:12:49.393Z** / `0` — same generation as §14 |
| PID 1 | `/pre-start.sh` (B5 unchanged) |

The bounded `docker exec` was issued against the **verified container ID**, non-interactive, no `-i`
and no `-t`.

### 15.3 Resolution, step by step against the documented chain

`FileSystem::getTempDirectory()` → `FileSystemComponent::getOsTemporaryDirectory()`:

| Step | Source | Measured | Outcome |
| --- | --- | --- | --- |
| 1 | `Settings::get('file_temp_path')` | **unset** on both sites (§12.1 / P7: commented at `sites/default/settings.php:626`; absent from `sites/qa/settings.php`) | fall through to the OS resolver |
| 2 | `if (ini_get('upload_tmp_dir'))` | **unset in every config source** — `cli/php.ini`, `fpm/php.ini`, **51** `cli/conf.d/*.ini`, **51** `fpm/conf.d/*.ini`, **1** `fpm/pool.d/*.conf` (incl. `php_admin_value[...]` / `php_value[...]` forms) | falsy ⇒ **not added** to the candidate list |
| 3 | non-Windows branch | `$directories[] = '/tmp'` | first candidate |
| 4 | `$directories[] = sys_get_temp_dir()` | `sys_temp_dir` also **unset in every source** | third candidate, never reached |
| 5 | `foreach`: first `is_dir && is_writable` wins | `/tmp` → `is_dir=1`, `is_writable=1` | **returns `/tmp`** (after `rtrim`) |

**Control for step 2/4** — a "not found" result from a grep must be proven to be a real negative, not
a dead matcher. The same grep style over the same files found `memory_limit` (**1** hit in
`fpm/php.ini`) and `clear_env` (**1** hit in `pool.d`). So the matcher works and the absence of
`upload_tmp_dir` / `sys_temp_dir` is genuine.

**Method note.** The FPM value was established by **static configuration read** across all five
source classes, *not* by starting PHP-FPM or running `php-fpm -t`. The `settings.php` layer (step 1)
was already measured host-side in P7 and is unchanged. No Drupal bootstrap was used at any point.

### 15.4 The variant that would be written

`HtaccessWriter.php:109` registers the temporary directory as
`new ProtectedDirectory('Temporary files directory', 'temporary://')` — **no third argument**. Per
`ProtectedDirectory::__construct($title, $path, $private = FALSE)` (`:41`), `isPrivate()` is
**FALSE**, so `ensure()` calls `write($path, FALSE)` and the **public** variant is used: prevent
execution only, **no** deny-all block. That is the 486-byte form, matching the two existing public
files in §12.1. `FileSecurity::writeFile()` then applies `chmod 0444`.

`write()` normalises `temporary://` through the stream wrapper, and `TemporaryStream::getDirectoryPath()`
returns `getTempDirectory()`, so the write resolves to **`/tmp/.htaccess`** — the path checked above.

### 15.5 Scope and status

Only `/tmp/.htaccess` was inspected — existence plus `stat` type/size/mtime had it existed. **No file
contents were read or printed**, and no other directory or file was examined.

**P8 §13 is superseded on this one point only**: its `REQUIRES_VERIFICATION` for the temporary
directory is now **resolved**. Everything else P8 recorded stands, and §13's blocked status is left
in place as the historical record of that attempt.

**No stop condition is added.** The write is a single file, created only because it is absent, never
overwritten (§12.2), untracked and outside any customer-upload path, in a container-internal
directory.

**Unchanged:** **B5 remains diagnosed and prepared, but not repaired.** **B2, B3, B4 and B6 are
untouched.** **Option A and Option B remain NOT AUTHORIZED.** **Task 26B default-site activation
remains BLOCKED / NOT AUTHORIZED.** **Task 25A verdict B and Tasks 25B–25D remain BLOCKED.** The §6
item 5 P5 decision is still open — this result adds one confirmed write to the disclosure it must
weigh, and authorizes nothing.

---

## 16. Residual cron-body review — Task 26B-P10, 2026-10-10 (host clock)

Source/documentation inspection only. Prepared **2026-10-10T14:26–14:40Z** (host clock). §§1–15
preserved verbatim. **No Docker/DDEV/Drush/Drupal bootstrap, no cron, no HTTP request, no automated
test, no service start/recreate, and no edit to code, configuration, database, container or runtime
files.** This section selects no P5 option.

### 16.1 Scope and a note on prior coverage

The five handlers are exactly those named in [CRON-IMPACT-P5.md](CRON-IMPACT-P5.md) §5:
`system_cron`, `node_cron`, `comment_cron`, `history_cron`, `field_cron`.

**[CRON-IMPACT-P6.md](CRON-IMPACT-P6.md) §3 already read and classified these five** (one line each),
which superseded the "not read individually" status in P5 §5; P6 §9.2 carries none of them as open.
So **no prior-file correction is required** — the P5 §5 line is historical and correct for P5's time.
P10's added value is the **direct-call trace** of each effect class (DB writes/deletes and their
conditions, filesystem, queue/mail, outbound), which P6 recorded only at summary depth. Findings are
**consistent with P6 §3** and add precision, not reversal.

**Source examined:** live core **10.6.16** (`core/lib/Drupal.php:78`; the root `core/` docroot).
Fingerprints of the five files, for provenance: `system.module` `1d24e0df…`, `node.module`
`dee7e721…`, `comment.module` `5a080dc7…`, `history.module` `0da5cde3…`, `field.module` `6ed271c0…`.
These are **source** facts; the state values below are **live** for the container generation started
2026-10-10T03:12:48Z (§14) and must be re-read if that generation changes.

### 16.2 Per-handler direct-call trace

| Handler | DB write/delete (condition) | Filesystem | Queue / mail | Outbound | Current-state effect |
| --- | --- | --- | --- | --- | --- |
| `node_cron` (`node.module:603`) | At most **one `state` write** to `node.min_max_update_time`; gated `if moduleExists('search')` (enabled). Reads an aggregate `MIN/MAX(created)` over `node`. **No delete.** | none | none | none | **0 nodes** ⇒ aggregate is empty/NULL; one trivial state write or none. No business table. |
| `comment_cron` (`comment.module:412`) | **One `state` write** to `comment.node_comment_statistics_scale`; reads `comment.statistics->getMaximumCount('node')` (a SELECT). **No delete.** | none | none | none | Single infra state write. No business table. |
| `history_cron` (`history.module:129-132`) | **`DELETE FROM history WHERE timestamp < HISTORY_READ_LIMIT`** (= request time − 30 days, `:24`). | none | none | none | `history` is **empty** on both sites (P6) ⇒ **0 rows deleted**. `history` is node-read tracking, not business data. |
| `field_cron` (`field.module:167` → `field.purge.inc:74` `field_purge_batch`) | Iterates the **deleted-fields repository**; only a field flagged deleted triggers `purgeFieldData()` / `field_purge_field()`. | none | none | none | **0 deleted field storages, 0 `field_deleted_*` tables** (P6) ⇒ both loops are empty ⇒ **no-op**. Guard is a measured-empty repository, not an untested branch. |
| `system_cron` (`system.module:1005-1041`) | Four GC calls — traced in §16.3. | **`/tmp/.htaccess` create-if-absent** (resolved in §15) | queue GC only (§16.3), **no processing, no mail** | **security-advisories fetch** (§11.2 / P6 §3.1) | Infra-table maintenance + one filesystem create + one outbound call. **No business table.** |

**None of the five touches an order, order item, submission, checkout attempt, reservation, guard,
review request, payment, or managed-file record.** This confirms P6 §3 at direct-call depth.

### 16.3 `system_cron`'s four GC calls, pinned

P6 summarised these as "GC itself is maintenance-only." The precise behaviour, read from the
installed backends actually wired by `settings.ddev.redis.php` (§7):

| GC call | Effective backend | Direct effect | Current state |
| --- | --- | --- | --- |
| `flood()->garbageCollection()` | **Redis** `FloodFactory` (redis `example.services.yml` is loaded) | Redis flood keys expire by TTL; `FloodFactory` declares no `garbageCollection` override | Operates only on rate-limit flood keys. **Exact backend GC method not pinned — REQUIRES_VERIFICATION — but it can touch only flood state, never business data.** |
| `Cache::getBins()` → each `->garbageCollection()` | **Redis** (`PhpRedis`) for every bin | `CacheBase::garbageCollection()` is an **explicit no-op** (`// @todo Do we need to do anything here?`); Redis expiry is TTL-driven | **No effect.** |
| `keyvalue.expirable.database->garbageCollection()` | core **DB** factory (redis does not override keyvalue) | **`DELETE FROM key_value_expire WHERE expire < now`** | Deletes expired rows from the `key_value_expire` **infrastructure** table only (form/temp state). Bounded to expired rows; no business table. |
| queue-worker GC loop | default **`queue.database`** `DatabaseQueue` (redis adds `queue.redis*` services but does not change the default) | per worker, only `if instanceof QueueGarbageCollectionInterface`: `DELETE FROM queue WHERE created < now−864000 AND name LIKE 'drupal_batch:%'` + stale-lease reset | `queue` table **empty** on both sites (P6, re-confirmed P9) ⇒ **0 rows touched**; and it only ever touches the `queue` infra table. |

So `system_cron`'s database footprint is: **possible deletes of expired rows from `key_value_expire`
and stale `drupal_batch:%` rows from `queue`** — both infrastructure tables, both bounded to
expired/stale rows, neither a business table. Plus the already-recorded `/tmp/.htaccess` create (§15)
and the security-advisories outbound fetch (§11.2).

### 16.4 Reconciliation with the recorded effect list

| Effect class | New in P10? | Disposition |
| --- | --- | --- |
| Outbound network | **No** | The four calls already in §10.3 / §11.2 stand; no fifth call found in these bodies. |
| Filesystem | **No** | Only `/tmp/.htaccess` (§15); no other filesystem write in these bodies. |
| Queue processing / mail | **No** | `system_cron` only *garbage-collects* queues; it does not process them. Processing is `advancedqueue_cron` / core `processQueues()`, already covered (P5/P6); queues empty. |
| Database — business tables | **No** | None of the five writes or deletes a business table. |
| Database — infrastructure tables | **Itemised, not new in kind** | `key_value_expire` and `queue` (`drupal_batch:%`) expired-row deletes, plus `node`/`comment` state writes and the empty `history` delete. P6's "maintenance-only" is correct; P10 names the exact tables and conditions so the disclosure is complete. |

**No effect contradicts P6, and no new effect on protected data was found.** The only item that
remains open is the Redis flood GC's exact backend method (§16.3), which is immaterial to protected
data.

### 16.5 Status

**P10 adds no stop condition and changes no finding.** The five handlers are now traced to
direct-call depth and confirm the P6 verdict: on current state they produce infrastructure-table
maintenance, one `/tmp/.htaccess` create, and one outbound security-advisories fetch — and touch no
protected business record.

**Unchanged:** **B5 remains diagnosed and prepared, but not repaired.** **B2, B3, B4 and B6 remain
ungranted.** **Option A and Option B remain NOT AUTHORIZED.** **Task 26B default-site activation
remains BLOCKED / NOT AUTHORIZED.** **Task 25A verdict B and Tasks 25B–25D remain BLOCKED.**

**The P5 decision (§6 item 5) remains the user's to make.** The residual read-only review it asked
for is now complete; the first-request/recovery effects are disclosed in full (four outbound calls,
one `/tmp/.htaccess` create, infrastructure-table GC, and the measured-zero business-data paths). The
choice is the user's: **accept** the disclosed effects, **request further read-only evidence** for the
one remaining `REQUIRES_VERIFICATION` item (the Redis flood GC method, immaterial to protected data),
or **separately approve an explicitly described mitigation**. This task performs none of these and
starts no service.

---

## 17. Redis flood garbage-collection — Task 26B-P11, 2026-10-10 (host clock): **RESOLVED**

Source inspection only. Prepared **2026-10-10T15:03–15:12Z** (host clock). §§1–16 preserved verbatim.
**No Docker, DDEV, Drush, Drupal bootstrap, cron, HTTP request, test, or Redis/runtime query was
run; no code, configuration, database, container or runtime file was changed.** This section selects
no P5 option.

### 17.1 Answer

The one `REQUIRES_VERIFICATION` item from §16.3 is resolved. The `garbageCollection()` method
`system_cron` reaches for the configured Redis flood service is an **explicit no-op** — empty body,
source comment `// No garbage collection necessary.` **No Redis write/delete, no database work, no
filesystem write, no network call, no TTL action.** And this holds **for all three** possible client
implementations, so the "which client is active" question is immaterial.

### 17.2 The method/class chain, from source

| Step | Source | Resolves to |
| --- | --- | --- |
| `flood` service override | `modules/contrib/redis/example.services.yml:31-33` (loaded via `settings.ddev.redis.php:16`) | `class: Drupal\Core\Flood\FloodInterface`, `factory: ['@redis.flood.factory', get]` |
| `redis.flood.factory` | `modules/contrib/redis/redis.services.yml:10-12` (loaded via `settings.ddev.redis.php:19`) | `Drupal\redis\Flood\FloodFactory`, args `@redis.factory`, `@request_stack` |
| `FloodFactory::get()` | `src/Flood/FloodFactory.php:45-47` | `new $class_name(...)` where `$class_name = ClientFactory::getClass(REDIS_IMPL_FLOOD)` |
| `ClientFactory::getClass('\Drupal\redis\Flood\')` | `src/ClientFactory.php:208-215` | `'\Drupal\redis\Flood\' . getClientName()` |
| client selection | `src/ClientFactory.php:100-121` `getClientInterface()` | no `redis.connection[interface]` in settings (confirmed), `Predis\Client` **absent from vendor** (`vendor/predis/predis/src/Client.php` does not exist), `Redis` extension present ⇒ **PhpRedis** |
| concrete method | `src/Flood/PhpRedis.php:91-93` | **`garbageCollection()` — empty, `// No garbage collection necessary.`** |

The `composer.lock` lines mentioning `predis/predis` are the redis module's own `require`/`suggest`
metadata, **not** an installed package; `vendor/predis` does not exist, so the Predis branch cannot be
taken.

### 17.3 Why the client ambiguity does not matter

Rather than rely on the PhpRedis resolution alone, all three flood backends were read:

| Class | `garbageCollection()` | Fingerprint |
| --- | --- | --- |
| `Drupal\redis\Flood\PhpRedis` (`:91`) | **no-op** (`// No garbage collection necessary.`) | `28737c2e…` |
| `Drupal\redis\Flood\Predis` (`:91`) | **no-op** (identical) | `d63cbb89…` |
| `Drupal\redis\Flood\Relay` | **no-op** — `class Relay extends PhpRedis`, inherits it | `23bc6a1c…` |

So whichever implementation `getClientName()` returns, the GC call does nothing. The flood module's
rate-limit keys are expired by Redis TTL on the set/register path (`RedisPrefixTrait` only prefixes
keys; it has no GC), which is why GC is declared unnecessary. The `protected garbageCollection()`
methods under `src/Queue/*` are queue-backend methods, **not** reachable from `system_cron`'s flood
call.

### 17.4 Version / provenance

- Core **10.6.16** (`core/lib/Drupal.php:78`).
- `drupal/redis` **1.11.0** (`redis.info.yml:10` `8.x-1.11`; `composer.lock` `version: 1.11.0`).
- File fingerprints: `FloodFactory.php` `42e9f49b…`, `Flood/PhpRedis.php` `28737c2e…`,
  `Flood/Predis.php` `d63cbb89…`, `Flood/Relay.php` `23bc6a1c…`.

This is a **source** fact and does not depend on any container generation; it is stable as long as
these files are unchanged. No live state was read, so there is nothing generation-scoped to re-measure
for this item.

### 17.5 Status

**P11 adds no stop condition and changes no finding.** It closes §16.3's residual: `system_cron`'s
flood GC is confirmed inert. With this, every effect of the first-request cron run is established from
source and state — the four outbound calls, the one `/tmp/.htaccess` create, the infrastructure-table
GC/writes (`key_value_expire`, `queue`, `node`/`comment` state), and the measured-zero business-data
paths — with **no remaining `REQUIRES_VERIFICATION` in the cron-effects review.**

**Unchanged:** **B5 remains diagnosed and prepared, but not repaired.** **B2, B3, B4 and B6 remain
ungranted.** **Option A and Option B remain NOT AUTHORIZED.** **Task 26B default-site activation
remains BLOCKED / NOT AUTHORIZED.** **Task 25A verdict B and Tasks 25B–25D remain BLOCKED.**

**The P5 decision (§6 item 5) remains the user's to make.** This task authorized no service start, no
repair, and no activation.

---

## 18. Gate assessment B2/B3/B4/B6 and execution-prerequisite pack — Task 26B-P12, 2026-10-10

Read-only assessment. Prepared **2026-10-10** (America/New_York). §§1–17 preserved verbatim. **No
Docker, DDEV, Drush, Drupal bootstrap, database/Redis query, cron, HTTP request, service start,
container lifecycle action, test, or external call was run; no source, configuration, backup,
database, container or runtime state was created, modified, cloned, restored or cleaned.** This
section approves no gate and authorizes no execution.

### 18.1 Owner P5 acceptance — recorded, narrowly scoped (append-only)

> **2026-10-10 (America/New_York) — the owner has ACCEPTED the disclosed first-request cron
> effects** as documented in §§10.3, 11, 15, 16 and 17: four outbound metadata calls
> (update, announcements, Commerce inbox, security advisories), one create-if-absent
> `/tmp/.htaccess`, infrastructure-table GC/writes (`key_value_expire`, `queue` `drupal_batch:%`,
> `node`/`comment` state), the empty-`history` delete, and the measured-zero business-data paths —
> with the Redis flood GC confirmed inert.

**Scope of this acceptance, explicitly:** it closes the **P5 decision (§6 item 5)** on the cron
effects **only**. It does **not** authorize Option A, Option B, service startup, `/start.sh`,
PHP-FPM start, repair, activation, `updatedb`, update hooks, cache invalidation, migration, backup,
restore, clone, or any other mutation. It does **not** satisfy or weaken B1, B2, B3, B4, B5 or B6.
Prior statements in §§1–17 stand unchanged; this is an append-only note.

### 18.2 Gate-status matrix

| Gate | Meaning | Status | Closure criterion (NEXT_EXECUTION_PROMPT §6) | What is missing |
| --- | --- | --- | --- | --- |
| **B1** | isolated rehearsal target for `10046→10047` exists & approved | **OPEN** | a named target exists and is approved | no third target exists — only `sites/default` + `sites/qa` (READINESS §5.1); QA is ahead at 10049 and cannot rehearse |
| **B2** | owner approves the whole activated wiring set | **OPEN — inventory prepared for review (§18.3)** | explicit owner approval of the full set, acknowledging it goes live at `updatedb` | owner approval not given; this task only inventories |
| **B3** | runtime atomicity + safe disposal after failed rollback | **BLOCKED (§18.4)** | real-database correctness evidence | only source structure is established; runtime proof absent |
| **B4** | complete private backup + exercised restoration | **OPEN (§18.5)** | backup taken and restore into an approved target exercised | no backup created or rehearsed (every task read-only) |
| **B5** | web container health / `phpstatus`+`mailpit` | **BLOCKED — diagnosed & prepared, not repaired** | failure resolved under separate authorization, or plan amended off-HTTP | supervisord not started (DIAGNOSIS); repair = Option A, NOT AUTHORIZED; P1 re-measure also required |
| **B6** | isolation target manifest verified per component | **OPEN (§18.6)** | every boundary VERIFIED on a real target | no target exists; shared components are NOT ISOLATED / UNKNOWN / PARTIAL |

No gate is closed. B2/B3/B4/B6 remain open or blocked, as detailed below.

### 18.3 B2 — activated wiring set, inventoried for owner review (NOT approved)

The default site's compiled container was built **2026-10-01** (§2.3) and holds **15** arguments for
`dispatch_checkout_manager`. A rebuild compiles from the current working tree, so the activated set
is the working-tree delta against that stale container. All inputs are **uncommitted** (HEAD
`b49c259e`; nothing staged).

**Central service — `custom_webform_handlers.dispatch_checkout_manager`:** **16** promoted
`readonly` constructor arguments (`src/Commerce/DispatchCheckoutManager.php`), the 16th being
`@database` (`Connection`) — the transaction owner (§18.4). Its consumers:
`DispatchPaymentProcess.php:86` (checkout pane) and, via `dispatch_checkout_starter`
(`services.yml:718-726`), `ReviewPaymentForm.php:77` and `AddressDistanceHandler.php:309` (§P10 §16;
P6 referrer closure). None is cron-reachable.

| Input class | Change | Git state | Fingerprint (sha256 head) |
| --- | --- | --- | --- |
| `custom_webform_handlers.services.yml` | the 16-arg definition + Task 24B/24C/24H service defs | modified | `920581e3…` (= §2.4) |
| `custom_webform_handlers.install` | hooks 10047/10048/10049 | modified | `172d25b0…` (= §2.4) |
| `src/Commerce/DispatchCheckoutManager.php` | 16th arg `@database`, transaction owner | modified | `b7251503…` (= §2.4) |
| `config/schema/custom_webform_handlers.schema.yml` | config schema | modified | `7fe85c2a…` |
| `config/install/custom_webform_handlers.settings.yml` | install config | modified | `74a809d7…` |
| `src/Checkout/CheckoutAttemptStore.php` | 24B snapshot columns gate | modified | `cc464e68…` |
| `src/Checkout/QuoteSnapshot.php` | 24B immutable snapshot | modified | `2377cc61…` |
| `src/Checkout/AcceptedJob.php` | 24B accepted-job value object | **untracked (new)** | `a13ee4c3…` |
| `src/Dispatch/PreparedHold.php` | 24C prepared hold | **untracked (new)** | `948ba8aa…` |
| `src/Dispatch/Admission/` (7 files) | 24C booking-admission store + value objects | **untracked (new)** | per-file (not expanded) |

**Scope summary (names only, for owner review):** **26** tracked-modified module files (21 `src/*`,
5 `*.yml`/`.install`) plus **11** untracked `src/*` inputs (`AcceptedJob`, `PreparedHold`, 7 under
`Admission/`, and routing `Route*`/`Routing*`/`FixedDevelopmentRouteProvider` — the Task 20/24H
multi-provider distance chain). Two workstreams become active together: **24B/24C** (checkout
attempt + immutable snapshot + booking admission + `@database` transaction ownership) and **24H**
(routing cost boundary / distance chain). **Prepared for owner review; B2 is NOT marked approved.**

### 18.4 B3 — transaction correctness: **BLOCKED**

**What existing evidence establishes (source only).** `DispatchCheckoutManager` opens one
caller-owned writer transaction on `@database` (`:369`), writes the guarded Hold claim, the draft
order/item, and the Checkout Attempt inside it, verifies the commit before publishing the receipt
(`:535`→), and distinguishes four rollback outcomes (`:388-510`, `:602`). This is **structure,
ordering, and completion checks** — established by reading source.

**What it does NOT establish.** The class's own docblocks record **real-database atomicity as
PENDING** and **safe disposal after a *failed* rollback as BLOCKED**, and the
25A bridge decision records the same
(`commerce-webform-bridge/task-25a-bridge-compatibility/DECISION.md:220-232`, citing 24C-8C-R2):
"whether they [the three writes] do [commit or roll back together], under failure and concurrency,
is REQUIRES_VERIFICATION and is not claimed here as established." No prototype, simulated, or
real-database evidence of atomicity-under-failure exists.

**Evidence-class ladder (none above source reached for B3):** source reasoning ✓ · prototype ✗ ·
simulated ✗ · **real-database ✗ (required)**.

**Smallest future evidence plan (state-changing — not performed, Gate-1 target required):**
1. On an **approved isolated target** (B1/B6), drive the checkout path to the point where Hold +
   draft order + attempt are written, force a mid-transaction failure, and confirm **all three roll
   back together** with no residual Hold, order, attempt, or guard identity.
2. Force a **failed rollback** (e.g. a connection drop / nested-transaction edge) and confirm **safe
   disposal**: no half-committed state, no orphaned receipt, guard identity released.
3. Repeat under concurrency to probe the `FOR UPDATE`/single-writer claim.

**Why a backup or a successful restore cannot substitute (B4≠B3):** a restore proves a prior state
can be *recovered*; it says nothing about whether the transaction path leaves a *consistent* state
under failure in the first place. Recoverability is not correctness. B3 must be closed by evidence of
the path's behaviour, not by the existence of an undo.

### 18.5 B4 — backup completeness and restoration: **OPEN**

**No backup artifact or restoration rehearsal is named in any readiness document or established
evidence**, because every task in this chain (26A, 26A-R1, 26B-P1…P12) has been read-only; §5 Step 1
of NEXT_EXECUTION_PROMPT is explicitly a *prepared, unexecuted* step. No broad filesystem search was
performed and no backup was created, opened, copied, or restored.

**Future backup manifest (from READINESS §5.2), to be produced before any mutation:**

| Item | Why |
| --- | --- |
| `db` database (schema + data), consistent snapshot | hooks mutate schema; a restore path must exist |
| `sites/default/files` (public) + any private path | the restore unit must be whole |
| the three §2.4 source fingerprints | prove the executed code is the assessed code |
| the default site's compiled-container key (§2.2) | record the pre-change container |

**Restoration-rehearsal prerequisites:** the backup must be **private** (not committed, not printed)
and its restoration must be **exercised into the approved isolated target** (B1/B6) and recorded with
identity/relationship-integrity checks (§6.2 keyed method), not counts. Until both the backup and an
exercised restore exist, B4 stays OPEN.

### 18.6 B6 — isolation manifest, per component (no target exists)

Isolation is **not inferred** from a clone, `--uri`, a Redis prefix, or a proposed manifest. No
isolated target exists, so no boundary can be VERIFIED-as-isolated; each is marked by what the
evidence actually shows.

| Component (READINESS §5.1) | Status | Dated evidence + scope |
| --- | --- | --- |
| Code + `vendor/` ownership | **NOT ISOLATED** | One tree, one `vendor/`, serves both sites (25A §4). Host==container fingerprints (P9, 2026-10-10). A rehearsal that edits code edits the live site's code. |
| Database + credentials | **NOT ISOLATED (no clone)** | `db` vs `qa` are separate schemas but on the **same server, user, password** (`sites/qa/settings.php` reuses the default DDEV include, P7). No `db` clone exists; DBHub `dbhub_ro` reaches only `db` (P5). |
| URI / settings / files | **PARTIAL** | Per-site paths verified: `sites/default/files` vs `sites/qa/files` + `sites/qa/private` (P7 §4). But only two sites exist; **no isolated third target**. |
| Cache + compiled-container namespace | **UNKNOWN** | Shared Redis **instance**; separation is a **key-prefix convention**, not an instance boundary (P2 §10.3; keys enumerated P9). Whether a default-site flush can touch QA's `container` bin is unproven. |
| Sessions, queues, cron, workers | **UNKNOWN / shared** | Containers shared. Cron fires on the first HTTP request and is 19-day overdue (P3/P5/P6); `queue` empty now but shared. Drush disables cron for its own process only. |
| Outbound email | **NOT ISOLATED (sink down)** | `mailpit:FAILED` across three restarts (P1/P9); the local sink is down and where mail would go is unproven (P6: no scheduled-email module enabled, so no mail is *generated* by cron). |
| Webhooks / routing / payments | **PARTIAL-VERIFIED** | `development_fixed` intact on both sites incl. FPM `clear_env = no` so `getenv` resolves (P6 §8.3); **0** payment gateways (P5 §6.1). Safeguards hold, but must be re-asserted **inside** any future target. |

**B6 stays OPEN.** Required future verification: create a named target, then verify each row **on that
target** — especially the Redis `container`-bin namespace boundary and the sessions/queues/cron
boundary, which are the two UNKNOWNs most able to leak between the rehearsal and the live sites.

### 18.7 Execution-prerequisite sequence

The `updatedb` boundary itself invalidates the default compiled container (§10.2); **there is no
separately gated later `drush cr`.** So B2 and B3 gate `updatedb`, not a subsequent rebuild.

**Stage 1 — Gate 1 (isolated setup + rehearsal). Prerequisites, in order:**
1. Owner decision to proceed toward execution at all (distinct from the §18.1 cron-effects
   acceptance, which does not authorize this).
2. **B6** — write the isolation target manifest and **verify every boundary on a real target**
   (§18.6), closing the UNKNOWNs.
3. **B1** — the named `10046→10047` target exists and is approved.
4. **B4** — take the private backup (§18.5) and **exercise** restoration into that target.
5. Run the §4 diagnostics and §6.2 preservation checks **green in the rehearsal** (keyed method).
   → Passing Stage 1 closes **B1, B4, B6**. It closes neither B2 nor B3 nor B5.

**Stage 2 — Gate 2 (default-site activation). Prerequisites, in order:**
6. **B2** — explicit owner approval of the full activated wiring set (§18.3), acknowledging it goes
   live at `updatedb`.
7. **B3** — real-database correctness evidence (§18.4). A backup/restore does not substitute.
8. **B5** — the web container's `phpstatus`/`mailpit` failure resolved under separate authorization,
   or the plan amended off-HTTP; plus the **P1 re-measure** (§14 / PRECHECK-P2 §7), because the
   compiled-container validity is generation-scoped and the stack has restarted.
9. **Preservation baseline** (READINESS §6.1) captured immediately before Step 3, §6.2 checks ready.
10. Re-confirm immediately before Step 3: pending-hook manifest is exactly
    `10047/10048/10049`; **no** post-update pending site-wide; the three §2 source fingerprints
    unchanged; and the five §10.1 cron stop-conditions still safe (§14.4).

**Stage 3 — the execution point.** Only when **every** item 1–10 is satisfied and a **separate,
explicit, written execution authorization** names the exact default target and the exact three hooks
may `updatedb` be considered. This task does not grant it, and nothing short of all of Stages 1–2
plus that authorization reaches it.

### 18.8 Status

**B2, B3, B4 and B6 remain open or blocked** — closure criteria are unmet for each (§18.2). The P5
cron-effects acceptance (§18.1) is recorded with its narrow scope and satisfies none of them. **No
service, repair, migration, backup, restore, clone, cache invalidation, or activation was performed.**

**Unchanged:** **B5 remains diagnosed and prepared, but not repaired.** **Option A and Option B
remain STRICTLY NOT AUTHORIZED.** **Task 26B default-site activation remains BLOCKED / NOT
AUTHORIZED.** **Task 25A verdict B and Tasks 25B–25D remain BLOCKED.**

---

**Reminder: this file is a proposal. Nothing in it has been executed, and nothing in it may be
executed until the §6 authorizations are granted for the exact option named. Task 26B default-site
activation remains BLOCKED / NOT AUTHORIZED.**
