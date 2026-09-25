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

namespace Uhifadhi\Roster\Enum;

/**
 * WHEN A POST READS AS LATE.
 *
 * LATE IS PER POST and falls back to this, which is why the default is
 * RELATIVE and not a number of hours: a gate pinging every fifteen minutes
 * and a rim post pinging every two hours cannot share one fixed window, and
 * twice the interval is the same sentence for both — "we have missed two".
 *
 * A station may override it with a silence window of its own; this is what a
 * station that sets none falls back to.
 */
enum LateThreshold: string
{
    /** Twice the AREA's ping interval — the number its handsets are told. The default, and relative on purpose. */
    case TwiceTheInterval = 'twice_the_interval';

    case OneHour = 'one_hour';

    case FourHours = 'four_hours';

    public function label(): string
    {
        return match ($this) {
            self::TwiceTheInterval => '2 × interval',
            self::OneHour => '1 h',
            self::FourHours => '4 h',
        };
    }

    /** The window in minutes, given the area's ping interval ({@see \Uhifadhi\Roster\Service\RosterSettingsService::lateAfterMinutes()}). */
    public function minutes(int $pingIntervalMinutes): int
    {
        return match ($this) {
            self::TwiceTheInterval => 2 * $pingIntervalMinutes,
            self::OneHour => 60,
            self::FourHours => 240,
        };
    }
}
