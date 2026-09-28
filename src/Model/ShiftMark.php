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

/**
 * ONE MARK ON ONE DAY OF A PERSON'S WEEK (#19; the design's day / night /
 * off / leave pills): a watch they stand, a day they are stood down, or a
 * day they are away.
 *
 * THE KIND IS WHAT THE PILL WEARS and the label is what it says. A watch
 * whose window crosses midnight wears the night pill and every other watch
 * the day pill, whatever the area calls it — "radio night" is a night, the
 * office watch is a day — so an area's own vocabulary reads in the house's
 * two colours. An absence says its kind ("leave", "sick") in the leave pill.
 */
final readonly class ShiftMark
{
    public const string DAY = 'day';
    public const string NIGHT = 'night';
    public const string OFF = 'off';
    public const string LEAVE = 'leave';

    public function __construct(
        /** One of the constants: the pill it wears. */
        public string $kind,
        /** What the pill says. */
        public string $label,
        /** `06:00–18:00` for a watch; null for a day off or away. */
        public ?string $window = null,
    ) {
    }

    public static function off(): self
    {
        return new self(self::OFF, 'off');
    }
}
