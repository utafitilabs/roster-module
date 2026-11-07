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

use Uhifadhi\Contracts\Area\PersonWatch;

/**
 * ONE ROW OF A DAY'S REGISTER — a watch somebody stood, somebody rostered
 * who reported nothing, the hole a post's watch leaves, or a post that asks
 * for nobody that day. Every row carries its post, so the register needs no
 * head row to say whose rows follow, and a page of twenty rows can start
 * anywhere in the day.
 */
final readonly class AgendaRow
{
    public const string WATCH = 'watch';

    /** Rostered, and nothing reported for the day. */
    public const string UNREPORTED = 'unreported';

    public const string HOLE = 'hole';

    /** The post asks for nobody on this day. */
    public const string NOBODY = 'nobody';

    /**
     * @param self::WATCH|self::UNREPORTED|self::HOLE|self::NOBODY $kind
     * @param int                                                  $index which of the person's watches, from 1
     */
    public function __construct(
        public string $kind,
        public PostPresence $post,
        public ?RosteredPerson $person = null,
        public ?PersonWatch $watch = null,
        public int $index = 1,
    ) {
    }
}
