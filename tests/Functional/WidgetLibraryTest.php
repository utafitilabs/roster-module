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
use Uhifadhi\Roster\Controller\RosterWidgetsController;
use Uhifadhi\Roster\Entity\Duty;
use Uhifadhi\Roster\Entity\Rotation;
use Uhifadhi\Roster\Entity\RotationPoolMember;
use Uhifadhi\Roster\Enum\RotationScope;
use Uhifadhi\Roster\Model\Cycle;
use Uhifadhi\Roster\Service\StationWatchService;
use Uhifadhi\Roster\Tests\FreshDatabase;
use Uhifadhi\Roster\Tests\Integration\Fixtures\FixedManageVoter;
use Uhifadhi\Roster\Widget\RosterOrgWidgets;
use Uhifadhi\Roster\Widget\RosterWidgets;

/**
 * THE WIDGET LIBRARY IS THE CONFIGURE PAGE'S FIRST SECTION.
 *
 * TWO THINGS ARE ASSERTED AND THEY ARE BOTH RULINGS. First, that the library
 * is a CONFIGURE section and not a door on the dashboard: editing a surface
 * never happens on the surface being edited, so the overview must carry no
 * control into this page at all. Second, that EVERY widget in the catalogue
 * renders — the library draws all sixteen as real cards on real data, and a
 * catalogue entry whose partial is missing takes the whole page down rather
 * than showing a gap.
 *
 * THE PREVIEW IS THE WIDGET, so the same partials and the same context are
 * used here as on the dashboard. That is asserted by rendering both and
 * finding the same cards.
 */ final class WidgetLibraryTest extends WebTestCase
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
        $this->em->flush();
    }

    private function open(): \Symfony\Component\DomCrawler\Crawler
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => FixedManageVoter::MANAGER_EMAIL]);
        self::assertInstanceOf(User::class, $user);
        $this->client->loginUser($user);

        $router = static::getContainer()->get('router');
        self::assertInstanceOf(\Symfony\Component\Routing\RouterInterface::class, $router);

        $crawler = $this->client->request('GET', $router->generate(RosterWidgetsController::LIBRARY_ROUTE, ['uuid' => (string) $this->area->getUuidString()]));
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /** IT RENDERS, and every widget in the catalogue renders inside it. */
    public function testTheLibraryRendersEveryWidgetInTheCatalogue(): void
    {
        $crawler = $this->open();

        $ids = RosterWidgets::declaration()->ids();
        self::assertCount(15, $ids, 'The surface the design declares, less the map ruled off it on 2026-09-26.');

        // The component renders each widget once into its canvas or its
        // picker; what matters is that no partial is missing, which would
        // have thrown before this line.
        // ASSERTED FROM THE CATALOGUE, never from a list retyped here: a
        // label edited in one place and not the other is exactly the drift
        // this test exists to catch, and a copy would hide it.
        $text = html_entity_decode($crawler->text());
        foreach ($ids as $id) {
            self::assertStringContainsString(
                html_entity_decode(RosterWidgets::declaration()->get($id)->label),
                $text,
                \sprintf('The library does not draw "%s".', $id),
            );
        }
    }

    /** THE LIBRARY OFFERS NO MAP: the area's marks are drawn on the area's pages. */
    public function testTheLibraryOffersNoPlate(): void
    {
        $crawler = $this->open();

        self::assertCount(0, $crawler->filter('.map-plate, .viewer'));
        self::assertStringNotContainsString('Waiting on', $crawler->text());
    }

    /** THE FIVE DESIGNS ARE OFFERED AS PRESETS, with the shipped one leading. */
    public function testTheFiveDirectionsAreOfferedAsDesignsToStartFrom(): void
    {
        $catalog = RosterWidgets::declaration();

        self::assertSame(
            ['a', 'b', 'c', 'd', 'e'],
            array_map(static fn (object $preset): string => $preset->id, $catalog->presets()),
        );

        $crawler = $this->open();
        foreach (['The day board', 'Station first', 'The week planner', 'The people', 'Right now'] as $direction) {
            self::assertStringContainsString($direction, $crawler->text());
        }
    }

    /**
     * NO DOOR ON THE DASHBOARD. The ruling is that the library is reached
     * from Configure and from nowhere else, so the overview carries no link
     * into it — not a `.w-act`, not anything.
     */
    public function testTheDashboardCarriesNoDoorIntoTheLibrary(): void
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => FixedManageVoter::MANAGER_EMAIL]);
        self::assertInstanceOf(User::class, $user);
        $this->client->loginUser($user);

        $router = static::getContainer()->get('router');
        self::assertInstanceOf(\Symfony\Component\Routing\RouterInterface::class, $router);

        $overview = $this->client->request('GET', $router->generate(RosterController::OVERVIEW_ROUTE, ['uuid' => (string) $this->area->getUuidString()]));
        self::assertResponseIsSuccessful();

        self::assertCount(0, $overview->filter('.w-act'), 'Editing never happens on the surface being edited.');
        self::assertCount(
            0,
            $overview->filter('a[href*="/widgets"]'),
            'The library is a configure section, and the dashboard does not link it.',
        );
    }

    /** IT IS A SECTION OF THE CONFIGURE PAGE, and the first one. */
    public function testItIsTheFirstSectionOfTheConfigurePage(): void
    {
        // THE STRIP AS THE PAGE ACTUALLY DRAWS IT. The sections source
        // resolves the area from the CURRENT request, so asking it outside
        // one answers nothing — which is why this reads the rendered strip
        // rather than calling the service.
        $crawler = $this->open();

        $labels = $crawler->filter('.cfgnav a, .atabs a')->each(
            static fn (\Symfony\Component\DomCrawler\Crawler $link): string => trim($link->text()),
        );

        self::assertContains('Widget library', $labels);
        self::assertSame('Widget library', $labels[0] ?? null, 'It is the FIRST section of the configure page.');
    }

    /**
     * A MODULE WITH TWO SURFACES HAS ONE LIBRARY PAGE, A SECTION EACH.
     *
     * TWO LIBRARY PAGES WOULD BE TWO ANSWERS to "where do I change my
     * widgets", so the roster's Overview and the organization roster are
     * arranged in one place — each in a house section, each with its own
     * catalogue, its own presets and its own write routes.
     */
    public function testEveryOneOfThisModulesSurfacesIsOnTheOneLibraryPage(): void
    {
        $crawler = $this->open();

        $sections = $crawler->filter('section.w-surface');
        self::assertCount(2, $sections, 'One section per surface, and the module has two.');

        self::assertSame(
            ['The module dashboard', 'The organization roster'],
            $sections->each(static fn (\Symfony\Component\DomCrawler\Crawler $s): string => html_entity_decode(trim($s->filter('h2.zone')->text()))),
            'The house section, in the order the page reads.',
        );

        foreach ($sections as $section) {
            self::assertNotSame('', trim(new \Symfony\Component\DomCrawler\Crawler($section)->filter('p.pgsub')->text()), 'Each section says what it is.');
        }
    }

    /**
     * EACH SECTION IS ITS OWN COMPOSITION: its own catalogue, its own
     * presets, and its own write routes. A token or a save URL shared
     * between them would let an arrangement of one be written over the
     * other.
     */
    public function testEachSurfaceCarriesItsOwnCatalogueAndItsOwnWriteRoutes(): void
    {
        $crawler = $this->open();

        $roots = $crawler->filter('[data-widget-root]');
        self::assertCount(2, $roots, 'One library root per surface.');

        $saves = $roots->each(static fn (\Symfony\Component\DomCrawler\Crawler $r): string => (string) $r->attr('data-widget-save-url'));
        self::assertStringContainsString('/'.RosterWidgets::SURFACE.'/save', $saves[0]);
        self::assertStringContainsString('/'.RosterOrgWidgets::SURFACE.'/save', $saves[1]);
        self::assertCount(2, array_unique($saves), 'A save for one surface is not a save for another.');

        $tokens = $roots->each(static fn (\Symfony\Component\DomCrawler\Crawler $r): string => (string) $r->attr('data-widget-csrf-token'));
        self::assertCount(2, array_unique($tokens), 'And a token good for one is not good for another.');
    }

    /**
     * AND EACH SURFACE HAS ITS OWN RESET, NAMING ITSELF. A button that does
     * not say which composition it throws away is not one anything can act
     * on safely, and the framework ignores a bare one on a page with two.
     */
    public function testEachSurfaceHasItsOwnResetNamingItself(): void
    {
        $crawler = $this->open();

        self::assertCount(1, $crawler->filter('[data-widget-reset="'.RosterWidgets::SURFACE.'"]'));
        self::assertCount(0, $crawler->filter('[data-widget-reset=""]'), 'No bare reset on a page with two libraries.');
    }

    /**
     * THE ORGANIZATION'S SECTION CARRIES THE ID A DOOR FROM THAT SCOPE NAMES.
     *
     * A door that always lands at the top is a door that makes the reader
     * hunt for the thing it just opened.
     */
    public function testTheOrganizationSectionCarriesItsAnchor(): void
    {
        $sections = $this->open()->filter('section.w-surface');

        self::assertNull($sections->eq(0)->attr('id'), 'The first section is where the page already starts.');
        self::assertSame(RosterWidgetsController::ORG_ANCHOR, $sections->eq(1)->attr('id'));
    }
}
