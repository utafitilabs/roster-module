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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * THE SHEET'S SEAMS — every name that crosses from Twig into JavaScript,
 * from JavaScript into CSS, and from the template into a route.
 *
 * A FULLY GREEN FUNCTIONAL SUITE PROVES NOTHING ABOUT ANY OF THEM. Nothing
 * that talks HTTP can catch a controller that toggles `.closed` while the
 * sheet is written `.shut`, or a target the template never marks: every
 * server-side test passes and only a browser meets the dead control. So the
 * assertion is made on the FILES, as text, and it fails where somebody would
 * be editing.
 *
 * FOUR CONTROLLERS AND THREE SEAMS EACH — its name, its targets, and the
 * classes it toggles. If a name here has to change it changes in three
 * places at once, which is the point.
 */
final class SheetSeamTest extends TestCase
{
    private const string SHEET = __DIR__.'/../../../assets/controllers/sheet_controller.js';

    private const string FOLDS = __DIR__.'/../../../assets/controllers/sheet_folds_controller.js';

    private const string MENU = __DIR__.'/../../../assets/controllers/day_menu_controller.js';

    private const string FILL = __DIR__.'/../../../assets/controllers/fill_controller.js';

    private const string PAGE = __DIR__.'/../../../templates/week/show.html.twig';

    private const string CELL = __DIR__.'/../../../templates/week/_cell.html.twig';

    private const string PACKAGE = __DIR__.'/../../../assets/package.json';

    private const string SHEET_CSS = __DIR__.'/../../../public/roster.css';

    private const string BOUND = __DIR__.'/../../../assets/controllers/bound_controller.js';

    private const string WALL = __DIR__.'/../../../templates/board/_wall.html.twig';

    /** The other cards bounded by the sheet's rule: the board, the agenda, the live page. */
    private const array BOUNDED = [
        __DIR__.'/../../../templates/board/show.html.twig',
        __DIR__.'/../../../templates/today/show.html.twig',
        __DIR__.'/../../../templates/live/show.html.twig',
    ];

    private static function read(string $path): string
    {
        $contents = file_get_contents($path);
        self::assertIsString($contents, $path.' must be readable.');

        return $contents;
    }

    /**
     * THE CONTROLLER NAME IS ONE STRING IN THREE PLACES — the template that
     * mounts it, the manifest that registers it, and the file it points at.
     * A name that agrees with only two of them is a `data-controller`
     * Stimulus never matches: markup that looks perfect and does nothing.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function controllers(): iterable
    {
        yield 'the bounded sheet' => ['roster--sheet', 'controllers/sheet_controller.js'];
        yield 'the station folds' => ['roster--sheet-folds', 'controllers/sheet_folds_controller.js'];
        yield 'the by-hand menu' => ['roster--day-menu', 'controllers/day_menu_controller.js'];
        yield 'the fill row' => ['roster--fill', 'controllers/fill_controller.js'];
    }

    #[DataProvider('controllers')]
    public function testEachControllerIsNamedTheSameInTheTemplateAndTheManifest(string $name, string $file): void
    {
        $manifest = self::read(self::PACKAGE);
        $mounted = self::read(self::PAGE);

        self::assertStringContainsString('"name": "'.$name.'"', $manifest, 'The manifest must register the name the page mounts.');
        self::assertStringContainsString('"main": "'.$file.'"', $manifest);
        self::assertFileExists(__DIR__.'/../../../assets/'.$file);
        self::assertStringContainsString($name, $mounted, 'The page must mount it.');
    }

    /**
     * EVERY TARGET A CONTROLLER DECLARES IS MARKED IN THE MARKUP, and every
     * one the markup marks is declared. A `hasXTarget` that is always false
     * is a feature that silently does nothing.
     *
     * @return iterable<string, array{string, string, list<string>}>
     */
    public static function targets(): iterable
    {
        yield 'the bounded sheet' => [self::SHEET, self::PAGE, ['scroller', 'today']];
        yield 'the fill row' => [self::FILL, self::PAGE, ['pattern', 'strip']];
    }

