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
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Bundle\AreaBundle\Enum\PostingSource;
use Uhifadhi\Bundle\AreaBundle\Service\PostingService;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Roster\DependencyInjection\RosterConfiguration;
use Uhifadhi\Roster\Entity\Absence;
use Uhifadhi\Roster\Entity\Duty;
use Uhifadhi\Roster\Entity\Shift;
use Uhifadhi\Roster\Enum\AbsenceKind;
use Uhifadhi\Roster\Service\StationWatchService;
use Uhifadhi\Roster\Tests\FreshDatabase;
use Uhifadhi\Roster\Tests\Integration\Fixtures\FixedManageVoter;
use Uhifadhi\Roster\UhifadhiRosterBundle;

/**
 * THE ROSTER ON A PERSON'S OWN DASHBOARD, AND THE PAGE BEHIND IT (#19; design
 * variants-my-dashboard a.html ME·09 + ME·13, roster.html, station.html
 * SN·03 — approved 28 Sep 2026), over real HTTP, for somebody who may read
 * nothing of the areas: they are shown their own post because it is theirs,
 * never because a grant says so.
 */
final class MyRosterTest extends WebTestCase
{
    use EveryAreaRunsTheRoster;
    use FreshDatabase;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private AreaOfInterest $area;
    private Station $post;
    private User $me;
    private \DateTimeImmutable $today;

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
        $this->area->setPingIntervalMinutes(5);
        $this->em->persist($this->area);
        $this->post = new Station()->setArea($this->area)->setName('north gate post')->setCode('ST-01')->setPoint('{"type":"Point","coordinates":[12.3,-5.7]}');
        $this->em->persist($this->post);
        foreach (RosterConfiguration::DEFAULT_SHIFTS as $position => $shift) {
            $this->em->persist(new Shift($this->area, $shift['key'], $shift['label'], $shift['start'], $shift['end'], $position));
        }

        $this->me = $this->aPerson(FixedManageVoter::PERSON_EMAIL, 'Neema', 'Example');
        $head = $this->aPerson('head@example.test', 'Juma', 'Headman');
        $other = $this->aPerson('other@example.test', 'Tumaini', 'Other');
        $this->em->flush();

        $postings = static::getContainer()->get('test_public.'.PostingService::class);
        self::assertInstanceOf(PostingService::class, $postings);
        $postings->appointLeader($postings->post($this->post, $head, PostingSource::WrittenHere));
        $postings->post($this->post, $this->me, PostingSource::WrittenHere);
        $postings->post($this->post, $other, PostingSource::WrittenHere);

        $watches = static::getContainer()->get('test_public.'.StationWatchService::class);
        self::assertInstanceOf(StationWatchService::class, $watches);
        $watches->addToRoster($this->post)->expect(['day', 'night'])->setNeedsPerShift(['day' => 2, 'night' => 2]);

        $this->today = new \DateTimeImmutable('today');
        foreach ([$this->me, $head] as $person) {
            $this->em->persist(new Duty($this->area, $this->post, $person, 'day', $this->today));
        }
        $this->em->persist(new Duty($this->area, $this->post, $this->me, 'day', $this->today->modify('+1 day')));
        $this->em->persist(new Duty($this->area, $this->post, $other, 'night', $this->today));
        $this->em->persist(new Absence($this->area, $this->me, $this->today->modify('+20 days'), $this->today->modify('+22 days'), AbsenceKind::Leave));
        $this->em->flush();

