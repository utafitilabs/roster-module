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

use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\Posting;
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Bundle\AreaBundle\Enum\PostingSource;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Roster\Entity\Duty;
use Uhifadhi\Roster\Entity\StationWatch;
use Uhifadhi\Roster\Entity\WatchChange;
use Uhifadhi\Roster\Enum\SwapState;
use Uhifadhi\Roster\Enum\WatchChangeState;
use Uhifadhi\Roster\Exception\ApprovalNeedsAHeadException;
use Uhifadhi\Roster\Service\ApprovalService;
use Uhifadhi\Roster\Service\SwapService;
use Uhifadhi\Roster\Tests\Integration\IntegrationTestCase;

/**
 * A STATION MAY REQUIRE ITS HEAD'S APPROVAL (ruled 29 Sep, #60; built 12 Oct):
 * an accepted swap there waits for the head before a watch moves, and so does
 * a planner's change to their own watch. Approving makes the change; refusing
 * leaves both watches as they were. Only the head decides, and a station with
 * no head cannot require it.
 */
final class ApprovalServiceTest extends IntegrationTestCase
{
    private AreaOfInterest $area;
    private Station $gate;
    private User $head;
    private User $ada;
    private User $ben;

    protected function setUp(): void
    {
        parent::setUp();

        $this->area = $this->anArea();
        $this->gate = $this->aStation($this->area, 'north gate post', 'ST-01');
        $this->theShiftVocabulary($this->area);
        $this->head = $this->aPerson('rehema@example.test', 'Rehema', 'Mushi');
        $this->ada = $this->aPerson('ada@example.test', 'Ada');
        $this->ben = $this->aPerson('ben@example.test', 'Ben');
        $this->em->persist(new Posting()->setStation($this->gate)->setPerson($this->head)->setSince(new \DateTimeImmutable('2026-09-01'))->setLeader(true)->setSource(PostingSource::WrittenHere));
        $this->em->persist(new StationWatch($this->gate, 120, 240));
        $this->em->flush();
    }

    public function testAStationRequiresNothingUntilItIsSwitchedOn(): void
    {
        self::assertFalse($this->approvals()->requiresApproval($this->gate));

        $this->approvals()->require($this->gate, true);

        self::assertTrue($this->approvals()->requiresApproval($this->gate));
    }

    public function testAStationWithNoHeadCannotRequireApproval(): void
    {
        $post = $this->aStation($this->area, 'south post', 'ST-02');
        $this->em->persist(new StationWatch($post, 120, 240));
        $this->em->flush();

        $this->expectException(ApprovalNeedsAHeadException::class);
        $this->approvals()->require($post, true);
    }

    public function testWithoutApprovalAnAcceptedSwapMovesTheWatchAtOnce(): void
    {
        $duty = $this->aDuty($this->ada, 'day', '2026-10-19');
        $swap = $this->swaps()->offer($duty, $this->ben, $this->ada);

        $this->swaps()->accept($swap, new \DateTimeImmutable('2026-10-18 20:00'));

        self::assertSame(SwapState::Accepted, $swap->getState());
        self::assertSame($this->ben->getId(), $duty->getPerson()->getId());
    }

    public function testAnAcceptedSwapWaitsForTheHeadAndMovesNothing(): void
    {
        $this->approvals()->require($this->gate, true);
        $duty = $this->aDuty($this->ada, 'day', '2026-10-19');
        $swap = $this->swaps()->offer($duty, $this->ben, $this->ada);

        $this->swaps()->accept($swap, new \DateTimeImmutable('2026-10-18 20:00'));

        self::assertSame(SwapState::Waiting, $swap->getState());
        self::assertSame($this->ada->getId(), $duty->getPerson()->getId(), 'the watch is Ada\'s until the head approves');
        self::assertSame([$swap->getId()], array_map(static fn ($item): ?int => $item->swap?->getId(), $this->approvals()->waitingIn($this->area)));
    }

