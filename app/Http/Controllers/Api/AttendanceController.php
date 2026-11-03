<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Member;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Head counts per service. Days are saved as a whole (all services at once). */
class AttendanceController extends Controller
{
    /** One day: a row for every configured service (zeros when not yet counted). */
    public function day(Request $request): JsonResponse
    {
        $date = $request->validate(['date' => ['required', 'date']])['date'];
        $saved = Attendance::whereDate('date', $date)->get()->keyBy('service');
        $names = collect(Setting::get('services'))->merge($saved->keys())->unique()->values();

        return response()->json(['data' => [
            'date' => substr($date, 0, 10),
            'services' => $names->map(fn ($s) => $this->row($saved->get($s), $s))->values(),
            'total' => $saved->sum(fn (Attendance $a) => $a->total()),
        ]]);
    }

    public function saveDay(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date', \App\Support\ChurchDate::notFuture()],
            'services' => ['required', 'array', 'min:1'],
            'services.*.service' => ['required', 'string', 'max:50', 'distinct'],
            'services.*.men' => ['required', 'integer', 'min:0', 'max:100000'],
            'services.*.women' => ['required', 'integer', 'min:0', 'max:100000'],
            'services.*.children' => ['required', 'integer', 'min:0', 'max:100000'],
            'services.*.visitors' => ['required', 'integer', 'min:0', 'max:100000'],
            'services.*.notes' => ['nullable', 'string', 'max:255'],
        ]);

        DB::transaction(function () use ($data, $request) {
            foreach ($data['services'] as $s) {
                $counts = array_intersect_key($s, array_flip(['men', 'women', 'children', 'visitors']));
                $existing = Attendance::whereDate('date', $data['date'])->where('service', $s['service'])->first();
                if (array_sum($counts) === 0) {
                    $existing?->delete(); // all zeros = not held / clear it

                    continue;
                }
                ($existing ?? new Attendance(['date' => $data['date'], 'service' => $s['service']]))
                    ->fill([...$counts, 'notes' => $s['notes'] ?? null, 'recorded_by' => $request->user()->id])
                    ->save();
            }
        });

        return $this->day(new Request(['date' => $data['date']]));
    }

    /** A period (?from=&to=, default the last N weeks): totals per day (adults/children/visitors) and per service, plus averages. */
    public function trends(Request $request): JsonResponse
    {
        [$from, $to] = $this->period($request);
        if (! $from && ! $to) {
            $from = now()->subWeeks(max(1, min(52, $request->integer('weeks', 12))))->toDateString();
        }
        $rows = Attendance::query()->inPeriod('date', $from, $to)->orderBy('date')->get();

        $days = $rows->groupBy(fn ($a) => $a->date->toDateString())->map(fn ($g, $d) => [
            'date' => $d,
            'men' => $g->sum('men'), 'women' => $g->sum('women'), 'children' => $g->sum('children'), 'visitors' => $g->sum('visitors'),
            'total' => $g->sum(fn ($a) => $a->total()),
            'services' => $g->map(fn ($a) => ['service' => $a->service, 'total' => $a->total()])->values(),
        ])->values();

        $avg = $days->avg('total');

        return response()->json(['data' => [
            'days' => $days,
            'averageTotal' => $avg ? (int) round($avg) : 0,
            'averageAdults' => $days->isEmpty() ? 0 : (int) round($days->avg(fn ($d) => $d['men'] + $d['women'])),
            'attendanceRate' => static::attendanceRate(),
            'activeMembers' => Member::active()->count(),
        ]]);
    }

    /** Average adult attendance over the last 4 recorded days as a % of active members (null if nothing recorded). */
    public static function attendanceRate(): ?float
    {
        $recent = Attendance::query()->select('date')->distinct()->orderByDesc('date')->limit(4)->pluck('date');
        $active = Member::active()->count();
        if ($recent->isEmpty() || $active === 0) {
            return null;
        }
        $adults = Attendance::whereIn('date', $recent)->selectRaw('SUM(men + women) as a')->value('a');

        return round(($adults / $recent->count()) / $active * 100, 1);
    }

    private function row(?Attendance $a, string $service): array
    {
        return [
            'service' => $service,
            'men' => $a->men ?? 0, 'women' => $a->women ?? 0, 'children' => $a->children ?? 0, 'visitors' => $a->visitors ?? 0,
            'total' => $a?->total() ?? 0,
            'notes' => $a?->notes,
            'recorded' => (bool) $a,
        ];
    }
}
