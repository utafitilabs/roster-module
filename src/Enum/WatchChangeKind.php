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

namespace Uhifadhi\Roster\Enum;

/** WHAT A PLANNER ASKED TO DO TO THEIR OWN WATCH — the sheet menu's own items. */
enum WatchChangeKind: string
{
    case Shift = 'shift';
    case Move = 'move';
    case DayOff = 'off';

    /** "change her shift to night", "give her watch to B. Mwita", "give herself the day off" */
    public function describe(?string $shiftKey, ?string $toName): string
    {
        return match ($this) {
            self::Shift => 'change the shift to '.$shiftKey,
            self::Move => 'give the watch to '.$toName,
            self::DayOff => 'take the day off',
        };
    }
}
