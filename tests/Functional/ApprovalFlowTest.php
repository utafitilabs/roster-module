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
use Uhifadhi\Roster\Entity\StationWatch;
use Uhifadhi\Roster\Entity\Swap;
use Uhifadhi\Roster\Entity\WatchChange;
use Uhifadhi\Roster\Enum\SwapState;
use Uhifadhi\Roster\Enum\WatchChangeState;
use Uhifadhi\Roster\Service\SwapService;
use Uhifadhi\Roster\Tests\FreshDatabase;
use Uhifadhi\Roster\Tests\Integration\Fixtures\FixedManageVoter;

/**
 * THE STATION'S HEAD APPROVES, ON THE PAGE (#60, design C with A): the Week
 * tab's strip shows what waits and lets the head decide; whoever plans the
 * roster sees who it waits for; a planner's change to their own watch is
 * asked rather than made; the switch is on the Watches page; and the head's
 * own dashboard carries a "Waiting for you" card.
 */
final class ApprovalFlowTest extends WebTestCase
{
    use EveryAreaRunsTheRoster;
    use FreshDatabase;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private AreaOfInterest $area;
    private Station $gate;
    private User $manager;
    private User $head;
    private User $ada;
    private User $ben;

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
        $this->gate = new Station()->setArea($this->area)->setName('north gate post')->setCode('ST-01')->setPoint('{"type":"Point","coordinates":[12.3,-5.7]}');
        $this->em->persist($this->gate);

        $this->manager = $this->person(FixedManageVoter::MANAGER_EMAIL, 'Mara', 'Manager');
        $this->head = $this->person('rehema@example.test', 'Rehema', 'Mushi');
        $this->ada = $this->person('ada@example.test', 'Ada', 'Example');
        $this->ben = $this->person('ben@example.test', 'Ben', 'Example');
        $this->em->flush();

        foreach ([$this->head, $this->ada, $this->ben, $this->manager] as $person) {
            $this->em->persist(new Posting()->setStation($this->gate)->setPerson($person)->setSince(new \DateTimeImmutable('-1 year'))
                ->setSource(PostingSource::WrittenHere)->setLeader($person === $this->head));
        }
        $watch = new StationWatch($this->gate, 120, 240);
        $watch->setRequiresApproval(true);
        $this->em->persist($watch);
        $this->em->flush();

        $this->everyAreaRunsTheRoster($this->em);
    }

    public function testTheHeadSeesWhatWaitsAndApprovesIt(): void
    {
        $swap = $this->anAgreedSwap();

        $this->client->loginUser($this->head);
        $strip = $this->week()->filter('[data-approvals]');
        self::assertStringContainsString('B. Example takes A. Example', $strip->text());

        $this->client->submit($strip->selectButton('Approve')->form());
        self::assertResponseRedirects();

        $this->em->clear();
        $swap = $this->em->getRepository(Swap::class)->find($swap->getId());
        self::assertInstanceOf(Swap::class, $swap);
        self::assertSame(SwapState::Accepted, $swap->getState());
        self::assertSame($this->ben->getId(), $swap->getDuty()->getPerson()->getId());
    }

    public function testWhoeverPlansSeesWhoItWaitsForAndCannotDecideIt(): void
    {
        $swap = $this->anAgreedSwap();

        $this->client->loginUser($this->manager);
        $strip = $this->week()->filter('[data-approvals]');
        self::assertStringContainsString('waiting for Rehema Mushi', $strip->text());
        self::assertCount(0, $strip->filter('button'), 'no door for somebody who may not decide');

        $token = $this->week()->filter('input[name="_token"]')->first()->attr('value');
        $this->client->request('POST', '/areas/'.$this->area->getUuidString().'/modules/roster/approvals/'.$swap->getUuid()->toRfc4122().'/approve', ['_token' => $token]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testAPlannersChangeToTheirOwnWatchIsAskedNotMade(): void
    {
        $duty = new Duty($this->area, $this->gate, $this->manager, 'day', new \DateTimeImmutable('today'));
        $this->em->persist($duty);
        $this->em->flush();

        $this->client->loginUser($this->manager);
        $token = $this->week()->filter('input[name="_token"]')->first()->attr('value');
        $this->client->request('POST', '/areas/'.$this->area->getUuidString().'/modules/roster/sheet/day', [
            '_token' => $token, 'duty' => $duty->getUuid()->toRfc4122(), 'op' => 'off',
        ]);

        self::assertStringContainsString('waits for the head', (string) $this->client->followRedirect()->filter('[data-shell-flash]')->text());
        $this->em->clear();
        self::assertNotNull($this->em->getRepository(Duty::class)->find($duty->getId()), 'the watch stands until the head approves');
        $asked = $this->em->getRepository(WatchChange::class)->findAll();
        self::assertCount(1, $asked);
        self::assertSame(WatchChangeState::Waiting, $asked[0]->getState());
    }

    public function testTheSwitchIsOnTheWatchesPageAndNeedsAHead(): void
    {
        $post = new Station()->setArea($this->area)->setName('rim outpost')->setCode('ST-02')->setPoint('{"type":"Point","coordinates":[12.4,-5.6]}');
        $this->em->persist($post);
        $this->em->persist(new StationWatch($post, 120, 240));
        $this->em->flush();

        $this->client->loginUser($this->manager);
        $page = $this->client->request('GET', '/areas/'.$this->area->getUuidString().'/modules/roster/watches');
        self::assertResponseIsSuccessful();
        $card = $page->filter('[data-approval-switches]');
        self::assertStringContainsString('head Rehema Mushi', $card->text());
        self::assertStringContainsString('needs a head first', $card->text(), 'the rim outpost has no head');

        $this->client->submit($card->selectButton('Stop requiring it')->form());
        $this->em->clear();
        self::assertFalse($this->em->getRepository(StationWatch::class)->findOneBy(['station' => $this->gate->getId()])?->requiresApproval());
    }

    public function testTheHeadsDashboardSaysWhatWaitsForThem(): void
    {
        $this->anAgreedSwap();

        $this->client->loginUser($this->head);
        $card = $this->client->request('GET', '/me')->filter('[data-waiting-for-you]');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $card);
        self::assertStringContainsString('B. Example takes A. Example', $card->text());

        $this->client->loginUser($this->ben);
        self::assertCount(0, $this->client->request('GET', '/me')->filter('[data-waiting-for-you]'), 'only the head is waited for');
    }

    private function anAgreedSwap(): Swap
    {
        $duty = new Duty($this->area, $this->gate, $this->ada, 'day', new \DateTimeImmutable('today'));
        $this->em->persist($duty);
        $this->em->flush();
        $swaps = static::getContainer()->get('test_public.'.SwapService::class);
        self::assertInstanceOf(SwapService::class, $swaps);
        $swap = $swaps->offer($duty, $this->ben, $this->ada);
        $swaps->accept($swap, new \DateTimeImmutable());
        self::assertSame(SwapState::Waiting, $swap->getState());

        return $swap;
    }

    private function week(): Crawler
    {
        $crawler = $this->client->request('GET', '/areas/'.$this->area->getUuidString().'/modules/roster/week');
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function person(string $email, string $first, string $last): User
    {
        $user = new User()->setPassword('x')->setEmail($email)->setFirstName($first)->setLastName($last);
        $this->em->persist($user);

        return $user;
    }
}
