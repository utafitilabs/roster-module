# Upgrading

What an installation has to do — or knowingly not do — when it takes a new
release of this module. Nothing here is automatic: a release that needed a
hand is a release with a note under it.

## Contents

- [The rule for anything this module ships to a host](#the-rule-for-anything-this-module-ships-to-a-host)
- [Stimulus controllers an older installation will not have](#stimulus-controllers-an-older-installation-will-not-have)
- [0.1.0 — `rotation`, the cycle editor](#010--rotation-the-cycle-editor)
- [0.1.0 — `now-line`, the day board's line at "now"](#010--now-line-the-day-boards-line-at-now)
- [`station_watch.catchment_metres` is dropped](#station_watchcatchment_metres-is-dropped)

## The rule for anything this module ships to a host

**Nothing a host has in its own tree is removed in one release.** A Stimulus
controller, a config key, a template block an installation may have
overridden: the release that stops using it ships it deprecated and inert, and
the release AFTER that deletes it. The reason is mechanical rather than
polite — Flex keeps a host's own `assets/controllers.json` entry when a package
it already has is updated, so anything this package deletes in one step stays
switched on over there with nothing behind it.

## Stimulus controllers an older installation will not have

**This is the one upgrade step this module has, and it is invisible until
somebody clicks something.**

`assets/controllers.json` is the INSTALLATION's file. Flex writes a package's
entry into it from that package's own `assets/package.json`
(`symfony.controllers`) **at install time** — no recipe ships the file, and
there is nothing to merge on a later update. So an installation that installed
this module BEFORE a given release declared a controller never receives it,
and the markup that names it renders perfectly and does nothing.

It is worth stating how this fails, because it fails quietly: there is no
error, no console warning and no missing element. The control is simply inert.
It was found on a test bed where the cycle editor had never once worked.

**The fix, either way round:**

```bash
composer recipes:install uhifadhi/roster-module --force --no-interaction
```

…or add the block by hand, which is all the command does here:

```jsonc
// assets/controllers.json
"@uhifadhi/roster-module": {
    "rotation": { "enabled": true, "fetch": "eager" },
    "now-line":  { "enabled": true, "fetch": "eager" }
}
```

Then `php bin/console asset-map:compile` and `cache:clear`. Verify by grepping
the compiled map rather than by eye — a controller that is registered appears
in it, and one that is not does not:

```bash
grep -c 'roster-module/controllers' public/assets/importmap.json   # expect 2
```

**Check this on every update that adds a controller.** Each release below says
which one it added.

## 0.1.0 — `rotation`, the cycle editor

The rotation editor's ring, its per-day counts and its pool are held as a
DRAFT until Save, by the `rotation` controller. Without it the page still
READS — the server renders the rotation as it stands — but nothing can be
edited: the ring's buttons are dead and the form submits the draft it was
rendered with.

Enable `@uhifadhi/roster-module/rotation` as above.

## 0.1.0 — `now-line`, the day board's line at "now"

The day board's line at the current minute is placed by the VIEWER's clock,
not the server's, and re-placed every minute — so a board left open on an
office wall keeps telling the truth, and a reader in another timezone sees
their own time rather than the server's.

Without the controller **the line does not appear at all**. That is
deliberate: the element ships hidden and the browser reveals it once it has
placed it, because a line drawn at the server's idea of now is worse than no
line — it is wrong by the offset between the two clocks, and it looks
authoritative.

Enable `@uhifadhi/roster-module/now-line` as above.

## `station_watch.catchment_metres` is dropped

**The column goes in this release's migration. Nothing to do if you never read it.**

**What changed.** A post's catchment — how close a ping has to be for a claim
of "at post" to read as verified — now has one home: `station.catchment_m`,
the column the AREA measures against and its own `StationService::setCatchment()`
writes. This module no longer reads `station_watch.catchment_metres` anywhere.
The Watches section still edits the distance and still shows it; it writes it
to the post.

**Why.** Verification always read the post's column. This module held the same
number in a second one, so the field on the configure page edited something
nothing measured — for a while the page carried a flag saying exactly that.
Two columns for one distance are two answers the day somebody edits one of
them, and the area has now grown its own row for the same value.

**What happens to the old column.** It is gone. The release before this one
left it written-and-never-read so there was a version where both columns were
true; `Version20260924000000` now drops it, marked `@destructive`. Its `down()`
puts the column back and fills it from the post's own ring, so a rollback
lands on a schema an installation can run.

**If you read it yourself.** An installation or a module that queried
`station_watch.catchment_metres` should move to the station's column now:

```diff
-$metres = $watch->getCatchmentMetres();
+$metres = $watch->getStation()->getCatchmentM();
```

and write through the area's verb rather than the entity:

```diff
-$watch->setCatchmentMetres($metres);
+$stations->setCatchment($station, $metres);
```

`getCatchmentMetres()` and `setCatchmentMetres()` are gone with the column, as
is the property that mapped it.

**The area's default is unaffected.** `roster.default_catchment_metres` and the
Settings field above it are a different thing — the ring a post falls back on
when it carries none of its own — and they stay exactly as they are.
