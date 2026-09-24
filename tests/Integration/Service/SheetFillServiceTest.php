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
use Uhifadhi\Bundle\AreaBundle\Entity\Posting;
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Bundle\AreaBundle\Enum\PostingSource;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Roster\Entity\Duty;
use Uhifadhi\Roster\Entity\EditedDay;
use Uhifadhi\Roster\Entity\Pattern;
use Uhifadhi\Roster\Enum\ForbiddenDay;
use Uhifadhi\Roster\Enum\NightThenDay;
use Uhifadhi\Roster\Enum\RuleKind;
use Uhifadhi\Roster\Enum\RuleUnit;
use Uhifadhi\Roster\Model\Cycle;
use Uhifadhi\Roster\Model\RuleValue;
use Uhifadhi\Roster\Service\PatternService;
use Uhifadhi\Roster\Service\SheetFillService;
use Uhifadhi\Roster\Service\ShiftRuleService;
use Uhifadhi\Roster\Service\StationWatchService;
use Uhifadhi\Roster\Tests\Integration\IntegrationTestCase;

/**
 * FILLING A STATION FROM A PATTERN — and the four rules it obeys.
 *
 * WHAT THIS TEST DEFENDS is that the rules are rules. A fill that
 * quietly stood somebody on a watch their rest rule forbids would make
 * the Watches card decoration, and a fill that overwrote a day somebody
 * edited would make the by-hand menu a suggestion.
 */
final class SheetFillServiceTest extends IntegrationTestCase
{
    private AreaOfInterest $area;
    private Station $gate;
    private User $ada;
    private \DateTimeImmutable $today;

    protected function setUp(): void
    {
        parent::setUp();

        $this->area = $this->anArea();
        $this->gate = $this->aStation($this->area, 'eastgate post', 'ST-01');
        $this->theShiftVocabulary($this->area);
        $this->ada = $this->aPerson('ada@example.test', 'Ada', 'Example');
        $this->em->flush();

        $this->em->persist(
            new Posting()->setStation($this->gate)->setPerson($this->ada)
                ->setSince(new \DateTimeImmutable('-1 year'))->setSource(PostingSource::WrittenHere),
        );

        $watch = $this->watches()->addToRoster($this->gate);
        $watch->expect(['day', 'night']);
        $this->em->flush();

        $this->today = new \DateTimeImmutable('today');
    }

    private function fills(): SheetFillService
    {
        $service = static::getContainer()->get('test_public.'.SheetFillService::class);
        self::assertInstanceOf(SheetFillService::class, $service);

        return $service;
    }

    private function watches(): StationWatchService
    {
        $service = static::getContainer()->get('test_public.'.StationWatchService::class);
        self::assertInstanceOf(StationWatchService::class, $service);

        return $service;
    }

    private function patterns(): PatternService
    {
        $service = static::getContainer()->get('test_public.'.PatternService::class);
        self::assertInstanceOf(PatternService::class, $service);

        return $service;
    }

    private function rules(): ShiftRuleService
    {
        $service = static::getContainer()->get('test_public.'.ShiftRuleService::class);
        self::assertInstanceOf(ShiftRuleService::class, $service);

        return $service;
    }

    /** @param list<string> $positions */
    private function aPattern(array $positions): Pattern
    {
        return $this->patterns()->create($this->area, Cycle::of($positions));
    }

    /** @return list<Duty> */
    private function duties(): array
    {
        return $this->em->getRepository(Duty::class)->findBy(['station' => $this->gate], ['onDay' => 'ASC']);
    }

    /**
     * THE HORIZON IS THE AREA'S OWN RULE. Six weeks is the standard, so
     * a ring of nothing but day watches fills forty-two days.
     */
    public function testItFillsAsFarAheadAsTheRuleSays(): void
    {
        $plan = $this->fills()->fill($this->gate, $this->aPattern(['day']), $this->today);

        self::assertSame(42, $plan->days);
        self::assertEquals($this->today->modify('+41 days'), $plan->through);
        self::assertCount(42, $this->duties());
    }

