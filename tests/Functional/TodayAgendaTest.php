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
        // AND ONE ON TOMORROW, so the second card draws a watch that is DUE
        // rather than only the hole under it.
        $this->em->persist(new Duty($this->area, $gate, $ranger, 'day', new \DateTimeImmutable('tomorrow')));
        $this->em->flush();
    }

    private function open(): \Symfony\Component\DomCrawler\Crawler
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
     * THE DAY'S CARD IS BOUNDED AND SCROLLS INSIDE ITSELF — RULED 25 sep,
     * the sheet's own height rule; the tab stays above the scroll.
     */
    public function testTheDaysCardIsBoundedAndScrollsInsideItself(): void
    {
        $crawler = $this->open();

        $card = $crawler->filter('.c[data-controller="roster--bound"]');
        self::assertCount(1, $card, 'The day is the one bounded card; tomorrow is not asked for.');
        self::assertSame('roster--bound', $card->attr('data-controller'));
        self::assertStringStartsWith('Today', trim($card->filter('.tab')->text()));

        $scroller = $card->filter('.rscroll');
        self::assertCount(1, $scroller);
        self::assertSame('scroller', $scroller->attr('data-roster--bound-target'));
        self::assertCount(1, $scroller->filter('.r-ag'), 'The agenda is what scrolls.');
        self::assertCount(0, $scroller->filter('.tab'), 'The head stays outside the scroll.');
    }

    /** THE FILTER ROW: three grouped dropdowns and the search, all closed. */
    public function testTheFilterRowIsThreeClosedDropdownsAndASearch(): void
    {
        $crawler = $this->open();

        $row = $crawler->filter('form.lfilt');
        self::assertCount(1, $row, 'The house filter row, not a bespoke one.');

        $dropdowns = $row->filter('details.i-dd');
        self::assertCount(3, $dropdowns, 'Post, check-in state, shift.');

        $dropdowns->each(static function (\Symfony\Component\DomCrawler\Crawler $one): void {
            self::assertNull($one->attr('open'), 'A filter opens when it is asked to, never by default.');
            self::assertCount(1, $one->filter('summary.mchip.i-ddt'), 'The chip IS the summary — the browser opens it.');
            self::assertGreaterThan(1, $one->filter('a.i-ddopt')->count(), 'Every option is a real link.');
        });

        self::assertCount(1, $row->filter('.lsearch input[name="q"]'));
    }

    /** EVERY OPTION IS A LINK THAT ACTUALLY NARROWS THE PAGE. */
    public function testChoosingAPostNarrowsTheAgendaAndLeavesTheFiguresAlone(): void
    {
        $crawler = $this->open();

        $before = $crawler->filter('[data-kpi="check-ins-in"] .disp')->text();

        $link = $crawler->filter('form.lfilt details.i-dd')->eq(0)->filter('a.i-ddopt')->eq(1)->attr('href');
        self::assertIsString($link);
        $narrowed = $this->client->request('GET', $link);
        self::assertResponseIsSuccessful();

        // The FIRST agenda is the day's; the second is tomorrow's and is
        // not what the filter narrows.
        self::assertCount(1, $narrowed->filter('.r-ag')->eq(0)->filter('.r-ag-post'), 'One post chosen, one post drawn.');
        self::assertSame(
            $before,
            $narrowed->filter('[data-kpi="check-ins-in"] .disp')->text(),
            'The figures are the whole day\'s — narrowing must not quietly rewrite them.',
        );
    }

    /**
     * THE FOUR KPI CARDS the design names, in its order — four and never
     * five, ruled. Verified is a FRAGMENT of check-ins in here: how many
     * came in and how many of them the pings bore out is one thought.
     */
    public function testTheStripDrawsTheDesignsFourCards(): void
    {
        $crawler = $this->open();

        $cards = $crawler->filter('.kstrip .c.kpi');
        self::assertCount(4, $cards, 'A KPI row is four cards, never five.');

        self::assertSame(
            ['on-the-watch', 'check-ins-in', 'needs-an-answer', 'holes'],
            $cards->each(static fn (\Symfony\Component\DomCrawler\Crawler $card): string => (string) $card->attr('data-kpi')),
        );
    }

    /** BOTH SECTIONS, LABELLED, in the design's order. */
    public function testTheDayAndTomorrowAreTwoLabelledSections(): void
    {
        $crawler = $this->open();

        self::assertSame(
            ['Today', 'Tomorrow'],
            $crawler->filter('h2.zone')->each(static fn (\Symfony\Component\DomCrawler\Crawler $h): string => trim($h->text())),
        );

        self::assertCount(2, $crawler->filter('.r-ag'), 'One agenda each.');
    }

    /**
     * THE ROW SHAPE: the initials, the name and its role line, the shift
     * chip, the fact, the state and how old the last position is.
     */
    public function testAnAgendaLineCarriesTheDesignsSixParts(): void
    {
        $crawler = $this->open();

        $line = $crawler->filter('.r-ag .r-ag-line')->eq(0);

        self::assertCount(1, $line->filter('.av'), 'The initials.');
        self::assertCount(1, $line->filter('.nm em'), 'The name over its shift.');
        self::assertCount(1, $line->filter('.r-sh'), 'The watch chip.');
        self::assertCount(1, $line->filter('.why'), 'The one fact that makes it answerable.');
        self::assertCount(1, $line->filter('.r-ci'), 'What the area read.');
        self::assertCount(1, $line->filter('.r-ping'), 'How old the last position is.');
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
            static fn (\Symfony\Component\DomCrawler\Crawler $card): bool => str_contains($card->filter('.tab')->count() > 0 ? $card->filter('.tab')->text() : '', 'Tomorrow'),
        );
        self::assertGreaterThan(0, $tomorrow->count());

        self::assertStringNotContainsString('no check-in', $tomorrow->text());
        self::assertStringContainsString('due', $tomorrow->text());
    }
}
