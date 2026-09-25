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
 * ONE STATION'S COVER ON ONE DAY — the token under a day column on the
 * station's own head row.
 *
 * IT IS COUNTED PER SHIFT AND STATED AS ONE FIGURE. A station that runs
 * two day watches and two night watches needs four people, and five on
 * the day watch with nobody on the night watch is not four: the count
 * that is stated is what each shift could actually use, which is why
 * {@see $on} is the sum of the covered part of each shift and never the
 * raw head count.
 *
 * A STATION THAT NAMES NO NUMBER IS COUNTED AGAINST ITS OWN RANGERS —
 * RULED 25 sep. The token says how many of the rangers stationed there
 * stand a watch that day, out of how many are stationed there, and a day
 * nobody stands wears the empty mark. It is never SHORT: nothing was
 * asked, so {@see countsAsShort()} leaves it out of "station-days under
 * the number".
 *
 * A DAY THERE IS NOTHING TO COUNT AGAINST READS AS A DASH, not as a zero:
 * no number named and nobody stationed.
 */
final readonly class SheetCover
{
    public function __construct(
        public \DateTimeImmutable $day,
        /** How many of the needed places are covered — or, where no number is named, how many watches stand there; null where there is nothing to count against. */
        public ?int $on,
        /** How many the station says it needs across the shifts it runs — or, where it names no number, how many are stationed there; null where there is nothing to count against. */
        public ?int $needed,
        public SheetCoverState $state,
        public bool $isToday = false,
        /** True where the station names no number and {@see $needed} is the rangers stationed there. */
        public bool $againstStationed = false,
    ) {
    }

    /** Whether this day is a station-day under the number the station names. */
    public function countsAsShort(): bool
    {
        return !$this->againstStationed && $this->state->isUnder();
    }

    /** What the token prints — "4/4", or a dash where nothing is asked. */
    public function label(): string
    {
        if (null === $this->on || null === $this->needed) {
            return '—';
        }

        return $this->on.'/'.$this->needed;
    }
}
