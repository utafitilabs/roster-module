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

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\MockClock;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Enum\PostingSource;
use Uhifadhi\Bundle\AreaBundle\Service\PostingService;
use Uhifadhi\Contracts\Area\DayState;
use Uhifadhi\Contracts\Area\LivePositionsInterface;
use Uhifadhi\Contracts\Area\PresenceProviderInterface;
use Uhifadhi\Roster\Devkit\PresenceContentProvider;
use Uhifadhi\Roster\Devkit\RosterContentProvider;
use Uhifadhi\Roster\Tests\Integration\IntegrationTestCase;

/**
 * THE PROOF BEHIND THE PLAN — the check-ins and the positions that make
 * the demo's rostered days read like days somebody actually worked.
 *
 * IT WRITES THROUGH THE AREA'S OWN HANDSET API and derives nothing. The
 * roster is forbidden to compute presence, and a seeder that wrote a
 * `DayState` into a column would be doing exactly that — and would go on
 * agreeing with itself long after the real derivation had changed under
 * it. So this provider CLAIMS and PINGS the way a phone does, and every
 * reading in these assertions comes back out of the area's own service.
 *
 * WHAT IT HAS TO PRODUCE is the set of states the screens draw. A demo
 * where everybody is quietly at post exercises one branch of Today, one
 * colour on the board and none of the flags, and would have shipped every
 * one of those broken.
 */
final class PresenceContentProviderTest extends IntegrationTestCase
{
    /** A Wednesday on which the fixture's roster stands the day and office watches. */
    private const string PINNED_DAY = '2026-09-23';

    private AreaOfInterest $area;

    protected function setUp(): void
    {
        parent::setUp();

        // PIN THE DAY, not only the hour: the roster plan is drawn per date,
        // and on some dates this park has one watch standing at half past
        // ten, which never reaches the stale mark. It failed on 27 Sep.
        $clock = self::getContainer()->get('clock');
        self::assertInstanceOf(MockClock::class, $clock);
        $clock->modify(self::PINNED_DAY.' 08:00');

        $this->aParkWithFourStations();
    }

    /**
     * THE PARK THIS SUITE SEEDS INTO, built the way an installation builds
     * one — and built by a method rather than inline because one test
     * builds it TWICE, on two empty databases, to prove the demo comes out
     * the same both times.
     */
    private function aParkWithFourStations(): void
    {
        $this->area = $this->anArea();

        $postings = $this->service(PostingService::class);
        self::assertInstanceOf(PostingService::class, $postings);

        // ONE STANDING POSTING A PERSON (ruled, and the area refuses the
        // second), so the park is staffed by bringing enough rangers for
        // the posts rather than by posting the same six people round and
        // round. Four posts, two each, eight people, everybody somewhere
        // exactly once — which is also what a real park's roll looks like.
        $people = [];
        foreach (range(1, 8) as $n) {
            $people[] = $this->aPerson(\sprintf('ranger%d@example.test', $n), 'Ranger'.$n);
        }

        $next = 0;
        foreach (range(1, 4) as $n) {
            $station = $this->aStation($this->area, \sprintf('post %d', $n), \sprintf('ST-0%d', $n));
            $this->em->flush();
            foreach ([0, 1] as $ignored) {
                $postings->post($station, $people[$next], PostingSource::WrittenHere);
                ++$next;
            }
        }

        $this->em->flush();

        $plan = $this->service(RosterContentProvider::class);
        self::assertInstanceOf(RosterContentProvider::class, $plan);
        $plan->load();
    }

    private function provider(): PresenceContentProvider
    {
        $provider = $this->service(PresenceContentProvider::class);
        self::assertInstanceOf(PresenceContentProvider::class, $provider);

        return $provider;
    }

    private function presence(): PresenceProviderInterface
    {
        $presence = self::getContainer()->get('test_public.'.PresenceProviderInterface::class);
        self::assertInstanceOf(PresenceProviderInterface::class, $presence);

        return $presence;
    }

