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
use Uhifadhi\Roster\Entity\ShiftRule;
use Uhifadhi\Roster\Enum\RuleKind;

/**
 * THE RULES AN AREA SETS ON THE ROSTER — the defaults every station follows
 * unless its own row says otherwise. Ping every is not among them: it is the
 * area's own column, read through the area bundle's PingInterval.
 *
 * @extends ServiceEntityRepository<ShiftRule>
 */
final class ShiftRuleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ShiftRule::class);
    }

    /**
     * Every rule this area has written, keyed by kind — a kind with no row
     * is a rule nobody has touched, and reads as the standard.
     *
     * @return array<string, ShiftRule>
     */
    public function findByArea(AreaOfInterest $area): array
    {
        $rules = [];
        foreach ($this->findBy(['area' => $area]) as $rule) {
            $rules[$rule->getKind()->value] = $rule;
        }

        return $rules;
    }

    public function findOneByAreaAndKind(AreaOfInterest $area, RuleKind $kind): ?ShiftRule
    {
        return $this->findOneBy(['area' => $area, 'kind' => $kind]);
    }
}
