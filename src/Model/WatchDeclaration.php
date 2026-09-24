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

namespace Uhifadhi\Roster\Model;

use Uhifadhi\Roster\Entity\Rotation;
use Uhifadhi\Roster\Entity\Shift;

/**
 * WHAT A STATION'S WATCH ASKS FOR, in the words every register prints —
 * "day 2 · night 2", or "no watch".
 *
 * ONE SENTENCE, ONE IMPLEMENTATION. The rota's heading, the organization
 * dashboard's watches cell and anything that comes after all print this same
 * phrase; a second copy would say "day 2, night 2" somewhere within a
 * release, and the two would be read side by side on the same screen.
 *
 * IT TAKES BOTH HALVES BECAUSE THE ANSWER NEEDS BOTH. The WATCH says which
 * shifts a station stands; the ROTATION says how many people each of them
 * takes. A station with a watch and no ring asks for nothing in particular
 * and says so — which is not the same fact as a station that declares no
 * watch at all, but reads the same way here on purpose: neither is short of
 * anybody.
 */
final readonly class WatchDeclaration
{
    /** What a station that stands nothing prints, everywhere. */
    public const string NOTHING = 'no watch';

    /**
     * @param list<string>         $expects the station's own shift keys, in the order it declares them
     * @param array<string, Shift> $shifts  the area's vocabulary, keyed by shift key
     */
    public static function of(array $expects, ?Rotation $rotation, array $shifts): string
    {
        if (null === $rotation || [] === $expects) {
            return self::NOTHING;
        }

        $parts = [];
        foreach ($expects as $key) {
            $slots = $rotation->slotsFor($key);
            if ($slots > 0) {
                $shift = $shifts[$key] ?? null;
                $parts[] = mb_strtolower(null === $shift ? $key : $shift->getLabel()).' '.$slots;
            }
        }

        return [] === $parts ? self::NOTHING : implode(' · ', $parts);
    }
}
