<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Marriage;
use App\Models\Member;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/** Marriage register: planned weddings (banns, counselling) and solemnised marriages with certificates. */
class MarriageController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $rows = Marriage::query()
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('search'), fn ($q, $s) => $q->where(fn ($q) => $q
                ->where('groom_name', 'like', "%{$s}%")->orWhere('bride_name', 'like', "%{$s}%")->orWhere('certificate_number', 'like', "%{$s}%")))
            ->inPeriod('date', ...$this->period($request))
            ->when($request->query('status') === 'scheduled', fn ($q) => $q->orderBy('date'), fn ($q) => $q->orderByDesc('date'))
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        return response()->json([
            'data' => collect($rows->items())->map(fn ($m) => $this->present($m)),
            'meta' => $this->meta($rows),
        ]);
    }

    public function stats(): JsonResponse
    {
        $year = now()->year;

        return response()->json(['data' => [
            'year' => $year,
            'marriedThisYear' => Marriage::where('status', 'completed')->whereDateBetween('date', ["{$year}-01-01", "{$year}-12-31"])->count(),
            'planned' => Marriage::where('status', 'scheduled')->count(),
            'bannsPending' => Marriage::where('status', 'scheduled')->where('banns_read', '<', 3)->count(),
            'totalRegistered' => Marriage::where('status', 'completed')->count(),
        ]]);
    }

    public function show(Marriage $marriage): JsonResponse
    {
        return response()->json(['data' => $this->present($marriage)]);
    }

    public function store(Request $request): JsonResponse
    {
        $m = Marriage::create([...$this->validated($request), 'recorded_by' => $request->user()->id]);

        return response()->json(['data' => $this->present($m)], 201);
    }

    public function update(Request $request, Marriage $marriage): JsonResponse
    {
        $marriage->update($this->validated($request, $marriage));

        return response()->json(['data' => $this->present($marriage->fresh())]);
    }

    public function destroy(Marriage $marriage): Response
    {
        $marriage->delete();

        return response()->noContent();
    }

    private function validated(Request $request, ?Marriage $existing = null): array
    {
        $req = $existing ? 'sometimes' : 'required';
        $v = $request->validate([
            'status' => ['sometimes', Rule::in(['scheduled', 'completed'])],
            'groomMemberId' => ['nullable', 'integer', 'exists:members,id'],
            'groomName' => [$existing ? 'sometimes' : 'required_without:groomMemberId', 'nullable', 'string', 'max:255'],
            'brideMemberId' => ['nullable', 'integer', 'exists:members,id'],
            'brideName' => [$existing ? 'sometimes' : 'required_without:brideMemberId', 'nullable', 'string', 'max:255'],
            'date' => [$req, 'date'],
            'place' => ['nullable', 'string', 'max:255'],
            'officiant' => ['nullable', 'string', 'max:255'],
            'witnesses' => ['nullable', 'string', 'max:255'],
            'bannsRead' => ['sometimes', 'integer', 'min:0', 'max:3'],
            'counsellingDone' => ['sometimes', 'boolean'],
            'certificateNumber' => ['nullable', 'string', 'max:30', Rule::unique('marriages', 'certificate_number')->ignore($existing)],
            'notes' => ['nullable', 'string'],
        ]);

        $data = collect($v)->mapWithKeys(fn ($val, $k) => [\Illuminate\Support\Str::snake($k) => $val])->all();
        foreach (['groom', 'bride'] as $who) {
            if (array_key_exists("{$who}_name", $data) && $data["{$who}_name"] === null) {
                unset($data["{$who}_name"]);
            }
            if (! empty($data["{$who}_member_id"]) && empty($data["{$who}_name"])) {
                $data["{$who}_name"] = Member::find($data["{$who}_member_id"])->name;
            }
        }

        return $data;
    }

    private function present(Marriage $m): array
    {
        return [
            'id' => (string) $m->id,
            'status' => $m->status,
            'groomName' => $m->groom_name,
            'groomMemberId' => $m->groom_member_id ? (string) $m->groom_member_id : null,
            'brideName' => $m->bride_name,
            'brideMemberId' => $m->bride_member_id ? (string) $m->bride_member_id : null,
            'date' => $m->date->toDateString(),
            'place' => $m->place,
            'officiant' => $m->officiant,
            'witnesses' => $m->witnesses,
            'bannsRead' => $m->banns_read,
            'counsellingDone' => $m->counselling_done,
            'certificateNumber' => $m->certificate_number,
            'notes' => $m->notes,
        ];
    }
}
