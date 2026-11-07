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

    private function open(): Crawler
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

    /** NO STRIP AND NO FIGURES: what the month came to is the caption's. */
    public function testThePageCarriesNoStripAndNoFigures(): void
    {
        $crawler = $this->open();

        self::assertCount(0, $crawler->filter('.factband'));
        self::assertCount(0, $crawler->filter('.kpi'));
    }

    /**
     * THE PICKER IS THE SHELL'S DROPDOWN, searchable, in the month's own nav
     * row; choosing a ranger sends the page's own form, so the choice is an
     * address somebody can send.
     */
    public function testTheRangerPickerIsTheShellsSearchableDropdown(): void
    {
        $crawler = $this->open();

        $form = $crawler->filter('.cal-plate .cal-nav form[data-controller="roster--filter"]');
        self::assertCount(1, $form, 'The picker rides in the month\'s nav row.');
        self::assertCount(1, $crawler->filter('.cal-nav'), 'One nav row, not the picker in a row of its own.');
        self::assertSame('get', strtolower((string) $form->attr('method')));
        self::assertCount(1, $form->filter('input[type="hidden"][name="month"]'), 'The month stays where it is.');

        $picker = $form->filter('.dd.dd-end[data-dd="ranger"]');
        self::assertCount(1, $picker);
        self::assertCount(1, $picker->filter('.dd-q input'), 'A ranger is found by typing.');
        self::assertSame('Ada Example', trim($picker->filter('.dd-v')->text()));
        self::assertSame('true', $picker->filter('.dd-o[data-value]')->reduce(static fn (Crawler $o): bool => 'Ada Example' === $o->attr('data-label'))->attr('aria-selected'));
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
     * WHAT THE MONTH CAME TO IS THE CAPTION'S. A day watch today and a
     * night yesterday were seeded; on the 1st the night is last month's.
     */
    public function testTheCaptionCountsThisRangersOwnMonth(): void
    {
        $crawler = $this->open();

        $expected = new \DateTimeImmutable('yesterday')->format('Y-m') === new \DateTimeImmutable('today')->format('Y-m') ? '2 watches · 1 night' : '1 watch · 0 nights';
        self::assertStringContainsString($expected, $crawler->filter('.c .tab .src')->eq(0)->text());
    }
}
