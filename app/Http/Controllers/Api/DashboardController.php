<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contribution;
use App\Models\Member;
use App\Models\Offertory;
use App\Services\DashboardAttention;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/** Serves the dashboard's `DashboardStats` shape. */
class DashboardController extends Controller
{
    private const TREND_MONTHS = 6;

    /** Things to act on, filtered to what the user may open. */
    public function attention(Request $request, DashboardAttention $attention): JsonResponse
    {
        return response()->json(['data' => $attention->for($request->user())]);
    }

    /** The latest Sunday at a glance, per service. */
    public function sunday(Request $request, DashboardAttention $attention): JsonResponse
    {
        return response()->json(['data' => $attention->sunday($request->user())]);
    }

    /**
     * Headline figures. People numbers need members.view and money numbers finance.view, so the
     * dashboard never shows anything the user couldn't open themselves.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $people = $request->user()->can('members.view');
        $money = $request->user()->can('finance.view');
        $now = now();
        $monthStart = $now->copy()->startOfMonth();
        $lastMonthStart = $monthStart->copy()->subMonth();

        $total = Member::count();
        $totalAtMonthStart = Member::whereDate('date_joined', '<', $monthStart)->count();

        $thisMonth = (float) Offertory::whereDate('date', '>=', $monthStart)->sum('amount');
        $lastMonth = (float) Offertory::whereDate('date', '>=', $lastMonthStart)
            ->whereDate('date', '<', $monthStart)->sum('amount');

        $byGender = Member::selectRaw('gender, COUNT(*) as c')->groupBy('gender')->pluck('c', 'gender');

        return response()->json(['data' => [
            'totalMembers' => $people ? $total : null,
            'membersChange' => $people ? $this->percentChange($totalAtMonthStart, $total) : null,
            'activeMembers' => $people ? Member::active()->count() : null,
            'attendanceRate' => $people ? AttendanceController::attendanceRate() : null,
            'sundaySchool' => $people ? Member::active()->where('sunday_school', true)->count() : null,
            'sundaySchoolClasses' => $people ? (\App\Models\SundaySchoolClass::count() ?: null) : null,
            'monthlyOffertory' => $money ? round($thisMonth, 2) : null,
            'offertoryChange' => $money ? $this->percentChange($lastMonth, $thisMonth) : null,
            'membershipByGender' => $people ? [
                'male' => (int) ($byGender['male'] ?? 0),
                'female' => (int) ($byGender['female'] ?? 0),
            ] : null,
            'offertoryTrends' => $money ? $this->offertoryTrends($monthStart) : [],
            'recentActivities' => $this->recentActivities($people, $money),
        ]]);
    }

    private function percentChange(float $from, float $to): float
    {
        if ($from == 0.0) {
            return $to > 0 ? 100.0 : 0.0;
        }

        return round(($to - $from) / $from * 100, 1);
    }

    /** Per-month totals by category for the last N months (grouped in PHP to stay DB-agnostic). */
    private function offertoryTrends(Carbon $monthStart): array
    {
        $from = $monthStart->copy()->subMonths(self::TREND_MONTHS - 1);

        $grouped = Offertory::whereDate('date', '>=', $from)
            ->get(['date', 'category', 'amount'])
            ->groupBy(fn ($o) => $o->date->format('Y-m'));

        return collect(range(0, self::TREND_MONTHS - 1))->map(function ($i) use ($from, $grouped) {
            $month = $from->copy()->addMonths($i);
            $rows = $grouped->get($month->format('Y-m'), collect());

            return ['month' => $month->format('M')]
                + collect(Offertory::CATEGORIES)
                    ->mapWithKeys(fn ($c) => [$c => round((float) $rows->where('category', $c)->sum('amount'), 2)])
                    ->all();
        })->all();
    }

    private function recentActivities(bool $people, bool $money, int $limit = 5): array
    {
        /** @var Collection<int, array> $events */
        $events = collect()
            ->merge(! $people ? [] : Member::latest()->limit($limit)->get()->map(fn ($m) => [
                'key' => "member-{$m->id}", 'name' => $m->name, 'action' => 'joined the church', 'at' => $m->created_at,
            ]))
            ->merge(! $money ? [] : Offertory::with('member')->latest()->limit($limit)->get()->map(fn ($o) => [
                'key' => "offertory-{$o->id}", 'name' => $o->contributorLabel(),
                'action' => sprintf('gave %s of %s', $o->category, number_format((float) $o->amount, 2)), 'at' => $o->created_at,
            ]))
            ->merge(! $money ? [] : Contribution::with([...Contribution::RELATIONS, 'project'])->where('kind', '!=', 'pledge_payment')->latest()->limit($limit)->get()->map(fn ($c) => [
                'key' => "contribution-{$c->id}", 'name' => $c->contributorLabel(),
                'action' => $c->kind === 'in_kind' ? "gave {$c->in_kind_description} to {$c->project->name}"
                    : sprintf('contributed %s to %s', number_format((float) $c->amount, 2), $c->project->name), 'at' => $c->created_at,
            ]));

        return $events->sortByDesc('at')->take($limit)->values()->map(fn ($e) => [
            'id' => $e['key'],
            'name' => $e['name'],
            'action' => $e['action'],
            'time' => $e['at']->diffForHumans(),
        ])->all();
    }
}
