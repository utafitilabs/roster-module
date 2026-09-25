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

namespace Uhifadhi\Roster\Tests\Unit\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Uhifadhi\Roster\DependencyInjection\RosterConfiguration;

final class RosterConfigurationTest extends TestCase
{
    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function process(array $config): array
    {
        $builder = new TreeBuilder('roster');
        RosterConfiguration::define($builder->getRootNode());

        /** @var array<string, mixed> $processed */
        $processed = new Processor()->process($builder->buildTree(), ['roster' => $config]);

        return $processed;
    }

    public function testDefaultsFileTheModuleUnderOperationsWithoutDevTools(): void
    {
        $config = $this->process([]);

        self::assertSame('operations', $config['module_category']);
        self::assertFalse($config['dev_tools']);
    }

    public function testADeploymentFilesTheModuleWhereItWants(): void
    {
        self::assertSame('pressure', $this->process(['module_category' => 'pressure'])['module_category']);
    }

    public function testAnEmptyCategoryIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process(['module_category' => '']);
    }

    public function testTheTreeIsClosedToUnknownKeys(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process(['shift_patterns' => ['day' => ['label' => 'Day shift']]]);
    }

    /**
     * THE FOUR NAMED WINDOWS EVERY SURFACE IS DRAWN IN. `radio` is a fourth
     * shift and not a note on `night`, because a headquarters stands a radio
     * watch overnight and the day board has to say which of the two a person
     * is on.
     */
    public function testTheShiftVocabularyDefaultsToTheFourNamedWindows(): void
    {
        $shifts = $this->process([])['shifts'];

        self::assertIsArray($shifts);
        self::assertSame(['day', 'night', 'office', 'radio'], array_column($shifts, 'key'));
        self::assertSame(['06:00', '18:00', '07:30', '18:00'], array_column($shifts, 'start'));
        self::assertSame(['18:00', '06:00', '16:30', '06:00'], array_column($shifts, 'end'));
    }

    /**
     * Whether a gate really runs two twelves is an operational fact a
     * deployment owns, which is exactly why the vocabulary is config: a fifth
     * named window is a line in a yaml file, never a release.
     */
    public function testADeploymentNamesItsOwnShifts(): void
    {
        $shifts = $this->process(['shifts' => [
            ['key' => 'early', 'label' => 'Early', 'start' => '05:00', 'end' => '13:00'],
            ['key' => 'late', 'label' => 'Late', 'start' => '13:00', 'end' => '21:00'],
        ]])['shifts'];

        self::assertIsArray($shifts);
        self::assertSame(['early', 'late'], array_column($shifts, 'key'));
    }

    /**
     * A WINDOW MAY CROSS MIDNIGHT, and that is not a mistake to validate away:
     * a duty belongs to the calendar day its watch BEGINS on, so a night watch
     * is one duty and two blocks on a day board.
     */
    public function testAWindowMayCrossMidnight(): void
    {
        $shifts = $this->process(['shifts' => [
            ['key' => 'tour', 'label' => 'Overnight tour', 'start' => '22:00', 'end' => '04:00'],
        ]])['shifts'];

        self::assertIsArray($shifts);
        // AND A SHIFT THAT NAMES NO SLOT OPENS ON THE FIRST ONE: the
        // palette is the house's eighteen, and the Configure page is
        // where a deployment moves a shift off a slot it shares.
        self::assertSame([['key' => 'tour', 'label' => 'Overnight tour', 'start' => '22:00', 'end' => '04:00', 'colour' => 1]], $shifts);
    }

    public function testAShiftWithoutAClockTimeIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process(['shifts' => [
            ['key' => 'day', 'label' => 'Day', 'start' => 'dawn', 'end' => '18:00'],
        ]]);
    }

    public function testAShiftKeyThatIsNotAnIdentifierIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process(['shifts' => [
            ['key' => 'Night Watch', 'label' => 'Night', 'start' => '18:00', 'end' => '06:00'],
        ]]);
    }

    /**
     * A duty stores the key and nothing else, so two shifts sharing one make a
     * STORED ROW ambiguous about the window it was stood in — and no later
     * screen can recover the answer.
     */
    public function testTwoShiftsSharingAKeyAreRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process(['shifts' => [
            ['key' => 'day', 'label' => 'Day', 'start' => '06:00', 'end' => '18:00'],
            ['key' => 'day', 'label' => 'Long day', 'start' => '06:00', 'end' => '20:00'],
        ]]);
    }

    /**
     * "off" is the word a rotation's ring spells a stood-down day with, so a
     * shift of that name would make every stored cycle ambiguous.
     */
    public function testAShiftCalledOffIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process(['shifts' => [
            ['key' => 'off', 'label' => 'Off watch', 'start' => '18:00', 'end' => '06:00'],
        ]]);
    }

    public function testAnEmptyShiftVocabularyIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process(['shifts' => []]);
    }

    /**
     * The starting values a new area setting, station watch or rotation is
     * created with — never read at display time, where what a station actually
     * runs at is its own stored value.
     */
    public function testTheStartingValuesAreTheDesignsOwn(): void
    {
        $defaults = $this->process([])['defaults'];

        self::assertSame([
            'ping_interval_minutes' => 30,
            'silence_window_minutes' => 120,
            'offline_after_minutes' => 1440,
            'catchment_metres' => 1500,
            'horizon_days' => 42,
        ], $defaults);
    }

    public function testADeploymentOverridesOneStartingValueWithoutRestatingTheRest(): void
    {
        $defaults = $this->process(['defaults' => ['silence_window_minutes' => 90]])['defaults'];

        self::assertIsArray($defaults);
        self::assertSame(90, $defaults['silence_window_minutes']);
        self::assertSame(1440, $defaults['offline_after_minutes']);
    }

    /**
     * THE PING INTERVAL IS THE AREA'S, so this key is accepted and inert for
     * one release and says so when a deployment still sets it.
     */
    public function testSettingThePingIntervalHereIsDeprecated(): void
    {
        $this->expectUserDeprecationMessage('Since uhifadhi/roster-module 0.1.2: "roster.defaults.ping_interval_minutes" is read by nothing: the ping interval is the area\'s, set on its Area settings. Remove the key; it goes in the next release.');

        $defaults = $this->process(['defaults' => ['ping_interval_minutes' => 15]])['defaults'];

        self::assertIsArray($defaults);
        self::assertSame(15, $defaults['ping_interval_minutes']);
    }

    /**
     * Offline has to come after late, or a station would go straight from
     * reporting to offline and "late" would name nothing.
     */
    public function testOfflineComingBeforeLateIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process(['defaults' => [
            'silence_window_minutes' => 1440,
            'offline_after_minutes' => 120,
        ]]);
    }

    public function testAZeroPingIntervalIsRefused(): void
    {
        $this->expectUserDeprecationMessage('Since uhifadhi/roster-module 0.1.2: "roster.defaults.ping_interval_minutes" is read by nothing: the ping interval is the area\'s, set on its Area settings. Remove the key; it goes in the next release.');
        $this->expectException(InvalidConfigurationException::class);

        $this->process(['defaults' => ['ping_interval_minutes' => 0]]);
    }
}
