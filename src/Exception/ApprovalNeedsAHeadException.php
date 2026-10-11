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

namespace Uhifadhi\Roster\Exception;

use Uhifadhi\Bundle\AreaBundle\Entity\Station;

/**
 * A STATION WITH NO HEAD CANNOT REQUIRE ITS HEAD'S APPROVAL: approval is the
 * head's, and a station without one would leave every swap waiting on nobody.
 */
final class ApprovalNeedsAHeadException extends \DomainException
{
    public function __construct(Station $station)
    {
        parent::__construct(\sprintf('%s has no head yet, so nothing there can wait for one — post a head first.', (string) $station->getName()));
    }
}
