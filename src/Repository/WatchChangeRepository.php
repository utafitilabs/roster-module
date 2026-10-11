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
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Roster\Entity\WatchChange;
use Uhifadhi\Roster\Enum\WatchChangeState;

/**
 * The changes planners asked of their own watches, waiting for a head.
 *
 * @extends ServiceEntityRepository<WatchChange>
 */
final class WatchChangeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WatchChange::class);
    }

    /** @return list<WatchChange> oldest first */
    public function findWaitingInArea(AreaOfInterest $area): array
    {
        /** @var list<WatchChange> $rows */
        $rows = $this->createQueryBuilder('c')
            ->andWhere('c.area = :area')->setParameter('area', $area)
            ->andWhere('c.state = :waiting')->setParameter('waiting', WatchChangeState::Waiting)
            ->orderBy('c.requestedAt', 'ASC')
            ->getQuery()->getResult();

        return $rows;
    }

    /**
     * @param list<Station> $stations
     *
     * @return list<WatchChange> oldest first
     */
    public function findWaitingAtStations(array $stations): array
    {
        if ([] === $stations) {
            return [];
        }

        /** @var list<WatchChange> $rows */
        $rows = $this->createQueryBuilder('c')
            ->join('c.duty', 'd')
            ->andWhere('d.station IN (:stations)')->setParameter('stations', $stations)
            ->andWhere('c.state = :waiting')->setParameter('waiting', WatchChangeState::Waiting)
            ->orderBy('c.requestedAt', 'ASC')
            ->getQuery()->getResult();

        return $rows;
    }
}
