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
use Uhifadhi\Contracts\Entity\UserInterface;
use Uhifadhi\Roster\Entity\Absence;
use Uhifadhi\Roster\Entity\Duty;
use Uhifadhi\Roster\Entity\EditedDay;
use Uhifadhi\Roster\Entity\Rotation;
use Uhifadhi\Roster\Entity\RotationPoolMember;
use Uhifadhi\Roster\Enum\AbsenceKind;
use Uhifadhi\Roster\Enum\DutyState;
use Uhifadhi\Roster\Enum\RotationScope;
use Uhifadhi\Roster\Exception\RotationCannotGenerate;
use Uhifadhi\Roster\Model\Cycle;
use Uhifadhi\Roster\Repository\DutyRepository;
use Uhifadhi\Roster\Service\RotationGenerator;
use Uhifadhi\Roster\Tests\Integration\IntegrationTestCase;

/**
 * The ruled authoring model against a real database: a pattern generates, a
 * day is edited, and a later run never undoes the edit.
 */
final class RotationGeneratorTest extends IntegrationTestCase
{
    private AreaOfInterest $area;
    private Station $gate;
    /** @var list<UserInterface> */
    private array $pool = [];
    private Rotation $rotation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->area = $this->anArea();
        $this->gate = $this->aStation($this->area, 'north gate post', 'ST-01');
        $this->theShiftVocabulary($this->area);

        // The design's own ring: two days, two nights, one off, out of five.
        $this->rotation = new Rotation(
            $this->area,
            RotationScope::Post,
            Cycle::of(['day', 'day', 'night', 'night', Cycle::OFF]),
            new \DateTimeImmutable('2026-09-14'),
            ['day' => 2, 'night' => 2],
            42,
        )->standAt($this->gate);
        $this->em->persist($this->rotation);

        foreach (['ada', 'ben', 'cleo', 'dai', 'esi'] as $position => $name) {
            $person = $this->aPerson($name.'@example.test', ucfirst($name));
            $this->pool[] = $person;
            $this->em->persist(new RotationPoolMember($this->rotation, $person, $position));
        }

