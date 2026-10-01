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

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Bundle\AreaBundle\Controller\LiveSheetController;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Repository\PostingRepository;
use Uhifadhi\Bundle\AreaBundle\Repository\StationRepository;
use Uhifadhi\Bundle\AreaBundle\Repository\ZoneRepository;
use Uhifadhi\Bundle\AreaBundle\Service\AreaPlateService;
use Uhifadhi\Bundle\AreaBundle\Service\ZoneSetService;
use Uhifadhi\Bundle\AtlasBundle\Model\AtlasMap;
use Uhifadhi\Bundle\AtlasBundle\Model\LayerShape;
use Uhifadhi\Bundle\AtlasBundle\Model\LegendItem;
use Uhifadhi\Bundle\AtlasBundle\Model\LiveMarks;
use Uhifadhi\Contracts\Area\DayState;
use Uhifadhi\Contracts\Area\LivePresence;
use Uhifadhi\Roster\Model\LiveFigures;
use Uhifadhi\Roster\Model\LiveRailGroup;
use Uhifadhi\Roster\Model\PostPresence;
use Uhifadhi\Roster\Model\PostState;
use Uhifadhi\Roster\Model\RosteredPerson;
use Uhifadhi\Roster\Model\ShiftWindow;

/**
 * WHERE EVERYBODY IS, FED TO THE ATLAS.
 *
 * THIS MODULE DRAWS NOTHING. The plate, its imagery, its controls and its
 * key are the atlas's; the ground, the boundary, the zones and the posts are
 * the AREA's. What the roster contributes is one marker layer per presence
 * state and the legend group over them — and it contributes them by handing
 * the atlas features, never by rendering a map.
 *
 * THE POSITIONS ARE THE AREA'S TOO, read through
 * {@see \Uhifadhi\Contracts\Area\LivePositionsInterface}. This module stores
 * no position and derives no state.
 *
 * AND IT DOES NOT DRAW THE MARKS EITHER. `AtlasMap::livePositions()` adds
 * the live layer and the key that must come with it in one call; the mark
 * is the house's own `.livedot` component. So this names no colour, no
 * size and no typeface for a position — it hands over the positions and
 * the count of the people the read had no fix for, and nothing else.
 *
 * THE ONE KEY IT DOES WRITE is the rail's: six legend ITEMS, under their
 * own heading, for the states the COLUMN is colour-coded by. Nothing on
 * the map is drawn in those colours, which is exactly why they are items
 * and not layers — and their swatches are the house's semantic token
 * names, because those rows sit on the page ground and not on imagery.
 *
 * STALENESS IS THE ANSWER'S. {@see LivePresence::isStale()} decides what is
 * old, from the area's own ping interval; a threshold of this module's would
 * be a second opinion about a number the area already publishes.
 */
