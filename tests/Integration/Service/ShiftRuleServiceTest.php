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

namespace Uhifadhi\Roster\Tests\Integration\Service;

use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Roster\Enum\ForbiddenDay;
use Uhifadhi\Roster\Enum\NightThenDay;
use Uhifadhi\Roster\Enum\RuleChoiceInterface;
use Uhifadhi\Roster\Enum\RuleKind;
use Uhifadhi\Roster\Enum\RuleUnit;
use Uhifadhi\Roster\Model\RuleValue;
use Uhifadhi\Roster\Service\ShiftRuleService;
use Uhifadhi\Roster\Service\StationWatchService;
use Uhifadhi\Roster\Tests\Integration\IntegrationTestCase;

/**
 * THE FIVE RULES AND THEIR EXCEPTIONS, against the real database.
 *
 * TWO THINGS ARE BEING PROVED AND THEY ARE DIFFERENT. One is the ruling —
 * an area sets five, any station may overrule any of them, and removing the
 * row is how it goes back to following. The other is the PROJECTION: every
 * live surface in this module reads three columns that predate the rules,
 * and if saving a rule did not recompute them the card would say one thing
 * and the day board another.
 */
final class ShiftRuleServiceTest extends IntegrationTestCase
{
    private AreaOfInterest $area;
    private Station $gate;
    private Station $outpost;

    protected function setUp(): void
    {
        parent::setUp();

        $this->area = $this->anArea();
        $this->gate = $this->aStation($this->area, 'north gate post', 'ST-01');
        $this->outpost = $this->aStation($this->area, 'far outpost', 'ST-02');
        $this->theShiftVocabulary($this->area);
        $this->em->flush();

        $watches = $this->service(StationWatchService::class);
        self::assertInstanceOf(StationWatchService::class, $watches);
        $watches->addToRoster($this->gate);
        $watches->addToRoster($this->outpost);
    }

    private function rules(): ShiftRuleService
    {
        $rules = $this->service(ShiftRuleService::class);
        self::assertInstanceOf(ShiftRuleService::class, $rules);

        return $rules;
    }

    /**
     * AN AREA NOBODY HAS CONFIGURED READS AS THE STANDARD, not as a card
     * of blanks. A blank is a rule that measures nothing, and every
     * surface that asked one would have to carry its own fallback.
     */
    public function testAnUntouchedAreaReadsAsTheStandard(): void
    {
        $values = $this->rules()->forArea($this->area);

        self::assertCount(6, $values, 'Every measured rule the roster owns answers, the filling pair included.');
        self::assertSame('2 hours', $values[RuleKind::LateAfter->value]->label());
        self::assertArrayNotHasKey(RuleKind::PingEvery->value, $values, 'Ping every is the area\'s, read from the area.');
        self::assertSame('1.5 km', $values[RuleKind::CheckInWithin->value]->label());
        self::assertSame('11 hours', $values[RuleKind::RestBetween->value]->label());
        self::assertSame('6 weeks', $values[RuleKind::FillAhead->value]->label());
    }

    /**
     * AND THE TWO THAT ARE PICKED READ AS THEIR STANDARD TOO. Never, and
     * a forbidden day left unfilled: the safe answer is the one an area
     * that has said nothing gets.
     */
    public function testTheChosenRulesAlsoReadAsTheStandard(): void
    {
        $choices = $this->rules()->choicesForArea($this->area);

        self::assertCount(2, $choices);
        self::assertSame(NightThenDay::Never, $choices[RuleKind::NightThenDay->value]);
        self::assertSame(ForbiddenDay::LeftUnfilled, $choices[RuleKind::ForbiddenDay->value]);
    }

    /** AND A CHOICE, ONCE SAVED, IS WHAT COMES BACK. */
    public function testAChosenRuleComesBackAsItWasPicked(): void
    {
        $this->rules()->save($this->area, [
            RuleKind::NightThenDay->value => NightThenDay::WarnMe,
            RuleKind::ForbiddenDay->value => ForbiddenDay::FilledAndFlagged,
        ]);

        $this->em->clear();

        $choices = $this->rules()->choicesForArea($this->reloadedArea());
        self::assertSame(NightThenDay::WarnMe, $choices[RuleKind::NightThenDay->value]);
        self::assertSame(ForbiddenDay::FilledAndFlagged, $choices[RuleKind::ForbiddenDay->value]);
    }

