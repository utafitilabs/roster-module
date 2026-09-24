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

namespace Uhifadhi\Roster\Tests\Unit\Template;

use PHPUnit\Framework\TestCase;

/**
 * NO DESIGN-WORKSPACE IDENTIFIER REACHES A SHIPPED TEMPLATE.
 *
 * THE DESIGN NUMBERS ITS FRAMES so a person reviewing one can point at it —
 * `RO·G1`, `RO·G2`, and the `.idx` chip that prints them. They are how the
 * workshop talks about a drawing, and they mean nothing whatever to a
 * ranger: a screen that shipped one would be showing its own reference
 * number to somebody looking for a ranger's name.
 *
 * IT HAPPENS BY COPYING, which is why it needs a test rather than care.
 * Porting a design well means bringing its markup across faithfully, and
 * the index code sits in the middle of the very `<span class="tab">` a
 * porter is copying. It reached six of this module's partials the first
 * time an organization-level surface was ported, in the comments as well as
 * the markup.
 *
 * WHAT THE HOUSE HOLDS, THIS FILE NO LONGER DOES. The rest of this
 * module's stylesheet rules — every class shipped, every token defined, no
 * selector restated, no colour named — are the shell's own conformance
 * case now, run from {@see RosterVocabularyConformanceTest}. One set of
 * rules for every module, and a rule the house learns reaches all of them.
 */
final class NoWorkshopIdentifiersTest extends TestCase
{
    private const string TEMPLATES = __DIR__.'/../../../templates';

    /** The shapes the workspace's own index takes, in markup and in prose. */
    private const array DOTS = ['·', '&middot;', '&#183;'];

    public function testNoWorkshopIdentifierReachedAShippedTemplate(): void
    {
        $offenders = [];

        foreach ($this->templateFiles() as $file) {
            $body = (string) file_get_contents($file);

            foreach (self::DOTS as $dot) {
                if (1 === preg_match('/\b[A-Z]{2}\s*'.preg_quote($dot, '/').'\s*[A-Z]?[0-9]/', $body)) {
                    $offenders[] = basename($file);
                }
            }

            if (str_contains($body, 'class="idx"')) {
                $offenders[] = basename($file).' (workshop index chip)';
            }
        }

        self::assertSame(
            [],
            array_values(array_unique($offenders)),
            'A design-workspace identifier reached a shipped template: '.implode(', ', array_unique($offenders)),
        );
    }

    /** @return list<string> */
    private function templateFiles(): array
    {
        $files = [];

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::TEMPLATES, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ('twig' === $file->getExtension()) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
