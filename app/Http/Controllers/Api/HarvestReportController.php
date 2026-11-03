<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Models\CellGroup;
use App\Models\Offertory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Harvest league: each harvest gift counts for its cell group (the gift's own cell, else the giver's cell),
 * and every cell's total rolls up through village / region → parish → archdeaconry.
 */
class HarvestReportController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate(['year' => ['sometimes', 'integer', 'min:2000', 'max:2100']]);
        [$from, $to] = $this->period($request);
        if ($from || $to) {
            $to ??= \App\Support\ChurchDate::today();
            $from ??= substr($to, 0, 4).'-01-01';
            $year = (int) substr($from, 0, 4);
        } else {
            $year = $request->integer('year', now()->year);
            [$from, $to] = ["{$year}-01-01", "{$year}-12-31"];
        }

        $gifts = Offertory::with(['member.cellGroup', 'cellGroup'])->where('category', 'harvest')
            ->whereDateBetween('date', [$from, $to])->get();

        $cellOf = fn (Offertory $o) => $o->cell_group_id ?? $o->member?->cell_group_id;
        $byCell = $gifts->groupBy(fn ($o) => $cellOf($o) ?? 'none');

        $cells = CellGroup::with('area')->get()->keyBy('id');
        $cellRows = $cells->map(function (CellGroup $c) use ($byCell) {
            $g = $byCell->get($c->id, collect());

            return [
                'id' => (string) $c->id, 'name' => $c->name, 'area' => $c->area?->name,
                'areaId' => $c->area_id ? (string) $c->area_id : null,
                'total' => round((float) $g->sum('amount'), 2), 'gifts' => $g->count(),
                'givers' => $g->pluck('member_id')->filter()->unique()->count(),
                'target' => $c->harvest_target !== null ? (float) $c->harvest_target : null,
            ];
        })->sortByDesc('total')->values();

        // Roll cell totals up the area tree.
        $areas = Area::with('parent.parent.parent')->get()->keyBy('id');
        $areaTotals = [];
        foreach ($cellRows as $row) {
            if (! $row['areaId']) {
                continue;
            }
            foreach ($areas[$row['areaId']]?->lineage() ?? [] as $node) {
                $areaTotals[$node->id] ??= ['total' => 0.0, 'target' => 0.0, 'cells' => 0];
                $areaTotals[$node->id]['total'] += $row['total'];
                $areaTotals[$node->id]['target'] += $row['target'] ?? 0;
                $areaTotals[$node->id]['cells']++;
            }
        }
        $areaRows = collect($areaTotals)->map(fn ($t, $id) => [
            'id' => (string) $id, 'name' => $areas[$id]->name, 'level' => $areas[$id]->level,
            'parent' => $areas[$id]->parent?->name, 'total' => round($t['total'], 2),
            'target' => $t['target'] ?: null, 'cells' => $t['cells'],
        ])->sortByDesc('total')->values()->groupBy('level');

        $members = $gifts->whereNotNull('member_id')->groupBy('member_id')->map(fn ($g) => [
            'id' => (string) $g->first()->member_id, 'name' => $g->first()->member?->name,
            'pledgeNumber' => $g->first()->member?->pledge_number, 'cell' => $g->first()->member?->cellGroup?->name,
            'total' => round((float) $g->sum('amount'), 2),
        ])->sortByDesc('total')->take(25)->values();

        $none = $byCell->get('none', collect());

        return response()->json(['data' => [
            'year' => $year, 'from' => $from, 'to' => $to,
            'total' => round((float) $gifts->sum('amount'), 2),
            'target' => (float) $cells->sum('harvest_target') ?: null,
            'cells' => $cellRows,
            'areas' => $areaRows,
            'topGivers' => $members,
            'unassigned' => ['total' => round((float) $none->sum('amount'), 2), 'gifts' => $none->count()],
        ]]);
    }
}
