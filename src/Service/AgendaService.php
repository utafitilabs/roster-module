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
use Uhifadhi\Roster\Model\AgendaFilter;
use Uhifadhi\Roster\Model\AgendaRow;
use Uhifadhi\Roster\Model\PostPresence;
use Uhifadhi\Roster\Model\RosteredPerson;
use Uhifadhi\Roster\Model\ShiftWindow;
use Uhifadhi\Roster\Model\TodayFigures;
use Uhifadhi\Roster\Repository\DutyRepository;
use Uhifadhi\Roster\Repository\ShiftRepository;

/**
 * THE AGENDA: which posts and which people the day is showing, and the five
 * figures over them.
 *
 * THE FILTER IS APPLIED TO THE READING, NOT TO THE QUERY. The area answers
 * the whole day in one read; narrowing it here rather than asking again is
 * what lets the option counts on the filter row be TRUE — an option that
 * says "1 unverified" because it counted the same answer the rows came from
 * can never disagree with the rows.
 *
 * THE FIGURES ARE OF THE WHOLE DAY, NOT OF THE FILTER. A strip that changed
 * when somebody narrowed to one post would be answering a different question
 * from the one it is labelled with, and "2 need an answer" would mean "2 of
 * the ones you are looking at" — which is how a dashboard comes to under-report
 * a problem to the person filtering for it.
 */
