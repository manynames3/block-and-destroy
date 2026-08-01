// Steam configuration for Squeak & Destroy (Electron desktop build).
//
// ⚠️⚠️⚠️ REPLACE with your real Steam App ID once you have a Steam partner
// account + an app created in the Steamworks partner backend. ⚠️⚠️⚠️
//
// Until then we use 480 (Valve's "Spacewar" SDK test app), which lets the
// Steamworks SDK initialize successfully on any machine that has the Steam
// client running, so the integration can be exercised end-to-end.
//
// When you get the real App ID you must change it in TWO places:
//   1) STEAM_APP_ID below
//   2) electron/steam_appid.txt  (the Steam client reads that on dev launch)
//
const STEAM_APP_ID = 480; // Spacewar test app — replace with real App ID.

module.exports = { STEAM_APP_ID };
