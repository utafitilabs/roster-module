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
 * ONE PERSON POSTED AT THE POST, ACROSS THE WEEK (#19; roster.html, RO·01):
 * their name as the design prints it (`J. Mollel`), what Team calls them,
 * whether they lead the post or are the one reading, and a list of marks per
 * day — a day holds any number of watches, so a day is a list.
 */
final readonly class MyRosterRow
{
    /** @param list<list<ShiftMark>> $days Monday first, seven of them */
    public function __construct(
        public string $personUuid,
        public string $name,
        public ?string $role,
        public bool $head,
        public bool $me,
        public array $days,
    ) {
    }
}
