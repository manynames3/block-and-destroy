#!/usr/bin/env node
/* Headless render harness — boots bundle.min.js in jsdom + node-canvas, drives a
 * few screens, and exports PNG "screenshots" of the real Canvas2D output. Also a
 * runtime smoke test: any uncaught error during boot/render is captured.
 * Run: node tools/headless-shot.js   (writes tools/shots/*.png + prints a report)
 * NOTE: WebGL is unavailable in node-canvas → CA.PostFX stays no-op (2D path). */
'use strict';
const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');

const ROOT = path.join(__dirname, '..');
const OUT = path.join(__dirname, 'shots');
fs.mkdirSync(OUT, { recursive: true });

const errors = [];
const logs = [];

// Universal no-op proxy — handles the WebAudio graph (createGain/Oscillator/…)
// without modelling it. Every prop is a callable that returns another proxy;
// numeric-ish props read as 0; it is also thenable-safe.
function noopProxy() {
  const f = function(){ return noopProxy(); };
  return new Proxy(f, {
    get(_t, prop){
      if (prop === 'then') return undefined;          // not a promise
      if (prop === 'currentTime' || prop === 'sampleRate') return 0;
      if (prop === 'state') return 'running';
      if (prop === 'destination') return noopProxy();
      if (prop === Symbol.toPrimitive) return ()=>0;
      return noopProxy();
    },
    apply(){ return noopProxy(); }
  });
}

const html = `<!DOCTYPE html><html><head></head><body>
  <div id="game-wrap"><canvas id="game" width="1280" height="720"></canvas></div>
  <div id="boot-splash"></div>
  <div id="rotate-hint"><span class="rh-label"></span></div>
  <button id="fs-btn"></button>
</body></html>`;

const dom = new JSDOM(html, {
  runScripts: 'outside-only',
  pretendToBeVisual: true,
  url: 'https://localhost/animals/',
  resources: undefined,
});
const { window } = dom;
const document = window.document;

// ---- browser-API stubs the game expects ----------------------------------
function defv(obj, prop, value){ try{ Object.defineProperty(obj, prop, { value, configurable:true }); }catch(e){ try{ obj[prop]=value; }catch(_){} } }
defv(window, 'devicePixelRatio', 1);
defv(window, 'innerWidth', 1280);
defv(window, 'innerHeight', 720);
if (!(window.performance && window.performance.now)) defv(window, 'performance', { now: () => Date.now() });
window.matchMedia = () => ({ matches:false, media:'', onchange:null,
  addEventListener(){}, removeEventListener(){}, addListener(){}, removeListener(){}, dispatchEvent(){return false;} });
window.AudioContext = window.webkitAudioContext = function(){ return noopProxy(); };
window.fetch = () => Promise.reject(new Error('headless: no network'));
window.scrollTo = () => {};
window.alert = () => {};
// silence jsdom "Not implemented: HTMLMediaElement play/pause" noise
try { window.HTMLMediaElement.prototype.play = () => Promise.resolve(); window.HTMLMediaElement.prototype.pause = () => {}; } catch(e){}
// hardwareConcurrency / deviceMemory for perf-tier
try { Object.defineProperty(window.navigator, 'hardwareConcurrency', { value: 8, configurable:true }); } catch(e){}

// rAF: capture the latest callback; we tick manually so renders are deterministic.
let rafCb = null;
window.requestAnimationFrame = (cb) => { rafCb = cb; return 1; };
window.cancelAnimationFrame = () => { rafCb = null; };
function tick(frames, t0){
  let t = t0 || 16;
  for (let i=0;i<frames;i++){
    const cb = rafCb; rafCb = null;
    if (!cb) break;
    try { cb(t); } catch(e){ errors.push('[frame] '+(e && e.stack || e)); }
    t += 16;
  }
}

// capture console + window errors
const _cw = window.console || console;
window.console = Object.assign({}, _cw, {
  error: (...a)=>{ errors.push('[console.error] '+a.join(' ')); },
  warn:  (...a)=>{ logs.push('[warn] '+a.join(' ')); },
  log:   (...a)=>{ logs.push('[log] '+a.join(' ')); },
});
window.addEventListener('error', (e)=> errors.push('[window.error] '+(e.message||e)));
window.addEventListener('unhandledrejection', ()=>{ /* account init rejects by design */ });

// ---- load the production bundle into the window scope --------------------
const bundle = fs.readFileSync(path.join(ROOT, 'bundle.min.js'), 'utf8');
let booted = false;
try {
  window.eval(bundle);
  if (!window.CA) throw new Error('CA undefined after bundle eval');
  // fire DOMContentLoaded + load so any boot listeners attach, then boot.
  document.dispatchEvent(new window.Event('DOMContentLoaded', { bubbles:true }));
  window.dispatchEvent(new window.Event('load'));
  window.CA.boot(document.getElementById('game'));
  booted = true;
} catch(e){ errors.push('[boot] '+(e && e.stack || e)); }

function snap(name){
  try {
    const cv = document.getElementById('game');
    let buf = null;
    if (typeof cv.toDataURL === 'function'){
      const url = cv.toDataURL('image/png');
      const b64 = url.split(',')[1];
      if (b64) buf = Buffer.from(b64, 'base64');
    }
    if (!buf && cv.toBuffer) buf = cv.toBuffer('image/png');
    if (!buf){ logs.push('[snap] no extractor for '+name); return false; }
    fs.writeFileSync(path.join(OUT, name+'.png'), buf);
    return true;
  } catch(e){ errors.push('[snap '+name+'] '+(e && e.stack || e)); return false; }
}

const CA = window.CA;
const shots = [];

if (booted && CA) {
  // 1) MENU (canvas pass) — tick a few frames then snapshot
  tick(5); shots.push(['menu', snap('01-menu')]);

  // 2) EDITOR — the v4.38.1 fix: canvas preview must render behind the DOM toolbar
  try {
    if (CA.UI && CA.UI.setScreen) CA.UI.setScreen('editor');
    // make sure #menu-root has 'show' so we exercise the exact fixed path
    let mr = document.getElementById('menu-root');
    if (mr && mr.classList) mr.classList.add('show');
    tick(10);
    shots.push(['editor', snap('02-editor')]);
  } catch(e){ errors.push('[editor] '+(e && e.stack || e)); }

  // 3) MATCH — try to start a quick game so terrain/entities/fx actually draw
  try {
    if (CA.newGame) {
      CA.newGame({});            // defaults
      tick(40, 16);
      shots.push(['match', snap('03-match')]);
    } else { logs.push('[match] CA.newGame absent'); }
  } catch(e){ errors.push('[match] '+(e && e.stack || e)); }
}

// ---- report ----
console.error('\n==== HEADLESS REPORT ====');
console.error('booted:', booted, '| CA:', !!(window.CA), '| screens shot:', shots.map(s=>s[0]+'='+s[1]).join(', '));
console.error('ERRORS ('+errors.length+'):');
errors.slice(0,40).forEach(e=>console.error('  - '+e.split('\n')[0]));
console.error('first warns ('+Math.min(logs.length,8)+' of '+logs.length+'):');
logs.slice(0,8).forEach(l=>console.error('  · '+l.slice(0,160)));
process.exit(0);
