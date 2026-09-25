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
use Uhifadhi\Bundle\AreaBundle\Service\StationService;
use Uhifadhi\Roster\Entity\ShiftRule;
use Uhifadhi\Roster\Entity\StationRuleException;
use Uhifadhi\Roster\Enum\ForbiddenDay;
use Uhifadhi\Roster\Enum\NightThenDay;
use Uhifadhi\Roster\Enum\RuleChoiceInterface;
use Uhifadhi\Roster\Enum\RuleKind;
use Uhifadhi\Roster\Model\RuleValue;
use Uhifadhi\Roster\Repository\ShiftRuleRepository;
use Uhifadhi\Roster\Repository\StationRuleExceptionRepository;
use Uhifadhi\Roster\Repository\StationWatchRepository;

/**
 * THE RULES AN AREA SETS, AND WHAT EACH STATION DOES DIFFERENTLY.
 *
 * RULED 20 sep, twice: "forcing predefined options is stupid" — a rule is a
 * number somebody typed and a unit they picked — and "rules configurable
 * like exceptions" — every one the roster sets, not just hours, may be given to
 * one station on that station's own row.
 *
 * PING EVERY IS NOT ONE OF THEM. It is the area's fact, read through the
 * area's {@see \Uhifadhi\Bundle\AreaBundle\Service\PingInterval}; this
 * service neither answers it, nor saves it, nor lets a station overrule it
 * ({@see RuleKind::isSetOnTheArea()}).
 *
 * FOLLOWING THE AREA IS SAID BY SILENCE. There is no "same as the area"
 * value to store: a station with no exception row follows, and removing the
 * row is how it goes back to following. That is why {@see clearException()}
 * deletes rather than writes, and why {@see effective()} answers from the
 * area whenever the station says nothing.
 *
 * AND THE ANSWER IS PROJECTED ONTO WHAT ALREADY READS IT. Every live
 * surface in this module was written against three columns that predate the
 * rules — a watch's silence window, its offline window, and a station's own
 * catchment — and the sheet, the day board and the organization dashboard
 * all read them today. So this service is the one WRITER and those columns
 * are its PROJECTION: saving a rule or an exception recomputes them, and no
 * reader had to learn anything. The release that moves the readers onto
 * {@see effective()} is the one that drops the columns, as the module's own
 * migration rule requires.
 */
