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
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;
use Twig\Environment;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\ShellBundle\Widget\Model\WidgetCatalog;
use Uhifadhi\Bundle\ShellBundle\Widget\Service\WidgetEndpoint;
use Uhifadhi\Bundle\ShellBundle\Widget\Service\WidgetService;
use Uhifadhi\Contracts\Area\LivePositionsInterface;
use Uhifadhi\Contracts\Shell\Scope;
use Uhifadhi\Roster\Model\LiveRailGroup;
use Uhifadhi\Roster\Module\RosterModuleProvider;
use Uhifadhi\Roster\Repository\ShiftRepository;
use Uhifadhi\Roster\Service\PresenceReader;
use Uhifadhi\Roster\Service\RosterDashboardService;
use Uhifadhi\Roster\Service\RosterIdentityService;
use Uhifadhi\Roster\Service\RosterLiveService;
use Uhifadhi\Roster\Service\RosterOrgService;
use Uhifadhi\Roster\Service\RosterWidgetUrls;
use Uhifadhi\Roster\Widget\RosterOrgWidgets;
use Uhifadhi\Roster\Widget\RosterRailWidgets;
use Uhifadhi\Roster\Widget\RosterWidgets;

/**
 * THE ROSTER'S WIDGET LIBRARY — the first tab of the module's configure page.
 *
 * IT IS NOT A DOOR ON THE DASHBOARD. Ruled: editing never happens on the
 * surface being edited, and the library is the first section of Configure on
 * every module in the platform. So this screen wears the configure page's
 * heading and its section strip, exactly as Rotation, Watches and Settings
 * do, and the dashboard carries no "customize" control at all.
 *
 * THE MECHANICS ARE THE SHELL'S, WHOLE. The page is chrome; everything under
 * it is `@Shell/widget/_library.html.twig` parameterised by this surface's
 * catalogue, its partial name and its routes, and every write goes through
 * {@see WidgetEndpoint}. There is no roster-specific widget machinery
 * anywhere, which is the only way a widget can be guaranteed to behave the
 * same here as on every other surface.
 *
 * EVERY PREVIEW IS THE REAL WIDGET ON REAL DATA, built from the SAME context
 * the dashboard hands its partials. A picture of a widget that came from
 * somewhere else is a picture that eventually stops matching what gets added.
 *
 * REGISTERED ONLY WHERE SECURITYBUNDLE IS. Arranging a dashboard is a write
 * attributed to a person; without a firewall there is nobody to attribute it
 * to, so the screen does not exist rather than existing unattributed.
 */
#[Route(defaults: ['_uhifadhi_module' => RosterModuleProvider::SLUG])]
final class RosterWidgetsController
{
    public const string LIBRARY_ROUTE = 'roster_widgets';
    public const string SAVE_ROUTE = 'roster_widgets_save';
    public const string RESET_ROUTE = 'roster_widgets_reset';
    public const string PRESET_ROUTE = 'roster_widgets_preset';
    public const string PRESET_COPY_ROUTE = 'roster_widgets_preset_copy';
    public const string PRESET_CREATE_ROUTE = 'roster_widgets_preset_create';
    public const string PRESET_APPLY_ROUTE = 'roster_widgets_preset_apply';
    public const string PRESET_RENAME_ROUTE = 'roster_widgets_preset_rename';
    public const string PRESET_DELETE_ROUTE = 'roster_widgets_preset_delete';

    /** Where a widget's own partial lives, as the library's sprintf format. */
    public const string PARTIAL = '@UhifadhiRoster/dashboard/_w_%s.html.twig';

    /** And the rail's, whose widgets ARE the lists the Live tab draws. */
    public const string RAIL_PARTIAL = '@UhifadhiRoster/rail/_%s.html.twig';

    /** The id of the rail's section, which the Live tab's door names. */
    public const string RAIL_ANCHOR = 'rail';

