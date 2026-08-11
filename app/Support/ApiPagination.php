<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

final class ApiPagination
{
    public const DEFAULT_PER_PAGE = 25;

    public const MAX_PER_PAGE = 100;

    public static function page(Request $request): int
    {
        $page = filter_var($request->query('page'), FILTER_VALIDATE_INT);

        return is_int($page) && $page > 0 ? $page : 1;
    }

    public static function perPage(Request $request): int
    {
        $perPage = filter_var($request->query('per_page'), FILTER_VALIDATE_INT);

        if (! is_int($perPage) || $perPage < 1) {
            return self::DEFAULT_PER_PAGE;
        }

        return min($perPage, self::MAX_PER_PAGE);
    }

    /** @param callable(mixed): array<string, mixed> $project */
    public static function envelope(LengthAwarePaginator $paginator, callable $project): array
    {
        $perPage = $paginator->perPage();
        $paginator->appends(['per_page' => $perPage]);

        return [
            'data' => $paginator->getCollection()->map($project)->values()->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $perPage,
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'links' => [
                'first' => $paginator->url(1),
                'last' => $paginator->url(max(1, $paginator->lastPage())),
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
            ],
        ];
    }
}
