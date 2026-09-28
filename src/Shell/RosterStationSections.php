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

namespace Uhifadhi\Roster\Shell;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Bundle\AreaBundle\Repository\StationRepository;
use Uhifadhi\Contracts\Area\StationAction;
use Uhifadhi\Contracts\Area\StationSection;
use Uhifadhi\Contracts\Area\StationSectionRequest;
use Uhifadhi\Contracts\Area\StationSections;
use Uhifadhi\Contracts\Area\StationSectionsInterface;
use Uhifadhi\Contracts\Area\StationSurface;
use Uhifadhi\Roster\Controller\RosterConfigureController;
use Uhifadhi\Roster\Entity\Shift;
use Uhifadhi\Roster\Entity\StationWatch;
use Uhifadhi\Roster\Enum\RuleKind;
use Uhifadhi\Roster\Module\RosterModuleProvider;
use Uhifadhi\Roster\Service\PresenceReader;
use Uhifadhi\Roster\Service\RosterSettingsService;
use Uhifadhi\Roster\Service\ShiftRuleService;
use Uhifadhi\Roster\Service\ShiftVocabularyService;
use Uhifadhi\Roster\Service\StationWatchService;

/**
 * WHAT THE ROSTER PUTS ON A POST — a *Watch and presence* band on the
 * station's record, and a *Roster* block on its card on the area's Stations
 * configure page.
 *
 * RULED 18 sep, and this class is the whole of what survived it. The station
 * is the AREA's: its name, kind, point, call sign, zone and postings all
 * belong there. What the roster keeps is the watch — which shifts the post
 * expects, how long its silence may run, how wide its catchment is — and the
 * presence derived from the people on it. Those are drawn INTO the area's own
 * pages rather than onto a page of this module's, which is why
 * `modules/roster/stations.html` was retired as a page.
 *
 * A POST THIS MODULE DOES NOT KEEP ON ITS BOOKS IS ANSWERED WITH SILENCE ON
 * ITS RECORD — no key in the answer at all, so the area draws no band and no
 * placeholder. That is not the same as a post this module has nothing to
 * REPORT about: one is "not our post", the other is a band saying so in this
 * module's own words, and the contract keeps them apart deliberately.
 *
 * ON THE CONFIGURE CARD IT IS ANSWERED WITH THE DOOR, and the difference is
 * the whole point of a configure surface: the record is where somebody reads
 * a post, and saying nothing there is honest; the card is where somebody
 * SETS ONE UP, and a post that can never be worked because no screen offers
 * the one write is the defect this block exists to close. Ported from the
 * design's own `configure-stations.html` — "not on the roster's books", and
 * one control that changes it.
 *
 * THE AREA DRAWS THE BAND AND THIS DRAWS WHAT IS IN IT. The card, the
 * heading row, the contributor tag and the summary line are the surface's
 * chrome, written once by the bundle that owns the page; the templates named
 * here write rows and nothing around them.
 *
 * AND A THIRD SURFACE, THE PERSON'S OWN (#19, ruled 28 Sep 2026): the post
 * as somebody posted at it reads it on `/me/station`. There it says what the
 * post EXPECTS of them — its watches, how many each needs, how near a
 * check-in has to be, how often a handset reports — and nothing else: no
 * presence of anybody else, no door into this module's configure page. It is
 * named in its own branch, never left to fall into the configure one, because
 * the configure block is a setting-up surface and a ranger is not setting a
 * post up.
 *
 * BATCHED, and it matters: the configure page draws a card per station, so a
 * contributor asked once per card would run a query per card per module per
 * page. It is handed the whole set and asks its questions once.
 */
