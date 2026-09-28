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

use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Twig\Environment;
use Uhifadhi\Contracts\Entity\UserInterface;
use Uhifadhi\Roster\Service\MyRosterService;

/**
 * MY ROSTER (#19; design variants-my-dashboard/roster.html, approved 28 Sep
 * 2026): the week at the post the signed-in person is posted at — everybody
 * posted there, day by day, against what the post expects — then their next
 * watches, their swaps and who is away. The page behind the My roster door
 * and card on their own dashboard.
 *
 * IT NAMES NO PERMISSION, deliberately: it shows nothing but the post the
 * signed-in person is posted at, which is theirs to read whatever their
 * grants — the same reason the core's My station page names none. The
 * module's route test lists it as open, with that reason.
 *
 * NOT AN AREA ROUTE. It carries no `{uuid}` and no `_uhifadhi_module`
 * default, because it is not a page of one area's roster: the area is the
 * one the person is posted in, and a module parked there answers with a page
 * that says there is no roster rather than a 404.
 *
 * THE INSTANT IS THE CLOCK'S where the installation has one, so a suite can
 * pin the hour "next" is measured from.
 *
 * @see https://symfony.com/doc/current/components/clock.html
 * @see vendor/symfony/security-core/Authentication/Token/Storage/TokenStorageInterface.php
 */
final readonly class RosterMeController
{
    public const string ROUTE = 'roster_me';

    public function __construct(
        private Environment $twig,
        private TokenStorageInterface $tokens,
        private MyRosterService $roster,
        private ?ClockInterface $clock = null,
    ) {
    }

    #[Route('/me/roster', name: self::ROUTE, methods: ['GET'])]
    public function roster(): Response
    {
        $user = $this->tokens->getToken()?->getUser();
        $uuid = $user instanceof UserInterface ? $user->getUuidString() : null;
        if (null === $uuid) {
            throw new AccessDeniedException('A roster of your own needs an account of your own.');
        }

        $now = $this->clock?->now() ?? new \DateTimeImmutable();

        return new Response($this->twig->render('@UhifadhiRoster/me/roster.html.twig', [
            'roster' => $this->roster->for($uuid, $now),
            'today' => $now->setTime(0, 0),
        ]));
    }
}
