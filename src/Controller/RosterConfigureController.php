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

namespace Uhifadhi\Roster\Controller;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Uid\Uuid;
use Twig\Environment;
use Uhifadhi\Bundle\AreaBundle\Controller\StationConfigureController;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Bundle\AreaBundle\Repository\PostingRepository;
use Uhifadhi\Bundle\AreaBundle\Repository\StationRepository;
use Uhifadhi\Bundle\AreaBundle\Service\CheckInStatusService;
use Uhifadhi\Contracts\Access\Verb;
use Uhifadhi\Roster\Access\RosterConcerns;
use Uhifadhi\Roster\Entity\Rotation;
use Uhifadhi\Roster\Entity\RotationPoolMember;
use Uhifadhi\Roster\Entity\Shift;
use Uhifadhi\Roster\Entity\StationWatch;
use Uhifadhi\Roster\Enum\RestRule;
use Uhifadhi\Roster\Enum\RotationPreset;
use Uhifadhi\Roster\Enum\RuleChoiceInterface;
use Uhifadhi\Roster\Enum\RuleKind;
use Uhifadhi\Roster\Enum\RuleUnit;
use Uhifadhi\Roster\Model\RotationDraft;
use Uhifadhi\Roster\Model\RuleValue;
use Uhifadhi\Roster\Module\RosterModuleProvider;
use Uhifadhi\Roster\Repository\RotationRepository;
use Uhifadhi\Roster\Service\RosteredPeople;
use Uhifadhi\Roster\Service\RosterIdentityService;
use Uhifadhi\Roster\Service\RosterSettingsService;
use Uhifadhi\Roster\Service\RotationEditor;
use Uhifadhi\Roster\Service\RotationGenerator;
use Uhifadhi\Roster\Service\RotationPreview;
use Uhifadhi\Roster\Service\ShiftRuleService;
use Uhifadhi\Roster\Service\ShiftVocabularyService;
use Uhifadhi\Roster\Service\StationWatchService;

/**
 * THE THREE SECTIONS OF THE ROSTER'S CONFIGURE PAGE — Rotation, Watches,
 * Settings — each at an address of its own so it can link the stylesheet it
 * draws with.
 *
 * EACH ONE STILL BELONGS TO THE CONFIGURE PAGE. It wears the page's heading
 * and the page's section strip, and the Configure action stays lit on it,
 * because the template adopts the same frame. Nothing here draws navigation,
 * and there is no way back other than the strip, the lit Configure and the
 * crumb.
 *
 * THEY SIT BESIDE /configure AND NEVER UNDER IT. The shell owns
 * `/areas/{uuid}/modules/{slug}/configure/{section}` and matches any
 * lowercase slug, so a section screen addressed under that prefix would race
 * the frame's own route and the winner would depend on import order. A
 * section's own address is one segment under the module instead, and the
 * shell's bare `/configure` answers 302 to the first of them — one rule,
 * both shapes.
 *
 * EVERY WRITE RIDES ON THE ROSTER'S CONFIGURE GRANT AND A CSRF TOKEN. The check
 * is in CODE rather than an #[IsGranted] attribute, so the class stays
 * loadable in an installation with no security-bundle attributes to resolve —
 * and the whole controller is registered only where SecurityBundle is in the
 * kernel, so a security-less installation gets no routes at all rather than
 * open write endpoints.
 */
#[Route(defaults: ['_uhifadhi_module' => RosterModuleProvider::SLUG])]
final class RosterConfigureController
{
    public const string ROTATION_ROUTE = 'roster_configure_rotation';
    public const string WATCHES_ROUTE = 'roster_configure_watches';
    public const string SETTINGS_ROUTE = 'roster_configure_settings';

    /** PUT A POST ON THE BOOKS — the door the Watches section's add row opens. */
    public const string ADD_TO_ROSTER_ROUTE = 'roster_configure_watches_add';

    /** DECLARE A ROTATION — the door the page header's "New rotation" opens. */
    public const string DECLARE_ROTATION_ROUTE = 'roster_configure_rotation_declare';

    /** The query that opens the rotation section on a blank declaration. */
    public const string NEW_QUERY = 'new';

    /** THE SHIFTS CARD AND THE RULES CARD ARE ONE FORM, so they are one write. */
    public const string SAVE_SHIFTS_AND_RULES_ROUTE = 'roster_configure_rules_save';

    /** And the two controls on that form that do something else. */
    public const string ADD_SHIFT_ROUTE = 'roster_configure_shift_add';
    public const string CLOSE_SHIFT_ROUTE = 'roster_configure_shift_close';

    public const string SAVE_ROTATION_ROUTE = 'roster_configure_rotation_save';
    public const string GENERATE_ROTATION_ROUTE = 'roster_configure_rotation_generate';
    public const string SAVE_WATCHES_ROUTE = 'roster_configure_watches_save';
    public const string SAVE_SETTINGS_ROUTE = 'roster_configure_settings_save';