        $this->everyAreaRunsTheRoster($this->em);
        $this->client->loginUser($this->me);
    }

    public function testTheDashboardHasADoorToMyRoster(): void
    {
        $door = $this->client->request('GET', '/')->filter('.md-pages a')->reduce(static fn (Crawler $a): bool => str_contains($a->text(), 'My roster'));

        self::assertResponseIsSuccessful();
        self::assertCount(1, $door);
        self::assertStringContainsString('the week at north gate post', $door->text());
        self::assertSame('/me/roster', $door->attr('href'));
    }

    public function testMyRosterCardIsMyWeekAtThePost(): void
    {
        $card = $this->client->request('GET', '/')->filter('[data-slot="left"] [data-me="roster"]');

        self::assertCount(1, $card);
        self::assertStringStartsWith('My roster', $card->filter('.tab')->text());
        self::assertStringContainsString('north gate post', $card->filter('.tab .src')->text());
        self::assertCount(7, $card->filter('.md-wk > div'), 'Monday to Sunday.');
        self::assertSame('day', $card->filter('.md-wk > div.me .md-sh')->text(), 'Today is marked, and I am on the day watch.');
        self::assertStringContainsString('day 06:00–18:00', $this->row($card, 'Next'));
        self::assertSame('J. Headman (head)', $this->row($card, 'On with me today'));
        self::assertSame('/me/roster', $card->filter('.sxfoot a')->attr('href'));
    }

    public function testTheRosterCardComesFirstOnTheLeft(): void
    {
        $left = $this->client->request('GET', '/')->filter('[data-slot="left"] > .c');

        self::assertSame('roster', $left->first()->attr('data-me'));
    }

    public function testMyLeaveCardListsThisYearsAbsencesAndSaysThereIsNoAllowance(): void
    {
        $card = $this->client->request('GET', '/')->filter('[data-slot="row"] [data-me="leave"]');

        self::assertCount(1, $card);
        self::assertStringStartsWith('My leave', $card->filter('.tab')->text());
        self::assertStringContainsString($this->today->format('Y'), $card->filter('.tab .src')->text());
        $leave = $this->today->modify('+20 days');
        if ($leave->format('Y') === $this->today->format('Y')) {
            self::assertStringContainsString('Leave', $card->text());
            self::assertStringContainsString('3 days', $card->text());
        }
        self::assertStringContainsString('no leave allowance', $card->filter('.sxfoot')->text());
        self::assertCount(0, $card->filter('.sxfoot a'), 'There is no way to ask for leave here, so no door says there is.');
    }

    public function testTheHeadCarriesTheSheetTheCardsAreDrawnIn(): void
    {
        $links = $this->client->request('GET', '/')->filter('head link[rel="stylesheet"]')->each(static fn (Crawler $l): string => (string) $l->attr('href'));

        self::assertNotEmpty(array_filter($links, static fn (string $href): bool => str_contains($href, 'bundles/uhifadhiroster/me')));
    }

    public function testMyRosterPageNamesThePostAndMyWeek(): void
    {
        $page = $this->client->request('GET', '/me/roster');

        self::assertResponseIsSuccessful();
        $facts = $page->filter('.factband .f')->each(static fn (Crawler $f): string => $f->filter('.k')->text().': '.$f->filter('.v')->text());
        self::assertContains('Station: north gate post', $facts);
        self::assertContains('Head: J. Headman', $facts);
        self::assertContains('Posted here: 3', $facts);
        self::assertStringContainsString('day 06–18', implode("\n", $facts));
    }

    public function testTheWeekTableIsEverybodyPostedHereWithMyRowMarked(): void
    {
        $table = $this->client->request('GET', '/me/roster')->filter('[data-me="week"] table.md-rt');

        self::assertCount(1, $table);
        self::assertCount(8, $table->filter('thead th'), 'The person, then Monday to Sunday.');
        self::assertCount(1, $table->filter('tbody tr.me'));
        self::assertStringContainsString('N. Example', $table->filter('tbody tr.me')->text());
        self::assertCount(1, $table->filter('tbody tr.me .chip.acc'));
        // Three people and the count row.
        self::assertCount(4, $table->filter('tbody tr'));
        self::assertStringContainsString('on duty · day · night', $table->filter('tbody tr')->last()->text());
    }

    public function testTheWeekSaysWhatThePostExpects(): void
    {
        $card = $this->client->request('GET', '/me/roster')->filter('[data-me="week"]');

        self::assertStringContainsString('the post expects day 2 · night 2', $card->filter('.sxfoot')->text());
    }

    public function testThePageListsMyNextShiftsMySwapsAndWhoIsAway(): void
    {
        $page = $this->client->request('GET', '/me/roster');

        self::assertCount(1, $page->filter('[data-me="next"]'));
        self::assertCount(1, $page->filter('[data-me="swaps"]'));
        self::assertCount(1, $page->filter('[data-me="away"]'));
        self::assertStringContainsString('day', $page->filter('[data-me="next"] .md-sh')->first()->text());
    }

    public function testSomebodyPostedNowhereIsToldSo(): void
    {
        $loose = $this->aPerson('loose@example.test', 'Loose', 'Nowhere');
        $this->em->flush();
        $this->client->loginUser($loose);

        $page = $this->client->request('GET', '/me/roster');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('posted at no station', $page->filter('main')->text());
    }

    public function testMyRosterPageNeedsSomebodySignedIn(): void
    {
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/me/roster');

        // Refused, and refused by the firewall — never drawn, and never a 500.
        self::assertContains($this->client->getResponse()->getStatusCode(), [302, 401, 403]);
    }

    /** SN·03 on My station: the roster's Watches band, as the person posted there reads it. */
    public function testMyStationCarriesWhatThePostExpects(): void
    {
        $band = $this->client->request('GET', '/me/station')->filter('#watches');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $band);
        self::assertStringContainsString('Watches', $band->filter('.tab')->text());
        self::assertStringContainsString('every 5 min on watch', $band->text());
        self::assertCount(0, $band->filter('.rb-act a'), 'No door into the roster for somebody who may not open it.');
    }

    public function testTheSheetIsServedWhereTheHeadSaysItIs(): void
    {
        self::assertFileExists(\dirname(__DIR__, 2).'/public/me.css');
        self::assertSame('bundles/uhifadhiroster/me.css', UhifadhiRosterBundle::ME_STYLESHEET);
    }

    private function row(Crawler $card, string $label): string
    {
        $row = $card->filter('.rln')->reduce(static fn (Crawler $r): bool => $r->filter('span')->first()->text() === $label);
        self::assertCount(1, $row, $label.' has a row.');

        return trim((string) preg_replace('/\s+/', ' ', $row->filter('span')->last()->text()));
    }

    private function aPerson(string $email, string $first, string $last): User
    {
        $user = new User()->setPassword('x')->setEmail($email)->setFirstName($first)->setLastName($last);
        $this->em->persist($user);

        return $user;
    }
}
