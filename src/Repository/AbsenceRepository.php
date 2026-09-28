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
use Uhifadhi\Roster\Entity\Absence;

/**
 * Who is not available, and between which days.
 *
 * @extends ServiceEntityRepository<Absence>
 */
final class AbsenceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Absence::class);
    }

    /**
     * EVERY ABSENCE THAT TOUCHES A WINDOW, which is not the same as every
     * absence that starts in it: a fortnight's leave beginning last monday is
     * exactly the one the generator must not miss when it plans this week.
     *
     * @return list<Absence>
     */
    public function findOverlapping(AreaOfInterest $area, \DateTimeImmutable $from, \DateTimeImmutable $through): array
    {
        /** @var list<Absence> $absences */
        $absences = $this->createQueryBuilder('a')
            ->andWhere('a.area = :area')
            ->andWhere('a.startsOn <= :through')
            ->andWhere('a.endsOn >= :from')
            ->setParameter('area', $area)
            ->setParameter('from', $from->setTime(0, 0))
            ->setParameter('through', $through->setTime(0, 0))
            ->orderBy('a.startsOn', 'ASC')
            ->addOrderBy('a.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $absences;
    }

    /**
     * THESE PEOPLE'S ABSENCES THAT TOUCH A WINDOW, in whichever area they
     * were recorded — an absence is about the person, and a fortnight away
     * recorded against another park is still a fortnight away (#19: a
     * person's own leave, and who is away from their post this week).
     *
     * @param list<string> $personUuids
     *
     * @return list<Absence>
     */
    public function findForPeopleBetween(array $personUuids, \DateTimeImmutable $from, \DateTimeImmutable $through): array
    {
        if ([] === $personUuids) {
            return [];
        }

        /** @var list<Absence> $absences */
        $absences = $this->createQueryBuilder('a')
            ->join('a.person', 'p')
            ->andWhere('p.uuid IN (:people)')
            ->andWhere('a.startsOn <= :through')
            ->andWhere('a.endsOn >= :from')
            ->setParameter('people', $personUuids)
            ->setParameter('from', $from->setTime(0, 0))
            ->setParameter('through', $through->setTime(0, 0))
            ->orderBy('a.startsOn', 'ASC')
            ->addOrderBy('a.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $absences;
    }
}
