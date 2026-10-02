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

namespace Uhifadhi\Roster\Tests\Integration\Service;

use Uhifadhi\Roster\Service\RosterSettingsService;
use Uhifadhi\Roster\Tests\Integration\IntegrationTestCase;
use Uhifadhi\Roster\Tests\SetsCoreSettings;

/**
 * WHAT THE ROSTER READS OF SETTINGS: the ping interval in force for an area,
 * Settings › Core's — never a number of the roster's own.
 */
final class RosterSettingsServiceTest extends IntegrationTestCase
{
    use SetsCoreSettings;

    public function testTheIntervalIsTheOneInForceForTheArea(): void
    {
        $area = $this->anArea();
        $this->em->flush();
        self::assertSame(30, $this->settings()->pingIntervalFor($area), 'Nothing set: half an hour.');

        $this->pingEvery($this->em, $area, 20);
        self::assertSame(20, $this->settings()->pingIntervalFor($area));
    }

    private function settings(): RosterSettingsService
    {
        $settings = $this->service(RosterSettingsService::class);
        self::assertInstanceOf(RosterSettingsService::class, $settings);

        return $settings;
    }
}
