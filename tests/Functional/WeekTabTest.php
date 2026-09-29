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
use Uhifadhi\Bundle\AreaBundle\Entity\Posting;
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Bundle\AreaBundle\Enum\PostingSource;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Roster\Entity\Duty;
use Uhifadhi\Roster\Entity\EditedDay;
use Uhifadhi\Roster\Entity\SheetPreference;
use Uhifadhi\Roster\Model\Cycle;
use Uhifadhi\Roster\Service\PatternService;
use Uhifadhi\Roster\Service\StationWatchService;
use Uhifadhi\Roster\Service\SwapService;
use Uhifadhi\Roster\Tests\FreshDatabase;
use Uhifadhi\Roster\Tests\Integration\Fixtures\FixedManageVoter;

/**
 * THE PLANNING SHEET, OVER REAL HTTP.
 *
 * THE GEOMETRY CHANGED AGAIN and these tests changed with it: the grid was
 * posts down over a week, then people down grouped by post, and is now THE
 * SHEET — people down, days across, one band per station, one, two or four
 * weeks at a time. A test still asserting an older shape would be a test
 * defending a design nobody ships.
 *
 * What has never changed is what the tab is for: A GAP HAS TO SHOW AT ONCE.
 */
final class WeekTabTest extends WebTestCase
{
    use EveryAreaRunsTheRoster;
    use FreshDatabase;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private AreaOfInterest $area;
    private Station $gate;
    private Station $rim;
    private User $ada;
    private User $bea;
    private \DateTimeImmutable $monday;

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

        $this->gate = $this->aStation('north gate post', 'ST-01');
        $this->rim = $this->aStation('rim outpost', 'ST-02');

        $this->em->persist(new User()->setPassword('x')->setEmail(FixedManageVoter::MANAGER_EMAIL)->setFirstName('Mara')->setLastName('Manager'));
        $this->ada = new User()->setPassword('x')->setEmail('ada@example.test')->setFirstName('Ada')->setLastName('Example');
        $this->bea = new User()->setPassword('x')->setEmail('bea@example.test')->setFirstName('Bea')->setLastName('Example');
        $this->em->persist($this->ada);
        $this->em->persist($this->bea);
        $this->em->flush();

        foreach ([$this->ada, $this->bea] as $person) {
            $this->em->persist(
                new Posting()->setStation($this->gate)->setPerson($person)
                    ->setSince(new \DateTimeImmutable('-1 year'))->setSource(PostingSource::WrittenHere),
            );
        }
        $this->em->flush();

        $this->everyAreaRunsTheRoster($this->em);

