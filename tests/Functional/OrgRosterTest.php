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
use Uhifadhi\Roster\Controller\RosterOrgController;
use Uhifadhi\Roster\Entity\Duty;
use Uhifadhi\Roster\Module\RosterModuleProvider;
use Uhifadhi\Roster\Service\StationWatchService;
use Uhifadhi\Roster\Tests\FreshDatabase;
use Uhifadhi\Roster\Tests\Integration\Fixtures\FixedManageVoter;

/**
 * THE ROSTER AT ORGANIZATION SCOPE — three screens, read across every area.
 *
 * WHAT IS ASSERTED IS THE WIDENING. Every figure on these pages has to be
 * the area query with the area filter widened, so the tests that matter
 * compare the organization's number against the sum of the areas' own —
 * a second aggregate would pass a "does it render" test and fail this one.
 *
 * AND WHICH AREA IS A COLUMN. At this scope every row has to say which area
 * it is about, with the area's POSITION in the declared order and never a
 * colour of its own.
 */
final class OrgRosterTest extends WebTestCase
{
    use EveryAreaRunsTheRoster;

    use FreshDatabase;
    use ReadsTheLiveStream;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    /** @var list<AreaOfInterest> */
    private array $areas = [];

    protected function setUp(): void
    {
        $this->client = self::createClient();
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        $this->em = $em;

        self::freshDatabase($this->em);

        $this->em->persist(new User()->setPassword('x')->setEmail(FixedManageVoter::MANAGER_EMAIL)->setFirstName('Mara')->setLastName('Manager'));

        // TWO AREAS, because every figure on these pages is wrong in a way
        // one area hides: a sum over one is the thing itself.
        foreach (['north reserve', 'south reserve'] as $index => $name) {
            $area = new AreaOfInterest()->setSource('test fixture')->setName($name)->setGeom(
                \sprintf('{"type":"MultiPolygon","coordinates":[[[[%1$d.2,-5.8],[%1$d.5,-5.8],[%1$d.5,-5.5],[%1$d.2,-5.5],[%1$d.2,-5.8]]]]}', 12 + $index),
            );
            $this->em->persist($area);
            $this->areas[] = $area;

            $gate = new Station()
                ->setArea($area)
                ->setName($name.' gate')
                ->setCode(\sprintf('ST-0%d', $index + 1))
                ->setPoint(\sprintf('{"type":"Point","coordinates":[%d.3,-5.7]}', 12 + $index));
            $this->em->persist($gate);
            $this->em->flush();

            $this->everyAreaRunsTheRoster($this->em);

            $watches = static::getContainer()->get('test_public.'.StationWatchService::class);
            self::assertInstanceOf(StationWatchService::class, $watches);
            $watches->addToRoster($gate)->expect(['day']);

            $ranger = new User()->setPassword('x')->setEmail(\sprintf('ranger%d@example.test', $index))->setFirstName('R'.$index)->setLastName('Example');
            $this->em->persist($ranger);
            $this->em->persist(new Duty($area, $gate, $ranger, 'day', new \DateTimeImmutable('today')));
        }

        $this->em->flush();
    }