    /**
     * PIN THE HOUR, because these readings are about the day in front of
     * the reader and the day has hours in it.
     *
     * THE SCRIPTS GO TO THE WATCHES ACTUALLY STANDING, so how many of them
     * there are — and therefore how far down the list the seeder gets —
     * depends on the time of day. At ten in the morning this fixture has
     * the day and office watches out, which is two, which reaches the
     * stale mark. At a quarter to midnight it has one, and "a handset has
     * gone quiet" is then not a state the park is in.
     *
     * THIS IS WHAT BROKE CI. Not a wrong assertion — the same assertion,
     * true at the hour one leg ran and false at the hour the other did.
     *
     * AND IT HANDS THE CLOCK BACK, because pinning one is only half of it:
     * a reading asked for "now" while the clock says half past ten is a
     * reading of two different instants, and the answer then depends on
     * the hour the suite happens to run at — which is the very thing this
     * helper exists to stop. A caller that needs the instant takes it from
     * here rather than from the wall.
     */
    private function atMidMorning(): MockClock
    {
        $clock = self::getContainer()->get('clock');
        self::assertInstanceOf(MockClock::class, $clock);
        $clock->modify('today 10:30');

        return $clock;
    }

    /** The pinned instant: every day this suite names is the clock's, never the wall's. */
    private function now(): \DateTimeImmutable
    {
        $clock = self::getContainer()->get('clock');
        self::assertInstanceOf(MockClock::class, $clock);

        return $clock->now();
    }

    private function positions(): LivePositionsInterface
    {
        $positions = self::getContainer()->get('test_public.'.LivePositionsInterface::class);
        self::assertInstanceOf(LivePositionsInterface::class, $positions);

        return $positions;
    }

    /**
     * EVERY DAY OF THE MONTH UP TO TODAY, as the area reads it back.
     *
     * @return list<\Uhifadhi\Contracts\Area\PersonDay>
     */
    private function theMonthAsRead(): array
    {
        $days = [];
        $day = $this->now()->modify('first day of this month')->setTime(0, 0);
        $today = $this->now()->modify('today');

        while ($day <= $today) {
            foreach ($this->presence()->dayIn((string) $this->area->getUuidString(), $day->format('Y-m-d')) as $personDay) {
                $days[] = $personDay;
            }
            $day = $day->modify('+1 day');
        }

        return $days;
    }

    public function testItIsSeededAfterTheRosterItProves(): void
    {
        $provider = $this->provider();

        self::assertSame('roster-presence', $provider->key());
        self::assertSame(['roster'], $provider->dependsOn());
    }

    /** THE DAYS ARE THERE AT ALL, and they are the area's reading, not ours. */
    public function testTheMonthSoFarHasBeenWorked(): void
    {
        $this->provider()->load();

        self::assertNotEmpty($this->theMonthAsRead(), 'A month of watches with nobody reporting is an empty demo.');
    }

    /**
     * THE STATES THE SCREENS DRAW ALL APPEAR. Named one by one rather than
     * counted, because the point is that no branch of Today, the board or
     * the live plate goes unexercised.
     */
    public function testEveryReadingTheScreensDrawAppearsAtLeastOnce(): void
    {
        $this->provider()->load();

        $seen = [];
        foreach ($this->theMonthAsRead() as $day) {
            foreach ($day->watches as $watch) {
                $seen[$watch->state->value] = true;
            }
        }

        self::assertArrayHasKey(DayState::WorkingElsewhere->value, $seen, 'Somebody out on an escort.');
        self::assertArrayHasKey(DayState::NotWorking->value, $seen, 'Somebody unfit for duty.');
        self::assertArrayHasKey(DayState::Special->value, $seen, 'Somebody on a special assignment.');
        self::assertArrayHasKey(DayState::AtPostUnverified->value, $seen, 'A claim the positions do not bear out.');
    }

    /** A CLAIM NOBODY CLOSED — a fact the board has to be able to draw. */
    public function testSomebodyWentHomeWithoutCheckingOut(): void
    {
        $this->provider()->load();

        $found = false;
        foreach ($this->theMonthAsRead() as $day) {
            foreach ($day->watches as $watch) {
                $found = $found || $watch->notCheckedOut;
            }
        }

        self::assertTrue($found);
    }

