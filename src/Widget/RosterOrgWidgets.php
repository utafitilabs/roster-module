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
 * THE ROSTER AT ORGANIZATION SCOPE — the module's third surface.
 *
 * IT IS THE AREA PAGE ONE SCOPE WIDER, and that is the whole design. Every
 * figure on it is the area query with the area filter WIDENED — the same
 * service the area dashboard calls, given every area instead of one — and
 * never a second aggregate written beside it. Two code paths would drift,
 * and the day they disagreed nobody would know which was right.
 *
 * WHICH AREA IS A COLUMN, NOT A PAGE. The one thing this scope adds is that
 * every row has to say which area it is about; the swatch beside the name is
 * the area's position in the declared order, which the house turns into a
 * colour through `data-cat`. No area owns a hue, and this module names none.
 *
 * THE SCOPE CONTROL IS THE SHELL'S. A module states no scope control of its
 * own and no module sheet restates one: the selected scope arrives as one
 * argument — every area, or one of them.
 *
 * ONLY THREE TABS. Overview, Today and Live are drawn; the week plan, the day
 * board and the calendar are read one area at a time and are deliberately not
 * here. A tab whose body is a note is a placeholder, and the product ships
 * none.
 */
final class RosterOrgWidgets implements WidgetSurfaceInterface
{
    /** What a stored preference row is keyed by. */
    public const string SURFACE = 'roster-org';

    /** What the arrangement this module ships with is CALLED. */
    public const string DEFAULT_LABEL = 'Every area at once';

    public const string DEFAULT_DESCRIPTION = 'The organization’s day on one screen: the figures, the questions, a band per area and the plate. The only direction that answers "which area is the problem" without picking one first; a single area’s detail is one click away and deliberately not here.';

    /** The one heading the library files these widgets under. */
    public const string GROUP = 'a';

    public function catalog(): WidgetCatalog
    {
        return self::declaration();
    }

    /** The catalogue, reachable without an instance. */
    public static function declaration(): WidgetCatalog
    {
        return new WidgetCatalog(
            self::SURFACE,
            [new WidgetGroup(
                self::GROUP,
                'Every area at once',
                'The organization’s day: the four figures, what needs an answer in any area, one band per area, and every live position on one plate.',
            )],
            [
                new Widget('kpis', 'The day’s check-ins, org-wide', self::GROUP, 12, [12], on: true, note: 'Four figures, every area counted: due, verified at a post, needing an answer, and the posts still reporting.'),
                new Widget('decisions', 'Needs a decision, everywhere', self::GROUP, 12, [12, 9], on: true, note: 'Every ranger and every watch that needs somebody to act, in any area, loudest first. The area is named on the row.'),
                new Widget('areas', 'One band per area', self::GROUP, 12, [12, 9], on: true, note: 'Rangers, posts reporting, check-ins in and questions open — one row per area, with the way into that area’s own roster.'),
                new Widget('map', 'Every area, live', self::GROUP, 12, [12], on: true, note: 'All four boundaries on one plate with every live position on it. The full version is the Live tab.'),
                new Widget('load', 'Who is carrying the nights', self::GROUP, 6, [12, 6], on: false, note: 'Nights stood per person over the last four weeks, org-wide — the question an area page cannot answer.'),
                new Widget('gaps', 'Unfilled watches, next seven days', self::GROUP, 6, [12, 6], on: false, note: 'Every watch nobody is on in any area, soonest first.'),
            ],
            [
                new WidgetPreset(
                    'a',
                    self::DEFAULT_LABEL,
                    self::DEFAULT_DESCRIPTION,
                    ['kpis' => 12, 'decisions' => 12, 'areas' => 12, 'map' => 12],
                ),
            ],
            defaultPreset: 'a',
        );
    }
}
