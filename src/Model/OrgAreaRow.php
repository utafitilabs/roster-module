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
 * ONE AREA'S DAY, AS THE ORGANIZATION READS IT — a band, not a card.
 *
 * WHICH AREA IS A COLUMN AT THIS SCOPE, and it is the only thing the wider
 * reading adds: every figure here is the area's own, from the same service
 * the area's own page calls. Nothing is computed twice.
 *
 * THE POSITION IS NOT A COLOUR. It is the area's place in the declared
 * order, which the SHELL turns into a swatch through `data-cat`. No area
 * owns a hue and this module names none.
 */
final readonly class OrgAreaRow
{
    public function __construct(
        public string $uuid,
        public string $name,
        /** 1-based place in the declared order — the house's categorical set. */
        public int $position,
        public int $rangers,
        public int $postsReporting,
        public int $postsOnTheBooks,
        public int $checkedIn,
        public int $expected,
        public int $needingADecision,
        /** Where this area's own roster lives, or null where it is not mounted. */
        public ?string $url = null,
    ) {
    }
}
