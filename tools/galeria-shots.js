#!/usr/bin/env node
/* Gallery action-shot generator — re-shoots specific galeria/*.jpg with a DISTINCT
 * explosion FX each (Jani: the 4 were all the same 'rocket' burst). Boots the
 * bundle in jsdom + node-canvas, starts a match per theme, triggers a chosen FX
 * near a unit, ticks a few frames so the FX is mid-animation, and writes a JPG.
 * Run: node tools/galeria-shots.js
 * Same getImageData→fresh-canvas re-encode + SYNCHRONOUS structure as map-shots.js. */
'use strict';
const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');
const NC = require('canvas');

const ROOT = path.join(__dirname, '..');
const OUT = path.join(ROOT, 'galeria');

const errors = [];
function noopProxy(){ const f=function(){return noopProxy();}; return new Proxy(f,{get(_t,p){ if(p==='then')return undefined; if(p==='currentTime'||p==='sampleRate')return 0; if(p==='state')return'running'; if(p==='destination')return noopProxy(); if(p===Symbol.toPrimitive)return ()=>0; return noopProxy(); }, apply(){return noopProxy();}}); }

const dom = new JSDOM('<!DOCTYPE html><body><div id="game-wrap"><canvas id="game" width="1280" height="720"></canvas></div><div id="boot-splash"></div><div id="rotate-hint"><span class="rh-label"></span></div><button id="fs-btn"></button></body>',
  { runScripts:'outside-only', pretendToBeVisual:true, url:'https://localhost/animals/' });
const { window } = dom; const document = window.document;
function defv(o,p,v){ try{ Object.defineProperty(o,p,{value:v,configurable:true}); }catch(e){ try{o[p]=v;}catch(_){} } }
defv(window,'devicePixelRatio',1); defv(window,'innerWidth',1280); defv(window,'innerHeight',720);
if (!(window.performance && window.performance.now)) defv(window,'performance',{ now:()=>Date.now() });
window.matchMedia = () => ({ matches:false, addEventListener(){}, removeEventListener(){}, addListener(){}, removeListener(){}, dispatchEvent(){return false;} });
window.AudioContext = window.webkitAudioContext = function(){ return noopProxy(); };
window.fetch = () => Promise.reject(new Error('no net')); window.scrollTo = () => {}; window.alert = () => {};
try { window.HTMLMediaElement.prototype.play = () => Promise.resolve(); window.HTMLMediaElement.prototype.pause = () => {}; } catch(e){}
try { Object.defineProperty(window.navigator,'hardwareConcurrency',{ value:8, configurable:true }); } catch(e){}
let rafCb = null;
window.requestAnimationFrame = (cb)=>{ rafCb = cb; return 1; };
window.cancelAnimationFrame = ()=>{ rafCb = null; };
function tick(frames){ let t=16; for(let i=0;i<frames;i++){ const cb=rafCb; rafCb=null; if(!cb)break; try{ cb(t); }catch(e){ errors.push('[frame] '+(e&&e.message)); } t+=16; } }
window.console = Object.assign({}, window.console||console, { error(){}, warn(){}, log(){} });
window.addEventListener('error', (e)=> errors.push('[win] '+(e.message||e)));
window.addEventListener('unhandledrejection', ()=>{});

let booted=false;
try {
  window.eval(fs.readFileSync(path.join(ROOT,'bundle.min.js'),'utf8'));
  document.dispatchEvent(new window.Event('DOMContentLoaded',{bubbles:true}));
  window.dispatchEvent(new window.Event('load'));
  window.CA.boot(document.getElementById('game'));
  booted = true;
} catch(e){ errors.push('[boot] '+(e&&e.stack||e)); }

const CA = window.CA;

