#!/usr/bin/env node
/* Map preview generator — boots bundle.min.js in jsdom + node-canvas, starts a
 * quick match on EACH theme, and exports a downscaled landscape PNG preview to
 * assets/maps/<theme>.png. Used by the Daily Challenge card.
 * Run: node tools/map-shots.js
 *
 * IMPORTANT: extract pixels via getImageData and RE-ENCODE on a fresh node-canvas.
 * Calling toBuffer() directly on the jsdom <canvas> returned a stale/identical
 * buffer across iterations (every theme came out byte-identical). The structure
 * here is deliberately SYNCHRONOUS (no async/await) — a yield between capture and
 * write also reintroduced the duplication. */
'use strict';
const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');
const NC = require('canvas');             // hard dep — used to re-encode + downscale

const ROOT = path.join(__dirname, '..');
const OUT = path.join(ROOT, 'assets', 'maps');
fs.mkdirSync(OUT, { recursive: true });

const errors = [];
function noopProxy() {
  const f = function(){ return noopProxy(); };
  return new Proxy(f, { get(_t, p){
    if (p === 'then') return undefined;
    if (p === 'currentTime' || p === 'sampleRate') return 0;
    if (p === 'state') return 'running';
    if (p === 'destination') return noopProxy();
    if (p === Symbol.toPrimitive) return ()=>0;
    return noopProxy();
  }, apply(){ return noopProxy(); } });
}

const dom = new JSDOM('<!DOCTYPE html><body><div id="game-wrap"><canvas id="game" width="1280" height="720"></canvas></div><div id="boot-splash"></div><div id="rotate-hint"><span class="rh-label"></span></div><button id="fs-btn"></button></body>',
  { runScripts:'outside-only', pretendToBeVisual:true, url:'https://localhost/animals/' });
const { window } = dom;
const document = window.document;
function defv(o,p,v){ try{ Object.defineProperty(o,p,{value:v,configurable:true}); }catch(e){ try{o[p]=v;}catch(_){} } }
defv(window,'devicePixelRatio',1); defv(window,'innerWidth',1280); defv(window,'innerHeight',720);
if (!(window.performance && window.performance.now)) defv(window,'performance',{ now:()=>Date.now() });
window.matchMedia = () => ({ matches:false, media:'', onchange:null, addEventListener(){}, removeEventListener(){}, addListener(){}, removeListener(){}, dispatchEvent(){return false;} });
window.AudioContext = window.webkitAudioContext = function(){ return noopProxy(); };
window.fetch = () => Promise.reject(new Error('headless: no network'));
window.scrollTo = () => {}; window.alert = () => {};
try { window.HTMLMediaElement.prototype.play = () => Promise.resolve(); window.HTMLMediaElement.prototype.pause = () => {}; } catch(e){}
try { Object.defineProperty(window.navigator,'hardwareConcurrency',{ value:8, configurable:true }); } catch(e){}

let rafCb = null;
window.requestAnimationFrame = (cb) => { rafCb = cb; return 1; };
window.cancelAnimationFrame = () => { rafCb = null; };
// Drive the rAF loop synchronously. The timestamp MUST be monotonic ACROSS calls
// (the bundle's loop computes dt = ts - last; resetting per call would yield dt<=0
// and stall the fixed-timestep sim). 16ms/frame ≈ 60fps wall-clock.
let _ts = 0;
function tick(frames){ for (let i=0;i<frames;i++){ const cb = rafCb; rafCb = null; if (!cb) break; _ts += 16; try { cb(_ts); } catch(e){ errors.push('[frame] '+(e&&e.message)); } } }

window.console = Object.assign({}, window.console || console, { error(){}, warn(){}, log(){} });
window.addEventListener('error', (e)=> errors.push('[window.error] '+(e.message||e)));
window.addEventListener('unhandledrejection', ()=>{});

let booted = false;
try {
  window.eval(fs.readFileSync(path.join(ROOT, 'bundle.min.js'), 'utf8'));
  if (!window.CA) throw new Error('CA undefined after bundle eval');
  document.dispatchEvent(new window.Event('DOMContentLoaded', { bubbles:true }));
  window.dispatchEvent(new window.Event('load'));
  window.CA.boot(document.getElementById('game'));
  booted = true;
} catch(e){ errors.push('[boot] '+(e && e.stack || e)); }

const CA = window.CA;
const OW = 960, OH = 540;   // output preview size — FULL 16:9 frame (whole map)

