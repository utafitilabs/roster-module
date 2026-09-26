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

namespace Uhifadhi\Roster\Tests\Integration\Fixtures;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Uhifadhi\Bundle\AreaBundle\Access\AreaConcerns;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Contracts\Access\Grant;
use Uhifadhi\Contracts\Access\Verb;
use Uhifadhi\Roster\Controller\RosterConfigureController;
use Uhifadhi\Roster\Controller\RosterController;

/**
 * Test stand-in for the INSTALLATION's permission voter: this module only
 * DECLARES the roster concern; deciding who holds its verbs is Team's job.
 *
 * TWO ACCOUNTS AND A DIFFERENT ANSWER FOR EACH, on purpose. A blanket "may do
 * everything" stub could never show the case the configure page is built
 * around — somebody who can READ how the area is set up and cannot change
 * it — and that reader is the commonest visitor the page has.
 *
 * @extends Voter<string, mixed>
 */
final class FixedManageVoter extends Voter
{
    /** Holds both: may change the rotations, and may offer a watch. */
    public const string MANAGER_EMAIL = 'manager@example.test';

    /** The area's control-room grant, lifting the rank rule for live positions. */
    public const string CONTROL_ROOM = 'locations.read';

    /** Holds nothing: reads the configure page and saves nothing. */
    public const string READER_EMAIL = 'reader@example.test';

    /**
     * THE GROUND'S OWN PAIRS, none of them this module's to declare and none
     * of them ever checked by it. They are granted here because this module
     * CONTRIBUTES to the area's and the organization's own screens and
     * answers the area's own endpoint, and a suite that could not open them
     * would be testing the contribution in a vacuum.
     *
     * THEY ARE SPELT FROM THE AREA'S OWN DECLARATION so that a rename
     * upstream breaks this file rather than quietly closing every page it
     * opens. `area.view` and `duty.checkin` were the old machinery's words
     * and named nothing the core enforces any more.
     *
     * @return list<string>
     */
    private static function groundPairs(): array
    {
        return [
            (string) Grant::of(AreaConcerns::AREAS, Verb::Read),
            (string) Grant::of(AreaConcerns::ZONES, Verb::Read),
            (string) Grant::of(AreaConcerns::STATIONS, Verb::Read),
            (string) Grant::of(AreaConcerns::STATIONS, Verb::Configure),
            (string) Grant::of(AreaConcerns::ASSIGNMENTS, Verb::Manage),
            (string) Grant::of(AreaConcerns::DUTY, Verb::Record),
        ];
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [
            RosterConfigureController::CONFIGURE,
            RosterController::RECORD,
            // WHO MAY OPEN THE AREA'S OWN SETTINGS — the manager's, like the
            // roster's two, so a reader is shown no door into them.
            (string) Grant::of(AreaConcerns::AREAS, Verb::Configure),
            // THE CONTROL ROOM: the manager sees every live position, so the
            // Live pages have somebody to stream to whatever the rank rule
            // says. Spelt as a string because a core older than the rule
            // does not declare it, and there the grant changes nothing.
            self::CONTROL_ROOM,
            ...self::groundPairs(),
        ], true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        // ANY SIGNED-IN ACCOUNT MAY READ THE PARK AND REPORT ITS OWN DAY.
        // The two roster permissions are the manager's alone; the ground's
        // are everybody's here, which is what makes "me" mean the token's
        // account.
        if (\in_array($attribute, self::groundPairs(), true)) {
            return true;
        }

        return self::MANAGER_EMAIL === $user->getEmail();
    }
}
