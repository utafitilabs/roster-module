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

use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Bundle\AreaBundle\Enum\PostingSource;
use Uhifadhi\Bundle\AreaBundle\Service\PostingService;
use Uhifadhi\Bundle\RegistryBundle\Service\AreaModuleService;
use Uhifadhi\Bundle\RegistryBundle\Service\RegistrySyncService;
use Uhifadhi\Bundle\ShellBundle\Widget\Entity\WidgetCustomPreset;
use Uhifadhi\Bundle\ShellBundle\Widget\Model\WidgetDom;
use Uhifadhi\Bundle\ShellBundle\Widget\Service\WidgetEndpoint;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Roster\Controller\RosterConfigureController;
use Uhifadhi\Roster\Controller\RosterController;
use Uhifadhi\Roster\Entity\Duty;
use Uhifadhi\Roster\Enum\RotationPreset;
use Uhifadhi\Roster\Model\Cycle;
use Uhifadhi\Roster\Module\RosterModuleProvider;
use Uhifadhi\Roster\Service\PatternService;
use Uhifadhi\Roster\Service\RotationEditor;
use Uhifadhi\Roster\Service\ShiftVocabularyService;
use Uhifadhi\Roster\Service\StationWatchService;
use Uhifadhi\Roster\Service\SwapService;
use Uhifadhi\Roster\Tests\Functional\Authority\AuthorityKernel;
use Uhifadhi\Roster\Widget\RosterWidgets;
use Uhifadhi\Test\Authority\Probe;
use Uhifadhi\Test\Authority\World;
use Uhifadhi\Test\AuthorityTableTestCase;

/**
 * THE ROSTER'S AUTHORITY TABLE: every route this module mounts, called as
 * every kind of person, against the reviewed table beside it.
 *
 *     UHIFADHI_RECORD_AUTHORITY_TABLE=1 vendor/bin/phpunit tests/Functional/AuthorityTableTest.php
 *
 * The records the probes act on are Kilimani's: the world's station on the
 * roster's books with a pattern and a rotation, the colleague and a second
 * ranger posted there, a watch each, and an open offer of one to the other.
 */
#[CoversNothing]
final class AuthorityTableTest extends AuthorityTableTestCase
{
    /** The second ranger posted at the world's station, so a watch has somebody to be offered to. */
    private const string SECOND_RANGER = 'zawadi.mushi@unr.example';

    private string $postOffTheBooks = '';
    private string $pattern = '';
    private string $spare = '';
    private string $rotation = '';
    private string $duty = '';
    private string $swap = '';
    private string $waitingSwap = '';
    private string $shift = '';
    private string $ownPreset = '';
    private string $secondRanger = '';

    protected static function getKernelClass(): string
    {
        return AuthorityKernel::class;
    }

    protected static function tableFile(): string
    {
        return __DIR__.'/authority-table.md';
    }

    protected static function scope(): string
    {
        return 'the roster module';
    }

    protected static function generator(): string
    {
        return 'tests/Functional/AuthorityTableTest.php';
    }

    protected static function controllerNamespace(): string
    {
        return 'Uhifadhi\\Roster\\Controller\\';
    }

    protected function notSavableHere(): array
    {
        return [
            'POST roster_widgets_save' => "it edits the sender's own layout, and nobody here is on one",
        ];
    }

