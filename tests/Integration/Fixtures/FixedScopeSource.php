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

use Uhifadhi\Bundle\AreaBundle\Repository\AreaOfInterestRepository;
use Uhifadhi\Contracts\Shell\Scope;
use Uhifadhi\Contracts\Shell\ScopeSourceInterface;

/**
 * THE HOST'S ANSWER TO "WHAT MAY THIS PERSON LOOK AT", played by a fixture.
 *
 * THE SHELL HOLDS NO AREAS AND NO VOTERS, so the scope list is the
 * application's — and in this suite there is no application, so something
 * has to stand where one would. It offers the organization and every area,
 * which is what an installation offers somebody who may see them all.
 *
 * IT IS NOT A STUB OF THIS MODULE'S BEHAVIOUR. What is under test is that
 * the roster reads whatever scope it is handed and widens its own queries
 * to match; who may see what is somebody else's question entirely.
 */
final readonly class FixedScopeSource implements ScopeSourceInterface
{
    public function __construct(
        private AreaOfInterestRepository $areas,
    ) {
    }

    public function scopes(): iterable
    {
        yield Scope::organization();

        foreach ($this->areas->findBy([], ['id' => 'ASC']) as $area) {
            yield Scope::area((string) $area->getUuidString(), (string) $area->getName());
        }
    }
}
