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

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Uhifadhi\Bundle\AreaBundle\Repository\AreaOfInterestRepository;
use Uhifadhi\Bundle\AreaBundle\Service\StationService;
use Uhifadhi\Contracts\Area\PresenceProviderInterface;
use Uhifadhi\Roster\Repository\AbsenceRepository;
use Uhifadhi\Roster\Repository\AreaRosterSettingsRepository;
use Uhifadhi\Roster\Repository\DutyRepository;
use Uhifadhi\Roster\Repository\EditedDayRepository;
use Uhifadhi\Roster\Repository\PatternRepository;
use Uhifadhi\Roster\Repository\RotationPoolMemberRepository;
use Uhifadhi\Roster\Repository\RotationRepository;
use Uhifadhi\Roster\Repository\SheetPreferenceRepository;
use Uhifadhi\Roster\Repository\ShiftRepository;
use Uhifadhi\Roster\Repository\ShiftRuleRepository;
use Uhifadhi\Roster\Repository\StationRuleExceptionRepository;
use Uhifadhi\Roster\Repository\StationWatchRepository;
use Uhifadhi\Roster\Repository\SwapRepository;
use Uhifadhi\Roster\Service\CyclePlanner;
use Uhifadhi\Roster\Service\DayBoardService;
use Uhifadhi\Roster\Service\PatternService;
use Uhifadhi\Roster\Service\PresenceReader;
use Uhifadhi\Roster\Service\RosterCalendar;
use Uhifadhi\Roster\Service\RosteredPeople;
use Uhifadhi\Roster\Service\RosterIdentityService;
use Uhifadhi\Roster\Service\RosterSettingsService;
use Uhifadhi\Roster\Service\RotationEditor;
use Uhifadhi\Roster\Service\RotationGenerator;
use Uhifadhi\Roster\Service\RotationPreview;
use Uhifadhi\Roster\Service\SheetDayService;
use Uhifadhi\Roster\Service\SheetFillService;
use Uhifadhi\Roster\Service\SheetPreferences;
use Uhifadhi\Roster\Service\SheetService;
use Uhifadhi\Roster\Service\ShiftRuleService;
use Uhifadhi\Roster\Service\ShiftVocabularyService;
use Uhifadhi\Roster\Service\StationWatchService;
use Uhifadhi\Roster\Service\SwapCostService;
use Uhifadhi\Roster\Service\SwapService;
use Uhifadhi\Roster\Service\WeekGridService;
use Uhifadhi\Roster\Twig\RosterTrailExtension;

