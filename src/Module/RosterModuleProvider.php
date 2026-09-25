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

namespace Uhifadhi\Roster\Module;

use Uhifadhi\Contracts\ModuleProviderInterface;
use Uhifadhi\Contracts\ModuleProviderTrait;
use Uhifadhi\Contracts\Shell\OrgPage;
use Uhifadhi\Contracts\Shell\OrgPagesInterface;
use Uhifadhi\Roster\Controller\RosterController;
use Uhifadhi\Roster\Controller\RosterOrgController;

/**
 * DECLARES THE ONE MODULE THIS BUNDLE CONTRIBUTES — "Roster": who is due on
 * watch in an area, where and when.
 *
 * THE SLUG IS SINGULAR, AND IT IS LOAD-BEARING. `roster` is the word in the
 * url every screen lives under (/areas/{uuid}/modules/roster), the key the
 * per-area ledger switches this module on by, and the slug every contribution
 * this module makes to the area overview has to repeat — a contribution whose
 * slug disagrees is a contribution that never disappears when an area switches
 * the module off. The pre-ruling scaffold said `rosters`; the ruling says
 * `roster`, and the catalogue row it replaces was called "Operations", a name
 * that collided with the operational-modules tier and is retired.
 *
 * WHAT IT OWNS, AND THE LINE IT DOES NOT CROSS. The rotation, the duties it
 * generates, the swap, the absence, and the watch a station expects. NOT the
 * station, NOT the posting, NOT the check-in, and NOT a position — those are
 * the AREA's, and presence is READ from the area's seam rather than computed
 * here. A roster says who is due at a post tonight; it never says who is
 * there.
 */
final class RosterModuleProvider implements ModuleProviderInterface, OrgPagesInterface
{
    // The defaults for status, pinned, base and position.
    use ModuleProviderTrait;

    /**
     * The one spelling of the module's identity, imported by every
     * contribution this module makes so no two can disagree about which
     * module they belong to.
     */
    public const string SLUG = 'roster';

    public function __construct(
        private readonly string $category,
    ) {
    }

    public function slug(): string
    {
        return self::SLUG;
    }

    /** The one sentence the catalogue prints under the name — what the module is, for a stranger. */
    public function description(): string
    {
        return 'Who is on duty, where, and who checked in.';
    }

    public function name(): string
    {
        return 'Roster';
    }

    public function category(): string
    {
        return $this->category;
    }

    public function dataSource(): string
    {
        return 'Rotations, duties and the area\'s check-ins';
    }

    /**
     * THE MARK, UNDER THIS MODULE'S OWN NAMESPACE.
     *
     * A MODULE SHIPS THE MARKS IT ASKS FOR. A bare name resolves in the
     * host's default icon set, so a module naming one is a module betting
     * that every installation happens to ship that glyph — and the bet
     * fails silently until something renders the name in a place that has
     * no such icon. `roster:` is this bundle's own directory, which
     * travels with it.
     */
    public function icon(): string
    {
        return 'roster:calendar-clock';
    }

    /**
     * THE MODULE OWNS ITS PAGES, so the tile links straight to the Overview
     * tab rather than through the platform's generic module page.
     */
    public function entryRoute(): string
    {
        return RosterController::OVERVIEW_ROUTE;
    }

    /*
     * IT DECLARES NOTHING TO TICK HERE, and that is where the declaration
     * lives rather than an omission. What there is to have a permission
     * about in this module is declared through the access seam - one
     * source, one concern, and the two verbs this module actually enforces:
     * {@see \Uhifadhi\Roster\Access\RosterConcerns}.
     */

    /**
     * THE ROSTER READ ACROSS EVERY AREA AT ONCE — the module's own screens
     * one scope wider, which the SHELL mounts: a row in Observatory, these
     * as its tabs, and the scope control in the action row. This module
     * writes none of those three.
     *
     * THREE TABS AND NOT SIX. Overview, Today and Live answer a question
     * that only exists across areas — which area is the problem, who needs
     * an answer anywhere, where everybody is. The week plan, the day board
     * and the calendar are read one area at a time and are deliberately
     * absent: a tab whose body would be a note is a placeholder, and the
     * product ships none.
     *
     * ROUTE NAMES, NEVER PATHS. The application mounts them; a screen whose
     * route an installation has not mounted is left out rather than drawn
     * as a link to a 404.
     */
    public function orgPages(): array
    {
        return [
            new OrgPage('overview', 'Overview', RosterOrgController::OVERVIEW_ROUTE),
            new OrgPage('today', 'Today', RosterOrgController::TODAY_ROUTE),
            new OrgPage('live', 'Live', RosterOrgController::LIVE_ROUTE),
        ];
    }
}
