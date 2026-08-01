# Contributing

Thanks for looking. This is a hobby project that grew large, so the bar is
"does it work and does it fit the existing style", not ceremony.

Questions, or want to check an idea before you build it?
**[discord.gg/sBfBdB8AzK](https://discord.gg/sBfBdB8AzK)**

## Setup

There is no install step. Serve the folder and open it:

```bash
python3 -m http.server 8123
```

Develop against <http://localhost:8123/index.html?dev> — that loads the 50
individual modules from `js/` instead of `bundle.min.js`, so a reload is your
whole edit cycle. `/dev/` is a sandbox arena with every weapon, infinite HP and
unlimited firing, which is the fastest way to test a weapon or an effect.

Before you open a PR, run the build so the bundle matches the sources:

```bash
node build.js
```

## The rules that actually matter

1. **A new module goes in three places.** `build.js` `FILES`, the dev script list
   in `index.html`, and `sw.js` `PRECACHE`. Miss `build.js` and your code simply
   vanishes from production — this has bitten us more than once.
2. **`js/contract.js` is the contract.** Config, state, the event bus and
   `CA.explode()` live there. `CA.explode(x, y, radius, damage, knockback)` is the
   *only* damage pipeline — terrain, units, FX and screen shake all hang off it.
   Do not open a second one.
3. **Modules talk through `CA.events`,** not by reaching into each other.
4. **Determinism is load-bearing.** Terrain generation and wind are seeded
   (`mulberry32` off `S.seed`) so saves, daily challenges and replays reproduce.
   If you add generation, append it at the *end* of the random stream or derive it
   from a hash — inserting a call in the middle changes every existing seed.
5. **PHP must stay compatible with old hosts** — no `??`, no short array syntax
   in the backend files. They target PHP 5.6 and still run on it.
6. **No new runtime dependencies.** No framework, no CDN script. The game must
   keep working from `file://` and offline.
7. **New user-facing strings need translations.** Add the key via `addEN(...)`
   and to every `js/i18n-<lang>.js`. `node build.js` warns about drift.

## Assets

Read [docs/ASSET_LICENSING.md](docs/ASSET_LICENSING.md) first. Short version:
**CC0, CC BY, or your own work** — nothing from Freepik, Flaticon, CraftPix,
LudicArts or any other use-only marketplace, however tempting the art is. Add the
source and licence to `ATTRIBUTIONS.md` *and* to `js/menu-credits.js`.

Replacing the removed art with CC0 equivalents is the single most valuable
contribution available right now.

## Pull requests

- One topic per PR. Balance changes separate from engine changes.
- Say how you tested it. "Ran a classic match on the cave map, fired the drill
  five times" beats "should work".
- Match the surrounding code: same comment density, same naming, tabs vs spaces
  as the file already uses. Do not reformat files you are not changing.
- Commit `bundle.min.js` alongside source changes so the production build stays
  in sync.

## Bugs

Open an issue with the map, the mode, the weapon, and what you expected — plus
the browser and whether it was touch or desktop. If you can reproduce it, read
`CA.state.seed` from the console and include it: terrain is seeded, so the same
seed rebuilds the same map.
