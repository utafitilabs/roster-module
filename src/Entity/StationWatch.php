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

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Roster\Entity\Trait\TimestampableTrait;
use Uhifadhi\Roster\Repository\StationWatchRepository;

/**
 * THE FOUR COLUMNS THE ROSTER OWNS ON A STATION — and not one more.
 *
 * RULED 2026-09-18: a station is a place and the place is the AREA's. Its
 * name, kind, point, call sign, zone, opening and its postings all moved out
 * of this module. What stayed is the WATCH: which named shifts the post
 * expects, how long its silence may run, how long before that silence is
 * offline, and how close a ping has to be for a claim of "at post" to read as
 * verified.
 *
 * SO THIS IS A CONTRIBUTION, NOT A REGISTRY. It is a row hanging off somebody
 * else's record, drawn on the area's own station record and Stations configure
 * card as well as on this module's Configure page — one place it is edited,
 * several doors onto it. Deleting the roster module leaves the station whole.
 *
 * A POST WITH NO ROW HERE HAS NO WATCH, and that is a legal state with
 * consequences the design is explicit about: it is never counted, never late
 * and never a hole. Only offline, which is a fact about a post nobody has
 * heard from and not a complaint about a rota nobody wrote.
 *
 * A POST WITH A ROW AND AN EMPTY `expects` IS THE SAME THING SAID LOUDER — it
 * has been looked at and declared to run nothing. The row then still carries
 * the silence window that decides when its quiet becomes offline, which is
 * exactly why an outpost reached once a fortnight needs one.
 */
