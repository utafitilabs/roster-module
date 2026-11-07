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
 * THE AGENDA, MEASURED AGAINST ITS DESIGN — modules/roster/today.html.
 *
 * The page is the band, the filter row, five figures, TODAY and TOMORROW.
 * Every one of those was a coverage-manifest line, and each is asserted here
 * by the element the design draws rather than by the words in it.
 *
 * THE DROPDOWNS ARE `<details>` AND THAT IS THE POINT OF ASSERTING IT. A
 * filter rendered as a div is a filter rendered OPEN, which is what the
 * calendar's picker was doing; a details element is closed until somebody
 * opens it and needs no script of ours to be either.
 *
 * IT ASSERTS STRUCTURE, NOT LAYOUT. What exists, how many, in what order,
 * carrying which figures. Whether the strip lays out in one row is the
 * browser's answer and is measured against the design by eye, not here.
 */
final class TodayAgendaTest extends WebTestCase
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

        $crawler = $this->client->request('GET', $router->generate(RosterController::TODAY_ROUTE, ['uuid' => (string) $this->area->getUuidString()]));
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /**
     * NO STRIP AND NO FIGURES. A strip and a row of figures belong to an
     * Overview; this is a list page, and the figures live on the tab that
     * owns them.
     */
    public function testThePageCarriesNoStripAndNoFigures(): void
    {
        $crawler = $this->open();

        self::assertCount(0, $crawler->filter('.factband'), 'The band is the Overview\'s.');
        self::assertCount(0, $crawler->filter('.kpi'), 'The figures are the Overview\'s.');
    }

    /**
     * THE INDEX NAMES THE TWO CARDS, one row each, and each row takes the
     * reader to its card.
     */
    public function testTheIndexListsTodayAndTomorrowOneRowPerCard(): void
    {
        $crawler = $this->open();

        $rows = $crawler->filter('.onpage-nav a.onpage-row');
        self::assertSame(['#today', '#tomorrow'], $rows->each(static fn (Crawler $row): string => (string) $row->attr('href')));
        self::assertSame(['Today', 'Tomorrow'], $rows->each(static fn (Crawler $row): string => trim($row->filter('.onpage-l')->text())));
        self::assertCount(1, $crawler->filter('.onpage-main > #today.c'));
        self::assertCount(1, $crawler->filter('.onpage-main > #tomorrow.c'));
    }

    public function testTheFilterRowIsTheHouseBar(): void
    {
        $crawler = $this->open();

        $row = $crawler->filter('.onpage-main > div.lfilt');
        self::assertCount(1, $row, 'The row sits above both cards, inside the index\'s column.');
        self::assertCount(0, $row->filter('.dd'), 'No axis is the shell\'s scripted dropdown.');
        self::assertCount(0, $crawler->filter('[data-controller~="roster--filter"]'));
        $chips = $row->filter('details.i-dd > summary.mchip.i-ddt .i-ddval')->each(static fn (Crawler $v): string => html_entity_decode(trim($v->text())));
        self::assertCount(3, $chips);
        self::assertMatchesRegularExpression('/^all posts with a watch · \d+$/', $chips[0]);
        self::assertMatchesRegularExpression('/^every state · \d+$/', $chips[1]);
        self::assertMatchesRegularExpression('/^all shifts · \d+$/', $chips[2]);
        self::assertCount(0, $row->filter('summary.on'), 'Nothing narrows while nothing is chosen.');
        self::assertCount(0, $row->filter('.i-ddhead'), 'A single-group menu has no head.');
        $row->filter('.i-ddmenu .i-ddopt')->each(static function (Crawler $option): void {
            self::assertSame('a', $option->nodeName());
            self::assertStringContainsString('/modules/roster/today', (string) $option->attr('href'));
        });
        $search = $row->filter('form.lsearch[method="get"]');
        self::assertCount(1, $search);
        self::assertSame('Find a ranger or a post…', $search->filter('input[name="q"]')->attr('placeholder'));
    }

    public function testChoosingAPostNarrowsBothDays(): void
    {
        $this->open();
        $other = new Station()->setArea($this->area)->setName('south gate post')->setCode('ST-02')->setPoint('{"type":"Point","coordinates":[12.4,-5.6]}');
        $this->em->persist($other);
        $this->em->flush();
        $watches = static::getContainer()->get('test_public.'.StationWatchService::class);
        self::assertInstanceOf(StationWatchService::class, $watches);
        $watches->addToRoster($other)->expect(['day']);

        $crawler = $this->open();
        self::assertSame(['north gate post', 'south gate post'], $this->stationsIn($crawler), 'Both posts drawn while nothing is chosen.');

        $gate = $this->em->getRepository(Station::class)->findOneBy(['code' => 'ST-01']);
        self::assertInstanceOf(Station::class, $gate);
        $narrowed = $this->client->request('GET', $this->client->getRequest()->getUri().'?post='.$gate->getUuidString());

        self::assertSame(['north gate post'], $this->stationsIn($narrowed), 'One post chosen, one post drawn.');
        $chip = $narrowed->filter('.lfilt details.i-dd')->first()->filter('summary');
        self::assertMatchesRegularExpression('/^north gate post · \d+$/', html_entity_decode(trim($chip->filter('.i-ddval')->text())));
        self::assertStringContainsString(' on', (string) $chip->attr('class'), 'The chosen chip narrows.');
        self::assertCount(1, $narrowed->filter('.lfilt details.i-dd')->first()->filter('a.i-ddopt.on[href*="post='.$gate->getUuidString().'"]'));
        self::assertCount(3, $narrowed->filter('.lfilt details.i-dd')->first()->filter('a.i-ddopt'), 'Every post stays on offer, plus All.');
    }

    /**
     * The stations Today's rows name in their sub-lines, once each, in order.
     *
     * @return list<string>
     */
    private function stationsIn(Crawler $crawler): array
    {
        $names = $crawler->filter('#today tbody tr:not(.ro-none) td:first-child .sub')->each(static fn (Crawler $sub): string => explode(' · ', trim($sub->text()))[0]);

        return array_values(array_unique($names));
    }

    /**
     * BOTH DAYS ARE THE HOUSE TABLE, the columns the design names for each
     * day, and no row of either is a band: the station is every row's
     * sub-line.
     */
    public function testBothDaysAreHouseTablesWithTheDesignsColumns(): void
    {
        $crawler = $this->open();

        $today = $crawler->filter('#today table.tbl.ro-tbl');
        self::assertCount(1, $today);
        self::assertSame(['Ranger', 'Shift', 'Evidence', 'Check-in', 'Last ping'], $today->filter('thead th')->each(static fn (Crawler $th): string => trim($th->text())));
        self::assertCount(0, $crawler->filter('tr.sxgrp'), 'No band rows in either table.');

        $ranger = $today->filter('tbody tr[data-person]')->eq(0);
        self::assertSame('Ada Example', trim($ranger->filter('td')->eq(0)->children('b')->text()));
        self::assertSame('north gate post · ST-01', trim($ranger->filter('td')->eq(0)->filter('.sub')->text()));
        self::assertCount(1, $ranger->filter('td')->eq(1)->filter('.r-sh'), 'The watch chip.');
        self::assertCount(1, $ranger->filter('td')->eq(3)->filter('.r-ci'), 'What the area read.');
        self::assertCount(1, $ranger->filter('td')->eq(4)->filter('.r-ping'), 'How old the last position is.');

        $tomorrow = $crawler->filter('#tomorrow table.tbl.ro-tbl');
        self::assertSame(['Ranger', 'Shift', 'Note', 'Check-in'], $tomorrow->filter('thead th')->each(static fn (Crawler $th): string => trim($th->text())));
    }

    /**
     * A HOLE IS ITS OWN ROW, with the door that fills it. The gate asks for
     * a day and a night and only the day is rostered.
     */
    public function testAHoleIsARowWithItsDoor(): void
    {
        $crawler = $this->open();

        $hole = $crawler->filter('#today tr.ro-hole');
        self::assertCount(1, $hole);
        self::assertSame('Unfilled', trim($hole->filter('td')->eq(0)->children('b')->text()));
        self::assertSame('north gate post · ST-01 · 1 of 2', trim($hole->filter('td')->eq(0)->filter('.sub')->text()));
        self::assertSame('Fill it', trim($hole->filter('a.btn')->text()));
        self::assertCount(1, $crawler->filter('#today tr.ro-none[hidden]'), 'The no-match row waits, hidden.');
    }

    /**
     * TOMORROW HAS NO STATE, because nothing has been reported for a day
     * that has not happened. A card that drew "no check-in" against every
     * name on it would be accusing the whole park of tomorrow.
     */
    public function testTomorrowDrawsWatchesDueRatherThanStatesNobodyReported(): void
    {
        $crawler = $this->open();

        $tomorrow = $crawler->filter('.c')->reduce(
            static fn (Crawler $card): bool => str_contains($card->filter('.tab')->count() > 0 ? $card->filter('.tab')->text() : '', 'Tomorrow'),
        );
        self::assertGreaterThan(0, $tomorrow->count());

        self::assertStringNotContainsString('no check-in', $tomorrow->text());
        self::assertStringContainsString('due', $tomorrow->text());
    }

    /** BOTH DAYS CARRY THE REGISTER FOOT, even at one page. */
    public function testBothDaysCarryTheRegisterFoot(): void
    {
        $crawler = $this->open();

        foreach (['#today', '#tomorrow'] as $card) {
            $foot = $crawler->filter($card.' .rdf-foot');
            self::assertCount(1, $foot, $card);
            self::assertStringContainsString('20 per page', $foot->text(), $card);
            self::assertSame('page 1 of 1', trim($foot->filter('.rdf-page')->text()), $card);
        }
    }

    /**
     * TWENTY ROWS A PAGE, PAGED BY GET LINKS. Twenty-five watches at the
     * gate are two pages of the day; the arrow is a link to the second, and
     * the other day's table keeps its own page.
     */
    public function testTodayPagesTwentyRowsAtATimeByLinks(): void
    {
        $gate = $this->em->getRepository(Station::class)->findOneBy(['code' => 'ST-01']);
        self::assertInstanceOf(Station::class, $gate);
        for ($i = 1; $i <= 24; ++$i) {
            $ranger = new User()->setPassword('x')->setEmail(\sprintf('ranger%02d@example.test', $i))->setFirstName('Ranger')->setLastName(\sprintf('%02d', $i));
            $this->em->persist($ranger);
            $this->em->persist(new Duty($this->area, $gate, $ranger, 'day', new \DateTimeImmutable('today')));
        }
        $this->em->flush();

        $crawler = $this->open();
        self::assertCount(20, $crawler->filter('#today tbody tr:not(.ro-none)'));
        self::assertStringStartsWith('page 1 of 2', trim($crawler->filter('#today .rdf-page')->text()));
        self::assertStringContainsString('25 watches', $crawler->filter('#today .rdf-foot')->text());

        $next = $crawler->filter('#today .rdf-page a[aria-label="Next page"]');
        self::assertCount(1, $next);
        self::assertStringContainsString('today_page=2', (string) $next->attr('href'));

        $second = $this->client->request('GET', (string) $next->attr('href'));
        self::assertResponseIsSuccessful();
        self::assertCount(5, $second->filter('#today tbody tr:not(.ro-none)'));
        self::assertStringStartsWith('page 2 of 2', trim($second->filter('#today .rdf-page')->text()));
        self::assertSame('page 1 of 1', trim($second->filter('#tomorrow .rdf-page')->text()));

        $all = $this->client->request('GET', (string) $crawler->filter('#today .rdf-size a.i-ddopt')->last()->attr('href'));
        self::assertCount(25, $all->filter('#today tbody tr:not(.ro-none)'));
    }
}
