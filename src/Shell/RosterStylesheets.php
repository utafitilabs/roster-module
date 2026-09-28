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

namespace Uhifadhi\Roster\Shell;

use Uhifadhi\Bundle\ShellBundle\Contract\StylesheetSourceInterface;
use Uhifadhi\Roster\UhifadhiRosterBundle;

/**
 * THE SHEET THIS MODULE'S CARD NEEDS ON SOMEBODY ELSE'S PAGE (#19).
 *
 * The My roster card is drawn on the core's person dashboard, which links no
 * module's sheet and cannot know which cards it is about to draw; a link in
 * the body would not be conforming HTML. So the head asks every package for
 * the sheets its components need, and this answers with the small one the
 * card is drawn in — not roster.css, which the module's own pages link for
 * themselves.
 *
 * @see StylesheetSourceInterface
 * @see https://html.spec.whatwg.org/multipage/semantics.html#the-link-element
 */
final readonly class RosterStylesheets implements StylesheetSourceInterface
{
    /** @return list<string> */
    public function stylesheets(): array
    {
        return [UhifadhiRosterBundle::ME_STYLESHEET];
    }
}