    /**
     * WHAT A PERSON MUST HOLD TO CHANGE HOW THIS AREA'S ROSTER IS SET UP.
     *
     * The pair is COMPOSED from the declaration rather than retyped: a gate
     * whose concern or verb differs from the declared one by a character is a
     * screen nobody can open and an admin checkbox that grants nothing.
     */
    public const string CONFIGURE = RosterConcerns::ROSTER.'.'.Verb::Configure->value;

    /** One token id for the whole configure page; each form carries it. */
    public const string CSRF_TOKEN_ID = 'roster_configure';

    /**
     * THE ONE WORD A DOOR ON THE AREA'S OWN STATIONS CARD SENDS, so that it
     * returns to the card it was pressed on. It is a name and not an
     * address: see {@see backFrom()}.
     */
    public const string BACK_TO_THE_STATION = 'station';

    public function __construct(
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $router,
        private readonly RosterIdentityService $identity,
        private readonly RosterSettingsService $settings,
        private readonly ShiftVocabularyService $shifts,
        // THE FIVE RULES AND WHAT ONE STATION DOES DIFFERENTLY — and the one
        // writer of the columns every live surface still reads.
        private readonly ShiftRuleService $rules,
        private readonly StationWatchService $watches,
        private readonly StationRepository $stations,
        // WHO STANDS AT A POST — the ring a new rotation starts with draws
        // on the people the AREA posted there, which is the only list this
        // module could honestly seed a pool from.
        private readonly PostingRepository $postings,
        private readonly CheckInStatusService $checkInStatuses,
        private readonly RosteredPeople $people,
        private readonly RotationEditor $editor,
        private readonly RotationPreview $preview,
        private readonly RotationGenerator $generator,
        private readonly RotationRepository $rotations,
        private readonly AuthorizationCheckerInterface $authorization,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
    ) {
    }

    #[Route('/areas/{uuid}/modules/roster/rotation', name: self::ROTATION_ROUTE, requirements: ['uuid' => Requirement::UUID], methods: ['GET'])]
    public function rotation(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        Request $request,
    ): Response {
        $rotations = $this->rotations->findByArea($area);
        $chosen = $this->chosenRotation($rotations, $request->query->get('rotation'));

        return new Response($this->twig->render('@UhifadhiRoster/configure/rotation.html.twig', [
            'area' => $area,
            'band' => $this->identity->bandFor($area),
            'rotations' => $rotations,
            'chosen' => $chosen,
            'shifts' => $this->shifts->openFor($area),
            'candidates' => $this->people->rosteredIn($area),
            'restRules' => RestRule::cases(),
            'horizons' => RotationEditor::HORIZONS,
            'previewFeed' => $this->preview,
            'previewScope' => null === $chosen ? null : RotationPreview::scopeFor($chosen),
            'previewMonth' => $this->people->monthOf($request->query->get('month')),
            'draft' => null === $chosen ? null : self::draftOf($chosen),
            'csrfToken' => $this->csrfTokenManager->getToken(self::CSRF_TOKEN_ID)->getValue(),
            // The posts the area registers that run no ring at all. Named
            // rather than counted: "a post with no rotation is never counted,
            // reported on or called a hole" is only trustworthy if the page
            // says which posts it means.
            'postsWithoutARotation' => $this->postsWithoutARotation($area),
            // THE BLANK DECLARATION, opened by the page header's one accent
            // action. It is a STATE of this section rather than a page of
            // its own: a rotation is declared and then edited, and sending
            // somebody to a second address to do the first half would be
            // two screens for one act.
            'declaring' => $request->query->has(self::NEW_QUERY) || [] === $rotations,
            // WHAT A RING MAY BE DECLARED FOR: a post already on the books.
            // A post with no watch has no shifts for a ring to repeat, so
            // it is not offered — the Watches section is where that is
            // fixed, and the section strip is two clicks away.
            'declarable' => $this->declarablePosts($area),
            'presets' => RotationPreset::cases(),
            'mayManage' => $this->authorization->isGranted(self::CONFIGURE, $area),
        ]));
    }

