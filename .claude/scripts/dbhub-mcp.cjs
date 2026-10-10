'use strict';

const { execFileSync, spawn } = require('child_process');
const fs = require('fs');
const path = require('path');

const projectRoot = path.resolve(__dirname, '..', '..');
const configPath = path.join(projectRoot, '.claude', 'dbhub.toml');
const dockerPath = 'C:\\Users\\mo737\\AppData\\Local\\Programs\\DockerDesktop\\resources\\bin\\docker.exe';
const dbhubToolsDir = path.join(projectRoot, '.claude', 'tools', 'dbhub');
const dbhubPath = path.join(dbhubToolsDir, 'node_modules', '@bytebase', 'dbhub', 'dist', 'index.js');

function fail(message) {
  process.stderr.write(`DBHub launcher: ${message}\n`);
  process.exit(1);
}

let config;
try {
  config = fs.readFileSync(configPath, 'utf8');
}
catch {
  fail('the private read-only connection file is unavailable');
}

const matches = [...config.matchAll(/^\s*dsn\s*=\s*"([^"]+)"\s*$/gm)];
if (matches.length !== 1) {
  fail('expected exactly one configured database source');
}

let dsn;
try {
  dsn = new URL(matches[0][1]);
}
catch {
  fail('the configured database source is invalid');
}

if (dsn.protocol !== 'mariadb:'
  || dsn.hostname !== '127.0.0.1'
  || dsn.username !== 'dbhub_ro'
  || dsn.pathname !== '/db') {
  fail('the configured source is not the expected loopback read-only account');
}

let publishedPort;
try {
  const mapping = execFileSync(
    dockerPath,
    ['port', 'ddev-ppu-db', '3306/tcp'],
    { encoding: 'utf8', windowsHide: true, stdio: ['ignore', 'pipe', 'ignore'] },
  ).trim();
  const match = mapping.match(/127\.0\.0\.1:(\d+)\s*$/);
  if (!match) {
    fail('the DDEV database has no verified loopback port mapping');
  }
  publishedPort = match[1];
}
catch {
  fail('the DDEV database is unavailable');
}

if (!fs.existsSync(dbhubPath)) {
  fail('the project-local DBHub runtime is not provisioned; run: npm --prefix .claude/tools/dbhub install');
}

// Resolve the project-local, patched runtime only. Never fall back to a global
// installation, download another version, or patch at startup: the connection-
// pool cap must already be provisioned on disk. These checks are read-only and
// never open a database connection.
let patchTarget;
try {
  patchTarget = require(path.join(dbhubToolsDir, 'patch-target.cjs'));
}
catch {
  fail('the project-local DBHub patch tooling is unavailable');
}

try {
  const connector = patchTarget.locateConnector(patchTarget.dbhubRoot(dbhubToolsDir));
  if (patchTarget.connectorState(connector) !== 'patched') {
    fail('the project-local DBHub connection-pool patch is not applied; run: npm --prefix .claude/tools/dbhub run provision');
  }
}
catch {
  fail('the project-local DBHub runtime could not be verified as patched');
}

dsn.port = publishedPort;
const child = spawn(
  process.execPath,
  [dbhubPath, '--transport', 'stdio'],
  {
    cwd: projectRoot,
    env: { ...process.env, DSN: dsn.toString() },
    stdio: 'inherit',
    windowsHide: true,
  },
);

child.once('error', () => fail('the pinned DBHub process could not start'));
child.once('exit', (code, signal) => {
  if (signal) {
    process.kill(process.pid, signal);
    return;
  }
  process.exit(code ?? 1);
});