    /**
     * THE PAIR COMES BACK AS IT WAS TYPED. "1 day" stored as 1440 minutes
     * and read back as minutes is the same threshold and a different
     * answer, and nobody would type a day again.
     */
    public function testWhatWasTypedIsWhatComesBack(): void
    {
        $this->rules()->save($this->area, [
            RuleKind::OfflineAfter->value => new RuleValue(1.0, RuleUnit::Days),
            RuleKind::LateAfter->value => new RuleValue(90.0, RuleUnit::Minutes),
        ]);

        $this->em->clear();

        $values = $this->rules()->forArea($this->reloadedArea());
        self::assertSame('1 day', $values[RuleKind::OfflineAfter->value]->label());
        self::assertSame('90 minutes', $values[RuleKind::LateAfter->value]->label());
    }

    /**
     * A STATION THAT SAYS NOTHING FOLLOWS THE AREA, and saying nothing is
     * the ONLY way it does: there is no "same as the area" value to store,
     * which is why the control beside an exception is a cross.
     */
    public function testAStationFollowsTheAreaUntilItSaysOtherwise(): void
    {
        $this->rules()->save($this->area, [RuleKind::LateAfter->value => new RuleValue(2.0, RuleUnit::Hours)]);

        self::assertSame('2 hours', $this->rules()->effective($this->gate, RuleKind::LateAfter)->label());

        $this->rules()->setException($this->gate, RuleKind::LateAfter, new RuleValue(1.0, RuleUnit::Hours));

        self::assertSame('1 hour', $this->rules()->effective($this->gate, RuleKind::LateAfter)->label());
        self::assertSame(
            '2 hours',
            $this->rules()->effective($this->outpost, RuleKind::LateAfter)->label(),
            'One station\'s exception is one station\'s.',
        );

        $this->rules()->clearException($this->gate, RuleKind::LateAfter);

        self::assertSame('2 hours', $this->rules()->effective($this->gate, RuleKind::LateAfter)->label());
        self::assertSame(
            [],
            $this->rules()->exceptionsForArea($this->area),
            'Going back to following leaves no row behind — the absence IS the answer.',
        );
    }

    /**
     * EVERY RULE, NOT JUST HOURS. Ruled 20 sep: "rules configurable like
     * exceptions" was about all five, so a station may give itself its own
     * catchment exactly as it gives itself its own late window.
     */
    public function testAnyOfTheFiveMayBeGivenToOneStation(): void
    {
        /*
         * A VALUE PER KIND, AND THEY DIFFER ON PURPOSE. Late and offline
         * are an ORDERED pair — a post that goes offline before it reads
         * late never reads late at all — so giving every rule the same
         * three hours would be testing the override with a pair the
         * entity is right to refuse.
         */
        /** @var array<string, RuleValue> $values */
        $values = [
            RuleKind::LateAfter->value => new RuleValue(90.0, RuleUnit::Minutes),
            RuleKind::OfflineAfter->value => new RuleValue(3.0, RuleUnit::Days),
            RuleKind::CheckInWithin->value => new RuleValue(800.0, RuleUnit::Metres),
            RuleKind::RaiseShortCover->value => new RuleValue(6.0, RuleUnit::Hours),
            RuleKind::RestBetween->value => new RuleValue(9.0, RuleUnit::Hours),
            RuleKind::FillAhead->value => new RuleValue(3.0, RuleUnit::Weeks),
        ];
        // AND THE TWO THAT ARE PICKED RATHER THAN MEASURED. A station may
        // overrule them too: a station allowing a night then a day is the
        // case the exception mechanism exists for.
        /** @var array<string, RuleChoiceInterface> $choices */
        $choices = [
            RuleKind::NightThenDay->value => NightThenDay::Allow,
            RuleKind::ForbiddenDay->value => ForbiddenDay::FilledAndFlagged,
        ];

        foreach (RuleKind::cases() as $kind) {
            if ($kind->isSetOnTheArea()) {
                continue;
            }

            if ($kind->isChoice()) {
                $choice = $choices[$kind->value];

                $this->rules()->setException($this->outpost, $kind, $choice);

                self::assertSame(
                    $choice,
                    $this->rules()->effectiveChoice($this->outpost, $kind),
                    \sprintf('"%s" is overridable at a station like every other rule.', $kind->label()),
                );

                continue;
            }

            $value = $values[$kind->value];

            $this->rules()->setException($this->outpost, $kind, $value);

            self::assertSame(
                $value->label(),
                $this->rules()->effective($this->outpost, $kind)->label(),
                \sprintf('"%s" is overridable at a station like every other rule.', $kind->label()),
            );
        }
    }

