#!/usr/bin/env node
/* Build the static-only artifact deployed to Cloudflare Pages. */
'use strict';

const fs = require('fs');
const path = require('path');

const ROOT = __dirname;
const OUT = path.join(ROOT, 'dist');
const FILES = [
  'index.html',
  'privacy.html',
  'manifest.webmanifest',
  'bundle.min.js',
  'sw.js',
  '_headers'
];
const DIRS = ['css', 'js', 'assets'];

function copyDir(src, dst) {
  fs.cpSync(src, dst, { recursive: true });
}

fs.rmSync(OUT, { recursive: true, force: true });
fs.mkdirSync(OUT, { recursive: true });

for (const file of FILES) {
  fs.copyFileSync(path.join(ROOT, file), path.join(OUT, file));
}
for (const dir of DIRS) {
  copyDir(path.join(ROOT, dir), path.join(OUT, dir));
}

console.log('Cloudflare Pages artifact:', OUT);
