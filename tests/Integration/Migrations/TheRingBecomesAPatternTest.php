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

use Uhifadhi\Roster\Enum\RuleKind;

/**
 * THE DATA AN INSTALLATION ALREADY HAS, CARRIED FORWARD — the half of a
 * migration a schema diff cannot see.
 *
 * A FRESH DATABASE PROVES THE SHAPE AND NOTHING ELSE. The tables can be
 * perfect and the park still arrive on the new screens with no rules, no
 * patterns and every station's own thresholds thrown away — which is the
 * failure this test exists to catch, and the only one an installation
 * would actually notice.
 *
 * SO IT MIGRATES TO THE VERSION BEFORE, WRITES A PARK AS THAT SCHEMA HELD
 * IT, AND MIGRATES THE REST OF THE WAY. Everything asserted below is a
 * fact somebody's installation already has, read back through the new
 * model.
 *
 * WRITTEN IN SQL, DELIBERATELY. The entities describe the schema this
 * migration produces, not the one it starts from, so a fixture built out
 * of them would be writing the destination and proving nothing about the
 * journey.
 */
final class TheRingBecomesAPatternTest extends MigrationsTestCase
{
    /** The last version before the redesign's schema. */
    private const string BEFORE = 'Uhifadhi\\Roster\\Migrations\\Version20260920000200';

    private const string AFTER = 'Uhifadhi\\Roster\\Migrations\\Version20260921000000';

    public function testAParkArrivesWithItsRulesItsPatternsAndItsExceptions(): void
    {
        $this->emptyDatabase();
        $this->console('doctrine:migrations:migrate', ['version' => self::BEFORE, '--no-interaction' => true]);

        $this->aParkAsTheOldSchemaHeldIt();

        $this->rebootKernel();
        $this->console('doctrine:migrations:migrate', ['version' => self::AFTER, '--no-interaction' => true]);

        $this->assertTheFiveRulesAreThere();
        $this->assertOnlyTheStationsThatDifferedHaveAnException();
        $this->assertOneCycleBecameOnePatternRunAtTwoStations();
        $this->assertEachStationKeptWhatItNeeds();
        $this->assertEveryShiftHasItsOwnColour();

        // AND THE REST OF THE JOURNEY, to the head of the line: a gap
        // becomes the station's, and the rule about it is renamed with
        // the thing it asks about.
        $this->rebootKernel();
        $this->console('doctrine:migrations:migrate', ['--no-interaction' => true]);

        $renamed = $this->connection->fetchAllAssociative("SELECT kind FROM roster_shift_rule WHERE area_id = 901 AND kind LIKE 'raise%'");
        self::assertCount(1, $renamed);
        self::assertSame(RuleKind::RaiseShortCover->value, self::asText($renamed[0]['kind']));

        // AND THE SEEDED SHIFTS END ON THE SLOTS THE DESIGN GIVES THEM:
        // position handed night the yellow at slot 2, and a night watch
        // that reads as a day watch's neighbour is the one confusion the
        // colour exists to prevent.
        $slots = [];
        foreach ($this->connection->fetchAllAssociative('SELECT shift_key, colour FROM roster_shift WHERE area_id = 901') as $row) {
            $slots[self::asText($row['shift_key'])] = (int) self::asText($row['colour']);
        }
        self::assertSame(['day' => 1, 'night' => 5], $slots);
    }

    /**
     * A PARK OF THREE STATIONS. Two run the same ring — which is the case
     * the ruling is about, because one object at area level is only worth
     * having if two stations can share it — and one runs its own. One
     * station's silence window differs from the area's and one agrees
     * with it.
     */
    private function aParkAsTheOldSchemaHeldIt(): void
    {
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO area_of_interest (id, name, source, uuid, geom)
            VALUES (901, 'seed reserve', 'test fixture', gen_random_uuid(),
                    ST_GeomFromGeoJSON('{"type":"MultiPolygon","coordinates":[[[[12.2,-5.8],[12.5,-5.8],[12.5,-5.5],[12.2,-5.5],[12.2,-5.8]]]]}'))
            SQL);

