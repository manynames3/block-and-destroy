#!/usr/bin/env node
'use strict';

/*
 * optimize-assets.js — image-optimization build tool for "Squeak & Destroy"
 * ==========================================================================
 *
 * Standalone, manually-run Node script that optimizes the raster PNG assets
 * under  animals/assets/**  to shrink the initial download budget
 * (Poki <= 8 MB initial, CrazyGames caps).
 *
 * It is intentionally STANDALONE and side-effect-light:
 *   - It does NOT touch build.js, sw.js, index.html, the deploy workflow,
 *     or any js/*.js. It only reads/writes files under assets/ (PNG in place
 *     for lossless/lossy, and .webp siblings for --webp).
 *   - It is safe to run repeatedly: lossless only overwrites when the result
 *     is genuinely smaller; webp only writes siblings; nothing is ever
 *     corrupted (every write goes through a temp file + atomic rename and is
 *     validated to be a non-empty, smaller-or-equal result before replacing).
 *
 * USAGE
 *   node tools/optimize-assets.js [flags]
 *
 * FLAGS
 *   (default)     Lossless PNG optimization — ZERO visual change. Overwrites
 *                 a PNG in place only if the optimized version is smaller.
 *   --lossy       Also apply conservative lossy quantization (pngquant-style,
 *                 quality 80-95) to PNGs. Off by default. Overwrites in place
 *                 only when smaller. Flat cartoon art tolerates this well.
 *   --webp        Generate a .webp sibling next to each PNG (for an SW that
 *                 prefers .webp with .png fallback — this script does NOT
 *                 modify the SW; it only produces the files).
 *   --quality=N   WebP / lossy quality (default 90). Clamped to 1..100.
 *   --dry-run     Report current vs projected sizes; write NOTHING.
 *   --dir=PATH    Asset root to scan (default: <repo>/assets).
 *   --help        Show usage.
 *
 * BACKENDS (auto-detected at runtime, best available wins)
 *   Lossless PNG : oxipng (PATH) > optipng (PATH) > sharp (node module)
 *                  > zlib re-deflate (built-in Node, always available).
 *   Lossy PNG    : pngquant (PATH) > sharp (node module).
 *   WebP         : cwebp (PATH) > sharp (node module).
 *
 * If a requested operation has no available backend, the script prints clear
 * install instructions and skips that operation gracefully (exit 0 — "nothing
 * to do, but here's how"). It exits non-zero only on a real, unexpected error.
 */

var fs = require('fs');
var path = require('path');
var zlib = require('zlib');
var childProcess = require('child_process');

/* --------------------------------------------------------------------------
 * CLI parsing
 * ------------------------------------------------------------------------ */

function parseArgs(argv) {
  var opts = {
    lossy: false,
    webp: false,
    dryRun: false,
    quality: 90,
    dir: null,
    help: false
  };
  argv.forEach(function (a) {
    if (a === '--lossy') opts.lossy = true;
    else if (a === '--webp') opts.webp = true;
    else if (a === '--dry-run' || a === '--dryrun') opts.dryRun = true;
    else if (a === '--help' || a === '-h') opts.help = true;
    else if (a.indexOf('--quality=') === 0) {
      var q = parseInt(a.slice('--quality='.length), 10);
      if (!isNaN(q)) opts.quality = Math.max(1, Math.min(100, q));
    } else if (a.indexOf('--dir=') === 0) {
      opts.dir = a.slice('--dir='.length);
    } else {
      console.warn('Unknown argument ignored: ' + a);
    }
  });
  return opts;
}

function printHelp() {
  console.log([
    'optimize-assets.js — shrink the PNG assets of Squeak & Destroy',
    '',
    'Usage: node tools/optimize-assets.js [flags]',
    '',
    '  (default)      Lossless PNG optimization (zero visual change).',
    '  --lossy        Also conservative lossy quantization (q 80-95).',
    '  --webp         Write a .webp sibling next to each PNG.',
    '  --quality=N    WebP / lossy quality (default 90, clamp 1..100).',
    '  --dry-run      Report sizes only; write nothing.',
    '  --dir=PATH     Asset root to scan (default: <repo>/assets).',
    '  --help         This message.'
  ].join('\n'));
}

