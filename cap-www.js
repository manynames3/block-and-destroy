#!/usr/bin/env node
/* ============================================================================
 * Squeak & Destroy — Capacitor webDir assembler.
 * Copies ONLY the files the native iOS app needs into `www/` (the Capacitor
 * `webDir`). The backend (*.php) stays REMOTE — the app talks to it via
 * CA.API_BASE (https://skynet.lospolo.hu/animals/), so no PHP is bundled.
 *
 *   node cap-www.js     ->  rebuilds ./www from the current sources
 *
 * Run AFTER `node build.js` (so bundle.min.js is fresh), then `npx cap sync ios`.
 * The `www/` folder is generated — it is git-ignored, never edit it by hand.
 * ==========================================================================*/
'use strict';
const fs = require('fs');
const path = require('path');

const ROOT = __dirname;
const WWW  = path.join(ROOT, 'www');

// Single files copied verbatim into www/.
const FILES = [
  'index.html',
  'manifest.webmanifest',
  'bundle.min.js',
  'privacy.html'        // in case an in-app link points at it relatively
];

// Whole directories copied recursively into www/.
const DIRS = ['css', 'js', 'assets'];

// NOTE: sw.js is intentionally NOT copied — under the capacitor:// scheme the
// service worker never registers (index.html gates it to http/https), so it is
// dead weight in the native build and could only confuse the WKWebView cache.

function rmrf(p) {
  if (fs.existsSync(p)) fs.rmSync(p, { recursive: true, force: true });
}
function copyDir(src, dst) {
  // fs.cpSync exists on Node >= 16.7; fall back to a manual walk otherwise.
  if (fs.cpSync) { fs.cpSync(src, dst, { recursive: true }); return; }
  fs.mkdirSync(dst, { recursive: true });
  for (const e of fs.readdirSync(src, { withFileTypes: true })) {
    const s = path.join(src, e.name), d = path.join(dst, e.name);
    if (e.isDirectory()) copyDir(s, d);
    else fs.copyFileSync(s, d);
  }
}

rmrf(WWW);
fs.mkdirSync(WWW, { recursive: true });

let nFiles = 0, nDirs = 0;
for (const f of FILES) {
  const src = path.join(ROOT, f);
  if (!fs.existsSync(src)) { console.warn('skip (missing):', f); continue; }
  fs.copyFileSync(src, path.join(WWW, f));
  nFiles++;
}
for (const d of DIRS) {
  const src = path.join(ROOT, d);
  if (!fs.existsSync(src)) { console.warn('skip (missing dir):', d); continue; }
  copyDir(src, path.join(WWW, d));
  nDirs++;
}

console.log('www/ assembled:', nFiles, 'files +', nDirs, 'dirs ->', WWW);
console.log('next: npx cap sync ios   (then npx cap open ios)');
