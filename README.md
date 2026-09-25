# uhifadhi/roster-module

Who is due on watch, where and when: the rotation a post or a team runs, the
duties it generates, the swaps and absences that change them, and the on-duty
presence read back from the area. A [uhifadhi](https://github.com/uhifadhilabs)
module bundle.

> **Status: in build.** The domain was ruled on 20 September 2026 and this
> repository is being built against that ruling. See
> [What is built](#what-is-built) for where it has got to.

## Contents

- [Charter](#charter)
- [What this module owns — and what it does not](#what-this-module-owns--and-what-it-does-not)
- [What is built](#what-is-built)
- [Installation](#installation)
- [Configuration](#configuration)
- [Development](#development)
- [License](#license)

## Charter

**Planned work, not performed work.** A roster says who was *meant* to be
somewhere; a patrol says what was *done*. This module owns the plan, and the
one place the two touch is a person on a watch who is out on a patrol — printed
with the patrols module's own id and never copied.

**Presence is derived, never typed.** There is no on-duty field on any form in
this module, for any role, including an administrator. Who is actually at a
post is read from the area's check-ins and the pings that followed them.

## What this module owns — and what it does not

| | Owner |
|---|---|
| Station, posting, check-in, position | **the area** |
| Person, position, department | **team** |
| Rotation — cycle, slots per shift, pool, horizon, base post | **roster** |
| Duty — one watch, one station, one day | **roster** |
| Swap — two cells, accepted on the handset | **roster** |
| Absence — person, from, to, kind, recorded by | **roster** |
| The watch a station expects — shifts, silence window, catchment, pool | **roster**, contributed onto the area's station record |
| Presence — who is here now, verified, late, offline | **read** from the area's presence seam |
| Ping interval — how often a handset reports, what "twice the interval" counts from | **the area** — set on its Area settings, shown read-only on the Watches rules card |

Keeping the three apart is what lets a ranger cover another station for a
fortnight without the org chart quietly rewriting itself.

## What is built

| Piece | File |
|---|---|
| The Symfony plug | `src/UhifadhiRosterBundle.php` |
| Config tree (`roster:`) | `src/DependencyInjection/RosterConfiguration.php` |
| Catalogue registration | `src/Module/RosterModuleProvider.php` |
| The shift vocabulary an area runs | `src/Entity/Shift.php` |
| The standing rotation and its ordered pool | `src/Entity/Rotation.php`, `src/Entity/RotationPoolMember.php` |
| One watch, one station, one day | `src/Entity/Duty.php` |
| The day the generator must not touch | `src/Entity/EditedDay.php` |
| Who is away, and why | `src/Entity/Absence.php` |
| Two cells, offered and answered on the handset | `src/Entity/Swap.php` |
| Presence, read from the area and joined to the roster | `src/Service/PresenceReader.php` |
| The handset's month | `src/Module/RosterWatches.php` |
| The watch on the area's own station pages | `src/Shell/RosterStationSections.php` |
| What the ring says — pure, no database | `src/Service/CyclePlanner.php` |
| What becomes a row | `src/Service/RotationGenerator.php` |
| The SQL that creates it all | `migrations/` |
| The four columns the roster owns on a station | `src/Entity/StationWatch.php` |
| The answers this module cannot guess | `src/Entity/AreaRosterSettings.php` |
| The tabs and the configure sections | `src/Shell/` |
| All six tabs | `src/Controller/RosterController.php` |
| One ranger's month, fed to the house calendar | `src/Service/RosterCalendar.php` |
| The day as a wall | `src/Service/DayBoardService.php` |
| Rotation · Watches · Settings | `src/Controller/RosterConfigureController.php` |
| The module's own vocabulary | `public/roster.css` |
| Static service wiring | `config/services.php` |
| Test installation | `tests/Integration/TestKernel.php` |

Still to come: the swap flow on the Week tab (the picker, the cost bar and
the offer states, being graduated from the archive), the Live tab's plate,
the cycle editor, the widget surface and its presets, the overview
contributions, the `WatchProviderInterface` implementation the area's
`/me/roster` answers the handset through, and the presence reads behind all
of them.

The bundle maps its own entity directory and serves its own assets, so an
installation writes no doctrine block and no asset path for it.

## Installation

```console
composer require uhifadhi/roster-module
php bin/console cache:clear --no-warmup
php bin/console doctrine:migrations:migrate
php bin/console registry:sync
php bin/console cache:warmup
```

Those are the installation's four commands after the require, the same four after every change to it. `doctrine:migrations:diff` must then report no changes: this module ships its own versions. `registry:sync` enters the module in the catalogue, gives every area its row and prints what it added, kept and retired; in development AssetMapper serves the module's assets from source while the production image compiles them.

The **Flex recipe** (`uhifadhi/roster-module/0.1` in `utafitilabs/recipes`)
adds `Uhifadhi\Roster\UhifadhiRosterBundle` to `config/bundles.php`, mounts
`config/routes/roster.yaml` and writes `config/packages/roster.yaml` with the
vocabulary below. Entity mapping, the migrations path and the module's icon
set are prepended by the bundle itself, so there is nothing else to wire.

### The two lines a recipe cannot merge

`assets/controllers.json` is the INSTALLATION's file and Flex merges into it,
but an installation that was built before this module shipped its controllers
will not have them — and a Stimulus controller that is not enabled there is
markup that looks perfect and does nothing. Check for this block and add it if
it is missing:

```jsonc
// assets/controllers.json
"@uhifadhi/roster-module": {
    "rotation": { "enabled": true, "fetch": "eager" },   // the cycle editor
    "now-line":  { "enabled": true, "fetch": "eager" },  // the day board's line at "now"
    "bound":     { "enabled": true, "fetch": "eager" }   // one height for every bounded card
}
```

The widget library also imports `uhifadhi/widgets`, which the CORE's own
recipe puts in `importmap.php`; an installation running the shell already has
it.

`docs/upgrading.md` says which release added which controller, and how to
check the compiled map rather than trusting the eye.

Then **switch it on per area** — a module is installed but parked, and every
page answers 404 in an area that has not taken it — and hand out the two
grants this module declares: `roster.record` to whoever fills, publishes and
swaps the day's watches, and `roster.configure` to whoever changes how the
area runs its roster at all. Every page that only reads opens on the ground's
own `areas.read`, so a reader who may open the area may read its roster.

### What a development machine needs

Set **`opcache.enable_cli=1`** in `php.ini`. The opcode cache is off for the
CLI by default, so every console run recompiles every file it touches — and
the runs this module asks for walk the registry, the entity mappings and the
templates each time. A production image compiles its cache once at build and
never pays that cost.

> "opcache.enable_cli *bool* — Enables the opcode cache for the CLI version of
> PHP." Default `0`.
> — <https://www.php.net/manual/en/opcache.configuration.php#ini.opcache.enable-cli>

Nothing else is different in development: the four commands above are the
whole sequence, and there is no by-hand warm-up beyond `cache:warmup`.

## Configuration

```yaml
# config/packages/roster.yaml
roster:
    module_category: operations   # catalogue category for the module tile
    dev_tools: false              # dev-only tooling; when@dev / when@test

    # The named windows a watch can be stood in. A station declares which of
    # them it runs; a window may cross midnight, and a duty belongs to the
    # calendar day its watch BEGINS on.
    shifts:
        - { key: day,    label: Day,         start: '06:00', end: '18:00' }
        - { key: night,  label: Night,       start: '18:00', end: '06:00' }
        - { key: office, label: Office,      start: '07:30', end: '16:30' }
        - { key: radio,  label: Radio night, start: '18:00', end: '06:00' }

    # The values a new area setting, station watch or rotation STARTS at.
    # What any of them actually runs at afterwards is its own stored value,
    # edited on a screen — nothing here is read at display time.
    # The ping interval is not here: it is the AREA's, set on its Area settings.
    defaults:
        silence_window_minutes: 120
        offline_after_minutes: 1440
        catchment_metres: 1500
        horizon_days: 42
```

Every key has a default and the tree is closed, so an unknown key fails loudly
rather than being ignored.

## Development

```bash
composer install
composer check      # cs:check -> phpstan (max) -> phpunit
```

- PHP 8.4+, PHPStan level **max** over `src`, `tests` and `migrations`,
  php-cs-fixer `@Symfony` + `@Symfony:risky`.
- **Tests first, always.**
- The integration suite boots a real installation
  (`tests/Integration/TestKernel.php`) against a real PostGIS database at
  `postgresql://app:app@127.0.0.1:5434/roster_bundle_test`. Never SQLite.

## License

**AGPL-3.0-or-later** — see [LICENSE](LICENSE): the same license as the
uhifadhi core this module plugs into. Use, modify and self-host freely; if you
offer a modified version to users over a network, they are entitled to the
source of what they're running.
