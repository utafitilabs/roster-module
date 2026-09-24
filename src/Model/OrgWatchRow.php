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
 * ONE STATION'S WATCH TODAY, ANYWHERE IN THE ORGANIZATION — a row of the
 * dashboard's watches cell.
 *
 * IT CARRIES ITS AREA, and that is the only thing this row has that the
 * per-area reading does not. Everything else is the area reading verbatim:
 * the organization's answer is the areas' answers, and a row that recomputed
 * anything would be a second opinion with no way to say which was right.
 *
 * THE READING IS DERIVED AND STORED NOWHERE. A station's state is measured
 * against its own thresholds every time somebody looks, so there is no
 * offline flag to be left set after a handset comes back.
 */
final readonly class OrgWatchRow
{
    public function __construct(
        /** The station's own name, as the area registers it. */
        public string $stationName,
        /** Its call sign, or null where the area issued none. */
        public ?string $stationCode,
        /** Which area it stands in — the column the per-area reading has no need of. */
        public string $areaName,
        /** What its watch asks for, in the register's own words: "day 2 · night 2". */
        public string $watch,
        /** How many people are on it today. */
        public int $onItNow,
        /** Of those, how many the positions bear out. */
        public int $verified,
        /** How many the watch asks for. */
        public int $expected,
        /** How the station itself reads — reporting, late, offline, or standing no watch. */
        public PostState $state,
        /** Minutes since the newest evidence from anybody here, or null where nothing ever arrived. */
        public ?int $silentFor = null,
    ) {
    }

    /** Whether this station stands a watch at all. */
    public function standsAWatch(): bool
    {
        return PostState::NoWatch !== $this->state;
    }

    /**
     * HOW THE VERIFIED COLUMN READS — the design's three tones, and which
     * one is a judgement about the row rather than a colour choice.
     *
     * Everybody borne out is good; nobody at all on a watch that asked for
     * somebody is a failure; anything between is a warning. A station
     * standing no watch is none of the three: it is never counted, never
     * late and never a hole.
     */
    public function tone(): string
    {
        if (!$this->standsAWatch()) {
            return '';
        }

        if ($this->expected > 0 && 0 === $this->verified) {
            return 'r';
        }

        return $this->verified >= $this->onItNow && $this->onItNow >= $this->expected ? 'g' : 'w';
    }
}