    /**
     * A DAY WITH TWO WATCHES ON IT — the ruling's own case, and the one a
     * demo of single intervals would never produce. A morning at the gate
     * and an afternoon on an escort are one person's day.
     */
    public function testSomebodysDayHoldsTwoWatches(): void
    {
        $this->provider()->load();

        $longest = 0;
        foreach ($this->theMonthAsRead() as $day) {
            $longest = max($longest, \count($day->watches));
        }

        self::assertGreaterThan(1, $longest);
    }

    /** POSITIONS BEHIND THE CLAIMS, so the live plate has something to draw. */
    public function testTheClaimsCarryPositions(): void
    {
        $this->provider()->load();

        $pings = 0;
        foreach ($this->theMonthAsRead() as $day) {
            $pings += $day->pings;
        }

        self::assertGreaterThan(0, $pings);
    }

    /**
     * NOTHING IS REPORTED FROM THE FUTURE. A check-in dated next week is
     * not demo content, it is a bug that would make the board read a plan
     * as a fact.
     */
    public function testNothingIsReportedFromTheFuture(): void
    {
        $this->provider()->load();

        $tomorrow = $this->now()->modify('tomorrow');
        $end = $this->now()->modify('last day of this month')->setTime(0, 0);

        for ($day = $tomorrow; $day <= $end; $day = $day->modify('+1 day')) {
            self::assertSame([], $this->presence()->dayIn((string) $this->area->getUuidString(), $day->format('Y-m-d')), 'A watch reported before it was stood.');
        }
    }

    /** A SECOND RUN CHANGES NOTHING: the claims carry their own references. */
    public function testASecondRunChangesNothing(): void
    {
        $this->provider()->load();
        $before = array_map(static fn (object $d): string => $d->localDate.'/'.$d->personUuid.'/'.\count($d->watches), $this->theMonthAsRead());

        $this->provider()->load();
        $after = array_map(static fn (object $d): string => $d->localDate.'/'.$d->personUuid.'/'.\count($d->watches), $this->theMonthAsRead());

        self::assertSame($before, $after);
    }

    /**
     * TODAY IS SCRIPTED, NOT DRAWN — the day somebody opens carries the
     * readings the screens are built for, in a stated order.
     *
     * THE BOARD, TODAY AND THE LIVE PLATE ALL READ ONE DAY. Left to the
     * draw, a park showed "at post" over and over on the morning somebody
     * looked: no claim to question, nobody silent, nothing to decide, on
     * the screens built for deciding. The month behind it was fine, which
     * is exactly why nobody noticed.
     *
     * WHAT IS ASSERTED IS THE MECHANISM AND NOT THE WHOLE LIST. This
     * fixture is four posts, so only the first of the scripts are reached;
     * the full spread needs a park-sized roster, and what makes it appear
     * there is this same order being applied. A watch that has not started
     * is not in it at all — "due later" is its own reading and the clock
     * owns it.
     */
    public function testTodayIsScriptedSoTheDaySomebodyOpensIsWorthReading(): void
    {
        $this->atMidMorning();
        $this->provider()->load();

        $today = $this->presence()->dayIn((string) $this->area->getUuidString(), $this->now()->modify('today')->format('Y-m-d'));
        self::assertNotSame([], $today, 'Somebody is on today.');

        $atPost = array_filter(
            $today,
            static fn (\Uhifadhi\Contracts\Area\PersonDay $day): bool => \in_array($day->state, [DayState::AtPostVerified, DayState::AtPostUnverified], true),
        );

        self::assertNotSame([], $atPost, 'The first scripted watch of the day is at its post.');
    }