    public function testTheHeadApprovesAndTheWatchMoves(): void
    {
        $this->approvals()->require($this->gate, true);
        $duty = $this->aDuty($this->ada, 'day', '2026-10-19');
        $swap = $this->swaps()->offer($duty, $this->ben, $this->ada);
        $this->swaps()->accept($swap, new \DateTimeImmutable('2026-10-18 20:00'));

        $this->approvals()->approveSwap($swap, $this->head);

        self::assertSame(SwapState::Accepted, $swap->getState());
        self::assertSame($this->ben->getId(), $duty->getPerson()->getId());
        self::assertSame([], $this->approvals()->waitingIn($this->area));
    }

    public function testTheHeadRefusesAndBothWatchesStand(): void
    {
        $this->approvals()->require($this->gate, true);
        $duty = $this->aDuty($this->ada, 'day', '2026-10-19');
        $swap = $this->swaps()->offer($duty, $this->ben, $this->ada);
        $this->swaps()->accept($swap, new \DateTimeImmutable('2026-10-18 20:00'));

        $this->approvals()->refuseSwap($swap, $this->head);

        self::assertSame(SwapState::Refused, $swap->getState());
        self::assertSame($this->ada->getId(), $duty->getPerson()->getId());
    }

    public function testOnlyTheHeadDecides(): void
    {
        $this->approvals()->require($this->gate, true);
        $duty = $this->aDuty($this->ada, 'day', '2026-10-19');
        $swap = $this->swaps()->offer($duty, $this->ben, $this->ada);
        $this->swaps()->accept($swap, new \DateTimeImmutable('2026-10-18 20:00'));

        self::assertTrue($this->approvals()->mayDecide($this->gate, $this->head));
        self::assertFalse($this->approvals()->mayDecide($this->gate, $this->ben));
        $this->expectException(AccessDeniedException::class);
        $this->approvals()->approveSwap($swap, $this->ben);
    }

    /** A planner who changes their own watch at such a station asks the head; the change waits. */
    public function testAChangeToOnesOwnWatchWaitsForTheHeadAndIsMadeOnApproval(): void
    {
        $this->approvals()->require($this->gate, true);
        $duty = $this->aDuty($this->ada, 'day', '2026-10-19');

        self::assertTrue($this->approvals()->needsApproval($duty, $this->ada), 'her own watch');
        self::assertFalse($this->approvals()->needsApproval($duty, $this->ben), 'somebody else\'s is a planner\'s ordinary work');

        $change = $this->approvals()->askToChangeShift($duty, 'night', $this->ada);
        self::assertSame(WatchChangeState::Waiting, $change->getState());
        self::assertSame('day', $duty->getShiftKey(), 'nothing changed yet');

        $this->approvals()->approveChange($change, $this->head);
        self::assertSame(WatchChangeState::Approved, $change->getState());
        self::assertSame('night', $duty->getShiftKey());
    }

    public function testTheHeadsWaitingAreTheStationsTheyLead(): void
    {
        $this->approvals()->require($this->gate, true);
        $duty = $this->aDuty($this->ada, 'day', '2026-10-19');
        $this->approvals()->askToChangeShift($duty, 'night', $this->ada);

        self::assertCount(1, $this->approvals()->waitingForHead($this->head));
        self::assertCount(0, $this->approvals()->waitingForHead($this->ben));
        self::assertInstanceOf(WatchChange::class, $this->approvals()->waitingForHead($this->head)[0]->change);
    }

    private function aDuty(User $person, string $shiftKey, string $day): Duty
    {
        $duty = new Duty($this->area, $this->gate, $person, $shiftKey, new \DateTimeImmutable($day));
        $this->em->persist($duty);
        $this->em->flush();

        return $duty;
    }

    private function approvals(): ApprovalService
    {
        $service = $this->service(ApprovalService::class);
        self::assertInstanceOf(ApprovalService::class, $service);

        return $service;
    }

    private function swaps(): SwapService
    {
        $service = $this->service(SwapService::class);
        self::assertInstanceOf(SwapService::class, $service);

        return $service;
    }
}
