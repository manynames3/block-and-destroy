# Architecture

A tour of how Squeak & Destroy is built, aimed at someone about to change it.

## Shape of the thing

One global — `CA` — declared in `js/contract.js`. Every module attaches to it and
communicates through it. There is no module system, no bundler at development
time: `index.html?dev` loads 50 `<script>` tags in dependency order, and
`node build.js` concatenates and minifies the same list into `bundle.min.js` for
production. The game is a `<canvas>` for the battlefield plus a DOM layer for the
menus.

Nothing is imported. Nothing is transpiled. Load order *is* the dependency graph,
and it is defined in exactly two places that must agree: `build.js` `FILES` and
the dev script list in `index.html`. (`sw.js` `PRECACHE` is the third place a new
file must be registered, for offline support.)

## The contract

`js/contract.js` is deliberately the first file and the only one that other
modules may assume exists:

- `CA.config` — tunable constants (world size, gravity, unit radius, turn timer)
- `CA.state` (aliased `S` inside modules) — the entire mutable game state, including
  `seed`, `teams`, `units`, `camera`, `wind`, `waterLevel`, `frame`
- `CA.events` — a synchronous pub/sub bus (`on` / `emit`). This is how modules talk.
- `CA.util` — RNG, math, geometry helpers
- **`CA.explode(x, y, radius, damage, knockback)`** — the single damage pipeline

That last one matters most. Every explosive thing in the game routes through it:
it carves the terrain mask, applies falloff damage to units in range, spawns the
FX, and shakes the camera. When a weapon "does damage", it calls `CA.explode`. A
second damage path would silently desynchronise terrain from units, so there
isn't one.

Also on the contract: `CA.getUnit`, `CA.getTeam`, `CA.activeUnit`,
`CA.aliveUnitsOfTeam`, `CA.aliveHeroesOfTeam`, and the ammo accessors
(`CA.ammoOf` / `spendAmmo` / `addAmmo` / `resetAmmo` — ammo is per-team).

## Modules

| File | Responsibility |
|---|---|
| `contract.js` | config, state, event bus, `CA.explode`, shared accessors |
| `core.js` | fixed-step game loop, turn manager, camera, input, win checks, `newGame` |
| `terrain.js` | destructible bitmap mask + cached painted canvas |
| `entities.js` | unit drawing, animation, physics, damage, drowning, fall damage |
| `weapons.js` | 63 weapons, ballistics, projectile stepping, `predict()` for the AI |
| `ai.js` | target selection, positioning, weapon choice, self-frag avoidance |
| `species.js` / `classes.js` | 12 species (passive + ultimate), 6 classes (skill trees) |
| `maps.js` / `weather.js` / `hazards.js` | 13 themes, weather, fire/poison/mines/barrels |
| `fx.js` | parallax backgrounds, particles, WebAudio mixer and sample bank |
| `ui.js` | canvas HUD, weapon bar, touch controls, pause overlay |
| `menu.js` + `menu-*.js` | DOM menu framework and its screens |
| `modes.js` | game modes expressed as hooks |
| `meta.js` / `entitlement.js` / `portal.js` / `ads.js` | progression, gating, web-portal and ad shims |
| `net.js` / `clans.js` / `ranked.js` / `account.js` | optional online layer |
| `i18n.js` + `i18n-*.js` | six languages, lazily loaded |

## Terrain

The map is an **offscreen canvas used as a bitmap mask**: alpha > 0 means solid.
`destroy()` clears a circle and repaints only the dirty rectangle of the cached
*painted* canvas — the one with grass crust, dirt gradient, rock speckle and
ambient occlusion. This split is why a 40-crater match still runs at 60 fps: the
expensive painting is incremental, and collision only ever reads alpha.

Generation is a seeded `mulberry32` PRNG driving multi-octave hills, floating
islands and caves. **The order of RNG calls is load-bearing**: saves store a seed,
not a heightmap, and daily challenges rely on every client generating the same
world. Append new generation at the end of the stream, or derive it from a
position hash.

## The turn loop

`core.js` runs a fixed 60 Hz accumulator. A turn is:

```
startTurn → AIM (input/AI) → FIRING → SETTLE (projectiles + physics settle) → endTurn
```

`endTurn` rotates to the next team's next living **hero** (minions do not take
turns), applies end-of-round effects, drops crates every third round, and raises
the water level in sudden death. `CA.onFired` decides whether a weapon ends the
turn — support and mobility abilities (shields, heals, teleport, parachute) do
not, damaging shots do.

Camera work is in the same file: follow with lerp, shake on impact, an impact
hold, and a kill-cam that delays the turn change so you get to watch.

## Game modes

A mode is an object of optional hooks — `setup`, `onTurnStart`, `onTurnEnd`,
`checkWin`, `update`, `render` — registered in `modes.js`. `core.js` calls them
if present. This is how twelve modes share one engine without branching all over
the loop. When adding a mode, check win conditions against
`CA.aliveHeroesOfTeam`, not `aliveUnitsOfTeam`: a stray summoned minion should not
keep a wiped-out player alive.

## Rendering and quality

`fx.js` `renderBg()` draws layered parallax with atmospheric perspective and
per-theme accents. It is quality-aware in three tiers and respects
`prefers-reduced-motion`. `perf-tier.js` picks a sensible default tier on first
run and never overrides an explicit user choice.

If a background image pack is absent, `renderBg()` falls back to fully procedural
parallax — that is the path this repository ships on (see
[ASSET_LICENSING.md](ASSET_LICENSING.md)). Similarly, `CA.speciesIcon(id)` and
`CA.img(path)` are lazy, cached, and **never throw**: a missing PNG degrades to
code-drawn art or an emoji glyph.

## Audio

`fx.js` owns a WebAudio graph: a master `DynamicsCompressor` limiter (chain
explosions used to clip), per-sound gain against the SFX volume setting, sample
throttling, and a lazy `AudioContext` created on the first user gesture. Samples
are fetched and decoded on demand with a synth fallback, so a missing file is
never fatal. Music selects OGG or MP3 by codec support.

## Persistence

`localStorage` holds settings, meta progression, achievements, saved teams and
saved games. Saves store the **seed**, not the terrain. The optional PHP backend
adds cloud profiles, leaderboards and clans; none of it is required to play, and
every call is defensive — offline is a first-class state, not an error.

## Offline and packaging

`sw.js` is network-first for code, cache-first for assets, network-only for
`.php`. Bump its `CACHE` constant with each release or clients keep the old
bundle. The same tree wraps into Electron (`electron/`) and Capacitor
(`capacitor.config.json`, `cap-www.js`); both run from `file://`, where the
service worker is deliberately skipped and `CA.BACKEND_ORIGIN` (in
`contract.js`) points the optional backend calls at a real origin.

## Gotchas worth knowing

- A new module must be added to `build.js`, `index.html` and `sw.js`. Missing
  `build.js` means it works in dev and disappears in production.
- Duplicate keys in an object literal: the last one wins, silently. This cost us
  a music-volume bug.
- `CA.FX` and `CA.fx` are aliases of the same object.
- The `unitDamaged` event is three-arg: `(unit, amount, {unit, amount, crit, x, y})`.
- In a background tab `requestAnimationFrame` stops, so headless tests must step
  physics manually (`CA.Entities.update(1)`).
- The backend targets PHP 5.6 — no `??`, no short array syntax.
