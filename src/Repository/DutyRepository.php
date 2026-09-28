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
use Uhifadhi\Roster\Entity\Duty;
use Uhifadhi\Roster\Entity\Rotation;
use Uhifadhi\Roster\Enum\DutyState;

/**
 * The duties of one area. Every read is area-scoped or station-scoped, and a
 * duty is always asked for by DAY or by a range of days — nothing in this
 * module ever wants "all duties".
 *
 * @extends ServiceEntityRepository<Duty>
 */
final class DutyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Duty::class);
    }

    /**
     * One area's whole day — what the day board, the agenda and the handover
     * all start from.
     *
     * @return list<Duty>
     */
    public function findByAreaOnDay(AreaOfInterest $area, \DateTimeImmutable $onDay): array
    {
        return $this->findByAreaBetween($area, $onDay, $onDay);
    }

    /**
     * @return list<Duty>
     */
    public function findByAreaBetween(AreaOfInterest $area, \DateTimeImmutable $from, \DateTimeImmutable $through): array
    {
        /** @var list<Duty> $duties */
        $duties = $this->createQueryBuilder('d')
            ->andWhere('d.area = :area')
            ->andWhere('d.onDay BETWEEN :from AND :through')
            ->setParameter('area', $area)
            ->setParameter('from', $from->setTime(0, 0))
            ->setParameter('through', $through->setTime(0, 0))
            ->orderBy('d.onDay', 'ASC')
            ->addOrderBy('d.shiftKey', 'ASC')
            ->addOrderBy('d.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $duties;
    }

    /**
     * THE LAST DAY ANY WATCH STANDS FOR THE AREA — how far the plan is
     * filled, wherever that falls. Null when nothing stands at all.
     */
    public function findLastDayByArea(AreaOfInterest $area): ?\DateTimeImmutable
    {
        /** @var Duty|null $last */
        $last = $this->createQueryBuilder('d')
            ->andWhere('d.area = :area')
            ->setParameter('area', $area)
            ->orderBy('d.onDay', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $last?->getOnDay();
    }

    /**
     * @return list<Duty>
     */
    public function findByStationBetween(Station $station, \DateTimeImmutable $from, \DateTimeImmutable $through): array
    {
        /** @var list<Duty> $duties */
        $duties = $this->createQueryBuilder('d')
            ->andWhere('d.station = :station')
            ->andWhere('d.onDay BETWEEN :from AND :through')
            ->setParameter('station', $station)
            ->setParameter('from', $from->setTime(0, 0))
            ->setParameter('through', $through->setTime(0, 0))
            ->orderBy('d.onDay', 'ASC')
            ->addOrderBy('d.shiftKey', 'ASC')
            ->addOrderBy('d.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $duties;
    }

    /**
     * THE DUTIES THIS ROTATION PUT THERE, and only those.
     *
     * The generator clears its own work before writing it again, and the
     * scope of "its own" is the whole point: a duty somebody added by hand
     * carries no rotation, a duty another rotation generated carries that
     * one, and neither is this rotation's to remove.
     *
     * @return list<Duty>
     */
    public function findGeneratedBy(Rotation $rotation, \DateTimeImmutable $from, \DateTimeImmutable $through): array
    {
        /** @var list<Duty> $duties */
        $duties = $this->createQueryBuilder('d')
            ->andWhere('d.rotation = :rotation')
            ->andWhere('d.onDay BETWEEN :from AND :through')
            ->setParameter('rotation', $rotation)
            ->setParameter('from', $from->setTime(0, 0))
            ->setParameter('through', $through->setTime(0, 0))
            ->orderBy('d.onDay', 'ASC')
            ->addOrderBy('d.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $duties;
    }

    /**
     * ONE PERSON'S STANDING WATCHES IN ONE AREA, between two days.
     *
     * STANDING, so a CANCELLED duty is not in it: the handset must not
     * remind somebody to stand a watch that was called off, and the row
     * stays in the database because the cancellation is itself a fact.
     *
     * The person arrives as a UUID because the contract is addressed that
     * way — the area asks on behalf of a phone, and neither of them has
     * this installation's account class in hand.
     *
     * @return list<Duty>
     */
    public function findStandingForPersonBetween(AreaOfInterest $area, string $personUuid, \DateTimeImmutable $from, \DateTimeImmutable $through): array
    {
        /** @var list<Duty> $duties */
        $duties = $this->createQueryBuilder('d')
            ->join('d.person', 'p')
            ->andWhere('d.area = :area')
            ->andWhere('p.uuid = :person')
            ->andWhere('d.onDay BETWEEN :from AND :through')
            ->andWhere('d.state != :cancelled')
            ->setParameter('area', $area)
            ->setParameter('person', $personUuid)
            ->setParameter('from', $from->setTime(0, 0))
            ->setParameter('through', $through->setTime(0, 0))
            ->setParameter('cancelled', DutyState::Cancelled)
            ->orderBy('d.onDay', 'ASC')
            ->addOrderBy('d.shiftKey', 'ASC')
            ->getQuery()
            ->getResult();

        return $duties;
    }

    /**
     * SEVERAL PEOPLE'S STANDING WATCHES IN ONE AREA, between two days — the
     * week at a post, read once for everybody posted there rather than once a
     * person (#19, the person's own roster).
     *
     * @param list<string> $personUuids
     *
     * @return list<Duty>
     */
    public function findStandingForPeopleBetween(AreaOfInterest $area, array $personUuids, \DateTimeImmutable $from, \DateTimeImmutable $through): array
    {
        if ([] === $personUuids) {
            return [];
        }

        /** @var list<Duty> $duties */
        $duties = $this->createQueryBuilder('d')
            ->join('d.person', 'p')
            ->andWhere('d.area = :area')
            ->andWhere('p.uuid IN (:people)')
            ->andWhere('d.onDay BETWEEN :from AND :through')
            ->andWhere('d.state != :cancelled')
            ->setParameter('area', $area)
            ->setParameter('people', $personUuids)
            ->setParameter('from', $from->setTime(0, 0))
            ->setParameter('through', $through->setTime(0, 0))
            ->setParameter('cancelled', DutyState::Cancelled)
            ->orderBy('d.onDay', 'ASC')
            ->addOrderBy('d.shiftKey', 'ASC')
            ->addOrderBy('d.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $duties;
    }

    /**
     * HAS THIS SHIFT EVER BEEN STOOD IN THIS AREA?
     *
     * Asked with a limit rather than a COUNT, because the only caller wants a
     * yes or a no — whether a shift may be deleted or only closed — and
     * counting every duty of a five-year-old vocabulary row to learn that it
     * is not zero is a scan nobody needs.
     *
     * @return list<Duty>
     */
    public function findByAreaAndShift(AreaOfInterest $area, string $shiftKey, int $limit): array
    {
        /** @var list<Duty> $duties */
        $duties = $this->createQueryBuilder('d')
            ->andWhere('d.area = :area')
            ->andWhere('d.shiftKey = :shiftKey')
            ->setParameter('area', $area)
            ->setParameter('shiftKey', $shiftKey)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $duties;
    }

    /**
     * WHAT THE WATCH ALREADY HOLDS — used before writing, so a generated duty
     * never collides with one a duty officer put there by hand for the same
     * person, post, shift and day.
     *
     * @return array<string, true> keyed "Y-m-d|shift|personId"
     */
    public function existingKeysByStationBetween(Station $station, \DateTimeImmutable $from, \DateTimeImmutable $through): array
    {
        $keys = [];
        foreach ($this->findByStationBetween($station, $from, $through) as $duty) {
            $keys[self::key($duty->getOnDay(), $duty->getShiftKey(), $duty->getPerson()->getId())] = true;
        }

        return $keys;
    }

    /** The one spelling of a duty's natural identity, so two callers cannot disagree. */
    public static function key(\DateTimeImmutable $onDay, string $shiftKey, int|string|null $personId): string
    {
        return $onDay->format('Y-m-d').'|'.$shiftKey.'|'.($personId ?? '?');
    }
}
