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

namespace Uhifadhi\Roster\Tests\Integration\Shell;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Contracts\Area\StationSectionRequest;
use Uhifadhi\Contracts\Area\StationSectionsInterface;
use Uhifadhi\Contracts\Area\StationSurface;
use Uhifadhi\Contracts\Kpi\StationRef;
use Uhifadhi\Roster\Module\RosterModuleProvider;
use Uhifadhi\Roster\Service\StationWatchService;
use Uhifadhi\Roster\Shell\RosterStationSections;
use Uhifadhi\Roster\Tests\Integration\IntegrationTestCase;

/**
 * WHAT THE ROSTER PUTS ON A POST — and, just as load-bearing, what it stays
 * silent about.
 */
final class RosterStationSectionsTest extends IntegrationTestCase
{
    private AreaOfInterest $area;
    private Station $onTheBooks;
    private Station $offTheBooks;

    protected function setUp(): void
    {
        parent::setUp();

        $this->area = $this->anArea();
        $this->onTheBooks = $this->aStation($this->area, 'north gate post', 'ST-01');
        $this->offTheBooks = $this->aStation($this->area, 'west outpost', 'ST-02');
        $this->theShiftVocabulary($this->area);
        $this->em->flush();

        $watches = $this->service(StationWatchService::class);
        self::assertInstanceOf(StationWatchService::class, $watches);
        $watches->addToRoster($this->onTheBooks)->expect(['day', 'night']);
        $this->em->flush();
    }

    private function sections(): RosterStationSections
    {
        $sections = $this->service(RosterStationSections::class);
        self::assertInstanceOf(RosterStationSections::class, $sections);

        return $sections;
    }

    private function request(StationSurface $surface, Station ...$stations): StationSectionRequest
    {
        return new StationSectionRequest(
            array_map(
                fn (Station $station): StationRef => new StationRef(
                    (string) $station->getUuidString(),
                    (string) $this->area->getUuidString(),
                    (string) $station->getName(),
                ),
                array_values($stations),
            ),
            $surface,
        );
    }

    public function testItIsTheContractTheAreaCollects(): void
    {
        self::assertInstanceOf(StationSectionsInterface::class, $this->sections());
        self::assertSame(RosterModuleProvider::SLUG, $this->sections()->moduleSlug());
    }

    public function testItPutsAWatchAndPresenceBandOnTheRecord(): void
    {
        $answer = $this->sections()->sectionsFor($this->request(StationSurface::Record, $this->onTheBooks));

        $sections = $answer->forStation((string) $this->onTheBooks->getUuidString());

        self::assertCount(1, $sections);
        self::assertSame(RosterStationSections::WATCH, $sections[0]->id);
        self::assertSame('Watch and presence', $sections[0]->label);
        self::assertSame('@UhifadhiRoster/station/_watch.html.twig', $sections[0]->template);
    }

    public function testItPutsARosterBlockOnTheConfigureCard(): void
    {
        $answer = $this->sections()->sectionsFor($this->request(StationSurface::Configure, $this->onTheBooks));

        $sections = $answer->forStation((string) $this->onTheBooks->getUuidString());

        self::assertCount(1, $sections);
        self::assertSame(RosterStationSections::ROSTER, $sections[0]->id);
        self::assertSame('Roster', $sections[0]->label);
        self::assertSame('@UhifadhiRoster/station/_configure.html.twig', $sections[0]->template);
    }

    /**
     * A POST THIS MODULE DOES NOT KEEP ON ITS BOOKS IS ANSWERED WITH SILENCE
     * — no key at all, so the area draws no band and no placeholder. It is
     * the difference between "not our post" and "our post, nothing to
     * report", and the contract keeps them apart deliberately.
     */
    public function testAPostOffTheBooksIsAnsweredWithSilenceAndNotAnEmptyBand(): void
    {
        $answer = $this->sections()->sectionsFor($this->request(StationSurface::Record, $this->onTheBooks, $this->offTheBooks));

        self::assertSame([], $answer->forStation((string) $this->offTheBooks->getUuidString()));
        self::assertArrayNotHasKey((string) $this->offTheBooks->getUuidString(), $answer->byStation);
        self::assertNotSame([], $answer->forStation((string) $this->onTheBooks->getUuidString()));
    }