    /**
     * AND ONE HANDSET HAS GONE QUIET — the stale mark on the live plate,
     * which is the only one that means "where they WERE" rather than
     * where they are.
     *
     * IT CANNOT BE LEFT TO A FREQUENCY. A plate whose every mark is fresh
     * never shows the state a duty officer acts on, and a demo that got
     * one only on the days the draw felt like it is a demo that is wrong
     * on the morning somebody looks.
     */
    public function testOneHandsetOnTodaysWatchHasGoneQuiet(): void
    {
        $clock = $this->atMidMorning();
        $this->provider()->load();

        // THE PLATE IS ASKED FOR THE PINNED INSTANT, not for the wall's.
        // A live reading closes a watch whose rostered end has passed, so
        // asking at the real hour empties a plate the seeder filled at
        // half past ten — green all morning, red all evening, and nothing
        // wrong with either the seeder or the plate.
        $live = $this->positions()->liveIn((string) $this->area->getUuidString(), $clock->now());

        self::assertNotSame([], $live->positions, 'The plate is not empty.');
        self::assertGreaterThan(0, $live->staleCount(), 'No mark on the plate is stale.');
    }

    /**
     * AND BEFORE ANY WATCH HAS BEGUN, NOTHING ON TODAY IS SCRIPTED.
     *
     * "DUE LATER" IS ITS OWN READING AND THE CLOCK OWNS IT. At four in the
     * morning the day watch has not started and the night one began
     * yesterday: scripting a claim onto a watch that starts at six would
     * be the demo reporting the future, and a board drawn at that hour
     * should show people due rather than people present.
     *
     * THIS IS THE CASE THAT BROKE CI. The same code seeded one thing in
     * the morning and another in the afternoon, so the suite was green on
     * one leg and red on the next — not because either was wrong, but
     * because the seeder read a wall clock nobody could pin. It is a
     * collaborator now, and this test moves it on purpose.
     */
    public function testBeforeTheFirstWatchBeginsNothingOnTodayIsScripted(): void
    {
        $clock = self::getContainer()->get('clock');
        self::assertInstanceOf(MockClock::class, $clock);
        $clock->modify('today 04:00');

        $this->provider()->load();

        // NOTHING WAS CLAIMED ON TODAY. Every watch on this day starts at
        // six or later, so at four in the morning the seeder has written
        // no check-in against any of them: "due later" is the reading, and
        // the demo says it by writing nothing at all.
        //
        // THE FACT IS THE COUNT AND NOT A STATE. A night watch that began
        // YESTERDAY and is still running is legitimately part of today's
        // reading and may carry any claim its own day was scripted with —
        // so asserting "no reading on today says X" is asserting something
        // untrue, and which watch happens to hold X depends on a sort
        // order. What cannot vary is that nothing was written FOR today.
        self::assertSame(0, $this->claimsRecordedOn($clock->now()), 'A watch that has not started cannot have been claimed against.');

        // AND THE PLATE IS HONEST ABOUT IT: every mark on it belongs to a
        // watch of an EARLIER day.
        //
        // THE WHOLE PLATE IS NOT THE CLAIM, and asserting it was is what
        // made this test flaky — the reasoning three lines above, applied
        // to the claims count and then forgotten for the plate. A night
        // watch that began yesterday at six and runs to six this morning
        // is still out at four, legitimately live, and legitimately quiet
        // if its handset has not pinged for a while; about one watch in
        // nine is left open on purpose, and which ones depends on a draw
        // taken from the duty's uuid. So "no mark is stale" was true on
        // most runs and false on roughly one in ten, and true for no
        // reason either time.
        //
        // WHAT CANNOT VARY is that nothing which STARTS TODAY is on the
        // plate, because nothing that starts today has begun. That is the
        // fact this test is about, and it is stronger than the count it
        // replaces: a seeder that claimed today's watches early would be
        // caught by it, and was not caught by a zero.
        foreach ($this->positions()->liveIn((string) $this->area->getUuidString(), $clock->now())->positions as $mark) {
            self::assertLessThan(
                $clock->now()->setTime(0, 0),
                $this->watchDayOf($mark->clientRef),
                'A watch that has not started cannot be standing on the plate.',
            );
        }
    }

    /**
     * THE DAY THE WATCH BEHIND A MARK BELONGS TO. The seeder's own client
     * reference carries the duty it claimed against — `demo-<uuid>-1` —
     * which is the only handle a live mark offers back to the roster.
     */
    private function watchDayOf(?string $clientRef): \DateTimeImmutable
    {
        self::assertIsString($clientRef);

        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $day = $em->getConnection()->fetchOne(
            'SELECT local_date FROM duty_checkin WHERE client_ref = ?',
            [$clientRef],
        );

        self::assertIsString($day);

        return new \DateTimeImmutable($day);
    }

