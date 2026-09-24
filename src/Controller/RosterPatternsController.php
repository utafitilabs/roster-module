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
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;
use Twig\Environment;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Roster\Entity\Pattern;
use Uhifadhi\Roster\Model\Cycle;
use Uhifadhi\Roster\Module\RosterModuleProvider;
use Uhifadhi\Roster\Repository\PatternRepository;
use Uhifadhi\Roster\Service\PatternNamer;
use Uhifadhi\Roster\Service\PatternService;
use Uhifadhi\Roster\Service\RosterIdentityService;

/**
 * PATTERNS — the cycles this area fills a station from, and the editor that
 * says one.
 *
 * NOTHING HERE IS TYPED EXCEPT A NUMBER AND A SHIFT. Ruled 21 sep: "the name
 * should not be written by user but rather generated from the config the user
 * chooses." The editor posts the CYCLE and the server derives the name from
 * it again through the same function the page shows live, so the register,
 * this editor and the sheet's fill row cannot print three different names for
 * one object.
 *
 * AND APPLYING ONE IS NOT HERE. A pattern is given to a station from the Week
 * sheet's fill row, where the days are — this page lists the cycles and says
 * which stations run each, and offers no station picker at all.
 */
#[Route(defaults: ['_uhifadhi_module' => RosterModuleProvider::SLUG])]
final class RosterPatternsController
{
    public const string ROUTE = 'roster_configure_patterns';
    public const string SAVE_ROUTE = 'roster_configure_pattern_save';
    public const string DELETE_ROUTE = 'roster_configure_pattern_delete';

    /** Which pattern the editor is open on. */
    public const string OPEN_QUERY = 'pattern';

    /** And the query that opens it on a cycle nobody has said yet. */
    public const string NEW_QUERY = 'new';

    /**
     * THE CYCLE, AS THE EDITOR POSTS IT — one field holding the parts in
     * order, because a cycle is validated as ONE object: a ring that is
     * half-applied is a ring that plans a month nobody asked for.
     */
    public const string CYCLE_FIELD = 'cycle';

    public function __construct(
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $router,
        private readonly RosterIdentityService $identity,
        private readonly PatternService $patterns,
        private readonly PatternRepository $register,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
    ) {
    }

    #[Route('/areas/{uuid}/modules/roster/patterns', name: self::ROUTE, requirements: ['uuid' => Requirement::UUID], methods: ['GET'])]
    #[IsGranted('areas.read', subject: 'area')]
    public function index(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        Request $request,
    ): Response {
        $patterns = $this->patterns->forArea($area);
        $open = $this->openPattern($area, $request);

        return new Response($this->twig->render('@UhifadhiRoster/configure/patterns.html.twig', [
            'area' => $area,
            'band' => $this->identity->bandFor($area),
            // THE REGISTER, each with the one thing a reader wants before
            // they edit it: which places it reaches.
            'cards' => array_map(fn (Pattern $pattern): array => $this->cardOf($pattern), $patterns),
            'open' => null === $open ? null : $this->cardOf($open),
            // THE CYCLE AS RUNS OF THE SAME DAY, which is the shape the
            // sentence editor is: "2 days of day, then 2 days of night".
            // One derivation for the name and for the editor, so the two
            // cannot read the cycle differently.
            'runs' => $this->editableRuns($area, $open),
            // A BLANK CYCLE IS A STATE OF THIS PAGE, not an address of its
            // own: a pattern is said and then saved, and sending somebody
            // to a second screen to start would be two pages for one act.
            'declaring' => null === $open && $request->query->has(self::NEW_QUERY),
            'shifts' => $this->patterns->shiftsFor($area),
            'csrfToken' => $this->csrfTokenManager->getToken(RosterConfigureController::CSRF_TOKEN_ID)->getValue(),
        ]));
    }