    /**
     * @param list<string> $targets
     */
    #[DataProvider('targets')]
    public function testEveryTargetIsBothDeclaredAndMarked(string $controller, string $page, array $targets): void
    {
        $js = self::read($controller);
        $template = self::read($page);

        preg_match('/static targets = \[([^\]]*)\]/', $js, $declared);
        $names = $declared[1] ?? '';
        self::assertNotSame('', $names, 'The controller must declare its targets.');

        foreach ($targets as $target) {
            self::assertStringContainsString("'".$target."'", $names, $target.' is marked in the markup and not declared.');
            self::assertMatchesRegularExpression('/-target="'.$target.'"/', $template, $target.' is declared and never marked.');
        }
    }

    /**
     * EVERY ACTION THE MARKUP CALLS IS A METHOD ON THE CONTROLLER IT NAMES.
     * This is the failure the whole class exists for: `#foldAll` against a
     * `foldEverything()` is a chip that looks right and does nothing.
     */
    public function testEveryActionTheMarkupCallsExists(): void
    {
        $methods = [
            'roster--sheet-folds' => self::read(self::FOLDS),
            'roster--day-menu' => self::read(self::MENU),
            'roster--fill' => self::read(self::FILL),
        ];

        foreach ([self::PAGE, self::CELL] as $page) {
            preg_match_all('/data-action="([^"]+)"/', self::read($page), $found);

            foreach ($found[1] as $action) {
                foreach (explode(' ', $action) as $one) {
                    if (!str_contains($one, '#')) {
                        continue;
                    }

                    [$controller, $method] = explode('#', str_contains($one, '->') ? explode('->', $one)[1] : $one, 2);

                    self::assertArrayHasKey($controller, $methods, $one.' names a controller this page does not mount.');
                    self::assertMatchesRegularExpression(
                        '/\b'.preg_quote($method, '/').'\s*\(/',
                        $methods[$controller],
                        $one.' is called and the controller has no such method.',
                    );
                }
            }
        }
    }

    /**
     * THE FOLD'S VALUES ARE READ ON BOTH SIDES. Stimulus turns
     * `urlValue` into `data-roster--sheet-folds-url-value`, and nothing
     * warns when only one half exists — the preference simply stops being
     * remembered, silently.
     */
    public function testTheFoldControllerReadsTheValuesTheMarkupWrites(): void
    {
        $js = self::read(self::FOLDS);
        $template = self::read(self::PAGE);

        foreach (['url', 'token'] as $value) {
            self::assertStringContainsString($value.': String', $js);
            self::assertStringContainsString('data-roster--sheet-folds-'.$value.'-value=', $template);
        }
    }

    /**
     * THE CLASSES THE CONTROLLERS TOGGLE ARE CLASSES THE SHEET SHIPS. A
     * class toggled by JavaScript and defined by nobody is a control that
     * runs perfectly and changes nothing on the screen.
     */
    public function testEveryClassTheControllersToggleIsShipped(): void
    {
        $css = self::read(self::SHEET_CSS);

        foreach (['shut', 'on'] as $toggled) {
            self::assertStringContainsString('.'.$toggled, $css, '.'.$toggled.' is toggled and shipped by nobody.');
        }

        // The selectors the controllers reach with, in the markup and the
        // sheet alike.
        foreach (['.stfold', '.strow', '.pmenuwrap', '.psheetwrap', '.sheetcard'] as $selector) {
            self::assertStringContainsString($selector, $css, $selector.' is reached for and shipped by nobody.');
            self::assertStringContainsString(
                ltrim($selector, '.'),
                self::read(self::PAGE).self::read(self::CELL),
                $selector.' is styled and never drawn.',
            );
        }
    }

    /**
     * AND THE BOUND THE CARD MEASURES IS THE ONE THE SHEET SPENDS.
     * `--sheetmax` written by the controller and read by nothing would
     * leave the sheet the height of its data — which is the one thing a
     * bounded card may never be.
     */
    public function testTheMeasuredBoundIsTheOneTheSheetSpends(): void
    {
        self::assertStringContainsString("setProperty('--cardmax'", self::read(self::BOUND));
        self::assertStringContainsString('var(--cardmax', self::read(self::SHEET_CSS));
        self::assertStringNotContainsString('--sheetmax', self::read(self::SHEET).self::read(self::SHEET_CSS), 'One bound, one name.');
    }