    /** And the organization section's, for a door from that scope. */
    public const string ORG_ANCHOR = 'org';

    /**
     * THE TWO COMPOSITIONS THIS MODULE HAS, as a route requirement.
     *
     * EVERY WRITE NAMES ITS SURFACE IN THE URL. One library page configures
     * both, so a save that did not say which one it was saving would be a
     * save the server had to guess at — and the guess would be wrong exactly
     * half the time.
     */
    public const string SURFACES = RosterWidgets::SURFACE.'|'.RosterRailWidgets::SURFACE.'|'.RosterOrgWidgets::SURFACE;

    public function __construct(
        private readonly Environment $twig,
        private readonly WidgetService $widgets,
        private readonly WidgetEndpoint $endpoint,
        private readonly RosterWidgetUrls $urls,
        private readonly RosterDashboardService $dashboard,
        private readonly RosterIdentityService $identity,
        private readonly UrlGeneratorInterface $router,
        // THE RAIL'S OWN READS, so its library twins are the real lists on
        // this minute's data rather than a drawing of them.
        private readonly RosterLiveService $liveService,
        private readonly PresenceReader $presence,
        private readonly LivePositionsInterface $positions,
        private readonly ShiftRepository $shifts,
        private readonly RosterOrgService $org,
    ) {
    }

