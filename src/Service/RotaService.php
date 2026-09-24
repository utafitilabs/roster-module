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

namespace Uhifadhi\Roster\Service;

/**
 * THE FORTNIGHT EVERY OTHER SURFACE COUNTS IN.
 *
 * WHAT USED TO BE HERE WAS THE WEEK TAB'S GRID, and it is gone: the tab
 * draws THE SHEET now — people down, days across, one band per station,
 * one, two or four weeks at a time — and that is {@see SheetService} with
 * {@see \Uhifadhi\Roster\Model\SheetWindow} for its window. Keeping the
 * older grid beside it would be shipping two answers to one question.
 *
 * WHAT REMAINS IS THE WINDOW ITSELF, because the dashboard, the
 * organization surface and the demo content all still count in the same
 * monday-anchored fortnight and all of them said so by naming this class.
 * A window that began on the day somebody happened to look is not a window
 * two people can compare notes in.
 */
final class RotaService
{
    /** A fortnight. */
    public const int DAYS = 14;

    /** THE MONDAY THE FORTNIGHT STARTS ON. */
    public static function start(\DateTimeImmutable $day): \DateTimeImmutable
    {
        return $day->setTime(0, 0)->modify('monday this week');
    }
}
