#!/usr/bin/env node
/* Headless smoke-test for the intro cinematic: boots the bundle, plays CA.Intro,
 * advances its own rAF timeline and snapshots a few keyframes. Verifies it renders
 * without throwing. (logo/explosion images won't load in jsdom → built-in fallbacks.)
 *   node tools/intro-shot.js  -> tools/shots/intro-*.png */
'use strict';
const fs=require('fs'), path=require('path'); const { JSDOM }=require('jsdom');
const ROOT=path.join(__dirname,'..'); const OUT=path.join(__dirname,'shots'); fs.mkdirSync(OUT,{recursive:true});
function noop(){const f=function(){return noop();};return new Proxy(f,{get(_t,p){if(p==='then')return undefined;if(p==='currentTime'||p==='sampleRate')return 0;if(p==='state')return'running';return noop();},apply(){return noop();}});}
const dom=new JSDOM('<!DOCTYPE html><body><div id="game-wrap"><canvas id="game" width="1280" height="720"></canvas></div></body>',{runScripts:'outside-only',pretendToBeVisual:true,url:'https://localhost/animals/?intro'});
const {window}=dom, document=window.document;
function defv(o,p,v){try{Object.defineProperty(o,p,{value:v,configurable:true});}catch(e){try{o[p]=v;}catch(_){}}}
defv(window,'devicePixelRatio',1); defv(window,'innerWidth',1280); defv(window,'innerHeight',720);
if(!(window.performance&&window.performance.now)) defv(window,'performance',{now:()=>Date.now()});
window.matchMedia=()=>({matches:false,addEventListener(){},removeEventListener(){},addListener(){},removeListener(){}});
window.AudioContext=window.webkitAudioContext=function(){return noop();};
window.fetch=()=>Promise.reject(new Error('no net'));
try{window.HTMLMediaElement.prototype.play=()=>Promise.resolve();window.HTMLMediaElement.prototype.pause=()=>{};}catch(e){}
let rafCb=null; window.requestAnimationFrame=cb=>{rafCb=cb;return 1;}; window.cancelAnimationFrame=()=>{rafCb=null;};
const errors=[]; window.addEventListener('error',e=>errors.push(''+(e.message||e)));
window.console=Object.assign({},window.console,{error(){},warn(){},log(){}});
window.eval(fs.readFileSync(path.join(ROOT,'bundle.min.js'),'utf8'));
const CA=window.CA;
document.dispatchEvent(new window.Event('DOMContentLoaded')); window.dispatchEvent(new window.Event('load'));
CA.boot(document.getElementById('game'));
function snap(name){ try{ var c=document.getElementById('ca-intro'); if(!c){errors.push('no ca-intro canvas');return;} fs.writeFileSync(path.join(OUT,name+'.png'),Buffer.from(c.toDataURL('image/png').split(',')[1],'base64')); }catch(e){errors.push('[snap '+name+'] '+e.message);} }
try{ CA.Intro.play(function(){}); }catch(e){ errors.push('[play] '+(e&&e.stack||e)); }
// advance the intro's own rAF to specific timeline points (ms since first frame)
function tickTo(ms){ const cb=rafCb; if(cb){ try{ cb(ms); }catch(e){ errors.push('[frame '+ms+'] '+(e&&e.stack||e)); } } }
tickTo(1000);              // t0 anchor (elapsed 0)
tickTo(3500); snap('intro-walk');     // ~2.5s — happy walk
tickTo(11200); snap('intro-boom');
  tickTo(13500); snap('intro-squad');
  tickTo(19300); snap('intro-fire');
tickTo(24500); snap('intro-logo');    // ~23.5s — logo finale
console.error('intro errors: '+errors.length+(errors.length?' | '+errors.slice(0,6).join(' ; '):''));
console.error('saved: '+fs.readdirSync(OUT).filter(f=>f.indexOf('intro-')===0).join(', '));
process.exit(0);
