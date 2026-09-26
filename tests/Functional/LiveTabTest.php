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
use Uhifadhi\Bundle\AreaBundle\Entity\Zone;
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
 * LIVE, MEASURED AGAINST ITS DESIGN — modules/roster/live.html.
 *
 * THE PLATE IS FIRST and the roster is underneath it, which is the whole
 * shape of the tab and was the thing the port had backwards: it used to be
 * the roster and nothing else.
 *
 * WHAT THIS MODULE CONTRIBUTES IS ASSERTED, and nothing the atlas or the
 * area owns is. The plate's imagery, controls and key are the house's; the
 * ground, the boundary and the posts are the area's; the marker layers and
 * their legend group are the roster's, and so is the rail beside them.
 *
 * THE RAIL AND THE MAP ARE DELIBERATELY NOT THE SAME SET. Somebody at their
 * post whose phone has said nothing is in the rail's last group and nowhere
 * on the map — a surface showing only the map would report them absent, and
 * one inventing a marker at the post would turn a claim into proof.
 */ final class LiveTabTest extends WebTestCase
{
    use EveryAreaRunsTheRoster;
    use FreshDatabase;
    use ReadsTheLiveStream;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private AreaOfInterest $area;
    private Station $gate;
    private User $ranger;

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
        $this->gate = $gate;

        // A ZONE, so the zones list has a row that centres on something.
        // The rail draws one row per zone whether or not anybody is posted
        // in it, which is the whole point of the list: an empty zone is an
        // answer.
        $this->em->persist(new Zone()->setArea($this->area)->setName('north sector')->setGeom(
            '{"type":"MultiPolygon","coordinates":[[[[12.25,-5.75],[12.45,-5.75],[12.45,-5.55],[12.25,-5.55],[12.25,-5.75]]]]}',
        ));
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
        $this->ranger = $ranger;
        $this->em->persist(new RotationPoolMember($rotation, $ranger, 0));
        // Due today and nothing reported: the "no check-in" row the decisions
        // card exists for, and the one figure an empty demo never produces.
        $this->em->persist(new Duty($this->area, $gate, $ranger, 'day', new \DateTimeImmutable('today')));
        // AND A NIGHT WATCH THAT BEGAN YESTERDAY, which is the case the
        // wall exists to draw: it is still standing at 05:00 this morning,
        // so it is the first block of today's row as well as the last of
        // yesterday's.
        $this->em->persist(new Duty($this->area, $gate, $ranger, 'night', new \DateTimeImmutable('yesterday')));
        $this->em->flush();
    }

    /**
     * THE LIVE TAB STREAMS. It used to draw once, so its marks stood still
     * until the page was reloaded; it now subscribes to the area's topic
     * like the area overview does, with the cookie the hub checks.
     */
    public function testTheLiveTabStreamsTheAreasPositions(): void
    {
        $this->open();

        self::assertStreamsTheAreas($this->client, [(string) $this->area->getUuidString()]);
    }

    private function open(): \Symfony\Component\DomCrawler\Crawler
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => FixedManageVoter::MANAGER_EMAIL]);
        self::assertInstanceOf(User::class, $user);
        $this->client->loginUser($user);

        $router = static::getContainer()->get('router');
        self::assertInstanceOf(\Symfony\Component\Routing\RouterInterface::class, $router);

        $crawler = $this->client->request('GET', $router->generate(RosterController::LIVE_ROUTE, ['uuid' => (string) $this->area->getUuidString()]));
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
            ['positions-now', 'verified', 'oldest-ping', 'posts-reporting'],
            $cards->each(static fn (\Symfony\Component\DomCrawler\Crawler $card): string => (string) $card->attr('data-kpi')),
        );
    }

    /** THE PLATE IS FIRST, and the roster is underneath it. */
    public function testThePlateComesFirstAndTheRosterIsUnderneath(): void
    {
        $crawler = $this->open();

        self::assertSame(
            ['The plate first', 'The roster, underneath'],
            $crawler->filter('h2.zone')->each(static fn (\Symfony\Component\DomCrawler\Crawler $h): string => html_entity_decode(trim($h->text()))),
        );

        self::assertCount(1, $crawler->filter('.fg-live'), 'The plate and its rail, side by side, in the card that leads the page.');
        self::assertGreaterThan(0, $crawler->filter('.fg-live .map-plate')->count(), 'And the atlas plate itself is in it.');
    }

    /**
     * THE ROSTER UNDERNEATH IS BOUNDED AND SCROLLS INSIDE ITS CARD — RULED
     * 25 sep, the sheet's own height rule — with its column head pinned.
     */
    public function testTheRosterUnderneathIsBoundedWithItsHeadPinned(): void
    {
        $crawler = $this->open();

        $card = $crawler->filter('.c[data-controller="roster--bound"]');
        self::assertCount(1, $card);
        self::assertSame('roster--bound', $card->attr('data-controller'));
        self::assertStringStartsWith('The roster, underneath', html_entity_decode(trim($card->filter('.tab')->text())));

        $scroller = $card->filter('.rscroll');
        self::assertCount(1, $scroller);
        self::assertSame('scroller', $scroller->attr('data-roster--bound-target'));
        self::assertCount(1, $scroller->filter('table.tbl > thead > tr'), 'The column head is a thead, which is what pins.');
        self::assertCount(6, $scroller->filter('table.tbl > thead th'));
    }

    /** THE FILTER ROW, the house's, pointed at this page. */
    public function testItWearsTheHouseFilterRow(): void
    {
        $crawler = $this->open();

        $row = $crawler->filter('form.lfilt');
        self::assertCount(1, $row);
        self::assertCount(3, $row->filter('details.i-dd'));
        self::assertStringContainsString('/live', (string) $row->attr('action'));
    }

    /**
     * THE RAIL IS HEAD + BODY + FOOT, AND ONLY THE BODY SCROLLS.
     *
     * AN OVERFLOW BOX CLIPS EVERYTHING POSITIONED INSIDE IT, so a head, a
     * popover or a tooltip parented to a scrolling rail is cut off the
     * moment the lists are long enough to scroll — which on this tab is
     * always. The head and the foot therefore sit OUTSIDE the scroller;
     * this asserts that arrangement structurally, because the clipping
     * itself is a computed-layout fact and yours to see.
     */
    public function testTheRailIsAPinnedHeadAScrollingBodyAndAPinnedFoot(): void
    {
        $crawler = $this->open();

        $rail = $crawler->filter('.fg-side');
        self::assertCount(1, $rail);

        self::assertCount(1, $crawler->filter('.fg-side > .fg-rlhd'), 'The head is a child of the rail, not of the scroller.');
        self::assertCount(1, $crawler->filter('.fg-side > .rl-body'), 'One scroller.');
        self::assertCount(1, $crawler->filter('.fg-side > .fg-foot'), 'And the foot is outside it too.');

        self::assertCount(0, $rail->filter('.rl-body .fg-rlhd'), 'Nothing pinned may sit inside the scroller.');
        self::assertCount(0, $rail->filter('.rl-body .fg-foot'));
    }

    /**
     * THE RAIL IS A WIDGET SURFACE: the lists it carries are widgets, and
     * the shipped arrangement is two of the three.
     */
    public function testTheRailCarriesTheListsTheSurfaceShipsWith(): void
    {
        $crawler = $this->open();

        self::assertSame(
            ['people', 'stations'],
            $crawler->filter('.rl-body .rl-cell')->each(
                static fn (\Symfony\Component\DomCrawler\Crawler $cell): string => (string) $cell->attr('data-list'),
            ),
            'The duty officer: people first, posts under them — and zones one adopt away.',
        );

        self::assertCount(3, $crawler->filter('.rl-presets .mchip'), 'Three arrangements to choose between.');
        self::assertCount(1, $crawler->filter('.rl-presets .mchip.on'), 'One of them is in force.');
    }

    /**
     * A PRESET VISIBLY CHANGES WHICH LISTS SHOW AND THEIR ORDER, and the
     * choice is REMEMBERED — in the same store the Overview surface uses,
     * so a duty officer's rail is the rail they left.
     */
    public function testAdoptingAnArrangementChangesTheRailAndIsRemembered(): void
    {
        $crawler = $this->open();

        $everything = $crawler->filter('.rl-presets .mchip')->reduce(
            static fn (\Symfony\Component\DomCrawler\Crawler $chip): bool => 'Everything' === trim($chip->text()),
        );
        self::assertCount(1, $everything);

        $this->client->request('GET', (string) $everything->attr('href'));
        self::assertResponseRedirects();
        $after = $this->client->followRedirect();

        self::assertSame(
            ['people', 'stations', 'zones'],
            $after->filter('.rl-body .rl-cell')->each(
                static fn (\Symfony\Component\DomCrawler\Crawler $cell): string => (string) $cell->attr('data-list'),
            ),
            'All three lists, in the order the arrangement names.',
        );

        // AND IT PERSISTS: a fresh request reads the same rail back out of
        // the store rather than falling back to what the module ships.
        $again = $this->open();
        self::assertSame(
            ['people', 'stations', 'zones'],
            $again->filter('.rl-body .rl-cell')->each(
                static fn (\Symfony\Component\DomCrawler\Crawler $cell): string => (string) $cell->attr('data-list'),
            ),
        );
        self::assertSame('Everything', trim($again->filter('.rl-presets .mchip.on')->text()));
    }

    /**
     * THE STATIONS LIST IS ONE ROW PER POST, and a post the plate cannot
     * be centred on is INERT AND UNMARKED rather than a link that does
     * nothing when clicked.
     */
    public function testTheStationsListCarriesTheDesignsColumns(): void
    {
        $crawler = $this->open();

        $row = $crawler->filter('[data-list="stations"] .fg-stn')->eq(0);
        self::assertCount(1, $row->filter('.nm'), 'The post, and its code and people under it.');
        self::assertCount(1, $row->filter('.nm em'));
        self::assertCount(1, $row->filter('.lv'), 'How many of them are live now.');

        self::assertStringContainsString('ST-01', $row->filter('.nm em')->text());
        self::assertStringContainsString('live', $row->filter('.lv')->text());
    }

    /** THE DOOR IS THE LIBRARY, in the design's exact words. */
    public function testTheRailsDoorIsTheWidgetLibrary(): void
    {
        $crawler = $this->open();

        // THE HOUSE'S `.more` IDIOM, not a door class of this module's:
        // one quiet door on a card looks the same everywhere in the app.
        $door = $crawler->filter('.fg-side .fg-rlhd .more');
        self::assertCount(1, $door);
        self::assertSame('Widget library →', html_entity_decode(trim($door->text())));
        self::assertStringContainsString('/widgets', (string) $door->attr('href'));
    }

    /** AND THE FOOT SAYS WHICH ARRANGEMENT IS IN FORCE, and whose it is. */
    public function testTheFootMarksTheArrangementInForce(): void
    {
        $crawler = $this->open();

        self::assertStringContainsString('default ·', html_entity_decode($crawler->filter('.fg-side .fg-foot')->text()));
    }

    /**
     * THE LEGEND CARRIES THE LIVE KEY, and the module named none of it.
     *
     * A LAYER SHIPS A LEGEND — and here the legend says more than the
     * layer can: the mark has a state the plate draws DIMMED and a state
     * it draws NOWHERE, and a plate silent about the people it is not
     * showing would be claiming the park is fully seen. The atlas adds
     * both in one call.
     */
    public function testTheLiveKeyIsOnThePlateAndTheModuleNamesNoneOfIt(): void
    {
        $crawler = $this->open();

        $plate = html_entity_decode($crawler->filter('.fg-live')->html());

        foreach (['Live position', 'Stale', 'No position'] as $row) {
            self::assertStringContainsString($row, $plate, 'The key the live layer ships with.');
        }

        // AND THE RAIL'S OWN KEY, under its own heading: the six states
        // the column is colour-coded by. They are legend ITEMS and not
        // layers — nothing on the map is drawn in these colours — but a
        // reader meeting amber in the rail has nowhere else to learn it.
        self::assertStringContainsString('Presence states', $plate);
        self::assertStringContainsString('at post · verified', $plate);
        self::assertStringContainsString('no check-in', $plate);

        // AND NO COLOUR ANYWHERE NEAR THIS MODULE'S OWN LAYER. The posts
        // layer is still the roster's; it must not have grown a palette.
        self::assertDoesNotMatchRegularExpression('/roster\.[a-z_.]+[^}]{0,300}#[0-9A-Fa-f]{6}/', $plate);
    }

    /** The rail's edit route, for one op on one list. */
    private function editUrl(string $op, string $list): string
    {
        $router = static::getContainer()->get('router');
        self::assertInstanceOf(\Symfony\Component\Routing\RouterInterface::class, $router);

        return $router->generate(RosterController::RAIL_EDIT_ROUTE, [
            'uuid' => (string) $this->area->getUuidString(),
            'op' => $op,
            'list' => $list,
        ]);
    }

    /**
     * COMPOSE THE RAIL AS THE PAGE DOES: the token comes off the rendered
     * form rather than out of the container, so a page that stopped
     * shipping one would fail here rather than quietly pass.
     *
     * @return list<string> the rail after the edit
     */
    private function compose(\Symfony\Component\DomCrawler\Crawler $page, string $op, string $list): array
    {
        $token = $page->filter('#rail-'.$list.'-'.$op.' input[name="_token"]')->attr('value');
        self::assertIsString($token, 'Every control the rail draws submits a real form.');

        $this->client->request('POST', $this->editUrl($op, $list), ['_token' => $token]);
        self::assertResponseRedirects();

        return $this->railOf($this->client->followRedirect());
    }

    /** @return list<string> */
    private function railOf(\Symfony\Component\DomCrawler\Crawler $page): array
    {
        return $page->filter('.rl-body .rl-cell')->each(
            static fn (\Symfony\Component\DomCrawler\Crawler $cell): string => (string) $cell->attr('data-list'),
        );
    }

    /**
     * A ROW CENTRES THE PLATE ON WHAT IT NAMES, and the row it centred on
     * says so.
     *
     * THE PAGE MUST NOT RELOAD BLIND: the answer to a click is the same
     * page with the plate moved and the row marked, so somebody who
     * centred on a post can see which one they chose. The move itself is
     * the atlas's — this asserts that the click reaches it and comes back
     * marked.
     */
    public function testARowCentresThePlateAndTheChosenRowSaysSo(): void
    {
        $page = $this->open();

        $row = $page->filter('.rl-cell[data-list="stations"] a.fg-stn')->first();
        self::assertCount(1, $row, 'A post with a point is a link, not an inert row.');

        $centre = (string) $row->attr('href');
        self::assertStringContainsString('centre='.$this->gate->getUuidString(), $centre);

        $router = static::getContainer()->get('router');
        self::assertInstanceOf(\Symfony\Component\Routing\RouterInterface::class, $router);
        $centred = $this->client->request(
            'GET',
            $router->generate(RosterController::LIVE_ROUTE, ['uuid' => (string) $this->area->getUuidString()]).$centre,
        );
        self::assertResponseIsSuccessful();

        self::assertCount(1, $centred->filter('.rl-cell[data-list="stations"] a.fg-stn.on'), 'The row that was clicked is the row that is marked.');
    }

    /**
     * THE CELL HEAD CARRIES THE SURFACE'S OWN EDITING: where the list
     * sits, and the way out of the rail.
     *
     * THE ENDS OF THE RAIL ARE DEAD ENDS. A first list with a live "up"
     * would answer a click by not moving, which reads as a broken button
     * rather than as the top of the list.
     */
    public function testEachCellCarriesItsOrderAndRemoveControls(): void
    {
        $page = $this->open();

        $cells = $page->filter('.rl-body .rl-cell');
        self::assertCount(2, $cells);

        self::assertNotNull($cells->eq(0)->filter('.rl-cellhd .ord button')->eq(0)->attr('disabled'), 'The first list cannot move up.');
        self::assertNull($cells->eq(0)->filter('.rl-cellhd .ord button')->eq(1)->attr('disabled'));
        self::assertNull($cells->eq(1)->filter('.rl-cellhd .ord button')->eq(0)->attr('disabled'));
        self::assertNotNull($cells->eq(1)->filter('.rl-cellhd .ord button')->eq(1)->attr('disabled'), 'And the last cannot move down.');

        self::assertCount(1, $cells->eq(0)->filter('.rl-cellhd button.rm'), 'Every cell offers the way out.');
        self::assertCount(1, $cells->eq(1)->filter('.rl-cellhd button.rm'));
    }

    /**
     * MOVING A LIST IS REMEMBERED THE WAY THE PRESET CHOICE IS — the same
     * store, so the rail somebody arranged is the rail they come back to.
     */
    public function testMovingAListReordersTheRailAndIsRemembered(): void
    {
        $page = $this->open();
        self::assertSame(['people', 'stations'], $this->railOf($page));

        self::assertSame(['stations', 'people'], $this->compose($page, 'down', 'people'));
        self::assertSame(['stations', 'people'], $this->railOf($this->open()), 'A rail rearranged is a rail that stays rearranged.');
    }

    /** AND UP IS DOWN'S UNDO, over the lists that are on. */
    public function testMovingAListBackUpRestoresTheOrder(): void
    {
        $this->compose($this->open(), 'down', 'people');

        self::assertSame(['people', 'stations'], $this->compose($this->open(), 'up', 'people'));
    }

    /**
     * TAKING A LIST OUT LEAVES A DOOR BACK IN, and the door is in the
     * foot, where the design put it: the rail's own body is for lists that
     * are in it.
     */
    public function testALisTakenOutIsOfferedBackFromTheFoot(): void
    {
        self::assertSame(['people'], $this->compose($this->open(), 'remove', 'stations'));

        $page = $this->open();
        $door = $page->filter('.fg-side .fg-foot button.rl-add[aria-pressed="false"]');
        self::assertCount(2, $door, 'Zones was never in the rail, and stations has just left it.');
        self::assertStringContainsString('from the library', $door->first()->text());

        self::assertSame(['people', 'stations'], $this->compose($page, 'add', 'stations'), 'And the door puts it back where it was.');
    }

    /** A LIST THE SURFACE SHIPS WITH OFF IS A LIST THE FOOT CAN ADD. */
    public function testTheFootAddsTheListTheSurfaceShipsSwitchedOff(): void
    {
        self::assertSame(['people', 'stations', 'zones'], $this->compose($this->open(), 'add', 'zones'));
        self::assertCount(1, $this->open()->filter('.rl-cell[data-list="zones"]'));
    }

    /** AND COMPOSING IT IS A WRITE: it takes the permission and the token. */
    public function testComposingTheRailRefusesARequestThatDidNotComeFromThePage(): void
    {
        $this->open();
        $this->client->catchExceptions(false);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException::class);
        $this->client->request('POST', $this->editUrl('remove', 'people'));
    }

    /**
     * THE TAIL OF THE PEOPLE LIST IS FOLDED, AND THE HEAD STILL COUNTS IT.
     *
     * "NOT HERE" AND "NOT DUE" ARE DIFFERENT ANSWERS and only one of them
     * is a problem, so somebody off today is in the list — a rail that
     * dropped them would answer "who is missing" with a name that is
     * simply off — but behind their own head, where they cost one row
     * rather than five.
     */
    public function testThePeopleWhoAreNotOnTheWatchAreFoldedIntoTheTail(): void
    {
        $this->reportNotWorking();

        $page = $this->open();
        $cell = $page->filter('.rl-cell[data-list="people"]');

        $fold = $cell->filter('details.rl-more');
        self::assertCount(1, $fold, 'One fold, not one per group: nobody in it is missing.');
        self::assertStringContainsString('off · 1', html_entity_decode($fold->filter('summary')->text()));
        self::assertStringContainsString('Ada', $fold->filter('.pr.note')->text());

        self::assertSame('1', trim($cell->filter('.rl-cellhd em')->text()), 'The head counts the whole list, folded or not.');
        $offHeads = $cell->filter('.grp.gsub')->reduce(
            static fn (\Symfony\Component\DomCrawler\Crawler $head): bool => str_contains(html_entity_decode($head->text()), 'off ·'),
        );
        self::assertCount(1, $offHeads, 'One head for the tail, and it is the fold\'s own.');
        self::assertCount(1, $fold->filter('summary .grp.gsub'), 'The head names the fold from inside it.');
    }

    /**
     * THE AREA IS TOLD, NOT THIS MODULE'S TABLES. A presence row is the
     * area's to derive, so the fixture claims through its own service
     * exactly as a handset would.
     */
    private function reportNotWorking(): void
    {
        $statuses = static::getContainer()->get('test_public.'.\Uhifadhi\Bundle\AreaBundle\Service\CheckInStatusService::class);
        self::assertInstanceOf(\Uhifadhi\Bundle\AreaBundle\Service\CheckInStatusService::class, $statuses);
        $doors = static::getContainer()->get('test_public.'.\Uhifadhi\Bundle\AreaBundle\Service\CheckInService::class);
        self::assertInstanceOf(\Uhifadhi\Bundle\AreaBundle\Service\CheckInService::class, $doors);

        $key = null;
        foreach ($statuses->offeredBy($this->area) as $status) {
            if (\Uhifadhi\Bundle\AreaBundle\Enum\CheckInStatusKind::NotWorking === $status->getKind()) {
                $key = $status->getKey();
            }
        }
        self::assertIsString($key, 'The area offers a "not working" status out of the box.');

        $doors->claim($this->area, $this->ranger, [
            'clientRef' => 'live-tab-tail',
            'localDate' => new \DateTimeImmutable('today')->format('Y-m-d'),
            'status' => $key,
            'occurredAt' => new \DateTimeImmutable('today 06:00')->format(\DATE_ATOM),
            'deviceId' => 'live-tab-handset',
            'appVersion' => '1.4.0',
        ]);
        $this->em->flush();
    }

    /**
     * A ROW CHANGES THE PLATE IN PLACE, and says so with the atlas's own
     * verb rather than with JavaScript of this module's.
     *
     * THE PLATE IS THE ATLAS'S TO MOVE. The row already points at the
     * answer — `?centre=…`, which the server computes with `focusOn()` —
     * and the verb only says "fetch that and swap yourself" and "bring this
     * one region with you", so the row that was clicked comes back marked.
     * The href is untouched and is still the whole behaviour without a
     * script.
     */
    public function testEveryCentringRowAsksThePlateToSwapAndBringItsListBack(): void
    {
        $this->compose($this->open(), 'add', 'zones');
        $page = $this->open();

        foreach (['stations', 'zones'] as $list) {
            $root = $page->filter('.rl-cell[data-list="'.$list.'"]');
            self::assertSame(RosterController::RAIL_LIST_ID.$list, (string) $root->attr('id'), 'The region the answer brings back is named.');

            $rows = $root->filter('a[href*="centre="]');
            self::assertGreaterThan(0, $rows->count(), \sprintf('The %s list has a row to centre on.', $list));

            $rows->each(static function (\Symfony\Component\DomCrawler\Crawler $row) use ($list): void {
                self::assertNotNull($row->attr('data-atlas-swap'), 'Every centring row changes the plate in place.');
                self::assertSame('#'.RosterController::RAIL_LIST_ID.$list, (string) $row->attr('data-atlas-swap-also'), 'And brings its own list back with it.');
            });
        }
    }

    /**
     * AND THE VERB NAMES NO PLATE. With one plate on the page the link it
     * sits beside is unambiguous, and a module naming the atlas's element
     * by selector is a module that breaks when the atlas renames it.
     */
    public function testTheRowNamesNoPlate(): void
    {
        $row = $this->open()->filter('.rl-cell[data-list="stations"] a.fg-stn')->first();

        self::assertSame('', (string) $row->attr('data-atlas-swap'));
    }

    /** AND THE HREF IS UNTOUCHED, because it is still the whole behaviour. */
    public function testTheRowStillCarriesTheAddressThatDoesTheWholeJob(): void
    {
        $row = $this->open()->filter('.rl-cell[data-list="stations"] a.fg-stn')->first();

        self::assertStringContainsString('centre='.$this->gate->getUuidString(), (string) $row->attr('href'));
    }
}
