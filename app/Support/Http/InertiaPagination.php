<?php

namespace App\Support\Http;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class InertiaPagination
{
    /**
     * @template TKey of array-key
     * @template TValue
     *
     * @param  LengthAwarePaginator<TKey, TValue>  $paginator
     * @return array{
     *     current_page: int,
     *     last_page: int,
     *     per_page: int,
     *     total: int,
     *     from: int|null,
     *     to: int|null,
     *     prev_page_url: string|null,
     *     next_page_url: string|null
     * }
     */
    public static function from(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
            'prev_page_url' => $paginator->previousPageUrl(),
            'next_page_url' => $paginator->nextPageUrl(),
        ];
    }

    /**
     * @template TKey of array-key
     * @template TValue
     *
     * @param  LengthAwarePaginator<TKey, TValue>  $paginator
     */
    public static function redirectIfOutOfRange(
        LengthAwarePaginator $paginator,
        Request $request,
        string $pageName = 'page',
    ): ?RedirectResponse {
        if ($paginator->currentPage() <= $paginator->lastPage()) {
            return null;
        }

        $query = $request->query();
        $query[$pageName] = $paginator->lastPage();
        $queryString = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $request->session()->reflash();

        return redirect()->to($request->url().($queryString === '' ? '' : '?'.$queryString));
    }
}
