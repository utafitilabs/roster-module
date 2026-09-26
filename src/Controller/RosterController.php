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

namespace Uhifadhi\Roster\Controller;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Exception\InvalidParameterException;
use Symfony\Component\Routing\Exception\MissingMandatoryParametersException;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;
use Twig\Environment;
use Uhifadhi\Bundle\AreaBundle\Controller\StationsController;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Bundle\AreaBundle\Repository\StationRepository;
use Uhifadhi\Bundle\AreaBundle\Service\PresenceStreamService;
use Uhifadhi\Bundle\ShellBundle\Widget\Service\WidgetService;
use Uhifadhi\Contracts\Access\Verb;
use Uhifadhi\Contracts\Area\DayState;
use Uhifadhi\Contracts\Area\LivePositionsInterface;
use Uhifadhi\Contracts\Atlas\YearMonth;
use Uhifadhi\Contracts\Entity\UserInterface;
use Uhifadhi\Roster\Access\RosterConcerns;
use Uhifadhi\Roster\Entity\Duty;
use Uhifadhi\Roster\Entity\Pattern;
use Uhifadhi\Roster\Enum\NightThenDay;
use Uhifadhi\Roster\Enum\RuleKind;
use Uhifadhi\Roster\Model\AgendaFilter;
use Uhifadhi\Roster\Model\FillPlan;
use Uhifadhi\Roster\Model\PostPresence;
use Uhifadhi\Roster\Model\PostState;
use Uhifadhi\Roster\Model\Sheet;
use Uhifadhi\Roster\Model\SheetWindow;
use Uhifadhi\Roster\Module\RosterModuleProvider;
use Uhifadhi\Roster\Repository\AbsenceRepository;
use Uhifadhi\Roster\Repository\DutyRepository;
use Uhifadhi\Roster\Repository\PatternRepository;
use Uhifadhi\Roster\Repository\ShiftRepository;
use Uhifadhi\Roster\Repository\StationWatchRepository;
use Uhifadhi\Roster\Service\AgendaService;
use Uhifadhi\Roster\Service\DayBoardService;
use Uhifadhi\Roster\Service\DayPlanService;
use Uhifadhi\Roster\Service\PatternService;
use Uhifadhi\Roster\Service\PresenceReader;
use Uhifadhi\Roster\Service\RosterCalendar;
use Uhifadhi\Roster\Service\RosterDashboardService;
use Uhifadhi\Roster\Service\RosteredPeople;
use Uhifadhi\Roster\Service\RosterIdentityService;
use Uhifadhi\Roster\Service\RosterLiveService;
use Uhifadhi\Roster\Service\RotaService;
use Uhifadhi\Roster\Service\SheetDayService;
use Uhifadhi\Roster\Service\SheetFillService;
use Uhifadhi\Roster\Service\SheetPreferences;
use Uhifadhi\Roster\Service\SheetService;
use Uhifadhi\Roster\Service\ShiftRuleService;
use Uhifadhi\Roster\Service\SwapCostService;
use Uhifadhi\Roster\Service\SwapService;
use Uhifadhi\Roster\Widget\RosterRailWidgets;
use Uhifadhi\Roster\Widget\RosterWidgets;

/**
 * THE ROSTER'S OWN PAGES, at /areas/{uuid}/modules/roster.
 *
 * THE MODULE MARKER IS SPELLED OUT rather than imported from the registry's
 * constant. A class constant in a route attribute is a LOAD-TIME dependency,
 * and while this module does require the core, a module's own marker is one
 * string it should be able to say for itself; a seam test asserts the two
 * agree. Without the marker a page is not exempt from the per-area gate — it
 * is guessed at.
 *
 * WHERE AN AREA HAS PARKED THIS MODULE, EVERY PAGE HERE IS 404. The registry
 * owns the ledger and enforces it in one listener; nothing in this class asks.
 * 404 and not 403: a parked module is not withheld, the area is not running
 * it.
 *
 * NO BASE CLASS. A reusable bundle's controller does not extend
 * AbstractController — that ties it to a service-subscriber container it
 * cannot assume and hides its dependencies behind a container lookup. It takes
 * what it needs in its constructor and is wired explicitly.
 *
 * @see https://symfony.com/doc/current/bundles/best_practices.html
 */
#[Route(defaults: ['_uhifadhi_module' => RosterModuleProvider::SLUG])]
final class RosterController
{
    /**
     * The module's front door, and the route its catalogue tile links to.
     * Published as a constant because three other classes name it — the tab
     * declaration, the configure screens' trail, and the provider.
     */
    public const string OVERVIEW_ROUTE = 'roster_overview';

    /** The planner's tab: the sheet — people down, days across, per station. */
    public const string WEEK_ROUTE = 'roster_week';

    /** How this person reads the sheet: the weeks in view, and the folds. */
    public const string SHEET_PREFS_ROUTE = 'roster_sheet_prefs';

    /** Filling a station's days from a pattern, or saying what filling would do. */
    public const string SHEET_FILL_ROUTE = 'roster_sheet_fill';

    /** One day, changed by hand — every item on the sheet's own menu. */
    public const string SHEET_DAY_ROUTE = 'roster_sheet_day';

    /** Handing a day back to the pattern. */
    public const string SHEET_CLEAR_MARK_ROUTE = 'roster_sheet_clear_mark';

    /** Offering a watch to somebody, answered on the handset. */
    public const string OFFER_SWAP_ROUTE = 'roster_swap_offer';

    /** Taking an offer back, before it has been answered. */
    public const string WITHDRAW_SWAP_ROUTE = 'roster_swap_withdraw';

    /**
     * WHAT A PERSON MUST HOLD TO OFFER A WATCH TO SOMEBODY ELSE.
     *
     * RECORD and not CONFIGURE: moving one watch between two people on one
     * night is a duty officer's daily work, and making it need the verb that
     * rewrites the area's rotations would push every shift change up to
     * whoever holds that.
     */
    public const string RECORD = RosterConcerns::ROSTER.'.'.Verb::Record->value;

    /** One token id for the writes this controller makes. */
    public const string CSRF_TOKEN_ID = 'roster_week';

    /** The agenda: who is due today, post by post, with how each day reads. */
    public const string TODAY_ROUTE = 'roster_today';

    /** The day as a wall: twenty-four hours across, one post per row. */
    public const string BOARD_ROUTE = 'roster_board';

    /** One ranger's month, in the house calendar. */
    public const string CALENDAR_ROUTE = 'roster_calendar';

    /** What is true this minute. */
    public const string LIVE_ROUTE = 'roster_live';

    /** Adopting one of the rail's arrangements. */
    public const string RAIL_PRESET_ROUTE = 'roster_live_rail_preset';

    /** Moving, taking out or putting back one of the rail's lists. */
    public const string RAIL_EDIT_ROUTE = 'roster_live_rail_edit';

    /**
     * WHAT THE ATLAS BRINGS BACK WITH THE PLATE, prefixed once.
     *
     * A row in the rail asks the plate to change in place and to carry its
     * own list across from the same answer, so the row that was clicked
     * returns wearing the mark. The id is the list's; this is its stem.
     */
    public const string RAIL_LIST_ID = 'rail-list-';

    /** The generated plan for a day, as slots to fill. */
    public const string PLAN_ROUTE = 'roster_plan';

