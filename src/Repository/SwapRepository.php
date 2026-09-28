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

namespace Uhifadhi\Roster\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Roster\Entity\Duty;
use Uhifadhi\Roster\Entity\Swap;
use Uhifadhi\Roster\Enum\SwapState;

/**
 * The offers out on an area's roster.
 *
 * @extends ServiceEntityRepository<Swap>
 */
final class SwapRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Swap::class);
    }

    /**
     * EVERY OFFER STILL WAITING ON SOMEBODY, over a window of days.
     *
     * Scoped by the DUTY's day rather than by when the offer was made: a week
     * grid is drawing a week, and an offer made a fortnight ago about a watch
     * in that week belongs on it.
     *
     * @return list<Swap>
     */
    public function findOpenBetween(AreaOfInterest $area, \DateTimeImmutable $from, \DateTimeImmutable $through): array
    {
        /** @var list<Swap> $swaps */
        $swaps = $this->createQueryBuilder('s')
            ->join('s.duty', 'd')
            ->andWhere('s.area = :area')
            ->andWhere('s.state = :open')
            ->andWhere('d.onDay BETWEEN :from AND :through')
            ->setParameter('area', $area)
            ->setParameter('open', SwapState::Offered)
            ->setParameter('from', $from->setTime(0, 0))
            ->setParameter('through', $through->setTime(0, 0))
            ->orderBy('d.onDay', 'ASC')
            ->addOrderBy('s.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $swaps;
    }

    /**
     * THE LATEST OFFERS OVER A WINDOW, in whatever state they ended in.
     *
     * Bounded by the caller, because a card never grows with its data: the
     * register shows the latest few and says how many there were.
     *
     * @return list<Swap>
     */
    public function findRecentBetween(AreaOfInterest $area, \DateTimeImmutable $from, \DateTimeImmutable $through, int $limit): array
    {
        /** @var list<Swap> $swaps */
        $swaps = $this->createQueryBuilder('s')
            ->join('s.duty', 'd')
            ->andWhere('s.area = :area')
            ->andWhere('d.onDay BETWEEN :from AND :through')
            ->setParameter('area', $area)
            ->setParameter('from', $from->setTime(0, 0))
            ->setParameter('through', $through->setTime(0, 0))
            ->orderBy('s.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $swaps;
    }

    /**
     * EVERY OFFER ONE PERSON IS PARTY TO over a window of days, in whatever
     * state it is in — the watch was theirs, they were asked, or they asked
     * (#19, the Swaps card on a person's own roster). Scoped by the duty's
     * day, like the week grid's, because a person reads a swap as the watch
     * it is about.
     *
     * @return list<Swap>
     */
    public function findInvolving(string $personUuid, \DateTimeImmutable $from, \DateTimeImmutable $through, int $limit): array
    {
        /** @var list<Swap> $swaps */
        $swaps = $this->createQueryBuilder('s')
            ->join('s.duty', 'd')
            ->join('d.person', 'holder')
            ->join('s.offeredTo', 'asked')
            ->leftJoin('s.offeredBy', 'asker')
            ->andWhere('holder.uuid = :person OR asked.uuid = :person OR asker.uuid = :person')
            ->andWhere('d.onDay BETWEEN :from AND :through')
            ->setParameter('person', $personUuid)
            ->setParameter('from', $from->setTime(0, 0))
            ->setParameter('through', $through->setTime(0, 0))
            ->orderBy('d.onDay', 'ASC')
            ->addOrderBy('s.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $swaps;
    }

    /** Whatever offer is out on this watch, or null. */
    public function findOpenForDuty(Duty $duty): ?Swap
    {
        return $this->findOneBy(['duty' => $duty, 'state' => SwapState::Offered]);
    }
}
