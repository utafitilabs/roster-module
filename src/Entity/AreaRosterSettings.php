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
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Roster\Entity\Trait\TimestampableTrait;
use Uhifadhi\Roster\Enum\LateThreshold;
use Uhifadhi\Roster\Enum\VacancyAnnounce;
use Uhifadhi\Roster\Repository\AreaRosterSettingsRepository;

/**
 * THE SIX ANSWERS THIS MODULE CANNOT GUESS ABOUT AN ORGANIZATION.
 *
 * Every one of them was an open verdict while the module was being designed.
 * They are settings now rather than questions, each with a default and a
 * stated consequence for choosing wrong — which is the only honest way to ship
 * a question you could not answer for somebody else.
 *
 * ONE ROW PER AREA, created on first read from the bundle's configured
 * starting values. The config is the seed; this is what the park runs on, and
 * nothing reads the config again once this row exists.
 *
 * THE SHIFT VOCABULARY IS NOT HERE, although the Settings section draws it.
 * A shift has a lifecycle — renamed freely, closed and never deleted — so it
 * is {@see Shift}, a list of rows, and this record holds the numbers.
 */
#[ORM\Entity(repositoryClass: AreaRosterSettingsRepository::class)]
#[ORM\Table(name: 'roster_area_settings')]
#[ORM\UniqueConstraint(name: 'uniq_roster_settings_area', columns: ['area_id'])]
#[ORM\HasLifecycleCallbacks]
class AreaRosterSettings
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine via reflection)

    #[ORM\OneToOne(targetEntity: AreaOfInterest::class)]
    #[ORM\JoinColumn(name: 'area_id', nullable: false, onDelete: 'CASCADE')]
    private AreaOfInterest $area;

    /**
     * POSITIONS PER HOUR, PER HANDSET.
     *
     * It trades against the catchment: a wide catchment tolerates a slow
     * interval, a tight one does not — at two kilometres and sixty minutes a
     * ranger can leave and return unseen. Every minute taken off it costs
     * battery in a place with no mains.
     */
    #[ORM\Column(name: 'ping_interval_minutes')]
    private int $pingIntervalMinutes;

    /**
     * WHAT AN OFF DAY IN A CYCLE PRODUCES. True — the default — means NO
     * STATE: a ranger the ring stands down is not expected to check in and has
     * failed at nothing. False means a rest day reads as a missing check-in,
     * which colours every one of them red, and is only right for an
     * organization that genuinely requires a daily check-in from everybody.
     */
    #[ORM\Column(name: 'off_day_has_no_state')]
    private bool $offDayHasNoState = true;

    /**
     * WHETHER AN ABSENCE CARRIES AN APPROVAL STATE. Off by default and off in
     * v1: the roster records an absence because the hole it makes is this
     * module's to show, and approval belongs to Team, which owns the person's
     * employment. Turning it on adds a READ-ONLY state chip on the absence
     * row and no workflow of this module's own.
     */
    #[ORM\Column(name: 'leave_approval_shown')]
    private bool $leaveApprovalShown = false;

    /**
     * USED BY A POST THAT SETS NONE OF ITS OWN.
     *
     * It decides who reads as unverified. A radius smaller than the post's own
     * compound flags people standing in it; one larger than the nearest road
     * verifies people driving past. Changing it RE-DERIVES EVERY PAST DAY,
     * because a state is never stored.
     */
    #[ORM\Column(name: 'default_catchment_metres')]
    private int $defaultCatchmentMetres;

    #[ORM\Column(name: 'late_threshold', length: 32, enumType: LateThreshold::class)]
    private LateThreshold $lateThreshold = LateThreshold::TwiceTheInterval;

    #[ORM\Column(name: 'vacancy_announce', length: 32, enumType: VacancyAnnounce::class)]
    private VacancyAnnounce $vacancyAnnounce = VacancyAnnounce::AsSoonAsKnown;

    public function __construct(AreaOfInterest $area, int $pingIntervalMinutes, int $defaultCatchmentMetres)
    {
        $this->area = $area;
        $this->pingIntervalMinutes = $pingIntervalMinutes;
        $this->defaultCatchmentMetres = $defaultCatchmentMetres;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getArea(): AreaOfInterest
    {
        return $this->area;
    }

    public function getPingIntervalMinutes(): int
    {
        return $this->pingIntervalMinutes;
    }

    public function setPingIntervalMinutes(int $minutes): static
    {
        if ($minutes < 1) {
            throw new \InvalidArgumentException('A ping interval of zero is not a fast interval, it is no interval.');
        }

        $this->pingIntervalMinutes = $minutes;

        return $this;
    }

    public function offDayHasNoState(): bool
    {
        return $this->offDayHasNoState;
    }

    public function setOffDayHasNoState(bool $noState): static
    {
        $this->offDayHasNoState = $noState;

        return $this;
    }

    public function isLeaveApprovalShown(): bool
    {
        return $this->leaveApprovalShown;
    }

    public function setLeaveApprovalShown(bool $shown): static
    {
        $this->leaveApprovalShown = $shown;

        return $this;
    }

    public function getDefaultCatchmentMetres(): int
    {
        return $this->defaultCatchmentMetres;
    }

    public function setDefaultCatchmentMetres(int $metres): static
    {
        if ($metres < 1) {
            throw new \InvalidArgumentException('A catchment is a distance from the post, so it is at least one metre.');
        }

        $this->defaultCatchmentMetres = $metres;

        return $this;
    }

    public function getLateThreshold(): LateThreshold
    {
        return $this->lateThreshold;
    }

    public function setLateThreshold(LateThreshold $threshold): static
    {
        $this->lateThreshold = $threshold;

        return $this;
    }

    public function getVacancyAnnounce(): VacancyAnnounce
    {
        return $this->vacancyAnnounce;
    }

    public function setVacancyAnnounce(VacancyAnnounce $announce): static
    {
        $this->vacancyAnnounce = $announce;

        return $this;
    }

    /** The fallback window in minutes for a post that sets none of its own. */
    public function lateAfterMinutes(): int
    {
        return $this->lateThreshold->minutes($this->pingIntervalMinutes);
    }
}
