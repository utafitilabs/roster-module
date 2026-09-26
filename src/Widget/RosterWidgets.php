<?php

declare(strict_types=1);

/*
 * This file is part of the UhifadhiLabs Roster Module.
 *
 * (c) Ezekiel Mjema <https://github.com/eemjema>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Uhifadhi\Roster\Widget;

use Uhifadhi\Bundle\ShellBundle\Widget\Model\Widget;
use Uhifadhi\Bundle\ShellBundle\Widget\Model\WidgetCatalog;
use Uhifadhi\Bundle\ShellBundle\Widget\Model\WidgetGroup;
use Uhifadhi\Bundle\ShellBundle\Widget\Model\WidgetPreset;
use Uhifadhi\Bundle\ShellBundle\Widget\Registry\WidgetSurfaceInterface;

/**
 * THE CATALOGUE of the per-area ROSTER surface — a transcription of the
 * design's own surface declaration (roster.widgets.js), which is the spec.
 *
 * THE OVERVIEW IS COMPOSED, NOT DRAWN. The tab renders whatever the widget
 * framework resolves for this surface, and what it ships resolving to is the
 * composition the design settled on 20 sep: the figures, what needs a
 * decision, the posts and who the pings put on them, the plate. Four of the
 * sixteen; the other twelve are in the library and any of them can be pulled
 * onto the tab.
 *
 * FOUR WIDGETS LEFT THE OVERVIEW AND BECAME TABS — the day board, the
 * check-in feed, reporting-right-now and the registry. They stay in the
 * catalogue, because a person who wants the day board ON the overview as well
 * is not wrong; a tab is where the module leads, not a fence.
 *
 * THE FIVE DIRECTIONS ARE PRESETS, NOT PAGES — the same grammar patrols and
 * incidents use. Each is a headed section of the library AND a preset that
 * composes it, so somebody adopts a direction, copies it, and mixes a widget
 * from another into their copy.
 *
 * A CATALOGUE IS A STATEMENT OF WHAT A SURFACE SHIPS, so this class has no
 * dependencies and nothing may vary it at runtime. It is nonetheless tagged
 * {@see WidgetSurfaceInterface} in the bundle's loadExtension, because being
 * FINDABLE is the half a catalogue alone cannot do: `widget:prune` walks the
 * registry, and a surface no service claims has its stored layouts read as
 * orphans and deleted.
 */
final class RosterWidgets implements WidgetSurfaceInterface
{
    /** What a stored preference row is keyed by. */
    public const string SURFACE = 'roster';

    /** What the composition this module ships with is CALLED when it leads the preset strip. */
    public const string DEFAULT_LABEL = 'The roster dashboard';

    public const string DEFAULT_DESCRIPTION = 'What the module ships with: the day\'s figures, then what needs a decision, then the posts and who the pings put on them, then the plate. The direction-neutral screen — adopt one of the five below to lead with something sharper.';

    public function catalog(): WidgetCatalog
    {
        return self::declaration();
    }

    /** The catalogue, reachable without an instance — see the class docblock. */
    public static function declaration(): WidgetCatalog
    {
        $groups = [];
        $presets = [];
        foreach (self::directions() as $letter => [$label, $tradeOff, $layout]) {
            $groups[] = new WidgetGroup($letter, $label, $tradeOff);
            // The preset id IS the direction's letter, exactly as the design
            // declares it, and the trade-off line is written ONCE — so a
            // headed section and the preset that adopts it can never disagree
            // about what the same design costs.
            $presets[] = new WidgetPreset($letter, $label, $tradeOff, $layout);
        }

        return new WidgetCatalog(
            self::SURFACE,
            $groups,
            self::widgets(),
            $presets,
            // A person who has never chosen opens on the shipped composition,
            // not on the first direction: picking one of the five for somebody
            // is the choice the library exists to let them make.
            WidgetCatalog::DEFAULT_PRESET_ID,
            self::DEFAULT_LABEL,
            self::DEFAULT_DESCRIPTION,
        );
    }

