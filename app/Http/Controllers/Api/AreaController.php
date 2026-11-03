<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Models\CellGroup;
use App\Models\Member;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Archdeaconry > parish > region > village; cell groups attach to a village or region. */
class AreaController extends Controller
{
    public function index(): JsonResponse
    {
        $areas = Area::withCount(['cellGroups', 'children'])->orderBy('name')->get();
        $cellMembers = CellGroup::withCount(['members' => fn ($q) => $q->where('status', 'active')])->get(['id', 'area_id'])
            ->groupBy('area_id')->map->sum('members_count');

        return response()->json(['data' => $areas->map(fn (Area $a) => [
            'id' => (string) $a->id, 'name' => $a->name, 'level' => $a->level,
            'parentId' => $a->parent_id ? (string) $a->parent_id : null,
            'cellGroups' => $a->cell_groups_count, 'children' => $a->children_count,
            'members' => (int) ($cellMembers[$a->id] ?? 0),
        ])]);
    }

    public function store(Request $request): JsonResponse
    {
        $a = Area::create($this->validated($request));

        return response()->json(['data' => ['id' => (string) $a->id]], 201);
    }

    public function update(Request $request, Area $area): JsonResponse
    {
        $area->update($this->validated($request, $area));

        return response()->json(['data' => ['id' => (string) $area->id]]);
    }

    public function destroy(Area $area): Response
    {
        $area->delete(); // children & cells keep existing, just unlinked

        return response()->noContent();
    }

    /**
     * Build the tree from what members already have on file: each distinct parish becomes a parish,
     * each village goes under the parish most of its members name, and cell groups attach to their village.
     */
    public function fromMembers(): JsonResponse
    {
        $created = 0;
        $linked = 0;
        DB::transaction(function () use (&$created, &$linked) {
            $pairs = Member::whereNotNull('village')->where('village', '!=', '')
                ->selectRaw('village, parish, COUNT(*) as c')->groupBy('village', 'parish')->get();

            foreach ($pairs->pluck('parish')->filter()->unique() as $parish) {
                $p = Area::firstOrCreate(['name' => $parish, 'level' => 'parish', 'parent_id' => null]);
                $created += (int) $p->wasRecentlyCreated;
            }
            foreach ($pairs->groupBy('village') as $village => $rows) {
                $parishName = $rows->sortByDesc('c')->first()->parish;
                $parent = $parishName ? Area::where('level', 'parish')->where('name', $parishName)->first() : null;
                $v = Area::where('level', 'village')->where('name', $village)->first()
                    ?? Area::create(['name' => $village, 'level' => 'village', 'parent_id' => $parent?->id]);
                $created += (int) $v->wasRecentlyCreated;
                $linked += CellGroup::whereNull('area_id')->where('village', $village)->update(['area_id' => $v->id]);
            }
        });

        return response()->json(['data' => ['created' => $created, 'cellGroupsLinked' => $linked]]);
    }

    private function validated(Request $request, ?Area $existing = null): array
    {
        $v = $request->validate([
            'name' => [$existing ? 'sometimes' : 'required', 'string', 'max:100'],
            'level' => [$existing ? 'sometimes' : 'required', Rule::in(array_keys(Area::LEVELS))],
            'parentId' => ['nullable', 'integer', 'exists:areas,id'],
        ]);
        $level = $v['level'] ?? $existing?->level;
        $parentId = array_key_exists('parentId', $v) ? $v['parentId'] : $existing?->parent_id;
        if ($parentId) {
            $parent = Area::find($parentId);
            if (Area::LEVELS[$parent->level] >= Area::LEVELS[$level] || ($existing && $parent->id === $existing->id)) {
                throw ValidationException::withMessages(['parentId' => "A {$level} can't sit under a {$parent->level}."]);
            }
        }

        return array_filter(['name' => $v['name'] ?? null, 'level' => $v['level'] ?? null], fn ($x) => $x !== null)
            + (array_key_exists('parentId', $v) ? ['parent_id' => $v['parentId']] : []);
    }
}
