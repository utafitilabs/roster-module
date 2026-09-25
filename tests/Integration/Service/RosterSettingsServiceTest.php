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

use Uhifadhi\Roster\Enum\LateThreshold;
use Uhifadhi\Roster\Service\RosterSettingsService;
use Uhifadhi\Roster\Tests\Integration\IntegrationTestCase;

/**
 * "TWICE THE INTERVAL" COUNTS FROM THE AREA'S INTERVAL — the one the handset
 * is told and the live reading judges by, never a number of the roster's own.
 */
final class RosterSettingsServiceTest extends IntegrationTestCase
{
    public function testTwiceTheIntervalIsTwiceTheAreasNumber(): void
    {
        $area = $this->anArea()->setPingIntervalMinutes(20);
        $this->em->flush();

        self::assertSame(LateThreshold::TwiceTheInterval, $this->settings()->forArea($area)->getLateThreshold());
        self::assertSame(40, $this->settings()->lateAfterMinutes($area));
        self::assertSame(20, $this->settings()->pingIntervalFor($area));
    }

    /** An area that sets none runs at half an hour, so twice it is an hour. */
    public function testAnAreaThatSetsNoneCountsFromTheDefault(): void
    {
        $area = $this->anArea();
        $this->em->flush();

        self::assertSame(60, $this->settings()->lateAfterMinutes($area));
    }

    /** A fixed threshold does not move with the interval. */
    public function testAFixedThresholdIgnoresTheInterval(): void
    {
        $area = $this->anArea()->setPingIntervalMinutes(20);
        $this->em->flush();
        $this->settings()->forArea($area)->setLateThreshold(LateThreshold::FourHours);

        self::assertSame(240, $this->settings()->lateAfterMinutes($area));
    }

    private function settings(): RosterSettingsService
    {
        $settings = $this->service(RosterSettingsService::class);
        self::assertInstanceOf(RosterSettingsService::class, $settings);

        return $settings;
    }
}
