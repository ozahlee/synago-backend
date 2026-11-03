<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Observance;
use App\Models\User;
use App\Services\Calendar\CalendarService;
use App\Services\Calendar\ChurchYear;
use App\Services\Calendar\ReminderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CalendarController extends Controller
{
    public function __construct(private CalendarService $calendar) {}

    /** Everything on the calendar between two dates (max ~3 months), plus the church year for those dates. */
    public function index(Request $request): JsonResponse
    {
        $v = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from'], 'kinds' => ['nullable', 'string']]);
        $from = Carbon::parse($v['from'])->startOfDay();
        $to = Carbon::parse($v['to'])->endOfDay();
        abort_if($from->diffInDays($to) > 100, 422, 'Choose a range of at most about three months.');
        $kinds = isset($v['kinds']) ? explode(',', $v['kinds']) : null;

        $years = range($from->year, $to->year);
        $overlaps = fn ($x) => $x['end'] >= $from->toDateString() && $x['start'] <= $to->toDateString();

        return response()->json(['data' => [
            'items' => $this->calendar->items($request->user(), $from, $to, $kinds),
            'seasons' => collect($years)->flatMap(fn ($y) => ChurchYear::seasons($y))->filter($overlaps)->values(),
            'feasts' => collect($years)->flatMap(fn ($y) => ChurchYear::feasts($y))->filter($overlaps)->values(),
            'today' => ReminderService::now()->toDateString(),
            'season' => ChurchYear::seasonOn(ReminderService::now()),
        ]]);
    }

    /** The church year for one year: seasons, principal feasts and custom observances. */
    public function churchYear(Request $request): JsonResponse
    {
        $year = $request->integer('year', ReminderService::now()->year);

        return response()->json(['data' => [
            'year' => $year, 'easter' => ChurchYear::easter($year)->toDateString(),
            'seasons' => ChurchYear::seasons($year), 'feasts' => ChurchYear::feasts($year),
        ]]);
    }

    /** Private subscription link for phone / Google calendars. */
    public function feedLink(Request $request): JsonResponse
    {
        $user = $request->user();
        $token = $user->getAttributes()['calendar_token'] ?? null;
        if ($request->isMethod('post') || ! $token) {
            $token = Str::random(40);
            $user->forceFill(['calendar_token' => $token])->save();
        }

        return response()->json(['data' => ['url' => url("/api/v1/calendar/feed/{$token}.ics")]]);
    }

    /** iCalendar feed (no login; the token is the secret). Covers 1 month back to 6 months ahead. */
    public function feed(string $token): Response
    {
        $user = User::where('calendar_token', $token)->where('is_active', true)->whereNotNull('church_id')->firstOrFail();
        // no login on this route: work inside the feed owner's church
        \App\Support\Tenant::set($user->getAttributes()['church_id']);
        abort_if(\App\Support\Tenant::church()?->isSuspended(), 404);
        $tz = config('church.timezone');
        $now = ReminderService::now();
        $items = $this->calendar->items($user, $now->copy()->subMonth(), $now->copy()->addMonths(6),
            ['event', 'service', 'meeting', 'announcement', 'sacrament', 'activity']);
        $feasts = collect(range($now->year, $now->year + 1))->flatMap(fn ($y) => ChurchYear::feasts($y));

        $esc = fn ($s) => str_replace(["\\", ';', ',', "\n"], ['\\\\', '\;', '\,', '\n'], (string) $s);
        $utc = fn (string $local) => Carbon::parse($local, $tz)->utc()->format('Ymd\THis\Z');
        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Synago//Church calendar//EN', 'CALSCALE:GREGORIAN',
            'X-WR-CALNAME:'.$esc(\App\Models\Setting::get('churchName')), 'X-WR-TIMEZONE:'.$tz];
        $stamp = now()->utc()->format('Ymd\THis\Z');

        foreach ($items as $i) {
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:'.$i['id'].'@synago';
            $lines[] = 'DTSTAMP:'.$stamp;
            if ($i['allDay']) {
                $lines[] = 'DTSTART;VALUE=DATE:'.str_replace('-', '', $i['start']);
            } else {
                $lines[] = 'DTSTART:'.$utc($i['start']);
                $lines[] = 'DTEND:'.$utc($i['end'] ?? Carbon::parse($i['start'])->addHour()->format('Y-m-d\TH:i'));
            }
            $lines[] = 'SUMMARY:'.$esc($i['title']);
            if ($i['subtitle']) {
                $lines[] = 'DESCRIPTION:'.$esc($i['subtitle']);
            }
            $lines[] = 'END:VEVENT';
        }
        foreach ($feasts as $f) {
            $lines = [...$lines, 'BEGIN:VEVENT', 'UID:feast-'.Str::slug($f['name']).'-'.$f['start'].'@synago', 'DTSTAMP:'.$stamp,
                'DTSTART;VALUE=DATE:'.str_replace('-', '', $f['start']), 'SUMMARY:✝ '.$esc($f['name']), 'TRANSP:TRANSPARENT', 'END:VEVENT'];
        }
        $lines[] = 'END:VCALENDAR';

        return response(implode("\r\n", $lines)."\r\n", 200, ['Content-Type' => 'text/calendar; charset=utf-8', 'Content-Disposition' => 'inline; filename="church.ics"']);
    }

    // ---------------------------------------------------------------- events

    public function showEvent(Event $event): JsonResponse
    {
        return response()->json(['data' => $this->presentEvent($event->load(['organiser', 'ministry', 'cellGroup']))]);
    }

    public function storeEvent(Request $request): JsonResponse
    {
        $data = $this->eventData($request);
        $this->guardClash($request, new Event($data));
        $e = Event::create([...$data, 'created_by' => $request->user()->id]);

        return response()->json(['data' => $this->presentEvent($e->fresh(['organiser', 'ministry', 'cellGroup']))], 201);
    }

    public function updateEvent(Request $request, Event $event): JsonResponse
    {
        $data = $this->eventData($request, true);
        $this->guardClash($request, (clone $event)->fill($data), $event->id);
        $event->update($data);
        // Pending reminders for this event are rebuilt by the next run with the new time / text.
        \App\Models\Reminder::where('source', 'event')->where('source_id', $event->id)->where('status', 'pending')->delete();

        return response()->json(['data' => $this->presentEvent($event->fresh(['organiser', 'ministry', 'cellGroup']))]);
    }

    public function destroyEvent(Event $event): Response
    {
        \App\Models\Reminder::where('source', 'event')->where('source_id', $event->id)->where('status', 'pending')->update(['status' => 'cancelled', 'note' => 'Event deleted']);
        $event->delete();

        return response()->noContent();
    }

    /** Same venue, overlapping time, within the next year → 409 unless the user confirms with ignoreClash. */
    private function guardClash(Request $request, Event $candidate, ?int $ignoreId = null): void
    {
        if (! $candidate->venue || $request->boolean('ignoreClash')) {
            return;
        }
        $from = $candidate->starts_at->copy()->startOfDay();
        $to = $candidate->repeat === 'none' ? $candidate->starts_at->copy()->endOfDay() : $from->copy()->addYear();
        $mine = collect($candidate->occurrences($from, $to))->map(fn ($s) => [$s, $s->copy()->addMinutes($candidate->durationMinutes())]);

        $clashes = Event::where('venue', $candidate->venue)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->get()
            ->flatMap(function (Event $other) use ($mine, $from, $to) {
                $hits = [];
                foreach ($other->occurrences($from, $to) as $s) {
                    $e = $s->copy()->addMinutes($other->durationMinutes());
                    foreach ($mine as [$ms, $me]) {
                        if ($s->lt($me) && $e->gt($ms)) {
                            $hits[] = ['title' => $other->title, 'start' => $s->format('Y-m-d\TH:i')];
                            break;
                        }
                    }
                    if (count($hits) >= 3) {
                        break;
                    }
                }

                return $hits;
            })->take(5)->values();

        if ($clashes->isNotEmpty()) {
            abort(response()->json([
                'message' => "{$candidate->venue} is already booked at that time.",
                'clashes' => $clashes,
            ], 409));
        }
    }

    private function eventData(Request $request, bool $update = false): array
    {
        $req = $update ? 'sometimes' : 'required';
        $v = $request->validate([
            'title' => [$req, 'string', 'max:255'],
            'type' => [$req, Rule::in(Event::TYPES)],
            'description' => ['nullable', 'string', 'max:5000'],
            'startsAt' => [$req, 'date'],
            'endsAt' => ['nullable', 'date', 'after_or_equal:startsAt'],
            'allDay' => ['sometimes', 'boolean'],
            'venue' => ['nullable', 'string', 'max:255'],
            'organiserMemberId' => ['nullable', 'integer', 'exists:members,id'],
            'ministryId' => ['nullable', 'integer', 'exists:ministries,id'],
            'cellGroupId' => ['nullable', 'integer', 'exists:cell_groups,id'],
            'projectId' => ['nullable', 'integer', 'exists:projects,id'],
            'repeat' => ['sometimes', Rule::in(Event::REPEATS)],
            'repeatUntil' => ['nullable', 'date'],
            'remindMinutes' => ['nullable', 'array', 'max:3'],
            'remindMinutes.*' => ['integer', 'min:0', 'max:20160'],
            'remindAudience' => ['nullable', 'array'],
            'remindAudience.type' => ['required_with:remindAudience', Rule::in(['all', 'staff', 'leaders', 'ministry', 'cell_group', 'members'])],
            'remindAudience.ministryId' => ['nullable', 'integer'],
            'remindAudience.cellGroupId' => ['nullable', 'integer'],
            'remindAudience.memberIds' => ['nullable', 'array'],
            'remindChannel' => ['sometimes', Rule::in(['sms', 'app', 'both'])],
        ]);

        return collect($v)->mapWithKeys(fn ($val, $k) => [Str::snake($k) => $val])->all();
    }

    private function presentEvent(Event $e): array
    {
        return [
            'id' => (string) $e->id, 'title' => $e->title, 'type' => $e->type, 'description' => $e->description,
            'startsAt' => $e->starts_at->format('Y-m-d\TH:i'), 'endsAt' => $e->ends_at?->format('Y-m-d\TH:i'), 'allDay' => $e->all_day,
            'venue' => $e->venue, 'organiserMemberId' => $e->organiser_member_id ? (string) $e->organiser_member_id : null, 'organiser' => $e->organiser?->name,
            'ministryId' => $e->ministry_id ? (string) $e->ministry_id : null, 'ministry' => $e->ministry?->name,
            'cellGroupId' => $e->cell_group_id ? (string) $e->cell_group_id : null, 'cellGroup' => $e->cellGroup?->name,
            'projectId' => $e->project_id ? (string) $e->project_id : null,
            'repeat' => $e->repeat, 'repeatUntil' => $e->repeat_until?->toDateString(),
            'remindMinutes' => $e->remind_minutes ?? [], 'remindAudience' => $e->remind_audience, 'remindChannel' => $e->remind_channel,
        ];
    }

    // ---------------------------------------------------------------- observances

    public function observances(): JsonResponse
    {
        return response()->json(['data' => Observance::orderBy('month')->orderBy('day')->get()->map(fn ($o) => $this->presentObservance($o))]);
    }

    public function storeObservance(Request $request): JsonResponse
    {
        $o = Observance::create($this->observanceData($request));

        return response()->json(['data' => $this->presentObservance($o)], 201);
    }

    public function updateObservance(Request $request, Observance $observance): JsonResponse
    {
        $observance->update($this->observanceData($request));

        return response()->json(['data' => $this->presentObservance($observance)]);
    }

    public function destroyObservance(Observance $observance): Response
    {
        $observance->delete();

        return response()->noContent();
    }

    private function observanceData(Request $request): array
    {
        $v = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'rule' => ['required', Rule::in(['fixed', 'easter'])],
            'month' => ['required_if:rule,fixed', 'nullable', 'integer', 'between:1,12'],
            'day' => ['required_if:rule,fixed', 'nullable', 'integer', 'between:1,31'],
            'easterOffset' => ['required_if:rule,easter', 'nullable', 'integer', 'between:-70,70'],
            'durationDays' => ['sometimes', 'integer', 'between:1,60'],
            'colour' => ['required', Rule::in(Observance::COLOURS)],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        if ($v['rule'] === 'fixed' && ! checkdate($v['month'], $v['day'], 2024)) {
            throw ValidationException::withMessages(['day' => 'That date does not exist.']);
        }

        return collect($v)->mapWithKeys(fn ($val, $k) => [Str::snake($k) => $val])->all();
    }

    private function presentObservance(Observance $o): array
    {
        return ['id' => (string) $o->id, 'name' => $o->name, 'rule' => $o->rule, 'month' => $o->month, 'day' => $o->day,
            'easterOffset' => $o->easter_offset, 'durationDays' => $o->duration_days, 'colour' => $o->colour, 'note' => $o->note];
    }
}
