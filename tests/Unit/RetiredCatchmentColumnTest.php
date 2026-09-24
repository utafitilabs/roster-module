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

namespace Uhifadhi\Roster\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * ONE DISTANCE, ONE HOME — and the second one is gone for good.
 *
 * A POST'S RING IS `station.catchment_m`. That is the column the AREA
 * measures a claim against and the one its own service writes; this module
 * states the distance and does no measuring with it. The watch carried the
 * same number in a second place for a while, and two columns for one
 * distance are two answers the day somebody edits one of them — which was
 * exactly the state the configure page had to carry a flag about.
 *
 * THE RETIREMENT IS OVER. The property, its accessors and the column all
 * went in the release after the one that stopped reading them. What this
 * test holds now is that nothing brings any of them back by hand: an entity
 * property is a schema change that looks like a one-line edit, and the next
 * `diff` would hand an installation SQL re-adding a column somebody
 * deliberately dropped.
 *
 * `default_catchment_metres` — the AREA's fallback ring, a configured setting
 * and not this column — is deliberately not matched: the pattern names the
 * retired property and its accessors, which is what a return of the column
 * actually looks like.
 *
 * @see \Uhifadhi\Roster\Migrations\Version20260924000000
 */
final class RetiredCatchmentColumnTest extends TestCase
{
    private const string SRC = __DIR__.'/../../src';

    private const string TEMPLATES = __DIR__.'/../../templates';

    public function testNothingInTheModuleCarriesAWatchCatchmentAgain(): void
    {
        $offenders = [];

        foreach ($this->sources() as $path => $code) {
            if (preg_match('/\b(getCatchmentMetres|setCatchmentMetres|catchmentMetres)\b/', $code)) {
                $offenders[] = $path;
            }
        }

        self::assertSame(
            [],
            $offenders,
            "These name the DROPPED watch catchment. The ring is the post's — read Station::getCatchmentM() and write through StationService::setCatchment():\n  ".implode("\n  ", $offenders),
        );
    }

    /** And nothing maps the column that was dropped. */
    public function testTheWatchMapsNoCatchmentColumn(): void
    {
        $entity = (string) file_get_contents(self::SRC.'/Entity/StationWatch.php');

        self::assertStringNotContainsString('catchment_metres', $entity);
    }

    /**
     * Every PHP and Twig file this module ships, by its path from the root.
     *
     * @return array<string, string>
     */
    private function sources(): array
    {
        $found = [];

        foreach ([self::SRC => 'src', self::TEMPLATES => 'templates'] as $root => $prefix) {
            /** @var \SplFileInfo $file */
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if (\in_array($file->getExtension(), ['php', 'twig'], true)) {
                    $found[$prefix.'/'.ltrim(str_replace($root, '', $file->getPathname()), '/')] = (string) file_get_contents($file->getPathname());
                }
            }
        }

        return $found;
    }
}
