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

namespace Uhifadhi\Roster\Tests\Unit\Access;

use PHPUnit\Framework\TestCase;
use Uhifadhi\Contracts\Access\Grant;
use Uhifadhi\Contracts\Access\Verb;
use Uhifadhi\Roster\Access\RosterConcerns;

/**
 * THE DECLARATIONS AND THE ROUTES, HELD TOGETHER — in both directions,
 * because each direction is a different mistake and both look like working
 * code.
 *
 *   - A ROUTE THAT NAMES NO PAIR is a page of this module that anybody who
 *     reached the installation can open. Nothing else in this bundle is
 *     guarding it: the registry's listener only closes routes in an area
 *     that has PARKED the module, which is not a permission.
 *   - A ROUTE THAT NAMES A PAIR NOBODY DECLARED can never be granted,
 *     because the voter refuses a pair its catalogue does not know. The page
 *     is then shut to everybody but an administrator, and no error says so.
 *   - A DECLARED PAIR NOTHING ENFORCES is a box an administrator can tick
 *     that changes nothing — the worst of the three, because it reads as a
 *     power somebody was given.
 *
 * THE GATE IS THE ATTRIBUTE AND NOTHING ELSE. A check written inside an
 * action is invisible to this walk, is invisible to the door a template
 * draws, and splits one route's answer over two places; where an action
 * needed two pairs, the action was split into two routes instead.
 *
 * IT READS THE SOURCE rather than the router, so it costs nothing and runs
 * without a kernel — the same choice the core's module-side conformance
 * makes.
 */
final class EveryRouteNamesItsPairTest extends TestCase
{
    public function testEveryRouteThisModuleShipsNamesThePairItEnforces(): void
    {
        $ungated = [];

        foreach (self::routes() as [$file, $name, $pairs]) {
            if ([] === $pairs) {
                $ungated[] = $file.' -> '.$name;
            }
        }

        sort($ungated);

        self::assertSame([], $ungated, \sprintf(
            "These routes enforce nothing [%s].\nEvery page and every write of this module names the pair it enforces: ".
            '`#[IsGranted(\'<concern>.<verb>\', subject: \'area\')]`.',
            implode(', ', $ungated),
        ));
    }

    public function testEveryPairARouteNamesIsOneThisModuleOrTheCoreDeclares(): void
    {
        $strangers = [];

        foreach (self::routes() as [$file, $name, $pairs]) {
            foreach ($pairs as $pair) {
                $grant = Grant::tryParse($pair);
                if (null === $grant) {
                    $strangers[] = $file.' -> '.$name.' ('.$pair.' is not a pair)';
                    continue;
                }

                // A route of this module may legitimately gate on a concern
                // the core declares — reading the ground a roster is drawn
                // on, say; what it must never do is name a verb of OUR
                // concern that we never declared.
                if (RosterConcerns::ROSTER === $grant->concern && !\in_array($pair, self::declaredPairs(), true)) {
                    $strangers[] = $file.' -> '.$name.' ('.$pair.')';
                }
            }
        }

        sort($strangers);

        self::assertSame([], $strangers, \sprintf(
            'These routes name a pair this module declares the concern of but not the verb [%s].',
            implode(', ', $strangers),
        ));
    }

    public function testEveryPairThisModuleDeclaresIsEnforcedSomewhere(): void
    {
        $enforced = [];
        foreach (self::routes() as [, , $pairs]) {
            foreach ($pairs as $pair) {
                $enforced[$pair] = true;
            }
        }

        $idle = array_filter(
            self::declaredPairs(),
            static fn (string $pair): bool => !isset($enforced[$pair]),
        );

        self::assertSame([], array_values($idle), \sprintf(
            "These pairs are declared and nothing enforces them [%s].\nA declared power nothing enforces is a box an ".
            'administrator can tick that changes nothing. Declare the verb in the same change that ships the thing enforcing it.',
            implode(', ', $idle),
        ));
    }

    /**
     * A ROUTE'S GATE IS ITS ATTRIBUTE, so no action asks the checker itself.
     *
     * A check in code is a second gate on a route that already carries one,
     * or — worse — the only gate on a route that carries none, where this
     * walk reports the route clean and the template beside it draws a door
     * naming a pair nothing holds it to.
     */
    public function testNoControllerAsksTheCheckerInCode(): void
    {
        $asking = [];

        foreach (self::shippedSource() as $path) {
            if (!str_contains($path, '/Controller/')) {
                continue;
            }

            if (1 === preg_match('/->isGranted\s*\(/', (string) file_get_contents($path))) {
                $asking[] = basename($path);
            }
        }

        sort($asking);

        self::assertSame([], $asking, \sprintf(
            "These controllers ask the authorization checker in code [%s].\n".
            'A route\'s gate is its `#[IsGranted]` attribute, and a drawn control is a `door()` in the template. '.
            'An action that needs two pairs is two routes.',
            implode(', ', $asking),
        ));
    }

    /**
     * Every route this module ships, as [file, route name, pairs gated].
     *
     * A class-level `#[Route(defaults: …)]` carries no path and is not a
     * route; it is skipped by the same test the core's uses — a chunk with
     * no quoted path in it.
     *
     * @return list<array{string, string, list<string>}>
     */
    private static function routes(): array
    {
        $routes = [];

        foreach (self::shippedSource() as $path) {
            $source = (string) file_get_contents($path);

            foreach (\array_slice(explode('#[Route(', $source), 1) as $chunk) {
                $head = explode('function ', $chunk, 2)[0];

                // The path is what makes a chunk a route at all. The NAME is
                // read as written — this module spells its route names as
                // class constants so a template and a redirect cannot
                // disagree about one — and is only ever a label in a failure.
                if (1 !== preg_match("/'(\/[^']*)'/", $head, $route)) {
                    continue;
                }

                preg_match('/name:\s*([^,\n]+)/', $head, $name);

                preg_match_all("/#\[IsGranted\(\s*'([^']+)'/", $head, $gates);

                $routes[] = [basename($path), trim($name[1] ?? $route[1]), $gates[1]];
            }
        }

        self::assertNotSame([], $routes, 'this module ships routes; a walk that found none is a broken walk, not a clean one.');

        return $routes;
    }

    /** @return list<string> */
    private static function declaredPairs(): array
    {
        $pairs = [];
        foreach (new RosterConcerns()->concerns() as $concern) {
            foreach (Verb::cases() as $verb) {
                if ($concern->supports($verb)) {
                    $pairs[] = (string) Grant::of($concern->key(), $verb);
                }
            }
        }

        return $pairs;
    }

    /** @return list<string> */
    private static function shippedSource(): array
    {
        $paths = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(\dirname(__DIR__, 3).'/src', \FilesystemIterator::SKIP_DOTS),
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ('php' === $file->getExtension()) {
                $paths[] = $file->getPathname();
            }
        }

        sort($paths);

        return $paths;
    }
}