    /** AND THE RULE IS A RULE: three weeks fills twenty-one days. */
    public function testTheHorizonFollowsTheRule(): void
    {
        $this->rules()->save($this->area, [RuleKind::FillAhead->value => new RuleValue(3.0, RuleUnit::Weeks)]);

        self::assertSame(21, $this->fills()->preview($this->gate, $this->aPattern(['day']), $this->today)->days);
    }

    /**
     * A PREVIEW WRITES NOTHING. The number somebody reads before pressing
     * the button is the number they get, and it costs them nothing to
     * ask.
     */
    public function testAPreviewWritesNothing(): void
    {
        $plan = $this->fills()->preview($this->gate, $this->aPattern(['day']), $this->today);

        self::assertSame(42, $plan->days);
        self::assertSame([], $this->duties());
    }

    /**
     * IT NEVER WRITES THE PAST. A start date behind today is honoured
     * from today onward: somebody typing last monday means "from the
     * ring's own beginning", not "rewrite last week".
     */
    public function testItNeverWritesThePast(): void
    {
        $plan = $this->fills()->fill($this->gate, $this->aPattern(['day']), $this->today->modify('-10 days'));

        self::assertEquals($this->today, $plan->from);
        foreach ($this->duties() as $duty) {
            self::assertGreaterThanOrEqual($this->today, $duty->getOnDay());
        }
    }

    /** IT NEVER OVERWRITES A DAY SOMEBODY EDITED, and says how many it left. */
    public function testItLeavesADayEditedByHandExactlyAsItIs(): void
    {
        $this->em->persist(new EditedDay($this->gate, $this->today, null, new \DateTimeImmutable(), $this->ada, true));
        $this->em->flush();

        $plan = $this->fills()->fill($this->gate, $this->aPattern(['day']), $this->today);

        self::assertSame(1, $plan->leftAlone);
        self::assertSame(41, $plan->days);
        foreach ($this->duties() as $duty) {
            self::assertNotEquals($this->today, $duty->getOnDay(), 'The edited day keeps its answer.');
        }
    }

    /** AND IT LEAVES A DAY THAT IS ALREADY FILLED ALONE. */
    public function testADayAlreadyStandingIsNotFilledTwice(): void
    {
        $this->em->persist(new Duty($this->area, $this->gate, $this->ada, 'day', $this->today));
        $this->em->flush();

        $this->fills()->fill($this->gate, $this->aPattern(['day']), $this->today);

        self::assertCount(42, $this->duties(), 'Forty-one written, and the one that was already there.');
    }

    /**
     * A DAY WATCH THE MORNING AFTER A NIGHT WATCH IS REFUSED — and the
     * day is LEFT UNFILLED rather than filled wrongly, which is the
     * area's standard answer.
     */
    public function testANightThenADayIsLeftUnfilled(): void
    {
        $plan = $this->fills()->fill($this->gate, $this->aPattern(['night', 'day']), $this->today);

        self::assertGreaterThan(0, $plan->forbidden, 'Every second day of this ring is a day after a night.');

        foreach ($this->duties() as $duty) {
            self::assertSame('night', $duty->getShiftKey(), 'Only the nights could be written.');
        }
    }

    /**
     * UNLESS THE AREA ALLOWS IT.
     *
     * THE RING IS NIGHT THEN OFFICE, and that is not a detail. A night
     * ends at 06:00 and a day watch begins at 06:00, so back-to-back
     * they leave no rest at all and no area could ever permit the pair;
     * the office watch begins at 07:30 and leaves ninety minutes, which
     * is a pair an area genuinely might want and the rest rule genuinely
     * might allow. Testing the choice against a pair the OTHER rule
     * forbids would be testing nothing.
     */
    public function testAnAreaMayAllowANightThenADay(): void
    {
        $this->rules()->save($this->area, [
            RuleKind::NightThenDay->value => NightThenDay::Allow,
            RuleKind::RestBetween->value => new RuleValue(1.0, RuleUnit::Hours),
        ]);
        $this->watches()->addToRoster($this->gate)->expect(['night', 'office']);
        $this->em->flush();

        $plan = $this->fills()->fill($this->gate, $this->aPattern(['night', 'office']), $this->today);

        self::assertSame(0, $plan->forbidden);
        self::assertSame(42, $plan->days);
    }

