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

namespace Uhifadhi\Roster\Access;

use Uhifadhi\Bundle\AreaBundle\Repository\PostingRepository;
use Uhifadhi\Contracts\Access\Power;
use Uhifadhi\Contracts\Access\PowerGroup;
use Uhifadhi\Contracts\Access\PowerSourceInterface;
use Uhifadhi\Contracts\Access\PowerTarget;
use Uhifadhi\Contracts\Entity\UserInterface;
use Uhifadhi\Roster\Controller\RosterConfigureController;
use Uhifadhi\Roster\Controller\RosterController;
use Uhifadhi\Roster\Repository\DutyRepository;

/**
 * WHAT THE ROSTER LETS ONE PERSON DO TO ANOTHER'S WATCHES, for Team ›
 * Permissions: putting somebody on a watch, moving, changing or clearing it,
 * giving them the day off; offering their watch to somebody else; and
 * generating a rotation's watches for everybody in its pool.
 *
 * EACH IS ASKED OF AN AREA, because the routes ask their pair of the area the
 * watch stands in: the area of the person's latest watch, or, for somebody
 * not yet on one, the area of their standing posting — the posting is what
 * makes a person rosterable there at all. Somebody with neither is somebody
 * the roster cannot reach, and is asked of nothing.
 *
 * @see https://symfony.com/doc/current/doctrine.html#fetching-objects-from-the-database — findOneBy() takes the ordering as its second argument
 */
final readonly class RosterPowers implements PowerSourceInterface
{
    public const string CHANGE_A_WATCH = 'roster-change-another-rangers-watch';
    public const string OFFER_A_WATCH = 'roster-offer-another-rangers-watch';
    public const string GENERATE_WATCHES = 'roster-generate-another-rangers-watches';

    private const array TARGETS = [PowerTarget::AColleague, PowerTarget::SomebodyBeyondTheirArea];

    public function __construct(
        private DutyRepository $duties,
        private PostingRepository $postings,
    ) {
    }

    public function powers(): iterable
    {
        $records = PowerGroup::OthersRecords;

        yield new Power(self::CHANGE_A_WATCH, $records, 'put another ranger on a watch, move, change or clear it, or give them the day off', [RosterController::RECORD], self::TARGETS);
        yield new Power(self::OFFER_A_WATCH, $records, 'offer another ranger’s watch to somebody else, or take the offer back', [RosterController::RECORD], self::TARGETS);
        yield new Power(self::GENERATE_WATCHES, $records, 'generate the watches of every ranger in a rotation', [RosterConfigureController::CONFIGURE], self::TARGETS);
    }

    public function subject(Power $power, string $question, PowerTarget $kind, ?object $target): mixed
    {
        if (!$target instanceof UserInterface) {
            return null;
        }

        $latest = $this->duties->findOneBy(['person' => $target], ['onDay' => 'DESC', 'id' => 'DESC']);
        if (null !== $latest) {
            return $latest->getArea();
        }

        foreach ($this->postings->findStandingByPerson($target) as $posting) {
            $area = $posting->getStation()?->getArea();
            if (null !== $area) {
                return $area;
            }
        }

        return null;
    }
}
