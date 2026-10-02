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

namespace Uhifadhi\Roster\Service;

use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Service\PingInterval;

/**
 * WHAT THE ROSTER READS OF SETTINGS: the ping interval in force for an area,
 * from Settings › Core (set by Super Admins and Admins, ruled 1 Oct 2026).
 *
 * The roster keeps no settings of its own any more. Its four stored answers —
 * off days on a tour, leave approval, announcing vacancies, the late
 * threshold — were read by nothing and went in 0.2 (ruled 2 Oct 2026), each to
 * return with the feature that reads it; its configuration is the Watches
 * rules, the area manager's.
 */
final readonly class RosterSettingsService
{
    public function __construct(
        // THE AREA'S PING INTERVAL, read the way the handset reads it.
        private PingInterval $pingInterval,
    ) {
    }

    /** How often this area's handsets report — Settings › Core's value in force here. */
    public function pingIntervalFor(AreaOfInterest $area): int
    {
        return $this->pingInterval->for($area);
    }
}
