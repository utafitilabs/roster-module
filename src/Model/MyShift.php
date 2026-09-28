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
 * ONE WATCH OF A PERSON'S STILL TO COME (#19): the instant it begins and the
 * mark it wears — what "Next" and "My next shifts" list.
 */
final readonly class MyShift
{
    public function __construct(
        public \DateTimeImmutable $startsAt,
        public ShiftMark $mark,
    ) {
    }
}
