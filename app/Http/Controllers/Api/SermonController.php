<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Offertory;
use App\Models\Sermon;
use App\Models\SermonSeries;
use App\Models\Setting;
use App\Services\Calendar\ChurchYear;
use App\Services\Calendar\ReminderService;
use App\Support\Scripture;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Service register & preaching rota. */
class SermonController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status');
        $rows = Sermon::with(['preacher', 'series'])
            ->when($status, fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('preacherId'), fn ($q, $v) => $q->where('preacher_member_id', $v))
            ->when($request->query('seriesId'), fn ($q, $v) => $q->where('sermon_series_id', $v))
            ->when($request->query('book') !== null && $request->query('book') !== '', fn ($q) => $q->whereJsonContains('books', (int) $request->query('book')))
            ->when($request->query('year'), fn ($q, $y) => $q->whereYear('date', $y))
            ->when($request->query('date'), fn ($q, $d) => $q->whereDate('date', $d))
            ->when($request->query('search'), fn ($q, $s) => $q->where(fn ($q) => $q
                ->where('title', 'like', "%{$s}%")->orWhere('theme', 'like', "%{$s}%")->orWhere('scriptures', 'like', "%{$s}%")
                ->orWhere('preacher_name', 'like', "%{$s}%")->orWhereHas('preacher', fn ($q) => $q->where('name', 'like', "%{$s}%"))))
            ->when($status === 'planned', fn ($q) => $q->orderBy('date'), fn ($q) => $q->orderByDesc('date'))
            ->orderBy('service')
            ->paginate($this->perPage($request));

        return response()->json(['data' => collect($rows->items())->map(fn ($s) => $this->present($s)), 'meta' => $this->meta($rows)]);
    }

    public function show(Sermon $sermon): JsonResponse
    {
        $sermon->load(['preacher', 'series']);
        $att = Attendance::whereDate('date', $sermon->date)->when($sermon->service, fn ($q) => $q->where('service', $sermon->service))->get();
        $off = Offertory::whereDate('date', $sermon->date)->when($sermon->service, fn ($q) => $q->where('service', $sermon->service));

        return response()->json(['data' => $this->present($sermon) + [
            'attendance' => $att->isEmpty() ? null : $att->sum(fn ($a) => $a->total()),
            'offertory' => round((float) $off->sum('amount'), 2) ?: null,
        ]]);
    }

    public function stats(Request $request): JsonResponse
    {
        $today = ReminderService::now()->startOfDay();
        $year = $request->integer('year', $today->year);
        $preached = Sermon::where('status', 'preached')->whereYear('date', $year)->get(['books', 'preacher_member_id', 'preacher_name']);

        $coverage = array_fill(0, 66, 0);
        foreach ($preached as $s) {
            foreach (array_unique($s->books ?? []) as $b) {
                $coverage[$b]++;
            }
        }

        // Sundays in the next 4 weeks × configured services with nobody assigned yet
        $services = Setting::get('services', []);
        $planned = Sermon::whereDateBetween('date', [$today->toDateString(), $today->copy()->addWeeks(4)->toDateString()])->get(['date', 'service'])
            ->map(fn ($s) => $s->date->toDateString().'|'.$s->service)->all();
        $gaps = [];
        for ($d = $today->copy(); $d->lte($today->copy()->addWeeks(4)); $d->addDay()) {
            if ($d->isSunday()) {
                foreach ($services as $svc) {
                    if (! in_array($d->toDateString().'|'.$svc, $planned, true)) {
                        $gaps[] = ['date' => $d->toDateString(), 'service' => $svc];
                    }
                }
            }
        }

        return response()->json(['data' => [
            'year' => $year,
            'preached' => $preached->count(),
            'preachers' => $preached->map(fn ($s) => $s->preacher_member_id ? "m{$s->preacher_member_id}" : 'n'.strtolower((string) $s->preacher_name))->unique()->count(),
            'upcoming' => Sermon::where('status', 'planned')->whereDate('date', '>=', $today->toDateString())->count(),
            'gaps' => $gaps,
            'coverage' => collect(array_keys(Scripture::BOOKS))->map(fn ($name, $i) => ['book' => $name, 'index' => $i, 'testament' => $i < 39 ? 'OT' : 'NT', 'count' => $coverage[$i]]),
        ]]);
    }

    public function parse(Request $request): JsonResponse
    {
        $p = Scripture::parse((string) $request->query('ref', ''));

        return response()->json(['data' => $p]);
    }

    public function store(Request $request): JsonResponse
    {
        $s = Sermon::create([...$this->validated($request), 'created_by' => $request->user()->id]);

        return response()->json(['data' => $this->present($s->load(['preacher', 'series']))], 201);
    }

    public function update(Request $request, Sermon $sermon): JsonResponse
    {
        $sermon->update($this->validated($request, $sermon));
        // a changed date / preacher means the rota reminder must be rebuilt
        \App\Models\Reminder::where('source', 'rule:preacher_rota')->where('source_id', $sermon->id)->where('status', 'pending')->delete();

        return response()->json(['data' => $this->present($sermon->fresh(['preacher', 'series']))]);
    }

    public function destroy(Sermon $sermon): Response
    {
        $sermon->delete();

        return response()->noContent();
    }

    // ---------------------------------------------------------------- series

    public function series(): JsonResponse
    {
        $rows = SermonSeries::withCount('sermons')->withMin('sermons', 'date')->withMax('sermons', 'date')->orderByDesc('sermons_max_date')->get();

        return response()->json(['data' => $rows->map(fn ($s) => [
            'id' => (string) $s->id, 'name' => $s->name, 'description' => $s->description, 'sermons' => $s->sermons_count,
            'from' => $s->sermons_min_date ? substr($s->sermons_min_date, 0, 10) : null, 'to' => $s->sermons_max_date ? substr($s->sermons_max_date, 0, 10) : null,
            'preached' => $s->sermons()->where('status', 'preached')->count(),
        ])]);
    }

    public function storeSeries(Request $request): JsonResponse
    {
        $v = $request->validate(['name' => ['required', 'string', 'max:150', Rule::unique('sermon_series', 'name')], 'description' => ['nullable', 'string', 'max:2000']]);
        $s = SermonSeries::create($v);

        return response()->json(['data' => ['id' => (string) $s->id, 'name' => $s->name]], 201);
    }

    public function updateSeries(Request $request, SermonSeries $series): JsonResponse
    {
        $v = $request->validate(['name' => ['sometimes', 'string', 'max:150', Rule::unique('sermon_series', 'name')->ignore($series)], 'description' => ['nullable', 'string', 'max:2000']]);
        $series->update($v);

        return response()->json(['data' => ['id' => (string) $series->id, 'name' => $series->name]]);
    }

    public function destroySeries(SermonSeries $series): Response
    {
        $series->delete(); // sermons keep, just without a series

        return response()->noContent();
    }

    // ---------------------------------------------------------------- helpers

    private function validated(Request $request, ?Sermon $existing = null): array
    {
        $req = $existing ? 'sometimes' : 'required';
        $v = $request->validate([
            'date' => [$req, 'date'],
            'service' => ['nullable', 'string', 'max:50'],
            'status' => ['sometimes', Rule::in(['planned', 'preached'])],
            'preacherMemberId' => ['nullable', 'integer', 'exists:members,id'],
            'preacherName' => ['nullable', 'string', 'max:255'],
            'preacherChurch' => ['nullable', 'string', 'max:255'],
            'title' => ['nullable', 'string', 'max:255'],
            'theme' => ['nullable', 'string', 'max:255'],
            'seriesId' => ['nullable', 'integer', 'exists:sermon_series,id'],
            'scriptures' => ['nullable', 'array', 'max:12'],
            'scriptures.*' => ['nullable', 'string', 'max:60'],
            'notes' => ['nullable', 'string', 'max:10000'],
            'recordingUrl' => ['nullable', 'url', 'max:500'],
        ]);

        $out = [];
        $map = ['date' => 'date', 'service' => 'service', 'status' => 'status', 'preacherMemberId' => 'preacher_member_id', 'preacherName' => 'preacher_name',
            'preacherChurch' => 'preacher_church', 'title' => 'title', 'theme' => 'theme', 'seriesId' => 'sermon_series_id', 'notes' => 'notes', 'recordingUrl' => 'recording_url'];
        foreach ($map as $in => $col) {
            if (array_key_exists($in, $v)) {
                $out[$col] = $v[$in];
            }
        }
        if (! empty($out['preacher_member_id'])) {
            $out['preacher_name'] = null; // a member preacher replaces a typed name
        }

        if (array_key_exists('scriptures', $v)) {
            $refs = [];
            $books = [];
            foreach (array_values(array_filter($v['scriptures'] ?? [], fn ($x) => trim((string) $x) !== '')) as $i => $raw) {
                $p = Scripture::parse($raw);
                if (! $p) {
                    throw ValidationException::withMessages(["scriptures.{$i}" => "“{$raw}” isn't a Bible reference I recognise (try e.g. John 15:1-8)."]);
                }
                $refs[] = $p['ref'];
                $books[] = $p['bookIndex'];
            }
            $out['scriptures'] = array_values(array_unique($refs));
            $out['books'] = array_values(array_unique($books));
        }

        return $out;
    }

    private function present(Sermon $s): array
    {
        $season = ChurchYear::seasonOn(Carbon::parse($s->date));

        return [
            'id' => (string) $s->id, 'date' => $s->date->toDateString(), 'service' => $s->service, 'status' => $s->status,
            'preacherMemberId' => $s->preacher_member_id ? (string) $s->preacher_member_id : null,
            'preacher' => $s->preacherLabel(), 'preacherPhone' => $s->preacher?->phone, 'preacherChurch' => $s->preacher_church,
            'visiting' => ! $s->preacher_member_id && (bool) $s->preacher_name,
            'title' => $s->title, 'theme' => $s->theme,
            'seriesId' => $s->sermon_series_id ? (string) $s->sermon_series_id : null, 'series' => $s->series?->name,
            'scriptures' => $s->scriptures ?? [], 'notes' => $s->notes, 'recordingUrl' => $s->recording_url,
            'season' => $season ? ['name' => $season['name'], 'colour' => $season['colour']] : null,
        ];
    }
}
