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

/**
 * THE DAY BOARD, MEASURED AGAINST ITS DESIGN — modules/roster/board.html.
 *
 * The page is the band, the filter row, five figures, THE DAY HOUR BY HOUR,
 * and WHO IS ACTUALLY ON THOSE POSTS. Each was a coverage-manifest line.
 *
 * THE NOW LINE IS THE REASON HALF OF THIS EXISTS. It carries no position
 * from the server — the viewer's clock places it — so what a server-side
 * test can hold is that the element is there, that it is hidden until
 * something places it, and that it names the day it belongs to. The maths
 * and the ticking are pinned by the seam test beside this one.
 */ final class DayBoardTest extends WebTestCase
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
        // AND A NIGHT WATCH THAT BEGAN YESTERDAY, which is the case the
        // wall exists to draw: it is still standing at 05:00 this morning,
        // so it is the first block of today's row as well as the last of
        // yesterday's.
        $this->em->persist(new Duty($this->area, $gate, $ranger, 'night', new \DateTimeImmutable('yesterday')));
        $this->em->flush();
    }

    private function open(): \Symfony\Component\DomCrawler\Crawler
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => FixedManageVoter::MANAGER_EMAIL]);
        self::assertInstanceOf(User::class, $user);
        $this->client->loginUser($user);

        $router = static::getContainer()->get('router');
        self::assertInstanceOf(\Symfony\Component\Routing\RouterInterface::class, $router);

        $crawler = $this->client->request('GET', $router->generate(RosterController::BOARD_ROUTE, ['uuid' => (string) $this->area->getUuidString()]));
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
            ['rostered-now', 'verified', 'needs-an-answer', 'posts-reporting'],
            $cards->each(static fn (\Symfony\Component\DomCrawler\Crawler $card): string => (string) $card->attr('data-kpi')),
        );
    }

    /** THE FILTER ROW, the same one the agenda wears, pointed at this page. */
    public function testItWearsTheSameFilterRowAsTheAgenda(): void
    {
        $crawler = $this->open();

        $row = $crawler->filter('form.lfilt');
        self::assertCount(1, $row);
        self::assertCount(3, $row->filter('details.i-dd'));
        self::assertCount(1, $row->filter('.lsearch input[name="q"]'));

        self::assertStringContainsString(
            '/board',
            (string) $row->attr('action'),
            'The row re-queries the page it is on, not the agenda.',
        );
    }

    /** BOTH SECTIONS, LABELLED, in the design's words and order. */
    public function testTheTwoSectionsAreLabelledAsTheDesignNamesThem(): void
    {
        $crawler = $this->open();

        self::assertSame(
            ['The day, hour by hour', 'Who is actually on those posts'],
            $crawler->filter('h2.zone')->each(static fn (\Symfony\Component\DomCrawler\Crawler $h): string => trim($h->text())),
        );
    }

    /**
     * THE WALL, ITS HOUR SCALE AND ITS BLOCKS — and the line that the
     * VIEWER places. The server writes the day and no position at all.
     */
    public function testTheWallCarriesTheHourScaleAndANowLineTheViewerPlaces(): void
    {
        $crawler = $this->open();

        self::assertCount(1, $crawler->filter('.r-day'));
        self::assertSame(
            ['00', '03', '06', '09', '12', '15', '18', '21'],
            $crawler->filter('.r-ruler .hrs span')->each(static fn (\Symfony\Component\DomCrawler\Crawler $h): string => trim($h->text())),
        );

        $line = $crawler->filter('.r-now');
        self::assertCount(1, $line, 'The line exists whatever the day.');
        self::assertNotNull($line->attr('hidden'), 'And is hidden until the browser places it.');
        self::assertNull($line->attr('style'), 'The server renders no position: it does not know what time it is where the reader is.');
        self::assertSame(
            new \DateTimeImmutable('today')->format('Y-m-d'),
            $crawler->filter('.r-day')->attr('data-roster--now-line-day-value'),
            'It names the day it belongs to, so the browser can refuse to draw it on another one.',
        );
    }

    /**
     * THE LINE STAYS IN THE MIDDLE AND THE DAY MOVES UNDER IT — RULED 25
     * sep. The hours are wider than the window and scroll inside it; the
     * controller sits on the wall and holds the scroller, the axis the
     * line is placed along, and the line itself.
     */
    public function testTheDayScrollsUnderALineTheControllerCentres(): void
    {
        $crawler = $this->open();

        $wall = $crawler->filter('.r-day');
        self::assertSame('roster--now-line', $wall->attr('data-controller'));

        $scroller = $wall->filter('.r-dayscroll');
        self::assertCount(1, $scroller);
        self::assertSame('scroller', $scroller->attr('data-roster--now-line-target'));
        self::assertStringContainsString('scroll->roster--now-line#scrolled', (string) $scroller->attr('data-action'), 'A scroll by hand is noticed, so the board is not pulled back from under the reader.');

        $axis = $scroller->filter('.r-dayboard .r-daywrap > .r-nowlayer');
        self::assertCount(1, $axis, 'The line runs along the hours, not across the labels.');
        self::assertSame('axis', $axis->attr('data-roster--now-line-target'));
        self::assertSame('line', $axis->filter('.r-now')->attr('data-roster--now-line-target'));

        self::assertCount(1, $scroller->filter('.r-dayboard > .r-ruler'), 'The hour scale scrolls with the hours it names.');
    }

    /**
     * THE CARD IS BOUNDED AND SCROLLS INSIDE ITSELF — RULED 25 sep, the
     * sheet's own height rule, with the hour scale pinned. Thirty-two
     * posts made a 3,416px page.
     */
    public function testTheDayBoardCardIsBoundedWithTheHoursPinned(): void
    {
        $crawler = $this->open();

        $card = $crawler->filter('.c[data-controller="roster--bound"]')->reduce(static fn (\Symfony\Component\DomCrawler\Crawler $c): bool => $c->filter('.r-day')->count() > 0);
        self::assertCount(1, $card);
        self::assertSame('roster--bound', $card->attr('data-controller'));

        $scroller = $card->filter('.r-dayscroll');
        self::assertStringContainsString('rscroll', (string) $scroller->attr('class'));
        self::assertSame('scroller', $scroller->attr('data-roster--bound-target'));
    }

    /** AND SO IS "HERE NOW, AGAINST THE WATCH", with its tab above the scroll. */
    public function testTheHereNowCardIsBounded(): void
    {
        $crawler = $this->open();

        $card = $crawler->filter('.c[data-controller="roster--bound"]')->reduce(static fn (\Symfony\Component\DomCrawler\Crawler $c): bool => $c->filter('.r-preslist')->count() > 0);
        self::assertCount(1, $card);
        self::assertSame('roster--bound', $card->attr('data-controller'));
        self::assertStringStartsWith('Here now, against the watch', trim($card->filter('.tab')->text()));

        $list = $card->filter('.r-preslist');
        self::assertStringContainsString('rscroll', (string) $list->attr('class'));
        self::assertSame('scroller', $list->attr('data-roster--bound-target'));
        self::assertCount(0, $list->filter('.tab'), 'The head stays outside the scroll.');
    }

    /** A NIGHT WATCH IS TWO BLOCKS — the morning tail of last night's. */
    public function testTheMorningTailOfLastNightsWatchIsDrawn(): void
    {
        $crawler = $this->open();

        $blocks = $crawler->filter('.r-track .r-blk');
        self::assertGreaterThan(0, $blocks->count());

        $lefts = $blocks->each(static fn (\Symfony\Component\DomCrawler\Crawler $b): string => (string) $b->attr('style'));
        self::assertNotEmpty(array_filter($lefts, static fn (string $style): bool => str_contains($style, 'left:0')));
    }

    /**
     * THE SECOND CARD IS THE MEASUREMENT, and it keeps the plan and the
     * pings in two columns rather than folding them into one verdict.
     */
    public function testThePresenceCardKeepsRosteredAndMeasuredApart(): void
    {
        $crawler = $this->open();

        $rows = $crawler->filter('.r-preslist .r-pres');
        self::assertGreaterThan(0, $rows->count());

        $first = $rows->eq(0);
        self::assertCount(1, $first->filter('.nm'), 'Which post.');
        self::assertCount(1, $first->filter('.who'), 'What the pings say about its people.');
        self::assertCount(1, $first->filter('.cnt'), 'And how many of its expectation they bear out.');
    }
}