final readonly class ShiftRuleService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ShiftRuleRepository $rules,
        private StationRuleExceptionRepository $exceptions,
        private StationWatchRepository $watches,
        // THE CATCHMENT IS THE STATION'S COLUMN, so the verb that writes it
        // is the area's. This module states the distance; the area measures.
        private StationService $stations,
        private RosterSettingsService $settings,
    ) {
    }

    /**
     * THIS AREA'S FIVE, in the order the card draws them — a kind nobody has
     * written reads as the product's standard rather than as a blank.
     *
     * @return array<string, RuleValue> keyed by {@see RuleKind::value}
     */
    public function forArea(AreaOfInterest $area): array
    {
        $written = $this->rules->findByArea($area);

        $values = [];
        foreach (RuleKind::cases() as $kind) {
            if ($kind->isChoice() || $kind->isSetOnTheArea()) {
                continue;
            }

            $values[$kind->value] = ($written[$kind->value] ?? null)?->getValue() ?? $kind->standard();
        }

        return $values;
    }

    /**
     * AND THE ONES THAT ARE PICKED RATHER THAN MEASURED — the same
     * question, asked of the rules that answer it with a word.
     *
     * @return array<string, RuleChoiceInterface> keyed by {@see RuleKind::value}
     */
    public function choicesForArea(AreaOfInterest $area): array
    {
        $written = $this->rules->findByArea($area);

        $choices = [];
        foreach (RuleKind::cases() as $kind) {
            if (!$kind->isChoice()) {
                continue;
            }

            $choices[$kind->value] = ($written[$kind->value] ?? null)?->getChoice() ?? $kind->standardChoice();
        }

        return $choices;
    }

    /**
     * SAVE THE CARD, WHOLE. The rules are one form with one Save, so they are
     * written as one act: a per-row save would let somebody leave the page
     * having changed three and believing they changed them all.
     *
     * @param array<string, RuleValue|RuleChoiceInterface> $values keyed by {@see RuleKind::value}; a kind left out keeps what it had
     *
     * @throws \InvalidArgumentException when a value cannot follow another, said in full
     */
    public function save(AreaOfInterest $area, array $values): void
    {
        $written = $this->rules->findByArea($area);

        foreach (RuleKind::cases() as $kind) {
            // THE AREA'S OWN IS NOT WRITTEN HERE, whatever the caller sent.
            if ($kind->isSetOnTheArea()) {
                continue;
            }

            $answer = $values[$kind->value] ?? null;
            if (!$answer instanceof RuleValue && !$answer instanceof RuleChoiceInterface) {
                continue;
            }

            $row = $written[$kind->value] ?? null;
            if (null === $row) {
                $this->entityManager->persist(new ShiftRule($area, $kind, $answer));

                continue;
            }

            if ($answer instanceof RuleValue) {
                $row->set($answer);
            } else {
                $row->choose($answer);
            }
        }

        $this->entityManager->flush();
        $this->project($area);
    }

    /**
     * WHAT EVERY STATION IN THIS AREA DOES DIFFERENTLY, keyed by station
     * uuid — asked once for a table that draws a row per station.
     *
     * @return array<string, list<StationRuleException>>
     */
    public function exceptionsForArea(AreaOfInterest $area): array
    {
        return $this->exceptions->findByArea($area);
    }

    /**
     * GIVE ONE STATION ITS OWN ANSWER TO ONE RULE.
     *
     * @throws \InvalidArgumentException when the answer is not one this rule takes, the rule is the area's, or the pair it produces cannot stand
     */
    public function setException(Station $station, RuleKind $kind, RuleValue|RuleChoiceInterface $answer): StationRuleException
    {
        if ($kind->isSetOnTheArea()) {
            throw new \InvalidArgumentException(\sprintf('"%s" is the area\'s, one number for every handset in it; a station does not set its own.', $kind->label()));
        }

        $row = $this->exceptions->findOneByStationAndKind($station, $kind);

        if (null === $row) {
            $row = new StationRuleException($station, $kind, $answer);
            $this->entityManager->persist($row);
        } else {
            if ($answer instanceof RuleValue) {
                $row->set($answer);
            } else {
                $row->choose($answer);
            }
        }

        $this->entityManager->flush();
        $this->projectStation($station);

        return $row;
    }

    /**
     * AND TAKE IT BACK OFF. There is no value that means "follow the area":
     * the absence of the row is that answer, which is why the control beside
     * an exception is a cross and not a third option in a select.
     */
    public function clearException(Station $station, RuleKind $kind): void
    {
        $row = $this->exceptions->findOneByStationAndKind($station, $kind);

        if (null === $row) {
            return;
        }

        $this->entityManager->remove($row);
        $this->entityManager->flush();
        $this->projectStation($station);
    }

    /**
     * WHAT THIS RULE ACTUALLY SAYS AT THIS STATION — its own answer where it
     * has one, and the area's where it has not.
     */
    public function effective(Station $station, RuleKind $kind): RuleValue
    {
        $own = $this->exceptions->findOneByStationAndKind($station, $kind);
        if (null !== $own) {
            return $own->getValue();
        }

        $area = $station->getArea();
        if (null === $area) {
            return $kind->standard();
        }

        return $this->rules->findOneByAreaAndKind($area, $kind)?->getValue() ?? $kind->standard();
    }

    /**
     * AND WHAT A CHOSEN RULE SAYS AT THIS STATION — the same walk, for the
     * rules answered with a word.
     *
     * @throws \LogicException when the kind is measured rather than chosen
     */
    public function effectiveChoice(Station $station, RuleKind $kind): RuleChoiceInterface
    {
        $own = $this->exceptions->findOneByStationAndKind($station, $kind);
        if (null !== $own) {
            return $own->getChoice();
        }

        $area = $station->getArea();
        if (null === $area) {
            return $kind->standardChoice();
        }

        return $this->rules->findOneByAreaAndKind($area, $kind)?->getChoice() ?? $kind->standardChoice();
    }

    /**
     * WHAT THIS STATION DOES ABOUT A DAY WATCH THE MORNING AFTER A NIGHT
     * ONE — the chosen rule, typed, so the fill is not handed an
     * interface and left to narrow it.
     */
    public function nightThenDayAt(Station $station): NightThenDay
    {
        $choice = $this->effectiveChoice($station, RuleKind::NightThenDay);

        return $choice instanceof NightThenDay ? $choice : NightThenDay::Never;
    }

    /** AND WHAT IT DOES WITH A DAY ITS OWN RULES FORBID. */
    public function forbiddenDayAt(Station $station): ForbiddenDay
    {
        $choice = $this->effectiveChoice($station, RuleKind::ForbiddenDay);

        return $choice instanceof ForbiddenDay ? $choice : ForbiddenDay::LeftUnfilled;
    }

    /**
     * RECOMPUTE EVERY COLUMN THE RULES STAND BEHIND, for a whole area.
     *
     * It walks the watches rather than the stations: a station this module
     * has not been asked to work has no watch, and writing a threshold onto
     * one would put a post on the books by arithmetic.
     */
    private function project(AreaOfInterest $area): void
    {
        foreach ($this->watches->findByArea($area) as $watch) {
            $this->projectStation($watch->getStation());
        }

        $settings = $this->settings->forArea($area);
        $rules = $this->forArea($area);
        $settings->setDefaultCatchmentMetres($rules[RuleKind::CheckInWithin->value]->toMetres());

        $this->entityManager->flush();
    }

    /**
     * AND FOR ONE STATION.
     *
     * @throws \InvalidArgumentException when late and offline would end up in the wrong order
     */
    private function projectStation(Station $station): void
    {
        $watch = $this->watches->findOneForStation($station);

        if (null !== $watch) {
            $watch->setThresholds(
                $this->effective($station, RuleKind::LateAfter)->toMinutes(),
                $this->effective($station, RuleKind::OfflineAfter)->toMinutes(),
            );
        }

        // THE AREA'S OWN VERB, and it flushes: a station's catchment is the
        // area module's column and this module does not reach past the desk
        // that owns it to batch a write.
        $this->stations->setCatchment($station, $this->effective($station, RuleKind::CheckInWithin)->toMetres());
    }
}