    /** Writing the sheet's picks as duties. */
    public const string PUBLISH_ROUTE = 'roster_plan_publish';

    /**
     * HOW MANY ROWS A DASHBOARD CARD SHOWS BEFORE IT SAYS HOW MANY THERE
     * WERE. A card's height never grows with its data (ruled): the rest are
     * one click away on the tab that is built to list them.
     */
    public const int DECISIONS_SHOWN = 6;

    public const int STATIONS_SHOWN = 6;

    public function __construct(
        private readonly Environment $twig,
        private readonly RosterIdentityService $identity,
        private readonly SheetService $sheet,
        private readonly SheetFillService $fills,
        private readonly SheetDayService $days,
        private readonly SheetPreferences $preferences,
        private readonly PatternService $patterns,
        private readonly PatternRepository $patternRows,
        private readonly StationRepository $stations,
        private readonly StationWatchRepository $watches,
        private readonly ShiftRuleService $rules,
        private readonly PresenceReader $presence,
        private readonly DayBoardService $board,
        private readonly RosteredPeople $people,
        private readonly RosterCalendar $calendar,
        private readonly SwapService $swaps,
        private readonly SwapCostService $cost,
        private readonly RosterDashboardService $dashboard,
        private readonly AgendaService $agenda,
        private readonly ShiftRepository $shifts,
        private readonly DutyRepository $duties,
        private readonly LivePositionsInterface $positions,
        private readonly RosterLiveService $liveService,
        private readonly DayPlanService $plans,
        private readonly AbsenceRepository $absences,
        private readonly WidgetService $widgetService,
        private readonly UrlGeneratorInterface $router,
        /*
         * NULL WHERE THE INSTALLATION RUNS NO SECURITY. There the week tab
         * offers no swap — there is nobody to attribute an offer to and
         * nothing to refuse one with — and the page reads as a plan rather
         * than a form that cannot post.
         */
        private readonly ?TokenStorageInterface $tokens = null,
        private readonly ?CsrfTokenManagerInterface $csrfTokenManager = null,
        /** The area's live stream: the Live tab's marks keep moving, as the area overview's do. */
        private readonly ?PresenceStreamService $streams = null,
    ) {
    }

