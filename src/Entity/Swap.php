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
use Uhifadhi\Roster\Enum\SwapState;
use Uhifadhi\Roster\Repository\SwapRepository;

/**
 * TWO CELLS, SENT TO THE HANDSET FOR ACCEPTANCE.
 *
 * RULED: "a swap is two cells and a cost bar, accepted on the handset." Two
 * cells is the whole shape — the duty somebody is giving up, and either the
 * duty they want in exchange or simply the person they are asking to take
 * it. A one-sided hand-over and a true exchange are the same object with the
 * second cell null or not, because they are the same conversation.
 *
 * AN OFFER IS NOT AN EDIT, and that is the reason this is an entity rather
 * than a column on a duty. Until the other person accepts, BOTH duties stand
 * exactly as the rotation generated them: the week grid reads as though no
 * swap existed, the handset shows the original watches, and nobody has been
 * moved without agreeing. A duty officer who could accept on somebody's
 * behalf could move a ranger's rest day without telling them, which is
 * precisely what a swap exists not to be.
 *
 * IT IS KEPT AFTER IT RESOLVES. A declined or withdrawn swap is a fact about
 * a week somebody may have to read back — "we did ask, and they said no" —
 * and deleting it makes a hole look like one nobody tried to fill.
 *
 * THE ACCEPTANCE MOVES THE DUTIES AND THIS ROW RECORDS THAT IT DID. The
 * duties are the roster; this is the agreement that changed them.
 */
#[ORM\Entity(repositoryClass: SwapRepository::class)]
#[ORM\Table(name: 'roster_swap')]
#[ORM\Index(name: 'idx_roster_swap_area_state', columns: ['area_id', 'state'])]
#[ORM\HasLifecycleCallbacks]
class Swap
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
     * THE FIRST CELL — the watch being given up.
     *
     * CASCADE: a swap for a duty that no longer exists is an offer about
     * nothing, and leaving it behind would put a chip on a week whose cell
     * has gone.
     */
    #[ORM\ManyToOne(targetEntity: Duty::class)]
    #[ORM\JoinColumn(name: 'duty_id', nullable: false, onDelete: 'CASCADE')]
    private Duty $duty;

    /**
     * THE SECOND CELL, where the swap is a true exchange rather than a
     * hand-over. Null means "please take mine"; a duty means "take mine and
     * I will take yours".
     */
    #[ORM\ManyToOne(targetEntity: Duty::class)]
    #[ORM\JoinColumn(name: 'counter_duty_id', nullable: true, onDelete: 'CASCADE')]
    private ?Duty $counterDuty = null;

    /** Who is being asked. The person who accepts, and the only one who can. */
    #[ORM\ManyToOne(targetEntity: UserInterface::class)]
    #[ORM\JoinColumn(name: 'offered_to_id', nullable: false, onDelete: 'CASCADE')]
    private UserInterface $offeredTo;

    /**
     * Who asked. Nullable and SET NULL for the reason an absence's recorder
     * is: the agreement outlives the account that arranged it.
     */
    #[ORM\ManyToOne(targetEntity: UserInterface::class)]
    #[ORM\JoinColumn(name: 'offered_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?UserInterface $offeredBy = null;

    #[ORM\Column(length: 16, enumType: SwapState::class)]
    private SwapState $state = SwapState::Offered;

    #[ORM\Column(name: 'responded_at', nullable: true)]
    private ?\DateTimeImmutable $respondedAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $note = null;

    public function __construct(
        AreaOfInterest $area,
        Duty $duty,
        UserInterface $offeredTo,
        ?UserInterface $offeredBy = null,
        ?Duty $counterDuty = null,
    ) {
        if (null !== $counterDuty && $counterDuty->getId() === $duty->getId() && null !== $duty->getId()) {
            throw new \InvalidArgumentException('A swap is two cells. Offering a watch in exchange for itself is not a swap, it is a no-op with a notification attached.');
        }

        $this->uuid = Uuid::v7();
        $this->area = $area;
        $this->duty = $duty;
        $this->offeredTo = $offeredTo;
        $this->offeredBy = $offeredBy;
        $this->counterDuty = $counterDuty;
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

    public function getDuty(): Duty
    {
        return $this->duty;
    }

    public function getCounterDuty(): ?Duty
    {
        return $this->counterDuty;
    }

    /** Whether this is a true exchange rather than a hand-over. */
    public function isExchange(): bool
    {
        return null !== $this->counterDuty;
    }

    public function getOfferedTo(): UserInterface
    {
        return $this->offeredTo;
    }

    public function getOfferedBy(): ?UserInterface
    {
        return $this->offeredBy;
    }

    public function getState(): SwapState
    {
        return $this->state;
    }

    public function getRespondedAt(): ?\DateTimeImmutable
    {
        return $this->respondedAt;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): static
    {
        $this->note = $note;

        return $this;
    }

    /**
     * AGREED, AND WAITING FOR THE HEAD (#60): the person asked said yes at a
     * station that requires its head's approval. Nothing moves; the answer
     * is kept, and the head's decision resolves it.
     *
     * @throws \LogicException when the offer has already been answered
     */
    public function awaitTheHead(\DateTimeImmutable $at): static
    {
        if (SwapState::Offered !== $this->state) {
            throw new \LogicException(\sprintf('This swap was already %s; only an offer waits for the head.', $this->state->label()));
        }

        $this->state = SwapState::Waiting;
        $this->respondedAt = $at;

        return $this;
    }

    /**
     * RESOLVE THE OFFER. Only an open one can be answered: a swap that has
     * already been accepted, declined or withdrawn has moved the roster or
     * decided not to, and answering it twice would do it twice.
     *
     * @throws \LogicException when the swap has already been answered
     */
    public function resolve(SwapState $state, \DateTimeImmutable $at): static
    {
        if (!$this->state->isOpen()) {
            throw new \LogicException(\sprintf('This swap was already %s; an answered offer is not answered again.', $this->state->label()));
        }

        if ($state->isOpen()) {
            throw new \LogicException('Resolving a swap means accepting, declining or withdrawing it.');
        }

        // A SWAP THE HEAD DECIDES keeps the moment the person asked agreed.
        $this->respondedAt = SwapState::Waiting === $this->state ? $this->respondedAt : $at;
        $this->state = $state;

        return $this;
    }
}