        $this->monday = new \DateTimeImmutable('today')->modify('monday this week');
    }

    /**
     * THE SWAPS REGISTER SAYS WHAT HAPPENED TO EACH OFFER, in the design's
     * words: the station by its code, and after the state chip a phrase
     * for that state — "sent 11:38, not answered", "accepted on the
     * handset 16:02", "declined 09:14 — no reason given", "withdrawn by …".
     * An offer's sent time is the instant its identifier was minted.
     */
    public function testTheSwapsRegisterSaysWhatHappenedToEachOffer(): void
    {
        $swaps = static::getContainer()->get('test_public.'.SwapService::class);
        self::assertInstanceOf(SwapService::class, $swaps);
        $other = new User()->setPassword('x')->setEmail('other@example.test')->setFirstName('Oren')->setLastName('Other');
        $this->em->persist($other);
        $duties = [];
        for ($i = 0; $i < 4; ++$i) {
            $duties[] = $duty = new Duty($this->area, $this->gate, $this->ada, 'day', $this->monday->modify(\sprintf('+%d days', $i)));
            $this->em->persist($duty);
        }
        $this->em->flush();

        $at = new \DateTimeImmutable('today 09:14');
        $swaps->offer($duties[0], $other);
        $swaps->accept($swaps->offer($duties[1], $other), $at->setTime(16, 2));
        $swaps->decline($swaps->offer($duties[2], $other), $at);
        $swaps->withdraw($swaps->offer($duties[3], $other, $this->ada), $at);
        $this->em->flush();

        $rows = $this->open()->filter('h2.zone + .grid > .c')->eq(1)->filter('.rln')->each(static fn (Crawler $r): string => html_entity_decode(preg_replace('/\s+/', ' ', trim($r->text())) ?? ''));
        self::assertCount(4, $rows);
        $all = implode("\n", $rows);

        self::assertStringContainsString(' at '.$this->gate->getCode(), $all, 'The station by its code.');
        self::assertStringNotContainsString(' at '.$this->gate->getName(), $all);
        self::assertMatchesRegularExpression('/takes .* offered sent \d\d:\d\d, not answered/', $all);
        self::assertMatchesRegularExpression('/took .* accepted accepted on the handset 16:02/', $all);
        self::assertMatchesRegularExpression('/asked for .* declined declined 09:14 — no reason given/', $all);
        self::assertMatchesRegularExpression('/asked for .* withdrawn withdrawn by /', $all);
    }

    private function aStation(string $name, string $code): Station
    {
        $station = new Station()
            ->setArea($this->area)
            ->setName($name)
            ->setCode($code)
            ->setPoint('{"type":"Point","coordinates":[12.3,-5.7]}');
        $this->em->persist($station);

        return $station;
    }

    private function signIn(): void
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => FixedManageVoter::MANAGER_EMAIL]);
        self::assertInstanceOf(User::class, $user);
        $this->client->loginUser($user);
    }

    private function open(string $query = ''): Crawler
    {
        $this->signIn();
        $crawler = $this->client->request('GET', $this->url().($query ? '?'.$query : ''));
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function url(): string
    {
        return '/areas/'.$this->area->getUuidString().'/modules/roster/week';
    }

    /** The gate filled from a ring of nothing but day watches. */
    private function aFilledGate(): void
    {
        $watches = static::getContainer()->get('test_public.'.StationWatchService::class);
        self::assertInstanceOf(StationWatchService::class, $watches);
        $patterns = static::getContainer()->get('test_public.'.PatternService::class);
        self::assertInstanceOf(PatternService::class, $patterns);

        $watch = $watches->addToRoster($this->gate);
        $watch->expect(['day']);
        // AND THE NUMBER THE PLACE NAMES: cover is counted against it.
        $watch->setNeedsPerShift(['day' => 2]);
        $patterns->applyTo($watch, $patterns->create($this->area, Cycle::of(['day'])));
        $watch->filledFrom($this->monday, $this->monday->modify('+41 days'));
        $this->em->flush();
    }

    /**
     * THE TOKEN OFF THE RENDERED PAGE, and off the SHEET CARD: the card
     * always carries one because the fold preference posts, while the fill
     * row is only drawn where there is something to fill.
     */
    private function token(Crawler $crawler): string
    {
        $token = $crawler->filter('.sheetcard')->attr('data-roster--sheet-folds-token-value');
        self::assertIsString($token);

        return $token;
    }

    /** ONE BAND PER STATION ON THE AREA'S BOOKS, and a row per ranger. */
    public function testTheSheetIsOneBandPerStationAndOneRowPerRanger(): void
    {
        $crawler = $this->open();

        self::assertCount(1, $crawler->filter('table.fg-rota.csheet'));
        self::assertCount(2, $crawler->filter('tr.stfold'), 'A band per station, whether anybody is stationed there or not.');
        self::assertCount(2, $crawler->filter('tr.strow'));
        self::assertStringContainsString('north gate post', $crawler->filter('tr.stfold')->eq(0)->text());
        self::assertStringContainsString('nobody stationed here', $crawler->filter('tr.stfold')->eq(1)->text());
    }

    /** TWO WEEKS IS THE DEFAULT, and the chip moves it to one or four. */
    public function testTheWindowIsTwoWeeksAndTheChipResizesIt(): void
    {
        self::assertCount(14, $this->open()->filter('thead th:not(.who)'));
        self::assertCount(7, $this->open('weeks=1')->filter('thead th:not(.who)'));
        self::assertCount(28, $this->open('weeks=4')->filter('thead th:not(.who)'));
        // AND THERE IS NO THREE: anything between is a stretched fortnight,
        // so an asked-for three falls back rather than drawing one.
        self::assertCount(14, $this->open('weeks=3')->filter('thead th:not(.who)'));
    }

    /** AND THE WEEKS ARE REMEMBERED, per person. */
    public function testTheWeeksInViewAreRememberedPerPerson(): void
    {
        $this->open('weeks=4');
        $this->em->clear();

        self::assertCount(28, $this->open()->filter('thead th:not(.who)'), 'Coming back gets the month back.');

        $remembered = $this->em->getRepository(SheetPreference::class)->findAll();
        self::assertCount(1, $remembered);
        self::assertSame(4, $remembered[0]->getWeeks());
    }

    /** IT OPENS ON THE CURRENT WEEK, always starting on a monday. */
    public function testItOpensOnTheCurrentWeekAndStartsOnAMonday(): void
    {
        $crawler = $this->open();

        $first = $crawler->filter('thead th:not(.who) time')->eq(0)->attr('datetime');
        self::assertSame($this->monday->format('Y-m-d'), $first);
        self::assertCount(1, $crawler->filter('thead th.today'), 'Today has its column.');
    }

    /**
     * A GAP SHOWS AT ONCE — the one thing this tab exists for — AND IT IS
     * THE STATION'S. RULED 21 sep: one cover token per day on the band's
     * own head row, and not a mark on any ranger.
     */
    public function testAGapShowsAtOnceOnTheStationsOwnRow(): void
    {
        $this->aFilledGate();

        $crawler = $this->open();

        self::assertCount(0, $crawler->filter('.cl.unf'), 'A ranger is on a shift or off, and never a gap.');

        $gate = $crawler->filter('tr.stfold[data-fold="ST-01"]');
        self::assertCount(14, $gate->filter('.cvr'), 'One cover token under every day column.');
        self::assertCount(14, $gate->filter('.cvr.none'), 'The station needs two a day and nobody is on.');
        self::assertSame('0/2', $gate->filter('.cvr')->eq(0)->text());
        self::assertStringContainsString('14 short this window', $crawler->filter('tr.stfold')->eq(0)->text());
    }

    /** A DAY WITH A DUTY IS THE CALENDAR'S BAR, in the shift's own colour. */
    public function testADutyDrawsTheCalendarsBar(): void
    {
        $this->em->persist(new Duty($this->area, $this->gate, $this->ada, 'day', $this->monday));
        $this->em->flush();

        $bar = $this->open()->filter('.cl.bar')->eq(0);

        self::assertCount(1, $bar->filter('i.dot'));
        self::assertIsString($bar->attr('data-cat'));
        self::assertStringContainsString('day', $bar->text());
    }

    /** A FOLDED STATION STILL STATES HOW MANY DAYS IT IS SHORT, and its rows are hidden. */
    public function testAFoldedStationHidesItsRowsAndKeepsItsGapCount(): void
    {
        $this->aFilledGate();
        $this->signIn();

        $manager = $this->em->getRepository(User::class)->findOneBy(['email' => FixedManageVoter::MANAGER_EMAIL]);
        self::assertInstanceOf(User::class, $manager);
        $this->em->persist(new SheetPreference($manager, $this->area)->fold(['ST-01']));
        $this->em->flush();

        $crawler = $this->client->request('GET', $this->url());

        $band = $crawler->filter('tr.stfold')->eq(0);
        self::assertStringContainsString('shut', (string) $band->attr('class'));
        self::assertStringContainsString('14 short this window', $band->text(), 'Folding is for length and may never hide a gap.');
        self::assertIsString($crawler->filter('tr.strow')->eq(0)->attr('hidden'));
    }

    /** AND THE FOLDS ARE REMEMBERED, per person. */
    public function testFoldsAreRemembered(): void
    {
        $crawler = $this->open();

        $this->client->request('POST', '/areas/'.$this->area->getUuidString().'/modules/roster/sheet/prefs', [
            '_token' => $this->token($crawler),
            'folded' => ['ST-02'],
        ]);

        self::assertResponseStatusCodeSame(204);

        $this->em->clear();
        self::assertStringContainsString('shut', (string) $this->open()->filter('tr.stfold')->eq(1)->attr('class'));
    }

    /** THE STATION FILTER NARROWS THE SHEET, and still lists every station. */
    public function testTheStationFilterNarrowsTheSheet(): void
    {
        $crawler = $this->open('station='.$this->gate->getUuidString());

        self::assertCount(1, $crawler->filter('tr.stfold'));
        self::assertStringContainsString('north gate post', $crawler->filter('tr.stfold')->text());
        self::assertCount(3, $crawler->filter('.i-ddmenu[aria-label="station"] .i-ddopt'), 'All stations, plus the two.');
    }

    /** FILLING FROM A PATTERN WRITES THE DAYS, and says how many. */
    public function testAManagerFillsAStationFromAPattern(): void
    {
        $patterns = static::getContainer()->get('test_public.'.PatternService::class);
        self::assertInstanceOf(PatternService::class, $patterns);
        $watches = static::getContainer()->get('test_public.'.StationWatchService::class);
        self::assertInstanceOf(StationWatchService::class, $watches);
        $watches->addToRoster($this->gate)->expect(['day']);
        $pattern = $patterns->create($this->area, Cycle::of(['day', Cycle::OFF]));
        $this->em->flush();

        $crawler = $this->open();

        $this->client->request('POST', '/areas/'.$this->area->getUuidString().'/modules/roster/sheet/fill', [
            '_token' => $this->token($crawler),
            'station' => (string) $this->gate->getUuidString(),
            'pattern' => (string) $pattern->getUuid(),
            'from' => $this->monday->format('Y-m-d'),
        ]);

        self::assertResponseRedirects();
        self::assertGreaterThan(0, $this->em->getRepository(Duty::class)->count(['station' => $this->gate]));
    }

    /** AND A PREVIEW WRITES NOTHING. */
    public function testAPreviewWritesNothing(): void
    {
        $patterns = static::getContainer()->get('test_public.'.PatternService::class);
        self::assertInstanceOf(PatternService::class, $patterns);
        $watches = static::getContainer()->get('test_public.'.StationWatchService::class);
        self::assertInstanceOf(StationWatchService::class, $watches);
        $watches->addToRoster($this->gate)->expect(['day']);
        $pattern = $patterns->create($this->area, Cycle::of(['day']));
        $this->em->flush();

        $crawler = $this->open();

        $this->client->request('POST', '/areas/'.$this->area->getUuidString().'/modules/roster/sheet/fill', [
            '_token' => $this->token($crawler),
            'station' => (string) $this->gate->getUuidString(),
            'pattern' => (string) $pattern->getUuid(),
            'from' => $this->monday->format('Y-m-d'),
            'preview' => '1',
        ]);

        self::assertResponseRedirects();
        self::assertSame(0, $this->em->getRepository(Duty::class)->count(['station' => $this->gate]));
    }

    /**
     * AND THERE IS NO SIXTH VERB. "Mark the day unfilled" went with the
     * cell kind it wrote: a ranger is on a shift or off, so the menu
     * offers five things and the server refuses the retired one.
     */
    public function testTheRetiredUnfillVerbIsNeitherOfferedNorAccepted(): void
    {
        $duty = new Duty($this->area, $this->gate, $this->ada, 'day', $this->monday);
        $this->em->persist($duty);
        $this->em->flush();

        $crawler = $this->open();
        self::assertCount(1, $crawler->filter('.pmenuwrap .pmenu'), 'A day with a watch on it opens a menu.');
        // Four on an unedited day — the fifth, clearing the hand mark,
        // appears once there is a mark to clear. Never a sixth.
        self::assertCount(4, $crawler->filter('.pmenuwrap .pmenu .dmr'));
        self::assertStringNotContainsString('Mark unfilled', $crawler->filter('.pmenuwrap .pmenu')->text());

        $this->client->request('POST', '/areas/'.$this->area->getUuidString().'/modules/roster/sheet/day', [
            '_token' => $this->token($crawler),
            'duty' => (string) $duty->getUuid(),
            'op' => 'unfill',
        ]);

        self::assertResponseRedirects();
        $this->em->clear();

        self::assertSame(1, $this->em->getRepository(Duty::class)->count(['station' => $this->gate]), 'The watch stands; the verb is gone.');
        self::assertCount(0, $this->em->getRepository(EditedDay::class)->findAll());
    }

    /** AND CLEARING THE MARK HANDS THE DAY BACK TO THE PATTERN. */
    public function testClearingTheMarkHandsTheDayBack(): void
    {
        $this->em->persist(new EditedDay($this->gate, $this->monday, null, new \DateTimeImmutable(), $this->ada, true));
        $this->em->flush();

        $crawler = $this->open();

        $this->client->request('POST', '/areas/'.$this->area->getUuidString().'/modules/roster/sheet/mark/clear', [
            '_token' => $this->token($crawler),
            'station' => (string) $this->gate->getUuidString(),
            'person' => (string) $this->ada->getUuidString(),
            'day' => $this->monday->format('Y-m-d'),
        ]);

        self::assertResponseRedirects();
        $this->em->clear();

        self::assertSame([], $this->em->getRepository(EditedDay::class)->findAll());
    }

    /** AN AREA WITH NO STATION SAYS SO, rather than drawing an empty grid. */
    public function testAnAreaWithNoStationSaysSo(): void
    {
        foreach ($this->em->getRepository(Posting::class)->findAll() as $posting) {
            $this->em->remove($posting);
        }
        $this->em->flush();

        $this->em->remove($this->gate);
        $this->em->remove($this->rim);
        $this->em->flush();

        self::assertStringContainsString(
            'No station is on the area’s books',
            $this->open()->filter('body')->text(),
        );
    }
}
