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
use Uhifadhi\Roster\Model\Cycle;
use Uhifadhi\Roster\Model\SheetCell;
use Uhifadhi\Roster\Model\SheetCellKind;
use Uhifadhi\Roster\Model\SheetWindow;
use Uhifadhi\Roster\Service\PatternService;
use Uhifadhi\Roster\Service\SheetDayService;
use Uhifadhi\Roster\Service\SheetService;
use Uhifadhi\Roster\Service\StationWatchService;
use Uhifadhi\Roster\Tests\Integration\IntegrationTestCase;

/**
 * WHAT EACH ITEM ON THE BY-HAND MENU LEAVES BEHIND.
 *
 * THE ASSERTION IS THE SHEET, NOT THE SERVICE'S RETURN. The design's own
 * answer to "what happens after each action" is four frames ending in the
 * sheet that results, so every test here runs the verb and then READS THE
 * SHEET BACK — the same read the tab does. A verb that stored the right
 * rows and drew the wrong cell would be the defect the frames exist to
 * prevent.
 *
 * AND THE ONE THAT KEEPS BEING READ WRONGLY IS THE DAY OFF. RULED 21
 * sep: the CELL is the same either way — the watch goes, the day draws
 * nothing, and the hand mark says a person decided it. What differs is
 * the STATION: standing somebody down on a day the place needs covered
 * leaves that place short, and the band's head row says so. A gap
 * belongs to the station, which is why one verb has two tests and
 * neither of them is about the ranger.
 */
final class SheetDayServiceTest extends IntegrationTestCase
{
    private AreaOfInterest $area;
    private Station $gate;
    private User $ada;
    private User $bea;
    private \DateTimeImmutable $monday;

    protected function setUp(): void
    {
        parent::setUp();

        $this->area = $this->anArea();
        $this->gate = $this->aStation($this->area, 'eastgate post', 'ST-01');
        $this->theShiftVocabulary($this->area);
        $this->ada = $this->aPerson('ada@example.test', 'Ada', 'Example');
        $this->bea = $this->aPerson('bea@example.test', 'Bea', 'Example');
        $this->em->flush();

        foreach ([$this->ada, $this->bea] as $person) {
            $this->em->persist(
                new Posting()->setStation($this->gate)->setPerson($person)->setSince(new \DateTimeImmutable('-1 year'))->setSource(PostingSource::WrittenHere),
            );
        }
        $this->em->flush();

        $this->monday = new \DateTimeImmutable('today')->modify('monday this week');
    }

    private function days(): SheetDayService
    {
        $service = static::getContainer()->get('test_public.'.SheetDayService::class);
        self::assertInstanceOf(SheetDayService::class, $service);

        return $service;
    }

    private function sheet(): SheetService
    {
        $service = static::getContainer()->get('test_public.'.SheetService::class);
        self::assertInstanceOf(SheetService::class, $service);

        return $service;
    }

    /** A RING THAT ASKS FOR A DAY WATCH EVERY DAY, so every cell is one the cycle expects cover on. */
    private function theCycleExpectsCoverEveryDay(): void
    {
        $watches = static::getContainer()->get('test_public.'.StationWatchService::class);
        self::assertInstanceOf(StationWatchService::class, $watches);
        $patterns = static::getContainer()->get('test_public.'.PatternService::class);
        self::assertInstanceOf(PatternService::class, $patterns);

        $watch = $watches->addToRoster($this->gate);
        $watch->expect(['day']);
        // AND THE STATION SAYS HOW MANY IT NEEDS. RULED 21 sep: cover is
        // counted against the number the place names, not against a ring.
        $watch->setNeedsPerShift(['day' => 1]);
        $patterns->applyTo($watch, $patterns->create($this->area, Cycle::of(['day'])));
        $watch->filledFrom($this->monday, $this->monday->modify('+27 days'));
        $this->em->flush();
    }

    private function aDuty(User $person, string $shift = 'night'): Duty
    {
        $duty = new Duty($this->area, $this->gate, $person, $shift, $this->monday);
        $this->em->persist($duty);
        $this->em->flush();

        return $duty;
    }

    /** MONDAY'S CELL ON ONE RANGER'S ROW, read back the way the tab reads it. */
    private function mondayOf(User $person): SheetCell
    {
        $sheet = $this->sheet()->read($this->area, SheetWindow::of($this->monday, 2, new \DateTimeImmutable('today')));
        foreach ($sheet->bands[0]->rows as $row) {
            if ($row->personUuid === (string) $person->getUuidString()) {
                return $row->cells[0];
            }
        }

        self::fail('That ranger has no row on the sheet.');
    }

    /** HOW MANY OF THIS STATION'S DAYS STAND UNDER THE NUMBER IT NAMES. */
    private function shortDaysAtTheStation(): int
    {
        return $this->sheet()->read($this->area, SheetWindow::of($this->monday, 2, new \DateTimeImmutable('today')))->bands[0]->shortDays();
    }