    /**
     * ONE HEIGHT RULE FOR EVERY BOUNDED CARD — RULED 25 sep. The sheet,
     * the day board, "here now" on the board, the day on the agenda and
     * the roster under the live plate share it: the sheet's shape — what
     * the screen leaves below the card taken at 1.3, capped at one
     * viewport less the card's chrome, floored — and the whole card at
     * 80 % of what the sheet was (670px floor to 536, and 20 % off the
     * measured height).
     */
    public function testEveryBoundedCardSharesTheSheetsRuleAtFourFifths(): void
    {
        $bound = self::read(self::BOUND);

        self::assertStringContainsString('CARD_FLOOR: 536,', $bound, '670 × 0.8');
        self::assertStringContainsString('SCROLLER_FLOOR: 240,', $bound, '300 × 0.8');
        self::assertStringContainsString('GROW: 1.3,', $bound, 'what the screen leaves, taken at the sheet\'s 1.3');
        self::assertStringContainsString('SHARE: 0.8,', $bound, 'and the card at four fifths of it');
        self::assertStringContainsString('Math.min(free * CardBound.GROW, cap)', $bound);
        self::assertStringContainsString('(chrome + grown) * CardBound.SHARE', $bound);
        self::assertStringContainsString('Math.max(CardBound.SCROLLER_FLOOR, CardBound.CARD_FLOOR - chrome)', $bound);

        // The sheet spends the same rule rather than a copy of it.
        $sheet = self::read(self::SHEET);
        self::assertStringContainsString("import { CardBound } from './bound_controller.js';", $sheet);
        self::assertStringContainsString('CardBound.apply(this.element, this.scrollerTarget)', $sheet);
        self::assertStringNotContainsString('CARD_FLOOR = ', $sheet, 'No second set of numbers.');

        // One rule in the sheet, read by every scroller that is bounded.
        $css = self::read(self::SHEET_CSS);
        self::assertMatchesRegularExpression('/\.rscroll \{[^}]*overflow: auto;[^}]*max-height: var\(--cardmax, calc\(80vh - 240px\)\);/s', $css);
        self::assertDoesNotMatchRegularExpression('/\.psheetwrap \{[^}]*max-height/s', $css, 'The sheet has no bound of its own any more.');
        self::assertStringContainsString('class="psheetwrap rscroll', self::read(self::PAGE));

        // Every bounded card is the one controller and the one class.
        foreach (self::BOUNDED as $template) {
            $markup = self::read($template);
            self::assertStringContainsString('data-controller="roster--bound"', $markup, $template.' measures it.');
            self::assertStringContainsString('data-roster--bound-target="scroller"', $markup.self::read(self::WALL), $template.' names what scrolls.');
        }

        $manifest = json_decode(self::read(self::PACKAGE), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);
        $symfony = $manifest['symfony'] ?? null;
        self::assertIsArray($symfony);
        $controllers = $symfony['controllers'] ?? null;
        self::assertIsArray($controllers);
        self::assertSame(
            ['main' => 'controllers/bound_controller.js', 'name' => 'roster--bound', 'fetch' => 'eager', 'enabled' => true],
            $controllers['bound'] ?? null,
        );
    }

    /**
     * A HEAD INSIDE A BOUNDED SCROLLER IS PINNED: a table's column head,
     * and the day board's hour scale and post names.
     */
    public function testTheHeadsInsideABoundedScrollerArePinned(): void
    {
        $css = self::read(self::SHEET_CSS);

        self::assertMatchesRegularExpression('/\.rscroll > table\.tbl > thead th \{[^}]*position: sticky; top: 0;/s', $css);
        self::assertMatchesRegularExpression('/\.r-ruler \{[^}]*position: sticky; top: 0;/s', $css);
        self::assertMatchesRegularExpression('/\.r-dayrow \.lbl \{[^}]*position: sticky; left: 0;/s', $css);
    }

