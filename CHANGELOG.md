# Changelog

Every release of `uhifadhi/roster-module`, newest first. What an installation
has to do for a release is in [docs/upgrading.md](docs/upgrading.md).

## Contents

- [0.1.3](#013)
- [0.1.2](#012)

## 0.1.3

 * the day board opens on the viewer's own today: a board the server drew for another day (a server on UTC, a viewer past midnight) is reopened asking for the viewer's date, so the now line is never hidden on a board that says yesterday

Not released yet.

 * the week sheet's cover token under every day of a station's head row
   counts a station that names no number against the rangers stationed
   there — "3/4" where it read a dash — and a day nobody stands there wears
   the empty mark; such a day is never counted as short cover
   (`SheetCover::$againstStationed`, `SheetCover::countsAsShort()`)
 * the day board's line at "now" stays at the centre of the board and the
   hours scroll under it: the hours are wider than the window
   (`--r-day-hours`, twelve by default), the post names and the hour scale
   are pinned, and every minute the `now-line` controller scrolls the board
   so now sits at the centre of the window, clamped at the two ends of the
   day; a scroll by hand is left alone until the viewer's day changes, and a
   resize re-centres. The hour scale's labels sit at the start of their
   hours, as the design draws them
 * one height for every bounded card: the day board, "here now", the
   agenda's day and the roster under the live plate scroll inside
   themselves by the week sheet's rule, with their heads pinned, measured by
   the new `bound` controller (`CardBound`); the week sheet spends the same
   rule and is a fifth shorter — a 536px floor, and 0.8 of the measured card.
   The scroller's bound is `--cardmax` on `.rscroll`; `--sheetmax` is gone.
   Enable `@uhifadhi/roster-module/bound` (docs/upgrading.md)

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
