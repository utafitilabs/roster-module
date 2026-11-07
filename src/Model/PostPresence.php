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
 * ONE POST ON ONE DAY — who was rostered, how each of their days reads, and
 * the post's own state derived from the two.
 *
 * THE JOIN NEITHER SIDE CAN MAKE ALONE, and that is the whole reason this
 * object exists. The AREA knows who reported and what their positions say;
 * it does not know who was SUPPOSED to be on, and says so. This module knows
 * who was due and nothing about whether they came. The absence of a reported
 * day against a rostered watch is the "no check-in" a board draws, and only
 * a caller holding both can see it.
 *
 * THE STATE IS DERIVED HERE AND STORED NOWHERE, like everything else in this
 * chain: there is no offline flag to be left set after a handset comes back,
 * and no nightly job whose failure would freeze an area in yesterday's truth.
 */
final readonly class PostPresence
{
    /**
     * @param list<RosteredPerson> $rostered  everybody due at this post today, with how their day reads
     * @param int                  $expected  how many the post's watch asks for
     * @param int                  $verified  how many are at post with a position that bears it out
     * @param int                  $flagged   how many claimed the post and are unverified
     * @param int                  $silentFor minutes since the newest evidence from anybody here, or null if never
     */
    public function __construct(
        public string $stationUuid,
        public string $stationName,
        public array $rostered,
        public int $expected,
        public int $verified,
        public int $flagged,
        public PostState $state,
        public ?int $silentFor = null,
        /** The station's call sign, the area's; null where the area gave it none. */
        public ?string $stationCode = null,
    ) {
    }

    /**
     * HOW MANY WATCHES ARE UNFILLED — the shortfall between what the post
     * asks for and who is on its books today. Never negative: a post with
     * more people on it than it asked for is covered, not over-staffed by a
     * negative hole.
     */
    public function shortfall(): int
    {
        return max(0, $this->expected - \count($this->rostered));
    }
}
