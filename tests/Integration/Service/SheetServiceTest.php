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
use Uhifadhi\Roster\Model\SheetCellKind;
use Uhifadhi\Roster\Model\SheetCoverState;
use Uhifadhi\Roster\Model\SheetWindow;
use Uhifadhi\Roster\Service\SheetService;
use Uhifadhi\Roster\Service\StationWatchService;
use Uhifadhi\Roster\Tests\Integration\IntegrationTestCase;

/**
 * THE SHEET, READ — people down, days across, per station.
 *
 * WHAT THIS TEST DEFENDS is the one thing the tab exists for: a gap shows
 * at once — AND IT BELONGS TO THE STATION. RULED 21 sep: a ranger is on a
 * shift or off, and what can be short is a place on a day against the
 * number it names. A station-day under the number that draws as an
 * ordinary quiet one is the tab failing at its job.
 */
final class SheetServiceTest extends IntegrationTestCase
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

    private function sheet(): SheetService
    {
        $service = static::getContainer()->get('test_public.'.SheetService::class);
        self::assertInstanceOf(SheetService::class, $service);

        return $service;
    }

    private function watches(): StationWatchService
    {
        $service = static::getContainer()->get('test_public.'.StationWatchService::class);
        self::assertInstanceOf(StationWatchService::class, $service);

        return $service;
    }

    private function window(int $weeks = 2): SheetWindow
    {
        return SheetWindow::of($this->monday, $weeks, new \DateTimeImmutable('today'));
    }

    /**
     * A STATION WITH NOBODY STATIONED AT IT STILL GETS A BAND. It is on
     * the area's books, and a sheet that quietly left it out could never
     * be used to notice it.
     */
    public function testEveryStationOnTheBooksGetsABand(): void
    {
        $this->aStation($this->area, 'escarpment roadside post', 'ST-12');
        $this->em->flush();

        $sheet = $this->sheet()->read($this->area, $this->window());

        self::assertCount(2, $sheet->bands);
        self::assertSame(['ST-01', 'ST-12'], array_map(static fn ($band) => $band->stationCode, $sheet->bands));
        self::assertSame(0, $sheet->bands[1]->rangers(), 'Nobody is stationed there, and the band says so.');
    }

    /** RANGERS DOWN, ONE ROW EACH, AND A CELL PER DAY OF THE WINDOW. */
    public function testTheSheetIsPeopleDownAndTheWindowAcross(): void
    {
        $sheet = $this->sheet()->read($this->area, $this->window(2));

        $band = $sheet->bands[0];
        self::assertSame(2, $band->rangers());
        self::assertSame(['Ada Example', 'Bea Example'], array_map(static fn ($row) => $row->personName, $band->rows));
        self::assertCount(14, $band->rows[0]->cells);

        $sheet = $this->sheet()->read($this->area, $this->window(4));
        self::assertCount(28, $sheet->bands[0]->rows[0]->cells);
    }

    /**
     * A DAY WITH A DUTY ON IT IS THE SHIFT'S OWN COLOUR — the stored slot,
     * so the same watch is the same colour on every tab.
     */
    public function testADutyDrawsTheShiftsOwnColour(): void
    {
        $this->em->persist(new Duty($this->area, $this->gate, $this->ada, 'day', $this->monday));
        $this->em->flush();

        $cell = $this->sheet()->read($this->area, $this->window())->bands[0]->rows[0]->cells[0];

        self::assertSame(SheetCellKind::Watch, $cell->kind);
        self::assertSame('day', $cell->shiftKey);
        self::assertIsInt($cell->colour);
        self::assertNotNull($cell->dutyUuid);
    }

    /**
     * AND A DAY THE STATION NEEDS COVERED AND NOBODY IS ON IS SHORT AT
     * THE STATION, NOT AT A RANGER. This is the assertion the tab exists
     * for, and RULED 21 sep it is made on the band's head row.
     */
    public function testADayTheStationNeedsAndNobodyIsOnIsShortAtTheStation(): void
    {
        $watch = $this->watches()->addToRoster($this->gate);
        $watch->expect(['day']);
        $watch->setNeedsPerShift(['day' => 2]);
        $this->em->flush();

        $band = $this->sheet()->read($this->area, $this->window())->bands[0];

        self::assertSame(14, $band->shortDays());
        self::assertSame(SheetCoverState::Nobody, $band->cover[0]->state);
        self::assertSame('0/2', $band->cover[0]->label());
        self::assertSame(SheetCellKind::Off, $band->rows[0]->cells[0]->kind, 'A ranger is on a shift or off, never a gap.');
    }

    /**
     * ONE ON OF THE TWO IT NEEDS IS SHORT; BOTH IS MET — and the figure
     * is counted PER SHIFT, so five on the day watch cannot cover a
     * night watch nobody is standing.
     */
    public function testCoverIsCountedPerShiftAndStatedAsOneFigure(): void
    {
        $watch = $this->watches()->addToRoster($this->gate);
        $watch->expect(['day', 'night']);
        $watch->setNeedsPerShift(['day' => 1, 'night' => 1]);
        $this->em->persist(new Duty($this->area, $this->gate, $this->ada, 'day', $this->monday));
        $this->em->persist(new Duty($this->area, $this->gate, $this->bea, 'day', $this->monday));
        $this->em->flush();

        $cover = $this->sheet()->read($this->area, $this->window())->bands[0]->cover[0];

        self::assertSame(SheetCoverState::Short, $cover->state, 'Two on the day watch do not stand the night watch.');
        self::assertSame('1/2', $cover->label(), 'Only the covered part of each shift counts.');
    }

    /** AND WHEN EVERY SHIFT HAS WHAT IT ASKED FOR, THE DAY IS MET. */
    public function testADayWithWhatTheStationAskedForIsMet(): void
    {
        $watch = $this->watches()->addToRoster($this->gate);
        $watch->expect(['day']);
        $watch->setNeedsPerShift(['day' => 1]);
        $this->em->persist(new Duty($this->area, $this->gate, $this->ada, 'day', $this->monday));
        $this->em->flush();

        $cover = $this->sheet()->read($this->area, $this->window())->bands[0]->cover[0];

        self::assertSame(SheetCoverState::Met, $cover->state);
        self::assertSame('1/1', $cover->label());
        self::assertSame(0, $this->sheet()->read($this->area, $this->window())->bands[0]->cover[1]->on ?? -1, 'And the next day, which nobody stands, is short.');
    }

    /**
     * A STATION THAT NAMES NO NUMBER EXPECTS NOTHING OF ANYBODY. Null and
     * zero are different facts: nothing asked reads as a dash, and a
     * fortnight of alarm ink at a station nobody has made a decision
     * about would be the sheet shouting at a reader who has done nothing
     * wrong.
     */
    public function testAStationThatNamesNoNumberIsNeverShort(): void
    {
        $band = $this->sheet()->read($this->area, $this->window())->bands[0];

        self::assertSame(0, $band->shortDays());
        self::assertSame(SheetCoverState::Nothing, $band->cover[0]->state);
        self::assertSame('—', $band->cover[0]->label());
        self::assertNull($band->cover[0]->needed);
        self::assertSame(SheetCellKind::Off, $band->rows[0]->cells[0]->kind);
    }

    /**
     * EACH RANGER STILL HOLDS THEIR SEAT AT THE STATION, in a stable
     * order — a seat that moved when somebody was renamed would slide a
     * whole station's ring the next time a pattern filled it.
     */
    public function testEveryRangerHoldsAStableSeatAtTheirStation(): void
    {
        $band = $this->sheet()->read($this->area, $this->window())->bands[0];

        self::assertSame(0, $band->rows[0]->seat);
        self::assertSame(1, $band->rows[1]->seat);
        self::assertSame(['Ada Example', 'Bea Example'], array_map(static fn ($row) => $row->personName, $band->rows));
    }

    /**
     * A HAND MARK IS ONE RANGER'S DAY, and it carries which of the two
     * things the hand did: stood them down, or left the seat open.
     */
    public function testAHandMarkIsOneRangersDayAndSaysWhichKind(): void
    {
        $this->em->persist(new EditedDay($this->gate, $this->monday, null, new \DateTimeImmutable(), $this->ada, true));
        $this->em->persist(new EditedDay($this->gate, $this->monday->modify('+1 day'), null, new \DateTimeImmutable(), $this->ada, false));
        $this->em->flush();

        $rows = $this->sheet()->read($this->area, $this->window())->bands[0]->rows;

        self::assertTrue($rows[0]->cells[0]->editedByHand);
        self::assertSame(SheetCellKind::Off, $rows[0]->cells[0]->kind, 'Stood down by hand draws nothing, and carries the mark.');
        self::assertTrue($rows[0]->cells[1]->editedByHand);
        self::assertSame(SheetCellKind::Off, $rows[0]->cells[1]->kind, 'Whatever the hand did, a ranger with no watch is off.');
        self::assertFalse($rows[1]->cells[0]->editedByHand, 'The mark is one ranger\'s, not the whole station\'s.');
    }

    /** THE SHEET'S OWN FIGURES COME OFF ITS OWN CELLS. */
    public function testTheFiguresCountWhatTheSheetDraws(): void
    {
        $this->em->persist(new Duty($this->area, $this->gate, $this->ada, 'day', $this->monday));
        $this->em->persist(new EditedDay($this->gate, $this->monday->modify('+1 day'), null, new \DateTimeImmutable(), $this->bea, false));
        $this->em->flush();

        $sheet = $this->sheet()->read($this->area, $this->window());

        self::assertSame(2, $sheet->rangers());
        self::assertSame(1, $sheet->daysPlanned());
        self::assertSame(0, $sheet->shortCover(), 'The station names no number, so nothing is short.');
        self::assertSame(1, $sheet->editedByHand());
    }

    /**
     * AND THE VERY FIRST RENDER OF A NEW AREA IS IN COLOUR.
     *
     * The shift list is SEEDED ON FIRST ASK, and the sheet is the first
     * thing an area opens. Read through the repository it came back
     * empty, every cell fell back to `--fog`, and a whole fortnight drew
     * grey — then came back in colour on the next request, which is the
     * kind of defect nobody can reproduce on the second look.
     */
    public function testTheFirstReadOfAnAreaThatHasNamedNoShiftIsStillInColour(): void
    {
        $fresh = $this->anArea();
        $station = $this->aStation($fresh, 'ridge gate post', 'ST-05');
        $person = $this->aPerson('cyd@example.test', 'Cyd', 'Example');
        $this->em->flush();
        $this->em->persist(
            new Posting()->setStation($station)->setPerson($person)->setSince(new \DateTimeImmutable('-1 year'))->setSource(PostingSource::WrittenHere),
        );
        $this->em->persist(new Duty($fresh, $station, $person, 'day', $this->monday));
        $this->em->flush();

        $cell = $this->sheet()->read($fresh, $this->window())->bands[0]->rows[0]->cells[0];

        self::assertSame(SheetCellKind::Watch, $cell->kind);
        self::assertIsInt($cell->colour, 'A cell with no slot resolves to nothing and draws grey.');
        self::assertSame('day', $cell->shiftLabel, 'And it reads the area\'s word, not the stored key.');
    }

    /** ONE STATION ONLY, when the head's filter names one. */
    public function testTheFilterNarrowsTheSheetToOneStation(): void
    {
        $this->aStation($this->area, 'fig tree ranger post', 'ST-02');
        $this->em->flush();

        $sheet = $this->sheet()->read($this->area, $this->window())->only((string) $this->gate->getUuidString());

        self::assertCount(1, $sheet->bands);
        self::assertSame('ST-01', $sheet->bands[0]->stationCode);
        self::assertSame(2, $sheet->stations, 'The count is still the area\'s, so the chip can say what it is filtering out of.');
    }
}