// --- DISK-BACKED IMAGE LOADER --------------------------------------------
// In jsdom CA.img() sets img.src to a RELATIVE url ('assets/bg/.../l1.png?v=..')
// which would network-fetch (blocked) -> never completes -> renderBg falls back
// to the OLD procedural background. We override CA.img to decode the PNG/JPG
// from disk into a REAL node-canvas Image (drawImage-able), CACHED so each layer
// decodes ONCE total (NOT per renderBg frame × hundreds of frames × 13 themes —
// per-frame synchronous decode was NOT what hung; the hang was rendering the
// large photo layers EVERY frame, ~0.5s/frame in node-canvas, see PERF below).
//
// PERF / no-hang: drawing the ~1280×1024 painterly layers is ~10× slower than the
// procedural fallback. The expensive part is the ~240 "settle" frames needed to
// (a) settle terrain physics and (b) let the 4s "Classic Battle" intro card fade
// out (the card's timer advances 1/60 PER RENDER, so it genuinely needs ~240
// renders). So we render those settle frames with the photo bg OFF (cheap
// procedural) and only switch the photo bg ON for the final 3 frames that the
// screenshot actually captures. Plus quality='low' to halve per-frame cost.
let PHOTO_ON = false;
if (booted && CA) {
  const diskCache = Object.create(null);
  CA.img = function(url){
    if (!url) return null;
    if (!PHOTO_ON) return null;                       // settle phase -> procedural (fast)
    if (url in diskCache) return diskCache[url];      // incl. cached null (missing/bad)
    var rel = String(url).split('?')[0];              // strip ?v=.. cache-bust
    var file = path.join(ROOT, rel);
    try {
      var buf = fs.readFileSync(file);
      var im = new NC.Image();
      im.src = buf;                                   // synchronous decode from Buffer
      diskCache[url] = (im.width > 0) ? im : null;    // real, drawImage-able NC.Image
    } catch(e){
      diskCache[url] = null;                          // missing file -> procedural fallback
    }
    return diskCache[url];
  };
  CA._imgCache = diskCache;                            // keep bundle's own refs coherent
  try { CA.settings.quality = 'low'; CA.settings.postfx = false; } catch(e){}
}

function writePreview(theme){
  const cv = document.getElementById('game');
  const w = cv.width, h = cv.height;
  const sctx = cv.getContext('2d');
  const img = sctx.getImageData(0, 0, w, h);     // LIVE, theme-specific pixels
  const full = NC.createCanvas(w, h);
  full.getContext('2d').putImageData(img, 0, 0);
  // FULL FRAME (Jani: "teljes képernyő legyen a képe a mapról") — no top-strip
  // crop; downscale the WHOLE 16:9 game frame (the camera is zoomed out to show
  // the entire arena, see the loop) into the output.
  const out = NC.createCanvas(OW, OH);
  const octx = out.getContext('2d');
  octx.drawImage(full, 0, 0, w, h, 0, 0, OW, OH);
  const buf = out.toBuffer('image/png');
  fs.writeFileSync(path.join(OUT, theme + '.png'), buf);
  return buf.length;
}

const results = [];
if (booted && CA && CA.newGame && CA.Maps && CA.Maps.list){
  let themes = [];
  try { themes = CA.Maps.list().filter(id => id && id !== 'random'); } catch(e){ themes = []; }
  for (const theme of themes){
    const t0 = Date.now();
    try {
      // HUMAN teams keep the scene/camera static (AI would fire + pan to the sky).
      CA.newGame({ gameMode:'classic', theme: theme, seed: 4242,
        teams:[ { kind:'capybara', isAI:false, size:3 },
                { kind:'worm', isAI:false, size:3 } ] });
      try { CA.settings.quality = 'low'; } catch(e){}   // newGame may reset settings
      // SETTLE with photo bg OFF (cheap procedural render): settle terrain physics
      // and let the 4s "Classic Battle" intro card fully fade (its timer advances
      // 1/60 per render, so it needs ~240 renders regardless of dt).
      PHOTO_ON = false;
      tick(245);
      // ZOOM OUT to the whole map: a tiny userZoom clamps to the min (fit) zoom, so
      // as much of the arena as possible is in frame (camera stays centred on the
      // active unit ≈ mid-map). Tick so it settles to the zoomed-out view.
      try { CA.state.camera.userZoom = 0.05; } catch(e){}
      tick(12);
      // Now switch the painterly PHOTO backgrounds ON and render a few frames so the
      // bg layers decode (cached) and draw into what we capture.
      PHOTO_ON = true;
      tick(3);
      const n = writePreview(theme);
      results.push(theme + '=ok (' + n + 'b, ' + (Date.now()-t0) + 'ms)');
    } catch(e){ results.push(theme + '=ERR'); errors.push('['+theme+'] '+(e && e.stack || e)); }
    // hard per-theme safety: 245+12+3 photo frames should be ~7s; if a theme somehow
    // blows past 30s, the budget assumption is wrong — bail rather than hang the run.
    if (Date.now() - t0 > 30000) { errors.push('['+theme+'] exceeded 30s budget'); }
  }
}
process.stderr.write('\n==== MAP SHOTS ====\nbooted:'+booted+'\n'+results.join('\n')+'\n');
if (errors.length){ process.stderr.write('ERRORS('+errors.length+'):\n'); errors.slice(0,12).forEach(e=>process.stderr.write('  - '+String(e).split('\n')[0]+'\n')); }
process.exit(0);
