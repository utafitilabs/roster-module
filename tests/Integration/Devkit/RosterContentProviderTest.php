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

namespace Uhifadhi\Roster\Tests\Integration\Devkit;

use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\Posting;
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Bundle\AreaBundle\Enum\PostingSource;
use Uhifadhi\Bundle\AreaBundle\Repository\PostingRepository;
use Uhifadhi\Bundle\AreaBundle\Service\PostingService;
use Uhifadhi\Bundle\TeamBundle\Entity\Department;
use Uhifadhi\Bundle\TeamBundle\Entity\Placement;
use Uhifadhi\Bundle\TeamBundle\Entity\Position;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Roster\Devkit\RosterContentProvider;
use Uhifadhi\Roster\Entity\Rotation;
use Uhifadhi\Roster\Entity\RotationPoolMember;
use Uhifadhi\Roster\Enum\RotationScope;
use Uhifadhi\Roster\Enum\SwapState;
use Uhifadhi\Roster\Repository\AbsenceRepository;
use Uhifadhi\Roster\Repository\DutyRepository;
use Uhifadhi\Roster\Repository\EditedDayRepository;
use Uhifadhi\Roster\Repository\RotationRepository;
use Uhifadhi\Roster\Repository\ShiftRepository;
use Uhifadhi\Roster\Repository\StationWatchRepository;
use Uhifadhi\Roster\Repository\SwapRepository;
use Uhifadhi\Roster\Service\RotaService;
use Uhifadhi\Roster\Service\StationWatchService;
use Uhifadhi\Roster\Tests\Integration\IntegrationTestCase;

/**
 * THE SEED PLAN — what `fixtures:seed` puts on the roster so that every tab
 * reads like a working park rather than a set of empty frames.
 *
 * IT IS BUILT ON THE AREA'S OWN CONTENT, not on a parallel world of its
 * own: the posts are the ones the area seeded, and the people ringed at
 * each are the people POSTED there. A seed that invented its own stations
 * would be a seed of a product that does not exist, and the first thing it
 * would hide is the seam it is supposed to exercise.
 *
 * EVERYTHING FALLS IN THE CURRENT MONTH, because that is the window every
 * tab opens on. Content seeded into a fixed calendar month reads rich on
 * the day it was written and empty forever after — the one bug a seed
 * cannot afford, since nobody looks at a seed twice.
 */
final class RosterContentProviderTest extends IntegrationTestCase
{
    private AreaOfInterest $area;

    /** @var list<User> the six the fixture posts round the area's four posts */
    private array $people = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->area = $this->anArea();

        // Four posts and six people, posted around them the way the area's
        // own seeder does it: the seed hangs on whatever it finds.
        $postings = $this->service(PostingService::class);
        self::assertInstanceOf(PostingService::class, $postings);

        // ONE STANDING POSTING A PERSON (ruled, and the area refuses the
        // second). The posts used to share people by walking a cursor
        // round six of them; now each post has its own three, and the
        // fixture brings the twelve rangers that needs. A park staffs its
        // posts with the people it has, and this is what that looks like.
        $this->people = [];
        foreach (range(1, 12) as $n) {
            $this->people[] = $this->aPerson(\sprintf('ranger%d@example.test', $n), 'Ranger'.$n);
        }

        $next = 0;
        foreach (range(1, 4) as $n) {
            $station = $this->aStation($this->area, \sprintf('post %d', $n), \sprintf('ST-0%d', $n));
            $this->em->flush();

            // Three each, and each of them somewhere exactly once.
            foreach ([0, 1, 2] as $ignored) {
                $postings->post($station, $this->people[$next], PostingSource::WrittenHere);
                ++$next;
            }
        }

