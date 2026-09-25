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
use Uhifadhi\Roster\Entity\StationWatch;
use Uhifadhi\Roster\Enum\ForbiddenDay;
use Uhifadhi\Roster\Enum\NightThenDay;
use Uhifadhi\Roster\Enum\RuleKind;
use Uhifadhi\Roster\Enum\RuleUnit;
use Uhifadhi\Roster\Model\RuleValue;
use Uhifadhi\Roster\Service\RosterSettingsService;
use Uhifadhi\Roster\Service\ShiftRuleService;
use Uhifadhi\Roster\Service\StationWatchService;
use Uhifadhi\Roster\Tests\FreshDatabase;
use Uhifadhi\Roster\Tests\Integration\Fixtures\FixedManageVoter;

/**
 * THE CONFIGURE PAGE, OVER REAL HTTP — the three sections, who may save them,
 * and what a save actually stores.
 *
 * A GREEN SERVICE TEST IS NOT A WORKING PAGE. Everything here goes through
 * the router, the registry's per-area gate, the real authorization checker
 * and a real CSRF token read off the rendered form, because those four are
 * where a configure page actually breaks.
 */
final class ConfigurePageTest extends WebTestCase
{
    use EveryAreaRunsTheRoster;
    use FreshDatabase;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private AreaOfInterest $area;
    private Station $gate;

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

        $this->gate = new Station()
            ->setArea($this->area)
            ->setName('north gate post')
            ->setCode('ST-01')
            ->setPoint('{"type":"Point","coordinates":[12.3,-5.7]}');
        $this->em->persist($this->gate);

        // A post the area registers and the roster does NOT work — the whole
        // point of "a post with no watch is never counted".
        $this->em->persist(new Station()
            ->setArea($this->area)
            ->setName('west outpost')
            ->setCode('ST-02')
            ->setPoint('{"type":"Point","coordinates":[12.25,-5.75]}'));

        $this->em->persist(new User()->setPassword('x')->setEmail(FixedManageVoter::MANAGER_EMAIL)->setFirstName('Mara')->setLastName('Manager'));
        $this->em->persist(new User()->setPassword('x')->setEmail(FixedManageVoter::READER_EMAIL)->setFirstName('Rafi')->setLastName('Reader'));