    /**
     * A POST THAT DECLARES NO WATCH SAYS SO — it is on the books, so it gets
     * a band, and the band states that it is never counted, never late and
     * never a hole. Staying silent about it would look like a band that
     * failed to load.
     */
    public function testAPostWithNoWatchGetsABandThatSaysSo(): void
    {
        $watches = $this->service(StationWatchService::class);
        self::assertInstanceOf(StationWatchService::class, $watches);
        $watches->addToRoster($this->offTheBooks)->expect([]);
        $this->em->flush();

        $answer = $this->sections()->sectionsFor($this->request(StationSurface::Record, $this->offTheBooks));
        $sections = $answer->forStation((string) $this->offTheBooks->getUuidString());

        self::assertCount(1, $sections);
        self::assertStringContainsString('never counted, never late and never a hole', (string) $sections[0]->summary);
    }

    /** The band offers a way to the one place a watch is edited, and no editor of its own. */
    public function testTheBandLinksToTheOnePlaceAWatchIsEdited(): void
    {
        $answer = $this->sections()->sectionsFor($this->request(StationSurface::Configure, $this->onTheBooks));
        $sections = $answer->forStation((string) $this->onTheBooks->getUuidString());

        self::assertCount(1, $sections[0]->actions);
        self::assertStringContainsString('/modules/roster/watches', $sections[0]->actions[0]->url);
    }

    /**
     * AND ON THE CONFIGURE CARD IT IS ANSWERED WITH THE DOOR.
     *
     * The record is where somebody READS a post and silence there is
     * honest; the card is where somebody SETS ONE UP, and a post that can
     * never be worked because no screen offers the one write is the defect
     * this block exists to close. Ported from the design's own
     * configure-stations.html.
     */
    public function testAPostOffTheBooksIsOfferedTheDoorOnItsConfigureCard(): void
    {
        // THE BLOCK IS DRAWN INSIDE A REQUEST and its door carries a token,
        // which is a thing in a session — so the request this kernel test
        // stands in has to be a real one, as the page's is.
        $request = Request::create('/areas/'.$this->area->getUuidString().'/stations/settings');
        $request->setSession(new Session(new MockArraySessionStorage()));
        $requests = static::getContainer()->get('request_stack');
        self::assertInstanceOf(RequestStack::class, $requests);
        $requests->push($request);

        $answer = $this->sections()->sectionsFor($this->request(StationSurface::Configure, $this->offTheBooks));
        $sections = $answer->forStation((string) $this->offTheBooks->getUuidString());

        self::assertCount(1, $sections);
        self::assertSame(RosterStationSections::ROSTER, $sections[0]->id);
        self::assertSame('@UhifadhiRoster/station/_configure_off.html.twig', $sections[0]->template);
        self::assertStringContainsString('Not on the roster', (string) $sections[0]->summary);
        $door = $sections[0]->variables['door'];
        self::assertIsString($door);
        self::assertStringContainsString('/modules/roster/watches/add', $door);
        self::assertSame([], $sections[0]->actions, 'The door is the row; the heading carries no second way out.');
    }

    /** An empty request is an empty answer, and never a query. */
    public function testAnEmptyRequestIsAnEmptyAnswer(): void
    {
        $answer = $this->sections()->sectionsFor(new StationSectionRequest([], StationSurface::Record));

        self::assertTrue($answer->isEmpty());
    }

    /**
     * THE WHOLE SET IN ONE CALL. The configure page draws a card per station,
     * so the answer has to be keyed by post rather than ordered — a
     * contributor that skipped one would otherwise shift every card after it
     * onto the wrong station.
     */
    public function testItAnswersASetKeyedByPost(): void
    {
        $watches = $this->service(StationWatchService::class);
        self::assertInstanceOf(StationWatchService::class, $watches);
        $watches->addToRoster($this->offTheBooks);
        $this->em->flush();

        $answer = $this->sections()->sectionsFor($this->request(StationSurface::Configure, $this->onTheBooks, $this->offTheBooks));

        self::assertArrayHasKey((string) $this->onTheBooks->getUuidString(), $answer->byStation);
        self::assertArrayHasKey((string) $this->offTheBooks->getUuidString(), $answer->byStation);
    }