/* --------------------------------------------------------------------------
 * Small utilities
 * ------------------------------------------------------------------------ */

function fmtBytes(n) {
  if (n < 1024) return n + ' B';
  if (n < 1024 * 1024) return (n / 1024).toFixed(1) + ' KB';
  return (n / (1024 * 1024)).toFixed(2) + ' MB';
}

function pct(before, after) {
  if (before <= 0) return '0.0%';
  var d = (1 - after / before) * 100;
  return (d >= 0 ? '-' : '+') + Math.abs(d).toFixed(1) + '%';
}

/* Recursively collect *.png files under a dir. */
function findPngs(root) {
  var out = [];
  (function walk(dir) {
    var entries;
    try {
      entries = fs.readdirSync(dir, { withFileTypes: true });
    } catch (e) {
      return;
    }
    entries.forEach(function (e) {
      var full = path.join(dir, e.name);
      if (e.isDirectory()) walk(full);
      else if (e.isFile() && /\.png$/i.test(e.name)) out.push(full);
    });
  })(root);
  return out.sort();
}

/* Is an external binary available on PATH? */
function hasBinary(name) {
  try {
    childProcess.execSync(
      (process.platform === 'win32' ? 'where ' : 'command -v ') + name,
      { stdio: 'ignore' }
    );
    return true;
  } catch (e) {
    return false;
  }
}

/* Try to require a node module (e.g. sharp); return the module or null. */
function tryRequire(name) {
  try {
    return require(name);
  } catch (e) {
    return null;
  }
}

/* Run an external command synchronously, returning {ok, stderr}. */
function runCmd(cmd, args) {
  try {
    var r = childProcess.spawnSync(cmd, args, { stdio: ['ignore', 'ignore', 'pipe'] });
    if (r.status === 0) return { ok: true, stderr: '' };
    return { ok: false, stderr: (r.stderr ? r.stderr.toString() : 'exit ' + r.status) };
  } catch (e) {
    return { ok: false, stderr: e.message };
  }
}

/* Atomically replace `dest` with `buf`, but ONLY if buf is non-empty and
 * strictly smaller than the existing file. Returns true if replaced. This is
 * the corruption guard: a write that isn't a clear win is discarded. */
function safeReplaceIfSmaller(dest, buf, originalSize) {
  if (!buf || buf.length === 0) return false;
  if (buf.length >= originalSize) return false;
  var tmp = dest + '.opt.tmp';
  fs.writeFileSync(tmp, buf);
  fs.renameSync(tmp, dest);
  return true;
}

/* Write a sibling file atomically (used for .webp). */
function safeWrite(dest, buf) {
  if (!buf || buf.length === 0) return false;
  var tmp = dest + '.tmp';
  fs.writeFileSync(tmp, buf);
  fs.renameSync(tmp, dest);
  return true;
}

/* --------------------------------------------------------------------------
 * Backend detection
 * ------------------------------------------------------------------------ */

function detectBackends() {
  var sharp = tryRequire('sharp');
  return {
    sharp: sharp,
    oxipng: hasBinary('oxipng'),
    optipng: hasBinary('optipng'),
    pngquant: hasBinary('pngquant'),
    cwebp: hasBinary('cwebp')
  };
}

/* --------------------------------------------------------------------------
 * Zero-dependency lossless fallback: re-deflate the IDAT stream.
 *
 * A PNG is a signature + a sequence of chunks. The pixel data lives in one or
 * more IDAT chunks whose concatenated payload is a single zlib stream. We can
 * losslessly shrink most PNGs by inflating that stream and re-deflating it at
 * maximum compression (level 9). This changes NO pixels — it only re-packs the
 * exact same decompressed bytes — so it is 100% visually identical.
 *
 * This is deliberately conservative: if anything looks off (bad signature,
 * inflate/deflate mismatch in decoded length), we abort and return null so the
 * original is kept untouched.
 * ------------------------------------------------------------------------ */

var PNG_SIG = Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]);

