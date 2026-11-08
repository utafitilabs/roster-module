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
use Symfony\Component\Routing\RouterInterface;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Roster\Service\StationWatchService;
use Uhifadhi\Roster\Tests\FreshDatabase;
use Uhifadhi\Roster\Tests\Integration\Fixtures\FixedManageVoter;

/**
 * THE ROSTER'S BLOCK ON AN AREA'S CONFIGURE › STATIONS CARD, a page the
 * area draws and the roster does not own: the block is drawn in `.rset`
 * rows, and the page's head links the sheet those rows are defined in.
 */
final class StationConfigureBlockTest extends WebTestCase
{
    use EveryAreaRunsTheRoster;
    use FreshDatabase;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->em = $em;

        self::freshDatabase($this->em);
    }

    public function testTheStationsConfigurePageLinksTheSheetTheBlockIsDrawnIn(): void
    {
        [$area, $gate, $manager] = $this->aRosteredStation();

        $this->client->loginUser($manager);
        $router = static::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);
        $crawler = $this->client->request('GET', $router->generate('area_stations_configure', [
            'uuid' => (string) $area->getUuidString(),
            'open' => (string) $gate->getUuidString(),
        ]));

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('details.zcard .rset')->count(), 'the block is drawn on the card');
        self::assertTrue(self::links($crawler, 'bundles/uhifadhiroster/station'), 'the head links the sheet `.rset` is defined in');
    }

    /**
     * THE ROSTER'S OWN PAGES DRAW THE SAME CHIPS AND ROWS, and get the
     * station sheet from the same head, ahead of roster.css, which they link
     * themselves and whose later rules may refine them.
     */
    public function testTheWatchesSectionLinksTheStationSheetBeforeTheRostersOwn(): void
    {
        [$area, , $manager] = $this->aRosteredStation();
        $this->client->loginUser($manager);

        $crawler = $this->client->request('GET', '/areas/'.$area->getUuidString().'/modules/roster/watches');

        self::assertResponseIsSuccessful();
        $sheets = self::sheets($crawler);
        $station = array_key_first(array_filter($sheets, static fn (string $h): bool => str_contains($h, 'bundles/uhifadhiroster/station')));
        $roster = array_key_first(array_filter($sheets, static fn (string $h): bool => str_contains($h, 'bundles/uhifadhiroster/roster')));
        self::assertNotNull($station);
        self::assertNotNull($roster);
        self::assertLessThan($roster, $station);
    }

    /** @return array{AreaOfInterest, Station, User} */
    private function aRosteredStation(): array
    {
        $area = new AreaOfInterest()->setSource('test fixture')->setName('seed reserve')->setGeom(
            '{"type":"MultiPolygon","coordinates":[[[[12.2,-5.8],[12.5,-5.8],[12.5,-5.5],[12.2,-5.5],[12.2,-5.8]]]]}',
        );
        $this->em->persist($area);
        $gate = new Station()->setArea($area)->setName('north gate post')->setCode('ST-01')->setPoint('{"type":"Point","coordinates":[12.3,-5.7]}');
        $this->em->persist($gate);
        $manager = new User()->setPassword('x')->setEmail(FixedManageVoter::MANAGER_EMAIL)->setFirstName('Mara')->setLastName('Manager');
        $this->em->persist($manager);
        $this->em->flush();
        $this->everyAreaRunsTheRoster($this->em);

        $watches = static::getContainer()->get('test_public.'.StationWatchService::class);
        self::assertInstanceOf(StationWatchService::class, $watches);
        $watches->addToRoster($gate)->expect(['day', 'night']);
        $this->em->flush();

        return [$area, $gate, $manager];
    }

    /** @return list<string> */
    private static function sheets(Crawler $page): array
    {
        return $page->filter('head link[rel="stylesheet"]')->each(static fn (Crawler $l): string => (string) $l->attr('href'));
    }

    private static function links(Crawler $page, string $sheet): bool
    {
        return [] !== array_filter(self::sheets($page), static fn (string $href): bool => str_contains($href, $sheet));
    }
}
