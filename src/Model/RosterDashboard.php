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
use Uhifadhi\Roster\Entity\Rotation;

/**
 * EVERYTHING EVERY WIDGET ON THIS SURFACE READS, built once.
 *
 * THE PICTURE OF A WIDGET IS THE WIDGET. The library renders each partial on
 * REAL data at full size, from the SAME context the dashboard hands them, so
 * what somebody arranges is exactly what they get. That only holds if there
 * is one context — two would eventually disagree, and the disagreement would
 * show up as a preview that looked nothing like the card it added.
 *
 * ONE BUILD, NOT SIXTEEN. Sixteen widgets each asking the presence seam for
 * the same day is sixteen chances for one screen to contradict itself, and
 * on the library page it is sixteen renders of sixteen queries.
 *
 * IT HOLDS ANSWERS, NOT SERVICES. A widget partial receives facts and does
 * no work; anything a card needs that is not here is a fact nobody has
 * measured yet, and the card says so rather than computing it in Twig.
 */
final readonly class RosterDashboard
{
    /**
     * @param list<PostPresence>                                                                                                                    $posts       every post on the books, today
     * @param list<Decision>                                                                                                                        $decisions   what needs somebody to act
     * @param array<string, list<BoardBlock>>                                                                                                       $blocks      the day as a wall, by station uuid
     * @param list<WeekRow>                                                                                                                         $weekRows    the fortnight, posts down
     * @param list<\DateTimeImmutable>                                                                                                              $weekDays    the fortnight's own days
     * @param list<array{station: string, code: string|null, day: \DateTimeImmutable, shiftKey: string, label: string, filled: int, expected: int}> $gaps        every unfilled watch in the window
     * @param list<Rotation>                                                                                                                        $rotations   the standing patterns
     * @param list<Absence>                                                                                                                         $absences    who is away this month
     * @param list<array{name: string, watches: int, nights: int}>                                                                                  $load        shifts and nights per person, this month
     * @param list<array{person: string, station: string|null, shift: string, watch: \Uhifadhi\Contracts\Area\PersonWatch}>                         $checkIns    the day's check-ins, newest first
     * @param array<string, string>                                                                                                                 $shiftLabels the area's own shift vocabulary
     */
    public function __construct(
        public \DateTimeImmutable $day,
        public \DateTimeImmutable $now,
        public TodayFigures $figures,
        public array $posts,
        public array $decisions,
        public array $blocks,
        public array $weekRows,
        public array $weekDays,
        public array $gaps,
        public array $rotations,
        public array $absences,
        public array $load,
        public array $checkIns,
        public array $shiftLabels,
        /** What is true this minute: the figures the Live tab's plate is captioned with. */
        public LiveFigures $live,
    ) {
    }

    /**
     * THE POSTS THAT ARE NOT TALKING TO US — late or offline, worst first.
     * The one reading in which an offline post cannot be missed.
     *
     * @return list<PostPresence>
     */
    public function quiet(): array
    {
        $quiet = array_values(array_filter(
            $this->posts,
            static fn (PostPresence $post): bool => PostState::Late === $post->state || PostState::Offline === $post->state,
        ));

        usort($quiet, static fn (PostPresence $a, PostPresence $b): int => ($b->silentFor ?? 0) <=> ($a->silentFor ?? 0));

        return $quiet;
    }

    /**
     * WHO IS ON A WATCH THIS MINUTE. Not who checked in — a clock question,
     * asked of the plan, and answered the same way for somebody whose phone
     * is flat as for somebody whose phone is reporting.
     *
     * @return list<array{person: RosteredPerson, post: PostPresence}>
     */
    public function onTheWatch(): array
    {
        $out = [];
        foreach ($this->posts as $post) {
            foreach ($post->rostered as $person) {
                if ($person->watchCount() > 0 || $person->isStillOut()) {
                    $out[] = ['person' => $person, 'post' => $post];
                }
            }
        }

        return $out;
    }
}
