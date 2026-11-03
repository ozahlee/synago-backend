<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\OffertoryRequest;
use App\Http\Resources\OffertoryResource;
use App\Models\Offertory;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class OffertoryController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $records = $this->filtered($request)
            ->with('member')
            ->latest('date')->latest('id')
            ->paginate($this->perPage($request));

        return OffertoryResource::collection($records);
    }

    /** Totals per category for the same filters as index. */
    public function summary(Request $request): JsonResponse
    {
        $totals = $this->filtered($request)
            ->selectRaw('category, SUM(amount) as total, COUNT(*) as count')
            ->groupBy('category')
            ->get()
            ->keyBy('category');

        $byCategory = collect(Offertory::CATEGORIES)->mapWithKeys(fn ($c) => [$c => [
            'total' => round((float) ($totals[$c]->total ?? 0), 2),
            'count' => (int) ($totals[$c]->count ?? 0),
        ]]);

        return response()->json(['data' => [
            'total' => round($byCategory->sum('total'), 2),
            'byCategory' => $byCategory,
        ]]);
    }

    /**
     * One day's giving broken down by service (First Service, Second Service, …) and category,
     * plus the day's grand total. Services follow the configured order; unassigned records come last.
     */
    public function day(Request $request): JsonResponse
    {
        $date = $request->validate(['date' => ['required', 'date']])['date'];

        $rows = Offertory::whereDate('date', $date)
            ->selectRaw('service, category, SUM(amount) as total, COUNT(*) as count')
            ->groupBy('service', 'category')
            ->get();

        $configured = collect(Setting::get('services', []));
        $names = $configured->merge($rows->pluck('service')->filter()->unique()->diff($configured))->values();
        if ($rows->contains(fn ($r) => $r->service === null)) {
            $names->push(null);
        }

        $block = fn ($subset) => [
            'total' => round((float) $subset->sum('total'), 2),
            'count' => (int) $subset->sum('count'),
            'byCategory' => collect(Offertory::CATEGORIES)
                ->mapWithKeys(fn ($c) => [$c => round((float) $subset->where('category', $c)->sum('total'), 2)]),
        ];

        return response()->json(['data' => [
            'date' => substr($date, 0, 10),
            'services' => $names->map(fn ($name) => ['service' => $name] + $block($rows->where('service', $name)))->values(),
            ...$block($rows),
        ]]);
    }

    /** The most recent days with offerings, each with its per-service totals (for a "recent Sundays" list). */
    public function days(Request $request): JsonResponse
    {
        $limit = max(1, min(26, $request->integer('limit', 8)));
        $dates = Offertory::query()->select('date')->distinct()->orderByDesc('date')->limit($limit)->pluck('date');
        if ($dates->isEmpty()) {
            return response()->json(['data' => []]);
        }

        $rows = Offertory::whereIn('date', $dates)
            ->selectRaw('date, service, SUM(amount) as total, COUNT(*) as count')
            ->groupBy('date', 'service')->get()
            ->groupBy(fn ($r) => $r->date->toDateString());

        return response()->json(['data' => $dates->map(fn ($d) => $d->toDateString())->map(fn ($d) => [
            'date' => $d,
            'total' => round((float) $rows[$d]->sum('total'), 2),
            'count' => (int) $rows[$d]->sum('count'),
            'services' => $rows[$d]->map(fn ($r) => ['service' => $r->service, 'total' => round((float) $r->total, 2)])->values(),
        ])->values()]);
    }

    public function store(OffertoryRequest $request): OffertoryResource
    {
        $record = Offertory::create([...$request->payload(), 'recorded_by' => $request->user()->id]);

        return new OffertoryResource($record->load('member'));
    }

    public function show(Offertory $offertory): OffertoryResource
    {
        return new OffertoryResource($offertory->load('member'));
    }

    public function update(OffertoryRequest $request, Offertory $offertory): OffertoryResource
    {
        $offertory->update($request->payload());

        return new OffertoryResource($offertory->load('member'));
    }

    public function destroy(Offertory $offertory): Response
    {
        $offertory->delete();

        return response()->noContent();
    }

    private function filtered(Request $request)
    {
        return Offertory::query()
            ->when($request->query('category'), fn ($q, $v) => $q->where('category', $v))
            ->when($request->query('service'), fn ($q, $v) => $q->where('service', $v))
            ->when($request->query('memberId'), fn ($q, $v) => $q->where('member_id', $v))
            ->inPeriod('date', ...$this->period($request));
    }
}
