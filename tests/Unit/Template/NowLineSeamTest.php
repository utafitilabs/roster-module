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

use PHPUnit\Framework\TestCase;
use Uhifadhi\Roster\UhifadhiRosterBundle;

/**
 * THE NOW LINE'S SEAM — the names that cross from Twig into JavaScript, and
 * the one number that has to mean the same thing on both sides.
 *
 * THIS IS THE TEST SHAPE THE FLEET USES WHERE THERE IS NO JS RUNNER. Nothing
 * that talks HTTP can catch a controller that reads `data-x` while the
 * template writes `data-y`: every server-side test passes and only a browser
 * ever sees the dead element. So the assertion is made on the FILES, as
 * text, and it fails where somebody would be editing.
 *
 * THE MATHS IS ASSERTED TOO, and that is the load-bearing half. The blocks
 * on the wall are placed by the SERVER as minutes-since-midnight over the
 * day's minutes; the line over them is placed by the BROWSER. If the two
 * formulae ever differ the line lands between the wrong hours, and every
 * other test in this suite would still be green.
 */
final class NowLineSeamTest extends TestCase
{
    private const string CONTROLLER = __DIR__.'/../../../assets/controllers/now_line_controller.js';

    private const string WALL = __DIR__.'/../../../templates/board/_wall.html.twig';

    private const string BOARD_SERVICE = __DIR__.'/../../../src/Service/DayBoardService.php';

    private const string PACKAGE = __DIR__.'/../../../assets/package.json';

    private const string CSS = __DIR__.'/../../../public/roster.css';

    private static function read(string $path): string
    {
        $contents = file_get_contents($path);
        self::assertIsString($contents, $path.' must be readable.');

        return $contents;
    }

    /**
     * THE CONTROLLER NAME IS THE SAME STRING in the template and in the
     * manifest that registers it. A name that agrees with neither is a
     * `data-controller` attribute Stimulus never matches — markup that looks
     * perfect and does nothing.
     */
    public function testTheTemplateNamesTheControllerTheManifestRegisters(): void
    {
        $wall = self::read(self::WALL);
        $package = self::read(self::PACKAGE);

        $name = self::manifestName($package);

        self::assertSame('roster--now-line', $name, 'The manifest registers the controller under this name.');
        self::assertStringContainsString('data-controller="'.$name.'"', $wall, 'And the wall writes exactly that name.');
        self::assertStringContainsString('data-'.$name.'-day-value=', $wall, 'The day value is spelled from the same name.');
    }

    /**
     * THE VALUE THE CONTROLLER READS IS THE VALUE THE TEMPLATE WRITES.
     * Stimulus resolves `dayValue` from `data-<name>-day-value`, so the
     * declaration and the attribute are two spellings of one contract.
     */
    public function testTheControllerReadsTheValueTheWallWrites(): void
    {
        $controller = self::read(self::CONTROLLER);

        self::assertStringContainsString('day: String', $controller, 'It declares the value…');
        self::assertStringContainsString('this.dayValue', $controller, '…and reads it.');
        self::assertStringContainsString('data-roster--now-line-day-value=', self::read(self::WALL));
    }

    /**
     * THE TWO PERCENTAGES AGREE. The server divides minutes-since-midnight
     * by the minutes in a day to place a block; the browser must do the same
     * to place the line over it.
     */
    public function testTheBrowsersPercentOfADayIsTheServersPercentOfADay(): void
    {
        $service = self::read(self::BOARD_SERVICE);
        $controller = self::read(self::CONTROLLER);

        self::assertStringContainsString('private const int MINUTES_IN_A_DAY = 24 * 60;', $service);
        self::assertStringContainsString('$minutes / self::MINUTES_IN_A_DAY * 100', $service);

        self::assertStringContainsString('(at.getHours() * 60 + at.getMinutes()) / (24 * 60)) * 100', $controller);
    }

    /**
     * THE SERVER NO LONGER PLACES THE LINE. The defect was a percent
     * rendered into the markup: it was the server's zone, it was decided
     * against the server's idea of today, and it never moved again. This
     * fails if a `left:` style creeps back onto the element.
     */
    public function testTheServerRendersNoPositionForTheLine(): void
    {
        $wall = self::read(self::WALL);

        self::assertStringNotContainsString('nowPercent', $wall, 'A server-rendered position is the bug this replaced.');
        self::assertStringNotContainsString('class="r-now" style=', $wall);
        self::assertStringNotContainsString('percentOfDay', self::read(self::BOARD_SERVICE), 'And the server no longer computes one.');
    }

    /**
     * IT RE-PLACES ITSELF. A line that is correct once and then frozen is
     * the defect somebody reported on a screen nobody reloads.
     */
    public function testTheLineIsPlacedAgainEveryMinute(): void
    {
        $controller = self::read(self::CONTROLLER);

        self::assertStringContainsString('60000 - (Date.now() % 60000)', $controller, 'It ticks on the minute boundary, not sixty seconds after load.');
        self::assertStringContainsString('setTimeout', $controller);
        self::assertStringContainsString('clearTimeout', $controller, 'And stops when the element goes away.');
    }

