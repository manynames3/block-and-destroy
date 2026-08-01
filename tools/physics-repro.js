#!/usr/bin/env node
/* Headless PHYSICS repro — boots the bundle (jsdom + node-canvas), starts a
 * match, applies a knockback impulse to a unit, then steps the sim frame-by-frame
 * logging the unit's per-step position delta. Detects "teleport" glitches: a
 * single sim-step that moves the unit further than a visible arc would (which,
 * combined with the loop's multi-step catch-up, reads on screen as an instant jump
 * to the landing spot — Jani's offline glitch).
 *   node tools/physics-repro.js
 * WebGL/audio are stubbed; this only exercises the deterministic 2D physics. */
'use strict';
const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');
const ROOT = path.join(__dirname, '..');

function noopProxy(){ const f=function(){return noopProxy();};
  return new Proxy(f,{ get(_t,p){ if(p==='then')return undefined; if(p==='currentTime'||p==='sampleRate')return 0; if(p==='state')return 'running'; return noopProxy(); }, apply(){return noopProxy();} }); }

const dom = new JSDOM(`<!DOCTYPE html><html><body><div id="game-wrap"><canvas id="game" width="1280" height="720"></canvas></div></body></html>`,
  { runScripts:'outside-only', pretendToBeVisual:true, url:'https://localhost/animals/' });
const { window } = dom; const document = window.document;
function defv(o,p,v){ try{ Object.defineProperty(o,p,{value:v,configurable:true}); }catch(e){ try{o[p]=v;}catch(_){}}}
defv(window,'devicePixelRatio',1); defv(window,'innerWidth',1280); defv(window,'innerHeight',720);
if(!(window.performance&&window.performance.now)) defv(window,'performance',{now:()=>Date.now()});
window.matchMedia=()=>({matches:false,addEventListener(){},removeEventListener(){},addListener(){},removeListener(){}});
window.AudioContext=window.webkitAudioContext=function(){return noopProxy();};
window.fetch=()=>Promise.reject(new Error('no net'));
try{ window.HTMLMediaElement.prototype.play=()=>Promise.resolve(); window.HTMLMediaElement.prototype.pause=()=>{}; }catch(e){}
try{ Object.defineProperty(window.navigator,'hardwareConcurrency',{value:8,configurable:true}); }catch(e){}
let rafCb=null; window.requestAnimationFrame=(cb)=>{ rafCb=cb; return 1; }; window.cancelAnimationFrame=()=>{rafCb=null;};
const errors=[]; window.addEventListener('error',e=>errors.push(''+(e.message||e)));
const _con=window.console; window.console=Object.assign({},_con,{error(){}, warn(){}, log(){}});

window.eval(fs.readFileSync(path.join(ROOT,'bundle.min.js'),'utf8'));
const CA = window.CA;
document.dispatchEvent(new window.Event('DOMContentLoaded')); window.dispatchEvent(new window.Event('load'));
CA.boot(document.getElementById('game'));

// advance whole rAF frames (loop()->step()); returns the unit pos after each frame
function frame(t){ const cb=rafCb; rafCb=null; if(cb) cb(t); }

function runCase(kb, stacks){
  // fresh match
  CA.newGame({ gameMode:'classic', theme:'jungle', teamSize:1, vsAI:false, aiLevel:0 });
  const S = CA.state;
  // force the live sim state so step() actually integrates physics headlessly
  try{ S.mode = CA.GameState.PLAYING; S.paused = false; S._hitstop = 0; }catch(e){}
  // let it settle on the ground a few frames
  let t=16; for(let i=0;i<30;i++){ frame(t); t+=16; try{ S.mode=CA.GameState.PLAYING; S.paused=false; }catch(e){} }
  const u = (S.units||[]).find(x=>x.alive);
  if(!u) return { kb, stacks, err:'no unit' };
  const x0=u.x, y0=u.y;
  // apply knockback: a blast just BELOW-beside the unit (kb strength × stacks, like
  // a close/cluster hit). damageInRadius applies the vx/vy impulse directly.
  for(let s=0;s<stacks;s++){ CA.Entities.damageInRadius(u.x-6, u.y+10, 110, 8, kb); }
  const v0 = Math.hypot(u.vx||0, u.vy||0);
  // step frame-by-frame, recording the unit's per-FRAME position delta
  let px=u.x, py=u.y, maxStep=0, maxAt=-1, settledAt=-1; const trail=[];
  for(let i=0;i<240;i++){
    try{ S.mode=CA.GameState.PLAYING; S.paused=false; if((S._hitstop||0)>0) S._hitstop=0; }catch(e){}
    frame(t); t+=16;
    const dx=u.x-px, dy=u.y-py, d=Math.hypot(dx,dy);
    if(d>maxStep){ maxStep=d; maxAt=i; }
    if(i<8 || d>40) trail.push('f'+i+' Δ='+d.toFixed(1)+' v=('+ (u.vx||0).toFixed(1)+','+(u.vy||0).toFixed(1)+') grounded='+!!u.grounded);
    px=u.x; py=u.y;
    if(settledAt<0 && i>4 && d<0.5 && u.grounded){ settledAt=i; }
  }
  return { kb, stacks, startV:+v0.toFixed(1), maxStepPx:+maxStep.toFixed(1), maxAtFrame:maxAt,
           settledFrame:settledAt, totalTravelPx:+Math.hypot(u.x-x0,u.y-y0).toFixed(0), trail:trail.slice(0,10) };
}

console.error('=== PHYSICS KNOCKBACK REPRO (per-FRAME position delta; >~40px/frame reads as a teleport) ===');
[ [4,4],[1,1] ].forEach(([kb,st])=>{
  let r; try{ r=runCase(kb,st); }catch(e){ r={kb,stacks:st,err:(e&&e.message)||e}; }
  console.error('\nkb='+kb+' ×'+st+'  startV='+r.startV+'  MAX step='+r.maxStepPx+'px @f'+r.maxAtFrame+
                '  settled@f'+r.settledFrame+'  travel='+r.totalTravelPx+'px'+(r.err?('  ERR:'+r.err):''));
  (r.trail||[]).forEach(l=>console.error('   '+l));
});
console.error('\nboot errors: '+errors.length);
process.exit(0);
