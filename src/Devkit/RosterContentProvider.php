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

namespace Uhifadhi\Roster\Devkit;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Bundle\AreaBundle\Enum\PostingSource;
use Uhifadhi\Bundle\AreaBundle\Repository\AreaOfInterestRepository;
use Uhifadhi\Bundle\AreaBundle\Repository\PostingRepository;
use Uhifadhi\Bundle\AreaBundle\Repository\StationRepository;
use Uhifadhi\Bundle\AreaBundle\Service\PostingService;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Bundle\TeamBundle\Repository\DepartmentRepository;
use Uhifadhi\Bundle\TeamBundle\Repository\UserRepository;
use Uhifadhi\Contracts\Devkit\ContentProviderInterface;
use Uhifadhi\Contracts\Entity\UserInterface;
use Uhifadhi\Roster\Entity\Absence;
use Uhifadhi\Roster\Entity\Duty;
use Uhifadhi\Roster\Entity\Rotation;
use Uhifadhi\Roster\Entity\RotationPoolMember;
use Uhifadhi\Roster\Enum\AbsenceKind;
use Uhifadhi\Roster\Enum\RotationScope;
use Uhifadhi\Roster\Enum\RuleKind;
use Uhifadhi\Roster\Enum\SwapState;
use Uhifadhi\Roster\Model\Cycle;
use Uhifadhi\Roster\Repository\AbsenceRepository;
use Uhifadhi\Roster\Repository\DutyRepository;
use Uhifadhi\Roster\Repository\RotationRepository;
use Uhifadhi\Roster\Repository\StationWatchRepository;
use Uhifadhi\Roster\Service\RotaService;
use Uhifadhi\Roster\Service\RotationGenerator;
use Uhifadhi\Roster\Service\ShiftVocabularyService;
use Uhifadhi\Roster\Service\StationWatchService;
use Uhifadhi\Roster\Service\SwapService;

/**
 * THE DEMO ROSTER — the plan: what each post asks for, who is in its ring,
 * who that puts on watch every day of THIS month, who is away, and which
 * two people are trying to trade.
 *
 * IT HANGS ON THE AREA'S OWN CONTENT AND INVENTS NONE OF IT. The posts are
 * the ones the area seeded and the ring at each is THE PEOPLE POSTED
 * THERE, read through the area's postings. That is not tidiness: a demo
 * that picked its own people would put somebody on the night watch at a
 * post whose own page never lists them, and the first thing it would hide
 * is the seam between the two modules — which is the thing most worth
 * demonstrating. Where an area has no postings at all there is nothing to
 * hang on, so the people are posted first — through the area's own
 * service, so the station page and the ring still agree.
 *
 * IT IS ALWAYS THIS MONTH. Every tab opens on the month it is opened in,
 * so content seeded into a fixed calendar month reads rich on the day it
 * was written and empty forever after. There are no literal dates here,
 * only offsets from today.
 *
 * IT SEEDS THE STATES THE SCREENS HAVE TO DRAW, on purpose — a post that
 * asks for nothing, a post manned round the clock, a ring too small for
 * what its post asks, somebody away, and a trade in each of the four
 * states a trade can be in. A demo where every post is alike exercises one
 * branch of every page and ships the rest broken.
 *
 * IT ASKS FOR EACH THING SEPARATELY, NEVER FOR THE AREA AS A WHOLE. What
 * somebody configured is left exactly as it is; what nobody did is filled
 * in beside it. An area-wide "been here" mark is how one hand-made post
 * kept a whole park empty through run after run.
 */
