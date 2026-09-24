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
use Symfony\Component\Routing\RouterInterface;
use Uhifadhi\Bundle\AreaBundle\Controller\StationConfigureController;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Bundle\AreaBundle\Enum\PostingSource;
use Uhifadhi\Bundle\AreaBundle\Service\PostingService;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Roster\Controller\RosterConfigureController;
use Uhifadhi\Roster\Controller\RosterController;
use Uhifadhi\Roster\Entity\Duty;
use Uhifadhi\Roster\Entity\Rotation;
use Uhifadhi\Roster\Entity\StationWatch;
use Uhifadhi\Roster\Enum\RotationPreset;
use Uhifadhi\Roster\Repository\RotationRepository;
use Uhifadhi\Roster\Service\StationWatchService;
use Uhifadhi\Roster\Tests\FreshDatabase;
use Uhifadhi\Roster\Tests\Integration\Fixtures\FixedManageVoter;

/**
 * THE WHOLE DUTY FLOW, FROM AN EMPTY INSTALLATION TO A WATCH ON A PHONE —
 * over real HTTP, through the doors a person actually presses.
 *
 * THIS IS THE TEST THE DEFECT NEEDED. Every piece of the chain had a green
 * unit test and the chain did not work, because no screen in the product
 * put a post on this module's books or declared a rotation: the seeder did
 * both, and the seeder is not a door. A test that seeds a `StationWatch` by
 * hand asserts a state no installation can reach.
 *
 * SO NOTHING HERE IS SEEDED PAST THE AREA. The area registers a post and
 * posts a ranger at it — both the AREA's own acts — and everything after
 * that is a form submitted to a route:
 *
 *   1. the Watches section puts the post on the books
 *   2. the Watches section says which watch it stands
 *   3. the rotation section declares the ring, drawing on who is posted there
 *   4. Plan the day publishes today's watches
 *   5. the handset reads them at `GET /api/areas/{uuid}/me/roster`
 *
 * AND IT FINISHES AT THE ENDPOINT, not at the provider behind it. The phone
 * asks the AREA, and the area asks this module through one contract; a test
 * that stopped at the module's own service would prove the answer exists
 * and not that anybody can read it.
 */
final class TheDutyFlowStartsTest extends WebTestCase
{
    use EveryAreaRunsTheRoster;
    use FreshDatabase;

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

        $this->gate = new Station()
            ->setArea($this->area)
            ->setName('north gate post')
            ->setCode('ST-01')
            ->setPoint('{"type":"Point","coordinates":[12.3,-5.7]}');
        $this->em->persist($this->gate);

        $this->em->persist(new User()->setPassword('x')->setEmail(FixedManageVoter::MANAGER_EMAIL)->setFirstName('Mara')->setLastName('Manager'));

        $this->ranger = new User()->setPassword('x')->setEmail('ada@example.test')->setFirstName('Ada')->setLastName('Example');
        $this->em->persist($this->ranger);

        $this->em->flush();

        // THE AREA'S OWN ACT, through the area's own service: a person
        // stands at a post. Nothing this module writes is seeded.
        $postings = static::getContainer()->get('test_public.'.PostingService::class);
        self::assertInstanceOf(PostingService::class, $postings);
        $postings->post($this->gate, $this->ranger, PostingSource::WrittenHere);