    /**
     * THE BY-HAND MENU IS LIFTED OUT OF THE SHEET, and this is the
     * assertion that keeps it there.
     *
     * `.psheetwrap` is `overflow:auto`, so a menu drawn inside it is
     * CLIPPED — and no z-index wins an argument with a clip. Inside the
     * isolated `<tbody>` it also lost the hit test to the day squares.
     * Both are answered by the same move: onto a layer on the body.
     */
    public function testTheMenuLeavesTheSheetToOpen(): void
    {
        $js = self::read(self::MENU);
        $css = self::read(self::SHEET_CSS);

        self::assertStringContainsString('this.layer().appendChild(menu)', $js, 'The menu has to leave the sheet, not out-rank it.');
        self::assertStringContainsString("layer.className = 'pmenulayer'", $js, 'And the layer it lands on is the one the sheet ships.');
        self::assertStringContainsString("classList.add('pmout')", $js);
        self::assertStringContainsString("classList.remove('pmout')", $js);
        self::assertStringContainsString('.pmenulayer', $css, 'The layer has to be shipped.');
        self::assertStringContainsString('position: fixed', $css);
    }

    /**
     * IT STAYS ATTACHED TO ITS CELL, and the browser does it.
     *
     * The open cell is given an `anchor-name` and the panel hangs off it
     * with `position-anchor`, so the two move on the same frame the
     * scroll is painted. A menu repositioned from a scroll HANDLER lands
     * a frame behind the cell, which is the jitter this replaced — so the
     * JS path is a fallback, it is asked for by feature test, and when it
     * runs it writes inside a frame.
     */
    public function testTheMenuIsAnchoredToItsCellRatherThanChasingIt(): void
    {
        $js = self::read(self::MENU);
        $css = self::read(self::SHEET_CSS);

        preg_match("/static ANCHOR = '(--[a-z-]+)'/", $js, $named);
        $anchor = $named[1] ?? '';
        self::assertNotSame('', $anchor, 'The controller has to name the anchor it sets.');

        self::assertStringContainsString('cell.style.anchorName = this.constructor.ANCHOR', $js, 'The open CELL is the anchor.');
        self::assertStringContainsString('position-anchor: '.$anchor, $css, 'And the panel has to name the same one.');
        self::assertStringContainsString('top: anchor(bottom)', $css);
        self::assertStringContainsString('position-try-fallbacks', $css, 'Flipping and clamping are the browser\'s, not a handler\'s.');

        self::assertStringContainsString('static anchorPositioning()', $js, 'The JS path is a fallback, asked for by feature test.');
        self::assertStringContainsString('requestAnimationFrame', $js, 'And it writes in a frame, never from the scroll event.');

        /*
         * AND THE FALLBACK REFUSES TO RUN WHERE THE BROWSER IS ATTACHING.
         * An inline `top` in px out-ranks `top: anchor(bottom)`, so one
         * call to the placement routine detaches the menu from its cell
         * for good: measured on the test bed, the cell moved -120 and the
         * menu moved 0, computed top frozen at 287.57px. The guard sits
         * inside the method, not at its call sites, so a third call site
         * cannot bring the defect back.
         */
        self::assertMatchesRegularExpression(
            '/place\(\)\s*\{(?:\s*\/\*.*?\*\/)?\s*if \(this\.anchored \|\|/s',
            $js,
            'The inline-offset path has to refuse outright when anchor positioning is available.',
        );
        self::assertStringNotContainsString('.style.top =', $js, 'An inline length beats `top: anchor(bottom)`; the fallback may not write one.');
        self::assertStringContainsString("setProperty('--pm-top'", $js, 'It writes a custom property instead.');
        self::assertStringContainsString('@supports not (position-anchor:', self::read(self::SHEET_CSS), 'And the BROWSER decides which positioning applies, not the script.');
    }

