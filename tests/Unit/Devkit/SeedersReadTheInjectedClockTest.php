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

namespace Uhifadhi\Roster\Tests\Unit\Devkit;

use PHPUnit\Framework\TestCase;

/**
 * NO SEED SEEDER READS THE WALL CLOCK.
 *
 * THIS IS THE REGRESSION HALF OF A BUG THAT LANDED THREE TIMES. Once in the
 * core's presence service, once in a test that asked a live plate for "now"
 * while the clock said half past ten, and once here — the roster seeder
 * writing its fortnight from `new \DateTimeImmutable('today')` while the
 * presence seeder beside it worked those duties against an injected clock.
 * Each was found the same way: a suite green on one leg and red on the next,
 * with nothing wrong in the diff.
 *
 * WHY THE SEEDERS AND NOT EVERYTHING. A controller reading "today" is
 * reading the day a person is looking at the screen, and there is no other
 * clock for it to read. A SEEDER is different in kind: it is only ever run
 * against a clock somebody chose — a suite's pinned instant, or a seed being
 * built for a particular day — and its whole output is a function of that
 * instant. Two seeders that disagree about what day it is produce a park
 * whose duties are on Monday and whose check-ins are on Tuesday, and every
 * figure derived from the pair is wrong in a way no single test can see.
 *
 * THE RULE IS THEREFORE ABSOLUTE IN THIS DIRECTORY and stated nowhere else:
 * take the clock as a collaborator, and derive every day, month and instant
 * from it.
 *
 * (The same rule is worth having over the services and controllers that
 * still read the wall clock, and it is a larger change than a seeder's
 * constructor — recorded in docs/design-decisions.md rather than pretended
 * here.)
 */
final class SeedersReadTheInjectedClockTest extends TestCase
{
    private const string DEVKIT = __DIR__.'/../../../src/Devkit';

    /**
     * The shapes a wall-clock read takes. `DateTimeImmutable::createFrom*`
     * and a constructor given a stored string are not reads of the clock
     * and are not matched: the pattern requires a relative or empty
     * argument, which is exactly what "ask the machine what time it is"
     * looks like.
     */
    private const array WALL_CLOCK = [
        '/new\s+\\\\?DateTimeImmutable\s*\(\s*\)/',
        '/new\s+\\\\?DateTimeImmutable\s*\(\s*[\'"](?:now|today|tomorrow|yesterday|midnight|first day of this month|last day of this month)[\'"]/i',
        '/new\s+\\\\?DateTime\s*\(\s*\)/',
        '/\btime\s*\(\s*\)/',
        '/\bdate\s*\(\s*[\'"]/',
        '/\bstrtotime\s*\(/',
    ];

    public function testNoSeederAsksTheMachineWhatTimeItIs(): void
    {
        $offenders = [];

        foreach ($this->seederFiles() as $file) {
            $body = (string) file_get_contents($file);

            foreach (self::WALL_CLOCK as $shape) {
                if (1 === preg_match($shape, self::withoutComments($body), $found)) {
                    $offenders[] = basename($file).': '.trim($found[0]);
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            "A seeder read the wall clock. Take \\Psr\\Clock\\ClockInterface as a constructor argument and derive the instant from it — the seeders have to agree about what day it is:\n  ".implode("\n  ", $offenders),
        );
    }

    /** And every one of them holds the clock, so the rule above is reachable. */
    public function testEverySeederTakesTheClockAsACollaborator(): void
    {
        $without = [];

        foreach ($this->seederFiles() as $file) {
            $body = (string) file_get_contents($file);

            if (str_contains($body, 'ContentProviderInterface') && !str_contains($body, 'ClockInterface $clock')) {
                $without[] = basename($file);
            }
        }

        self::assertSame([], $without, 'A content provider without a clock: '.implode(', ', $without));
    }

    /**
     * COMMENTS ARE NOT CODE. This module's docblocks describe the very bug
     * this test guards, in the words the pattern matches, and a rule that
     * failed on its own explanation would be deleted within a week.
     */
    private static function withoutComments(string $body): string
    {
        $stripped = '';

        foreach (token_get_all($body) as $token) {
            $stripped .= \is_array($token)
                ? (\in_array($token[0], [\T_COMMENT, \T_DOC_COMMENT], true) ? ' ' : $token[1])
                : $token;
        }

        return $stripped;
    }

    /**
     * @return list<string>
     */
    private function seederFiles(): array
    {
        $files = [];

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::DEVKIT, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ('php' === $file->getExtension()) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        self::assertNotSame([], $files, 'The seeders moved; this rule is pointing at nothing.');

        return $files;
    }
}
