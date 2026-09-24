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

namespace Uhifadhi\Roster;

use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Uhifadhi\Bundle\AreaBundle\Overview\OrgOverviewContributorInterface;
use Uhifadhi\Bundle\AreaBundle\Repository\AreaOfInterestRepository;
use Uhifadhi\Bundle\AreaBundle\Repository\PostingRepository;
use Uhifadhi\Bundle\AreaBundle\Repository\StationRepository;
use Uhifadhi\Bundle\AreaBundle\Repository\ZoneRepository;
use Uhifadhi\Bundle\AreaBundle\Service\AreaPlateService;
use Uhifadhi\Bundle\AreaBundle\Service\CheckInService;
use Uhifadhi\Bundle\AreaBundle\Service\CheckInStatusService;
use Uhifadhi\Bundle\AreaBundle\Service\PostingService;
use Uhifadhi\Bundle\AreaBundle\Service\ZoneSetService;
use Uhifadhi\Bundle\ShellBundle\Widget\Registry\WidgetSurfaceInterface;
use Uhifadhi\Bundle\ShellBundle\Widget\Service\WidgetEndpoint;
use Uhifadhi\Bundle\ShellBundle\Widget\Service\WidgetService;
use Uhifadhi\Bundle\TeamBundle\Repository\DepartmentRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\UserRepository;
use Uhifadhi\Contracts\Area\LivePositionsInterface;
use Uhifadhi\Contracts\Area\StationSectionsInterface;
use Uhifadhi\Contracts\Roster\WatchProviderInterface;
use Uhifadhi\Contracts\Shell\ConfigurationSectionsInterface;
use Uhifadhi\Contracts\Shell\ModuleTabsInterface;
use Uhifadhi\Roster\Controller\RosterConfigureController;
use Uhifadhi\Roster\Controller\RosterController;
use Uhifadhi\Roster\Controller\RosterOrgController;
use Uhifadhi\Roster\Controller\RosterPatternsController;
use Uhifadhi\Roster\Controller\RosterWidgetsController;
use Uhifadhi\Roster\DependencyInjection\RosterConfiguration;
use Uhifadhi\Roster\Devkit\PresenceContentProvider;
use Uhifadhi\Roster\Devkit\RosterContentProvider;
use Uhifadhi\Roster\Module\RosterModuleProvider;
use Uhifadhi\Roster\Module\RosterWatches;
use Uhifadhi\Roster\Org\RosterOrgOverview;
use Uhifadhi\Roster\Repository\AbsenceRepository;
use Uhifadhi\Roster\Repository\DutyRepository;
use Uhifadhi\Roster\Repository\PatternRepository;
use Uhifadhi\Roster\Repository\RotationPoolMemberRepository;
use Uhifadhi\Roster\Repository\RotationRepository;
use Uhifadhi\Roster\Repository\ShiftRepository;
use Uhifadhi\Roster\Repository\StationWatchRepository;
use Uhifadhi\Roster\Service\AgendaService;
use Uhifadhi\Roster\Service\DayPlanService;
use Uhifadhi\Roster\Service\RosterDashboardService;
use Uhifadhi\Roster\Service\RosterFiguresService;
use Uhifadhi\Roster\Service\RosterLiveService;
use Uhifadhi\Roster\Service\RosterOrgService;
use Uhifadhi\Roster\Service\RosterWidgetUrls;
use Uhifadhi\Roster\Shell\RosterConfigurationSections;
use Uhifadhi\Roster\Shell\RosterModuleTabs;
use Uhifadhi\Roster\Shell\RosterStationSections;
use Uhifadhi\Roster\Widget\RosterOrgWidgets;
use Uhifadhi\Roster\Widget\RosterRailWidgets;
use Uhifadhi\Roster\Widget\RosterWidgets;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * ROSTER — who is due on watch, where and when.
 *
 * The module owns the ROTATION a post or a team runs, the DUTIES it generates,
 * the SWAP between two of them, the ABSENCE that makes a hole, and the WATCH a
 * station expects. It owns no station, no posting, no check-in and no
 * position: those are the area's, and the presence this module draws is READ
 * from the area rather than computed here.
 *
 * Zero-config: registering the bundle maps its own entities, registers its own
 * migrations and serves its own assets, so an installation writes no doctrine
 * block, no migrations path and no asset path for it.
 */
