// Preload bridge for Steam integration.
//
// Runs in an isolated context (contextIsolation:true) and exposes a tiny,
// safe surface to the game's renderer as `window.SteamAPI`. This object only
// exists in the Electron build — on the web/mobile builds `window.SteamAPI`
// is simply undefined, so renderer code should feature-detect it.
//
// Bridge contract (what the game calls):
//   window.SteamAPI = {
//     available: true,                  // present => running inside Electron+preload
//     appId:     <number>,              // the full Steam App ID we initialized with
//     ready:     () => Promise<boolean>,// resolves true if Steam initialized OK
//     isOwned:   () => Promise<boolean>,// true if the user owns the full app
//     openStore: () => void             // open the Steam store page (overlay or browser)
//   }
//
const { contextBridge, ipcRenderer } = require('electron');
const { STEAM_APP_ID } = require('./steam-config.js');

// Defensive: if for some reason ipcRenderer is missing, fall back to inert
// stubs so the renderer never throws when probing the API.
const hasIpc = !!(ipcRenderer && typeof ipcRenderer.invoke === 'function');

contextBridge.exposeInMainWorld('SteamAPI', {
  available: true,
  appId: STEAM_APP_ID,
  ready: () => (hasIpc ? ipcRenderer.invoke('steam:ready') : Promise.resolve(false)),
  isOwned: () => (hasIpc ? ipcRenderer.invoke('steam:isOwned') : Promise.resolve(false)),
  openStore: () => {
    if (hasIpc) {
      try { ipcRenderer.send('steam:openStore'); } catch (_) { /* no-op */ }
    }
  },
});