    protected function probes(World $world): array
    {
        $area = ['uuid' => $world->kilimani];
        $week = RosterController::CSRF_TOKEN_ID;
        $configure = RosterConfigureController::CSRF_TOKEN_ID;
        $widgets = WidgetEndpoint::csrfTokenId(RosterWidgets::SURFACE, Uuid::fromString($world->kilimani));
        $surface = $area + ['surface' => RosterWidgets::SURFACE];
        $mine = $surface + ['presetUuid' => $this->ownPreset];
        $monday = new \DateTimeImmutable('monday this week')->format('Y-m-d');
        $tomorrow = new \DateTimeImmutable('tomorrow')->format('Y-m-d');

        return [
            Probe::get('roster_org_overview'),
            Probe::get('roster_org_today'),
            Probe::get('roster_org_live'),
            Probe::get('roster_me'),

            Probe::get('roster_overview', $area),
            Probe::get('roster_week', $area),
            Probe::get('roster_today', $area),
            Probe::get('roster_board', $area),
            Probe::get('roster_calendar', $area),
            Probe::get('roster_live', $area),
            Probe::get('roster_plan', $area),

            Probe::post('roster_sheet_prefs', $area, $week, ['weeks' => '2']),
            Probe::post('roster_sheet_fill', $area, $week, ['station' => $world->station, 'pattern' => $this->pattern, 'from' => $monday]),
            Probe::post('roster_sheet_day', $area, $week, ['duty' => $this->duty, 'op' => 'shift', 'shift' => 'night']),
            Probe::post('roster_sheet_clear_mark', $area, $week, ['station' => $world->station, 'day' => $monday, 'person' => $world->member]),
            Probe::post('roster_swap_offer', $area, $week, ['duty' => $this->duty, 'taking' => $this->secondRanger]),
            Probe::post('roster_swap_withdraw', $area, $week, ['swap' => $this->swap]),
            Probe::post('roster_approval_decide', $area + ['item' => $this->waitingSwap, 'decision' => 'refuse'], $week),
            Probe::post('roster_plan_publish', $area, $week, ['day' => $tomorrow, RosterController::slotField($world->station, 'day') => [$world->member]]),

            Probe::get('roster_configure_rotation', $area),
            Probe::get('roster_configure_watches', $area),
            Probe::get('roster_configure_settings', $area),
            Probe::get('roster_configure_patterns', $area),
            Probe::post('roster_configure_watches_add', $area, $configure, ['station' => $this->postOffTheBooks]),
            Probe::post('roster_configure_rotation_declare', $area, $configure, ['station' => $world->station, 'preset' => RotationPreset::OneOfEachThenOff->value]),
            Probe::post('roster_configure_rules_save', $area, $configure),
            Probe::post('roster_configure_shift_add', $area, $configure),
            Probe::post('roster_configure_shift_close', $area, $configure, ['close' => $this->shift]),
            Probe::post('roster_configure_rotation_save', $area + ['rotation' => $this->rotation], $configure, ['draft' => $this->draft($world)]),
            Probe::post('roster_configure_rotation_generate', $area + ['rotation' => $this->rotation], $configure),
            Probe::post('roster_configure_watches_save', $area, $configure),
            Probe::post('roster_configure_approval', $area + ['station' => $world->station], $configure, ['required' => '0']),
            Probe::post('roster_configure_pattern_save', $area, $configure, ['cycle' => '[{"days":2,"shift":"day"},{"days":1,"shift":"off"}]']),
            Probe::post('roster_configure_pattern_delete', $area + ['pattern' => $this->spare], $configure),

            Probe::get('roster_widgets', $area),
            Probe::json('roster_widgets_save', $surface, $widgets, '{"order":[],"widgets":{}}', WidgetDom::CSRF_HEADER),
            Probe::post('roster_widgets_reset', $surface, $widgets),
            Probe::post('roster_widgets_preset', $surface + ['presetId' => 'a'], $widgets),
            Probe::post('roster_widgets_preset_copy', $surface + ['presetId' => 'a'], $widgets, ['name' => 'My morning']),
            Probe::post('roster_widgets_preset_create', $surface, $widgets, ['name' => 'My evening']),
            Probe::post('roster_widgets_preset_apply', $mine, $widgets),
            Probe::post('roster_widgets_preset_rename', $mine, $widgets, ['name' => 'My afternoon']),
            Probe::post('roster_widgets_preset_delete', $mine, $widgets),
        ];
    }

