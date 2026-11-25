#!/usr/bin/env node
/**
 * release-theme.js
 *
 * Pack the Koperasi Kita theme into a production-ready install zip.
 *
 * Flow — the pack stops dead when any step fails:
 *   1. version check: package.json and style.css must agree
 *   2. install JS dependencies (plain install; this repo has no lockfile,
 *      so never `ci` / `--frozen-lockfile`)
 *   3. typecheck (tsc --noEmit)
 *   4. production build (vite build) + dist manifest check
 *   5. stage the shippable files under a `koperasikita/` prefix
 *   6. zip, verify with `unzip -t`, print the SHA-256
 *
 * Usage:
 *   node release-theme.js        (or: bun run release)
 *
 * Output:
 *   releases/koperasikita-{version}.zip
 *
 * What ships: the theme exactly as WordPress installs it — PHP templates,
 * inc/, assets/, dist/ (the production build), languages/ (including the
 * compiled .mo), demo-content/ and README.md. Development-only files never
 * ship: TypeScript sources, package/composer metadata, CI config, docs.
 * There are no production Composer dependencies (composer.json only
 * declares the autoload map and dev tools), so no Composer step runs here.
 */

import { execSync, execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import {
  copyFileSync,
  existsSync,
  mkdirSync,
  mkdtempSync,
  readFileSync,
  readdirSync,
  rmSync,
  statSync,
} from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, resolve } from 'node:path';

// ---------------------------------------------------------------------------
// Config
// ---------------------------------------------------------------------------

const THEME_DIR = resolve(import.meta.dirname);
const THEME_SLUG = 'koperasikita';

/** Tracked files that never ship, even though they live in the repo. */
const EXCLUDED_DIRS = ['src/', '.github/', 'docs/', 'releases/'];
const EXCLUDED_FILES = new Set([
  '.gitignore',
  'bun.lock',
  'composer.json',
  'composer.lock',
  'package.json',
  'package-lock.json',
  'phpcs.xml',
  'phpcs.xml.dist',
  'release-theme.js',
]);

const RELEASES_DIR = resolve(THEME_DIR, 'releases');

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function run(cmd, label) {
  console.log(`\n> ${label}…`);
  console.log(`  $ ${cmd}`);
  execSync(cmd, { cwd: THEME_DIR, stdio: 'inherit' });
}

function hasCommand(cmd) {
  try {
    execSync(`command -v ${cmd}`, { stdio: 'ignore' });
    return true;
  } catch {
    return false;
  }
}

function formatSize(bytes) {
  if (bytes < 1024) return `${bytes} B`;
  const kb = bytes / 1024;
  if (kb < 1024) return `${kb.toFixed(1)} KB`;
  return `${(kb / 1024).toFixed(2)} MB`;
}

/** All files under a directory, as paths relative to that directory. */
function walkDir(dir, base = dir) {
  const out = [];
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    const full = resolve(dir, entry.name);
    if (entry.isDirectory()) out.push(...walkDir(full, base));
    else if (entry.isFile()) out.push(full.slice(base.length + 1));
  }
  return out;
}

function stageFile(stageTheme, relPath) {
  const dest = resolve(stageTheme, relPath);
  mkdirSync(dirname(dest), { recursive: true });
  copyFileSync(resolve(THEME_DIR, relPath), dest);
}

// ---------------------------------------------------------------------------
// Steps
// ---------------------------------------------------------------------------

console.log('');
console.log('==============================================');
console.log(`  Packing ${THEME_SLUG} — release zip`);
console.log('==============================================');

if (!hasCommand('zip') || !hasCommand('unzip')) {
  throw new Error('The `zip` and `unzip` commands are required to pack a release.');
}
if (!hasCommand('git')) {
  throw new Error('`git` is required: the shippable file list comes from `git ls-files`.');
}

// 1. Version check.
const pkg = JSON.parse(readFileSync(resolve(THEME_DIR, 'package.json'), 'utf8'));
const styleCss = readFileSync(resolve(THEME_DIR, 'style.css'), 'utf8');
const styleVersion = styleCss.match(/^Version:\s*(.+)$/m)?.[1].trim();
if (pkg.version !== styleVersion) {
  throw new Error(
    `Version mismatch: package.json says ${pkg.version}, style.css says ${styleVersion}. Fix one of them first.`,
  );
}
const VERSION = pkg.version;
const ZIP_NAME = `${THEME_SLUG}-${VERSION}.zip`;
const ZIP_PATH = resolve(RELEASES_DIR, ZIP_NAME);

// Warn (do not fail) on a dirty tree: the zip should come from a known state.
try {
  const dirty = execSync('git status --porcelain', { cwd: THEME_DIR, encoding: 'utf8' }).trim();
  if (dirty) console.log(`\n! Working tree has uncommitted changes:\n${dirty}\n  Continuing anyway — commit first if the zip must match HEAD.`);
} catch {
  // Status is best-effort; a failure here must not block the pack.
}

const head = execSync('git rev-parse --short HEAD', { cwd: THEME_DIR, encoding: 'utf8' }).trim();

// 2–4. Install, typecheck, build. Any failure throws and stops the pack.
const PM = hasCommand('bun') ? 'bun' : 'npm';
run(`${PM} install`, 'Install dependencies');
run(`${PM} run typecheck`, 'Typecheck');
run(`${PM} run build`, 'Production build');
if (!existsSync(resolve(THEME_DIR, 'dist/.vite/manifest.json'))) {
  throw new Error('dist/.vite/manifest.json is missing after the build — refusing to pack.');
}

// 5. Stage the shippable files.
const stageRoot = mkdtempSync(resolve(tmpdir(), 'kk-release-'));
const stageTheme = resolve(stageRoot, THEME_SLUG);
let staged = 0;
try {
  mkdirSync(stageTheme, { recursive: true });

  const tracked = execSync('git ls-files -z', { cwd: THEME_DIR, encoding: 'utf8' })
    .split('\0')
    .filter(Boolean);
  for (const file of tracked) {
    if (EXCLUDED_DIRS.some((d) => file.startsWith(d))) continue;
    if (EXCLUDED_FILES.has(file)) continue;
    stageFile(stageTheme, file);
    staged += 1;
  }

  // dist/ is gitignored, so it is not in `git ls-files` — stage it separately.
  for (const file of walkDir(resolve(THEME_DIR, 'dist'))) {
    stageFile(stageTheme, `dist/${file}`);
    staged += 1;
  }

  // 6. Zip + verify.
  mkdirSync(RELEASES_DIR, { recursive: true });
  if (existsSync(ZIP_PATH)) {
    rmSync(ZIP_PATH);
    console.log(`\nRemoved existing ${ZIP_NAME}`);
  }
  console.log(`\n> Creating ${ZIP_NAME} (${staged} files)…`);
  execFileSync('zip', ['-r', '-q', ZIP_PATH, THEME_SLUG], { cwd: stageRoot, stdio: 'inherit' });
  execFileSync('unzip', ['-t', '-q', ZIP_PATH], { stdio: 'ignore' });
} finally {
  rmSync(stageRoot, { recursive: true, force: true });
}

const sha256 = createHash('sha256').update(readFileSync(ZIP_PATH)).digest('hex');
const size = formatSize(statSync(ZIP_PATH).size);

console.log('');
console.log('----------------------------------------------');
console.log('  Release complete');
console.log(`  version:  ${VERSION}`);
console.log(`  head:     ${head}`);
console.log(`  files:    ${staged}`);
console.log(`  zip:      ${ZIP_PATH} (${size})`);
console.log(`  sha256:   ${sha256}`);
console.log('----------------------------------------------');
console.log('');
