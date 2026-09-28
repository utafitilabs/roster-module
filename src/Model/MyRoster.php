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

namespace Uhifadhi\Roster\Model;

use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\Station;

/**
 * A PERSON'S OWN ROSTER (#19; design variants-my-dashboard, ME·09 and
 * roster.html, approved 28 Sep 2026) — the week at the post they are posted
 * at, read once, so the dashboard card and the page can never disagree.
 *
 * EVERYTHING HERE IS THE POST THEY ARE POSTED AT and nothing wider: the
 * people posted there, the watches it runs, the absences of those people.
 * That is what lets the page name no permission.
 */
final readonly class MyRoster
{
    /**
     * @param list<\DateTimeImmutable>                                                                            $days          Monday to Sunday
     * @param list<list<ShiftMark>>                                                                               $mine          my marks, a list per day
     * @param list<MyRosterRow>                                                                                   $rows          everybody posted here, me first, then the head
     * @param array<string, string>                                                                               $countedShifts the watches counted per day, key to label
     * @param list<array{onDuty: int, byShift: array<string, int>}>                                               $counts        per day, the post's own watches
     * @param array<string, int>                                                                                  $expects       how many each watch needs, key to people
     * @param list<string>                                                                                        $watches       `day 06–18`, per watch the post runs
     * @param list<MyShift>                                                                                       $coming        my watches in the week ahead
     * @param list<string>|null                                                                                   $onWithMe      null when I stand no watch today
     * @param list<MySwap>                                                                                        $swaps
     * @param list<array{name: string, me: bool, kind: string, from: \DateTimeImmutable, to: \DateTimeImmutable}> $away
     */
    public function __construct(
        public Station $station,
        public AreaOfInterest $area,
        public ?MyRosterRow $head,
        public array $days,
        public array $mine,
        public array $rows,
        public array $countedShifts,
        public array $counts,
        public array $expects,
        public array $watches,
        public int $myShifts,
        public int $myMinutes,
        public ?MyShift $next,
        public array $coming,
        public ?array $onWithMe,
        public array $swaps,
        public array $away,
    ) {
    }

    /** The ISO week number the design prints in the card's heading. */
    public function week(): int
    {
        return (int) $this->days[0]->format('W');
    }
}
