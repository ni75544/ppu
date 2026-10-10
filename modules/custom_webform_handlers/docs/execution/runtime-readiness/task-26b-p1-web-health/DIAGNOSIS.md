# Task 26B-P1 — Web-Container Health Diagnosis (blocker B5)

Read-only diagnosis. Prepared 2026-10-08 (America/New_York). Repository `C:\Users\mo737\ppu`.

**No repair was performed.** No Drush command, no Drupal bootstrap, no migration, update hook, cache
rebuild, cron run, or worker. No container or service was started, stopped, restarted, recreated,
reloaded, or signalled. No code, `.ddev/`, configuration, dependency, or environment variable was
modified. No database, form, email, payment, or routing provider was touched. No scratch file was
created or deleted. Nothing was committed.

Governing instruction: [PROMPT_SPEC.md](PROMPT_SPEC.md). Blocker under diagnosis: **B5** in
[task-26a-readiness/NEXT_EXECUTION_PROMPT.md](../task-26a-readiness/NEXT_EXECUTION_PROMPT.md) §1,
evidence baseline in [task-26a-readiness/READINESS.md](../task-26a-readiness/READINESS.md) §10.4.

---

## 1. Verdict

**Both failures REPRODUCE.** They share **one established root cause**:

> The web container's supervisord is **not running**, so none of its three managed programs —
> `nginx`, `php-fpm`, `mailpit` — is running. Nothing is listening on port **80** or port **8025**,
> so both healthcheck requests fail to connect.
>
> This is not a misconfiguration. The container was **started on 2026-10-08T02:14:30Z without
> DDEV's `/start.sh` phase**, which is the only thing that launches supervisord. PID 1 is
> `/pre-start.sh` — DDEV's log-pipe helper, which by design blocks forever and starts no services.

Classification for both checks: **service unavailable**. Incorrect healthcheck target,
routing/configuration mismatch, and timeout/resource exhaustion are each **ruled out** by direct
observation (§5).

One consequence is load-bearing for Task 26B and is flagged in [REPAIR_PROMPT.md](REPAIR_PROMPT.md)
§4: the Redis container restarted in the **same second** as the web container, so whether the default
site's cached 15-argument compiled container survived is **unknown**. Restoring PHP-FPM without
checking that first risks letting the first HTTP request compile a fresh **16-argument** container
from the uncommitted working tree — which is precisely the activation that blockers **B2** and
**B3** gate.

---

## 2. Fresh failure signal

### 2.1 Container identity and state

| Field | Value |
| --- | --- |
| Name / ID | `ddev-ppu-web` / `6cd266577f75…` |
| Image | `ddev/ddev-webserver:v1.25.3-ppu-built` |
| State | `running` (`Running=true`), PID 6553 |
| **Created** | **2026-10-01T03:04:23Z** |
| **StartedAt** | **2026-10-08T02:14:30Z** |
| RestartCount / RestartPolicy | **0** / **`no`** |
| Health status | **`unhealthy`** |
| Failing streak | **11912** (was 10708 during Task 26A-R1 at ≈06:35Z — still climbing) |
| Observation time | 2026-10-08T07:03Z |

`Created` seven days before `StartedAt`, with `RestartCount=0`, means this is a **restart of a
pre-existing container**, not a fresh creation. That single fact explains the stale artefacts in
§4.2.

### 2.2 Healthcheck definition

From `docker inspect` (filtered): `CMD-SHELL /healthcheck.sh`, interval **1 s**, timeout **70 s**,
start period **240 s**, retries **70**.

### 2.3 Last five retained results — both checks failing, failing *fast*

| Start | Duration | Exit | Output |
| --- | --- | --- | --- |
| 07:03:19 | 1.0 s | 1 | `/var/www/html:OK mailpit:FAILED phpstatus:FAILED` |
| 07:03:21 | 0.0 s | 1 | identical |
| 07:03:22 | 1.0 s | 1 | identical |
| 07:03:24 | 0.0 s | 1 | identical |
| 07:03:25 | 1.0 s | 1 | identical |

Two readings follow immediately:

- **Not a timeout.** Each check completes in **0–1 s** against a **70 s** timeout. A hung or
  resource-starved service would consume the budget; an absent listener fails instantly.
- **`/var/www/html:OK`** every cycle — the code mount is healthy. This is **not** a Mutagen or mount
  fault, which the project has seen before.

Cadence is ≈1.5 s rather than the 59 s the script uses when previously healthy, because
`/tmp/healthy` is absent (`/healthcheck.sh:18-21`, removed at `:74` on every failure).

---

## 3. What each failing check actually measures

Read from the container's own `/healthcheck.sh`, nginx `monitoring.conf`, and the PHP-FPM pool.

| | **mailpit** | **phpstatus** |
| --- | --- | --- |
| Command | `curl --fail -s 127.0.0.1:8025` (`/healthcheck.sh:42`) | `curl --fail -s 127.0.0.1/phpstatus` (`:62`) |
| Gating | always runs | only when `DDEV_WEBSERVER_TYPE` ends in `-fpm` (`:61`); here `nginx-fpm`, so it runs |
| Host / port / protocol | HTTP, `127.0.0.1:8025`, path `/` | HTTP, `127.0.0.1:80`, path `/phpstatus` |
| Timeout | none in the curl call; bounded only by the 70 s Docker healthcheck timeout | same |
| Expected response | HTTP 2xx (`--fail` makes curl exit non-zero on ≥ 400) | HTTP 2xx |
| **Actual result** | **curl exit 7 — could not connect** | **curl exit 7 — could not connect** |
| Responsible service | `[program:mailpit]`, `/etc/supervisor/conf.d/mailpit.conf:1` | `[program:nginx]`, `supervisord-nginx-fpm.conf:4` → unix socket → `[program:php-fpm]`, `/etc/supervisor/php-fpm.conf:1` |
| Request path | Mailpit's own HTTP UI listener on 8025 | nginx `:80` → `fastcgi_pass unix:/run/php/php-fpm.sock` (`monitoring.conf:20`) → PHP-FPM status handler |

### 3.1 `/phpstatus` does not bootstrap Drupal — verified before requesting it

- `monitoring.conf:10-21` defines `location ~ ^/phpstatus$` with `fastcgi_pass
  unix:/run/php/php-fpm.sock` and `access_log off`.
- The PHP-FPM pool sets **`pm.status_path = /phpstatus`** (`/etc/php/8.4/fpm/pool.d/*.conf:258`) and
  `listen = /run/php/php-fpm.sock` (`:41`), matching nginx exactly.
- PHP-FPM therefore serves this path from its **internal status handler**. No Drupal file is
  included, no settings are read, and no database connection is made. There is no `phpstatus` script
  in the docroot for `SCRIPT_FILENAME` to reach.

This was confirmed **before** any request was issued. No Drupal application route — customer form,
checkout, reservation, or administrative — was requested, and no email was sent.

### 3.2 Bounded reproduction

One request per endpoint, 3 s timeout, exact healthcheck command shape:

| Request | curl exit | Meaning |
| --- | --- | --- |
| `curl --fail -s --max-time 3 127.0.0.1/phpstatus` | **7** | could not connect |
| `curl --fail -s --max-time 3 127.0.0.1:8025` | **7** | could not connect |
| Control: a port proven to be listening (Docker DNS, `127.0.0.11:41121`) | 52 | *connected*, empty reply |

The control is what makes exit 7 meaningful: curl is functional, and a listening socket yields a
different code (52, connected-but-empty) than an absent one (7). Exit 7 is therefore specifically
"no listener", not "curl broken", not **22** (HTTP error under `--fail`), and not **28** (timeout).

---

## 4. Root cause

### 4.1 Nothing that should be serving is running