final readonly class RosterContentProvider implements ContentProviderInterface
{
    /**
     * WHAT EACH POST ASKS FOR, walked round the area's posts in order. The
     * empty one is deliberate and is the point of the list: a post on the
     * books that asks for no watch is a real state, and it is the one an
     * all-alike demo never produces.
     *
     * @var list<list<string>>
     */
    private const array DEMANDS = [
        ['day', 'night'],
        ['day'],
        ['day', 'night'],
        [],
        ['day', 'night'],
        ['office'],
        ['day', 'night'],
        ['day'],
    ];

    /**
     * THE RINGS, walked round the posts the same way. A ring is read as the
     * run of days one person works before the next takes over, so
     * `day night off` is the classic two-on-one-off and the longer ones are
     * the patterns a park with a bigger pool actually runs.
     *
     * @var list<list<string>>
     */
    private const array RINGS = [
        ['day', 'night', Cycle::OFF],
        ['day', 'day', Cycle::OFF],
        ['day', 'night', Cycle::OFF, Cycle::OFF],
        ['day', 'day', 'night', Cycle::OFF],
    ];

    /**
     * HOW FAR AHEAD A DEMO RING IS PUBLISHED. Long enough that the month
     * after this one is not blank the moment somebody steps forward a
     * page, short enough to stay a plausible planning horizon rather than
     * a year of guesses.
     */
    private const int HORIZON_DAYS = 45;

    /**
     * HOW MANY PEOPLE THE DEMO POSTS AT A POST it had to staff itself. Enough
     * that a two-on-one-off ring has somebody for every position in it, few
     * enough that a post still reads as a post rather than a parade.
     */
    private const int POSTED_PER_POST = 3;

    /**
     * HOW MANY TRADES ARE READ BACK to see which states a window already
     * holds. Four would do today; a handful leaves room for a hand-made one
     * beside them without the seeder concluding it has never been here.
     */
    private const int TRADES_READ = 50;

    /**
     * HOW MANY PEOPLE A DEMO SQUAD CARRIES. Two is the smallest number
     * that is a squad rather than a person, and the fewest to take out of
     * a park's gates to show the scope.
     */
    private const int SQUAD_SIZE = 2;

    /**
     * HOW MANY PEOPLE THE POSTS MUST BE LEFT WITH before a squad may take
     * any. Below this the area is not staffing a tour, it is abandoning
     * its gates to draw one.
     */
    private const int POSTS_KEEP_AT_LEAST = 2;

    public function __construct(
        private AreaOfInterestRepository $areas,
        private StationRepository $stations,
        private PostingRepository $postings,
        private PostingService $postingDesk,
        private UserRepository $people,
        private DepartmentRepository $departments,
        private DutyRepository $duties,
        private RotationRepository $rings,
        private StationWatchRepository $rosteredPosts,
        private AbsenceRepository $absences,
        private ShiftVocabularyService $vocabulary,
        private StationWatchService $watches,
        private RotationGenerator $generator,
        private SwapService $swaps,
        private EntityManagerInterface $entityManager,
        /*
         * THE CLOCK IS A COLLABORATOR HERE, exactly as it is in the
         * presence seeder beside it.
         *
         * THE TWO SEEDERS HAVE TO AGREE ABOUT WHAT DAY IT IS. This one
         * writes the duties and that one works them; if one reads a
         * pinned clock and the other the wall, a suite that pins ten
         * past midnight seeds a fortnight starting on one date and
         * reports against another, and every figure in between is off by
         * a day. It cost a flaky CI leg once already, in the service
         * these two feed.
         */
        private ClockInterface $clock,
    ) {
    }

    /** Midnight of the day this seeder is running for. */
    private function today(): \DateTimeImmutable
    {
        return $this->clock->now()->setTime(0, 0);
    }

    public function key(): string
    {
        return 'roster';
    }

    public function label(): string
    {
        return 'Roster';
    }

    public function description(): string
    {
        return 'Watches, rings and a month of duties at the areas\' own posts, with absences and a trade in every state.';
    }

    /**
     * The posts have to stand and be staffed before anybody can be rostered
     * onto them, and the people have to exist before they can be posted.
     */
    public function dependsOn(): array
    {
        return ['area', 'team', 'zone', 'station'];
    }

    public function load(): void
    {
        foreach ($this->areas->findAll() as $area) {
            $this->loadArea($area);
        }
    }

    private function loadArea(AreaOfInterest $area): void
    {
        $posts = $this->stations->findBy(['area' => $area], ['code' => 'ASC']);
        if ([] === $posts) {
            // NO POSTS, NO ROSTER. An area nobody stands at is not a roster
            // with nothing on it; it is an area this module has nothing to
            // say about, and seeding a vocabulary into it would put a
            // configure page's worth of content behind an empty tab.
            return;
        }

        // IDEMPOTENCE IS PER THING, NOT PER AREA, and that is a correction
        // worth stating. The first cut skipped a whole area that already
        // had anything on its roster — and the one area the module was
        // actually switched on for had a SINGLE post configured by hand,
        // so it stayed empty through every run. Each post, each absence
        // and each trade is now asked for separately: what somebody has
        // configured is left exactly as it is, and what nobody has is
        // filled in beside it.

        $this->seedVocabulary($area);
        $this->staffTheArea($area, $posts);

        $rings = [];

        // WHO IS ALREADY IN SOMEBODY ELSE'S RING. A person may be posted
        // at several posts, so without this two rings both draw them and
        // the same ranger stands two watches on one morning.
        //
        // THE RINGS ALREADY STANDING COUNT, and reading them here rather
        // than as the walk passes them is the whole of it: a post whose
        // ring an earlier run had made was returned from early, so its
        // people were never marked, and the next post to be rung drew
        // them straight back — a park that had merely been seeded twice
        // had rangers standing two watches on one morning.
        // AND THEY ARE EXTENDED below, once the new rings have run.
        $ringed = [];
        $standingRings = [];
        foreach ($this->rings->findByArea($area) as $standing) {
            $standingRings[] = $standing;
            foreach ($standing->getPool() as $member) {
                $ringed[(string) $member->getPerson()->getUuidString()] = true;
            }
        }

        // THE SQUAD GOES FIRST, and the order is the whole of it: it draws
        // from a DEPARTMENT while a post draws from its own postings, so a
        // squad formed after the walk would find every one of its people
        // already standing a gate — and one formed without being marked
        // here would put them on tour and on the gate the same morning.
        $tour = $this->putASquadOnTour($area, $posts, $ringed);
        if (null !== $tour) {
            $rings[] = $tour;
        }

        foreach ($posts as $index => $post) {
            $rotation = $this->putOnTheRoster($post, $index, $ringed);
            if (null !== $rotation) {
                $rings[] = $rotation;
            }
        }

        $this->seedAbsences($area);

        // THE RINGS RUN AFTER THE ABSENCES, because the generator skips a
        // day somebody is away — which is the behaviour worth showing, and
        // it can only show if the absence is already on the books.
        foreach ($rings as $rotation) {
            $this->generator->generate($rotation, $this->windowStart(), $this->fillHorizon());
        }

        // A STANDING RING IS EXTENDED, NEVER REDRAWN: it runs only from the
        // day after it was last generated through, so the duties, hand marks
        // and trades an earlier run put on the books stay exactly where they
        // are. Redrawing it would be the planner's own "fill again", which
        // clears the ring's work first — and a seed is not a planner.
        foreach ($standingRings as $rotation) {
            $through = $rotation->getGeneratedThrough();
            if (null === $through || $through >= $this->fillHorizon()) {
                continue;
            }
            $this->generator->generate($rotation, $through->modify('+1 day'), $this->fillHorizon());
        }

        $this->seedSwaps($area);
    }

    /**
     * THE SHIFTS A PARK RUNS, asked for rather than written out here. The
     * module's own vocabulary service seeds an area that has never had one,
     * and a demo that listed the four shifts itself would be a second copy
     * of a default that already has an owner — and would go on seeding the
     * old four the day somebody changed them.
     */
    private function seedVocabulary(AreaOfInterest $area): void
    {
        $this->vocabulary->forArea($area);
    }

    /**
     * AN AREA NOBODY IS POSTED AT IS STAFFED, THROUGH THE AREA'S OWN DOOR.
     *
     * A ring draws from the people posted at its post, so an area whose
     * posts carry no postings has nobody to draw and every tab on it reads
     * nought — which is the state the one area a demo is usually switched
     * on for arrives in, because the core's own demo staffs the two parks
     * it invented and no others.
     *
     * IT ASKS THE AREA RATHER THAN WRITING THE AREA'S TABLE. A posting is
     * the area's fact, so it is made through {@see PostingService} — the
     * same rule the check-ins follow — and the station page, the identity
     * band and the ring then all say the same thing. A demo that rang
     * people the area does not post would show a roster the post's own
     * page contradicts.
     *
     * IT IS ALL OR NOTHING, PER AREA. One post nobody is posted at inside a
     * staffed area is a deliberate state the board has to draw, and filling
     * that in would delete it; an area with no standing posting anywhere is
     * not making a point, it is empty.
     *
     * @param list<Station> $posts
     */
    private function staffTheArea(AreaOfInterest $area, array $posts): void
    {
        if ([] !== $this->postings->findStandingByArea($area)) {
            return;
        }

        // AND ONLY THE PEOPLE WHO STAND NOWHERE YET.
        //
        // ONE POSTING A PERSON IS ONE AREA TOO. A demo installation with a
        // second area cannot staff it with the rangers already standing in
        // the first — the area refuses them, rightly, because a person
        // works in one place. So the roll this method draws from is the
        // people nobody has posted, and an installation whose rangers are
        // all spoken for gets an area the demo leaves unstaffed rather
        // than an exception.
        $spokenFor = [];
        foreach ($this->postings->findAllStanding() as $standing) {
            $person = $standing->getPerson();
            $uuid = $person?->getUuidString();
            if (null !== $uuid) {
                $spokenFor[$uuid] = true;
            }
        }

        $people = array_values(array_filter(
            $this->theInstallationsPeople(),
            static fn (User $person): bool => !isset($spokenFor[(string) $person->getUuidString()]),
        ));

        $headcount = \count($people);
        if (0 === $headcount) {
            return;
        }

        // EACH PERSON ONCE, AND THEN THE PEOPLE RUN OUT.
        //
        // SOMEBODY STANDS AT ONE POST AT A TIME (ruled, and the area
        // refuses the second). So the number of postings a demo can make is
        // the HEADCOUNT and not the number of posts: a cursor that wrapped
        // round would come back to somebody who already stands somewhere,
        // and the area would refuse them — rightly, because there is no
        // second person there to post.
        //
        // A PARK WITH TWELVE POSTS AND SIX RANGERS HAS UNSTAFFED POSTS, and
        // the demo says so rather than inventing staff. That is the same
        // state this method already protects above: one post nobody stands
        // at is something the board has to draw.
        $next = 0;
        foreach ($posts as $post) {
            foreach (range(1, self::POSTED_PER_POST) as $ignored) {
                if ($next >= $headcount) {
                    return;
                }

                $this->postingDesk->post($post, $people[$next], PostingSource::WrittenHere);
                ++$next;
            }
        }
    }

    /**
     * THE REAL PEOPLE THE INSTALLATION HAS, preferring the ones who hold a
     * position: a park's watches are stood by its rangers, and an account
     * with no position is as likely to be an administrator as a ranger.
     * Where nobody holds one, everybody is a candidate — an installation
     * that has not filled in its positions still deserves a demo.
     *
     * @return list<User>
     */
    private function theInstallationsPeople(): array
    {
        $everyone = array_values(array_filter(
            $this->people->findAllByName(),
            static fn (User $person): bool => $person->isActive() && null === $person->getDeletedAt(),
        ));

        $positioned = array_values(array_filter(
            $everyone,
            static fn (User $person): bool => null !== $person->getPosition(),
        ));

        return [] === $positioned ? $everyone : $positioned;
    }

    /**
     * ONE POST ONTO THE ROSTER: what it asks for, and the ring of the
     * people posted there that answers it.
     *
     * A POST THAT ASKS FOR NOTHING GETS NO RING. There is nothing for a
     * rotation to generate, and one standing there with an empty demand
     * would be a ring that quietly produces no duties — which reads on
     * every page as a bug rather than as the deliberate state it is.
     */
    /**
     * @param array<string, true> $ringed people already drawn by an earlier post, added to here
     */
    private function putOnTheRoster(Station $post, int $index, array &$ringed): ?Rotation
    {
        $watch = $this->rosteredPosts->findOneForStation($post);

        if (null === $watch) {
            $watch = $this->watches->addToRoster($post);
            $this->watches->save(
                $watch,
                self::DEMANDS[$index % \count(self::DEMANDS)],
                $watch->getSilenceWindowMinutes(),
                $watch->getOfflineAfterMinutes(),
            );
        }

        // WHAT THE POST ASKS IS THE WATCH'S OWN ANSWER, never the demo's
        // list — the second half of the per-thing correction. A post
        // somebody had configured was read as "been here, leave it" and
        // returned before anybody had been rung, so an area whose single
        // post was set up by hand stayed at nought through run after run.
        // The configuration is still theirs; the ring answers it.
        //
        // AND ONLY IN SHIFTS THE AREA NAMES. A watch may ask for a shift
        // that was never in the vocabulary or has since been closed, and a
        // ring built round one generates nothing while reading, on every
        // page, like a ring that is broken.
        $demands = $this->shiftsTheAreaNames($post->getArea(), $watch->getExpects());

        if ([] === $demands) {
            return null;
        }

        $standing = $this->rings->findOneForStation($post);
        if (null !== $standing) {
            // A RING IS ALREADY TURNING HERE — this seeder's from an
            // earlier run, or somebody's own. Either way a second one at
            // one post is two people on one watch. But somebody posted
            // here SINCE it was made joins it, at the end of the ring:
            // a ranger the sheet lists at a station with no watch in a
            // month is not a demo of a roster, it is a demo of a ranger
            // nobody rostered. Their days are drawn as the ring is
            // carried on to the horizon, never over what already stands.
            $this->joinTheStandingRing($standing, $post, $ringed);

            return null;
        }

        $posted = [];
        foreach ($this->postings->findStandingByStation($post) as $posting) {
            $person = $posting->getPerson();
            if ($person instanceof UserInterface) {
                $posted[] = $person;
            }
        }

        // ONE PERSON, ONE POST — in the demo, where nothing forces it.
        //
        // A ranger is often POSTED at more than one post, and each post's
        // ring draws from the people posted there, so two rings sharing a
        // person put them on two watches the same morning: Today showed
        // one ranger "watch 1 of 2" at two posts and twenty-four hours on
        // duty in a twenty-four hour day. Nothing in the product forbids
        // it — two independent per-post rings genuinely can double-book
        // somebody, which is worth knowing — but a DEMO that shows it is
        // showing a roster no park would publish. So a post takes the
        // people nobody has ringed yet.
        $pool = array_values(array_filter(
            $posted,
            static fn (UserInterface $person): bool => !isset($ringed[(string) $person->getUuidString()]),
        ));

        if ([] === $pool) {
            // NOBODY LEFT, OR NOBODY POSTED HERE AT ALL. Both are a post
            // without a ring, and the pages draw it as the hole it is —
            // which is better than borrowing somebody already spoken for
            // and calling the result a roster.
            return null;
        }

        foreach ($pool as $person) {
            $ringed[(string) $person->getUuidString()] = true;
        }

        $ring = self::RINGS[$index % \count(self::RINGS)];
        $slots = [];
        foreach ($demands as $shiftKey) {
            $slots[$shiftKey] = 1;
        }

        // A RING THAT DOES NOT ASK FOR WHAT THE POST NEEDS generates
        // nothing for the shift it leaves out, so the ring is narrowed to
        // the post's own demands and the classic day/night pair is kept
        // only where the post actually stands a night.
        $ring = array_values(array_filter(
            $ring,
            static fn (string $position): bool => Cycle::OFF === $position || \in_array($position, $demands, true),
        ));
        if ([] === array_filter($ring, static fn (string $p): bool => Cycle::OFF !== $p)) {
            $ring = [$demands[0], Cycle::OFF];
        }

        $rotation = new Rotation(
            $post->getArea() ?? throw new \LogicException('A post always belongs to an area.'),
            RotationScope::Post,
            Cycle::of($ring),
            $this->monthStart(),
            $slots,
            self::HORIZON_DAYS,
        )->standAt($post);

        $this->entityManager->persist($rotation);

        foreach ($pool as $position => $person) {
            $this->entityManager->persist(new RotationPoolMember($rotation, $person, $position));
        }

        $this->entityManager->flush();

        return $rotation;
    }

    /**
     * @param array<string, true> $ringed
     */
    private function joinTheStandingRing(Rotation $ring, Station $post, array &$ringed): void
    {
        $position = -1;
        foreach ($ring->getPool() as $member) {
            $ringed[(string) $member->getPerson()->getUuidString()] = true;
            $position = max($position, $member->getPosition());
        }

        foreach ($this->postings->findStandingByStation($post) as $posting) {
            $person = $posting->getPerson();
            if (!$person instanceof UserInterface || isset($ringed[(string) $person->getUuidString()])) {
                continue;
            }
            $this->entityManager->persist(new RotationPoolMember($ring, $person, ++$position));
            $ringed[(string) $person->getUuidString()] = true;
        }
        $this->entityManager->flush();
    }

    /**
     * ONE SQUAD, CARRYING A RING OF ITS OWN — the second scope a rotation
     * can have, and without one in the demo the identity band draws its
     * "per team" figure as nought and {@see RotationScope::Team} renders
     * nowhere at all.
     *
     * A SQUAD IS A DEPARTMENT'S PEOPLE, NOT A POST'S. That is the entire
     * difference between the two scopes: a post rings whoever the area
     * posts there, a squad carries whoever the organization put in a
     * department, and it takes them wherever it is sent. Drawing the demo's
     * squad from postings would produce a per-post ring wearing the other
     * scope's name.
     *
     * IT IS COUNTED AT A BASE POST, ruled — a squad away on tour still
     * belongs somewhere, and its watches are recorded there. The base is
     * the area's first post, which is the one every other page already
     * leads with; that its own ring stands there too is not a clash but
     * the case the board exists to draw.
     *
     * AN AREA THAT CANNOT SPARE THE PEOPLE CARRIES NONE. Two people out of
     * a park that has three leaves its gates with one, and a demo that
     * emptied the page somebody opens to show off a scope on the page they
     * do not is a bad trade.
     *
     * @param list<Station>       $posts
     * @param array<string, true> $ringed people already drawn, added to here
     */
    private function putASquadOnTour(AreaOfInterest $area, array $posts, array &$ringed): ?Rotation
    {
        foreach ($this->rings->findByArea($area) as $standing) {
            if (RotationScope::Team === $standing->getScope()) {
                // This area already carries one, from an earlier run or
                // from somebody's own work. A second squad is a second
                // squad every run.
                return null;
            }
        }

        $shiftKey = $this->firstShiftTheAreaNames($area);
        $base = $posts[0] ?? null;

        if (null === $shiftKey || null === $base) {
            return null;
        }

        $spare = $this->peopleTheAreaCanSpare($area, $ringed);
        $squad = $this->aDepartmentsPeople($area, $ringed);

        if (null === $squad) {
            return null;
        }

        [$departmentName, $carried] = $squad;

        $costToThePosts = \count(array_filter(
            $carried,
            static fn (UserInterface $person): bool => isset($spare[(string) $person->getUuidString()]),
        ));

        if (\count($spare) - $costToThePosts < self::POSTS_KEEP_AT_LEAST) {
            return null;
        }

        $rotation = new Rotation(
            $area,
            RotationScope::Team,
            Cycle::of([$shiftKey, $shiftKey, $shiftKey, Cycle::OFF, Cycle::OFF]),
            $this->monthStart(),
            [$shiftKey => 1],
            self::HORIZON_DAYS,
        )->carriedBy($departmentName, $base);

        $this->entityManager->persist($rotation);

        foreach ($carried as $position => $person) {
            $ringed[(string) $person->getUuidString()] = true;
            $this->entityManager->persist(new RotationPoolMember($rotation, $person, $position));
        }

        $this->entityManager->flush();

        return $rotation;
    }

    /**
     * THE PEOPLE THIS AREA'S POSTS STILL HAVE TO DRAW ON, keyed by uuid —
     * what says whether a squad is affordable. Counted through the area's
     * own postings, because a person nobody posts here is not one of this
     * park's gates' people whatever else they are.
     *
     * @param array<string, true> $ringed
     *
     * @return array<string, true>
     */
    private function peopleTheAreaCanSpare(AreaOfInterest $area, array $ringed): array
    {
        $spare = [];
        foreach ($this->postings->findStandingByArea($area) as $posting) {
            $person = $posting->getPerson();
            if (null === $person) {
                continue;
            }

            $uuid = (string) $person->getUuidString();
            if (!isset($ringed[$uuid])) {
                $spare[$uuid] = true;
            }
        }

        return $spare;
    }

    /**
     * THE FIRST DEPARTMENT THAT CAN FIELD A SQUAD, and the people it
     * fields — the area's own departments before the ones it inherits,
     * because a park's own squad is the likelier answer and a demo should
     * show the likelier answer.
     *
     * A DEPARTMENT'S PEOPLE ARE THE PEOPLE PLACED IN IT. Team owns that
     * chain and this only reads it: a department is a dimension of a
     * person's PLACEMENT, not something a position owns, so somebody may
     * be placed in several and a position belongs to none.
     *
     * @param array<string, true> $ringed
     *
     * @return array{string, non-empty-list<UserInterface>}|null
     */
    private function aDepartmentsPeople(AreaOfInterest $area, array $ringed): ?array
    {
        $everyone = $this->theInstallationsPeople();

        foreach ([...$this->departments->findForArea($area), ...$this->departments->findOrgLevelOrdered()] as $department) {
            $name = $department->getName();
            if (!$department->isActive() || null === $name) {
                continue;
            }

            $carried = [];
            foreach ($everyone as $person) {
                if (isset($ringed[(string) $person->getUuidString()])) {
                    continue;
                }

                // PLACED IN THIS ONE BY NAME. A placement across ALL
                // departments is not membership of any: it would make
                // everybody a member of every department and the squad
                // would be whoever the sort happened to reach first.
                if (\in_array($department, $person->getPlacement()?->getDepartments() ?? [], true)) {
                    $carried[] = $person;
                }

                if (self::SQUAD_SIZE === \count($carried)) {
                    return [$name, $carried];
                }
            }
        }

        return null;
    }

    /**
     * THE SHIFT A TOUR IS STOOD IN — the first the area names. A squad
     * needs one shift, not a post's whole demand: what it carries is a
     * pattern of days away, and the pattern is the point.
     */
    private function firstShiftTheAreaNames(AreaOfInterest $area): ?string
    {
        foreach ($this->vocabulary->openFor($area) as $shift) {
            return $shift->getKey();
        }

        return null;
    }

    /**
     * THE ASKED-FOR SHIFTS THE AREA ACTUALLY NAMES, in the order the post
     * asked for them.
     *
     * @param list<string> $asked
     *
     * @return list<string>
     */
    private function shiftsTheAreaNames(?AreaOfInterest $area, array $asked): array
    {
        if (null === $area) {
            return [];
        }

        $named = [];
        foreach ($this->vocabulary->openFor($area) as $shift) {
            $named[$shift->getKey()] = true;
        }

        return array_values(array_filter($asked, static fn (string $key): bool => isset($named[$key])));
    }

    /**
     * SOMEBODY IS ALWAYS AWAY. Three people, three reasons and three
     * lengths, spread across the month so the generator's skip is visible
     * in the grid rather than tucked into a corner of it.
     */
    private function seedAbsences(AreaOfInterest $area): void
    {
        $people = [];
        foreach ($this->postings->findStandingByArea($area) as $posting) {
            $person = $posting->getPerson();
            if ($person instanceof UserInterface) {
                $people[(string) $person->getUuidString()] = $person;
            }
        }

        $people = array_values($people);
        if ([] === $people) {
            return;
        }

        if ([] !== $this->absences->findOverlapping($area, $this->monthStart(), $this->monthEnd())) {
            // SOMEBODY IS ALREADY DOWN AS AWAY THIS MONTH. Three more would
            // be three more on every run.
            return;
        }

        $script = [
            [AbsenceKind::Leave, 2, 8],
            [AbsenceKind::Sick, 11, 13],
            [AbsenceKind::Course, 18, 22],
        ];

        foreach ($script as $index => [$kind, $fromDay, $throughDay]) {
            $person = $people[$index % \count($people)];

            $this->entityManager->persist(new Absence(
                $area,
                $person,
                $this->dayOfMonth($fromDay),
                $this->dayOfMonth($throughDay),
                $kind,
                $people[($index + 1) % \count($people)],
            ));
        }

        $this->entityManager->flush();
    }

    /**
     * A TRADE IN EVERY STATE IT CAN BE IN, all inside the fortnight the
     * grid draws — the offered one marks two cells, and the three that
     * have been answered are what keep the register from drawing one of
     * its four rows in anger and the other three never.
     *
     * IT IS ASKED PER STATE, not per area. A window that already held one
     * trade counted as done, so an area seeded before a state existed
     * never got it however many times the seeder ran.
     *
     * THE FORTNIGHT IS THE PLANNER'S OWN, so every trade falls inside the
     * window the Week tab opens on rather than near it.
     */
    private function seedSwaps(AreaOfInterest $area): void
    {
        $from = RotaService::start($this->today());
        $through = $from->modify(\sprintf('+%d days', RotaService::DAYS - 1));

        $alreadyIn = [];
        foreach ($this->swaps->recentBetween($area, $from, $through, self::TRADES_READ) as $swap) {
            $alreadyIn[$swap->getState()->value] = true;
        }

        $duties = $this->duties->findByAreaBetween($area, $from, $through);
        if (\count($duties) < 2) {
            return;
        }

        // WHO IS ALREADY DUE ON WHICH DAY. An accepted trade MOVES a watch,
        // and moving it onto somebody who is already standing one that day
        // would be the double-booking every surface is built to show — and,
        // where it is the same post and shift, a row the database refuses.
        $due = [];
        foreach ($duties as $duty) {
            $due[$duty->getOnDay()->format('Y-m-d')][(string) $duty->getPerson()->getUuidString()] = true;
        }

        // THE WATCHES THAT ALREADY HAVE AN OFFER OUT. Two offers on one
        // cell is a roster that depends on who taps first, and the area
        // refuses it — so a run that finds a state missing must look past
        // the watches an earlier run already spoke for.
        $spokenFor = [];
        foreach ($this->swaps->openBetween($area, $from, $through) as $open) {
            $spokenFor[(string) $open->getDuty()->getUuid()] = true;
        }

        $answeredOn = $this->today();
        $cursor = 0;

        foreach (SwapState::cases() as $state) {
            if (isset($alreadyIn[$state->value])) {
                continue;
            }

            $trade = $this->nextTradableWatch($duties, $cursor, $spokenFor, $due);
            if (null === $trade) {
                return;
            }

            ['duty' => $duty, 'taker' => $taker] = $trade;
            $swap = $this->swaps->offer($duty, $taker, $duty->getPerson());
            $spokenFor[(string) $duty->getUuid()] = true;

            match ($state) {
                SwapState::Offered => null,
                SwapState::Accepted => $this->swaps->accept($swap, $answeredOn),
                SwapState::Declined => $this->swaps->decline($swap, $answeredOn),
                SwapState::Withdrawn => $this->swaps->withdraw($swap, $answeredOn),
            };

            if (SwapState::Accepted === $state) {
                // The watch has changed hands, so the day says so.
                $due[$duty->getOnDay()->format('Y-m-d')][(string) $taker->getUuidString()] = true;
            }
        }
    }

    /**
     * THE NEXT WATCH A TRADE CAN BE MADE OF, and somebody who could take
     * it — walking forward from where the last one was found, so four
     * trades are four different cells.
     *
     * @param list<Duty>                         $duties
     * @param array<string, true>                $spokenFor watches that already have an offer out
     * @param array<string, array<string, true>> $due       who is already due, by day
     *
     * @return array{duty: Duty, taker: UserInterface}|null
     */
    private function nextTradableWatch(array $duties, int &$cursor, array $spokenFor, array $due): ?array
    {
        while (isset($duties[$cursor])) {
            $duty = $duties[$cursor];
            ++$cursor;

            if (isset($spokenFor[(string) $duty->getUuid()])) {
                continue;
            }

            $taker = $this->somebodyFreeThatDay($duty, $duties, $due);
            if (null !== $taker) {
                return ['duty' => $duty, 'taker' => $taker];
            }
        }

        return null;
    }

    /**
     * SOMEBODY WHO COULD ACTUALLY TAKE IT — not the person already standing
     * the watch, and nobody who is due elsewhere that day.
     *
     * @param list<Duty>                         $duties
     * @param array<string, array<string, true>> $due    who is already due, by day
     */
    private function somebodyFreeThatDay(Duty $duty, array $duties, array $due): ?UserInterface
    {
        $day = $duty->getOnDay()->format('Y-m-d');
        $standing = (string) $duty->getPerson()->getUuidString();

        foreach ($duties as $other) {
            $candidate = $other->getPerson();
            $uuid = (string) $candidate->getUuidString();

            if ($uuid !== $standing && !isset($due[$day][$uuid])) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * WHERE THE DEMO'S PLAN BEGINS — the earlier of the month's first day
     * and the FORTNIGHT the Week tab opens on.
     *
     * TWO REASONS, AND BOTH ARE ABOUT A DAY NOBODY TESTS ON. The planner's
     * window is fourteen days from the Monday of this week, so for the
     * first days of a month it reaches back into the last one: a demo that
     * started at the first would draw those days as holes it had simply
     * never generated, which reads as a park that forgot to staff itself.
     * And the presence demo can only report from watches that have already
     * happened — on the 1st, a month-bounded plan has none, so a park
     * seeded that morning had no worked history at all and no state to
     * draw.
     *
     * IT IS THE EARLIER OF THE TWO, never simply the fortnight: mid-month
     * the fortnight starts well after the first, and rostering from there
     * would leave the first half of every calendar empty.
     */
    private function windowStart(): \DateTimeImmutable
    {
        $fortnight = RotaService::start($this->today());
        $month = $this->monthStart();

        return $fortnight < $month ? $fortnight : $month;
    }

    /**
     * WHERE THE DEMO'S PLAN ENDS — the later of the month's last day and
     * the area's fill-ahead reach, six weeks from today by default. The
     * month alone left the second week of the sheet's own fortnight empty
     * from the third week of every month: a Week tab whose right half is
     * a wall of short cover is not a demo of a roster.
     */
    private function fillHorizon(): \DateTimeImmutable
    {
        $standard = RuleKind::FillAhead->standard();
        $ahead = $this->today()->modify(\sprintf('+%d days', (int) round($standard->value) * $standard->unit->days()));
        $month = $this->monthEnd();

        return $ahead > $month ? $ahead : $month;
    }

    private function monthStart(): \DateTimeImmutable
    {
        return $this->clock->now()->modify('first day of this month')->setTime(0, 0);
    }

    private function monthEnd(): \DateTimeImmutable
    {
        return $this->clock->now()->modify('last day of this month')->setTime(0, 0);
    }

    /** A day of THIS month, clamped to a month that has fewer of them. */
    private function dayOfMonth(int $day): \DateTimeImmutable
    {
        $last = (int) $this->monthEnd()->format('j');

        return $this->monthStart()->modify(\sprintf('+%d days', min($day, $last) - 1));
    }
}
