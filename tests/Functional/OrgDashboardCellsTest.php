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
use Uhifadhi\Bundle\AreaBundle\Enum\PostingSource;
use Uhifadhi\Bundle\AreaBundle\Service\PostingService;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Roster\Entity\Duty;
use Uhifadhi\Roster\Entity\Rotation;
use Uhifadhi\Roster\Entity\RotationPoolMember;
use Uhifadhi\Roster\Enum\RotationScope;
use Uhifadhi\Roster\Model\Cycle;
use Uhifadhi\Roster\Org\RosterOrgOverview;
use Uhifadhi\Roster\Service\StationWatchService;
use Uhifadhi\Roster\Tests\FreshDatabase;
use Uhifadhi\Roster\Tests\Integration\Fixtures\FixedManageVoter;
use Uhifadhi\Roster\UhifadhiRosterBundle;

/**
 * WHAT THIS MODULE PUTS ON THE ORGANIZATION DASHBOARD — `/`, the core's own
 * page, rendered over real HTTP with this module installed.
 *
 * THE SEAM IS THE SUBJECT, NOT THE FIGURES. Every number here has its own
 * test somewhere else; what cannot be proved anywhere else is that the
 * contract holds — that the core asks, that this module answers, that the
 * cell the core draws is drawn from THIS bundle's partial on THIS module's
 * figures, and that the tile lands in a strip the core lays out. A green
 * unit test of a contributor that the host never reaches proves nothing at
 * all, which is the lesson the core learnt when its area overview shipped a
 * host that fataled on every module's cell.
 *
 * IT IS THE DESIGN THAT SAYS WHICH CELLS. `org.widgets.js` declares the
 * roster group and its `watches` cell, and `kpis` carries one figure per
 * contributor — the roster's is the first of the four.
 */
final class OrgDashboardCellsTest extends WebTestCase
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
            ->setName('north gate station')
            ->setCode('ST-01')
            ->setPoint('{"type":"Point","coordinates":[12.3,-5.7]}');
        $this->em->persist($gate);

        // A SECOND STATION THE ROSTER DOES NOT WORK, so the cell's own
        // "not on the books" reading has something to say.
        $this->em->persist(new Station()
            ->setArea($this->area)
            ->setName('west outstation')
            ->setCode('ST-02')
            ->setPoint('{"type":"Point","coordinates":[12.25,-5.75]}'));

        $this->em->persist(new User()->setPassword('x')->setEmail(FixedManageVoter::MANAGER_EMAIL)->setFirstName('Mara')->setLastName('Manager'));
        $ranger = new User()->setPassword('x')->setEmail('ada@example.test')->setFirstName('Ada')->setLastName('Example');
        $this->em->persist($ranger);
        $this->em->flush();

        $assignments = static::getContainer()->get('test_public.'.PostingService::class);
        self::assertInstanceOf(PostingService::class, $assignments);
        $assignments->post($gate, $ranger, PostingSource::WrittenHere);

        $this->everyAreaRunsTheRoster($this->em);

        $watches = static::getContainer()->get('test_public.'.StationWatchService::class);
        self::assertInstanceOf(StationWatchService::class, $watches);
        $watches->addToRoster($gate)->expect(['day']);

        $rotation = new Rotation(
            $this->area,
            RotationScope::Post,
            Cycle::of(['day', Cycle::OFF]),
            new \DateTimeImmutable('today'),
            ['day' => 2],
            42,
        )->standAt($gate);
        $this->em->persist($rotation);
        $this->em->persist(new RotationPoolMember($rotation, $ranger, 0));
        $this->em->persist(new Duty($this->area, $gate, $ranger, 'day', new \DateTimeImmutable('today')));
        $this->em->flush();
    }

    private function dashboard(): \Symfony\Component\DomCrawler\Crawler
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => FixedManageVoter::MANAGER_EMAIL]);
        self::assertInstanceOf(User::class, $user);
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /**
     * THE CELL IS ON THE GRID AND IT IS DRAWN FROM THIS BUNDLE'S PARTIAL.
     *
     * The station name is the tell: the core's template contains no widget
     * markup at all, so a row naming a station can only have come from a
     * partial this module ships.
     */
    public function testTheWatchesCellIsDrawnFromThisModulesOwnPartial(): void
    {
        $cell = $this->dashboard()->filter('[data-w="'.RosterOrgOverview::WATCHES.'"]');

        self::assertCount(1, $cell, 'The contributed cell is on the grid.');
        self::assertStringContainsString('north gate station', $cell->text());
        self::assertStringContainsString('ST-01', $cell->text());
        // What the station's watch asks for, in the words the register prints.
        self::assertStringContainsString('day 2', $cell->text());
    }

    /** AND IT NAMES ITSELF, so the day the module is uninstalled its absence reads as the system working. */
    public function testTheCellStatesWhoseFigureItIs(): void
    {
        self::assertStringContainsString(
            RosterOrgOverview::SLUG,
            $this->dashboard()->filter('[data-w="'.RosterOrgOverview::WATCHES.'"] .ao-by')->text(),
        );
    }

    /**
     * THE FIGURE LANDS IN THE STRIP the core assembles — one tile per
     * contributor, four to a row, and this module's is one of them.
     */
    public function testTheOnDutyFigureLandsInTheStrip(): void
    {
        $strip = $this->dashboard()->filter('[data-w="kpis"] .kpi');

        self::assertCount(4, $strip, 'The strip is four to a row, filled or stated absent.');

        // EVERY TILE, NOT THE FIRST ONE. `Crawler::text()` answers for the
        // first node it holds, so asserting against it would pass on the
        // host's own tile and never look at this module's.
        $tiles = $strip->each(static fn (\Symfony\Component\DomCrawler\Crawler $tile): string => $tile->text());

        self::assertStringContainsString(RosterOrgOverview::ON_DUTY_LABEL, implode(' | ', $tiles));
    }

    /**
     * THE CELL IS BOUNDED AND HAS A WAY OUT — a card never grows with its
     * data, and the way out is the organization's own roster, not an
     * area's.
     */
    public function testTheCellIsBoundedAndLeadsToTheOrganizationsRoster(): void
    {
        $cell = $this->dashboard()->filter('[data-w="'.RosterOrgOverview::WATCHES.'"]');

        self::assertLessThanOrEqual(
            RosterOrgOverview::ROWS_SHOWN,
            $cell->filter('table.tbl tr')->count() - 1,
            'A bounded card shows at most the rows it declares.',
        );
        self::assertSame('/roster/today', $cell->filter('a.more')->attr('href'));
    }

    /** A contributed cell brings its own stylesheet, and the page links it. */
    public function testTheCellBringsThisModulesSheet(): void
    {
        $this->dashboard();

        // MATCHED WITHOUT ITS DIGEST: AssetMapper content-versions the file,
        // so asserting the bare name would be asserting that versioning is
        // off.
        self::assertMatchesRegularExpression(
            '#'.preg_quote(substr(UhifadhiRosterBundle::STYLESHEET, 0, -4), '#').'[.-][^"]*\.css#',
            (string) $this->client->getResponse()->getContent(),
        );
    }
}
