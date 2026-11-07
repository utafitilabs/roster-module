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
 * THE PLANNING SHEET — people down, days across, per station.
 *
 * ONE READ FOR THE WHOLE WINDOW. Thirty-four rangers over twenty-eight
 * days is 952 cells, and asking per cell is how a planner's tab ends up
 * slower than the month it plans.
 *
 * ITS OWN FIGURES COME OFF ITS OWN CELLS. The band above the sheet counts
 * what the picture under it draws, so a reader can check one against the
 * other — a strip that counted a different window would be a strip
 * nobody could check at all.
 */
final readonly class Sheet
{
    /**
     * @param list<SheetBand> $bands
     */
    public function __construct(
        public SheetWindow $window,
        public array $bands,
        /** Every station on the area's books, whether anybody is stationed there or not. */
        public int $stations,
        /** The last day any watch stands for the area — the fill horizon, which may run past the window. */
        public ?\DateTimeImmutable $filledTo = null,
    ) {
    }

    public function rangers(): int
    {
        $rangers = 0;
        foreach ($this->bands as $band) {
            $rangers += $band->rangers();
        }

        return $rangers;
    }

    /** STATION-DAYS UNDER THE NUMBER — the window's one figure for short cover. */
    public function shortCover(): int
    {
        $short = 0;
        foreach ($this->bands as $band) {
            $short += $band->shortDays();
        }

        return $short;
    }

    public function editedByHand(): int
    {
        $edited = 0;
        foreach ($this->bands as $band) {
            foreach ($band->rows as $row) {
                $edited += $row->editedByHand();
            }
        }

        return $edited;
    }

    /** How many watches the window actually holds — "days planned". */
    public function daysPlanned(): int
    {
        $planned = 0;
        foreach ($this->bands as $band) {
            foreach ($band->rows as $row) {
                foreach ($row->cells as $cell) {
                    if (SheetCellKind::Watch === $cell->kind) {
                        ++$planned;
                    }
                }
            }
        }

        return $planned;
    }

    /**
     * THE SAME SHEET, NARROWED TO ONE STATION — the head's filter, applied
     * to the read rather than asked of the database again.
     *
     * THE STATION COUNT DOES NOT MOVE. It is the area's, so the chip can
     * go on saying what it is filtering out of; a filter that renamed the
     * whole to the part would be a filter nobody could undo.
     */
    public function only(string $stationUuid): self
    {
        $bands = [];
        foreach ($this->bands as $band) {
            if ($band->stationUuid === $stationUuid) {
                $bands[] = $band;
            }
        }

        return new self($this->window, $bands, $this->stations);
    }

    /**
     * THE SHEET NARROWED TO THE RANGERS A SEARCH NAMES, by name or call
     * sign. A station none of whose rangers match leaves the sheet; the
     * station count stays the area's, as it does under {@see self::only()}.
     */
    public function matching(?string $search): self
    {
        if (null === $search || '' === trim($search)) {
            return $this;
        }

        $needle = mb_strtolower(trim($search));
        $bands = [];
        foreach ($this->bands as $band) {
            $rows = array_values(array_filter(
                $band->rows,
                static fn (SheetRow $row): bool => str_contains(mb_strtolower($row->personName.' '.$row->stationCode), $needle),
            ));
            if ([] !== $rows) {
                $bands[] = new SheetBand($band->stationUuid, $band->stationName, $band->stationCode, $rows, $band->cover);
            }
        }

        return new self($this->window, $bands, $this->stations, $this->filledTo);
    }

    /**
     * Every ranger row, band after band: what the register foot pages.
     *
     * @return list<SheetRow>
     */
    public function rows(): array
    {
        $rows = [];
        foreach ($this->bands as $band) {
            array_push($rows, ...$band->rows);
        }

        return $rows;
    }

    /**
     * THE SHEET NARROWED TO ONE PAGE'S ROWS, each band over the rows of it
     * the page holds. A band with nobody stationed goes on the page that
     * holds the row before it, so every station is on exactly one page.
     *
     * @param list<SheetRow> $rows this page's, taken from {@see self::rows()}
     */
    public function keeping(array $rows, bool $firstPage): self
    {
        $bands = [];
        $previousKept = $firstPage;
        foreach ($this->bands as $band) {
            if ([] === $band->rows) {
                if ($previousKept) {
                    $bands[] = $band;
                }
                continue;
            }
            $kept = array_values(array_filter($band->rows, static fn (SheetRow $row): bool => \in_array($row, $rows, true)));
            if ([] !== $kept) {
                $bands[] = new SheetBand($band->stationUuid, $band->stationName, $band->stationCode, $kept, $band->cover);
            }
            $last = $band->rows[\count($band->rows) - 1];
            $previousKept = \in_array($last, $rows, true);
        }

        return new self($this->window, $bands, $this->stations, $this->filledTo);
    }

    public function isEmpty(): bool
    {
        return [] === $this->bands;
    }
}
