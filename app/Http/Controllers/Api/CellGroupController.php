<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CellGroup;
use App\Models\Member;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Home / cell groups, usually one per village. */
class CellGroupController extends Controller
{
    public function index(): JsonResponse
    {
        $groups = CellGroup::with(['leader', 'area'])->withCount(['members' => fn ($q) => $q->where('status', 'active')])->orderBy('name')->get();

        $unassigned = Member::active()->whereNull('cell_group_id')
            ->selectRaw("COALESCE(NULLIF(village, ''), '—') as v, COUNT(*) as c")->groupBy('v')->orderByDesc('c')->get();

        return response()->json(['data' => [
            'groups' => $groups->map(fn ($g) => $this->present($g)),
            'unassigned' => [
                'total' => (int) $unassigned->sum('c'),
                'byVillage' => $unassigned->map(fn ($r) => ['village' => $r->v === '—' ? null : $r->v, 'count' => (int) $r->c])->values(),
            ],
        ]]);
    }

    public function show(CellGroup $cellGroup): JsonResponse
    {
        $cellGroup->load('leader')->loadCount('members');
        $members = $cellGroup->members()->orderBy('name')->get(['id', 'name', 'phone', 'pledge_number', 'status', 'village']);

        return response()->json(['data' => $this->present($cellGroup) + [
            'memberList' => $members->map(fn ($m) => [
                'id' => (string) $m->id, 'name' => $m->name, 'phone' => $m->phone,
                'pledgeNumber' => $m->pledge_number, 'status' => $m->status, 'village' => $m->village,
            ]),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $g = CellGroup::create($this->validated($request));

        return response()->json(['data' => $this->present($g->load('leader')->loadCount('members'))], 201);
    }

    public function update(Request $request, CellGroup $cellGroup): JsonResponse
    {
        $cellGroup->update($this->validated($request, $cellGroup));

        return response()->json(['data' => $this->present($cellGroup->fresh('leader')->loadCount('members'))]);
    }

    public function destroy(CellGroup $cellGroup): Response
    {
        $cellGroup->delete(); // members.cell_group_id → null

        return response()->noContent();
    }

    public function addMembers(Request $request, CellGroup $cellGroup): JsonResponse
    {
        $ids = $request->validate(['memberIds' => ['required', 'array', 'min:1'], 'memberIds.*' => ['integer', 'exists:members,id']])['memberIds'];
        Member::whereIn('id', $ids)->update(['cell_group_id' => $cellGroup->id]);

        return $this->show($cellGroup);
    }

    public function removeMember(CellGroup $cellGroup, Member $member): JsonResponse
    {
        abort_unless($member->cell_group_id === $cellGroup->id, 404);
        $member->update(['cell_group_id' => null]);

        return $this->show($cellGroup);
    }

    /** One group per village for unassigned active members ("Kireka Cell"), reusing an existing group for that village. */
    public function fromVillages(): JsonResponse
    {
        $created = 0;
        $assigned = 0;
        DB::transaction(function () use (&$created, &$assigned) {
            $villages = Member::active()->whereNull('cell_group_id')->whereNotNull('village')->where('village', '!=', '')
                ->distinct()->pluck('village');
            foreach ($villages as $village) {
                $group = CellGroup::where('village', $village)->first();
                if (! $group) {
                    $group = CellGroup::create(['name' => "{$village} Cell", 'village' => $village]);
                    $created++;
                }
                $assigned += Member::active()->whereNull('cell_group_id')->where('village', $village)->update(['cell_group_id' => $group->id]);
            }
        });

        return response()->json(['data' => ['created' => $created, 'assigned' => $assigned]]);
    }

    private function validated(Request $request, ?CellGroup $existing = null): array
    {
        $v = $request->validate([
            'name' => [$existing ? 'sometimes' : 'required', 'string', 'max:100', Rule::unique('cell_groups', 'name')->ignore($existing)],
            'leaderId' => ['nullable', 'integer', 'exists:members,id'],
            'host' => ['nullable', 'string', 'max:255'],
            'village' => ['nullable', 'string', 'max:100'],
            'parish' => ['nullable', 'string', 'max:100'],
            'meetingDay' => ['nullable', 'string', 'max:15'],
            'meetingTime' => ['nullable', 'string', 'max:10'],
            'location' => ['nullable', 'string', 'max:255'],
            'areaId' => ['nullable', 'integer', 'exists:areas,id'],
            'harvestTarget' => ['nullable', 'numeric', 'min:0'],
        ]);

        return collect($v)->mapWithKeys(fn ($val, $k) => [Str::snake($k) => $val])->all();
    }

    private function present(CellGroup $g): array
    {
        return [
            'id' => (string) $g->id, 'name' => $g->name,
            'leaderId' => $g->leader_id ? (string) $g->leader_id : null, 'leader' => $g->leader?->name, 'leaderPhone' => $g->leader?->phone,
            'host' => $g->host, 'village' => $g->village, 'parish' => $g->parish,
            'meetingDay' => $g->meeting_day, 'meetingTime' => $g->meeting_time, 'location' => $g->location,
            'members' => (int) ($g->members_count ?? 0),
            'areaId' => $g->area_id ? (string) $g->area_id : null, 'area' => $g->area?->name,
            'harvestTarget' => $g->harvest_target !== null ? (float) $g->harvest_target : null,
        ];
    }
}
