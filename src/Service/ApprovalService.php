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

namespace Uhifadhi\Roster\Service;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Bundle\AreaBundle\Repository\PostingRepository;
use Uhifadhi\Contracts\Entity\UserInterface;
use Uhifadhi\Roster\Entity\Duty;
use Uhifadhi\Roster\Entity\Swap;
use Uhifadhi\Roster\Entity\WatchChange;
use Uhifadhi\Roster\Enum\SwapState;
use Uhifadhi\Roster\Enum\WatchChangeKind;
use Uhifadhi\Roster\Enum\WatchChangeState;
use Uhifadhi\Roster\Exception\ApprovalNeedsAHeadException;
use Uhifadhi\Roster\Model\ApprovalItem;
use Uhifadhi\Roster\Repository\StationWatchRepository;
use Uhifadhi\Roster\Repository\SwapRepository;
use Uhifadhi\Roster\Repository\WatchChangeRepository;

/**
 * A STATION'S HEAD APPROVES (ruled 29 Sep, #60; built 11 Oct).
 *
 * Where a station requires it, an agreed swap waits for the head before a
 * watch moves, and so does a planner's change to their OWN watch — whoever
 * plans a station may change anybody's watch there, and this keeps them from
 * approving their own day off. Approving makes the change exactly as the
 * sheet's menu or an acceptance would; refusing leaves every watch as it was.
 *
 * WHO DECIDES: the station's head — the person holding its leader's posting —
 * or a tier above the matrix (an Admin or a Super Admin), who may do anything
 * on the roster anyway. A station with no head cannot require approval.
 */
