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

namespace Uhifadhi\Roster\Tests\Unit\Template;

use Uhifadhi\Bundle\ShellBundle\Test\VocabularyConformanceTestCase;

/**
 * THE HOUSE'S OWN CONFORMANCE RULES, run against this module.
 *
 * THE SHELL SHIPS THESE, so every module is measured by one set and a rule
 * the house learns reaches all of them at once. This module had grown its
 * own versions of two of them; the house's are stricter and are not mine to
 * keep in step.
 */
final class RosterVocabularyConformanceTest extends VocabularyConformanceTestCase
{
    protected static function bundlePath(): string
    {
        return \dirname(__DIR__, 3);
    }

    protected static function alias(): string
    {
        return 'roster';
    }

    protected static function ownStylesheets(): array
    {
        return ['roster.css'];
    }

    /**
     * AND THE WIDGET GRID'S SHEET, which this module's bases link because
     * three of its surfaces are COMPOSED — without it nothing declares a
     * span and every widget draws one column wide. It is the shell's and
     * shipped separately, because only a page composing a surface needs
     * it, so a module that links it has to say so here.
     */
    protected static function linkedStylesheets(): array
    {
        return [
            ...parent::linkedStylesheets(),
            \dirname((new \ReflectionClass(\Uhifadhi\Bundle\ShellBundle\ShellBundle::class))->getFileName() ?: '').'/public/widget.css',
            /*
             * AND THE AREA'S OWN SHEET, because one of this module's
             * templates is not drawn on one of this module's pages: the
             * organization dashboard's watches cell is rendered by the
             * CORE, on a page that links the area vocabulary before any
             * module's. The contributor tag `.ao-by` and the honest-absent
             * paragraph are that vocabulary, and a cell that restated them
             * would be the drift this whole test exists to stop.
             */
            \dirname((new \ReflectionClass(\Uhifadhi\Bundle\AreaBundle\AreaBundle::class))->getFileName() ?: '').'/public/area.css',
        ];
    }

    /**
     * EVERY REPEATED ROW FAMILY CANCELS ITS RULE ON THE LAST ROW — AND ON
     * THE LAST ROW BEFORE THE CARD'S FOOT, WHICH IS NOT THE SAME THING.
     *
     * THE SHELL DOES NOT SHIP THIS CHECK and it is worth the module keeping
     * it. A list whose final row is underlined reads as a list that was CUT
     * OFF rather than one that ended, and `:last-of-type` alone does not
     * prevent it: the `.staddrow` at the end of a card body is a `div` too,
     * so the last row is never the last div of its parent and the hairline
     * kept being drawn straight into the foot's own. Measured on the
     * rendered Watches section, 21 sep.
     *
     * BOTH HALVES ARE ASSERTED, because either alone is a family that draws
     * one rule too many in one of the two shapes a card comes in.
     *
     * @return iterable<string, array{string}>
     */
    public static function rowFamilies(): iterable
    {
        yield 'a shift row' => ['.wsh'];
        yield 'a rule row' => ['.rl'];
        yield 'an area setting' => ['.rset'];
        yield 'a check-in status' => ['.rstat'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rowFamilies')]
    public function testEveryRepeatedRowFamilyCancelsItsRuleOnTheLastRow(string $family): void
    {
        $sheet = self::theOwnSheet();

        self::assertStringContainsString($family.' {', $sheet, $family.' draws a rule between its rows.');
        self::assertMatchesRegularExpression(
            '/'.preg_quote($family, '/').':last-of-type,\s*\n\s*'.preg_quote($family, '/').':has\(\+ \.staddrow\)\s*\{[^}]*border-bottom:\s*0/',
            $sheet,
            $family.' has to cancel it on the last row AND on the last row before a card foot.',
        );
    }

    /**
     * AND THE TABLE'S LAST ROW, which needs no `:has()` — a table's foot is
     * outside the table, so its last `<tr>` really is the last child.
     */
    public function testTheStationTableDrawsNoRuleUnderItsLastRow(): void
    {
        self::assertMatchesRegularExpression(
            '/table\.wmx tr:last-child td\s*\{[^}]*border-bottom:\s*0/',
            self::theOwnSheet(),
        );
    }

    /**
     * AND THE SHEET'S OWN LAST ROW. The sheet is thirty-four dashed rows
     * and the thirty-fourth is the one that would read as cut off — which
     * on a bounded scroller is exactly the wrong thing to say, because the
     * reader cannot tell a rule from the edge of the scrollport.
     */
    public function testTheSheetDrawsNoRuleUnderItsLastRow(): void
    {
        self::assertMatchesRegularExpression(
            // The sheet's table IS `.fg-rota csheet`, and the design draws
            // the dashed rule — and cancels it — on the family, once.
            '/\.fg-rota tr:last-child td\s*\{[^}]*border-bottom:\s*0/',
            self::theOwnSheet(),
        );
    }

    private static function theOwnSheet(): string
    {
        $sheet = file_get_contents(self::bundlePath().'/public/roster.css');
        self::assertIsString($sheet);

        return $sheet;
    }

    /**
     * THE MONO FACE IS THE SHELL'S, NAMED ONCE.
     *
     * Owner, 21 sep: the sheet's labels did not look like the design's, and
     * the cause was not the font loading — this sheet carried its own stack
     * starting at `ui-monospace`, while the shell defines `--font-mono:
     * "JetBrains Mono", ui-monospace, Menlo, monospace`. A module-local
     * stack renders the shell's own face on every OTHER surface and the
     * system face here, which is the same defect as restating a shared
     * class: two copies, one of them wrong, and nobody looking at the CSS
     * can tell which. Shipping the face is the shell's chore; naming it is
     * everybody's.
     */
    public function testNoModuleLocalFontStack(): void
    {
        foreach (static::ownStylesheets() as $sheet) {
            $css = file_get_contents(\dirname(__DIR__, 3).'/public/'.$sheet);
            self::assertIsString($css);

            foreach (['ui-monospace', 'JetBrains Mono', 'system-ui', '-apple-system'] as $face) {
                self::assertStringNotContainsString(
                    $face,
                    $css,
                    \sprintf('%s names a font stack of its own; use the shell\'s var(--font-mono) / var(--font-sans).', basename($sheet)),
                );
            }
        }
    }
}