final readonly class AgendaService
{
    /** How far ahead the agenda's hole figure looks: today and tomorrow. */
    public const int HOLE_DAYS = 2;

    /** A state the filter offers that is not a DayState: the watch has not begun. */
    public const string DUE = 'due';

    public function __construct(
        private PresenceReader $presence,
        private WeekGridService $week,
        private ShiftRepository $shifts,
        private DutyRepository $duties,
        private StationRepository $stations,
    ) {
    }

    /**
     * EVERY POST ON THE BOOKS, with the people the filter leaves on them.
     *
     * A POST WITH NOBODY LEFT AFTER THE FILTER IS DROPPED, not drawn empty:
     * an agenda filtered to "unverified" that still listed six posts with
     * nothing under them would bury the one row somebody was looking for.
     * A post chosen BY the filter is kept whatever is under it, because
     * that is the post they asked to see.
     *
     * @param list<PostPresence>         $posts
     * @param array<string, ShiftWindow> $windows
     *
     * @return list<PostPresence>
     */
    public function narrow(array $posts, AgendaFilter $filter, ?\DateTimeImmutable $now = null, array $windows = []): array
    {
        if ($filter->isEverything()) {
            return $posts;
        }

        $now ??= new \DateTimeImmutable();
        $kept = [];

        foreach ($posts as $post) {
            if (null !== $filter->post && $post->stationUuid !== $filter->post) {
                continue;
            }

            $people = array_values(array_filter(
                $post->rostered,
                fn (RosteredPerson $person): bool => $this->matches($person, $post, $filter, $now, $windows),
            ));

            if ([] === $people && null === $filter->post) {
                continue;
            }

            $kept[] = new PostPresence(
                stationUuid: $post->stationUuid,
                stationName: $post->stationName,
                rostered: $people,
                expected: $post->expected,
                verified: $post->verified,
                flagged: $post->flagged,
                state: $post->state,
                silentFor: $post->silentFor,
                stationCode: $post->stationCode,
            );
        }

        return $kept;
    }

    /**
     * @param array<string, ShiftWindow> $windows
     */
    private function matches(RosteredPerson $person, PostPresence $post, AgendaFilter $filter, \DateTimeImmutable $now, array $windows): bool
    {
        if (null !== $filter->shift && $person->shiftKey !== $filter->shift) {
            return false;
        }

        if (null !== $filter->q) {
            $needle = mb_strtolower($filter->q);
            $hay = mb_strtolower($person->personName.' '.$post->stationName);
            if (!str_contains($hay, $needle)) {
                return false;
            }
        }

        if (null === $filter->state) {
            return true;
        }

        if (self::DUE === $filter->state) {
            return 0 === $person->watchCount() && !$this->hasBegun($person, $now, $windows);
        }

        return $person->state()->value === $filter->state;
    }

    /**
     * Whether this person's watch has started yet today.
     *
     * @param array<string, ShiftWindow> $windows
     */
    private function hasBegun(RosteredPerson $person, \DateTimeImmutable $now, array $windows): bool
    {
        $window = $windows[$person->shiftKey] ?? null;

        return null === $window || $window->startsAtMinuteOfDay() <= self::minuteOfDay($now);
    }

    /**
     * THE DAY AS THE REGISTER'S ROWS, in the posts' order: under each post a
     * row for every watch of every person rostered there (one for somebody
     * who reported nothing), then the hole its watch leaves, or the one row
     * that says the post asks for nobody. Flat, so the foot can page them.
     *
     * @param list<PostPresence> $posts
     *
     * @return list<AgendaRow>
     */
    public static function rows(array $posts): array
    {
        $rows = [];
        foreach ($posts as $post) {
            foreach ($post->rostered as $person) {
                $watches = $person->watches();
                if ([] === $watches) {
                    $rows[] = new AgendaRow(AgendaRow::UNREPORTED, $post, $person);
                }
                foreach ($watches as $i => $watch) {
                    $rows[] = new AgendaRow(AgendaRow::WATCH, $post, $person, $watch, $i + 1);
                }
            }
            if ($post->shortfall() > 0) {
                $rows[] = new AgendaRow(AgendaRow::HOLE, $post);
            } elseif ([] === $post->rostered) {
                $rows[] = new AgendaRow(AgendaRow::NOBODY, $post);
            }
        }

        return $rows;
    }

    /**
     * THE FIVE FIGURES THE AGENDA LEADS WITH, over the WHOLE day.
     *
     * @param list<PostPresence> $posts the unnarrowed reading
     */
    public function figuresFor(AreaOfInterest $area, array $posts, \DateTimeImmutable $day, ?\DateTimeImmutable $now = null): TodayFigures
    {
        $now ??= new \DateTimeImmutable();
        $windows = $this->shifts->windowsFor($area);
        $minute = self::minuteOfDay($now);
        $isToday = $day->format('Y-m-d') === $now->format('Y-m-d');

        $onTheWatch = 0;
        $dueLater = 0;

        foreach ($posts as $post) {
            foreach ($post->rostered as $person) {
                $window = $windows[$person->shiftKey] ?? null;
                if (null === $window) {
                    continue;
                }

                // A DAY THAT IS NOT TODAY HAS NO "NOW" IN IT. Every watch on
                // it is simply rostered, and calling one of them current
                // because the clock happens to be inside its hours would be
                // a claim about a day nobody is standing.
                if (!$isToday) {
                    ++$dueLater;

                    continue;
                }

                if (self::contains($window, $minute)) {
                    ++$onTheWatch;

                    continue;
                }

                if ($window->startsAtMinuteOfDay() > $minute) {
                    ++$dueLater;
                }
            }
        }

        // ASKED ONCE. The same window answers the day's fold and the
        // agenda's own hole figure, and two reads of one question are two
        // chances for one card to disagree with the card beside it.
        $holes = \count($this->week->gaps($area, $day, $day->modify(\sprintf('+%d days', self::HOLE_DAYS - 1))));

        return new TodayFigures(
            day: RosterFiguresService::fold($posts, $holes, $this->stations->countByArea($area)),
            onTheWatchNow: $onTheWatch,
            dueLaterToday: $dueLater,
            onTheWatchTomorrow: \count($this->duties->findByAreaOnDay($area, $day->modify('+1 day'))),
            holesNextTwoDays: $holes,
        );
    }

    /**
     * TOMORROW'S AGENDA — the plan only. Nothing has been reported for a day
     * that has not happened, so the card draws watches and never a state.
     *
     * @return list<PostPresence>
     */
    public function tomorrow(AreaOfInterest $area, \DateTimeImmutable $day): array
    {
        return $this->presence->postsOn($area, $day->modify('+1 day'));
    }

    /** Whether a minute of the day falls inside a window, midnight included. */
    private static function contains(ShiftWindow $window, int $minute): bool
    {
        $from = $window->startsAtMinuteOfDay();
        $to = $window->endsAtMinuteFromItsDay();

        return $window->crossesMidnight()
            // The evening head today, or the morning tail of last night's.
            ? $minute >= $from || $minute < $to - (24 * 60)
            : $minute >= $from && $minute < $to;
    }

    private static function minuteOfDay(\DateTimeImmutable $at): int
    {
        return ((int) $at->format('G') * 60) + (int) $at->format('i');
    }
}
