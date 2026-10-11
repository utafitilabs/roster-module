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
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Contracts\Entity\UserInterface;
use Uhifadhi\Roster\Entity\Duty;
use Uhifadhi\Roster\Entity\Swap;
use Uhifadhi\Roster\Enum\SwapState;
use Uhifadhi\Roster\Repository\DutyRepository;
use Uhifadhi\Roster\Repository\StationWatchRepository;
use Uhifadhi\Roster\Repository\SwapRepository;

/**
 * OFFERING A SWAP, AND WHAT HAPPENS WHEN ONE IS ACCEPTED.
 *
 * ACCEPTING IS THE ONLY THING THAT MOVES ANYBODY. Offering changes nothing:
 * both duties stand as the rotation generated them, the week grid reads as
 * though no swap existed, and the handset shows the original watches. That
 * is what makes an unaccepted offer safe to leave open over a weekend.
 *
 * AN ACCEPTED SWAP MARKS BOTH DAYS AS EDITED, and this is the line that
 * matters most: the nightly generator would otherwise regenerate those days
 * from the ring and put everybody back where the pattern says they belong,
 * quietly undoing an agreement two people made. Marking the day is how the
 * generator is told to step over it — the same mechanism a duty officer's
 * hand edit uses, because it is the same fact: somebody decided this day is
 * not what the pattern says.
 *
 * ONE OPEN OFFER PER WATCH. Two people cannot both be asked to take the same
 * cell — whichever accepted second would find the watch already gone, and
 * the roster would depend on who tapped first.
 */
