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
use Uhifadhi\Bundle\AreaBundle\Enum\PostingSource;
use Uhifadhi\Bundle\AreaBundle\Service\PostingService;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Roster\Entity\Absence;
use Uhifadhi\Roster\Entity\Duty;
use Uhifadhi\Roster\Enum\AbsenceKind;
use Uhifadhi\Roster\Enum\DutyState;
use Uhifadhi\Roster\Enum\SwapState;
use Uhifadhi\Roster\Model\ShiftMark;
use Uhifadhi\Roster\Service\MyRosterService;
use Uhifadhi\Roster\Service\StationWatchService;
use Uhifadhi\Roster\Service\SwapService;
use Uhifadhi\Roster\Tests\Functional\EveryAreaRunsTheRoster;
use Uhifadhi\Roster\Tests\Integration\IntegrationTestCase;

/**
 * A PERSON'S OWN ROSTER (#19; design variants-my-dashboard, ME·09 and
 * roster.html, approved 28 Sep 2026): their week at the post they are posted
 * at, everybody posted there, what the post expects, their next shifts,
 * their swaps, who is away, and their own absences this year.
 *
 * THE INSTANT IS PINNED to a Monday mid-morning, after the day watch began
 * and long before the night one, so "next" and "today" mean one thing.
 */
final class MyRosterServiceTest extends IntegrationTestCase
{
    use EveryAreaRunsTheRoster;

    private const string NOW = '2026-09-28 10:30';

    private AreaOfInterest $area;
    private Station $post;
    private User $me;
    private User $head;
    private User $tumaini;
    private User $upendo;
    private \DateTimeImmutable $monday;

    protected function setUp(): void
    {
        parent::setUp();

        $this->monday = new \DateTimeImmutable('2026-09-28');
        $this->area = $this->anArea();
        $this->post = $this->aStation($this->area, 'north gate post', 'ST-01');
        $elsewhere = $this->aStation($this->area, 'rim outpost', 'ST-02');
        $this->theShiftVocabulary($this->area);
        $this->me = $this->aPerson('me@example.test', 'Neema', 'Example');
        $this->head = $this->aPerson('head@example.test', 'Juma', 'Headman');
        $this->tumaini = $this->aPerson('tumaini@example.test', 'Tumaini', 'Other');
        $this->upendo = $this->aPerson('upendo@example.test', 'Upendo', 'Night');
        $stranger = $this->aPerson('stranger@example.test', 'Salma', 'Stranger');
        $this->em->flush();

        $postings = $this->service(PostingService::class);
        self::assertInstanceOf(PostingService::class, $postings);
        $postings->appointLeader($postings->post($this->post, $this->head, PostingSource::WrittenHere));
        foreach ([$this->me, $this->tumaini, $this->upendo] as $person) {
            $postings->post($this->post, $person, PostingSource::WrittenHere);
        }
        $postings->post($elsewhere, $stranger, PostingSource::WrittenHere);

        $watches = $this->service(StationWatchService::class);
        self::assertInstanceOf(StationWatchService::class, $watches);
        $watches->addToRoster($this->post)->expect(['day', 'night'])->setNeedsPerShift(['day' => 2, 'night' => 2]);

        // MY WEEK: day, day, off, night, then leave from friday to sunday.
        $this->duty($this->me, 'day', 0);
        $this->duty($this->me, 'day', 1);
        $this->duty($this->me, 'night', 3);
        $this->duty($this->me, 'day', 4); // under the leave: a hole, never a watch
        $this->em->persist(new Absence($this->area, $this->me, $this->monday->modify('+4 days'), $this->monday->modify('+6 days'), AbsenceKind::Leave));

        // MONDAY AT THE POST: the head and Tumaini on the day watch with me,
        // Upendo on the night, and a stranger at another post on the day.
        $this->duty($this->head, 'day', 0);
        $this->duty($this->tumaini, 'day', 0);
        $this->duty($this->upendo, 'night', 0);
        $this->em->persist(new Duty($this->area, $elsewhere, $stranger, 'day', $this->monday));
        // A cancelled watch asks nobody to be anywhere.
        $this->duty($this->tumaini, 'night', 2)->setState(DutyState::Cancelled);

        $this->em->flush();
        $this->everyAreaRunsTheRoster($this->em);
    }

    public function testMyWeekRunsMondayToSundayWithOneMarkADay(): void
    {
        $roster = $this->roster();

        self::assertSame('north gate post', $roster->station->getName());
        self::assertCount(7, $roster->days);
        self::assertSame('2026-09-28', $roster->days[0]->format('Y-m-d'));
        self::assertSame(
            ['day', 'day', 'off', 'night', 'leave', 'leave', 'leave'],
            array_map(static fn (array $marks): string => implode('+', array_map(static fn (ShiftMark $m): string => $m->kind, $marks)), $roster->mine),
        );
        self::assertSame('leave', $roster->mine[4][0]->label);
    }

    public function testMyNextShiftIsTheFirstThatHasNotBegun(): void
    {
        $next = $this->roster()->next;

        self::assertNotNull($next);
        self::assertSame('2026-09-29 06:00', $next->startsAt->format('Y-m-d H:i'));
        self::assertSame('day', $next->mark->label);
        self::assertSame('06:00–18:00', $next->mark->window);
    }