    /**
     * DID THE REQUEST COME FROM THE PAGE — and nothing else, because the
     * other question a write asks is the route's own.
     *
     * WHO MAY WRITE IS THE `#[IsGranted]` ON THE ROUTE. A second check here
     * would be a gate in a place no test walks and no door mirrors: the
     * template draws its control through `door()` naming the pair the route
     * carries, and the two are held together by the module's conformance.
     */
    private function guardTheForm(Request $request): void
    {
        $token = $request->request->get('_token');
        if (null === $this->csrfTokenManager || !\is_string($token) || !$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_TOKEN_ID, $token))) {
            throw new AccessDeniedHttpException('That form did not come from this page.');
        }
    }

    /** Who is offering. Null where the installation runs no security. */
    private function viewer(): ?UserInterface
    {
        $user = $this->tokens?->getToken()?->getUser();

        return $user instanceof UserInterface ? $user : null;
    }

    /**
     * Say it in the frame's own flashes, where the session carries a bag —
     * `SessionInterface` does not promise one, and losing a sentence is a
     * smaller failure than a 500.
     */
    private function flash(Request $request, string $type, string $message): void
    {
        $session = $request->hasSession() ? $request->getSession() : null;

        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add($type, $message);
        }
    }

    /**
     * THE OVERVIEW TAB — the header, the six-tab strip, the identity band,
     * then the composed surface.
     *
     * THE SURFACE IS NOT HERE YET, and the reason is worth stating rather
     * than filling with a placeholder: every widget the shipped composition
     * names — the day's check-ins, what needs a decision, the posts and who
     * the pings put on them, the plate — is a reading of PRESENCE, and
     * presence is the area's to answer through a seam that has not landed.
     * Drawing any of it today would mean inventing the one thing this module
     * is ruled never to invent.
     */
    #[Route('/areas/{uuid}/modules/roster', name: self::OVERVIEW_ROUTE, requirements: ['uuid' => Requirement::UUID], methods: ['GET'])]
    #[IsGranted('areas.read', subject: 'area')]
    public function overview(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
    ): Response {
        $day = new \DateTimeImmutable('today');

        // ONE READ OF THE DAY, and it is the SAME build the widget library
        // hands its previews. Two contexts would eventually disagree, and
        // the disagreement would show up as a preview that looked nothing
        // like the card it added.
        return new Response($this->twig->render('@UhifadhiRoster/overview/show.html.twig', [
            'area' => $area,
            'band' => $this->identity->bandFor($area),
            'dash' => $this->dashboard->build($area, $day),
            'rosterDecisionLimit' => self::DECISIONS_SHOWN,
            'rosterStationLimit' => self::STATIONS_SHOWN,
            // WHICH WIDGETS, HOW WIDE, IN WHAT ORDER — the shell's widget
            // framework resolving this surface's catalogue: the shipped
            // composition until somebody changes it in the library.
            'widgets' => $this->widgetService->resolve(RosterWidgets::declaration(), $this->viewer(), $area->getUuid()),
        ]));
    }

    /**
     * THE WEEK — THE PLANNING SHEET. People down, days across, per
     * station.
     *
     * IT OPENS ON THE CURRENT WEEK, always starting on a monday: a week
     * that began on the day somebody happened to look is not a week
     * anybody plans in, and two people comparing notes would be
     * comparing different weeks. `?from=` moves it and an unreadable one
     * falls back rather than failing — a mistyped date in a url is not
     * worth a 500, and the head says which weeks it is drawing.
     *
     * THE WINDOW AND THE FOLDS ARE THIS PERSON'S. `?weeks=` is both the
     * link the chip carries and the act of choosing: a filtered sheet is
     * a url somebody can send, and pressing it also remembers.
     *
     * THE SWAP FLOW STAYS. A swap is two cells and a cost, and this is
     * the only tab where the donor and the gap are in the same screenful.
     */
    #[Route('/areas/{uuid}/modules/roster/week', name: self::WEEK_ROUTE, requirements: ['uuid' => Requirement::UUID], methods: ['GET'])]
    #[IsGranted('areas.read', subject: 'area')]
    public function week(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        Request $request,
    ): Response {
        $viewer = $this->viewer();

        $asked = $request->query->get('weeks');
        $weeks = is_numeric($asked) ? (int) $asked : $this->preferences->weeksFor($viewer, $area);
        if (is_numeric($asked)) {
            $this->preferences->rememberWeeks($viewer, $area, $weeks);
        }

        $window = SheetWindow::of($this->askedFor($request) ?? new \DateTimeImmutable('today'), $weeks);
        $chosenStation = $this->stationIn($area, $request->query->get('station'));
        // ONE READ, and the filter is applied to it. The station chip has
        // to list every station whatever the sheet under it is narrowed
        // to — a chip that only offered what is already showing could
        // never be used to change it — so reading the whole area once and
        // narrowing in memory is both the cheaper answer and the honest
        // one.
        $whole = $this->sheet->read($area, $window);
        $sheet = null === $chosenStation ? $whole : $whole->only((string) $chosenStation->getUuidString());

        $from = $window->from;
        $through = $window->through;

        return new Response($this->twig->render('@UhifadhiRoster/week/show.html.twig', [
            'area' => $area,
            'sheet' => $sheet,
            'window' => $window,
            'days' => $window->days(),
            'folded' => $this->preferences->foldedFor($viewer, $area),
            // THE FILL ROW'S OWN FIELDS: which stations may be filled,
            // from which pattern, and the mondays it offers as a start.
            'stations' => $this->stationsOnTheBooks($area),
            'chosenStation' => $chosenStation,
            'fillPatterns' => $this->fillPatterns($area),
            'patternColours' => $this->patterns->coloursFor($area),
            // THE STATION FILTER LISTS EVERY STATION, whatever the sheet
            // under it is narrowed to: a chip that only offered what is
            // already showing could never be used to change it.
            'stationOptions' => self::stationOptions($whole),
            'startDates' => self::startDates($window),
            // WHAT THE ROW WOULD DO AS IT STANDS, said in numbers rather
            // than in general terms. The design states them at rest and
            // not only after Preview is pressed, and it is right to: a
            // caption that only says "it fills forward" is a caption
            // nobody reads twice. It is the same walk Preview runs, with
            // nothing written.
            'fillPreview' => $this->previewOfTheRowAsItStands($area, $window),
            // WHAT A FILL OBEYS, READ-ONLY. RULED 21 sep: the rules live
            // on the Watches card, so the row states them and the door
            // goes there rather than offering a second place to set them.
            'fillRules' => $this->fillRules($area),
            // THE KEY UNDER THE SHEET — the area's own shifts, in its own
            // colours, so the reader is never asked what a tint means.
            'shifts' => $this->shifts->findByArea($area),
            // THE SWAP FLOW: the register of offers over this window, and
            // the trade being put together, if one is.
            'recent' => $this->swaps->recentBetween($area, $from, $through, 5),
            'offering' => $offering = $this->swaps->offering($area, $request->query->get('give'), $request->query->get('take')),
            'cost' => null === $offering ? null : $this->cost->of($offering['duty'], $offering['taking']),
            'csrfToken' => $this->csrfTokenManager?->getToken(self::CSRF_TOKEN_ID)->getValue() ?? '',
        ]));
    }

    /**
     * REMEMBER HOW THIS PERSON READS THE SHEET — the weeks in view and
     * the stations they keep folded.
     *
     * IT ANSWERS 204 AND NOT A REDIRECT. The fold has already happened in
     * the browser; sending the page back would scroll a planner who had
     * just folded one station of twelve to the top of the sheet.
     */
    #[Route('/areas/{uuid}/modules/roster/sheet/prefs', name: self::SHEET_PREFS_ROUTE, requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted('roster.record', subject: 'area')]
    public function sheetPreferences(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        Request $request,
    ): Response {
        $this->guardTheForm($request);

        $viewer = $this->viewer();

        $weeks = $request->request->get('weeks');
        if (is_numeric($weeks)) {
            $this->preferences->rememberWeeks($viewer, $area, (int) $weeks);
        }

        if ($request->request->has('folded')) {
            $folded = [];
            foreach ($request->request->all('folded') as $station) {
                if (\is_string($station) && '' !== $station) {
                    $folded[] = $station;
                }
            }

            $this->preferences->rememberFolds($viewer, $area, $folded);
        }

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * FILL A STATION'S DAYS FROM A PATTERN — or say what filling would
     * do, which is the same walk with nothing written.
     */
    #[Route('/areas/{uuid}/modules/roster/sheet/fill', name: self::SHEET_FILL_ROUTE, requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted('roster.record', subject: 'area')]
    public function sheetFill(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        Request $request,
    ): Response {
        $this->guardTheForm($request);

        $station = $this->stationIn($area, $request->request->get('station'));
        $pattern = $this->patternIn($area, $request->request->get('pattern'));
        $from = self::readDate((string) $request->request->get('from', '')) ?? new \DateTimeImmutable('today');

        if (null === $station || null === $pattern) {
            $this->flash($request, 'error', 'Pick a station and a pattern before filling.');

            return $this->backToTheSheet($area, $request);
        }

        $preview = '' !== (string) $request->request->get('preview', '');
        $plan = $preview
            ? $this->fills->preview($station, $pattern, $from)
            : $this->fills->fill($station, $pattern, $from);

        $this->flash($request, $preview ? 'info' : 'success', \sprintf(
            '%s %d day%s at %s to %s · %d edited day%s left as %s.',
            $preview ? 'Would fill' : 'Filled',
            $plan->days,
            1 === $plan->days ? '' : 's',
            $station->getName() ?? 'that station',
            strtolower($plan->through->format('D j M')),
            $plan->leftAlone,
            1 === $plan->leftAlone ? '' : 's',
            1 === $plan->leftAlone ? 'it is' : 'they are',
        ));

        return $this->backToTheSheet($area, $request);
    }

    /**
     * ONE DAY, CHANGED BY HAND — every item on the sheet's own menu, and
     * each of them leaves the mark that stops a fill deciding the day
     * again.
     */
    #[Route('/areas/{uuid}/modules/roster/sheet/day', name: self::SHEET_DAY_ROUTE, requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted('roster.record', subject: 'area')]
    public function sheetDay(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        Request $request,
    ): Response {
        $this->guardTheForm($request);

        $duty = $this->dutyIn($area, $request->request->get('duty'));

        if (null === $duty) {
            $this->flash($request, 'error', 'That day is no longer on the sheet.');

            return $this->backToTheSheet($area, $request);
        }

        $viewer = $this->viewer();

        try {
            match ((string) $request->request->get('op')) {
                'shift' => $this->days->changeShift($duty, (string) $request->request->get('shift'), $viewer),
                'move' => $this->days->moveTo($duty, $this->person($area, (string) $request->request->get('person')), $viewer),
                'off' => $this->days->giveTheDayOff($duty, $viewer),
                'clear' => $this->days->clearTheMark($duty->getStation(), $duty->getOnDay(), $duty->getPerson()),
                default => $this->flash($request, 'error', 'That is not something a day can be asked to do.'),
            };
        } catch (\InvalidArgumentException $refused) {
            $this->flash($request, 'error', $refused->getMessage());
        }

        return $this->backToTheSheet($area, $request);
    }

    /**
     * AND CLEARING A MARK ON A DAY THAT HAS NO DUTY LEFT — the common
     * case, because the commonest edit is taking somebody off a watch.
     */
    #[Route('/areas/{uuid}/modules/roster/sheet/mark/clear', name: self::SHEET_CLEAR_MARK_ROUTE, requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted('roster.record', subject: 'area')]
    public function clearTheMark(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        Request $request,
    ): Response {
        $this->guardTheForm($request);

        $station = $this->stationIn($area, $request->request->get('station'));
        $day = self::readDate((string) $request->request->get('day', ''));

        if (null === $station || null === $day) {
            $this->flash($request, 'error', 'That day is no longer on the sheet.');

            return $this->backToTheSheet($area, $request);
        }

        try {
            $this->days->clearTheMark($station, $day, $this->person($area, (string) $request->request->get('person')));
        } catch (\InvalidArgumentException $refused) {
            $this->flash($request, 'error', $refused->getMessage());
        }

        return $this->backToTheSheet($area, $request);
    }

    /**
     * BACK TO THE SHEET SOMEBODY WAS LOOKING AT — its weeks, its window
     * and its station filter, so an edit never moves the page under the
     * person who made it.
     */
    private function backToTheSheet(AreaOfInterest $area, Request $request): RedirectResponse
    {
        $parameters = ['uuid' => (string) $area->getUuidString()];
        foreach (['from', 'weeks', 'station'] as $carried) {
            $value = $request->request->get($carried);
            if (\is_string($value) && '' !== $value) {
                $parameters[$carried] = $value;
            }
        }

        return new RedirectResponse($this->router->generate(self::WEEK_ROUTE, $parameters));
    }

    /**
     * SOMEBODY ROSTERED IN THIS AREA, by uuid.
     *
     * @throws \InvalidArgumentException when nobody in this area answers to it
     */
    private function person(AreaOfInterest $area, string $uuid): UserInterface
    {
        return $this->people->byUuid($area)[$uuid]
            ?? throw new \InvalidArgumentException('Nobody rostered in this area answers to that.');
    }

    /**
     * A STATION ON THIS AREA'S BOOKS, by uuid — and never another area's.
     * The uuid is somebody's to type, so "this row exists" is not the
     * question; "this row is in the park you are looking at" is.
     */
    private function stationIn(AreaOfInterest $area, mixed $uuid): ?Station
    {
        if (!\is_string($uuid) || !Uuid::isValid($uuid)) {
            return null;
        }

        $station = $this->stations->findOneBy(['uuid' => Uuid::fromString($uuid)]);

        return $station instanceof Station && $station->getArea()?->getId() === $area->getId() ? $station : null;
    }

    /** One of this area's patterns, by uuid. */
    private function patternIn(AreaOfInterest $area, mixed $uuid): ?Pattern
    {
        if (!\is_string($uuid) || !Uuid::isValid($uuid)) {
            return null;
        }

        return $this->patternRows->findOneByUuid($area, Uuid::fromString($uuid));
    }

    /** One of this area's duties, by uuid. */
    private function dutyIn(AreaOfInterest $area, mixed $uuid): ?Duty
    {
        if (!\is_string($uuid) || !Uuid::isValid($uuid)) {
            return null;
        }

        $duty = $this->duties->findOneBy(['area' => $area, 'uuid' => Uuid::fromString($uuid)]);

        return $duty instanceof Duty ? $duty : null;
    }

    /**
     * WHAT THE FILL ROW WOULD DO WITH THE OPTIONS IT OPENS ON — the first
     * station on the books, its own pattern where it has one, from the
     * window's own monday.
     *
     * NULL WHERE THERE IS NOTHING TO FILL, which is also when the row
     * itself is not drawn.
     */
    private function previewOfTheRowAsItStands(AreaOfInterest $area, SheetWindow $window): ?FillPlan
    {
        $stations = $this->stationsOnTheBooks($area);
        $patterns = $this->patterns->forArea($area);

        if ([] === $stations || [] === $patterns) {
            return null;
        }

        $station = $stations[0];
        $pattern = $this->watches->findOneForStation($station)?->getPattern() ?? $patterns[0];

        return $this->fills->preview($station, $pattern, $window->from);
    }

    /**
     * THE AREA'S PATTERNS AS THE FILL ROW NEEDS THEM — the name it
     * derives, and the cycle the strip draws from.
     *
     * A LIST AND NOT THE ENTITIES, because the strip is redrawn in the
     * browser when the select changes and a template cannot hand a
     * `<script>` an object.
     *
     * @return list<array{uuid: string, name: string, cycle: list<string>}>
     */
    private function fillPatterns(AreaOfInterest $area): array
    {
        $patterns = [];
        foreach ($this->patterns->forArea($area) as $pattern) {
            $patterns[] = [
                'uuid' => (string) $pattern->getUuid(),
                'name' => $this->patterns->nameOf($pattern),
                'cycle' => $pattern->getCycle()->toStored(),
            ];
        }

        return $patterns;
    }

    /**
     * EVERY STATION THE FILTER OFFERS, with how many rangers stand at it.
     *
     * @return list<array{uuid: string, name: string, code: string|null, rangers: int}>
     */
    private static function stationOptions(Sheet $sheet): array
    {
        $options = [];
        foreach ($sheet->bands as $band) {
            $options[] = [
                'uuid' => $band->stationUuid,
                'name' => $band->stationName,
                'code' => $band->stationCode,
                'rangers' => $band->rangers(),
            ];
        }

        return $options;
    }

    /**
     * THE STATIONS THE FILL ROW MAY BE POINTED AT — the ones on the
     * roster's books, because a station nobody has given a watch to has
     * nothing to fill.
     *
     * @return list<Station>
     */
    private function stationsOnTheBooks(AreaOfInterest $area): array
    {
        $stations = [];
        foreach ($this->watches->findByArea($area) as $watch) {
            $stations[] = $watch->getStation();
        }

        return $stations;
    }

    /**
     * THE MONDAYS THE FILL ROW OFFERS AS A START — this one, the one
     * before, and the four after.
     *
     * A LIST AND NOT A DATE FIELD, because a fill that started on a
     * wednesday would put every seat of the ring half a week out of step
     * with the sheet's own columns.
     *
     * @return list<\DateTimeImmutable>
     */
    private static function startDates(SheetWindow $window): array
    {
        $dates = [];
        for ($offset = -1; $offset <= 4; ++$offset) {
            $dates[] = $window->from->modify(\sprintf('%+d days', 7 * $offset));
        }

        return $dates;
    }

    /**
     * WHAT A FILL OBEYS, IN THE ROW'S OWN WORDS — read-only, with the
     * door to the card that sets them.
     *
     * @return array{ahead: string, rest: string, nightThenDay: string}
     */
    private function fillRules(AreaOfInterest $area): array
    {
        $values = $this->rules->forArea($area);
        $choices = $this->rules->choicesForArea($area);

        return [
            'ahead' => $values[RuleKind::FillAhead->value]->label(),
            'rest' => $values[RuleKind::RestBetween->value]->label(),
            'nightThenDay' => NightThenDay::Never === $choices[RuleKind::NightThenDay->value]
                ? 'no night then day'
                : strtolower($choices[RuleKind::NightThenDay->value]->label()).' night then day',
        ];
    }

    /** A date in a request, or null where it cannot be read. */
    private static function readDate(string $raw): ?\DateTimeImmutable
    {
        if ('' === $raw) {
            return null;
        }

        try {
            return new \DateTimeImmutable($raw)->setTime(0, 0);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * OFFER A WATCH. It moves nobody: until the handset accepts, both duties
     * stand exactly as the rotation generated them.
     */
    #[Route('/areas/{uuid}/modules/roster/week/offer', name: self::OFFER_SWAP_ROUTE, requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted('roster.record', subject: 'area')]
    public function offerSwap(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        Request $request,
    ): Response {
        $this->guardTheForm($request);

        try {
            $this->swaps->offerFromRequest(
                $area,
                (string) $request->request->get('duty'),
                (string) $request->request->get('taking'),
                $this->viewer(),
            );
        } catch (\LogicException|\InvalidArgumentException $refused) {
            $this->flash($request, 'error', $refused->getMessage());
        }

        return new RedirectResponse($this->router->generate(self::WEEK_ROUTE, ['uuid' => (string) $area->getUuidString()]));
    }

    /** Take an offer back, before it has been answered. */
    #[Route('/areas/{uuid}/modules/roster/week/withdraw', name: self::WITHDRAW_SWAP_ROUTE, requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted('roster.record', subject: 'area')]
    public function withdrawSwap(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        Request $request,
    ): Response {
        $this->guardTheForm($request);

        try {
            $this->swaps->withdrawFromRequest($area, (string) $request->request->get('swap'), new \DateTimeImmutable());
        } catch (\LogicException|\InvalidArgumentException $refused) {
            $this->flash($request, 'error', $refused->getMessage());
        }

        return new RedirectResponse($this->router->generate(self::WEEK_ROUTE, ['uuid' => (string) $area->getUuidString()]));
    }

    /**
     * TODAY — the agenda, post by post: who is due, and how each of their
     * days actually reads.
     *
     * THE PLAN AND THE MEASUREMENT SIDE BY SIDE AND NEVER MERGED. The watch
     * is this module's; the state beside it is the area's reading of that
     * person's own check-in and pings. The gap between the two is the point
     * of the module, and collapsing them into one verdict would throw it
     * away.
     */
    #[Route('/areas/{uuid}/modules/roster/today', name: self::TODAY_ROUTE, requirements: ['uuid' => Requirement::UUID], methods: ['GET'])]
    #[IsGranted('areas.read', subject: 'area')]
    public function today(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        Request $request,
    ): Response {
        $day = $this->askedFor($request) ?? new \DateTimeImmutable('today');
        $now = new \DateTimeImmutable();

        $filter = AgendaFilter::fromQuery(
            $request->query->get('post'),
            $request->query->get('state'),
            $request->query->get('shift'),
            $request->query->get('q'),
        );

        // ONE READ OF THE DAY. The figures are folded over the WHOLE of it
        // and the rows are the narrowed copy, so narrowing to one post never
        // quietly rewrites the strip above it.
        $whole = $this->presence->postsOn($area, $day, $now);
        $windows = $this->shifts->windowsFor($area);

        return new Response($this->twig->render('@UhifadhiRoster/today/show.html.twig', [
            'area' => $area,
            'band' => $this->identity->bandFor($area),
            'day' => $day,
            'tomorrow' => $day->modify('+1 day'),
            'isToday' => $day->format('Y-m-d') === $now->format('Y-m-d'),
            'filter' => $filter,
            'posts' => $this->agenda->narrow($whole, $filter, $now, $windows),
            'tomorrowPosts' => $this->agenda->tomorrow($area, $day),
            'figures' => $this->agenda->figuresFor($area, $whole, $day, $now),
            'chosenPost' => $this->chosenPost($whole, $filter),
            'stateLabels' => self::stateLabels(),
            'stateCounts' => self::stateCounts($whole),
            'shiftLabels' => $this->shiftLabels($area),
            'shiftCounts' => self::shiftCounts($whole),
        ]));
    }

    /**
     * THE DAY BOARD — the day as a wall: twenty-four hours across, one post
     * per row, a block for every watch and a line where "now" is.
     *
     * A NIGHT WATCH IS TWO BLOCKS. It crosses midnight, and drawing it as
     * one would be a lie about the day it belongs to: the part before 06:00
     * belongs to the watch that began yesterday.
     */
    #[Route('/areas/{uuid}/modules/roster/board', name: self::BOARD_ROUTE, requirements: ['uuid' => Requirement::UUID], methods: ['GET'])]
    #[IsGranted('areas.read', subject: 'area')]
    public function board(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        Request $request,
    ): Response {
        $day = $this->askedFor($request) ?? new \DateTimeImmutable('today');
        $now = new \DateTimeImmutable();

        $filter = AgendaFilter::fromQuery(
            $request->query->get('post'),
            $request->query->get('state'),
            $request->query->get('shift'),
            $request->query->get('q'),
        );

        // THE SAME ONE READ THE AGENDA USES, and the same filter over it: the
        // two tabs are two readings of one day, so a day board filtered to a
        // post must show the post the agenda would have shown.
        $whole = $this->presence->postsOn($area, $day, $now);
        $windows = $this->shifts->windowsFor($area);
        $narrowed = $this->agenda->narrow($whole, $filter, $now, $windows);

        return new Response($this->twig->render('@UhifadhiRoster/board/show.html.twig', [
            'area' => $area,
            'band' => $this->identity->bandFor($area),
            'day' => $day,
            'isToday' => $day->format('Y-m-d') === $now->format('Y-m-d'),
            'filter' => $filter,
            'posts' => $narrowed,
            'blocks' => $this->board->blocksFor($area, $day),
            'figures' => $this->agenda->figuresFor($area, $whole, $day, $now),
            'quiet' => self::quiet($whole),
            'chosenPost' => $this->chosenPost($whole, $filter),
            'stateLabels' => self::stateLabels(),
            'stateCounts' => self::stateCounts($whole),
            'shiftLabels' => $this->shiftLabels($area),
            'shiftCounts' => self::shiftCounts($whole),
        ]));
    }

    /**
     * THE CALENDAR — one ranger's month, drawn in the HOUSE calendar.
     *
     * The grid, the cell, its fixed height and the stepper are the atlas's;
     * this module says only what happened on which day. It ships no month
     * grid of its own, which is the whole reason the component exists.
     */
    #[Route('/areas/{uuid}/modules/roster/calendar', name: self::CALENDAR_ROUTE, requirements: ['uuid' => Requirement::UUID], methods: ['GET'])]
    #[IsGranted('areas.read', subject: 'area')]
    public function calendar(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        Request $request,
    ): Response {
        $people = $this->people->rosteredIn($area);
        $chosen = $this->people->choose($people, $request->query->get('ranger'));
        $month = $this->people->monthOf($request->query->get('month'));

        return new Response($this->twig->render('@UhifadhiRoster/calendar/show.html.twig', [
            'area' => $area,
            'band' => $this->identity->bandFor($area),
            'people' => $people,
            // WHO, WHAT THEY ARE AND WHERE — the caption's own fields. The
            // role is team's fact and the post is the area's; this only
            // reads them, and says nothing where either is unanswered.
            'chosen' => null === $chosen ? null : $this->describe($area, $chosen, $month),
            'month' => $month,
            'figures' => null === $chosen ? null : $this->calendar->figuresFor($area, $chosen['uuid'], $month),
            'scope' => null === $chosen ? null : RosterCalendar::scopeFor((string) $area->getUuidString(), $chosen['uuid']),
            'feed' => $this->calendar,
        ]));
    }

    /**
     * LIVE — what is true this minute, and the roster underneath it.
     *
     * THE POSITIONS ARE THE AREA'S. This module stores none and draws none
     * of its own: the plate and its markers belong to whoever owns the
     * ground, and the roster reads the states over them.
     */
    #[Route('/areas/{uuid}/modules/roster/live', name: self::LIVE_ROUTE, requirements: ['uuid' => Requirement::UUID], methods: ['GET'])]
    #[IsGranted('areas.read', subject: 'area')]
    public function live(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        Request $request,
    ): Response {
        $day = new \DateTimeImmutable('today');
        $now = new \DateTimeImmutable();

        $filter = AgendaFilter::fromQuery(
            $request->query->get('post'),
            $request->query->get('state'),
            $request->query->get('shift'),
            $request->query->get('q'),
        );

        $whole = $this->presence->postsOn($area, $day, $now);
        $narrowed = $this->agenda->narrow($whole, $filter, $now, $this->shifts->windowsFor($area));

        // WHERE EVERYBODY IS, ASKED OF THE AREA FOR ONE STATED MOMENT. The
        // instant is passed rather than taken from a clock inside the
        // seam, so the plate, the rail and the figures on this page are
        // all answering the same minute.
        $live = $this->positions->liveIn((string) $area->getUuidString(), $now);

        // THE RAIL IS A SURFACE, resolved exactly as the Overview's is and
        // out of the same store: which lists it carries, and in what
        // order, is a decision somebody made and the framework remembers.
        $catalog = RosterRailWidgets::declaration();
        $resolved = $this->widgetService->resolve($catalog, $this->viewer(), $area->getUuid());
        $active = $this->widgetService->activeRef($catalog, $this->viewer(), $area->getUuid());

        $stations = $this->liveService->stations($area, $live, $whole);
        $zones = $this->liveService->zones($area, $live);
        $people = RosterLiveService::rail($live, $narrowed, $this->shifts->windowsFor($area), $now);

        $centre = self::centreAsked($request);

        $shown = [];
        foreach ($resolved as $widget) {
            // The framework answers a resolved surface as rows, not as
            // catalogue objects: the layout is what a person arranged, and
            // a Widget is what the module declared.
            if ($widget['on']) {
                $shown[] = (string) $widget['id'];
            }
        }

        $lists = [];
        $counted = 0;
        foreach ($shown as $id) {
            $position = \count($lists) + 1;
            $common = [
                'position' => $position,
                'total' => \count($shown),
                'centre' => $centre,
                // THE RAIL'S OWN CHROME, asked for by the rail. The same
                // partial renders in the widget library without any of it,
                // and is then the list and nothing else.
                'cell' => ' rl-cell',
                'head' => true,
                // AND THE NAME THE ATLAS BRINGS BACK. A row asks the plate
                // to swap itself and to carry this one region across with
                // it, so the row that was clicked returns marked. Only the
                // rail names it: the library draws this same list three
                // times from one render, and three of one id is not a page.
                'listId' => self::RAIL_LIST_ID.$id,
            ];
            $context = match ($id) {
                'stations' => ['stations' => $stations, ...$common],
                'zones' => ['zones' => $zones, ...$common],
                default => ['rail' => $people, 'people' => self::railPeople($people), ...$common],
            };

            $counted += match ($id) {
                'stations' => $stations->count(),
                'zones' => $zones->count(),
                default => self::railPeople($people),
            };

            $lists[] = ['id' => $id, 'context' => $context];
        }

        // EVERY LIST THE SURFACE DECLARES, so the foot can offer the ones
        // that are out. The button for a list already in the rail is drawn
        // and hidden by the sheet, which is the design's own arrangement:
        // one door per list, and only the absent ones read as doors.
        $railAll = [];
        foreach ($resolved as $widget) {
            $id = (string) $widget['id'];
            $railAll[] = ['id' => $id, 'label' => RosterRailWidgets::NAMES[$id] ?? (string) $widget['label'], 'on' => (bool) $widget['on']];
        }

        // THE MARKS KEEP MOVING. The area's own stream and cookie, under
        // the area's own pair; with no hub the plate draws once.
        $plate = $this->liveService->plate($area, $live, $this->liveService->figures($live, $whole)->withoutAFix, $whole, $centre);
        $subscription = $this->streams?->forArea($request, $area);
        if (null !== $subscription) {
            $plate->liveStream($subscription->stream);
        }

        $response = new Response($this->twig->render('@UhifadhiRoster/live/show.html.twig', [
            'area' => $area,
            'band' => $this->identity->bandFor($area),
            'day' => $day,
            'now' => $now,
            'filter' => $filter,
            'posts' => $narrowed,
            // THE PEOPLE THE READ HAD NO FIX FOR are the plate's business
            // too: they are on no layer, and a plate silent about them
            // would be a plate claiming the park is fully seen.
            'plate' => $plate,
            'rail' => $people,
            'railLists' => $lists,
            'railCount' => \sprintf('%d in %d list%s', $counted, \count($lists), 1 === \count($lists) ? '' : 's'),
            'centre' => $centre,
            'railAll' => $railAll,
            'railPresets' => $catalog->presets(),
            'railPreset' => $active['id'],
            // WHO CHOSE THE ARRANGEMENT AND WHEN. A rail somebody else set
            // up is a rail whose shape needs explaining, and the foot is
            // where it explains itself.
            'railDefault' => $this->railDefault($catalog, $area),
            'live' => $this->liveService->figures($live, $whole),
            'stationsUrl' => $this->stationsUrl($area),
            'chosenPost' => $this->chosenPost($whole, $filter),
            'stateLabels' => self::stateLabels(),
            'stateCounts' => self::stateCounts($whole),
            'shiftLabels' => $this->shiftLabels($area),
            'shiftCounts' => self::shiftCounts($whole),
        ]));
        if (null !== $subscription) {
            $response->headers->setCookie($subscription->cookie);
        }

        return $response;
    }

    /**
     * THE RANGER THE CALENDAR IS ABOUT, with the two facts the caption
     * carries beside their name.
     *
     * NEITHER IS THIS MODULE'S. The role is the position TEAM holds for
     * them and the post is where the AREA posts them; a month that invented
     * either would be a roster claiming to know the org chart. Where a fact
     * is unanswered the caption simply leaves it out.
     *
     * THE POST IS THE ONE THIS MONTH'S WATCHES ARE AT, not a posting: a
     * ranger posted at three gates who stood every watch at one of them is
     * described by the one, and that is what the reader of a month wants.
     *
     * @param array{uuid: string, name: string} $chosen
     *
     * @return array{uuid: string, name: string, role: string|null, post: string|null, postUrl: string|null}
     */
    private function describe(AreaOfInterest $area, array $chosen, YearMonth $month): array
    {
        $counts = [];
        $stations = [];
        foreach ($this->duties->findStandingForPersonBetween($area, $chosen['uuid'], $month->firstDay(), $month->lastDay()) as $duty) {
            $station = $duty->getStation();
            $key = (string) $station->getUuidString();
            $counts[$key] = ($counts[$key] ?? 0) + 1;
            $stations[$key] = $station;
        }

        arsort($counts);
        $mostWatched = array_key_first($counts);
        $station = null === $mostWatched ? null : $stations[$mostWatched];

        return [
            'uuid' => $chosen['uuid'],
            'name' => $chosen['name'],
            'role' => $this->people->roleOf($area, $chosen['uuid']),
            'post' => $station?->getName(),
            'postUrl' => null === $station ? null : $this->stationUrl($station),
        ];
    }

    /**
     * HOW MANY PEOPLE THE RAIL'S PEOPLE LIST HOLDS, across its groups.
     *
     * @param list<\Uhifadhi\Roster\Model\LiveRailGroup> $groups
     */
    private static function railPeople(array $groups): int
    {
        $total = 0;
        foreach ($groups as $group) {
            $total += $group->count();
        }

        return $total;
    }

    /**
     * WHAT THE RAIL'S FOOT SAYS: the arrangement in force, who put it
     * there and when — or that nobody has, and it is the one the module
     * ships with.
     */
    private function railDefault(\Uhifadhi\Bundle\ShellBundle\Widget\Model\WidgetCatalog $catalog, AreaOfInterest $area): string
    {
        $active = $this->widgetService->activeRef($catalog, $this->viewer(), $area->getUuid());
        $chosen = $catalog->preset($active['id']);

        return null === $chosen || $catalog->defaultPresetId() === $active['id']
            ? \sprintf('%s · the module · as shipped', $catalog->builtins()[0]->label ?? RosterRailWidgets::DEFAULT_LABEL)
            : \sprintf('%s · %s · chosen here', $chosen->label, $this->viewer()?->getFullName() ?? 'this installation');
    }

    /**
     * ADOPT ONE OF THE RAIL'S ARRANGEMENTS. It is a write, so it is a POST
     * and it is attributed — the same endpoint the Overview's presets go
     * through, over this surface's own catalogue.
     */
    #[Route('/areas/{uuid}/modules/roster/live/rail/{presetId}', name: self::RAIL_PRESET_ROUTE, requirements: ['uuid' => Requirement::UUID, 'presetId' => '[a-z0-9_-]+'], methods: ['GET', 'POST'])]
    #[IsGranted('areas.read', subject: 'area')]
    public function adoptRailPreset(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        Request $request,
        string $presetId,
    ): Response {
        $viewer = $this->viewer();

        if (null !== $viewer) {
            $this->widgetService->applyPreset(RosterRailWidgets::declaration(), $viewer, $area->getUuid(), $presetId);
        }

        return new RedirectResponse($this->router->generate(self::LIVE_ROUTE, ['uuid' => (string) $area->getUuidString()]));
    }

    /**
     * COMPOSE THE RAIL: move a list, take one out, put one back.
     *
     * THIS IS THE SURFACE'S OWN EDITING and it is persisted exactly as the
     * preset choice is — the same store, the same rules. What it is NOT is
     * a second way of writing a shipped design: the framework refuses that
     * outright, and rightly, so the first edit to an arrangement the module
     * ships COPIES it and edits the copy. The copy keeps the design's name,
     * because "The duty officer, mine" is what it is.
     */
    #[Route('/areas/{uuid}/modules/roster/live/rail/{op}/{list}', name: self::RAIL_EDIT_ROUTE, requirements: ['uuid' => Requirement::UUID, 'op' => 'up|down|remove|add', 'list' => '[a-z]+'], methods: ['POST'])]
    #[IsGranted('roster.record', subject: 'area')]
    public function composeRail(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        Request $request,
        string $op,
        string $list,
    ): Response {
        $this->guardTheForm($request);

        $viewer = $this->viewer();
        $catalog = RosterRailWidgets::declaration();

        if (null !== $viewer && $catalog->has($list)) {
            $areaUuid = $area->getUuid();

            // A SHIPPED DESIGN IS NOT EDITED IN PLACE. Copying first is the
            // framework's rule and the library says so in as many words;
            // doing it here rather than refusing means the control the
            // design draws actually works on the first click.
            if ('mine' !== $this->widgetService->activeRef($catalog, $viewer, $areaUuid)['kind']) {
                $this->widgetService->copyBuiltinPreset(
                    $catalog,
                    $viewer,
                    $areaUuid,
                    $this->widgetService->activeRef($catalog, $viewer, $areaUuid)['id'],
                );
            }

            $this->widgetService->save(
                $catalog,
                $viewer,
                self::railPayload($this->widgetService->resolve($catalog, $viewer, $areaUuid), $op, $list),
                $areaUuid,
            );
        }

        return new RedirectResponse($this->router->generate(self::LIVE_ROUTE, ['uuid' => (string) $area->getUuidString()]));
    }

    /**
     * THE RAIL AFTER ONE EDIT, as the framework's own payload.
     *
     * MOVING IS AMONG THE LISTS THAT ARE ON. A list moved "up" past one
     * that is switched off would appear not to move at all, which reads as
     * a broken button rather than as a no-op.
     *
     * @param list<array{id: string, label: string, group: string, on: bool, cols: int, spans: list<int>}> $resolved
     *
     * @return array{order: list<string>, widgets: array<string, array{on: bool, cols: int}>}
     */
    private static function railPayload(array $resolved, string $op, string $list): array
    {
        $order = [];
        $widgets = [];
        foreach ($resolved as $widget) {
            $order[] = $widget['id'];
            $widgets[$widget['id']] = ['on' => $widget['on'], 'cols' => $widget['cols']];
        }

        if ('remove' === $op || 'add' === $op) {
            if (isset($widgets[$list])) {
                $widgets[$list] = ['on' => 'add' === $op, 'cols' => $widgets[$list]['cols']];
            }

            return ['order' => $order, 'widgets' => $widgets];
        }

        $shown = array_values(array_filter($order, static fn (string $id): bool => $widgets[$id]['on']));
        $at = array_search($list, $shown, true);

        if (false === $at) {
            return ['order' => $order, 'widgets' => $widgets];
        }

        $to = $at + ('up' === $op ? -1 : 1);

        if (!isset($shown[$to])) {
            return ['order' => $order, 'widgets' => $widgets];
        }

        [$shown[$at], $shown[$to]] = [$shown[$to], $shown[$at]];

        // The off lists keep their places behind the on ones, so switching
        // one back on puts it where it was rather than at the front.
        $off = array_values(array_filter($order, static fn (string $id): bool => !$widgets[$id]['on']));

        return ['order' => [...$shown, ...$off], 'widgets' => $widgets];
    }

    /** The area's stations page, or null where this installation omits it. */
    private function stationsUrl(AreaOfInterest $area): ?string
    {
        try {
            return $this->router->generate(StationsController::ROUTE, ['uuid' => (string) $area->getUuidString()]);
        } catch (RouteNotFoundException|InvalidParameterException|MissingMandatoryParametersException) {
            return null;
        }
    }

    /**
     * THE AREA'S OWN PAGE FOR A POST, or null where this installation does
     * not run the screen. A door to a route that is not registered is a
     * 500 on a page that was only trying to be helpful.
     */
    private function stationUrl(Station $station): ?string
    {
        try {
            return $this->router->generate(StationsController::ROUTE, ['uuid' => (string) $station->getArea()?->getUuidString()]);
        } catch (RouteNotFoundException|InvalidParameterException|MissingMandatoryParametersException) {
            return null;
        }
    }

    /**
     * THE POSTS THAT ARE NOT TALKING TO US. Late is a warning and not a
     * fault — it clears itself the moment something lands.
     *
     * @param list<PostPresence> $posts
     *
     * @return list<PostPresence>
     */
    private static function quiet(array $posts): array
    {
        return array_values(array_filter(
            $posts,
            static fn (PostPresence $post): bool => PostState::Late === $post->state || PostState::Offline === $post->state,
        ));
    }

    /**
     * THE POST THE FILTER NAMES, so the chip can wear its name rather than
     * a uuid. A uuid nothing matches reads as "all posts", which is what the
     * page is in fact showing.
     *
     * @param list<PostPresence> $posts
     */
    private function chosenPost(array $posts, AgendaFilter $filter): ?PostPresence
    {
        foreach ($posts as $post) {
            if ($post->stationUuid === $filter->post) {
                return $post;
            }
        }

        return null;
    }

    /**
     * THE STATES THE FILTER OFFERS. The area's own day states, plus the one
     * non-state the design names: DUE, a watch that has not begun. A ranger
     * who has not checked in at 11:42 for an 18:00 watch has failed at
     * nothing, and offering them under "no check-in" would say they had.
     *
     * @return array<string, string>
     */
    private static function stateLabels(): array
    {
        $labels = [];
        foreach (DayState::cases() as $state) {
            $labels[$state->value] = $state->label();
        }

        $labels[AgendaService::DUE] = 'Due later today';

        return $labels;
    }

    /**
     * HOW MANY PEOPLE ARE IN EACH STATE, counted from the same reading the
     * rows came from — never a second query, which is how an option and a
     * list come to disagree.
     *
     * @param list<PostPresence> $posts
     *
     * @return array<string, int>
     */
    private static function stateCounts(array $posts): array
    {
        $counts = ['all' => 0];
        foreach ($posts as $post) {
            foreach ($post->rostered as $person) {
                ++$counts['all'];
                $key = $person->state()->value;
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * @param list<PostPresence> $posts
     *
     * @return array<string, int>
     */
    private static function shiftCounts(array $posts): array
    {
        $counts = [];
        foreach ($posts as $post) {
            foreach ($post->rostered as $person) {
                $counts[$person->shiftKey] = ($counts[$person->shiftKey] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * THE AREA'S OWN SHIFT VOCABULARY, which is the only list a shift filter
     * may offer: a hard-coded day/night pair would be wrong in any park that
     * named its shifts differently, and every one of them does.
     *
     * @return array<string, string>
     */
    private function shiftLabels(AreaOfInterest $area): array
    {
        $labels = [];
        foreach ($this->shifts->findByArea($area) as $shift) {
            if ($shift->isOpen()) {
                $labels[$shift->getKey()] = $shift->getLabel().' '.$shift->getStartsAt().'–'.$shift->getEndsAt();
            }
        }

        return $labels;
    }

    /**
     * PLAN THE DAY — the generated plan as slots to fill.
     *
     * IT OPENS ON TOMORROW, because that is the day a duty officer plans:
     * today is already being worked and the agenda is the screen for it.
     * `?day=` moves it.
     *
     * NOTHING HERE MARKS ANYBODY PRESENT. Filling a slot writes a DUTY,
     * and presence is derived later from what the handsets report against
     * these rows — the line this screen must not cross.
     */
    #[Route('/areas/{uuid}/modules/roster/plan', name: self::PLAN_ROUTE, requirements: ['uuid' => Requirement::UUID], methods: ['GET'])]
    #[IsGranted('areas.read', subject: 'area')]
    public function plan(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        Request $request,
    ): Response {
        $day = self::dayAsked($request) ?? new \DateTimeImmutable('tomorrow');
        $sheet = $this->plans->sheetFor($area, $day);
        $windows = $this->shifts->windowsFor($area);

        // THE TWO CARDS THE DESIGN DRAWS: the watches that run in daylight,
        // then the ones that cross midnight — the order the day happens in.
        $daylight = [];
        $nights = [];
        foreach ($sheet as $slot) {
            // Every slot on the sheet names a shift the area still has a
            // window for — the sheet drops the ones it does not — so this
            // lookup always answers.
            if ($windows[$slot->shiftKey]->crossesMidnight()) {
                $nights[] = $slot;
            } else {
                $daylight[] = $slot;
            }
        }

        return new Response($this->twig->render('@UhifadhiRoster/plan/show.html.twig', [
            'area' => $area,
            'band' => $this->identity->bandFor($area),
            'day' => $day,
            'daylight' => $daylight,
            'nights' => $nights,
            'free' => DayPlanService::whoIsFree($sheet),
            'away' => $this->absences->findOverlapping($area, $day, $day->modify(\sprintf('+%d days', RotaService::DAYS - 1))),
            'csrfToken' => $this->csrfTokenManager?->getToken(self::CSRF_TOKEN_ID)->getValue() ?? '',
        ]));
    }

    /**
     * PUBLISH THE SHEET. The picks become duties; anything the sheet no
     * longer holds is taken off it.
     *
     * THE RULES ARE ASKED AGAIN HERE. A blocked pill is disabled in the
     * markup and markup is a suggestion — a form can be posted by
     * anything — so a pick that breaks a rule is refused with a sentence
     * rather than written.
     */
    #[Route('/areas/{uuid}/modules/roster/plan/publish', name: self::PUBLISH_ROUTE, requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted('roster.record', subject: 'area')]
    public function publishThePlan(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        Request $request,
    ): Response {
        $this->guardTheForm($request);

        $day = self::dayAsked($request, $request->request->get('day')) ?? new \DateTimeImmutable('tomorrow');

        $picks = [];
        foreach ($request->request->all() as $field => $value) {
            if (str_starts_with($field, self::SLOT_FIELD) && \is_array($value)) {
                $picks[self::slotKeyOf($field)] = array_values(array_filter($value, \is_string(...)));
            }
        }

        $refused = $this->plans->publish($area, $day, $picks);

        foreach ($refused as $sentence) {
            $this->flash($request, 'error', $sentence);
        }

        if ([] === $refused) {
            $this->flash($request, 'success', \sprintf('The day is published — %s is the plan the handsets will report against.', $day->format('D j M')));
        }

        return new RedirectResponse($this->router->generate(self::PLAN_ROUTE, [
            'uuid' => (string) $area->getUuidString(),
            'day' => $day->format('Y-m-d'),
        ]));
    }

    /**
     * THE FIELD A SLOT'S PILLS POST UNDER. One field per slot, carrying the
     * post and the shift, so the server reads a sheet rather than a flat
     * list of people it would have to guess the slots of.
     */
    public const string SLOT_FIELD = 'slot_';

    /** The "<station uuid>|<shift key>" a field name carries. */
    private static function slotKeyOf(string $field): string
    {
        return str_replace('__', '|', substr($field, \strlen(self::SLOT_FIELD)));
    }

    /** The field name a slot's pills are posted under. */
    public static function slotField(string $stationUuid, string $shiftKey): string
    {
        return self::SLOT_FIELD.$stationUuid.'__'.$shiftKey;
    }

    /** What a rail row asked the plate to centre on, if anything readable. */
    private static function centreAsked(Request $request): ?string
    {
        $centre = $request->query->get('centre');

        return \is_string($centre) && '' !== $centre ? $centre : null;
    }

    /** A day from the url or the form, or null where neither reads. */
    private static function dayAsked(Request $request, mixed $posted = null): ?\DateTimeImmutable
    {
        $raw = $posted ?? $request->query->get('day');
        if (!\is_string($raw)) {
            return null;
        }

        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);

        return false === $parsed ? null : $parsed;
    }

    /**
     * THE WEEK THE URL ASKED FOR, or null where it asked for nothing
     * readable. Untrusted like every query field.
     */
    private function askedFor(Request $request): ?\DateTimeImmutable
    {
        $from = $request->query->get('from');
        if (!\is_string($from)) {
            return null;
        }

        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $from);

        return false === $parsed ? null : $parsed;
    }
}