    #[Route(
        '/areas/{uuid}/modules/roster/widgets',
        name: self::LIBRARY_ROUTE,
        requirements: ['uuid' => Requirement::UUID],
        methods: ['GET'],
        priority: 2,
    )]
    public function library(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
    ): Response {
        $catalog = RosterWidgets::declaration();
        $viewer = $this->endpoint->user();
        $areaUuid = $area->getUuid();
        $now = new \DateTimeImmutable();

        // ONE LIBRARY PAGE, A SECTION PER SURFACE. The module has two
        // compositions — the Overview's page of cards and the Live tab's
        // column of lists — and they share the mechanism and nothing else.
        // Two library pages would be two answers to "where do I change my
        // widgets", so there is one, and each section carries its own
        // catalogue, presets, routes and token.
        $rail = RosterRailWidgets::declaration();
        $org = RosterOrgWidgets::declaration();

        return new Response($this->twig->render('@UhifadhiRoster/widgets/library.html.twig', [
            'area' => $area,
            // The configure frame draws the identity band, so every screen
            // that wears the frame owes it the same figures.
            'band' => $this->identity->bandFor($area),
            'liveUrl' => $this->router->generate(RosterController::LIVE_ROUTE, ['uuid' => (string) $area->getUuidString()]),
            'surfaces' => [
                [
                    'label' => 'The module dashboard',
                    'intro' => 'What the roster opens on. The directions below are presets, not pages: the roster was drawn five ways — as a day board, station first, a week planner, the people, and right now — and none became a screen of its own.',
                    'catalog' => $catalog,
                    'builtins' => $catalog->builtins(),
                    'customPresets' => $this->widgets->customPresets($catalog, $viewer, $areaUuid),
                    'active' => $this->widgets->activeRef($catalog, $viewer, $areaUuid),
                    'widgets' => $this->widgets->resolve($catalog, $viewer, $areaUuid),
                    'partial' => self::PARTIAL,
                    // THE SAME CONTEXT THE DASHBOARD USES. The preview is
                    // the widget.
                    'widgetContext' => [
                        'area' => $area,
                        'dash' => $this->dashboard->build($area, new \DateTimeImmutable('today'), $now),
                        'rosterDecisionLimit' => RosterController::DECISIONS_SHOWN,
                        'rosterStationLimit' => RosterController::STATIONS_SHOWN,
                    ],
                    'urls' => $this->urls->forArea($area),
                    'csrfToken' => $this->endpoint->csrfToken($catalog, $areaUuid),
                ],
                [
                    // AND THE THIRD: the module read across every area. It
                    // is composed here beside the other two because this is
                    // the module's library, and a person arranging one will
                    // want the others.
                    'anchor' => self::ORG_ANCHOR,
                    'label' => 'The organization roster',
                    'intro' => 'The same module one scope wider: every area at once. The figures under these widgets are the area page’s own, with the area filter widened — never a second aggregate.',
                    'catalog' => $org,
                    'builtins' => $org->builtins(),
                    'customPresets' => $this->widgets->customPresets($org, $viewer, $areaUuid),
                    'active' => $this->widgets->activeRef($org, $viewer, $areaUuid),
                    'widgets' => $this->widgets->resolve($org, $viewer, $areaUuid),
                    'partial' => RosterOrgController::PARTIAL,
                    'widgetContext' => $this->orgPreview($now),
                    'urls' => $this->urls->forArea($area, RosterOrgWidgets::SURFACE),
                    'csrfToken' => $this->endpoint->csrfToken($org, $areaUuid),
                ],
                [
                    // THE DOOR ON THE LIVE TAB LANDS HERE, not at the top of
                    // the page: a module with two surfaces has two doors into
                    // one library, and a door that always lands at the top
                    // makes the reader hunt for what it just opened.
                    'anchor' => self::RAIL_ANCHOR,
                    'label' => 'The Live tab’s plate rail',
                    'intro' => 'The column beside the Live tab’s plate is a widget surface of its own, and the lists it carries are widgets. The rail is one column wide, so every widget in it is full width.',
                    'catalog' => $rail,
                    'builtins' => $rail->builtins(),
                    'customPresets' => $this->widgets->customPresets($rail, $viewer, $areaUuid),
                    'active' => $this->widgets->activeRef($rail, $viewer, $areaUuid),
                    'widgets' => $this->widgets->resolve($rail, $viewer, $areaUuid),
                    'partial' => self::RAIL_PARTIAL,
                    // AND THE RAIL'S PREVIEWS ARE THE RAIL'S OWN LISTS, on
                    // this minute's reading: a twin of the Live tab, not a
                    // drawing of one. The composing controls are off here —
                    // a preview of a list is not a place to reorder it.
                    'widgetContext' => $this->railPreview($area, $now),
                    'urls' => $this->urls->forArea($area, RosterRailWidgets::SURFACE),
                    'csrfToken' => $this->endpoint->csrfToken($rail, $areaUuid),
                ],
            ],
        ]));
    }

    /**
     * WHAT THE RAIL'S THREE LISTS NEED TO DRAW THEMSELVES, once.
     *
     * EVERY PREVIEW IS THE REAL LIST ON REAL DATA — the same partials the
     * Live tab includes, reading the same services — so a twin here cannot
     * fall out of step with the column it stands for.
     *
     * @return array<string, mixed>
     */
    /**
     * WHAT THE ORGANIZATION WIDGETS NEED TO DRAW THEMSELVES.
     *
     * THE PREVIEW IS THE WIDGET, so these are the real partials on the
     * real reading — read across every area this installation has, which
     * is what the surface is for. The library is not the page, so it draws
     * no plate: a second live map inside a picker is a second live map.
     *
     * @return array<string, mixed>
     */
    private function orgPreview(\DateTimeImmutable $now): array
    {
        $day = $now->setTime(0, 0);
        $areas = $this->org->areasIn(Scope::organization(), []);

        return [
            'areas' => $areas,
            'figures' => $this->org->figuresFor($areas, $day, $now),
            'bands' => $this->org->bands($areas, $day, $now),
            'decisions' => $this->org->decisions($areas, $day, $now),
            'plate' => null,
        ];
    }

    /**
     * WHAT THE RAIL'S THREE LISTS NEED TO DRAW THEMSELVES, once.
     *
     * EVERY PREVIEW IS THE REAL LIST ON REAL DATA — the same partials the
     * Live tab includes, reading the same services — so a twin here cannot
     * fall out of step with the column it stands for.
     *
     * @return array<string, mixed>
     */
    private function railPreview(AreaOfInterest $area, \DateTimeImmutable $now): array
    {
        $day = new \DateTimeImmutable('today');
        $live = $this->positions->liveIn((string) $area->getUuidString(), $now);
        $posts = $this->presence->postsOn($area, $day, $now);

        return [
            'stations' => $this->liveService->stations($area, $live, $posts),
            'zones' => $this->liveService->zones($area, $live),
            'rail' => $rail = RosterLiveService::rail($live, $posts, $this->shifts->windowsFor($area), $now),
            'people' => array_sum(array_map(static fn (LiveRailGroup $group): int => $group->count(), $rail)),
            // A LIBRARY IS NOT THE SURFACE. The order marks and the way out
            // of the rail belong to the rail itself; here they would be
            // controls on a picture.
            'position' => 1,
            'total' => 1,
            'mayCompose' => false,
            'centre' => null,
        ];
    }

    #[Route('/areas/{uuid}/modules/roster/widgets/{surface}/save', name: self::SAVE_ROUTE, requirements: ['uuid' => Requirement::UUID, 'surface' => self::SURFACES], methods: ['POST'], priority: 2)]
    public function save(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        string $surface,
    ): Response {
        return $this->endpoint->save($request, self::catalogOf($surface), $area->getUuid());
    }

    #[Route('/areas/{uuid}/modules/roster/widgets/{surface}/reset', name: self::RESET_ROUTE, requirements: ['uuid' => Requirement::UUID, 'surface' => self::SURFACES], methods: ['POST'], priority: 2)]
    public function reset(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        string $surface,
    ): Response {
        return $this->afterWrite(
            $request,
            $area,
            $this->endpoint->reset($request, self::catalogOf($surface), $area->getUuid()),
            \sprintf(
                'This area’s %s is back to “%s”.',
                self::nameOf($surface),
                RosterRailWidgets::SURFACE === $surface ? RosterRailWidgets::DEFAULT_LABEL : RosterWidgets::DEFAULT_LABEL,
            ),
        );
    }

    #[Route('/areas/{uuid}/modules/roster/widgets/{surface}/preset/{presetId}', name: self::PRESET_ROUTE, requirements: ['uuid' => Requirement::UUID, 'surface' => self::SURFACES, 'presetId' => '[a-z0-9_-]+'], methods: ['POST'], priority: 2)]
    public function applyPreset(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        string $surface,
        string $presetId,
    ): Response {
        $catalog = self::catalogOf($surface);
        // A design the surface does not ship is refused by the endpoint;
        // naming it here is only for the case where it IS shipped.
        $adopted = $catalog->preset($presetId);

        return $this->afterWrite(
            $request,
            $area,
            $this->endpoint->applyPreset($request, $catalog, $presetId, $area->getUuid()),
            \sprintf('This area’s %s now follows “%s”.', self::nameOf($surface), null !== $adopted ? $adopted->label : $presetId),
        );
    }

    #[Route('/areas/{uuid}/modules/roster/widgets/{surface}/preset/{presetId}/copy', name: self::PRESET_COPY_ROUTE, requirements: ['uuid' => Requirement::UUID, 'surface' => self::SURFACES, 'presetId' => '[a-z0-9_-]+'], methods: ['POST'], priority: 3)]
    public function copyPreset(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        string $surface,
        string $presetId,
    ): Response {
        return $this->afterWrite(
            $request,
            $area,
            $this->endpoint->copyPreset($request, self::catalogOf($surface), $presetId, $area->getUuid()),
            'Copied — the copy is yours to edit, and the design it came from is untouched.',
        );
    }

    #[Route('/areas/{uuid}/modules/roster/widgets/{surface}/presets', name: self::PRESET_CREATE_ROUTE, requirements: ['uuid' => Requirement::UUID, 'surface' => self::SURFACES], methods: ['POST'], priority: 2)]
    public function createPreset(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        string $surface,
    ): Response {
        return $this->afterWrite(
            $request,
            $area,
            $this->endpoint->createCustomPreset($request, self::catalogOf($surface), $area->getUuid()),
            'Saved — this arrangement is now one of your own designs.',
        );
    }

    #[Route('/areas/{uuid}/modules/roster/widgets/{surface}/presets/{presetUuid}/apply', name: self::PRESET_APPLY_ROUTE, requirements: ['uuid' => Requirement::UUID, 'surface' => self::SURFACES, 'presetUuid' => Requirement::UUID], methods: ['POST'], priority: 2)]
    public function applyCustomPreset(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        string $surface,
        string $presetUuid,
    ): Response {
        return $this->afterWrite(
            $request,
            $area,
            $this->endpoint->applyCustomPreset($request, self::catalogOf($surface), Uuid::fromString($presetUuid), $area->getUuid()),
            'Your own design is now this area’s roster dashboard.',
        );
    }

    #[Route('/areas/{uuid}/modules/roster/widgets/{surface}/presets/{presetUuid}/rename', name: self::PRESET_RENAME_ROUTE, requirements: ['uuid' => Requirement::UUID, 'surface' => self::SURFACES, 'presetUuid' => Requirement::UUID], methods: ['POST'], priority: 2)]
    public function renameCustomPreset(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        string $surface,
        string $presetUuid,
    ): Response {
        return $this->afterWrite(
            $request,
            $area,
            $this->endpoint->renameCustomPreset($request, self::catalogOf($surface), Uuid::fromString($presetUuid), $area->getUuid()),
            'Renamed.',
        );
    }

    #[Route('/areas/{uuid}/modules/roster/widgets/{surface}/presets/{presetUuid}/delete', name: self::PRESET_DELETE_ROUTE, requirements: ['uuid' => Requirement::UUID, 'surface' => self::SURFACES, 'presetUuid' => Requirement::UUID], methods: ['POST'], priority: 2)]
    public function deleteCustomPreset(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        string $surface,
        string $presetUuid,
    ): Response {
        return $this->afterWrite(
            $request,
            $area,
            $this->endpoint->deleteCustomPreset($request, self::catalogOf($surface), Uuid::fromString($presetUuid), $area->getUuid()),
            'Design deleted. Your dashboard is back on the one this module ships with.',
        );
    }

    /**
     * WHICH COMPOSITION A WRITE IS ABOUT. Both are this module's and both
     * are configured on one page, so the URL says which and nothing has to
     * infer it from the payload.
     */
    private static function catalogOf(string $surface): WidgetCatalog
    {
        return match ($surface) {
            RosterRailWidgets::SURFACE => RosterRailWidgets::declaration(),
            RosterOrgWidgets::SURFACE => RosterOrgWidgets::declaration(),
            default => RosterWidgets::declaration(),
        };
    }

    /** What to call a surface in a sentence somebody reads after a write. */
    private static function nameOf(string $surface): string
    {
        return match ($surface) {
            RosterRailWidgets::SURFACE => 'Live plate rail',
            RosterOrgWidgets::SURFACE => 'organization roster',
            default => 'roster dashboard',
        };
    }

    /**
     * A refused write is returned as it came (the library's fetch reads the
     * status and the message); a successful one says so and goes back to the
     * library, so the plain-form path works with no JavaScript at all.
     */
    private function afterWrite(Request $request, AreaOfInterest $area, Response $response, string $flash): Response
    {
        if (Response::HTTP_NO_CONTENT !== $response->getStatusCode()) {
            return $response;
        }

        $session = $request->hasSession() ? $request->getSession() : null;
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('success', $flash);
        }

        return new RedirectResponse($this->router->generate(self::LIBRARY_ROUTE, ['uuid' => (string) $area->getUuidString()]));
    }
}
