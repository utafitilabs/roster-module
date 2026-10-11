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

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * THE INSTALLATION LEARNS THE CONTROLLERS FROM COMPOSER, NOT BY HAND.
 *
 * Flex registers a package's Stimulus controllers in the installation's
 * assets/controllers.json only when the package carries the "symfony-ux"
 * keyword; a package without it is skipped at install AND its hand-written
 * entries are stripped at the next `composer update`, because Flex rebuilds
 * that file from the packages it recognises
 * (symfony/flex: PackageJsonSynchronizer::resolvePackageJson;
 * https://symfony.com/bundles/StimulusBundle/current/index.html#lazy-stimulus-controllers).
 */
#[CoversNothing]
final class ComposerManifestTest extends TestCase
{
    public function testAPackageThatShipsControllersCarriesTheSymfonyUxKeyword(): void
    {
        $root = \dirname(__DIR__, 2);

        /** @var array{keywords?: list<string>} $composer */
        $composer = json_decode((string) file_get_contents($root.'/composer.json'), true, 512, \JSON_THROW_ON_ERROR);
        /** @var array{symfony?: array{controllers?: array<string, mixed>}} $package */
        $package = json_decode((string) file_get_contents($root.'/assets/package.json'), true, 512, \JSON_THROW_ON_ERROR);

        self::assertNotEmpty($package['symfony']['controllers'] ?? [], 'The module ships Stimulus controllers.');
        self::assertContains('symfony-ux', $composer['keywords'] ?? [], 'Without the "symfony-ux" keyword Flex never writes the controllers into an installation and strips any hand-written entry on the next update.');
    }
}
