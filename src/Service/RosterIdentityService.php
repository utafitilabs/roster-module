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
use Uhifadhi\Bundle\AreaBundle\Repository\PostingRepository;
use Uhifadhi\Bundle\AreaBundle\Repository\StationRepository;
use Uhifadhi\Roster\Entity\Rotation;
use Uhifadhi\Roster\Entity\Shift;
use Uhifadhi\Roster\Entity\StationWatch;
use Uhifadhi\Roster\Enum\RotationScope;
use Uhifadhi\Roster\Model\IdentityBand;
use Uhifadhi\Roster\Repository\RotationRepository;
use Uhifadhi\Roster\Repository\StationRuleExceptionRepository;
use Uhifadhi\Roster\Repository\StationWatchRepository;

/**
 * THE IDENTITY BAND'S FIGURES — structural facts about how this area's roster
 * is SET UP, never about what is happening in it.
 *
 * THE RANGER COUNT IS THE AREA'S, NOT THIS MODULE'S. It counts the people the
 * area posts to the stations on this module's books — distinct people, because
 * one ranger posted to two posts is one ranger. A person in a rotation's pool
 * who is not posted anywhere is not counted here: the band says who the park
 * has at these posts, and that is the area's answer to give.
 *
 * "OF THE AREA'S 12" IS THE AREA'S TOO. This module works six of them; the
 * denominator belongs to whoever owns the registry, and printing it is how the
 * band admits that the other six exist and are deliberately not its business.
 */
final readonly class RosterIdentityService
{
    public function __construct(
        private StationWatchRepository $watches,
        private StationRepository $stations,
        private PostingRepository $postings,
        private RotationRepository $rotations,
        // KEYED BY STATION, so the count is of PLACES that differ and not of
        // rows: one station with three of its own is one station to look at.
        private StationRuleExceptionRepository $exceptions,
        private ShiftVocabularyService $shifts,
        private RosterSettingsService $settings,
    ) {
    }

    public function bandFor(AreaOfInterest $area): IdentityBand
    {
        $watches = $this->watches->findByArea($area);
        $rotations = $this->rotations->findActiveByArea($area);
        $open = $this->shifts->openFor($area);

        return new IdentityBand(
            rangers: $this->rangersAtWatchedPosts($area, $watches),
            postsWithAWatch: \count($watches),
            stationsRunningAShift: \count(array_filter($watches, static fn (StationWatch $watch): bool => !$watch->expectsNothing())),
            stationsWithTheirOwnRules: \count($this->exceptions->findByArea($area)),
            stationsInArea: $this->stations->countByArea($area),
            rotations: \count($rotations),
            rotationsPerPost: $this->countScope($rotations, RotationScope::Post),
            rotationsPerTeam: $this->countScope($rotations, RotationScope::Team),
            namedShifts: \count($open),
            shiftLabels: implode(', ', array_map(static fn (Shift $shift): string => mb_strtolower($shift->getLabel()), $open)),
            pingIntervalMinutes: $this->settings->pingIntervalFor($area),
            generatedThrough: $this->furthestGenerated($rotations),
        );
    }

    /**
     * DISTINCT PEOPLE POSTED TO THE POSTS ON THE BOOKS. Counted through the
     * area's standing postings, keyed by person, because one ranger posted to
     * two of them is one ranger — and a band that double-counted would say
     * the park is bigger than it is.
     *
     * @param list<StationWatch> $watches
     */
    private function rangersAtWatchedPosts(AreaOfInterest $area, array $watches): int
    {
        if ([] === $watches) {
            return 0;
        }

        $onTheBooks = [];
        foreach ($watches as $watch) {
            $onTheBooks[(string) $watch->getStation()->getId()] = true;
        }

        $people = [];
        foreach ($this->postings->findStandingByArea($area) as $posting) {
            $station = $posting->getStation();
            if (null === $station || !isset($onTheBooks[(string) $station->getId()])) {
                continue;
            }

            $person = $posting->getPerson();
            if (null !== $person) {
                $people[(string) $person->getId()] = true;
            }
        }

        return \count($people);
    }

    /**
     * @param list<Rotation> $rotations
     */
    private function countScope(array $rotations, RotationScope $scope): int
    {
        return \count(array_filter($rotations, static fn (Rotation $rotation): bool => $scope === $rotation->getScope()));
    }

    /**
     * HOW FAR THE PLAN RUNS — the FURTHEST any standing rotation has reached,
     * not the nearest.
     *
     * The band is answering "how far ahead is this park planned", and one post
     * that has never generated does not un-plan the other five; that post
     * shows as a gap on its own row in the rotation table, where somebody can
     * act on it. Null when no rotation has ever run, which the band draws
     * differently from a date.
     *
     * @param list<Rotation> $rotations
     */
    private function furthestGenerated(array $rotations): ?\DateTimeImmutable
    {
        $furthest = null;
        foreach ($rotations as $rotation) {
            $through = $rotation->getGeneratedThrough();
            if (null !== $through && (null === $furthest || $through > $furthest)) {
                $furthest = $through;
            }
        }

        return $furthest;
    }
}