    /**
     * THE PARTS THE SENTENCE EDITOR OPENS ON — the pattern's own runs, or
     * one blank part for a cycle nobody has said yet.
     *
     * A BLANK EDITOR STILL HAS A PART, because an editor with no line in it
     * offers nothing to change: the first thing somebody wants to do is
     * raise or lower a number, not find the control that creates a line.
     *
     * @return list<array{key: string, days: int}>
     */
    private function editableRuns(AreaOfInterest $area, ?Pattern $pattern): array
    {
        if (null === $pattern) {
            $first = $this->patterns->shiftsFor($area)[0] ?? null;

            return [['key' => null === $first ? Cycle::OFF : $first->getKey(), 'days' => 1]];
        }

        $runs = [];
        foreach (PatternNamer::runs($pattern->getCycle()) as [$entry, $days]) {
            $runs[] = ['key' => $entry, 'days' => $days];
        }

        return $runs;
    }

    /**
     * SAY A CYCLE, OR CHANGE ONE. The same write, because the two are the
     * same act: a pattern with no uuid is one nobody has saved yet.
     *
     * EDITING CHANGES FUTURE FILLS ONLY, and that is guaranteed by what this
     * does NOT do — it writes a cycle and no duty, at any station running it.
     */
    #[Route('/areas/{uuid}/modules/roster/patterns', name: self::SAVE_ROUTE, requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted('roster.configure', subject: 'area')]
    public function save(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        Request $request,
    ): Response {
        $this->guardTheForm($request);

        try {
            $cycle = $this->submittedCycle($area, $request);
        } catch (\InvalidArgumentException $refused) {
            self::flash($request, 'error', $refused->getMessage());

            return $this->back($area);
        }

        $asked = $request->request->get(self::OPEN_QUERY);
        $subject = \is_string($asked) && '' !== $asked ? $this->patternIn($area, $asked) : null;

        if (\is_string($asked) && '' !== $asked && null === $subject) {
            throw new NotFoundHttpException('That pattern is not in this area.');
        }

        $pattern = null === $subject
            ? $this->patterns->create($area, $cycle)
            : $this->patterns->save($subject, $cycle);

        self::flash($request, 'success', \sprintf(
            '“%s” is saved. It reaches future fills only — no day already planned moves, at any station running it.',
            $this->patterns->nameOf($pattern),
        ));

        return $this->back($area, [self::OPEN_QUERY => $pattern->getUuid()->toRfc4122()]);
    }

    /**
     * REMOVE A PATTERN. The stations that ran it stop being filled and keep
     * everything else, every planned day included.
     */
    #[Route('/areas/{uuid}/modules/roster/patterns/{pattern}/delete', name: self::DELETE_ROUTE, requirements: ['uuid' => Requirement::UUID, 'pattern' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted('roster.configure', subject: 'area')]
    public function delete(
        #[MapEntity(mapping: ['uuid' => 'uuid'])] AreaOfInterest $area,
        string $pattern,
        Request $request,
    ): Response {
        $this->guardTheForm($request);

        $subject = $this->patternIn($area, $pattern);
        if (null === $subject) {
            throw new NotFoundHttpException('That pattern is not in this area.');
        }

        $stations = \count($this->patterns->stationsRunning($subject));
        $name = $this->patterns->nameOf($subject);
        $this->patterns->delete($subject);

        self::flash($request, 'success', \sprintf(
            '“%s” is gone. %s',
            $name,
            0 === $stations
                ? 'No station was being filled from it.'
                : \sprintf('%d station%s stopped being filled; every day already planned stays.', $stations, 1 === $stations ? '' : 's'),
        ));

        return $this->back($area);
    }

    /**
     * ONE PATTERN AS EVERY SURFACE ON THIS PAGE READS IT — the derived name,
     * the cycle drawn as days, and where it runs.
     *
     * ONE SHAPE FOR THE REGISTER AND THE EDITOR, deliberately: they print
     * the same three facts, and building them separately is how a card and
     * the editor beside it end up disagreeing about a pattern's length.
     *
     * @return array{pattern: Pattern, uuid: string, name: string, length: int, days: list<array{key: string, colour: int|null}>, stations: list<string>}
     */
    private function cardOf(Pattern $pattern): array
    {
        $colours = $this->patterns->coloursFor($pattern->getArea());

        $days = [];
        foreach ($pattern->getCycle()->positions as $entry) {
            $days[] = ['key' => $entry, 'colour' => Cycle::OFF === $entry ? null : ($colours[$entry] ?? null)];
        }

        $stations = [];
        foreach ($this->patterns->stationsRunning($pattern) as $station) {
            $stations[] = $station->getName() ?? '—';
        }

        return [
            'pattern' => $pattern,
            'uuid' => $pattern->getUuid()->toRfc4122(),
            'name' => $this->patterns->nameOf($pattern),
            'length' => $pattern->length(),
            'days' => $days,
            'stations' => $stations,
        ];
    }

