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
use Uhifadhi\Roster\Widget\RosterWidgets;

/**
 * THE OVERVIEW IS POPULATED THE MOMENT THE MODULE IS SWITCHED ON.
 *
 * The regression this exists for is a tab that rendered its header, its tab
 * strip and its identity band and then STOPPED — a page that looks deliberate
 * and is empty. The surface is composed, so "populated" is a fact about the
 * shipped composition resolving to something, and that is what is asserted
 * here: the four widgets the design ships, in the rendered DOM, by their own
 * hooks.
 *
 * IT ASSERTS STRUCTURE, NOT LAYOUT. What elements exist, how many, in what
 * order, carrying which figures — everything a server can be held to. Whether
 * the strip lays out in one row of five is the browser's answer and is
 * measured against the design by eye, not here.
 */
final class OverviewSurfaceTest extends WebTestCase
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

        $this->area = new AreaOfInterest()->setSource('test fixture')->setName('demo reserve')->setGeom(
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
        // card exists for, and the one figure an empty demo never produces.
        $this->em->persist(new Duty($this->area, $gate, $ranger, 'day', new \DateTimeImmutable('today')));
        $this->em->flush();
    }

    private function open(): \Symfony\Component\DomCrawler\Crawler
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => FixedManageVoter::MANAGER_EMAIL]);
        self::assertInstanceOf(User::class, $user);
        $this->client->loginUser($user);

        $router = static::getContainer()->get('router');
        self::assertInstanceOf(\Symfony\Component\Routing\RouterInterface::class, $router);

        $crawler = $this->client->request('GET', $router->generate(RosterController::OVERVIEW_ROUTE, ['uuid' => (string) $this->area->getUuidString()]));
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /**
     * THE SHIPPED COMPOSITION IS ON THE PAGE: the figures, what needs a
     * decision, the posts, and who is reporting right now.
     */
    public function testTheShippedCompositionRendersItsFourWidgets(): void
    {
        $crawler = $this->open();

        self::assertCount(1, $crawler->filter('.w-grid'), 'The tab is a composed surface, not a drawn page.');

        $drawn = $crawler->filter('.w-grid > .w-cell')->each(
            static fn (\Symfony\Component\DomCrawler\Crawler $cell): string => (string) $cell->attr('data-w'),
        );

        self::assertSame(['kpis', 'decisions', 'stations', 'live'], $drawn);
    }

    /**
     * THE FOUR KPI CARDS, in one strip, each with its figure.
     *
     * FOUR AND NEVER FIVE, ruled: flagged and no-check-in share one card
     * here, because both are a person somebody has to go and ask about and
     * the fragment beneath keeps them apart.
     */
    public function testTheFigureStripDrawsTheDesignsFourCards(): void
    {
        $crawler = $this->open();

        $strip = $crawler->filter('.w-grid .kstrip');
        self::assertCount(1, $strip, 'One strip, the house idiom — never a bespoke row.');

        $cards = $strip->filter('.c.kpi');
        self::assertCount(4, $cards, 'A KPI row is four cards, never five.');

        self::assertSame(
            ['checked-in', 'verified', 'needs-an-answer', 'posts-reporting'],
            $cards->each(static fn (\Symfony\Component\DomCrawler\Crawler $card): string => (string) $card->attr('data-kpi')),
        );

        foreach ($cards as $card) {
            $one = new \Symfony\Component\DomCrawler\Crawler($card);
            self::assertCount(1, $one->filter('.tab'), 'Every card names itself.');
            self::assertCount(1, $one->filter('.disp'), 'Every card carries a figure.');
            self::assertCount(1, $one->filter('.sub'), 'And one fragment under it.');
        }
    }

    /**
     * THE FIGURES ARE THE DAY'S, not a constant. One person due, nothing
     * reported: checked in reads 0 of 1 and the missing row appears.
     */
    public function testTheFiguresCountTheDayTheAreaActuallyReported(): void
    {
        $crawler = $this->open();

        $checkedIn = $crawler->filter('[data-kpi="checked-in"] .disp')->text();
        self::assertStringContainsString('0', $checkedIn);
        self::assertStringContainsString('of 2', $checkedIn, 'The POST expects two — a day and a night.');

        self::assertSame('1', trim($crawler->filter('[data-kpi="needs-an-answer"] .disp')->text()), 'One due and nothing reported is one thing to answer.');
    }

    /** WHAT NEEDS A DECISION SAYS WHICH, and the row names the person. */
    public function testTheDecisionsCardNamesWhoAndWhy(): void
    {
        $crawler = $this->open();

        $card = $crawler->filter('[data-w="decisions"]');
        self::assertCount(1, $card);
        self::assertStringContainsString('Needs a decision', $card->filter('.tab')->text());
        self::assertStringContainsString('Ada Example', $card->text());
        self::assertStringContainsString('no check-in', $card->text());
    }

    /** THE POSTS CARD CARRIES THE AREA'S OWN DENOMINATOR. */
    public function testTheStationsCardSaysHowManyOfTheAreasPosts(): void
    {
        $crawler = $this->open();

        $card = $crawler->filter('[data-w="stations"]');
        self::assertCount(1, $card);
        self::assertStringContainsString('1 of the area’s 1', html_entity_decode($card->filter('.src')->text()));
        self::assertStringContainsString('north gate post', $card->text());
    }

    /**
     * NO SECOND MAP (ruled 2026-09-26). The area's marks are the area
     * overview's and the Live tab's to draw; this tab lists who is
     * reporting and opens the Live tab for where they are.
     */
    public function testTheOverviewDrawsNoPlateAndOpensTheLiveTab(): void
    {
        $crawler = $this->open();

        self::assertCount(0, $crawler->filter('.map-plate, .viewer'));
        $card = $crawler->filter('[data-w="live"]');
        self::assertCount(1, $card);
        self::assertStringContainsString('The live plate', $card->text());
    }

    /** THE CATALOGUE AND THE PAGE AGREE ABOUT WHAT IS SHIPPED ON. */
    public function testTheCatalogueIsWhatThePageDraws(): void
    {
        self::assertSame(
            ['kpis' => 12, 'decisions' => 12, 'stations' => 12, 'live' => 12],
            RosterWidgets::declaration()->defaultLayout(),
        );
    }
}
