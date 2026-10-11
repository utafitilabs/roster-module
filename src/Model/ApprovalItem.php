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

namespace Uhifadhi\Roster\Model;

use Uhifadhi\Bundle\AreaBundle\Entity\Station;
use Uhifadhi\Contracts\Entity\UserInterface;
use Uhifadhi\Roster\Entity\Swap;
use Uhifadhi\Roster\Entity\WatchChange;

/**
 * ONE THING WAITING FOR A STATION'S HEAD (#60): an agreed swap, or a planner's
 * change to their own watch — what it is, where, since when, and whether the
 * person looking may decide it.
 */
final readonly class ApprovalItem
{
    public function __construct(
        public Station $station,
        public string $kind,
        public string $sentence,
        public \DateTimeImmutable $since,
        public ?UserInterface $head,
        public bool $mayDecide,
        public ?Swap $swap = null,
        public ?WatchChange $change = null,
    ) {
    }

    /** What the approve and refuse forms post to name this item. */
    public function uuid(): string
    {
        return (string) ($this->swap?->getUuid() ?? $this->change?->getUuid())?->toRfc4122();
    }
}
