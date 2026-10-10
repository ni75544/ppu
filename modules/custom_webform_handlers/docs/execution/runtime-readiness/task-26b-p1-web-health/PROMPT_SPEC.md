# Task 26B-P1 — Diagnose Web-Container Health Failures Without Changing Runtime State

Repository: `C:\Users\mo737\ppu`. Prepared: 2026-10-08 (America/New_York).

This file is the governing instruction for **Task 26B-P1**. It authorizes bounded read-only
diagnostics against the already-running environment, plus the three documents in this directory and
a navigation entry in the existing execution index. **It is diagnosis, not repair.**

Unchanged by this task: **Task 25A verdict B**, **Tasks 25B–25D BLOCKED**, and **Task 26B
default-site activation BLOCKED / NOT AUTHORIZED**. Task 26A-R1's documentation corrections are
accepted and are not reopened.

## Objective

Diagnose blocker **B5** from
[task-26a-readiness/NEXT_EXECUTION_PROMPT.md](../task-26a-readiness/NEXT_EXECUTION_PROMPT.md) §1:
the reported `phpstatus:FAILED` and `mailpit:FAILED` healthchecks on `ddev-ppu-web`.

## Authority and boundaries

Authorized: read-only Docker inspection, bounded `docker exec` reads, and at most three requests per
built-in read-only health endpoint after confirming the endpoint does not bootstrap Drupal.

Preserved unchanged (inspection only): scheduling, driver locks, transaction code, Commerce,
historical records, configuration, and routing safeguards.

Not authorized — and not performed:

- Any Drush command, or any Drupal bootstrap.
- Migrations, update hooks, cache rebuilds, cron, or workers.
- Starting, stopping, restarting, recreating, reloading, or signalling any container or service.
- Modifying application code, `.ddev/`, configuration, dependencies, or environment variables.
- Creating a rehearsal environment, restoring databases, or deleting scratch files.
- Submitting forms, sending email, triggering payments, or calling routing/geocoding providers.
- Automated suites, stress tests, continuous watchers, or long polling loops.
- Commits, resets, stashes, discarding dirty work, or memory edits.

Commands are RTK-prefixed. Only read-only Docker verbs were used; nothing that can start or recreate
the project was invoked. In particular **`ddev` was never run** — per the project's own record,
`ddev exec` on a not-started project recreates `web` and `db`.

## Required method

- Establish a **fresh** failure signal; if a failure no longer reproduces, record **NOT REPRODUCED**
  and do not manufacture a repair.
- For each failing check, establish: command and timeout; host, port, protocol, endpoint; expected
  versus actual response; and the responsible process or service.
- Do **not** infer from `phpstatus:FAILED` that all PHP-FPM traffic is unavailable. Do **not** infer
  from successful PHP CLI execution that HTTP/FPM is healthy.
- Rank hypotheses, then discriminate with targeted observations. Classify each failing check as:
  service unavailable / incorrect healthcheck target / routing-configuration mismatch / timeout or
  resource exhaustion / insufficient evidence.
- Do not dump complete Docker inspection output, environment variables, or unfiltered logs. Redact
  secrets. Normal diagnostic access-log entries may occur; do not describe the work as literally
  zero filesystem activity.
- Propose the **smallest** repair without implementing it. If a cause stays uncertain, name the next
  discriminating check and the permission it needs; avoid speculative repair lists.

A healthy web container addresses **B5 only**. It does not resolve transaction correctness (B3),
isolation (B6), backup/restore (B4), the activated wiring set (B2), or any activation gate.

## Evidence sources used

| Source | Used for |
| --- | --- |
| `rtk docker inspect` (format-filtered) | Container identity, state, create/start times, restart count and policy, health status, streak, healthcheck definition and last results |
| `rtk docker logs --tail` | Corroborating which startup phase ran |
| `rtk docker exec … bash -c` (bounded reads) | Process table, listening sockets, `supervisorctl status`, `/healthcheck.sh`, `/pre-start.sh`, `/start.sh`, nginx `monitoring.conf`, PHP-FPM pool, supervisor program set |
| `.ddev/.ddev-docker-compose-base.yaml` (narrow grep) | Whether the container command is DDEV-generated or an out-of-band override |
| Bounded `curl` inside the container | Exact reproduction of both healthcheck commands, one request per endpoint, 3 s timeout |

Graphify was queried first per the repository rule and established nothing here: container
infrastructure, supervisor configuration, and Docker state are outside its coverage. Every finding is
read from live inspection or from the named configuration file. GitNexus, Context7 and DBHub were not
applicable; DBHub remains **suspended** and no database was touched.

## Deliverables

Under `modules/custom_webform_handlers/docs/execution/runtime-readiness/task-26b-p1-web-health/`:

- `PROMPT_SPEC.md` — this file.
- `DIAGNOSIS.md` — fresh signal, per-check anatomy, ranked hypotheses, established cause, residual
  uncertainty.
- `REPAIR_PROMPT.md` — smallest repair proposal, marked **PROPOSED — NOT AUTHORIZED FOR EXECUTION**.

Plus a navigation/status entry in the existing execution index
([commerce-webform-bridge/README.md](../../commerce-webform-bridge/README.md)), which is where this
pack is indexed from.

## Verification

Validate documentation links, whitespace including untracked files (ordinary `git diff` does not
cover them), and `git diff --check`.

## Stop

Stop after the diagnosis and the saved repair proposal. **Repair and activate nothing.**
