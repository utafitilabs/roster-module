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
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Bundle\AreaBundle\Entity\Zone;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Roster\Controller\RosterController;
use Uhifadhi\Roster\Entity\Duty;
use Uhifadhi\Roster\Entity\Rotation;
use Uhifadhi\Roster\Entity\RotationPoolMember;
use Uhifadhi\Roster\Enum\RotationScope;
use Uhifadhi\Roster\Model\Cycle;
use Uhifadhi\Roster\Service\StationWatchService;
use Uhifadhi\Roster\Tests\FreshDatabase;
use Uhifadhi\Roster\Tests\Integration\Fixtures\FixedManageVoter;

/**
 * LIVE, MEASURED AGAINST ITS DESIGN — modules/roster/live.html.
 *
 * THE PLATE IS FIRST and the roster is underneath it, which is the whole
 * shape of the tab and was the thing the port had backwards: it used to be
 * the roster and nothing else.
 *
 * WHAT THIS MODULE CONTRIBUTES IS ASSERTED, and nothing the atlas or the
 * area owns is. The plate's imagery, controls and key are the house's; the
 * ground, the boundary and the posts are the area's; the marker layers and
 * their legend group are the roster's, and so is the strip under them.
 *
 * THE STRIP AND THE MAP ARE DELIBERATELY NOT THE SAME SET. Somebody on duty
 * whose phone has said nothing is named in the strip and is nowhere on the
 * map — a surface showing only the map would report them absent, and one
 * inventing a marker at the post would turn a claim into proof.
 */ final class LiveTabTest extends WebTestCase
{
    use EveryAreaRunsTheRoster;
    use FreshDatabase;
    use ReadsTheLiveStream;

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

        // A ZONE, so the plate has ground under it and the key a zone row.
        $this->em->persist(new Zone()->setArea($this->area)->setName('north sector')->setGeom(
            '{"type":"MultiPolygon","coordinates":[[[[12.25,-5.75],[12.45,-5.75],[12.45,-5.55],[12.25,-5.55],[12.25,-5.75]]]]}',
        ));
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
        // AND A NIGHT WATCH THAT BEGAN YESTERDAY, which is the case the
        // wall exists to draw: it is still standing at 05:00 this morning,
        // so it is the first block of today's row as well as the last of
        // yesterday's.
        $this->em->persist(new Duty($this->area, $gate, $ranger, 'night', new \DateTimeImmutable('yesterday')));
        $this->em->flush();
    }

    /**
     * THE LIVE TAB STREAMS. It used to draw once, so its marks stood still
     * until the page was reloaded; it now subscribes to the area's topic
     * like the area overview does, with the cookie the hub checks.
     */
    public function testTheLiveTabStreamsTheAreasPositions(): void
    {
        $this->open();

        self::assertStreamsTheAreas($this->client, [(string) $this->area->getUuidString()]);
    }

    private function open(): \Symfony\Component\DomCrawler\Crawler
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => FixedManageVoter::MANAGER_EMAIL]);
        self::assertInstanceOf(User::class, $user);
        $this->client->loginUser($user);

        $router = static::getContainer()->get('router');
        self::assertInstanceOf(\Symfony\Component\Routing\RouterInterface::class, $router);

        $crawler = $this->client->request('GET', $router->generate(RosterController::LIVE_ROUTE, ['uuid' => (string) $this->area->getUuidString()]));
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /** THE FOUR KPI CARDS the design names, in its order — four, never five. */
    public function testTheStripDrawsTheDesignsFourCards(): void
    {
        $crawler = $this->open();

        $cards = $crawler->filter('.kstrip .c.kpi');
        self::assertCount(4, $cards, 'A KPI row is four cards, never five.');

        self::assertSame(
            ['positions-now', 'verified', 'oldest-ping', 'posts-reporting'],
            $cards->each(static fn (\Symfony\Component\DomCrawler\Crawler $card): string => (string) $card->attr('data-kpi')),
        );
    }

    /** THE PLATE IS FIRST, and the roster is underneath it. */
    public function testThePlateComesFirstAndTheRosterIsUnderneath(): void
    {
        $crawler = $this->open();

        self::assertSame(
            ['The plate first', 'The roster, underneath'],
            $crawler->filter('h2.zone')->each(static fn (\Symfony\Component\DomCrawler\Crawler $h): string => html_entity_decode(trim($h->text()))),
        );

        self::assertCount(1, $crawler->filter('.fg-live'), 'The plate, in the card that leads the page.');
        self::assertGreaterThan(0, $crawler->filter('.fg-live .map-plate')->count(), 'And the atlas plate itself is in it.');
    }

    /**
     * THE PLATE TAKES THE ROW (ruled 1 Oct, #16 D). A click on a mark opens
     * the sheet at the plate's foot, so no rail stands beside the plate and
     * nothing on the page composes one.
     */
    public function testThePlateTakesTheRowWithNoRailBesideIt(): void
    {
        $crawler = $this->open();

        self::assertCount(0, $crawler->filter('.fg-side'), 'No rail.');
        self::assertCount(0, $crawler->filter('.rl-cell, .rl-presets, .rl-add'), 'And nothing that composes one.');
    }

    /**
     * THE ROSTER UNDERNEATH IS BOUNDED AND SCROLLS INSIDE ITS CARD — RULED
     * 25 sep, the sheet's own height rule — with its column head pinned.
     */
    public function testTheRosterUnderneathIsBoundedWithItsHeadPinned(): void
    {
        $crawler = $this->open();

        $card = $crawler->filter('.c[data-controller="roster--bound"]');
        self::assertCount(1, $card);
        self::assertSame('roster--bound', $card->attr('data-controller'));
        self::assertStringStartsWith('The roster, underneath', html_entity_decode(trim($card->filter('.tab')->text())));

        $scroller = $card->filter('.rscroll');
        self::assertCount(1, $scroller);
        self::assertSame('scroller', $scroller->attr('data-roster--bound-target'));
        self::assertCount(1, $scroller->filter('table.tbl > thead > tr'), 'The column head is a thead, which is what pins.');
        self::assertCount(6, $scroller->filter('table.tbl > thead th'));
    }

    /** THE FILTER ROW, the house's, pointed at this page. */
    public function testItWearsTheHouseFilterRow(): void
    {
        $crawler = $this->open();

        $row = $crawler->filter('form.lfilt');
        self::assertCount(1, $row);
        self::assertCount(3, $row->filter('details.i-dd'));
        self::assertStringContainsString('/live', (string) $row->attr('action'));
    }

    /**
     * THE LEGEND CARRIES THE LIVE KEY, and the module named none of it.
     *
     * A LAYER SHIPS A LEGEND — and here the legend says more than the
     * layer can: the mark has a state the plate draws DIMMED and a state
     * it draws NOWHERE, and a plate silent about the people it is not
     * showing would be claiming the park is fully seen. The atlas adds
     * both in one call.
     */
    public function testTheLiveKeyIsOnThePlateAndTheModuleNamesNoneOfIt(): void
    {
        $crawler = $this->open();

        $plate = html_entity_decode($crawler->filter('.fg-live')->html());

        foreach (['Live position', 'Stale', 'No position'] as $row) {
            self::assertStringContainsString($row, $plate, 'The key the live layer ships with.');
        }

        // AND THE RAIL'S OWN KEY, under its own heading: the six states
        // the column is colour-coded by. They are legend ITEMS and not
        // layers — nothing on the map is drawn in these colours — but a
        // reader meeting amber in the rail has nowhere else to learn it.
        self::assertStringContainsString('Presence states', $plate);
        self::assertStringContainsString('at post · verified', $plate);
        self::assertStringContainsString('no check-in', $plate);

        // AND NO COLOUR ANYWHERE NEAR THIS MODULE'S OWN LAYER. The posts
        // layer is still the roster's; it must not have grown a palette.
        self::assertDoesNotMatchRegularExpression('/roster\.[a-z_.]+[^}]{0,300}#[0-9A-Fa-f]{6}/', $plate);
    }
}
