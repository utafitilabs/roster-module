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
 * ONE STATION'S BAND OF THE SHEET — its head, and the rangers stationed
 * at it.
 *
 * A BAND FOLDS, and a FOLDED BAND STILL STATES HOW MANY DAYS IT IS SHORT.
 * Folding is for length — a sheet of 34 rangers is not read by scrolling
 * for ages — and it may never hide a gap, which is why {@see $cover} sits
 * on the head row and not only in the rows underneath it.
 *
 * THE COVER IS THE BAND'S, NOT A RANGER'S. RULED 21 sep: what can be
 * short is the station on the day, against the number the station says it
 * needs, so the head row carries one token per day and a ranger's cell
 * carries none.
 *
 * A STATION WITH NOBODY STATIONED AT IT STILL GETS A BAND. It is on the
 * area's books, and a sheet that quietly left it out would be a sheet
 * that cannot be used to notice it.
 */
final readonly class SheetBand
{
    /**
     * @param list<SheetRow>   $rows
     * @param list<SheetCover> $cover one per day of the window, in order
     */
    public function __construct(
        public string $stationUuid,
        public string $stationName,
        public ?string $stationCode,
        public array $rows,
        public array $cover = [],
    ) {
    }

    public function rangers(): int
    {
        return \count($this->rows);
    }

    /** How many of this band's days stand under the number the station names. */
    public function shortDays(): int
    {
        $short = 0;
        foreach ($this->cover as $day) {
            if ($day->countsAsShort()) {
                ++$short;
            }
        }

        return $short;
    }

    /** The code the fold is keyed by; a station with none is keyed by its uuid. */
    public function foldKey(): string
    {
        return $this->stationCode ?? $this->stationUuid;
    }
}
