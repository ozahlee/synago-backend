<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

abstract class Controller
{
    /** Page size from ?perPage=, clamped to 1..100 (default 20). */
    protected function perPage(Request $request): int
    {
        return max(1, min(100, $request->integer('perPage', 20)));
    }

    /**
     * The ?from=&to= period (YYYY-MM-DD, either optional) shared by every list and report.
     *
     * @return array{0: ?string, 1: ?string}
     */
    protected function period(Request $request): array
    {
        $v = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);

        return [isset($v['from']) ? substr($v['from'], 0, 10) : null, isset($v['to']) ? substr($v['to'], 0, 10) : null];
    }

    /** Pagination meta in the same shape Laravel resources produce (for hand-built JSON lists). */
    protected function meta(\Illuminate\Pagination\LengthAwarePaginator $p): array
    {
        return [
            'current_page' => $p->currentPage(), 'last_page' => $p->lastPage(), 'per_page' => $p->perPage(),
            'total' => $p->total(), 'from' => $p->firstItem(), 'to' => $p->lastItem(),
        ];
    }
}