        $this->em->flush();
    }

    private function generator(): RotationGenerator
    {
        $generator = $this->service(RotationGenerator::class);
        self::assertInstanceOf(RotationGenerator::class, $generator);

        return $generator;
    }

    private function duties(): DutyRepository
    {
        $duties = $this->service(DutyRepository::class);
        self::assertInstanceOf(DutyRepository::class, $duties);

        return $duties;
    }

    /**
     * @return array<string, int> shift key => how many duties, on one day
     */
    private function countsOn(string $day): array
    {
        $counts = [];
        foreach ($this->duties()->findByStationBetween($this->gate, new \DateTimeImmutable($day), new \DateTimeImmutable($day)) as $duty) {
            $counts[$duty->getShiftKey()] = ($counts[$duty->getShiftKey()] ?? 0) + 1;
        }
        ksort($counts);

        return $counts;
    }

    public function testItWritesTheWholeWindowAsPlannedDuties(): void
    {
        $run = $this->generator()->generate($this->rotation, new \DateTimeImmutable('2026-09-14'), new \DateTimeImmutable('2026-09-20'));

        // Seven days, four watches a day.
        self::assertSame(28, $run->created);
        self::assertSame(['day' => 2, 'night' => 2], $this->countsOn('2026-09-19'));

        foreach ($this->duties()->findByStationBetween($this->gate, new \DateTimeImmutable('2026-09-14'), new \DateTimeImmutable('2026-09-20')) as $duty) {
            // GENERATING IS NOT TELLING ANYBODY. Publishing is a separate act,
            // so a six-week horizon does not promise six weeks of watches.
            self::assertSame(DutyState::Planned, $duty->getState());
            self::assertSame($this->rotation, $duty->getRotation());
        }
    }

    /**
     * How far the generator has run is what the Configure page reads back as
     * "Generated to wed 28 oct", so it has to be stored and not recomputed.
     */
    public function testItRecordsHowFarItHasRun(): void
    {
        $this->generator()->generate($this->rotation, new \DateTimeImmutable('2026-09-14'), new \DateTimeImmutable('2026-09-20'));

        self::assertSame('2026-09-20', $this->rotation->getGeneratedThrough()?->format('Y-m-d'));
    }

    /**
     * WITHOUT A THROUGH DATE THE ROTATION'S OWN HORIZON DECIDES — six weeks,
     * three months or the end of the year, whichever the editor wrote as a
     * number of days.
     */
    public function testTheRotationsOwnHorizonDecidesHowFarItRuns(): void
    {
        $this->rotation->setHorizonDays(13);

        $run = $this->generator()->generate($this->rotation, new \DateTimeImmutable('2026-09-14'));

        self::assertSame('2026-09-27', $run->through->format('Y-m-d'));
    }

    /**
     * RUNNING IT TWICE LEAVES THE SAME ROSTER. The nightly run is the ordinary
     * case, so a generator that accumulated duplicates would double the park
     * every night.
     */
    public function testRunningItTwiceChangesNothing(): void
    {
        $generator = $this->generator();
        $generator->generate($this->rotation, new \DateTimeImmutable('2026-09-14'), new \DateTimeImmutable('2026-09-20'));

        $second = $generator->generate($this->rotation, new \DateTimeImmutable('2026-09-14'), new \DateTimeImmutable('2026-09-20'));

        self::assertSame(28, $second->replaced, 'The second run clears its own work before writing it again.');
        self::assertSame(28, $second->created);
        self::assertCount(28, $this->duties()->findByStationBetween($this->gate, new \DateTimeImmutable('2026-09-14'), new \DateTimeImmutable('2026-09-20')));
    }

    /**
     * THE RULE THIS CLASS EXISTS TO KEEP. A duty officer takes somebody off
     * sunday night and puts somebody else on; the nightly run must leave that
     * whole day exactly as they left it.
     *
     * A flag on a duty could not carry this: the commonest edit is a REMOVAL,
     * and a flag on a deleted row goes with the row — the next run would find
     * nothing, conclude the day was never generated, and put the person the
     * duty officer took off straight back on.
     */
    public function testAnEditedDayIsNeverOverwritten(): void
    {
        $generator = $this->generator();
        $generator->generate($this->rotation, new \DateTimeImmutable('2026-09-14'), new \DateTimeImmutable('2026-09-20'));

        $sunday = new \DateTimeImmutable('2026-09-20');

        // The duty officer empties sunday's night watch and marks the day.
        foreach ($this->duties()->findByStationBetween($this->gate, $sunday, $sunday) as $duty) {
            if ('night' === $duty->getShiftKey()) {
                $this->em->remove($duty);
            }
        }
        $this->em->persist(new EditedDay($this->gate, $sunday, $this->pool[0], new \DateTimeImmutable()));
        $this->em->flush();

        $run = $generator->generate($this->rotation, new \DateTimeImmutable('2026-09-14'), new \DateTimeImmutable('2026-09-20'));

        self::assertSame(['2026-09-20'], $run->protectedDays);
        self::assertSame(['day' => 2], $this->countsOn('2026-09-20'), 'The night watch the duty officer emptied stays empty.');
        // Every other day was regenerated as normal.
        self::assertSame(['day' => 2, 'night' => 2], $this->countsOn('2026-09-19'));
    }

    /**
     * A DUTY SOMEBODY ADDED BY HAND IS NOT THIS ROTATION'S TO REMOVE. It
     * carries no rotation, so the clear steps over it — and the generator
     * must not then write a second copy of the same watch.
     */
    public function testItNeitherRemovesNorDuplicatesAHandMadeDuty(): void
    {
        $byHand = new Duty($this->area, $this->gate, $this->pool[4], 'day', new \DateTimeImmutable('2026-09-14'));
        $byHand->setNote('covering from the southern post');
        $this->em->persist($byHand);
        $this->em->flush();

        $run = $this->generator()->generate($this->rotation, new \DateTimeImmutable('2026-09-14'), new \DateTimeImmutable('2026-09-14'));

        self::assertSame(1, $run->skippedAlreadyThere, 'The ring wanted that same watch and found it already there.');
        self::assertSame(['day' => 2, 'night' => 2], $this->countsOn('2026-09-14'));

        $kept = $this->duties()->findByStationBetween($this->gate, new \DateTimeImmutable('2026-09-14'), new \DateTimeImmutable('2026-09-14'));
        $notes = array_filter(array_map(static fn (Duty $d): ?string => $d->getNote(), $kept));
        self::assertSame(['covering from the southern post'], array_values($notes));
    }

    /**
     * AN ABSENCE MAKES A HOLE, and the hole is the point: the watch comes up
     * short against what the station expects, visibly, in time to be filled.
     */
    public function testSomebodyAwayIsNotRostered(): void
    {
        $this->em->persist(new Absence(
            $this->area,
            $this->pool[0],
            new \DateTimeImmutable('2026-09-14'),
            new \DateTimeImmutable('2026-09-16'),
            AbsenceKind::Leave,
            $this->pool[1],
        ));
        $this->em->flush();

        $run = $this->generator()->generate($this->rotation, new \DateTimeImmutable('2026-09-14'), new \DateTimeImmutable('2026-09-20'));

        // Ada walks the ring day, day, night, night, off from the 14th, so
        // the three days of leave take three watches off her.
        self::assertSame(3, $run->skippedAbsent);
        self::assertSame(['day' => 1, 'night' => 2], $this->countsOn('2026-09-14'));
        // And the day after the leave ends she is back.
        self::assertSame(['day' => 2, 'night' => 2], $this->countsOn('2026-09-18'));
    }

    /**
     * An absence that began before the window is exactly the one a naive
     * "absences starting in this range" query would miss.
     */
    public function testAnAbsenceThatStartedBeforeTheWindowStillCounts(): void
    {
        $this->em->persist(new Absence(
            $this->area,
            $this->pool[0],
            new \DateTimeImmutable('2026-09-01'),
            new \DateTimeImmutable('2026-09-30'),
            AbsenceKind::Course,
        ));
        $this->em->flush();

        $this->generator()->generate($this->rotation, new \DateTimeImmutable('2026-09-14'), new \DateTimeImmutable('2026-09-20'));

        foreach ($this->duties()->findByStationBetween($this->gate, new \DateTimeImmutable('2026-09-14'), new \DateTimeImmutable('2026-09-20')) as $duty) {
            self::assertNotSame($this->pool[0]->getId(), $duty->getPerson()->getId());
        }
    }

    /**
     * "Two day watches, saturdays stood down" — the shape Fig Tree runs, and the
     * one a ring alone cannot express.
     */
    public function testAStoodDownWeekdayGeneratesNothingAtThatPost(): void
    {
        $this->rotation->setStandDownWeekdays([6]);
        $this->em->flush();

        $this->generator()->generate($this->rotation, new \DateTimeImmutable('2026-09-14'), new \DateTimeImmutable('2026-09-20'));

        self::assertSame([], $this->countsOn('2026-09-19'), 'Saturday 19 September 2026 is stood down.');
        self::assertSame(['day' => 2, 'night' => 2], $this->countsOn('2026-09-18'));
    }

    /**
     * A rotation with nobody in the pool produces nothing and says so without
     * failing — a post whose people have all been taken out is a post that is
     * short, which every surface already draws.
     */
    public function testAnEmptyPoolGeneratesNothingAndIsNotAnError(): void
    {
        $bare = new Rotation(
            $this->area,
            RotationScope::Post,
            Cycle::of(['day']),
            new \DateTimeImmutable('2026-09-14'),
            ['day' => 1],
            7,
        )->standAt($this->aStation($this->area, 'west outpost', 'ST-02'));
        $this->em->persist($bare);
        $this->em->flush();

        $run = $this->generator()->generate($bare, new \DateTimeImmutable('2026-09-14'), new \DateTimeImmutable('2026-09-20'));

        self::assertSame(0, $run->created);
        self::assertSame('2026-09-20', $bare->getGeneratedThrough()?->format('Y-m-d'));
    }

    /** A stood-down rotation generates nothing, and its existing duties stay. */
    public function testAStoodDownRotationGeneratesNothing(): void
    {
        $generator = $this->generator();
        $generator->generate($this->rotation, new \DateTimeImmutable('2026-09-14'), new \DateTimeImmutable('2026-09-20'));

        $this->rotation->setActive(false);
        $this->em->flush();

        $run = $generator->generate($this->rotation, new \DateTimeImmutable('2026-09-21'), new \DateTimeImmutable('2026-09-27'));

        self::assertSame(0, $run->created);
        self::assertCount(28, $this->duties()->findByStationBetween($this->gate, new \DateTimeImmutable('2026-09-14'), new \DateTimeImmutable('2026-09-20')));
    }

    /**
     * A SQUAD AWAY ON TOUR IS COUNTED AT ITS BASE — ruled 2026-09-20. The
     * cycle travels with the team; the filing stands still, because a duty is
     * one watch at one station on one day and every count in the product is
     * keyed by station.
     */
    public function testAPerTeamRotationFilesItsWatchesAtItsBasePost(): void
    {
        $base = $this->aStation($this->area, 'headquarters', 'ST-05');
        $squad = new Rotation(
            $this->area,
            RotationScope::Team,
            Cycle::of(['day', 'day', Cycle::OFF]),
            new \DateTimeImmutable('2026-09-14'),
            ['day' => 2],
            42,
        )->carriedBy('rapid response team', $base);
        $this->em->persist($squad);
        $this->em->persist(new RotationPoolMember($squad, $this->pool[0], 0));
        $this->em->flush();

        $run = $this->generator()->generate($squad, new \DateTimeImmutable('2026-09-14'), new \DateTimeImmutable('2026-09-20'));

        self::assertSame(5, $run->created);

        $duties = $this->duties()->findByStationBetween($base, new \DateTimeImmutable('2026-09-14'), new \DateTimeImmutable('2026-09-20'));
        self::assertCount(5, $duties);
        foreach ($duties as $duty) {
            self::assertSame($base->getId(), $duty->getStation()->getId());
        }

        // And nothing landed at the gate the squad has nothing to do with.
        self::assertSame([], $this->countsOn('2026-09-14'));
    }

    /**
     * A ROTATION WITH NO POST AT ALL IS REFUSED LOUDLY. Unreachable through
     * the module — both ways of setting a scope take a station — so this is
     * about the row written around them: a silent "nothing written" would look
     * exactly like a pool that is all away.
     */
    public function testARotationNamingNoPostAtAllIsRefused(): void
    {
        $orphan = new Rotation(
            $this->area,
            RotationScope::Team,
            Cycle::of(['day']),
            new \DateTimeImmutable('2026-09-14'),
            ['day' => 1],
            7,
        );
        $this->em->persist($orphan);
        $this->em->persist(new RotationPoolMember($orphan, $this->pool[0], 0));
        $this->em->flush();

        $this->expectException(RotationCannotGenerate::class);

        $this->generator()->generate($orphan, new \DateTimeImmutable('2026-09-14'), new \DateTimeImmutable('2026-09-20'));
    }
}
