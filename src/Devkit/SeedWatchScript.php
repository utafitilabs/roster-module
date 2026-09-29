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

namespace Uhifadhi\Roster\Devkit;

use Uhifadhi\Bundle\AreaBundle\Enum\CheckInStatusKind;

/**
 * WHAT ONE WATCH IS TOLD TO BE, where the seed is not leaving it to chance.
 *
 * A SEED THAT DRAWS ITS STATES CANNOT PROMISE THEM. The first cut asked a
 * stable hash "about one watch in nineteen is a special assignment", which
 * is a frequency and not a guarantee: the hash is seeded by each duty's
 * UUID, the UUIDs are new on every seed, and a month that happened to
 * contain no nineteenth watch shipped a park with a whole reading missing.
 * It failed in CI on a date nobody had run before, which is exactly how it
 * would have failed at a seed.
 *
 * SO THE READINGS THE SCREENS DRAW ARE ASSIGNED, one to a real watch, before
 * the draw gets a say. What is left over is still drawn — variety is the
 * point of the rest of the month — but the set of states the product has to
 * be able to render is now a fact about the seeder rather than a hope about
 * its distribution.
 */
final readonly class SeedWatchScript
{
    private function __construct(
        /** What the claim says, or null where the watch is not reported at all. */
        public ?CheckInStatusKind $kind,
        /** Whether anybody closed it. */
        public bool $closed,
        /** Whether the day carries a second watch after this one. */
        public bool $second,
        /**
         * WHETHER THIS WATCH IS STANDING AWAY FROM ITS POST. The AREA
         * derives "at post, unverified" from the distance between the fix
         * and the post, so a seed cannot claim that state — it can only
         * stand somebody far enough away and let the area say so.
         */
        public bool $away = false,
        /**
         * WHETHER THE HANDSET HAS GONE QUIET. Its pings stop early, so the
         * last one is hours old and the live plate draws it stale: the one
         * state on that plate that means "where they WERE".
         */
        public bool $quiet = false,
    ) {
    }

    /** A watch worked and closed, saying this. */
    public static function reporting(CheckInStatusKind $kind): self
    {
        return new self($kind, true, false);
    }

    /** A watch nobody closed — the board's own "still out" on a past day. */
    public static function neverClosed(): self
    {
        return new self(CheckInStatusKind::AtPost, false, false);
    }

    /** Due, and nothing arrived. */
    public static function unreported(): self
    {
        return new self(null, false, false);
    }

    /** A morning at the post and an afternoon somewhere else. */
    public static function twoWatches(): self
    {
        return new self(CheckInStatusKind::AtPost, true, true);
    }

    /** At the post by their own account, and standing nowhere near it. */
    public static function awayFromThePost(): self
    {
        return new self(CheckInStatusKind::AtPost, true, false, away: true);
    }

    /** At the post, and the handset stopped talking hours ago. */
    public static function goneQuiet(): self
    {
        return new self(CheckInStatusKind::AtPost, true, false, quiet: true);
    }

    public function isReported(): bool
    {
        return null !== $this->kind;
    }

    /**
     * WHAT THE CLAIM SAYS. Asked only of a script that reports — an
     * unreported watch is returned from before anything is claimed, and a
     * caller that got here with one has lost track of which it is holding.
     */
    public function claims(): CheckInStatusKind
    {
        return $this->kind ?? throw new \LogicException('An unreported watch makes no claim; check isReported() first.');
    }
}
