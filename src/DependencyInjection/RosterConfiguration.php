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

namespace Uhifadhi\Roster\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\NodeDefinition;
use Uhifadhi\Roster\Entity\Shift;
use Uhifadhi\Roster\Model\Cycle;

/**
 * THE BUNDLE'S SEMANTIC CONFIGURATION — what an installation writes in
 * config/packages/roster.yaml, and nothing that belongs to an AREA.
 *
 * The line between the two is the one that matters here, because both look
 * like settings from a distance:
 *
 *   AN INSTALLATION'S, and therefore in this tree: nothing but STARTING
 *   VALUES. The shift vocabulary a new area's list is seeded with, and the
 *   numbers a new station watch or a new rotation is created with.
 *
 *   AN AREA'S, and therefore NOT here: the shift list the area actually runs,
 *   what any individual station expects, how long its silence may run, how
 *   wide its catchment is, and how often the handset pings. Those are edited
 *   on a screen by somebody who runs the park, not by whoever last deployed
 *   it.
 *
 * SO `shifts` SEEDS A LIST, IT IS NOT THE LIST. Ruled: "one list for the whole
 * area", edited on the Configure page's Settings section, where a shift in use
 * cannot be deleted, only closed — which is a lifecycle, and a lifecycle needs
 * rows. {@see Shift} is the running vocabulary; this
 * is the four windows an area starts with so that nobody has to invent a day
 * shift before they can write a rotation.
 *
 * NAMED SHIFTS ARE RULED (grammar 3, provisionally): a duty is stood in one of
 * a named set of windows, and a station declares which of them it runs. The
 * four below are the vocabulary the design is drawn in. They are a DEFAULT and
 * not a truth about anybody's park: whether a gate really runs two twelves is
 * an operational fact the deployment owns, which is exactly why it is config.
 *
 * A window may CROSS MIDNIGHT — `night` runs 18:00 to 06:00 — and that is not
 * a mistake to be validated away. A duty belongs to the calendar day its watch
 * BEGINS on, so a night watch is one duty and two blocks on a day board.
 *
 * Static so the tree is testable with a plain Processor and shared verbatim by
 * the bundle's configure().
 */
final class RosterConfiguration
{
    /**
     * THE SHIFT VOCABULARY A NEW AREA IS SEEDED WITH — the four named windows
     * every surface in the design is drawn in.
     *
     * `radio` is a real fourth shift rather than a note on `night`: a
     * headquarters stands a radio watch overnight, and the day board, the
     * rotation and the week grid all have to be able to say which of the two
     * a person is on.
     *
     * A LABEL IS PRINTED AS IT WAS TYPED, everywhere — a pattern's derived
     * name says "2 days of day" because the word IS "day". The product never
     * capitalises, pluralises or otherwise edits a shift name: the words are
     * the area's own and it does not know one from another.
     *
     * AND EACH ONE OPENS ON ITS OWN PALETTE SLOT. A shift is given a colour
     * when it is created and keeps it on every tab; four shifts seeded on one
     * slot would paint a whole fortnight in one hue, which is what made the
     * sheet unreadable. The slots are spread rather than consecutive so that
     * two shifts read as different at a glance, not as two steps of one ramp.
     *
     * @var list<array{key: string, label: string, start: string, end: string, colour: int}>
     */
    public const array DEFAULT_SHIFTS = [
        ['key' => 'day', 'label' => 'day', 'start' => '06:00', 'end' => '18:00', 'colour' => 1],
        ['key' => 'night', 'label' => 'night', 'start' => '18:00', 'end' => '06:00', 'colour' => 5],
        ['key' => 'office', 'label' => 'office', 'start' => '07:30', 'end' => '16:30', 'colour' => 3],
        ['key' => 'radio', 'label' => 'radio night', 'start' => '18:00', 'end' => '06:00', 'colour' => 8],
    ];

    /**
     * THE VALUE THE DEPRECATED `defaults.ping_interval_minutes` KEY DEFAULTS
     * TO. Nothing reads it: the ping interval is the area's, set on its Area
     * settings. The key and this constant go in the next release.
     */
    public const int DEFAULT_PING_INTERVAL_MINUTES = 30;

