# tools/ — asset optimization

Standalone, manually-run build tooling for **Squeak & Destroy**. Nothing here is
wired into `build.js`, the deploy workflow, the service worker, or the game at
runtime. You run these by hand when you want to shrink the shipped assets.

## `optimize-assets.js`

Optimizes the raster **PNG** assets under `animals/assets/**` to reduce the
initial download budget (Poki ≤ 8 MB initial, CrazyGames caps). Animals are
drawn procedurally (no sprites), so this targets the PNGs only: `hero.png`
(~980 KB), `logo.png`, `appicon.png`, weapon icons, UI icons, boxes, species.

### Usage

```bash
node tools/optimize-assets.js [flags]
```

### Flags

| Flag          | Effect                                                                                      |
|---------------|---------------------------------------------------------------------------------------------|
| *(default)*   | **Lossless** PNG optimization — ZERO visual change. Safe default. Overwrites in place only if smaller. |
| `--lossy`     | Also apply **conservative lossy** quantization (pngquant-style, quality 80–95). Off by default. Overwrites in place only if smaller. Flat cartoon art tolerates this well. |
| `--webp`      | Generate a `.webp` **sibling** next to each PNG. Does NOT modify the SW — only produces files. |
| `--quality=N` | WebP / lossy quality (default `90`, clamped 1–100).                                          |
| `--dry-run`   | Report current vs projected sizes; **write nothing**.                                       |
| `--dir=PATH`  | Asset root to scan (default `<repo>/assets`).                                                |
| `--help`      | Show usage.                                                                                  |

Combine freely, e.g. a full pass:

```bash
node tools/optimize-assets.js --lossy --webp --quality=90
```

### Safety guarantees

- **Idempotent-ish & non-destructive.** Every write goes through a temp file +
  atomic rename, and an original PNG is replaced **only if** the new file is
  non-empty and strictly smaller. Lossless re-runs converge to "0 rewritten".
- **Lossless is truly lossless.** Verified by decoding raw pixels before/after —
  they are byte-identical. Lossless only re-packs the compressed pixel stream.
- **WebP writes siblings only** (`foo.png` → `foo.webp`); originals are never
  touched by `--webp`.
- **Lossy only runs with `--lossy`** and never on by default.

### Backends (auto-detected at runtime, best available wins)

| Operation     | Preference order                                                          |
|---------------|---------------------------------------------------------------------------|
| Lossless PNG  | `oxipng` (PATH) → `optipng` (PATH) → `sharp` (node module) → **zlib re-deflate (built into Node — always available, zero install)** |
| Lossy PNG     | `pngquant` (PATH) → `sharp` (node module)                                 |
| WebP          | `cwebp` (PATH) → `sharp` (node module)                                    |

If a requested operation has **no** backend available, the script prints install
instructions and skips that operation gracefully (exit 0). It only exits non-zero
on a real unexpected error. The built-in zlib lossless path means the default
lossless mode always does *something* even on a bare machine, but native tools
produce notably smaller files.

### Installing better backends

```bash
# Lossless (best)
npm i -g oxipng            # or: brew install oxipng   |  apt install oxipng
# (optipng also supported: brew/apt install optipng)

# Lossy
npm i -g pngquant-bin      # or: brew install pngquant  |  apt install pngquant

# WebP (provides cwebp)
brew install webp          # or: apt install webp

# All-in-one Node module (lossless + lossy + webp; gitignored node_modules)
npm i sharp
```

### Measured results in this repo (43 PNGs, 2.67 MB total)

| Mode      | Total after | Δ        |
|-----------|-------------|----------|
| lossless  | 2.36 MB     | −11.7%   |
| lossy q90 | 729.7 KB    | −73.3%   |
| webp q90  | 785.0 KB    | −71.3%   |

`hero.png` alone drops ~27% losslessly. (Figures from the `sharp` backend; native
`oxipng`/`pngquant` typically do as well or slightly better.)

---

## RECOMMENDED — but NOT YET APPLIED — `sw.js` change for WebP

> This is **documentation only**. The service worker has **not** been modified.
> Apply this manually and bump `CACHE` if/when you decide to ship WebP variants.

The game's `sw.js` uses **cache-first** for static assets. To serve `.webp` with a
transparent `.png` fallback, you'd add a small rewrite in the static-asset branch
of the `fetch` handler: when a `.png` is requested and the client sends an
`Accept: image/webp` header, try the `.webp` sibling first, then fall back to the
requested `.png`.

Sketch (for reference only — do not paste verbatim without testing):

```js
// Inside the fetch handler, BEFORE the existing cache-first static branch:
var accept = req.headers.get('accept') || '';
if (/\.png(?:$|\?)/i.test(url.pathname) && accept.indexOf('image/webp') !== -1) {
  var webpUrl = req.url.replace(/\.png(\?|$)/i, '.webp$1');
  event.respondWith(
    caches.match(webpUrl, { ignoreSearch: true }).then(function (hit) {
      if (hit) return hit;
      return fetch(webpUrl).then(function (resp) {
        if (resp && resp.ok && resp.type === 'basic') {
          var copy = resp.clone();
          caches.open(CACHE).then(function (c) { c.put(webpUrl, copy); });
          return resp;
        }
        // No .webp available → fall back to the original .png request.
        return fetch(req);
      }).catch(function () { return fetch(req); });
    })
  );
  return;
}
```

Also, if you precache WebP, add the `.webp` paths to the `PRECACHE` array and bump
the `CACHE` version string so clients pick them up.

> **HTML `<picture>` alternative:** instead of (or in addition to) the SW, you can
> serve WebP at the markup level with `<picture><source srcset="x.webp"
> type="image/webp"><img src="x.png"></picture>`. That requires editing markup,
> which this tool also does not do.

### Caveats / housekeeping

- The deploy pipeline is an **FTP mirror without `--delete`**: files removed from
  git stay on the server. If you generate `.webp` siblings and later remove them,
  clean them on the server manually.
- Generated `.webp` files are **not** auto-added to git or the SW precache by this
  tool — that's a deliberate, reviewed step you take yourself.