final readonly class SwapService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private SwapRepository $swaps,
        private SheetDayService $days,
        private DutyRepository $duties,
        private RosteredPeople $pool,
        /** Where a station says its head approves; null in a suite that is not about it. */
        private ?StationWatchRepository $watches = null,
    ) {
    }

    /**
     * OFFER A WATCH TO SOMEBODY.
     *
     * @param Duty|null $counterDuty the watch offered in exchange, for a true swap; null for a hand-over
     *
     * @throws \LogicException when the watch already has an offer out, or the offer is to the person already on it
     */
    public function offer(Duty $duty, UserInterface $offeredTo, ?UserInterface $offeredBy = null, ?Duty $counterDuty = null): Swap
    {
        if (null !== $this->swaps->findOpenForDuty($duty)) {
            throw new \LogicException('That watch already has an offer out. Two people asked to take one cell is a roster that depends on who taps first.');
        }

        if ($duty->getPerson()->getId() === $offeredTo->getId()) {
            throw new \LogicException('That watch is already theirs; a swap with oneself moves nobody.');
        }

        $swap = new Swap($duty->getArea(), $duty, $offeredTo, $offeredBy, $counterDuty);
        $this->entityManager->persist($swap);
        $this->entityManager->flush();

        return $swap;
    }

    /**
     * ACCEPT — the one operation that moves people, and the only one the
     * person being asked can perform.
     *
     * A HAND-OVER moves one duty to the new person. A TRUE EXCHANGE moves
     * both, each to the other's person. Either way both affected DAYS are
     * marked edited so the generator leaves them alone.
     */
    public function accept(Swap $swap, \DateTimeImmutable $at): Swap
    {
        // AT A STATION WHOSE HEAD APPROVES (#60), agreeing moves nothing yet:
        // the swap waits, and the head's approval makes it.
        if ($this->watches?->findOneForStation($swap->getDuty()->getStation())?->requiresApproval() ?? false) {
            $swap->awaitTheHead($at);
            $this->entityManager->flush();

            return $swap;
        }

        return $this->make($swap, $at);
    }

    /**
     * MAKE AN AGREED SWAP: the duties change hands. Called on acceptance, or
     * on the head's approval where the station requires it.
     */
    public function make(Swap $swap, \DateTimeImmutable $at): Swap
    {
        $swap->resolve(SwapState::Accepted, $at);

        $duty = $swap->getDuty();
        $counter = $swap->getCounterDuty();
        $giving = $duty->getPerson();
        $taking = $swap->getOfferedTo();

        $this->moveDutyTo($duty, $taking);

        if (null !== $counter) {
            $this->moveDutyTo($counter, $giving);
        }

        /*
         * BOTH ENDS OF THE TRADE, EACH BY NAME.
         *
         * A TRUE EXCHANGE leaves both people working, each on the
         * other's watch, so neither day is an absence. A HAND-OVER
         * leaves the giver not working a day they were down for, which
         * is an absence — and whether that opens a gap is the station's
         * ring to answer, exactly as it is when the day is moved from
         * the sheet's own menu.
         */
        $station = $duty->getStation();
        $onDay = $duty->getOnDay();

        $this->days->markByHand($station, $onDay, $taking, false, $taking, $at);
        $this->days->markByHand($station, $onDay, $giving, null === $counter, $taking, $at);

        $this->entityManager->flush();

        return $swap;
    }

    /** The other person said no. Both duties stand as they were. */
    public function decline(Swap $swap, \DateTimeImmutable $at): Swap
    {
        $swap->resolve(SwapState::Declined, $at);
        $this->entityManager->flush();

        return $swap;
    }

    /** Refused by the station's head. Both duties stand as they were. */
    public function refuse(Swap $swap, \DateTimeImmutable $at): Swap
    {
        $swap->resolve(SwapState::Refused, $at);
        $this->entityManager->flush();

        return $swap;
    }

    /** Taken back before an answer. Both duties stand as they were. */
    public function withdraw(Swap $swap, \DateTimeImmutable $at): Swap
    {
        $swap->resolve(SwapState::Withdrawn, $at);
        $this->entityManager->flush();

        return $swap;
    }

    /**
     * THE OFFERS STILL WAITING, over a window of days.
     *
     * @return list<Swap>
     */
    public function openBetween(AreaOfInterest $area, \DateTimeImmutable $from, \DateTimeImmutable $through): array
    {
        return $this->swaps->findOpenBetween($area, $from, $through);
    }

    /**
     * THE LATEST OFFERS OVER A WINDOW, ANSWERED OR NOT — the register.
     *
     * EVERY STATE IT CAN END IN is shown, which is the point of keeping a
     * declined or withdrawn offer: "we did ask, and they said no" is a fact
     * about a week, and a register that only listed the successes would
     * make a hole look like one nobody tried to fill.
     *
     * @return list<Swap>
     */
    public function recentBetween(AreaOfInterest $area, \DateTimeImmutable $from, \DateTimeImmutable $through, int $limit): array
    {
        return $this->swaps->findRecentBetween($area, $from, $through, $limit);
    }

    /**
     * THE TRADE BEING PUT TOGETHER — the watch being given up and the person
     * being asked, both named in the url.
     *
     * A CARD, NOT A SESSION. The offer under construction lives in the query
     * string so that it is shareable, refreshable and gone the moment
     * somebody navigates away; a half-built swap kept in a session would
     * follow a duty officer around the product.
     *
     * @return array{duty: Duty, taking: UserInterface}|null
     */
    public function offering(AreaOfInterest $area, mixed $dutyUuid, mixed $takingUuid): ?array
    {
        if (!\is_string($dutyUuid) || !\is_string($takingUuid) || !Uuid::isValid($dutyUuid) || !Uuid::isValid($takingUuid)) {
            return null;
        }

        $duty = $this->duties->findOneBy(['area' => $area, 'uuid' => Uuid::fromString($dutyUuid)]);
        if (null === $duty) {
            return null;
        }

        foreach ($this->pool->findCandidates($area) as $person) {
            if ((string) $person->getUuidString() === $takingUuid) {
                return ['duty' => $duty, 'taking' => $person];
            }
        }

        return null;
    }

    /**
     * OFFER, ADDRESSED THE WAY A FORM ADDRESSES THINGS — by uuid, checked
     * against this area.
     *
     * @throws \LogicException when the watch or the person is not this area\'s, or the offer is refused
     */
    public function offerFromRequest(AreaOfInterest $area, string $dutyUuid, string $takingUuid, ?UserInterface $offeredBy): Swap
    {
        $subject = $this->offering($area, $dutyUuid, $takingUuid);
        if (null === $subject) {
            throw new \LogicException('That watch or that person is not in this area.');
        }

        return $this->offer($subject['duty'], $subject['taking'], $offeredBy);
    }

    /**
     * @throws \LogicException when the swap is not this area\'s or has already been answered
     */
    public function withdrawFromRequest(AreaOfInterest $area, string $swapUuid, \DateTimeImmutable $at): Swap
    {
        if (!Uuid::isValid($swapUuid)) {
            throw new \LogicException('That is not a swap.');
        }

        $swap = $this->swaps->findOneBy(['area' => $area, 'uuid' => Uuid::fromString($swapUuid)]);
        if (null === $swap) {
            throw new \LogicException('That swap is not in this area.');
        }

        return $this->withdraw($swap, $at);
    }

    /**
     * THE DUTY CHANGES HANDS — and it is the same row, deliberately. A swap
     * that deleted one duty and wrote another would lose the row's identity,
     * and anything that referenced it (this swap included) would be pointing
     * at nothing.
     */
    private function moveDutyTo(Duty $duty, UserInterface $person): void
    {
        $duty->reassignTo($person);
    }
}