| Observation | Command | Result |
| --- | --- | --- |
| Supervisor control | `supervisorctl status` | `unix:///var/run/supervisor/supervisor.sock refused connection` |
| Supervisor process | `pgrep -a supervisord` | **none** |
| Process table | `ps -eo pid,ppid,stat,etime,comm` | **PID 1 = `bash`**, elapsed `04:51:34` (= container uptime); PID 8 its child. **No `nginx`, no `php-fpm`, no `mailpit`.** Remaining PIDs are this diagnosis's own `docker exec` processes. |
| Listening TCP | `ss -lntp` | **only `127.0.0.11:41121`** — Docker's embedded DNS resolver. **Nothing on :80. Nothing on :8025.** |
| Listening unix | `ss -lxp` filtered for fpm/php | **no FPM listener** |
| Container logs | `docker logs --tail 12`, plus total line count | **10 lines for the whole container lifetime**, containing only `/pre-start.sh`'s own `set -x` trace (`logpipe`, `trap`, `cat`, `wait`) and the previous generation's shutdown tail. **Zero supervisord, nginx, php-fpm or mailpit output.** |

All three failing-or-absent services are supervisord-managed, and the program set is exactly three:
`nginx` (`supervisord-nginx-fpm.conf:4`), `php-fpm` (`/etc/supervisor/php-fpm.conf:1`), and `mailpit`
(`/etc/supervisor/conf.d/mailpit.conf:1`), pulled in by `[include] files = …` (`:1-2`). **One missing
supervisord accounts for both failing checks.** There is no cron or queue program in this container.

### 4.2 Why PID 1 is `bash`, and why that is *not* itself the defect

| Fact | Evidence |
| --- | --- |
| Container command is `/pre-start.sh` | `docker inspect`: `Path=/pre-start.sh`, `Cmd=["/pre-start.sh"]`, `Entrypoint=null` |
| The image's default command is `/start.sh` | `docker inspect ddev/ddev-webserver:v1.25.3-ppu-built`: `ImageCmd=["/start.sh"]` |
| **The override is DDEV's own, not out-of-band** | `.ddev/.ddev-docker-compose-base.yaml:96` → `command: /pre-start.sh` (DDEV-generated; also present in the full render at `:320-321`) |
| `/pre-start.sh` starts no services, by design | Image file, 821 B, dated Jul 4 with `/start.sh`. It creates the FIFO `/var/tmp/logpipe`, backgrounds `cat < ${logpipe}`, and `wait`s. It contains **no** reference to `start.sh`, `exec`, or `supervisor`. |
| `/start.sh` is the only thing that launches supervisord | `/start.sh:151` → `exec /usr/bin/supervisord -n -c "/etc/supervisor/supervisord-${DDEV_WEBSERVER_TYPE}.conf"` (6330 B, same image, never executed in this generation) |

So DDEV's design is deliberate: `/pre-start.sh` holds PID 1 as a log pipe, and DDEV invokes
`/start.sh` as a **separate phase** during its own start sequence. The defect is that **that phase
never ran for the current container generation.**

Corroboration from stale artefacts — a socket file is not a listening socket:

| Path | Timestamp | Reading |
| --- | --- | --- |
| `/run/php/php-fpm.sock`, `/run/php/php8.4-fpm.pid` | **Oct 1 03:05** | written ≈40 s after `Created` (Oct 1 03:04:23), when supervisord last ran properly |
| `/var/run/supervisor/supervisor.sock`, `supervisord.pid` | **Oct 1 03:05** | same generation |

These persist in the container's writable layer across stop/start. Their presence is why a
file-existence check would have been misleading: `ss` shows **no** listener behind either.

### 4.3 Trigger

The project stack was started at **02:14:30–02:14:31Z**: `ddev-ppu-web` at 02:14:30.401Z and
`ddev-ppu-redis` at 02:14:31.020Z — sub-second apart, with `RestartCount=0` and `RestartPolicy=no` on
both. That pattern is a **whole-stack container start outside DDEV** (host boot or Docker Desktop
restoring containers), not Docker's restart policy and not a single manual container start.

The precise initiator is **NOT ESTABLISHED** and is outside what read-only container inspection can
show. It does not change the cause or the repair.

### 4.4 Why it has persisted for the container's whole uptime

`/healthcheck.sh:23-29` is the image's self-heal path: if `php-fpm`, `nginx`, or `apache2` is in
supervisor **FATAL** state it prints `<service>:FATAL` and calls `supervisorctl shutdown`, so the
container exits and can be restarted.

