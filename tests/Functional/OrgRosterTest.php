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

    /** TODAY NAMES THE AREA ON EVERY ROW. */
    public function testTodayNamesTheAreaOnEveryRow(): void
    {
        $rows = $this->open(RosterOrgController::TODAY_ROUTE)->filter('table.tbl tr')->slice(1);

        self::assertGreaterThan(0, $rows->count());
        $rows->each(static function (Crawler $row): void {
            self::assertCount(1, $row->filter('.orgarea'), 'A row at this scope says which area it is about.');
        });
    }

    /** AND LIVE DRAWS ONE PLATE, with the map sheet the page owes it. */
    public function testLiveDrawsOnePlateAndLinksTheMapSheet(): void
    {
        $live = $this->open(RosterOrgController::LIVE_ROUTE);

        self::assertSame(
            ['The plate first', 'Needs an answer, everywhere'],
            $live->filter('h2.zone')->each(static fn (Crawler $h): string => trim($h->text())),
        );

        $sheets = $live->filter('link[rel="stylesheet"]')->each(static fn (Crawler $l): string => (string) $l->attr('href'));
        self::assertNotEmpty(array_filter($sheets, static fn (string $href): bool => str_contains($href, 'map')));
    }
}