    /**
     * THE LINE STAYS AT THE CENTRE AND THE DAY SCROLLS UNDER IT — RULED
     * 25 sep. The line is drawn at now's place on the hours, and the
     * scroller is moved so that place sits in the middle of the window
     * the pinned post names leave; at either end of the day it cannot be
     * centred and is clamped, which is correct.
     */
    public function testTheBoardIsScrolledSoNowSitsAtTheCentreOfTheWindow(): void
    {
        $controller = self::read(self::CONTROLLER);

        self::assertStringContainsString("static targets = ['scroller', 'axis', 'line'];", $controller);
        self::assertStringContainsString('const x = axisStart + axisWidth * percent / 100;', $controller, 'where now is, in the scroller\'s own coordinates');
        self::assertStringContainsString('const centre = axisStart + (viewportWidth - axisStart) / 2;', $controller, 'the middle of the window the post names leave');
        self::assertStringContainsString('return Math.min(Math.max(0, x - centre), Math.max(0, scrollWidth - viewportWidth));', $controller, 'clamped at the start and the end of the day');
        self::assertStringContainsString('this.scrollerTarget.scrollLeft = NowLine.centredScrollLeft(', $controller);
        self::assertStringContainsString('this.lineTarget.style.left = `${percent}%`;', $controller, 'the line is drawn at now on the hours and scrolls with them');
    }

    /**
     * A READER WHO SCROLLS BY HAND IS NOT FOUGHT. Only a change the
     * controller did not make counts; the board stays there until the
     * viewer's day changes, and a resize, which changes the geometry the
     * reader chose in, re-centres.
     */
    public function testAScrollByHandIsLeftAloneUntilTheDayChanges(): void
    {
        $controller = self::read(self::CONTROLLER);
        $wall = self::read(self::WALL);

        self::assertStringContainsString('scroll->roster--now-line#scrolled', $wall);
        self::assertMatchesRegularExpression('/scrolled\(\) \{.*?Math\.abs\(left - this\.left\) > 1.*?this\.userScrolled = true;/s', $controller, 'a horizontal move the controller did not make');
        self::assertMatchesRegularExpression('/tick\(\) \{.*?this\.place\(\{ follow: true \}\)/s', $controller, 'every minute re-places the line and follows now');
        self::assertMatchesRegularExpression('/if \(follow && !this\.userScrolled\) \{\s*this\.centre\(percent\);/', $controller, 'unless the reader has taken the board');
        self::assertMatchesRegularExpression('/if \(today !== this\.today\) \{\s*this\.today = today;\s*this\.userScrolled = false;/', $controller, 'a new day hands the board back to the clock');
        self::assertStringContainsString('new ResizeObserver(', $controller);
        self::assertMatchesRegularExpression('/resized\(\) \{.*?this\.userScrolled = false;.*?this\.place\(\{ follow: true \}\)/s', $controller, 'a resize re-centres');
        self::assertStringContainsString('this.observer?.disconnect()', $controller);
    }

    /**
     * THE HOURS ARE WIDER THAN THE WINDOW. Twenty-four hours over a window
     * of `--r-day-hours`, and the post names and the hour scale pinned so
     * the reader always knows which row and which hour.
     */
    public function testTheHoursAreWiderThanTheWindowAndTheLineRunsAlongThem(): void
    {
        $css = self::read(self::CSS);

        self::assertMatchesRegularExpression('/\.r-dayscroll \{[^}]*overflow-x: auto;/s', $css);
        self::assertStringContainsString('width: calc(var(--r-axis-at) + (100% - var(--r-axis-at)) * 24 / var(--r-day-hours));', $css);
        self::assertMatchesRegularExpression('/\.r-nowlayer \{[^}]*position: absolute;[^}]*left: var\(--r-axis-at\);[^}]*right: 0;/s', $css);
        self::assertDoesNotMatchRegularExpression('/\.r-now \{[^}]*margin-left/s', $css, 'the line is placed on the hours, not offset across the labels');
    }

    /** The asset namespace the manifest is keyed by is the bundle's own. */
    public function testTheAssetNamespaceIsTheBundles(): void
    {
        $manifest = json_decode(self::read(self::PACKAGE), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);
        self::assertArrayHasKey('name', $manifest);
        self::assertIsString($manifest['name']);

        self::assertSame(UhifadhiRosterBundle::ASSET_NAMESPACE, $manifest['name']);
    }

    /** The registered name of the now-line controller, read as a string. */
    private static function manifestName(string $package): string
    {
        $manifest = json_decode($package, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);

        $symfony = $manifest['symfony'] ?? null;
        self::assertIsArray($symfony);
        $controllers = $symfony['controllers'] ?? null;
        self::assertIsArray($controllers);
        $nowLine = $controllers['now-line'] ?? null;
        self::assertIsArray($nowLine);
        $name = $nowLine['name'] ?? null;
        self::assertIsString($name);

        return $name;
    }
}