**That path cannot fire here.** With no supervisord, `supervisorctl status` fails to *connect* rather
than reporting `FATAL`, so `grep -q FATAL` never matches — consistent with the observed output, which
contains no `FATAL` token. Combined with `RestartPolicy=no`, the container stays **running but
unhealthy indefinitely**, exactly as observed across the ≈4 h 49 m uptime.

### 4.5 Why PHP CLI succeeded in Task 26A while HTTP was dead

`docker exec … php` / `drush` forks a **fresh PHP CLI process**. It requires neither supervisord, nor
nginx, nor the FPM socket. This confirms Task 26A-R1's correction — CLI success does not prove FPM
health — and now supplies the mechanism. Equally, `phpstatus:FAILED` does **not** mean "PHP-FPM is
broken": PHP-FPM is simply **not started**, and PHP itself executes fine on the CLI path.

---

## 5. Ranked hypotheses and how each was settled

| Rank | Hypothesis | Verdict | Discriminating observation |
| --- | --- | --- | --- |
| 1 | **Service unavailable** — nginx, php-fpm and mailpit are not running | **ESTABLISHED** | No supervisord process; `supervisorctl` connection refused; no `nginx`/`php-fpm`/`mailpit` in the process table; only the Docker DNS port is listening; curl exit **7** on both endpoints; 10-line container log containing only `/pre-start.sh` |
| 2 | Incorrect healthcheck target | **RULED OUT** | nginx `fastcgi_pass unix:/run/php/php-fpm.sock` (`monitoring.conf:20`) matches pool `listen = /run/php/php-fpm.sock` (`:41`); requested path matches `pm.status_path = /phpstatus` (`:258`); 8025 is Mailpit's standard UI port. The targets are coherent — and this container was healthy on the same configuration on Oct 1. |
| 3 | Routing / configuration mismatch | **RULED OUT** | Failure is **pre-HTTP**: exit 7 (no connection), not 22 (HTTP error under `--fail`). No request ever reaches nginx or FPM to be mis-routed. |
| 4 | Timeout / resource exhaustion | **RULED OUT** | Checks return in **0–1 s** against a **70 s** timeout; curl exit 7, not 28. No service is present to be saturated. |
| 5 | Mount / sync fault (the project has a history of these) | **RULED OUT** | `/var/www/html:OK` on every cycle; `/healthcheck.sh`, `/pre-start.sh`, `/start.sh` all readable in-container |
| 6 | PHP-FPM crashed into supervisor FATAL | **RULED OUT** | No `FATAL` token in any healthcheck output, and supervisord is absent entirely, so no program can hold a FATAL state. The stale Oct 1 pidfile is a previous generation's, not a crash record. |

---

## 6. Residual uncertainty

| Item | Status |
| --- | --- |
| Which agent started the stack at 02:14:30Z (host boot, Docker Desktop restore, or manual) | **NOT ESTABLISHED** — outside read-only container inspection. Does not affect cause or repair. |
| Whether a subsequent start would succeed, or fail on some other condition | **REQUIRES_VERIFICATION** — cannot be known without performing a start, which this task forbids. |
| Whether the default site's cached **15-argument** compiled container survived the Redis restart at 02:14:31Z | **NOT ESTABLISHED** — deliberately not probed (no database or cache read was in scope). This is the first pre-repair check in [REPAIR_PROMPT.md](REPAIR_PROMPT.md) §3, because the answer determines whether restoring FPM would trigger the B2/B3-gated activation. |
| Whether Mailpit has any queued or lost mail from the outage window | **NOT ESTABLISHED** — Mailpit is a local sink with no listener; nothing was sent and no mail state was inspected. |
| Whether anything outside this container depends on the dead web endpoints | Browser/HTTP verification for Tasks 26B and later is blocked while this stands; no other dependency was enumerated. |

### 6.1 Honest note on side effects of this diagnosis

This task wrote nothing and started nothing, but it is not literally zero filesystem activity:

