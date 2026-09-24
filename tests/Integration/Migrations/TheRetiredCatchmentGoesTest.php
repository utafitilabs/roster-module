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

namespace Uhifadhi\Roster\Tests\Integration\Migrations;

/**
 * THE SECOND CATCHMENT IS GONE FROM THE DATABASE.
 *
 * ONE DISTANCE, ONE HOME. A post's ring is `station.catchment_m` — the column
 * the area measures a claim of "at post" against and the one its own service
 * writes. `roster_station_watch.catchment_metres` held the same number in a
 * second place; the release before this stopped reading it, and this one takes
 * it away.
 *
 * WHY A TEST AND NOT JUST A MIGRATION. A drop is the one change that cannot be
 * un-shipped, so what it removed is asserted rather than assumed: a later
 * `diff` that quietly re-added the column — because somebody restored the
 * property on the entity — would otherwise pass every other test in this suite.
 *
 * @see \Uhifadhi\Roster\Migrations\Version20260924000000
 */
final class TheRetiredCatchmentGoesTest extends MigrationsTestCase
{
    private const string TABLE = 'roster_station_watch';

    private const string COLUMN = 'catchment_metres';

    public function testTheWatchNoLongerCarriesACatchmentOfItsOwn(): void
    {
        $this->emptyDatabase();
        $this->migrateToLatest();

        self::assertNotContains(
            self::COLUMN,
            $this->columnsOf(self::TABLE),
            'The retired second catchment is still on the watch; the ring is the post\'s.',
        );
    }

    /** The post's own ring is untouched by the drop — that is where the number lives now. */
    public function testThePostKeepsItsRing(): void
    {
        $this->emptyDatabase();
        $this->migrateToLatest();

        self::assertContains('catchment_m', $this->columnsOf('station'));
    }

    /** @return list<string> */
    private function columnsOf(string $table): array
    {
        $names = [];
        foreach ($this->connection->createSchemaManager()->listTableColumns($table) as $column) {
            $names[] = $column->getName();
        }

        return $names;
    }
}
