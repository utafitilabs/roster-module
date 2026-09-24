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

namespace Uhifadhi\Roster\Tests\Unit\Widget;

use PHPUnit\Framework\TestCase;
use Uhifadhi\Roster\Widget\RosterOrgWidgets;
use Uhifadhi\Roster\Widget\RosterRailWidgets;
use Uhifadhi\Roster\Widget\RosterWidgets;

/**
 * THE ORGANIZATION SURFACE, AS THE DESIGN DECLARES IT.
 *
 * IT IS A THIRD SURFACE AND NOT A PRESET OF THE FIRST. The Overview composes
 * one area's page, the rail composes a column beside a plate, and this one
 * composes every area at once. They share the mechanism — the same catalogue
 * shape, the same resolution, the same store — and nothing else, which is
 * what a surface is for.
 *
 * WHAT IS ASSERTED IS THE DECLARATION, because that is the part a person
 * chose. The figures under it are the area service with the filter widened,
 * and they are tested where they are computed.
 */
final class RosterOrgWidgetsTest extends TestCase
{
    public function testItCarriesTheSixWidgetsTheDesignDeclares(): void
    {
        $catalog = RosterOrgWidgets::declaration();

        self::assertSame(['kpis', 'decisions', 'areas', 'map', 'load', 'gaps'], $catalog->ids());
    }

    /**
     * FOUR ON, TWO OFF. The two that are off are real questions whose answer
     * is a chart or a second list, and a dashboard that opened with six
     * cards would bury the four that answer "which area is the problem".
     */
    public function testItOpensOnTheFourThatAnswerWhichAreaIsTheProblem(): void
    {
        $catalog = RosterOrgWidgets::declaration();

        $on = [];
        foreach ($catalog->ids() as $id) {
            if ($catalog->get($id)->on) {
                $on[] = $id;
            }
        }

        self::assertSame(['kpis', 'decisions', 'areas', 'map'], $on);
    }

    /** ONE ARRANGEMENT, and it is the one the surface ships on. */
    public function testItShipsOneArrangementAndOpensOnIt(): void
    {
        $catalog = RosterOrgWidgets::declaration();

        self::assertCount(1, $catalog->presets());
        self::assertSame('a', $catalog->presets()[0]->id);
        self::assertSame(RosterOrgWidgets::DEFAULT_LABEL, $catalog->presets()[0]->label);
        self::assertSame(
            ['kpis' => 12, 'decisions' => 12, 'areas' => 12, 'map' => 12],
            $catalog->presets()[0]->layout,
            'The shipped arrangement is the four that are on, each full width.',
        );
    }

    /**
     * AND IT IS ITS OWN SURFACE, so an arrangement adopted here is not one
     * adopted on the area dashboard or in the rail. Three surfaces, three
     * stored preferences, one module.
     */
    public function testItIsKeyedApartFromThisModulesOtherSurfaces(): void
    {
        self::assertSame('roster-org', RosterOrgWidgets::SURFACE);

        self::assertCount(3, array_unique([
            RosterWidgets::SURFACE,
            RosterRailWidgets::SURFACE,
            RosterOrgWidgets::SURFACE,
        ]));
    }

    /**
     * THE MODULE NAMES NO COLOUR, here as everywhere. An area's swatch is
     * its POSITION in the declared order; the shell turns `data-cat="n"`
     * into a value. A surface that reached for a hue would be inventing an
     * identity for somebody else's area.
     */
    public function testItDeclaresNoColour(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../../src/Widget/RosterOrgWidgets.php');

        self::assertDoesNotMatchRegularExpression('/#[0-9A-Fa-f]{3,8}\b|\brgba?\(|\bhsla?\(/', $source);
    }
}