    /**
     * THE SURFACE'S WIDGETS, in the order the shipped composition lays them
     * out. `cols` is the width the catalogue draws it at, the spans are the
     * widths the width-chips offer (widest first), and `on` is whether the
     * SHIPPED composition includes it. A widget's SECTION in the library
     * comes from its group, never from its place here.
     *
     * @return list<Widget>
     */
    private static function widgets(): array
    {
        return [
            new Widget('kpis', 'The day\'s check-ins', 'a', 12, [12, 9, 6], on: true, note: 'Check-ins in, verified at a post, flagged, missing, and the posts still reporting.'),
            new Widget('decisions', 'Needs a decision', 'b', 12, [12, 9], on: true, note: 'Every ranger and every watch that needs somebody to act: a missing check-in, a claim the device disagrees with, a hole.'),
            new Widget('stations', 'Stations & who is actually on', 'b', 12, [12, 9, 6], on: true, note: 'One banded row per post that runs a watch: who was rostered, who the pings put there, and the way into the area\'s record.'),
            // WHO IS REPORTING, NOT WHERE (ruled 2026-09-26): the area's marks
            // are the area overview's and the Live tab's to draw, so the
            // shipped composition carries this list and its door to the plate.
            new Widget('live', 'Reporting right now', 'e', 12, [12, 6], on: true, note: 'Who is on a watch this minute, with the age of their last ping, and the way to the live plate.'),
            // ---- in the library, off the shipped composition ----
            new Widget('day', 'The day board', 'a', 12, [12, 9], on: false, note: 'Twenty-four hours across, one station per row, a block per watch and a line at now.'),
            new Widget('handover', 'Next shift change', 'a', 6, [12, 6], on: false, note: 'Who goes off at the shift change, who comes on, and what the outgoing watch has to hand over.'),
            new Widget('offline', 'Silent & offline', 'b', 6, [12, 6], on: false, note: 'Every station that is late, silent or offline, with how long it has been that way.'),
            new Widget('registry', 'What each station\'s watch expects', 'b', 12, [12, 9], on: false, note: 'The roster\'s own columns — expected shifts, catchment radius and silence window. Name, kind, position and call sign are the area\'s.'),
            new Widget('week', 'The week plan', 'c', 12, [12, 9], on: false, note: 'Seven days across, stations down, one cell per watch; unfilled cells are drawn as holes.'),
            new Widget('gaps', 'Unfilled shifts', 'c', 6, [12, 6], on: false, note: 'Every watch in the next seven days that nobody is on, soonest first.'),
            new Widget('pattern', 'The standing pattern', 'c', 6, [12, 6], on: false, note: 'The recurring watch each station expects, and how far ahead the plan has been generated. Edited in the rotation editor, never here.'),
            new Widget('people', 'People on the roster', 'd', 12, [12, 9], on: false, note: 'Department, posting, today\'s shift and whether they are out on a patrol — the watches standing now first.'),
            new Widget('away', 'Away', 'd', 6, [12, 6], on: false, note: 'Leave, rest days and courses — who is not available, and until when.'),
            new Widget('load', 'Nights and hours', 'd', 6, [12, 6], on: false, note: 'Shifts and nights per person this month — who is carrying the rota.'),
            new Widget('checkins', 'The check-in feed', 'e', 12, [12, 9], on: false, note: 'Every check-in of the day newest first, with its status, its post and whether the pings bear it out.'),
        ];
    }

    /**
     * THE FIVE DIRECTIONS: the letter the library files each under, what it
     * is called, what the library says it COSTS, and the layout that IS that
     * design — listed is on, at the width listed, in that order; absent is
     * off.
     *
     * The trade-off line is the design's own sentence, verbatim, written once
     * and read twice — by the headed section and by the preset — so the
     * product can never say something about a direction the design did not.
     *
     * @return array<string, array{string, string, array<string, int>}>
     */
    private static function directions(): array
    {
        return [
            'a' => [
                'The day board',
                'Today as a wall: twenty-four hours across, one station per row, a block for every watch and a line where "now" is. The best direction for a shift handover and for a screen on an office wall; it knows nothing about tomorrow, and a person\'s own week is invisible in it.',
                ['kpis' => 12, 'day' => 12, 'handover' => 6, 'live' => 6],
            ],
            'b' => [
                'Station first',
                'The area is its posts. One card per station that runs a watch — who is actually on it against who was rostered, and whether it is still talking to us — over the roster\'s own columns: what watch it expects, how wide its catchment is, how long its silence may run. The only direction in which an offline station cannot be missed; the weakest at "who is working too many nights".',
                ['kpis' => 12, 'stations' => 6, 'offline' => 6, 'live' => 12, 'registry' => 12],
            ],
            'c' => [
                'The week planner',
                'Seven days across, stations down, one cell per watch, and every hole drawn as a hole. The only direction you can plan in rather than read; today is one column of seven, so the question "who is on right now" takes a second look.',
                ['kpis' => 12, 'week' => 12, 'gaps' => 6, 'pattern' => 6],
            ],
            'd' => [
                'The people',
                'The rota as the people in it: who is posted where, who is away, and who is carrying the nights. The direction that answers "is this fair" and the one a complaint is settled from; it says very little about any single day.',
                ['people' => 12, 'away' => 6, 'load' => 6],
            ],
            'e' => [
                'Right now',
                'This minute only: who is reporting, and every check-in of the day newest first. The direction to leave open beside a radio; anything that has not happened yet is not in it.',
                ['kpis' => 12, 'live' => 6, 'offline' => 6, 'checkins' => 12],
            ],
        ];
    }
}
