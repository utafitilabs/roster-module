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
 * THE CALENDAR, MEASURED AGAINST ITS DESIGN — modules/roster/calendar.html.
 *
 * The page is the band, five figures, a named section, and one ranger's
 * month in the HOUSE calendar. This module ships no month grid of its own,
 * so what is asserted here is what this module contributes: the figures, the
 * caption's fields, the picker, and the fact that the grid came from the
 * atlas.
 *
 * THE PICKER IS A `<details>` AND THAT IS THE POINT OF ASSERTING IT. It was
 * a div with a `data-dd` attribute and no toggle, which renders the panel
 * OPEN on a page with no script to close it — the defect the owner saw.
 */ final class CalendarTabTest extends WebTestCase
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

    private function open(): \Symfony\Component\DomCrawler\Crawler
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => FixedManageVoter::MANAGER_EMAIL]);
        self::assertInstanceOf(User::class, $user);
        $this->client->loginUser($user);

        $router = static::getContainer()->get('router');
        self::assertInstanceOf(\Symfony\Component\Routing\RouterInterface::class, $router);

        $crawler = $this->client->request('GET', $router->generate(RosterController::CALENDAR_ROUTE, ['uuid' => (string) $this->area->getUuidString()]));
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
            ['watches', 'verified', 'needs-an-answer', 'nights-in-a-row'],
            $cards->each(static fn (\Symfony\Component\DomCrawler\Crawler $card): string => (string) $card->attr('data-kpi')),
        );
    }

    /**
     * THE PICKER IS A CLOSED GROUPED DROPDOWN, not a panel rendered open,
     * and every ranger in it is a real link.
     */
    public function testTheRangerPickerIsAClosedDropdownOfRealLinks(): void
    {
        $crawler = $this->open();

        // IN THE MONTH'S OWN NAV ROW, through the atlas's controls slot:
        // one line, the stepper at the left and the picker at the right,
        // as the design draws it.
        $picker = $crawler->filter('.cal-plate .cal-nav details.i-dd');
        self::assertCount(1, $picker, 'A details element the browser opens — never a div that is always open.');
        self::assertCount(1, $crawler->filter('.cal-nav'), 'One nav row, not the picker in a row of its own.');
        self::assertNull($picker->attr('open'));
        self::assertCount(1, $picker->filter('summary.mchip.i-ddt'));

        $options = $picker->filter('a.i-ddopt');
        self::assertGreaterThan(0, $options->count());
        self::assertStringContainsString('ranger=', (string) $options->eq(0)->attr('href'), 'Choosing a ranger is a url somebody can send.');
    }

    /** THE MONTH STEPPER IS THE ATLAS'S, and it is on the page. */
    public function testTheMonthStepperAndTheGridAreTheAtlas(): void
    {
        $crawler = $this->open();

        self::assertCount(1, $crawler->filter('.cal-plate'), 'The month is the house component, not a grid of this module\'s.');
        self::assertGreaterThan(0, $crawler->filter('.cal-plate .cal-nav .mchip.on')->count(), 'The month chip.');
        self::assertCount(2, $crawler->filter('.cal-plate .cal-nav .mchip.ghost'), 'And an arrow each way.');
        self::assertGreaterThan(0, $crawler->filter('.cal .dc')->count(), 'The grid draws days.');
    }

    /** THE SECTION IS NAMED for whose month it is. */
    public function testTheSectionNamesTheRangerAndTheMonth(): void
    {
        $crawler = $this->open();

        $zone = $crawler->filter('h2.zone');
        self::assertCount(1, $zone);
        self::assertStringContainsString('Ada Example', $zone->text());
    }

    /**
     * THE CAPTION CARRIES THE DESIGN'S FIELDS — who, and what their month
     * came to. A month with only a name on it makes the reader check the
     * picker to know whose it is.
     */
    public function testTheCaptionCarriesWhoAndWhatTheMonthCameTo(): void
    {
        $crawler = $this->open();

        $caption = $crawler->filter('.c .tab .src')->eq(0)->text();

        self::assertStringContainsString('Ada Example', $caption);
        self::assertStringContainsString('north gate post', strtolower($caption), 'Where their watches were.');
        self::assertMatchesRegularExpression('/\d+ watch/', $caption);
        self::assertMatchesRegularExpression('/\d+ night/', $caption);
    }

    /**
     * THE FIGURES ARE ABOUT THIS PERSON'S MONTH. One watch today and one
     * night yesterday: two watches, one of them a night.
     */
    public function testTheFiguresCountThisRangersOwnMonth(): void
    {
        $crawler = $this->open();

        self::assertStringContainsString('3', $crawler->filter('[data-kpi="watches"] .disp')->text(), 'Three watches were seeded.');

        // THE NIGHT BEGAN YESTERDAY, which on the 1st is last month's - so
        // the run is read on the month that holds it.
        $nights = $this->client->request('GET', $this->client->getRequest()->getPathInfo().'?month='.new \DateTimeImmutable('yesterday')->format('Y-m'));
        self::assertSame('1', trim($nights->filter('[data-kpi="nights-in-a-row"] .disp')->text()), 'One night, so the longest run is one.');
    }
}