    /**
     * AND ONCE THE WATCHES HAVE BEGUN, TODAY IS CLAIMED AGAINST — the same
     * count, at an hour when the seeder has work to do. Without this the
     * test above would pass against a seeder that wrote nothing, ever.
     */
    public function testOnceTheWatchesHaveBegunTodayIsClaimedAgainst(): void
    {
        $this->atMidMorning();
        $this->provider()->load();

        $clock = self::getContainer()->get('clock');
        self::assertInstanceOf(MockClock::class, $clock);

        self::assertGreaterThan(0, $this->claimsRecordedOn($clock->now()));
    }

    /**
     * THE SAME PARK SEEDS THE SAME DEMO, TWICE, ON TWO EMPTY DATABASES.
     *
     * THIS IS THE PROMISE `DemoDraw` MAKES IN ITS OWN DOCBLOCK — "stable
     * across runs, machines and PHP versions" — and it was not kept. The
     * draw was keyed on the duty's UUID, minted fresh on every seed, so the
     * variety it produced was different every time: a screenshot did not
     * reproduce, a bug somebody saw once could not be got back, and two
     * assertions in this very file rode on a coin toss. It read as a
     * PHP-version bug on CI, green on one matrix leg and red on the other
     * for no reason but the draw.
     *
     * A STATION, A SHIFT AND A DAY ARE WHAT A WATCH IS. Keying on those
     * makes the demo a function of the park, which is the only version of
     * "stable" worth having — and it is what this test measures, rather
     * than measuring that one figure came out the way it did today.
     */
    public function testTheSameParkSeedsTheSameDemoTwice(): void
    {
        $this->atMidMorning();
        $this->provider()->load();
        $first = $this->everyClaim();

        self::assertNotSame([], $first, 'A demo that seeded nothing would pass this test for the wrong reason.');

        // A SECOND INSTALLATION OF THE SAME PARK — empty database, same
        // fixture, same hour. The identity map goes with the tables: the
        // ids restart at one, and a manager still holding the first park's
        // objects would refuse the second park's as collisions.
        self::freshDatabase($this->em);
        $this->em->clear();
        $this->aParkWithFourStations();
        $this->atMidMorning();
        $this->provider()->load();

        self::assertSame($first, $this->everyClaim(), 'The same park seeded a different demo.');
    }

    /**
     * EVERY CLAIM THE SEEDER WROTE, as comparable text — keyed by what the
     * watch IS, never by a row id, because the row ids are exactly what
     * differ between two seeds of one park.
     *
     * @return list<string>
     */
    private function everyClaim(): array
    {
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $rows = $em->getConnection()->fetchAllAssociative(
            <<<'SQL'
                SELECT s.code, u.email, k.status_key AS status, c.local_date,
                       c.occurred_at, c.ended_at,
                       (SELECT COUNT(*) FROM duty_position p WHERE p.checkin_id = c.id) AS pings
                FROM duty_checkin c
                JOIN team_user u ON u.id = c.person_id
                JOIN duty_checkin_status k ON k.id = c.status_id
                LEFT JOIN station s ON s.id = c.station_id
                ORDER BY s.code NULLS FIRST, u.email, c.local_date, c.occurred_at
                SQL,
        );

        return array_map(
            static fn (array $row): string => implode('|', array_map(
                static fn (mixed $cell): string => \is_scalar($cell) ? (string) $cell : '—',
                $row,
            )),
            $rows,
        );
    }

    /** How many check-ins the seeder wrote for that day. */
    private function claimsRecordedOn(\DateTimeImmutable $day): int
    {
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        $count = $em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM duty_checkin c JOIN area_of_interest a ON a.id = c.area_id WHERE a.uuid = ? AND c.local_date = ?',
            [(string) $this->area->getUuidString(), $day->format('Y-m-d')],
        );

        self::assertIsNumeric($count);

        return (int) $count;
    }
}