    /**
     * WHAT AN INSTALLATION DOES BEFORE ANYBODY OPENS A ROSTER PAGE — the
     * catalogue reconciled and the module switched on in both areas — and
     * the records the probes act on, made through the module's own services.
     */
    protected function arrange(World $world): void
    {
        $em = $this->em();
        $kilimani = $em->getRepository(AreaOfInterest::class)->findOneBy(['uuid' => $world->kilimani]);
        $station = $em->getRepository(Station::class)->findOneBy(['uuid' => $world->station]);
        $member = $em->getRepository(User::class)->findOneBy(['uuid' => $world->member]);
        self::assertInstanceOf(AreaOfInterest::class, $kilimani);
        self::assertInstanceOf(Station::class, $station);
        self::assertInstanceOf(User::class, $member);

        $this->service(RegistrySyncService::class)->sync();
        foreach ($em->getRepository(AreaOfInterest::class)->findAll() as $area) {
            $this->service(AreaModuleService::class)->install($area, RosterModuleProvider::SLUG);
        }

        $second = new User()->setEmail(self::SECOND_RANGER)->setFirstName('Zawadi')->setLastName('Mushi')->setPassword('x')->setVerified(true);
        $offTheBooks = new Station()->setArea($kilimani)->setName('Bonde Post')->setPoint('{"type":"Point","coordinates":[37.15,-2.85]}');
        $em->persist($second);
        $em->persist($offTheBooks);
        $em->flush();
        $this->service(PostingService::class)->post($station, $second, PostingSource::WrittenHere);

        $shifts = $this->service(ShiftVocabularyService::class)->forArea($kilimani);
        $watches = $this->service(StationWatchService::class);
        $watch = $watches->addToRoster($station);
        $watches->save($watch, ['day'], $watch->getSilenceWindowMinutes(), $watch->getOfflineAfterMinutes());

        $patterns = $this->service(PatternService::class);
        $pattern = $patterns->create($kilimani, Cycle::of(['day', 'day', Cycle::OFF]));
        $spare = $patterns->create($kilimani, Cycle::of(['night', Cycle::OFF]));
        $patterns->applyTo($watch, $pattern);

        $rotation = $this->service(RotationEditor::class)->declareForPost($station, ['day'], RotationPreset::OneOfEachThenOff, [$member, $second]);

        $duty = new Duty($kilimani, $station, $member, 'day', new \DateTimeImmutable('today'));
        $theirs = new Duty($kilimani, $station, $second, 'day', new \DateTimeImmutable('tomorrow'));
        $preset = new WidgetCustomPreset(RosterWidgets::SURFACE, $member, $kilimani->getUuid(), 'Morning');
        $em->persist($duty);
        $em->persist($theirs);
        $em->persist($preset);
        $em->flush();
        $swap = $this->service(SwapService::class)->offer($theirs, $member, $second);

        // A SWAP AGREED AND WAITING FOR THE HEAD (#60), for the probe that
        // decides one: a third day, its offer accepted where nobody approved yet.
        $later = new Duty($kilimani, $station, $second, 'day', new \DateTimeImmutable('+2 days'));
        $em->persist($later);
        $em->flush();
        $waiting = $this->service(SwapService::class)->offer($later, $member, $second)->awaitTheHead(new \DateTimeImmutable());
        $em->flush();

        $this->postOffTheBooks = (string) $offTheBooks->getUuidString();
        $this->pattern = $pattern->getUuid()->toRfc4122();
        $this->spare = $spare->getUuid()->toRfc4122();
        $this->rotation = $rotation->getUuid()->toRfc4122();
        $this->duty = $duty->getUuid()->toRfc4122();
        $this->swap = $swap->getUuid()->toRfc4122();
        $this->waitingSwap = $waiting->getUuid()->toRfc4122();
        $this->shift = $shifts[\count($shifts) - 1]->getUuid()->toRfc4122();
        $this->ownPreset = (string) $preset->getUuidString();
        $this->secondRanger = (string) $second->getUuidString();
    }

    /** The rotation editor's draft, as the page posts it. */
    private function draft(World $world): string
    {
        return json_encode([
            'cycle' => ['day', Cycle::OFF],
            'slots' => ['day' => 1],
            'pool' => [$world->member, $this->secondRanger],
            'standDown' => [],
            'horizonDays' => 28,
            'anchoredOn' => new \DateTimeImmutable('tomorrow')->format('Y-m-d'),
        ], \JSON_THROW_ON_ERROR);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(string $class): object
    {
        $service = static::getContainer()->get('test_public.'.$class);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
