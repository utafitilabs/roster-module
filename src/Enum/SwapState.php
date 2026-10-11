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

namespace Uhifadhi\Roster\Enum;

/**
 * HOW FAR A SWAP HAS GOT.
 *
 * THE HANDSET ACCEPTS, AND NOBODY ELSE CAN. A swap is an OFFER between two
 * people; a duty officer who could accept on somebody's behalf would be a
 * duty officer who can move a ranger's rest day without telling them, and
 * the whole reason a swap is not just an edit is that the other person
 * agreed.
 *
 * AN OFFER THAT IS NOT ACCEPTED CHANGES NOTHING. Until it is, both duties
 * stand exactly as the rotation generated them and the roster reads as
 * though no swap existed — which is what makes an unaccepted swap safe to
 * leave open over a weekend.
 */
enum SwapState: string
{
    /** Sent to the handset, waiting. Neither duty has moved. */
    case Offered = 'offered';

    /**
     * Agreed by the person asked, at a station that requires its head's
     * approval (#60): neither duty has moved until the head approves.
     */
    case Waiting = 'waiting';

    /** Agreed, and the duties have changed hands. */
    case Accepted = 'accepted';

    /** The other person said no. Both duties stand as they were. */
    case Declined = 'declined';

    /** Taken back by whoever offered it, before an answer. */
    case Withdrawn = 'withdrawn';

    /** Agreed, and refused by the station's head. Both duties stand as they were. */
    case Refused = 'refused';

    public function label(): string
    {
        return match ($this) {
            self::Offered => 'offered',
            self::Waiting => 'waiting for the head',
            self::Accepted => 'accepted',
            self::Declined => 'declined',
            self::Withdrawn => 'withdrawn',
            self::Refused => 'refused by the head',
        };
    }

    /** Whether this swap is still waiting on somebody. */
    public function isOpen(): bool
    {
        return self::Offered === $this || self::Waiting === $this;
    }

    /** Whether it moved anybody. */
    public function changedTheRoster(): bool
    {
        return self::Accepted === $this;
    }
}