    /**
     * PUT A POST ON THE ROSTER'S BOOKS — the one write that makes this
     * module work a post at all.
     *
     * IT IS DELIBERATE AND IT IS SMALL. The post arrives with the area's
     * default ring and no shift at all, which is exactly the honest state:
     * this module now keeps the post, and has not yet been told what it
     * stands. Everything after that is the Watches row itself.
     *
     * TWO DOORS, ONE WRITE. The Watches section's add row and the Roster
     * block on the area's own Stations configure card both post here, and
     * the second says so with `back` so it returns to the card it was
     * pressed on rather than to a page nobody asked for.
     */
    #[Route('/areas/{uuid}/modules/roster/watches/add', name: self::ADD_TO_ROSTER_ROUTE, requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    public function addToRoster(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        Request $request,
    ): Response {
        $this->guardWrite($area, $request);

        $station = $this->stationIn($area, $request->request->get('station'));
        if (null === $station) {
            self::flash($request, 'error', 'That post is not one of this area’s, so it cannot go on this area’s books.');

            return $this->backFrom($request, $area, null);
        }

        $this->watches->addToRoster($station);

        self::flash($request, 'success', \sprintf(
            '%s is on the roster’s books. Name the watches it stands, and it starts being counted.',
            $station->getName(),
        ));

        return $this->backFrom($request, $area, $station);
    }

    /**
     * DECLARE A ROTATION — what the page header's "New rotation" writes.
     *
     * THE RING IS THE PRESET FILLED WITH THE POST'S OWN WATCHES, and the
     * pool is whoever the area has posted there. Both are a starting point
     * and both are editable the moment the redirect lands: the point of the
     * door is that a post with a watch stops being a post that can never
     * generate anything.
     */
    #[Route('/areas/{uuid}/modules/roster/rotation/new', name: self::DECLARE_ROTATION_ROUTE, requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    public function declareRotation(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        Request $request,
    ): Response {
        $this->guardWrite($area, $request);

        $station = $this->stationIn($area, $request->request->get('station'));
        $preset = RotationPreset::tryFrom((string) $request->request->get('preset')) ?? RotationPreset::OneOfEachThenOff;

        if (null === $station) {
            self::flash($request, 'error', 'A rotation stands at a post, and that one is not on this area’s books.');

            return $this->backTo(self::ROTATION_ROUTE, $area);
        }

        $watch = $this->watches->forStation($station);
        if (null === $watch) {
            self::flash($request, 'error', \sprintf('%s is not on the roster’s books yet, so there is no watch for a ring to repeat.', $station->getName()));

            return $this->backTo(self::ROTATION_ROUTE, $area);
        }

        $pool = [];
        foreach ($this->postings->findStandingByStation($station) as $posting) {
            $person = $posting->getPerson();
            if (null !== $person) {
                $pool[] = $person;
            }
        }

        try {
            $teamName = trim((string) $request->request->get('team_name'));

            $rotation = '' === $teamName
                ? $this->editor->declareForPost($station, $watch->getExpects(), $preset, $pool)
                : $this->editor->declareForTeam($teamName, $station, $watch->getExpects(), $preset, $pool);
        } catch (\InvalidArgumentException $refused) {
            self::flash($request, 'error', $refused->getMessage());

            return $this->backTo(self::ROTATION_ROUTE, $area);
        }

        self::flash($request, 'success', \sprintf(
            'The rotation is declared — a %d-day ring drawing on %d. Generate it, and the days it produces reach the plan sheet and the handsets.',
            $rotation->getCycle()->length(),
            $rotation->getPool()->count(),
        ));

        return $this->backTo(self::ROTATION_ROUTE, $area, ['rotation' => $rotation->getUuid()->toRfc4122()]);
    }

    /**
     * WATCHES — this area's own shift names, the five rules, and which of
     * its stations runs what.
     *
     * THREE CARDS AND TWO FORMS, as drawn (layout A, ruled 21 sep): the
     * shifts and the rules stand side by side and are saved together
     * because they are one grid with one Save; the station table is full
     * width below and saved on its own, because toggling twelve stations
     * and typing a threshold are two acts a duty officer does at two
     * different moments.
     */
    #[Route('/areas/{uuid}/modules/roster/watches', name: self::WATCHES_ROUTE, requirements: ['uuid' => Requirement::UUID], methods: ['GET'])]
    public function watches(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
    ): Response {
        $watches = $this->watches->forArea($area);
        $shifts = $this->shifts->openFor($area);

        return new Response($this->twig->render('@UhifadhiRoster/configure/watches.html.twig', [
            'area' => $area,
            'band' => $this->identity->bandFor($area),
            'shifts' => $shifts,
            // HOW MANY STATIONS STAND EACH SHIFT — the quiet figure on a
            // shift's own row, and the one fact that makes closing one a
            // decision rather than a guess.
            'runCounts' => self::runCounts($shifts, $watches),
            // THE WHOLE PALETTE, AND WHO IS WEARING WHAT. The picker shows
            // all eighteen slots because the reader has to see what is left,
            // and a slot another shift wears is named by that shift rather
            // than merely disabled — "why can I not have that one" is the
            // first question a bare grey square asks.
            'slots' => self::paletteSlots($shifts),
            'freeSlots' => \count(array_filter(self::paletteSlots($shifts), static fn (?string $wornBy): bool => null === $wornBy)),
            'takenBy' => self::paletteTaken($shifts),
            'rules' => $this->rules->forArea($area),
            // THE CHOSEN RULES SEPARATELY, because they are a different
            // shape of answer and a row that had to ask which of two
            // arrays to look in would be a row carrying the enum's job.
            'ruleChoices' => $this->rules->choicesForArea($area),
            'ruleKinds' => RuleKind::cases(),
            // ONE ROW PER STATION THE AREA REGISTERS, not per station on
            // these books: the table is where a station joins them, so a
            // station missing from it could never be added.
            'rows' => $this->stationRows($area, $shifts),
            // The area's other posts — what "Add a post to the roster" offers.
            'postsOffTheBooks' => $this->postsOffTheBooks($area, $watches),
            'mayManage' => $this->authorization->isGranted(self::CONFIGURE, $area),
            'csrfToken' => $this->csrfTokenManager->getToken(self::CSRF_TOKEN_ID)->getValue(),
        ]));
    }

    /**
     * SAVE THE SHIFTS AND THE RULES — one grid, one Save, one write.
     *
     * A PER-CARD SAVE WAS REJECTED and it is worth writing down why: the two
     * cards are one row of the page and a reader treats them as one form, so
     * a Save that persisted half of what they had typed would be a page that
     * quietly threw work away. The drawn cta says "Save the rules"; it saves
     * the grid, which is what the person pressing it means.
     */
    #[Route('/areas/{uuid}/modules/roster/rules', name: self::SAVE_SHIFTS_AND_RULES_ROUTE, requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    public function saveShiftsAndRules(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        Request $request,
    ): Response {
        $this->guardWrite($area, $request);

        try {
            $this->applyShiftRows($area, $request);
            $this->rules->save($area, $this->submittedRules($request));
        } catch (\InvalidArgumentException $refused) {
            self::flash($request, 'error', $refused->getMessage());
        }

        return $this->backTo(self::WATCHES_ROUTE, $area);
    }

    /**
     * ADD A SHIFT. It arrives named, because a nameless row is a row nobody
     * can tell from the next one — and the name is this area's own from the
     * first keystroke after that.
     */
    #[Route('/areas/{uuid}/modules/roster/shifts/add', name: self::ADD_SHIFT_ROUTE, requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    public function addShift(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        Request $request,
    ): Response {
        $this->guardWrite($area, $request);

        try {
            // THE ROWS ALREADY ON THE PAGE ARE SAVED FIRST. Adding a shift
            // must not be a way to lose the three names somebody had just
            // corrected above it.
            $this->applyShiftRows($area, $request);

            $existing = $this->shifts->forArea($area);
            $shift = $this->shifts->add($area, self::freeShiftKey($existing), 'new shift', '06:00', '18:00');
            $shift->recolour(self::freeColourSlot($existing));
            $this->shifts->rename($shift, $shift->getLabel());
        } catch (\InvalidArgumentException $refused) {
            self::flash($request, 'error', $refused->getMessage());
        }

        return $this->backTo(self::WATCHES_ROUTE, $area);
    }

    /**
     * CLOSE A SHIFT. Closed and never deleted: the duties that named it are
     * what keep the word that described a past month, so it stops being
     * offered and every row that used it stays readable.
     */
    #[Route('/areas/{uuid}/modules/roster/shifts/close', name: self::CLOSE_SHIFT_ROUTE, requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    public function closeShift(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        Request $request,
    ): Response {
        $this->guardWrite($area, $request);

        $asked = $request->request->get('close');
        $subject = null;
        foreach ($this->shifts->forArea($area) as $shift) {
            if (\is_string($asked) && $shift->getUuid()->toRfc4122() === $asked) {
                $subject = $shift;
            }
        }

        if (null === $subject) {
            self::flash($request, 'error', 'That shift is not one of this area’s, so it cannot be closed here.');

            return $this->backTo(self::WATCHES_ROUTE, $area);
        }

        try {
            $this->applyShiftRows($area, $request);
            $this->shifts->close($subject, new \DateTimeImmutable('today'));
        } catch (\InvalidArgumentException $refused) {
            self::flash($request, 'error', $refused->getMessage());

            return $this->backTo(self::WATCHES_ROUTE, $area);
        }

        self::flash($request, 'success', \sprintf(
            '“%s” is closed. It stops being offered and every day that named it still reads.',
            $subject->getLabel(),
        ));

        return $this->backTo(self::WATCHES_ROUTE, $area);
    }

    #[Route('/areas/{uuid}/modules/roster/settings', name: self::SETTINGS_ROUTE, requirements: ['uuid' => Requirement::UUID], methods: ['GET'])]
    public function settings(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
    ): Response {
        return new Response($this->twig->render('@UhifadhiRoster/configure/settings.html.twig', [
            'area' => $area,
            'band' => $this->identity->bandFor($area),
            'settings' => $this->settings->forArea($area),
            // THE AREA'S LIST, READ AND NEVER WRITTEN. A check-in belongs to
            // the area and so do the statuses it can carry; this section
            // shows them because this is where somebody configuring the
            // roster looks for them, and links to where they are edited.
            'checkInStatuses' => $this->checkInStatuses->offeredBy($area),
            'mayManage' => $this->authorization->isGranted(self::CONFIGURE, $area),
            'csrfToken' => $this->csrfTokenManager->getToken(self::CSRF_TOKEN_ID)->getValue(),
        ]));
    }

    /**
     * SAVE THE CYCLE EDITOR'S DRAFT — the ring, the counts, the pool, the
     * rest rule, the anchor and the horizon, all in one write.
     *
     * SAVING IS NOT GENERATING. The two are separate acts and stay separate:
     * correcting a typo in a ring must not rewrite six weeks of duties on
     * the spot. The page offers "Generate from tomorrow" beside Save, and
     * that is the one that writes rows.
     */
    #[Route('/areas/{uuid}/modules/roster/rotation/{rotation}', name: self::SAVE_ROTATION_ROUTE, requirements: ['uuid' => Requirement::UUID, 'rotation' => Requirement::UUID], methods: ['POST'])]
    public function saveRotation(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        string $rotation,
        Request $request,
    ): Response {
        $this->guardWrite($area, $request);

        $subject = $this->rotations->findOneByUuid($area, Uuid::fromString($rotation));
        if (null === $subject) {
            throw new NotFoundHttpException('That rotation is not in this area.');
        }

        $shiftKeys = [];
        foreach ($this->shifts->openFor($area) as $shift) {
            $shiftKeys[$shift->getKey()] = $shift->getLabel();
        }

        try {
            $draft = RotationDraft::fromSubmitted(
                json_decode((string) $request->request->get('draft'), true),
                $shiftKeys,
            );
            $this->editor->apply($subject, $draft, $this->people->byUuid($area));
        } catch (\InvalidArgumentException $refused) {
            // REFUSED WHOLE AND SAID OUT LOUD. A draft is validated as one
            // object, so a malformed ring never lands half-applied — and the
            // person sees the sentence rather than a page that silently kept
            // the old ring.
            self::flash($request, 'error', $refused->getMessage());
        }

        return $this->backTo(self::ROTATION_ROUTE, $area, ['rotation' => $rotation]);
    }

    /**
     * RUN THE RING FROM TOMORROW. Today is left alone deliberately: people
     * are already standing today's watches, and regenerating the day
     * underneath them would move somebody who is at a post.
     */
    #[Route('/areas/{uuid}/modules/roster/rotation/{rotation}/generate', name: self::GENERATE_ROTATION_ROUTE, requirements: ['uuid' => Requirement::UUID, 'rotation' => Requirement::UUID], methods: ['POST'])]
    public function generateRotation(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        string $rotation,
        Request $request,
    ): Response {
        $this->guardWrite($area, $request);

        $subject = $this->rotations->findOneByUuid($area, Uuid::fromString($rotation));
        if (null === $subject) {
            throw new NotFoundHttpException('That rotation is not in this area.');
        }

        $from = new \DateTimeImmutable('tomorrow');
        $run = $this->generator->generate($subject, $from, $from->modify(\sprintf('+%d days', $subject->getHorizonDays())));

        self::flash($request, 'success', \sprintf(
            '%d watch%s written to %s.%s',
            $run->created,
            1 === $run->created ? '' : 'es',
            $run->through->format('j M Y'),
            [] === $run->protectedDays ? '' : \sprintf(' %d day%s somebody had edited were left alone.', $run->protectedDayCount(), 1 === $run->protectedDayCount() ? '' : 's'),
        ));

        return $this->backTo(self::ROTATION_ROUTE, $area, ['rotation' => $rotation]);
    }

    /**
     * SAVE THE STATION TABLE — which stations run which shifts, and what
     * each one does differently.
     *
     * IN ONE WRITE, because the table is one form with one Save. A per-row
     * save would let somebody leave the page having changed four of twelve
     * and believing they changed twelve.
     *
     * A STATION'S EXCEPTIONS ARE REPLACED BY WHAT THE ROW SENT, never
     * merged. Removing an exception is how a station goes back to following
     * the area, and the only thing that says so is the row's silence — so a
     * save that merged would make the cross on an exception do nothing.
     */
    #[Route('/areas/{uuid}/modules/roster/watches', name: self::SAVE_WATCHES_ROUTE, requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    public function saveWatches(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        Request $request,
    ): Response {
        $this->guardWrite($area, $request);

        foreach ($this->watches->forArea($area) as $watch) {
            $station = $watch->getStation();
            $id = (string) $station->getId();

            $this->watches->save(
                $watch,
                $this->shiftKeys($request, 'expects_'.$id),
                $watch->getSilenceWindowMinutes(),
                $watch->getOfflineAfterMinutes(),
            );

            try {
                $this->applyExceptions($station, $request, 'exception_'.$id);
            } catch (\InvalidArgumentException $refused) {
                self::flash($request, 'error', \sprintf('%s: %s', $station->getName() ?? 'That station', $refused->getMessage()));
            }
        }

        return $this->backTo(self::WATCHES_ROUTE, $area);
    }

    /**
     * ONE ROW PER STATION THE AREA REGISTERS — what it runs, what it needs,
     * and what it does differently.
     *
     * A STATION OFF THE BOOKS IS A ROW LIKE ANY OTHER, drawn quiet. The
     * table is the register of what this module works, so a station missing
     * from it would be a station nobody could ever add.
     *
     * @param list<Shift> $shifts
     *
     * @return list<array{station: Station, onTheBooks: bool, needs: int|null, runs: array<string, bool>, exceptions: list<array{kind: RuleKind, value: RuleValue}>}>
     */
    private function stationRows(AreaOfInterest $area, array $shifts): array
    {
        $exceptions = $this->rules->exceptionsForArea($area);

        $rows = [];
        foreach ($this->stations->findByArea($area) as $station) {
            $watch = $this->watches->forStation($station);

            $runs = [];
            foreach ($shifts as $shift) {
                $runs[$shift->getKey()] = null !== $watch && \in_array($shift->getKey(), $watch->getExpects(), true);
            }

            $own = [];
            foreach ($exceptions[(string) $station->getUuidString()] ?? [] as $exception) {
                $own[] = ['kind' => $exception->getKind(), 'value' => $exception->getValue()];
            }

            $rows[] = [
                'station' => $station,
                'onTheBooks' => null !== $watch,
                // HOW MANY PEOPLE THE PLACE NEEDS, added across the shifts
                // it stands. NULL and not zero where it has declared none:
                // a station nobody has stated a need for is short of
                // nobody, and a zero would read as a place that needs
                // nobody at all.
                'needs' => null === $watch ? null : self::needsTotal($watch, $shifts),
                'runs' => $runs,
                'exceptions' => $own,
            ];
        }

        return $rows;
    }

    /**
     * @param list<Shift> $shifts
     */
    private static function needsTotal(StationWatch $watch, array $shifts): ?int
    {
        $total = 0;
        foreach ($shifts as $shift) {
            $total += $watch->needsOn($shift->getKey());
        }

        return $total > 0 ? $total : null;
    }

    /**
     * HOW MANY STATIONS STAND EACH SHIFT.
     *
     * @param list<Shift>        $shifts
     * @param list<StationWatch> $watches
     *
     * @return array<string, int>
     */
    private static function runCounts(array $shifts, array $watches): array
    {
        $counts = [];
        foreach ($shifts as $shift) {
            $counts[$shift->getKey()] = 0;
        }

        foreach ($watches as $watch) {
            foreach ($watch->getExpects() as $key) {
                if (isset($counts[$key])) {
                    ++$counts[$key];
                }
            }
        }

        return $counts;
    }

    /**
     * THE SHIFT ROWS AS THE CARD SENT THEM — a name and a window this area
     * typed, and the palette slot it keeps on every surface.
     *
     * THE KEY IS NOT IN THE FORM, and cannot be: it is issued once and every
     * duty stores it, so a name corrected on this card renames the shift
     * everywhere and changes no stored row.
     *
     * @throws \InvalidArgumentException when a window is not a window
     */
    private function applyShiftRows(AreaOfInterest $area, Request $request): void
    {
        foreach ($this->shifts->forArea($area) as $shift) {
            $id = $shift->getUuid()->toRfc4122();

            $label = trim((string) $request->request->get('shift_label_'.$id, ''));
            if ('' !== $label && $label !== $shift->getLabel()) {
                $this->shifts->rename($shift, $label);
            }

            $start = trim((string) $request->request->get('shift_start_'.$id, ''));
            $end = trim((string) $request->request->get('shift_end_'.$id, ''));
            if ('' !== $start && '' !== $end && ($start !== $shift->getStartsAt() || $end !== $shift->getEndsAt())) {
                $this->shifts->moveWindow($shift, $start, $end);
            }

            $colour = $request->request->getInt('shift_colour_'.$id);
            if ($colour > 0 && $colour !== $shift->getColour()) {
                $shift->recolour($colour);
                // The vocabulary owns the flush; renaming to the label it
                // already has is a no-op write that commits the slot.
                $this->shifts->rename($shift, $shift->getLabel());
            }
        }
    }

    /**
     * THE RULES, AS THE CARD SENT THEM. A kind whose answer did not arrive
     * keeps what it had — a blank field is somebody who did not answer, not
     * somebody who answered zero.
     *
     * @return array<string, RuleValue|RuleChoiceInterface>
     */
    private function submittedRules(Request $request): array
    {
        $values = [];
        foreach (RuleKind::cases() as $kind) {
            if ($kind->isChoice()) {
                $picked = trim((string) $request->request->get('rule_choice_'.$kind->value, ''));
                if ('' === $picked) {
                    continue;
                }

                $values[$kind->value] = $kind->choiceOf($picked);

                continue;
            }

            $number = trim((string) $request->request->get('rule_value_'.$kind->value, ''));
            $unit = RuleUnit::tryFrom((string) $request->request->get('rule_unit_'.$kind->value, ''));

            if ('' === $number || !is_numeric($number) || null === $unit) {
                continue;
            }

            $values[$kind->value] = $kind->valueOf((float) $number, $unit);
        }

        return $values;
    }

    /**
     * WHAT ONE STATION DOES DIFFERENTLY, REPLACED BY WHAT ITS ROW SENT.
     *
     * @throws \InvalidArgumentException when a submitted pair cannot stand
     */
    private function applyExceptions(Station $station, Request $request, string $prefix): void
    {
        $kinds = $request->request->all($prefix.'_kind');
        $numbers = $request->request->all($prefix.'_value');
        $units = $request->request->all($prefix.'_unit');

        $sent = [];
        foreach ($kinds as $index => $raw) {
            $kind = RuleKind::tryFrom(\is_string($raw) ? $raw : '');
            $number = \is_scalar($numbers[$index] ?? null) ? trim((string) $numbers[$index]) : '';
            $unit = RuleUnit::tryFrom(\is_string($units[$index] ?? null) ? $units[$index] : '');

            if (null === $kind || null === $unit || '' === $number || !is_numeric($number)) {
                continue;
            }

            $sent[$kind->value] = $kind->valueOf((float) $number, $unit);
        }

        foreach (RuleKind::cases() as $kind) {
            if (isset($sent[$kind->value])) {
                $this->rules->setException($station, $kind, $sent[$kind->value]);

                continue;
            }

            $this->rules->clearException($station, $kind);
        }
    }

    /**
     * A KEY NO SHIFT IN THIS AREA HAS. It is an identifier and not a label:
     * every duty stores it and it is never edited, which is why it is
     * issued here rather than typed into the row.
     *
     * @param list<Shift> $existing
     */
    private static function freeShiftKey(array $existing): string
    {
        $taken = [];
        foreach ($existing as $shift) {
            $taken[$shift->getKey()] = true;
        }

        $n = \count($existing) + 1;
        while (isset($taken['shift_'.$n])) {
            ++$n;
        }

        return 'shift_'.$n;
    }

    /**
     * THE EIGHTEEN SLOTS, EACH WITH THE SHIFT WEARING IT OR NULL.
     *
     * @param list<Shift> $shifts
     *
     * @return array<int, string|null> keyed by slot, 1 to 18
     */
    private static function paletteSlots(array $shifts): array
    {
        $worn = [];
        foreach ($shifts as $shift) {
            $worn[$shift->getColour()] ??= $shift->getLabel();
        }

        $slots = [];
        for ($slot = Shift::FIRST_SLOT; $slot <= Shift::SLOTS; ++$slot) {
            $slots[$slot] = $worn[$slot] ?? null;
        }

        return $slots;
    }

    /**
     * WHICH SLOT EACH SHIFT IS WEARING, as the picker's own footer line.
     *
     * @param list<Shift> $shifts
     *
     * @return list<string>
     */
    private static function paletteTaken(array $shifts): array
    {
        $taken = [];
        foreach ($shifts as $shift) {
            $taken[] = $shift->getColour().' '.$shift->getLabel();
        }

        return $taken;
    }

    /**
     * THE FIRST PALETTE SLOT NOBODY IS WEARING, wrapping round the
     * eighteen — a fifth shift should not open the same colour as the first
     * while fourteen slots are free.
     *
     * @param list<Shift> $existing
     */
    private static function freeColourSlot(array $existing): int
    {
        $taken = [];
        foreach ($existing as $shift) {
            $taken[$shift->getColour()] = true;
        }

        for ($slot = Shift::FIRST_SLOT; $slot <= Shift::SLOTS; ++$slot) {
            if (!isset($taken[$slot])) {
                return $slot;
            }
        }

        return Shift::FIRST_SLOT;
    }

    #[Route('/areas/{uuid}/modules/roster/settings', name: self::SAVE_SETTINGS_ROUTE, requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    public function saveSettings(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        Request $request,
    ): Response {
        $this->guardWrite($area, $request);

        $current = $this->settings->forArea($area);

        /*
         * ONLY WHAT THIS PAGE STILL OWNS. The ping interval, the default
         * catchment and the late threshold became three of the five RULES
         * on the Watches section, so they are passed through untouched
         * here: a second editor for one fact is a fact that disagrees with
         * itself the first time somebody uses the other one.
         */
        $this->settings->save(
            $area,
            $current->getPingIntervalMinutes(),
            $request->request->getBoolean('off_day_has_no_state', $current->offDayHasNoState()),
            $request->request->getBoolean('leave_approval_shown', $current->isLeaveApprovalShown()),
            $current->getDefaultCatchmentMetres(),
            $current->getLateThreshold(),
            $current->getVacancyAnnounce(),
        );

        return $this->backTo(self::SETTINGS_ROUTE, $area);
    }

    /**
     * THE TWO THINGS EVERY WRITE ON THIS PAGE ASKS, in one place so neither
     * can be forgotten on the next form: does this person hold the
     * permission, and did this request come from the page.
     */
    private function guardWrite(AreaOfInterest $area, Request $request): void
    {
        if (!$this->authorization->isGranted(self::CONFIGURE, $area)) {
            throw new AccessDeniedHttpException('Changing how this area runs its roster needs the "'.self::CONFIGURE.'" grant.');
        }

        $token = $request->request->get('_token');
        if (!\is_string($token) || !$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_TOKEN_ID, $token))) {
            throw new AccessDeniedHttpException('That form did not come from this page.');
        }
    }

    /**
     * @return list<string>
     */
    private function shiftKeys(Request $request, string $field): array
    {
        $submitted = $request->request->all($field);

        return array_values(array_filter(
            array_map(static fn (mixed $key): string => \is_string($key) ? $key : '', $submitted),
            static fn (string $key): bool => '' !== $key,
        ));
    }

    /**
     * @param list<StationWatch> $watches
     *
     * @return list<Station>
     */
    private function postsOffTheBooks(AreaOfInterest $area, array $watches): array
    {
        $onTheBooks = [];
        foreach ($watches as $watch) {
            $onTheBooks[(string) $watch->getStation()->getId()] = true;
        }

        return array_values(array_filter(
            $this->stations->findByArea($area),
            static fn (Station $station): bool => !isset($onTheBooks[(string) $station->getId()]),
        ));
    }

    /**
     * ONE OF THIS AREA'S POSTS, BY UUID — and nothing else.
     *
     * THE AREA IS PART OF THE LOOKUP, not a check after it. A uuid out of a
     * form names any post in the installation, and a write that trusted it
     * would let a form posted from one park's configure page put another
     * park's gate on these books.
     */
    private function stationIn(AreaOfInterest $area, mixed $uuid): ?Station
    {
        if (!\is_string($uuid) || !Uuid::isValid($uuid)) {
            return null;
        }

        $station = $this->stations->findOneBy(['uuid' => Uuid::fromString($uuid)]);

        return $station instanceof Station && $station->getArea()?->getId() === $area->getId() ? $station : null;
    }

    /**
     * WHERE A DOOR PRESSED SOMEWHERE ELSE GOES BACK TO.
     *
     * A NAME, NEVER A URL. The Roster block on the area's Stations configure
     * card posts here too, and it says where it came from with one known
     * word — anything else in that field is the Watches section, because a
     * redirect built from a submitted address is an open redirect however
     * politely it is asked.
     */
    private function backFrom(Request $request, AreaOfInterest $area, ?Station $station): RedirectResponse
    {
        if (self::BACK_TO_THE_STATION === $request->request->get('back')) {
            return new RedirectResponse($this->router->generate(
                StationConfigureController::ROUTE,
                ['uuid' => (string) $area->getUuidString()]
                    + (null === $station ? [] : [StationConfigureController::OPEN_QUERY => (string) $station->getUuidString()]),
            ));
        }

        return $this->backTo(self::WATCHES_ROUTE, $area);
    }

    /**
     * THE POSTS A RING MAY BE DECLARED FOR — on the books, standing at least
     * one watch, and not already running one.
     *
     * @return list<Station>
     */
    private function declarablePosts(AreaOfInterest $area): array
    {
        $posts = [];
        foreach ($this->watches->forArea($area) as $watch) {
            $station = $watch->getStation();

            if (!$watch->expectsNothing() && null === $this->rotations->findOneForStation($station)) {
                $posts[] = $station;
            }
        }

        return $posts;
    }

    /**
     * @return list<Station>
     */
    private function postsWithoutARotation(AreaOfInterest $area): array
    {
        return array_values(array_filter(
            $this->stations->findByArea($area),
            fn (Station $station): bool => null === $this->rotations->findOneForStation($station),
        ));
    }

    /**
     * BACK TO THE SECTION THAT WAS SAVED, as a redirect: a POST answered with
     * a rendered page is a page a refresh re-submits.
     */
    /**
     * SAY IT IN THE FRAME'S OWN FLASHES.
     *
     * A session only carries a flash bag where the application gave it one
     * — `SessionInterface` does not promise it — so a page that assumed one
     * would 500 on an installation with a stateless session rather than
     * merely losing a sentence. The message is the lesser loss.
     */
    private static function flash(Request $request, string $type, string $message): void
    {
        $session = $request->hasSession() ? $request->getSession() : null;

        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add($type, $message);
        }
    }

    /**
     * @param array<string, scalar> $extra
     */
    private function backTo(string $route, AreaOfInterest $area, array $extra = []): RedirectResponse
    {
        $uuid = $area->getUuidString();

        if (null === $uuid) {
            throw new NotFoundHttpException('That area has no identifier to return to.');
        }

        return new RedirectResponse($this->router->generate($route, ['uuid' => $uuid] + $extra));
    }

    /**
     * WHICH ROTATION THE EDITOR IS ON — the one asked for, or the first.
     *
     * @param list<Rotation> $rotations
     */
    private function chosenRotation(array $rotations, mixed $asked): ?Rotation
    {
        if ([] === $rotations) {
            return null;
        }

        if (\is_string($asked) && Uuid::isValid($asked)) {
            foreach ($rotations as $rotation) {
                if ($rotation->getUuid()->toRfc4122() === $asked) {
                    return $rotation;
                }
            }
        }

        return $rotations[0];
    }

    /**
     * THE ROTATION AS THE EDITOR STARTS FROM IT. Serialised into one field
     * so the server validates the draft as one object rather than field by
     * field.
     *
     * @return array<string, mixed>
     */
    private static function draftOf(Rotation $rotation): array
    {
        return [
            'cycle' => $rotation->getCycle()->toStored(),
            'slots' => $rotation->getSlotsPerShift(),
            'pool' => array_values(array_map(
                static fn (RotationPoolMember $member): string => (string) $member->getPerson()->getUuidString(),
                $rotation->getPool()->toArray(),
            )),
            'standDown' => $rotation->getStandDownWeekdays(),
            'restRule' => $rotation->getRestRule()->value,
            'horizonDays' => $rotation->getHorizonDays(),
            'anchoredOn' => $rotation->getAnchoredOn()->format('Y-m-d'),
        ];
    }
}
