# Changelog

Every release of `uhifadhi/roster-module`, newest first. What an installation
has to do for a release is in [docs/upgrading.md](docs/upgrading.md).

## Contents

- [0.1.2](#012)

## 0.1.2

Not released yet.

 * the ping interval is the area's: the Watches rules card shows the area's
   Ping every as a read-only row with a door to the area's settings, and the
   rules, a station's exceptions and the Settings form no longer carry it
 * "twice the interval" counts from the area's interval, read through the
   core's `PingInterval` — `RosterSettingsService::lateAfterMinutes()` and
   `pingIntervalFor()`; the identity band reads the same number
 * a station can no longer be given its own ping interval;
   `ShiftRuleService::setException()` refuses it
 * `roster.defaults.ping_interval_minutes` is deprecated and read by nothing;
   `roster_area_settings.ping_interval_minutes` is written once and read by
   nothing, and is dropped by an `@destructive` migration in the next release
 * `AreaRosterSettings::getPingIntervalMinutes()` and `setPingIntervalMinutes()`
   are deprecated and go with the column; `AreaRosterSettings::lateAfterMinutes()`,
   which counted from the roster's copy, is removed; `RosterSettingsService::save()`
   takes no interval
 * needs the core release that ships Area settings › Ping every