    /**
     * HOW LONG A STATION MAY GO QUIET BEFORE IT READS AS LATE, in minutes.
     * Per station in the product (a gate that never closes and a rim post
     * reached once a fortnight cannot share one); this is what a station's
     * watch is created with.
     */
    public const int DEFAULT_SILENCE_WINDOW_MINUTES = 120;

    /**
     * HOW LONG BEFORE LATE BECOMES OFFLINE, in minutes. Offline is a fact
     * about the post and not about its people, so the threshold is the
     * station's own and this is only where a new one starts.
     */
    public const int DEFAULT_OFFLINE_AFTER_MINUTES = 1440;

    /**
     * HOW CLOSE A PING HAS TO BE FOR A CLAIM OF "at post" TO READ AS VERIFIED,
     * in metres. Per station in the product; this is the starting radius.
     *
     * It never hides anything: a claim whose pings fall outside it is SHOWN
     * AND FLAGGED as unverified, never corrected and never turned into an
     * absence.
     */
    public const int DEFAULT_CATCHMENT_METRES = 1500;

    /**
     * HOW FAR AHEAD A NEW ROTATION GENERATES DUTIES, in days. Per rotation in
     * the product — a rotation carries its own horizon — and this is the value
     * the editor offers when one is created.
     */
    public const int DEFAULT_HORIZON_DAYS = 42;

