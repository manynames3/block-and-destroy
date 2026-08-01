// Squeak & Destroy — macOS desktop wrapper (Electron).
// The game is a pure static web app; index.html already loads the individual
// js/*.js files under file:// (see index.html dev-loader), so we just point a
// BrowserWindow at it. In dev we load ../index.html; when packaged, the whole
// game folder is copied to Contents/Resources/game (see package.json build).
const { app, BrowserWindow, shell, Menu, ipcMain } = require('electron');
const path = require('path');

// ── Steam integration (steamworks.js) ─────────────────────────────────────
// Fully defensive: if steamworks.js is not installed, the Steam client isn't
// running, or the app wasn't launched through Steam, `steamClient` stays null
// and the app runs as a normal desktop demo. NOTHING here may crash startup.
const { STEAM_APP_ID } = require('./steam-config.js');
let steamClient = null;
try {
  const steamworks = require('steamworks.js');
  // steamworks.js: init(appId) returns the client object (Omnibus API).
  steamClient = steamworks.init(STEAM_APP_ID);
  // Some steamworks.js builds expose an Electron helper to enable the overlay
  // in the renderer. Best-effort; ignore if absent or it throws.
  try { steamworks.electronEnableSteamOverlay && steamworks.electronEnableSteamOverlay(); } catch (_) {}
  console.log('[steam] initialized for appId', STEAM_APP_ID);
} catch (e) {
  steamClient = null;
  console.log('[steam] not available (running as plain desktop):', e && e.message);
}

// Some steamworks.js versions require manual callback pumping; others run a
// background thread. Calling runCallbacks() when it exists is harmless and
// keeps overlay/ownership state fresh. Guard everything.
if (steamClient && typeof steamClient.runCallbacks === 'function') {
  setInterval(() => { try { steamClient.runCallbacks(); } catch (_) {} }, 1000 / 30);
}

// IPC: ownership / ready / open-store. Renderer reaches these via preload.js.
ipcMain.handle('steam:ready', () => !!steamClient);

ipcMain.handle('steam:isOwned', () => {
  // steamworks.js API: client.apps.isSubscribedApp(appId) -> boolean.
  // (ASSUMPTION: confirm against the installed steamworks.js version's README;
  //  the Omnibus `apps` namespace exposes isSubscribedApp.)
  try {
    if (steamClient && steamClient.apps && typeof steamClient.apps.isSubscribedApp === 'function') {
      return !!steamClient.apps.isSubscribedApp(STEAM_APP_ID);
    }
  } catch (_) { /* fall through */ }
  return false;
});

ipcMain.on('steam:openStore', () => {
  // Prefer the in-app Steam overlay store page; fall back to the public store
  // deep-link in the system browser when the overlay isn't usable.
  try {
    if (steamClient && steamClient.overlay && typeof steamClient.overlay.activateToStore === 'function') {
      steamClient.overlay.activateToStore(STEAM_APP_ID);
      return;
    }
  } catch (_) { /* fall through to browser */ }
  shell.openExternal('https://store.steampowered.com/app/' + STEAM_APP_ID);
});
// ───────────────────────────────────────────────────────────────────────────

function indexPath() {
  return app.isPackaged
    ? path.join(process.resourcesPath, 'game', 'index.html')
    : path.join(__dirname, '..', 'index.html');
}

function createWindow() {
  const win = new BrowserWindow({
    width: 1280,
    height: 800,
    minWidth: 900,
    minHeight: 560,
    backgroundColor: '#15151f',
    title: 'Block & Destroy',
    show: false,
    webPreferences: {
      contextIsolation: true,
      nodeIntegration: false,
      backgroundThrottling: false,
      preload: path.join(__dirname, 'preload.js'),
    },
  });
  win.once('ready-to-show', () => win.show());
  win.loadFile(indexPath());
  // open any external (http/https) link in the system browser, never in-app
  win.webContents.setWindowOpenHandler(({ url }) => {
    if (/^https?:/.test(url)) shell.openExternal(url);
    return { action: 'deny' };
  });
  // never let an in-app navigation leave the local game (e.g. a stray
  // location.href to an external URL would otherwise replace the game UI).
  // Anything non-file:// is bounced to the system browser instead.
  win.webContents.on('will-navigate', (e, url) => {
    if (!url.startsWith('file:')) {
      e.preventDefault();
      if (/^https?:/.test(url)) shell.openExternal(url);
    }
  });
  return win;
}

app.whenReady().then(() => {
  // minimal native menu (Cmd+Q/Cmd+W/fullscreen/devtools)
  Menu.setApplicationMenu(Menu.buildFromTemplate([
    { role: 'appMenu' },
    { role: 'editMenu' },
    { role: 'viewMenu' },
    { role: 'windowMenu' },
  ]));
  createWindow();
  app.on('activate', () => {
    if (BrowserWindow.getAllWindows().length === 0) createWindow();
  });
});

app.on('window-all-closed', () => {
  if (process.platform !== 'darwin') app.quit();
});
