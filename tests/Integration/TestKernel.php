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

namespace Uhifadhi\Roster\Tests\Integration;

use ApiPlatform\Symfony\Bundle\ApiPlatformBundle;
use Composer\InstalledVersions;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Doctrine\Bundle\MigrationsBundle\DoctrineMigrationsBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\UX\Icons\UXIconsBundle;
use Symfony\UX\Map\UXMapBundle;
use Symfony\UX\StimulusBundle\StimulusBundle;
use Uhifadhi\Bundle\AreaBundle\AreaBundle;
use Uhifadhi\Bundle\AreaBundle\Controller\AreaController;
use Uhifadhi\Bundle\AtlasBundle\AtlasBundle;
use Uhifadhi\Bundle\RegistryBundle\RegistryBundle;
use Uhifadhi\Bundle\ShellBundle\Frame\Controller\ConfigureController;
use Uhifadhi\Bundle\ShellBundle\ShellBundle;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\TeamBundle;
use Uhifadhi\Roster\Tests\Integration\Fixtures\CollectedModules;
use Uhifadhi\Roster\Tests\Integration\Fixtures\FixedManageVoter;
use Uhifadhi\Roster\Tests\Integration\Fixtures\FixedScopeSource;
use Uhifadhi\Roster\UhifadhiRosterBundle;
use UtafitiLabs\PostGISBundle\UtafitiLabsPostGISBundle;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

/**
 * THE SMALLEST INSTALLATION THIS BUNDLE CAN LIVE IN, and every part of it is
 * real: framework + twig + doctrine + PostGIS + security, the five core
 * bundles, and this module — against a REAL PostGIS database
 * (ROSTER_TEST_DATABASE_URL, see phpunit.dist.xml).
 *
 * NOTHING HERE IS A COPY. Every bundle below is the published one, because a
 * copy cannot hold a contract — it pins whatever the copyist believed.
 *
 * THE AREA BUNDLE IS NOT OPTIONAL SCENERY HERE. It owns the station a watch is
 * stood at, the posting that puts a person there, and the check-ins presence
 * is derived from. A duty carries a foreign key to a station, so a suite
 * without AreaBundle would be asserting against a schema no installation has.
 */