    /**
     * THE PERSON'S OWN POST (#19): `/me/station` carries the post's WATCHES —
     * what it expects of the people on it, day and night, how near a
     * check-in has to be and how often a handset reports — and nothing a
     * ranger may not read: no presence of anybody else, no door into the
     * roster's configure page.
     */
    public function testThePersonsOwnPostGetsWhatItExpects(): void
    {
        $this->onTheBooksNeeds(['day' => 2, 'night' => 1]);

        $answer = $this->sections()->sectionsFor($this->request(StationSurface::Mine, $this->onTheBooks));
        $sections = $answer->forStation((string) $this->onTheBooks->getUuidString());

        self::assertCount(1, $sections);
        self::assertSame(RosterStationSections::WATCHES, $sections[0]->id);
        self::assertSame('Watches', $sections[0]->label);
        self::assertSame('@UhifadhiRoster/station/_mine.html.twig', $sections[0]->template);
        self::assertSame([], $sections[0]->actions, 'A person reading their own post is handed no door into the roster.');
        self::assertArrayNotHasKey('presence', $sections[0]->variables, 'Who else is on watch is not what this section says.');
    }

    /** And never the configure block: the third surface is not a second configure card. */
    public function testThePersonsOwnPostIsNeverAnsweredWithTheConfigureBlock(): void
    {
        $answer = $this->sections()->sectionsFor($this->request(StationSurface::Mine, $this->onTheBooks, $this->offTheBooks));

        foreach ($answer->byStation as $sections) {
            foreach ($sections as $section) {
                self::assertNotSame(RosterStationSections::ROSTER, $section->id);
            }
        }
    }

    /** A post this module does not keep is silence on the person's page too — never the door to put it on the books. */
    public function testAPostOffTheBooksSaysNothingOnThePersonsOwnPage(): void
    {
        $answer = $this->sections()->sectionsFor($this->request(StationSurface::Mine, $this->offTheBooks));

        self::assertArrayNotHasKey((string) $this->offTheBooks->getUuidString(), $answer->byStation);
    }

    /**
     * THE ROWS, as the design's SN·03 prints them: one per watch the post
     * runs with its window and how many people it needs, then the check-in
     * and the pings.
     */
    public function testTheWatchesRowsSayWhatThePostExpects(): void
    {
        $this->onTheBooksNeeds(['day' => 2, 'night' => 1]);
        $this->area->setPingIntervalMinutes(5);
        $this->em->flush();

        $section = $this->sections()->sectionsFor($this->request(StationSurface::Mine, $this->onTheBooks))
            ->forStation((string) $this->onTheBooks->getUuidString())[0];
        $twig = static::getContainer()->get('twig');
        self::assertInstanceOf(\Twig\Environment::class, $twig);
        $html = $twig->render($section->template, $section->variables);
        $rows = new \Symfony\Component\DomCrawler\Crawler($html)->filter('.rln')->each(
            static fn (\Symfony\Component\DomCrawler\Crawler $row): string => implode(' ', $row->filter('span')->each(
                static fn (\Symfony\Component\DomCrawler\Crawler $span): string => trim((string) preg_replace('/\s+/', ' ', $span->text())),
            )),
        );

        self::assertSame([
            'Day 06:00–18:00 · 2 people',
            'Night 18:00–06:00 · 1 person',
            'Check-in within 1.5 km of the station',
            'Pings every 5 min on watch',
        ], $rows);
    }

    /** @param array<string, int> $needs */
    private function onTheBooksNeeds(array $needs): void
    {
        $watches = $this->service(StationWatchService::class);
        self::assertInstanceOf(StationWatchService::class, $watches);
        $watch = $watches->forStation($this->onTheBooks);
        self::assertNotNull($watch);
        $watch->setNeedsPerShift($needs);
        $this->em->flush();
    }
}
