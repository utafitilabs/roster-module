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
use Uhifadhi\Roster\Entity\Rotation;

/**
 * ONE ROW OF THE ROTATIONS REGISTER: a ring the area declares, or a post the
 * area registers that runs none — named rather than counted, so "a post with
 * no rotation is never a hole" says which posts it means.
 */
final readonly class RotationRow
{
    public const string RUNNING = 'running';
    public const string STOOD_DOWN = 'stood-down';
    public const string NONE = 'none';

    /** The states in the order the chip lists them, each with its label. */
    public const array STATES = [self::RUNNING => 'running', self::STOOD_DOWN => 'stood down', self::NONE => 'no rotation'];

    /**
     * @param ?Rotation $rotation the ring; null for a post that runs none
     * @param ?Station  $station  the post a ring stands at or runs none at; null for a team's ring
     * @param string    $state    one of {@see self::STATES}
     */
    public function __construct(
        public ?Rotation $rotation,
        public ?Station $station,
        public string $state,
    ) {
    }

    /** The post's name, or the team's. */
    public function name(): string
    {
        return (string) ($this->station?->getName() ?? $this->rotation?->getTeamName());
    }
}
