#!/usr/bin/env bash
#
# Builds the isolated QA site: same codebase, separate database, no real data.
#
# The permanent browser gate must never run against the database that holds real
# and historical submissions. This creates a second Drupal site on this same DDEV
# project — ppu-qa.ddev.site — backed by a `qa` schema, seeded by cloning the
# live schema and configuration and then deleting every row that could contain a
# person. Cloning rather than installing from scratch is deliberate: a QA site
# built from a different configuration would prove something about that
# configuration instead of about this one.
#
# Idempotent: re-running rebuilds the QA database and leaves the default site
# untouched. The only writes to the default site's database are the reads
# performed by the export.
#
# Usage: bash qa/support/qa-env-setup.sh

set -euo pipefail

if [ ! -f .ddev/config.yaml ]; then
  echo "run this from the project root (no .ddev/config.yaml here)" >&2
  exit 1
fi

DUMP="${TMPDIR:-/tmp}/ppu-qa-clone-$$.sql"

cleanup() {
  # The dump is a copy of the live database, so it does not outlive this script
  # and never lands inside the repository.
  rm -f "$DUMP" "$DUMP.gz"
}
trap cleanup EXIT

echo "== 1/6 site directory =="
# config/sync is created here as well, because qa-site-settings.php declares
# $settings['config_sync_directory'] and Drupal writes a deny-all .htaccess into
# that directory on sight. If the directory is missing, that write fails, and the
# failure is not quiet: it raises an error-level requirement and logs a
# security-type error row on ordinary page requests, which reads as a red mutation
# sentinel on every browser gate this site runs.
mkdir -p sites/qa/files sites/qa/private sites/qa/files/config/sync
cp qa/support/qa-site-settings.php sites/qa/settings.php
echo "sites/qa/settings.php written from qa/support/qa-site-settings.php (git-ignored)"

echo "== 2/6 hostname =="
if ! grep -q 'additional_hostnames: \["ppu-qa"\]' .ddev/config.yaml; then
  echo "ppu-qa is not in .ddev/config.yaml additional_hostnames" >&2
  exit 1
fi
ddev start >/dev/null
echo "https://ppu-qa.ddev.site routed"

echo "== 3/6 clone schema and configuration =="
ddev export-db --gzip=false --file="$DUMP" >/dev/null
ddev import-db --database=qa --file="$DUMP" >/dev/null
cleanup
echo "qa database created from the live schema, dump deleted"

echo "== 4/6 scramble accounts =="
# sql:sanitize rewrites every user email and password hash and empties sessions,
# so the QA site cannot be signed into with a real credential.
ddev exec vendor/bin/drush --uri=https://ppu-qa.ddev.site sql:sanitize --yes >/dev/null
echo "user emails and password hashes scrambled"

echo "== 5/6 delete transactional rows =="
ddev exec vendor/bin/drush --uri=https://ppu-qa.ddev.site php:script qa/support/qa-reset.php

echo "== 6/6 verify =="
ddev exec vendor/bin/drush --uri=https://ppu-qa.ddev.site cr >/dev/null
ddev exec vendor/bin/drush --uri=https://ppu-qa.ddev.site php:script qa/support/mutation-state.php

echo
echo "QA site ready:  https://ppu-qa.ddev.site"
echo "reset fixtures: ddev exec vendor/bin/drush --uri=https://ppu-qa.ddev.site php:script qa/support/qa-reset.php"