    /**
     * THE CYCLE THE EDITOR SENT, read as parts and refused whole.
     *
     * THE SHIFT IS CHECKED AGAINST THIS AREA'S OWN LIST. A cycle naming a
     * shift the area does not have would generate a watch nobody could read,
     * and the form is the only place that can still say so in a sentence.
     *
     * @throws \InvalidArgumentException when a part names no shift, no days, or a shift this area has not got
     */
    private function submittedCycle(AreaOfInterest $area, Request $request): Cycle
    {
        $known = [Cycle::OFF => true];
        foreach ($this->patterns->shiftsFor($area) as $shift) {
            $known[$shift->getKey()] = true;
        }

        $submitted = json_decode((string) $request->request->get(self::CYCLE_FIELD), true);
        if (!\is_array($submitted)) {
            throw new \InvalidArgumentException('The cycle did not arrive. Say at least one part — so many days of a shift, or so many off.');
        }

        $positions = [];
        foreach ($submitted as $part) {
            if (!\is_array($part)) {
                throw new \InvalidArgumentException('A part of a cycle is a number of days and a shift.');
            }

            $raw = $part['days'] ?? 0;
            $days = is_numeric($raw) ? (int) $raw : 0;
            $entry = \is_string($part['shift'] ?? null) ? $part['shift'] : '';

            if ($days < 1) {
                continue;
            }

            if (!isset($known[$entry])) {
                throw new \InvalidArgumentException(\sprintf('This area has no shift called “%s”, so a cycle cannot stand one.', $entry));
            }

            for ($day = 0; $day < $days; ++$day) {
                $positions[] = $entry;
            }
        }

        return Cycle::of($positions);
    }

    /** Which pattern the editor is open on, where the page asked for one. */
    private function openPattern(AreaOfInterest $area, Request $request): ?Pattern
    {
        $asked = $request->query->get(self::OPEN_QUERY);

        return \is_string($asked) ? $this->patternIn($area, $asked) : null;
    }

    /**
     * ONE OF THIS AREA'S PATTERNS, BY UUID — and nothing else.
     *
     * THE AREA IS PART OF THE LOOKUP rather than a check after it: a uuid
     * out of a form names any pattern in the installation, and a write that
     * trusted it would let one park's page edit another park's cycle.
     */
    private function patternIn(AreaOfInterest $area, string $uuid): ?Pattern
    {
        return Uuid::isValid($uuid) ? $this->register->findOneByUuid($area, Uuid::fromString($uuid)) : null;
    }

    /**
     * DID THIS REQUEST COME FROM THE PAGE — the one thing an action still
     * asks for itself. Who may write is the route's `#[IsGranted]`.
     */
    private function guardTheForm(Request $request): void
    {
        $token = $request->request->get('_token');
        if (!\is_string($token) || !$this->csrfTokenManager->isTokenValid(new CsrfToken(RosterConfigureController::CSRF_TOKEN_ID, $token))) {
            throw new AccessDeniedHttpException('That form did not come from this page.');
        }
    }

    /**
     * @param array<string, scalar> $extra
     */
    private function back(AreaOfInterest $area, array $extra = []): RedirectResponse
    {
        $uuid = $area->getUuidString();

        if (null === $uuid) {
            throw new NotFoundHttpException('That area has no identifier to return to.');
        }

        return new RedirectResponse($this->router->generate(self::ROUTE, ['uuid' => $uuid] + $extra));
    }

    /**
     * A session only carries a flash bag where the application gave it one,
     * so a page that assumed one would 500 on a stateless installation
     * rather than merely losing a sentence. The message is the lesser loss.
     */
    private static function flash(Request $request, string $type, string $message): void
    {
        $session = $request->hasSession() ? $request->getSession() : null;

        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add($type, $message);
        }
    }
}
