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

namespace Uhifadhi\Roster\Tests\Unit\Module;

use PHPUnit\Framework\TestCase;
use Uhifadhi\Contracts\Access\Grant;
use Uhifadhi\Contracts\ModuleProviderInterface;
use Uhifadhi\Roster\Access\RosterConcerns;
use Uhifadhi\Roster\Controller\RosterConfigureController;
use Uhifadhi\Roster\Controller\RosterController;
use Uhifadhi\Roster\Module\RosterModuleProvider;

final class RosterModuleProviderTest extends TestCase
{
    public function testDeclaresTheRosterModule(): void
    {
        $provider = new RosterModuleProvider('operations');

        self::assertInstanceOf(ModuleProviderInterface::class, $provider);
        self::assertSame('Roster', $provider->name());
        self::assertSame('operations', $provider->category());
        self::assertSame('Rotations, duties and the area\'s check-ins', $provider->dataSource());
        self::assertSame('roster:calendar-clock', $provider->icon());
    }

    /**
     * THE SLUG IS SINGULAR. It is the word in every url this module serves,
     * the key the per-area ledger switches it on by, and the string every
     * contribution to the area overview has to repeat. The pre-ruling scaffold
     * said "rosters"; changing it back would silently orphan every one of
     * those, so it is asserted against the constant AND against the literal.
     */
    public function testTheSlugIsRoster(): void
    {
        self::assertSame('roster', RosterModuleProvider::SLUG);
        self::assertSame('roster', new RosterModuleProvider('operations')->slug());
    }

    public function testCategoryIsDeploymentConfigured(): void
    {
        self::assertSame('pressure', new RosterModuleProvider('pressure')->category());
    }

    /**
     * THE TILE LINKS STRAIGHT TO THE OVERVIEW TAB. Until this commit the
     * module rendered through the platform's generic module page; the route
     * and this assertion landed together, because a tile pointing at a route
     * that does not exist is a catalogue full of 404s.
     */
    public function testTheTileLinksStraightToTheOverviewTab(): void
    {
        self::assertSame(RosterController::OVERVIEW_ROUTE, new RosterModuleProvider('operations')->entryRoute());
    }

    /**
     * DECLARED THROUGH THE ACCESS SEAM, AND AGAINST THE EXACT PAIR THE
     * CONTROLLER CHECKS. A gate whose declaration and whose check differ by
     * one character is a screen nobody can open and an admin checkbox that
     * grants nothing, and neither failure says so anywhere.
     */
    public function testTheConcernItDeclaresCarriesTheTwoPairsItsWritesCheck(): void
    {
        $pairs = [];
        foreach (new RosterConcerns()->concerns() as $concern) {
            foreach ($concern->verbs() as $verb) {
                $pairs[] = (string) Grant::of($concern->key(), $verb);
            }
        }

        // TWO, AND THEY ARE DIFFERENT JOBS. Moving one watch between two
        // people on one night is a duty officer's daily work; rewriting an
        // area's rotations is not, and one verb for both would push every
        // shift change up to whoever holds the second.
        self::assertSame([
            RosterController::RECORD,
            RosterConfigureController::CONFIGURE,
        ], $pairs);
    }

    /** The module itself names this module, so a department gate can reach it. */
    public function testEveryConcernNamesThisModule(): void
    {
        foreach (new RosterConcerns()->concerns() as $concern) {
            self::assertSame(RosterModuleProvider::SLUG, $concern->moduleSlug());
        }
    }
}
