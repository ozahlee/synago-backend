<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Organisation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Companies, NGOs, partner churches and others who give to projects. */
class OrganisationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $rows = Organisation::withSum('contributions', 'amount')->withSum('contributions', 'in_kind_value')->withCount('contributions')
            ->when($request->query('search'), fn ($q, $s) => $q->where('name', 'like', "%{$s}%")->orWhere('contact_name', 'like', "%{$s}%"))
            ->orderBy('name')->get();

        return response()->json(['data' => $rows->map(fn ($o) => $this->present($o))]);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->present(Organisation::create($this->validated($request)))], 201);
    }

    public function update(Request $request, Organisation $organisation): JsonResponse
    {
        $organisation->update($this->validated($request, $organisation));

        return response()->json(['data' => $this->present($organisation)]);
    }

    public function destroy(Organisation $organisation): Response
    {
        abort_if($organisation->contributions()->exists(), 422, 'This organisation has contributions on record and cannot be deleted.');
        $organisation->delete();

        return response()->noContent();
    }

    private function validated(Request $request, ?Organisation $existing = null): array
    {
        $v = $request->validate([
            'name' => [$existing ? 'sometimes' : 'required', 'string', 'max:150', Rule::unique('organisations', 'name')->ignore($existing)],
            'type' => ['sometimes', Rule::in(Organisation::TYPES)],
            'contactName' => ['nullable', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return collect($v)->mapWithKeys(fn ($val, $k) => [Str::snake($k) => $val])->all();
    }

    private function present(Organisation $o): array
    {
        return ['id' => (string) $o->id, 'name' => $o->name, 'type' => $o->type, 'contactName' => $o->contact_name, 'phone' => $o->phone,
            'email' => $o->email, 'notes' => $o->notes, 'contributions' => (int) ($o->contributions_count ?? 0),
            'given' => round((float) ($o->contributions_sum_amount ?? 0) + (float) ($o->contributions_sum_in_kind_value ?? 0), 2)];
    }
}