#[ORM\Entity(repositoryClass: StationWatchRepository::class)]
#[ORM\Table(name: 'roster_station_watch')]
#[ORM\Index(name: 'idx_roster_station_watch_pattern', columns: ['pattern_id'])]
#[ORM\UniqueConstraint(name: 'uniq_roster_watch_station', columns: ['station_id'])]
#[ORM\HasLifecycleCallbacks]
class StationWatch
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine via reflection)

    /**
     * ONE ROW PER STATION. The watch lives and dies with the post, because it
     * describes nothing else — but the post does not die with the watch, which
     * is the whole point of the ruling.
     */
    #[ORM\OneToOne(targetEntity: Station::class)]
    #[ORM\JoinColumn(name: 'station_id', nullable: false, onDelete: 'CASCADE')]
    private Station $station;

    /**
     * WHICH NAMED SHIFTS THIS POST EXPECTS, as keys from the area's own list.
     * Empty is legal and means "declared to run nothing".
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $expects = [];

    /** How long the post may go quiet before it reads as late. */
    #[ORM\Column(name: 'silence_window_minutes')]
    private int $silenceWindowMinutes;

    /** How long before late becomes offline. Always longer than the window above. */
    #[ORM\Column(name: 'offline_after_minutes')]
    private int $offlineAfterMinutes;

    /**
     * HOW MANY PEOPLE THIS STATION NEEDS ON EACH SHIFT IT RUNS, keyed by
     * shift key — "day 2, night 2".
     *
     * RULED 21 sep: how many a station needs LIVES WITH THE STATION. It
     * was a column on the ring, which made it a property of the pattern —
     * and a pattern is one shared object now, so a need kept there would
     * say that every station running the same cycle needs the same number
     * of people, which is not a thing about cycles at all.
     *
     * IT IS WHAT A SHORTFALL IS MEASURED AGAINST, so it is a DECLARATION
     * and never a count of who turned up: a station whose need nobody has
     * stated is short of nobody, and a station that asks for two and
     * staffs one is short of one however the pattern is going.
     *
     * @var array<string, int>
     */
    #[ORM\Column(name: 'needs_per_shift', type: Types::JSON)]
    private array $needsPerShift = [];

    /**
     * THE CYCLE THIS STATION IS FILLED FROM, or null where nobody has
     * applied one.
     *
     * NULL IS A REAL ANSWER AND THE COMMON ONE. A station with no pattern
     * is filled by hand on the sheet; it is never short of a pattern, and
     * nothing on any screen asks it to have one.
     *
     * SET NULL AND NOT CASCADE: deleting a pattern must not delete the
     * stations that ran it. They stop being filled and keep everything
     * else about themselves.
     */
    #[ORM\ManyToOne(targetEntity: Pattern::class)]
    #[ORM\JoinColumn(name: 'pattern_id', nullable: true, onDelete: 'SET NULL')]
    private ?Pattern $pattern = null;

    /**
     * THE DAY THE CYCLE STARTS FROM — the anchor, and the one thing that
     * turns a ring of days into dates.
     *
     * IT IS THE START DATE SOMEBODY TYPED IN THE FILL ROW, kept, because
     * "a later start date changes only the days after it" can only be
     * true if the earlier one is still known. Null until a pattern has
     * been run here, and null means the station expects nothing of
     * anybody: a station nobody has filled yet has no gaps, only
     * emptiness, and drawing a fortnight of alarm ink at it would be the
     * sheet shouting about a decision nobody has made.
     */
    #[ORM\Column(name: 'pattern_from', type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $patternFrom = null;

    /**
     * HOW FAR AHEAD IT IS FILLED. What the band means by "filled to 28
     * oct", and what the next run starts checking from.
     */
    #[ORM\Column(name: 'filled_through', type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $filledThrough = null;

    public function __construct(
        Station $station,
        int $silenceWindowMinutes,
        int $offlineAfterMinutes,
    ) {
        $this->station = $station;
        $this->silenceWindowMinutes = $silenceWindowMinutes;
        $this->offlineAfterMinutes = $offlineAfterMinutes;
        $this->guardThresholds();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStation(): Station
    {
        return $this->station;
    }

    /**
     * @return list<string>
     */
    public function getExpects(): array
    {
        return $this->expects;
    }

    /**
     * @param list<string> $shiftKeys
     */
    public function expect(array $shiftKeys): static
    {
        $this->expects = array_values(array_unique($shiftKeys));

        return $this;
    }

    public function expectsNothing(): bool
    {
        return [] === $this->expects;
    }

    public function getSilenceWindowMinutes(): int
    {
        return $this->silenceWindowMinutes;
    }

    public function getOfflineAfterMinutes(): int
    {
        return $this->offlineAfterMinutes;
    }

    public function setThresholds(int $silenceWindowMinutes, int $offlineAfterMinutes): static
    {
        $this->silenceWindowMinutes = $silenceWindowMinutes;
        $this->offlineAfterMinutes = $offlineAfterMinutes;
        $this->guardThresholds();

        return $this;
    }

    /**
     * OFFLINE HAS TO COME AFTER LATE, or a post would go straight from
     * reporting to offline and "late" would name nothing — which is the state
     * that exists precisely so somebody can pick up a radio before it becomes
     * the other one.
     */
    private function guardThresholds(): void
    {
        if ($this->silenceWindowMinutes < 1) {
            throw new \InvalidArgumentException('A silence window is at least one minute; zero would make every post late the moment it reported.');
        }

        if ($this->offlineAfterMinutes <= $this->silenceWindowMinutes) {
            throw new \InvalidArgumentException(\sprintf('Offline (%d min) has to come after late (%d min), or a post would never read as late at all.', $this->offlineAfterMinutes, $this->silenceWindowMinutes));
        }
    }

    /**
     * @return array<string, int>
     */
    public function getNeedsPerShift(): array
    {
        return $this->needsPerShift;
    }

    /** How many this station needs on one shift; none where it has not said. */
    public function needsOn(string $shiftKey): int
    {
        return max(0, $this->needsPerShift[$shiftKey] ?? 0);
    }

    /**
     * @param array<string, int> $needs
     */
    public function setNeedsPerShift(array $needs): static
    {
        $kept = [];
        foreach ($needs as $key => $count) {
            if ('' !== $key && $count > 0) {
                $kept[$key] = $count;
            }
        }

        $this->needsPerShift = $kept;

        return $this;
    }

    public function getPattern(): ?Pattern
    {
        return $this->pattern;
    }

    /** Apply a cycle to this station, or take it off one. */
    public function filledBy(?Pattern $pattern): static
    {
        $this->pattern = $pattern;

        return $this;
    }

    public function getPatternFrom(): ?\DateTimeImmutable
    {
        return $this->patternFrom;
    }

    public function getFilledThrough(): ?\DateTimeImmutable
    {
        return $this->filledThrough;
    }

    /**
     * THE FILL SAYS WHERE THE RING STARTS AND HOW FAR IT REACHED.
     *
     * The anchor is only taken the FIRST time, or when the pattern
     * itself changes: a second run from a later monday must not slide
     * the ring under the days already standing, which is what "a later
     * start date changes only the days after it" means.
     */
    public function filledFrom(\DateTimeImmutable $from, \DateTimeImmutable $through): static
    {
        $this->patternFrom ??= $from->setTime(0, 0);
        $this->filledThrough = $through->setTime(0, 0);

        return $this;
    }

    /** A pattern change re-anchors the ring; there is nothing to keep in step with. */
    public function reanchor(?\DateTimeImmutable $from): static
    {
        $this->patternFrom = $from?->setTime(0, 0);

        return $this;
    }
}