    /** @param array<string, string> $query */
    private function open(string $route, array $query = []): Crawler
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => FixedManageVoter::MANAGER_EMAIL]);
        self::assertInstanceOf(User::class, $user);
        $this->client->loginUser($user);

        $router = static::getContainer()->get('router');
        self::assertInstanceOf(\Symfony\Component\Routing\RouterInterface::class, $router);

        $crawler = $this->client->request('GET', $router->generate($route, $query));
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /** THE STRIP IS THREE, and the three the provider names. */
    public function testTheStripIsTheThreeScreensTheModuleContributes(): void
    {
        $tabs = $this->open(RosterOrgController::OVERVIEW_ROUTE)->filter('.atabs a');

        self::assertSame(
            ['Overview', 'Today', 'Live'],
            $tabs->each(static fn (Crawler $tab): string => trim($tab->text())),
            'The week plan, the day board and the calendar are read one area at a time and are deliberately not here.',
        );

        self::assertSame(
            ['overview', 'today', 'live'],
            array_map(static fn (\Uhifadhi\Contracts\Shell\OrgPage $page): string => $page->key, new RosterModuleProvider('operations')->orgPages()),
            'And the strip is built from the same declaration the shell mounts.',
        );
    }

    /**
     * THE ORGANIZATION'S FIGURE IS THE AREAS' OWN, ADDED — not a second
     * aggregate that happens to agree today.
     */
    public function testEveryFigureIsTheAreaQueryOneScopeWider(): void
    {
        // POSTS ON THE BOOKS is the figure this fixture certainly produces
        // — one watched post in each area — and it is folded exactly as
        // every other figure on the page is, so it proves the widening.
        $org = $this->open(RosterOrgController::OVERVIEW_ROUTE);
        $orgPosts = self::postsOnTheBooks($org);

        $summed = 0;
        foreach ($this->areas as $area) {
            $summed += self::postsOnTheBooks($this->open(RosterOrgController::OVERVIEW_ROUTE, ['area' => (string) $area->getUuidString()]));
        }

        self::assertSame(2, $orgPosts, 'The fixture puts one post on the books in each of two areas.');
        self::assertSame($orgPosts, $summed, 'The organization figure is the areas added, so it cannot drift from them.');
    }

    /** What the "posts reporting" card says it is counting against. */
    private static function postsOnTheBooks(Crawler $page): int
    {
        return (int) filter_var($page->filter('[data-kpi="posts-reporting"] .disp em')->text(), \FILTER_SANITIZE_NUMBER_INT);
    }

    /** NARROWING THE SCOPE IS THE SAME PAGE, one area wide. */
    public function testNarrowingTheScopeDrawsTheSamePageForOneArea(): void
    {
        $one = $this->open(RosterOrgController::OVERVIEW_ROUTE, ['area' => (string) $this->areas[0]->getUuidString()]);

        self::assertCount(1, $one->filter('.orgband'), 'One area in scope is one band.');
        self::assertStringContainsString('north reserve', $one->filter('.orgband')->text());
    }

    /** EVERY ROW SAYS WHICH AREA, by position and never by a colour. */
    public function testEveryBandNamesItsAreaByPositionAndNotByColour(): void
    {
        $bands = $this->open(RosterOrgController::OVERVIEW_ROUTE)->filter('.orgband .orgarea');

        self::assertCount(2, $bands);

        foreach ($bands as $index => $band) {
            $swatch = new Crawler($band)->attr('data-cat');
            self::assertSame((string) ($index + 1), $swatch, 'The swatch is the area’s place in the declared order.');
        }
    }

    /** TODAY NAMES THE AREA ON EVERY ROW, in the design's columns. */
    public function testTodayNamesTheAreaOnEveryRow(): void
    {
        $table = $this->open(RosterOrgController::TODAY_ROUTE)->filter('.c table.tbl');
        self::assertSame(
            ['Area', 'Ranger', 'Post', 'Shift', 'Check-in', 'State', 'Ping age', ''],
            $table->filter('thead th')->each(static fn (Crawler $th): string => trim($th->text())),
        );

        $rows = $table->filter('tbody tr');
        self::assertGreaterThan(0, $rows->count());
        $rows->each(static function (Crawler $row): void {
            self::assertCount(1, $row->filter('.orgarea'), 'A row at this scope says which area it is about.');
        });
    }

    /**
     * TODAY'S FILTER ROW is the shell's three dropdowns, area, state and
     * shift, and closes with what the day came to; choosing an area keeps
     * that area's rows only.
     */
    public function testTodaysFilterRowNarrowsTheRows(): void
    {
        $crawler = $this->open(RosterOrgController::TODAY_ROUTE);

        $row = $crawler->filter('.c div.lfilt');
        self::assertCount(1, $row);
        self::assertCount(0, $row->filter('.dd'));
        self::assertCount(0, $crawler->filter('[data-controller~="roster--filter"]'));
        $chips = $row->filter('details.i-dd summary.mchip.i-ddt .i-ddval')->each(static fn (Crawler $v): string => html_entity_decode(trim($v->text())));
        self::assertCount(3, $chips);
        self::assertMatchesRegularExpression('/^all areas · \d+$/', $chips[0]);
        self::assertMatchesRegularExpression('/^every state · \d+$/', $chips[1]);
        self::assertMatchesRegularExpression('/^every shift · \d+$/', $chips[2]);
        self::assertCount(0, $row->filter('.i-ddhead'));
        self::assertCount(1, $row->filter('form.lsearch input[name="q"]'));
        self::assertMatchesRegularExpression('/^\d+ due · \d+ checked in · \d+ need an answer$/', html_entity_decode(trim($row->filter('.lfilt-n')->text())));
        self::assertCount(0, $crawler->filter('.factband'), 'The band is the Overview\'s.');

        $first = (string) $this->areas[0]->getName();
        $narrowed = $this->open(RosterOrgController::TODAY_ROUTE, ['area' => $first]);
        $names = array_unique($narrowed->filter('.c table.tbl tbody .orgarea')->each(static fn (Crawler $a): string => trim($a->text())));
        self::assertSame([$first], array_values($names));
        $chip = $narrowed->filter('.lfilt details.i-dd summary')->first();
        self::assertStringStartsWith(mb_strtolower($first).' · ', html_entity_decode(trim($chip->filter('.i-ddval')->text())));
        self::assertStringContainsString(' on', (string) $chip->attr('class'));

        $nobody = $this->open(RosterOrgController::TODAY_ROUTE, ['q' => 'nobody by this name']);
        self::assertCount(0, $nobody->filter('.c table.tbl tbody .orgarea'));
    }

    /** LIVE'S RANGERS CARRY THE SAME BAR, and choosing an area keeps its rows only. */
    public function testLivesRangersCarryTheHouseBar(): void
    {
        $crawler = $this->open(RosterOrgController::LIVE_ROUTE);

        $row = $crawler->filter('.c div.lfilt');
        self::assertCount(1, $row);
        self::assertCount(3, $row->filter('details.i-dd summary.mchip.i-ddt'));
        self::assertCount(1, $row->filter('form.lsearch input[name="q"]'));
        $row->filter('a.i-ddopt')->each(static function (Crawler $option): void {
            self::assertStringContainsString('/roster/live', (string) $option->attr('href'));
        });

        $first = (string) $this->areas[0]->getName();
        $narrowed = $this->open(RosterOrgController::LIVE_ROUTE, ['area' => $first]);
        $names = array_unique($narrowed->filter('.c table.tbl tbody .orgarea')->each(static fn (Crawler $a): string => trim($a->text())));
        self::assertSame([$first], array_values($names));
        self::assertCount(1, $narrowed->filter('.rdf-foot'), 'The foot stays under the table.');
    }

    /** WHAT NEEDS AN ANSWER IS THE BANNER on Today and Live; on the Overview it needs a decision. */
    public function testAttentionIsTheBannerOnEveryOrgPage(): void
    {
        foreach ([[RosterOrgController::OVERVIEW_ROUTE, 'decision', 'Today'], [RosterOrgController::TODAY_ROUTE, 'answer', 'Live'], [RosterOrgController::LIVE_ROUTE, 'answer', 'Today']] as [$route, $word, $door]) {
            $banner = $this->open($route)->filter('.banner');
            self::assertCount(1, $banner, $route);
            self::assertMatchesRegularExpression('/^\d+ needs? an? '.$word.', in \d+ areas?$/', trim($banner->filter('.banner-t > b')->text()), $route);
            self::assertSame($door, trim($banner->filter('.banner-acts a.btn')->text()), $route);
        }
        self::assertCount(0, $this->open(RosterOrgController::OVERVIEW_ROUTE)->filter('[data-w="decisions"]'), 'No card says it again.');
    }

    /** THE ORGANIZATION'S LIVE PAGE STREAMS every area in scope, one topic each. */
    public function testLiveStreamsEveryAreaInScope(): void
    {
        $this->open(RosterOrgController::LIVE_ROUTE);

        self::assertStreamsTheAreas($this->client, array_map(static fn ($a): string => (string) $a->getUuidString(), $this->areas));
    }

    /**
     * THE OVERVIEW DRAWS NO PLATE (ruled 2026-09-26). The organization's
     * marks are the dashboard's to draw; the roster keeps its lists and a
     * door to its Live page on the figures.
     */
    public function testTheOverviewDrawsNoPlateAndItsFiguresOpenTheLivePage(): void
    {
        $overview = $this->open(RosterOrgController::OVERVIEW_ROUTE);

        self::assertCount(0, $overview->filter('.map-plate, .viewer'));
        self::assertCount(0, $overview->filter('[data-w="map"]'));
        $router = static::getContainer()->get('router');
        self::assertInstanceOf(\Symfony\Component\Routing\RouterInterface::class, $router);
        self::assertCount(1, $overview->filter('[data-w="kpis"] a.more[href="'.$router->generate(RosterOrgController::LIVE_ROUTE).'"]'));
    }

    /**
     * AND LIVE IS TWO COLUMNS: the map, and the rangers of every area beside
     * it, with the map sheet the page owes it.
     */
    public function testLiveDrawsTheMapAndTheRangersBesideIt(): void
    {
        $live = $this->open(RosterOrgController::LIVE_ROUTE);

        self::assertCount(0, $live->filter('h2.zone'));
        $columns = $live->filter('.grid.g32 > .c');
        self::assertCount(2, $columns);
        self::assertStringStartsWith('Every area, live', trim($columns->eq(0)->filter('.tab')->text()));
        self::assertStringStartsWith('Rangers, every area', trim($columns->eq(1)->filter('.tab')->text()));
        self::assertSame(
            ['Area', 'Ranger', 'Post', 'Presence', 'Ping age'],
            $columns->eq(1)->filter('table.tbl thead th')->each(static fn (Crawler $th): string => trim($th->text())),
        );

        $sheets = $live->filter('link[rel="stylesheet"]')->each(static fn (Crawler $l): string => (string) $l->attr('href'));
        self::assertNotEmpty(array_filter($sheets, static fn (string $href): bool => str_contains($href, 'map')));
    }

    /** THE ORGANIZATION'S TODAY AND LIVE TABLES CARRY THE REGISTER FOOT. */
    public function testTheOrgTablesCarryTheRegisterFoot(): void
    {
        foreach ([RosterOrgController::TODAY_ROUTE, RosterOrgController::LIVE_ROUTE] as $route) {
            $foot = $this->open($route)->filter('.c table.tbl + .rdf-foot');
            self::assertCount(1, $foot, $route);
            self::assertSame('page 1 of 1', trim($foot->filter('.rdf-page')->text()), $route);
            self::assertStringContainsString('rangers_per=50', (string) $foot->filter('.rdf-size a.i-ddopt')->eq(1)->attr('href'), $route);
        }
    }
}
