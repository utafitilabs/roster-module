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
use Uhifadhi\Contracts\Area\LivePositionsInterface;
use Uhifadhi\Roster\Model\RosterDashboard;
use Uhifadhi\Roster\Repository\AbsenceRepository;
use Uhifadhi\Roster\Repository\DutyRepository;
use Uhifadhi\Roster\Repository\RotationRepository;
use Uhifadhi\Roster\Repository\ShiftRepository;

/**
 * THE ONE READ BEHIND THE WHOLE SURFACE.
 *
 * BOTH SCREENS BUILD THE SAME CONTEXT. The dashboard composes four widgets
 * and the library renders all sixteen, but they hand their partials the same
 * object — that is what makes the library's preview the widget rather than a
 * picture of one, and it is a promise the shell's component relies on.
 *
 * THE FORTNIGHT, NOT THE WEEK, because the week grid and the swap register
 * both read it and the planner's own window is fourteen days.
 *
 * NOTHING HERE DERIVES PRESENCE. Every state on this page came out of the
 * AREA through {@see PresenceReader}; this only asks, folds and orders.
 */
final readonly class RosterDashboardService
{
    /** How many people the load card ranks. A card never grows with its data. */
    private const int LOAD_SHOWN = 8;

    public function __construct(
        private PresenceReader $presence,
        private AgendaService $agenda,
        private WeekGridService $week,
        private DayBoardService $board,
        private DutyRepository $duties,
        private RotationRepository $rotations,
        private AbsenceRepository $absences,
        private ShiftRepository $shifts,
        private RosterLiveService $liveService,
        private LivePositionsInterface $positions,
    ) {
    }

    public function build(AreaOfInterest $area, \DateTimeImmutable $day, ?\DateTimeImmutable $now = null): RosterDashboard
    {
        $now ??= new \DateTimeImmutable();
        $from = RotaService::start($day);
        $through = $from->modify(\sprintf('+%d days', RotaService::DAYS - 1));

        $posts = $this->presence->postsOn($area, $day, $now);
        $gaps = $this->week->gaps($area, $from, $through);

        // WHERE EVERYBODY IS, ONCE, FOR THE WHOLE SURFACE. The plate widget
        // draws the real map on the dashboard and in the library alike —
        // the preview IS the widget — so the live answer is part of the one
        // context both build from rather than a read the map card makes for
        // itself. The instant is the one every other figure on the page is
        // answering, so the caption under the plate cannot disagree with
        // the strip above it.
        $live = $this->positions->liveIn((string) $area->getUuidString(), $now);

        $shiftLabels = [];
        foreach ($this->shifts->findByArea($area) as $shift) {
            $shiftLabels[$shift->getKey()] = $shift->getLabel();
        }

        return new RosterDashboard(
            day: $day,
            now: $now,
            figures: $this->agenda->figuresFor($area, $posts, $day, $now),
            posts: $posts,
            decisions: RosterFiguresService::decisions($posts, $gaps),
            blocks: $this->board->blocksFor($area, $day),
            weekRows: $this->week->rows($area, $from, $through),
            weekDays: $this->week->days($from, $through),
            gaps: $gaps,
            rotations: $this->rotations->findActiveByArea($area),
            absences: $this->absences->findOverlapping($area, $this->monthStart($day), $this->monthEnd($day)),
            load: $this->loadThisMonth($area, $day),
            checkIns: self::checkInsNewestFirst($posts),
            shiftLabels: $shiftLabels,
            live: $this->liveService->figures($live, $posts),
        );
    }

    /**
     * SHIFTS AND NIGHTS PER PERSON THIS MONTH — who is carrying the rota,
     * not who appears most often. Nights are counted separately because a
     * month of nights and a month of days are not the same month.
     *
     * @return list<array{name: string, watches: int, nights: int}>
     */
    private function loadThisMonth(AreaOfInterest $area, \DateTimeImmutable $day): array
    {
        $nightKeys = [];
        foreach ($this->shifts->windowsFor($area) as $key => $window) {
            if ($window->crossesMidnight()) {
                $nightKeys[$key] = true;
            }
        }

        $tally = [];
        foreach ($this->duties->findByAreaBetween($area, $this->monthStart($day), $this->monthEnd($day)) as $duty) {
            $name = $duty->getPerson()->getFullName();
            $tally[$name] ??= ['name' => $name, 'watches' => 0, 'nights' => 0];
            ++$tally[$name]['watches'];

            if (isset($nightKeys[$duty->getShiftKey()])) {
                ++$tally[$name]['nights'];
            }
        }

        $ranked = array_values($tally);
        usort($ranked, static fn (array $a, array $b): int => [$b['nights'], $b['watches']] <=> [$a['nights'], $a['watches']]);

        return \array_slice($ranked, 0, self::LOAD_SHOWN);
    }

    /**
     * THE DAY'S CHECK-INS AS A FEED, newest first — the same watches the
     * agenda draws under their posts, flattened and re-ordered by when they
     * were claimed. One reading, two shapes.
     *
     * @param list<\Uhifadhi\Roster\Model\PostPresence> $posts
     *
     * @return list<array{person: string, station: string|null, shift: string, watch: \Uhifadhi\Contracts\Area\PersonWatch}>
     */
    private static function checkInsNewestFirst(array $posts): array
    {
        $feed = [];
        foreach ($posts as $post) {
            foreach ($post->rostered as $person) {
                foreach ($person->watches() as $watch) {
                    $feed[] = [
                        'person' => $person->personName,
                        'station' => $watch->stationName ?? $post->stationName,
                        'shift' => $person->shiftLabel,
                        'watch' => $watch,
                    ];
                }
            }
        }

        usort($feed, static fn (array $a, array $b): int => ($b['watch']->occurredAt?->getTimestamp() ?? 0) <=> ($a['watch']->occurredAt?->getTimestamp() ?? 0));

        return $feed;
    }

    private function monthStart(\DateTimeImmutable $day): \DateTimeImmutable
    {
        return $day->modify('first day of this month')->setTime(0, 0);
    }

    private function monthEnd(\DateTimeImmutable $day): \DateTimeImmutable
    {
        return $day->modify('last day of this month')->setTime(0, 0);
    }
}
