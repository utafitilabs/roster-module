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

namespace Uhifadhi\Roster\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Uhifadhi\Contracts\Area\DayState;
use Uhifadhi\Contracts\Area\LivePosition;
use Uhifadhi\Contracts\Area\LivePresence;
use Uhifadhi\Roster\Model\PostPresence;
use Uhifadhi\Roster\Model\PostState;
use Uhifadhi\Roster\Model\RosteredPerson;
use Uhifadhi\Roster\Model\ShiftWindow;
use Uhifadhi\Roster\Service\RosterLiveService;

/**
 * THE TAIL OF THE RAIL'S PEOPLE LIST — who is in it, and why.
 *
 * "NOT HERE" AND "NOT DUE" ARE DIFFERENT ANSWERS, and only one of them is
 * a problem. A watch that starts at six cannot have been checked into at
 * nine in the morning, and reading that silence as "no position" would put
 * a name in front of the duty officer every single morning for no reason
 * at all. So the people who are legitimately not on the watch are marked
 * as the tail — still in the list, still counted, and folded by the
 * surface behind one head.
 *
 * THE CLOCK IS PASSED IN. A grouping that read a clock inside itself would
 * be a grouping this test could only assert at nine in the morning.
 *
 * GROUPING IS A FUNCTION OF ITS ARGUMENTS and is declared as one: it reads
 * no repository, no plate and no clock, so it needs no instance and this
 * test needs no doubles standing in for five collaborators it never calls.
 */
final class RosterLiveRailTailTest extends TestCase
{
    /** @param list<RosteredPerson> $people */
    private function post(array $people): PostPresence
    {
        return new PostPresence(
            stationUuid: 'station-1',
            stationName: 'north gate post',
            rostered: $people,
            expected: \count($people),
            verified: 0,
            flagged: 0,
            state: PostState::Reporting,
        );
    }

    private function person(string $name, string $shift): RosteredPerson
    {
        return new RosteredPerson(personUuid: $name, personName: $name, shiftKey: $shift, shiftLabel: $shift);
    }

    /** @return array<string, array{int, bool}> label => [count, is the tail] */
    private function groupsAt(string $clock): array
    {
        $now = new \DateTimeImmutable('today '.$clock);
        $rail = RosterLiveService::rail(
            new LivePresence(positions: [], pingIntervalMinutes: 30, asOf: $now),
            [$this->post([$this->person('ada', 'night'), $this->person('bea', 'day')])],
            ['day' => ShiftWindow::of('day', '06:00', '18:00'), 'night' => ShiftWindow::of('night', '18:00', '06:00')],
            $now,
        );

        $groups = [];
        foreach ($rail as $group) {
            if (!$group->isEmpty()) {
                $groups[$group->label] = [$group->count(), $group->tail];
            }
        }

        return $groups;
    }

    /**
     * A WATCH THAT HAS NOT STARTED IS DUE, NOT SILENT — and due is the
     * tail, because nobody needs to act on it.
     */
    public function testAWatchThatHasNotBegunReadsAsDueLaterAndIsTheTail(): void
    {
        $groups = $this->groupsAt('09:00');

        self::assertSame([1, true], $groups['due later'] ?? null, 'The night watch has not begun at nine.');
        self::assertSame([1, false], $groups['no position'] ?? null, 'The day watch has, and nothing has reported.');
    }

    /**
     * AND ONCE IT HAS STARTED, SILENCE IS SILENCE. The same person, six
     * hours later, is somebody the duty officer has heard nothing from.
     */
    public function testOnceTheWatchHasBegunTheSameSilenceIsNoPosition(): void
    {
        $groups = $this->groupsAt('20:00');

        self::assertArrayNotHasKey('due later', $groups, 'Nothing is due later once both watches have begun.');
        self::assertSame([2, false], $groups['no position'] ?? null);
    }

    /**
     * WITHOUT THE SHIFT HOURS NOTHING IS DUE. A caller that cannot say
     * when a watch starts gets the honest answer — silence — rather than
     * a guess that folds somebody away.
     */
    public function testWithoutTheShiftHoursNobodyIsAssumedToBeDue(): void
    {
        $now = new \DateTimeImmutable('today 09:00');
        $rail = RosterLiveService::rail(
            new LivePresence(positions: [], pingIntervalMinutes: 30, asOf: $now),
            [$this->post([$this->person('ada', 'night')])],
        );

        foreach ($rail as $group) {
            if ('due later' === $group->label) {
                self::assertTrue($group->isEmpty());
            }
        }
    }

    /**
     * WHO THE PLATE CANNOT DRAW (ruled 1 Oct, #16 D): the people on duty with
     * no position, and every stale fix - and nobody whose watch has not begun.
     */
    public function testTheStripNamesTheSilentAndTheStaleButNotTheDue(): void
    {
        $now = new \DateTimeImmutable('today 09:00');
        $stale = new LivePosition(
            personUuid: 'cleo',
            personName: 'Cleo Example',
            clientRef: 'c-1',
            state: DayState::AtPostVerified,
            latitude: -5.7,
            longitude: 12.3,
            recordedAt: $now->modify('-2 hours'),
        );
        $rail = RosterLiveService::rail(
            new LivePresence(positions: [$stale], pingIntervalMinutes: 30, asOf: $now),
            [$this->post([$this->person('ada', 'night'), $this->person('bea', 'day'), new RosteredPerson(personUuid: 'cleo', personName: 'Cleo Example', shiftKey: 'day', shiftLabel: 'day')])],
            ['day' => ShiftWindow::of('day', '06:00', '18:00'), 'night' => ShiftWindow::of('night', '18:00', '06:00')],
            $now,
        );

        $strip = RosterLiveService::notOnPlate($rail);

        self::assertSame(['Cleo Example', 'bea'], array_column($strip, 'name'), 'the stale fix and the silent watch; never the watch not yet begun');
        self::assertSame(['stale · 2 h 00', 'no position'], array_column($strip, 'reason'));
        self::assertSame('north gate post', $strip[1]['seat']);
    }
}
