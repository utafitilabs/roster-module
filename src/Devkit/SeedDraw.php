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

/**
 * THE SEED'S VARIETY, DRAWN THE SAME WAY EVERY TIME.
 *
 * A seed needs a roster that looks lived in — this post manned round the
 * clock and that one only by day, this watch quietly worked and that one
 * claimed from seven kilometres away. Picking those with `rand()` would
 * make every run a different product: a screenshot would not reproduce, a
 * test that passed would fail on the next seed, and a bug somebody saw
 * once could not be got back.
 *
 * So the variety is a FUNCTION OF THE THING IT DESCRIBES. The same duty
 * always draws the same day, because the draw is a hash of its identity
 * and nothing else — no clock, no counter, no global state. That is also
 * what makes the seeder idempotent in the only sense that matters: a
 * second run recomputes the same answers rather than inventing new ones.
 *
 * IT IS NOT A RANDOM NUMBER GENERATOR and must never be used as one. The
 * output is stable across runs, machines and PHP versions because it is
 * built on `crc32`, which is specified; a hash that varied by platform
 * would seed one park on a laptop and a different one in CI.
 */
final readonly class SeedDraw
{
    private function __construct(private int $seed)
    {
    }

    /** A draw fixed by whatever names this thing — a duty's uuid, a post's code. */
    public static function of(string ...$parts): self
    {
        return new self(crc32(implode('/', $parts)));
    }

    /** A whole number in [0, $bound), stable for this draw. */
    public function upTo(int $bound): int
    {
        if ($bound < 1) {
            throw new \InvalidArgumentException('A draw needs at least one thing to choose between.');
        }

        return $this->seed % $bound;
    }

    /**
     * TRUE ROUGHLY ONE TIME IN $inEvery — the shape most of the seed's
     * variety takes: "about one watch in nine is claimed from outside the
     * ring". Written as a frequency rather than a percentage because that
     * is how the cases were described, and a reader can count them.
     */
    public function oneIn(int $inEvery): bool
    {
        return 0 === $this->upTo(max(1, $inEvery));
    }

    /**
     * One of the given things. The list is the vocabulary; which one comes
     * back is fixed by the draw.
     *
     * @template T
     *
     * @param non-empty-list<T> $choices
     *
     * @return T
     */
    public function oneOf(array $choices): mixed
    {
        return $choices[$this->upTo(\count($choices))];
    }

    /** A whole number in [$low, $high], both ends included. */
    public function between(int $low, int $high): int
    {
        return $low + $this->upTo(max(1, $high - $low + 1));
    }

    /** A further draw from this one, so a duty's watch can differ from its day. */
    public function then(string $part): self
    {
        return new self(crc32($part.'/'.$this->seed));
    }
}
