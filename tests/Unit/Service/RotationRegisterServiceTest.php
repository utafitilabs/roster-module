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

namespace Uhifadhi\Roster\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Roster\Entity\Rotation;
use Uhifadhi\Roster\Enum\RotationScope;
use Uhifadhi\Roster\Model\Cycle;
use Uhifadhi\Roster\Model\RotationRow;
use Uhifadhi\Roster\Service\RotationRegisterService;

/**
 * EVERY ROTATION, AND EVERY POST THAT RUNS NONE, AS ONE REGISTER: a row a
 * ring or a post, each in one of three states, narrowed by the state chip
 * and a search over the post, its code and the team.
 */
final class RotationRegisterServiceTest extends TestCase
{
    public function testEveryRingIsARowAndEveryPostThatRunsNoneIsOneAfterThem(): void
    {
        $rows = self::rows();

        self::assertSame(['north gate post', 'rapid response team', 'river post', 'west outpost'], array_map(static fn (RotationRow $row): string => $row->name(), $rows));
        self::assertSame(
            [RotationRow::RUNNING, RotationRow::RUNNING, RotationRow::STOOD_DOWN, RotationRow::NONE],
            array_map(static fn (RotationRow $row): string => $row->state, $rows),
        );
        self::assertNull($rows[3]->rotation);
        self::assertSame('ST-02', $rows[3]->station?->getCode());
    }

    public function testTheChipCountsEveryRowAndEachState(): void
    {
        self::assertSame(
            ['all' => 4, RotationRow::RUNNING => 2, RotationRow::STOOD_DOWN => 1, RotationRow::NONE => 1],
            new RotationRegisterService()->stateCounts(self::rows()),
        );
    }

    public function testTheStateAndTheSearchNarrow(): void
    {
        $service = new RotationRegisterService();
        $rows = self::rows();

        self::assertSame(['west outpost'], self::names($service->narrow($rows, RotationRow::NONE, null)));
        self::assertSame(['river post'], self::names($service->narrow($rows, RotationRow::STOOD_DOWN, null)));
        self::assertSame(['north gate post'], self::names($service->narrow($rows, null, 'st-01')), 'the code, in any case');
        self::assertSame(['rapid response team'], self::names($service->narrow($rows, null, 'rapid')), 'the team');
        self::assertSame(['rapid response team'], self::names($service->narrow($rows, null, 'headquarters')), 'and the post a team is based at');
        self::assertCount(4, $service->narrow($rows, 'not-a-state', ''), 'an unknown state narrows nothing');
        self::assertSame([], $service->narrow($rows, RotationRow::RUNNING, 'west'));
    }

    /**
     * @return list<RotationRow>
     */
    private static function rows(): array
    {
        $area = new AreaOfInterest()->setName('seed reserve');
        $gate = self::station($area, 'north gate post', 'ST-01');
        $west = self::station($area, 'west outpost', 'ST-02');
        $river = self::station($area, 'river post', 'ST-03');
        $base = self::station($area, 'headquarters', 'ST-05');

        $ring = static fn (RotationScope $scope): Rotation => new Rotation($area, $scope, Cycle::of(['day', Cycle::OFF]), new \DateTimeImmutable('2026-09-14'), ['day' => 1], 42);

        return new RotationRegisterService()->rows(
            [
                $ring(RotationScope::Post)->standAt($gate),
                $ring(RotationScope::Team)->carriedBy('rapid response team', $base),
                $ring(RotationScope::Post)->standAt($river)->setActive(false),
            ],
            [$west],
        );
    }

    private static function station(AreaOfInterest $area, string $name, string $code): Station
    {
        return new Station()->setArea($area)->setName($name)->setCode($code);
    }

    /**
     * @param list<RotationRow> $rows
     *
     * @return list<string>
     */
    private static function names(array $rows): array
    {
        return array_map(static fn (RotationRow $row): string => $row->name(), $rows);
    }
}