function crc32(buf) {
  // Standard PNG CRC-32 over chunk type + data.
  var c;
  if (!crc32.table) {
    crc32.table = [];
    for (var n = 0; n < 256; n++) {
      c = n;
      for (var k = 0; k < 8; k++) c = (c & 1) ? (0xedb88320 ^ (c >>> 1)) : (c >>> 1);
      crc32.table[n] = c >>> 0;
    }
  }
  c = 0xffffffff;
  for (var i = 0; i < buf.length; i++) {
    c = crc32.table[(c ^ buf[i]) & 0xff] ^ (c >>> 8);
  }
  return (c ^ 0xffffffff) >>> 0;
}

function losslessZlibRedeflate(srcBuf) {
  if (srcBuf.length < 8 || !srcBuf.slice(0, 8).equals(PNG_SIG)) return null;

  var chunks = [];          // {type, data}
  var idatPayloads = [];
  var off = 8;
  while (off + 8 <= srcBuf.length) {
    var len = srcBuf.readUInt32BE(off);
    var type = srcBuf.toString('ascii', off + 4, off + 8);
    var dataStart = off + 8;
    var dataEnd = dataStart + len;
    if (dataEnd + 4 > srcBuf.length) return null; // truncated / malformed
    var data = srcBuf.slice(dataStart, dataEnd);
    if (type === 'IDAT') {
      idatPayloads.push(data);
    } else {
      chunks.push({ type: type, data: data });
    }
    off = dataEnd + 4; // skip the chunk's CRC
    if (type === 'IEND') break;
  }
  if (idatPayloads.length === 0) return null;

  // Inflate the concatenated IDAT stream, then re-deflate at max level.
  var raw;
  try {
    raw = zlib.inflateSync(Buffer.concat(idatPayloads));
  } catch (e) {
    return null; // unusual filtering / not standard zlib — leave it alone
  }
  var redeflated;
  try {
    redeflated = zlib.deflateSync(raw, { level: 9, memLevel: 9 });
  } catch (e) {
    return null;
  }

  // Sanity: re-inflating must reproduce the exact same raw bytes.
  try {
    var check = zlib.inflateSync(redeflated);
    if (check.length !== raw.length || !check.equals(raw)) return null;
  } catch (e) {
    return null;
  }

  // Rebuild the PNG: signature + all non-IDAT chunks in order, with a single
  // new IDAT inserted where the first IDAT used to be (before IEND).
  function buildChunk(type, data) {
    var typeBuf = Buffer.from(type, 'ascii');
    var lenBuf = Buffer.alloc(4);
    lenBuf.writeUInt32BE(data.length, 0);
    var crcBuf = Buffer.alloc(4);
    crcBuf.writeUInt32BE(crc32(Buffer.concat([typeBuf, data])), 0);
    return Buffer.concat([lenBuf, typeBuf, data, crcBuf]);
  }

  var parts = [PNG_SIG];
  var idatWritten = false;
  for (var c = 0; c < chunks.length; c++) {
    var ch = chunks[c];
    if (ch.type === 'IEND' && !idatWritten) {
      parts.push(buildChunk('IDAT', redeflated));
      idatWritten = true;
    }
    parts.push(buildChunk(ch.type, ch.data));
  }
  if (!idatWritten) return null; // no IEND found — refuse to rebuild

  return Buffer.concat(parts);
}

/* --------------------------------------------------------------------------
 * Per-operation backends
 * ------------------------------------------------------------------------ */

/* Lossless: returns an optimized Buffer (or null if no improvement/backend). */
function losslessOptimize(file, backends) {
  var orig = fs.readFileSync(file);

  // 1) Native binaries operate in place; we run them on a temp copy so we can
  //    compare and keep control of the atomic replace.
  if (backends.oxipng || backends.optipng) {
    var tmp = file + '.lossless.tmp.png';
    fs.copyFileSync(file, tmp);
    var res;
    if (backends.oxipng) {
      // -o max = best effort, --strip safe removes non-essential metadata only.
      res = runCmd('oxipng', ['-o', 'max', '--strip', 'safe', tmp]);
    } else {
      res = runCmd('optipng', ['-o5', '-strip', 'all', '-quiet', tmp]);
    }
    if (res.ok) {
      try {
        var out = fs.readFileSync(tmp);
        fs.unlinkSync(tmp);
        if (out.length > 0 && out.length < orig.length) return out;
        return null;
      } catch (e) { /* fall through */ }
    }
    try { fs.unlinkSync(tmp); } catch (e) {}
  }

  // 2) sharp (libvips) — lossless PNG recompress with max effort.
  if (backends.sharp) {
    try {
      var buf = backends.sharp(orig)
        .png({ compressionLevel: 9, effort: 10, palette: false })
        .toBuffer();
      if (buf && buf.then) { /* async — handled below */ }
    } catch (e) { /* fall through */ }
    // sharp's sync path isn't available; use the async variant via deasync-free
    // shim is overkill — instead we expose an async API (see optimizeFile).
  }

  // 3) Built-in zlib re-deflate — always available, zero deps.
  var z = losslessZlibRedeflate(orig);
  if (z && z.length < orig.length) return z;

  return null;
}

