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

namespace Uhifadhi\Roster\Tests;

use Doctrine\ORM\EntityManagerInterface;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Service\PingInterval;
use Uhifadhi\Bundle\AreaBundle\Settings\CoreSettings;
use Uhifadhi\Bundle\RegistryBundle\Entity\SettingValue;
use Uhifadhi\Bundle\RegistryBundle\Settings\SettingsResolver;
use Uhifadhi\Contracts\Settings\SettingDepth;
use Uhifadhi\Contracts\Settings\SettingsReaderInterface;

/**
 * THE PING INTERVAL IS SETTINGS › CORE'S (ruled 1 Oct 2026): a test gives an
 * area its own the way an Admin does — a custom value in the settings store —
 * and reads it back the way the handset does.
 */
trait SetsCoreSettings
{
    protected function pingEvery(EntityManagerInterface $em, AreaOfInterest $area, int $minutes): void
    {
        $area->generateUuid();
        $em->persist(new SettingValue(CoreSettings::PING_INTERVAL, SettingDepth::Area, (string) $area->getUuidString(), $minutes, 'admin@example.test', new \DateTimeImmutable()));
        $em->flush();

        $reader = static::getContainer()->get(SettingsReaderInterface::class);
        if ($reader instanceof SettingsResolver) {
            $reader->reset();
        }
    }

    protected function pingIntervalOf(AreaOfInterest $area): int
    {
        $interval = static::getContainer()->get('area.ping_interval');
        \assert($interval instanceof PingInterval);

        return $interval->for($area);
    }
}