- Each `docker exec` creates and reaps processes inside the running container.
- The container's own healthcheck writes and removes `/tmp/healthy` roughly every 1.5 s
  (`/healthcheck.sh:71`, `:74`) **independently of this task**, and has done so throughout.
- The two diagnostic HTTP requests could not reach a server, so they produced no access-log entry;
  the `/phpstatus` location has `access_log off` in any case (`monitoring.conf:12`).

---

## 7. Scope limit

A healthy web container resolves **B5 only**. It does **not** resolve, weaken, or partially satisfy:

- **B2** — approval of the activated wiring set.
- **B3** — runtime atomicity and safe disposal after a failed rollback.
- **B4** — backup completeness and restoration capability.
- **B6** — a verified isolation target manifest.
- Task **26B default-site activation**, which remains **BLOCKED / NOT AUTHORIZED**.
- Task **25A verdict B** and Tasks **25B–25D**, which remain unchanged and **BLOCKED**.

---

## 8. Commands actually run (all read-only; exit codes as observed)

| Purpose | Command shape | Exit | Result |
| --- | --- | --- | --- |
| Query-first orientation | `graphify query …` | 0 | Established nothing; container/supervisor state is outside graph coverage |
| Wrapper availability | `command -v rtk`, `rtk --help` | 0 | `rtk docker` is the correct form; the `rtk proxy docker` shape used in earlier packs does not exist |
| Identity / state / health | `rtk docker inspect ddev-ppu-web --format …` | 0 | §2.1 |
| Healthcheck definition and results | `rtk docker inspect … --format '{{json .State.Health.Log}}'` | 0 | §2.2, §2.3 |
| Check definitions | `rtk docker exec … bash -c 'cat /healthcheck.sh'` | 0 | §3 |
| Supervisor control | `rtk docker exec … bash -c 'supervisorctl status'` | 0 | connection refused |
| Supervisor process | `rtk docker exec … bash -c 'pgrep -a supervisord'` | 0 | none |
| Process table | `rtk docker exec … bash -c 'ps -eo …'` | 0 | PID 1 = `bash`; no web services |
| Listening sockets | `rtk docker exec … bash -c 'ss -lntp / ss -lxp'` | 0 | only Docker DNS |
| Stale socket/pid artefacts | `rtk docker exec … bash -c 'ls -la /run/php* /var/run/supervisor/'` | 0 | Oct 1 timestamps |
| Command vs image default | `rtk docker inspect` on container and image | 0 | `/pre-start.sh` vs `/start.sh` |
| Override provenance | narrow `grep` on `.ddev/.ddev-docker-compose-*.yaml` (credential lines excluded) | 0 | DDEV-generated at `:96` |
| Shim and startup scripts | `rtk docker exec … bash -c 'cat /pre-start.sh'`, `grep … /start.sh` | 0 | §4.2 |
| Endpoint safety | `grep phpstatus /etc/nginx/`, `cat monitoring.conf`, `grep status_path pool.d` | 0 | §3.1 — no Drupal bootstrap |
| Supervisor program set | `grep '^\[program:'` across supervisor confs | 0 | exactly nginx, php-fpm, mailpit |
| **Bounded reproduction** | `curl --fail -s --max-time 3` ×1 per endpoint, plus one control | 7 / 7 / 52 | §3.2 |
| Logs | `rtk docker logs --tail 12`, line count | 0 | 10 lines, `/pre-start.sh` trace only |
| Shared-service timing | `rtk docker inspect ddev-ppu-redis --format …` | 0 | started 02:14:31Z, `RestartCount=0` |
| Sibling contrast | `rtk docker inspect` on `ddev-ppu-db`, `ddev-router`, `ddev-ppu-redis` | 0 | `db` and `router` **healthy**; their entrypoints are their own, unaffected |

`ddev` was never invoked. No Drush command was run. No container verb other than `inspect`, `logs`,
and `exec` (reads only) was used.

**Difficulty encountered:** Git Bash converted the container path `/healthcheck.sh` into a Windows
path on a bare `docker exec cat` (`cat: 'C:/Program Files/Git/healthcheck.sh': No such file`). Worked
around by wrapping container-side commands in `bash -c '…'`. No retry changed runtime state.