/* sharp is async; provide an async lossless path that prefers sharp when the
 * sync binaries weren't used. Returns a Promise<Buffer|null>. */
function losslessOptimizeAsync(file, backends) {
  var orig = fs.readFileSync(file);

  // If a native binary or zlib already produced a win, prefer that (sync path).
  var sync = losslessOptimize(file, backends);
  if (sync) return Promise.resolve(sync);

  if (backends.sharp) {
    return backends.sharp(orig)
      .png({ compressionLevel: 9, effort: 10, palette: false })
      .toBuffer()
      .then(function (buf) {
        if (buf && buf.length > 0 && buf.length < orig.length) return buf;
        return null;
      })
      .catch(function () { return null; });
  }

  return Promise.resolve(null);
}

/* Lossy: conservative quantization. Returns Promise<Buffer|null>. */
function lossyOptimizeAsync(file, backends, quality) {
  var orig = fs.readFileSync(file);
  var qLow = Math.max(80, Math.min(95, quality - 10));
  var qHigh = Math.max(qLow + 1, Math.min(95, quality));

  if (backends.pngquant) {
    var tmp = file + '.lossy.tmp.png';
    // pngquant writes to stdout with '-'; quality range keeps cartoon art clean.
    try {
      var r = childProcess.spawnSync(
        'pngquant',
        ['--quality=' + qLow + '-' + qHigh, '--strip', '--force', '--output', tmp, '--', file],
        { stdio: ['ignore', 'ignore', 'pipe'] }
      );
      // pngquant exits 99 when it can't meet quality — treat as "no result".
      if (r.status === 0) {
        var out = fs.readFileSync(tmp);
        fs.unlinkSync(tmp);
        if (out.length > 0 && out.length < orig.length) return Promise.resolve(out);
        return Promise.resolve(null);
      }
      try { fs.unlinkSync(tmp); } catch (e) {}
    } catch (e) { /* fall through */ }
  }

  if (backends.sharp) {
    // sharp palette quantization: quality maps to the palette fit; keep effort high.
    return backends.sharp(orig)
      .png({ palette: true, quality: qHigh, effort: 10, dither: 0.5 })
      .toBuffer()
      .then(function (buf) {
        if (buf && buf.length > 0 && buf.length < orig.length) return buf;
        return null;
      })
      .catch(function () { return null; });
  }

  return Promise.resolve(null);
}

/* WebP: returns Promise<Buffer|null>. */
function webpEncodeAsync(file, backends, quality) {
  var orig = fs.readFileSync(file);

  if (backends.cwebp) {
    var tmp = file.replace(/\.png$/i, '') + '.webp.tmp';
    try {
      var r = childProcess.spawnSync(
        'cwebp',
        ['-q', String(quality), '-alpha_q', '100', '-m', '6', '-quiet', file, '-o', tmp],
        { stdio: ['ignore', 'ignore', 'pipe'] }
      );
      if (r.status === 0) {
        var out = fs.readFileSync(tmp);
        fs.unlinkSync(tmp);
        if (out.length > 0) return Promise.resolve(out);
        return Promise.resolve(null);
      }
      try { fs.unlinkSync(tmp); } catch (e) {}
    } catch (e) { /* fall through */ }
  }

  if (backends.sharp) {
    return backends.sharp(orig)
      .webp({ quality: quality, effort: 6, alphaQuality: 100 })
      .toBuffer()
      .then(function (buf) {
        if (buf && buf.length > 0) return buf;
        return null;
      })
      .catch(function () { return null; });
  }

  return Promise.resolve(null);
}

