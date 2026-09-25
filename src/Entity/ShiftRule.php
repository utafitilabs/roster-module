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

namespace Uhifadhi\Roster\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Roster\Entity\Trait\RuleAnswerTrait;
use Uhifadhi\Roster\Entity\Trait\TimestampableTrait;
use Uhifadhi\Roster\Enum\RuleChoiceInterface;
use Uhifadhi\Roster\Enum\RuleKind;
use Uhifadhi\Roster\Model\RuleValue;
use Uhifadhi\Roster\Repository\ShiftRuleRepository;

/**
 * ONE OF THE RULES, AS THIS AREA SETS IT — the default every station
 * follows unless its own row says otherwise. Ping every is the area's own
 * column and is never a row here.
 *
 * RULED 20 sep, twice over. "Forcing predefined options is stupid": the
 * value is a number somebody typed and a unit they picked, and both halves
 * are stored so the field comes back saying what was put in it. And
 * "rules configurable like exceptions": every one the roster sets is an area
 * default with a per-station exception available under it — both, always,
 * with nothing in the product assuming one way of working.
 *
 * ONE ROW PER KIND PER AREA. Five columns on one row would make adding a
 * sixth rule a migration on every installation; five rows make it a new
 * case on an enum and a row nobody has written yet, which reads as the
 * standard until somebody does.
 */
#[ORM\Entity(repositoryClass: ShiftRuleRepository::class)]
#[ORM\Table(name: 'roster_shift_rule')]
#[ORM\UniqueConstraint(name: 'uniq_roster_rule_area_kind', columns: ['area_id', 'kind'])]
#[ORM\UniqueConstraint(name: 'uniq_roster_shift_rule_uuid', columns: ['uuid'])]
#[ORM\Index(name: 'idx_roster_shift_rule_area', columns: ['area_id'])]
#[ORM\HasLifecycleCallbacks]
class ShiftRule
{
    use RuleAnswerTrait;
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine via reflection)

    #[ORM\Column(type: 'uuid')]
    private Uuid $uuid;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'area_id', nullable: false, onDelete: 'CASCADE')]
    private AreaOfInterest $area;

    #[ORM\Column(length: 32, enumType: RuleKind::class)]
    private RuleKind $kind;

    /**
     * THE ANSWER IS EITHER SHAPE, and the row takes whichever the rule has.
     * A single constructor rather than two: "this rule, for this
     * area, says this" is one act, and a caller that had to know in
     * advance which of two verbs a kind wanted would be carrying the
     * enum's own knowledge around with it.
     *
     * @throws \InvalidArgumentException when the answer is not one this rule takes
     */
    public function __construct(AreaOfInterest $area, RuleKind $kind, RuleValue|RuleChoiceInterface $answer)
    {
        $this->uuid = Uuid::v7();
        $this->area = $area;
        $this->kind = $kind;

        if ($answer instanceof RuleValue) {
            $this->set($answer);
        } else {
            $this->choose($answer);
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): Uuid
    {
        return $this->uuid;
    }

    public function getArea(): AreaOfInterest
    {
        return $this->area;
    }

    public function getKind(): RuleKind
    {
        return $this->kind;
    }
}