final readonly class RosterLiveService
{
    /** What the legend heads the rail's own colour coding with. */
    public const string PRESENCE_GROUP = 'Presence states';

    public function __construct(
        private AreaPlateService $plates,
        private ZoneSetService $zones,
        private StationRepository $stations,
        private PostingRepository $postings,
        private ZoneRepository $zoneRepository,
        /** Where a click on a live mark asks who it is (#16 C); absent, the marks open nothing. */
        private ?UrlGeneratorInterface $urls = null,
    ) {
    }

    /** The address a live mark's click asks, `{id}` for the person, or null where it is not mounted. */
    public function sheetAddress(): ?string
    {
        return null === $this->urls ? null : LiveSheetController::addressTemplate($this->urls);
    }

    /**
     * THE AREA'S PLATE, WITH THIS MODULE'S MARKERS ON IT.
     *
     * The base is the area's own stations plate — the same ground, the same
     * rings and the same posts a reader saw on the Stations tab, so nobody
     * has to learn a second map. Only the presence layers are ours.
     */
    /**
     * @param list<PostPresence> $rostered the posts on this module's books, for the rail's own key
     * @param string|null        $centre   the uuid of the post or zone a row asked the plate to centre on
     */
    public function plate(AreaOfInterest $area, LivePresence $live, int $withoutPosition = 0, array $rostered = [], ?string $centre = null): AtlasMap
    {
        $view = $this->zones->view($area);

        // THE POSTS AS THE AREA'S PLATE ASKS FOR THEM, and not one field
        // more. A ZONE'S NAME IS A NAME; ITS COLOUR IS THE AREA'S, and
        // this module neither reads it nor passes it on. The zone rows go
        // through untouched and the area hues them — the only arrangement
        // in which the same zone is the same colour on every plate in the
        // product, and the only one that does not have to be edited every
        // time that palette changes.
        $posts = [];
        foreach ($this->stations->findByArea($area) as $post) {
            $posts[] = [
                'uuid' => (string) $post->getUuidString(),
                'name' => (string) $post->getName(),
                'point' => $post->getPoint(),
                'posted' => $this->postings->countStandingByStation($post),
                'here' => false,
                'zone' => $post->getZone()?->getName(),
            ];
        }

        $map = $this->plates->stationsPlate($area, $view->rows, $posts);

        // WHERE PEOPLE ARE, DRAWN BY THE ATLAS. One call adds the live
        // layer AND the key that must come with it — live, stale, and the
        // people this read had no fix for at all, who are on no layer and
        // would otherwise go unmentioned.
        //
        // THIS MODULE NAMES NO COLOUR, NO SIZE AND NO TYPEFACE. The mark is
        // the house's `.livedot` component; staleness is the ANSWER's, from
        // LivePresence::isStale at two ping intervals, never a threshold of
        // ours. All the roster contributes is the positions and the count
        // of the people missing from them.
        $map->livePositions($live, $withoutPosition, $this->sheetAddress());

        // THE PRESENCE STATES ARE A KEY TO THE RAIL, not to the plate, and
        // the legend says so in its own heading. They are legend ITEMS and
        // not layers: nothing on the map is drawn in these colours — the
        // marks are the house's one live dot — so a row that switched a
        // layer would switch nothing. They earn their place because the
        // rail beside the plate IS colour-coded by them, and a reader
        // meeting "at post · unverified" in amber has nowhere else to
        // learn what amber means.
        //
        // THE SWATCHES ARE THEME TOKENS, NOT PLATE TOKENS: these rows sit
        // on the page ground under the plate, not on imagery.
        foreach (self::presenceKey($rostered) as [$label, $swatch, $count]) {
            $map->addLegendItem(new LegendItem(
                label: \sprintf('%s · %d', $label, $count),
                swatch: $swatch,
                shape: LayerShape::Point,
                group: self::PRESENCE_GROUP,
            ));
        }

        // A ROW ASKED THE PLATE TO CENTRE ON WHAT IT NAMES. The plate is
        // about the whole area and the click makes it about one thing on
        // it, which is the atlas's own `focusOn` — a subject's geometry,
        // not a coordinate this module worked out.
        $subject = null === $centre ? null : $this->geometryOf($area, $centre);

        if (null !== $subject) {
            $this->plates->focusOn($map, $subject, self::POST_ZOOM);
        }

        return $map;
    }

    /**
     * THE SIX STATES THE RAIL COLOUR-CODES BY, each with how many people
     * are in it — the key to the column, printed under the map.
     *
     * A SWATCH IS A TOKEN NAME. This module names no value; the tokens are
     * the house's semantic six, the same words every other surface in the
     * app states a state in.
     *
     * @param list<PostPresence> $posts
     *
     * @return list<array{string, string, int}>
     */
    private static function presenceKey(array $posts): array
    {
        $counts = [];
        foreach ($posts as $post) {
            foreach ($post->rostered as $person) {
                $key = 0 === $person->watchCount() ? DayState::NoCheckIn->value : $person->state()->value;
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }

        $rows = [];
        foreach ([
            [DayState::AtPostVerified, 'at post · verified', 'var(--ok)'],
            [DayState::AtPostUnverified, 'at post · unverified', 'var(--warn)'],
            [DayState::Special, 'special assignment', 'var(--acc)'],
            [DayState::WorkingElsewhere, 'working elsewhere', 'var(--dim)'],
            [DayState::NotWorking, 'not working', 'var(--fog)'],
            [DayState::NoCheckIn, 'no check-in', 'var(--fail)'],
        ] as [$state, $label, $swatch]) {
            $rows[] = [$label, $swatch, $counts[$state->value] ?? 0];
        }

        return $rows;
    }

    /**
     * WHO THE PLATE CANNOT DRAW (ruled 1 Oct, #16 D): the people on duty with
     * no position, and those whose last fix is stale - the strip under the
     * plate, now the rail is gone. Read off the rail's own groups, so the
     * strip and the reading cannot disagree.
     *
     * @param list<LiveRailGroup> $rail
     *
     * @return list<array{uuid: string, name: string, initials: string, reason: string, seat: string, stale: bool}>
     */
    public static function notOnPlate(array $rail): array
    {
        $off = [];
        foreach ($rail as $group) {
            foreach ($group->rows as $row) {
                $none = 'no position' === $group->label;
                if (!$none && !$row['stale']) {
                    continue;
                }
                $name = $row['person']->personName;
                $off[] = [
                    'uuid' => $row['person']->personUuid,
                    'name' => $name,
                    'initials' => LiveMarks::initials($name),
                    'reason' => $none ? 'no position' : 'stale · '.$row['age'],
                    'seat' => $row['post']->stationName,
                    'stale' => !$none,
                ];
            }
        }

        return $off;
    }

    /**
     * THE RAIL BESIDE THE PLATE — everybody, grouped the way the design
     * reads them, with "no position" last.
     *
     * THE RAIL IS THE ROSTER'S LIST AND THE MAP IS THE AREA'S ANSWER, and
     * they are deliberately not the same set: somebody at their post whose
     * phone has said nothing is on this list and not on that map. Inventing
     * a marker at the post's own point would turn a claim into proof, which
     * is the one thing this module exists to avoid.
     *
     * THE TAIL OF THE LIST IS THE PEOPLE WHO ARE LEGITIMATELY NOT ON IT:
     * the watch that has not started yet, and the day somebody is off. They
     * belong in the rail — a list that dropped them would answer "who is
     * missing" with a name that is simply due at six — but they are not what
     * a duty officer is scanning for, so they are marked as the tail and the
     * surface folds them behind one head.
     *
     * @param list<PostPresence>         $posts
     * @param array<string, ShiftWindow> $windows the shift hours, so a watch that has not begun reads as due rather than as silent
     *
     * @return list<LiveRailGroup>
     */
    public static function rail(LivePresence $live, array $posts, array $windows = [], ?\DateTimeImmutable $now = null): array
    {
        $now ??= $live->asOf;
        $minute = (int) $now->format('G') * 60 + (int) $now->format('i');
        $fixes = [];
        foreach ($live->positions as $position) {
            $fixes[$position->personUuid] = $position;
        }

        $groups = [
            DayState::AtPostVerified->value => [],
            DayState::AtPostUnverified->value => [],
            'away' => [],
            'none' => [],
            'due' => [],
            'off' => [],
        ];

        foreach ($posts as $post) {
            foreach ($post->rostered as $person) {
                $fix = $fixes[$person->personUuid] ?? null;
                $window = $windows[$person->shiftKey] ?? null;
                $key = match (true) {
                    DayState::NotWorking === $person->state() => 'off',
                    // DUE IS NOT SILENT. A watch whose hours have not
                    // started cannot have been checked into, and reading
                    // that as "no position" would put a name in front of
                    // the duty officer every morning for no reason.
                    0 === $person->watchCount() && null !== $window && $window->startsAtMinuteOfDay() > $minute => 'due',
                    null === $fix => 'none',
                    DayState::AtPostVerified === $fix->state => DayState::AtPostVerified->value,
                    DayState::AtPostUnverified === $fix->state => DayState::AtPostUnverified->value,
                    default => 'away',
                };

                $groups[$key][] = [
                    'person' => $person,
                    'post' => $post,
                    'fix' => $fix,
                    'age' => null === $fix ? null : self::age($fix->ageSeconds($live->asOf)),
                    'stale' => null !== $fix && $live->isStale($fix),
                ];
            }
        }

        $labels = [
            DayState::AtPostVerified->value => ['at post · verified', 'st-ok'],
            DayState::AtPostUnverified->value => ['at post · unverified', 'st-warn'],
            'away' => ['not at a post', ''],
            'none' => ['no position', 'st-fail'],
            'due' => ['due later', ''],
            'off' => ['off', ''],
        ];

        $rail = [];
        foreach ($groups as $key => $rows) {
            [$label, $tone] = $labels[$key];
            $rail[] = new LiveRailGroup($label, $tone, $rows, \in_array($key, ['due', 'off'], true));
        }

        return $rail;
    }

    /** How close a plate comes to a post, which has no extent of its own. */
    private const int POST_ZOOM = 13;

    /**
     * THE GEOMETRY BEHIND A ROW'S UUID — a post's point or a zone's shape.
     *
     * IT LOOKS IN THE AREA ONLY. A uuid from another park is not a subject
     * this plate may be centred on, and answering null is how a link
     * somebody edited by hand does nothing rather than something.
     */
    private function geometryOf(AreaOfInterest $area, string $uuid): ?string
    {
        if (!Uuid::isValid($uuid)) {
            return null;
        }

        foreach ($this->stations->findByArea($area) as $station) {
            if ((string) $station->getUuidString() === $uuid) {
                return $station->getPoint();
            }
        }

        foreach ($this->zoneRepository->zonesFor($area) as $zone) {
            if ((string) $zone->getUuidString() === $uuid) {
                return $zone->getGeom();
            }
        }

        return null;
    }

    /**
     * THE FIVE FIGURES ABOVE THE PLATE, counted over the same answer the
     * plate and the rail are drawn from.
     *
     * @param list<PostPresence> $posts
     */
    public function figures(LivePresence $live, array $posts): LiveFigures
    {
        $expected = 0;
        $reporting = 0;
        foreach ($posts as $post) {
            $expected += \count($post->rostered);

            if (PostState::Reporting === $post->state) {
                ++$reporting;
            }
        }

        $verified = 0;
        $away = 0;
        $oldest = null;
        foreach ($live->positions as $position) {
            if (DayState::AtPostVerified === $position->state) {
                ++$verified;
            } elseif (DayState::AtPostUnverified !== $position->state) {
                ++$away;
            }

            $age = $position->ageSeconds($live->asOf);
            $oldest = null === $oldest ? $position : ($age > $oldest->ageSeconds($live->asOf) ? $position : $oldest);
        }

        return new LiveFigures(
            positions: \count($live->positions),
            expected: $expected,
            stale: $live->staleCount(),
            withoutAFix: max(0, $expected - \count($live->positions)),
            verified: $verified,
            awayFromAPost: $away,
            oldestAge: null === $oldest ? null : self::age($oldest->ageSeconds($live->asOf)),
            oldestName: $oldest?->personName,
            oldestStation: $oldest?->stationName,
            pingIntervalMinutes: $live->pingIntervalMinutes,
            postsReporting: $reporting,
            postsOnTheBooks: \count($posts),
        );
    }

    /**
     * HOW OLD A FIX IS, in the words a person uses. Minutes up to an hour
     * and hours after it — "247 min" is a number somebody has to divide.
     */
    public static function age(int $seconds): string
    {
        $minutes = intdiv(max(0, $seconds), 60);

        if ($minutes < 60) {
            return \sprintf('%d min', $minutes);
        }

        return \sprintf('%d h %02d', intdiv($minutes, 60), $minutes % 60);
    }

    /**
     * Whether anybody on the books is rostered at all, which is the
     * difference between "nobody is reporting" and "nobody is due".
     *
     * @param list<PostPresence> $posts
     */
    public static function anybodyDue(array $posts): bool
    {
        foreach ($posts as $post) {
            if ([] !== $post->rostered) {
                return true;
            }
        }

        return false;
    }

    /** @return list<RosteredPerson> */
    public static function nobody(): array
    {
        return [];
    }
}