/*
 * The bundle's static service wiring.
 *
 * PHP (not YAML) on purpose: a reusable bundle must not force symfony/yaml
 * onto installations, and FQCN references stay refactor-safe and
 * phpstan-checked. Imported by UhifadhiRosterBundle::loadExtension(), which
 * keeps only the config-DRIVEN definitions — the parameters below are read
 * there, and the controllers are registered there because they sit behind the
 * SecurityBundle guard.
 *
 * Everything defined here is defined EXPLICITLY — no autowire(), no
 * autoconfigure(), and ids prefixed with the bundle alias — because this
 * bundle is installed by other projects via Composer, which is what Symfony
 * calls a reusable bundle:
 *
 *   "Services should not use autowiring or autoconfiguration. Instead, all
 *    services should be defined explicitly."
 *   "If the bundle defines services, they must be prefixed with the bundle
 *    alias."
 *   — https://symfony.com/doc/current/bundles/best_practices.html
 *
 * REPOSITORIES ARE THE ONE EXCEPTION TO THE PREFIX, and it is not a style
 * choice: ServiceRepositoryCompilerPass keys its locator by SERVICE ID while
 * ContainerRepositoryFactory looks a repository up by CLASS NAME, so a
 * prefixed repository id is a repository Doctrine cannot find.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    foreach ([
        ShiftRepository::class,
        RotationRepository::class,
        RotationPoolMemberRepository::class,
        DutyRepository::class,
        EditedDayRepository::class,
        SheetPreferenceRepository::class,
        AbsenceRepository::class,
        StationWatchRepository::class,
        AreaRosterSettingsRepository::class,
        SwapRepository::class,
        PatternRepository::class,
        ShiftRuleRepository::class,
        StationRuleExceptionRepository::class,
    ] as $repository) {
        $services->set($repository)
            ->args([service('doctrine')])
            ->tag('doctrine.repository_service');
    }

    // THE ROTATION ALGEBRA. No collaborators at all, which is the point: what
    // a ring says is a calculation, and a calculation that needed a database
    // could not be unit tested against the shapes a park actually runs.
    $services->set('roster.cycle_planner', CyclePlanner::class);

    // THE HALF THAT WRITES ROWS. Everything it needs is stated; nothing is
    // discovered.
    $services->set('roster.rotation_generator', RotationGenerator::class)
        ->args([
            service('doctrine.orm.entity_manager'),
            service('roster.cycle_planner'),
            service(ShiftRepository::class),
            service(RotationPoolMemberRepository::class),
            service(DutyRepository::class),
            service(EditedDayRepository::class),
            service(AbsenceRepository::class),
        ]);

    // WHAT THE AREA RUNS ON. Created from the installation's starting values
    // on first ask, and never read from config again once the row exists.
    // The ping interval is the AREA's, asked of the area bundle's own reader.
    $services->set('roster.settings', RosterSettingsService::class)
        ->args([
            service('doctrine.orm.entity_manager'),
            service(AreaRosterSettingsRepository::class),
            service('area.ping_interval'),
            param('roster.default_catchment_metres'),
        ]);

    // THE AREA'S ONE LIST OF NAMED SHIFTS, seeded from the configured
    // vocabulary the first time anybody asks for it.
    $services->set('roster.shift_vocabulary', ShiftVocabularyService::class)
        ->args([
            service('doctrine.orm.entity_manager'),
            service(ShiftRepository::class),
            service(DutyRepository::class),
            param('roster.shifts'),
        ]);

    // THE POSTS ON THIS MODULE'S BOOKS. No create-on-read, unlike the
    // settings row: a post is given a watch deliberately.
    $services->set('roster.station_watches', StationWatchService::class)
        ->args([
            service('doctrine.orm.entity_manager'),
            service(StationWatchRepository::class),
            service(RotationRepository::class),
            service('roster.settings'),
            service(StationService::class),
            param('roster.default_silence_window_minutes'),
            param('roster.default_offline_after_minutes'),
        ]);

    /*
     * THE AREA'S RULES AND WHAT EACH STATION DOES DIFFERENTLY — and the one
     * writer of the three columns the live surfaces still read.
     */
    $services->set('roster.shift_rules', ShiftRuleService::class)
        ->args([
            service('doctrine.orm.entity_manager'),
            service(ShiftRuleRepository::class),
            service(StationRuleExceptionRepository::class),
            service(StationWatchRepository::class),
            service(StationService::class),
            service('roster.settings'),
        ]);

    /*
     * THE CYCLES AN AREA FILLS FROM. It takes the vocabulary because a
     * pattern's NAME is derived from this area's own shift names on every
     * read — there is no name column and nobody types one.
     */
    $services->set('roster.patterns', PatternService::class)
        ->args([
            service('doctrine.orm.entity_manager'),
            service(PatternRepository::class),
            service(StationWatchRepository::class),
            service('roster.shift_vocabulary'),
        ]);

    /*
     * WHO IS ACTUALLY ON — read from the AREA, never computed here.
     *
     * PresenceProviderInterface is not a seam this module implements: the
     * area publishes exactly one implementation and a module type-hints the
     * interface and is wired to it by name. Asking for it by the INTERFACE
     * is what keeps that true — a module reaching for `area.presence`
     * directly would be a module that had learned the area's service ids.
     */
    $services->set('roster.presence', PresenceReader::class)
        ->args([
            service(PresenceProviderInterface::class),
            service(DutyRepository::class),
            service(ShiftRepository::class),
            service(StationWatchRepository::class),
            service(RotationRepository::class),
        ]);

    /*
     * OFFERING A WATCH, AND WHAT AN ACCEPTANCE DOES. Offering moves nobody;
     * accepting moves the duties AND marks both days edited, so the nightly
     * generator does not quietly rebuild them from the ring and undo an
     * agreement two people made.
     */
    $services->set('roster.swaps', SwapService::class)
        ->args([
            service('doctrine.orm.entity_manager'),
            service(SwapRepository::class),
            // The by-hand mark is the sheet's own rule, and there is one of
            // it: an accepted swap changes two people's days exactly as the
            // sheet's menu does.
            service('roster.sheet_day'),
            service(DutyRepository::class),
            service('roster.rostered_people'),
        ]);

    // WHAT A TRADE COSTS — every check stated, none enforced. A duty officer
    // may knowingly send an offer that breaks the rest rule; what the page
    // must never do is send one quietly.
    $services->set('roster.swap_cost', SwapCostService::class)
        ->args([
            service(DutyRepository::class),
            service(ShiftRepository::class),
            service(RotationRepository::class),
            service(AbsenceRepository::class),
        ]);

    /*
     * THE PLANNER'S GRID. Two queries for a whole week rather than one per
     * cell: seven days across six posts is forty-two cells, and asking per
     * cell is how a planner's tab ends up slower than the month it plans.
     */
    $services->set('roster.week_grid', WeekGridService::class)
        ->args([
            service(StationWatchRepository::class),
            service(DutyRepository::class),
            service(RotationRepository::class),
            service(ShiftRepository::class),
        ]);

    /*
     * THE PLANNING SHEET. Four queries for the whole window whatever its
     * size: thirty-four rangers over twenty-eight days is 952 cells, and
     * anything asked per cell is asked 952 times.
     */
    $services->set('roster.sheet', SheetService::class)
        ->args([
            service('Uhifadhi\Bundle\AreaBundle\Repository\StationRepository'),
            service('Uhifadhi\Bundle\AreaBundle\Repository\PostingRepository'),
            service(StationWatchRepository::class),
            service(DutyRepository::class),
            service(EditedDayRepository::class),
            // THE VOCABULARY AND NOT THE REPOSITORY: the list is seeded on
            // first ask, and the sheet is the first thing a new area opens.
            service('roster.shift_vocabulary'),
        ]);

    /*
     * AND FILLING IT FROM A PATTERN. It obeys the four rules the Watches
     * card sets, never touches a day somebody edited, and never writes
     * the past — a fill is a plan, and rewriting a watch already stood
     * would be rewriting a record of what happened.
     */
    $services->set('roster.sheet_fill', SheetFillService::class)
        ->args([
            service('doctrine.orm.entity_manager'),
            service('Uhifadhi\Bundle\AreaBundle\Repository\PostingRepository'),
            service(StationWatchRepository::class),
            service(DutyRepository::class),
            service(EditedDayRepository::class),
            service(ShiftRepository::class),
            service('roster.shift_rules'),
            service('clock'),
        ]);

    /*
     * HOW ONE PERSON LIKES THE SHEET. Nobody signed in answers the
     * standard rather than refusing: a preference is a convenience, and
     * a page that would not render without one would be a page held
     * hostage by a nicety.
     */
    $services->set('roster.sheet_preferences', SheetPreferences::class)
        ->args([service('doctrine.orm.entity_manager'), service(SheetPreferenceRepository::class)]);

    /*
     * AND ONE DAY CHANGED BY HAND. Every item on the by-hand menu is a
     * verb here and not in a controller, because every one of them
     * leaves the mark that stops the fill deciding the day again — and a
     * mark left off by one of five call sites is a day quietly
     * overwritten the next night.
     */
    $services->set('roster.sheet_day', SheetDayService::class)
        ->args([service('doctrine.orm.entity_manager'), service(EditedDayRepository::class), service('clock')]);

    /*
     * THE DAY AS A WALL. It reads two days, not one: a night watch that
     * began yesterday is still standing at 05:00 this morning, and a board
     * that only read today would draw an empty gate for the hours somebody
     * was actually on it.
     */
    $services->set('roster.day_board', DayBoardService::class)
        ->args([service(DutyRepository::class), service(ShiftRepository::class)]);

    /*
     * ONE RANGER'S MONTH, fed to the HOUSE calendar. No tag and no
     * collection: a surface NAMES the feed it wants, exactly as it names a
     * plate's subject, because a month of watches and a month of patrols are
     * different pages and not one page that merged them.
     */
    $services->set('roster.calendar', RosterCalendar::class)
        ->args([
            service(DutyRepository::class),
            service(ShiftRepository::class),
            service(PresenceProviderInterface::class),
            service(AreaOfInterestRepository::class),
        ]);

    // Who the calendar's ranger picker offers: the people this area's
    // rotations actually draw from, which is not the payroll and not the
    // postings.
    $services->set('roster.rostered_people', RosteredPeople::class)
        ->args([
            service(RotationRepository::class),
            service(RotationPoolMemberRepository::class),
            service('Uhifadhi\Bundle\AreaBundle\Repository\PostingRepository'),
        ]);

    // THE ONE WRITE THE CYCLE EDITOR MAKES. It applies a whole draft and
    // does NOT generate: correcting a typo in a ring must not rewrite six
    // weeks of duties on the spot.
    $services->set('roster.rotation_editor', RotationEditor::class)
        ->args([service('doctrine.orm.entity_manager'), service(RotationPoolMemberRepository::class), service(RotationRepository::class)]);

    // THE MONTH A RING WOULD PRODUCE, through the same pure planner the
    // generator runs — so a preview cannot disagree with the result, and it
    // writes nothing at all.
    $services->set('roster.rotation_preview', RotationPreview::class)
        ->args([service(RotationRepository::class), service(ShiftRepository::class), service('roster.cycle_planner')]);

    /*
     * THE FORTNIGHT ITSELF IS NOT A SERVICE. What was registered here was
     * the week tab's old grid; the tab draws the sheet now, and what is
     * left of RotaService is the monday-anchored window as two static
     * members, which nothing has to be handed.
     */

    // `roster_url()` — the URL of a screen, or null where the installation did
    // not mount it. Twig's own path() THROWS on an unregistered route, so a
    // breadcrumb naming the area's screens is a breadcrumb that takes the
    // whole page down in an installation that mounted one screen fewer.
    $services->set('roster.twig.trail', RosterTrailExtension::class)
        ->args([service('router')])
        ->tag('twig.extension');

    // THE IDENTITY BAND'S FIGURES. It reads the AREA's station and posting
    // repositories by their published classes — a module may type-hint the
    // platform it requires; the platform never names the module.
    $services->set('roster.identity', RosterIdentityService::class)
        ->args([
            service(StationWatchRepository::class),
            service('Uhifadhi\Bundle\AreaBundle\Repository\StationRepository'),
            service('Uhifadhi\Bundle\AreaBundle\Repository\PostingRepository'),
            service(RotationRepository::class),
            service(StationRuleExceptionRepository::class),
            service('roster.shift_vocabulary'),
            service('roster.settings'),
        ]);
};
