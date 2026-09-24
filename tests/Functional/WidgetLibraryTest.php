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
use Uhifadhi\Roster\Widget\RosterRailWidgets;
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
        self::assertCount(16, $ids, 'The surface the design declares.');

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

    /**
     * THE LIBRARY'S PREVIEW OF THE MAP WIDGET IS A REAL MAP, and the page
     * links the sheet that styles it. A library that drew a picture of a
     * plate would be a library whose previews stop matching what gets
     * added.
     */
    public function testTheLibraryPreviewsTheMapWidgetAsARealPlate(): void
    {
        $crawler = $this->open();

        self::assertGreaterThan(0, $crawler->filter('.map-plate, .viewer')->count(), 'The plate is previewed, not described.');
        self::assertStringNotContainsString('Waiting on', $crawler->text());

        $sheets = $crawler->filter('link[rel="stylesheet"]')->each(
            static fn (\Symfony\Component\DomCrawler\Crawler $link): string => (string) $link->attr('href'),
        );
        self::assertNotEmpty(array_filter($sheets, static fn (string $href): bool => str_contains($href, 'map')));
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
     * widgets", so the roster's Overview and the Live tab's plate rail are
     * arranged in one place — each in a house section, each with its own
     * catalogue, its own presets and its own write routes.
     */
    public function testEveryOneOfThisModulesSurfacesIsOnTheOneLibraryPage(): void
    {
        $crawler = $this->open();

        $sections = $crawler->filter('section.w-surface');
        self::assertCount(3, $sections, 'One section per surface, and the module has three.');

        self::assertSame(
            ['The module dashboard', 'The organization roster', 'The Live tab’s plate rail'],
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
        self::assertCount(3, $roots, 'One library root per surface.');

        $saves = $roots->each(static fn (\Symfony\Component\DomCrawler\Crawler $r): string => (string) $r->attr('data-widget-save-url'));
        self::assertStringContainsString('/'.RosterWidgets::SURFACE.'/save', $saves[0]);
        self::assertStringContainsString('/'.RosterOrgWidgets::SURFACE.'/save', $saves[1]);
        self::assertStringContainsString('/'.RosterRailWidgets::SURFACE.'/save', $saves[2]);
        self::assertCount(3, array_unique($saves), 'A save for one surface is not a save for another.');

        $tokens = $roots->each(static fn (\Symfony\Component\DomCrawler\Crawler $r): string => (string) $r->attr('data-widget-csrf-token'));
        self::assertCount(3, array_unique($tokens), 'And a token good for one is not good for another.');
    }

    /**
     * THE RAIL'S THREE LISTS AND ITS THREE ARRANGEMENTS ARE IN THE LIBRARY,
     * and the lists are the REAL ones — the same partials the Live tab
     * includes, on this minute's reading.
     */
    public function testTheRailsListsAndArrangementsRenderFromTheLibrary(): void
    {
        $crawler = $this->open();
        $rail = $crawler->filter('section.w-surface')->eq(2);
        $text = html_entity_decode($rail->text());

        $catalog = RosterRailWidgets::declaration();
        self::assertSame(['people', 'stations', 'zones'], $catalog->ids());
        foreach ($catalog->ids() as $id) {
            self::assertStringContainsString(html_entity_decode($catalog->get($id)->label), $text, \sprintf('The library does not draw "%s".', $id));
        }

        foreach ($catalog->presets() as $preset) {
            self::assertStringContainsString(html_entity_decode($preset->label), $text);
        }

        // AND THE TWIN IS THE LIST, not a drawing of one: the stations
        // partial's own row, on the post the fixture registers.
        self::assertGreaterThan(0, $rail->filter('.fg-stn')->count(), 'The preview is the real list.');
        self::assertStringContainsString('north gate post', $text);
    }

    /**
     * A LIBRARY IS NOT THE SURFACE. The order marks and the way out of the
     * rail belong to the rail itself; here they would be controls on a
     * picture, and one of them would take a list out of a column the
     * person is looking at somewhere else.
     */
    public function testTheRailsTwinsCarryNoneOfTheRailsOwnControls(): void
    {
        $rail = $this->open()->filter('section.w-surface')->eq(2);

        self::assertCount(0, $rail->filter('.rl-cellhd .ord button'));
        self::assertCount(0, $rail->filter('.rl-cellhd button.rm'));
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
        self::assertCount(1, $crawler->filter('[data-widget-reset="'.RosterRailWidgets::SURFACE.'"]'));
        self::assertCount(0, $crawler->filter('[data-widget-reset=""]'), 'No bare reset on a page with two libraries.');
    }

    /**
     * THE TWIN IS THE ROW, not a second shape of it.
     *
     * A RAIL WIDGET IS A LIST AND `.fg-lst` IS THE WIDGET ROOT, so the same
     * partial renders in the rail beside the plate and at full size here,
     * and the only difference between the two is the chrome the RAIL wraps
     * it in. This compares the two renderings element for element: same
     * root, same row classes, same inner parts. Computed layout is yours to
     * see in a browser; what a test can hold is that both sides are handed
     * the identical markup for the sheet to work on — and the companion
     * unit test holds that no rule the row needs is scoped to the rail.
     */
    public function testAListRendersIdenticallyInTheLibraryAndInTheRail(): void
    {
        // THE LIBRARY DRAWS EACH WIDGET THREE TIMES — into the canvas, into
        // the template its script previews from, and onto the picker's
        // stage — from one render. Any of them is the twin; they are the
        // same string.
        $twins = $this->open()->filter('.fg-lst[data-list="stations"]');
        self::assertCount(3, $twins, 'The widget root IS the list, in the library too.');
        $library = $twins->first();

        $router = static::getContainer()->get('router');
        self::assertInstanceOf(\Symfony\Component\Routing\RouterInterface::class, $router);
        $rail = $this->client
            ->request('GET', $router->generate(RosterController::LIVE_ROUTE, ['uuid' => (string) $this->area->getUuidString()]))
            ->filter('.fg-lst[data-list="stations"]');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $rail);

        // THE RAIL ADDS ITS CHROME TO THE SAME ELEMENT and nothing else:
        // one root, one extra class, so a twin is this markup with the
        // chrome left off rather than a shape of its own.
        self::assertSame('fg-lst', (string) $library->attr('class'));
        self::assertSame('fg-lst rl-cell', (string) $rail->attr('class'));

        self::assertSame(self::shapeOf($library), self::shapeOf($rail), 'The same list, drawn the same way.');
    }

    /**
     * Every element of a list's rows as class chains, so two renderings can
     * be compared without comparing the data in them — the library and the
     * tab read the same minute, but they are two reads and a count may tick
     * between them.
     *
     * @return list<string>
     */
    private static function shapeOf(\Symfony\Component\DomCrawler\Crawler $list): array
    {
        return $list->filter('.fg-stn')->each(static fn (\Symfony\Component\DomCrawler\Crawler $row): string => \sprintf(
            '%s[%s]: %s',
            $row->nodeName(),
            (string) $row->attr('class'),
            implode(' ', $row->filter('*')->each(
                static fn (\Symfony\Component\DomCrawler\Crawler $part): string => $part->nodeName().'.'.(string) $part->attr('class'),
            )),
        ));
    }

    /**
     * THE RAIL'S SECTION CARRIES THE ID THE LIVE TAB'S DOOR NAMES.
     *
     * A MODULE WITH TWO SURFACES HAS TWO DOORS INTO ONE PAGE. A door that
     * always lands at the top is a door that makes the reader hunt for the
     * thing it just opened — so the rail's section is `#rail`, and the Live
     * tab's door says so.
     */
    public function testTheRailsSectionCarriesTheAnchorTheLiveDoorNames(): void
    {
        $sections = $this->open()->filter('section.w-surface');

        self::assertNull($sections->eq(0)->attr('id'), 'The first section is where the page already starts.');
        self::assertSame(RosterWidgetsController::ORG_ANCHOR, $sections->eq(1)->attr('id'));
        self::assertSame(RosterWidgetsController::RAIL_ANCHOR, $sections->eq(2)->attr('id'));
    }

    /** AND THE DOOR ON THE LIVE TAB OPENS IT, rather than the page. */
    public function testTheLiveTabsDoorLandsOnTheRailsSection(): void
    {
        $router = static::getContainer()->get('router');
        self::assertInstanceOf(\Symfony\Component\Routing\RouterInterface::class, $router);

        $live = $this->client->request('GET', $router->generate(RosterController::LIVE_ROUTE, ['uuid' => (string) $this->area->getUuidString()]));
        self::assertResponseIsSuccessful();

        $door = $live->filter('.fg-side > .fg-rlhd a.more');
        self::assertCount(1, $door);
        self::assertStringEndsWith(
            $router->generate(RosterWidgetsController::LIBRARY_ROUTE, ['uuid' => (string) $this->area->getUuidString()]).'#'.RosterWidgetsController::RAIL_ANCHOR,
            (string) $door->attr('href'),
        );
    }

    /**
     * A TWIN CENTRES NOTHING, AND CARRIES NO ID.
     *
     * THE LIBRARY DRAWS EACH WIDGET THREE TIMES from one render, so an id
     * on the list root would be three of one id — not a page. And a row
     * here has no plate to move: the plate is on the Live tab, and a
     * preview that quietly navigated to it would be a preview acting as
     * the thing it is a picture of.
     */
    public function testATwinCentresNothingAndCarriesNoId(): void
    {
        $library = $this->open();

        self::assertCount(0, $library->filter('.fg-lst[id]'), 'Three renders of one list cannot share an id.');
        self::assertCount(0, $library->filter('.fg-lst [data-atlas-swap]'), 'A preview has no plate to move.');
        self::assertCount(0, $library->filter('.fg-lst a[href]'), 'And nowhere to send anybody: an anchor with no href is inert.');

        // BUT THE ROW KEEPS ITS SHAPE. Same element, same classes, same
        // centre mark — a twin that dropped the affordance would measure
        // differently from the row it stands for, which is the one thing
        // the library exists to show.
        self::assertGreaterThan(0, $library->filter('.fg-lst a.fg-stn .loc')->count());
    }
}