/* --------------------------------------------------------------------------
 * Backend capability summary + install hints
 * ------------------------------------------------------------------------ */

function capabilities(backends) {
  return {
    lossless: backends.oxipng || backends.optipng || !!backends.sharp || true, // zlib always
    lossy: backends.pngquant || !!backends.sharp,
    webp: backends.cwebp || !!backends.sharp
  };
}

function installHints() {
  return [
    'How to enable more/better backends:',
    '  Lossless (best): npm i -g oxipng   |  brew install oxipng   |  apt install oxipng',
    '                   (or optipng: brew/apt install optipng)',
    '  Lossy:           npm i -g pngquant-bin  |  brew install pngquant  |  apt install pngquant',
    '  WebP:            brew install webp  |  apt install webp        (provides cwebp)',
    '  All-in-one node: npm i sharp        (local devDependency; gives lossless+lossy+webp)',
    '',
    'Note: the built-in zlib lossless backend always works with NO install,',
    'but native oxipng/optipng/pngquant/cwebp produce notably smaller files.'
  ].join('\n');
}

/* --------------------------------------------------------------------------
 * Main
 * ------------------------------------------------------------------------ */

function main() {
  var opts = parseArgs(process.argv.slice(2));
  if (opts.help) { printHelp(); return 0; }

  var assetDir = opts.dir
    ? path.resolve(opts.dir)
    : path.resolve(__dirname, '..', 'assets');

  if (!fs.existsSync(assetDir)) {
    console.error('Asset directory not found: ' + assetDir);
    return 1;
  }

  var backends = detectBackends();
  var caps = capabilities(backends);

  console.log('Squeak & Destroy — asset optimizer');
  console.log('Scanning: ' + assetDir);
  console.log('Detected backends: ' +
    [
      'oxipng=' + (backends.oxipng ? 'yes' : 'no'),
      'optipng=' + (backends.optipng ? 'yes' : 'no'),
      'pngquant=' + (backends.pngquant ? 'yes' : 'no'),
      'cwebp=' + (backends.cwebp ? 'yes' : 'no'),
      'sharp=' + (backends.sharp ? 'yes' : 'no'),
      'zlib(builtin)=yes'
    ].join(', '));

  var mode = [];
  mode.push('lossless');
  if (opts.lossy) mode.push('lossy(q' + opts.quality + ')');
  if (opts.webp) mode.push('webp(q' + opts.quality + ')');
  console.log('Mode: ' + mode.join(' + ') + (opts.dryRun ? '  [DRY RUN]' : ''));

  // Warn (don't fail) if a requested op has no backend.
  if (opts.lossy && !caps.lossy) {
    console.log('\n[skip] --lossy requested but no lossy backend (pngquant/sharp) is available.');
    console.log(installHints());
  }
  if (opts.webp && !caps.webp) {
    console.log('\n[skip] --webp requested but no webp backend (cwebp/sharp) is available.');
    console.log(installHints());
  }

  var files = findPngs(assetDir);
  if (files.length === 0) {
    console.log('\nNo PNG files found. Nothing to do.');
    return 0;
  }

  console.log('\nFound ' + files.length + ' PNG file(s).\n');

  // Column header
  console.log(
    pad('file', 40) + pad('orig', 11) +
    (true ? pad('lossless', 18) : '') +
    (opts.lossy ? pad('lossy', 18) : '') +
    (opts.webp ? pad('webp', 18) : '')
  );
  console.log('-'.repeat(40 + 11 + 18 + (opts.lossy ? 18 : 0) + (opts.webp ? 18 : 0)));

  var totals = {
    orig: 0, lossless: 0, lossy: 0, webp: 0,
    losslessWritten: 0, lossyWritten: 0, webpWritten: 0
  };

  // Process sequentially to keep memory low and output ordered.
  var chain = Promise.resolve();
  files.forEach(function (file) {
    chain = chain.then(function () {
      return processOne(file, assetDir, backends, caps, opts, totals);
    });
  });

  return chain.then(function () {
    console.log('-'.repeat(40 + 11 + 18 + (opts.lossy ? 18 : 0) + (opts.webp ? 18 : 0)));

    var row = pad('TOTAL (' + files.length + ' files)', 40) + pad(fmtBytes(totals.orig), 11);
    row += pad(fmtBytes(totals.lossless || totals.orig) + ' ' +
               pct(totals.orig, totals.lossless || totals.orig), 18);
    if (opts.lossy) {
      row += pad(fmtBytes(totals.lossy || totals.orig) + ' ' +
                 pct(totals.orig, totals.lossy || totals.orig), 18);
    }
    if (opts.webp) {
      row += pad(fmtBytes(totals.webp) + ' ' + pct(totals.orig, totals.webp), 18);
    }
    console.log(row);

    console.log('');
    if (opts.dryRun) {
      console.log('[DRY RUN] No files were written.');
    } else {
      console.log('Lossless PNGs rewritten: ' + totals.losslessWritten);
      if (opts.lossy) console.log('Lossy PNGs rewritten:    ' + totals.lossyWritten);
      if (opts.webp) console.log('WebP siblings written:   ' + totals.webpWritten);
    }
    return 0;
  });
}

