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

use Uhifadhi\Contracts\Access\Concern;
use Uhifadhi\Contracts\Access\ConcernSourceInterface;
use Uhifadhi\Contracts\Access\ScopeKind;
use Uhifadhi\Contracts\Access\Verb;
use Uhifadhi\Roster\Module\RosterModuleProvider;

/**
 * WHAT THERE IS TO HAVE A PERMISSION ABOUT IN THIS MODULE - one thing, and
 * two different jobs done to it.
 *
 * THE ROSTER IS ONE CONCERN. The rotation, the watches a station expects, the
 * day's fill and the swap are all the same subject: who stands where, when.
 * Splitting them into separate things to tick would put an administrator in
 * front of a page of rows that all say "roster" and none of which says which
 * job it is.
 *
 * TWO VERBS, AND THE DISTANCE BETWEEN THEM IS THE POINT. FILLING the day's
 * watches, publishing them, offering one to somebody else and taking the
 * offer back is a duty officer's daily work - RECORD. Changing how the area
 * runs its roster at all - the rotations, what each station's watch expects,
 * the module's settings - is CONFIGURE, and an organization routinely wants
 * the second held by fewer people than the first. Making one shift change
 * need the power that rewrites an area's rotations would push every swap up
 * to whoever holds that.
 *
 * NO READ, because nothing here reads a roster as a thing of its own: the
 * week, the day board and the calendar open on the area that carries them,
 * and a row nobody enforces is a checkbox that takes nothing away and gives
 * nothing. It arrives with the first screen that needs it.
 *
 * NOTHING HERE IS SENSITIVE. A watch is the organization's own arrangement of
 * its own people's time; the facts about a person are the team's, declared
 * and withheld there.
 *
 * WHOEVER ENFORCES A CONCERN DECLARES IT, which is why this is here and not
 * in the core. It arrives with the module and it leaves with it.
 */
final readonly class RosterConcerns implements ConcernSourceInterface
{
    /** The key, spelt once, so a gate, a door and a test cannot disagree. */
    public const string ROSTER = 'roster';

    public function declaredBy(): string
    {
        return 'Roster';
    }

    public function concerns(): iterable
    {
        yield new Concern(
            key: self::ROSTER,
            label: 'Roster',
            description: 'Who stands where, and when: filling and publishing the day’s watches, swapping one, and setting up the rotations and watch rules an area runs on.',
            verbs: [Verb::Record, Verb::Configure],
            scopeKinds: [ScopeKind::Organization, ScopeKind::Area],
            moduleSlug: RosterModuleProvider::SLUG,
        );
    }
}