        // THE AREA'S SETTINGS, where three of the five rules already lived.
        $this->connection->executeStatement(<<<'SQL'
            INSERT INTO roster_area_settings
                (id, area_id, ping_interval_minutes, off_day_has_no_state, leave_approval_shown,
                 default_catchment_metres, late_threshold, vacancy_announce)
            VALUES (901, 901, 45, TRUE, FALSE, 1500, '4h', 'as_known')
            SQL);

        foreach ([[901, 'day', 'Day', 0], [902, 'night', 'Night', 1]] as [$id, $key, $label, $position]) {
            $this->connection->executeStatement(
                "INSERT INTO roster_shift (id, area_id, uuid, shift_key, label, starts_at, ends_at, position)
                 VALUES (:id, 901, gen_random_uuid(), :key, :label, '06:00', '18:00', :position)",
                ['id' => $id, 'key' => $key, 'label' => $label, 'position' => $position],
            );
        }

        foreach ([[901, 'ST-01'], [902, 'ST-02'], [903, 'ST-03']] as [$id, $code]) {
            $this->connection->executeStatement(
                "INSERT INTO station (id, area_id, uuid, name, code, active, point)
                 VALUES (:id, 901, gen_random_uuid(), :name, :code, TRUE, ST_GeomFromGeoJSON('{\"type\":\"Point\",\"coordinates\":[12.3,-5.7]}'))",
                ['id' => $id, 'name' => 'station '.$code, 'code' => $code],
            );
        }

        // ST-01 differs from the area on its silence window (the area says
        // 4 hours); ST-02 and ST-03 agree with it and must stay silent.
        foreach ([[901, 901, 90, 1440, 1500], [902, 902, 240, 1440, 1500], [903, 903, 240, 1440, 1500]] as [$id, $station, $silence, $offline, $catchment]) {
            $this->connection->executeStatement(
                'INSERT INTO roster_station_watch (id, station_id, expects, silence_window_minutes, offline_after_minutes, catchment_metres)
                 VALUES (:id, :station, :expects, :silence, :offline, :catchment)',
                [
                    'id' => $id, 'station' => $station, 'expects' => '["day"]',
                    'silence' => $silence, 'offline' => $offline, 'catchment' => $catchment,
                ],
            );
        }