    /**
     * AND IT IS PUT BACK, never cloned. The template is the one place the
     * menu exists; a controller that copied it would answer a form the
     * second copy had already answered, and one that forgot to return it
     * would strand a node on the layer after a navigation.
     */
    public function testTheMenuIsReturnedToItsCellAndNeverCloned(): void
    {
        $js = self::read(self::MENU);

        self::assertStringContainsString('this.home.appendChild(this.menu)', $js, 'Closing puts it back.');
        self::assertStringContainsString("this.cell.style.anchorName = ''", $js, 'And takes the anchor off the cell.');
        self::assertMatchesRegularExpression('/disconnect\(\)\s*\{\s*(?:\/\/[^
]*
\s*)*this\.close\(\);/', $js, 'And so does going away.');
        self::assertStringNotContainsString('cloneNode', $js);
    }

    /**
     * A SCROLL DOES NOT CLOSE IT — it follows. What closes it is the cell
     * leaving the scroller's visible band (under the pinned day head, or
     * past the bottom), an outside click, or Escape: a menu pointing at a
     * row nobody can see is the only case worth dismissing.
     */
    public function testTheMenuClosesWhenItsCellLeavesTheBand(): void
    {
        $js = self::read(self::MENU);

        self::assertStringContainsString("addEventListener('scroll', this.follow, true)", $js, 'Capture, so the sheet\'s own scroller counts.');
        self::assertStringContainsString("addEventListener('resize', this.follow)", $js);
        self::assertStringContainsString("'Escape'", $js);
        self::assertStringContainsString("addEventListener('click', this.dismiss)", $js);

        self::assertStringContainsString('.psheetwrap', $js, 'The band is the scroller\'s.');
        self::assertStringContainsString('cell.bottom <= top || cell.top >= port.bottom', $js, 'And leaving it is what closes the menu.');
        self::assertMatchesRegularExpression('/if \(this\.gone\(\)\) \{\s*this\.close\(\);/', $js);
    }

    /**
     * THE LAYER OUT-RANKS EVERY LAYER THE SHEET PINS — read out of the
     * sheet rather than typed here, so a new sticky layer raised above it
     * fails this test instead of hiding the menu again.
     *
     * AND IT STAYS UNDER THE SHELL'S CONFIRM MODAL (120), which is the one
     * thing that must always win: a question about destroying something
     * cannot be covered by the menu that asked it.
     */
    public function testThePortalledMenuOutRanksEverySheetLayer(): void
    {
        $css = self::read(self::SHEET_CSS);

        preg_match('/\.pmenulayer\s*\{[^}]*z-index:\s*(\d+)/', $css, $portal);
        $declared = $portal[1] ?? '';
        self::assertNotSame('', $declared, 'The layer has to declare where it opens.');
        $top = (int) $declared;

        // Every z-index the sheet's own sticky and isolated layers spend.
        preg_match_all('/(\.psheetwrap|table\.csheet|\.cl)[^{}]*\{[^}]*z-index:\s*(\d+)/', $css, $layers);
        self::assertNotSame([], $layers[2], 'The sheet pins layers; this test is about beating them.');

        foreach ($layers[2] as $layer) {
            self::assertGreaterThan((int) $layer, $top, 'A sheet layer is pinned above the menu, which is how it opened behind the cells.');
        }

        self::assertLessThan(120, $top, 'The shell\'s confirm modal has to stay on top of everything.');
    }

    /**
     * THE CELL THAT OPENS THE MENU IS A BUTTON, AND IT NAMES ITS EVENT.
     *
     * THIS IS THE ONE THE WHOLE SUITE MISSED. Stimulus binds no default
     * event to a `<span>`: the attribute rendered, the controller
     * connected, the cell was inside its scope — and a real click did
     * NOTHING, while every server-side test passed, because a test that
     * builds its own request never dispatches a click. Measured in a
     * headless browser: zero of three rows opened. A real button also
     * brings the keyboard, which a span never had.
     */
    public function testTheCellThatOpensTheMenuIsAButtonThatNamesItsEvent(): void
    {
        $cell = self::read(self::CELL);

        preg_match("/\\{% set act = '([^']+)' %\\}/", $cell, $act);
        $attributes = $act[1] ?? '';

        self::assertStringContainsString('type="button"', $attributes, 'A span gets no click binding from Stimulus.');
        self::assertStringContainsString('click->roster--day-menu#open', $attributes, 'And the event is named, never left to a default.');
        self::assertStringNotContainsString('data-action="roster--day-menu#open"', $cell, 'The unnamed form is the defect.');

        self::assertMatchesRegularExpression(
            '/<\{\{ menu \? .button. : .span. \}\} class="cl/',
            $cell,
            'A cell with nothing to do stays a span; a cell with a menu is a button.',
        );

        self::assertStringContainsString('button.cl', self::read(self::SHEET_CSS), 'And the button needs its reset, or it sizes to content and measures 0px wide at four weeks.');
        self::assertStringContainsString('.dm .dmstep[hidden]', self::read(self::SHEET_CSS), 'A display of our own beats [hidden]; the closed step has to be said out loud.');
    }

    /**
     * FIVE VERBS, IN THE RULED ORDER, WITH THE RULED MARKS — option B.
     *
     * The order is the ruling, and so is the count: "mark the day
     * unfilled" went with the cell kind it wrote, because RULED 21 sep a
     * gap belongs to the station and not to a ranger. A row that lost its
     * mark, or a sixth that crept back in, changes what the owner ruled
     * on.
     */
    public function testTheMenuIsTheFiveRuledVerbsInOrder(): void
    {
        $cell = self::read(self::CELL);

        preg_match_all('/<span class="l">([^<]+)<\/span>/', $cell, $verbs);
        self::assertSame([
            'Change the shift',
            'Move to someone else',
            'Swap with another',
            'Give the day off',
            'Clear the hand mark',
            'Clear the hand mark',
        ], $verbs[1], 'Five verbs on a watch, in order; the mark-clearing one again where there is no watch left.');

        foreach ([
            'roster:replace',
            'roster:user-round-plus',
            'roster:arrow-right-left',
            'roster:circle-slash',
            'roster:eraser',
        ] as $mark) {
            self::assertStringContainsString("ux_icon('".$mark."')", $cell, 'The ruled mark, verbatim.');
        }

        self::assertStringNotContainsString('value="unfill"', $cell, 'A ranger is never marked unfilled; the station is short.');
        self::assertStringNotContainsString('class="cl unf"', $cell, 'And no cell wears the retired kind.');
    }

    /**
     * AND A VERB THAT TAKES A VALUE GROWS ITS CHOICE UNDER ITS OWN ROW.
     *
     * THE ROW IS NOT A STIMULUS ACTION. The panel is lifted onto a layer on
     * the body when it opens, outside the controller's element, and an
     * action attribute on a lifted row binds to nothing — "Change the
     * shift" lit up and nothing opened. The row names its step in
     * `data-step-name` and the controller listens on the panel it lifted.
     */
    public function testEveryValueTakingVerbOpensItsStepInline(): void
    {
        $cell = self::read(self::CELL);
        $js = self::read(self::MENU);
        $css = self::read(self::SHEET_CSS);

        foreach (['shift', 'move', 'swap'] as $verb) {
            self::assertStringContainsString('data-step-name="'.$verb.'"', $cell);
            self::assertStringContainsString('data-step="'.$verb.'"', $cell);
        }
        self::assertStringNotContainsString('day-menu#step', $cell, 'No action binds on a row the layer will lift.');

        self::assertStringContainsString("menu.addEventListener('click', this.stepClick)", $js, 'The lifted panel is listened to directly.');
        self::assertStringContainsString("this.menu.removeEventListener('click', this.stepClick)", $js, 'And let go on close.');
        self::assertStringContainsString('.dmstep[data-step="${wanted}"]', $js, 'The row opens the step it names.');
        self::assertStringContainsString('.dm .dmstep', $css, 'And the step is shipped.');
    }

    /**
     * THE CELL READS THE SHIFT'S STORED SLOT AND NEVER A HUE OF ITS OWN.
     * RULED 21 sep: a shift is given a colour when it is created and keeps
     * it on every tab, so the cell resolves `[data-cat]` through the
     * shell's own resolver.
     */
    public function testTheCellTakesItsColourFromTheStoredSlot(): void
    {
        $cell = self::read(self::CELL);
        $css = self::read(self::SHEET_CSS);

        self::assertStringContainsString('data-cat="{{ cell.colour }}"', $cell);
        self::assertStringContainsString('var(--cat', $css, 'The bar reads the resolved slot.');
        self::assertDoesNotMatchRegularExpression('/\.cl\.bar[^{]*\{[^}]*#[0-9a-f]{3,6}/i', $css, 'No hex on the cell: the colour is the shift\'s.');
    }
}
