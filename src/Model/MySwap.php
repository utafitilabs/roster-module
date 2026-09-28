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

use Uhifadhi\Roster\Enum\SwapState;

/**
 * ONE OFFER A PERSON IS PARTY TO (#19; roster.html, RO·03): the watch it is
 * about, who is on the other side of it, and where it stands.
 *
 * WAITING FOR IS THE PERSON WHO ANSWERS, and in this module that is the one
 * asked — nobody approves a swap but the person taking the watch. Null once
 * it has been answered.
 */
final readonly class MySwap
{
    public function __construct(
        public \DateTimeImmutable $day,
        public string $shiftLabel,
        /** The other person, as the design prints a name. */
        public string $other,
        public SwapState $state,
        public ?string $waitingFor,
    ) {
    }
}
