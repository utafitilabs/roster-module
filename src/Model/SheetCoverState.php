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
 * HOW ONE STATION'S ONE DAY STANDS AGAINST WHAT IT NEEDS.
 *
 * RULED 21 sep: A GAP BELONGS TO THE STATION, NOT TO A RANGER. A person
 * is on a shift or they are off; nobody is ever "unfilled". What can be
 * short is the PLACE on the DAY, against the number that place says it
 * needs — so the alarm ink is spent once, on the station's own row, where
 * the decision is actually made.
 *
 * NOTHING ASKED IS NOT A FAILURE. A station that has never said how many
 * it needs expects nothing of anybody, and its day reads as a dash. A
 * fortnight of alarm ink at a place nobody has made a decision about
 * would be the sheet shouting at a reader who has done nothing wrong.
 */
enum SheetCoverState: string
{
    /** The station names no number and nobody is stationed there; there is nothing to count against. */
    case Nothing = 'nil';

    /** Every shift the station runs has the people it asked for. */
    case Met = 'met';

    /** Somebody is on, but fewer than the station needs on at least one shift. */
    case Short = 'short';

    /** Not one is on — against the number the station needs, or against the rangers stationed there. */
    case Nobody = 'none';

    /** The class the token wears — `.cvr` plus this. */
    public function className(): string
    {
        return $this->value;
    }

    /** Whether this day counts towards "station-days under the number". */
    public function isUnder(): bool
    {
        return self::Short === $this || self::Nobody === $this;
    }
}