final readonly class ApprovalService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private StationWatchRepository $watches,
        private PostingRepository $postings,
        private SwapRepository $swaps,
        private WatchChangeRepository $changes,
        private SwapService $swapService,
        private SheetDayService $days,
        private ClockInterface $clock,
        private ?AuthorizationCheckerInterface $authorization = null,
    ) {
    }

    public function requiresApproval(Station $station): bool
    {
        return $this->watches->findOneForStation($station)?->requiresApproval() ?? false;
    }

    /**
     * Switch whether the station's head approves.
     *
     * @throws ApprovalNeedsAHeadException when switching it on at a station with no head
     * @throws \LogicException             when the station keeps no watch
     */
    public function require(Station $station, bool $required): void
    {
        $watch = $this->watches->findOneForStation($station) ?? throw new \LogicException(\sprintf('%s keeps no watch to approve.', (string) $station->getName()));
        if ($required && null === $this->headOf($station)) {
            throw new ApprovalNeedsAHeadException($station);
        }

        $watch->setRequiresApproval($required);
        $this->entityManager->flush();
    }

    public function headOf(Station $station): ?UserInterface
    {
        return $this->postings->findLeaderAt($station)?->getPerson();
    }

    public function mayDecide(Station $station, ?UserInterface $person): bool
    {
        if (null === $person) {
            return false;
        }

        $head = $this->headOf($station);
        if (null !== $head && $head->getId() === $person->getId()) {
            return true;
        }

        return (bool) $this->authorization?->isGranted('ROLE_ADMIN');
    }

    /** Whether this change by this person must be asked rather than made: their own watch, at a station whose head approves. */
    public function needsApproval(Duty $duty, ?UserInterface $by): bool
    {
        return null !== $by
            && $duty->getPerson()->getId() === $by->getId()
            && $this->requiresApproval($duty->getStation());
    }

    public function askToChangeShift(Duty $duty, string $shiftKey, UserInterface $by): WatchChange
    {
        return $this->ask($duty, WatchChangeKind::Shift, $by, $shiftKey);
    }

    public function askToMove(Duty $duty, UserInterface $to, UserInterface $by): WatchChange
    {
        return $this->ask($duty, WatchChangeKind::Move, $by, null, $to);
    }

    public function askForTheDayOff(Duty $duty, UserInterface $by): WatchChange
    {
        return $this->ask($duty, WatchChangeKind::DayOff, $by);
    }

    public function approveSwap(Swap $swap, UserInterface $by): void
    {
        $this->assertDecides($swap->getDuty()->getStation(), $by, SwapState::Waiting === $swap->getState());
        $this->swapService->make($swap, $this->clock->now());
    }

    public function refuseSwap(Swap $swap, UserInterface $by): void
    {
        $this->assertDecides($swap->getDuty()->getStation(), $by, SwapState::Waiting === $swap->getState());
        $this->swapService->refuse($swap, $this->clock->now());
    }

    public function approveChange(WatchChange $change, UserInterface $by): void
    {
        $duty = $change->getDuty() ?? throw new \LogicException('The watch this change was asked of is gone.');
        $this->assertDecides($duty->getStation(), $by, WatchChangeState::Waiting === $change->getState());

        $change->decide(WatchChangeState::Approved, $by, $this->clock->now());
        $this->entityManager->flush();

        match ($change->getKind()) {
            WatchChangeKind::Shift => $this->days->changeShift($duty, (string) $change->getShiftKey(), $by),
            WatchChangeKind::Move => $this->days->moveTo($duty, $change->getToPerson() ?? throw new \LogicException('Nobody to give the watch to.'), $by),
            WatchChangeKind::DayOff => $this->days->giveTheDayOff($duty, $by),
        };
    }

    public function refuseChange(WatchChange $change, UserInterface $by): void
    {
        $duty = $change->getDuty() ?? throw new \LogicException('The watch this change was asked of is gone.');
        $this->assertDecides($duty->getStation(), $by, WatchChangeState::Waiting === $change->getState());

        $change->decide(WatchChangeState::Refused, $by, $this->clock->now());
        $this->entityManager->flush();
    }

    /**
     * EVERYTHING WAITING IN AN AREA, oldest first — the Week tab's strip.
     *
     * @return list<ApprovalItem>
     */
    public function waitingIn(AreaOfInterest $area, ?UserInterface $viewer = null): array
    {
        return $this->items($this->swaps->findWaitingInArea($area), $this->changes->findWaitingInArea($area), $viewer);
    }

    /**
     * WHAT WAITS FOR ONE HEAD, at the stations they lead — their dashboard's card.
     *
     * @return list<ApprovalItem>
     */
    public function waitingForHead(UserInterface $person): array
    {
        $stations = [];
        foreach ($this->postings->findStandingByPerson($person) as $posting) {
            $station = $posting->getStation();
            if ($posting->isLeader() && null !== $station) {
                $stations[] = $station;
            }
        }

        return $this->items($this->swaps->findWaitingAtStations($stations), $this->changes->findWaitingAtStations($stations), $person);
    }

    /**
     * WHAT WAITS FOR THE PERSON WITH THIS UUID, as a head — the dashboard's
     * card, which knows the person by their uuid.
     *
     * @return list<ApprovalItem>
     */
    public function waitingForHeadByUuid(string $personUuid): array
    {
        foreach ($this->postings->findStandingByPersonUuids([$personUuid]) as $posting) {
            $person = $posting->getPerson();
            if (null !== $person) {
                return $this->waitingForHead($person);
            }
        }

        return [];
    }

    /**
     * @param list<Swap>        $swaps
     * @param list<WatchChange> $changes
     *
     * @return list<ApprovalItem>
     */
    private function items(array $swaps, array $changes, ?UserInterface $viewer): array
    {
        $items = [];
        foreach ($swaps as $swap) {
            $station = $swap->getDuty()->getStation();
            $items[] = new ApprovalItem($station, 'swap', self::swapSentence($swap), $swap->getRespondedAt() ?? $swap->getCreatedAt() ?? $this->clock->now(), $this->headOf($station), $this->mayDecide($station, $viewer), swap: $swap);
        }
        foreach ($changes as $change) {
            $station = $change->getDuty()?->getStation();
            if (null === $station) {
                continue;
            }
            $items[] = new ApprovalItem($station, 'own watch', self::name($change->getRequestedBy()).' asked to '.$change->getSummary(), $change->getRequestedAt(), $this->headOf($station), $this->mayDecide($station, $viewer), change: $change);
        }
        usort($items, static fn (ApprovalItem $a, ApprovalItem $b): int => $a->since <=> $b->since);

        return $items;
    }

    private function ask(Duty $duty, WatchChangeKind $kind, UserInterface $by, ?string $shiftKey = null, ?UserInterface $to = null): WatchChange
    {
        $summary = $kind->describe($shiftKey, null === $to ? null : self::name($to)).' on '.self::day($duty).' at '.(string) ($duty->getStation()->getCode() ?? $duty->getStation()->getName());
        $change = new WatchChange($duty, $kind, $by, $this->clock->now(), $summary, $shiftKey, $to);
        $this->entityManager->persist($change);
        $this->entityManager->flush();

        return $change;
    }

    private function assertDecides(Station $station, UserInterface $by, bool $waiting): void
    {
        if (!$waiting) {
            throw new \LogicException('Only what is waiting for the head is approved or refused.');
        }
        if (!$this->mayDecide($station, $by)) {
            throw new AccessDeniedException(\sprintf('Only the head of %s approves or refuses what waits there.', (string) $station->getName()));
        }
    }

    private static function swapSentence(Swap $swap): string
    {
        $duty = $swap->getDuty();
        $at = (string) ($duty->getStation()->getCode() ?? $duty->getStation()->getName());

        return \sprintf('%s takes %s’s %s %s at %s', self::name($swap->getOfferedTo()), self::name($duty->getPerson()), self::day($duty), $duty->getShiftKey(), $at);
    }

    private static function day(Duty $duty): string
    {
        return strtolower($duty->getOnDay()->format('D j'));
    }

    /** "R. Mushi" */
    private static function name(UserInterface $person): string
    {
        return trim(mb_substr((string) $person->getFirstName(), 0, 1).'. '.$person->getLastName());
    }
}
