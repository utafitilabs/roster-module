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

namespace Uhifadhi\Roster\Service;

use Doctrine\ORM\EntityManagerInterface;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Bundle\AreaBundle\Service\StationService;
use Uhifadhi\Roster\Entity\StationWatch;
use Uhifadhi\Roster\Repository\RotationRepository;
use Uhifadhi\Roster\Repository\StationWatchRepository;

/**
 * THE POSTS ON THIS MODULE'S BOOKS, and the four columns it owns on each.
 *
 * A POST IS ADDED TO THE ROSTER DELIBERATELY. The area may register twelve
 * stations; this module works the ones somebody has given a watch, and the
 * others it does not count, report on or complain about. So there is no
 * create-on-read here, unlike the settings row — an accidental row would put
 * a post on the books that nobody meant to be watching.
 *
 * THE SAME BLOCK RENDERS IN TWO PLACES. This module's Configure page and the
 * area's own Stations configure card are two doors onto one record; both call
 * this, and neither owns the other's screen.
 */
final readonly class StationWatchService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private StationWatchRepository $watches,
        private RotationRepository $rotations,
        private RosterSettingsService $settings,
        // THE RING IS THE POST'S, so the verb that writes it is the area's.
        private StationService $stations,
        private int $defaultSilenceWindowMinutes,
        private int $defaultOfflineAfterMinutes,
    ) {
    }

    /**
     * @return list<StationWatch>
     */
    public function forArea(AreaOfInterest $area): array
    {
        return $this->watches->findByArea($area);
    }

    public function forStation(Station $station): ?StationWatch
    {
        return $this->watches->findOneForStation($station);
    }

    /**
     * PUT A POST ON THE BOOKS. Its ring starts at whatever the AREA's default
     * is rather than the installation's, because the area setting is
     * explicitly "used by a post that sets none of its own" — reading past it
     * to the config would make the area's own number mean nothing.
     *
     * THE RING IS THE POST'S, AND IT IS SET ON THE POST. A post that already
     * carries one keeps it: joining the roster is not a reason to move a
     * distance somebody chose, and the area owns that column.
     */
    public function addToRoster(Station $station): StationWatch
    {
        $existing = $this->watches->findOneForStation($station);
        if (null !== $existing) {
            return $existing;
        }

        $settings = $this->settings->forArea($station->getArea() ?? throw new \LogicException('A station always belongs to an area; this one does not, so there is no roster to add it to.'));

        $watch = new StationWatch(
            $station,
            $this->defaultSilenceWindowMinutes,
            $this->defaultOfflineAfterMinutes,
        );
        $this->entityManager->persist($watch);

        if (null === $station->getCatchmentM()) {
            $this->stations->setCatchment($station, $settings->getDefaultCatchmentMetres());
        }

        $this->entityManager->flush();

        return $watch;
    }

    /**
     * SAVE ONE POST'S WATCH.
     *
     * @param list<string> $expects shift keys from the area's own list; empty declares a post that runs nothing
     */
    public function save(StationWatch $watch, array $expects, int $silenceWindowMinutes, int $offlineAfterMinutes): StationWatch
    {
        $watch
            ->expect($expects)
            ->setThresholds($silenceWindowMinutes, $offlineAfterMinutes);

        $this->entityManager->flush();

        return $watch;
    }

    /**
     * HOW MANY PEOPLE THE POST'S RING DRAWS FROM — what the Watches row prints
     * as "pool 5".
     *
     * It comes from the ROTATION and not from the area's postings, and the
     * difference is the point: a pool may be smaller than the postings
     * (somebody on long leave is taken out of the ring without being
     * unposted). A post with no rotation draws from nobody, which is a real
     * zero and not a missing figure.
     */
    public function poolSizeFor(Station $station): int
    {
        return $this->rotations->findOneForStation($station)?->getPool()->count() ?? 0;
    }
}
