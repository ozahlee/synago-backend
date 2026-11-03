<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Household;
use App\Models\Member;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/** Families (households): a head plus the members who live with them. */
class HouseholdController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $rows = Household::with('head')->withCount('members')
            ->when($request->query('search'), fn ($q, $s) => $q->where('name', 'like', "%{$s}%")->orWhereHas('head', fn ($q) => $q->where('name', 'like', "%{$s}%")))
            ->orderBy('name')->paginate($this->perPage($request));

        return response()->json(['data' => collect($rows->items())->map(fn ($h) => $this->present($h)), 'meta' => $this->meta($rows),
            'stats' => ['households' => Household::count(), 'membersInFamilies' => Member::whereNotNull('household_id')->count(), 'membersWithout' => Member::active()->whereNull('household_id')->count()]]);
    }

    public function show(Household $household): JsonResponse
    {
        $household->load('head')->loadCount('members');
        $members = $household->members()->orderBy('date_of_birth')->get(['id', 'name', 'gender', 'date_of_birth', 'phone', 'pledge_number', 'status']);

        return response()->json(['data' => $this->present($household) + [
            'memberList' => $members->map(fn ($m) => ['id' => (string) $m->id, 'name' => $m->name, 'gender' => $m->gender,
                'age' => $m->date_of_birth?->age, 'phone' => $m->phone, 'pledgeNumber' => $m->pledge_number, 'status' => $m->status,
                'isHead' => $m->id === $household->head_member_id]),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $h = Household::create($this->validated($request));
        if ($h->head_member_id) {
            Member::whereKey($h->head_member_id)->update(['household_id' => $h->id]);
        }

        return response()->json(['data' => $this->present($h->load('head')->loadCount('members'))], 201);
    }

    public function update(Request $request, Household $household): JsonResponse
    {
        $household->update($this->validated($request, true));
        if ($household->head_member_id) {
            Member::whereKey($household->head_member_id)->update(['household_id' => $household->id]);
        }

        return response()->json(['data' => $this->present($household->fresh('head')->loadCount('members'))]);
    }

    public function destroy(Household $household): Response
    {
        $household->delete(); // members stay, just without a household

        return response()->noContent();
    }

    public function addMembers(Request $request, Household $household): JsonResponse
    {
        $ids = $request->validate(['memberIds' => ['required', 'array', 'min:1'], 'memberIds.*' => ['integer', 'exists:members,id']])['memberIds'];
        Member::whereIn('id', $ids)->update(['household_id' => $household->id]);

        return $this->show($household);
    }

    public function removeMember(Household $household, Member $member): JsonResponse
    {
        abort_unless($member->household_id === $household->id, 404);
        $member->update(['household_id' => null]);
        if ($household->head_member_id === $member->id) {
            $household->update(['head_member_id' => null]);
        }

        return $this->show($household);
    }

    private function validated(Request $request, bool $update = false): array
    {
        $v = $request->validate([
            'name' => [$update ? 'sometimes' : 'required', 'string', 'max:150'],
            'headMemberId' => ['nullable', 'integer', 'exists:members,id'],
            'phone' => ['nullable', 'string', 'max:30'],
            'village' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return collect($v)->mapWithKeys(fn ($val, $k) => [Str::snake($k) => $val])->all();
    }

    private function present(Household $h): array
    {
        return ['id' => (string) $h->id, 'name' => $h->name, 'headMemberId' => $h->head_member_id ? (string) $h->head_member_id : null,
            'head' => $h->head?->name, 'phone' => $h->phone ?? $h->head?->phone, 'village' => $h->village, 'notes' => $h->notes,
            'members' => (int) ($h->members_count ?? 0)];
    }
}