    /**
     * PING EVERY IS NOT A STATION'S TO OVERRULE. The handset reads one
     * number, the area's, so a station's own would be a value nothing reads.
     */
    public function testAStationCannotBeGivenItsOwnPingInterval(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->rules()->setException($this->gate, RuleKind::PingEvery, new RuleValue(15.0, RuleUnit::Minutes));
    }

    /**
     * AND SAVING THE AREA'S RULES WRITES NO PING INTERVAL, even when handed
     * one: the area's own number stays what the area set.
     */
    public function testSavingTheRulesWritesNoPingInterval(): void
    {
        $this->area->setPingIntervalMinutes(45);
        $this->em->flush();

        $this->rules()->save($this->area, [
            RuleKind::PingEvery->value => new RuleValue(5.0, RuleUnit::Minutes),
            RuleKind::LateAfter->value => new RuleValue(3.0, RuleUnit::Hours),
        ]);

        self::assertSame(45, $this->area->getPingIntervalMinutes());
        self::assertArrayNotHasKey(RuleKind::PingEvery->value, $this->rules()->forArea($this->area));
    }

    /**
     * AND A UNIT THAT MEASURES THE WRONG THING IS REFUSED. A catchment in
     * hours is not a tight catchment, it is a sentence nobody can act on.
     */
    public function testAUnitThatMeasuresTheWrongThingIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->rules()->setException($this->gate, RuleKind::CheckInWithin, new RuleValue(2.0, RuleUnit::Hours));
    }

    /**
     * THE PROJECTION. Saving the rules recomputes the columns the sheet,
     * the day board and the organization dashboard already read, so the
     * card and the surfaces cannot disagree for a single request.
     */
    public function testSavingTheRulesReachesTheColumnsTheLiveSurfacesRead(): void
    {
        $this->rules()->save($this->area, [
            RuleKind::LateAfter->value => new RuleValue(1.0, RuleUnit::Hours),
            RuleKind::OfflineAfter->value => new RuleValue(1.0, RuleUnit::Days),
            RuleKind::CheckInWithin->value => new RuleValue(2.0, RuleUnit::Kilometres),
        ]);

        $watches = $this->service(StationWatchService::class);
        self::assertInstanceOf(StationWatchService::class, $watches);

        $watch = $watches->forStation($this->gate);
        self::assertNotNull($watch);
        self::assertSame(60, $watch->getSilenceWindowMinutes(), 'Late after 1 hour is sixty minutes of silence.');
        self::assertSame(1440, $watch->getOfflineAfterMinutes());
        self::assertSame(2000, $this->gate->getCatchmentM(), 'And a 2 km check-in is 2000 m of catchment.');
    }

    /**
     * AND AN EXCEPTION REACHES THEM TOO, for that station and no other.
     */
    public function testAnExceptionReachesOnlyItsOwnStation(): void
    {
        $this->rules()->save($this->area, [RuleKind::CheckInWithin->value => new RuleValue(1.5, RuleUnit::Kilometres)]);
        $this->rules()->setException($this->gate, RuleKind::CheckInWithin, new RuleValue(800.0, RuleUnit::Metres));

        self::assertSame(800, $this->gate->getCatchmentM());
        self::assertSame(1500, $this->outpost->getCatchmentM());
    }

    private function reloadedArea(): AreaOfInterest
    {
        $area = $this->em->getRepository(AreaOfInterest::class)->findOneBy(['name' => 'demo reserve']);
        self::assertInstanceOf(AreaOfInterest::class, $area);

        return $area;
    }
}