        // TWO STATIONS ON ONE CYCLE, and a third on its own.
        foreach ([
            [901, 901, '["day","day","off"]', '{"day":2}'],
            [902, 902, '["day","day","off"]', '{"day":1}'],
            [903, 903, '["day","night","off"]', '{"day":1,"night":1}'],
        ] as [$id, $station, $cycle, $slots]) {
            $this->connection->executeStatement(
                "INSERT INTO roster_rotation
                    (id, area_id, station_id, uuid, scope, cycle, anchored_on, slots_per_shift, stand_down_weekdays, rest_rule, horizon_days, active)
                 VALUES (:id, 901, :station, gen_random_uuid(), 'post', :cycle, CURRENT_DATE, :slots, '[]', 'none', 42, TRUE)",
                ['id' => $id, 'station' => $station, 'cycle' => $cycle, 'slots' => $slots],
            );
        }
    }

    /** All five, and the three that had a home keep their own numbers. */
    private function assertTheFiveRulesAreThere(): void
    {
        $rules = [];
        foreach ($this->connection->fetchAllAssociative('SELECT kind, value, unit FROM roster_shift_rule WHERE area_id = 901') as $row) {
            $rules[self::asText($row['kind'])] = self::asText($row['value']).' '.self::asText($row['unit']);
        }

        self::assertCount(5, $rules, 'Every rule has an area default after the migration.');
        self::assertSame('45 minutes', $rules[RuleKind::PingEvery->value], 'The ping interval came across verbatim.');
        self::assertSame('1500 m', $rules[RuleKind::CheckInWithin->value], 'And so did the catchment.');
        self::assertSame('4 hours', $rules[RuleKind::LateAfter->value], 'The area named a fixed threshold, so it is kept.');
        self::assertSame('1 days', $rules[RuleKind::OfflineAfter->value]);
        // THE FIFTH RULE IS STILL UNDER ITS OLD NAME AT THIS VERSION. It
        // is renamed to `raise_short_cover` by Version20260921000400, once
        // a gap became the station's — which is the assertion below.
        self::assertSame('4 hours', $rules['raise_unfilled']);
    }

    /**
     * FOLLOWING THE AREA IS SAID BY SILENCE. A station whose window
     * matched the area's default must come out with no exception at all —
     * backfilling one for every station would turn three defaults into
     * three exceptions, and the card would read as if nothing agreed with
     * anything.
     */
    private function assertOnlyTheStationsThatDifferedHaveAnException(): void
    {
        $late = $this->connection->fetchAllAssociative(
            "SELECT station_id, value, unit FROM roster_station_rule_exception WHERE kind = 'late_after' ORDER BY station_id",
        );

        self::assertCount(1, $late, 'Only the station that differed has one.');
        self::assertSame(901, self::asInt($late[0]['station_id']));
        self::assertSame('90 minutes', self::asText($late[0]['value']).' '.self::asText($late[0]['unit']), 'In the unit it was stored in.');

        self::assertSame(
            0,
            self::asInt($this->connection->fetchOne("SELECT COUNT(*) FROM roster_station_rule_exception WHERE kind = 'check_in_within'")),
            'Every station agreed with the area on the catchment, so none of them says anything.',
        );
    }

    /** One cycle, one pattern — and both stations running it point at the same row. */
    private function assertOneCycleBecameOnePatternRunAtTwoStations(): void
    {
        self::assertSame(
            2,
            self::asInt($this->connection->fetchOne('SELECT COUNT(*) FROM roster_pattern WHERE area_id = 901')),
            'Two distinct cycles became two patterns, not three.',
        );

        $shared = self::asInt($this->connection->fetchOne('SELECT pattern_id FROM roster_station_watch WHERE station_id = 901'));

        self::assertSame(
            $shared,
            self::asInt($this->connection->fetchOne('SELECT pattern_id FROM roster_station_watch WHERE station_id = 902')),
            'Two stations that ran the same ring now run one shared pattern.',
        );
        self::assertNotSame(
            $shared,
            self::asInt($this->connection->fetchOne('SELECT pattern_id FROM roster_station_watch WHERE station_id = 903')),
            'And the one that ran its own keeps its own.',
        );
    }

    /** How many a station needs moved off the ring and onto the station. */
    private function assertEachStationKeptWhatItNeeds(): void
    {
        self::assertSame(['day' => 2], self::asNeeds(901));
        self::assertSame(['day' => 1, 'night' => 1], self::asNeeds(903));
    }

    /**
     * WHAT ONE STATION DECLARES IT NEEDS, read as data rather than as text.
     *
     * Postgres keeps a `json` column's bytes exactly as they were written,
     * spacing included, so comparing the text would be asserting how the
     * previous release's ORM happened to punctuate — which is not a fact
     * about the migration at all.
     *
     * @return array<string, int>
     */
    private function asNeeds(int $station): array
    {
        $decoded = json_decode(self::asText($this->connection->fetchOne(
            'SELECT needs_per_shift::text FROM roster_station_watch WHERE station_id = :station',
            ['station' => $station],
        )), true);

        self::assertIsArray($decoded);

        /** @var array<string, int> $decoded */
        return $decoded;
    }

    /** And every shift came out of it with a slot of its own. */
    private function assertEveryShiftHasItsOwnColour(): void
    {
        $colours = $this->connection->fetchFirstColumn('SELECT colour FROM roster_shift WHERE area_id = 901 ORDER BY position');

        self::assertSame([1, 2], array_map(self::asInt(...), $colours));
    }

    /**
     * A COLUMN READ BACK IS `mixed`, AND IT IS NARROWED ONCE.
     *
     * The driver's return type is honest — a column could be anything — so
     * casting it inline in twenty assertions is twenty unchecked casts. One
     * function that refuses anything unexpected is the same code with the
     * refusal written down, and a schema that changed shape under the test
     * fails HERE, naming the value, rather than three assertions later as a
     * confusing comparison.
     */
    private static function asInt(mixed $value): int
    {
        self::assertIsNumeric($value, 'A column this test reads as a number came back as something else.');

        return (int) $value;
    }

    private static function asText(mixed $value): string
    {
        self::assertIsScalar($value, 'A column this test reads as text came back as something else.');

        return (string) $value;
    }
}
