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

namespace Uhifadhi\Roster\Service;

use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Repository\StationRepository;
use Uhifadhi\Contracts\Area\DayState;
use Uhifadhi\Roster\Model\DayFigures;
use Uhifadhi\Roster\Model\Decision;
use Uhifadhi\Roster\Model\PostPresence;
use Uhifadhi\Roster\Model\PostState;
use Uhifadhi\Roster\Model\RosteredPerson;

/**
 * THE DAY'S FIGURES, FOLDED ONCE.
 *
 * THE FOLD IS THE ONLY THING HERE. Every input is somebody else's answer —
 * the plan is this module's, the readings are the AREA's through
 * {@see PresenceReader}, the holes are the week grid's, the area's station
 * count is the registry's — and this class counts them. It derives no state,
 * because a figure that re-derived "verified" would be a second opinion on
 * the one question the module is ruled never to answer for itself.
 *
 * ONE READ, NOT FIVE. Five cards asking the presence seam separately is five
 * chances for one screen's numbers to disagree with each other, which is the
 * particular way a dashboard loses its reader for good.
 *
 * COUNTED OVER THE POSTS ON THIS MODULE'S BOOKS, and the area's own total is
 * carried beside them rather than folded in: "6 of the area's 12" is two
 * facts, and the second one belongs to whoever owns the registry.
 */