        $this->em->flush();
    }

    private function provider(): RosterContentProvider
    {
        $provider = $this->service(RosterContentProvider::class);
        self::assertInstanceOf(RosterContentProvider::class, $provider);

        return $provider;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function repository(string $class): object
    {
        $repository = $this->service($class);
        self::assertInstanceOf($class, $repository);

        return $repository;
    }

    private function monthStart(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('first day of this month')->setTime(0, 0);
    }

    /** The earlier of the month's first day and the planner's fortnight. */
    private function windowStart(): \DateTimeImmutable
    {
        $fortnight = RotaService::start(new \DateTimeImmutable('today'));
        $month = $this->monthStart();

        return $fortnight < $month ? $fortnight : $month;
    }

    private function monthEnd(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('last day of this month')->setTime(0, 0);
    }

    /** Six weeks from today (the fill-ahead default), or the month's end when that reaches further. */
    private function fillHorizon(): \DateTimeImmutable
    {
        $ahead = new \DateTimeImmutable('today')->modify('+6 weeks');
        $month = $this->monthEnd();

        return $ahead > $month ? $ahead : $month;
    }

    /** THE ORDER IT IS SEEDED IN is stated as what it is built on. */
    public function testItIsSeededAfterTheContentItHangsOn(): void
    {
        $provider = $this->provider();

        self::assertSame('roster', $provider->key());
        self::assertSame(['area', 'team', 'zone', 'station'], $provider->dependsOn());
        self::assertNotSame('', trim($provider->label()));
        self::assertNotSame('', trim($provider->description()));
    }

    /** THE VOCABULARY FIRST: nothing can be rostered onto a shift that has no name. */
    public function testItSeedsTheShiftVocabulary(): void
    {
        $this->provider()->load();

        $keys = array_map(
            static fn (object $shift): string => $shift->getKey(),
            $this->repository(ShiftRepository::class)->findByArea($this->area),
        );

        self::assertContains('day', $keys);
        self::assertContains('night', $keys);
    }

    /**
     * THE POSTS ARE THE AREA'S, and they come onto the roster with what each
     * asks for — including one that asks for NOTHING, because a post on the
     * books with no watch is a state the board has to draw and an all-alike
     * seed never produces it.
     */
    public function testItPutsTheAreasOwnPostsOnTheRosterWithDifferentDemands(): void
    {
        $this->provider()->load();

        $watches = $this->repository(StationWatchRepository::class)->findByArea($this->area);
        self::assertNotEmpty($watches);

        $demands = [];
        foreach ($watches as $watch) {
            $demands[] = $watch->getExpects();
            self::assertSame($this->area->getId(), $watch->getStation()->getArea()?->getId());
        }

        self::assertContains([], $demands, 'A post that asks for no watch.');
        self::assertContains(['day', 'night'], $demands, 'A post that is manned round the clock.');
        self::assertGreaterThan(1, \count(array_unique(array_map('serialize', $demands))), 'Not every post asks the same thing.');
    }

    /**
     * THE RING AT A POST IS THE PEOPLE POSTED THERE. Reading the pool from
     * the area's postings is the whole point of the seam: a seed that picked
     * people at random would show a roster nobody at that post appears on.
     */
    public function testEveryRingDrawsFromThePeoplePostedAtThatPost(): void
    {
        $this->provider()->load();

        $rotations = $this->repository(RotationRepository::class)->findByArea($this->area);
        self::assertNotEmpty($rotations);

        $postings = $this->service(PostingService::class);
        self::assertInstanceOf(PostingService::class, $postings);

        foreach ($rotations as $rotation) {
            if (RotationScope::Post !== $rotation->getScope()) {
                // A SQUAD IS THE OTHER CASE ON PURPOSE: it carries a
                // department's people to whatever post it is based at, and
                // testing it against that post's postings would be testing
                // the rule it exists to be an exception to.
                continue;
            }

            $station = $rotation->watchStation();
            self::assertNotNull($station, 'A per-post ring names its post.');

            $posted = [];
            foreach ($postings->standingAt($station) as $posting) {
                $posted[] = $posting->getPerson()?->getUuidString();
            }

            self::assertNotEmpty($rotation->getPool(), 'A ring with nobody in it generates nothing.');
            foreach ($rotation->getPool() as $member) {
                self::assertContains($member->getPerson()->getUuidString(), $posted, 'Somebody in the ring who is not posted here.');
            }
        }
    }

    /**
     * THE WHOLE OF THE CURRENT MONTH IS ROSTERED, both ends of it — a seed
     * that generated from today forward leaves the first half of every
     * calendar and every fortnight grid empty.
     */
    public function testItRostersTheWholeOfTheCurrentMonth(): void
    {
        $this->provider()->load();

        $duties = $this->repository(DutyRepository::class)->findByAreaBetween($this->area, $this->monthStart(), $this->monthEnd());
        self::assertNotEmpty($duties);

        $days = [];
        foreach ($duties as $duty) {
            $days[$duty->getOnDay()->format('Y-m-d')] = true;
        }

        self::assertArrayHasKey($this->monthStart()->format('Y-m-d'), $days, 'The first of the month is rostered.');
        self::assertArrayHasKey($this->monthEnd()->format('Y-m-d'), $days, 'So is the last.');
        self::assertGreaterThan(20, \count($days), 'Nearly every day of the month carries a watch.');
    }

    /**
     * NOBODY STANDS TWO WATCHES IN ONE DAY AT TWO DIFFERENT POSTS.
     *
     * Caught by opening Today, not by a test: one ranger was showing
     * "watch 1 of 2" at Alpha Gate and again at Outer Marker, adding up
     * to twenty-four hours on duty in a twenty-four hour day. The cause
     * is real and worth naming — a person may be POSTED at several posts,
     * every post's ring draws from the people posted there, and two rings
     * that share a person will both put them on the same morning. The
     * seed therefore hands each post its own people where the area has
     * enough of them to go round.
     */
    public function testNobodyIsRosteredAtTwoPostsOnTheSameDay(): void
    {
        $this->provider()->load();

        $seen = [];
        foreach ($this->repository(DutyRepository::class)->findByAreaBetween($this->area, $this->monthStart(), $this->monthEnd()) as $duty) {
            $key = $duty->getOnDay()->format('Y-m-d').'/'.$duty->getPerson()->getUuidString();
            $station = (string) $duty->getStation()->getUuidString();

            if (isset($seen[$key]) && $seen[$key] !== $station) {
                self::fail(\sprintf('%s is due at two posts on %s.', $duty->getPerson()->getFullName(), $duty->getOnDay()->format('Y-m-d')));
            }

            $seen[$key] = $station;
        }

        self::assertNotEmpty($seen);
    }

    /**
     * NOTHING IS ROSTERED OUTSIDE THE WINDOW THE TABS LOOK AT, and that
     * window is the FORTNIGHT-OR-MONTH behind, and the FILL HORIZON ahead.
     *
     * BEHIND: the Week tab opens on fourteen days from the Monday of this
     * week, so in the first days of a month it reaches back into the last
     * one; a seed bounded at the first drew those days as holes. And the
     * presence seed can only report from watches that have already
     * happened. Ruled 20 sep: roster from the earlier of the two.
     *
     * AHEAD: the plan reaches as far as the area's fill-ahead rule says a
     * pattern fills — six weeks from today by default — or the end of the
     * month, whichever is further. The month alone left the second week of
     * the sheet's own fortnight empty from the third week of every month,
     * and a Week tab whose right half is a wall of short cover is not a
     * seed of a roster, it is a seed of a park that stopped planning.
     */
    public function testItRostersNothingOutsideTheWindowBehindOrTheHorizonAhead(): void
    {
        $this->provider()->load();

        $duties = $this->repository(DutyRepository::class);

        $before = $duties->findByAreaBetween($this->area, $this->windowStart()->modify('-2 months'), $this->windowStart()->modify('-1 day'));
        $after = $duties->findByAreaBetween($this->area, $this->fillHorizon()->modify('+1 day'), $this->fillHorizon()->modify('+2 months'));

        self::assertSame([], $before);
        self::assertSame([], $after);
    }

    /**
     * THE FORTNIGHT AFTER THIS ONE IS ROSTERED TOO, every day of it, and
     * so is the last day of the horizon — the sheet's default window and
     * the four-week view under it are populated whatever the date.
     */
    public function testItRostersThroughTheFillHorizon(): void
    {
        $this->provider()->load();

        $days = [];
        foreach ($this->repository(DutyRepository::class)->findByAreaBetween($this->area, $this->windowStart(), $this->fillHorizon()) as $duty) {
            $days[$duty->getOnDay()->format('Y-m-d')] = true;
        }

        $fortnight = RotaService::start(new \DateTimeImmutable('today'));
        for ($i = 0; $i < 28; ++$i) {
            $day = $fortnight->modify(\sprintf('+%d days', $i))->format('Y-m-d');
            self::assertArrayHasKey($day, $days, \sprintf('%s is rostered.', $day));
        }
        self::assertArrayHasKey($this->fillHorizon()->format('Y-m-d'), $days, 'The last day of the horizon is rostered.');
    }

    /**
     * A SECOND SEED EXTENDS WHAT STANDS. A park seeded last month has rings
     * whose duties stop where that run's horizon did; running the seed
     * again carries every standing ring on from there to today's horizon,
     * and touches nothing a ring has already drawn — its duties, hand
     * marks and trades stay exactly where the first run put them.
     */
    public function testASecondSeedExtendsTheStandingRingsToTheHorizon(): void
    {
        $this->provider()->load();

        $duties = $this->repository(DutyRepository::class);
        $cut = $this->windowStart()->modify('+13 days');
        $kept = \count($duties->findByAreaBetween($this->area, $this->windowStart(), $cut));

        // The first run stopped at the fortnight's end, as an older seed did.
        foreach ($duties->findByAreaBetween($this->area, $cut->modify('+1 day'), $this->fillHorizon()) as $duty) {
            $this->em->remove($duty);
        }
        foreach ($this->repository(RotationRepository::class)->findByArea($this->area) as $ring) {
            $ring->setGeneratedThrough($cut);
        }
        $this->em->flush();
        $this->em->clear();
        self::assertSame([], $duties->findByAreaBetween($this->area, $cut->modify('+1 day'), $this->fillHorizon()));

        $this->provider()->load();
        $this->em->clear();

        self::assertNotEmpty($duties->findByAreaBetween($this->area, $cut->modify('+1 day'), $this->fillHorizon()), 'The standing rings were carried on to the horizon.');
        self::assertSame($kept, \count($duties->findByAreaBetween($this->area, $this->windowStart(), $cut)), 'And what stood already was not redrawn.');
    }

    /**
     * SOMEBODY POSTED AFTER THE RING WAS MADE JOINS IT on the next seed,
     * at the end of the ring — a stationed ranger with no watch for a
     * month is not what a seed should show.
     */
    public function testSomebodyPostedLaterJoinsTheStandingRing(): void
    {
        $this->provider()->load();

        $station = $this->em->getRepository(Station::class)->findOneBy(['area' => $this->area], ['code' => 'ASC']);
        self::assertInstanceOf(Station::class, $station);
        $late = new User()->setPassword('x')->setEmail('late@example.test')->setFirstName('Late')->setLastName('Joiner');
        $this->em->persist($late);
        $this->em->flush();
        $this->em->persist(new Posting()->setStation($station)->setPerson($late)->setSince(new \DateTimeImmutable('today'))->setSource(PostingSource::WrittenHere));
        $this->em->flush();
        $this->em->clear();

        $this->provider()->load();
        $this->em->clear();

        $again = $this->em->getRepository(Station::class)->find($station->getId());
        self::assertInstanceOf(Station::class, $again);
        $ring = $this->repository(RotationRepository::class)->findOneForStation($again);
        self::assertInstanceOf(Rotation::class, $ring);
        $members = array_map(static fn (RotationPoolMember $m): string => (string) $m->getPerson()->getUuidString(), $ring->getPool()->toArray());
        self::assertContains((string) $late->getUuidString(), $members, 'The late joiner is in the ring.');
        self::assertSame(\count($members), \count(array_unique($members)), 'And nobody is in it twice.');
    }

    /**
     * AND THERE IS ALWAYS SOMETHING BEHIND TODAY TO REPORT ON. This is the
     * property the window exists for: whatever date the suite runs on,
     * including the 1st, the plan reaches back far enough that the presence
     * seed has worked watches to hand its readings to.
     */
    public function testThereIsAlwaysAPastToReportOn(): void
    {
        $this->provider()->load();

        $past = $this->repository(DutyRepository::class)->findByAreaBetween(
            $this->area,
            $this->windowStart(),
            new \DateTimeImmutable('yesterday'),
        );

        self::assertNotEmpty($past, 'A park seeded on the 1st still has days behind it.');
    }

    /** ABSENCES AND SWAPS, inside the window, so both cards read. */
    public function testItSeedsAbsencesAndSwapsInsideTheWindow(): void
    {
        $this->provider()->load();

        $absences = $this->repository(AbsenceRepository::class)->findOverlapping($this->area, $this->monthStart(), $this->monthEnd());
        self::assertNotEmpty($absences, 'Somebody is away this month.');

        self::assertNotEmpty(
            // THE PLANNER'S FORTNIGHT, which is where the seeder puts its
            // trades - on the 1st of a month it starts in the month before.
            $this->repository(SwapRepository::class)->findOpenBetween($this->area, $this->windowStart(), $this->monthEnd()),
            'A trade is in flight — the pair the grid marks on both cells.',
        );

        $states = array_map(
            static fn (object $swap): string => $swap->getState()->value,
            $this->repository(SwapRepository::class)->findRecentBetween($this->area, $this->windowStart(), $this->monthEnd(), 50),
        );
        self::assertGreaterThan(1, \count(array_unique($states)), 'And one already answered, so the card is not all one state.');
    }

    /**
     * A SECOND RUN CHANGES NOTHING. `fixtures:seed` is run again and again
     * on a working database, and a seeder that doubled its content every
     * time would make the second run a different product from the first.
     */
    public function testASecondRunChangesNothing(): void
    {
        $this->provider()->load();

        $counted = static fn (array $rows): int => \count($rows);
        $before = [
            $counted($this->repository(ShiftRepository::class)->findByArea($this->area)),
            $counted($this->repository(StationWatchRepository::class)->findByArea($this->area)),
            $counted($this->repository(RotationRepository::class)->findByArea($this->area)),
            $counted($this->repository(DutyRepository::class)->findByAreaBetween($this->area, $this->monthStart(), $this->monthEnd())),
            $counted($this->repository(AbsenceRepository::class)->findOverlapping($this->area, $this->monthStart(), $this->monthEnd())),
            $counted($this->repository(SwapRepository::class)->findRecentBetween($this->area, $this->windowStart(), $this->monthEnd(), 50)),
        ];

        $this->provider()->load();

        $after = [
            $counted($this->repository(ShiftRepository::class)->findByArea($this->area)),
            $counted($this->repository(StationWatchRepository::class)->findByArea($this->area)),
            $counted($this->repository(RotationRepository::class)->findByArea($this->area)),
            $counted($this->repository(DutyRepository::class)->findByAreaBetween($this->area, $this->monthStart(), $this->monthEnd())),
            $counted($this->repository(AbsenceRepository::class)->findOverlapping($this->area, $this->monthStart(), $this->monthEnd())),
            $counted($this->repository(SwapRepository::class)->findRecentBetween($this->area, $this->windowStart(), $this->monthEnd(), 50)),
        ];

        self::assertSame($before, $after);
    }

    /**
     * AN AREA SOMEBODY HAS MERELY LOOKED AT IS STILL SEEDED.
     *
     * The regression for a bug that passed every test and was caught only
     * by opening the page: the "have I been here before" mark was the
     * shift vocabulary, and the module SEEDS THAT LAZILY the first time
     * any page reads it. So every area anybody had ever opened counted as
     * already done, and the seed silently skipped it — on the one area
     * the roster was actually switched on for. The mark is now a post on
     * the roster, which nothing creates by accident.
     */
    public function testAnAreaWhoseVocabularyWasAlreadyReadIsStillSeeded(): void
    {
        // What merely opening a roster page does.
        $vocabulary = $this->service(\Uhifadhi\Roster\Service\ShiftVocabularyService::class);
        self::assertInstanceOf(\Uhifadhi\Roster\Service\ShiftVocabularyService::class, $vocabulary);
        self::assertNotEmpty($vocabulary->forArea($this->area), 'Reading the vocabulary seeds it.');

        $this->provider()->load();

        self::assertNotEmpty($this->repository(StationWatchRepository::class)->findByArea($this->area));
        self::assertNotEmpty($this->repository(DutyRepository::class)->findByAreaBetween($this->area, $this->monthStart(), $this->monthEnd()));
    }

    /**
     * A POST SOMEBODY HAS CONFIGURED IS LEFT EXACTLY AS IT IS — AND THE
     * REST OF THE AREA IS STILL FILLED IN.
     *
     * The second half is the correction, and it cost a render to find.
     * Idempotence was first written per AREA: anything already on the
     * roster meant "been here, skip". The one area the module was
     * actually switched on for had a SINGLE post configured by hand, so
     * every tab on it stayed at nought through run after run while the
     * seeder reported success. What a person configured is theirs; the
     * seven posts beside it were nobody's and are now seeded.
     */
    public function testAConfiguredPostIsUntouchedAndTheRestOfTheAreaIsStillSeeded(): void
    {
        $watches = $this->service(StationWatchService::class);
        self::assertInstanceOf(StationWatchService::class, $watches);

        $posts = $this->em->getRepository(Station::class)->findBy(['area' => $this->area], ['code' => 'ASC']);
        self::assertNotEmpty($posts);
        $byHand = $watches->addToRoster($posts[0]);
        $watches->save($byHand, ['radio'], 45, 180);

        $this->provider()->load();

        // Untouched: still the one shift somebody chose, not the seed's.
        self::assertSame(['radio'], $byHand->getExpects());
        self::assertSame(45, $byHand->getSilenceWindowMinutes());

        // And the area is not empty because of it.
        self::assertGreaterThan(1, \count($this->repository(StationWatchRepository::class)->findByArea($this->area)));
        self::assertNotEmpty($this->repository(RotationRepository::class)->findByArea($this->area));
        self::assertNotEmpty($this->repository(DutyRepository::class)->findByAreaBetween($this->area, $this->monthStart(), $this->monthEnd()));
    }

    /** AN AREA WITH NO POSTS IS LEFT ALONE, rather tha seeded into nothing. */
    public function testAnAreaWithNoPostsIsLeftAlone(): void
    {
        $bare = $this->anArea('bare reserve');
        $this->em->flush();

        $this->provider()->load();

        self::assertSame([], $this->repository(StationWatchRepository::class)->findByArea($bare));
        self::assertSame([], $this->repository(RotationRepository::class)->findByArea($bare));
    }

    /**
     * A POST SOMEBODY PUT ON THE ROSTER BY HAND STILL GETS A RING.
     *
     * Configuring a post and staffing it are two decisions, and only the
     * first of them had been made. The seeder read a watch on a post as
     * "been here, leave it" and returned before it had rung anybody, so a
     * post an administrator had set up sat on every tab with nothing on
     * it — and so did the whole of an area whose single post was set up
     * that way. What a person configured is still theirs: the ring
     * answers THE WATCH'S OWN demands, never the seed's list.
     */
    public function testAPostConfiguredByHandStillGetsARingAnsweringItsOwnDemands(): void
    {
        $watches = $this->service(StationWatchService::class);
        self::assertInstanceOf(StationWatchService::class, $watches);

        $posts = $this->em->getRepository(Station::class)->findBy(['area' => $this->area], ['code' => 'ASC']);
        self::assertNotEmpty($posts);
        $watches->save($watches->addToRoster($posts[0]), ['night'], 45, 180);

        $this->provider()->load();

        $rotation = $this->repository(RotationRepository::class)->findOneForStation($posts[0]);
        self::assertNotNull($rotation, 'A post on the books with nobody on it is not a seed.');

        $shifts = [];
        foreach ($this->repository(DutyRepository::class)->findByStationBetween($posts[0], $this->monthStart(), $this->monthEnd()) as $duty) {
            $shifts[$duty->getShiftKey()] = true;
        }

        self::assertSame(['night'], array_keys($shifts), 'It stands the watch it was configured for, and no other.');
    }

    /**
     * AN AREA NOBODY IS POSTED AT IS STAFFED FROM THE INSTALLATION'S OWN
     * PEOPLE, THROUGH THE AREA'S OWN DOOR.
     *
     * A ring is drawn from the people posted at its post, so an area whose
     * posts carry no postings has nothing to draw and every tab on it
     * reads nought. The seed therefore posts the real people the
     * installation has — through {@see PostingService}, the area's own
     * service, so the station page, the identity band and the ring all
     * say the same thing. It only does so where the area has NO standing
     * posting at all: one unstaffed post inside a staffed area is a state
     * worth drawing, and this must not fill it in.
     */
    public function testAnAreaNobodyIsPostedAtIsStaffedFromTheInstallationsPeople(): void
    {
        $quiet = $this->anArea('quiet reserve');
        foreach (range(1, 3) as $n) {
            $this->aStation($quiet, \sprintf('quiet post %d', $n), \sprintf('QT-0%d', $n));
        }

        // AND RANGERS WHO STAND NOWHERE YET. One posting a person is one
        // AREA too, so a second area is staffed by the people the first
        // one did not take — an installation whose whole roll is already
        // posted has nobody to open a new reserve with, and the seed says
        // so rather than double-posting somebody.
        foreach (range(1, 4) as $n) {
            $this->aPerson(\sprintf('spare%d@example.test', $n), 'Spare'.$n);
        }

        $this->em->flush();

        $this->provider()->load();

        $postings = $this->service(PostingService::class);
        self::assertInstanceOf(PostingService::class, $postings);

        $posts = $this->em->getRepository(Station::class)->findBy(['area' => $quiet], ['code' => 'ASC']);
        self::assertNotEmpty($postings->standingAt($posts[0]), 'Somebody is posted at the first post.');

        self::assertNotEmpty($this->repository(RotationRepository::class)->findByArea($quiet));
        self::assertNotEmpty($this->repository(DutyRepository::class)->findByAreaBetween($quiet, $this->monthStart(), $this->monthEnd()));
    }

    /**
     * ONE UNSTAFFED POST INSIDE A STAFFED AREA IS LEFT UNSTAFFED. The area's
     * own seed leaves a post with nobody at it on purpose; filling it in
     * would delete the state.
     */
    public function testAPostNobodyIsPostedAtInsideAStaffedAreaIsLeftAlone(): void
    {
        $spare = $this->aStation($this->area, 'spare post', 'ST-09');
        $this->em->flush();

        $this->provider()->load();

        $postings = $this->service(PostingService::class);
        self::assertInstanceOf(PostingService::class, $postings);

        self::assertSame([], $postings->standingAt($spare));
    }

    /**
     * A TRADE IN EVERY STATE IT CAN BE IN — offered, accepted, declined and
     * withdrawn. A register that only ever shows two of the four draws two
     * of its four rows in anger and the other two never.
     */
    public function testATradeIsSeededInEveryState(): void
    {
        $this->provider()->load();

        $from = RotaService::start(new \DateTimeImmutable('today'));
        $through = $from->modify(\sprintf('+%d days', RotaService::DAYS - 1));

        $states = [];
        foreach ($this->repository(SwapRepository::class)->findRecentBetween($this->area, $from, $through, 50) as $swap) {
            $states[$swap->getState()->value] = true;
        }

        foreach (SwapState::cases() as $state) {
            self::assertArrayHasKey($state->value, $states, \sprintf('No trade is %s.', $state->value));
        }
    }

    /**
     * AN ACCEPTED TRADE MARKS ITS DAY, so the next generation leaves the
     * agreement two people made exactly where they put it.
     *
     * AND IT MARKS THE TWO RANGERS IN IT, not the post: the mark names
     * whose day was changed, so a colleague on the same morning is left
     * to the ring.
     */
    public function testAnAcceptedTradeProtectsItsDay(): void
    {
        $this->provider()->load();

        $protected = 0;
        foreach ($this->em->getRepository(Station::class)->findBy(['area' => $this->area]) as $post) {
            $protected += \count($this->repository(EditedDayRepository::class)->protectedPersonDaysBetween($post, $this->windowStart(), $this->monthEnd()));
        }

        self::assertGreaterThan(0, $protected);
    }

    /**
     * A POST ADDED AFTER THE FIRST RUN DOES NOT DRAW PEOPLE WHO ARE
     * ALREADY IN A RING.
     *
     * The rings of earlier runs are returned from early, so the people in
     * them were never counted as spoken for and the next post to be rung
     * drew them again — two watches on one morning, at two posts, on a
     * park that had merely been seeded twice. Every ring already standing
     * in the area is read before the walk starts.
     */
    public function testAPostRungOnALaterRunDoesNotDrawPeopleAlreadyInARing(): void
    {
        $this->provider()->load();

        $postings = $this->service(PostingService::class);
        self::assertInstanceOf(PostingService::class, $postings);

        $later = $this->aStation($this->area, 'late post', 'ST-09');
        $this->em->flush();

        // MOVING SOMEBODY IS TWO ACTS AND NOT ONE. They already stand at
        // the post their ring belongs to, and one person stands at one
        // post — so the posting they have ends before the one they are
        // going to is made. Posting them without that is the write the
        // area now refuses, and rightly.
        $standing = $this->repository(PostingRepository::class);
        foreach ($this->repository(RotationRepository::class)->findByArea($this->area)[0]->getPool() as $member) {
            $person = $member->getPerson();
            foreach ($standing->findStandingByPerson($person) as $posting) {
                $postings->end($posting);
            }

            $postings->post($later, $person, PostingSource::WrittenHere);
        }

        $this->provider()->load();

        $seen = [];
        foreach ($this->repository(DutyRepository::class)->findByAreaBetween($this->area, $this->monthStart(), $this->monthEnd()) as $duty) {
            $key = $duty->getOnDay()->format('Y-m-d').'/'.$duty->getPerson()->getUuidString();
            $station = (string) $duty->getStation()->getUuidString();

            if (isset($seen[$key]) && $seen[$key] !== $station) {
                self::fail(\sprintf('%s is due at two posts on %s.', $duty->getPerson()->getFullName(), $duty->getOnDay()->format('Y-m-d')));
            }

            $seen[$key] = $station;
        }

        self::assertNotEmpty($seen);
    }

    /**
     * A SQUAD CARRIES A RING OF ITS OWN — the second scope a rotation can
     * have, and the one the identity band counts as "per team".
     *
     * ITS PEOPLE ARE A DEPARTMENT'S, not a post's postings: that is the
     * whole difference between the two scopes, and a seed with only
     * per-post rings draws the band's team figure as nought and never
     * renders the scope at all.
     *
     * ITS PEOPLE ARE SPOKEN FOR BEFORE THE POSTS WALK, so a squad away on
     * tour is not also standing the gate it is based at.
     */
    public function testASquadCarriesARingDrawnFromADepartment(): void
    {
        $squad = $this->aDepartmentOf('Protection Service', [0, 1]);

        $this->provider()->load();

        $team = array_values(array_filter(
            $this->repository(RotationRepository::class)->findByArea($this->area),
            static fn (Rotation $rotation): bool => RotationScope::Team === $rotation->getScope(),
        ));

        self::assertCount(1, $team, 'One squad, and only one.');
        self::assertSame($squad->getName(), $team[0]->getTeamName());
        self::assertNotNull($team[0]->getBaseStation(), 'A squad is counted at a base post.');
        self::assertNotEmpty($team[0]->getPool());

        $carried = [];
        foreach ($team[0]->getPool() as $member) {
            $carried[] = (string) $member->getPerson()->getUuidString();
        }

        foreach ($this->repository(RotationRepository::class)->findByArea($this->area) as $rotation) {
            if (RotationScope::Team === $rotation->getScope()) {
                continue;
            }

            foreach ($rotation->getPool() as $member) {
                self::assertNotContains(
                    (string) $member->getPerson()->getUuidString(),
                    $carried,
                    'Somebody is on tour with the squad and standing a post the same month.',
                );
            }
        }
    }

    /** THE SQUAD'S TOUR IS ROSTERED, at the post it is based at. */
    public function testTheSquadsTourProducesWatchesThisMonth(): void
    {
        $this->aDepartmentOf('Protection Service', [0, 1]);

        $this->provider()->load();

        $team = array_values(array_filter(
            $this->repository(RotationRepository::class)->findByArea($this->area),
            static fn (Rotation $rotation): bool => RotationScope::Team === $rotation->getScope(),
        ));
        self::assertCount(1, $team);

        $carried = $this->repository(DutyRepository::class)->findGeneratedBy($team[0], $this->monthStart(), $this->monthEnd());
        self::assertNotEmpty($carried, 'A squad with nothing on its tour is a scope nothing draws.');

        foreach ($carried as $duty) {
            self::assertSame($team[0]->getBaseStation()?->getId(), $duty->getStation()->getId());
        }
    }

    /**
     * AN AREA THAT CANNOT SPARE THE PEOPLE CARRIES NO SQUAD. Taking two
     * people out of a park that has three leaves its posts with one, and
     * a seed that emptied the gates to show off a second scope would be
     * trading the page somebody opens for the one they do not.
     */
    public function testAnAreaTooSmallToSpareThePeopleCarriesNoSquad(): void
    {
        $this->aDepartmentOf('Protection Service', [0, 1]);

        $small = $this->anArea('small reserve');
        $this->aStation($small, 'small post', 'SM-01');
        $this->em->flush();

        $this->provider()->load();

        foreach ($this->repository(RotationRepository::class)->findByArea($small) as $rotation) {
            self::assertSame(RotationScope::Post, $rotation->getScope());
        }
    }

    /**
     * A DEPARTMENT WITH SOME OF THE AREA'S PEOPLE IN IT — a department, and
     * the people PLACED in it.
     *
     * A DEPARTMENT IS A DIMENSION OF A PLACEMENT, not something a position
     * owns: the position says what somebody may do, the placement says
     * where and in which departments they do it. So membership is written
     * on the person, one placement each.
     *
     * @param list<int> $whichPeople indexes into the twelve people the fixture posts
     */
    private function aDepartmentOf(string $name, array $whichPeople): Department
    {
        $department = new Department()->setName($name);
        $this->em->persist($department);

        $position = new Position()->setName('Ranger');
        $this->em->persist($position);

        foreach ($whichPeople as $index) {
            $placement = new Placement()->acrossTheOrganization()->inDepartment($department);
            $this->em->persist($placement);

            $this->people[$index]->setPosition($position)->setPlacement($placement);
        }

        $this->em->flush();

        return $department;
    }

    /**
     * THE SEED SEEDS UNDER THE RULE: ONE STANDING POSTING A PERSON.
     *
     * A POSTING IS WHERE SOMEBODY WORKS, AND THEY WORK IN ONE PLACE
     * (ruled; the area refuses the second). Two standing postings make a
     * roll that cannot be read, a head count that double-counts, and a
     * handset that cannot say which post its check-in is against — so a
     * seed that produced one would be demonstrating a state the product
     * does not allow.
     *
     * THIS IS ASSERTED OVER EVERY POSTING IN THE INSTALLATION and not only
     * this area's, because one posting a person is one AREA too: the way
     * this breaks is a second area staffed with the first one's rangers,
     * which no per-area check would ever see.
     */
    public function testTheSeedLeavesEverybodyStandingAtOnePostAtMost(): void
    {
        $quiet = $this->anArea('quiet reserve');
        foreach (range(1, 3) as $n) {
            $this->aStation($quiet, \sprintf('quiet post %d', $n), \sprintf('QT-0%d', $n));
        }
        foreach (range(1, 4) as $n) {
            $this->aPerson(\sprintf('spare%d@example.test', $n), 'Spare'.$n);
        }
        $this->em->flush();

        $this->provider()->load();
        // TWICE, because a second run is where a seed repeats itself: the
        // first pass is the one everybody writes and the second is the one
        // that posts somebody who is already standing.
        $this->provider()->load();

        $where = [];
        foreach ($this->repository(PostingRepository::class)->findAllStanding() as $posting) {
            $person = $posting->getPerson();
            self::assertNotNull($person);

            $name = $person->getFullName();
            $station = $posting->getStation()?->getName() ?? 'nowhere';

            self::assertArrayNotHasKey(
                $name,
                $where,
                \sprintf('%s stands at %s and at %s.', $name, $where[$name] ?? '?', $station),
            );

            $where[$name] = $station;
        }

        self::assertNotEmpty($where, 'And the seed did staff the park.');
    }
}