        $this->everyAreaRunsTheRoster($this->em);
    }

    /**
     * THE WHOLE CHAIN, IN ONE TEST, because it is one claim: a park that has
     * just installed this module can get a watch onto a ranger's phone
     * without touching the database. Splitting it into five would let four
     * of them pass while the product still cannot start.
     */
    public function testAParkCanStartTheDutyFlowFromTheScreensAlone(): void
    {
        $uuid = (string) $this->area->getUuidString();

        // ── 1 ─ THE POST GOES ON THE BOOKS, from the Watches section's own
        // add row. Before this, `StationWatchService::addToRoster` had no
        // caller in the product at all.
        $this->signIn(FixedManageVoter::MANAGER_EMAIL);

        $watches = $this->client->request('GET', '/areas/'.$uuid.'/modules/roster/watches');
        self::assertResponseIsSuccessful();
        self::assertSame(1, $watches->filter('button:contains("Put it on the books")')->count(), 'The door the defect was missing.');

        $token = $watches->filter('input[name="_token"]')->attr('value');
        self::assertIsString($token);

        $this->client->request('POST', '/areas/'.$uuid.'/modules/roster/watches/add', [
            '_token' => $token,
            'station' => (string) $this->gate->getUuidString(),
        ]);
        self::assertResponseRedirects();

        $watch = $this->watchService()->forStation($this->gate);
        self::assertInstanceOf(StationWatch::class, $watch, 'The post is on the roster’s books.');
        self::assertTrue($watch->expectsNothing(), 'And it stands nothing yet — joining the books is not declaring a watch.');

        // ── 2 ─ AND IT IS TOLD WHAT IT STANDS. A post on the books with no
        // shift generates nothing, which is the honest intermediate state.
        $this->client->request('POST', '/areas/'.$uuid.'/modules/roster/watches', [
            '_token' => $token,
            'expects_'.$this->gate->getId() => ['day'],
        ]);
        self::assertResponseRedirects();

        $this->em->clear();
        $watch = $this->watchService()->forStation($this->freshGate());
        self::assertInstanceOf(StationWatch::class, $watch);
        self::assertSame(['day'], $watch->getExpects());

        // ── 3 ─ THE RING IS DECLARED, from the page header's one accent
        // action. The pool is whoever the AREA posted at the gate.
        $rotationPage = $this->client->request('GET', '/areas/'.$uuid.'/modules/roster/rotation?'.RosterConfigureController::NEW_QUERY.'=1');
        self::assertResponseIsSuccessful();
        self::assertSame(1, $rotationPage->filter('.pgact a:contains("New rotation")')->count(), 'The design puts it in the page header.');
        self::assertSame(1, $rotationPage->filter('button:contains("Declare the rotation")')->count());

        $token = $rotationPage->filter('input[name="_token"]')->attr('value');
        self::assertIsString($token);

        $this->client->request('POST', '/areas/'.$uuid.'/modules/roster/rotation/new', [
            '_token' => $token,
            'station' => (string) $this->freshGate()->getUuidString(),
            'preset' => RotationPreset::OneOfEachThenOff->value,
        ]);
        self::assertResponseRedirects();

        $this->em->clear();
        $rotation = $this->rotations()->findOneForStation($this->freshGate());
        self::assertInstanceOf(Rotation::class, $rotation);
        self::assertSame(['day', 'off'], $rotation->getCycle()->positions, 'The preset is filled with the post’s own watch.');
        self::assertSame(['day' => 1], $rotation->getSlotsPerShift());
        self::assertSame(1, $rotation->getPool()->count(), 'Drawing on the person the area posted there.');

        // ── 4 ─ TODAY IS PLANNED AND PUBLISHED. The sheet's slot came from
        // the ring; publishing it writes the duty the handset reports
        // against.
        $today = new \DateTimeImmutable('today');
        $plan = $this->client->request('GET', '/areas/'.$uuid.'/modules/roster/plan?day='.$today->format('Y-m-d'));
        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $plan->filter('.r-slot')->count(), 'The ring asks for somebody at the gate today.');

        $token = $plan->filter('input[name="_token"]')->attr('value');
        self::assertIsString($token);

        $this->client->request('POST', '/areas/'.$uuid.'/modules/roster/plan/publish', [
            '_token' => $token,
            'day' => $today->format('Y-m-d'),
            RosterController::SLOT_FIELD.$this->freshGate()->getUuidString().'__day' => [(string) $this->ranger->getUuidString()],
        ]);
        self::assertResponseRedirects();

        $this->em->clear();
        $duties = $this->em->getRepository(Duty::class)->findAll();
        self::assertCount(1, $duties, 'One watch, published for today.');

        // ── 5 ─ AND THE PHONE READS IT. "Me" is the token's account, so the
        // ranger signs in and asks for their own month.
        $this->signIn('ada@example.test');
        $this->client->request('GET', '/api/areas/'.$uuid.'/me/roster');

        self::assertResponseIsSuccessful();
        $answer = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($answer);

        self::assertTrue($answer['rostered'] ?? null, 'Rostered — not a ranger the roster has never heard of.');

        $watches = $answer['watches'] ?? null;
        self::assertIsArray($watches);
        self::assertCount(1, $watches);

        $onTheDay = $watches[0];
        self::assertIsArray($onTheDay);
        self::assertSame($today->format('Y-m-d'), $onTheDay['localDate']);
        self::assertSame((string) $this->freshGate()->getUuidString(), $onTheDay['stationUuid']);
        self::assertSame('day', $onTheDay['label']);
    }

    /**
     * A POST THE ROSTER DOES NOT WORK STILL CARRIES THE DOOR on the area's
     * own Stations configure card — the second of the two doors the design
     * draws, and the one somebody setting a post up actually meets.
     */
    public function testTheAreasStationCardOffersTheDoorOnAPostOffTheBooks(): void
    {
        $this->signIn(FixedManageVoter::MANAGER_EMAIL);

        // ONE CARD'S BODY IS RENDERED AT A TIME on that page, and only the
        // open one is asked of its modules — so the post has to be opened
        // for this module to be asked about it at all.
        $router = static::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);
        $crawler = $this->client->request('GET', $router->generate(
            StationConfigureController::ROUTE,
            ['uuid' => (string) $this->area->getUuidString(), 'open' => (string) $this->gate->getUuidString()],
        ));
        self::assertResponseIsSuccessful();

        $heading = $crawler->filter('.ao-by.roster')->ancestors()->first()->text();
        self::assertStringContainsString('not on the roster’s books', mb_strtolower($heading));
        self::assertSame(1, $crawler->filter('button:contains("Put this post on the roster")')->count());

        // AND PRESSING IT PUTS THE POST ON THE BOOKS, from that card.
        // THE BLOCK'S OWN FORM AND ITS OWN TOKEN. The card carries several
        // forms, each the area's, each with a token of its own — this
        // module's write rides on this module's token id.
        $token = $crawler->filter('form[action*="/modules/roster/watches/add"] input[name="_token"]')->attr('value');
        self::assertIsString($token);

        $this->client->request('POST', '/areas/'.$this->area->getUuidString().'/modules/roster/watches/add', [
            '_token' => $token,
            'station' => (string) $this->gate->getUuidString(),
            'back' => RosterConfigureController::BACK_TO_THE_STATION,
        ]);

        // BACK TO THE CARD IT WAS PRESSED ON, with that post still open.
        self::assertResponseRedirects();
        self::assertStringContainsString(
            $router->generate(StationConfigureController::ROUTE, ['uuid' => (string) $this->area->getUuidString()]),
            (string) $this->client->getResponse()->headers->get('Location'),
        );
        self::assertInstanceOf(StationWatch::class, $this->watchService()->forStation($this->gate));
    }

    private function signIn(string $email): void
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user);
        $this->client->loginUser($user);
    }

    /** The gate again after a clear() — the same row, a live object. */
    private function freshGate(): Station
    {
        $gate = $this->em->getRepository(Station::class)->findOneBy(['code' => 'ST-01']);
        self::assertInstanceOf(Station::class, $gate);

        return $gate;
    }

    private function watchService(): StationWatchService
    {
        $service = static::getContainer()->get('test_public.'.StationWatchService::class);
        self::assertInstanceOf(StationWatchService::class, $service);

        return $service;
    }

    private function rotations(): RotationRepository
    {
        $repository = static::getContainer()->get('test_public.'.RotationRepository::class);
        self::assertInstanceOf(RotationRepository::class, $repository);

        return $repository;
    }
}