    /** CHANGE THE SHIFT — the cell wears the new shift and carries the hand mark. */
    public function testChangingTheShiftLeavesTheWatchWearingItAndMarked(): void
    {
        $this->theCycleExpectsCoverEveryDay();
        $duty = $this->aDuty($this->ada);

        $this->days()->changeShift($duty, 'day', null);

        $cell = $this->mondayOf($this->ada);
        self::assertSame(SheetCellKind::Watch, $cell->kind);
        self::assertSame('day', $cell->shiftKey);
        self::assertTrue($cell->editedByHand, 'A day a person decided is a day a fill must not decide again.');
    }

    /**
     * MOVE TO SOMEONE ELSE — the day sits on the other ranger's row, and
     * the origin draws NOTHING. A ranger is on a shift or off; what the
     * move can leave short is the STATION, and the head row says so.
     */
    public function testMovingTheDayLeavesTheOriginOffAndTheStationShort(): void
    {
        $this->theCycleExpectsCoverEveryDay();
        $duty = $this->aDuty($this->ada);

        $this->days()->moveTo($duty, $this->bea, null);

        $taken = $this->mondayOf($this->bea);
        self::assertSame(SheetCellKind::Watch, $taken->kind);
        self::assertTrue($taken->editedByHand);

        $origin = $this->mondayOf($this->ada);
        self::assertSame(SheetCellKind::Off, $origin->kind, 'A ranger is on a shift or off, and never a gap.');
        self::assertTrue($origin->editedByHand);
    }

    /** AND WHERE THE CYCLE ASKED NOTHING, the origin is simply empty. */
    public function testMovingTheDayLeavesAnEmptyOriginWhereNothingWasExpected(): void
    {
        $duty = $this->aDuty($this->ada);

        $this->days()->moveTo($duty, $this->bea, null);

        $origin = $this->mondayOf($this->ada);
        self::assertSame(SheetCellKind::Off, $origin->kind, 'No number named, no expectation, no alarm ink.');
        self::assertTrue($origin->editedByHand);
        self::assertSame(0, $this->shortDaysAtTheStation());
    }

    /**
     * GIVE THE DAY OFF — the watch goes, the cell draws nothing and
     * carries the hand mark, and the STATION's day goes short because
     * nobody is standing what it needs.
     */
    public function testGivingTheDayOffDrawsNothingAndLeavesTheStationShort(): void
    {
        $this->theCycleExpectsCoverEveryDay();
        $duty = $this->aDuty($this->ada, 'day');
        $before = $this->shortDaysAtTheStation();

        $this->days()->giveTheDayOff($duty, null);

        $cell = $this->mondayOf($this->ada);
        self::assertSame(SheetCellKind::Off, $cell->kind);
        self::assertTrue($cell->editedByHand);
        self::assertSame($before + 1, $this->shortDaysAtTheStation(), 'The station head counts one more short day.');
    }

    /** AND WHERE THE STATION NAMES NO NUMBER, nothing goes short at all. */
    public function testGivingTheDayOffLeavesNothingShortWhereNothingWasExpected(): void
    {
        $duty = $this->aDuty($this->ada);

        $this->days()->giveTheDayOff($duty, null);

        $cell = $this->mondayOf($this->ada);
        self::assertSame(SheetCellKind::Off, $cell->kind);
        self::assertTrue($cell->editedByHand);
        self::assertSame(0, $this->shortDaysAtTheStation());
    }

    /** CLEAR THE HAND MARK — the mark goes, the shift stays, the next fill owns the day again. */
    public function testClearingTheMarkHandsTheDayBackToThePattern(): void
    {
        $duty = $this->aDuty($this->ada);
        $this->days()->changeShift($duty, 'day', null);

        $this->days()->clearTheMark($this->gate, $this->monday, $this->ada);

        $cell = $this->mondayOf($this->ada);
        self::assertSame(SheetCellKind::Watch, $cell->kind);
        self::assertSame('day', $cell->shiftKey, 'The shift the hand chose stays.');
        self::assertFalse($cell->editedByHand);
    }

    /** AND THE MARK IS ONE RANGER'S — clearing Ada's leaves Bea's alone. */
    public function testClearingOneRangersMarkLeavesTheOthersStanding(): void
    {
        $this->days()->changeShift($this->aDuty($this->ada), 'day', null);
        $this->days()->changeShift($this->aDuty($this->bea), 'day', null);

        $this->days()->clearTheMark($this->gate, $this->monday, $this->ada);

        self::assertFalse($this->mondayOf($this->ada)->editedByHand);
        self::assertTrue($this->mondayOf($this->bea)->editedByHand);
    }
}
