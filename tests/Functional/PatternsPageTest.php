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
use Uhifadhi\Roster\Controller\RosterPatternsController;
use Uhifadhi\Roster\Entity\Pattern;
use Uhifadhi\Roster\Model\Cycle;
use Uhifadhi\Roster\Service\PatternService;
use Uhifadhi\Roster\Service\ShiftVocabularyService;
use Uhifadhi\Roster\Service\StationWatchService;
use Uhifadhi\Roster\Tests\FreshDatabase;
use Uhifadhi\Roster\Tests\Integration\Fixtures\FixedManageVoter;

/**
 * THE PATTERNS SECTION, OVER REAL HTTP — the register, the sentence editor,
 * and the three writes behind it.
 *
 * NOTHING IS TYPED BUT A NUMBER AND A SHIFT. Ruled 21 sep: there is no name
 * field on this page or anywhere else, so the assertions below are all about
 * a name the SERVER derived from a cycle somebody said — which is the only
 * way the register, the editor and the sheet's fill row can be trusted to
 * print the same words.
 */
final class PatternsPageTest extends WebTestCase
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

        $this->area = new AreaOfInterest()->setSource('test fixture')->setName('seed reserve')->setGeom(
            '{"type":"MultiPolygon","coordinates":[[[[12.2,-5.8],[12.5,-5.8],[12.5,-5.5],[12.2,-5.5],[12.2,-5.8]]]]}',
        );
        $this->em->persist($this->area);

        $this->gate = new Station()
            ->setArea($this->area)
            ->setName('north gate post')
            ->setCode('ST-01')
            ->setPoint('{"type":"Point","coordinates":[12.3,-5.7]}');
        $this->em->persist($this->gate);

        $this->em->persist(new User()->setPassword('x')->setEmail(FixedManageVoter::MANAGER_EMAIL)->setFirstName('Mara')->setLastName('Manager'));
        $this->em->persist(new User()->setPassword('x')->setEmail(FixedManageVoter::READER_EMAIL)->setFirstName('Rafi')->setLastName('Reader'));

        $this->everyAreaRunsTheRoster($this->em);

        // The area names its own shifts; reading the list is what seeds one.
        $this->vocabulary()->forArea($this->area);
    }

    private function url(string $query = ''): string
    {
        return '/areas/'.$this->area->getUuidString().'/modules/roster/patterns'.$query;
    }

    private function signIn(string $email): void
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user);
        $this->client->loginUser($user);
    }

    private function vocabulary(): ShiftVocabularyService
    {
        $service = static::getContainer()->get('test_public.'.ShiftVocabularyService::class);
        self::assertInstanceOf(ShiftVocabularyService::class, $service);

        return $service;
    }

    private function patterns(): PatternService
    {
        $service = static::getContainer()->get('test_public.'.PatternService::class);
        self::assertInstanceOf(PatternService::class, $service);

        return $service;
    }

    private function token(string $query = ''): string
    {
        $crawler = $this->client->request('GET', $this->url($query));
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('input[name=_token]')->attr('value');
        self::assertIsString($token);

        return $token;
    }

    /**
     * A FRESH AREA FILLS BY HAND, and the page says so rather than showing an
     * empty grid. The product ships no patterns on purpose.
     */
    public function testAFreshAreaOpensOnAnEmptyRegisterAndADoor(): void
    {
        $this->signIn(FixedManageVoter::MANAGER_EMAIL);
        $crawler = $this->client->request('GET', $this->url());

        self::assertResponseIsSuccessful();
        self::assertSame(0, $crawler->filter('.pregc')->count(), 'Nothing is shipped.');
        self::assertSame(1, $crawler->filter('.c.pnew')->count(), 'And the way to say one is on the page.');
        self::assertSame(1, $crawler->filter('.pgact a:contains("New pattern")')->count(), 'Creating one is a page-level act.');
    }

    /**
     * THE BLANK EDITOR IS A STATE OF THIS PAGE and carries no name field —
     * the derived name is a read-only title, and an empty cycle says so.
     */
    public function testTheBlankEditorOffersASentenceAndNoNameField(): void
    {
        $this->signIn(FixedManageVoter::MANAGER_EMAIL);
        $crawler = $this->client->request('GET', $this->url('?'.RosterPatternsController::NEW_QUERY));

        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('.ptool')->count());
        self::assertSame(1, $crawler->filter('.pdname')->count());
        self::assertSame(0, $crawler->filter('input[name="name"], input[name="label"]')->count(), 'Nobody types a name.');
        self::assertGreaterThan(0, $crawler->filter('.psbl [data-roster-days]')->count(), 'An editor opens with a part to change.');
    }

    /** SAY A CYCLE AND IT GETS A NAME — derived by the server, not sent by the page. */
    public function testSayingACycleCreatesAPatternTheServerNames(): void
    {
        $this->signIn(FixedManageVoter::MANAGER_EMAIL);
        $token = $this->token('?'.RosterPatternsController::NEW_QUERY);

        $this->client->request('POST', $this->url(), [
            '_token' => $token,
            RosterPatternsController::CYCLE_FIELD => json_encode([
                ['days' => 2, 'shift' => 'day'],
                ['days' => 2, 'shift' => 'night'],
                ['days' => 1, 'shift' => Cycle::OFF],
            ], \JSON_THROW_ON_ERROR),
        ]);
        self::assertResponseRedirects();

        $this->em->clear();
        $patterns = $this->em->getRepository(Pattern::class)->findAll();
        self::assertCount(1, $patterns);
        self::assertSame(5, $patterns[0]->length());
        self::assertSame('2 days of day, 2 days of night, 1 off', $this->patterns()->nameOf($patterns[0]));
    }

    /**
     * A CYCLE NAMING A SHIFT THIS AREA HAS NOT GOT IS REFUSED WHOLE, with a
     * sentence — not saved as a ring that would generate a watch nobody
     * could read.
     */
    public function testACycleNamingAShiftTheAreaHasNotGotIsRefused(): void
    {
        $this->signIn(FixedManageVoter::MANAGER_EMAIL);
        $token = $this->token('?'.RosterPatternsController::NEW_QUERY);

        $this->client->request('POST', $this->url(), [
            '_token' => $token,
            RosterPatternsController::CYCLE_FIELD => json_encode([['days' => 2, 'shift' => 'moonlight']], \JSON_THROW_ON_ERROR),
        ]);
        self::assertResponseRedirects();

        $this->em->clear();
        self::assertCount(0, $this->em->getRepository(Pattern::class)->findAll());
    }

    /**
     * EDITING ONE CHANGES THE CYCLE AND SAYS WHERE IT REACHES. The page
     * names the stations rather than counting them: the whole question a
     * reader has before they edit is which places it touches.
     */
    public function testTheEditorSaysWhereThePatternRunsAndWhatAnEditReaches(): void
    {
        $pattern = $this->patterns()->create($this->area, Cycle::of(['day', 'day', Cycle::OFF]));

        $watches = static::getContainer()->get('test_public.'.StationWatchService::class);
        self::assertInstanceOf(StationWatchService::class, $watches);
        $this->patterns()->applyTo($watches->addToRoster($this->gate), $pattern);

        $this->signIn(FixedManageVoter::MANAGER_EMAIL);
        $crawler = $this->client->request('GET', $this->url('?'.RosterPatternsController::OPEN_QUERY.'='.$pattern->getUuid()->toRfc4122()));

        self::assertResponseIsSuccessful();
        $where = $crawler->filter('.pwhere')->text();
        self::assertStringContainsString('north gate post', $where);
        self::assertStringContainsString('future fills only', $where);
    }

    /**
     * AND SAVING AN EDIT MOVES NOBODY. The cycle changes; a day already
     * planned at a station running it is untouched, which is what makes one
     * shared object safe to edit at all.
     */
    public function testSavingAnEditChangesTheCycleAndNothingElse(): void
    {
        $pattern = $this->patterns()->create($this->area, Cycle::of(['day', 'day', Cycle::OFF]));
        $uuid = $pattern->getUuid()->toRfc4122();

        $this->signIn(FixedManageVoter::MANAGER_EMAIL);
        $token = $this->token('?'.RosterPatternsController::OPEN_QUERY.'='.$uuid);

        $this->client->request('POST', $this->url(), [
            '_token' => $token,
            RosterPatternsController::OPEN_QUERY => $uuid,
            RosterPatternsController::CYCLE_FIELD => json_encode([
                ['days' => 4, 'shift' => 'day'],
                ['days' => 4, 'shift' => Cycle::OFF],
            ], \JSON_THROW_ON_ERROR),
        ]);
        self::assertResponseRedirects();

        $this->em->clear();
        $patterns = $this->em->getRepository(Pattern::class)->findAll();
        self::assertCount(1, $patterns, 'An edit is not a second pattern.');
        self::assertSame('4 days of day, 4 off', $this->patterns()->nameOf($patterns[0]));
    }

    /** DELETING ONE STOPS THE FILL AND KEEPS THE STATION on the books. */
    public function testDeletingAPatternLeavesTheStationItFilled(): void
    {
        $pattern = $this->patterns()->create($this->area, Cycle::of(['day', Cycle::OFF]));
        $uuid = $pattern->getUuid()->toRfc4122();

        $watches = static::getContainer()->get('test_public.'.StationWatchService::class);
        self::assertInstanceOf(StationWatchService::class, $watches);
        $this->patterns()->applyTo($watches->addToRoster($this->gate), $pattern);

        $this->signIn(FixedManageVoter::MANAGER_EMAIL);
        $token = $this->token('?'.RosterPatternsController::OPEN_QUERY.'='.$uuid);

        $this->client->request('POST', $this->url('/'.$uuid.'/delete'), ['_token' => $token]);
        self::assertResponseRedirects();

        $this->em->clear();
        self::assertCount(0, $this->em->getRepository(Pattern::class)->findAll());

        $station = $this->em->getRepository(Station::class)->findOneBy(['code' => 'ST-01']);
        self::assertInstanceOf(Station::class, $station);
        self::assertNotNull($watches->forStation($station), 'The station is still on the books.');
    }

    /**
     * A READER SEES THE REGISTER AND IS OFFERED NO WAY TO CHANGE IT.
     * Withheld rather than disabled: a greyed control tells somebody a thing
     * exists and they are not trusted with it.
     */
    public function testAReaderIsOfferedNoWayToSayOrChangeAPattern(): void
    {
        $this->patterns()->create($this->area, Cycle::of(['day', Cycle::OFF]));

        $this->signIn(FixedManageVoter::READER_EMAIL);
        $crawler = $this->client->request('GET', $this->url());

        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('.pregc')->count(), 'They can read it.');
        self::assertSame(0, $crawler->filter('.c.pnew')->count());
        self::assertSame(0, $crawler->filter('.pgact a:contains("New pattern")')->count());
    }

    /** And a reader who posts anyway is refused. */
    public function testAReaderCannotSayAPattern(): void
    {
        $this->signIn(FixedManageVoter::MANAGER_EMAIL);
        $token = $this->token('?'.RosterPatternsController::NEW_QUERY);

        $this->signIn(FixedManageVoter::READER_EMAIL);
        $this->client->request('POST', $this->url(), [
            '_token' => $token,
            RosterPatternsController::CYCLE_FIELD => json_encode([['days' => 1, 'shift' => 'day']], \JSON_THROW_ON_ERROR),
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    /** And so is a form that did not come from this page. */
    public function testAPostWithoutAValidTokenIsRefused(): void
    {
        $this->signIn(FixedManageVoter::MANAGER_EMAIL);

        $this->client->request('POST', $this->url(), [
            '_token' => 'not-the-token',
            RosterPatternsController::CYCLE_FIELD => json_encode([['days' => 1, 'shift' => 'day']], \JSON_THROW_ON_ERROR),
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * A PATTERN FROM ANOTHER AREA CANNOT BE EDITED FROM THIS PAGE. The area
     * is part of the lookup rather than a check after it: a uuid out of a
     * form names any pattern in the installation.
     */
    public function testAPatternFromAnotherAreaIsNotReachableHere(): void
    {
        $elsewhere = new AreaOfInterest()->setSource('test fixture')->setName('other reserve')->setGeom(
            '{"type":"MultiPolygon","coordinates":[[[[13.2,-6.8],[13.5,-6.8],[13.5,-6.5],[13.2,-6.5],[13.2,-6.8]]]]}',
        );
        $this->em->persist($elsewhere);
        $this->em->flush();
        $this->everyAreaRunsTheRoster($this->em);

        $theirs = $this->patterns()->create($elsewhere, Cycle::of(['day', Cycle::OFF]));

        $this->signIn(FixedManageVoter::MANAGER_EMAIL);
        // THE REGISTER ALONE CARRIES NO TOKEN, because it carries no form:
        // the way to say one is a link to the blank editor.
        $token = $this->token('?'.RosterPatternsController::NEW_QUERY);

        $this->client->request('POST', $this->url(), [
            '_token' => $token,
            RosterPatternsController::OPEN_QUERY => $theirs->getUuid()->toRfc4122(),
            RosterPatternsController::CYCLE_FIELD => json_encode([['days' => 9, 'shift' => 'day']], \JSON_THROW_ON_ERROR),
        ]);

        self::assertResponseStatusCodeSame(404);
    }
}
