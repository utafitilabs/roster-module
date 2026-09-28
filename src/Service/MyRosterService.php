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
use Uhifadhi\Bundle\RegistryBundle\Service\AreaModuleService;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Contracts\Entity\UserInterface;
use Uhifadhi\Roster\Entity\Absence;
use Uhifadhi\Roster\Entity\Duty;
use Uhifadhi\Roster\Entity\Shift;
use Uhifadhi\Roster\Entity\StationWatch;
use Uhifadhi\Roster\Model\MyLeave;
use Uhifadhi\Roster\Model\MyRoster;
use Uhifadhi\Roster\Model\MyRosterRow;
use Uhifadhi\Roster\Model\MyShift;
use Uhifadhi\Roster\Model\MySwap;
use Uhifadhi\Roster\Model\ShiftMark;
use Uhifadhi\Roster\Module\RosterModuleProvider;
use Uhifadhi\Roster\Repository\AbsenceRepository;
use Uhifadhi\Roster\Repository\DutyRepository;
use Uhifadhi\Roster\Repository\ShiftRepository;
use Uhifadhi\Roster\Repository\StationWatchRepository;
use Uhifadhi\Roster\Repository\SwapRepository;

/**
 * A PERSON'S OWN ROSTER, READ ONCE (#19; design variants-my-dashboard,
 * approved 28 Sep 2026): the week at the post they are posted at — their
 * marks, everybody posted there, what the post expects, their next watches,
 * their swaps, who is away — and their own absences this year.
 *
 * ONE READ FOR THE CARD AND THE PAGE, so the dashboard's week and the page's
 * table can never disagree about the same day.
 *
 * ONLY THE POST THEY ARE POSTED AT. Nothing here reads another post's people
 * or another person's leave beyond that post's, which is what lets the page
 * name no permission: it shows a person their own post and nothing wider.
 *
 * A STANDING WATCH IS ONE NOT CALLED OFF — the handset's own reading
 * ({@see \Uhifadhi\Roster\Module\RosterWatches}), so the phone and the page
 * list the same watches. A watch on a day the person is away is a hole, not
 * a watch: it wears the absence, and is neither counted nor listed as next.
 */