final readonly class RosterStationSections implements StationSectionsInterface
{
    /** The band on the post's own record. */
    public const string WATCH = 'watch';

    /** The block on the post's card on the area's Stations configure page. */
    public const string ROSTER = 'roster';

    /** What the post expects, on the person's own page (#19). */
    public const string WATCHES = 'watches';

    public function __construct(
        private StationRepository $stations,
        private StationWatchService $watches,
        private ShiftVocabularyService $shifts,
        private PresenceReader $presence,
        private ShiftRuleService $rules,
        private RosterSettingsService $settings,
        private UrlGeneratorInterface $router,
        private ?CsrfTokenManagerInterface $csrfTokenManager = null,
        // A TOKEN IS A THING IN A SESSION, so it can only be issued inside
        // a request that has one. This contributor is called from a page
        // and nowhere else, and asking the manager outside one throws.
        private ?RequestStack $requests = null,
    ) {
    }

    public function moduleSlug(): string
    {
        return RosterModuleProvider::SLUG;
    }

    public function sectionsFor(StationSectionRequest $request): StationSections
    {
        if ($request->isEmpty()) {
            return StationSections::none();
        }

        $stations = $this->stationsByUuid($request->stationUuids());
        if ([] === $stations) {
            return StationSections::none();
        }

        $today = new \DateTimeImmutable('today');

        $byStation = [];
        foreach ($stations as $uuid => $station) {
            $watch = $this->watches->forStation($station);

            // EVERY SURFACE BY NAME. A match with no default is what keeps a
            // surface added later from falling silently into one of these:
            // PHP refuses an unhandled case at the line that forgot it.
            //
            // NOT ON THE BOOKS: the record and the person's own page say
            // nothing at all; the configure card says so, and offers the one
            // write that changes it.
            $section = match ($request->surface) {
                StationSurface::Record => null === $watch ? null : $this->recordBand($station, $watch, $today),
                StationSurface::Configure => null === $watch ? $this->offTheBooksBlock($station) : $this->configureBlock($station, $watch),
                StationSurface::Mine => null === $watch ? null : $this->mineBand($station, $watch),
            };

            if (null !== $section) {
                $byStation[$uuid] = [$section];
            }
        }

        return new StationSections($byStation);
    }

    /**
     * THE *WATCH AND PRESENCE* BAND on the post's record: what it expects,
     * and who is on it now against who was rostered.
     */
    private function recordBand(Station $station, StationWatch $watch, \DateTimeImmutable $today): StationSection
    {
        $presence = $this->presence->post($station, $today);
        $area = $station->getArea();

        return new StationSection(
            id: self::WATCH,
            label: 'Watch and presence',
            template: '@UhifadhiRoster/station/_watch.html.twig',
            variables: [
                'watch' => $watch,
                'presence' => $presence,
                'shifts' => $this->labelsFor($station),
                'day' => $today,
                // THE RING IS THE POST'S, and this section draws the post.
                'catchmentM' => $station->getCatchmentM(),
            ],
            summary: $this->summaryFor($watch, $presence?->shortfall() ?? 0),
            actions: null === $area ? [] : [
                new StationAction('The roster', $this->router->generate(
                    RosterConfigureController::WATCHES_ROUTE,
                    ['uuid' => (string) $area->getUuidString()],
                )),
            ],
        );
    }

    /**
     * THE *WATCHES* BAND on the person's own page (#19; design
     * variants-my-dashboard/station.html, SN·03): one row per watch the post
     * runs — its window and how many people it needs — then how near a
     * check-in has to be and how often a handset reports on watch.
     *
     * THE CHECK-IN ROW IS A DISTANCE because that is the rule this module
     * keeps ({@see RuleKind::CheckInWithin}, "of the station"); the design's
     * "within 30 min of the watch" is a time window no rule here states.
     *
     * NO ACTION: the person reading it may not edit a watch, and a door they
     * would meet a 403 behind is not a door.
     */
    private function mineBand(Station $station, StationWatch $watch): StationSection
    {
        $windows = [];
        foreach ($this->shiftsOf($station) as $shift) {
            $windows[$shift->getKey()] = $shift;
        }

        $rows = [];
        foreach ($watch->getExpects() as $key) {
            $shift = $windows[$key] ?? null;
            $rows[] = [
                'label' => null === $shift ? $key : $shift->getLabel(),
                'window' => null === $shift ? null : $shift->getStartsAt().'–'.$shift->getEndsAt(),
                'people' => $watch->needsOn($key),
            ];
        }

        $area = $station->getArea();

        return new StationSection(
            id: self::WATCHES,
            label: 'Watches',
            template: '@UhifadhiRoster/station/_mine.html.twig',
            variables: [
                'shifts' => $rows,
                'checkInWithin' => $this->rules->effective($station, RuleKind::CheckInWithin)->label(),
                'pingEvery' => null === $area ? null : $this->settings->pingIntervalFor($area),
            ],
            summary: 'What the post expects.',
            actions: [],
        );
    }

    /**
     * THE *ROSTER* BLOCK on the post's configure card — the four columns
     * this module owns, read-only here. They are EDITED in one place, the
     * module's own Watches section, and this is the other door onto them: a
     * second editor would be a second validation and eventually a second
     * answer.
     */
    private function configureBlock(Station $station, StationWatch $watch): StationSection
    {
        $area = $station->getArea();

        return new StationSection(
            id: self::ROSTER,
            label: 'Roster',
            template: '@UhifadhiRoster/station/_configure.html.twig',
            variables: [
                'watch' => $watch,
                'shifts' => $this->labelsFor($station),
                'pool' => $this->watches->poolSizeFor($station),
                'catchmentM' => $station->getCatchmentM(),
            ],
            summary: 'The shifts this post expects, how long its silence may run, and how wide its catchment is.',
            actions: null === $area ? [] : [
                new StationAction('Edit on the roster', $this->router->generate(
                    RosterConfigureController::WATCHES_ROUTE,
                    ['uuid' => (string) $area->getUuidString()],
                )),
            ],
        );
    }

    /**
     * THE *ROSTER* BLOCK ON A POST THIS MODULE DOES NOT YET WORK — one row,
     * and the one control that changes it.
     *
     * THE WRITE IS THE MODULE'S OWN, AT THE MODULE'S OWN ADDRESS. The area
     * owns the card and knows nothing about a watch; this block posts to
     * the roster's configure controller and says where it came from, so the
     * redirect lands back on the card it was pressed on.
     */
    private function offTheBooksBlock(Station $station): StationSection
    {
        $area = $station->getArea();

        return new StationSection(
            id: self::ROSTER,
            label: 'Watch and presence',
            template: '@UhifadhiRoster/station/_configure_off.html.twig',
            variables: [
                'station' => $station,
                // NO TOKEN, NO DOOR. Without SecurityBundle the configure
                // controller is not registered at all, so a button here
                // would post at a route nobody mounted.
                'door' => null === $area || !$this->mayIssueAToken() ? null : $this->router->generate(
                    RosterConfigureController::ADD_TO_ROSTER_ROUTE,
                    ['uuid' => (string) $area->getUuidString()],
                ),
                'csrfToken' => $this->mayIssueAToken()
                    ? $this->csrfTokenManager?->getToken(RosterConfigureController::CSRF_TOKEN_ID)->getValue() ?? ''
                    : '',
                'back' => RosterConfigureController::BACK_TO_THE_STATION,
            ],
            summary: 'Not on the roster’s books.',
            actions: [],
        );
    }

    /**
     * WHETHER A WRITE CAN BE OFFERED AT ALL.
     *
     * TWO THINGS HAVE TO BE TRUE and neither is this module's doing: the
     * installation runs SecurityBundle, so the route the door posts to
     * exists at all; and this is a request with a session, so a token can
     * be issued. Without either, the block draws the row and no button —
     * which is the honest state, rather than a button at a route nobody
     * mounted or a 500 inside somebody else's page.
     */
    private function mayIssueAToken(): bool
    {
        return null !== $this->csrfTokenManager && true === $this->requests?->getCurrentRequest()?->hasSession();
    }

    /**
     * THE LINE BESIDE THE HEADING. A post that declares no watch says so
     * plainly — it is never counted, never late and never a hole, and a band
     * that stayed silent about it would look like a band that failed to
     * load.
     */
    private function summaryFor(StationWatch $watch, int $shortfall): string
    {
        if ($watch->expectsNothing()) {
            return 'This post declares no watch, so it is never counted, never late and never a hole — only offline.';
        }

        return 0 === $shortfall
            ? 'Who is due here today, and what the positions say about them.'
            : \sprintf('Who is due here today, and what the positions say about them. %d watch%s short.', $shortfall, 1 === $shortfall ? '' : 'es');
    }

    /**
     * The area's shift labels, keyed by the key a watch stores.
     *
     * @return array<string, string>
     */
    private function labelsFor(Station $station): array
    {
        $labels = [];
        foreach ($this->shiftsOf($station) as $shift) {
            $labels[$shift->getKey()] = $shift->getLabel();
        }

        return $labels;
    }

    /** @return list<Shift> the area's shift vocabulary; none for a post with no area */
    private function shiftsOf(Station $station): array
    {
        $area = $station->getArea();

        return null === $area ? [] : $this->shifts->forArea($area);
    }

    /**
     * @param list<string> $uuids
     *
     * @return array<string, Station> keyed by uuid, in no particular order — the answer is keyed too
     */
    private function stationsByUuid(array $uuids): array
    {
        $valid = [];
        foreach ($uuids as $uuid) {
            if (Uuid::isValid($uuid)) {
                $valid[] = Uuid::fromString($uuid);
            }
        }

        if ([] === $valid) {
            return [];
        }

        $stations = [];
        foreach ($this->stations->findBy(['uuid' => $valid]) as $station) {
            $stations[(string) $station->getUuidString()] = $station;
        }

        return $stations;
    }
}
