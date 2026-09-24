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

namespace Uhifadhi\Roster\Tests\Unit\Access;

use Uhifadhi\Bundle\TeamBundle\Test\AccessConformanceTestCase;
use Uhifadhi\Contracts\Access\ConcernSourceInterface;
use Uhifadhi\Roster\Access\RosterConcerns;
use Uhifadhi\Roster\Module\RosterModuleProvider;

/**
 * THE RULES THE CORE HOLDS ITSELF TO, run over this module's declarations in
 * this module's own CI — a concern declared once and keyed by a slug, a
 * sentence on every one of them, every concern naming this module, every door
 * through the helper, and every route under an area passing that area to a
 * per-area gate.
 *
 * It travels as a base class precisely so a module cannot be released with a
 * gate that looks enforced and is not.
 */
final class AccessConformanceTest extends AccessConformanceTestCase
{
    protected static function source(): ConcernSourceInterface
    {
        return new RosterConcerns();
    }

    protected static function bundlePath(): string
    {
        return \dirname(__DIR__, 3);
    }

    protected static function moduleSlug(): string
    {
        return RosterModuleProvider::SLUG;
    }

    /**
     * NOTHING THIS MODULE DECLARES IS A FACT ABOUT A PERSON OR A CASE. A
     * watch is the organization's own arrangement of its own people's time;
     * the facts about the person standing it are the team's, declared and
     * withheld there.
     *
     * Stated here as well as in the declaration on purpose: a concern that
     * quietly stopped being sensitive would otherwise be a silent widening.
     *
     * @return list<string>
     */
    protected static function sensitiveConcerns(): array
    {
        return [];
    }
}
