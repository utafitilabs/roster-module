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
use Uhifadhi\Contracts\Entity\UserInterface;
use Uhifadhi\Roster\Entity\Trait\TimestampableTrait;
use Uhifadhi\Roster\Enum\WatchChangeKind;
use Uhifadhi\Roster\Enum\WatchChangeState;
use Uhifadhi\Roster\Repository\WatchChangeRepository;

/**
 * A PLANNER'S CHANGE TO THEIR OWN WATCH, waiting for the station's head (#60).
 *
 * Whoever plans a station's roster may change anybody's watch there; where the
 * station requires its head's approval, a change to their OWN watch is asked
 * rather than made, so nobody approves their own day off. The watch stands as
 * it was until the head approves, and the change is then made as the sheet's
 * menu makes it.
 */
#[ORM\Entity(repositoryClass: WatchChangeRepository::class)]
#[ORM\Table(name: 'roster_watch_change')]
#[ORM\Index(name: 'idx_roster_watch_change_area_state', columns: ['area_id', 'state'])]
#[ORM\HasLifecycleCallbacks]
class WatchChange
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine via reflection)

    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $uuid;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'area_id', nullable: false, onDelete: 'CASCADE')]
    private AreaOfInterest $area;

    /**
     * SET NULL, NOT CASCADE: an approved day off removes the watch, and the
     * record of who asked and who approved outlives it, reading its summary.
     */
    #[ORM\ManyToOne(targetEntity: Duty::class)]
    #[ORM\JoinColumn(name: 'duty_id', nullable: true, onDelete: 'SET NULL')]
    private ?Duty $duty; // @phpstan-ignore property.unusedType (the database sets it null when the watch is removed)

    /** What was asked, as it read when it was asked: "take the day off on sun 20 at ST-01". */
    #[ORM\Column(length: 255)]
    private string $summary;

    #[ORM\Column(length: 16, enumType: WatchChangeKind::class)]
    private WatchChangeKind $kind;

    #[ORM\Column(name: 'shift_key', length: 64, nullable: true)]
    private ?string $shiftKey;

    #[ORM\ManyToOne(targetEntity: UserInterface::class)]
    #[ORM\JoinColumn(name: 'to_person_id', nullable: true, onDelete: 'CASCADE')]
    private ?UserInterface $toPerson;

    #[ORM\ManyToOne(targetEntity: UserInterface::class)]
    #[ORM\JoinColumn(name: 'requested_by_id', nullable: false, onDelete: 'CASCADE')]
    private UserInterface $requestedBy;

    #[ORM\Column(name: 'requested_at')]
    private \DateTimeImmutable $requestedAt;

    #[ORM\Column(length: 16, enumType: WatchChangeState::class)]
    private WatchChangeState $state = WatchChangeState::Waiting;

    #[ORM\ManyToOne(targetEntity: UserInterface::class)]
    #[ORM\JoinColumn(name: 'decided_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?UserInterface $decidedBy = null;

    #[ORM\Column(name: 'decided_at', nullable: true)]
    private ?\DateTimeImmutable $decidedAt = null;

    public function __construct(Duty $duty, WatchChangeKind $kind, UserInterface $requestedBy, \DateTimeImmutable $requestedAt, string $summary, ?string $shiftKey = null, ?UserInterface $toPerson = null)
    {
        $this->summary = $summary;
        $this->uuid = Uuid::v7();
        $this->area = $duty->getArea();
        $this->duty = $duty;
        $this->kind = $kind;
        $this->requestedBy = $requestedBy;
        $this->requestedAt = $requestedAt;
        $this->shiftKey = $shiftKey;
        $this->toPerson = $toPerson;
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

    public function getDuty(): ?Duty
    {
        return $this->duty;
    }

    public function getSummary(): string
    {
        return $this->summary;
    }

    public function getKind(): WatchChangeKind
    {
        return $this->kind;
    }

    public function getShiftKey(): ?string
    {
        return $this->shiftKey;
    }

    public function getToPerson(): ?UserInterface
    {
        return $this->toPerson;
    }

    public function getRequestedBy(): UserInterface
    {
        return $this->requestedBy;
    }

    public function getRequestedAt(): \DateTimeImmutable
    {
        return $this->requestedAt;
    }

    public function getState(): WatchChangeState
    {
        return $this->state;
    }

    public function getDecidedBy(): ?UserInterface
    {
        return $this->decidedBy;
    }

    public function getDecidedAt(): ?\DateTimeImmutable
    {
        return $this->decidedAt;
    }

    /** @throws \LogicException when it was already decided */
    public function decide(WatchChangeState $state, UserInterface $by, \DateTimeImmutable $at): static
    {
        if (WatchChangeState::Waiting !== $this->state) {
            throw new \LogicException(\sprintf('This change was already %s; a decided change is not decided again.', $this->state->label()));
        }
        if (WatchChangeState::Waiting === $state) {
            throw new \LogicException('Deciding a change means approving or refusing it.');
        }

        $this->state = $state;
        $this->decidedBy = $by;
        $this->decidedAt = $at;

        return $this;
    }
}