function pad(s, w) {
  s = String(s);
  if (s.length >= w) return s.slice(0, w - 1) + ' ';
  return s + ' '.repeat(w - s.length);
}

/* Process a single file across the enabled operations. Returns a Promise. */
function processOne(file, assetDir, backends, caps, opts, totals) {
  var rel = path.relative(assetDir, file);
  var origSize = fs.statSync(file).size;
  totals.orig += origSize;

  var losslessSize = origSize; // default: no change
  var lossySize = origSize;
  var webpSize = 0;

  // --- Lossless (always) ---
  var p = losslessOptimizeAsync(file, backends).then(function (buf) {
    if (buf && buf.length < origSize) {
      losslessSize = buf.length;
      if (!opts.dryRun) {
        if (safeReplaceIfSmaller(file, buf, origSize)) totals.losslessWritten++;
      }
    }
    totals.lossless += losslessSize;
  });

  // --- Lossy (optional) ---
  if (opts.lossy && caps.lossy) {
    p = p.then(function () {
      // Operate on the (possibly already lossless-optimized) current file.
      var curSize = fs.statSync(file).size;
      return lossyOptimizeAsync(file, backends, opts.quality).then(function (buf) {
        if (buf && buf.length < curSize) {
          lossySize = buf.length;
          if (!opts.dryRun) {
            if (safeReplaceIfSmaller(file, buf, curSize)) totals.lossyWritten++;
          }
        } else {
          lossySize = curSize;
        }
        totals.lossy += lossySize;
      });
    });
  } else if (opts.lossy) {
    p = p.then(function () { totals.lossy += losslessSize; });
  }

  // --- WebP sibling (optional) ---
  if (opts.webp && caps.webp) {
    p = p.then(function () {
      return webpEncodeAsync(file, backends, opts.quality).then(function (buf) {
        if (buf && buf.length > 0) {
          webpSize = buf.length;
          if (!opts.dryRun) {
            var dest = file.replace(/\.png$/i, '.webp');
            if (safeWrite(dest, buf)) totals.webpWritten++;
          }
        }
        totals.webp += webpSize;
      });
    });
  }

  return p.then(function () {
    var row = pad(rel, 40) + pad(fmtBytes(origSize), 11);
    row += pad(fmtBytes(losslessSize) + ' ' + pct(origSize, losslessSize), 18);
    if (opts.lossy) row += pad(fmtBytes(lossySize) + ' ' + pct(origSize, lossySize), 18);
    if (opts.webp) {
      row += pad(webpSize ? (fmtBytes(webpSize) + ' ' + pct(origSize, webpSize)) : 'n/a', 18);
    }
    console.log(row);
  });
}

/* Entry point — convert the (possibly async) main into a proper exit code. */
Promise.resolve()
  .then(main)
  .then(function (code) { process.exit(code || 0); })
  .catch(function (err) {
    console.error('\nUnexpected error: ' + (err && err.stack ? err.stack : err));
    process.exit(1);
  });
