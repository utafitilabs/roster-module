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

/** HOW FAR A PLANNER'S CHANGE TO THEIR OWN WATCH HAS GOT, at a station whose head approves (#60). */
enum WatchChangeState: string
{
    /** Asked; the watch stands as it was. */
    case Waiting = 'waiting';

    /** Approved by the head, and made. */
    case Approved = 'approved';

    /** Refused by the head; the watch stands as it was. */
    case Refused = 'refused';

    public function label(): string
    {
        return match ($this) {
            self::Waiting => 'waiting for the head',
            self::Approved => 'approved',
            self::Refused => 'refused by the head',
        };
    }
}
