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
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Bundle\AreaBundle\Repository\PostingRepository;
use Uhifadhi\Bundle\AreaBundle\Repository\StationRepository;
use Uhifadhi\Roster\Entity\StationWatch;
use Uhifadhi\Roster\Model\Sheet;
use Uhifadhi\Roster\Model\SheetBand;
use Uhifadhi\Roster\Model\SheetCell;
use Uhifadhi\Roster\Model\SheetCellKind;
use Uhifadhi\Roster\Model\SheetCover;
use Uhifadhi\Roster\Model\SheetCoverState;
use Uhifadhi\Roster\Model\SheetRow;
use Uhifadhi\Roster\Model\SheetWindow;
use Uhifadhi\Roster\Repository\DutyRepository;
use Uhifadhi\Roster\Repository\EditedDayRepository;
use Uhifadhi\Roster\Repository\StationWatchRepository;

/**
 * THE PLANNING SHEET, READ — people down, days across, per station.
 *
 * FOUR QUERIES FOR THE WHOLE WINDOW, whatever its size. Thirty-four
 * rangers over twenty-eight days is 952 cells; asking anything per cell
 * is how a planner's tab ends up slower than the month it plans. The
 * stations, the postings, the duties and the hand marks each come back
 * once and are indexed here.
 *
 * A GAP BELONGS TO THE STATION, NOT TO A RANGER. RULED 21 sep. A
 * ranger's cell is a shift or it is off, and that is the whole of it;
 * what can be SHORT is the place on the day, against the number the place
 * says it needs. So every band's head row carries one cover token per day
 * — how many of the needed places are covered, out of how many the
 * station asked for — and the alarm ink is spent once, where the decision
 * is actually made.
 *
 * AND A STATION THAT NAMES NO NUMBER EXPECTS NOTHING. No needs, no
 * expectation, no alarm ink — a fortnight of it at a station nobody has
 * made a decision about would be the sheet shouting at somebody who has
 * done nothing wrong. Null and zero are different facts: nothing asked
 * reads as a dash, never as 0/0.
 *
 * NOTHING HERE IS PRESENCE. The sheet says who is DUE; whether they came
 * is the area's reading and belongs on the tabs that ask that question.
 */
