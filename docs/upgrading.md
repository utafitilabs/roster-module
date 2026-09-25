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
- [0.1.2 — the ping interval is the area's](#012--the-ping-interval-is-the-areas)

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

## 0.1.2 — the ping interval is the area's

**Needs the core release that ships Area settings › Ping every.** Widen the
installation's `uhifadhi/uhifadhi` constraint to it, then `composer update`,
`cache:clear`, `doctrine:migrations:migrate` (no roster migration in this
release) and `asset-map:compile`.

**What changed.** How often a handset reports is set on the AREA — the Ping
every field on its Area settings, `AreaOfInterest::$pingIntervalMinutes` — and
this module reads it there, through the core's `PingInterval`. The Watches
rules card shows it as a read-only row with a door to the area's settings;
saving the rules, a station's exceptions or the Settings form never writes it.
"Twice the interval" counts from the area's number
(`RosterSettingsService::lateAfterMinutes()`).

**Why.** The handset has always been told the area's number. The roster's own
column was a second copy that nothing in the field read, so changing it
changed nothing on the phones.

**Carry the value across once.** An area whose roster rule said something other
than 30 minutes should get that number on its Area settings, because the
phones never saw the roster's copy. To see which areas differ:

```sql
SELECT a.name, s.ping_interval_minutes AS roster_copy, a.ping_interval_minutes AS the_areas
FROM roster_area_settings s JOIN area_of_interest a ON a.id = s.area_id
WHERE s.ping_interval_minutes IS DISTINCT FROM COALESCE(a.ping_interval_minutes, 30);
```

Set each one on the area's Area settings, or leave it: the phones already run
at the area's value.

**Deprecated here, removed in the next release.**

| What | This release | Next release |
|---|---|---|
| `roster.defaults.ping_interval_minutes` | accepted, read by nothing, a deprecation notice where set | removed — delete the key from `config/packages/roster.yaml` |
| `%roster.default_ping_interval_minutes%` | still set | removed |
| `roster_area_settings.ping_interval_minutes` | written once on a new row, read by nothing | dropped by an `@destructive` migration |
| `ping_every` rows in `roster_shift_rule` and `roster_station_rule_exception` | kept, read by nothing | deleted by the same migration |
| `AreaRosterSettings::getPingIntervalMinutes()` / `setPingIntervalMinutes()` | deprecated, reading and writing the column nothing reads — read `PingInterval::for($area)` | removed with the column |
| `AreaRosterSettings::lateAfterMinutes()` | removed — it counted from the roster's copy; read `RosterSettingsService::lateAfterMinutes($area)` | — |
| `RosterSettingsService::save()` | takes no interval: `save($area, $offDayHasNoState, $leaveApprovalShown, $catchmentMetres, $lateThreshold, $vacancyAnnounce)` | — |
