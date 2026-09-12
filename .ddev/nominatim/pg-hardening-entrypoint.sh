#!/bin/sh
# Boot-time hardening gate for the private Nominatim service's PostgreSQL.
#
# Project-owned, bind-mounted read-only, and set as the container's `command:`
# so it runs before the image's own entrypoint. It forces `listen_addresses` to
# loopback, refuses to start the service at all if the configuration mounts did
# not land, and then hands over to `/app/start.sh` unchanged.
#
# WHY A SCRIPT AND NOT ONLY A conf.d DROP-IN
#
# `zz-ppu-hardening.conf` on its own is inert in this image. Read from the
# image's own postgresql.conf, not assumed:
#
#     817: include_dir = 'conf.d'
#     828: listen_addresses='*'
#
# The image appends its setting *after* the include, and PostgreSQL takes the
# last assignment, so a drop-in cannot win. There is no second `include_dir`, no
# active `include_if_exists`, and no script in /app mentions postgresql.conf,
# listen_addresses or pg_hba at all -- the image offers no override hook.
# `ALTER SYSTEM` would win but writes into the database volume rather than into
# this repository, and `service postgresql start` (init.sh:86, start.sh:41)
# passes no `-c` options. Rewriting the one line at boot is what remains, and it
# is re-applied on every start, so a container recreate or a volume deletion
# cannot quietly undo it.
#
# The drop-in is still mounted and stays: if a future image stops pinning `'*'`,
# the drop-in becomes the effective setting and already says localhost.
#
# WHY THE ASSERTIONS ARE FATAL
#
# A mistyped mount target does not fail. Docker creates a directory at the
# target, PostgreSQL falls back to the image's own pg_hba.conf, and that file's
# last rule is `host all all 0.0.0.0/0 md5` -- any role, any database, any
# address. That failure mode is invisible in a container that otherwise looks
# healthy, so it is checked here and it stops the boot. No listener is better
# than an open one.
#
# Every rule check strips comment and blank lines first. This project's own
# pg_hba.conf explains the broad rule in a comment, so a naive
# `grep -c '0.0.0.0/0'` matches the explanation and reports a false positive.
#
# LF line endings only; authored on Windows, and CRs here break the shell.
set -eu

PGCONF=/etc/postgresql/16/main/postgresql.conf
PGHBA=/etc/postgresql/16/main/pg_hba.conf
DROPIN=/etc/postgresql/16/main/conf.d/zz-ppu-hardening.conf
TUNING=/etc/postgresql/16/main/conf.d/postgres-tuning.conf

fail() { echo "ppu-hardening: FATAL: $1" >&2; exit 1; }
effective() { grep -vE '^[[:space:]]*(#|$)' "$1" || true; }

# The mounts landed, and conf.d was mounted per file rather than per directory.
[ -f "$PGHBA" ] || fail "pg_hba.conf mount did not land (target is not a regular file)"
[ -f "$DROPIN" ] || fail "zz-ppu-hardening.conf mount did not land"
[ -f "$TUNING" ] || fail "postgres-tuning.conf is missing -- conf.d was mounted as a directory"

# The three authentication invariants, comment-blind.
if effective "$PGHBA" | grep -q '0\.0\.0\.0/0'; then
  fail "a broad host rule is active in pg_hba.conf"
fi
if ! effective "$PGHBA" | grep -qE '^local[[:space:]]+all[[:space:]]+all[[:space:]]+peer'; then
  fail "unix-socket peer rule missing -- the importer and API would lose access"
fi
if ! effective "$PGHBA" | grep -qE '^host[[:space:]]+all[[:space:]]+all[[:space:]]+127\.0\.0\.1/32[[:space:]]+scram-sha-256'; then
  fail "loopback scram rule missing -- the credential could not be verified"
fi

# Force the listener to loopback, then prove no non-loopback value survives in
# either file.
sed -i "s|^[[:space:]]*listen_addresses[[:space:]]*=.*|listen_addresses = 'localhost'|" "$PGCONF"
if effective "$PGCONF" | grep -E '^[[:space:]]*listen_addresses' | grep -qv "'localhost'"; then
  fail "listen_addresses in postgresql.conf is not loopback-only"
fi
if ! effective "$DROPIN" | grep -qE "^[[:space:]]*listen_addresses[[:space:]]*=[[:space:]]*'localhost'"; then
  fail "the drop-in no longer pins listen_addresses to localhost"
fi

echo "ppu-hardening: pg_hba mounts verified, listen_addresses forced to loopback"
exec /app/start.sh "$@"
