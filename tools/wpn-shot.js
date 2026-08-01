#!/usr/bin/env node
/* Headless weapon-bar verifier: boots the bundle, starts (1) a vs-AI match and
 * (2) the /dev sandbox, renders a frame each, and saves a 2.5× ZOOM of the bottom
 * weapon bar so the per-weapon ammo count / ∞ / ultimate borders are readable.
 *   node tools/wpn-shot.js  -> tools/shots/wpn-ai.png + wpn-dev.png */
'use strict';
const fs=require('fs'), path=require('path');
const { JSDOM }=require('jsdom');
const ROOT=path.join(__dirname,'..'); const OUT=path.join(__dirname,'shots'); fs.mkdirSync(OUT,{recursive:true});
function noop(){ const f=function(){return noop();}; return new Proxy(f,{get(_t,p){if(p==='then')return undefined;if(p==='currentTime'||p==='sampleRate')return 0;if(p==='state')return'running';return noop();},apply(){return noop();}}); }
const dom=new JSDOM('<!DOCTYPE html><body><div id="game-wrap"><canvas id="game" width="1280" height="720"></canvas></div></body>',{runScripts:'outside-only',pretendToBeVisual:true,url:'https://localhost/animals/'});
const {window}=dom, document=window.document;
function defv(o,p,v){try{Object.defineProperty(o,p,{value:v,configurable:true});}catch(e){try{o[p]=v;}catch(_){}}}
defv(window,'devicePixelRatio',1); defv(window,'innerWidth',1280); defv(window,'innerHeight',720);
if(!(window.performance&&window.performance.now)) defv(window,'performance',{now:()=>Date.now()});
window.matchMedia=()=>({matches:false,addEventListener(){},removeEventListener(){},addListener(){},removeListener(){}});
window.AudioContext=window.webkitAudioContext=function(){return noop();};
window.fetch=()=>Promise.reject(new Error('no net'));
try{window.HTMLMediaElement.prototype.play=()=>Promise.resolve();window.HTMLMediaElement.prototype.pause=()=>{};}catch(e){}
try{Object.defineProperty(window.navigator,'hardwareConcurrency',{value:8,configurable:true});}catch(e){}
let rafCb=null; window.requestAnimationFrame=cb=>{rafCb=cb;return 1;}; window.cancelAnimationFrame=()=>{rafCb=null;};
const errors=[]; window.addEventListener('error',e=>errors.push(''+(e.message||e)));
window.console=Object.assign({},window.console,{error(){},warn(){},log(){}});
window.eval(fs.readFileSync(path.join(ROOT,'bundle.min.js'),'utf8'));
const CA=window.CA;
document.dispatchEvent(new window.Event('DOMContentLoaded')); window.dispatchEvent(new window.Event('load'));
CA.boot(document.getElementById('game'));
const cv=document.getElementById('game');
function frame(t){const cb=rafCb;rafCb=null;if(cb)cb(t);}
function run(frames){let t=16;for(let i=0;i<frames;i++){try{CA.state.mode=CA.GameState.PLAYING;CA.state.paused=false;}catch(e){}frame(t);t+=16;}}
function saveZoom(name){
  // zoom the bottom 26% (the weapon bar) ~2.4× into a wide image
  const z=2.4, sy=Math.round(cv.height*0.74), sh=cv.height-sy;
  const oc=document.createElement('canvas'); oc.width=Math.round(cv.width*z*0.5); oc.height=Math.round(sh*z);
  const g=oc.getContext('2d'); g.imageSmoothingEnabled=false;
  g.fillStyle='#101015'; g.fillRect(0,0,oc.width,oc.height);
  g.drawImage(cv, Math.round(cv.width*0.25),sy, Math.round(cv.width*0.5),sh, 0,0, oc.width,oc.height);
  const url=oc.toDataURL('image/png'); fs.writeFileSync(path.join(OUT,name+'.png'), Buffer.from(url.split(',')[1],'base64'));
}
function full(name){ const url=cv.toDataURL('image/png'); fs.writeFileSync(path.join(OUT,name+'.png'),Buffer.from(url.split(',')[1],'base64')); }

// (1) vs-AI match — finite ammo counts + ∞ for infinite
try{ CA.newGame({gameMode:'classic', theme:'jungle', vsAI:true, aiLevel:1, teamSize:2}); run(60); saveZoom('wpn-ai-bar'); full('wpn-ai-full'); }
catch(e){ errors.push('[ai] '+e.message); }
// (2) /dev sandbox — all weapons incl. ultimates (∞ + crimson ultimate borders)
try{ if(CA.startDev){ CA.startDev(); run(40); saveZoom('wpn-dev-bar'); full('wpn-dev-full'); } }
catch(e){ errors.push('[dev] '+e.message); }

console.error('errors:'+errors.length+(errors.length?' '+errors.slice(0,5).join(' | '):''));
console.error('saved: '+fs.readdirSync(OUT).filter(f=>f.indexOf('wpn')===0).join(', '));
process.exit(0);
