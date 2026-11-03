<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CellGroup;
use App\Models\Ministry;
use App\Models\Position;
use App\Models\Project;
use App\Models\SundaySchoolClass;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/** Offices held by members (teacher, chairperson, cell leader…), and giving position-holders a login. */
class PositionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $rows = Position::with('member')
            ->when(! $request->boolean('includePast'), fn ($q) => $q->current())
            ->when($request->query('memberId'), fn ($q, $v) => $q->where('member_id', $v))
            ->when($request->query('scopeType'), fn ($q, $v) => $q->where('scope_type', $v))
            ->orderBy('scope_type')->orderBy('title')->get();
        $logins = User::whereIn('member_id', $rows->pluck('member_id'))->get()->keyBy('member_id');

        return response()->json(['data' => $rows->map(fn (Position $p) => $this->present($p, $logins[$p->member_id] ?? null))]);
    }

    /** Groups a position can cover, for the picker. */
    public function scopes(): JsonResponse
    {
        $map = fn ($q) => $q->orderBy('name')->get(['id', 'name'])->map(fn ($m) => ['id' => (string) $m->id, 'name' => $m->name]);

        return response()->json(['data' => [
            'ministry' => $map(Ministry::query()),
            'cell_group' => $map(CellGroup::query()),
            'sunday_school_class' => $map(SundaySchoolClass::query()),
            'project' => $map(Project::query()),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $p = Position::create($this->validated($request));

        return response()->json(['data' => $this->present($p->load('member'))], 201);
    }

    public function update(Request $request, Position $position): JsonResponse
    {
        $position->update($this->validated($request, $position));

        return response()->json(['data' => $this->present($position->fresh('member'))]);
    }

    public function destroy(Position $position): Response
    {
        $position->delete();

        return response()->noContent();
    }

    /**
     * Give the position-holder a login with the "leader" role: they see only "My groups"
     * (their class, ministry or cell). Reuses an existing account linked to the same member.
     */
    public function grantLogin(Request $request, Position $position): JsonResponse
    {
        $member = $position->member;
        $existing = User::where('member_id', $member->id)->first();
        if ($existing) {
            $existing->assignRole('leader');

            return response()->json(['data' => ['userId' => (string) $existing->id, 'email' => $existing->email, 'created' => false]]);
        }
        $v = $request->validate([
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', Password::min(8)],
        ]);
        $user = DB::transaction(function () use ($member, $v) {
            $u = User::create(['name' => $member->name, 'email' => $v['email'], 'phone' => $member->phone, 'password' => $v['password'], 'member_id' => $member->id]);
            $u->assignRole('leader');

            return $u;
        });

        return response()->json(['data' => ['userId' => (string) $user->id, 'email' => $user->email, 'created' => true]], 201);
    }

    private function validated(Request $request, ?Position $existing = null): array
    {
        $req = $existing ? 'sometimes' : 'required';
        $v = $request->validate([
            'memberId' => [$req, 'integer', 'exists:members,id'],
            'title' => [$req, 'string', 'max:100'],
            'scopeType' => [$req, Rule::in(array_keys(Position::SCOPES))],
            'scopeId' => ['nullable', 'integer'],
            'startDate' => ['nullable', 'date'],
            'endDate' => ['nullable', 'date'],
        ]);
        $type = $v['scopeType'] ?? $existing?->scope_type;
        $id = array_key_exists('scopeId', $v) ? $v['scopeId'] : $existing?->scope_id;
        $class = Position::SCOPES[$type] ?? null;
        if ($class && (! $id || ! $class::whereKey($id)->exists())) {
            throw ValidationException::withMessages(['scopeId' => 'Choose which group this position covers.']);
        }

        $data = ['scope_id' => $class ? $id : null];
        foreach (['memberId' => 'member_id', 'title' => 'title', 'scopeType' => 'scope_type', 'startDate' => 'start_date', 'endDate' => 'end_date'] as $in => $out) {
            if (array_key_exists($in, $v)) {
                $data[$out] = $v[$in];
            }
        }

        return $data;
    }

    private function present(Position $p, ?User $login = null): array
    {
        return [
            'id' => (string) $p->id, 'memberId' => (string) $p->member_id, 'member' => $p->member?->name,
            'memberPhone' => $p->member?->phone, 'title' => $p->title, 'scopeType' => $p->scope_type,
            'scopeId' => $p->scope_id ? (string) $p->scope_id : null, 'scopeName' => $p->scopeName(),
            'startDate' => $p->start_date?->toDateString(), 'endDate' => $p->end_date?->toDateString(),
            'current' => ! $p->end_date || $p->end_date->gte(today()),
            'login' => $login ? ['email' => $login->email, 'active' => $login->is_active] : null,
        ];
    }
}