    public function testMyComingShiftsAreTheWeekAheadAndNeverOneOnLeave(): void
    {
        $coming = array_map(
            static fn ($shift): string => $shift->startsAt->format('D H:i'),
            $this->roster()->coming,
        );

        self::assertSame(['Tue 06:00', 'Thu 18:00'], $coming);
    }

    public function testOnWithMeTodayIsMyPostAndMyWatchWithTheHeadFirst(): void
    {
        self::assertSame(['J. Headman (head)', 'T. Other'], $this->roster()->onWithMe);
    }

    public function testTheTableIsEverybodyPostedHereWithMyRowMarked(): void
    {
        $rows = $this->roster()->rows;

        self::assertCount(4, $rows, 'Everybody posted here, and nobody posted elsewhere.');
        self::assertTrue($rows[0]->me, 'My row leads the table.');
        $heads = array_values(array_filter($rows, static fn ($row): bool => $row->head));
        self::assertCount(1, $heads);
        self::assertSame('J. Headman', $heads[0]->name);
        $tumaini = array_values(array_filter($rows, static fn ($row): bool => 'T. Other' === $row->name))[0];
        self::assertSame('off', $tumaini->days[2][0]->kind, 'A cancelled watch is a day off.');
    }

    /** "on duty · day · night" per day, against what the post expects. */
    public function testTheCountRowCountsThePostsOwnWatchesAgainstWhatItExpects(): void
    {
        $roster = $this->roster();

        self::assertSame(['day' => 'day', 'night' => 'night'], $roster->countedShifts);
        self::assertSame(['onDuty' => 4, 'byShift' => ['day' => 3, 'night' => 1]], $roster->counts[0]);
        self::assertSame(['onDuty' => 0, 'byShift' => ['day' => 0, 'night' => 0]], $roster->counts[4], 'A watch under leave is not a watch.');
        self::assertSame(['day' => 2, 'night' => 2], $roster->expects);
    }

    public function testTheFactbandSaysTheWatchesAndMyWeek(): void
    {
        $roster = $this->roster();

        self::assertSame(['day 06–18', 'night 18–06'], $roster->watches);
        self::assertSame(3, $roster->myShifts);
        self::assertSame(36 * 60, $roster->myMinutes);
        self::assertSame(4, \count($roster->rows));
    }

    public function testAwayFromThePostIsThisWeeksAbsences(): void
    {
        $away = $this->roster()->away;

        self::assertCount(1, $away);
        self::assertTrue($away[0]['me']);
        self::assertSame('leave', $away[0]['kind']);
    }

    public function testMySwapsCarryTheirState(): void
    {
        $swaps = $this->service(SwapService::class);
        self::assertInstanceOf(SwapService::class, $swaps);
        $mine = $this->em->getRepository(Duty::class)->findOneBy(['person' => $this->me, 'shiftKey' => 'night']);
        self::assertInstanceOf(Duty::class, $mine);
        $swaps->offer($mine, $this->tumaini, $this->me);
        $this->em->flush();

        $roster = $this->roster();

        self::assertCount(1, $roster->swaps);
        self::assertSame(SwapState::Offered, $roster->swaps[0]->state);
        self::assertSame('T. Other', $roster->swaps[0]->other);
        self::assertSame('T. Other', $roster->swaps[0]->waitingFor);
        self::assertSame('night', $roster->swaps[0]->shiftLabel);
    }

    public function testMyLeaveIsThisYearsAbsences(): void
    {
        $this->em->persist(new Absence($this->area, $this->me, new \DateTimeImmutable('2025-12-30'), new \DateTimeImmutable('2026-01-02'), AbsenceKind::Sick));
        $this->em->persist(new Absence($this->area, $this->me, new \DateTimeImmutable('2025-06-01'), new \DateTimeImmutable('2025-06-03'), AbsenceKind::Leave));
        $this->em->flush();

        $leave = $this->readings()->leaveFor((string) $this->me->getUuidString(), new \DateTimeImmutable(self::NOW));

        self::assertSame(2026, $leave->year);
        self::assertCount(2, $leave->absences, 'Last year\'s is not this year\'s.');
        self::assertSame(AbsenceKind::Sick, $leave->absences[0]->getKind());
        // Two days of the new year's sick leave, and three of the leave.
        self::assertSame(5, $leave->daysAway);
    }

    public function testSomebodyPostedNowhereHasNoRoster(): void
    {
        $loose = $this->aPerson('loose@example.test', 'Loose', 'Nowhere');
        $this->em->flush();

        self::assertNull($this->readings()->for((string) $loose->getUuidString(), new \DateTimeImmutable(self::NOW)));
    }

    private function roster(): \Uhifadhi\Roster\Model\MyRoster
    {
        $roster = $this->readings()->for((string) $this->me->getUuidString(), new \DateTimeImmutable(self::NOW));
        self::assertNotNull($roster);

        return $roster;
    }

    private function duty(User $person, string $shift, int $dayOfWeek): Duty
    {
        $duty = new Duty($this->area, $this->post, $person, $shift, $this->monday->modify(\sprintf('+%d days', $dayOfWeek)));
        $this->em->persist($duty);

        return $duty;
    }

    private function readings(): MyRosterService
    {
        $service = $this->service(MyRosterService::class);
        self::assertInstanceOf(MyRosterService::class, $service);

        return $service;
    }
}