final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * THE HOUR THIS SUITE RUNS AT, whenever it actually runs.
     *
     * THE DAY IS TODAY AND THE HOUR IS NOT. The demo rosters the current
     * fortnight, so a fixed DATE would seed a month the suite then looks
     * for in the wrong place; what broke CI was the TIME OF DAY — which
     * watches have begun, which are still running, how old a ping is. So
     * the date moves with the calendar and the clock is pinned to
     * mid-morning: after the day and office watches begin, long before the
     * night one, which is the state these tests are about.
     */
    public const string NOW = 'today 10:30';

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new TwigBundle();
        yield new StimulusBundle();
        yield new UXIconsBundle();
        // The atlas's plates are built on UX Map and its Leaflet bridge, and
        // the Live tab draws through them.
        yield new UXMapBundle();
        yield new DoctrineBundle();
        // An installation has this, and this module ships a history for it to
        // run. Without it the bundle's migrations_paths block is guarded out.
        yield new DoctrineMigrationsBundle();
        yield new UtafitiLabsPostGISBundle();
        yield new SecurityBundle();
        // The per-area catalogue this module registers itself in.
        yield new RegistryBundle();
        // The frame every roster screen renders in, and the widget framework
        // the Overview tab IS.
        yield new ShellBundle();
        // The map chrome and the plate the Live tab draws on.
        yield new AtlasBundle();
        // The account class every duty, swap and absence is keyed by.
        yield new TeamBundle();
        // The station a watch is stood at, the posting that puts a person
        // there, and the check-ins presence is read from.
        // THE FIELD API'S OWN CONDITION. The area registers the handset's
        // check-in door only where ApiPlatform and Security both are, and
        // the demo presence seeder writes through that door — so a kernel
        // without this would exercise the seeder's absent-API branch and
        // assert nothing about the one that runs in an installation.
        yield new ApiPlatformBundle();
        yield new AreaBundle();
        yield new UhifadhiRosterBundle();
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'test',
            'test' => true,
            'router' => ['utf8' => true],
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
            // loginUser() needs a stateful firewall and flashes need a session;
            // the mock file storage is the documented test-env choice.
            'session' => ['storage_factory_id' => 'session.storage.factory.mock_file'],
            // Every write this module ships carries a CSRF token, so the token
            // manager has to exist here as it does in a real installation
            // (FrameworkBundle only defines it when csrf_protection is on).
            'csrf_protection' => ['enabled' => true],
            // asset() has to exist: the shell's document and this module's base
            // template both link stylesheets with it.
            'assets' => true,
            'asset_mapper' => [
                'paths' => [__DIR__.'/Fixtures/app/assets' => ''],
            ],
        ]);

        // The security block a skeleton installation writes, minus the screens
        // this kernel does not mount: the hashers, the entity provider over the
        // account TeamBundle owns, and the checker that refuses a deactivated
        // one. Permission checks go through the real AuthorizationChecker
        // rather than a stub that always says yes.
        $container->extension('security', [
            'password_hashers' => [
                'Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface' => [
                    // Test-only cost floor, the documented Symfony practice.
                    'algorithm' => 'auto',
                    'cost' => 4,
                    'time_cost' => 3,
                    'memory_cost' => 10,
                ],
            ],
            'providers' => [
                'team_user_provider' => ['entity' => ['class' => User::class, 'property' => 'email']],
            ],
            'firewalls' => [
                'main' => [
                    'lazy' => true,
                    'provider' => 'team_user_provider',
                    'user_checker' => 'team.user_checker',
                ],
            ],
        ]);

        $container->extension('doctrine', [
            'dbal' => [
                'url' => '%env(ROSTER_TEST_DATABASE_URL)%',
            ],
            'orm' => [
                // The skeleton's own choice (config/packages/doctrine.yaml),
                // mirrored here so this module's metadata-driven SQL is
                // exercised against the column names it will actually meet.
                'naming_strategy' => 'doctrine.orm.naming_strategy.underscore',
                // NO 'mappings' AND NO 'resolve_target_entities', both
                // deliberately: every entity this module points at arrives with
                // the bundle that owns it and maps itself.
            ],
        ]);

        // With on-demand fetching on, a name no file answers to is fetched from
        // a remote API and cached, so a missing glyph stays invisible until the
        // deployment with no outbound network draws a blank square. An
        // installation turns it off, and this kernel is an installation.
        $container->extension('ux_icons', [
            'iconify' => ['on_demand' => false],
        ]);

        // UX Map draws nothing at all until a renderer is named.
        $container->extension('ux_map', ['renderer' => 'leaflet://default']);

        // THE INSTALLATION'S PERMISSION VOTER, played by a fixture: this
        // module declares the roster concern and grants nobody its verbs, so
        // something has to decide who holds it. Tagged by hand — a
        // reusable-bundle test kernel does not autoconfigure.
        $container->services()->set(FixedManageVoter::class)->tag('security.voter');

        // WHAT THIS VIEWER MAY LOOK AT. The shell holds no areas and no
        // voters, so the scope list is the application's — and there is no
        // application here, so a fixture stands where one would.
        $container->services()
            ->set(FixedScopeSource::class)
            ->args([service(\Uhifadhi\Bundle\AreaBundle\Repository\AreaOfInterestRepository::class)])
            ->tag('shell.scope_source');

        // THE CLOCK IS FIXED IN THIS SUITE, and that is the whole point of
        // it being a collaborator. What the presence seeder writes depends
        // on the time of day — which watches have begun, which are still
        // running, how old a ping is — so a suite reading the wall clock
        // asserts a different thing every hour, and passes on one CI leg
        // while failing on the next for no reason but when they ran.
        //
        // A test that cares about the hour moves this itself; everything
        // else simply gets a stable mid-morning.
        $container->services()
            ->set('clock', MockClock::class)
            ->args([self::NOW])
            ->public();

        // Public aliases so tests can hold the bundle's private services,
        // keyed by class name for readability (see IntegrationTestCase). They
        // exist only because a bundle test kernel has no controllers yet:
        // unreferenced private services are removed at compile time. Delete an
        // alias the day a real reference exists.
        foreach ([
            \Uhifadhi\Roster\Service\CyclePlanner::class => 'roster.cycle_planner',
            \Uhifadhi\Roster\Service\RotationGenerator::class => 'roster.rotation_generator',
            \Uhifadhi\Roster\Repository\ShiftRepository::class => \Uhifadhi\Roster\Repository\ShiftRepository::class,
            \Uhifadhi\Roster\Repository\RotationRepository::class => \Uhifadhi\Roster\Repository\RotationRepository::class,
            \Uhifadhi\Roster\Repository\DutyRepository::class => \Uhifadhi\Roster\Repository\DutyRepository::class,
            \Uhifadhi\Roster\Repository\EditedDayRepository::class => \Uhifadhi\Roster\Repository\EditedDayRepository::class,
            \Uhifadhi\Roster\Repository\AbsenceRepository::class => \Uhifadhi\Roster\Repository\AbsenceRepository::class,
            \Uhifadhi\Roster\Repository\StationWatchRepository::class => \Uhifadhi\Roster\Repository\StationWatchRepository::class,
            \Uhifadhi\Roster\Repository\AreaRosterSettingsRepository::class => \Uhifadhi\Roster\Repository\AreaRosterSettingsRepository::class,
            \Uhifadhi\Roster\Service\RosterSettingsService::class => 'roster.settings',
            \Uhifadhi\Roster\Service\ShiftVocabularyService::class => 'roster.shift_vocabulary',
            \Uhifadhi\Roster\Service\StationWatchService::class => 'roster.station_watches',
            // The five rules and their per-station exceptions, and the
            // cycles an area fills a station from.
            \Uhifadhi\Roster\Service\ShiftRuleService::class => 'roster.shift_rules',
            \Uhifadhi\Roster\Service\PatternService::class => 'roster.patterns',
            \Uhifadhi\Roster\Service\RosterIdentityService::class => 'roster.identity',
            \Uhifadhi\Roster\Shell\RosterModuleTabs::class => 'roster.module_tabs',
            \Uhifadhi\Roster\Shell\RosterConfigurationSections::class => 'roster.configuration_sections',
            \Uhifadhi\Roster\Shell\RosterStationSections::class => 'roster.station_sections',
            \Uhifadhi\Roster\Module\RosterWatches::class => 'roster.watches',
            \Uhifadhi\Roster\Service\PresenceReader::class => 'roster.presence',
            \Uhifadhi\Roster\Service\SwapService::class => 'roster.swaps',
            \Uhifadhi\Roster\Service\SwapCostService::class => 'roster.swap_cost',
            \Uhifadhi\Roster\Service\RotationEditor::class => 'roster.rotation_editor',
            \Uhifadhi\Roster\Service\RotationPreview::class => 'roster.rotation_preview',
            \Uhifadhi\Roster\Service\WeekGridService::class => 'roster.week_grid',
            \Uhifadhi\Roster\Service\DayBoardService::class => 'roster.day_board',
            \Uhifadhi\Roster\Service\RosterCalendar::class => 'roster.calendar',
            \Uhifadhi\Roster\Service\RosteredPeople::class => 'roster.rostered_people',
            \Uhifadhi\Roster\Repository\SwapRepository::class => \Uhifadhi\Roster\Repository\SwapRepository::class,
            // The AREA's own derivation, so a test can assert this module
            // READS it rather than computing a second answer beside it.
            \Uhifadhi\Contracts\Area\PresenceProviderInterface::class => 'area.presence',
            // And the live read, so a demo-content test can assert what the
            // plate will actually draw this minute.
            \Uhifadhi\Contracts\Area\LivePositionsInterface::class => \Uhifadhi\Contracts\Area\LivePositionsInterface::class,
            \Uhifadhi\Bundle\AreaBundle\Service\CheckInStatusService::class => 'area.checkin_statuses',
            // AND THE DOOR A HANDSET CLAIMS THROUGH, so a fixture that
            // needs somebody off today tells the AREA rather than writing
            // a presence row this module is ruled never to own.
            \Uhifadhi\Bundle\AreaBundle\Service\CheckInService::class => 'area.checkins',
            // The post's own catchment — the ring a claim is measured
            // against, and the verb this module writes it through.
            \Uhifadhi\Bundle\AreaBundle\Service\StationService::class => 'area.stations',
            // The area's postings, so a demo-content test can staff its
            // posts the way an installation does rather than by hand.
            \Uhifadhi\Bundle\AreaBundle\Service\PostingService::class => 'area.postings',
            // AND THE POSTINGS THEMSELVES, so a fixture that MOVES somebody
            // can find the posting to end first: one person stands at one
            // post, so a move is two acts and not one.
            \Uhifadhi\Bundle\AreaBundle\Repository\PostingRepository::class => \Uhifadhi\Bundle\AreaBundle\Repository\PostingRepository::class,
            // The two demo-content providers. They are tagged for devkit,
            // which is not installed here, so a test reaches them by id.
            // The sheet's own two, so the reading and the filling can be
            // exercised without going through a controller.
            \Uhifadhi\Roster\Service\SheetService::class => 'roster.sheet',
            \Uhifadhi\Roster\Service\SheetFillService::class => 'roster.sheet_fill',
            \Uhifadhi\Roster\Service\SheetDayService::class => 'roster.sheet_day',
            \Uhifadhi\Roster\Service\SheetPreferences::class => 'roster.sheet_preferences',
            \Uhifadhi\Roster\Devkit\RosterContentProvider::class => 'roster.devkit.content',
            \Uhifadhi\Roster\Devkit\PresenceContentProvider::class => 'roster.devkit.presence_content',
            // The registry's own two, so a functional fixture can do what an
            // installation does: reconcile the catalogue, then switch this
            // module on for the area.
            \Uhifadhi\Bundle\RegistryBundle\Service\RegistrySyncService::class => 'registry.sync',
            \Uhifadhi\Bundle\RegistryBundle\Service\AreaModuleService::class => 'registry.area_modules',
        ] as $class => $serviceId) {
            $container->services()->alias('test_public.'.$class, $serviceId)->public();
        }

        // Stands in for the catalogue seed's collector: the registry collects
        // every service tagged "uhifadhi.module", and tagged services are
        // private, so this is what makes the bundle's contribution observable
        // from a test.
        $container->services()
            ->set(CollectedModules::class)
            ->args([tagged_iterator('uhifadhi.module')])
            ->public();
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $controllers = \dirname(__DIR__, 2).'/src/Controller/';
        if (is_dir($controllers)) {
            $routes->import($controllers, 'attribute');
        }

        /*
         * THE SCREENS AN INSTALLATION MOUNTS AROUND THIS MODULE. The roster's
         * breadcrumb names the area register, the area and its module grid,
         * and the shell owns the configure page every section of this
         * module's is a section OF — so a suite that renders a roster page
         * without them is a suite testing a page no installation serves.
         *
         * `roster_url()` still exists and is still the right shape: it keeps
         * a crumb a plain word rather than a 500 in an installation that
         * mounted one screen fewer. Mounting them here tests the other side
         * of that — the installation that mounted them all.
         */
        $routes->import(\dirname((new \ReflectionClass(AreaController::class))->getFileName() ?: ''), 'attribute');

        // The shell's configure page is a PHP route FILE, mounted by the
        // recipe in a real installation — not an attribute on a controller.
        $shell = \dirname((new \ReflectionClass(ConfigureController::class))->getFileName() ?: '', 3);
        $routes->import($shell.'/config/routes/configure.php');

        /*
         * AND THE FIELD API, under `/api`, exactly as an installation's own
         * `config/routes/api_platform.yaml` mounts it.
         *
         * IT IS THE OTHER END OF THIS MODULE'S ONE CONTRACT. The area asks
         * the roster "what is this person rostered for" and a handset reads
         * the answer at `GET /api/areas/{uuid}/me/roster`; a suite that
         * exercised the provider and never the endpoint would be asserting
         * that the module answers a question nobody can ask it. The
         * end-to-end test walks the whole chain and finishes there.
         */
        $routes->import('.', 'api_platform')->prefix('/api');
    }

    /**
     * THE STAND-IN INSTALLATION'S PROJECT DIRECTORY — an application's asset
     * side and nothing else. The shell's document renders the importmap of
     * whatever application it is installed in, so a suite that renders any
     * page through the page frame needs an application that has one. Pointing
     * the kernel at a fixture is how it gets one without this bundle growing
     * an importmap of its own, which a shipped bundle has no business
     * carrying.
     */
    public function getProjectDir(): string
    {
        return __DIR__.'/Fixtures/app';
    }

    /**
     * THE CACHE IS KEYED BY THE CORE IT WAS BUILT AGAINST.
     *
     * A KERNEL CACHE THAT OUTLIVES THE PACKAGE IT COMPILED IS A SUITE THAT
     * TESTS YESTERDAY'S SHELL. The templates this module renders through —
     * the page frame, the widget library, the map plate — belong to the
     * core, and Twig compiles them into this directory once. Update the
     * core, and every one of those stays compiled as it was: the suite goes
     * green against a shell that is no longer installed, and red against one
     * that is. It is not debug mode's job to notice, either — Twig's
     * auto-reload watches a template's own mtime, and a package extracted
     * fresh by Composer can arrive with an older one than the compiled copy.
     *
     * This was found the honest way: a test for a section id the core had
     * just started emitting stayed red through a correct upgrade, and the
     * only thing wrong was this directory.
     *
     * So the core's exact reference is part of the path. A new core is a new
     * cache, always, and the old one is simply orphaned in the temp
     * directory rather than reused.
     */
    private static function keyedBy(string $what): string
    {
        return \sprintf('%s/roster-module-tests/%s-%s/%s', sys_get_temp_dir(), self::coreReference(), self::ourOwnStamp(), $what);
    }

    /**
     * AND THIS MODULE'S OWN FILES ARE IN THE KEY TOO.
     *
     * A CONTAINER REMEMBERS WHICH TEMPLATES EXIST. Debug mode recompiles a
     * template whose mtime moved, but a template that did not exist when
     * the container was built is not in the list at all — so adding one and
     * running the suite gets the cache's answer, which is that there is no
     * such file. That is a false red with nothing wrong in the diff, and it
     * cost one the hour after the core half of this key was written.
     *
     * THE NEWEST MTIME IS ENOUGH and it is cheap: adding a file moves its
     * directory's mtime, so a new template changes the stamp exactly as an
     * edited one does.
     */
    private static function ourOwnStamp(): string
    {
        $newest = 0;

        foreach ([__DIR__.'/../../src', __DIR__.'/../../templates', __DIR__.'/../../config'] as $root) {
            if (!is_dir($root)) {
                continue;
            }

            /** @var \SplFileInfo $file */
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
                $newest = max($newest, $file->getMTime());
            }
        }

        return (string) $newest;
    }

    /**
     * WHICH CORE IS INSTALLED, as one short stable string.
     *
     * Composer knows the commit for anything it resolved, which is the whole
     * answer for CI and for an ordinary checkout. A PATH or symlinked
     * install — a test bed pointed straight at the core's working copy —
     * has no reference at all, and there the newest mtime among the
     * templates and the sheets is what changes when somebody edits one.
     */
    private static function coreReference(): string
    {
        $reference = InstalledVersions::getReference(self::CORE);

        if (\is_string($reference) && '' !== $reference) {
            return substr($reference, 0, 12);
        }

        $newest = 0;
        $root = InstalledVersions::getInstallPath(self::CORE);

        if (\is_string($root) && is_dir($root)) {
            /** @var \SplFileInfo $file */
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/src', \FilesystemIterator::SKIP_DOTS)) as $file) {
                if (\in_array($file->getExtension(), ['twig', 'css', 'js'], true)) {
                    $newest = max($newest, $file->getMTime());
                }
            }
        }

        return 'local-'.$newest;
    }

    /** The package every template this suite renders comes from. */
    private const string CORE = 'uhifadhi/uhifadhi';

    public function getCacheDir(): string
    {
        return self::keyedBy('cache');
    }

    public function getLogDir(): string
    {
        return self::keyedBy('log');
    }
}