        $this->everyAreaRunsTheRoster($this->em);
    }

    private function signIn(string $email): void
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user);
        $this->client->loginUser($user);
    }

    private function url(string $section): string
    {
        return '/areas/'.$this->area->getUuidString().'/modules/roster/'.$section;
    }

    private function watches(): StationWatchService
    {
        $service = static::getContainer()->get('test_public.'.StationWatchService::class);
        self::assertInstanceOf(StationWatchService::class, $service);

        return $service;
    }

    /** The fixture area, re-read after a request has rebooted the kernel. */
    private function areaAgain(): AreaOfInterest
    {
        $area = $this->em->getRepository(AreaOfInterest::class)->findOneBy(['name' => 'demo reserve']);
        self::assertInstanceOf(AreaOfInterest::class, $area);

        return $area;
    }

    private function rules(): ShiftRuleService
    {
        $service = static::getContainer()->get('test_public.'.ShiftRuleService::class);
        self::assertInstanceOf(ShiftRuleService::class, $service);

        return $service;
    }

    private function settings(): RosterSettingsService
    {
        $service = static::getContainer()->get('test_public.'.RosterSettingsService::class);
        self::assertInstanceOf(RosterSettingsService::class, $service);

        return $service;
    }

    /**
     * THE MODULE'S FRONT DOOR. The tile links straight here, so if this is not
     * 200 the catalogue is linking at nothing.
     */
    public function testTheOverviewTabIsTheModulesFrontDoor(): void
    {
        $this->signIn(FixedManageVoter::READER_EMAIL);

        $crawler = $this->client->request('GET', '/areas/'.$this->area->getUuidString().'/modules/roster');

        self::assertResponseIsSuccessful();
        // The identity band, on the tab it is byte-identical on.
        self::assertSame(1, $crawler->filter('.factband')->count());

        $band = $crawler->filter('.factband')->text();
        foreach (['Stations', 'Rangers stationed', 'Shifts', 'Exceptions'] as $cell) {
            self::assertStringContainsString($cell, $band, 'The band is the ruled four.');
        }

        // AND THE WORD IS STATION. "Rangers 34 posted at 12 posts" was the
        // last of "post" on this surface, ruled 21 sep.
        self::assertStringNotContainsString('post', mb_strtolower($band));
    }

    /**
     * THE BAND SAYS HOW THE PARK IS SET UP, so a station the area registers
     * and nobody has told what it stands is in the denominator and not in
     * the numerator — which is the whole of "12 · 11 run at least one
     * shift".
     */
    public function testTheBandCountsStationsRunningAShiftAgainstTheAreasOwnRegister(): void
    {
        $watch = $this->watches()->addToRoster($this->gate);
        $watch->expect(['day']);
        $this->em->flush();

        $this->signIn(FixedManageVoter::READER_EMAIL);

        $crawler = $this->client->request('GET', '/areas/'.$this->area->getUuidString().'/modules/roster');
        $band = $crawler->filter('.factband')->text();

        self::assertStringContainsString('2', $band, 'The area registers two.');
        self::assertStringContainsString('1 runs at least one shift', $band, 'And one of them stands a shift.');
    }

    /**
     * AND A STATION THAT HAS JOINED THE BOOKS AND BEEN TOLD NOTHING IS NOT
     * COUNTED AS RUNNING ONE. Joining is not declaring a watch, and a band
     * that conflated the two would report a park as staffed the moment
     * somebody added a row.
     */
    public function testAStationOnTheBooksThatStandsNothingIsNotCountedAsRunningAShift(): void
    {
        $this->watches()->addToRoster($this->gate);
        $this->em->flush();

        $this->signIn(FixedManageVoter::READER_EMAIL);

        $crawler = $this->client->request('GET', '/areas/'.$this->area->getUuidString().'/modules/roster');

        self::assertStringContainsString('none stands a shift yet', $crawler->filter('.factband')->text());
    }

    /**
     * AND THE EXCEPTIONS CELL COUNTS PLACES, NOT ROWS: one station with
     * three of its own is one station to go and look at.
     */
    public function testTheBandCountsStationsWithTheirOwnRulesAndNotTheRows(): void
    {
        $this->watches()->addToRoster($this->gate);
        $this->em->flush();
        $this->rules()->setException($this->gate, RuleKind::LateAfter, new RuleValue(1.0, RuleUnit::Hours));
        $this->rules()->setException($this->gate, RuleKind::CheckInWithin, new RuleValue(800.0, RuleUnit::Metres));

        $this->signIn(FixedManageVoter::READER_EMAIL);

        $crawler = $this->client->request('GET', '/areas/'.$this->area->getUuidString().'/modules/roster');

        self::assertStringContainsString(
            'station with its own settings',
            $crawler->filter('.factband')->text(),
            'Two rules at one place is one place to go and look at.',
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function sections(): iterable
    {
        yield 'patterns' => ['patterns'];
        yield 'rotation' => ['rotation'];
        yield 'watches' => ['watches'];
        yield 'settings' => ['settings'];
    }

    /**
     * EVERY SECTION RENDERS, and every one of them links THIS MODULE's
     * stylesheet — which is the reason all three keep an address of their own
     * rather than being bodies the shell renders inside its own page.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('sections')]
    public function testEverySectionRendersAndLinksTheModulesOwnStylesheet(string $section): void
    {
        $this->signIn(FixedManageVoter::MANAGER_EMAIL);

        $crawler = $this->client->request('GET', $this->url($section));

        self::assertResponseIsSuccessful();
        // The path is matched WITHOUT its digest: AssetMapper content-versions
        // the file, so asserting the bare name would be asserting that
        // versioning is off.
        self::assertMatchesRegularExpression('#bundles/uhifadhiroster/roster[.-][^"]*\.css#', $this->client->getResponse()->getContent() ?: '');
        // It wears the configure page's own heading, not a heading of its own.
        self::assertStringContainsString('configure', $crawler->filter('h1')->text());
    }

    /** The band repeats on every configure section, byte-identical. */
    #[\PHPUnit\Framework\Attributes\DataProvider('sections')]
    public function testTheIdentityBandRepeatsOnEverySection(string $section): void
    {
        $this->signIn(FixedManageVoter::MANAGER_EMAIL);

        $crawler = $this->client->request('GET', $this->url($section));

        self::assertSame(1, $crawler->filter('.factband')->count());
    }

    /**
     * A READER SEES THE PAGE AND NO SAVE BUTTON. Withheld rather than
     * disabled: a greyed control tells somebody a thing exists and they are
     * not trusted with it, which is a worse product than not offering it.
     */
    public function testAReaderSeesTheSettingsAndIsOfferedNoSave(): void
    {
        $this->signIn(FixedManageVoter::READER_EMAIL);

        $crawler = $this->client->request('GET', $this->url('settings'));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Off days on a tour', $crawler->filter('body')->text());
        self::assertSame(0, $crawler->filter('button[type=submit]')->count());
    }

    /**
     * SAVING THE SETTINGS STORES THE SIX. The token is read off the rendered
     * form rather than spelled out here: a test that hardcoded it would still
     * pass the day the page stopped rendering one.
     */
    public function testAManagerSavesTheSettings(): void
    {
        $this->signIn(FixedManageVoter::MANAGER_EMAIL);
        $crawler = $this->client->request('GET', $this->url('settings'));
        $token = $crawler->filter('input[name=_token]')->attr('value');
        self::assertIsString($token);

        $this->client->request('POST', $this->url('settings'), [
            '_token' => $token,
            'off_day_has_no_state' => '0',
            'leave_approval_shown' => '1',
        ]);

        self::assertResponseRedirects($this->url('settings'));

        $this->em->clear();
        $settings = $this->settings()->forArea($this->em->getRepository(AreaOfInterest::class)->findOneBy(['name' => 'demo reserve']) ?? throw new \LogicException('The fixture area vanished.'));

        self::assertFalse($settings->offDayHasNoState());
        self::assertTrue($settings->isLeaveApprovalShown());
    }

    /**
     * AND WHAT THIS PAGE DOES NOT OWN, IT DOES NOT WRITE. The ping interval
     * is the area's and the default catchment is a rule on the Watches
     * card, so a post here naming either changes nothing.
     */
    public function testTheSettingsPageCannotWriteWhatItDoesNotOwn(): void
    {
        $this->area->setPingIntervalMinutes(45);
        $this->em->flush();

        $this->signIn(FixedManageVoter::MANAGER_EMAIL);
        $crawler = $this->client->request('GET', $this->url('settings'));
        $token = $crawler->filter('input[name=_token]')->attr('value');
        self::assertIsString($token);

        $this->client->request('POST', $this->url('settings'), [
            '_token' => $token,
            'ping_interval_minutes' => '5',
            'default_catchment_metres' => '900',
        ]);

        $this->em->clear();
        $area = $this->areaAgain();

        self::assertSame(45, $area->getPingIntervalMinutes(), 'The area is the one home for it.');
        self::assertNotSame(900, $this->settings()->forArea($area)->getDefaultCatchmentMetres());
    }

    /** A reader who posts anyway is refused, token or no token. */
    public function testAReaderCannotSaveTheSettings(): void
    {
        $this->signIn(FixedManageVoter::MANAGER_EMAIL);
        $crawler = $this->client->request('GET', $this->url('settings'));
        $token = $crawler->filter('input[name=_token]')->attr('value');
        self::assertIsString($token);

        $this->signIn(FixedManageVoter::READER_EMAIL);
        $this->client->request('POST', $this->url('settings'), ['_token' => $token, 'ping_interval_minutes' => '5']);

        self::assertResponseStatusCodeSame(403);
    }

    /** A form that did not come from this page is refused. */
    public function testASaveWithoutAValidTokenIsRefused(): void
    {
        $this->signIn(FixedManageVoter::MANAGER_EMAIL);

        $this->client->request('POST', $this->url('settings'), ['_token' => 'not-the-token', 'ping_interval_minutes' => '5']);

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * PING EVERY IS THE AREA'S, SHOWN HERE READ-ONLY. The row states the
     * area's number, says whose it is in one fragment, and carries the door
     * to the area's settings — no control, and nothing for a station to
     * overrule.
     */
    public function testThePingRowShowsTheAreasValueReadOnlyWithADoor(): void
    {
        $this->area->setPingIntervalMinutes(45);
        $this->em->flush();

        $this->signIn(FixedManageVoter::MANAGER_EMAIL);
        $crawler = $this->client->request('GET', $this->url('watches'));
        self::assertResponseIsSuccessful();

        self::assertCount(0, $crawler->filter('[name="rule_value_ping_every"]'), 'No control: the area owns it.');
        self::assertCount(0, $crawler->filter('[name="rule_unit_ping_every"]'));

        $row = $crawler->filter('.rl.ro');
        self::assertCount(1, $row, 'One read-only row.');
        self::assertSame('Ping every', trim($row->filter('.k')->text()));
        self::assertSame('45 minutes', trim($row->filter('.v')->text()));
        self::assertStringContainsString('set on the area', $row->filter('.m')->text());
        self::assertSame(
            '/areas/'.$this->area->getUuidString().'/configure/settings',
            $row->filter('.m a')->attr('href'),
            'The door goes to the area\'s settings section.',
        );

        self::assertCount(0, $crawler->filter('template option[value="ping_every"]'), 'A station is not offered its own.');
    }

    /** An area that sets none shows the default it runs at; a reader gets the fact without the door. */
    public function testThePingRowShowsTheDefaultWhereTheAreaSetsNone(): void
    {
        $this->signIn(FixedManageVoter::READER_EMAIL);
        $crawler = $this->client->request('GET', $this->url('watches'));

        self::assertSame('30 minutes', trim($crawler->filter('.rl.ro .v')->text()));
        self::assertCount(0, $crawler->filter('.rl.ro .m a'), 'No door into settings this viewer may not open.');
    }

    /**
     * SAVING THE RULES LEAVES THE AREA'S INTERVAL ALONE, even when a post
     * names one: the card no longer sends it, and the service would not
     * write it if it did.
     */
    public function testSavingTheRulesLeavesTheAreasPingIntervalAlone(): void
    {
        $this->area->setPingIntervalMinutes(45);
        $this->em->flush();

        $this->signIn(FixedManageVoter::MANAGER_EMAIL);
        $crawler = $this->client->request('GET', $this->url('watches'));
        $token = $crawler->filter('input[name=_token]')->attr('value');
        self::assertIsString($token);

        $this->client->request('POST', '/areas/'.$this->area->getUuidString().'/modules/roster/rules', [
            '_token' => $token,
            'rule_value_ping_every' => '5',
            'rule_unit_ping_every' => 'minutes',
            'rule_value_late_after' => '3',
            'rule_unit_late_after' => 'hours',
        ]);

        self::assertResponseRedirects();

        $this->em->clear();
        $area = $this->areaAgain();
        self::assertSame(45, $area->getPingIntervalMinutes());
        self::assertSame('3 hours', $this->rules()->forArea($area)[RuleKind::LateAfter->value]->label());
        self::assertArrayNotHasKey(RuleKind::PingEvery->value, $this->rules()->forArea($area), 'The roster keeps no copy.');
    }

    /**
     * THE FOUR FILLING RULES ARE ON THIS CARD, under one group head.
     *
     * RULED 21 sep: what a fill obeys is an AREA rule, so it is set here
     * and the sheet's fill row only states it. Two of the four are picked
     * and not measured, and the row draws that difference — a select, not
     * a number nobody would know how to write.
     */
    public function testTheRulesCardCarriesTheFourAFillObeys(): void
    {
        $this->signIn(FixedManageVoter::MANAGER_EMAIL);
        $crawler = $this->client->request('GET', $this->url('watches'));
        self::assertResponseIsSuccessful();

        self::assertSame(
            ['Filling'],
            $crawler->filter('.rgrphd')->each(static fn (\Symfony\Component\DomCrawler\Crawler $head): string => trim($head->text())),
            'One group head, over the rules that came across from the sheet.',
        );

        $card = $crawler->filter('.rb-body')->reduce(
            static fn (\Symfony\Component\DomCrawler\Crawler $body): bool => str_contains($body->text(), 'Rest between watches'),
        );
        self::assertGreaterThan(0, $card->count(), 'The filling rules live on the rules card.');

        $text = $card->text();
        foreach (['Rest between watches', 'Night then day', 'Fill ahead', 'A day the rules forbid'] as $rule) {
            self::assertStringContainsString($rule, $text);
        }

        // MEASURED, AND CHOSEN. The horizon is a number and a unit; what
        // to do about a night then a day is a choice with no number in it.
        self::assertCount(1, $crawler->filter('input[name="rule_value_fill_ahead"]'));
        self::assertCount(1, $crawler->filter('select[name="rule_unit_fill_ahead"] option[value="weeks"]'));
        self::assertCount(0, $crawler->filter('input[name="rule_value_night_then_day"]'));
        self::assertCount(3, $crawler->filter('select[name="rule_choice_night_then_day"] option'));
        self::assertCount(2, $crawler->filter('select[name="rule_choice_forbidden_day"] option'));
    }

    /** AND SAVING THE CARD WRITES BOTH SHAPES OF ANSWER. */
    public function testAManagerSavesTheFillingRules(): void
    {
        $this->signIn(FixedManageVoter::MANAGER_EMAIL);
        $crawler = $this->client->request('GET', $this->url('watches'));
        $token = $crawler->filter('input[name=_token]')->attr('value');
        self::assertIsString($token);

        $this->client->request('POST', '/areas/'.$this->area->getUuidString().'/modules/roster/rules', [
            '_token' => $token,
            'rule_value_rest_between' => '9',
            'rule_unit_rest_between' => 'hours',
            'rule_value_fill_ahead' => '3',
            'rule_unit_fill_ahead' => 'weeks',
            'rule_choice_night_then_day' => 'warn',
            'rule_choice_forbidden_day' => 'flagged',
        ]);

        self::assertResponseRedirects();

        $this->em->clear();
        $area = $this->areaAgain();
        self::assertSame('9 hours', $this->rules()->forArea($area)[RuleKind::RestBetween->value]->label());
        self::assertSame('3 weeks', $this->rules()->forArea($area)[RuleKind::FillAhead->value]->label());
        self::assertSame(NightThenDay::WarnMe, $this->rules()->choicesForArea($area)[RuleKind::NightThenDay->value]);
        self::assertSame(ForbiddenDay::FilledAndFlagged, $this->rules()->choicesForArea($area)[RuleKind::ForbiddenDay->value]);
    }

    /**
     * SAVING THE STATION TABLE STORES WHICH SHIFTS A STATION RUNS, and the
     * one rule it does differently — on its own row, never in a fourth card
     * naming stations the table already lists.
     */
    public function testAManagerSavesWhichShiftsAStationRuns(): void
    {
        $watch = $this->watches()->addToRoster($this->gate);
        $watch->expect(['day', 'night']);
        $this->em->flush();

        $this->signIn(FixedManageVoter::MANAGER_EMAIL);
        $crawler = $this->client->request('GET', $this->url('watches'));
        $token = $crawler->filter('input[name=_token]')->attr('value');
        self::assertIsString($token);

        $id = $this->gate->getId();
        $this->client->request('POST', $this->url('watches'), [
            '_token' => $token,
            'expects_'.$id => ['day'],
            'exception_'.$id.'_kind' => [RuleKind::LateAfter->value],
            'exception_'.$id.'_value' => ['1'],
            'exception_'.$id.'_unit' => [RuleUnit::Hours->value],
        ]);

        self::assertResponseRedirects($this->url('watches'));

        $this->em->clear();
        $stored = $this->em->getRepository(StationWatch::class)->findOneBy([]);
        self::assertInstanceOf(StationWatch::class, $stored);
        self::assertSame(['day'], $stored->getExpects());

        // AND IT REACHED THE COLUMN THE LIVE SURFACES READ. The rules are the
        // authoring model and the watch's window is their projection, so the
        // card and the day board cannot disagree for a single request.
        self::assertSame(60, $stored->getSilenceWindowMinutes(), 'Late after 1 hour is sixty minutes of silence.');
    }

    /**
     * AND REMOVING THE LINE IS HOW A STATION FOLLOWS THE AREA AGAIN. There is
     * no "same as the area" value to send: the row's silence is that answer,
     * which is why a save REPLACES a station's exceptions rather than
     * merging them, and why the only control beside one is a cross.
     */
    public function testAStationGoesBackToFollowingTheAreaWhenItsRowSaysNothing(): void
    {
        $watch = $this->watches()->addToRoster($this->gate);
        $this->em->flush();
        $this->rules()->setException($this->gate, RuleKind::LateAfter, new RuleValue(1.0, RuleUnit::Hours));
        self::assertSame(60, $watch->getSilenceWindowMinutes());

        $this->signIn(FixedManageVoter::MANAGER_EMAIL);
        $crawler = $this->client->request('GET', $this->url('watches'));
        $token = $crawler->filter('input[name=_token]')->attr('value');
        self::assertIsString($token);

        $this->client->request('POST', $this->url('watches'), ['_token' => $token]);
        self::assertResponseRedirects();

        // RE-READ, because a request reboots the kernel and the object above
        // belongs to the entity manager the previous one had.
        $this->em->clear();
        $stored = $this->em->getRepository(StationWatch::class)->findOneBy([]);
        self::assertInstanceOf(StationWatch::class, $stored);

        self::assertSame(
            RuleKind::LateAfter->standard()->toMinutes(),
            $stored->getSilenceWindowMinutes(),
            'Back on the area own answer.',
        );
        self::assertSame([], $this->rules()->exceptionsForArea($this->areaAgain()), 'And no row is left behind.');
    }

    /**
     * A POST DECLARED TO RUN NOTHING. The form sends no `expects_*` at all,
     * which has to mean "none" and not "leave it alone" — otherwise a watch
     * could never be emptied.
     */
    public function testAWatchCanBeEmptied(): void
    {
        $watch = $this->watches()->addToRoster($this->gate);
        $watch->expect(['day', 'night']);
        $this->em->flush();

        $this->signIn(FixedManageVoter::MANAGER_EMAIL);
        $crawler = $this->client->request('GET', $this->url('watches'));
        $token = $crawler->filter('input[name=_token]')->attr('value');
        self::assertIsString($token);

        $this->client->request('POST', $this->url('watches'), ['_token' => $token]);

        $this->em->clear();
        $stored = $this->em->getRepository(StationWatch::class)->findOneBy([]);
        self::assertInstanceOf(StationWatch::class, $stored);
        self::assertSame([], $stored->getExpects());
        self::assertTrue($stored->expectsNothing());
    }

    /**
     * A post the area registers and nobody put on the roster's books is NAMED
     * on the page rather than silently missing — "a post with no watch is
     * never counted" is only trustworthy if the page says which posts it
     * means.
     */
    public function testTheRotationSectionNamesThePostsThatRunNothing(): void
    {
        $this->signIn(FixedManageVoter::MANAGER_EMAIL);

        $crawler = $this->client->request('GET', $this->url('rotation'));

        self::assertStringContainsString('west outpost', $crawler->filter('body')->text());
        self::assertStringContainsString('never a hole', $crawler->filter('body')->text());
    }

    /**
     * WHERE AN AREA HAS PARKED THIS MODULE, EVERY PAGE HERE IS 404 — the
     * registry's gate, not this module's, and 404 rather than 403 because a
     * parked module is not withheld: the area is not running it.
     */
    public function testAnAreaThatDoesNotRunTheRosterHasNoRosterPages(): void
    {
        $elsewhere = new AreaOfInterest()->setSource('test fixture')->setName('other reserve')->setGeom(
            '{"type":"MultiPolygon","coordinates":[[[[13.2,-6.8],[13.5,-6.8],[13.5,-6.5],[13.2,-6.5],[13.2,-6.8]]]]}',
        );
        $this->em->persist($elsewhere);
        $this->em->flush();

        $this->signIn(FixedManageVoter::MANAGER_EMAIL);
        $this->client->request('GET', '/areas/'.$elsewhere->getUuidString().'/modules/roster');

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * THE DOOR IS WITHHELD FROM A READER, not disabled. A greyed control
     * tells somebody a thing exists and they are not trusted with it, which
     * is a worse product than not offering it.
     */
    public function testAReaderIsOfferedNoWayToPutAPostOnTheBooks(): void
    {
        $this->signIn(FixedManageVoter::READER_EMAIL);

        $crawler = $this->client->request('GET', $this->url('watches'));

        self::assertResponseIsSuccessful();
        self::assertSame(0, $crawler->filter('button:contains("Put it on the books")')->count());
    }

    /**
     * THE ADD ROW OFFERS THIS AREA'S POSTS AND ONLY THE ONES OFF THE BOOKS.
     * A post already worked would be a second entry for one record.
     */
    public function testTheAddRowOffersThePostsThatAreNotOnTheBooksYet(): void
    {
        $this->watches()->addToRoster($this->gate);
        $this->signIn(FixedManageVoter::MANAGER_EMAIL);

        $crawler = $this->client->request('GET', $this->url('watches'));
        $offered = $crawler->filter('#roster-add-post option')->each(
            static fn (\Symfony\Component\DomCrawler\Crawler $option): string => trim($option->text()),
        );

        self::assertCount(1, $offered);
        self::assertStringContainsString('west outpost', $offered[0]);
    }

    /**
     * A UUID OUT OF A FORM NAMES ANY POST IN THE INSTALLATION. The area is
     * part of the lookup and not a check after it, so a form posted from
     * one park cannot put another park's gate on these books.
     */
    public function testAPostFromAnotherAreaCannotBePutOnTheseBooks(): void
    {
        $elsewhere = new AreaOfInterest()->setSource('test fixture')->setName('other reserve')->setGeom(
            '{"type":"MultiPolygon","coordinates":[[[[13.2,-6.8],[13.5,-6.8],[13.5,-6.5],[13.2,-6.5],[13.2,-6.8]]]]}',
        );
        $this->em->persist($elsewhere);
        $theirs = new Station()
            ->setArea($elsewhere)
            ->setName('their gate')
            ->setCode('ST-99')
            ->setPoint('{"type":"Point","coordinates":[13.3,-6.7]}');
        $this->em->persist($theirs);
        $this->em->flush();

        $this->signIn(FixedManageVoter::MANAGER_EMAIL);
        $crawler = $this->client->request('GET', $this->url('watches'));
        $token = $crawler->filter('input[name=_token]')->attr('value');
        self::assertIsString($token);

        $this->client->request('POST', $this->url('watches').'/add', [
            '_token' => $token,
            'station' => (string) $theirs->getUuidString(),
        ]);

        self::assertResponseRedirects();
        self::assertNull($this->watches()->forStation($theirs));
    }

    /** A reader who posts the add anyway is refused. */
    public function testAReaderCannotPutAPostOnTheBooks(): void
    {
        $this->signIn(FixedManageVoter::MANAGER_EMAIL);
        $crawler = $this->client->request('GET', $this->url('watches'));
        $token = $crawler->filter('input[name=_token]')->attr('value');
        self::assertIsString($token);

        $this->signIn(FixedManageVoter::READER_EMAIL);
        $this->client->request('POST', $this->url('watches').'/add', [
            '_token' => $token,
            'station' => (string) $this->gate->getUuidString(),
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->watches()->forStation($this->gate));
    }

    /**
     * A POST THAT STANDS NO WATCH IS NOT OFFERED A RING, and the section
     * says why rather than drawing an empty picker.
     */
    public function testAPostWithNoWatchIsNotOfferedARing(): void
    {
        $this->watches()->addToRoster($this->gate);
        $this->signIn(FixedManageVoter::MANAGER_EMAIL);

        $crawler = $this->client->request('GET', $this->url('rotation'));

        self::assertSame(0, $crawler->filter('button:contains("Declare the rotation")')->count());
        self::assertStringContainsString('No post is ready for a ring', $crawler->filter('body')->text());
    }

    /** And declaring one anyway is refused with a sentence rather than written. */
    public function testDeclaringARingForAPostWithNoWatchIsRefused(): void
    {
        $this->watches()->addToRoster($this->gate);
        $this->signIn(FixedManageVoter::MANAGER_EMAIL);

        $crawler = $this->client->request('GET', $this->url('rotation'));
        $token = $crawler->filter('input[name=_token]')->attr('value');
        self::assertIsString($token);

        $this->client->request('POST', $this->url('rotation').'/new', [
            '_token' => $token,
            'station' => (string) $this->gate->getUuidString(),
            'preset' => 'one_of_each_then_off',
        ]);

        self::assertResponseRedirects();
        $this->em->clear();
        self::assertSame([], $this->em->getRepository(\Uhifadhi\Roster\Entity\Rotation::class)->findAll());
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // The framework's debug error handler is registered during the test
        // and never popped; PHPUnit flags that as risky. Pop whatever is left.
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
