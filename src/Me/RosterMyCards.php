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

namespace Uhifadhi\Roster\Me;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;
use Uhifadhi\Contracts\Me\MyCard;
use Uhifadhi\Contracts\Me\MyCardProviderInterface;
use Uhifadhi\Roster\Controller\RosterMeController;
use Uhifadhi\Roster\Service\MyRosterService;

/**
 * WHAT THE ROSTER SHOWS A PERSON ABOUT THEMSELVES on their own dashboard (#19,
 * option A of variants-my-dashboard, ruled 28 Sep 2026): the door to My
 * roster, the My roster card — this week, the next watch, who stands mine
 * with me today — and the My leave card.
 *
 * ONLY THIS PERSON'S OWN RECORDS and the post they are posted at, whatever
 * their grants: a ranger who may read nothing of the areas is shown their
 * week because it is theirs.
 *
 * THE SLOTS ARE THE APPROVED LAYOUT'S: the door first in the row of doors
 * (10, ahead of the area's My station and My duty log), the card first in
 * the left column (10, above the area's My station), and the leave card in
 * the row at the foot (25, before the team's phone).
 */
final readonly class RosterMyCards implements MyCardProviderInterface
{
    public function __construct(
        private Environment $twig,
        private MyRosterService $roster,
        private UrlGeneratorInterface $router,
    ) {
    }

    public function cardsFor(string $personUuid, \DateTimeImmutable $now): array
    {
        $cards = [];
        $page = $this->router->generate(RosterMeController::ROUTE);

        $roster = $this->roster->for($personUuid, $now);
        if (null !== $roster) {
            $cards[] = new MyCard(MyCard::DOOR, 10, \sprintf('<a href="%s"><b>My roster</b> &middot; the week at %s</a>', $page, htmlspecialchars((string) $roster->station->getName())));
            $cards[] = new MyCard(MyCard::LEFT, 10, $this->twig->render('@UhifadhiRoster/me/_roster_card.html.twig', [
                'roster' => $roster,
                'today' => $now->setTime(0, 0),
                'door' => $page,
            ]));
        }

        // THE LEAVE CARD WHERE THE ROSTER HAS ANYTHING TO SAY: for somebody
        // it rosters, or somebody it recorded an absence for. A person no
        // roster knows is shown nothing rather than an empty year.
        $leave = $this->roster->leaveFor($personUuid, $now);
        if (null !== $roster || [] !== $leave->absences) {
            $cards[] = new MyCard(MyCard::ROW, 25, $this->twig->render('@UhifadhiRoster/me/_leave_card.html.twig', ['leave' => $leave]));
        }

        return $cards;
    }
}
