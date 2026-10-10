'use strict';

// Version-checked, idempotent provisioner for the project-local @bytebase/dbhub
// MariaDB connection-pool cap patch (Task 24B prerequisite).
//
//   node provision.cjs           apply the patch (idempotent); also the npm
//                                postinstall hook, so a clean install reapplies
//   node provision.cjs --check   verify only; never writes. Exit 0 iff patched
//
// Guarantees:
//   * validates the installed @bytebase/dbhub version and the actually-resolved
//     mariadb driver version before touching anything;
//   * applies exactly the reviewed one-line change to the single createPool call
//     site, and recognises an already-patched connector without rewriting it;
//   * fails explicitly (non-zero, secret-free) on any version or content drift;
//   * reapplies after a clean local install via the postinstall hook.
//
// It never connects to a database, never downloads anything, and never patches
// at MCP startup — provisioning time only.

const fs = require('fs');
const path = require('path');
const T = require('./patch-target.cjs');

const toolsDir = __dirname;
const checkOnly = process.argv.includes('--check');

function die(message) {
  process.stderr.write('dbhub provision: ' + message + '\n');
  process.exit(1);
}

function ok(message) {
  process.stdout.write('dbhub provision: ' + message + '\n');
}

const dbhubDir = T.dbhubRoot(toolsDir);
if (!fs.existsSync(dbhubDir)) {
  die(
    'the project-local @bytebase/dbhub is not installed under ' +
      path.relative(toolsDir, dbhubDir) +
      ' — run: npm --prefix .claude/tools/dbhub install',
  );
}

const dbhubVersion = T.readJson(path.join(dbhubDir, 'package.json')).version;
if (dbhubVersion !== T.EXPECTED_DBHUB_VERSION) {
  die(
    'expected @bytebase/dbhub ' +
      T.EXPECTED_DBHUB_VERSION +
      ', found ' +
      dbhubVersion +
      ' — refusing to patch a drifted version',
  );
}

let driverVersion;
try {
  driverVersion = T.mariadbDriverVersion(dbhubDir);
} catch (error) {
  die(error.message);
}
if (driverVersion !== T.REVIEWED_MARIADB_VERSION) {
  die(
    'the resolved mariadb driver is ' +
      driverVersion +
      ', not the reviewed ' +
      T.REVIEWED_MARIADB_VERSION +
      ' — revalidate createPool pool-option behaviour (connectionLimit, minimumIdle, idleTimeout) against this driver, then update REVIEWED_MARIADB_VERSION in patch-target.cjs before provisioning',
  );
}

let connectorFile;
try {
  connectorFile = T.locateConnector(dbhubDir);
} catch (error) {
  die(error.message);
}
const rel = path.relative(toolsDir, connectorFile);
const state = T.connectorState(connectorFile);

if (state === 'patched') {
  ok('already patched (' + rel + '); @bytebase/dbhub ' + dbhubVersion + ', mariadb ' + driverVersion);
  process.exit(0);
}

if (state === 'drift') {
  die(
    'unexpected connector content in ' +
      rel +
      ' — neither the reviewed createPool call nor the patched form is present; refusing to modify',
  );
}

// state === 'unpatched'
if (checkOnly) {
  die('connector ' + rel + ' is NOT patched (pool cap absent) — run: npm --prefix .claude/tools/dbhub run provision');
}

const before = fs.readFileSync(connectorFile, 'utf8');
const occurrences = before.split(T.NEEDLE).length - 1;
if (occurrences !== 1) {
  die('expected exactly one createPool call site in ' + rel + ', found ' + occurrences);
}

const after = before.replace(T.NEEDLE, T.PATCHED);
fs.writeFileSync(connectorFile, after);

if (T.connectorState(connectorFile) !== 'patched') {
  die('patch write did not take effect in ' + rel);
}

ok(
  'patched ' +
    rel +
    ': connectionLimit=2, minimumIdle=0, idleTimeout=60 (@bytebase/dbhub ' +
    dbhubVersion +
    ', mariadb ' +
    driverVersion +
    ')',
);
process.exit(0);
