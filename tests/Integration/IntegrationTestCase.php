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

namespace Uhifadhi\Roster\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Roster\DependencyInjection\RosterConfiguration;
use Uhifadhi\Roster\Entity\Shift;
use Uhifadhi\Roster\Tests\FreshDatabase;

/**
 * Symfony-standard kernel testing: KernelTestCase + KERNEL_CLASS
 * (phpunit.dist.xml) booting TestKernel with debug=true, against the real
 * PostGIS database and rebuilding the schema per test — so every assertion is
 * about what was actually stored.
 */
abstract class IntegrationTestCase extends KernelTestCase
{
    use FreshDatabase;

    protected EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->em = $em;

        self::freshDatabase($this->em);
    }

    /**
     * A SYNTHETIC AREA at nobody's coordinates. Never a client's name, never a
     * real park's boundary.
     */
    protected function anArea(string $name = 'seed reserve'): AreaOfInterest
    {
        $area = new AreaOfInterest()->setSource('test fixture')->setName($name)->setGeom(
            '{"type":"MultiPolygon","coordinates":[[[[12.2,-5.8],[12.5,-5.8],[12.5,-5.5],[12.2,-5.5],[12.2,-5.8]]]]}',
        );
        $this->em->persist($area);

        return $area;
    }

    protected function aStation(AreaOfInterest $area, string $name, string $code = 'ST-01'): Station
    {
        $station = new Station()
            ->setArea($area)
            ->setName($name)
            ->setCode($code)
            ->setPoint('{"type":"Point","coordinates":[12.3,-5.7]}');
        $this->em->persist($station);

        return $station;
    }

    protected function aPerson(string $email, string $firstName = 'Neema', string $lastName = 'Example'): User
    {
        $user = new User()->setPassword('x')->setEmail($email)->setFirstName($firstName)->setLastName($lastName);
        $this->em->persist($user);

        return $user;
    }

    /**
     * THE AREA'S SHIFT LIST, seeded from the bundle's configured vocabulary —
     * the same four windows an installation gets, so a test never invents a
     * shift the product does not ship with.
     *
     * @return array<string, Shift>
     */
    protected function theShiftVocabulary(AreaOfInterest $area): array
    {
        $shifts = [];
        foreach (RosterConfiguration::DEFAULT_SHIFTS as $position => $definition) {
            $shift = new Shift($area, $definition['key'], $definition['label'], $definition['start'], $definition['end'], $position);
            $this->em->persist($shift);
            $shifts[$definition['key']] = $shift;
        }

        return $shifts;
    }

    protected function tearDown(): void
    {
        $this->em->close();
        parent::tearDown();

        // The framework's debug error handler is registered during the test
        // and never popped; PHPUnit flags that as risky. Pop whatever is left.
        while (true) {
            $previous = set_exception_handler(static fn () => null);
            restore_exception_handler();
            if (null === $previous) {
                break;
            }
            restore_exception_handler();
        }
    }

    /**
     * Fetch a bundle service through the test container. The test_public.*
     * aliases (TestKernel) exist only because a bundle test kernel has no
     * controllers yet: unreferenced private services are removed at compile
     * time. Delete an alias once a real reference exists.
     */
    protected function service(string $id): object
    {
        return static::getContainer()->get('test_public.'.$id);
    }
}
