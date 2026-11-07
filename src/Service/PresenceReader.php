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
use Uhifadhi\Contracts\Area\PersonDay;
use Uhifadhi\Contracts\Area\PresenceProviderInterface;
use Uhifadhi\Roster\Entity\Rotation;
use Uhifadhi\Roster\Entity\StationWatch;
use Uhifadhi\Roster\Model\PostPresence;
use Uhifadhi\Roster\Model\PostState;
use Uhifadhi\Roster\Model\RosteredPerson;
use Uhifadhi\Roster\Repository\DutyRepository;
use Uhifadhi\Roster\Repository\RotationRepository;
use Uhifadhi\Roster\Repository\ShiftRepository;
use Uhifadhi\Roster\Repository\StationWatchRepository;

/**
 * WHO IS ACTUALLY ON — this module's ONE reading of presence, and it
 * COMPUTES NONE OF IT.
 *
 * PRESENCE IS THE AREA'S, RULED. The claim, the positions, the catchment
 * and the verdict on all three are derived by the area bundle and read here
 * through {@see PresenceProviderInterface}. This class does exactly one
 * thing the area cannot: it JOINS that reading to who was rostered. The
 * area says plainly that it does not hold who was supposed to be on, and
 * the absence of a reported day against a rostered watch is the "no
 * check-in" a board draws — a fact only a caller holding both sides can
 * see.
 *
 * THE POST'S STATE IS THE ONE THING THIS MODULE DERIVES, because it is a
 * property of the WATCH and not of the day: reporting, late and offline are
 * measured against thresholds this module owns on the post
 * ({@see StationWatch}). Everything about a PERSON comes from the area
 * unchanged.
 *
 * ONE CALL PER DAY, NOT ONE PER PERSON. `dayIn()` answers a whole area at
 * once and is asked once here; a station card that asked per row would put
 * a query behind every name on a control-room screen that is open all day.
 */