final readonly class SheetService
{
    public function __construct(
        private StationRepository $stations,
        private PostingRepository $postings,
        private StationWatchRepository $watches,
        private DutyRepository $duties,
        private EditedDayRepository $marks,
        private ShiftVocabularyService $shifts,
    ) {
    }

    /**
     * THE WHOLE SHEET FOR ONE WINDOW.
     *
     * NO STATION FILTER HERE, on purpose: narrowing is
     * {@see Sheet::only()}, applied to one read, because the head's chip
     * has to list every station whatever the sheet under it shows.
     */
    public function read(AreaOfInterest $area, SheetWindow $window): Sheet
    {
        $stations = $this->stations->findByArea($area);
        /*
         * BY CALL SIGN, which is how the sheet is read and how the
         * station filter lists them: ST-01 under ST-01. A station with
         * no code sorts last under its name rather than first under an
         * empty string, because an unlabelled place is not the first
         * place anybody looks.
         */
        usort($stations, static fn (Station $a, Station $b): int => [null === $a->getCode(), (string) $a->getCode(), (string) $a->getName()] <=> [null === $b->getCode(), (string) $b->getCode(), (string) $b->getName()]);
        $people = $this->peopleByStation($area);
        $watches = $this->watchesByStation($area);
        $duties = $this->dutiesByPersonAndDay($area, $window);
        $onStation = $this->dutiesByStationDayAndShift($area, $window);
        $marks = $this->marksByPersonAndDay($area, $window);
        $shifts = $this->shiftFacts($area);
        $days = $window->days();

        $bands = [];
        foreach ($stations as $station) {
            $uuid = (string) $station->getUuidString();
            $needs = isset($watches[$uuid]) ? $watches[$uuid]->getNeedsPerShift() : [];

            $rows = [];
            foreach ($people[$uuid] ?? [] as $seat => $person) {
                $cells = [];
                foreach ($days as $day) {
                    $cells[] = $this->cell(
                        $day,
                        $window,
                        $duties[$person['uuid']][$day->format('Y-m-d')] ?? null,
                        $marks[$person['uuid']][$day->format('Y-m-d')] ?? null,
                        $shifts,
                    );
                }

                $rows[] = new SheetRow(
                    personUuid: $person['uuid'],
                    personName: $person['name'],
                    stationCode: $station->getCode(),
                    seat: $seat,
                    cells: $cells,
                );
            }

            $cover = [];
            foreach ($days as $day) {
                $cover[] = $this->cover($day, $window, $needs, $onStation[$uuid][$day->format('Y-m-d')] ?? [], \count($people[$uuid] ?? []));
            }

            $bands[] = new SheetBand(
                stationUuid: $uuid,
                stationName: (string) $station->getName(),
                stationCode: $station->getCode(),
                rows: $rows,
                cover: $cover,
            );
        }

        return new Sheet($window, $bands, \count($stations), $this->duties->findLastDayByArea($area));
    }

    /**
     * ONE DAY OF ONE ROW — and there are only two answers.
     *
     * THE ORDER OF THE QUESTIONS IS THE RULING. A duty that stands is a
     * watch whatever else is true of the day; anything else is off, and
     * the hand mark only says that a person decided it rather than a
     * pattern. A ranger is never "unfilled": what is short is the
     * station, on the day, and {@see self::cover()} states it there.
     *
     * @param array{key: string, uuid: string}|null            $duty
     * @param array{leftOff: bool}|null                        $mark
     * @param array<string, array{label: string, colour: int}> $shifts
     */
    private function cell(
        \DateTimeImmutable $day,
        SheetWindow $window,
        ?array $duty,
        ?array $mark,
        array $shifts,
    ): SheetCell {
        $isToday = $day == $window->today;

        if (null !== $duty) {
            return new SheetCell(
                day: $day,
                kind: SheetCellKind::Watch,
                shiftKey: $duty['key'],
                shiftLabel: $shifts[$duty['key']]['label'] ?? $duty['key'],
                colour: $shifts[$duty['key']]['colour'] ?? null,
                dutyUuid: $duty['uuid'],
                editedByHand: null !== $mark,
                isToday: $isToday,
            );
        }

        return new SheetCell(
            day: $day,
            kind: SheetCellKind::Off,
            editedByHand: null !== $mark,
            isToday: $isToday,
        );
    }

    /**
     * ONE STATION'S COVER ON ONE DAY, counted per shift and stated as one
     * figure.
     *
     * PER SHIFT, BECAUSE THE SUM LIES. A station needing two on days and
     * two on nights, with five people on the day watch and nobody on the
     * night watch, has five people and is still short: only the covered
     * part of each shift counts, so that day reads 2/4 and wears the
     * alarm ink it has earned.
     *
     * A SHIFT THE STATION NAMES NO NUMBER FOR IS NOT AN EXPECTATION, so
     * people on it are neither counted nor missed.
     *
     * A STATION THAT NAMES NO NUMBER AT ALL IS COUNTED AGAINST THE RANGERS
     * STATIONED THERE — RULED 25 sep, after a station with four rangers on
     * the watch every day read a dash on every day. Every watch that
     * stands there counts; a day nobody stands wears the empty mark and
     * is never short. Nothing named and nobody stationed is a dash.
     *
     * @param array<string, int> $needs     how many this station needs, per shift key
     * @param array<string, int> $on        how many are actually on, per shift key
     * @param int                $stationed how many rangers are stationed there
     */
    private function cover(\DateTimeImmutable $day, SheetWindow $window, array $needs, array $on, int $stationed): SheetCover
    {
        $isToday = $day == $window->today;
        $wanted = 0;
        $covered = 0;

        foreach ($needs as $key => $count) {
            $count = max(0, $count);
            if (0 === $count) {
                continue;
            }

            $wanted += $count;
            $covered += min($count, max(0, $on[$key] ?? 0));
        }

        if (0 === $wanted) {
            if (0 === $stationed) {
                return new SheetCover($day, null, null, SheetCoverState::Nothing, $isToday);
            }

            $planned = array_sum(array_map(static fn (int $count): int => max(0, $count), $on));

            return new SheetCover(
                $day,
                $planned,
                $stationed,
                0 === $planned ? SheetCoverState::Nobody : SheetCoverState::Met,
                $isToday,
                againstStationed: true,
            );
        }

        $state = match (true) {
            $covered >= $wanted => SheetCoverState::Met,
            0 === $covered => SheetCoverState::Nobody,
            default => SheetCoverState::Short,
        };

        return new SheetCover($day, $covered, $wanted, $state, $isToday);
    }

    /**
     * THE RANGERS STATIONED AT EACH STATION, BY NAME — and the order is
     * the seat order, so it has to be stable. A seat that moved when
     * somebody was renamed would slide a whole station's ring.
     *
     * @return array<string, list<array{uuid: string, name: string}>>
     */
    private function peopleByStation(AreaOfInterest $area): array
    {
        $people = [];
        foreach ($this->postings->findStandingByArea($area) as $posting) {
            $station = $posting->getStation();
            $person = $posting->getPerson();

            if (null === $station || null === $person) {
                continue;
            }

            $people[(string) $station->getUuidString()][] = [
                'uuid' => (string) $person->getUuidString(),
                'name' => $person->getFullName(),
            ];
        }

        foreach ($people as $uuid => $atStation) {
            usort($atStation, static fn (array $a, array $b): int => [$a['name'], $a['uuid']] <=> [$b['name'], $b['uuid']]);
            $people[$uuid] = $atStation;
        }

        return $people;
    }

    /** @return array<string, StationWatch> by station uuid */
    private function watchesByStation(AreaOfInterest $area): array
    {
        $watches = [];
        foreach ($this->watches->findByArea($area) as $watch) {
            $watches[(string) $watch->getStation()->getUuidString()] = $watch;
        }

        return $watches;
    }

    /**
     * EVERY STANDING DUTY IN THE WINDOW, by ranger and day.
     *
     * ONE PER RANGER PER DAY on the sheet, and the last one read wins: a
     * cell is one bar, and a person on two watches in a day is a fact the
     * day board draws and this grid cannot.
     *
     * @return array<string, array<string, array{key: string, uuid: string}>>
     */
    private function dutiesByPersonAndDay(AreaOfInterest $area, SheetWindow $window): array
    {
        $duties = [];
        foreach ($this->duties->findByAreaBetween($area, $window->from, $window->through) as $duty) {
            if (!$duty->getState()->isStanding()) {
                continue;
            }

            $duties[(string) $duty->getPerson()->getUuidString()][$duty->getOnDay()->format('Y-m-d')] = [
                'key' => $duty->getShiftKey(),
                'uuid' => (string) $duty->getUuid(),
            ];
        }

        return $duties;
    }

    /**
     * EVERY STANDING DUTY IN THE WINDOW COUNTED BY STATION, DAY AND
     * SHIFT — the one read the cover token needs.
     *
     * IT COUNTS DUTIES AND NOT PEOPLE, deliberately: the station's
     * question is how many of its places are covered, and one person
     * cannot stand two of them.
     *
     * @return array<string, array<string, array<string, int>>> by station uuid, day and shift key
     */
    private function dutiesByStationDayAndShift(AreaOfInterest $area, SheetWindow $window): array
    {
        $on = [];
        foreach ($this->duties->findByAreaBetween($area, $window->from, $window->through) as $duty) {
            if (!$duty->getState()->isStanding()) {
                continue;
            }

            $station = (string) $duty->getStation()->getUuidString();
            $day = $duty->getOnDay()->format('Y-m-d');
            $key = $duty->getShiftKey();
            $on[$station][$day][$key] = ($on[$station][$day][$key] ?? 0) + 1;
        }

        return $on;
    }

    /**
     * EVERY HAND MARK IN THE WINDOW, by ranger and day. A station-wide
     * mark marks every ranger stationed there — a day nobody may touch is
     * a day every cell on it was decided by hand.
     *
     * @return array<string, array<string, array{leftOff: bool}>>
     */
    private function marksByPersonAndDay(AreaOfInterest $area, SheetWindow $window): array
    {
        $wholeStation = [];
        $marks = [];

        foreach ($this->marks->inWindow($area, $window->from, $window->through) as $mark) {
            $person = $mark->getPerson();
            $day = $mark->getOnDay()->format('Y-m-d');

            if (null === $person) {
                $wholeStation[(string) $mark->getStation()->getUuidString()][$day] = ['leftOff' => $mark->isLeftOff()];

                continue;
            }

            $marks[(string) $person->getUuidString()][$day] = ['leftOff' => $mark->isLeftOff()];
        }

        if ([] === $wholeStation) {
            return $marks;
        }

        foreach ($this->peopleByStation($area) as $stationUuid => $people) {
            foreach ($people as $person) {
                foreach ($wholeStation[$stationUuid] ?? [] as $day => $mark) {
                    $marks[$person['uuid']][$day] ??= $mark;
                }
            }
        }

        return $marks;
    }

    /**
     * THE AREA'S SHIFTS, AS THE CELL READS THEM — its own label, and the
     * palette slot it was given when it was created.
     *
     * THROUGH THE VOCABULARY AND NOT THE REPOSITORY, because the list is
     * SEEDED ON FIRST ASK. The sheet is the first thing an area opens, and
     * asking the repository read an empty list: every cell on that first
     * render fell back to `--fog` and the whole fortnight drew grey, then
     * came back in colour on the next request. A read that has to be the
     * second one is not a read.
     *
     * @return array<string, array{label: string, colour: int}>
     */
    private function shiftFacts(AreaOfInterest $area): array
    {
        $facts = [];
        foreach ($this->shifts->forArea($area) as $shift) {
            $facts[$shift->getKey()] = ['label' => $shift->getLabel(), 'colour' => $shift->getColour()];
        }

        return $facts;
    }
}
