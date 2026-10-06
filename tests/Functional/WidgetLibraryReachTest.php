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

namespace Uhifadhi\Roster\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Bundle\TeamBundle\Entity\Department;
use Uhifadhi\Bundle\TeamBundle\Entity\Placement;
use Uhifadhi\Bundle\TeamBundle\Entity\Position;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Roster\Service\StationWatchService;
use Uhifadhi\Roster\Tests\FreshDatabase;
use Uhifadhi\Roster\Tests\Functional\Authority\AuthorityKernel;

/**
 * THE WIDGET LIBRARY'S ORGANIZATION PREVIEW IS THE ORGANIZATION THE READER
 * MAY OPEN. The preview is the widget, so it reads what `/roster` reads for
 * the same person: somebody who may open one area is not shown another
 * area's roster through the library of the one they may.
 */
final class WidgetLibraryReachTest extends WebTestCase
{
    use EveryAreaRunsTheRoster;
    use FreshDatabase;

    protected static function getKernelClass(): string
    {
        return AuthorityKernel::class;
    }

    public function testTheOrganizationPreviewShowsOnlyTheAreasTheReaderMayOpen(): void
    {
        $client = self::createClient();
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        self::freshDatabase($em);

        $kilimani = new AreaOfInterest()->setName('Kilimani Game Reserve');
        $tambarare = new AreaOfInterest()->setName('Tambarare Game Reserve');
        $operations = new Department()->setName('Operations');
        $seat = new Position()->setName('Ranger')->setGrantValues(['areas.read'], ['areas.read']);
        $reader = new User()->setEmail('reader@example.test')->setFirstName('Neema')->setLastName('Example')->setPassword('x')->setVerified(true)
            ->setPosition($seat)->setPlacement(new Placement()->inAreas([$kilimani])->inDepartment($operations));
        $gate = new Station()->setArea($kilimani)->setName('North Gate')->setPoint('{"type":"Point","coordinates":[37.1,-2.8]}');
        $river = new Station()->setArea($tambarare)->setName('River Post')->setPoint('{"type":"Point","coordinates":[37.4,-2.6]}');
        foreach ([$kilimani, $tambarare, $operations, $seat, $reader, $gate, $river] as $entity) {
            $em->persist($entity);
        }
        $this->everyAreaRunsTheRoster($em);

        $watches = static::getContainer()->get('test_public.'.StationWatchService::class);
        self::assertInstanceOf(StationWatchService::class, $watches);
        foreach ([$gate, $river] as $station) {
            $watch = $watches->addToRoster($station);
            $watches->save($watch, ['day'], $watch->getSilenceWindowMinutes(), $watch->getOfflineAfterMinutes());
        }

        $client->loginUser($reader);
        $client->request('GET', '/areas/'.$kilimani->getUuidString().'/modules/roster/widgets');

        self::assertResponseIsSuccessful();
        $page = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('North Gate', $page);
        self::assertStringNotContainsString('Tambarare', $page);
        self::assertStringNotContainsString('River Post', $page);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        while (true) {
            $previous = set_exception_handler(static fn () => null);
            restore_exception_handler();
            if (null === $previous) {
                break;
            }
            restore_exception_handler();
        }
    }
}
