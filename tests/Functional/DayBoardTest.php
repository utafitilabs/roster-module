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
        // AND A NIGHT WATCH THAT BEGAN YESTERDAY, which is the case the
        // wall exists to draw: it is still standing at 05:00 this morning,
        // so it is the first block of today's row as well as the last of
        // yesterday's.
        $this->em->persist(new Duty($this->area, $gate, $ranger, 'night', new \DateTimeImmutable('yesterday')));
        $this->em->flush();
    }

    private function open(): Crawler
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

    /** NO STRIP AND NO FIGURES: they are the Overview's. */
    public function testThePageCarriesNoStripAndNoFigures(): void
    {
        $crawler = $this->open();

        self::assertCount(0, $crawler->filter('.factband'));
        self::assertCount(0, $crawler->filter('.kpi'));
    }

    /** THE INDEX, one row per card: the board, then who is here now with its count. */
    public function testTheIndexNamesTheBoardAndHereNow(): void
    {
        $crawler = $this->open();

        $rows = $crawler->filter('.onpage-nav a.onpage-row');
        self::assertSame(['#board', '#presence'], $rows->each(static fn (Crawler $row): string => (string) $row->attr('href')));
        self::assertSame(['The day board', 'Here now'], $rows->each(static fn (Crawler $row): string => trim($row->filter('.onpage-l')->text())));
        self::assertSame('1', trim($rows->eq(1)->filter('.onpage-n')->text()), 'Here now counts the posts it lists.');
        self::assertCount(1, $crawler->filter('.onpage-main #board.c'));
        self::assertCount(1, $crawler->filter('.onpage-main #presence.c'));
    }

    /** THE FILTER ROW, the same one the agenda wears, pointed at this page. */
    public function testItWearsTheSameFilterRowAsTheAgenda(): void
    {
        $crawler = $this->open();

        $row = $crawler->filter('.onpage-main > div.lfilt');
        self::assertCount(1, $row);
        self::assertCount(0, $row->filter('.dd'));
        self::assertCount(3, $row->filter('details.i-dd > summary.mchip.i-ddt'));
        self::assertCount(0, $row->filter('.i-ddhead'));
        $row->filter('a.i-ddopt')->each(static function (Crawler $option): void {
            self::assertStringContainsString('/modules/roster/board', (string) $option->attr('href'));
        });
        self::assertCount(1, $row->filter('form.lsearch input[name="q"]'));

        self::assertStringContainsString(
            '/board',
            (string) $row->filter('form.lsearch')->attr('action'),
            'The row re-queries the page it is on, not the agenda.',
        );
    }

    /** BOTH SECTIONS, LABELLED, in the design's words and order. */
    public function testTheTwoSectionsAreLabelledAsTheDesignNamesThem(): void
    {
        $crawler = $this->open();

        self::assertSame(
            ['The day, hour by hour', 'Who is actually on those posts'],
            $crawler->filter('h2.zone')->each(static fn (Crawler $h): string => trim($h->text())),
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
            $crawler->filter('.r-ruler .hrs span')->each(static fn (Crawler $h): string => trim($h->text())),
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

        $card = $crawler->filter('.c[data-controller="roster--bound"]')->reduce(static fn (Crawler $c): bool => $c->filter('.r-day')->count() > 0);
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

        $card = $crawler->filter('#presence.c');
        self::assertSame('roster--bound', $card->attr('data-controller'));
        self::assertStringStartsWith('Here now, against the watch', trim($card->filter('.tab')->text()));

        $scroller = $card->filter('.rscroll');
        self::assertSame('scroller', $scroller->attr('data-roster--bound-target'));
        self::assertCount(1, $scroller->filter('table.tbl.ro-tbl'), 'The register is what scrolls.');
        self::assertCount(0, $scroller->filter('.tab'), 'The head stays outside the scroll.');
    }

    /** A NIGHT WATCH IS TWO BLOCKS — the morning tail of last night's. */
    public function testTheMorningTailOfLastNightsWatchIsDrawn(): void
    {
        $crawler = $this->open();

        $blocks = $crawler->filter('.r-track .r-blk');
        self::assertGreaterThan(0, $blocks->count());

        $lefts = $blocks->each(static fn (Crawler $b): string => (string) $b->attr('style'));
        self::assertNotEmpty(array_filter($lefts, static fn (string $style): bool => str_contains($style, 'left:0')));
    }

    /**
     * THE SECOND CARD IS THE MEASUREMENT, the house register: which post,
     * what the pings say about its people, and how many of its expectation
     * they bear out — three columns, never one verdict.
     */
    public function testThePresenceRegisterKeepsRosteredAndMeasuredApart(): void
    {
        $crawler = $this->open();

        $table = $crawler->filter('#presence table.tbl.ro-tbl');
        self::assertSame(['Post', 'Who is there', 'Verified'], $table->filter('thead th')->each(static fn (Crawler $th): string => trim($th->text())));

        $row = $table->filter('tbody tr')->eq(0);
        self::assertSame('north gate post', trim($row->filter('td')->eq(0)->filter('b')->text()));
        self::assertSame('ST-01', trim($row->filter('td')->eq(0)->filter('.sub')->text()));
        self::assertStringContainsString('num', (string) $row->filter('td')->eq(2)->attr('class'));
        self::assertCount(1, $table->filter('tr.ro-none[hidden]'));
    }

    /** HERE NOW CARRIES THE REGISTER FOOT under its scroll, paged by its own keys. */
    public function testHereNowCarriesTheRegisterFoot(): void
    {
        $card = $this->open()->filter('#presence.c');

        $foot = $card->filter('.rdf-foot');
        self::assertCount(1, $foot);
        self::assertCount(0, $card->filter('.rscroll .rdf-foot'), 'The foot stays outside the scroll.');
        self::assertSame('page 1 of 1', trim($foot->filter('.rdf-page')->text()));
        self::assertStringContainsString('here_per=50', (string) $foot->filter('.rdf-size a.i-ddopt')->eq(1)->attr('href'));
    }
}
