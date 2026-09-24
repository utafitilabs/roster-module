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

use Doctrine\ORM\EntityManagerInterface;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Roster\Entity\Pattern;
use Uhifadhi\Roster\Entity\Shift;
use Uhifadhi\Roster\Entity\StationWatch;
use Uhifadhi\Roster\Model\Cycle;
use Uhifadhi\Roster\Repository\PatternRepository;
use Uhifadhi\Roster\Repository\StationWatchRepository;

/**
 * THE CYCLES AN AREA FILLS A STATION FROM — one shared object each, named
 * by its own cycle, and edited without moving anybody.
 *
 * THE PRODUCT SHIPS NO PATTERNS (ruled 20 sep). A fresh area opens on an
 * empty register, which is a state and not a setup step somebody skipped.
 *
 * A NAME IS DERIVED AND NEVER TYPED (ruled 21 sep). {@see PatternNamer} is
 * the one derivation, and this service is what hands it THIS AREA'S OWN
 * shift names — so the register, the editor and the sheet's fill row cannot
 * print three different names for one object, and renaming a shift renames
 * every pattern built from it.
 *
 * EDITING CHANGES FUTURE FILLS ONLY, and here that is a statement about
 * what this class does NOT do: {@see save()} writes the cycle and touches
 * no duty at any station running it. A day already planned stays planned
 * and a day somebody edited by hand is never reachable from here at all.
 * That is the whole reason one object may be shared by three stations; the
 * alternative — an edit that re-plans people who are already rostered — is
 * what a per-station copy was invented to avoid, at the price of three
 * objects going quietly out of step.
 */
final readonly class PatternService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PatternRepository $patterns,
        private StationWatchRepository $watches,
        private ShiftVocabularyService $shifts,
    ) {
    }

    /**
     * @return list<Pattern>
     */
    public function forArea(AreaOfInterest $area): array
    {
        return $this->patterns->findByArea($area);
    }

    /**
     * THIS AREA'S SHIFT NAMES, keyed by the key a cycle stores — the one
     * argument a derivation needs, and the reason it is not a static call
     * from a template.
     *
     * @return array<string, string>
     */
    public function labelsFor(AreaOfInterest $area): array
    {
        $labels = [];
        foreach ($this->shifts->forArea($area) as $shift) {
            $labels[$shift->getKey()] = $shift->getLabel();
        }

        return $labels;
    }

    /**
     * THE COLOUR SLOT EACH OF THIS AREA'S SHIFTS WEARS, keyed the same way
     * — what the cycle strip draws a day with, and `null` for off, which is
     * the one day with no fill.
     *
     * @return array<string, int>
     */
    public function coloursFor(AreaOfInterest $area): array
    {
        $colours = [];
        foreach ($this->shifts->forArea($area) as $shift) {
            $colours[$shift->getKey()] = $shift->getColour();
        }

        return $colours;
    }

    /** What this pattern is called, in this area's own words. */
    public function nameOf(Pattern $pattern): string
    {
        return PatternNamer::derive($pattern->getCycle(), $this->labelsFor($pattern->getArea()));
    }

    /**
     * SAY A CYCLE AND IT GETS A NAME. Nothing has to be filled in first and
     * nothing is applied anywhere: a pattern exists before any station runs
     * it, which is what makes the register's empty state honest.
     */
    public function create(AreaOfInterest $area, Cycle $cycle): Pattern
    {
        $pattern = new Pattern($area, $cycle);
        $this->entityManager->persist($pattern);
        $this->entityManager->flush();

        return $pattern;
    }

    /**
     * CHANGE THE CYCLE, AND CHANGE NOTHING ELSE.
     *
     * NO DUTY IS TOUCHED, at any station running it — that is the ruling,
     * and it is expressed as an absence rather than as a filter: this
     * method does not know how to write a duty, so it cannot move one. What
     * changes is what the next fill reads.
     */
    public function save(Pattern $pattern, Cycle $cycle): Pattern
    {
        $pattern->setCycle($cycle);
        $this->entityManager->flush();

        return $pattern;
    }

    /**
     * REMOVE A PATTERN. The stations that ran it stop being filled and keep
     * everything else about themselves, including every day already planned
     * — the relation is SET NULL for exactly that reason.
     */
    public function delete(Pattern $pattern): void
    {
        foreach ($this->runningAt($pattern) as $watch) {
            $watch->filledBy(null);
        }

        $this->entityManager->remove($pattern);
        $this->entityManager->flush();
    }

    /**
     * APPLY ONE TO A STATION, OR TAKE THE STATION OFF ONE.
     *
     * THE DOOR FOR THIS IS THE SHEET'S FILL ROW and not the configure page
     * (ruled 21 sep): a pattern is chosen where the days are, not where the
     * cycles are listed. The verb lives here because the sheet and the
     * register must not each grow their own.
     */
    public function applyTo(StationWatch $watch, ?Pattern $pattern): StationWatch
    {
        $watch->filledBy($pattern);
        $this->entityManager->flush();

        return $watch;
    }

    /**
     * THE WATCHES THIS PATTERN FILLS.
     *
     * @return list<StationWatch>
     */
    public function runningAt(Pattern $pattern): array
    {
        return array_values(array_filter(
            $this->watches->findByArea($pattern->getArea()),
            static fn (StationWatch $watch): bool => $watch->getPattern()?->getId() === $pattern->getId(),
        ));
    }

    /**
     * AND THE STATIONS THEMSELVES, NAMED — what a register card prints as
     * "running at 2 stations — Salt Flats Ranger Post, Ridge Ranger Post".
     *
     * NAMED RATHER THAN COUNTED, because a count alone is a figure nobody
     * can act on: the whole question a reader has about a pattern they are
     * about to edit is which places it reaches.
     *
     * @return list<Station>
     */
    public function stationsRunning(Pattern $pattern): array
    {
        return array_map(static fn (StationWatch $watch): Station => $watch->getStation(), $this->runningAt($pattern));
    }

    /**
     * THE SHIFTS A CYCLE MAY BE BUILT OUT OF — this area's open list, and
     * nothing the product invented.
     *
     * @return list<Shift>
     */
    public function shiftsFor(AreaOfInterest $area): array
    {
        return $this->shifts->openFor($area);
    }
}
