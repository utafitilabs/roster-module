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

namespace Uhifadhi\Roster\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * THE WATCH'S SECOND CATCHMENT GOES.
 *
 * ONE DISTANCE, ONE HOME. How close a ping has to be for a claim of "at post"
 * to read as verified is `station.catchment_m` — the column the AREA measures
 * against and the one its own service writes. `roster_station_watch` held the
 * same number in a second place, which is two answers the day somebody edits
 * one of them.
 *
 * @destructive Waits for the release that stopped reading it, which shipped in
 *              0.1 with the column left written-and-never-read and the retirement
 *              written up in docs/upgrading.md. This is the release after, and
 *              the column goes with the property that mapped it.
 *
 * THE UNWIND PUTS THE NUMBER BACK FROM WHERE IT REALLY LIVES. A rollback that
 * re-added the column empty would fail on the first installation with a watch
 * in it, so the column comes back nullable, is filled from the post's own ring
 * — the value it would have been kept in step with — and is tightened after.
 * A post with no ring of its own falls back to its area's default, and an area
 * with no roster settings to the configured 1500 metres
 * ({@see \Uhifadhi\Roster\DependencyInjection\RosterConfiguration::DEFAULT_CATCHMENT_METRES},
 * written as a literal because a migration must mean the same thing in five
 * years as it does today).
 */
final class Version20260924000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The watch stops carrying a catchment of its own; a post\'s ring is the post\'s.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE roster_station_watch DROP catchment_metres');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE roster_station_watch ADD catchment_metres INT DEFAULT NULL');
        $this->addSql(<<<'SQL'
            UPDATE roster_station_watch w SET catchment_metres = COALESCE(
                (SELECT s.catchment_m FROM station s WHERE s.id = w.station_id),
                (SELECT r.default_catchment_metres
                   FROM roster_area_settings r
                   JOIN station s2 ON s2.area_id = r.area_id
                  WHERE s2.id = w.station_id),
                1500
            )
            SQL);
        $this->addSql('ALTER TABLE roster_station_watch ALTER catchment_metres SET NOT NULL');
    }
}
