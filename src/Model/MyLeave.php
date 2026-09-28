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

use Uhifadhi\Roster\Entity\Absence;

/**
 * A PERSON'S ABSENCES THIS YEAR (#19; the design's ME·13 My leave), and how
 * many days of the year they cover.
 *
 * NO ALLOWANCE AND NO APPROVAL. This module records an absence because the
 * hole it makes is the roster's to show; how many days somebody is entitled
 * to, and whether a request was approved, belong to Team, which owns the
 * person's employment — so neither is here, and the card says so rather
 * than printing a number nobody keeps.
 */
final readonly class MyLeave
{
    /** @param list<Absence> $absences oldest first */
    public function __construct(
        public int $year,
        public array $absences,
        /** The days of this year the absences cover, each counted once. */
        public int $daysAway,
    ) {
    }
}