    public static function define(NodeDefinition|ArrayNodeDefinition $root): void
    {
        if (!$root instanceof ArrayNodeDefinition) {
            throw new \LogicException('The roster root node must be an array node.');
        }

        $root
            ->children()
                ->scalarNode('module_category')
                    ->info('Catalogue category the Roster module is filed under in each area.')
                    ->defaultValue('operations')->cannotBeEmpty()
                ->end()
                ->booleanNode('dev_tools')
                    ->info('Register dev-only tooling. The recipe enables this via when@dev/when@test.')
                    ->defaultFalse()
                ->end()
                ->arrayNode('shifts')
                    ->info('The named windows a NEW AREA\'s shift list is seeded with. The list an area runs is edited on its Configure page; a window may cross midnight.')
                    ->defaultValue(self::DEFAULT_SHIFTS)
                    ->requiresAtLeastOneElement()
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('key')
                                ->info('Stable identifier stored on every duty. Lowercase letters, digits and underscores.')
                                ->isRequired()->cannotBeEmpty()
                                ->validate()
                                    ->ifTrue(static fn (mixed $v): bool => !\is_string($v) || 1 !== preg_match('/^[a-z][a-z0-9_]*$/', $v))
                                    ->thenInvalid('A shift key is lowercase letters, digits and underscores, starting with a letter; got %s.')
                                ->end()
                                // "off" IS THE WORD A ROTATION'S RING USES FOR
                                // A STOOD-DOWN DAY, so a shift called "off"
                                // would make a stored ring ambiguous about
                                // whether somebody is on the off-watch or not
                                // working at all — and no later screen could
                                // recover the answer.
                                ->validate()
                                    ->ifTrue(static fn (mixed $v): bool => Cycle::OFF === $v)
                                    ->thenInvalid('"off" is reserved: a rotation\'s ring spells a stood-down day that way, so a shift of that name would make every stored cycle ambiguous. Call it something else.')
                                ->end()
                            ->end()
                            ->scalarNode('label')
                                ->info('What the shift is called on every surface.')
                                ->isRequired()->cannotBeEmpty()
                            ->end()
                            ->scalarNode('start')
                                ->info('When the window opens, as HH:MM in the area\'s own clock.')
                                ->isRequired()
                                ->validate()
                                    ->ifTrue(self::notAClockTime(...))
                                    ->thenInvalid('A shift starts at an HH:MM clock time; got %s.')
                                ->end()
                            ->end()
                            ->scalarNode('end')
                                ->info('When the window closes, as HH:MM. Earlier than start means the window crosses midnight.')
                                ->isRequired()
                                ->validate()
                                    ->ifTrue(self::notAClockTime(...))
                                    ->thenInvalid('A shift ends at an HH:MM clock time; got %s.')
                                ->end()
                            ->end()
                            ->integerNode('colour')
                                ->info('The palette slot the shift opens on, 1 to 18. It is the shift\'s from then on and is changed on the Configure page, never here.')
                                ->min(Shift::FIRST_SLOT)->max(Shift::SLOTS)
                                ->defaultValue(Shift::FIRST_SLOT)
                            ->end()
                        ->end()
                    ->end()
                    ->validate()
                        ->ifTrue(self::hasDuplicateKeys(...))
                        ->thenInvalid('Two shifts share a key. A duty stores the key, so a duplicate makes a stored duty ambiguous: %s')
                    ->end()
                ->end()
                ->arrayNode('defaults')
                    ->info('The values a new area setting, station watch or rotation starts at. Every one of them is edited per area, per station or per rotation afterwards — nothing here is read at display time.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        /*
                         * ACCEPTED AND INERT FOR ONE RELEASE. The interval is
                         * the area's; an installation's own roster.yaml may
                         * still set this, so it is deprecated rather than
                         * removed, and deleted in the next release. Symfony
                         * triggers the notice only where the key is set:
                         * https://symfony.com/doc/current/components/config/definition.html#deprecating-the-option
                         * vendor/symfony/config/Definition/ArrayNode.php finalizeValue().
                         */
                        ->integerNode('ping_interval_minutes')
                            ->info('Deprecated and read by nothing: the ping interval is the area\'s, set on its Area settings.')
                            ->setDeprecated('uhifadhi/roster-module', '0.1.2', '"roster.defaults.ping_interval_minutes" is read by nothing: the ping interval is the area\'s, set on its Area settings. Remove the key; it goes in the next release.')
                            ->min(1)->defaultValue(self::DEFAULT_PING_INTERVAL_MINUTES)
                        ->end()
                        ->integerNode('silence_window_minutes')
                            ->info('How long a station may go quiet before it reads as late.')
                            ->min(1)->defaultValue(self::DEFAULT_SILENCE_WINDOW_MINUTES)
                        ->end()
                        ->integerNode('offline_after_minutes')
                            ->info('How long before late becomes offline.')
                            ->min(1)->defaultValue(self::DEFAULT_OFFLINE_AFTER_MINUTES)
                        ->end()
                        ->integerNode('catchment_metres')
                            ->info('How close a ping has to be for a claim of "at post" to read as verified.')
                            ->min(1)->defaultValue(self::DEFAULT_CATCHMENT_METRES)
                        ->end()
                        ->integerNode('horizon_days')
                            ->info('How far ahead a new rotation generates duties.')
                            ->min(1)->defaultValue(self::DEFAULT_HORIZON_DAYS)
                        ->end()
                    ->end()
                    ->validate()
                        ->ifTrue(static fn (mixed $v): bool => \is_array($v)
                            && \is_int($v['silence_window_minutes'] ?? null)
                            && \is_int($v['offline_after_minutes'] ?? null)
                            && $v['offline_after_minutes'] <= $v['silence_window_minutes'])
                        ->thenInvalid('Offline has to come after late, or a station would go straight from reporting to offline and "late" would name nothing: %s')
                    ->end()
                ->end()
            ->end()
        ;
    }

    /**
     * WHETHER A VALUE IS NOT AN HH:MM CLOCK TIME. Phrased in the negative
     * because that is the question `ifTrue()` asks, and inverting it at the
     * call site reads as a double negative in a place nobody looks twice.
     */
    private static function notAClockTime(mixed $value): bool
    {
        return !\is_string($value) || 1 !== preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $value);
    }

    /**
     * WHETHER TWO SHIFTS SHARE A KEY. A duty stores the key and nothing else,
     * so a duplicate is not untidy — it makes a stored row ambiguous about
     * which window it was stood in, and no later screen can recover the answer.
     */
    private static function hasDuplicateKeys(mixed $shifts): bool
    {
        if (!\is_array($shifts)) {
            return false;
        }

        $keys = [];
        foreach ($shifts as $shift) {
            if (\is_array($shift) && \is_string($shift['key'] ?? null)) {
                $keys[] = $shift['key'];
            }
        }

        return \count($keys) !== \count(array_unique($keys));
    }
}