    /** AND REFUSES IT WHERE IT SAYS NEVER, whatever the rest allows. */
    public function testAnAreaThatSaysNeverRefusesTheMorningAfter(): void
    {
        $this->rules()->save($this->area, [RuleKind::RestBetween->value => new RuleValue(1.0, RuleUnit::Hours)]);

        $plan = $this->fills()->fill($this->gate, $this->aPattern(['night', 'office']), $this->today);

        self::assertSame(21, $plan->forbidden, 'Every office watch follows a night.');
        self::assertSame(21, $plan->days, 'Only the nights could be written.');
    }

    /**
     * AND A FORBIDDEN DAY MAY BE FILLED AND FLAGGED INSTEAD — the other
     * honest answer, and the duty says which rule it stands against.
     */
    public function testAForbiddenDayMayBeFilledAndFlagged(): void
    {
        $this->rules()->save($this->area, [RuleKind::ForbiddenDay->value => ForbiddenDay::FilledAndFlagged]);

        $plan = $this->fills()->fill($this->gate, $this->aPattern(['night', 'day']), $this->today);

        self::assertGreaterThan(0, $plan->forbidden);
        self::assertSame(42, $plan->days, 'Filled, and every one of them flagged.');

        $flagged = array_values(array_filter($this->duties(), static fn (Duty $duty): bool => null !== $duty->getNote()));
        self::assertNotSame([], $flagged);
        self::assertStringContainsString('night watch', (string) $flagged[0]->getNote());
    }

    /**
     * THE REST RULE IS A RULE. Eleven hours between a watch ending and
     * the next starting is the standard, and a ring that breaks it fills
     * nothing it should not.
     */
    public function testTheRestRuleRefusesTooShortAGap(): void
    {
        // A day watch is 06:00–18:00, so day-after-day leaves twelve
        // hours of rest. Ask for thirteen and no second day can stand.
        $this->rules()->save($this->area, [RuleKind::RestBetween->value => new RuleValue(13.0, RuleUnit::Hours)]);

        $plan = $this->fills()->fill($this->gate, $this->aPattern(['day']), $this->today);

        /*
         * EVERY OTHER DAY, and that is the rule working rather than a
         * miscount. A day left unfilled leaves the day after it with no
         * watch in front of it, so the rest rule has nothing to refuse
         * and it stands: the ring settles into on, off, on, off.
         */
        self::assertSame(21, $plan->forbidden);
        self::assertSame(21, $plan->days);
    }

    /**
     * A LATER START DATE CHANGES ONLY THE DAYS AFTER IT. The ring stays
     * anchored where the station was first filled from, so a second run
     * does not slide the watches already standing.
     */
    public function testALaterStartDateDoesNotSlideTheRing(): void
    {
        $pattern = $this->aPattern(['day', Cycle::OFF]);
        $this->fills()->fill($this->gate, $pattern, $this->today);
        $this->watches()->addToRoster($this->gate)->filledBy($pattern);
        $this->em->flush();

        $standing = [];
        foreach ($this->duties() as $duty) {
            $standing[] = $duty->getOnDay()->format('Y-m-d');
        }

        $this->fills()->fill($this->gate, $pattern, $this->today->modify('+3 days'));

        $after = [];
        foreach ($this->duties() as $duty) {
            $after[] = $duty->getOnDay()->format('Y-m-d');
        }

        // A SECOND RUN REACHES FURTHER — its horizon starts three days
        // later — so the test is that everything already standing is
        // still standing on exactly the day it was, not that the set is
        // unchanged.
        self::assertSame($standing, \array_slice($after, 0, \count($standing)), 'Nothing moved.');
        self::assertGreaterThanOrEqual(\count($standing), \count($after));
    }

    /** AND THE STATION REMEMBERS WHERE IT STARTED AND HOW FAR IT REACHED. */
    public function testTheFillRecordsTheAnchorAndTheHorizon(): void
    {
        $plan = $this->fills()->fill($this->gate, $this->aPattern(['day']), $this->today);

        $watch = $this->watches()->addToRoster($this->gate);
        self::assertEquals($this->today, $watch->getPatternFrom());
        self::assertEquals($plan->through, $watch->getFilledThrough());
    }
}
