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
use Uhifadhi\Bundle\AreaBundle\Repository\AreaOfInterestRepository;
use Uhifadhi\Bundle\AtlasBundle\Model\AtlasMap;
use Uhifadhi\Contracts\Area\LivePositionsInterface;
use Uhifadhi\Contracts\Shell\Scope;
use Uhifadhi\Roster\Model\DayFigures;
use Uhifadhi\Roster\Model\Decision;
use Uhifadhi\Roster\Model\OrgAreaRow;
use Uhifadhi\Roster\Model\OrgDecision;
use Uhifadhi\Roster\Model\OrgWatchRow;
use Uhifadhi\Roster\Model\WatchDeclaration;
use Uhifadhi\Roster\Repository\RotationRepository;
use Uhifadhi\Roster\Repository\ShiftRepository;
use Uhifadhi\Roster\Repository\StationWatchRepository;

/**
 * THE ROSTER READ ACROSS EVERY AREA — the area page one scope wider.
 *
 * EVERY FIGURE IS THE AREA QUERY WITH THE AREA FILTER WIDENED. This class
 * owns no counting of its own: it resolves which areas a scope reaches and
 * then calls the SAME services the area's own page calls, folding what
 * comes back. A second aggregate written for this scope would be two
 * numbers for one question, and the day they disagreed nobody could say
 * which was right.
 *
 * ONE AREA IS NOT A SPECIAL CASE. A scope naming a single area walks a list
 * of one, so the narrowed organization page and that area's own page are
 * the same reading and cannot differ.
 *
 * THE SCOPE IS ALREADY NARROWED to what the account may open — the shell's
 * scope source answers for that — so this class does no access checking and
 * must not: an area that reaches it is an area the reader may see.
 *
 * WHICH AREA IS A COLUMN AND NOT A COLOUR. Every row carries its area's
 * POSITION in the declared order; the shell turns that into a swatch. This
 * module names no hue and reads none.
 */
