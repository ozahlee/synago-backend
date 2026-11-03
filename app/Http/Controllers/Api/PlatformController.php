<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Church;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Platform owner console: every church on Synago, its status and trial, and basic usage. */
class PlatformController extends Controller
{
    public function stats(): JsonResponse
    {
        $by = Church::selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return response()->json(['data' => [
            'churches' => (int) $by->sum(),
            'trial' => (int) ($by['trial'] ?? 0), 'active' => (int) ($by['active'] ?? 0), 'suspended' => (int) ($by['suspended'] ?? 0),
            'trialsEndingThisWeek' => Church::where('status', 'trial')->whereBetween('trial_ends_at', [now(), now()->addWeek()])->count(),
            'newThisMonth' => Church::where('created_at', '>=', now()->startOfMonth())->count(),
            'activeLast7Days' => Church::where('last_active_at', '>=', now()->subDays(7))->count(),
            'members' => DB::table('members')->whereNull('deleted_at')->count(),
            'users' => DB::table('users')->whereNotNull('church_id')->count(),
        ]]);
    }

    public function index(Request $request): JsonResponse
    {
        $rows = Church::query()
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('search'), fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%")->orWhere('slug', 'like', "%{$s}%")))
            ->orderByDesc('created_at')->paginate($this->perPage($request));
        $ids = collect($rows->items())->pluck('id');
        $members = DB::table('members')->whereIn('church_id', $ids)->whereNull('deleted_at')->selectRaw('church_id, count(*) as n')->groupBy('church_id')->pluck('n', 'church_id');
        $users = DB::table('users')->whereIn('church_id', $ids)->selectRaw('church_id, count(*) as n')->groupBy('church_id')->pluck('n', 'church_id');
        $admins = DB::table('users')->whereIn('church_id', $ids)->orderBy('id')->get(['church_id', 'name', 'email', 'phone', 'email_verified_at'])->unique('church_id')->keyBy('church_id');

        return response()->json([
            'data' => collect($rows->items())->map(fn (Church $c) => $this->present($c) + [
                'members' => (int) ($members[$c->id] ?? 0), 'users' => (int) ($users[$c->id] ?? 0),
                'owner' => ($o = $admins[$c->id] ?? null) ? ['name' => $o->name, 'email' => $o->email, 'phone' => $o->phone, 'verified' => $o->email_verified_at !== null] : null,
            ]),
            'meta' => $this->meta($rows),
        ]);
    }

    public function show(Church $church): JsonResponse
    {
        return response()->json(['data' => $this->present($church)]);
    }

    public function update(Request $request, Church $church): JsonResponse
    {
        $v = $request->validate([
            'status' => ['sometimes', Rule::in(Church::STATUSES)],
            'plan' => ['nullable', 'string', 'max:30'],
            'trialEndsAt' => ['nullable', 'date'],
        ]);
        $church->update(collect($v)->mapWithKeys(fn ($val, $k) => [$k === 'trialEndsAt' ? 'trial_ends_at' : $k => $val])->all());

        return response()->json(['data' => $this->present($church->fresh())]);
    }

    private function present(Church $c): array
    {
        return [
            'id' => (string) $c->id, 'name' => $c->name, 'slug' => $c->slug, 'denomination' => $c->denomination, 'diocese' => $c->diocese,
            'phone' => $c->phone, 'email' => $c->email, 'country' => $c->country, 'status' => $c->status, 'plan' => $c->plan,
            'trialEndsAt' => $c->trial_ends_at?->toIso8601String(), 'trialDaysLeft' => $c->trialDaysLeft(),
            'onboarded' => $c->onboarded_at !== null, 'createdAt' => $c->created_at?->toIso8601String(), 'lastActiveAt' => $c->last_active_at?->toIso8601String(),
        ];
    }
}
