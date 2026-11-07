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

use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Roster\Entity\Rotation;
use Uhifadhi\Roster\Model\RotationRow;

/**
 * EVERY ROTATION IN AN AREA, AND EVERY POST THAT RUNS NONE, AS ONE REGISTER —
 * the rows, the state chip's counts and the narrowing. Pure: the caller reads
 * the rings and the posts, this decides what of them is on screen.
 */
final readonly class RotationRegisterService
{
    /**
     * @param list<Rotation> $rotations the rings the area declares
     * @param list<Station>  $idle      the posts the area registers that run none
     *
     * @return list<RotationRow>
     */
    public function rows(array $rotations, array $idle): array
    {
        $rows = [];
        foreach ($rotations as $rotation) {
            $rows[] = new RotationRow($rotation, $rotation->getStation(), $rotation->isActive() ? RotationRow::RUNNING : RotationRow::STOOD_DOWN);
        }
        foreach ($idle as $station) {
            $rows[] = new RotationRow(null, $station, RotationRow::NONE);
        }

        return $rows;
    }

    /**
     * How many rows there are, and how many in each state — over every row,
     * so an option never reads 0 because somebody is searching.
     *
     * @param list<RotationRow> $rows
     *
     * @return array<string, int>
     */
    public function stateCounts(array $rows): array
    {
        $counts = ['all' => \count($rows)] + array_fill_keys(array_keys(RotationRow::STATES), 0);
        foreach ($rows as $row) {
            ++$counts[$row->state];
        }

        return $counts;
    }

    /**
     * The rows in one state whose post, code, team or base post contains what
     * was typed. A state the chip does not offer narrows nothing.
     *
     * @param list<RotationRow> $rows
     *
     * @return list<RotationRow>
     */
    public function narrow(array $rows, ?string $state, ?string $query): array
    {
        $state = isset(RotationRow::STATES[(string) $state]) ? $state : null;
        $query = trim((string) $query);

        return array_values(array_filter($rows, static fn (RotationRow $row): bool => (null === $state || $state === $row->state)
            && ('' === $query || false !== mb_stripos(implode(' ', [
                $row->name(),
                $row->station?->getCode(),
                $row->rotation?->getTeamName(),
                $row->rotation?->getBaseStation()?->getName(),
            ]), $query))));
    }
}