final readonly class RosterOrgService
{
    public function __construct(
        private AreaOfInterestRepository $areas,
        private RosterFiguresService $figures,
        private PresenceReader $presence,
        private RosterIdentityService $identity,
        private WeekGridService $week,
        private RosterLiveService $live,
        private LivePositionsInterface $positions,
        // WHAT EACH STATION ASKS FOR — the three the declaration is read
        // from. The presence reading says who is on a station; only these
        // say what it asked for in the first place.
        private StationWatchRepository $watches,
        private RotationRepository $rotations,
        private ShiftRepository $shifts,
    ) {
    }

    /**
     * THE AREAS A SCOPE REACHES, in the declared order.
     *
     * @param list<Scope> $available everything the reader was offered
     *
     * @return list<AreaOfInterest>
     */
    public function areasIn(Scope $scope, array $available): array
    {
        $ordered = $this->declaredOrder();

        if (!$scope->isOrganization()) {
            foreach ($ordered as $area) {
                if ((string) $area->getUuidString() === $scope->areaUuid) {
                    return [$area];
                }
            }

            return [];
        }

        // THE ORGANIZATION IS THE AREAS THE READER WAS OFFERED, and not
        // every area there is: somebody who may open two of five is shown
        // an organization of two. The control is the authority on that.
        $offered = [];
        foreach ($available as $one) {
            if (null !== $one->areaUuid) {
                $offered[$one->areaUuid] = true;
            }
        }

        if ([] === $offered) {
            return $ordered;
        }

        return array_values(array_filter(
            $ordered,
            static fn (AreaOfInterest $area): bool => isset($offered[(string) $area->getUuidString()]),
        ));
    }

    /** @param list<AreaOfInterest> $areas */
    public function figuresFor(array $areas, \DateTimeImmutable $day, \DateTimeImmutable $now): DayFigures
    {
        return $this->figures->forScope($areas, $day, $now);
    }

    /**
     * EVERY AREA AS A BAND, in the declared order — the org answer to
     * "which of them is the problem".
     */
    /**
     * @param list<AreaOfInterest>                     $areas
     * @param (callable(AreaOfInterest): ?string)|null $url   where that area's own roster lives
     *
     * @return list<OrgAreaRow>
     */
    public function bands(array $areas, \DateTimeImmutable $day, \DateTimeImmutable $now, ?callable $url = null): array
    {
        $rows = [];

        foreach ($areas as $area) {
            $figures = $this->figures->forDay($area, $day, $now);
            $band = $this->identity->bandFor($area);

            $rows[] = new OrgAreaRow(
                uuid: (string) $area->getUuidString(),
                name: (string) $area->getName(),
                position: $this->positionOf($area),
                rangers: $band->rangers,
                postsReporting: $figures->postsReporting,
                postsOnTheBooks: $figures->postsOnTheBooks,
                checkedIn: $figures->checkedIn,
                expected: $figures->expected,
                needingADecision: $figures->needingADecision(),
                url: null === $url ? null : $url($area),
            );
        }

        return $rows;
    }

    /**
     * TODAY'S WATCHES ACROSS THE ORGANIZATION — one row per station on this
     * module's books, in any area.
     *
     * IT IS THE AREA READING WITH THE AREA COLUMN ADDED, and nothing else.
     * Every figure on a row comes from {@see PresenceReader::postsOn()} —
     * the same call the area's own stations card makes — so the
     * organization's answer is the areas' answers, station for station. The
     * only thing this adds is which area a row is in, which is the one fact
     * a per-area reading has no need of.
     *
     * A STATION THAT STANDS NO WATCH KEEPS ITS ROW. It is on the books, so
     * it is this module's to report on; what it reports is that it asks for
     * nobody, which is why it is never counted, never late and never a
     * hole. A station the roster does not keep at all is not here, and that
     * is a different fact.
     *
     * @param list<AreaOfInterest> $areas
     *
     * @return list<OrgWatchRow>
     */
    public function watches(array $areas, \DateTimeImmutable $day, \DateTimeImmutable $now): array
    {
        $rows = [];

        foreach ($areas as $area) {
            // THE VOCABULARY AND THE BOOKS ONCE PER AREA, not once per
            // station: a park of twelve stations is one query for the
            // shifts, not twelve of the same one.
            $shifts = [];
            foreach ($this->shifts->findByArea($area) as $shift) {
                $shifts[$shift->getKey()] = $shift;
            }

            $expects = [];
            foreach ($this->watches->findByArea($area) as $watch) {
                $expects[(string) $watch->getStation()->getUuidString()] = $watch;
            }

            foreach ($this->presence->postsOn($area, $day, $now) as $reading) {
                $watch = $expects[$reading->stationUuid] ?? null;
                if (null === $watch) {
                    continue;
                }

                $station = $watch->getStation();

                $rows[] = new OrgWatchRow(
                    stationName: $reading->stationName,
                    stationCode: $station->getCode(),
                    areaName: (string) $area->getName(),
                    watch: WatchDeclaration::of(
                        $watch->getExpects(),
                        $this->rotations->findOneForStation($station),
                        $shifts,
                    ),
                    onItNow: \count($reading->rostered),
                    verified: $reading->verified,
                    expected: $reading->expected,
                    state: $reading->state,
                    silentFor: $reading->silentFor,
                );
            }
        }

        return $rows;
    }

    /**
     * WHAT NEEDS SOMEBODY TO ACT, ANYWHERE — ordered by the question and
     * never by the area.
     *
     * @param list<AreaOfInterest> $areas
     *
     * @return list<OrgDecision>
     */
    public function decisions(array $areas, \DateTimeImmutable $day, \DateTimeImmutable $now): array
    {
        $rows = [];

        foreach ($areas as $area) {
            $posts = $this->presence->postsOn($area, $day, $now);
            $gaps = $this->week->gaps($area, $day, $day->modify('+6 days'));

            foreach (RosterFiguresService::decisions($posts, $gaps) as $decision) {
                $rows[] = new OrgDecision($decision, (string) $area->getName(), $this->positionOf($area));
            }
        }

        // THE ORDER IS THE AREA PAGE'S, kept: a missing check-in outranks a
        // flagged claim outranks a hole. `RosterFiguresService::decisions()`
        // already returns one area's rows in that order, so folding by kind
        // preserves it across areas without re-deciding what is loud.
        $byKind = [Decision::MISSING => 0, Decision::FLAGGED => 1, Decision::HOLE => 2];
        usort($rows, static fn (OrgDecision $a, OrgDecision $b): int => ($byKind[$a->decision->kind] ?? 9) <=> ($byKind[$b->decision->kind] ?? 9));

        return $rows;
    }

    /**
     * EVERY AREA'S GROUND ON ONE PLATE, with every live position on it.
     *
     * THE PLATE IS THE ATLAS'S AND THE GROUND IS EACH AREA'S. This module
     * contributes the marker layers and nothing else; where an
     * installation has no plate service at all, there is simply no plate
     * and the page says so rather than drawing an empty frame.
     *
     * @param list<AreaOfInterest> $areas
     */
    public function plate(array $areas, \DateTimeImmutable $now): ?AtlasMap
    {
        if ([] === $areas) {
            return null;
        }

        // ONE AREA IS THE AREA'S OWN PLATE, unchanged: the narrowed org
        // page draws exactly what that area's Live tab draws.
        $map = $this->live->plate(
            $areas[0],
            $this->positions->liveIn((string) $areas[0]->getUuidString(), $now),
        );

        // AND EVERY OTHER AREA'S POSITIONS ON TOP OF IT. The boundaries of
        // the rest are the AREA's to draw and the atlas's to place; what
        // this module adds is where its people are.
        foreach (\array_slice($areas, 1) as $area) {
            $map = $map->livePositions($this->positions->liveIn((string) $area->getUuidString(), $now));
        }

        return $map;
    }

    /**
     * EVERY ROSTERED PERSON ON THE DAY, ANYWHERE — the area agenda with one
     * column added, and the column is which area.
     *
     * @param list<AreaOfInterest> $areas
     *
     * @return list<array{areaName: string, position: int, post: \Uhifadhi\Roster\Model\PostPresence, person: \Uhifadhi\Roster\Model\RosteredPerson}>
     */
    public function agenda(array $areas, \DateTimeImmutable $day, \DateTimeImmutable $now): array
    {
        $rows = [];

        foreach ($areas as $area) {
            $position = $this->positionOf($area);
            $name = (string) $area->getName();

            foreach ($this->presence->postsOn($area, $day, $now) as $post) {
                foreach ($post->rostered as $person) {
                    $rows[] = ['areaName' => $name, 'position' => $position, 'post' => $post, 'person' => $person];
                }
            }
        }

        return $rows;
    }

    /**
     * THE AREAS AS THE PRODUCT DECLARES THEM — one order, everywhere, so
     * an area's swatch is the same on every surface that draws one.
     *
     * @return list<AreaOfInterest>
     */
    private function declaredOrder(): array
    {
        return $this->areas->findBy([], ['id' => 'ASC']);
    }

    /** An area's 1-based place in the declared order. */
    private function positionOf(AreaOfInterest $area): int
    {
        foreach ($this->declaredOrder() as $index => $candidate) {
            if ($candidate->getId() === $area->getId()) {
                return $index + 1;
            }
        }

        return 0;
    }
}
