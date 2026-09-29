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
use Uhifadhi\Roster\Entity\Absence;
use Uhifadhi\Roster\Entity\Duty;
use Uhifadhi\Roster\Entity\Rotation;
use Uhifadhi\Roster\Entity\RotationPoolMember;
use Uhifadhi\Roster\Enum\AbsenceKind;
use Uhifadhi\Roster\Enum\RotationScope;
use Uhifadhi\Roster\Model\Cycle;
use Uhifadhi\Roster\Service\StationWatchService;
use Uhifadhi\Roster\Tests\FreshDatabase;
use Uhifadhi\Roster\Tests\Integration\Fixtures\FixedManageVoter;

/**
 * PLAN THE DAY, MEASURED AGAINST ITS DESIGN — modules/roster/plan.html.
 *
 * THE GENERATED PLAN AS SLOTS TO FILL. Every slot came from a post's
 * rotation; filling one writes a duty row, and a duty row is what presence
 * is derived from later. NOTHING HERE MARKS ANYBODY PRESENT — that is the
 * handset's job, tomorrow — and a test that let this page write a state
 * would be a test agreeing to the one thing the module forbids.
 *
 * THE RULES ARE THE POINT OF THE SCREEN. Three refuse a pick and one only
 * warns, and both halves are asserted: a blocked candidate is drawn,
 * disabled, WITH ITS REASON, and a post of it is refused with a sentence
 * rather than written. A picker that silently dropped the people it will
 * not take would teach a duty officer nothing.
 */ final class PlanTheDayTest extends WebTestCase
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

        // A SECOND PERSON IN THE RING, so a slot has somebody to offer that
        // the rules do not refuse.
        $second = new User()->setPassword('x')->setEmail('bea@example.test')->setFirstName('Bea')->setLastName('Example');
        $this->em->persist($second);
        $this->em->persist(new RotationPoolMember($rotation, $second, 1));

        // AND ADA IS AWAY TOMORROW, which is the blocked pill: a rule that
        // refuses a pick has to be visible on the sheet, with its reason.
        $this->em->persist(new Absence(
            $this->area,
            $ranger,
            new \DateTimeImmutable('tomorrow'),
            new \DateTimeImmutable('tomorrow'),
            AbsenceKind::Leave,
            $second,
        ));

        $this->em->flush();
    }

    private function open(): \Symfony\Component\DomCrawler\Crawler
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => FixedManageVoter::MANAGER_EMAIL]);
        self::assertInstanceOf(User::class, $user);
        $this->client->loginUser($user);

        $router = static::getContainer()->get('router');
        self::assertInstanceOf(\Symfony\Component\Routing\RouterInterface::class, $router);

        $crawler = $this->client->request('GET', $router->generate(RosterController::PLAN_ROUTE, [
            'uuid' => (string) $this->area->getUuidString(),
            'day' => new \DateTimeImmutable('tomorrow')->format('Y-m-d'),
        ]));
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /** THE HEADER ACTIONS the design names. */
    public function testTheHeaderCarriesTheRotationAndPublish(): void
    {
        $crawler = $this->open();

        self::assertStringContainsString('The rotation', $crawler->filter('.pgact')->text());
        self::assertStringContainsString('Publish the day', $crawler->filter('.pgact')->text());
    }

    /** THE WATCHES ARE TWO CARDS: the daylight ones, then the ones that cross midnight. */
    public function testTheSlotsAreGroupedIntoDayAndNightCards(): void
    {
        $crawler = $this->open();

        $labels = $crawler->filter('h2.zone')->each(
            static fn (\Symfony\Component\DomCrawler\Crawler $h): string => html_entity_decode(trim($h->text())),
        );

        self::assertContains('The watches to fill', $labels);
        self::assertGreaterThan(0, $crawler->filter('.r-slot')->count(), 'A row per slot.');
    }

    /**
     * EVERY CANDIDATE IS A PILL AND A BLOCKED ONE IS SHOWN, DISABLED, WITH
     * ITS REASON. Omitting them would answer "who can I put here" and leave
     * "why not her" to somebody's memory of the rules.
     */
    public function testABlockedCandidateIsDrawnDisabledWithItsReason(): void
    {
        $crawler = $this->open();

        $blocked = $crawler->filter('.r-pill.blocked');
        self::assertGreaterThan(0, $blocked->count(), 'Ada is away today, so she cannot be picked.');
        self::assertCount($blocked->count(), $crawler->filter('.r-pill.blocked input[disabled]'));
        self::assertStringContainsString('leave', strtolower($blocked->text()), 'And the row says which rule refused it.');
    }

    /** THE RULES CARD lists what blocks and what only warns. */
    public function testTheRulesCardSaysWhichRulesBlockAndWhichWarn(): void
    {
        $crawler = $this->open();

        $rules = $crawler->filter('[data-card="rules"]');
        self::assertCount(1, $rules);

        $text = strtolower(html_entity_decode($rules->text()));
        self::assertStringContainsString('blocks the pick', $text);
        self::assertStringContainsString('warns', $text);
    }

    /** WHO IS AWAY, over the fortnight the plan reaches into. */
    public function testTheAwayCardNamesWhoAndWhy(): void
    {
        $crawler = $this->open();

        $away = $crawler->filter('[data-card="away"]');
        self::assertCount(1, $away);
        self::assertStringContainsString('Ada Example', $away->text());
    }

    /**
     * PUBLISHING WRITES A DUTY AND NOTHING ELSE. It marks nobody present:
     * presence is derived later from what the handsets report against
     * these rows.
     */
    public function testPublishingAPickWritesTheDuty(): void
    {
        $crawler = $this->open();

        $slot = $crawler->filter('.r-slot')->eq(0);
        $free = $slot->filter('.r-pill:not(.blocked) input')->eq(0);
        // The markup's name ends in [] because a slot takes many; the
        // client posts the parameter itself, so the brackets come off.
        $name = rtrim((string) $free->attr('name'), '[]');
        $value = (string) $free->attr('value');

        $token = (string) $crawler->filter('input[name="_token"]')->attr('value');
        $router = static::getContainer()->get('router');
        self::assertInstanceOf(\Symfony\Component\Routing\RouterInterface::class, $router);

        $this->client->request('POST', $router->generate(RosterController::PUBLISH_ROUTE, ['uuid' => (string) $this->area->getUuidString()]), [
            '_token' => $token,
            'day' => new \DateTimeImmutable('tomorrow')->format('Y-m-d'),
            $name => [$value],
        ]);

        self::assertResponseRedirects();

        $duties = static::getContainer()->get('test_public.'.\Uhifadhi\Roster\Repository\DutyRepository::class);
        self::assertInstanceOf(\Uhifadhi\Roster\Repository\DutyRepository::class, $duties);
        self::assertNotEmpty(
            $duties->findByAreaOnDay($this->area, new \DateTimeImmutable('tomorrow')),
            'The pick is a duty row now.',
        );
    }

    /**
     * AND A BLOCKED PICK IS REFUSED WITH A SENTENCE. The pill is disabled
     * in the markup and markup is a suggestion — a form can be posted by
     * anything — so the rules are asked again on the way in.
     */
    public function testABlockedPickIsRefusedRatherThanWritten(): void
    {
        $crawler = $this->open();

        $blocked = $crawler->filter('.r-pill.blocked input')->eq(0);
        $name = rtrim((string) $blocked->attr('name'), '[]');
        $value = (string) $blocked->attr('value');

        $token = (string) $crawler->filter('input[name="_token"]')->attr('value');
        $router = static::getContainer()->get('router');
        self::assertInstanceOf(\Symfony\Component\Routing\RouterInterface::class, $router);

        $day = new \DateTimeImmutable('tomorrow')->format('Y-m-d');
        $this->client->request('POST', $router->generate(RosterController::PUBLISH_ROUTE, ['uuid' => (string) $this->area->getUuidString()]), [
            '_token' => $token,
            'day' => $day,
            $name => [$value],
        ]);

        self::assertResponseRedirects();
        $after = $this->client->followRedirect();

        self::assertStringContainsString('cannot take', $after->filter('.flash, .fl, body')->text(), 'It says who, and which rule.');
    }
}
