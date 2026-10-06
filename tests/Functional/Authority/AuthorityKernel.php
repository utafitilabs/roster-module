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

namespace Uhifadhi\Roster\Tests\Functional\Authority;

use Uhifadhi\Roster\Tests\Integration\TestKernel;

/**
 * THE INSTALLATION THE AUTHORITY TABLE CALLS: the suite's kernel with no
 * fixture voting beside the team's own rules, the area's own scope list, and
 * the sign-in a skeleton installation writes.
 */
final class AuthorityKernel extends TestKernel
{
    protected static function theInstallationsRulesAlone(): bool
    {
        return true;
    }
}
