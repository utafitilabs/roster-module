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
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Roster\Entity\Duty;
use Uhifadhi\Roster\Entity\Rotation;
use Uhifadhi\Roster\Entity\RotationPoolMember;
use Uhifadhi\Roster\Enum\RotationScope;
use Uhifadhi\Roster\Model\Cycle;
use Uhifadhi\Roster\Service\StationWatchService;
use Uhifadhi\Roster\Tests\FreshDatabase;
use Uhifadhi\Roster\Tests\Integration\Fixtures\FixedManageVoter;

/**
 * THE ROSTER'S BAND ON A STATION'S RECORD — the design's
 * modules/roster/station.html, "Today's watch": the house register of who
 * stands the watch today, their shift, and what the area read of them now.
 */
final class StationRecordWatchTest extends WebTestCase
{
    use EveryAreaRunsTheRoster;
    use FreshDatabase;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private AreaOfInterest $area;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->em = $em;

        self::freshDatabase($this->em);

        $this->area = new AreaOfInterest()->setSource('test fixture')->setName('seed reserve')->setGeom(
            '{"type":"MultiPolygon","coordinates":[[[[12.2,-5.8],[12.5,-5.8],[12.5,-5.5],[12.2,-5.5],[12.2,-5.8]]]]}',
        );
        $this->em->persist($this->area);

        $gate = new Station()
            ->setArea($this->area)
            ->setName('north gate post')
            ->setCode('ST-01')
            ->setPoint('{"type":"Point","coordinates":[12.3,-5.7]}');
        $this->em->persist($gate);
        $this->em->persist(new User()->setPassword('x')->setEmail(FixedManageVoter::MANAGER_EMAIL)->setFirstName('Mara')->setLastName('Manager'));
        $this->em->flush();

        $this->everyAreaRunsTheRoster($this->em);

        $watches = static::getContainer()->get('test_public.'.StationWatchService::class);
        self::assertInstanceOf(StationWatchService::class, $watches);
        $watches->addToRoster($gate)->expect(['day', 'night']);

        $rotation = new Rotation(
            $this->area,
            RotationScope::Post,
            Cycle::of(['day', 'night', Cycle::OFF]),
            new \DateTimeImmutable('today'),
            ['day' => 1, 'night' => 1],
            42,
        )->standAt($gate);
        $this->em->persist($rotation);

        $ranger = new User()->setPassword('x')->setEmail('ada@example.test')->setFirstName('Ada')->setLastName('Example');
        $this->em->persist($ranger);
        $this->em->persist(new RotationPoolMember($rotation, $ranger, 0));
        // Due today and nothing reported: the "no check-in" row the decisions
        // card exists for, and the one figure an empty seed never produces.
        $this->em->persist(new Duty($this->area, $gate, $ranger, 'day', new \DateTimeImmutable('today')));
        // AND ONE ON TOMORROW, so the second card draws a watch that is DUE
        // rather than only the hole under it.
        $this->em->persist(new Duty($this->area, $gate, $ranger, 'day', new \DateTimeImmutable('tomorrow')));
        $this->em->flush();
    }

    private function open(): Crawler
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => FixedManageVoter::MANAGER_EMAIL]);
        self::assertInstanceOf(User::class, $user);
        $this->client->loginUser($user);

        $router = static::getContainer()->get('router');
        self::assertInstanceOf(\Symfony\Component\Routing\RouterInterface::class, $router);

        $gate = $this->em->getRepository(Station::class)->findOneBy(['code' => 'ST-01']);
        self::assertInstanceOf(Station::class, $gate);
        $crawler = $this->client->request('GET', $router->generate('area_station_show', ['uuid' => (string) $this->area->getUuidString(), 'station' => (string) $gate->getUuidString()]));
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    public function testTodaysWatchIsTheHouseRegister(): void
    {
        $crawler = $this->open();

        $table = $crawler->filter('table.tbl.ro-tbl[data-roster-watch]');
        self::assertCount(1, $table, 'The roster draws today\'s watch as the house register.');
        self::assertSame(['Person', 'Shift', 'Now'], $table->filter('thead th')->each(static fn (Crawler $th): string => trim($th->text())));

        $row = $table->filter('tbody tr')->eq(0);
        self::assertSame('Ada Example', trim($row->filter('td')->eq(0)->filter('b')->text()));
        self::assertCount(1, $row->filter('td')->eq(1)->filter('.r-sh'));
        self::assertCount(1, $row->filter('td')->eq(2)->filter('.r-ci.no_check_in'), 'Due today and nothing reported.');
        self::assertCount(0, $crawler->filter('.rset .r-ci'), 'No person is drawn as a fact row any more.');
    }

    /** TODAY'S WATCH CARRIES THE REGISTER FOOT, paged by keys of its own on a page that is the area's. */
    public function testTodaysWatchCarriesTheRegisterFoot(): void
    {
        $foot = $this->open()->filter('table.tbl.ro-tbl[data-roster-watch] + .rdf-foot');

        self::assertCount(1, $foot);
        self::assertStringContainsString('1 watch', $foot->text());
        self::assertSame('page 1 of 1', trim($foot->filter('.rdf-page')->text()));
        self::assertStringContainsString('watch_per=50', (string) $foot->filter('.rdf-size a.i-ddopt')->eq(1)->attr('href'));
    }
}
