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

namespace Uhifadhi\Roster\Tests\Unit\Shell;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Uhifadhi\Roster\Shell\RosterStylesheets;
use Uhifadhi\Roster\UhifadhiRosterBundle;

/**
 * THE SHEETS THE ROSTER'S COMPONENTS NEED ON PAGES THE ROSTER DOES NOT OWN:
 * the My roster card on the core's dashboard (me.css) and the blocks it
 * contributes into a station's configure card and record (station.css). A
 * rule for those blocks that lived only in roster.css would reach no page
 * but the roster's own.
 */
final class RosterStylesheetsTest extends TestCase
{
    public function testTheHeadIsAskedForTheDashboardSheetAndTheStationSheet(): void
    {
        self::assertSame(
            [UhifadhiRosterBundle::ME_STYLESHEET, UhifadhiRosterBundle::STATION_STYLESHEET],
            new RosterStylesheets()->stylesheets(),
        );
        self::assertSame('bundles/uhifadhiroster/station.css', UhifadhiRosterBundle::STATION_STYLESHEET);
        self::assertFileExists(self::publicDir().'/station.css');
    }

    /**
     * Every class the station blocks draw in.
     *
     * @return iterable<string, array{string}>
     */
    public static function stationVocabulary(): iterable
    {
        foreach (['.rset', '.rset > .lb', '.rset > .ct', '.rset > .why', '.rset .unit', '.r-sh', '.r-sh.on', '.r-ci', '.r-st-state', '.rfrag', 'table.tbl.ro-tbl'] as $selector) {
            yield $selector => [$selector];
        }
    }

    /** DEFINED ONCE, IN THE SHEET EVERY HEAD LINKS, and nowhere else. */
    #[DataProvider('stationVocabulary')]
    public function testEveryStationBlockRuleLivesInTheStationSheetOnly(string $selector): void
    {
        $rule = '/(^|\n)'.preg_quote($selector, '/').'\s*\{/';

        self::assertMatchesRegularExpression($rule, self::sheet('station.css'), $selector.' is defined in station.css.');
        self::assertDoesNotMatchRegularExpression($rule, self::sheet('roster.css'), $selector.' is not defined a second time in roster.css.');
    }

    private static function sheet(string $name): string
    {
        $css = @file_get_contents(self::publicDir().'/'.$name);

        return \is_string($css) ? $css : '';
    }

    private static function publicDir(): string
    {
        return \dirname(__DIR__, 3).'/public';
    }
}
