'use strict';

// Shared locator + state helper for the project-local @bytebase/dbhub MariaDB
// connection-pool cap patch (Task 24B prerequisite). Node built-ins only, so
// both the provisioning script and the MCP launcher can require it without any
// dependency of their own. Read-only except where provision.cjs writes.

const fs = require('fs');
const path = require('path');

// The reviewed upstream versions. provision.cjs refuses to patch anything else
// so an unreviewed connector is never silently modified.
const EXPECTED_DBHUB_VERSION = '1.2.3';
const REVIEWED_MARIADB_VERSION = '3.5.4';

// The exact reviewed call site and its patched form. mariadb.createPool() is
// called with the connector's connectionConfig object, which carries no
// pool-size key, so the driver defaults (connectionLimit=10, minimumIdle=10,
// eager prewarm) apply. The patch caps that one pool without touching auth,
// TLS, read-only handling or the finite acquireTimeout default.
const NEEDLE = 'this.pool = mariadb.createPool(connectionConfig);';
const PATCHED =
  'this.pool = mariadb.createPool({ ...connectionConfig, connectionLimit: 2, minimumIdle: 0, idleTimeout: 60 });';

function dbhubRoot(toolsDir) {
  return path.join(toolsDir, 'node_modules', '@bytebase', 'dbhub');
}

function readJson(file) {
  return JSON.parse(fs.readFileSync(file, 'utf8'));
}

// The mariadb driver that this dbhub install actually resolves (not a global or
// hoisted copy). require.resolve honours the same lookup dbhub's import uses.
function mariadbDriverVersion(dbhubDir) {
  const entry = require.resolve('mariadb', { paths: [dbhubDir] });
  let dir = path.dirname(entry);
  while (dir !== path.dirname(dir)) {
    const manifest = path.join(dir, 'package.json');
    if (fs.existsSync(manifest)) {
      const parsed = readJson(manifest);
      if (parsed.name === 'mariadb') {
        return parsed.version;
      }
    }
    dir = path.dirname(dir);
  }
  throw new Error('could not resolve the mariadb driver package.json from ' + entry);
}

// dbhub ships the connector as a content-hashed chunk (dist/mariadb-<hash>.js).
// Locate it by content, never by a hardcoded hash, so a rebuild that changes
// the hash does not break provisioning.
function locateConnector(dbhubDir) {
  const distDir = path.join(dbhubDir, 'dist');
  const candidates = fs
    .readdirSync(distDir)
    .filter((name) => /^mariadb-.*\.js$/.test(name));
  const matches = candidates.filter((name) => {
    const content = fs.readFileSync(path.join(distDir, name), 'utf8');
    return content.includes(NEEDLE) || content.includes(PATCHED);
  });
  if (matches.length !== 1) {
    throw new Error(
      'expected exactly one MariaDB connector under dist/ (matched ' +
        matches.length +
        ': ' +
        (matches.join(', ') || 'none') +
        ')',
    );
  }
  return path.join(distDir, matches[0]);
}

// 'patched' | 'unpatched' | 'drift'. PATCHED is checked first because the
// patched form does not contain the exact NEEDLE substring.
function connectorState(connectorFile) {
  const content = fs.readFileSync(connectorFile, 'utf8');
  if (content.includes(PATCHED)) {
    return 'patched';
  }
  if (content.includes(NEEDLE)) {
    return 'unpatched';
  }
  return 'drift';
}

module.exports = {
  EXPECTED_DBHUB_VERSION,
  REVIEWED_MARIADB_VERSION,
  NEEDLE,
  PATCHED,
  dbhubRoot,
  readJson,
  mariadbDriverVersion,
  locateConnector,
  connectorState,
};
