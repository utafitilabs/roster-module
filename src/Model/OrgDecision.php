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

namespace Uhifadhi\Roster\Model;

/**
 * A DECISION, WITH THE AREA IT IS IN.
 *
 * ORDERED BY THE QUESTION, NEVER BY THE AREA. A missing check-in in one
 * park outranks a late one in another, and a list grouped by area would
 * make a reader open four groups to find the loudest thing in the
 * organization — which is the one thing this page exists to show.
 */
final readonly class OrgDecision
{
    public function __construct(
        public Decision $decision,
        public string $areaName,
        /** The area's place in the declared order; the shell makes it a swatch. */
        public int $position,
    ) {
    }
}
