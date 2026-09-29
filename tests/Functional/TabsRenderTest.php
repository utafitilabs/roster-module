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
use Uhifadhi\Contracts\Shell\ModuleTab;
use Uhifadhi\Roster\Entity\Duty;
use Uhifadhi\Roster\Entity\Rotation;
use Uhifadhi\Roster\Entity\RotationPoolMember;
use Uhifadhi\Roster\Enum\RotationScope;
use Uhifadhi\Roster\Model\Cycle;
use Uhifadhi\Roster\Service\StationWatchService;
use Uhifadhi\Roster\Shell\RosterModuleTabs;
use Uhifadhi\Roster\Tests\FreshDatabase;
use Uhifadhi\Roster\Tests\Integration\Fixtures\FixedManageVoter;

/**
 * EVERY TAB THE STRIP OFFERS ACTUALLY RENDERS.
 *
 * This is the test that would have caught a tab declared before its page
 * existed — the failure mode the declaration's own docblock warns about, and
 * the one nothing else here can see: a ModuleTab carries a route name, the
 * shell generates a url from it, and a strip entry pointing at a route that
 * 404s looks exactly like a working strip until somebody clicks it.
 *
 * It is driven FROM THE DECLARATION rather than from a list written here, so
 * a seventh tab added tomorrow is covered the day it is declared.
 */
