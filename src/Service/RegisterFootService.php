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

namespace Uhifadhi\Roster\Service;

use Symfony\Component\HttpFoundation\Request;
use Uhifadhi\Bundle\ShellBundle\Service\TablePageService;

/**
 * ONE PAGE OF A REGISTER AND THE FOOT UNDER IT — this page's rows, and
 * what `@Shell/_foot.html.twig` is handed to draw "n watches · 20 per page"
 * and "page k of n". Every table names its own keys (`<key>_page`,
 * `<key>_per`), so two registers on one page, or a register on a page
 * another bundle owns, never move each other.
 */
final readonly class RegisterFootService
{
    public function __construct(private TablePageService $pages)
    {
    }

    /**
     * @template T
     *
     * @param array<T> $rows every row the filters left
     *
     * @return array{rows: list<T>, foot: array{count: int, noun: string, one: string, perPage: string, page: int, pages: int, links: array{sizes: array<array-key, string>, previous: ?string, next: ?string}}}
     */
    public function page(Request $request, array $rows, string $key, string $noun, string $one): array
    {
        $pageKey = $key.'_page';
        $perKey = $key.'_per';
        $table = $this->pages->read($request, \count($rows), $pageKey, $perKey);

        return [
            'rows' => $table->slice($rows),
            'foot' => [
                'count' => $table->total,
                'noun' => $noun,
                'one' => $one,
                'perPage' => $table->size,
                'page' => $table->page,
                'pages' => $table->pages,
                'links' => $this->pages->links($request, $table, $pageKey, $perKey),
            ],
        ];
    }
}