final class UhifadhiRosterBundle extends AbstractBundle
{
    /**
     * WHERE THIS BUNDLE'S OWN VOCABULARY IS SERVED FROM — what AssetMapper
     * serves public/roster.css under. Stated once because it has two readers
     * that must never disagree: this module's own base template, which links
     * it on every roster page, and the contribution that hands it to an AREA
     * rendering this module's presence card on its overview.
     */
    public const string STYLESHEET = 'bundles/uhifadhiroster/roster.css';

    /**
     * The AssetMapper namespace for the bundle's Stimulus controllers. It MUST
     * be the npm-style form of the composer package name: Flex keys
     * assets/controllers.json by '@'.<package name> and StimulusBundle
     * resolves that key back to this directory.
     */
    public const string ASSET_NAMESPACE = '@uhifadhi/roster-module';

    /**
     * StimulusBundle's own normalisation of {@see ASSET_NAMESPACE} — '@'
     * dropped, '/' and '_' to '-'. What a template's data-controller starts
     * with.
     */
    public const string CONTROLLER_PREFIX = 'uhifadhi--roster-module--';

    /** Config lives under "roster:", not the class-derived "uhifadhi_roster:". */
    protected string $extensionAlias = 'roster';

    public function configure(DefinitionConfigurator $definition): void
    {
        RosterConfiguration::define($definition->rootNode());
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // The bundle's public/ dir is auto-registered by AssetMapper under
        // `bundles/uhifadhiroster` and content-versioned — no config here, no
        // assets:install.

        /*
         * Ship the bundle's Stimulus controllers (assets/) under an AssetMapper
         * namespace, exactly as symfony/ux-turbo does (TurboExtension::prepend).
         * Guarded on BOTH conditions: a kernel may have no framework extension,
         * and AssetMapper is optional.
         *
         * The `path => namespace` shape is the one the framework's own config
         * node normalises: "Can be a simple array of an array of
         * ['path/to/assets': 'namespace']", read back as
         * `$result[$item['value']] = $item['namespace']`.
         *
         * @see https://symfony.com/doc/current/frontend/asset_mapper.html
         * @see vendor/symfony/framework-bundle/DependencyInjection/Configuration.php — the asset_mapper "paths" node
         */
        if ($builder->hasExtension('framework') && interface_exists(AssetMapperInterface::class)) {
            // PREPENDED, THE SHAPE EVERY symfony/ux BUNDLE WRITES. `extension()`
            // appends even when called from prependExtension(), which puts this
            // path LAST, where it overrules an installation's own framework
            // config instead of deferring to it; prepended, "any other settings
            // done explicitly inside the config/* files would override these
            // prepended settings".
            //
            // @see https://symfony.com/doc/current/bundles/prepend_extension.html
            // @see https://symfony.com/doc/current/frontend/create_ux_bundle.html
            // @see vendor/symfony/ux-map/src/UXMapBundle.php:117
            // @see vendor/symfony/ux-chartjs/src/DependencyInjection/ChartjsExtension.php:58
            $builder->prependExtensionConfig('framework', [
                'asset_mapper' => [
                    'paths' => [
                        __DIR__.'/../assets' => self::ASSET_NAMESPACE,
                    ],
                ],
            ]);
        }

        /*
         * THE SQL THAT CREATES THIS MODULE'S TABLES, SHIPPED WITH IT.
         *
         * An installation runs `doctrine:migrations:migrate` and writes no
         * version for roster_* — the same way it writes none for the core.
         * `doctrine:migrations:diff` stays what it runs for the entities IT
         * writes, and after an update of this package it must report no
         * changes.
         *
         * The guard is not decoration: an application may have this bundle
         * and not the migrations bundle, and there this module simply has no
         * history to run.
         *
         * The namespace is mapped by this package's composer.json with an
         * EXPLICIT psr-4 prefix. `Uhifadhi\Roster\` is `src/`, so a lowercase
         * `migrations/` directory would resolve under nothing — a failure
         * that shows up only on a case-sensitive filesystem, which is to say
         * in an installation and not here.
         *
         * @see https://symfony.com/bundles/DoctrineMigrationsBundle/current/index.html
         */
        if ($builder->hasExtension('doctrine_migrations')) {
            $container->extension('doctrine_migrations', [
                'migrations_paths' => [
                    'Uhifadhi\\Roster\\Migrations' => __DIR__.'/../migrations',
                ],
            ], prepend: true);
        }

        /*
         * THE MODULE'S OWN ICON SET. Icons are `ux_icon`, never an inline
         * <svg> in a template (the static designs keep theirs — they have no
         * Symfony runtime). The set is VENDORED here rather than pulled from
         * a remote lucide at render time: an installation behind a firewall
         * still draws its buttons, and a missing icon fails loudly at build
         * rather than silently at a customer's.
         *
         * One prefix maps to one local directory and is answered only from it —
         * `icon_sets.<prefix>.path`, which the extension reads straight into the
         * icon-set paths and which may not be combined with `alias`.
         *
         * @see https://symfony.com/bundles/ux-icons/current/index.html#full-configuration
         * @see vendor/symfony/ux-icons/src/DependencyInjection/UXIconsExtension.php
         */
        if ($builder->hasExtension('ux_icons')) {
            $container->extension('ux_icons', [
                'icon_sets' => [
                    'roster' => ['path' => __DIR__.'/../assets/icons/roster'],
                ],
            ]);
        }

        /*
         * Zero-config persistence: the bundle maps its own entities, so
         * installations never write a doctrine mappings block for roster_*.
         * `is_bundle: false` with an absolute `dir` and the namespace `prefix`
         * is the shape DoctrineBundle's own mapping node resolves without
         * looking the directory up inside a bundle.
         *
         * @see https://symfony.com/doc/current/doctrine.html
         * @see vendor/doctrine/doctrine-bundle/src/DependencyInjection/DoctrineExtension.php — setMappingDriverConfig()
         */
        if ($builder->hasExtension('doctrine')) {
            $container->extension('doctrine', [
                'orm' => [
                    'mappings' => [
                        'UhifadhiRoster' => [
                            'type' => 'attribute',
                            'dir' => __DIR__.'/Entity',
                            'prefix' => 'Uhifadhi\\Roster\\Entity',
                            'is_bundle' => false,
                        ],
                    ],
                ],
            ]);
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // Static service wiring lives in a PHP config file (see config/services.php
        // for why PHP, not YAML). loadExtension keeps only the config-DRIVEN bits.
        $container->import('../config/services.php');

        // Explicit wiring, no autowire/autoconfigure — see config/services.php
        // for the Symfony reusable-bundle rule and its citation.
        $services = $container->services();

        // The one module this bundle contributes, collected by the registry's
        // catalogue seed and module grid. The tag is applied BY HAND: the core
        // registers ModuleProviderInterface for autoconfiguration, but that
        // only fires for autoconfigured services and a reusable bundle does
        // not autoconfigure.
        $category = \is_string($config['module_category'] ?? null) ? $config['module_category'] : 'operations';
        $services->set('roster.module_provider', RosterModuleProvider::class)
            ->args([$category])
            ->tag('uhifadhi.module')
            // AND IT ANSWERS AT ORGANISATION LEVEL TOO. The same provider
            // names the screens this module contributes once across every
            // area; the shell mounts them and draws the chrome.
            ->tag('shell.org_pages');

        // THE DEPLOYMENT'S SHIFT VOCABULARY, as a parameter for the services
        // that read it. Shape-checked on the way in: phpstan max will demand
        // it, and a malformed tree is better caught at compile time than by a
        // day board that draws a watch with no window.
        $shifts = $config['shifts'] ?? RosterConfiguration::DEFAULT_SHIFTS;
        $builder->setParameter('roster.shifts', \is_array($shifts) ? array_values($shifts) : RosterConfiguration::DEFAULT_SHIFTS);

        // THE STARTING VALUES a new area setting, station watch or rotation is
        // created with. Read when something is CREATED, never at display time —
        // what a station or an area actually runs at is its own stored value.
        $defaults = $config['defaults'] ?? [];
        $defaults = \is_array($defaults) ? $defaults : [];
        $builder->setParameter('roster.default_ping_interval_minutes', self::intOr($defaults['ping_interval_minutes'] ?? null, RosterConfiguration::DEFAULT_PING_INTERVAL_MINUTES));
        $builder->setParameter('roster.default_silence_window_minutes', self::intOr($defaults['silence_window_minutes'] ?? null, RosterConfiguration::DEFAULT_SILENCE_WINDOW_MINUTES));
        $builder->setParameter('roster.default_offline_after_minutes', self::intOr($defaults['offline_after_minutes'] ?? null, RosterConfiguration::DEFAULT_OFFLINE_AFTER_MINUTES));
        $builder->setParameter('roster.default_catchment_metres', self::intOr($defaults['catchment_metres'] ?? null, RosterConfiguration::DEFAULT_CATCHMENT_METRES));
        $builder->setParameter('roster.default_horizon_days', self::intOr($defaults['horizon_days'] ?? null, RosterConfiguration::DEFAULT_HORIZON_DAYS));

        // Dev-only tooling hangs off a visible flag in installation config, the
        // way framework.test does, rather than an env() check buried in bundle
        // code. The recipe enables it via when@dev / when@test.
        $builder->setParameter('roster.dev_tools', true === ($config['dev_tools'] ?? false));

        /*
         * THE MODULE'S TWO DECLARATIONS TO THE FRAME — where its data lives
         * and what is on its configure page. Tagged BY HAND: a reusable
         * bundle is not autoconfigured, and a declaration that forgot its tag
         * gets a module with no tab strip, no children in the sidebar tree
         * and no Configure page, with nothing anywhere saying why.
         */
        $services->set('roster.module_tabs', RosterModuleTabs::class)
            ->tag(ModuleTabsInterface::TAG);

        $services->set('roster.configuration_sections', RosterConfigurationSections::class)
            ->args([service('request_stack'), service(AreaOfInterestRepository::class)])
            ->tag(ConfigurationSectionsInterface::TAG);

        /*
         * WHAT THIS MODULE PUTS ON A POST — the watch-and-presence band on
         * the station's record, and the Roster block on its configure card.
         * Ruled 18 sep: the station is the area's and the watch is the
         * roster's, so the watch is CONTRIBUTED into the area's own pages
         * rather than drawn on a page of this module's.
         *
         * Tagged by hand at this end too. An attribute on the contract
         * interface would be silently dead — Symfony reads autoconfigure
         * attributes off the definition's own class and PHP does not inherit
         * them from an interface — and the only symptom would be every band
         * quietly disappearing.
         */
        $services->set('roster.station_sections', RosterStationSections::class)
            ->args([
                service(StationRepository::class),
                service('roster.station_watches'),
                service('roster.shift_vocabulary'),
                service('roster.presence'),
                service('router'),
                /*
                 * THE DOOR ON AN OFF-THE-BOOKS POST'S CARD writes, so it
                 * carries a token — and it exists only where the configure
                 * controller does, because that is where it posts. An
                 * installation with no SecurityBundle gets the block
                 * without the door rather than a button at a route nobody
                 * mounted.
                 */
                service('security.csrf.token_manager')->nullOnInvalid(),
                service('request_stack'),
            ])
            ->tag(StationSectionsInterface::TAG);

        /*
         * WHAT THIS MODULE PUTS ON THE ORGANISATION DASHBOARD — the "on
         * duty now" figure in the strip, and today's watches across every
         * area. The design declares both; this registers them.
         *
         * A SECOND SEAM BESIDE THE AREA'S, opted into deliberately. A
         * module with nothing to say across areas says nothing and loses no
         * cells on the area page — so this tag is the whole of opting in.
         *
         * Tagged by hand at this end, like every other: a reusable bundle
         * is not autoconfigured, and an attribute on the contract interface
         * would be silently dead because PHP does not inherit attributes
         * from an interface.
         */
        $services->set('roster.org_overview', RosterOrgOverview::class)
            ->args([service('roster.org'), service('router')])
            ->tag(OrgOverviewContributorInterface::TAG);

        /*
         * THE ONE QUESTION THE AREA ASKS THE ROSTER, on behalf of a handset
         * reading its month. Read-only and per person; a day with no watch
         * is a rest day and is answered by saying nothing.
         */
        $services->set('roster.watches', RosterWatches::class)
            ->args([
                service(AreaOfInterestRepository::class),
                service(DutyRepository::class),
                service(ShiftRepository::class),
            ])
            ->tag(WatchProviderInterface::TAG);

        // THE DAY AS SLOTS TO FILL, and the one write that fills them. The
        // rules live here and nowhere else: the template renders what this
        // says and decides nothing.
        $services->set('roster.day_plan', DayPlanService::class)
            ->args([
                service(StationWatchRepository::class),
                service(RotationRepository::class),
                service(RotationPoolMemberRepository::class),
                service(DutyRepository::class),
                service(AbsenceRepository::class),
                service('roster.shift_vocabulary'),
                service('doctrine.orm.entity_manager'),
            ]);

        // WHERE EVERYBODY IS, FED TO THE ATLAS. The plate, the ground and
        // the posts are other people's; this contributes the marker layers
        // and the legend group over them, and draws nothing itself.
        // THE ROSTER ONE SCOPE WIDER. It counts nothing of its own: it
        // resolves the areas a scope reaches and folds what the per-area
        // services already answer.
        $services->set('roster.org', RosterOrgService::class)
            ->args([
                service(AreaOfInterestRepository::class),
                service('roster.figures'),
                service('roster.presence'),
                service('roster.identity'),
                service('roster.week_grid'),
                service('roster.live'),
                service(LivePositionsInterface::class),
                service(StationWatchRepository::class),
                service(RotationRepository::class),
                service(ShiftRepository::class),
            ]);

        $services->set('roster.live', RosterLiveService::class)
            ->args([
                service(AreaPlateService::class),
                service(ZoneSetService::class),
                service(StationRepository::class),
                service(PostingRepository::class),
                service(ZoneRepository::class),
            ]);

        // THE WHOLE SURFACE'S ONE READ. Both the dashboard and the library
        // build their widget context from this, which is what makes a
        // library preview the widget rather than a picture of one.
        $services->set('roster.dashboard', RosterDashboardService::class)
            ->args([
                service('roster.presence'),
                service('roster.agenda'),
                service('roster.week_grid'),
                service('roster.day_board'),
                service(DutyRepository::class),
                service(RotationRepository::class),
                service(AbsenceRepository::class),
                service(ShiftRepository::class),
                service('roster.live'),
                service(LivePositionsInterface::class),
            ]);

        // THE AGENDA — which posts and people a filtered day shows, and the
        // five figures over the whole of it.
        $services->set('roster.agenda', AgendaService::class)
            ->args([
                service('roster.presence'),
                service('roster.week_grid'),
                service(ShiftRepository::class),
                service(DutyRepository::class),
                service(StationRepository::class),
            ]);

        // THE DAY'S FIGURES — one read, folded once, so five cards on one
        // screen can never disagree about the same day.
        $services->set('roster.figures', RosterFiguresService::class)
            ->args([
                service('roster.presence'),
                service('roster.week_grid'),
                service(StationRepository::class),
            ]);

        /*
         * THE WIDGET SURFACE — a CATALOGUE, not a renderer. The shell owns
         * the preference storage, the presets and the resolution; this says
         * only what the surface ships and what the shipped composition is.
         *
         * TAGGED BY HAND, like every contribution seam: a reusable bundle
         * does not autoconfigure, so registerForAutoconfiguration never
         * fires for it and an untagged surface is one whose stored layouts
         * `widget:prune` reads as orphans and deletes.
         */
        $services->set('roster.widgets', RosterWidgets::class)
            ->tag(WidgetSurfaceInterface::TAG);

        // THE LIVE TAB'S RAIL IS A SECOND SURFACE, not a preset of the
        // first: one composes a page out of cards, the other a column out
        // of lists. They share the mechanism and nothing else, which is
        // what a surface is for. Tagged by hand like every contribution.
        $services->set('roster.rail_widgets', RosterRailWidgets::class)
            ->tag(WidgetSurfaceInterface::TAG);

        // AND THE THIRD: the module read across every area. It is a surface
        // like the other two and composed the same way; what makes it
        // different is only the scope the figures under it are read at.
        $services->set('roster.org_widgets', RosterOrgWidgets::class)
            ->tag(WidgetSurfaceInterface::TAG);

        /*
         * THE DEMO CONTENT, WHICH EXISTS ONLY WHERE DEVKIT DOES.
         *
         * THE TAG IS A LITERAL STRING, deliberately and per the contract:
         * naming devkit's own constant would load a class that is not
         * installed in production, which is the whole hazard the arrangement
         * exists to avoid. Both ends agree on the word through the contracts
         * package they always share, and neither names the other.
         *
         * THE SERVICES THEMSELVES ARE INERT HERE. They are ordinary tagged
         * services in every build; nothing collects them unless devkit — a
         * require-dev package — is present to run `fixtures:demo`. So this
         * costs a production container two definitions nobody calls.
         *
         * TWO PROVIDERS, NOT ONE, because they are two slices: the PLAN (who
         * is due where) and the PROOF (what the handsets reported against
         * it). The second depends on the first by key, so devkit orders them
         * without either knowing when the other runs.
         */
        $services->set('roster.devkit.content', RosterContentProvider::class)
            ->args([
                service(AreaOfInterestRepository::class),
                service(StationRepository::class),
                service(PostingRepository::class),
                service(PostingService::class),
                service(UserRepository::class),
                service(DepartmentRepository::class),
                service(DutyRepository::class),
                service(RotationRepository::class),
                service(StationWatchRepository::class),
                service(AbsenceRepository::class),
                service('roster.shift_vocabulary'),
                service('roster.station_watches'),
                service('roster.rotation_generator'),
                service('roster.swaps'),
                service('doctrine.orm.entity_manager'),
                // THE SAME CLOCK THE PRESENCE SEEDER READS. One writes the
                // duties and the other works them, so the two must agree
                // about what day it is.
                service('clock'),
            ])
            ->tag('uhifadhi.devkit.content_provider');

        $services->set('roster.devkit.presence_content', PresenceContentProvider::class)
            ->args([
                service(AreaOfInterestRepository::class),
                service(DutyRepository::class),
                service(ShiftRepository::class),
                // THE HANDSET'S OWN DOOR, WHICH IS CONDITIONAL. The area
                // registers its field API only where ApiPlatform and
                // Security both are, and an installation without it has no
                // way for a check-in to be reported at all — so there is
                // genuinely no presence to seed, and the provider says so
                // rather than the container failing to compile.
                service(CheckInService::class)->nullOnInvalid(),
                service(CheckInStatusService::class),
                // THE INSTANT, as a collaborator. What this seeder writes
                // depends on the time of day, so a wall clock inside it is
                // a demo nobody can test twice and get the same answer.
                service('clock'),
            ])
            ->tag('uhifadhi.devkit.content_provider');

        // THE OVERVIEW TAB. A read, so it is registered unconditionally: an
        // installation with no firewall still has a roster to look at.
        $services->set('roster.controller.overview', RosterController::class)
            ->args([
                service('twig'),
                service('roster.identity'),
                service('roster.sheet'),
                service('roster.sheet_fill'),
                service('roster.sheet_day'),
                service('roster.sheet_preferences'),
                service('roster.patterns'),
                service(PatternRepository::class),
                service('Uhifadhi\\Bundle\\AreaBundle\\Repository\\StationRepository'),
                service(StationWatchRepository::class),
                service('roster.shift_rules'),
                service('roster.presence'),
                service('roster.day_board'),
                service('roster.rostered_people'),
                service('roster.calendar'),
                service('roster.swaps'),
                service('roster.swap_cost'),
                service('roster.dashboard'),
                service('roster.agenda'),
                service(ShiftRepository::class),
                service(DutyRepository::class),
                service(LivePositionsInterface::class),
                service('roster.live'),
                service('roster.day_plan'),
                service(AbsenceRepository::class),
                service(WidgetService::class),
                service('router'),
                // Null where the installation runs no security: the week tab
                // then offers no swap, because there is nobody to attribute
                // an offer to and nothing to refuse one with.
                service('security.authorization_checker')->nullOnInvalid(),
                service('security.token_storage')->nullOnInvalid(),
                service('security.csrf.token_manager')->nullOnInvalid(),
            ])
            ->public();
        $services->alias(RosterController::class, 'roster.controller.overview')->public();

        /*
         * THE CONFIGURE SECTIONS ARE REGISTERED ONLY WHERE SECURITYBUNDLE IS
         * ACTUALLY IN THE KERNEL. Every write on that page changes how an area
         * runs its roster and rides on "roster.manage"; without an
         * authorization checker there is nothing to enforce it, so an
         * installation in that state gets NO configure routes (they fail
         * loudly) rather than three open write endpoints.
         *
         * The guard reads kernel.bundles, as FrameworkExtension does. Two
         * other checks look right and are not: hasExtension('security')
         * cannot be used while an extension is loading, because the builder is
         * then a restricted MergeExtensionConfigurationContainerBuilder that
         * does not expose other extensions; and interface_exists() only proves
         * a class is autoloadable — security-core is one of this bundle's DEV
         * dependencies, so it autoloads in our own test runs even when
         * SecurityBundle is absent, and the services would then reference
         * security.* ids that do not exist.
         */
        $bundles = $builder->hasParameter('kernel.bundles') ? $builder->getParameter('kernel.bundles') : [];
        $hasSecurity = \is_array($bundles) && isset($bundles['SecurityBundle']);

        // The templates hide the Configure action where the page cannot exist.
        $builder->setParameter('roster.configure_screens', $hasSecurity);

        if ($hasSecurity) {
            $services->set('roster.controller.configure', RosterConfigureController::class)
                ->args([
                    service('twig'),
                    service('router'),
                    service('roster.identity'),
                    service('roster.settings'),
                    service('roster.shift_vocabulary'),
                    service('roster.shift_rules'),
                    service('roster.station_watches'),
                    service(StationRepository::class),
                    service(PostingRepository::class),
                    service(CheckInStatusService::class),
                    service('roster.rostered_people'),
                    service('roster.rotation_editor'),
                    service('roster.rotation_preview'),
                    service('roster.rotation_generator'),
                    service(RotationRepository::class),
                    service('security.authorization_checker'),
                    service('security.csrf.token_manager'),
                ])
                ->public();
            $services->alias(RosterConfigureController::class, 'roster.controller.configure')->public();

            /*
             * PATTERNS — the configure page's own section, and a screen of
             * its own for the reason every one of these has one: it draws
             * this module's cycle strip and its sentence editor, and the
             * shell's configure page links no module stylesheet.
             */
            $services->set('roster.controller.patterns', RosterPatternsController::class)
                ->args([
                    service('twig'),
                    service('router'),
                    service('roster.identity'),
                    service('roster.patterns'),
                    service(PatternRepository::class),
                    service('security.authorization_checker'),
                    service('security.csrf.token_manager'),
                ])
                ->public();
            $services->alias(RosterPatternsController::class, 'roster.controller.patterns')->public();

            /*
             * THE WIDGET LIBRARY — the configure page's first section.
             *
             * SECURITY-GATED like every other write here, and for the same
             * reason: arranging a dashboard is a write attributed to a
             * person, and without a firewall there is nobody to attribute
             * it to. The screen does not exist rather than existing
             * unattributed.
             */
            $services->set('roster.widget_urls', RosterWidgetUrls::class)
                ->args([service('router')]);

            $services->set('roster.controller.org', RosterOrgController::class)
                ->args([
                    service('twig'),
                    service('roster.org'),
                    service(WidgetService::class),
                    service(WidgetEndpoint::class),
                    service(\Uhifadhi\Bundle\ShellBundle\Service\Scopes::class),
                ])
                ->public();
            $services->alias(RosterOrgController::class, 'roster.controller.org')->public();

            $services->set('roster.controller.widgets', RosterWidgetsController::class)
                ->args([
                    service('twig'),
                    service(WidgetService::class),
                    service(WidgetEndpoint::class),
                    service('roster.widget_urls'),
                    service('roster.dashboard'),
                    service('roster.identity'),
                    service('router'),
                    service('roster.live'),
                    service('roster.presence'),
                    service(LivePositionsInterface::class),
                    service(ShiftRepository::class),
                    service('roster.org'),
                ])
                ->public();
            $services->alias(RosterWidgetsController::class, 'roster.controller.widgets')->public();
        }
    }

    private static function intOr(mixed $value, int $fallback): int
    {
        return \is_int($value) ? $value : $fallback;
    }
}