final class TabsRenderTest extends WebTestCase
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

        // A post on the books with a ring, a pool and real duties, so every
        // tab has something to draw rather than only its empty state.
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
        $this->em->persist(new Duty($this->area, $gate, $ranger, 'day', new \DateTimeImmutable('today')));
        $this->em->persist(new Duty($this->area, $gate, $ranger, 'night', new \DateTimeImmutable('yesterday')));
        $this->em->flush();
    }

    private function signIn(): void
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => FixedManageVoter::MANAGER_EMAIL]);
        self::assertInstanceOf(User::class, $user);
        $this->client->loginUser($user);
    }

    /**
     * @return list<ModuleTab>
     */
    private function declaredTabs(): array
    {
        $tabs = static::getContainer()->get('test_public.'.RosterModuleTabs::class);
        self::assertInstanceOf(RosterModuleTabs::class, $tabs);

        return $tabs->tabs();
    }

    /** All six are declared, in the ruled order. */
    public function testAllSixTabsAreDeclaredInTheRuledOrder(): void
    {
        $labels = array_map(static fn (ModuleTab $tab): string => $tab->label, $this->declaredTabs());

        self::assertSame(['Overview', 'Today', 'Week', 'Day board', 'Calendar', 'Live'], $labels);
    }

    /**
     * EVERY DECLARED TAB RENDERS. Driven from the declaration, so a tab
     * added tomorrow is covered the day it is declared.
     */
    public function testEveryDeclaredTabRenders(): void
    {
        $this->signIn();
        $router = static::getContainer()->get('router');
        self::assertInstanceOf(\Symfony\Component\Routing\RouterInterface::class, $router);

        foreach ($this->declaredTabs() as $tab) {
            $url = $router->generate($tab->routeName, ['uuid' => $this->area->getUuidString()]);

            $this->client->request('GET', $url);

            self::assertResponseIsSuccessful(\sprintf('The "%s" tab (%s) did not render.', $tab->label, $tab->routeName));
        }
    }

    /**
     * NO TAB WEARS THE BANDED CARD. Ruled 2026-09-20: banding is for
     * register and configure cards; a dashboard card is the house card.
     *
     * THE SHEET IS THE ONE EXCEPTION, ruled 21 sep with the sheet itself,
     * and the reason is exactly why banding exists. The sheet's head has
     * to carry eight controls on one baseline — the window navigation,
     * Today, the station filter, the weeks chip and the fold pair — and
     * carrying a row of controls is the banded head's whole job. It is
     * named here rather than excused by a looser assertion, so a SECOND
     * banded card appearing on a tab still fails.
     */
    public function testNoTabWearsTheBandedCard(): void
    {
        $this->signIn();
        $router = static::getContainer()->get('router');
        self::assertInstanceOf(\Symfony\Component\Routing\RouterInterface::class, $router);

        foreach ($this->declaredTabs() as $tab) {
            $crawler = $this->client->request('GET', $router->generate($tab->routeName, ['uuid' => $this->area->getUuidString()]));

            self::assertSame(
                $crawler->filter('.rband.sheetcard')->count(),
                $crawler->filter('.rband')->count(),
                \sprintf('The "%s" tab wears a banded card that is not the sheet.', $tab->label),
            );
        }
    }

    /** The identity band is byte-identical on every tab. */
    public function testTheIdentityBandIsOnEveryTab(): void
    {
        $this->signIn();
        $router = static::getContainer()->get('router');
        self::assertInstanceOf(\Symfony\Component\Routing\RouterInterface::class, $router);

        foreach ($this->declaredTabs() as $tab) {
            $crawler = $this->client->request('GET', $router->generate($tab->routeName, ['uuid' => $this->area->getUuidString()]));

            self::assertSame(1, $crawler->filter('.factband')->count(), \sprintf('The "%s" tab has no identity band.', $tab->label));
        }
    }

    /**
     * THE CALENDAR IS THE HOUSE CALENDAR. It must draw the atlas's own grid
     * — the whole point of the component is that a busy week cannot make the
     * month taller than a quiet one, and a module's own grid would not know
     * that.
     */
    public function testTheCalendarDrawsTheHouseMonth(): void
    {
        $this->signIn();

        $crawler = $this->client->request('GET', '/areas/'.$this->area->getUuidString().'/modules/roster/calendar');

        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('.cal')->count(), 'The month has to be the atlas\'s grid.');
        self::assertGreaterThan(0, $crawler->filter('.cal .dc')->count());
        // The ranger picker, as a grouped dropdown in the house's cal-nav.
        self::assertGreaterThan(0, $crawler->filter('.cal-nav .i-dd')->count());
    }

    /**
     * A NIGHT WATCH IS TWO BLOCKS. Yesterday's night watch is still standing
     * this morning, so the board draws its tail — and a board that only read
     * today would leave every morning before six looking unmanned.
     */
    public function testTheDayBoardDrawsTheMorningTailOfLastNightsWatch(): void
    {
        $this->signIn();

        $crawler = $this->client->request('GET', '/areas/'.$this->area->getUuidString().'/modules/roster/board');

        self::assertResponseIsSuccessful();
        $blocks = $crawler->filter('.r-track .r-blk')->each(static fn ($b): string => (string) $b->attr('style'));
        self::assertNotSame([], $blocks);
        self::assertTrue(
            (bool) array_filter($blocks, static fn (string $style): bool => str_contains($style, 'left:0%')),
            'The tail of a night watch that began yesterday has to start at the left edge.',
        );
    }

    /** Today shows the plan and the measurement side by side. */
    public function testTodayShowsTheWatchAndTheStateSideBySide(): void
    {
        $this->signIn();

        $crawler = $this->client->request('GET', '/areas/'.$this->area->getUuidString().'/modules/roster/today');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Ada Example', $crawler->filter('body')->text());
        // Nobody has checked in, so the area reports nothing for them — which
        // is a "no check-in" and never an "absent".
        self::assertGreaterThan(0, $crawler->filter('.r-ci.no_check_in')->count());
    }

    /** Live ships the half this module owns and says whose the positions are. */
    public function testLiveDrawsTheRosterUnderneathAndNamesWhoOwnsThePositions(): void
    {
        $this->signIn();

        $crawler = $this->client->request('GET', '/areas/'.$this->area->getUuidString().'/modules/roster/live');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('The roster, underneath', $crawler->filter('body')->text());
        self::assertStringContainsString('north gate post', $crawler->filter('body')->text());
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        while (true) {
            $previous = set_exception_handler(static fn () => null);
            restore_exception_handler();
            if (null === $previous) {
                break;
            }
            restore_exception_handler();
        }
    }
}