// blast point: an alive unit near mid-field (so the FX sits in frame), lifted a bit.
function blastPoint(){
  const S = CA.state;
  // prefer the ACTIVE unit — the camera is centred on it, so the FX is framed.
  let au = null; try{ au = CA.activeUnit && CA.activeUnit(); }catch(e){}
  if (au && au.alive) return { x: au.x + 26, y: au.y - 8 };
  let best=null, bestd=1e9, cx=(CA.config.WORLD_W||4000)/2;
  for (const u of (S.units||[])){
    if (!u || !u.alive) continue;
    const d = Math.abs(u.x - cx);
    if (d < bestd){ bestd = d; best = u; }
  }
  if (best) return { x: best.x + 26, y: best.y - 8 };
  return { x: cx, y: (S.waterLevel||600) - 120 };
}

function triggerFX(kind){
  const S = CA.state, F = CA.FX, p = blastPoint();
  try {
    if (kind==='nuke'){
      if (F.mushroomCloud) F.mushroomCloud(p.x, p.y, 96);
      if (CA.explode) CA.explode(p.x, p.y, 70, 0, 0);   // crater, no damage
    } else if (kind==='vortex'){
      if (F.vortex) F.vortex(p.x, p.y, 90);
      if (CA.explode) CA.explode(p.x, p.y, 30, 0, 0);
    } else if (kind==='magic'){
      if (F.magicGlow) F.magicGlow(p.x, p.y, 86);
      if (CA.explode) CA.explode(p.x, p.y, 34, 0, 0);
    } else if (kind==='meteors'){
      // a little shower: three craters across the field + glows
      for (let i=-1;i<=1;i++){
        const x = p.x + i*70, y = p.y - Math.abs(i)*6;
        if (CA.explode) CA.explode(x, y, 40, 0, 0);
        if (F.magicGlow && i===0) {}
      }
    }
  } catch(e){ errors.push('[fx '+kind+'] '+(e&&e.message)); }
}

function writeJpg(name){
  const cv = document.getElementById('game');
  const w = cv.width, h = cv.height;
  const img = cv.getContext('2d').getImageData(0,0,w,h);
  const out = NC.createCanvas(w,h);
  out.getContext('2d').putImageData(img,0,0);
  const buf = out.toBuffer('image/jpeg', { quality: 0.9 });
  fs.writeFileSync(path.join(OUT, name + '.jpg'), buf);
  return buf.length;
}

const TARGETS = [
  { name:'snow_big',      theme:'snow',      mode:'classic', fx:'nuke',    settle:260, after:22 },
  { name:'graveyard_big', theme:'graveyard', mode:'classic', fx:'vortex',  settle:260, after:14 },
  { name:'ffa_meadow',    theme:'grass',     mode:'ffa',     fx:'magic',   settle:260, after:10 },
  { name:'ffa_desert',    theme:'desert',    mode:'ffa',     fx:'meteors', settle:260, after:10 },
];

const results = [];
if (booted && CA && CA.newGame){
  for (const tg of TARGETS){
    try {
      const teams = (tg.mode==='ffa')
        ? [ {kind:'capybara',isAI:false,size:2},{kind:'worm',isAI:false,size:2},{kind:'frog',isAI:false,size:2} ]
        : [ {kind:'capybara',isAI:false,size:3},{kind:'worm',isAI:false,size:3} ];
      CA.newGame({ gameMode: tg.mode, theme: tg.theme, seed: 4242, teams });
      tick(tg.settle);            // settle terrain + let the intro banner clear
      triggerFX(tg.fx);
      tick(tg.after);             // let the FX reach a photogenic mid-animation
      const n = writeJpg(tg.name);
      results.push(tg.name+' ('+tg.fx+') = '+n+'b');
    } catch(e){ results.push(tg.name+' = ERR'); errors.push('['+tg.name+'] '+(e&&e.stack||e)); }
  }
}
process.stderr.write('\n==== GALERIA SHOTS ====\nbooted:'+booted+'\n'+results.join('\n')+'\n');
if (errors.length){ process.stderr.write('ERRORS('+errors.length+'):\n'); errors.slice(0,12).forEach(e=>process.stderr.write('  - '+String(e).split('\n')[0]+'\n')); }
process.exit(0);