final readonly class MyRosterService
{
    /** How far ahead "next" looks for a watch. */
    public const int AHEAD_DAYS = 28;

    /** "My next shifts — the coming week". */
    public const int COMING_DAYS = 7;

    /** The Swaps card is bounded; it never grows with its data. */
    public const int SWAPS = 5;

    public function __construct(
        private PostingRepository $postings,
        private DutyRepository $duties,
        private AbsenceRepository $absences,
        private SwapRepository $swaps,
        private ShiftRepository $shifts,
        private StationWatchRepository $watches,
        private AreaModuleService $areaModules,
    ) {
    }

    /**
     * THE POST A PERSON IS POSTED AT, where it works the roster — null for
     * somebody posted nowhere, and for a post whose area has parked this
     * module, where there is no roster to show.
     */
    public function postOf(string $personUuid): ?Station
    {
        $station = ($this->postings->findStandingByPersonUuids([$personUuid])[0] ?? null)?->getStation();
        $area = $station?->getArea();

        return null !== $area && $this->areaModules->isActive($area, RosterModuleProvider::SLUG) ? $station : null;
    }

    public function for(string $personUuid, \DateTimeImmutable $now): ?MyRoster
    {
        $station = $this->postOf($personUuid);
        $area = $station?->getArea();
        if (null === $station || null === $area) {
            return null;
        }

        $today = $now->setTime(0, 0);
        $monday = $today->modify('monday this week');
        $sunday = $monday->modify('+6 days');
        $days = [];
        for ($i = 0; $i < 7; ++$i) {
            $days[] = $monday->modify(\sprintf('+%d days', $i));
        }

        $windows = [];
        foreach ($this->shifts->findByArea($area) as $shift) {
            $windows[$shift->getKey()] = $shift;
        }

        /** @var array<string, UserInterface> $people */
        $people = [];
        $headUuid = null;
        foreach ($this->postings->findStandingByStation($station) as $posting) {
            $person = $posting->getPerson();
            $uuid = $person?->getUuidString();
            if (null === $person || null === $uuid) {
                continue;
            }
            $people[$uuid] = $person;
            if ($posting->isLeader()) {
                $headUuid = $uuid;
            }
        }
        $uuids = array_keys($people);

        $weekDuties = $this->byPersonAndDay($this->duties->findStandingForPeopleBetween($area, $uuids, $monday, $sunday));
        $weekAway = $this->absences->findForPeopleBetween($uuids, $monday, $sunday);
        $away = $this->awayByPersonAndDay($weekAway, $days);

        $rows = [];
        foreach ($people as $uuid => $person) {
            $marks = [];
            foreach ($days as $day) {
                $marks[] = $this->marksOn($weekDuties[$uuid][$day->format('Y-m-d')] ?? [], $away[$uuid][$day->format('Y-m-d')] ?? null, $windows);
            }
            $rows[] = new MyRosterRow($uuid, self::shortName($person), self::roleOf($person), $uuid === $headUuid, $uuid === $personUuid, $marks);
        }
        // ME FIRST, THEN THE HEAD, THEN EVERYBODY ELSE BY NAME: the reader
        // finds their own row without looking, and the head is the next
        // question anybody asks of a post.
        usort($rows, static fn (MyRosterRow $a, MyRosterRow $b): int => [$b->me, $b->head, $a->name] <=> [$a->me, $a->head, $b->name]);

        $mine = [];
        $head = null;
        foreach ($rows as $row) {
            if ($row->me) {
                $mine = $row->days;
            }
            if ($row->head) {
                $head = $row;
            }
        }

        $watch = $this->watches->findOneForStation($station);
        $counted = $this->countedShifts($watch, $weekDuties, $station, $windows);
        $counts = [];
        foreach ($days as $day) {
            $counts[] = $this->countOn($day, $station, $uuids, $weekDuties, $away, $counted);
        }
        $expects = [];
        foreach (array_keys($counted) as $key) {
            if (null !== $watch && $watch->needsOn($key) > 0) {
                $expects[$key] = $watch->needsOn($key);
            }
        }

        $factband = [];
        foreach (array_keys($counted) as $key) {
            $shift = $windows[$key] ?? null;
            $factband[] = null === $shift
                ? $key
                : \sprintf('%s %s–%s', $shift->getLabel(), substr($shift->getStartsAt(), 0, 2), substr($shift->getEndsAt(), 0, 2));
        }

        $myShifts = 0;
        $myMinutes = 0;
        foreach ($weekDuties[$personUuid] ?? [] as $date => $duties) {
            if (isset($away[$personUuid][$date])) {
                continue;
            }
            foreach ($duties as $duty) {
                ++$myShifts;
                $myMinutes += self::minutesOf($windows[$duty->getShiftKey()] ?? null);
            }
        }

        [$next, $coming] = $this->ahead($area, $personUuid, $now, $windows);

        return new MyRoster(
            station: $station,
            area: $area,
            head: $head,
            days: $days,
            mine: $mine,
            rows: $rows,
            countedShifts: $counted,
            counts: $counts,
            expects: $expects,
            watches: $factband,
            myShifts: $myShifts,
            myMinutes: $myMinutes,
            next: $next,
            coming: $coming,
            onWithMe: $this->onWithMe($today, $personUuid, $headUuid, $people, $weekDuties, $away),
            swaps: $this->swapsOf($personUuid, $monday, $windows),
            away: array_map(static fn (Absence $absence): array => [
                'name' => self::shortName($absence->getPerson()),
                'me' => $absence->getPerson()->getUuidString() === $personUuid,
                'kind' => strtolower($absence->getKind()->label()),
                'from' => $absence->getStartsOn(),
                'to' => $absence->getEndsOn(),
            ], $weekAway),
        );
    }

    /**
     * THE PERSON'S ABSENCES THIS YEAR, whichever area recorded them, and the
     * days of the year they cover — an absence that began last December
     * counts only its days in this one.
     */
    public function leaveFor(string $personUuid, \DateTimeImmutable $now): MyLeave
    {
        $year = (int) $now->format('Y');
        $first = $now->setDate($year, 1, 1)->setTime(0, 0);
        $last = $now->setDate($year, 12, 31)->setTime(0, 0);

        $absences = $this->absences->findForPeopleBetween([$personUuid], $first, $last);
        $covered = [];
        foreach ($absences as $absence) {
            $day = max($absence->getStartsOn(), $first);
            $end = min($absence->getEndsOn(), $last);
            for (; $day <= $end; $day = $day->modify('+1 day')) {
                $covered[$day->format('Y-m-d')] = true;
            }
        }

        return new MyLeave($year, $absences, \count($covered));
    }

    /** A name as the design prints it: `J. Mollel`, or the whole name where there is no first one. */
    public static function shortName(UserInterface $person): string
    {
        $first = trim((string) $person->getFirstName());
        $last = trim((string) $person->getLastName());

        if ('' === $first || '' === $last) {
            return $person->getFullName();
        }

        return mb_substr($first, 0, 1).'. '.$last;
    }

    /**
     * WHAT TEAM CALLS THIS PERSON — their position's name, read and never
     * stored here ({@see RosteredPeople::roleOf()}).
     */
    private static function roleOf(UserInterface $person): ?string
    {
        return $person instanceof User ? $person->getPosition()?->getName() : null;
    }

    /**
     * @param list<Duty> $duties
     *
     * @return array<string, array<string, list<Duty>>> person uuid, then `Y-m-d`
     */
    private function byPersonAndDay(array $duties): array
    {
        $by = [];
        foreach ($duties as $duty) {
            $by[(string) $duty->getPerson()->getUuidString()][$duty->getOnDay()->format('Y-m-d')][] = $duty;
        }

        return $by;
    }

    /**
     * @param list<Absence>            $absences
     * @param list<\DateTimeImmutable> $days
     *
     * @return array<string, array<string, Absence>> person uuid, then `Y-m-d`
     */
    private function awayByPersonAndDay(array $absences, array $days): array
    {
        $by = [];
        foreach ($absences as $absence) {
            foreach ($days as $day) {
                if ($absence->covers($day)) {
                    $by[(string) $absence->getPerson()->getUuidString()][$day->format('Y-m-d')] = $absence;
                }
            }
        }

        return $by;
    }

    /**
     * @param list<Duty>           $duties
     * @param array<string, Shift> $windows
     *
     * @return list<ShiftMark>
     */
    private function marksOn(array $duties, ?Absence $absence, array $windows): array
    {
        if (null !== $absence) {
            return [new ShiftMark(ShiftMark::LEAVE, strtolower($absence->getKind()->label()))];
        }

        if ([] === $duties) {
            return [ShiftMark::off()];
        }

        return array_map(fn (Duty $duty): ShiftMark => $this->markOf($duty, $windows), $duties);
    }

    /** @param array<string, Shift> $windows */
    private function markOf(Duty $duty, array $windows): ShiftMark
    {
        $shift = $windows[$duty->getShiftKey()] ?? null;
        if (null === $shift) {
            return new ShiftMark(ShiftMark::DAY, $duty->getShiftKey());
        }

        return new ShiftMark(
            $shift->crossesMidnight() ? ShiftMark::NIGHT : ShiftMark::DAY,
            $shift->getLabel(),
            $shift->getStartsAt().'–'.$shift->getEndsAt(),
        );
    }

    /**
     * THE WATCHES THE COUNT ROW COUNTS — the ones the post declares, in its
     * own order; for a post that declares none, whichever it actually stood
     * this week, in the area's order.
     *
     * @param array<string, array<string, list<Duty>>> $weekDuties
     * @param array<string, Shift>                     $windows
     *
     * @return array<string, string> key to label
     */
    private function countedShifts(?StationWatch $watch, array $weekDuties, Station $station, array $windows): array
    {
        $keys = $watch?->getExpects() ?? [];
        if ([] === $keys) {
            $stood = [];
            foreach ($weekDuties as $byDay) {
                foreach ($byDay as $duties) {
                    foreach ($duties as $duty) {
                        if ($duty->getStation()->getId() === $station->getId()) {
                            $stood[$duty->getShiftKey()] = true;
                        }
                    }
                }
            }
            $keys = array_values(array_filter(array_keys($windows), static fn (string $key): bool => isset($stood[$key])));
        }

        $counted = [];
        foreach ($keys as $key) {
            $counted[$key] = ($windows[$key] ?? null)?->getLabel() ?? $key;
        }

        return $counted;
    }

    /**
     * @param list<string>                             $uuids
     * @param array<string, array<string, list<Duty>>> $weekDuties
     * @param array<string, array<string, Absence>>    $away
     * @param array<string, string>                    $counted
     *
     * @return array{onDuty: int, byShift: array<string, int>}
     */
    private function countOn(\DateTimeImmutable $day, Station $station, array $uuids, array $weekDuties, array $away, array $counted): array
    {
        $date = $day->format('Y-m-d');
        $onDuty = 0;
        $byShift = array_fill_keys(array_keys($counted), 0);

        foreach ($uuids as $uuid) {
            if (isset($away[$uuid][$date])) {
                continue;
            }
            $here = array_filter(
                $weekDuties[$uuid][$date] ?? [],
                static fn (Duty $duty): bool => $duty->getStation()->getId() === $station->getId(),
            );
            if ([] === $here) {
                continue;
            }
            ++$onDuty;
            foreach ($here as $duty) {
                if (isset($byShift[$duty->getShiftKey()])) {
                    ++$byShift[$duty->getShiftKey()];
                }
            }
        }

        return ['onDuty' => $onDuty, 'byShift' => $byShift];
    }

    /**
     * MY WATCHES STILL TO COME: the first that has not begun, and those of
     * the coming week.
     *
     * @param array<string, Shift> $windows
     *
     * @return array{0: MyShift|null, 1: list<MyShift>}
     */
    private function ahead(AreaOfInterest $area, string $personUuid, \DateTimeImmutable $now, array $windows): array
    {
        $today = $now->setTime(0, 0);
        $until = $today->modify(\sprintf('+%d days', self::AHEAD_DAYS));
        $days = [];
        for ($day = $today; $day <= $until; $day = $day->modify('+1 day')) {
            $days[] = $day;
        }
        $away = $this->awayByPersonAndDay($this->absences->findForPeopleBetween([$personUuid], $today, $until), $days);

        $shifts = [];
        foreach ($this->duties->findStandingForPersonBetween($area, $personUuid, $today, $until) as $duty) {
            if (isset($away[$personUuid][$duty->getOnDay()->format('Y-m-d')])) {
                continue;
            }
            $shift = $windows[$duty->getShiftKey()] ?? null;
            $startsAt = new \DateTimeImmutable($duty->getOnDay()->format('Y-m-d').' '.($shift?->getStartsAt() ?? '00:00'), $now->getTimezone());
            if ($startsAt > $now) {
                $shifts[] = new MyShift($startsAt, $this->markOf($duty, $windows));
            }
        }
        usort($shifts, static fn (MyShift $a, MyShift $b): int => $a->startsAt <=> $b->startsAt);

        $weekOut = $now->modify(\sprintf('+%d days', self::COMING_DAYS));

        return [
            $shifts[0] ?? null,
            array_values(array_filter($shifts, static fn (MyShift $shift): bool => $shift->startsAt <= $weekOut)),
        ];
    }

    /**
     * WHO STANDS MY WATCH WITH ME TODAY — the same post and the same watch —
     * the head first and marked. Null when I stand none today.
     *
     * @param array<string, UserInterface>             $people
     * @param array<string, array<string, list<Duty>>> $weekDuties
     * @param array<string, array<string, Absence>>    $away
     *
     * @return list<string>|null
     */
    private function onWithMe(\DateTimeImmutable $today, string $personUuid, ?string $headUuid, array $people, array $weekDuties, array $away): ?array
    {
        $date = $today->format('Y-m-d');
        if (isset($away[$personUuid][$date])) {
            return null;
        }

        $mine = [];
        foreach ($weekDuties[$personUuid][$date] ?? [] as $duty) {
            $mine[$duty->getStation()->getId().'|'.$duty->getShiftKey()] = true;
        }
        if ([] === $mine) {
            return null;
        }

        $with = [];
        foreach ($people as $uuid => $person) {
            if ($uuid === $personUuid || isset($away[$uuid][$date])) {
                continue;
            }
            foreach ($weekDuties[$uuid][$date] ?? [] as $duty) {
                if (isset($mine[$duty->getStation()->getId().'|'.$duty->getShiftKey()])) {
                    $with[$uuid] = self::shortName($person).($uuid === $headUuid ? ' (head)' : '');
                    break;
                }
            }
        }

        $head = null !== $headUuid && isset($with[$headUuid]) ? [$with[$headUuid]] : [];
        unset($with[$headUuid ?? '']);
        $others = array_values($with);
        sort($others);

        return [...$head, ...$others];
    }

    /**
     * @param array<string, Shift> $windows
     *
     * @return list<MySwap>
     */
    private function swapsOf(string $personUuid, \DateTimeImmutable $monday, array $windows): array
    {
        $swaps = [];
        foreach ($this->swaps->findInvolving($personUuid, $monday, $monday->modify(\sprintf('+%d days', self::AHEAD_DAYS)), self::SWAPS) as $swap) {
            $duty = $swap->getDuty();
            $asked = $swap->getOfferedTo();
            $iWasAsked = $asked->getUuidString() === $personUuid;
            $other = $iWasAsked ? ($swap->getOfferedBy() ?? $duty->getPerson()) : $asked;

            $swaps[] = new MySwap(
                $duty->getOnDay(),
                ($windows[$duty->getShiftKey()] ?? null)?->getLabel() ?? $duty->getShiftKey(),
                self::shortName($other),
                $swap->getState(),
                $swap->getState()->isOpen() ? ($iWasAsked ? 'me' : self::shortName($asked)) : null,
            );
        }

        return $swaps;
    }

    /** How long a watch runs, in minutes; one that crosses midnight ends the next day. */
    private static function minutesOf(?Shift $shift): int
    {
        if (null === $shift) {
            return 0;
        }

        [$sh, $sm] = array_map(intval(...), explode(':', $shift->getStartsAt()));
        [$eh, $em] = array_map(intval(...), explode(':', $shift->getEndsAt()));
        $minutes = ($eh * 60 + $em) - ($sh * 60 + $sm);

        return $minutes <= 0 ? $minutes + 1440 : $minutes;
    }
}