final readonly class RosterFiguresService
{
    /**
     * HOW FAR AHEAD A HOLE COUNTS AS NEEDING A DECISION. A watch nobody is
     * on next month is a planning matter; one inside the week is somebody's
     * evening.
     */
    public const int HOLE_HORIZON_DAYS = 7;

    public function __construct(
        private PresenceReader $presence,
        private WeekGridService $week,
        private StationRepository $stations,
    ) {
    }

    public function forDay(AreaOfInterest $area, \DateTimeImmutable $day, ?\DateTimeImmutable $now = null): DayFigures
    {
        return self::fold(
            $this->presence->postsOn($area, $day, $now),
            \count($this->week->gaps($area, $day, $day->modify(\sprintf('+%d days', self::HOLE_HORIZON_DAYS - 1)))),
            $this->stations->countByArea($area),
        );
    }

    /**
     * THE SAME DAY, ONE SCOPE WIDER — the organization, or one area.
     *
     * IT IS THE AREA QUERY WITH THE AREA FILTER WIDENED, and that is the
     * whole of it: this walks the areas the scope names and folds what
     * `forDay()` already answers for each. A second aggregate written
     * beside it — one SQL for the area page and another for the
     * organization — would be two numbers for one question, and the day
     * they disagreed nobody could say which was right.
     *
     * ONE AREA IS NOT A SPECIAL CASE. A scope naming a single area calls
     * `forDay()` on it and nothing else happens, so the narrowed org page
     * and that area's own page cannot differ: they are the same call.
     *
     * @param list<AreaOfInterest> $areas every area the scope reaches, already narrowed to what the reader may open
     */
    public function forScope(array $areas, \DateTimeImmutable $day, ?\DateTimeImmutable $now = null): DayFigures
    {
        $folded = null;
        foreach ($areas as $area) {
            $figures = $this->forDay($area, $day, $now);
            $folded = null === $folded ? $figures : self::add($folded, $figures);
        }

        // AN ORGANIZATION WITH NO AREAS IS A REAL STATE — a fresh
        // installation — and it reads as noughts rather than as an error.
        return $folded ?? self::fold([], 0, 0);
    }

    /**
     * TWO DAYS' FIGURES, ADDED. Every field here is a COUNT of things in
     * one area, so adding them is the same reading over more ground; there
     * is no ratio or average in {@see DayFigures} that would be wrong to
     * sum, and a field that grew one would have to be folded rather than
     * added.
     */
    private static function add(DayFigures $a, DayFigures $b): DayFigures
    {
        return new DayFigures(
            expected: $a->expected + $b->expected,
            checkedIn: $a->checkedIn + $b->checkedIn,
            verified: $a->verified + $b->verified,
            flagged: $a->flagged + $b->flagged,
            noCheckIn: $a->noCheckIn + $b->noCheckIn,
            postsReporting: $a->postsReporting + $b->postsReporting,
            postsOnTheBooks: $a->postsOnTheBooks + $b->postsOnTheBooks,
            postsInTheArea: $a->postsInTheArea + $b->postsInTheArea,
            holes: $a->holes + $b->holes,
        );
    }

    /**
     * WHAT NEEDS SOMEBODY TO ACT, as rows — the WHICH behind the figures'
     * HOW MANY, in the order the design reads them: the people first,
     * because a person is answerable now, and the holes after, because a
     * hole is answered by changing the plan.
     *
     * @param list<PostPresence>                                                                                                                    $posts
     * @param list<array{station: string, code: string|null, day: \DateTimeImmutable, shiftKey: string, label: string, filled: int, expected: int}> $gaps
     *
     * @return list<Decision>
     */
    public static function decisions(array $posts, array $gaps): array
    {
        $rows = [];

        foreach ($posts as $post) {
            foreach ($post->rostered as $person) {
                $where = $post->stationName.' · '.mb_strtolower($person->shiftLabel);

                if (0 === $person->watchCount()) {
                    $rows[] = Decision::missing($person->personName, $where, 'due, and the area has read nothing at all');

                    continue;
                }

                if ($person->isFlagged()) {
                    $rows[] = Decision::flagged($person->personName, $where, self::whyFlagged($person));
                }
            }
        }

        foreach ($gaps as $gap) {
            $rows[] = Decision::hole(
                mb_strtolower($gap['label']).' watch',
                $gap['station'].' · '.$gap['day']->format('D j M'),
                \sprintf('the pattern asked for %d and %d %s on it', $gap['expected'], $gap['filled'], 1 === $gap['filled'] ? 'is' : 'are'),
            );
        }

        return $rows;
    }

    /**
     * WHY A CLAIM IS FLAGGED, in the area's own words. No fix, no ring and
     * outside the ring are three different facts and the row says which:
     * "unverified" alone is an accusation with no content.
     */
    private static function whyFlagged(RosteredPerson $person): string
    {
        foreach ($person->watches() as $watch) {
            if (DayState::AtPostUnverified !== $watch->state) {
                continue;
            }

            $reason = $watch->unverifiedReason?->label();
            $distance = $watch->distanceM;

            return match (true) {
                null !== $distance && null !== $reason => \sprintf('%s — the nearest ping is %s away', $reason, self::metres($distance)),
                null !== $reason => $reason,
                default => 'the pings do not bear the claim out',
            };
        }

        return 'the pings do not bear the claim out';
    }

    /** A distance a person reads rather than a float. */
    private static function metres(float $metres): string
    {
        return $metres >= 1000.0
            ? \sprintf('%.1f km', $metres / 1000.0)
            : \sprintf('%d m', (int) round($metres));
    }

    /**
     * THE FOLD ITSELF, over answers already fetched — STATIC and pure so the
     * counting is reachable with nothing behind it, and so a surface that
     * has already read the posts for its own rows does not read them twice.
     *
     * Static because it holds no state and depends on nothing: a unit test
     * that had to stand a presence reader up to reach arithmetic would be
     * testing the seam again and saying nothing about the sums.
     *
     * @param list<PostPresence> $posts
     */
    public static function fold(array $posts, int $holes, int $postsInTheArea): DayFigures
    {
        $expected = 0;
        $checkedIn = 0;
        $verified = 0;
        $flagged = 0;
        $noCheckIn = 0;
        $reporting = 0;

        foreach ($posts as $post) {
            // THE POST'S OWN EXPECTATION, not the length of its rostered
            // list: a watch that asks for two and has one rostered is short
            // by one, and counting the list would hide exactly that.
            $expected += $post->expected;
            $verified += $post->verified;
            $flagged += $post->flagged;

            if (PostState::Reporting === $post->state) {
                ++$reporting;
            }

            foreach ($post->rostered as $person) {
                if ($person->watchCount() > 0) {
                    ++$checkedIn;

                    continue;
                }

                // NOTHING ARRIVED. Not "absent" — the area returned no
                // reading for them, and the card says which of the two it
                // is by never saying the other.
                ++$noCheckIn;
            }
        }

        return new DayFigures(
            expected: $expected,
            checkedIn: $checkedIn,
            verified: $verified,
            flagged: $flagged,
            noCheckIn: $noCheckIn,
            postsReporting: $reporting,
            postsOnTheBooks: \count($posts),
            postsInTheArea: $postsInTheArea,
            holes: $holes,
        );
    }
}
