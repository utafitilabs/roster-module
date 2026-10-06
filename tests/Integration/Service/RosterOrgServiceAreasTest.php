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

namespace Uhifadhi\Roster\Tests\Integration\Service;

use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Contracts\Shell\Scope;
use Uhifadhi\Roster\Service\RosterOrgService;
use Uhifadhi\Roster\Tests\Integration\IntegrationTestCase;

/**
 * WHICH AREAS AN ORGANIZATION READING COVERS. Every area, where nobody's
 * reach narrows it — the organization dashboard's own scope; otherwise the
 * areas the reader was offered, and none where they were offered none.
 */
final class RosterOrgServiceAreasTest extends IntegrationTestCase
{
    public function testTheWholeOrganizationIsEveryArea(): void
    {
        [$north, $south] = $this->twoAreas();

        self::assertSame([$north->getId(), $south->getId()], self::ids($this->org()->areasIn(Scope::organization(), null)));
    }

    public function testAReaderIsShownTheAreasTheyWereOffered(): void
    {
        [, $south] = $this->twoAreas();

        $offered = [Scope::area((string) $south->getUuidString(), 'south reserve')];

        self::assertSame([$south->getId()], self::ids($this->org()->areasIn(Scope::organization(), $offered)));
    }

    public function testAReaderOfferedNoAreaIsShownNone(): void
    {
        $this->twoAreas();

        self::assertSame([], $this->org()->areasIn(Scope::organization(), []));
    }

    /** @return array{AreaOfInterest, AreaOfInterest} */
    private function twoAreas(): array
    {
        $north = $this->anArea('north reserve');
        $south = $this->anArea('south reserve');
        $this->em->flush();

        return [$north, $south];
    }

    private function org(): RosterOrgService
    {
        $org = $this->service(RosterOrgService::class);
        self::assertInstanceOf(RosterOrgService::class, $org);

        return $org;
    }

    /**
     * @param list<AreaOfInterest> $areas
     *
     * @return list<int|null>
     */
    private static function ids(array $areas): array
    {
        return array_map(static fn (AreaOfInterest $area): ?int => $area->getId(), $areas);
    }
}
