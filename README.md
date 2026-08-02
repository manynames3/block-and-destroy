# Block & Destroy

An unofficial voxel-character reskin of
[Squeak & Destroy](https://github.com/lospolosadwords-dev/squeak-and-destroy),
the MIT-licensed browser artillery game by Los Polos Amigos Kft.

The original turn-based gameplay, destructible terrain, AI, classes, weapons,
local multiplayer, game modes, PWA support, Electron wrapper and Capacitor wrapper
are preserved. The twelve animal bodies are replaced by original, procedurally
drawn block characters—there are no copied Minecraft textures, skins, sounds,
models or other game files in this repository.

> NOT AN OFFICIAL MINECRAFT PRODUCT. NOT APPROVED BY OR ASSOCIATED WITH MOJANG OR
> MICROSOFT.

## Character roster

The fork intentionally retains the upstream internal species IDs so existing
saves, mode setup, multiplayer messages, passives and weapon balance stay
compatible.

| Character | Compatibility ID | Inherited play style |
|---|---|---|
| Steve | `capybara` | swimmer / TNT mega bomb |
| Alex | `worm` | miner / diamond drill |
| Creeper | `hedgehog` | charged contact rush |
| Villager | `beaver` | builds block walls |
| Slime | `frog` | high, long jump |
| Skeleton | `bird` | feather fall |
| Zombie | `koala` | makeshift glider |
| Enderman | `bat` | void drift |
| Witch | `owl` | potion float |
| Piglin | `fox` | nimble landing |
| Iron Golem | `cat` | heavy leap |
| Snow Golem | `penguin` | ice slide |

The reskin lives primarily in [`js/voxel-theme.js`](js/voxel-theme.js). It patches
the display metadata and draw callbacks after the upstream registries load,
without changing gameplay IDs or physics.

## Beacon Wars prototype

Beacon Wars is the featured quick-start match: Steve and Enderman face Creeper
and Slime in a short 2v2 battle. A standing beacon revives one fallen teammate
after each settled turn. Destroy the enemy beacon to disable those respawns,
then eliminate both heroes.

Each match uses a deterministic Chaos Card—Low Gravity, Double Knockback, Rising
Tide, or Supply Rush—and an eight-tool core arsenal:

| Tool | Tactical role |
|---|---|
| Redstone Rocket | direct wind-affected artillery |
| TNT Timer | selectable 1–5 second fuse |
| Crossbow Burst | short-range hitscan finisher |
| Scatter Charge | cluster shell with manual mid-air burst |
| Diamond Drill | tunnel through terrain |
| Tripwire Charge | area denial |
| Block Builder | create emergency cover and bridges |
| Grappling Hook | high-skill traversal |

The weapon interactions take inspiration from the expressive artillery-toolbox
style of games such as *Worms*, while retaining original names, procedural art,
and the existing project code and audio.

## Run it

No install is required for local play:

```bash
python3 -m http.server 8123
```

Open <http://localhost:8123>. Localhost loads the individual source files for
fast iteration.

For a production bundle:

```bash
npm run build
```

That regenerates `bundle.min.js` from the load order shared by `build.js` and
`index.html`.

## Deploy to Cloudflare Pages

Build a static-only Pages artifact with the PHP backend and development tooling
excluded:

```bash
npm run pages:build
```

The deployable site is written to `dist/`. With Wrangler authenticated, publish
it to the existing Pages project with:

```bash
npm run pages:deploy
```

The static deployment keeps offline/local play available. Account, leaderboard,
matchmaking and other PHP-backed services require a separately hosted backend.

## Controls

| Key | Action |
|---|---|
| A / D | Move |
| W / S | Aim |
| Space | Jump |
| Mouse or Enter | Hold to charge, release to fire; press again to burst a live Scatter Charge |
| 1–9 / 0 | Select weapon |
| Q | Switch unit before firing |
| Tab | Cycle weapon |
| P / Esc | Pause |
| Scroll, + / − | Zoom |

## Architecture

The app is framework-free JavaScript: a Canvas2D battlefield plus DOM menus. The
shared `CA` global is defined by `js/contract.js`; `js/entities.js` owns unit
rendering and physics; `js/terrain.js` owns the destructible bitmap mask; and
`js/weapons.js` owns the ballistic simulation. See
[`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) for the upstream architecture.

When adding another runtime module, keep these three locations synchronized:

- the development module list in `index.html`
- `FILES` in `build.js`
- `PRECACHE` in `sw.js`

## Licensing and attribution

- Upstream source code is MIT licensed; retain [`LICENSE`](LICENSE).
- Upstream original art and code-drawn visual assets are CC BY-NC 4.0; see
  [`LICENSE-ASSETS.md`](LICENSE-ASSETS.md). Commercial use of those retained
  assets requires replacement or permission from Los Polos Amigos Kft.
- Third-party fonts, icons, music and audio retain their own licenses; keep
  [`ATTRIBUTIONS.md`](ATTRIBUTIONS.md) with redistributions.
- Minecraft character names and likenesses are Mojang/Microsoft intellectual
  property. Review the current Minecraft Usage Guidelines before publishing,
  monetizing, or marketing this fan reskin. For a commercial release, use wholly
  original voxel identities and replace the retained CC BY-NC upstream assets.

The independent `Block & Destroy` name, block icon and procedural character art
were added for this fork. The original game and studio credit remain attributed.