final readonly class PresenceReader
{
    public function __construct(
        private PresenceProviderInterface $presence,
        private DutyRepository $duties,
        private ShiftRepository $shifts,
        private StationWatchRepository $watches,
        private RotationRepository $rotations,
    ) {
    }

    /**
     * EVERY POST ON THIS MODULE'S BOOKS, on one day.
     *
     * @return list<PostPresence> in the order the watches come back, which is by call sign
     */
    public function postsOn(AreaOfInterest $area, \DateTimeImmutable $day, ?\DateTimeImmutable $now = null): array
    {
        $watches = $this->watches->findByArea($area);
        if ([] === $watches) {
            return [];
        }

        $reported = $this->reportedByPerson($area, $day);
        $rostered = $this->rosteredByStation($area, $day, $reported);
        $now ??= new \DateTimeImmutable();

        $posts = [];
        foreach ($watches as $watch) {
            $station = $watch->getStation();
            $uuid = (string) $station->getUuidString();
            $people = $rostered[$uuid] ?? [];

            $posts[] = new PostPresence(
                stationUuid: $uuid,
                stationName: (string) $station->getName(),
                rostered: $people,
                expected: $this->expectedAt($watch, $area, $day),
                verified: \count(array_filter($people, static fn (RosteredPerson $p): bool => !$p->isFlagged() && $p->isPresent())),
                flagged: \count(array_filter($people, static fn (RosteredPerson $p): bool => $p->isFlagged())),
                state: $this->stateOf($watch, $people, $now, $silentFor),
                silentFor: $silentFor,
                stationCode: $station->getCode(),
            );
        }

        return $posts;
    }

    /** One post on one day, or null where it is not on this module's books. */
    public function post(Station $station, \DateTimeImmutable $day, ?\DateTimeImmutable $now = null): ?PostPresence
    {
        $area = $station->getArea();
        if (null === $area) {
            return null;
        }

        $uuid = (string) $station->getUuidString();
        foreach ($this->postsOn($area, $day, $now) as $post) {
            if ($post->stationUuid === $uuid) {
                return $post;
            }
        }

        return null;
    }

    /**
     * THE AREA'S READING OF THE DAY, BY PERSON.
     *
     * A DAY HOLDS ANY NUMBER OF WATCHES (ruled), and the contract carries
     * them INSIDE the day: `dayIn()` answers one row per person, holding
     * the list of check-ins they made. So keying by person uuid is right
     * here and loses nothing — it is the list within that a surface walks.
     *
     * @return array<string, PersonDay>
     */
    private function reportedByPerson(AreaOfInterest $area, \DateTimeImmutable $day): array
    {
        $byPerson = [];
        foreach ($this->presence->dayIn((string) $area->getUuidString(), $day->format('Y-m-d')) as $personDay) {
            $byPerson[$personDay->personUuid] = $personDay;
        }

        return $byPerson;
    }

    /**
     * WHO IS DUE WHERE, with the area's reading of each of them attached.
     *
     * @param array<string, PersonDay> $reported
     *
     * @return array<string, list<RosteredPerson>> station uuid => the people due there
     */
    private function rosteredByStation(AreaOfInterest $area, \DateTimeImmutable $day, array $reported): array
    {
        $labels = [];
        foreach ($this->shifts->findByArea($area) as $shift) {
            $labels[$shift->getKey()] = $shift->getLabel();
        }

        $byStation = [];
        foreach ($this->duties->findByAreaOnDay($area, $day) as $duty) {
            if (!$duty->getState()->isStanding()) {
                continue;
            }

            $personUuid = (string) $duty->getPerson()->getUuidString();
            $byStation[(string) $duty->getStation()->getUuidString()][] = new RosteredPerson(
                personUuid: $personUuid,
                personName: $duty->getPerson()->getFullName(),
                shiftKey: $duty->getShiftKey(),
                shiftLabel: $labels[$duty->getShiftKey()] ?? $duty->getShiftKey(),
                day: $reported[$personUuid] ?? null,
            );
        }

        return $byStation;
    }

    /**
     * HOW MANY THE POST ASKS FOR TODAY — the sum of its rotation's slots for
     * the shifts it expects.
     *
     * Read from the ROTATION rather than counted off the duties, because the
     * shortfall between the two IS the hole: a post whose pool has shrunk
     * keeps asking for two and says so.
     */
    private function expectedAt(StationWatch $watch, AreaOfInterest $area, \DateTimeImmutable $day): int
    {
        $rotation = $this->rotationFor($watch);
        if (null === $rotation || $rotation->standsDownOn($day)) {
            return 0;
        }

        $expected = 0;
        foreach ($watch->getExpects() as $shiftKey) {
            $expected += $rotation->slotsFor($shiftKey);
        }

        return $expected;
    }

    private function rotationFor(StationWatch $watch): ?Rotation
    {
        return $this->rotations->findOneForStation($watch->getStation());
    }

    /**
     * THE POST'S OWN STATE, measured against ITS OWN thresholds.
     *
     * A gate that never closes and a rim post reached once a fortnight
     * cannot share one window, which is why the numbers are on the watch.
     *
     * THE EVIDENCE IS THE NEWEST THING FROM ANYBODY HERE — a check-in or a
     * ping, whichever is later, from any of the post's people. The state is
     * the post's; the evidence is its people's, which is the whole shape of
     * the 20 sep restatement.
     *
     * @param list<RosteredPerson> $people
     */
    private function stateOf(StationWatch $watch, array $people, \DateTimeImmutable $now, ?int &$silentFor): PostState
    {
        // THE NEWEST EVIDENCE FROM ANYBODY HERE, across every watch of
        // every person — not the last row of each. A post whose morning
        // shift reported and whose afternoon has not is not silent since
        // this morning; it is silent since the last thing that arrived.
        $newest = null;
        foreach ($people as $person) {
            $seen = $person->lastSeenAt();
            if (null !== $seen && (null === $newest || $seen > $newest)) {
                $newest = $seen;
            }
        }

        if (null === $newest) {
            // NOTHING AT ALL. A post nobody has reported from is offline once
            // its long threshold has passed; before that it is simply a post
            // whose day has not started, and a post that expects nothing is
            // never "late" — it was not expected.
            $silentFor = null;

            return $watch->expectsNothing() ? PostState::NoWatch : PostState::Offline;
        }

        $silentFor = (int) floor(($now->getTimestamp() - $newest->getTimestamp()) / 60);

        if ($silentFor >= $watch->getOfflineAfterMinutes()) {
            return PostState::Offline;
        }

        if ($watch->expectsNothing()) {
            return PostState::NoWatch;
        }

        return $silentFor >= $watch->getSilenceWindowMinutes() ? PostState::Late : PostState::Reporting;
    }
}
